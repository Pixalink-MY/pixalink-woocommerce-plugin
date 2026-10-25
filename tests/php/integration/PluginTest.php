<?php
/**
 * Integration tests for Plugin.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration;

use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Requirements;

/**
 * The plugin loads alongside WooCommerce and boots with its requirements met.
 *
 * @covers \Pixalink\Loyalty\Plugin
 * @covers \Pixalink\Loyalty\Requirements
 */
final class PluginTest extends \WP_UnitTestCase {

	public function test_plugin_boots_on_plugins_loaded_with_requirements_met(): void {
		$this->assertSame( array(), Requirements::failures() );
		$this->assertTrue( Plugin::instance()->is_booted() );
		$this->assertGreaterThanOrEqual( 1, did_action( 'pixalink_loyalty_booted' ) );
		$this->assertNotFalse( has_action( 'plugins_loaded', array( Plugin::class, 'boot' ) ) );
	}

	public function test_constants_describe_a_source_checkout(): void {
		$this->assertSame( 'dev', PIXALINK_LOYALTY_DISTRIBUTION );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+/', PIXALINK_LOYALTY_VERSION );
		$this->assertFileExists( PIXALINK_LOYALTY_DIR . 'pixalink-loyalty-for-woocommerce.php' );
		$this->assertSame( 'pixalink-loyalty-for-woocommerce/pixalink-loyalty-for-woocommerce.php', plugin_basename( PIXALINK_LOYALTY_FILE ) );
	}

	public function test_header_and_requirements_agree_on_the_woocommerce_floor(): void {
		$headers = get_file_data( PIXALINK_LOYALTY_FILE, array( 'wc_min' => 'WC requires at least' ) );

		$this->assertSame( Requirements::MIN_WC_VERSION, $headers['wc_min'] );
		$this->assertTrue( version_compare( WC_VERSION, Requirements::MIN_WC_VERSION, '>=' ) );
	}

	public function test_services_are_shared(): void {
		$plugin = Plugin::instance();

		$this->assertSame( $plugin->client(), $plugin->client() );
		$this->assertSame( $plugin->options(), $plugin->options() );
		$this->assertSame( 'https://fake-pixalink.test/api/commerce/v1', $plugin->client()->base_url() );
	}
}
