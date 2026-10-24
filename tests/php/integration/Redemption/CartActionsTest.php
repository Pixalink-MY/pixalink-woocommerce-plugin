<?php
/**
 * Integration: the one server-side cart mutation path for both checkouts.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Redemption;

use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;
use Automattic\WooCommerce\StoreApi\StoreApi;
use Pixalink\Loyalty\Redemption\CartActions;
use Pixalink\Loyalty\Redemption\Labels;
use Pixalink\Loyalty\Tests\FakeServer\Server;

/**
 * Blocks' `extensionCartUpdate( { namespace: 'pixalink-loyalty', data } )` and the classic panel's fetch send the
 * same request (POST /wc/store/v1/cart/extensions with the Store API Nonce header), so both reach one callback.
 *
 * @covers \Pixalink\Loyalty\Redemption\CartActions
 * @covers \Pixalink\Loyalty\Checkout\Blocks\StoreApiExtension
 */
final class CartActionsTest extends RedemptionTestCase {

	public function test_one_update_callback_is_registered_for_the_namespace_even_when_blocks_loaded_first(): void {
		// In this bootstrap WooCommerce loads before the plugin, so woocommerce_blocks_loaded fired before boot.
		$this->assertGreaterThan( 0, did_action( 'woocommerce_blocks_loaded' ) );

		$callback = StoreApi::container()->get( ExtendSchema::class )->get_update_callback( CartActions::STORE_API_NAMESPACE );

		$this->assertSame( array( $this->module()->actions(), 'handle' ), $callback );
	}

	public function test_refresh_quote_fetches_once_and_the_cart_response_carries_the_panel(): void {
		$this->fill_cart();

		$cart = $this->act( array( 'action' => 'refresh_quote' ) );
		$this->act( array( 'action' => 'refresh_quote' ) );

		$this->assertCount( 1, $this->pixalink_requests( 'POST', 'quote' ), 'a fresh quote for the same cart is not re-fetched' );
		$panel = $cart['extensions'][ CartActions::STORE_API_NAMESPACE ];
		$this->assertTrue( $panel['verified'] );
		$this->assertFalse( $panel['quote']['stale'] );
		$this->assertSame( 1250, $panel['points']['balance'] );
		$this->assertSame( 1200, $panel['points']['max'] );
		$this->assertSame( 2500, $panel['credits']['max_cents'] );
		$this->assertSame( '1,250 points', $panel['points']['balance_label'] );
		$this->assertSame( '25.00 credits', $panel['credits']['balance_label'], 'credits are never shown as money' );
		$this->assertSame( array( 'pxl-welcome-88', 'pxl-tenpct-89', 'pxl-sku5-90', 'pxl-freeb-91', 'pxl-ghost-92', 'pxl-instore-93' ), wp_list_pluck( $panel['vouchers'], 'code' ) );
		$this->assertSame( array( true, true, true, true, false, false ), wp_list_pluck( $panel['vouchers'], 'usable' ) );
		$this->assertSame( 200, $panel['earn_preview'] );
	}

	public function test_set_points_applies_the_coupon_and_returns_the_recalculated_cart_with_its_label(): void {
		$this->fill_cart();
		$this->act( array( 'action' => 'refresh_quote' ) );

		$cart = $this->act(
			array(
				'action' => 'set_points',
				'points' => 1000,
			)
		);

		$this->assertSame( '19000', $cart['totals']['total_price'] );
		$this->assertSame( array( 'pxl-points' ), wp_list_pluck( $cart['coupons'], 'code' ) );
		$panel = $cart['extensions'][ CartActions::STORE_API_NAMESPACE ];
		$this->assertSame( 1000, $panel['points']['applied'] );
		$this->assertSame( array( 'pxl-points' => 'Points (1,000 pts)' ), $panel['labels'], 'what the coupons checkout filter relabels the chip with' );
	}

	public function test_points_are_clamped_to_the_step_and_cap_and_zero_removes_the_coupon(): void {
		$this->fill_cart();
		$this->act( array( 'action' => 'refresh_quote' ) );

		$this->act(
			array(
				'action' => 'set_points',
				'points' => 1234,
			)
		);
		$this->assertSame( 1200, $this->module()->state()->points() );

		$this->act(
			array(
				'action' => 'set_points',
				'points' => '999999',
			)
		);
		$this->assertSame( 1200, $this->module()->state()->points(), 'capped at max_for_cart' );

		$this->act(
			array(
				'action' => 'set_points',
				'points' => 0,
			)
		);
		$this->assertSame( 0, $this->module()->state()->points() );
		$this->assertNotContains( 'pxl-points', WC()->cart->get_applied_coupons() );
	}

	public function test_too_few_points_are_refused(): void {
		$this->fill_cart();
		$this->act( array( 'action' => 'refresh_quote' ) );

		$response = $this->cart_action(
			array(
				'action' => 'set_points',
				'points' => 50,
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pixalink_loyalty_points_below_minimum', $response->get_data()['code'] );
		$this->assertSame( 'Use at least 100 points.', $response->get_data()['message'] );
	}

	public function test_a_balance_below_the_minimum_cannot_pay_with_points(): void {
		Server::set_points_balance( 80 );
		$this->fill_cart();
		$this->act( array( 'action' => 'refresh_quote' ) );

		$response = $this->cart_action(
			array(
				'action' => 'set_points',
				'points' => 100,
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pixalink_loyalty_points_insufficient', $response->get_data()['code'] );
		$this->assertFalse( $this->store_api( 'GET', 'cart' )->get_data()['extensions'][ CartActions::STORE_API_NAMESPACE ]['points']['usable'] );
	}

	public function test_set_credits_applies_a_discount_coupon_clamped_to_the_balance(): void {
		$this->fill_cart();
		$this->act( array( 'action' => 'refresh_quote' ) );

		$cart = $this->act(
			array(
				'action'       => 'set_credits',
				'amount_cents' => 9999,
			)
		);

		$this->assertSame( 2500, $this->module()->state()->credits_cents() );
		$this->assertSame( '17500', $cart['totals']['total_price'] );
		$this->assertSame( array( 'pxl-credits' => 'Credits (25.00 credits)' ), $cart['extensions'][ CartActions::STORE_API_NAMESPACE ]['labels'] );

		$this->act(
			array(
				'action'       => 'set_credits',
				'amount_cents' => 0,
			)
		);
		$this->assertNotContains( 'pxl-credits', WC()->cart->get_applied_coupons() );
	}

	public function test_vouchers_apply_and_remove_by_their_pixalink_code_or_coupon_code(): void {
		$this->fill_cart();
		$this->act( array( 'action' => 'refresh_quote' ) );

		$cart = $this->act(
			array(
				'action' => 'apply_voucher',
				'code'   => 'PXL-WELCOME-88',
			)
		);
		$this->assertSame( array( 'pxl-welcome-88' ), wp_list_pluck( $cart['coupons'], 'code' ) );
		$this->assertSame( array( 'pxl-welcome-88' => 'Voucher: Welcome 10 off' ), $cart['extensions'][ CartActions::STORE_API_NAMESPACE ]['labels'], 'chips show the voucher title, not the code' );
		$this->assertTrue( $cart['extensions'][ CartActions::STORE_API_NAMESPACE ]['vouchers'][0]['applied'] );

		$cart = $this->act(
			array(
				'action' => 'remove_voucher',
				'code'   => 'pxl-welcome-88',
			)
		);
		$this->assertSame( array(), $cart['coupons'] );
	}

	public function test_a_refused_voucher_is_not_left_applied(): void {
		// PXL-WELCOME-88 needs a 50.00 minimum spend.
		WC()->cart->add_to_cart( $this->product( 'Product C', 'PXL-C', '15' ), 1 );
		$this->act( array( 'action' => 'refresh_quote' ) );

		$response = $this->cart_action(
			array(
				'action' => 'apply_voucher',
				'code'   => 'PXL-WELCOME-88',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'minimum spend', strtolower( $response->get_data()['message'] ) );
		$this->assertSame( array(), WC()->cart->get_applied_coupons() );
	}

	public function test_removing_the_points_chip_resets_the_selection(): void {
		$this->fill_cart();
		$this->act( array( 'action' => 'refresh_quote' ) );
		$this->act(
			array(
				'action' => 'set_points',
				'points' => 1000,
			)
		);

		$response = $this->store_api( 'POST', 'cart/remove-coupon', array( 'code' => 'pxl-points' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $this->module()->state()->points() );
		$this->assertSame( 0, $response->get_data()['extensions'][ CartActions::STORE_API_NAMESPACE ]['points']['applied'] );
	}

	public function test_a_requote_takes_off_what_the_member_can_no_longer_cover(): void {
		$this->fill_cart();
		$this->act( array( 'action' => 'refresh_quote' ) );
		$this->act(
			array(
				'action' => 'set_points',
				'points' => 1200,
			)
		);

		// The member spends points elsewhere; once the quote expires, the next refresh clamps to the new balance.
		Server::set_points_balance( 700 );
		$this->act( array( 'action' => 'refresh_quote' ) );
		$this->assertSame( 1200, $this->module()->state()->points(), 'a fresh quote is not re-fetched' );

		$this->module()->state()->update(
			array(
				'quoted_at'  => time() - 301,
				'expires_at' => time() - 1,
			)
		);
		$this->act( array( 'action' => 'refresh_quote' ) );

		$this->assertSame( 700, $this->module()->state()->points() );
		$this->assertEqualsWithDelta( 7.0, WC()->cart->get_coupon_discount_amount( 'pxl-points' ), 0.001 );
	}

	public function test_missing_store_api_nonce_is_rejected(): void {
		$this->fill_cart();

		$response = $this->store_api(
			'POST',
			'cart/extensions',
			array(
				'namespace' => CartActions::STORE_API_NAMESPACE,
				'data'      => array( 'action' => 'refresh_quote' ),
			),
			false
		);

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_missing_nonce', $response->get_data()['code'] );
	}

	public function test_spending_requires_a_verified_shopper(): void {
		delete_user_meta( $this->shopper, 'pixalink_customer_id' );
		$this->fill_cart();

		$response = $this->cart_action(
			array(
				'action' => 'set_points',
				'points' => 1000,
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'pixalink_loyalty_verification_required', $response->get_data()['code'] );
		$this->assertSame( Labels::verify_prompt(), $response->get_data()['message'] );
		$this->assertSame( array(), WC()->cart->get_applied_coupons() );

		$panel = $this->store_api( 'GET', 'cart' )->get_data()['extensions'][ CartActions::STORE_API_NAMESPACE ];
		$this->assertSame( 'unlinked', $panel['shopper'] );
		$this->assertNull( $panel['points'], 'no balances for unverified shoppers' );
	}

	public function test_a_switched_off_redemption_is_refused(): void {
		$this->switch_features( true, true, false );
		$this->fill_cart();
		$this->act( array( 'action' => 'refresh_quote' ) );

		$response = $this->cart_action(
			array(
				'action'       => 'set_credits',
				'amount_cents' => 500,
			)
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertNull( $this->store_api( 'GET', 'cart' )->get_data()['extensions'][ CartActions::STORE_API_NAMESPACE ]['credits'] );
	}

	public function test_unknown_actions_are_refused(): void {
		$response = $this->cart_action( array( 'action' => 'spike_link' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pixalink_loyalty_unknown_action', $response->get_data()['code'] );
	}

	/**
	 * Stores with merchant coupons disabled: WC_Cart::apply_coupon() and POST cart/apply-coupon refuse, but
	 * CartController::apply_coupon() (used by CartActions) does not check, so loyalty still works.
	 */
	public function test_points_apply_when_merchant_coupons_are_disabled(): void {
		update_option( 'woocommerce_enable_coupons', 'no' );
		$this->fill_cart();
		$this->act( array( 'action' => 'refresh_quote' ) );

		$this->assertFalse( WC()->cart->apply_coupon( 'pxl-points' ), 'the classic coupon field is gated' );

		$cart = $this->act(
			array(
				'action' => 'set_points',
				'points' => 1000,
			)
		);

		$this->assertSame( '19000', $cart['totals']['total_price'] );
	}

	public function test_classic_fetch_then_update_checkout_sees_the_same_cart_and_labels(): void {
		$seen = array();
		add_action(
			'pixalink_loyalty_cart_action',
			static function ( string $action ) use ( &$seen ): void {
				$seen[] = $action;
			}
		);
		$this->fill_cart();

		$this->act( array( 'action' => 'refresh_quote' ) );
		$this->act(
			array(
				'action' => 'set_points',
				'points' => 1000,
			)
		);
		$this->act(
			array(
				'action' => 'apply_voucher',
				'code'   => 'PXL-WELCOME-88',
			)
		);

		// `update_checkout` -> WC_AJAX::update_order_review recalculates the same WC()->cart and renders the labels.
		WC()->cart->calculate_totals();
		$this->assertSame( array( 'refresh_quote', 'set_points', 'apply_voucher' ), $seen );
		$this->assertEqualsWithDelta( 180.0, (float) WC()->cart->get_total( 'edit' ), 0.001 );
		$this->assertSame( 'Points (1,000 pts)', wc_cart_totals_coupon_label( new \WC_Coupon( 'pxl-points' ), false ) );
		$this->assertSame( 'Voucher: Welcome 10 off', wc_cart_totals_coupon_label( new \WC_Coupon( 'pxl-welcome-88' ), false ) );
	}
}
