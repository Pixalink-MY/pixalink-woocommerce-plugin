<?php
/**
 * Integration: holds placed at submit in both checkouts.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Redemption;

use Pixalink\Loyalty\Events\EventRepository;
use Pixalink\Loyalty\Events\Event;
use Pixalink\Loyalty\Events\EventType;
use Pixalink\Loyalty\Orders\OrderMeta;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Redemption\HoldCoordinator;
use Pixalink\Loyalty\Redemption\Labels;
use Pixalink\Loyalty\Tests\FakeServer\Server;

/**
 * The member holds 1,250 points; the quote offers up to 1,200 for the 200.00 cart. To reproduce "the balance
 * changed between preview and submit", the fake server's balance drops to 1,000 after the points were chosen,
 * so the PUT is refused with 409 points_insufficient.
 *
 * @covers \Pixalink\Loyalty\Redemption\HoldCoordinator
 * @covers \Pixalink\Loyalty\Redemption\VirtualCoupons
 * @covers \Pixalink\Loyalty\Orders\OrderLifecycle
 */
final class HoldCoordinatorTest extends RedemptionTestCase {

	public function test_hooks_and_priorities(): void {
		$holds = $this->module()->holds();

		$this->assertSame( HoldCoordinator::CLASSIC_PRIORITY, has_action( 'woocommerce_checkout_create_order', array( $holds, 'hold_classic' ) ) );
		$this->assertSame( 20, HoldCoordinator::CLASSIC_PRIORITY );
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
		$this->spend( 1000, true, true );

		$order_id = $this->classic_create_order();

		$this->assertIsInt( $order_id, is_wp_error( $order_id ) ? $order_id->get_error_message() : '' );
		$this->assertSame( array( 0, '' ), $seen['before'], 'no ID and no order key yet at woocommerce_checkout_create_order' );
		$order = wc_get_order( $order_id );
		$this->assertSame( $seen['after'], $order->get_order_key(), 'the data store kept the key set in the hook' );
		$this->assert_held( $order, 3 );
	}

	public function test_classic_hold_refusal_aborts_without_saving_an_order_and_requotes(): void {
		$exceptions = did_action( 'woocommerce_checkout_order_exception' );
		$orders     = $this->order_ids();
		$this->spend( 1200, false, false );
		Server::set_points_balance( 1000 );

		$result = $this->classic_create_order();

		$this->assertWPError( $result );
		$this->assertSame( Labels::hold_refused( 'points_insufficient' ), $result->get_error_message() );
		$this->assertSame( $orders, $this->order_ids(), 'nothing saved' );
		$this->assertSame( $exceptions + 1, did_action( 'woocommerce_checkout_order_exception' ) );
		$this->assertSame( 409, $this->last_request( 'PUT', 'orders/' )['status'] );
		$this->assertSame( 1000, $this->module()->state()->points(), 're-quoted and clamped: the shopper sees the new discount' );

		// Placing the order again succeeds with what the member still has.
		$retry = $this->classic_create_order();
		$this->assertIsInt( $retry );
		$this->assertSame( '1000', (string) $this->coupon_line( wc_get_order( $retry ), 'pxl-points' )->get_meta( OrderMeta::COUPON_POINTS ) );
	}

	/**
	 * The full classic handler (minus the AJAX JSON exit) turns the refusal into the checkout error notice.
	 */
	public function test_classic_process_checkout_shows_the_refusal_as_a_notice(): void {
		$orders = $this->order_ids();
		$this->spend( 1200, false, false );
		Server::set_points_balance( 1000 );

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

		$this->assertContains( Labels::hold_refused( 'points_insufficient' ), wp_list_pluck( wc_get_notices( 'error' ), 'notice' ) );
		$this->assertSame( $orders, $this->order_ids() );
	}

	public function test_a_classic_checkout_that_fails_after_the_hold_releases_it(): void {
		add_action(
			'woocommerce_checkout_create_order',
			static function (): void {
				throw new \Exception( 'Another extension refused the order.' );
			},
			30
		);
		$this->spend( 1000, false, false );

		$result = $this->classic_create_order();

		$this->assertWPError( $result );
		$put     = $this->last_request( 'PUT', 'orders/' );
		$release = $this->last_request( 'DELETE', 'orders/' );
		$this->assertSame( 200, $put['status'] );
		$this->assertSame( $put['path'], $release['path'], 'the holds of the unsaved order were given back' );
	}

	public function test_blocks_hold_uses_the_saved_order_key_before_payment(): void {
		$this->spend( 1000, true, true );

		$response = $this->blocks_checkout();

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$order = wc_get_order( $response->get_data()['order_id'] );
		$this->assertNotSame( '', $order->get_order_key() );
		$this->assert_held( $order, 3 );
		$this->assertSame( 'on-hold', $order->get_status(), 'BACS ran after the hold' );
	}

	public function test_blocks_hold_refusal_returns_409_and_keeps_a_reusable_draft_order(): void {
		$this->spend( 1200, false, false );
		Server::set_points_balance( 1000 );

		$refused = $this->blocks_checkout();

		$this->assertSame( 409, $refused->get_status(), (string) wp_json_encode( $refused->get_data() ) );
		$this->assertSame( 'pixalink_loyalty_hold_failed', $refused->get_data()['code'] );
		$this->assertSame( Labels::hold_refused( 'points_insufficient' ), $refused->get_data()['message'] );
		$this->assertSame( 1000, $refused->get_data()['data']['cart']['extensions']['pixalink-loyalty']['points']['applied'], 'the 409 carries the re-quoted cart, so the Checkout block shows the new total' );

		$draft_id = (int) WC()->session->get( 'store_api_draft_order' );
		$this->assertGreaterThan( 0, $draft_id );
		$this->assertSame( 'checkout-draft', wc_get_order( $draft_id )->get_status(), 'restored from pending before the error (ADR pitfall 5)' );

		// Placing the order again reuses the draft, and its coupon line is rebuilt with the new amount (pitfall 14).
		$retry = $this->blocks_checkout();

		$this->assertSame( 200, $retry->get_status(), (string) wp_json_encode( $retry->get_data() ) );
		$this->assertSame( $draft_id, (int) $retry->get_data()['order_id'], 'draft reused, nothing orphaned' );
		$this->assertSame( array( $draft_id ), $this->order_ids() );
		$order = wc_get_order( $draft_id );
		$this->assert_held( $order, 1 );
		$this->assertSame( '1000', (string) $this->coupon_line( $order, 'pxl-points' )->get_meta( OrderMeta::COUPON_POINTS ) );
		$this->assertEqualsWithDelta( 10.0, (float) $this->coupon_line( $order, 'pxl-points' )->get_discount(), 0.001 );
	}

	public function test_without_rebuilding_a_reused_drafts_coupon_lines_the_stale_amount_would_be_held(): void {
		remove_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this->module()->coupons(), 'refresh_draft_coupon_lines' ), 10 );
		$this->spend( 1200, false, false );
		Server::set_points_balance( 1000 );
		$this->assertSame( 409, $this->blocks_checkout()->get_status() );

		$retry = $this->blocks_checkout();

		$this->assertSame( 409, $retry->get_status(), 'the stale 1,200-point line is sent again' );
		$this->assertSame( 1200, $this->last_request( 'PUT', 'orders/' )['body']['redemptions'][0]['points'] );

		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this->module()->coupons(), 'refresh_draft_coupon_lines' ), 10, 1 );
	}

	public function test_discount_mismatch_requotes_and_asks_the_shopper_to_confirm(): void {
		$this->spend( 1000, false, false );
		$this->script_put( 422, 'discount_mismatch', 'The discount is worth more than the points held for it.' );
		$quotes = count( $this->pixalink_requests( 'POST', 'quote' ) );

		$refused = $this->blocks_checkout();

		$this->assertSame( 409, $refused->get_status() );
		$this->assertSame( Labels::hold_refused( 'discount_mismatch' ), $refused->get_data()['message'] );
		$this->assertSame( 'discount_mismatch', $refused->get_data()['data']['pixalink_code'] );
		$this->assertSame( $quotes + 1, count( $this->pixalink_requests( 'POST', 'quote' ) ), 're-quoted' );
	}

	public function test_pixalink_unreachable_blocks_only_orders_that_carry_redemptions(): void {
		$this->spend( 1000, false, false );
		add_filter( 'pre_http_request', array( self::class, 'network_down' ), 5, 3 );

		$refused = $this->blocks_checkout();

		remove_filter( 'pre_http_request', array( self::class, 'network_down' ), 5 );
		$this->assertSame( 409, $refused->get_status() );
		$this->assertSame( Labels::hold_refused( 'network_error' ), $refused->get_data()['message'] );

		// Without Pixalink rewards the order goes through.
		$this->act(
			array(
				'action' => 'set_points',
				'points' => 0,
			)
		);
		add_filter( 'pre_http_request', array( self::class, 'network_down' ), 5, 3 );
		$placed = $this->blocks_checkout();
		remove_filter( 'pre_http_request', array( self::class, 'network_down' ), 5 );
		$this->assertSame( 200, $placed->get_status(), (string) wp_json_encode( $placed->get_data() ) );
	}

	public function test_put_body_is_built_from_the_coupon_lines(): void {
		$this->spend( 1000, true, true );
		$order = wc_get_order( $this->classic_create_order() );

		$this->assertEqualsCanonicalizing(
			array(
				array(
					'kind'               => 'points',
					'coupon_code'        => 'pxl-points',
					'discount_cents'     => 1000,
					'discount_tax_cents' => 0,
					'points'             => 1000,
				),
				array(
					'kind'               => 'credit',
					'coupon_code'        => 'pxl-credits',
					'discount_cents'     => 500,
					'discount_tax_cents' => 0,
					'amount_cents'       => 500,
				),
				array(
					'kind'               => 'voucher',
					'coupon_code'        => 'pxl-welcome-88',
					'discount_cents'     => 1000,
					'discount_tax_cents' => 0,
					'voucher_id'         => '88',
				),
			),
			HoldCoordinator::redemptions( $order )
		);
	}

	public function test_a_failed_payment_releases_exactly_the_current_holds_through_the_outbox(): void {
		$this->spend( 1000, true, false );
		$order = wc_get_order( $this->blocks_checkout()->get_data()['order_id'] );
		$refs  = OrderMeta::hold_refs( $order );
		$this->assertCount( 2, $refs );

		$order->update_status( 'failed' );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( OrderMeta::REDEMPTION_RELEASED, OrderMeta::get_string( $order, OrderMeta::REDEMPTION_STATE ) );
		$events = array_values(
			array_filter(
				( new EventRepository() )->for_object( Event::OBJECT_ORDER, (int) $order->get_id() ),
				static fn ( Event $event ): bool => EventType::ORDER_REDEMPTIONS_RELEASED === $event->type
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame( 'payment_failed', $events[0]->payload['reason'] );
		$this->assertSame( $refs, $events[0]->payload['redemption_ids'] );

		$this->assertSame( 'delivered', Plugin::instance()->events()->deliverer()->deliver( $events[0]->id ) );
		$this->assertSame( 200, $this->last_request( 'POST', 'events' )['status'], 'the envelope satisfies event.request.json' );
	}

	public function test_a_release_still_in_the_outbox_does_not_mark_newer_holds_released(): void {
		$this->spend( 1000, false, false );
		$order = wc_get_order( $this->classic_create_order() );
		$order->update_status( 'failed' );
		$event = ( new EventRepository() )->for_object( Event::OBJECT_ORDER, (int) $order->get_id() )[0];

		// The shopper pays again and a new hold replaces the released one before the event goes out.
		$order = wc_get_order( $order->get_id() );
		OrderMeta::update(
			$order,
			array(
				OrderMeta::REDEMPTION_STATE => OrderMeta::REDEMPTION_HELD,
				OrderMeta::REDEMPTIONS      => array( array( 'redemption_id' => 'CH-NEWER1' ) ),
			)
		);
		Plugin::instance()->events()->deliverer()->deliver( $event->id );

		$this->assertSame( OrderMeta::REDEMPTION_HELD, OrderMeta::get_string( wc_get_order( $order->get_id() ), OrderMeta::REDEMPTION_STATE ) );
	}

	public function test_order_paid_carries_the_redemption_meta_of_every_coupon_line(): void {
		$this->spend( 1000, true, true );
		$order = wc_get_order( $this->blocks_checkout()->get_data()['order_id'] );

		$order->payment_complete();
		$paid = array_values(
			array_filter(
				( new EventRepository() )->for_object( Event::OBJECT_ORDER, (int) $order->get_id() ),
				static fn ( Event $event ): bool => EventType::ORDER_PAID === $event->type
			)
		);
		$this->assertCount( 1, $paid );
		Plugin::instance()->events()->deliverer()->deliver( $paid[0]->id );

		$sent = $this->last_request( 'POST', 'events' );
		$this->assertSame( 200, $sent['status'], 'the envelope satisfies event.request.json' );
		$lines = array_column( $sent['body']['data']['order']['coupon_lines'], null, 'code' );
		$refs  = array_column( OrderMeta::get( wc_get_order( $order->get_id() ), OrderMeta::REDEMPTIONS ), 'redemption_id', 'coupon_code' );

		$this->assertSame( 'points', $lines['pxl-points']['meta']['pixalink_kind'] );
		$this->assertSame( 1000, $lines['pxl-points']['meta']['pixalink_points'] );
		$this->assertSame( 1000, $lines['pxl-points']['meta']['pixalink_amount_cents'] );
		$this->assertSame( $refs['pxl-points'], $lines['pxl-points']['meta']['pixalink_redemption_id'] );
		$this->assertSame( 'credit', $lines['pxl-credits']['meta']['pixalink_kind'] );
		$this->assertSame( 500, $lines['pxl-credits']['meta']['pixalink_amount_cents'] );
		$this->assertSame( '88', $lines['pxl-welcome-88']['meta']['pixalink_voucher_id'] );
		$this->assertSame( 'fixed_cart', $lines['pxl-welcome-88']['meta']['pixalink_coupon']['discount_type'] );
		$this->assertSame( '10.00', $lines['pxl-welcome-88']['meta']['pixalink_coupon']['amount'] );
		$this->assertSame( '5001', $sent['body']['data']['customer']['pixalink_customer_id'] );
	}

	/**
	 * Refresh the quote, then choose points and optionally 5.00 credits and the welcome voucher (1 x A + 2 x B).
	 *
	 * @param int  $points  Points.
	 * @param bool $voucher Also apply PXL-WELCOME-88.
	 * @param bool $credits Also use 5.00 credits.
	 * @return void
	 */
	private function spend( int $points, bool $voucher, bool $credits ): void {
		$this->fill_cart();
		$this->act( array( 'action' => 'refresh_quote' ) );
		$this->act(
			array(
				'action' => 'set_points',
				'points' => $points,
			)
		);
		if ( $credits ) {
			$this->act(
				array(
					'action'       => 'set_credits',
					'amount_cents' => 500,
				)
			);
		}
		if ( $voucher ) {
			$this->act(
				array(
					'action' => 'apply_voucher',
					'code'   => 'PXL-WELCOME-88',
				)
			);
		}
	}

	/**
	 * Holds placed with the saved order key and recorded on the order and its coupon lines.
	 *
	 * @param \WC_Order $order Order.
	 * @param int       $count Expected holds.
	 * @return void
	 */
	private function assert_held( \WC_Order $order, int $count ): void {
		$put = $this->last_request( 'PUT', 'orders/' );
		$this->assertSame( 200, $put['status'], 'the fake server accepted the body (it answers 422 on contract violations)' );
		$this->assertSame( 'orders/' . $order->get_order_key() . '/redemptions', $put['path'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', (string) $put['headers']['idempotency-key'] );
		$this->assertSame( '5001', $put['body']['customer_id'] );
		$this->assertSame( 'USD', $put['body']['cart']['currency'] );
		$this->assertSame( HoldCoordinator::redemptions( $order ), $put['body']['redemptions'] );

		$this->assertSame( OrderMeta::REDEMPTION_HELD, OrderMeta::get_string( $order, OrderMeta::REDEMPTION_STATE ) );
		$this->assertSame( '5001', OrderMeta::get_string( $order, OrderMeta::CUSTOMER_ID ) );
		$this->assertGreaterThan( time(), (int) OrderMeta::get_string( $order, OrderMeta::REDEMPTION_EXPIRES_AT ) );
		$held = OrderMeta::get( $order, OrderMeta::REDEMPTIONS );
		$this->assertIsArray( $held );
		$this->assertCount( $count, $held );

		foreach ( $held as $entry ) {
			$this->assertMatchesRegularExpression( '/^CH-/', $entry['redemption_id'] );
			$this->assertSame( $entry['redemption_id'], (string) $this->coupon_line( $order, $entry['coupon_code'] )->get_meta( OrderMeta::COUPON_REDEMPTION_ID ), 'the line knows its hold' );
		}
	}

	/**
	 * Script the fake server's next PUT.
	 *
	 * @param int    $status  Status.
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @return void
	 */
	private function script_put( int $status, string $code, string $message ): void {
		$request = new \WP_REST_Request( 'POST', '/fake-pixalink/v1/script' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'method' => 'PUT',
					'path'   => 'orders/*',
					'status' => $status,
					'body'   => array(
						'message' => $message,
						'code'    => $code,
					),
				)
			)
		);
		Server::add_script( $request );
	}

	/**
	 * `pre_http_request` stand-in for Pixalink being unreachable.
	 *
	 * @param mixed  $preempt Earlier value.
	 * @param mixed  $args    Arguments.
	 * @param string $url     URL.
	 * @return mixed
	 */
	public static function network_down( $preempt, $args, $url ) {
		unset( $args );

		return false !== strpos( $url, 'fake-pixalink.test' ) ? new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) : $preempt;
	}
}
