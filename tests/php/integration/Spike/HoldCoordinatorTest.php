<?php
/**
 * SPIKE (P02, throwaway): Q4, commit-at-submit holds in both checkouts.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Spike;

use Pixalink\Loyalty\Spike\HoldCoordinator;

/**
 * The fake Pixalink server holds at most 1,250 points and only voucher 88; the canned quote offers 2,000 points,
 * so 1,500 points previews fine in the cart and is refused at submit (409 points_insufficient).
 *
 * @covers \Pixalink\Loyalty\Spike\HoldCoordinator
 */
final class HoldCoordinatorTest extends SpikeTestCase {

	private const REFUSED = 'You no longer have enough Pixalink points for this discount. Lower the points and place the order again.';

	public function test_hooks_and_priorities(): void {
		$holds = \Pixalink\Loyalty\Spike\Spike::instance()->holds();

		$this->assertSame( 20, has_action( 'woocommerce_checkout_create_order', array( $holds, 'hold_classic' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_store_api_checkout_order_processed', array( $holds, 'hold_blocks' ) ) );
	}

	public function test_classic_hold_runs_on_an_unsaved_order_whose_key_we_set_and_the_order_is_saved_with_it(): void {
		$seen = array();
		add_action(
			'woocommerce_checkout_create_order',
			static function ( \WC_Order $order ) use ( &$seen ): void {
				$seen['before'] = array( $order->get_id(), $order->get_order_key() );
			},
			19
		);
		add_action(
			'woocommerce_checkout_create_order',
			static function ( \WC_Order $order ) use ( &$seen ): void {
				$seen['after'] = $order->get_order_key();
			},
			21
		);
		$this->spend( 1000, true );

		$order_id = $this->classic_create_order();

		$this->assertIsInt( $order_id, is_wp_error( $order_id ) ? $order_id->get_error_message() : '' );
		$this->assertSame( array( 0, '' ), $seen['before'], 'no ID and no order key yet at woocommerce_checkout_create_order' );
		$order = wc_get_order( $order_id );
		$this->assertSame( $seen['after'], $order->get_order_key(), 'data store kept the key set in the hook' );
		$this->assert_held( $order );
	}

	public function test_classic_hold_failure_aborts_without_saving_an_order(): void {
		$exceptions = did_action( 'woocommerce_checkout_order_exception' );
		$orders     = $this->order_ids();
		$this->spend( 1500, false );

		$result = $this->classic_create_order();

		$this->assertWPError( $result );
		$this->assertSame( self::REFUSED, $result->get_error_message() );
		$this->assertSame( $orders, $this->order_ids(), 'nothing saved' );
		$this->assertSame( $exceptions + 1, did_action( 'woocommerce_checkout_order_exception' ) );
		$this->assertSame( 409, $this->last_put()['status'] );
	}

	/**
	 * The full classic handler (minus the AJAX JSON exit) turns the WP_Error into the checkout error notice.
	 */
	public function test_classic_process_checkout_shows_the_refusal_as_a_notice(): void {
		$orders = $this->order_ids();
		$this->spend( 1500, false );

		$_POST = array(
			'payment_method'                     => 'bacs',
			'woocommerce-process-checkout-nonce' => wp_create_nonce( 'woocommerce-process_checkout' ),
		);
		foreach ( $this->billing_address() as $key => $value ) {
			$_POST[ 'billing_' . $key ] = $value;
		}
		$_REQUEST = $_POST;

		try {
			WC()->checkout()->process_checkout();
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}

		$this->assertContains( self::REFUSED, wp_list_pluck( wc_get_notices( 'error' ), 'notice' ) );
		$this->assertSame( $orders, $this->order_ids() );
	}

	public function test_blocks_hold_uses_the_saved_draft_order_key(): void {
		$this->spend( 1000, true );

		$response = $this->blocks_checkout();

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$order = wc_get_order( $response->get_data()['order_id'] );
		$this->assertNotSame( '', $order->get_order_key() );
		$this->assert_held( $order );
		$this->assertSame( 'on-hold', $order->get_status(), 'BACS ran after the hold' );
	}

	public function test_blocks_hold_failure_returns_409_and_keeps_a_reusable_draft_order(): void {
		$this->spend( 1500, false );

		$refused = $this->blocks_checkout();

		$this->assertSame( 409, $refused->get_status(), (string) wp_json_encode( $refused->get_data() ) );
		$this->assertSame( 'pixalink_loyalty_hold_failed', $refused->get_data()['code'] );
		$this->assertSame( self::REFUSED, $refused->get_data()['message'] );

		$draft_id = (int) WC()->session->get( 'store_api_draft_order' );
		$this->assertGreaterThan( 0, $draft_id );
		$this->assertSame( 'checkout-draft', wc_get_order( $draft_id )->get_status(), 'restored from pending before throwing' );

		// The shopper lowers the points and places the order again: same order, holds replaced declaratively.
		$this->cart_action(
			array(
				'action' => 'set_points',
				'points' => 1000,
			)
		);
		$retry = $this->blocks_checkout();

		$this->assertSame( 200, $retry->get_status(), (string) wp_json_encode( $retry->get_data() ) );
		$this->assertSame( $draft_id, (int) $retry->get_data()['order_id'], 'draft reused, nothing orphaned' );
		$this->assertSame( array( $draft_id ), $this->order_ids() );
		$order = wc_get_order( $draft_id );
		$this->assert_held( $order );
		$this->assertSame( '1000', (string) $this->coupon_line( $order, 'pxl-points' )->get_meta( '_pixalink_points' ) );
		$this->assertEqualsWithDelta( 10.0, (float) $this->coupon_line( $order, 'pxl-points' )->get_discount(), 0.001 );
	}

	/**
	 * WooCommerce behaviour without VirtualCoupons::refresh_draft_coupon_lines(): the Store API rebuilds a reused
	 * draft's coupon lines only when the applied coupon *codes* change, so the retry still carries 1,500 points.
	 */
	public function test_a_reused_draft_keeps_a_stale_points_line_without_the_refresh(): void {
		add_filter( 'pixalink_loyalty_spike_refresh_draft_coupon_lines', '__return_false' );
		$this->spend( 1500, false );
		$this->assertSame( 409, $this->blocks_checkout()->get_status() );

		$this->cart_action(
			array(
				'action' => 'set_points',
				'points' => 1000,
			)
		);
		$retry = $this->blocks_checkout();

		$this->assertSame( 409, $retry->get_status(), 'stale line re-sent' );
		$put = $this->last_put();
		$this->assertSame( 1500, $put['body']['redemptions'][0]['points'] );
		$this->assertSame( '15.00', $put['body']['redemptions'][0]['discount'] );
	}

	/**
	 * WooCommerce behaviour without the restore: the Store API set the order to `pending` right before
	 * woocommerce_store_api_checkout_order_processed and re-saves it as-is when the hook throws. A changed cart
	 * hash then makes the retry create a second order and the first one is orphaned.
	 */
	public function test_without_restoring_the_draft_a_failed_hold_leaves_an_orphaned_pending_order(): void {
		add_filter( 'pixalink_loyalty_spike_restore_draft_on_hold_failure', '__return_false' );
		$this->spend( 1500, false );

		$this->assertSame( 409, $this->blocks_checkout()->get_status() );
		$first = (int) WC()->session->get( 'store_api_draft_order' );
		$this->assertSame( 'pending', wc_get_order( $first )->get_status() );

		$this->cart_action(
			array(
				'action' => 'set_points',
				'points' => 1000,
			)
		);
		$retry = $this->blocks_checkout();

		$this->assertSame( 200, $retry->get_status() );
		$this->assertNotSame( $first, (int) $retry->get_data()['order_id'] );
		$this->assertSame( 'pending', wc_get_order( $first )->get_status(), 'orphan left behind' );
	}

	public function test_put_body_is_built_from_the_coupon_lines(): void {
		$this->spend( 1000, true );
		$order = wc_get_order( $this->classic_create_order() );

		$this->assertEqualsCanonicalizing(
			array(
				array(
					'kind'         => 'points',
					'coupon_code'  => 'pxl-points',
					'discount'     => '10.00',
					'discount_tax' => '0.00',
					'points'       => 1000,
				),
				array(
					'kind'         => 'voucher',
					'coupon_code'  => 'pxl-welcome-88',
					'discount'     => '10.00',
					'discount_tax' => '0.00',
					'voucher_id'   => '88',
				),
			),
			HoldCoordinator::redemptions( $order )
		);
	}

	/**
	 * Link, add 1 x A + 2 x B, select points and optionally the welcome voucher.
	 *
	 * @param int  $points  Points.
	 * @param bool $voucher Also apply PXL-WELCOME-88.
	 * @return void
	 */
	private function spend( int $points, bool $voucher ): void {
		$this->fill_cart();
		$this->cart_action( array( 'action' => 'spike_link' ) );
		$this->assertSame(
			200,
			$this->cart_action(
				array(
					'action' => 'set_points',
					'points' => $points,
				)
			)->get_status()
		);
		if ( $voucher ) {
			$this->assertSame(
				200,
				$this->cart_action(
					array(
						'action' => 'apply_voucher',
						'code'   => 'PXL-WELCOME-88',
					)
				)->get_status()
			);
		}
	}

	/**
	 * Holds placed with the saved order key and recorded on the order.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	private function assert_held( \WC_Order $order ): void {
		$put = $this->last_put();
		$this->assertSame( 200, $put['status'], 'fake server accepted the body (it answers 422 on contract violations)' );
		$this->assertSame( 'orders/' . $order->get_order_key() . '/redemptions', $put['path'] );
		$this->assertSame( '5001', $put['body']['customer_id'] );
		$this->assertSame( 'USD', $put['body']['currency'] );
		$this->assertSame( HoldCoordinator::redemptions( $order ), $put['body']['redemptions'] );

		$this->assertSame( 'held', $order->get_meta( '_pixalink_redemption_state' ) );
		$held = $order->get_meta( '_pixalink_redemptions' );
		$this->assertIsArray( $held );
		$this->assertCount( count( $put['body']['redemptions'] ), $held );
		$this->assertMatchesRegularExpression( '/^CH-/', $held[0]['redemption_id'] );
	}

	/**
	 * Last PUT the fake server received.
	 *
	 * @return array<string,mixed>
	 */
	private function last_put(): array {
		$puts = $this->pixalink_requests( 'PUT', 'orders/' );
		$this->assertNotEmpty( $puts, 'no PUT orders/{key}/redemptions recorded' );

		return end( $puts );
	}

	/**
	 * IDs of all orders, drafts included.
	 *
	 * @return array<int,int>
	 */
	private function order_ids(): array {
		return array_map(
			'intval',
			wc_get_orders(
				array(
					'limit'  => -1,
					'return' => 'ids',
					'status' => array_keys( wc_get_order_statuses() ),
				)
			)
		);
	}
}
