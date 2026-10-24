<?php
/**
 * Integration: Cart & Checkout blocks integration and the Store API cart data.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Checkout;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry;
use Pixalink\Loyalty\Checkout\Blocks\BlocksIntegration;
use Pixalink\Loyalty\Redemption\CartActions;
use Pixalink\Loyalty\Redemption\Labels;
use Pixalink\Loyalty\Tests\Integration\Redemption\RedemptionTestCase;

/**
 * The browser half (the slot fill mounts, chips are relabelled) is tests/e2e/specs/checkout-redemption.spec.ts.
 *
 * @covers \Pixalink\Loyalty\Checkout\Blocks\BlocksIntegration
 * @covers \Pixalink\Loyalty\Checkout\Blocks\StoreApiExtension
 * @covers \Pixalink\Loyalty\Checkout\PanelData
 */
final class BlocksIntegrationTest extends RedemptionTestCase {

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

		$this->assertTrue( $registry->is_registered( BlocksIntegration::NAME ) );
		$this->assertContains( BlocksIntegration::SCRIPT, $registry->get_all_registered_script_handles() );
		$this->assertTrue( wp_script_is( BlocksIntegration::SCRIPT, 'registered' ) );
		foreach ( BlocksIntegration::DEPENDENCIES as $dependency ) {
			$this->assertContains( $dependency, wp_scripts()->registered[ BlocksIntegration::SCRIPT ]->deps );
		}

		$data = $registry->get_all_registered_script_data();
		$this->assertSame( CartActions::STORE_API_NAMESPACE, $data[ BlocksIntegration::NAME . '_data' ]['namespace'] );
		$this->assertTrue( $data[ BlocksIntegration::NAME . '_data' ]['enabled'] );
		$this->assertSame( 'Your rewards', $data[ BlocksIntegration::NAME . '_data' ]['strings']['title'] );
	}

	/**
	 * The client API this integration relies on, as the installed WooCommerce exports it. `ExperimentalDiscountsMeta`
	 * is the only experimental API ADR 0001 allows.
	 */
	public function test_installed_woocommerce_exports_the_client_api_we_use(): void {
		$bundle = (string) file_get_contents( WC_ABSPATH . 'assets/client/blocks/wc-cart-checkout-base-frontend.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		foreach ( array( 'ExperimentalDiscountsMeta', 'extensionCartUpdate', 'registerCheckoutFilters', 'registerCheckoutBlock' ) as $export ) {
			$this->assertStringContainsString( $export . ':()=>', $bundle, sprintf( '%s missing from wc.blocksCheckout in WC %s', $export, WC_VERSION ) );
		}
	}

	public function test_guests_get_the_verify_prompt_and_no_balances(): void {
		wp_set_current_user( 0 );
		$this->fresh_session();
		$this->fill_cart();

		$panel = $this->store_api( 'GET', 'cart' )->get_data()['extensions'][ CartActions::STORE_API_NAMESPACE ];

		$this->assertTrue( $panel['show'] );
		$this->assertSame( 'guest', $panel['shopper'] );
		$this->assertFalse( $panel['verified'] );
		$this->assertSame( Labels::verify_prompt(), $panel['verify_prompt'] );
		$this->assertNull( $panel['points'] );
		$this->assertNull( $panel['credits'] );
		$this->assertSame( array(), $panel['vouchers'] );
		$this->assertFalse( $panel['quote']['stale'], 'nothing for the client to refresh' );
	}

	public function test_a_verified_shopper_without_a_quote_is_told_to_refresh(): void {
		$this->fill_cart();

		$panel = $this->store_api( 'GET', 'cart' )->get_data()['extensions'][ CartActions::STORE_API_NAMESPACE ];

		$this->assertTrue( $panel['verified'] );
		$this->assertTrue( $panel['quote']['stale'], 'the UI calls refresh_quote' );
		$this->assertFalse( $panel['quote']['available'] );
		$this->assertSame( array(), $this->pixalink_requests( 'POST', 'quote' ), 'reading the cart never calls Pixalink' );
	}

	public function test_the_panel_is_hidden_when_the_merchant_switched_it_off(): void {
		update_option( 'pixalink_loyalty_checkout_panel_enabled', 'no' );
		$this->fill_cart();

		$panel = $this->store_api( 'GET', 'cart' )->get_data()['extensions'][ CartActions::STORE_API_NAMESPACE ];

		$this->assertFalse( $panel['show'] );
	}

	public function test_kill_switches_hide_each_redemption(): void {
		$this->switch_features( false, true, false );
		$this->fill_cart();

		$panel = $this->act( array( 'action' => 'refresh_quote' ) )['extensions'][ CartActions::STORE_API_NAMESPACE ];

		$this->assertSame(
			array(
				'vouchers' => false,
				'points'   => true,
				'credits'  => false,
			),
			$panel['enabled']
		);
		$this->assertNotNull( $panel['points'] );
		$this->assertNull( $panel['credits'] );
		$this->assertSame( array(), $panel['vouchers'] );
	}
}
