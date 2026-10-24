<?php
/**
 * Integration tests for EventRepository.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Events;

use Pixalink\Loyalty\Events\Event;
use Pixalink\Loyalty\Events\EventRepository;
use Pixalink\Loyalty\Events\EventType;
use Pixalink\Loyalty\Install\Schema;
use Pixalink\Loyalty\Support\Uuid;

/**
 * Real MySQL semantics of the outbox: INSERT IGNORE on uuid, per-order sequence, conditional claim and stale locks.
 *
 * @covers \Pixalink\Loyalty\Events\EventRepository
 * @covers \Pixalink\Loyalty\Events\Event
 */
final class EventRepositoryTest extends \WP_UnitTestCase {

	private EventRepository $repository;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$this->repository = new EventRepository();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->repository->table() ) );
	}

	/**
	 * Insert an order event.
	 *
	 * @param string    $name     Unique name.
	 * @param int       $order_id Order id.
	 * @param EventType $type     Type.
	 * @return array{id:int,created:bool,sequence:int}
	 */
	private function insert( string $name, int $order_id = 1001, EventType $type = EventType::ORDER_PAID ): array {
		return $this->repository->insert( Uuid::event_id( $name ), $type, Event::OBJECT_ORDER, $order_id, array( 'n' => $name ), '2026-10-24 08:00:00' );
	}

	public function test_the_table_exists_with_its_unique_uuid_key(): void {
		global $wpdb;

		$this->assertSame( $this->repository->table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->repository->table() ) ) );
		$this->assertSame( $wpdb->prefix . Schema::EVENTS_TABLE, $this->repository->table() );

		$unique = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $this->repository->table(), 'uuid' ), ARRAY_A );
		$this->assertNotEmpty( $unique );
		$this->assertSame( '0', (string) $unique[0]['Non_unique'] );
	}

	public function test_insert_is_ignored_for_a_known_uuid(): void {
		$first  = $this->insert( 'a' );
		$second = $this->repository->insert( Uuid::event_id( 'a' ), EventType::ORDER_PAID, Event::OBJECT_ORDER, 1001, array( 'n' => 'changed' ), '2026-10-24 09:00:00' );

		$this->assertTrue( $first['created'] );
		$this->assertFalse( $second['created'] );
		$this->assertSame( $first['id'], $second['id'] );
		$this->assertSame( array( 'n' => 'a' ), $this->repository->find( $first['id'] )?->payload );
	}

	public function test_sequences_are_per_order_and_zero_without_an_order(): void {
		$this->assertSame( 1, $this->insert( 'a', 1001 )['sequence'] );
		$this->assertSame( 2, $this->insert( 'b', 1001, EventType::ORDER_REFUNDED )['sequence'] );
		$this->assertSame( 1, $this->insert( 'c', 1002 )['sequence'] );
		$this->assertSame( 3, $this->insert( 'd', 1001, EventType::ORDER_COMPLETED )['sequence'] );

		$ping = $this->repository->insert( Uuid::v4(), EventType::PING, Event::OBJECT_NONE, 0, array(), '2026-10-24 08:00:00' );
		$this->assertSame( 0, $ping['sequence'] );
	}

	public function test_claim_is_exclusive_and_stale_locks_are_reclaimable(): void {
		$id = $this->insert( 'a' )['id'];

		$this->assertTrue( $this->repository->claim( $id, '2026-10-24 08:00:00', '2026-10-24 07:55:00' ) );
		$this->assertSame( 'sending', $this->repository->find( $id )?->status->value );
		$this->assertFalse( $this->repository->claim( $id, '2026-10-24 08:01:00', '2026-10-24 07:56:00' ), 'A fresh lock is respected.' );
		$this->assertTrue( $this->repository->claim( $id, '2026-10-24 08:06:00', '2026-10-24 08:01:00' ), 'A lock older than the timeout is reclaimed.' );

		$this->repository->mark_delivered( $id, 1, 200, array( 'status' => 'processed' ), '2026-10-24 08:06:01' );
		$this->assertFalse( $this->repository->claim( $id, '2026-10-24 09:00:00', '2026-10-24 08:55:00' ), 'Delivered rows are never claimed.' );
	}

	public function test_ordering_queries(): void {
		$paid      = $this->insert( 'a' );
		$refund    = $this->insert( 'b', 1001, EventType::ORDER_REFUNDED );
		$completed = $this->insert( 'c', 1001, EventType::ORDER_COMPLETED );

		$refund_event = $this->repository->find( $refund['id'] );
		$this->assertNotNull( $refund_event );
		$this->assertSame( $paid['id'], $this->repository->first_open_before( $refund_event )?->id );
		$this->assertSame( array( $completed['id'] ), $this->repository->open_ids_after( $refund_event ) );

		$this->repository->mark_dead( $paid['id'], 12, 500, 'boom', array() );
		$this->assertNull( $this->repository->first_open_before( $refund_event ) );
	}

	public function test_defer_requeue_cancel_and_due_ids(): void {
		$a = $this->insert( 'a', 1 )['id'];
		$b = $this->insert( 'b', 2 )['id'];
		$c = $this->insert( 'c', 3 )['id'];

		$this->repository->defer( $b, '2026-10-24 09:00:00', 'waiting' );
		$this->repository->mark_dead( $c, 12, 422, 'validation_failed', array( 'code' => 'validation_failed' ) );

		$this->assertSame( array( $a ), $this->repository->due_ids( '2026-10-24 08:30:00', '2026-10-24 08:25:00', 10 ) );
		$this->assertSame( array( $a, $b ), $this->repository->due_ids( '2026-10-24 09:00:00', '2026-10-24 08:55:00', 10 ) );
		$this->assertSame( 'waiting', $this->repository->find( $b )?->last_error );

		$this->assertSame( array( $c ), $this->repository->dead_ids( 10 ) );
		$this->assertTrue( $this->repository->requeue( $c, '2026-10-24 08:30:00' ) );
		$requeued = $this->repository->find( $c );
		$this->assertSame( 'retry', $requeued?->status->value );
		$this->assertSame( 0, $requeued?->attempts );
		$this->assertFalse( $this->repository->requeue( $c, '2026-10-24 08:30:00' ), 'Only dead or cancelled rows are requeued.' );

		$this->assertTrue( $this->repository->cancel( $a ) );
		$this->assertSame( 'cancelled', $this->repository->find( $a )?->status->value );
	}

	public function test_stats_and_heartbeat_queue_depth(): void {
		$a = $this->insert( 'a', 1 )['id'];
		$this->repository->insert( Uuid::event_id( 'b' ), EventType::ORDER_PAID, Event::OBJECT_ORDER, 2, array(), '2026-10-24 07:00:00' );
		$c = $this->insert( 'c', 3 )['id'];
		$d = $this->insert( 'd', 4 )['id'];
		$this->repository->mark_retry( $a, 1, '2026-10-24 08:01:00', 503, 'http_503', array() );
		$this->repository->mark_dead( $c, 12, 500, 'boom', array() );
		$this->repository->mark_delivered( $d, 1, 200, array( 'status' => 'processed' ), '2026-10-24 08:00:05' );

		$stats = $this->repository->stats();
		$this->assertSame( 1, $stats['pending'] );
		$this->assertSame( 1, $stats['retry'] );
		$this->assertSame( 1, $stats['dead'] );
		$this->assertSame( 1, $stats['delivered'] );
		$this->assertSame( '2026-10-24 07:00:00', $stats['oldest_open_at'] );

		$this->assertSame(
			array(
				'pending'           => 2,
				'failed'            => 1,
				'oldest_pending_at' => '2026-10-24T07:00:00Z',
			),
			$this->repository->queue_stats()
		);
		$this->assertSame( $d, $this->repository->last_delivered()?->id );
	}

	public function test_search_filters_by_status_and_order(): void {
		$this->insert( 'a', 1001 );
		$b = $this->insert( 'b', 1002 )['id'];
		$this->repository->mark_dead( $b, 12, 500, 'boom', array() );

		$this->assertSame( 2, $this->repository->search( array() )['total'] );
		$this->assertSame( array( $b ), array_map( static fn ( Event $event ): int => $event->id, $this->repository->search( array( 'status' => 'dead' ) )['items'] ) );
		$this->assertSame( 1, $this->repository->search( array( 'search' => '1001' ) )['total'] );
		$this->assertSame( 1, $this->repository->search( array( 'search' => Uuid::event_id( 'b' ) ) )['total'] );
	}
}
