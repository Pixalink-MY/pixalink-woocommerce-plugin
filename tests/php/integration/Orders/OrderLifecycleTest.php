<?php
/**
 * Integration tests for OrderLifecycle.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Orders;

use Pixalink\Loyalty\Events\EventDeliverer;
use Pixalink\Loyalty\Events\EventQueue;
use Pixalink\Loyalty\Events\EventType;
use Pixalink\Loyalty\Events\OutletResolver;
use Pixalink\Loyalty\Events\Pruner;
use Pixalink\Loyalty\Orders\OrderMeta;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Tests\FakeServer\Responses;
use Pixalink\Loyalty\Tests\FakeServer\Server;
use Pixalink\Loyalty\Tests\FakeServer\Signature;
use Pixalink\Loyalty\Tests\Integration\Support\OrderFixtures;

/**
 * Real WooCommerce order hooks → outbox rows → signed delivery to the fake server → order meta and notes.
 *
 * Runs in both order storage modes (CI matrix PIXALINK_LOYALTY_HPOS=1|0).
 *
 * @covers \Pixalink\Loyalty\Orders\OrderLifecycle
 * @covers \Pixalink\Loyalty\Orders\OrderMeta
 * @covers \Pixalink\Loyalty\Events\EventQueue
 * @covers \Pixalink\Loyalty\Events\EventDeliverer
 */
final class OrderLifecycleTest extends \WP_UnitTestCase {

	use OrderFixtures;

	public function set_up(): void {
		parent::set_up();

		$this->connect_store();
	}

	public function tear_down(): void {
		$this->disconnect_store();

		parent::tear_down();
	}

	public function test_payment_is_delivered_and_writes_points_to_the_order(): void {
		$order = $this->create_order();
		$order->payment_complete( 'txn-1001' );

		$events = $this->events_of( $order );
		$this->assertSame( array( 'order.paid' ), $this->event_types_of( $order ) );
		$this->assertSame( EventQueue::event_uuid( EventType::ORDER_PAID, home_url(), $order->get_order_key() ), $events[0]->uuid );
		$this->assertSame( 1, $events[0]->sequence );
		$this->assertSame( OrderMeta::EARN_QUEUED, OrderMeta::earn_state( wc_get_order( $order->get_id() ) ) );

		$this->assertSame( array( EventDeliverer::OUTCOME_DELIVERED ), $this->deliver_all( $order ) );

		$recorded = Server::recorded();
		$this->assertSame( 'events', end( $recorded )['path'] );
		$this->assertSame( 200, end( $recorded )['status'], 'The fake server validates the envelope against event.request.json.' );
		$this->assertSame( $events[0]->uuid, end( $recorded )['headers']['x-pixalink-event-id'] );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertSame( OrderMeta::EARN_DELIVERED, OrderMeta::earn_state( $fresh ) );
		$this->assertSame( '145', OrderMeta::get_string( $fresh, OrderMeta::POINTS_EARNED ) );
		$this->assertSame( 'earned', OrderMeta::get_string( $fresh, OrderMeta::EARN_OUTCOME ) );
		$this->assertSame( '90001', OrderMeta::get_string( $fresh, OrderMeta::POS_TRANSACTION_ID ) );
		$this->assertContains( 'Pixalink: 145 points earned.', $this->notes_of( $fresh ) );
	}

	public function test_duplicate_ipn_and_return_url_record_one_event(): void {
		$order = $this->create_order();

		$order->payment_complete( 'txn-1' );
		// Gateway IPN retried, then the shopper's return URL handler runs too.
		do_action( 'woocommerce_payment_complete', $order->get_id(), 'txn-1' );
		$again = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $again );
		$again->payment_complete( 'txn-1' );
		do_action( 'woocommerce_payment_complete', $order->get_id(), 'txn-1' );

		$this->assertSame( array( 'order.paid' ), $this->event_types_of( $order ) );
	}

	public function test_status_transitions_map_to_events(): void {
		$order = $this->create_order();
		$order->update_status( 'on-hold' );
		$this->assertSame( array(), $this->event_types_of( $order ), 'On hold is not paid.' );

		// Bank transfer confirmed by the merchant.
		$order->update_status( 'processing' );
		$order->update_status( 'completed' );
		$this->assertSame( array( 'order.paid', 'order.completed' ), $this->event_types_of( $order ) );

		$completed = $this->events_of( $order )[1];
		$this->assertSame( 2, $completed->sequence );
		$this->assertSame( 'completed', $completed->payload['order']['status'] );
		$this->assertCount( 1, $completed->payload['order']['line_items'], 'order.completed carries the full order.' );
	}

	public function test_cancelling_an_unpaid_order_releases_its_holds(): void {
		$order = $this->create_order();
		$order->update_status( 'cancelled' );

		$events = $this->events_of( $order );
		$this->assertSame( array( 'order.cancelled' ), $this->event_types_of( $order ) );
		$this->assertFalse( $events[0]->payload['was_paid'] );
		$this->assertSame( 'pending', $events[0]->payload['previous_status'] );

		$this->assertSame( array( EventDeliverer::OUTCOME_DELIVERED ), $this->deliver_all( $order ) );
	}

	public function test_cancelling_a_paid_order_says_so(): void {
		$order = $this->create_order();
		$order->payment_complete();
		$order->update_status( 'cancelled' );

		$events = $this->events_of( $order );
		$this->assertSame( array( 'order.paid', 'order.cancelled' ), $this->event_types_of( $order ) );
		$this->assertTrue( $events[1]->payload['was_paid'] );
		$this->assertSame( 'processing', $events[1]->payload['previous_status'] );
	}

	public function test_each_partial_refund_is_one_event_with_its_line_items(): void {
		$order = $this->create_order();
		$order->payment_complete();
		$item_id = array_key_first( $order->get_items() );

		$first  = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => '12.00',
				'reason'     => 'One latte spilled',
				'line_items' => array(
					$item_id => array(
						'qty'          => 1,
						'refund_total' => 12,
					),
				),
			)
		);
		$second = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '2.00',
				'reason'   => 'Goodwill',
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $first );
		$this->assertInstanceOf( \WC_Order_Refund::class, $second );

		$events = $this->events_of( $order );
		$this->assertSame( array( 'order.paid', 'order.refunded', 'order.refunded' ), $this->event_types_of( $order ) );
		$this->assertSame( EventQueue::event_uuid( EventType::ORDER_REFUNDED, home_url(), $order->get_order_key(), (string) $first->get_id() ), $events[1]->uuid );
		$this->assertSame( EventQueue::event_uuid( EventType::ORDER_REFUNDED, home_url(), $order->get_order_key(), (string) $second->get_id() ), $events[2]->uuid );

		$refund = $events[1]->payload['refund'];
		$this->assertSame( $first->get_id(), $refund['id'] );
		$this->assertSame( '12.00', $refund['amount'] );
		$this->assertSame( 'One latte spilled', $refund['reason'] );
		$this->assertSame(
			array(
				array(
					'line_item_id' => $item_id,
					'quantity'     => 1,
					'total'        => '12.00',
				),
			),
			$refund['line_items']
		);
		$this->assertSame( array(), $events[2]->payload['refund']['line_items'], 'Amount-only refunds carry no lines.' );

		// Re-firing the hook for the same refund does not add an event.
		do_action( 'woocommerce_order_refunded', $order->get_id(), $first->get_id() );
		$this->assertCount( 3, $this->events_of( $order ) );

		$this->assertSame( array_fill( 0, 3, EventDeliverer::OUTCOME_DELIVERED ), $this->deliver_all( $order ) );
	}

	public function test_deleting_a_sent_refund_records_refund_deleted(): void {
		$order = $this->create_order();
		$order->payment_complete();
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '5.00',
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$refund_id = $refund->get_id();

		// What WC_AJAX::delete_refund() does.
		$refund->delete( true );
		do_action( 'woocommerce_refund_deleted', $refund_id, $order->get_id() );

		$events = $this->events_of( $order );
		$this->assertSame( array( 'order.paid', 'order.refunded', 'order.refund_deleted' ), $this->event_types_of( $order ) );
		$this->assertSame( $refund_id, $events[2]->payload['refund_id'] );

		$this->deliver_all( $order );
		$this->assertStringContainsString( 'did not undo deleted refund', implode( "\n", $this->notes_of( $order ) ), 'The fake server answers "ignored".' );
	}

	public function test_deleting_a_reported_refund_after_its_event_was_pruned_still_records_refund_deleted(): void {
		$order = $this->create_order();
		$order->payment_complete();
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '5.00',
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$refund_id = $refund->get_id();
		$this->deliver_all( $order );
		( new Pruner( Plugin::instance()->events()->repository(), static fn (): int => time() + 31 * DAY_IN_SECONDS ) )->prune();
		$this->assertSame( array(), $this->events_of( $order ) );

		$refund->delete( true );
		do_action( 'woocommerce_refund_deleted', $refund_id, $order->get_id() );

		$this->assertSame( array( 'order.refund_deleted' ), $this->event_types_of( $order ) );
		$this->assertSame( $refund_id, $this->events_of( $order )[0]->payload['refund_id'] );
	}

	public function test_refund_deleted_is_skipped_for_refunds_pixalink_never_saw(): void {
		$order = $this->create_order();
		$order->payment_complete();

		do_action( 'woocommerce_refund_deleted', 987654, $order->get_id() );

		$this->assertSame( array( 'order.paid' ), $this->event_types_of( $order ) );
	}

	public function test_the_order_sequence_continues_after_its_delivered_events_are_pruned(): void {
		$order = $this->create_order();
		$order->payment_complete();
		$order->update_status( 'completed' );
		$this->assertSame( array( 1, 2 ), array_map( static fn ( $event ): int => $event->sequence, $this->events_of( $order ) ) );
		$this->deliver_all( $order );
		( new Pruner( Plugin::instance()->events()->repository(), static fn (): int => time() + 31 * DAY_IN_SECONDS ) )->prune();
		$this->assertSame( array(), $this->events_of( $order ), 'Delivered rows are gone after 30 days.' );

		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '5.00',
			)
		);

		$events = $this->events_of( $order );
		$this->assertSame( array( 'order.refunded' ), $this->event_types_of( $order ) );
		$this->assertSame( 3, $events[0]->sequence, 'Never 1 again: Pixalink already saw 1 and 2 for this order.' );
	}

	public function test_trashing_an_unpaid_order_releases_redemptions_once(): void {
		$order    = $this->create_order();
		$order_id = $order->get_id();

		// WC_Data::delete() resets the instance id, even when only trashing.
		$order->delete( false );
		$trashed = wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $trashed );

		$events = $this->events_of( $trashed );
		$this->assertSame( array( 'order.redemptions_released' ), $this->event_types_of( $trashed ) );
		$this->assertSame( 'trashed', $events[0]->payload['reason'] );
		$this->assertSame( $trashed->get_order_key(), $events[0]->payload['order']['order_key'] );
		$this->assertSame( array( EventDeliverer::OUTCOME_DELIVERED ), $this->deliver_all( $trashed ) );
	}

	public function test_deleting_an_unpaid_order_releases_redemptions(): void {
		$order    = $this->create_order();
		$order_id = $order->get_id();

		$order->delete( true );

		$released = Plugin::instance()->events()->repository()->for_object( 'order', $order_id );
		$this->assertCount( 1, $released );
		$this->assertSame( 'order.redemptions_released', $released[0]->type->value );
		$this->assertSame( 'deleted', $released[0]->payload['reason'] );
	}

	public function test_trashing_a_paid_order_keeps_its_loyalty_history(): void {
		$order    = $this->create_order();
		$order_id = $order->get_id();
		$order->payment_complete();

		$order->delete( false );

		$events = Plugin::instance()->events()->repository()->for_object( 'order', $order_id );
		$this->assertSame( array( 'order.paid' ), array_map( static fn ( $event ): string => $event->type->value, $events ) );
	}

	public function test_nothing_is_recorded_while_disconnected_or_when_filtered_out(): void {
		$this->disconnect_store();
		$disconnected = $this->create_order();
		$disconnected->payment_complete();
		$this->assertSame( array(), $this->event_types_of( $disconnected ) );

		$this->connect_store();
		$skip = static fn ( bool $send, \WC_Order $order ): bool => 'skip@example.com' !== $order->get_billing_email();
		add_filter( 'pixalink_loyalty_should_send_order', $skip, 10, 2 );
		$filtered = $this->create_order();
		$filtered->set_billing_email( 'skip@example.com' );
		$filtered->save();
		$filtered->payment_complete();
		remove_filter( 'pixalink_loyalty_should_send_order', $skip, 10 );

		$this->assertSame( array(), $this->event_types_of( $filtered ) );
	}

	public function test_a_refund_waits_for_the_orders_paid_event(): void {
		$order = $this->create_order();
		$order->payment_complete();
		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '1.00',
			)
		);
		$events = $this->events_of( $order );

		$deliverer = Plugin::instance()->events()->deliverer();
		$this->assertSame( EventDeliverer::OUTCOME_DEFERRED, $deliverer->deliver( $events[1]->id ) );
		$this->assertSame( 0, Plugin::instance()->events()->repository()->find( $events[1]->id )?->attempts );
		$this->assertNull( $deliverer->deliver( $events[1]->id ), 'Parked for a minute.' );

		// Delivering order.paid wakes the refund, which is then due immediately.
		$this->assertSame( EventDeliverer::OUTCOME_DELIVERED, $deliverer->deliver( $events[0]->id ) );
		$this->assertSame( EventDeliverer::OUTCOME_DELIVERED, $deliverer->deliver( $events[1]->id ) );
	}

	public function test_rejected_key_pauses_delivery_and_reconnecting_resumes_it(): void {
		$order = $this->create_order();
		$order->payment_complete();
		$event = $this->events_of( $order )[0];

		Plugin::instance()->credentials()->store( '2|someoneelsesoutletkey000000000000' );
		delete_transient( 'pixalink_loyalty_outlet_id' );
		$deliverer = Plugin::instance()->events()->deliverer();

		$this->assertSame( EventDeliverer::OUTCOME_PAUSE, $deliverer->deliver( $event->id ) );
		$this->assertSame( EventDeliverer::PAUSE_AUTH_FAILED, $deliverer->pause_reason() );
		$this->assertSame( 0, Plugin::instance()->events()->repository()->find( $event->id )?->attempts );

		// Reconnecting with a valid key lifts the pause.
		Plugin::instance()->credentials()->store( \Pixalink\Loyalty\Tests\FakeServer\Signature::FAKE_API_KEY );
		$this->assertFalse( $deliverer->is_paused() );
		$this->assertSame( EventDeliverer::OUTCOME_DELIVERED, $deliverer->deliver( $event->id ) );
	}

	public function test_an_event_queued_before_reconnecting_to_another_outlet_is_never_sent_there(): void {
		set_transient( OutletResolver::TRANSIENT, Responses::OUTLET_ID, DAY_IN_SECONDS );
		$order = $this->create_order();
		$order->payment_complete();
		$event = $this->events_of( $order )[0];
		$this->assertSame( Responses::OUTLET_ID, $event->outlet_id );

		// The merchant reconnects the store, now to outlet 202, before the event went out.
		Plugin::instance()->credentials()->store( Signature::FAKE_API_KEY );
		$outlet_b = static fn (): string => '202';
		add_filter( 'pixalink_loyalty_outlet_id', $outlet_b );
		$outcome = Plugin::instance()->events()->deliverer()->deliver( $event->id );
		remove_filter( 'pixalink_loyalty_outlet_id', $outlet_b );

		$this->assertSame( EventDeliverer::OUTCOME_DEAD, $outcome );
		$this->assertSame( array(), array_filter( Server::recorded(), static fn ( array $request ): bool => 'events' === $request['path'] ), 'Outlet 101\'s order never reaches outlet 202.' );
		$row = Plugin::instance()->events()->repository()->find( $event->id );
		$this->assertSame( 'dead', $row?->status->value );
		$this->assertStringStartsWith( EventDeliverer::CONNECTION_CHANGED, (string) $row?->last_error );
		$this->assertSame( 1, Plugin::instance()->events()->health()['stats']['dead'], 'Counted under "Failed" in the health panel.' );
	}

	public function test_resend_requeues_a_dead_paid_event(): void {
		$order = $this->create_order();
		$order->payment_complete();
		$event      = $this->events_of( $order )[0];
		$repository = Plugin::instance()->events()->repository();
		$repository->mark_dead( $event->id, 12, 503, 'http_503', array() );
		do_action( 'pixalink_loyalty_event_dead', $repository->find( $event->id ), array( 'code' => 'http_503' ) );
		$this->assertSame( OrderMeta::EARN_DEAD, OrderMeta::earn_state( wc_get_order( $order->get_id() ) ) );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertSame( 1, Plugin::instance()->events()->lifecycle()->resend( $fresh ) );
		$this->assertSame( 'retry', $repository->find( $event->id )?->status->value );
		$this->assertSame( OrderMeta::EARN_QUEUED, OrderMeta::earn_state( wc_get_order( $order->get_id() ) ) );

		$this->assertSame( 0, Plugin::instance()->events()->lifecycle()->resend( $fresh ), 'Open events are not duplicated.' );
	}
}
