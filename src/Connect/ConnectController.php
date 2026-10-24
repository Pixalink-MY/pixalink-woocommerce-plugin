<?php
/**
 * Admin request handlers for the Connect wizard.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Connect;

use Pixalink\Loyalty\Api\Resources\Connect;

defined( 'ABSPATH' ) || exit;

/**
 * `admin-post.php` actions (nonce + `manage_woocommerce`) and the hosted-connect callback on `admin_init`.
 *
 * Every handler ends in a redirect back to the settings tab carrying `pixalink_notice={outcome}` (a fixed code,
 * never server text), which {@see \Pixalink\Loyalty\Admin\Notices} turns into a translated message.
 * The `handle_*` methods return the redirect target so tests can drive them without exiting.
 */
final class ConnectController {

	public const CAPABILITY = 'manage_woocommerce';

	/**
	 * Settings tab id (kept here, not on SettingsPage: touching that class outside WooCommerce settings would
	 * autoload a WC_Settings_Page subclass before WooCommerce has loaded its parent).
	 */
	public const SETTINGS_TAB = 'pixalink_loyalty';

	public const ACTION_START        = 'pixalink_loyalty_connect_start';
	public const ACTION_KEY          = 'pixalink_loyalty_connect_key';
	public const ACTION_DISCONNECT   = 'pixalink_loyalty_disconnect';
	public const ACTION_TEST         = 'pixalink_loyalty_test';
	public const ACTION_PAUSE_LEGACY = 'pixalink_loyalty_pause_legacy';

	/**
	 * Nonce field name per action (all fields live in WooCommerce's settings form, so names must differ).
	 */
	public const NONCE_FIELDS = array(
		self::ACTION_START        => '_pixalink_start_nonce',
		self::ACTION_KEY          => '_pixalink_key_nonce',
		self::ACTION_DISCONNECT   => '_pixalink_disconnect_nonce',
		self::ACTION_TEST         => '_pixalink_test_nonce',
		self::ACTION_PAUSE_LEGACY => '_pixalink_pause_legacy_nonce',
	);

	public const FIELD_API_KEY           = 'pixalink_loyalty_api_key';
	public const FIELD_INTENT            = 'pixalink_loyalty_intent';
	public const FIELD_PAUSE_LEGACY      = 'pixalink_loyalty_pause_legacy';
	public const FIELD_MARKETING_CONSENT = 'pixalink_loyalty_marketing_consent';

	/**
	 * Marks that the consent box was rendered, so "unticked" is recorded as an explicit no.
	 */
	public const FIELD_CONSENT_SHOWN = 'pixalink_loyalty_marketing_consent_shown';

	public const NOTICE_ARG = 'pixalink_notice';

	/**
	 * Connect service.
	 *
	 * @var ConnectService
	 */
	private ConnectService $service;

	/**
	 * Constructor.
	 *
	 * @param ConnectService $service Connect service.
	 */
	public function __construct( ConnectService $service ) {
		$this->service = $service;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_START, array( $this, 'start' ) );
		add_action( 'admin_post_' . self::ACTION_KEY, array( $this, 'connect_key' ) );
		add_action( 'admin_post_' . self::ACTION_DISCONNECT, array( $this, 'disconnect' ) );
		add_action( 'admin_post_' . self::ACTION_TEST, array( $this, 'test' ) );
		add_action( 'admin_post_' . self::ACTION_PAUSE_LEGACY, array( $this, 'pause_legacy' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_callback' ) );
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_pixalink_host' ) );
		add_filter( 'removable_query_args', array( $this, 'removable_query_args' ) );
	}

	/**
	 * URL of the plugin's settings tab.
	 *
	 * @param array<string,string> $args Extra query arguments.
	 * @return string
	 */
	public static function settings_url( array $args = array() ): string {
		$url = admin_url( 'admin.php?page=wc-settings&tab=' . self::SETTINGS_TAB );

		return array() === $args ? $url : add_query_arg( array_map( 'rawurlencode', $args ), $url );
	}

	/**
	 * Let wp_safe_redirect() send the admin to the hosted connect page.
	 *
	 * @param mixed $hosts Allowed hosts.
	 * @return array<mixed>
	 */
	public function allow_pixalink_host( $hosts ): array {
		return array_values( array_unique( array_merge( is_array( $hosts ) ? $hosts : array(), $this->service->redirect_hosts() ) ) );
	}

	/**
	 * Strip the one-shot notice argument from the address bar after display.
	 *
	 * @param mixed $args Removable query arguments.
	 * @return array<mixed>
	 */
	public function removable_query_args( $args ): array {
		$args   = is_array( $args ) ? $args : array();
		$args[] = self::NOTICE_ARG;

		return $args;
	}

	/**
	 * `admin_post_pixalink_loyalty_connect_start`.
	 *
	 * @return void
	 */
	public function start(): void {
		$this->guard( self::ACTION_START );
		$this->redirect( $this->handle_start( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
	}

	/**
	 * `admin_post_pixalink_loyalty_connect_key`.
	 *
	 * @return void
	 */
	public function connect_key(): void {
		$this->guard( self::ACTION_KEY );
		$this->redirect( $this->handle_connect_key( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
	}

	/**
	 * `admin_post_pixalink_loyalty_disconnect`.
	 *
	 * @return void
	 */
	public function disconnect(): void {
		$this->guard( self::ACTION_DISCONNECT );
		$this->redirect( $this->handle_disconnect() );
	}

	/**
	 * `admin_post_pixalink_loyalty_test`.
	 *
	 * @return void
	 */
	public function test(): void {
		$this->guard( self::ACTION_TEST );
		$this->redirect( $this->handle_test() );
	}

	/**
	 * `admin_post_pixalink_loyalty_pause_legacy`.
	 *
	 * @return void
	 */
	public function pause_legacy(): void {
		$this->guard( self::ACTION_PAUSE_LEGACY );
		$this->redirect( $this->handle_pause_legacy() );
	}

	/**
	 * `admin_init`: handle `wc-settings&tab=pixalink_loyalty&pixalink_connect=callback`.
	 *
	 * @return void
	 */
	public function maybe_handle_callback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The PKCE state bound to the user is the CSRF check.
		$query = wp_unslash( $_GET );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! self::is_callback( $query ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to connect this store to Pixalink.', 'pixalink-loyalty-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$this->redirect( $this->handle_callback( $query ) );
	}

	/**
	 * Whether a query is the hosted-connect callback.
	 *
	 * @param mixed $query Query parameters.
	 * @return bool
	 */
	public static function is_callback( $query ): bool {
		return is_array( $query )
			&& 'wc-settings' === ( $query['page'] ?? null )
			&& self::SETTINGS_TAB === ( $query['tab'] ?? null )
			&& 'callback' === ( $query['pixalink_connect'] ?? null );
	}

	/**
	 * Start the hosted flow (or request access).
	 *
	 * @param mixed $post Unslashed POST data.
	 * @return string Redirect target (the hosted page, or the settings tab on failure).
	 */
	public function handle_start( $post ): string {
		$post   = is_array( $post ) ? $post : array();
		$intent = Connect::INTENT_REQUEST_ACCESS === self::field( $post, self::FIELD_INTENT ) ? Connect::INTENT_REQUEST_ACCESS : Connect::INTENT_CONNECT;

		try {
			return $this->service->start_hosted( get_current_user_id(), $intent, self::checked( $post, self::FIELD_PAUSE_LEGACY ), self::consent( $post ) );
		} catch ( ConnectException $exception ) {
			return self::settings_url( array( self::NOTICE_ARG => 'error_' . $exception->reason() ) );
		}
	}

	/**
	 * Connect with a pasted key (or the wp-config key when no key was pasted and one is configured).
	 *
	 * @param mixed $post Unslashed POST data.
	 * @return string Redirect target.
	 */
	public function handle_connect_key( $post ): string {
		$post    = is_array( $post ) ? $post : array();
		$user_id = get_current_user_id();
		$key     = self::field( $post, self::FIELD_API_KEY );
		$consent = self::consent( $post );
		$pause   = self::checked( $post, self::FIELD_PAUSE_LEGACY );

		try {
			if ( null !== $consent ) {
				$this->service->record_marketing_consent( $user_id, $consent );
			}
			$paused = '' === $key ? $this->service->adopt_configured_key( $user_id, $pause ) : $this->service->connect_with_key( $user_id, $key, $pause );
		} catch ( ConnectException $exception ) {
			return self::settings_url( array( self::NOTICE_ARG => 'error_' . $exception->reason() ) );
		}

		return self::settings_url( array( self::NOTICE_ARG => $paused > 0 ? 'connected_legacy_paused' : ConnectService::OUTCOME_CONNECTED ) );
	}

	/**
	 * Hosted-connect callback.
	 *
	 * @param array<mixed> $query Unslashed query.
	 * @return string Redirect target.
	 */
	public function handle_callback( array $query ): string {
		$user_id = get_current_user_id();
		$state   = self::field( $query, 'state' );
		$error   = self::field( $query, 'error' );

		try {
			if ( '' !== $error ) {
				$outcome = $this->service->fail_hosted( $user_id, $state, $error, self::field( $query, 'reason' ) );

				return self::settings_url( array( self::NOTICE_ARG => $outcome ) );
			}

			$paused = $this->service->complete_hosted( $user_id, self::field( $query, 'code' ), $state );
		} catch ( ConnectException $exception ) {
			return self::settings_url( array( self::NOTICE_ARG => 'error_' . $exception->reason() ) );
		}

		return self::settings_url( array( self::NOTICE_ARG => $paused > 0 ? 'connected_legacy_paused' : ConnectService::OUTCOME_CONNECTED ) );
	}

	/**
	 * Disconnect.
	 *
	 * @return string Redirect target.
	 */
	public function handle_disconnect(): string {
		$acknowledged = $this->service->disconnect( get_current_user_id() );

		return self::settings_url( array( self::NOTICE_ARG => $acknowledged ? 'disconnected' : 'disconnected_locally' ) );
	}

	/**
	 * Test the connection.
	 *
	 * @return string Redirect target.
	 */
	public function handle_test(): string {
		try {
			$this->service->test();
		} catch ( ConnectException $exception ) {
			return self::settings_url( array( self::NOTICE_ARG => 'error_' . $exception->reason() ) );
		}

		return self::settings_url( array( self::NOTICE_ARG => 'test_ok' ) );
	}

	/**
	 * Pause legacy webhooks while connected.
	 *
	 * @return string Redirect target.
	 */
	public function handle_pause_legacy(): string {
		$paused = $this->service->pause_legacy_webhooks();

		return self::settings_url( array( self::NOTICE_ARG => $paused > 0 ? 'legacy_paused' : 'legacy_none' ) );
	}

	/**
	 * Capability + nonce check for an admin-post action.
	 *
	 * @param string $action Action name.
	 * @return void
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage the Pixalink connection.', 'pixalink-loyalty-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $action, self::NONCE_FIELDS[ $action ] );
	}

	/**
	 * Redirect and stop (the Pixalink host is allowed via {@see ConnectController::allow_pixalink_host()}).
	 *
	 * @param string $location Target.
	 * @return void
	 */
	private function redirect( string $location ): void {
		wp_safe_redirect( $location, 303 );
		exit;
	}

	/**
	 * A trimmed scalar field.
	 *
	 * @param array<mixed> $data Data.
	 * @param string       $name Field.
	 * @return string
	 */
	private static function field( array $data, string $name ): string {
		return isset( $data[ $name ] ) && is_scalar( $data[ $name ] ) ? sanitize_text_field( (string) $data[ $name ] ) : '';
	}

	/**
	 * Whether a checkbox was ticked.
	 *
	 * @param array<mixed> $data Data.
	 * @param string       $name Field.
	 * @return bool
	 */
	private static function checked( array $data, string $name ): bool {
		return in_array( self::field( $data, $name ), array( '1', 'yes', 'on' ), true );
	}

	/**
	 * The marketing choice, or null when the box was not on the page.
	 *
	 * @param array<mixed> $data Data.
	 * @return bool|null
	 */
	private static function consent( array $data ): ?bool {
		return self::checked( $data, self::FIELD_CONSENT_SHOWN ) ? self::checked( $data, self::FIELD_MARKETING_CONSENT ) : null;
	}
}
