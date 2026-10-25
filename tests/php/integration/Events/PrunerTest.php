<?php
/**
 * Integration tests for Pruner.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Events;

use Pixalink\Loyalty\Events\Event;
use Pixalink\Loyalty\Events\EventRepository;
use Pixalink\Loyalty\Events\EventType;
use Pixalink\Loyalty\Events\Pruner;
use Pixalink\Loyalty\Support\Uuid;

/**
 * Retention: delivered rows 30 days after delivery, dead and cancelled rows 90 days after creation, open rows never.
 *
 * @covers \Pixalink\Loyalty\Events\Pruner
 * @covers \Pixalink\Loyalty\Events\EventRepository::prune
 */
final class PrunerTest extends \WP_UnitTestCase {

	private const NOW = 1793000000;

	private EventRepository $repository;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$this->repository = new EventRepository();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->repository->table() ) );
	}

	/**
	 * Insert a row created `$created_days_ago` days before NOW.
	 *
	 * @param string $name             Unique name.
	 * @param int    $created_days_ago Age.
	 * @return int Row id.
	 */
	private function row( string $name, int $created_days_ago ): int {
		return $this->repository->insert(
			Uuid::event_id( $name ),
			EventType::ORDER_PAID,
			Event::OBJECT_ORDER,
			crc32( $name ),
			array(),
			gmdate( 'Y-m-d H:i:s', self::NOW - $created_days_ago * DAY_IN_SECONDS )
		)['id'];
	}

	private static function days_ago( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', self::NOW - $days * DAY_IN_SECONDS );
	}

	public function test_prune_respects_retention_per_status(): void {
		$old_delivered    = $this->row( 'old-delivered', 40 );
		$recent_delivered = $this->row( 'recent-delivered', 40 );
		$old_dead         = $this->row( 'old-dead', 100 );
		$recent_dead      = $this->row( 'recent-dead', 60 );
		$old_cancelled    = $this->row( 'old-cancelled', 100 );
		$ancient_open     = $this->row( 'ancient-open', 365 );

		$this->repository->mark_delivered( $old_delivered, 1, 200, array(), self::days_ago( 31 ) );
		$this->repository->mark_delivered( $recent_delivered, 1, 200, array(), self::days_ago( 29 ) );
		$this->repository->mark_dead( $old_dead, 12, 500, 'boom', array() );
		$this->repository->mark_dead( $recent_dead, 12, 500, 'boom', array() );
		$this->repository->cancel( $old_cancelled );

		$deleted = ( new Pruner( $this->repository, static fn (): int => self::NOW ) )->prune();

		$this->assertSame( 3, $deleted );
		$this->assertNull( $this->repository->find( $old_delivered ) );
		$this->assertNull( $this->repository->find( $old_dead ) );
		$this->assertNull( $this->repository->find( $old_cancelled ) );
		$this->assertNotNull( $this->repository->find( $recent_delivered ) );
		$this->assertNotNull( $this->repository->find( $recent_dead ) );
		$this->assertNotNull( $this->repository->find( $ancient_open ), 'Open rows are never pruned.' );
	}
}
