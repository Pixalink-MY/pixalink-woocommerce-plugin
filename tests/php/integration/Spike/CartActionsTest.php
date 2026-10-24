<?php
/**
 * SPIKE (P02, throwaway): Q3, one server-side callback for Blocks extensionCartUpdate() and the classic fetch.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Spike;

use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;
use Automattic\WooCommerce\StoreApi\StoreApi;
use Pixalink\Loyalty\Spike\RedemptionState;
use Pixalink\Loyalty\Spike\Spike;

/**
 * Blocks' extensionCartUpdate( { namespace: 'pixalink-loyalty', data } ) and the classic panel's fetch send the
 * identical request (POST /wc/store/v1/cart/extensions with the `wc_store_api` Nonce header), so the server sees
 * one route and one callback; the tests below dispatch that request and then exercise each checkout.
 *
 * @covers \Pixalink\Loyalty\Spike\CartActions
 * @covers \Pixalink\Loyalty\Spike\Spike
 * @covers \Pixalink\Loyalty\Spike\StoreApiExtension
 */
final class CartActionsTest extends SpikeTestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pixalink_loyalty_spike_hold_enabled', '__return_false' );
	}

	public function test_one_update_callback_is_registered_for_the_namespace_even_when_blocks_loaded_first(): void {
		// In this bootstrap WooCommerce loads before the plugin, so woocommerce_blocks_loaded fired before boot.
		$this->assertGreaterThan( 0, did_action( 'woocommerce_blocks_loaded' ) );

		$callback = StoreApi::container()->get( ExtendSchema::class )->get_update_callback( Spike::STORE_API_NAMESPACE );

		$this->assertSame( array( Spike::instance()->actions(), 'handle' ), $callback );
	}

	public function test_blocks_extension_cart_update_applies_points_and_returns_the_recalculated_cart(): void {
		$this->fill_cart();
		$this->cart_action( array( 'action' => 'spike_link' ) );

		$response = $this->cart_action(
			array(
				'action' => 'set_points',
				'points' => 1000,
			)
		);

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$cart = $response->get_data();
		$this->assertSame( '19000', $cart['totals']['total_price'] );
		$this->assertSame( array( 'pxl-points' ), wp_list_pluck( $cart['coupons'], 'code' ) );
		$this->assertSame( '1000', $cart['coupons'][0]['totals']['total_discount'] );
		$extension = $cart['extensions'][ Spike::STORE_API_NAMESPACE ];
		$this->assertTrue( $extension['linked'] );
		$this->assertSame( 1000, $extension['points']['applied'] );
		$this->assertSame( 'Points (1,000 pts)', $extension['points_label'], 'label the coupons checkout filter uses' );
	}

	public function test_classic_fetch_then_update_checkout_sees_the_same_cart_through_the_same_callback(): void {
		$seen = array();
		add_action(
			'pixalink_loyalty_spike_cart_action',
			static function ( string $action ) use ( &$seen ): void {
				$seen[] = $action;
			}
		);
		$this->fill_cart();

		// What assets/js/src/spike/classic.js sends (plus X-WP-Nonce, which only matters for cookie-authenticated users).
		$this->cart_action( array( 'action' => 'spike_link' ) );
		$this->cart_action(
			array(
				'action' => 'set_points',
				'points' => 1000,
			)
		);
		$this->cart_action(
			array(
				'action' => 'apply_voucher',
				'code'   => 'PXL-WELCOME-88',
			)
		);

		// `update_checkout` -> WC_AJAX::update_order_review recalculates the same WC()->cart and renders the labels.
		WC()->cart->calculate_totals();
		$this->assertSame( array( 'spike_link', 'set_points', 'apply_voucher' ), $seen );
		$this->assertEqualsWithDelta( 180.0, (float) WC()->cart->get_total( 'edit' ), 0.001, '200 - 10 voucher - 10 points' );
		$this->assertSame( 'Points (1,000 pts)', wc_cart_totals_coupon_label( new \WC_Coupon( 'pxl-points' ), false ) );
		$this->assertSame( 'Voucher: Welcome 10 off', wc_cart_totals_coupon_label( new \WC_Coupon( 'pxl-welcome-88' ), false ) );

		$order = wc_get_order( $this->classic_create_order() );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertEqualsCanonicalizing( array( 'pxl-points', 'pxl-welcome-88' ), $order->get_coupon_codes() );
	}

	public function test_missing_store_api_nonce_is_rejected(): void {
		$this->fill_cart();

		$response = $this->store_api(
			'POST',
			'cart/extensions',
			array(
				'namespace' => Spike::STORE_API_NAMESPACE,
				'data'      => array( 'action' => 'spike_link' ),
			),
			false
		);

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_missing_nonce', $response->get_data()['code'] );
		$this->assertFalse( RedemptionState::is_verified() );
	}

	public function test_spending_requires_a_verified_shopper(): void {
		$this->fill_cart();

		$response = $this->cart_action(
			array(
				'action' => 'set_points',
				'points' => 1000,
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'pixalink_loyalty_verification_required', $response->get_data()['code'] );
		$this->assertSame( array(), WC()->cart->get_applied_coupons() );
	}

	public function test_points_are_clamped_to_step_and_cap_and_zero_removes_the_coupon(): void {
		$this->fill_cart();
		$this->cart_action( array( 'action' => 'spike_link' ) );

		$this->cart_action(
			array(
				'action' => 'set_points',
				'points' => 1234,
			)
		);
		$this->assertSame( 1200, RedemptionState::get()['points'] );

		$this->cart_action(
			array(
				'action' => 'set_points',
				'points' => 999999,
			)
		);
		$this->assertSame( 2000, RedemptionState::get()['points'], 'capped at min(available, max_for_cart)' );

		$this->cart_action(
			array(
				'action' => 'set_points',
				'points' => 0,
			)
		);
		$this->assertSame( 0, RedemptionState::get()['points'] );
		$this->assertNotContains( 'pxl-points', WC()->cart->get_applied_coupons() );
	}

	public function test_removing_the_points_chip_resets_the_state(): void {
		$this->fill_cart();
		$this->cart_action( array( 'action' => 'spike_link' ) );
		$this->cart_action(
			array(
				'action' => 'set_points',
				'points' => 1000,
			)
		);

		$response = $this->store_api( 'POST', 'cart/remove-coupon', array( 'code' => 'pxl-points' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, RedemptionState::get()['points'] );
		$this->assertSame( 0, $response->get_data()['extensions'][ Spike::STORE_API_NAMESPACE ]['points']['applied'] );
	}

	public function test_a_refused_voucher_is_not_left_selected(): void {
		// PXL-WELCOME-88 needs a 50.00 minimum spend.
		WC()->cart->add_to_cart( $this->product( 'Spike C', 'PXL-C', '15' ), 1 );
		$this->cart_action( array( 'action' => 'spike_link' ) );

		$response = $this->cart_action(
			array(
				'action' => 'apply_voucher',
				'code'   => 'PXL-WELCOME-88',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'minimum spend', strtolower( $response->get_data()['message'] ) );
		$this->assertSame( array(), RedemptionState::get()['vouchers'] );
	}

	/**
	 * Pitfall: stores with merchant coupons disabled. WC_Cart::apply_coupon() and the Store API apply-coupon route
	 * both refuse while wc_coupons_enabled() is false, but CartController::apply_coupon() (used by CartActions) and
	 * the totals calculation do not check it, so loyalty redemption still works.
	 */
	public function test_points_apply_when_merchant_coupons_are_disabled(): void {
		update_option( 'woocommerce_enable_coupons', 'no' );
		$this->fill_cart();
		$this->cart_action( array( 'action' => 'spike_link' ) );

		$this->assertFalse( WC()->cart->apply_coupon( 'pxl-points' ), 'classic coupon field path is gated' );
		$typed = $this->store_api( 'POST', 'cart/apply-coupon', array( 'code' => 'PXL-WELCOME-88' ) );
		$this->assertGreaterThanOrEqual( 400, $typed->get_status(), 'typed-code fallback is gated too' );

		$response = $this->cart_action(
			array(
				'action' => 'set_points',
				'points' => 1000,
			)
		);

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( '19000', $response->get_data()['totals']['total_price'] );
	}
}
