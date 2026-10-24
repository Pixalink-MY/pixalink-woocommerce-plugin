<?php
/**
 * SPIKE (P02, throwaway): Q6/Q7 server side, Blocks integration, cart extension data and the installed WC's
 * client API surface.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Spike;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry;
use Pixalink\Loyalty\Spike\BlocksIntegration;
use Pixalink\Loyalty\Spike\Spike;

/**
 * The browser half (fill mounts, chip relabelled) is tests/e2e/specs/spike-checkout.spec.ts.
 *
 * @covers \Pixalink\Loyalty\Spike\BlocksIntegration
 * @covers \Pixalink\Loyalty\Spike\StoreApiExtension
 */
final class BlocksIntegrationTest extends SpikeTestCase {

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function registries(): array {
		return array(
			'cart block'     => array( 'cart_block' ),
			'checkout block' => array( 'checkout_block' ),
		);
	}

	/**
	 * @dataProvider registries
	 *
	 * @param string $identifier Registry identifier (fires woocommerce_blocks_{identifier}_registration).
	 */
	public function test_integration_registers_its_script_in_cart_and_checkout_blocks( string $identifier ): void {
		$registry = new IntegrationRegistry();
		$registry->initialize( $identifier );

		$this->assertTrue( $registry->is_registered( 'pixalink-loyalty-spike' ) );
		$this->assertContains( BlocksIntegration::HANDLE, $registry->get_all_registered_script_handles() );
		$this->assertTrue( wp_script_is( BlocksIntegration::HANDLE, 'registered' ) );
		$this->assertContains( 'wc-blocks-checkout', wp_scripts()->registered[ BlocksIntegration::HANDLE ]->deps );
	}

	/**
	 * Q7: the client API the spike relies on, as exported by the installed WooCommerce (wc.blocksCheckout lives in
	 * the wc-cart-checkout-base chunk; the names are not minified because they are the public export map).
	 */
	public function test_installed_woocommerce_exports_the_client_api_we_use(): void {
		$bundle = (string) file_get_contents( WC_ABSPATH . 'assets/client/blocks/wc-cart-checkout-base-frontend.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		foreach ( array( 'ExperimentalDiscountsMeta', 'ExperimentalOrderMeta', 'extensionCartUpdate', 'registerCheckoutFilters', 'registerCheckoutBlock' ) as $export ) {
			$this->assertStringContainsString( $export . ':()=>', $bundle, sprintf( '%s missing from wc.blocksCheckout in WC %s', $export, WC_VERSION ) );
		}
		$this->assertTrue( function_exists( 'woocommerce_store_api_register_update_callback' ) );
		$this->assertTrue( function_exists( 'woocommerce_store_api_register_endpoint_data' ) );
		fwrite( STDERR, sprintf( "\n[spike] WooCommerce %s, WordPress %s, PHP %s, HPOS %s\n", WC_VERSION, get_bloginfo( 'version' ), PHP_VERSION, \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}

	public function test_cart_response_carries_the_extension_data(): void {
		$this->fill_cart();

		$unlinked = $this->store_api( 'GET', 'cart' )->get_data()['extensions'][ Spike::STORE_API_NAMESPACE ];
		$this->assertFalse( $unlinked['linked'] );
		$this->assertSame( array(), $unlinked['vouchers'] );

		$this->cart_action( array( 'action' => 'spike_link' ) );
		$linked = $this->store_api( 'GET', 'cart' )->get_data()['extensions'][ Spike::STORE_API_NAMESPACE ];

		$this->assertTrue( $linked['linked'] );
		$this->assertSame( 2000, $linked['points']['max'] );
		$this->assertSame( 100, $linked['points']['step'] );
		$this->assertSame( 'pxl-points', $linked['points_code'] );
		$this->assertSame( array( 'pxl-welcome-88', 'pxl-tenpct-89', 'pxl-sku5-90', 'pxl-freeb-91', 'pxl-ghost-92' ), wp_list_pluck( $linked['vouchers'], 'code' ) );
	}
}
