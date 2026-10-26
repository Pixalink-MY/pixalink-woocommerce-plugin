<?php
/**
 * Periodic connection heartbeat.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Connect;

use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Api\Resources\Connect;
use Pixalink\Loyalty\Install\Installer;
use Pixalink\Loyalty\Support\Clock;
use Pixalink\Loyalty\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * `POST connection/heartbeat` every 6 hours (Action Scheduler) and on demand.
 *
 * A successful response is cached in `pixalink_loyalty_remote_status` (remote kill switches, earn timing,
 * minimum plugin version) and re-aligns {@see Clock} from `server_time`. A rejected key (401, or a 403 other than
 * a subscription/feature state) marks the connection `auth_failed`; the next accepted heartbeat restores it.
 *
 * Pixalink can pause one outlet's store: the heartbeat then answers `status: commerce_paused` with every feature off,
 * and every other signed call 403 `commerce_paused` ({@see Heartbeat::on_commerce_paused()} records that at once). The
 * first heartbeat that reports `active` again fires `pixalink_loyalty_heartbeat_received`, which resumes event delivery.
 */
final class Heartbeat {

	public const HOOK = 'pixalink_loyalty_heartbeat';

	/**
	 * Interval in seconds (6 hours).
	 */
	public const INTERVAL = 21600;

	/**
	 * 403 codes that describe the Pixalink account rather than the key; the connection stays `connected`.
	 */
	public const ACCOUNT_STATE_CODES = ApiException::ACCOUNT_STATE_CODES;

	/**
	 * Connect API resource.
	 *
	 * @var Connect
	 */
	private Connect $api;

	/**
	 * Credentials.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Connection state.
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * Server-aligned clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private string $plugin_version;

	/**
	 * Constructor.
	 *
	 * @param Connect     $api            Connect API resource.
	 * @param Credentials $credentials    Credentials.
	 * @param Connection  $connection     Connection state.
	 * @param Clock       $clock          Server-aligned clock.
	 * @param Logger      $logger         Logger.
	 * @param string      $plugin_version Plugin version.
	 */
	public function __construct( Connect $api, Credentials $credentials, Connection $connection, Clock $clock, Logger $logger, string $plugin_version ) {
		$this->api            = $api;
		$this->credentials    = $credentials;
		$this->connection     = $connection;
		$this->clock          = $clock;
		$this->logger         = $logger;
		$this->plugin_version = $plugin_version;
	}

	/**
	 * Register the scheduled action callback and self-repair of the schedule.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'handle_scheduled' ) );
		add_action( 'admin_init', array( $this, 'ensure_scheduled' ) );
		add_action( 'pixalink_loyalty_commerce_paused', array( $this, 'on_commerce_paused' ), 10, 0 );
	}

	/**
	 * `pixalink_loyalty_commerce_paused` callback: a call came back 403 `commerce_paused`, so stop offering loyalty now
	 * rather than at the next heartbeat (which keeps the state, or clears it once Pixalink resumes the store).
	 *
	 * @return void
	 */
	public function on_commerce_paused(): void {
		$this->connection->mark_commerce_paused();
	}

	/**
	 * Action Scheduler callback: run, log failures, never throw (a throw marks the action failed noisily).
	 *
	 * @return void
	 */
	public function handle_scheduled(): void {
		if ( ! $this->should_run() ) {
			$this->unschedule();
			return;
		}

		try {
			$this->run();
		} catch ( ApiException $exception ) {
			// Recorded in the remote status by run(); nothing else to do until the next interval.
			unset( $exception );
		}
	}

	/**
	 * Keep exactly one recurring action while connected (cheap on admin_init: one indexed lookup).
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( $this->should_run() ) {
			$this->schedule();
		}
	}

	/**
	 * Whether a heartbeat makes sense: a connection exists and a key is available.
	 *
	 * @return bool
	 */
	public function should_run(): bool {
		return $this->connection->exists() && $this->credentials->is_configured();
	}

	/**
	 * Schedule the recurring heartbeat unless one exists.
	 *
	 * @return void
	 */
	public function schedule(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || $this->is_scheduled() ) {
			return;
		}

		as_schedule_recurring_action( time() + self::INTERVAL, self::INTERVAL, self::HOOK, array(), Installer::ACTION_GROUP, true );
	}

	/**
	 * Remove the recurring heartbeat.
	 *
	 * @return void
	 */
	public function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), Installer::ACTION_GROUP );
		}
	}

	/**
	 * Whether the recurring heartbeat is scheduled.
	 *
	 * @return bool
	 */
	public function is_scheduled(): bool {
		return function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, array(), Installer::ACTION_GROUP );
	}

	/**
	 * Send a heartbeat now and record the outcome.
	 *
	 * @return array<mixed> Heartbeat response.
	 * @throws ApiException On failure (already recorded).
	 */
	public function run(): array {
		try {
			$response = $this->request();
		} catch ( ApiException $exception ) {
			$this->record_failure( $exception );
			throw $exception;
		}

		$this->apply( $response );

		return $response;
	}

	/**
	 * Send a heartbeat without touching any stored state (used to probe a pasted key).
	 *
	 * @return array<mixed> Heartbeat response.
	 * @throws ApiException On failure.
	 */
	public function request(): array {
		return $this->api->heartbeat( $this->payload() );
	}

	/**
	 * Store a successful response: remote status cache, clock offset, recovered auth, outlet details.
	 *
	 * @param array<mixed> $response Heartbeat response.
	 * @return void
	 */
	public function apply( array $response ): void {
		$server_time = Clock::parse_server_time( $response['server_time'] ?? null );
		if ( null !== $server_time ) {
			$this->clock->sync_to( $server_time );
		}

		$this->connection->store_remote_status( $response );
		$this->connection->mark_recovered();

		if ( isset( $response['outlet'] ) && is_array( $response['outlet'] ) ) {
			$this->connection->update_details( self::string_keys( $response['outlet'] ), null );
		}

		/**
		 * Fires after an accepted heartbeat was stored (the events unit resumes delivery Pixalink paused once the
		 * status is `active` again).
		 *
		 * @since 0.3.0
		 *
		 * @param array<mixed> $response Heartbeat response (`status`, `features`, `earn_on`, ...).
		 */
		do_action( 'pixalink_loyalty_heartbeat_received', $response );
	}

	/**
	 * Body for the heartbeat; queue statistics come from the outbox once it exists (P05 hooks the filter).
	 *
	 * @return array{site:string,plugin_version:string,wc_version:string,pending:int,failed:int,oldest_pending_at:string|null}
	 */
	public function payload(): array {
		/**
		 * Filters the outbox statistics reported in the heartbeat.
		 *
		 * @since 0.1.0
		 *
		 * @param array{pending:int,failed:int,oldest_pending_at:string|null} $stats Pending and failed event counts, and the ISO 8601 UTC time of the oldest pending event.
		 */
		$stats = apply_filters(
			'pixalink_loyalty_heartbeat_queue_stats',
			array(
				'pending'           => 0,
				'failed'            => 0,
				'oldest_pending_at' => null,
			)
		);
		$stats = is_array( $stats ) ? $stats : array();

		$oldest = isset( $stats['oldest_pending_at'] ) && is_string( $stats['oldest_pending_at'] ) && '' !== $stats['oldest_pending_at'] ? $stats['oldest_pending_at'] : null;

		return Connect::heartbeat_payload(
			(string) home_url(),
			$this->plugin_version,
			LeadPayload::wc_version(),
			isset( $stats['pending'] ) && is_numeric( $stats['pending'] ) ? (int) $stats['pending'] : 0,
			isset( $stats['failed'] ) && is_numeric( $stats['failed'] ) ? (int) $stats['failed'] : 0,
			$oldest
		);
	}

	/**
	 * Whether an API failure means the key itself was rejected.
	 *
	 * @param ApiException $exception Failure.
	 * @return bool
	 */
	public static function is_key_rejection( ApiException $exception ): bool {
		return $exception->is_auth_failure() && ! $exception->is_account_state();
	}

	/**
	 * Record a failed heartbeat.
	 *
	 * @param ApiException $exception Failure.
	 * @return void
	 */
	private function record_failure( ApiException $exception ): void {
		$this->connection->record_heartbeat_error( $exception );

		if ( self::is_key_rejection( $exception ) ) {
			$this->connection->mark_auth_failed( $exception->error_code() );
			$this->logger->error(
				'Pixalink rejected the outlet API key; the store must reconnect.',
				array(
					'status'     => $exception->http_status(),
					'error_code' => $exception->error_code(),
				)
			);
			return;
		}

		$this->logger->warning(
			'Pixalink heartbeat failed.',
			array(
				'status'     => $exception->http_status(),
				'error_code' => $exception->error_code(),
			)
		);
	}

	/**
	 * Re-key an array with string keys.
	 *
	 * @param array<mixed> $value Array.
	 * @return array<string,mixed>
	 */
	private static function string_keys( array $value ): array {
		$keyed = array();
		foreach ( $value as $key => $item ) {
			$keyed[ (string) $key ] = $item;
		}

		return $keyed;
	}
}
