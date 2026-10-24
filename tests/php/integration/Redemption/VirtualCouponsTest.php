<?php
/**
 * Integration: vouchers, points and credits as virtual coupons in both checkouts, with tax.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Redemption;

use Pixalink\Loyalty\Orders\OrderMeta;
use Pixalink\Loyalty\Redemption\CouponMapper;
use Pixalink\Loyalty\Redemption\Labels;

/**
 * Cart: 1 x A (100.00 net, SKU PXL-A) + 2 x B (50.00 net, SKU PXL-B); 10% tax when enabled. The fake quote offers
 * 1,250 points at 0.01, 25.00 credits and vouchers 88 (10.00 fixed_cart, minimum 50.00), 89 (10%), 90 (5.00 off
 * each A), 91 (B free, one unit) and 92 (an unknown SKU).
 *
 * @covers \Pixalink\Loyalty\Redemption\VirtualCoupons
 * @covers \Pixalink\Loyalty\Redemption\CouponMapper
 */
final class VirtualCouponsTest extends RedemptionTestCase {

	private const CODES = array(
		'points'        => 'pxl-points',
		'credits'       => 'pxl-credits',
		'fixed_cart'    => 'PXL-WELCOME-88',
		'percent'       => 'PXL-TENPCT-89',
		'fixed_product' => 'PXL-SKU5-90',
		'free_item'     => 'PXL-FREEB-91',
	);

	public function set_up(): void {
		parent::set_up();

		// Totals only; holds are HoldCoordinatorTest's.
		remove_action( 'woocommerce_checkout_create_order', array( $this->module()->holds(), 'hold_classic' ), 20 );
		remove_action( 'woocommerce_store_api_checkout_order_processed', array( $this->module()->holds(), 'hold_blocks' ), 10 );
	}

	public function tear_down(): void {
		$this->module()->holds()->register();

		parent::tear_down();
	}

	/**
	 * [tax mode, redemption, expected order total]. Points 1,000 = 10.00 and credits 10.00, both fixed_cart.
	 *
	 * none: subtotal 200. exclusive: 200 net + 10% = 220. inclusive: A 110 + 2 x B 55 = 220 (20 tax inside).
	 * A tax-exclusive store takes fixed_cart discounts off the net price, so 10.00 lowers the total by 11.00.
	 *
	 * @return array<string,array{0:string,1:string,2:float}>
	 */
	public static function totals(): array {
		return array(
			'no tax / points'                  => array( 'none', 'points', 190.0 ),
			'no tax / credits'                 => array( 'none', 'credits', 190.0 ),
			'no tax / fixed_cart 10'           => array( 'none', 'fixed_cart', 190.0 ),
			'no tax / percent 10'              => array( 'none', 'percent', 180.0 ),
			'no tax / fixed_product 5 on A'    => array( 'none', 'fixed_product', 195.0 ),
			'no tax / free B (limit 1)'        => array( 'none', 'free_item', 150.0 ),
			'exclusive / points'               => array( 'excl', 'points', 209.0 ),
			'exclusive / credits'              => array( 'excl', 'credits', 209.0 ),
			'exclusive / fixed_cart 10'        => array( 'excl', 'fixed_cart', 209.0 ),
			'exclusive / percent 10'           => array( 'excl', 'percent', 198.0 ),
			'exclusive / fixed_product 5 on A' => array( 'excl', 'fixed_product', 214.5 ),
			'exclusive / free B (limit 1)'     => array( 'excl', 'free_item', 165.0 ),
			'inclusive / points'               => array( 'incl', 'points', 210.0 ),
			'inclusive / credits'              => array( 'incl', 'credits', 210.0 ),
			'inclusive / fixed_cart 10'        => array( 'incl', 'fixed_cart', 210.0 ),
			'inclusive / percent 10'           => array( 'incl', 'percent', 198.0 ),
			'inclusive / fixed_product 5 on A' => array( 'incl', 'fixed_product', 215.0 ),
			'inclusive / free B (limit 1)'     => array( 'incl', 'free_item', 165.0 ),
		);
	}

	/**
	 * Classic: the native coupon field (WC_Cart::apply_coupon) for vouchers, the cart action for points and credits,
	 * WC_Cart totals, then WC_Checkout::create_order() coupon lines and their meta.
	 *
	 * @dataProvider totals
	 *
	 * @param string $tax_mode   none | excl | incl.
	 * @param string $redemption Key of self::CODES.
	 * @param float  $expected   Expected total.
	 */
	public function test_classic_cart_and_checkout_totals( string $tax_mode, string $redemption, float $expected ): void {
		$this->arrange( $tax_mode );

		if ( 'points' === $redemption || 'credits' === $redemption ) {
			$this->redeem( $redemption );
		} else {
			$this->assertTrue( WC()->cart->apply_coupon( self::CODES[ $redemption ] ), implode( ' | ', wp_list_pluck( wc_get_notices( 'error' ), 'notice' ) ) );
		}
		WC()->cart->calculate_totals();

		$this->assertEqualsWithDelta( $expected, (float) WC()->cart->get_total( 'edit' ), 0.001, 'classic cart total' );

		$order_id = $this->classic_create_order();
		$this->assertIsInt( $order_id, is_wp_error( $order_id ) ? $order_id->get_error_message() : '' );
		$this->assert_order( wc_get_order( $order_id ), $tax_mode, $redemption, $expected );
	}

	/**
	 * Store API: cart/extensions for points and credits, POST cart/apply-coupon for typed voucher codes, GET cart
	 * totals, then POST checkout coupon lines and their meta.
	 *
	 * @dataProvider totals
	 *
	 * @param string $tax_mode   none | excl | incl.
	 * @param string $redemption Key of self::CODES.
	 * @param float  $expected   Expected total.
	 */
	public function test_store_api_cart_and_checkout_totals( string $tax_mode, string $redemption, float $expected ): void {
		$this->arrange( $tax_mode );

		if ( 'points' === $redemption || 'credits' === $redemption ) {
			$this->redeem( $redemption );
		} else {
			$response = $this->store_api( 'POST', 'cart/apply-coupon', array( 'code' => self::CODES[ $redemption ] ) );
			$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		}

		$cart = $this->store_api( 'GET', 'cart' )->get_data();
		$this->assertSame( (string) round( $expected * 100 ), $cart['totals']['total_price'], 'Store API cart total (minor units)' );
		$this->assertContains( strtolower( self::CODES[ $redemption ] ), wp_list_pluck( $cart['coupons'], 'code' ) );

		$checkout = $this->blocks_checkout();
		$this->assertSame( 200, $checkout->get_status(), (string) wp_json_encode( $checkout->get_data() ) );
		$this->assert_order( wc_get_order( $checkout->get_data()['order_id'] ), $tax_mode, $redemption, $expected );
	}

	public function test_points_are_clamped_to_the_quote_cap(): void {
		WC()->cart->add_to_cart( $this->product( 'Product C', 'PXL-C', '15' ), 1 );
		$this->act( array( 'action' => 'refresh_quote' ) );

		$this->act(
			array(
				'action' => 'set_points',
				'points' => 5000,
			)
		);

		$this->assertSame( 1200, $this->module()->state()->points(), "the quote's max_for_cart for a 15.00 cart, on its step" );
		$this->assertEqualsWithDelta( 12.0, WC()->cart->get_coupon_discount_amount( 'pxl-points' ), 0.001 );
	}

	public function test_a_voucher_applied_after_points_refits_them_into_what_is_left(): void {
		WC()->cart->add_to_cart( $this->product( 'Product C', 'PXL-C', '12.50' ), 1 );
		$this->act( array( 'action' => 'refresh_quote' ) );
		$this->act(
			array(
				'action' => 'set_points',
				'points' => 1200,
			)
		);

		$this->act(
			array(
				'action' => 'apply_voucher',
				'code'   => 'PXL-TENPCT-89',
			)
		);

		$this->assertSame( 1100, $this->module()->state()->points(), '12.50 less 10% leaves 11.25: 1,100 points on a 100 step' );
		$this->assertEqualsWithDelta( 0.25, (float) WC()->cart->get_total( 'edit' ), 0.001 );
	}

	public function test_coupon_lines_record_the_points_actually_spent_when_wooCommerce_capped_the_discount(): void {
		$cheap = $this->product( 'Product C', 'PXL-C', '9.37' );
		WC()->cart->add_to_cart( $cheap, 1 );
		$key = WC()->cart->add_to_cart( $this->product_a, 1 );
		$this->act( array( 'action' => 'refresh_quote' ) );
		$this->act(
			array(
				'action' => 'set_points',
				'points' => 1200,
			)
		);

		// The cart shrinks before a new quote: WooCommerce caps the fixed_cart discount silently at the subtotal.
		WC()->cart->remove_cart_item( (string) $key );
		WC()->cart->calculate_totals();
		$order = wc_get_order( $this->classic_create_order() );

		$line = $this->coupon_line( $order, 'pxl-points' );
		$this->assertEqualsWithDelta( 9.37, (float) $line->get_discount(), 0.001 );
		$this->assertSame( '937', (string) $line->get_meta( OrderMeta::COUPON_POINTS ), 'the fewest points covering the capped discount, not the 1,200 selected' );
		$this->assertSame( '937', (string) $line->get_meta( OrderMeta::COUPON_AMOUNT_CENTS ) );
	}

	public function test_points_apply_after_vouchers_and_credits_last(): void {
		$this->arrange( 'none' );
		$this->redeem( 'credits' );
		$this->redeem( 'points' );
		$this->assertTrue( WC()->cart->apply_coupon( 'PXL-WELCOME-88' ) );
		WC()->cart->calculate_totals();

		$this->assertSame( array( 'pxl-welcome-88', 'pxl-points', 'pxl-credits' ), array_keys( $this->sorted_coupons() ) );
		$this->assertEqualsWithDelta( 170.0, (float) WC()->cart->get_total( 'edit' ), 0.001 );
	}

	public function test_unverified_shoppers_are_vetoed_with_our_message_in_the_classic_cart(): void {
		delete_user_meta( $this->shopper, 'pixalink_phone_verified_at' );
		$this->fill_cart();

		foreach ( array( 'pxl-points', 'PXL-WELCOME-88', 'pxl-credits' ) as $code ) {
			wc_clear_notices();
			$this->assertFalse( WC()->cart->apply_coupon( $code ) );
			$this->assertSame( array( Labels::verify_prompt() ), wp_list_pluck( wc_get_notices( 'error' ), 'notice' ), $code );
		}
		$this->assertSame( array(), WC()->cart->get_applied_coupons() );
		$this->assertSame( array(), $this->pixalink_requests( 'POST', 'quote' ), 'unverified shoppers are never quoted' );
	}

	public function test_guests_are_vetoed_with_our_message_in_the_store_api(): void {
		wp_set_current_user( 0 );
		$this->fresh_session();
		$this->fill_cart();

		$response = $this->store_api( 'POST', 'cart/apply-coupon', array( 'code' => 'PXL-WELCOME-88' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_cart_coupon_error', $response->get_data()['code'] );
		$this->assertSame( Labels::verify_prompt(), $response->get_data()['message'] );
	}

	public function test_a_typed_voucher_code_works_before_the_panel_ever_quoted(): void {
		$this->fill_cart();
		$this->assertFalse( $this->module()->state()->has_quote() );

		$response = $this->store_api( 'POST', 'cart/apply-coupon', array( 'code' => 'PXL-TENPCT-89' ) );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( '18000', $response->get_data()['totals']['total_price'] );
		$this->assertCount( 1, $this->pixalink_requests( 'POST', 'quote' ), 'one quote for the typed code' );
	}

	public function test_a_coupon_switched_off_after_it_was_applied_is_removed_with_our_message_in_both_checkouts(): void {
		$this->arrange( 'none' );
		$this->redeem( 'points' );
		$this->assertContains( 'pxl-points', WC()->cart->get_applied_coupons() );

		// Pixalink's kill switch: the Blocks checkout POST is refused before an order exists.
		$this->switch_features( true, false, true );
		$orders   = $this->order_ids();
		$checkout = $this->blocks_checkout();
		$this->assertSame( 409, $checkout->get_status(), (string) wp_json_encode( $checkout->get_data() ) );
		$this->assertStringContainsString( Labels::unavailable( CouponMapper::KIND_POINTS ), (string) wp_json_encode( $checkout->get_data() ) );
		$this->assertSame( $orders, $this->order_ids(), 'refused before the draft order is created' );
		$this->assertNotContains( 'pxl-points', WC()->cart->get_applied_coupons() );

		// Classic: back on, re-applied, then the merchant switches points off; the cart check removes the coupon.
		$this->switch_features( true, true, true );
		$this->redeem( 'points' );
		update_option( 'pixalink_loyalty_points_redemption_enabled', 'no' );
		wc_clear_notices();

		WC()->cart->check_cart_coupons();

		$this->assertNotContains( 'pxl-points', WC()->cart->get_applied_coupons() );
		$notices = implode( ' ', wp_list_pluck( wc_get_notices( 'error' ), 'notice' ) );
		$this->assertStringContainsString( Labels::removed( CouponMapper::KIND_POINTS ) . ' ' . Labels::unavailable( CouponMapper::KIND_POINTS ), $notices );
		$this->assertStringNotContainsString( 'pxl-points', $notices, 'woocommerce_coupon_error replaced the notice that leaks the code' );
		$this->assertSame( 0, $this->module()->state()->points(), 'woocommerce_removed_coupon reset the selection' );
	}

	public function test_a_sku_scoped_voucher_with_unknown_skus_is_refused_instead_of_becoming_cart_wide(): void {
		$this->arrange( 'none' );

		$coupon = new \WC_Coupon( 'PXL-GHOST-92' );
		$this->assertTrue( $coupon->get_virtual() );
		$this->assertSame( array(), $coupon->get_product_ids(), 'what WooCommerce would see without the veto' );

		$response = $this->store_api( 'POST', 'cart/apply-coupon', array( 'code' => 'PXL-GHOST-92' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'does not apply to any product sold in this store', $response->get_data()['message'] );
	}

	public function test_an_in_store_voucher_is_refused_with_its_reason(): void {
		$this->arrange( 'none' );

		$response = $this->store_api( 'POST', 'cart/apply-coupon', array( 'code' => 'PXL-INSTORE-93' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'This voucher can only be redeemed in store.', $response->get_data()['message'] );
	}

	public function test_coupon_data_is_session_scoped_and_prefix_scoped(): void {
		$coupons = $this->module()->coupons();
		$this->arrange( 'none' );

		$this->assertFalse( $coupons->coupon_data( false, 'summer10', null ) );
		$this->assertFalse( $coupons->coupon_data( false, 123, null ) );
		$this->assertSame( array( 'already' ), $coupons->coupon_data( array( 'already' ), 'pxl-points', null ) );
		$this->assertSame( 'percent', $coupons->coupon_data( false, 'PXL-TENPCT-89', null )['discount_type'] );
		$this->assertSame( '0', $coupons->coupon_data( false, 'pxl-points', null )['amount'], 'no points chosen: a worthless placeholder' );

		$session         = WC()->session;
		WC()->session    = null;
		$without_session = $coupons->coupon_data( false, 'PXL-TENPCT-89', null );
		WC()->session    = $session;
		$this->assertFalse( $without_session, 'admin, cron and gateway callbacks fall back to the order line' );
	}

	public function test_the_quote_is_only_sent_for_the_verified_customer_with_a_valid_body(): void {
		$this->arrange( 'excl' );

		$quote = $this->last_request( 'POST', 'quote' );

		$this->assertSame( 200, $quote['status'], 'the fake server answers 422 on any contract violation' );
		$this->assertSame( '5001', $quote['body']['customer_id'] );
		$this->assertSame( 20000, $quote['body']['cart']['subtotal_cents'] );
		$this->assertFalse( $quote['body']['cart']['prices_include_tax'] );
		$this->assertSame( 2000, $quote['body']['cart']['tax_cents'] );
	}

	/**
	 * Common arrangement: taxes, the cart and a quote (what the panel fetches on load).
	 *
	 * @param string $tax_mode none | excl | incl.
	 * @return void
	 */
	private function arrange( string $tax_mode ): void {
		if ( 'none' !== $tax_mode ) {
			$this->use_taxes( 'incl' === $tax_mode );
		}
		$this->fill_cart();
		$this->act( array( 'action' => 'refresh_quote' ) );
	}

	/**
	 * Redeem 1,000 points or 10.00 credits through the cart action.
	 *
	 * @param string $what points | credits.
	 * @return void
	 */
	private function redeem( string $what ): void {
		$this->act(
			'points' === $what
				? array(
					'action' => 'set_points',
					'points' => 1000,
				)
				: array(
					'action'       => 'set_credits',
					'amount_cents' => 1000,
				)
		);
	}

	/**
	 * Applied coupons in the order WooCommerce applies them.
	 *
	 * @return array<string,\WC_Coupon>
	 */
	private function sorted_coupons(): array {
		$totals = new \WC_Cart_Totals( WC()->cart );
		$sorted = ( function () {
			return $this->coupons; // @phpstan-ignore property.protected
		} )->call( $totals );

		return is_array( $sorted ) ? $sorted : array();
	}

	/**
	 * Order totals, the coupon line amounts and our meta.
	 *
	 * @param mixed  $order      Order.
	 * @param string $tax_mode   Tax mode.
	 * @param string $redemption Redemption key.
	 * @param float  $expected   Expected total.
	 * @return void
	 */
	private function assert_order( $order, string $tax_mode, string $redemption, float $expected ): void {
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertEqualsWithDelta( $expected, (float) $order->get_total(), 0.001, 'order total' );

		$gross = 'none' === $tax_mode ? 200.0 : 220.0;
		$line  = $this->coupon_line( $order, self::CODES[ $redemption ] );
		$this->assertEqualsWithDelta( $gross - $expected, (float) $line->get_discount() + (float) $line->get_discount_tax(), 0.011, 'coupon line discount + discount_tax' );
		if ( 'none' === $tax_mode ) {
			$this->assertEqualsWithDelta( 0.0, (float) $line->get_discount_tax(), 0.001 );
		} else {
			$this->assertGreaterThan( 0.0, (float) $line->get_discount_tax(), 'discount_tax recorded separately' );
		}

		$snapshot = $line->get_meta( OrderMeta::COUPON_SNAPSHOT );
		$this->assertIsArray( $snapshot );
		$this->assertSame( strtolower( self::CODES[ $redemption ] ), $line->get_code(), 'WooCommerce stores the lower-cased code' );

		$value = (int) round( ( (float) $line->get_discount() + ( 'incl' === $tax_mode ? (float) $line->get_discount_tax() : 0.0 ) ) * 100 );
		$this->assertSame( (string) $value, (string) $line->get_meta( OrderMeta::COUPON_AMOUNT_CENTS ), 'value: the discount, with its tax share only when prices include tax' );

		if ( 'points' === $redemption ) {
			$this->assertSame( 'points', $line->get_meta( OrderMeta::COUPON_KIND ) );
			$this->assertSame( '1000', (string) $line->get_meta( OrderMeta::COUPON_POINTS ) );
			$this->assertSame( 'Points (1,000 pts)', $line->get_meta( OrderMeta::COUPON_TITLE ) );
			return;
		}

		if ( 'credits' === $redemption ) {
			$this->assertSame( 'credit', $line->get_meta( OrderMeta::COUPON_KIND ) );
			$this->assertSame( '1000', (string) $line->get_meta( OrderMeta::COUPON_AMOUNT_CENTS ) );
			return;
		}

		$ids = array(
			'fixed_cart'    => '88',
			'percent'       => '89',
			'fixed_product' => '90',
			'free_item'     => '91',
		);
		$this->assertSame( 'voucher', $line->get_meta( OrderMeta::COUPON_KIND ) );
		$this->assertSame( $ids[ $redemption ], (string) $line->get_meta( OrderMeta::COUPON_VOUCHER_ID ) );

		if ( 'free_item' === $redemption ) {
			$this->assertSame( array( $this->product_b ), array_map( 'intval', $snapshot['product_ids'] ) );
			$this->assertSame( 1, (int) $snapshot['limit_usage_to_x_items'] );
		}
	}
}
