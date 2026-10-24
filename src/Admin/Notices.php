<?php
/**
 * Admin notices about the Pixalink connection.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Admin;

use Pixalink\Loyalty\Connect\ConnectController;
use Pixalink\Loyalty\Connect\ConnectException;
use Pixalink\Loyalty\Connect\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * State notices (not connected, key rejected, plugin too old, account inactive, coupons disabled) and the one-shot
 * result notice after a Connect action (`?pixalink_notice={code}`).
 *
 * State notices show only on the Plugins screen and WooCommerce screens, to users who can manage
 * WooCommerce. "Not connected" and "coupons disabled" can be dismissed per user.
 */
final class Notices {

	public const NOT_CONNECTED    = 'not_connected';
	public const AUTH_FAILED      = 'auth_failed';
	public const PLUGIN_OUTDATED  = 'plugin_outdated';
	public const ACCOUNT_INACTIVE = 'account_inactive';
	public const COUPONS_DISABLED = 'coupons_disabled';

	public const DISMISSIBLE = array( self::NOT_CONNECTED, self::COUPONS_DISABLED );

	public const USER_META      = 'pixalink_loyalty_dismissed_notices';
	public const ACTION_DISMISS = 'pixalink_loyalty_dismiss_notice';

	/**
	 * Connection state.
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * Installed plugin version.
	 *
	 * @var string
	 */
	private string $plugin_version;

	/**
	 * Constructor.
	 *
	 * @param Connection $connection     Connection state.
	 * @param string     $plugin_version Installed plugin version.
	 */
	public function __construct( Connection $connection, string $plugin_version ) {
		$this->connection     = $connection;
		$this->plugin_version = $plugin_version;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION_DISMISS, array( $this, 'dismiss' ) );
	}

	/**
	 * `admin_notices` callback.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( ConnectController::CAPABILITY ) ) {
			return;
		}

		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = null === $screen ? '' : (string) $screen->id;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only query arguments.
		$on_tab = ConnectController::SETTINGS_TAB === ( isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '' ) && 'woocommerce_page_wc-settings' === $screen_id;
		$result = isset( $_GET[ ConnectController::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ ConnectController::NOTICE_ARG ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$notices = $this->notices_for_screen( $screen_id, $on_tab );
		if ( $on_tab && '' !== $result ) {
			$flash = self::result_notice( $result );
			if ( null !== $flash ) {
				array_unshift( $notices, $flash );
			}
		}

		foreach ( $notices as $notice ) {
			$this->print_notice( $notice );
		}
	}

	/**
	 * State notices that apply to a screen for the current user.
	 *
	 * @param string $screen_id Current screen id.
	 * @param bool   $on_tab    Whether this is the plugin's own settings tab (the card already explains the state).
	 * @return list<array{id:string,type:string,message:string,dismissible:bool}>
	 */
	public function notices_for_screen( string $screen_id, bool $on_tab = false ): array {
		if ( ! self::is_relevant_screen( $screen_id ) ) {
			return array();
		}

		$dismissed = $this->dismissed();
		$notices   = array();
		$status    = $this->connection->status();

		if ( Connection::STATUS_DISCONNECTED === $status && ! $on_tab && ! in_array( self::NOT_CONNECTED, $dismissed, true ) ) {
			$notices[] = self::notice(
				self::NOT_CONNECTED,
				'info',
				sprintf(
					/* translators: %s: link to the plugin settings. */
					__( 'Pixalink Loyalty is installed but not connected yet. %s to let shoppers earn points on their orders.', 'pixalink-loyalty-for-woocommerce' ),
					'<a href="' . esc_url( ConnectController::settings_url() ) . '">' . esc_html__( 'Connect your store', 'pixalink-loyalty-for-woocommerce' ) . '</a>'
				)
			);
		}

		if ( Connection::STATUS_AUTH_FAILED === $status && ! $on_tab ) {
			$notices[] = self::notice(
				self::AUTH_FAILED,
				'error',
				sprintf(
					/* translators: %s: link to the plugin settings. */
					__( 'Pixalink no longer accepts this store\'s connection, so orders are not earning points. %s.', 'pixalink-loyalty-for-woocommerce' ),
					'<a href="' . esc_url( ConnectController::settings_url() ) . '">' . esc_html__( 'Reconnect to Pixalink', 'pixalink-loyalty-for-woocommerce' ) . '</a>'
				)
			);
		}

		if ( ! $this->connection->exists() ) {
			return $notices;
		}

		if ( $this->connection->is_plugin_outdated( $this->plugin_version ) ) {
			$notices[] = self::notice(
				self::PLUGIN_OUTDATED,
				'warning',
				sprintf(
					/* translators: 1: installed version, 2: minimum version. */
					esc_html__( 'Pixalink needs Pixalink Loyalty for WooCommerce %2$s or newer (this site runs %1$s). Update the plugin so loyalty keeps working.', 'pixalink-loyalty-for-woocommerce' ),
					esc_html( $this->plugin_version ),
					esc_html( (string) $this->connection->min_plugin_version() )
				)
			);
		}

		if ( in_array( $this->connection->remote_status()['status'] ?? null, array( 'subscription_inactive', 'commerce_not_enabled' ), true ) ) {
			$notices[] = self::notice(
				self::ACCOUNT_INACTIVE,
				'warning',
				esc_html__( 'Pixalink reports that loyalty for this online store is paused (subscription inactive or online-store integration switched off). Contact Pixalink to resume it.', 'pixalink-loyalty-for-woocommerce' )
			);
		}

		if ( function_exists( 'wc_coupons_enabled' ) && ! wc_coupons_enabled() && ! in_array( self::COUPONS_DISABLED, $dismissed, true ) ) {
			$notices[] = self::notice(
				self::COUPONS_DISABLED,
				'warning',
				sprintf(
					/* translators: %s: link to WooCommerce general settings. */
					__( 'Pixalink vouchers and points are applied as coupons, but coupons are disabled on this store. %s.', 'pixalink-loyalty-for-woocommerce' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=general' ) ) . '">' . esc_html__( 'Enable the use of coupon codes', 'pixalink-loyalty-for-woocommerce' ) . '</a>'
				)
			);
		}

		return $notices;
	}

	/**
	 * Translated message for a result code from a Connect action.
	 *
	 * @param string $code `connected`, `error_{reason}`, ...
	 * @return array{id:string,type:string,message:string,dismissible:bool}|null
	 */
	public static function result_notice( string $code ): ?array {
		$results = array(
			'connected'               => array( 'success', __( 'Your store is connected to Pixalink. Shoppers now earn points on their orders.', 'pixalink-loyalty-for-woocommerce' ) ),
			'connected_legacy_paused' => array( 'success', __( 'Your store is connected to Pixalink, and the old Pixalink webhooks were paused so orders are not counted twice.', 'pixalink-loyalty-for-woocommerce' ) ),
			'lead_submitted'          => array( 'success', __( 'Thanks! Pixalink\'s team will contact you to set up your account. Come back here to connect once you have access.', 'pixalink-loyalty-for-woocommerce' ) ),
			'cancelled'               => array( 'info', __( 'Connecting was cancelled on Pixalink. Nothing was changed.', 'pixalink-loyalty-for-woocommerce' ) ),
			'disconnected'            => array( 'success', __( 'Your store is disconnected from Pixalink and its key was removed from this site.', 'pixalink-loyalty-for-woocommerce' ) ),
			'disconnected_locally'    => array( 'warning', __( 'Your store is disconnected on this site, but Pixalink could not be told. Remove the WooCommerce connection from the outlet in Pixalink as well.', 'pixalink-loyalty-for-woocommerce' ) ),
			'test_ok'                 => array( 'success', __( 'The connection to Pixalink works.', 'pixalink-loyalty-for-woocommerce' ) ),
			'legacy_paused'           => array( 'success', __( 'The old Pixalink webhooks were paused.', 'pixalink-loyalty-for-woocommerce' ) ),
			'legacy_none'             => array( 'info', __( 'No active legacy Pixalink webhooks were found.', 'pixalink-loyalty-for-woocommerce' ) ),
		);

		if ( isset( $results[ $code ] ) ) {
			return self::notice( 'result', $results[ $code ][0], esc_html( $results[ $code ][1] ), false );
		}

		if ( 0 === strpos( $code, 'error_' ) ) {
			$reason = substr( $code, strlen( 'error_' ) );
			if ( in_array( $reason, ConnectException::REASONS, true ) ) {
				return self::notice( 'result', 'error', esc_html( self::error_message( $reason ) ), false );
			}
		}

		return null;
	}

	/**
	 * Admin-facing explanation of a Connect failure.
	 *
	 * @param string $reason ConnectException reason.
	 * @return string
	 */
	public static function error_message( string $reason ): string {
		switch ( $reason ) {
			case ConnectException::INVALID_STATE:
				return __( 'The connection attempt expired or was started in another browser. Please try again.', 'pixalink-loyalty-for-woocommerce' );
			case ConnectException::INVALID_GRANT:
				return __( 'Pixalink did not accept the connection code (it may have expired). Please try again.', 'pixalink-loyalty-for-woocommerce' );
			case ConnectException::INVALID_REQUEST:
				return __( 'Pixalink could not start connecting this site. Check that the site address uses https and try again.', 'pixalink-loyalty-for-woocommerce' );
			case ConnectException::INVALID_KEY_FORMAT:
				return __( 'That does not look like an outlet API key. Copy the whole key from Pixalink, including the part before the "|".', 'pixalink-loyalty-for-woocommerce' );
			case ConnectException::KEY_REJECTED:
				return __( 'Pixalink did not accept this API key. Check that it belongs to the right outlet and has not been rotated.', 'pixalink-loyalty-for-woocommerce' );
			case ConnectException::SITE_ALREADY_CONNECTED:
				return __( 'This outlet is already connected to another WooCommerce store. Disconnect it there first, or use a different outlet.', 'pixalink-loyalty-for-woocommerce' );
			case ConnectException::ACCOUNT_INACTIVE:
				return __( 'Pixalink accepted the key, but the organisation\'s subscription or online-store integration is not active.', 'pixalink-loyalty-for-woocommerce' );
			case ConnectException::NETWORK_ERROR:
				return __( 'Pixalink could not be reached from this server. Try again in a few minutes; if it keeps failing, ask your host whether outgoing HTTPS requests are allowed.', 'pixalink-loyalty-for-woocommerce' );
			case ConnectException::NOT_CONNECTED:
				return __( 'This store is not connected to Pixalink.', 'pixalink-loyalty-for-woocommerce' );
			case ConnectException::CREDENTIALS_OVERRIDDEN:
				return __( 'The API key is set in wp-config.php (PIXALINK_LOYALTY_API_KEY). Remove the constant to connect another way.', 'pixalink-loyalty-for-woocommerce' );
			default:
				return __( 'Pixalink returned an unexpected error. Please try again; if it keeps happening, contact Pixalink support.', 'pixalink-loyalty-for-woocommerce' );
		}
	}

	/**
	 * `admin_post_pixalink_loyalty_dismiss_notice`: remember a dismissal for the current user.
	 *
	 * @return void
	 */
	public function dismiss(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified right below with the notice-specific action.
		$id = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		if ( ! in_array( $id, self::DISMISSIBLE, true ) || ! current_user_can( ConnectController::CAPABILITY ) ) {
			wp_die( esc_html__( 'This notice cannot be dismissed.', 'pixalink-loyalty-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION_DISMISS . '_' . $id );

		$this->dismiss_for_user( get_current_user_id(), $id );

		$referer = wp_get_referer();
		wp_safe_redirect( false === $referer ? admin_url() : $referer );
		exit;
	}

	/**
	 * Record a dismissal.
	 *
	 * @param int    $user_id User id.
	 * @param string $id      Notice id.
	 * @return void
	 */
	public function dismiss_for_user( int $user_id, string $id ): void {
		$dismissed = $this->dismissed( $user_id );
		if ( ! in_array( $id, $dismissed, true ) ) {
			$dismissed[] = $id;
			update_user_meta( $user_id, self::USER_META, $dismissed );
		}
	}

	/**
	 * Notice ids a user dismissed.
	 *
	 * @param int|null $user_id User id (current user when null).
	 * @return list<string>
	 */
	private function dismissed( ?int $user_id = null ): array {
		$stored = get_user_meta( $user_id ?? get_current_user_id(), self::USER_META, true );

		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_string' ) ) : array();
	}

	/**
	 * Plugins screen and WooCommerce screens.
	 *
	 * @param string $screen_id Screen id.
	 * @return bool
	 */
	private static function is_relevant_screen( string $screen_id ): bool {
		if ( in_array( $screen_id, array( 'plugins', 'plugins-network' ), true ) ) {
			return true;
		}

		return function_exists( 'wc_get_screen_ids' ) && in_array( $screen_id, wc_get_screen_ids(), true );
	}

	/**
	 * Notice record.
	 *
	 * @param string    $id          Id.
	 * @param string    $type        success | info | warning | error.
	 * @param string    $message     HTML message (printed through wp_kses_post()).
	 * @param bool|null $dismissible Whether it may be dismissed per user (null: per {@see Notices::DISMISSIBLE}).
	 * @return array{id:string,type:string,message:string,dismissible:bool}
	 */
	private static function notice( string $id, string $type, string $message, ?bool $dismissible = null ): array {
		return array(
			'id'          => $id,
			'type'        => $type,
			'message'     => $message,
			'dismissible' => $dismissible ?? in_array( $id, self::DISMISSIBLE, true ),
		);
	}

	/**
	 * Print one notice.
	 *
	 * @param array{id:string,type:string,message:string,dismissible:bool} $notice Notice.
	 * @return void
	 */
	private function print_notice( array $notice ): void {
		echo '<div class="notice notice-' . esc_attr( $notice['type'] ) . ' pixalink-loyalty-notice" data-pixalink-notice="' . esc_attr( $notice['id'] ) . '"><p>';
		echo wp_kses_post( $notice['message'] );
		if ( $notice['dismissible'] ) {
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => self::ACTION_DISMISS,
						'notice' => $notice['id'],
					),
					admin_url( 'admin-post.php' )
				),
				self::ACTION_DISMISS . '_' . $notice['id']
			);
			echo ' <a href="' . esc_url( $url ) . '" class="pixalink-loyalty-dismiss">' . esc_html__( 'Don\'t show again', 'pixalink-loyalty-for-woocommerce' ) . '</a>';
		}
		echo '</p></div>';
	}
}
