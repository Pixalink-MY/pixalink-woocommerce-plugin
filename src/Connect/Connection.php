<?php
/**
 * Persisted connection state and the cached remote status.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Connect;

use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to `pixalink_loyalty_connection` and `pixalink_loyalty_remote_status`.
 *
 * States: `disconnected` (default, and after a disconnect; organisation/outlet/connected_* are kept as history),
 * `connected`, and `auth_failed` (Pixalink rejected the key; a later successful heartbeat restores `connected`).
 * Secrets never live here; the API key is in {@see \Pixalink\Loyalty\Api\Credentials}.
 *
 * The state of the Pixalink account is the remote status `status` from the last heartbeat: `active`, or one of
 * {@see Connection::PAUSED_STATUSES}, which keep the connection `connected` (the key is still good). A 403
 * `commerce_paused` on any call records `commerce_paused` there too ({@see Connection::mark_commerce_paused()}), so
 * the store stops offering loyalty before the next heartbeat; the next heartbeat replaces it.
 */
final class Connection {

	public const STATUS_DISCONNECTED = 'disconnected';
	public const STATUS_CONNECTED    = 'connected';
	public const STATUS_AUTH_FAILED  = 'auth_failed';

	public const REMOTE_ACTIVE                = 'active';
	public const REMOTE_SUBSCRIPTION_INACTIVE = 'subscription_inactive';
	public const REMOTE_COMMERCE_NOT_ENABLED  = 'commerce_not_enabled';
	public const REMOTE_COMMERCE_PAUSED       = 'commerce_paused';

	/**
	 * Heartbeat statuses that pause the whole programme.
	 */
	public const PAUSED_STATUSES = array( self::REMOTE_SUBSCRIPTION_INACTIVE, self::REMOTE_COMMERCE_NOT_ENABLED, self::REMOTE_COMMERCE_PAUSED );

	public const METHOD_HOSTED  = 'hosted';
	public const METHOD_API_KEY = 'api_key';

	/**
	 * Fired once a connection is recorded ({@see Connection::mark_connected()}).
	 */
	public const CREDENTIALS_CHANGED_ACTION = 'pixalink_loyalty_credentials_changed';

	/**
	 * Option storage.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Local time source (unix seconds).
	 *
	 * @var \Closure():int
	 */
	private \Closure $time_source;

	/**
	 * Constructor.
	 *
	 * @param Options       $options     Option storage.
	 * @param \Closure|null $time_source Returns the local unix time; defaults to time().
	 */
	public function __construct( Options $options, ?\Closure $time_source = null ) {
		$this->options     = $options;
		$this->time_source = $time_source ?? static fn (): int => time();
	}

	/**
	 * The whole connection record with every key present.
	 *
	 * @return array{status:string,organisation:array<string,mixed>|null,outlet:array<string,mixed>|null,connected_at:int|null,connected_by:int|null,method:string|null,api_base_url:string|null,dashboard_url:string|null,disconnected_at:int|null,disconnected_by:int|null,auth_failed_at:int|null,auth_error:string|null}
	 */
	public function data(): array {
		$stored = $this->options->get_array( Options::CONNECTION );
		$status = isset( $stored['status'] ) && in_array( $stored['status'], array( self::STATUS_CONNECTED, self::STATUS_AUTH_FAILED ), true )
			? (string) $stored['status']
			: self::STATUS_DISCONNECTED;

		return array(
			'status'          => $status,
			'organisation'    => self::assoc( $stored['organisation'] ?? null ),
			'outlet'          => self::assoc( $stored['outlet'] ?? null ),
			'connected_at'    => self::int_or_null( $stored['connected_at'] ?? null ),
			'connected_by'    => self::int_or_null( $stored['connected_by'] ?? null ),
			'method'          => self::string_or_null( $stored['method'] ?? null ),
			'api_base_url'    => self::string_or_null( $stored['api_base_url'] ?? null ),
			'dashboard_url'   => self::string_or_null( $stored['dashboard_url'] ?? null ),
			'disconnected_at' => self::int_or_null( $stored['disconnected_at'] ?? null ),
			'disconnected_by' => self::int_or_null( $stored['disconnected_by'] ?? null ),
			'auth_failed_at'  => self::int_or_null( $stored['auth_failed_at'] ?? null ),
			'auth_error'      => self::string_or_null( $stored['auth_error'] ?? null ),
		);
	}

	/**
	 * Current status (one of the STATUS_* constants).
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->data()['status'];
	}

	/**
	 * Whether the store is connected and the key was last accepted.
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		return self::STATUS_CONNECTED === $this->status();
	}

	/**
	 * Whether a connection exists (connected or with a rejected key).
	 *
	 * @return bool
	 */
	public function exists(): bool {
		return self::STATUS_DISCONNECTED !== $this->status();
	}

	/**
	 * Record a fresh connection.
	 *
	 * @param array<string,mixed>      $organisation  `{id, name}` (empty when unknown).
	 * @param array<string,mixed>      $outlet        `{id, uuid, name, ...}`.
	 * @param int                      $user_id       Admin who connected.
	 * @param string                   $method        METHOD_HOSTED or METHOD_API_KEY.
	 * @param string|null              $api_base_url  API base the key belongs to.
	 * @param string|null              $dashboard_url Pixalink dashboard link.
	 * @return void
	 */
	public function mark_connected( array $organisation, array $outlet, int $user_id, string $method, ?string $api_base_url, ?string $dashboard_url ): void {
		$this->write(
			array(
				'status'        => self::STATUS_CONNECTED,
				'organisation'  => array() === $organisation ? null : $organisation,
				'outlet'        => $outlet,
				'connected_at'  => $this->now(),
				'connected_by'  => $user_id > 0 ? $user_id : null,
				'method'        => $method,
				'api_base_url'  => $api_base_url,
				'dashboard_url' => $dashboard_url,
			)
		);

		/**
		 * Fires after a connection to Pixalink was recorded: hosted sign-in, a pasted key, or the key from
		 * wp-config.php adopted. The key, and so the outlet, may differ from before even when the stored
		 * credentials option did not change (the wp-config.php key never touches it). The events unit forgets the
		 * cached outlet id and lifts a delivery pause.
		 *
		 * @since 0.3.0
		 */
		do_action( 'pixalink_loyalty_credentials_changed' );
	}

	/**
	 * Refresh the outlet/organisation details reported by Pixalink (renames), merged into what is known.
	 *
	 * @param array<string,mixed>|null $outlet       Outlet, or null to keep.
	 * @param array<string,mixed>|null $organisation Organisation, or null to keep.
	 * @return void
	 */
	public function update_details( ?array $outlet, ?array $organisation ): void {
		$stored = $this->options->get_array( Options::CONNECTION );
		if ( array() === $stored ) {
			return;
		}

		// Merge so a sparse record (the heartbeat's outlet has no organisation) never drops known fields.
		if ( null !== $outlet && array() !== $outlet ) {
			$stored['outlet'] = array_merge( is_array( $stored['outlet'] ?? null ) ? $stored['outlet'] : array(), $outlet );
		}
		if ( null !== $organisation && array() !== $organisation ) {
			$stored['organisation'] = array_merge( is_array( $stored['organisation'] ?? null ) ? $stored['organisation'] : array(), $organisation );
		}

		$this->options->set( Options::CONNECTION, $stored );
	}

	/**
	 * Pixalink rejected the key (401, or a 403 that is not a subscription/feature state).
	 *
	 * @param string $error_code Server error code.
	 * @return void
	 */
	public function mark_auth_failed( string $error_code ): void {
		$stored = $this->options->get_array( Options::CONNECTION );
		if ( ! $this->exists() ) {
			return;
		}

		$stored['status']         = self::STATUS_AUTH_FAILED;
		$stored['auth_failed_at'] = $this->now();
		$stored['auth_error']     = $error_code;
		$this->options->set( Options::CONNECTION, $stored );
	}

	/**
	 * A request authenticated again after an auth failure.
	 *
	 * @return void
	 */
	public function mark_recovered(): void {
		if ( self::STATUS_AUTH_FAILED !== $this->status() ) {
			return;
		}

		$stored           = $this->options->get_array( Options::CONNECTION );
		$stored['status'] = self::STATUS_CONNECTED;
		unset( $stored['auth_failed_at'], $stored['auth_error'] );
		$this->options->set( Options::CONNECTION, $stored );
	}

	/**
	 * Forget the live connection but keep its history (organisation, outlet, connected_at/by).
	 *
	 * @param int $user_id Admin who disconnected.
	 * @return void
	 */
	public function mark_disconnected( int $user_id ): void {
		$stored = $this->options->get_array( Options::CONNECTION );
		unset( $stored['auth_failed_at'], $stored['auth_error'] );

		$stored['status']          = self::STATUS_DISCONNECTED;
		$stored['disconnected_at'] = $this->now();
		$stored['disconnected_by'] = $user_id > 0 ? $user_id : null;
		$this->options->set( Options::CONNECTION, $stored );
		$this->options->delete( Options::REMOTE_STATUS );
	}

	/**
	 * Cached heartbeat state.
	 *
	 * @return array<mixed> Heartbeat response keys plus `received_at`, `last_attempt_at`, `last_error`.
	 */
	public function remote_status(): array {
		return $this->options->get_array( Options::REMOTE_STATUS );
	}

	/**
	 * Cache a successful heartbeat response.
	 *
	 * @param array<mixed> $response Heartbeat response.
	 * @return void
	 */
	public function store_remote_status( array $response ): void {
		$now = $this->now();

		$this->options->set(
			Options::REMOTE_STATUS,
			array_merge(
				$response,
				array(
					'received_at'     => $now,
					'last_attempt_at' => $now,
					'last_error'      => null,
				)
			)
		);
	}

	/**
	 * Remember a failed heartbeat without losing the last good response.
	 *
	 * @param ApiException $exception Failure.
	 * @return void
	 */
	public function record_heartbeat_error( ApiException $exception ): void {
		$status                    = $this->remote_status();
		$status['last_attempt_at'] = $this->now();
		$status['last_error']      = array(
			'code'   => $exception->error_code(),
			'status' => $exception->http_status(),
			'at'     => $this->now(),
		);
		$this->options->set( Options::REMOTE_STATUS, $status );
	}

	/**
	 * Pixalink answered 403 `commerce_paused`: record the store as paused, every remote feature off, until the next
	 * heartbeat says otherwise. The last heartbeat's other details (earn timing, branding, ...) are kept.
	 *
	 * @return void
	 */
	public function mark_commerce_paused(): void {
		if ( ! $this->exists() ) {
			return;
		}

		$status   = $this->remote_status();
		$features = is_array( $status['features'] ?? null ) ? $status['features'] : array();
		foreach ( $features as $feature => $value ) {
			if ( is_bool( $value ) ) {
				$features[ $feature ] = false;
			}
		}

		if ( self::REMOTE_COMMERCE_PAUSED === ( $status['status'] ?? null ) && ( $status['features'] ?? array() ) === $features ) {
			return;
		}

		$status['status']   = self::REMOTE_COMMERCE_PAUSED;
		$status['features'] = $features;
		$this->options->set( Options::REMOTE_STATUS, $status );
	}

	/**
	 * Whether Pixalink staff paused this store (last heartbeat, or a 403 `commerce_paused` since).
	 *
	 * @return bool
	 */
	public function is_commerce_paused(): bool {
		return self::REMOTE_COMMERCE_PAUSED === ( $this->remote_status()['status'] ?? null );
	}

	/**
	 * Whether Pixalink reports the account paused: subscription inactive, integration not enabled, or store paused.
	 *
	 * @return bool
	 */
	public function is_account_paused(): bool {
		return in_array( $this->remote_status()['status'] ?? null, self::PAUSED_STATUSES, true );
	}

	/**
	 * Unix time of the last successful heartbeat.
	 *
	 * @return int|null
	 */
	public function last_heartbeat_at(): ?int {
		return self::int_or_null( $this->remote_status()['received_at'] ?? null );
	}

	/**
	 * A remote kill switch from the last heartbeat (`earn`, `vouchers`, `points_redemption`, `credits`).
	 *
	 * Unknown before the first heartbeat: features default to off so nothing is offered unconfirmed.
	 *
	 * @param string $feature Feature name.
	 * @return bool
	 */
	public function feature( string $feature ): bool {
		$features = $this->remote_status()['features'] ?? array();

		return is_array( $features ) && isset( $features[ $feature ] ) && true === $features[ $feature ];
	}

	/**
	 * Minimum plugin version Pixalink supports, from the last heartbeat.
	 *
	 * @return string|null
	 */
	public function min_plugin_version(): ?string {
		return self::string_or_null( $this->remote_status()['min_plugin_version'] ?? null );
	}

	/**
	 * Whether the installed plugin is older than Pixalink's minimum.
	 *
	 * @param string $installed_version Installed plugin version.
	 * @return bool
	 */
	public function is_plugin_outdated( string $installed_version ): bool {
		$minimum = $this->min_plugin_version();

		return null !== $minimum && version_compare( $installed_version, $minimum, '<' );
	}

	/**
	 * Write the whole record.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	private function write( array $record ): void {
		$this->options->set( Options::CONNECTION, $record );
	}

	/**
	 * Current local time.
	 *
	 * @return int
	 */
	private function now(): int {
		return ( $this->time_source )();
	}

	/**
	 * A string-keyed array or null.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>|null
	 */
	private static function assoc( $value ): ?array {
		if ( ! is_array( $value ) || array() === $value ) {
			return null;
		}

		$assoc = array();
		foreach ( $value as $key => $item ) {
			$assoc[ (string) $key ] = $item;
		}

		return $assoc;
	}

	/**
	 * A positive int or null.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private static function int_or_null( $value ): ?int {
		return is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
	}

	/**
	 * A non-empty string or null.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private static function string_or_null( $value ): ?string {
		return is_string( $value ) && '' !== $value ? $value : null;
	}
}
