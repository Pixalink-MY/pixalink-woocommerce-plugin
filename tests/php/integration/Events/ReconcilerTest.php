<?php
/**
 * Integration tests for Reconciler.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Events;

use Pixalink\Loyalty\Events\Reconciler;
use Pixalink\Loyalty\Events\Scheduler;
use Pixalink\Loyalty\Orders\OrderMeta;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Tests\Integration\Support\OrderFixtures;

/**
 * The daily safety net and the batched resend.
 *
 * @covers \Pixalink\Loyalty\Events\Reconciler
 * @covers \Pixalink\Loyalty\Orders\OrderLifecycle::resend
 */
final class ReconcilerTest extends \WP_UnitTestCase {

	use OrderFixtures;

	public function set_up(): void {
		parent::set_up();

		$this->connect_store();
	}

	public function tear_down(): void {
		$this->disconnect_store();

		parent::tear_down();
	}

	private function reconciler(): Reconciler {
		return Plugin::instance()->events()->reconciler();
	}

	/**
	 * An order paid while the store was disconnected.
	 *
	 * @param int $paid_days_ago When it was paid.
	 * @return \WC_Order
	 */
	private function order_paid_while_disconnected( int $paid_days_ago = 1 ): \WC_Order {
		$this->disconnect_store();
		$order = $this->create_order();
		$order->payment_complete();
		$order->set_date_paid( time() - $paid_days_ago * DAY_IN_SECONDS );
		$order->save();
		Plugin::instance()->credentials()->store( \Pixalink\Loyalty\Tests\FakeServer\Signature::FAKE_API_KEY );

		$this->assertSame( array(), $this->event_types_of( $order ) );

		return $order;
	}

	public function test_reconcile_records_a_paid_order_that_has_no_event(): void {
		$missed  = $this->order_paid_while_disconnected();
		$too_old = $this->order_paid_while_disconnected( 10 );

		$this->assertSame( 1, $this->reconciler()->reconcile() );

		$this->assertSame( array( 'order.paid' ), $this->event_types_of( $missed ) );
		$this->assertSame( OrderMeta::EARN_QUEUED, OrderMeta::earn_state( wc_get_order( $missed->get_id() ) ) );
		$this->assertSame( array(), $this->event_types_of( $too_old ), 'Only the last 7 days are reconciled.' );

		$this->assertSame( 0, $this->reconciler()->reconcile(), 'A second run finds nothing new.' );
	}

	public function test_reconcile_records_what_happened_after_payment_in_order(): void {
		$order = $this->order_paid_while_disconnected();
		$this->disconnect_store();
		$order->update_status( 'completed' );
		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '3.00',
			)
		);
		Plugin::instance()->credentials()->store( \Pixalink\Loyalty\Tests\FakeServer\Signature::FAKE_API_KEY );

		$this->reconciler()->reconcile();

		$this->assertSame( array( 'order.paid', 'order.completed', 'order.refunded' ), $this->event_types_of( $order ) );
	}

	public function test_reconcile_leaves_dead_orders_to_the_merchant(): void {
		$order = $this->order_paid_while_disconnected();
		$fresh = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		OrderMeta::update( $fresh, array( OrderMeta::EARN_STATE => OrderMeta::EARN_DEAD ) );

		$this->assertSame( 0, $this->reconciler()->reconcile() );
		$this->assertSame( array(), $this->event_types_of( $order ) );
	}

	public function test_resend_runs_in_batches_and_chains_the_next_page(): void {
		$order = $this->order_paid_while_disconnected();

		$this->assertSame( array( $order->get_id() ), $this->reconciler()->paid_order_ids( 7, 1 ) );
		$this->assertSame( 1, $this->reconciler()->resend_batch( 7, 1 ) );
		$this->assertSame( array( 'order.paid' ), $this->event_types_of( $order ) );
		$this->assertFalse( as_has_scheduled_action( Scheduler::RESEND_HOOK, array( 'days' => 7, 'page' => 2 ), 'pixalink-loyalty' ), 'A short page ends the resend.' );

		$this->reconciler()->schedule_resend( 30 );
		$this->assertTrue( as_has_scheduled_action( Scheduler::RESEND_HOOK, array( 'days' => 30, 'page' => 1 ), 'pixalink-loyalty' ) );
	}

	public function test_recurring_actions_are_scheduled(): void {
		as_unschedule_all_actions( Scheduler::RECONCILE_HOOK, array(), 'pixalink-loyalty' );
		as_unschedule_all_actions( Scheduler::PRUNE_HOOK, array(), 'pixalink-loyalty' );
		as_unschedule_all_actions( Scheduler::SWEEP_HOOK, array(), 'pixalink-loyalty' );

		Plugin::instance()->events()->scheduler()->ensure_recurring();

		foreach ( array_keys( Scheduler::RECURRING ) as $hook ) {
			$this->assertTrue( as_has_scheduled_action( $hook, array(), 'pixalink-loyalty' ), $hook );
		}
	}
}
