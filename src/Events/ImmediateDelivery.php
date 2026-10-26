<?php
/**
 * Sends the events a request recorded once its response has gone out.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Attempts delivery of the order and customer events recorded during a request on `shutdown`, after the response has
 * been handed to the browser (`fastcgi_finish_request()` or `litespeed_finish_request()`), instead of waiting for
 * Action Scheduler, which runs on WP-Cron and so only when the store gets traffic.
 *
 * The outbox stays the source of truth and Action Scheduler the fallback: every event is still recorded and its
 * delivery action enqueued by {@see EventQueue} first. This only gets there sooner, through the same
 * {@see EventDeliverer} the queue uses (with a short HTTP timeout), so:
 * - a row is claimed atomically before it is sent, so this attempt and an Action Scheduler run never both send it;
 * - an event is skipped, untouched, while an earlier event of the same order is still open: the queue sends it in order;
 * - a failure is recorded like any other attempt (retry with backoff, dead, or pause) and the queue takes over;
 * - nothing is attempted while delivery is paused (key rejected or missing, store paused by Pixalink);
 * - the events of one request are sent in the order they were recorded, and sending stops at the first one that does
 *   not go through, so an unreachable Pixalink costs one timeout, not one per event.
 *
 * Off under WP-Cron and WP-CLI (the queue is already running there) and on servers that cannot finish the response
 * early, where sending would make the shopper wait; `pixalink_loyalty_send_events_immediately` overrides that.
 */
final class ImmediateDelivery {

	/**
	 * HTTP timeout in seconds of an immediate attempt (the queue's own attempts use the events default).
	 */
	public const TIMEOUT = 5;

	/**
	 * Events attempted per request; the rest are left to the queue.
	 */
	public const MAX_PER_REQUEST = 10;

	/**
	 * `shutdown` priority: after WordPress flushes output buffers (1) and WooCommerce saves the session (20).
	 */
	public const SHUTDOWN_PRIORITY = 1000;

	/**
	 * Outbox storage.
	 *
	 * @var EventStore
	 */
	private EventStore $store;

	/**
	 * Delivery worker with the short timeout.
	 *
	 * @var EventDeliverer
	 */
	private EventDeliverer $deliverer;

	/**
	 * Hands the response to the client so the rest of the request does not keep it waiting.
	 *
	 * @var \Closure():void
	 */
	private \Closure $finish_request;

	/**
	 * Row ids recorded during this request, keyed by id.
	 *
	 * @var array<int,int>
	 */
	private array $ids = array();

	/**
	 * Constructor.
	 *
	 * @param EventStore    $store          Outbox storage.
	 * @param EventDeliverer $deliverer     Delivery worker (built with a {@see ImmediateDelivery::TIMEOUT} sender).
	 * @param \Closure|null $finish_request `fn (): void` (tests); defaults to {@see ImmediateDelivery::finish_request()}.
	 */
	public function __construct( EventStore $store, EventDeliverer $deliverer, ?\Closure $finish_request = null ) {
		$this->store          = $store;
		$this->deliverer      = $deliverer;
		$this->finish_request = $finish_request ?? static function (): void {
			self::finish_request();
		};
	}

	/**
	 * Remember a newly recorded event and hook the attempt at the end of the request.
	 *
	 * @param int       $event_id Row id.
	 * @param EventType $type     Event type.
	 * @return void
	 */
	public function add( int $event_id, EventType $type ): void {
		if ( EventType::PING === $type || ! self::enabled() ) {
			return;
		}

		$this->ids[ $event_id ] = $event_id;

		if ( false === has_action( 'shutdown', array( $this, 'flush' ) ) ) {
			add_action( 'shutdown', array( $this, 'flush' ), self::SHUTDOWN_PRIORITY, 0 );
		}
	}

	/**
	 * Row ids waiting for the end of the request, oldest first.
	 *
	 * @return list<int>
	 */
	public function pending(): array {
		$ids = array_values( $this->ids );
		sort( $ids );

		return $ids;
	}

	/**
	 * `shutdown` callback.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->send();
	}

	/**
	 * Finish the response, then attempt each event recorded in this request.
	 *
	 * @return int Events delivered.
	 */
	public function send(): int {
		$ids       = $this->pending();
		$this->ids = array();
		if ( array() === $ids || $this->deliverer->is_paused() ) {
			return 0;
		}

		// A shopper closing the tab must not cut an attempt off mid-request (its row would stay locked).
		ignore_user_abort( true );
		( $this->finish_request )();

		$attempted = 0;
		$delivered = 0;
		foreach ( $ids as $id ) {
			if ( $attempted >= self::MAX_PER_REQUEST ) {
				break;
			}

			$event = $this->store->find( $id );
			// Only fresh rows: one already retrying, sending or done belongs to the queue.
			if ( null === $event || EventStatus::PENDING !== $event->status || null !== $event->next_attempt_at ) {
				continue;
			}

			if ( null !== $this->store->first_open_before( $event ) ) {
				continue;
			}

			++$attempted;
			$outcome = $this->deliverer->deliver( $id );
			if ( EventDeliverer::OUTCOME_DELIVERED === $outcome ) {
				++$delivered;
			} elseif ( EventDeliverer::OUTCOME_RETRY === $outcome || EventDeliverer::OUTCOME_PAUSE === $outcome ) {
				break;
			}
		}

		return $delivered;
	}

	/**
	 * Whether events recorded in this request are sent at its end.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		$enabled = ! ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			&& ! ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) )
			&& ( function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' ) );

		/**
		 * Filters whether order and customer events are sent to Pixalink at the end of the request that recorded
		 * them, rather than only by the background queue.
		 *
		 * Defaults to true on PHP-FPM and LiteSpeed, outside WP-Cron and WP-CLI. Elsewhere the response cannot be
		 * finished first, so the request would wait for Pixalink (up to five seconds). The queue sends anything this
		 * does not.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $enabled Default.
		 */
		return (bool) apply_filters( 'pixalink_loyalty_send_events_immediately', $enabled );
	}

	/**
	 * Hand the response to the client and keep running.
	 *
	 * @return void
	 */
	public static function finish_request(): void {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
	}
}
