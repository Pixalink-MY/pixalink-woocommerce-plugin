<?php
/**
 * Integration: releasing the holds of abandoned orders.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Redemption;

use Pixalink\Loyalty\Events\Scheduler;
use Pixalink\Loyalty\Orders\OrderMeta;
use Pixalink\Loyalty\Redemption\HoldSweeper;

/**
 * @covers \Pixalink\Loyalty\Redemption\HoldSweeper
 */
final class HoldSweeperTest extends RedemptionTestCase {

	public function test_the_sweep_runs_every_15_minutes(): void {
		$this->assertSame( HoldSweeper::INTERVAL, Scheduler::RECURRING[ HoldSweeper::HOOK ] );
		$this->assertSame( 900, HoldSweeper::INTERVAL );
		$this->assertSame( 10, has_action( HoldSweeper::HOOK, array( $this->module()->sweeper(), 'handle_scheduled' ) ) );
	}

	public function test_holds_of_unpaid_orders_past_their_ttl_are_released(): void {
		$expired = $this->held_order();
		OrderMeta::update( $expired, array( OrderMeta::REDEMPTION_EXPIRES_AT => time() - 60 ) );
		$current = $this->held_order();

		$released = $this->module()->sweeper()->sweep();

		$this->assertSame( 1, $released );
		$expired = wc_get_order( $expired->get_id() );
		$this->assertSame( OrderMeta::REDEMPTION_RELEASED, OrderMeta::get_string( $expired, OrderMeta::REDEMPTION_STATE ) );
		$this->assertSame( 'DELETE', $this->last_request( 'DELETE', 'orders/' )['method'] );
		$this->assertSame( 'orders/' . $expired->get_order_key() . '/redemptions', $this->last_request( 'DELETE', 'orders/' )['path'] );
		$this->assertStringContainsString( 'released because it was abandoned', implode( ' ', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $expired->get_id() ) ), 'content' ) ) );
		$this->assertTrue( OrderMeta::is_held( wc_get_order( $current->get_id() ) ), 'holds within their TTL stay' );
	}

	public function test_idle_checkout_drafts_are_abandoned_after_an_hour(): void {
		$sweeper = new HoldSweeper( \Pixalink\Loyalty\Plugin::instance()->redemptions_api(), \Pixalink\Loyalty\Plugin::instance()->logger(), static fn (): int => time() + HoldSweeper::DRAFT_IDLE_SECONDS + 5 );
		$draft   = $this->held_order();
		$draft->set_status( 'checkout-draft' );
		$draft->save();

		$this->assertTrue( $sweeper->is_abandoned( wc_get_order( $draft->get_id() ) ) );
		$this->assertFalse( $this->module()->sweeper()->is_abandoned( wc_get_order( $draft->get_id() ) ), 'not yet idle' );
	}

	public function test_paid_orders_are_never_swept(): void {
		$order = $this->held_order();
		OrderMeta::update( $order, array( OrderMeta::REDEMPTION_EXPIRES_AT => time() - 60 ) );
		$order->payment_complete();

		$this->assertSame( 0, $this->module()->sweeper()->sweep() );
		$this->assertSame( array(), $this->pixalink_requests( 'DELETE', 'orders/' ) );
	}

	public function test_a_failed_release_is_retried_on_the_next_sweep(): void {
		$order = $this->held_order();
		OrderMeta::update( $order, array( OrderMeta::REDEMPTION_EXPIRES_AT => time() - 60 ) );

		add_filter( 'pre_http_request', array( HoldCoordinatorTest::class, 'network_down' ), 5, 3 );
		$released = $this->module()->sweeper()->sweep();
		remove_filter( 'pre_http_request', array( HoldCoordinatorTest::class, 'network_down' ), 5 );

		$this->assertSame( 0, $released );
		$this->assertTrue( OrderMeta::is_held( wc_get_order( $order->get_id() ) ) );
		$this->assertSame( 1, $this->module()->sweeper()->sweep() );
	}

	/**
	 * An unpaid (pending) classic order holding 1,000 points.
	 *
	 * @return \WC_Order
	 */
	private function held_order(): \WC_Order {
		$this->fresh_session();
		$this->fill_cart();
		$this->act( array( 'action' => 'refresh_quote' ) );
		$this->act(
			array(
				'action' => 'set_points',
				'points' => 1000,
			)
		);
		$order_id = $this->classic_create_order();
		$this->assertIsInt( $order_id );
		$order = wc_get_order( $order_id );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertTrue( OrderMeta::is_held( $order ) );

		return $order;
	}
}
