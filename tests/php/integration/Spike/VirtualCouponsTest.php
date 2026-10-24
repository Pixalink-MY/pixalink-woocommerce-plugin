<?php
/**
 * SPIKE (P02, throwaway): Q1 (virtual coupons apply in both checkouts, with tax) and Q2 (validity veto).
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Spike;

use Pixalink\Loyalty\Spike\CouponMapper;
use Pixalink\Loyalty\Spike\RedemptionState;
use Pixalink\Loyalty\Spike\VirtualCoupons;

/**
 * Cart: 1 x A ($100 net, SKU PXL-A) + 2 x B ($50 net, SKU PXL-B); 10% tax when enabled.
 *
 * @covers \Pixalink\Loyalty\Spike\VirtualCoupons
 * @covers \Pixalink\Loyalty\Spike\CouponMapper
 */
final class VirtualCouponsTest extends SpikeTestCase {

	private const CODES = array(
		'points'        => 'pxl-points',
		'percent'       => 'PXL-TENPCT-89',
		'fixed_product' => 'PXL-SKU5-90',
		'free_item'     => 'PXL-FREEB-91',
	);

	public function set_up(): void {
		parent::set_up();

		// Q1 is about totals; holds are covered by HoldCoordinatorTest.
		add_filter( 'pixalink_loyalty_spike_hold_enabled', '__return_false' );
	}

	/**
	 * [tax mode, coupon, expected order total]. Points = 1,000 x 0.01 = 10.00 fixed_cart.
	 *
	 * none: subtotal 200.  excl: 200 net + 10% = 220.  incl: A 110 + 2 x B 55 = 220 (tax 20 inside).
	 *
	 * @return array<string,array{0:string,1:string,2:float}>
	 */
	public static function totals(): array {
		return array(
			'no tax / points'                 => array( 'none', 'points', 190.0 ),
			'no tax / percent 10'             => array( 'none', 'percent', 180.0 ),
			'no tax / fixed_product 5 on A'   => array( 'none', 'fixed_product', 195.0 ),
			'no tax / free B (limit 1)'       => array( 'none', 'free_item', 150.0 ),
			'exclusive / points'              => array( 'excl', 'points', 209.0 ),
			'exclusive / percent 10'          => array( 'excl', 'percent', 198.0 ),
			'exclusive / fixed_product 5 on A' => array( 'excl', 'fixed_product', 214.5 ),
			'exclusive / free B (limit 1)'    => array( 'excl', 'free_item', 165.0 ),
			'inclusive / points'              => array( 'incl', 'points', 210.0 ),
			'inclusive / percent 10'          => array( 'incl', 'percent', 198.0 ),
			'inclusive / fixed_product 5 on A' => array( 'incl', 'fixed_product', 215.0 ),
			'inclusive / free B (limit 1)'    => array( 'incl', 'free_item', 165.0 ),
		);
	}

	/**
	 * Q1, classic: native coupon field (WC_Cart::apply_coupon) for vouchers, CartActions for points,
	 * WC_Cart totals, then WC_Checkout::create_order() coupon_lines + stamped meta.
	 *
	 * @dataProvider totals
	 *
	 * @param string $tax_mode none | excl | incl.
	 * @param string $coupon   Key of self::CODES.
	 * @param float  $expected Expected total.
	 */
	public function test_classic_cart_and_checkout_totals( string $tax_mode, string $coupon, float $expected ): void {
		$this->arrange( $tax_mode );

		if ( 'points' === $coupon ) {
			$this->assertSame( 200, $this->cart_action( array( 'action' => 'set_points', 'points' => 1000 ) )->get_status() );
		} else {
			$this->assertTrue( WC()->cart->apply_coupon( self::CODES[ $coupon ] ), implode( ' | ', wp_list_pluck( wc_get_notices( 'error' ), 'notice' ) ) );
		}
		WC()->cart->calculate_totals();

		$this->assertEqualsWithDelta( $expected, (float) WC()->cart->get_total( 'edit' ), 0.001, 'classic cart total' );
		$code = wc_format_coupon_code( self::CODES[ $coupon ] );
		$this->assertEqualsWithDelta(
			$this->gross( $tax_mode ) - $expected,
			WC()->cart->get_coupon_discount_amount( $code, false ),
			0.011,
			'cart coupon discount incl. tax'
		);

		$order_id = $this->classic_create_order();
		$this->assertIsInt( $order_id, is_wp_error( $order_id ) ? $order_id->get_error_message() : '' );
		$this->assert_order( wc_get_order( $order_id ), $tax_mode, $coupon, $expected );
	}

	/**
	 * Q1, Blocks: Store API (POST cart/extensions for points, POST cart/apply-coupon for typed voucher codes),
	 * GET cart totals, then POST checkout coupon_lines + stamped meta.
	 *
	 * @dataProvider totals
	 *
	 * @param string $tax_mode none | excl | incl.
	 * @param string $coupon   Key of self::CODES.
	 * @param float  $expected Expected total.
	 */
	public function test_store_api_cart_and_checkout_totals( string $tax_mode, string $coupon, float $expected ): void {
		$this->arrange( $tax_mode );

		$response = 'points' === $coupon
			? $this->cart_action( array( 'action' => 'set_points', 'points' => 1000 ) )
			: $this->store_api( 'POST', 'cart/apply-coupon', array( 'code' => self::CODES[ $coupon ] ) );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		$cart = $this->store_api( 'GET', 'cart' )->get_data();
		$this->assertSame( (string) round( $expected * 100 ), $cart['totals']['total_price'], 'Store API cart total (minor units)' );
		$applied = wp_list_pluck( $cart['coupons'], 'code' );
		$this->assertContains( wc_format_coupon_code( self::CODES[ $coupon ] ), $applied );

		$checkout = $this->blocks_checkout();
		$this->assertSame( 200, $checkout->get_status(), (string) wp_json_encode( $checkout->get_data() ) );
		$this->assert_order( wc_get_order( $checkout->get_data()['order_id'] ), $tax_mode, $coupon, $expected );
	}

	/**
	 * A fixed_cart coupon worth more than the discountable subtotal is capped by WooCommerce: the coupon line
	 * carries the capped amount while the shopper selected more points. Holds must not derive points from the
	 * coupon amount (the PUT sends both `points` from the state and the actual `discount`).
	 */
	public function test_points_coupon_is_capped_at_the_discountable_subtotal(): void {
		$cheap = $this->product( 'Spike C', 'PXL-C', '15' );
		WC()->cart->add_to_cart( $cheap, 1 );
		$this->cart_action( array( 'action' => 'spike_link' ) );
		$this->cart_action( array( 'action' => 'set_points', 'points' => 2000 ) );
		WC()->cart->calculate_totals();

		$this->assertEqualsWithDelta( 0.0, (float) WC()->cart->get_total( 'edit' ), 0.001 );
		$this->assertEqualsWithDelta( 15.0, WC()->cart->get_coupon_discount_amount( 'pxl-points' ), 0.001, '2,000 pts = 20.00 requested, capped at the 15.00 line' );
		$this->assertSame( 2000, RedemptionState::get()['points'], 'state keeps what the shopper chose' );
	}

	/**
	 * Q2, classic: an unverified shopper typing our codes gets our message, not "does not exist".
	 */
	public function test_unverified_shopper_is_vetoed_with_our_message_in_the_classic_cart(): void {
		$this->fill_cart();

		foreach ( array( 'pxl-points', 'PXL-WELCOME-88' ) as $code ) {
			wc_clear_notices();
			$this->assertFalse( WC()->cart->apply_coupon( $code ) );
			$this->assertSame( array( VirtualCoupons::verify_message() ), wp_list_pluck( wc_get_notices( 'error' ), 'notice' ), $code );
		}
		$this->assertSame( array(), WC()->cart->get_applied_coupons() );
	}

	/**
	 * Q2, Blocks: POST cart/apply-coupon returns 400 woocommerce_rest_cart_coupon_error with our message.
	 */
	public function test_unverified_shopper_is_vetoed_with_our_message_in_the_store_api(): void {
		$this->fill_cart();

		$response = $this->store_api( 'POST', 'cart/apply-coupon', array( 'code' => 'PXL-WELCOME-88' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_rest_cart_coupon_error', $response->get_data()['code'] );
		$this->assertSame( VirtualCoupons::verify_message(), $response->get_data()['message'] );
	}

	/**
	 * Q2: a coupon that becomes invalid after it was applied (shopper unlinked, kill switch) is removed with our
	 * message by the classic cart check, and the Blocks checkout POST is refused before any order exists.
	 */
	public function test_coupon_invalidated_after_application_is_removed_with_our_message_in_both_checkouts(): void {
		$this->fill_cart();
		$this->cart_action( array( 'action' => 'spike_link' ) );
		$this->cart_action( array( 'action' => 'set_points', 'points' => 1000 ) );
		$this->assertContains( 'pxl-points', WC()->cart->get_applied_coupons() );

		RedemptionState::update( array( 'verified' => false ) );

		$orders_before = $this->order_count();
		$checkout      = $this->blocks_checkout();
		$this->assertSame( 409, $checkout->get_status(), (string) wp_json_encode( $checkout->get_data() ) );
		$this->assertStringContainsString( VirtualCoupons::verify_message(), (string) wp_json_encode( $checkout->get_data() ) );
		$this->assertSame( $orders_before, $this->order_count(), 'refused before the draft order is created' );
		$this->assertNotContains( 'pxl-points', WC()->cart->get_applied_coupons(), 'Store API removed the coupon' );

		// Classic: re-link, re-apply, invalidate, then the cart check (woocommerce_check_cart_items) removes it.
		RedemptionState::update( array( 'verified' => true ) );
		$this->cart_action( array( 'action' => 'set_points', 'points' => 1000 ) );
		$this->assertContains( 'pxl-points', WC()->cart->get_applied_coupons() );
		add_filter( 'pixalink_loyalty_spike_redemption_enabled', '__return_false' );
		wc_clear_notices();

		WC()->cart->check_cart_coupons();

		$this->assertNotContains( 'pxl-points', WC()->cart->get_applied_coupons() );
		$notices = implode( ' ', wp_list_pluck( wc_get_notices( 'error' ), 'notice' ) );
		$this->assertStringContainsString( 'Your Pixalink points discount was removed. Pixalink points and vouchers are temporarily unavailable.', $notices );
		$this->assertStringNotContainsString( 'pxl-points', $notices, 'woocommerce_coupon_error replaced the generic notice that leaks the code' );
	}

	/**
	 * Orders in any status, drafts included.
	 *
	 * @return int
	 */
	private function order_count(): int {
		return count(
			wc_get_orders(
				array(
					'limit'  => -1,
					'return' => 'ids',
					'status' => array_keys( wc_get_order_statuses() ),
				)
			)
		);
	}

	/**
	 * Pitfall: an empty product_ids list means "every product" to WooCommerce, so a SKU-scoped voucher whose SKUs
	 * match nothing would silently discount the whole cart. The veto refuses it instead.
	 */
	public function test_sku_scoped_voucher_with_unknown_skus_is_refused_instead_of_becoming_cart_wide(): void {
		$this->fill_cart();
		$this->cart_action( array( 'action' => 'spike_link' ) );

		$coupon = new \WC_Coupon( 'PXL-GHOST-92' );
		$this->assertTrue( $coupon->get_virtual() );
		$this->assertSame( array(), $coupon->get_product_ids(), 'what WooCommerce would see without the veto' );

		$response = $this->store_api( 'POST', 'cart/apply-coupon', array( 'code' => 'PXL-GHOST-92' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'does not apply to any product', $response->get_data()['message'] );
	}

	/**
	 * The filter answers only when a WC session exists (admin/cron/IPN contexts fall back to stored coupon_info),
	 * and never for non-Pixalink codes or ID lookups.
	 */
	public function test_coupon_data_is_session_scoped_and_prefix_scoped(): void {
		$coupons = \Pixalink\Loyalty\Spike\Spike::instance()->coupons();
		$this->cart_action( array( 'action' => 'spike_link' ) );

		$this->assertFalse( $coupons->coupon_data( false, 'summer10', null ) );
		$this->assertFalse( $coupons->coupon_data( false, 123, null ) );
		$this->assertSame( array( 'already' ), $coupons->coupon_data( array( 'already' ), 'pxl-points', null ) );
		$this->assertSame( 'percent', $coupons->coupon_data( false, 'PXL-TENPCT-89', null )['discount_type'], 'voucher resolves' );

		$session         = WC()->session;
		WC()->session    = null;
		$without_session = $coupons->coupon_data( false, 'PXL-TENPCT-89', null );
		WC()->session    = $session;
		$this->assertFalse( $without_session );
	}

	/**
	 * Common arrangement.
	 *
	 * @param string $tax_mode none | excl | incl.
	 * @return void
	 */
	private function arrange( string $tax_mode ): void {
		if ( 'none' !== $tax_mode ) {
			$this->use_taxes( 'incl' === $tax_mode );
		}
		$this->fill_cart();
		$this->assertSame( 200, $this->cart_action( array( 'action' => 'spike_link' ) )->get_status() );
	}

	/**
	 * Cart total before discounts.
	 *
	 * @param string $tax_mode Tax mode.
	 * @return float
	 */
	private function gross( string $tax_mode ): float {
		return 'none' === $tax_mode ? 200.0 : 220.0;
	}

	/**
	 * Order totals, coupon line amounts and the reconciliation meta.
	 *
	 * @param mixed  $order    Order.
	 * @param string $tax_mode Tax mode.
	 * @param string $coupon   Coupon key.
	 * @param float  $expected Expected total.
	 * @return void
	 */
	private function assert_order( $order, string $tax_mode, string $coupon, float $expected ): void {
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertEqualsWithDelta( $expected, (float) $order->get_total(), 0.001, 'order total' );
		$this->assertSame( 'incl' === $tax_mode, $order->get_prices_include_tax() );

		$line = $this->coupon_line( $order, self::CODES[ $coupon ] );
		$this->assertEqualsWithDelta( $this->gross( $tax_mode ) - $expected, (float) $line->get_discount() + (float) $line->get_discount_tax(), 0.011, 'coupon line discount + discount_tax' );
		if ( 'none' === $tax_mode ) {
			$this->assertEqualsWithDelta( 0.0, (float) $line->get_discount_tax(), 0.001 );
		} else {
			$this->assertGreaterThan( 0.0, (float) $line->get_discount_tax(), 'discount_tax recorded separately' );
		}

		$snapshot = $line->get_meta( '_pixalink_coupon' );
		$this->assertIsArray( $snapshot );
		if ( 'points' === $coupon ) {
			$this->assertSame( 'points', $line->get_meta( '_pixalink_kind' ) );
			$this->assertSame( 1000, (int) $line->get_meta( '_pixalink_points' ) );
			$this->assertSame( '', (string) $line->get_meta( '_pixalink_voucher_id' ) );
			$this->assertSame( CouponMapper::POINTS_CODE, $line->get_code() );
		} else {
			$this->assertSame( 'voucher', $line->get_meta( '_pixalink_kind' ) );
			$this->assertSame( (string) ( 89 + array_search( $coupon, array( 'percent', 'fixed_product', 'free_item' ), true ) ), (string) $line->get_meta( '_pixalink_voucher_id' ) );
			$this->assertSame( strtolower( self::CODES[ $coupon ] ), $line->get_code(), 'WooCommerce stores the lower-cased code' );
		}

		if ( 'free_item' === $coupon ) {
			$this->assertSame( array( $this->product_b ), array_map( 'intval', $snapshot['product_ids'] ) );
			$this->assertSame( 1, (int) $snapshot['limit_usage_to_x_items'] );
		}
	}
}
