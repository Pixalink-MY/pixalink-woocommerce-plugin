<?php
/**
 * Runtime requirement checks.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty;

defined( 'ABSPATH' ) || exit;

/**
 * Checks PHP, libsodium and WooCommerce before the plugin registers any behaviour.
 *
 * Keep {@see Requirements::MIN_WC_VERSION} in sync with the "WC requires at least" header
 * (bin/bump-version.sh updates both).
 */
final class Requirements {

	public const MIN_PHP_VERSION = '8.1';
	public const MIN_WC_VERSION  = '11.0';

	public const MISSING_WOOCOMMERCE = 'missing_woocommerce';
	public const OLD_WOOCOMMERCE     = 'old_woocommerce';
	public const OLD_PHP             = 'old_php';
	public const MISSING_SODIUM      = 'missing_sodium';

	/**
	 * Unmet requirement ids.
	 *
	 * @return list<string>
	 */
	public static function failures(): array {
		$failures = array();

		if ( version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '<' ) ) {
			$failures[] = self::OLD_PHP;
		}

		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$failures[] = self::MISSING_SODIUM;
		}

		if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) ) {
			$failures[] = self::MISSING_WOOCOMMERCE;
		} elseif ( version_compare( (string) constant( 'WC_VERSION' ), self::MIN_WC_VERSION, '<' ) ) {
			$failures[] = self::OLD_WOOCOMMERCE;
		}

		return $failures;
	}

	/**
	 * Whether every requirement is met.
	 *
	 * @return bool
	 */
	public static function are_met(): bool {
		return array() === self::failures();
	}

	/**
	 * Show an admin notice listing unmet requirements.
	 *
	 * @param list<string> $failures Requirement ids from {@see Requirements::failures()}.
	 * @return void
	 */
	public static function register_notice( array $failures ): void {
		add_action(
			'admin_notices',
			static function () use ( $failures ): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}

				echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Pixalink Loyalty for WooCommerce is inactive.', 'pixalink-loyalty-for-woocommerce' ) . '</strong></p><ul>';
				foreach ( $failures as $failure ) {
					echo '<li>' . esc_html( self::message( $failure ) ) . '</li>';
				}
				echo '</ul></div>';
			}
		);
	}

	/**
	 * Human-readable explanation of a failure (translated at render time, after `init`).
	 *
	 * @param string $failure Requirement id.
	 * @return string
	 */
	public static function message( string $failure ): string {
		switch ( $failure ) {
			case self::OLD_PHP:
				/* translators: 1: required PHP version, 2: current PHP version. */
				return sprintf( __( 'It requires PHP %1$s or newer; this site runs PHP %2$s.', 'pixalink-loyalty-for-woocommerce' ), self::MIN_PHP_VERSION, PHP_VERSION );
			case self::MISSING_SODIUM:
				return __( 'It requires the PHP sodium extension, which is not available on this server. Ask your host to enable it.', 'pixalink-loyalty-for-woocommerce' );
			case self::MISSING_WOOCOMMERCE:
				return __( 'It requires WooCommerce to be installed and active.', 'pixalink-loyalty-for-woocommerce' );
			case self::OLD_WOOCOMMERCE:
				/* translators: 1: required WooCommerce version, 2: installed WooCommerce version. */
				return sprintf( __( 'It requires WooCommerce %1$s or newer; this site runs WooCommerce %2$s.', 'pixalink-loyalty-for-woocommerce' ), self::MIN_WC_VERSION, defined( 'WC_VERSION' ) ? (string) constant( 'WC_VERSION' ) : '?' );
			default:
				return $failure;
		}
	}
}
