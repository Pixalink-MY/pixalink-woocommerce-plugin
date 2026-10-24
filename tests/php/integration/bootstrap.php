<?php
/**
 * Integration test bootstrap: WordPress test library + WooCommerce + this plugin + the fake Pixalink server.
 *
 * Tests dir: WP_TESTS_DIR (wp-env provides /wordpress-phpunit) or the wp-phpunit/wp-phpunit Composer package.
 * HPOS mode: PIXALINK_LOYALTY_HPOS=1|0 switches the authoritative order store before any test runs.
 *
 * @package Pixalink\Loyalty\Tests
 */

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;
use Automattic\WooCommerce\Internal\Features\FeaturesController;

$pixalink_loyalty_root = dirname( __DIR__, 3 );

require_once $pixalink_loyalty_root . '/vendor/autoload.php';
require_once $pixalink_loyalty_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$pixalink_loyalty_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: getenv( 'WP_PHPUNIT__DIR' );
if ( ! $pixalink_loyalty_tests_dir || ! is_readable( $pixalink_loyalty_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found. Run inside wp-env (npm run test:integration) or set WP_TESTS_DIR.\n" );
	exit( 1 );
}

require_once $pixalink_loyalty_tests_dir . '/includes/functions.php';

// SPIKE (P02, throwaway): register the virtual-coupon experiment for tests/php/integration/Spike.
if ( ! defined( 'PIXALINK_LOYALTY_SPIKE' ) ) {
	define( 'PIXALINK_LOYALTY_SPIKE', true );
}

/**
 * Switch WooCommerce's authoritative order storage.
 *
 * @param bool $enabled True for HPOS (custom order tables), false for posts.
 */
function pixalink_loyalty_tests_set_hpos( bool $enabled ): void {
	$container = wc_get_container();
	if ( $enabled ) {
		$container->get( DataSynchronizer::class )->create_database_tables();
	}
	$container->get( FeaturesController::class )->change_feature_enable( 'custom_order_tables', $enabled );
	update_option( CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION, wc_bool_to_string( $enabled ) );
	update_option( DataSynchronizer::ORDERS_DATA_SYNC_ENABLED_OPTION, 'no' );
}

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $pixalink_loyalty_root ): void {
		require_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		require_once $pixalink_loyalty_root . '/pixalink-loyalty-for-woocommerce.php';

		// The fake server is a mapped mu-plugin under wp-env; load it explicitly anywhere else.
		if ( ! has_filter( 'pre_http_request', array( \Pixalink\Loyalty\Tests\FakeServer\Server::class, 'intercept' ) ) ) {
			\Pixalink\Loyalty\Tests\FakeServer\Server::boot();
		}
	}
);

tests_add_filter(
	'setup_theme',
	static function (): void {
		WC_Install::install();

		// Reload capabilities after install, see https://core.trac.wordpress.org/ticket/28374.
		$GLOBALS['wp_roles'] = null;
		wp_roles();
	}
);

// After Action Scheduler (init:1) is ready: WooCommerce's DataSynchronizer unschedules jobs when HPOS options change.
tests_add_filter(
	'init',
	static function (): void {
		$hpos = getenv( 'PIXALINK_LOYALTY_HPOS' );
		if ( false !== $hpos && '' !== $hpos ) {
			pixalink_loyalty_tests_set_hpos( in_array( strtolower( $hpos ), array( '1', 'yes', 'true', 'on' ), true ) );
		}
	},
	20
);

// Outside wp-env the API base constant may be missing; point the client at the fake server.
tests_add_filter( 'pixalink_loyalty_api_base', static fn (): string => 'https://fake-pixalink.test/api/commerce/v1' );

require $pixalink_loyalty_tests_dir . '/includes/bootstrap.php';
