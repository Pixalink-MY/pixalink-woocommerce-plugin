<?php
/**
 * Store facts that prefill Pixalink's "Request access" form.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Connect;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the `lead` object of the hosted connect URL (`tests/contract/schemas/connect-lead.json`).
 *
 * Sent only when the admin chooses "Request access" (the server shows its lead form to guests); connecting an
 * existing account sends none of it. Marketing consent is included only when the admin made a choice.
 */
final class LeadPayload {

	public const CHECKOUT_BLOCKS  = 'blocks';
	public const CHECKOUT_CLASSIC = 'classic';
	public const CHECKOUT_NONE    = 'none';

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private string $plugin_version;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_version Plugin version.
	 */
	public function __construct( string $plugin_version ) {
		$this->plugin_version = $plugin_version;
	}

	/**
	 * Lead payload for the admin requesting access.
	 *
	 * @param string                    $admin_email       Requesting admin's e-mail.
	 * @param string                    $admin_name        Requesting admin's display name.
	 * @param array<string,mixed>|null  $marketing_consent `{value, source, captured_at, text_hash}` or null when not asked.
	 * @return array<string,mixed>
	 */
	public function build( string $admin_email, string $admin_name, ?array $marketing_consent ): array {
		$country = explode( ':', (string) get_option( 'woocommerce_default_country', '' ) )[0];

		$payload = array(
			'site_url'       => rtrim( (string) site_url(), '/' ),
			'home_url'       => rtrim( (string) home_url(), '/' ),
			'store_name'     => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
			'admin_email'    => $admin_email,
			'admin_name'     => $admin_name,
			'plugin_version' => $this->plugin_version,
			'wp_version'     => self::wp_version(),
			'wc_version'     => self::wc_version(),
			'php_version'    => PHP_VERSION,
			'locale'         => (string) get_locale(),
			'currency'       => self::currency(),
			'country'        => 1 === preg_match( '/^[A-Z]{2}$/', $country ) ? $country : null,
			'timezone'       => (string) wp_timezone_string(),
			'checkout_type'  => self::checkout_type(),
			'hpos'           => self::hpos_enabled(),
		);

		if ( null !== $marketing_consent ) {
			$payload['marketing_consent'] = $marketing_consent;
		}

		return $payload;
	}

	/**
	 * Which checkout the store's checkout page renders.
	 *
	 * @return string One of the CHECKOUT_* constants.
	 */
	public static function checkout_type(): string {
		$page_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0;
		if ( $page_id <= 0 ) {
			return self::CHECKOUT_NONE;
		}

		return has_block( 'woocommerce/checkout', $page_id ) ? self::CHECKOUT_BLOCKS : self::CHECKOUT_CLASSIC;
	}

	/**
	 * Whether High-Performance Order Storage is the authoritative order store.
	 *
	 * @return bool
	 */
	public static function hpos_enabled(): bool {
		if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		return 'yes' === get_option( 'woocommerce_custom_orders_table_enabled', 'no' );
	}

	/**
	 * WooCommerce version (semver-shaped; "0.0.0" when unknown).
	 *
	 * @return string
	 */
	public static function wc_version(): string {
		return defined( 'WC_VERSION' ) && is_string( constant( 'WC_VERSION' ) ) ? constant( 'WC_VERSION' ) : '0.0.0';
	}

	/**
	 * WordPress version.
	 *
	 * @return string
	 */
	public static function wp_version(): string {
		return isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : '0.0.0';
	}

	/**
	 * Store currency code.
	 *
	 * @return string|null
	 */
	private static function currency(): ?string {
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : get_option( 'woocommerce_currency', '' );

		return is_string( $currency ) && 1 === preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : null;
	}
}
