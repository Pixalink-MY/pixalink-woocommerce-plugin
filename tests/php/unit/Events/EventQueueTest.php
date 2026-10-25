<?php
/**
 * Tests for EventQueue.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Events;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Events\Event;
use Pixalink\Loyalty\Events\EventQueue;
use Pixalink\Loyalty\Events\EventType;
use Pixalink\Loyalty\Events\Scheduler;
use Pixalink\Loyalty\Install\Installer;
use Pixalink\Loyalty\Support\Uuid;
use Pixalink\Loyalty\Tests\Unit\Doubles\InMemoryEventStore;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * Event ids, per-order sequences and delivery scheduling.
 *
 * @covers \Pixalink\Loyalty\Events\EventQueue
 */
final class EventQueueTest extends TestCase {

	private const NOW = 1761300000;

	private InMemoryEventStore $store;

	/**
	 * Captured as_enqueue_async_action() calls.
	 *
	 * @var list<array<int,mixed>>
	 */
	private array $enqueued = array();

	protected function set_up(): void {
		parent::set_up();

		$this->store    = new InMemoryEventStore();
		$this->enqueued = array();
		Functions\when( 'as_enqueue_async_action' )->alias(
			function ( ...$args ): int {
				$this->enqueued[] = $args;

				return count( $this->enqueued );
			}
		);
	}

	private function queue(): EventQueue {
		return new EventQueue( $this->store, new Scheduler(), static fn (): int => self::NOW );
	}

	/**
	 * A WC_Order double.
	 *
	 * @param int    $id  Order id.
	 * @param string $key Order key.
	 * @return \WC_Order
	 */
	private function order( int $id = 1001, string $key = 'wc_order_AbC123xyz' ): \WC_Order {
		$order = \Mockery::mock( \WC_Order::class );
		$order->allows( 'get_id' )->andReturn( $id );
		$order->allows( 'get_order_key' )->andReturn( $key );

		return $order;
	}

	public function test_order_event_ids_are_uuid_v5_over_site_type_key_and_discriminator(): void {
		$expected = Uuid::event_id( 'https://shop.example.test|order.refunded|wc_order_AbC123xyz|77' );

		$this->assertSame( 'https://shop.example.test|order.refunded|wc_order_AbC123xyz|77', EventQueue::event_name( EventType::ORDER_REFUNDED, 'https://shop.example.test/', 'wc_order_AbC123xyz', '77' ) );
		$this->assertSame( $expected, EventQueue::event_uuid( EventType::ORDER_REFUNDED, 'https://shop.example.test', 'wc_order_AbC123xyz', '77' ) );
		$this->assertSame( $expected, EventQueue::event_uuid( EventType::ORDER_REFUNDED, 'https://shop.example.test/', 'wc_order_AbC123xyz', '77' ), 'A trailing slash does not change the id.' );
		$this->assertSame( '5', $expected[14] );
	}

	public function test_event_ids_are_deterministic_and_sensitive_to_every_name_part(): void {
		$base = EventQueue::event_uuid( EventType::ORDER_REFUNDED, 'https://shop.example.test', 'wc_order_A', '1' );

		$this->assertSame( $base, EventQueue::event_uuid( EventType::ORDER_REFUNDED, 'https://shop.example.test', 'wc_order_A', '1' ) );
		$this->assertNotSame( $base, EventQueue::event_uuid( EventType::ORDER_REFUNDED, 'https://shop.example.test', 'wc_order_A', '2' ), 'discriminator' );
		$this->assertNotSame( $base, EventQueue::event_uuid( EventType::ORDER_REFUNDED, 'https://shop.example.test', 'wc_order_B', '1' ), 'order key' );
		$this->assertNotSame( $base, EventQueue::event_uuid( EventType::ORDER_REFUND_DELETED, 'https://shop.example.test', 'wc_order_A', '1' ), 'type' );
		$this->assertNotSame( $base, EventQueue::event_uuid( EventType::ORDER_REFUNDED, 'https://other.example.test', 'wc_order_A', '1' ), 'site' );
		$this->assertNotSame(
			EventQueue::event_uuid( EventType::ORDER_PAID, 'https://shop.example.test', 'wc_order_A' ),
			EventQueue::event_uuid( EventType::ORDER_PAID, 'https://shop.example.test', 'wc_order_A', '1' ),
			'An empty discriminator is distinct from a non-empty one.'
		);
	}

	public function test_enqueue_stores_the_event_and_schedules_one_unique_delivery(): void {
		$result = $this->queue()->enqueue( EventType::ORDER_PAID, $this->order(), array( 'order' => array( 'id' => 1001 ) ) );

		$this->assertNotNull( $result );
		$this->assertTrue( $result['created'] );
		$this->assertSame( 1, $result['sequence'] );
		$this->assertSame( EventQueue::event_uuid( EventType::ORDER_PAID, 'https://shop.example.test', 'wc_order_AbC123xyz' ), $result['uuid'] );

		$event = $this->store->find( $result['id'] );
		$this->assertNotNull( $event );
		$this->assertSame( Event::OBJECT_ORDER, $event->object_type );
		$this->assertSame( 1001, $event->object_id );
		$this->assertSame( '2025-10-24 10:00:00', $event->created_at );
		$this->assertSame( array( 'order' => array( 'id' => 1001 ) ), $event->payload );

		$this->assertSame(
			array( array( Scheduler::DELIVER_HOOK, array( 'event_id' => $result['id'] ), Installer::ACTION_GROUP, true ) ),
			$this->enqueued
		);
	}

	public function test_re_enqueueing_the_same_fact_is_ignored_and_not_rescheduled(): void {
		$queue = $this->queue();

		$first  = $queue->enqueue( EventType::ORDER_PAID, $this->order(), array( 'n' => 1 ) );
		$second = $queue->enqueue( EventType::ORDER_PAID, $this->order(), array( 'n' => 2 ) );

		$this->assertNotNull( $first );
		$this->assertNotNull( $second );
		$this->assertFalse( $second['created'] );
		$this->assertSame( $first['id'], $second['id'] );
		$this->assertCount( 1, $this->store->rows );
		$this->assertCount( 1, $this->enqueued );
		$this->assertSame( array( 'n' => 1 ), $this->store->find( $first['id'] )?->payload, 'The first snapshot wins.' );
	}

	public function test_sequences_increase_per_order(): void {
		$queue = $this->queue();

		$paid      = $queue->enqueue( EventType::ORDER_PAID, $this->order( 1 ), array() );
		$other     = $queue->enqueue( EventType::ORDER_PAID, $this->order( 2, 'wc_order_other' ), array() );
		$refund_1  = $queue->enqueue( EventType::ORDER_REFUNDED, $this->order( 1 ), array(), '10' );
		$refund_2  = $queue->enqueue( EventType::ORDER_REFUNDED, $this->order( 1 ), array(), '11' );
		$completed = $queue->enqueue( EventType::ORDER_COMPLETED, $this->order( 1 ), array() );

		$this->assertSame( array( 1, 1, 2, 3, 4 ), array_column( array( $paid, $other, $refund_1, $refund_2, $completed ), 'sequence' ) );
	}

	public function test_ping_and_customer_events_get_random_v4_ids_and_sequence_zero(): void {
		$queue = $this->queue();

		$ping_1 = $queue->enqueue( EventType::PING, null, array() );
		$ping_2 = $queue->enqueue( EventType::PING, null, array() );

		$user     = \Mockery::mock( \WP_User::class );
		$user->ID = 17;
		$customer = $queue->enqueue( EventType::CUSTOMER_UPSERTED, $user, array( 'customer' => array() ) );

		$this->assertNotNull( $ping_1 );
		$this->assertNotNull( $ping_2 );
		$this->assertNotNull( $customer );
		$this->assertSame( '4', $ping_1['uuid'][14] );
		$this->assertNotSame( $ping_1['uuid'], $ping_2['uuid'] );
		$this->assertSame( 0, $ping_1['sequence'] );
		$this->assertSame( 0, $customer['sequence'] );
		$this->assertSame( Event::OBJECT_CUSTOMER, $this->store->find( $customer['id'] )?->object_type );
		$this->assertSame( 17, $this->store->find( $customer['id'] )?->object_id );
	}

	public function test_order_events_need_an_order_with_a_key(): void {
		$queue = $this->queue();

		$this->assertNull( $queue->enqueue( EventType::ORDER_PAID, null, array() ) );
		$this->assertNull( $queue->enqueue( EventType::ORDER_PAID, $this->order( 5, '' ), array() ) );
		$this->assertNull( $queue->enqueue( EventType::ORDER_PAID, new \stdClass(), array() ) );
		$this->assertSame( array(), $this->store->rows );
		$this->assertSame( array(), $this->enqueued );
	}

	public function test_an_event_remembers_the_connection_it_was_recorded_for(): void {
		$queue = new EventQueue(
			$this->store,
			new Scheduler(),
			static fn (): int => self::NOW,
			static fn (): array => array(
				'outlet_id' => '101',
				'api_base'  => 'https://api.example.test/commerce/v1',
			)
		);

		$stored = $queue->enqueue( EventType::ORDER_PAID, $this->order(), array() );

		$event = $this->store->find( $stored['id'] );
		$this->assertSame( '101', $event?->outlet_id );
		$this->assertSame( 'https://api.example.test/commerce/v1', $event?->api_base );
	}
}
