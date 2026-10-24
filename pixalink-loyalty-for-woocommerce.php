<?php
/**
 * Plugin Name:          Pixalink Loyalty for WooCommerce
 * Description:          Pixalink loyalty points, vouchers and member accounts for WooCommerce: earn on every order, redeem at checkout, see balances under My Account.
 * Version:              0.1.0
 * Requires at least:    7.0
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * WC requires at least: 11.0
 * WC tested up to:      11.2
 * Author:               Pixalink
 * Author URI:           https://pixalink.io
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          pixalink-loyalty-for-woocommerce
 * Domain Path:          /languages
 *
 * @package Pixalink\Loyalty
 */

defined( 'ABSPATH' ) || exit;

// Keep this file parseable on PHP 7.2+ (oldest PHP the supported WordPress runs on) so this notice can render.
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version. */
						__( 'Pixalink Loyalty for WooCommerce requires PHP %1$s or newer. This site runs PHP %2$s.', 'pixalink-loyalty-for-woocommerce' ),
						'8.1',
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

define( 'PIXALINK_LOYALTY_VERSION', '0.1.0' );
define( 'PIXALINK_LOYALTY_FILE', __FILE__ );
define( 'PIXALINK_LOYALTY_DIR', plugin_dir_path( __FILE__ ) );
define( 'PIXALINK_LOYALTY_URL', plugin_dir_url( __FILE__ ) );

/*
 * Build flavour (selfhosted | wporg | marketplace) is written into build-info.php by bin/build-zip.sh.
 * A source checkout has no build-info.php and reports "dev".
 */
$pixalink_loyalty_build_info = is_readable( __DIR__ . '/build-info.php' ) ? require __DIR__ . '/build-info.php' : array();
define(
	'PIXALINK_LOYALTY_DISTRIBUTION',
	is_array( $pixalink_loyalty_build_info ) && isset( $pixalink_loyalty_build_info['distribution'] ) && is_string( $pixalink_loyalty_build_info['distribution'] )
		? $pixalink_loyalty_build_info['distribution']
		: 'dev'
);
unset( $pixalink_loyalty_build_info );

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	// Fallback PSR-4 autoloader for installs without vendor/ (for example a plain git checkout).
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'Pixalink\\Loyalty\\';
			if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
				return;
			}
			$relative = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
			$path     = __DIR__ . '/src/' . $relative . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

register_activation_hook( __FILE__, array( \Pixalink\Loyalty\Install\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Pixalink\Loyalty\Install\Installer::class, 'deactivate' ) );

// Compatibility must be declared from the main file during before_woocommerce_init, even if boot later bails out.
add_action( 'before_woocommerce_init', array( \Pixalink\Loyalty\Compat\FeaturesCompat::class, 'declare_compatibility' ) );

add_action( 'plugins_loaded', array( \Pixalink\Loyalty\Plugin::class, 'boot' ) );
