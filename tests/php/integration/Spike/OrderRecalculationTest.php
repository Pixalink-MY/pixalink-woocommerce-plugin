<?php
/**
 * SPIKE (P02, throwaway): Q5, admin recalculation of orders carrying virtual coupons.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Spike;

use Automattic\WooCommerce\Internal\Orders\TaxesController;
use Pixalink\Loyalty\Spike\RedemptionState;
use Pixalink\Loyalty\Spike\Spike;

/**
 * Order (10% exclusive tax): 1 x A 100 + 2 x B 50, "free B" voucher (percent 100, product B, limit 1) and
 * 1,000 points (10.00): 200 - 50 - 10 = 140 net + 14 tax = 154.00.
 *
 * Admin contexts have no WC session, so `woocommerce_get_shop_coupon_data` answers false and WooCommerce rebuilds
 * virtual coupons from the coupon line's `coupon_info` meta (id, code, type, amount only).
 *
 * @covers \Pixalink\Loyalty\Spike\VirtualCoupons
 */
final class OrderRecalculationTest extends SpikeTestCase {

	private const TOTAL = 154.0;

	/**
	 * Order under test.
	 *
	 * @var \WC_Order
	 */
	private \WC_Order $order;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pixalink_loyalty_spike_hold_enabled', '__return_false' );

		$this->use_taxes( false );
		$this->fill_cart();
		$this->cart_action( array( 'action' => 'spike_link' ) );
		$this->cart_action(
			array(
				'action' => 'apply_voucher',
				'code'   => 'PXL-FREEB-91',
			)
		);
		$this->cart_action(
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
		RedemptionState::reset();
		WC()->session = null;
	}

	public function tear_down(): void {
		wc_load_cart();
		parent::tear_down();
	}

	/**
	 * The "Recalculate" button (wc-ajax calc_line_taxes -> TaxesController::calc_line_taxes): wc_save_order_items(),
	 * calculate_taxes(), calculate_totals( false ). None of these re-resolve coupons; line totals already carry the
	 * discount, so the order total does not move and woocommerce_get_shop_coupon_data is never asked for our codes.
	 */
	public function test_recalculate_button_keeps_totals_and_never_reresolves_coupons(): void {
		require_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php';
		$asked  = array();
		$before = 0;
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
		add_action(
			'woocommerce_order_before_calculate_totals',
			static function () use ( &$before ): void {
				++$before;
			}
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

		$this->assertGreaterThan( 0, $before, 'woocommerce_order_before_calculate_totals fired' );
		$this->assertSame( array(), $asked, 'no coupon was re-resolved' );
		$this->assertEqualsWithDelta( self::TOTAL, (float) $order->get_total(), 0.001 );
		$this->assertEqualsWithDelta( 14.0, (float) $order->get_total_tax(), 0.001 );
	}

	/**
	 * WC_Abstract_Order::recalculate_coupons() runs on admin "Apply coupon" / "Remove", REST v4 order updates and the
	 * Store API order-pay flow. Without our filter the "free B" voucher comes back from coupon_info as percent 100 on
	 * every line (product_ids and limit_usage_to_x_items are not stored) and the order collapses.
	 */
	public function test_recalculate_coupons_without_the_snapshot_filter_corrupts_scoped_vouchers(): void {
		remove_filter( 'woocommerce_order_recalculate_coupons_coupon_object', array( Spike::instance()->coupons(), 'rebuild_for_recalculation' ), 10 );
		$info = json_decode( (string) $this->coupon_line( $this->order, 'pxl-freeb-91' )->get_meta( 'coupon_info' ), true );
		$this->assertSame( array( 0, 'pxl-freeb-91', 'percent', 100 ), array_slice( $info, 0, 4 ), 'coupon_info keeps only id, code, type, amount' );

		$this->order->recalculate_coupons();

		$this->assertEqualsWithDelta( 0.0, (float) $this->order->get_total(), 0.001, '100% off everything' );
	}

	public function test_recalculate_coupons_with_the_snapshot_filter_keeps_totals(): void {
		$this->order->recalculate_coupons();

		$this->assertEqualsWithDelta( self::TOTAL, (float) $this->order->get_total(), 0.001 );
		$this->assertEqualsWithDelta( 50.0, (float) $this->coupon_line( $this->order, 'pxl-freeb-91' )->get_discount(), 0.001 );
		$this->assertEqualsWithDelta( 10.0, (float) $this->coupon_line( $this->order, 'pxl-points' )->get_discount(), 0.001 );
	}

	/**
	 * Admin adds a real store coupon to the order: every coupon is re-applied, ours from the snapshot.
	 */
	public function test_admin_apply_coupon_on_the_order_only_adds_the_new_discount(): void {
		$real = new \WC_Coupon();
		$real->set_code( 'real5' );
		$real->set_discount_type( 'fixed_cart' );
		$real->set_amount( '5' );
		$real->save();

		$this->assertTrue( $this->order->apply_coupon( 'real5' ) );

		$this->assertEqualsWithDelta( self::TOTAL - 5.5, (float) $this->order->get_total(), 0.001, '5 off + its 0.50 tax' );
		$this->assertEqualsWithDelta( 50.0, (float) $this->coupon_line( $this->order, 'pxl-freeb-91' )->get_discount(), 0.001 );
	}
}
