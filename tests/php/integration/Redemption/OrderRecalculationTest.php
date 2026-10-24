<?php
/**
 * Integration: admin recalculation of orders carrying our virtual coupons.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Redemption;

use Automattic\WooCommerce\Internal\Orders\TaxesController;

/**
 * Order (10% exclusive tax): 1 x A 100 + 2 x B 50, the "free B" voucher (percent 100, product B, one unit) and
 * 1,000 points (10.00): 200 - 50 - 10 = 140 net + 14 tax = 154.00.
 *
 * Admin has no WC session, so `woocommerce_get_shop_coupon_data` answers false and WooCommerce rebuilds virtual
 * coupons from the line's `coupon_info` (id, code, type, amount only) unless our snapshot filter steps in.
 *
 * @covers \Pixalink\Loyalty\Redemption\VirtualCoupons
 * @covers \Pixalink\Loyalty\Redemption\CouponMapper
 */
final class OrderRecalculationTest extends RedemptionTestCase {

	private const TOTAL = 154.0;

	/**
	 * Order under test.
	 *
	 * @var \WC_Order
	 */
	private \WC_Order $order;

	public function set_up(): void {
		parent::set_up();

		$this->use_taxes( false );
		$this->fill_cart();
		$this->act( array( 'action' => 'refresh_quote' ) );
		$this->act(
			array(
				'action' => 'apply_voucher',
				'code'   => 'PXL-FREEB-91',
			)
		);
		$this->act(
			array(
				'action' => 'set_points',
				'points' => 1000,
			)
		);

		$order_id = $this->classic_create_order();
		$this->assertIsInt( $order_id, is_wp_error( $order_id ) ? $order_id->get_error_message() : '' );
		$this->order = wc_get_order( $order_id );
		$this->assertEqualsWithDelta( self::TOTAL, (float) $this->order->get_total(), 0.001 );

		// From here on we are "in wp-admin": no shopper session.
		$this->module()->state()->reset();
		WC()->session = null;
	}

	public function tear_down(): void {
		wc_load_cart();
		parent::tear_down();
	}

	/**
	 * The "Recalculate" button (TaxesController::calc_line_taxes) never re-resolves coupons: line totals already carry
	 * the discount, so the total does not move.
	 */
	public function test_recalculate_button_keeps_totals_and_never_reresolves_coupons(): void {
		require_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php';
		$asked = array();
		add_filter(
			'woocommerce_get_shop_coupon_data',
			static function ( $data, $code ) use ( &$asked ) {
				if ( is_string( $code ) && '' !== $code ) {
					$asked[] = $code;
				}
				return $data;
			},
			1,
			2
		);

		$order = wc_get_container()->get( TaxesController::class )->calc_line_taxes(
			array(
				'order_id' => $this->order->get_id(),
				'items'    => '',
				'country'  => 'US',
				'state'    => 'CA',
				'postcode' => '94105',
				'city'     => 'San Francisco',
			)
		);

		$this->assertSame( array(), $asked, 'no coupon was re-resolved' );
		$this->assertEqualsWithDelta( self::TOTAL, (float) $order->get_total(), 0.001 );
		$this->assertEqualsWithDelta( 14.0, (float) $order->get_total_tax(), 0.001 );
	}

	/**
	 * Without the snapshot filter, recalculate_coupons() (admin Apply/Remove coupon, REST order updates, order-pay)
	 * turns "free B" into 100% off every line and the order collapses (ADR pitfall 6).
	 */
	public function test_recalculate_coupons_without_the_snapshot_filter_corrupts_scoped_vouchers(): void {
		remove_filter( 'woocommerce_order_recalculate_coupons_coupon_object', array( $this->module()->coupons(), 'rebuild_for_recalculation' ), 10 );
		$info = json_decode( (string) $this->coupon_line( $this->order, 'pxl-freeb-91' )->get_meta( 'coupon_info' ), true );
		$this->assertSame( array( 0, 'pxl-freeb-91', 'percent', 100 ), array_slice( $info, 0, 4 ), 'coupon_info keeps only id, code, type, amount' );

		$this->order->recalculate_coupons();
		add_filter( 'woocommerce_order_recalculate_coupons_coupon_object', array( $this->module()->coupons(), 'rebuild_for_recalculation' ), 10, 4 );

		$this->assertEqualsWithDelta( 0.0, (float) $this->order->get_total(), 0.001, '100% off everything' );
	}

	public function test_recalculate_coupons_with_the_snapshot_filter_keeps_totals(): void {
		$this->order->recalculate_coupons();

		$this->assertEqualsWithDelta( self::TOTAL, (float) $this->order->get_total(), 0.001 );
		$this->assertEqualsWithDelta( 50.0, (float) $this->coupon_line( $this->order, 'pxl-freeb-91' )->get_discount(), 0.001 );
		$this->assertEqualsWithDelta( 10.0, (float) $this->coupon_line( $this->order, 'pxl-points' )->get_discount(), 0.001 );
	}

	/**
	 * The admin adds a store coupon to the order: every coupon is re-applied, ours from the snapshot.
	 */
	public function test_admin_apply_and_remove_coupon_only_change_the_store_coupon(): void {
		$real = new \WC_Coupon();
		$real->set_code( 'real5' );
		$real->set_discount_type( 'fixed_cart' );
		$real->set_amount( '5' );
		$real->save();

		$this->assertTrue( $this->order->apply_coupon( 'real5' ) );
		$this->assertEqualsWithDelta( self::TOTAL - 5.5, (float) $this->order->get_total(), 0.001, '5 off plus its 0.50 tax' );
		$this->assertEqualsWithDelta( 50.0, (float) $this->coupon_line( $this->order, 'pxl-freeb-91' )->get_discount(), 0.001 );

		$this->order->remove_coupon( 'real5' );
		$this->assertEqualsWithDelta( self::TOTAL, (float) $this->order->get_total(), 0.001 );
	}
}
