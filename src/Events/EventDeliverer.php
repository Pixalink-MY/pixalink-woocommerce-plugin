<?php
/**
 * Outbox delivery worker.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Events;

use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\ApiResponse;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Delivers one outbox row per `pixalink_loyalty_deliver_event` action.
 *
 * Outcome of a delivery attempt:
 * - 2xx (`processed`, `duplicate`, `accepted`, `ignored`) and 409 duplicate → delivered;
 * - 401/403 (except `timestamp_skew`) or missing credentials → delivery paused for every event until the
 *   credentials change or a manual ping succeeds; the attempt is not counted;
 * - 400/404/422 and other client errors → dead;
 * - 408/409/425/429/5xx, transport errors and a repeated `timestamp_skew` → retried per {@see RetryPolicy}.
 *
 * Per-order ordering: while an earlier event of the same order is still open, the attempt is pushed back (at least a
 * minute, or until the earlier event's next attempt) without counting it, so Pixalink sees `order.paid` before that
 * order's refunds; delivering the earlier event wakes the next one immediately.
 */
final class EventDeliverer {

	public const OUTCOME_DELIVERED = 'delivered';
	public const OUTCOME_RETRY     = 'retry';
	public const OUTCOME_DEAD      = 'dead';
	public const OUTCOME_PAUSE     = 'pause';
	public const OUTCOME_DEFERRED  = 'deferred';

	public const PAUSE_AUTH_FAILED   = 'auth_failed';
	public const PAUSE_NOT_CONNECTED = 'not_connected';

	/**
	 * Seconds after which a `sending` lock is considered abandoned (worker died mid-request).
	 */
	public const LOCK_TIMEOUT = 300;

	/**
	 * Seconds an event waits for an earlier event of the same order.
	 */
	public const ORDERING_DELAY = 60;

	/**
	 * Rows re-enqueued per sweep.
	 */
	public const SWEEP_BATCH = 200;

	/**
	 * How early an action may run before its row's `next_attempt_at` and still attempt delivery.
	 */
	private const EARLY_TOLERANCE = 30;

	/**
	 * `last_error` prefix of a row the ordering guard pushed back.
	 */
	private const WAITING_NOTE = 'waiting_for_earlier_event:';

	/**
	 * 409 error codes that mean "already processed".
	 */
	private const DUPLICATE_CODES = array( 'duplicate', 'duplicate_event', 'event_duplicate', 'already_processed' );

	/**
	 * Outbox storage.
	 *
	 * @var EventStore
	 */
	private EventStore $store;

	/**
	 * Action Scheduler wiring.
	 *
	 * @var Scheduler
	 */
	private Scheduler $scheduler;

	/**
	 * Backoff.
	 *
	 * @var RetryPolicy
	 */
	private RetryPolicy $policy;

	/**
	 * Option storage (pause flag).
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Sends one envelope (normally Client::send_event()).
	 *
	 * @var \Closure(array<string,mixed>):ApiResponse
	 */
	private \Closure $sender;

	/**
	 * Resolves the outlet id (may throw ApiException).
	 *
	 * @var \Closure():string
	 */
	private \Closure $outlet_id;

	/**
	 * Current unix time.
	 *
	 * @var \Closure():int
	 */
	private \Closure $now;

	/**
	 * Constructor.
	 *
	 * @param EventStore    $store     Outbox storage.
	 * @param Scheduler     $scheduler Action Scheduler wiring.
	 * @param RetryPolicy   $policy    Backoff.
	 * @param Options       $options   Option storage.
	 * @param Logger        $logger    Logger.
	 * @param \Closure      $sender    `fn (array $envelope): ApiResponse`, throwing ApiException on failure.
	 * @param \Closure      $outlet_id `fn (): string`, throwing ApiException when unknown.
	 * @param \Closure|null $now       Unix time source (tests); defaults to time().
	 */
	public function __construct( EventStore $store, Scheduler $scheduler, RetryPolicy $policy, Options $options, Logger $logger, \Closure $sender, \Closure $outlet_id, ?\Closure $now = null ) {
		$this->store     = $store;
		$this->scheduler = $scheduler;
		$this->policy    = $policy;
		$this->options   = $options;
		$this->logger    = $logger;
		$this->sender    = $sender;
		$this->outlet_id = $outlet_id;
		$this->now       = $now ?? static fn (): int => time();
	}

	/**
	 * Register the Action Scheduler callbacks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( Scheduler::DELIVER_HOOK, array( $this, 'handle' ), 10, 1 );
		add_action( Scheduler::SWEEP_HOOK, array( $this, 'handle_sweep' ), 10, 0 );
	}

	/**
	 * `pixalink_loyalty_deliver_event` callback.
	 *
	 * @param mixed $event_id Row id (Action Scheduler passes the `event_id` arg positionally).
	 * @return void
	 */
	public function handle( $event_id ): void {
		if ( is_numeric( $event_id ) ) {
			$this->deliver( (int) $event_id );
		}
	}

	/**
	 * Attempt delivery of one row.
	 *
	 * @param int  $event_id     Row id.
	 * @param bool $bypass_pause Attempt even while delivery is paused (manual test ping).
	 * @return string|null One of the OUTCOME_* constants, or null when nothing was attempted.
	 */
	public function deliver( int $event_id, bool $bypass_pause = false ): ?string {
		$event = $this->store->find( $event_id );
		if ( null === $event || ! $event->status->is_open() ) {
			return null;
		}

		if ( ! $bypass_pause && $this->is_paused() ) {
			// Left untouched: the sweep re-enqueues it once delivery resumes.
			return null;
		}

		$now = ( $this->now )();
		if ( ! $this->is_due( $event, $now ) ) {
			return null;
		}

		$blocking = $this->store->first_open_before( $event );
		if ( null !== $blocking ) {
			// Wait at least a minute, or until the blocking event's own next attempt; its delivery wakes us earlier.
			$wake = max( $now + self::ORDERING_DELAY, ( Event::timestamp( $blocking->next_attempt_at ) ?? $now ) + self::ORDERING_DELAY );
			$this->store->defer( $event->id, self::utc_datetime( $wake ), self::WAITING_NOTE . $blocking->uuid );
			$this->scheduler->deliver_at( $event->id, $wake );

			return self::OUTCOME_DEFERRED;
		}

		if ( ! $this->store->claim( $event->id, self::utc_datetime( $now ), self::utc_datetime( $now - self::LOCK_TIMEOUT ) ) ) {
			return null;
		}

		$attempts = $event->attempts + 1;

		try {
			$envelope = $event->to_envelope( EventQueue::site_url(), ( $this->outlet_id )() );
			$response = ( $this->sender )( $envelope );
		} catch ( ApiException $exception ) {
			return $this->handle_failure( $event, $attempts, $exception, $now );
		} catch ( \Throwable $throwable ) {
			$this->give_up( $event, $attempts, 0, 'unexpected_error', $throwable->getMessage(), array() );

			return self::OUTCOME_DEAD;
		}

		$this->succeed( $event, $attempts, $response->status, $response->data, $now );

		return self::OUTCOME_DELIVERED;
	}

	/**
	 * `pixalink_loyalty_sweep_events` callback.
	 *
	 * @return void
	 */
	public function handle_sweep(): void {
		$this->sweep();
	}

	/**
	 * Re-enqueue due rows whose action was lost and reclaim stale locks.
	 *
	 * @return int Rows enqueued.
	 */
	public function sweep(): int {
		if ( $this->is_paused() ) {
			return 0;
		}

		$now = ( $this->now )();
		$ids = $this->store->due_ids( self::utc_datetime( $now ), self::utc_datetime( $now - self::LOCK_TIMEOUT ), self::SWEEP_BATCH );
		foreach ( $ids as $id ) {
			$this->scheduler->deliver_now( $id );
		}

		return count( $ids );
	}

	/**
	 * Map an API failure to an outcome.
	 *
	 * @param ApiException $exception Failure.
	 * @return string One of OUTCOME_DELIVERED, OUTCOME_RETRY, OUTCOME_DEAD, OUTCOME_PAUSE.
	 */
	public static function classify( ApiException $exception ): string {
		if ( ApiException::NOT_CONNECTED === $exception->error_code() ) {
			return self::OUTCOME_PAUSE;
		}

		if ( 409 === $exception->http_status() && self::is_duplicate( $exception ) ) {
			return self::OUTCOME_DELIVERED;
		}

		if ( $exception->is_auth_failure() ) {
			return self::OUTCOME_PAUSE;
		}

		if ( 'timestamp_skew' === $exception->error_code() || ApiException::INVALID_RESPONSE === $exception->error_code() || $exception->is_retryable() ) {
			return self::OUTCOME_RETRY;
		}

		return self::OUTCOME_DEAD;
	}

	/**
	 * Whether delivery is paused.
	 *
	 * @return bool
	 */
	public function is_paused(): bool {
		return '' !== $this->pause_reason();
	}

	/**
	 * Why delivery is paused (empty while delivering).
	 *
	 * @return string One of the PAUSE_* constants, or ''.
	 */
	public function pause_reason(): string {
		return $this->options->get_string( Options::DELIVERY_PAUSED );
	}

	/**
	 * Resume delivery and re-enqueue everything that waited.
	 *
	 * @return void
	 */
	public function resume(): void {
		if ( ! $this->is_paused() ) {
			return;
		}

		$this->options->delete( Options::DELIVERY_PAUSED );
		$this->logger->info( 'Pixalink event delivery resumed.' );
		$this->scheduler->sweep_now();

		/**
		 * Fires when paused event delivery resumes.
		 *
		 * @since 0.1.0
		 */
		do_action( 'pixalink_loyalty_delivery_resumed' );
	}

	/**
	 * Handle a failed attempt.
	 *
	 * @param Event        $event     Event.
	 * @param int          $attempts  Attempts including this one.
	 * @param ApiException $exception Failure.
	 * @param int          $now       Unix time.
	 * @return string Outcome.
	 */
	private function handle_failure( Event $event, int $attempts, ApiException $exception, int $now ): string {
		$outcome = self::classify( $exception );
		$body    = array(
			'code'    => $exception->error_code(),
			'message' => Logger::mask( $exception->getMessage() ),
			'details' => $exception->details(),
		);
		$error   = self::describe( $exception );

		switch ( $outcome ) {
			case self::OUTCOME_DELIVERED:
				$this->succeed(
					$event,
					$attempts,
					$exception->http_status(),
					array(
						'status' => 'duplicate',
						'result' => null,
					),
					$now
				);
				return $outcome;

			case self::OUTCOME_PAUSE:
				$reason = ApiException::NOT_CONNECTED === $exception->error_code() ? self::PAUSE_NOT_CONNECTED : self::PAUSE_AUTH_FAILED;
				$this->store->defer( $event->id, self::utc_datetime( $now ), $reason . ': ' . $error );
				$this->pause( $reason, $error );
				return $outcome;

			case self::OUTCOME_RETRY:
				if ( $this->policy->should_give_up( $attempts ) ) {
					$this->give_up( $event, $attempts, $exception->http_status(), $exception->error_code(), $error . ' (gave up after ' . $attempts . ' attempts)', $body );
					return self::OUTCOME_DEAD;
				}

				$next = $now + $this->policy->delay( $attempts, $exception->retry_after() );
				$this->store->mark_retry( $event->id, $attempts, self::utc_datetime( $next ), $exception->http_status(), $error, $body );
				$this->scheduler->deliver_at( $event->id, $next );
				return $outcome;
		}

		$this->give_up( $event, $attempts, $exception->http_status(), $exception->error_code(), $error, $body );

		return self::OUTCOME_DEAD;
	}

	/**
	 * Record a delivery and notify listeners.
	 *
	 * @param Event        $event       Event.
	 * @param int          $attempts    Attempts including this one.
	 * @param int          $http_status HTTP status.
	 * @param array<mixed> $body        Response body `{status, result}`.
	 * @param int          $now         Unix time.
	 * @return void
	 */
	private function succeed( Event $event, int $attempts, int $http_status, array $body, int $now ): void {
		$this->store->mark_delivered( $event->id, $attempts, $http_status, $body, self::utc_datetime( $now ) );
		$this->resume();

		$this->wake_next( $event );

		$delivered = $this->store->find( $event->id ) ?? $event;

		/**
		 * Fires after Pixalink accepted an event.
		 *
		 * @since 0.1.0
		 *
		 * @param Event        $event  The delivered event.
		 * @param array<mixed> $result Response body `{status: processed|duplicate|accepted|ignored, result}`.
		 */
		do_action( 'pixalink_loyalty_event_delivered', $delivered, $body );
	}

	/**
	 * Mark an event dead and notify listeners.
	 *
	 * @param Event        $event       Event.
	 * @param int          $attempts    Attempts made.
	 * @param int          $http_status HTTP status (0 when none).
	 * @param string       $code        Error code.
	 * @param string       $error       Error description.
	 * @param array<mixed> $body        Error body.
	 * @return void
	 */
	private function give_up( Event $event, int $attempts, int $http_status, string $code, string $error, array $body ): void {
		$this->store->mark_dead( $event->id, $attempts, $http_status, $error, $body );
		$this->wake_next( $event );
		$this->logger->error(
			'Pixalink event could not be delivered and was marked failed.',
			array(
				'event_id' => $event->uuid,
				'type'     => $event->type->value,
				'order_id' => $event->is_for_order() ? $event->object_id : null,
				'attempts' => $attempts,
				'error'    => $error,
			)
		);

		$dead = $this->store->find( $event->id ) ?? $event;

		/**
		 * Fires when an event is marked dead (no more automatic retries).
		 *
		 * @since 0.1.0
		 *
		 * @param Event                $event The failed event.
		 * @param array<string,mixed>  $error `{code, message, http_status, attempts}`.
		 */
		do_action(
			'pixalink_loyalty_event_dead',
			$dead,
			array(
				'code'        => $code,
				'message'     => $error,
				'http_status' => $http_status,
				'attempts'    => $attempts,
			)
		);
	}

	/**
	 * Enqueue the next open event of the same order, which may be waiting behind this one.
	 *
	 * @param Event $event Event that just left the open states.
	 * @return void
	 */
	private function wake_next( Event $event ): void {
		$next = $this->store->open_ids_after( $event );
		if ( array() === $next ) {
			return;
		}

		// Only rows the ordering guard parked are woken; a row in its own backoff keeps its schedule.
		$waiting = $this->store->find( $next[0] );
		if ( null === $waiting || 0 !== strpos( (string) $waiting->last_error, self::WAITING_NOTE ) ) {
			return;
		}

		$this->store->defer( $waiting->id, self::utc_datetime( ( $this->now )() ), '' );
		$this->scheduler->deliver_now( $waiting->id );
	}

	/**
	 * Pause delivery for every event.
	 *
	 * @param string $reason One of the PAUSE_* constants.
	 * @param string $error  What happened.
	 * @return void
	 */
	private function pause( string $reason, string $error ): void {
		if ( $this->pause_reason() === $reason ) {
			return;
		}

		$this->options->set( Options::DELIVERY_PAUSED, $reason );
		$this->logger->error(
			'Pixalink event delivery paused until the store is reconnected.',
			array(
				'reason' => $reason,
				'error'  => $error,
			)
		);

		/**
		 * Fires when event delivery pauses (rejected or missing credentials).
		 *
		 * @since 0.1.0
		 *
		 * @param string $reason "auth_failed" or "not_connected".
		 */
		do_action( 'pixalink_loyalty_delivery_paused', $reason );
	}

	/**
	 * Whether the row is due (actions may be duplicated or run early).
	 *
	 * @param Event $event Event.
	 * @param int   $now   Unix time.
	 * @return bool
	 */
	private function is_due( Event $event, int $now ): bool {
		if ( EventStatus::SENDING === $event->status ) {
			$locked = Event::timestamp( $event->locked_at );

			return null === $locked || $locked < $now - self::LOCK_TIMEOUT;
		}

		$next = Event::timestamp( $event->next_attempt_at );

		return null === $next || $next <= $now + self::EARLY_TOLERANCE;
	}

	/**
	 * Whether a 409 means "already processed".
	 *
	 * @param ApiException $exception Failure.
	 * @return bool
	 */
	private static function is_duplicate( ApiException $exception ): bool {
		$details = $exception->details();

		return in_array( $exception->error_code(), self::DUPLICATE_CODES, true )
			|| true === ( $details['duplicate'] ?? null )
			|| 'duplicate' === ( $details['status'] ?? null );
	}

	/**
	 * One-line error description for the row and the log.
	 *
	 * @param ApiException $exception Failure.
	 * @return string
	 */
	private static function describe( ApiException $exception ): string {
		return sprintf( '%s (HTTP %d): %s', $exception->error_code(), $exception->http_status(), Logger::mask( $exception->getMessage() ) );
	}

	/**
	 * UTC `Y-m-d H:i:s`.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private static function utc_datetime( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
