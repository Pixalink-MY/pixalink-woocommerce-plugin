<?php
/**
 * SPIKE (P02, throwaway): request tracing for the e2e run (WP_DEBUG_LOG only).
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Spike;

defined( 'ABSPATH' ) || exit;

/**
 * Logs, per Store API request, which session handler instance served it and whether the redemption state reached
 * the database at shutdown.
 */
final class SpikeDebug {

	/**
	 * Register hooks when WP_DEBUG is on.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		add_action( 'pixalink_loyalty_spike_cart_action', array( self::class, 'on_action' ), 1 );
		add_action( 'shutdown', array( self::class, 'on_shutdown' ), 30 );
	}

	/**
	 * Before each cart action.
	 *
	 * @param mixed $action Action.
	 * @return void
	 */
	public static function on_action( $action ): void {
		self::log( 'action ' . ( is_string( $action ) ? $action : '?' ) );
	}

	/**
	 * After WC_Session_Handler::save_data() (shutdown:20).
	 *
	 * @return void
	 */
	public static function on_shutdown(): void {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( false === strpos( $uri, 'wc/store' ) && false === strpos( $uri, 'checkout' ) && false === strpos( $uri, 'admin-ajax' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ajax_action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		global $wpdb;
		$customer = function_exists( 'WC' ) && WC()->session instanceof \WC_Session ? (string) WC()->session->get_customer_id() : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = '' === $customer ? null : $wpdb->get_var( $wpdb->prepare( "SELECT session_value FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = %s", $customer ) );
		// The redemption state's own transient, read straight from the table (no object cache).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$raw   = '' === $customer ? null : $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . RedemptionState::SESSION_KEY . '_' . md5( $customer ) ) );
		$state = is_string( $raw ) ? maybe_unserialize( $raw ) : null;

		self::log(
			sprintf(
				'shutdown%s db_row=%s db_verified=%s',
				'' === $ajax_action ? '' : ' ajax_action=' . $ajax_action,
				null === $row ? 'none' : 'yes',
				is_array( $state ) ? var_export( $state['verified'] ?? null, true ) : 'n/a' // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
			)
		);
	}

	/**
	 * Write one line.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private static function log( string $message ): void {
		$session = function_exists( 'WC' ) ? WC()->session : null;
		$uri     = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'[pxl-spike] %s uri=%s user=%d handler=%s#%s customer=%s mem_verified=%s',
				$message,
				$uri,
				get_current_user_id(),
				is_object( $session ) ? get_class( $session ) : 'none',
				is_object( $session ) ? spl_object_id( $session ) : '-',
				$session instanceof \WC_Session ? (string) $session->get_customer_id() : '-',
				RedemptionState::is_verified() ? 'yes' : 'no'
			)
		);
	}
}
