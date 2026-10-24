<?php
/**
 * Integration tests for FeaturesCompat.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Compat;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Pixalink\Loyalty\Compat\FeaturesCompat;

/**
 * WooCommerce records the plugin as compatible with HPOS and the Cart & Checkout blocks, and orders
 * round-trip through CRUD in whichever storage mode this run selected (PIXALINK_LOYALTY_HPOS).
 *
 * @covers \Pixalink\Loyalty\Compat\FeaturesCompat
 */
final class FeaturesCompatTest extends \WP_UnitTestCase {

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function features(): array {
		return array(
			'HPOS'                   => array( 'custom_order_tables' ),
			'Cart & Checkout blocks' => array( 'cart_checkout_blocks' ),
		);
	}

	/**
	 * @dataProvider features
	 *
	 * @param string $feature WooCommerce feature id.
	 */
	public function test_compatibility_is_declared( string $feature ): void {
		$plugins = FeaturesUtil::get_compatible_plugins_for_feature( $feature );
		$plugin  = plugin_basename( PIXALINK_LOYALTY_FILE );

		$this->assertContains( $plugin, $plugins['compatible'] );
		$this->assertNotContains( $plugin, $plugins['incompatible'] );
	}

	public function test_declaration_is_hooked_on_before_woocommerce_init(): void {
		$this->assertNotFalse( has_action( 'before_woocommerce_init', array( FeaturesCompat::class, 'declare_compatibility' ) ) );
		$this->assertSame( array( 'custom_order_tables', 'cart_checkout_blocks' ), FeaturesCompat::FEATURES );
	}

	public function test_order_storage_matches_the_requested_mode_and_crud_round_trips(): void {
		$requested = getenv( 'PIXALINK_LOYALTY_HPOS' );
		if ( false !== $requested && '' !== $requested ) {
			$this->assertSame( '1' === $requested, OrderUtil::custom_orders_table_usage_is_enabled(), 'HPOS mode was not applied by the bootstrap.' );
		}

		$order = wc_create_order();
		$order->update_meta_data( '_pixalink_customer_id', '5001' );
		$order->save();

		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( \WC_Order::class, $reloaded );
		$this->assertSame( '5001', $reloaded->get_meta( '_pixalink_customer_id' ) );
	}
}
