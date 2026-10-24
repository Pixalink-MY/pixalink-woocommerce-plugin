<?php
/**
 * Integration tests for the events Module.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Events;

use Pixalink\Loyalty\Events\EventDeliverer;
use Pixalink\Loyalty\Events\Scheduler;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\FakeServer\Server;
use Pixalink\Loyalty\Tests\Integration\Support\OrderFixtures;

/**
 * Wiring and the operations behind the health panel buttons.
 *
 * @covers \Pixalink\Loyalty\Events\Module
 * @covers \Pixalink\Loyalty\Events\OutletResolver
 * @covers \Pixalink\Loyalty\Events\Scheduler
 */
final class ModuleTest extends \WP_UnitTestCase {

	use OrderFixtures;

	public function set_up(): void {
		parent::set_up();

		$this->connect_store();
	}

	public function tear_down(): void {
		$this->disconnect_store();

		parent::tear_down();
	}

	public function test_hooks_are_registered_on_boot(): void {
		$this->assertNotFalse( has_action( Scheduler::DELIVER_HOOK ) );
		$this->assertNotFalse( has_action( Scheduler::SWEEP_HOOK ) );
		$this->assertNotFalse( has_action( Scheduler::RECONCILE_HOOK ) );
		$this->assertNotFalse( has_action( Scheduler::PRUNE_HOOK ) );
		$this->assertNotFalse( has_action( Scheduler::RESEND_HOOK ) );
		$this->assertNotFalse( has_action( 'woocommerce_payment_complete' ) );
		$this->assertNotFalse( has_filter( 'pixalink_loyalty_queue_stats' ) );
	}

	public function test_queue_stats_filter_feeds_the_heartbeat(): void {
		$order = $this->create_order();
		$order->payment_complete();

		$stats = apply_filters(
			'pixalink_loyalty_queue_stats',
			array(
				'pending'           => 0,
				'failed'            => 0,
				'oldest_pending_at' => null,
			)
		);

		$this->assertSame( 1, $stats['pending'] );
		$this->assertSame( 0, $stats['failed'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $stats['oldest_pending_at'] );
		$this->assertSame( $stats, Plugin::instance()->events()->queue_stats() );
	}

	public function test_send_ping_delivers_immediately_and_lifts_a_pause(): void {
		update_option( Options::DELIVERY_PAUSED, EventDeliverer::PAUSE_AUTH_FAILED );

		$result = Plugin::instance()->events()->send_ping();

		$this->assertSame( EventDeliverer::OUTCOME_DELIVERED, $result['outcome'] );
		$this->assertSame( 'ping', $result['event']?->type->value );
		$this->assertSame( array( 'pong' => true ), $result['event']?->response['result'] ?? null );
		$this->assertFalse( Plugin::instance()->events()->deliverer()->is_paused() );

		$paths = array_column( Server::recorded(), 'path' );
		$this->assertSame( array( 'outlet', 'events' ), $paths, 'The outlet id is looked up once, then cached.' );
		$this->assertSame( '101', get_transient( 'pixalink_loyalty_outlet_id' ) );
	}

	public function test_outlet_id_filter_skips_the_lookup(): void {
		$outlet = static fn (): string => '101';
		add_filter( 'pixalink_loyalty_outlet_id', $outlet );

		Plugin::instance()->events()->send_ping();

		remove_filter( 'pixalink_loyalty_outlet_id', $outlet );
		$this->assertSame( array( 'events' ), array_column( Server::recorded(), 'path' ) );
	}

	public function test_retry_dead_and_cancel(): void {
		$order = $this->create_order();
		$order->payment_complete();
		$event      = $this->events_of( $order )[0];
		$repository = Plugin::instance()->events()->repository();
		$repository->mark_dead( $event->id, 12, 503, 'http_503', array() );

		$this->assertSame( 1, Plugin::instance()->events()->retry_dead() );
		$this->assertSame( 'retry', $repository->find( $event->id )?->status->value );
		$this->assertTrue( as_has_scheduled_action( Scheduler::DELIVER_HOOK, array( 'event_id' => $event->id ), 'pixalink-loyalty' ) );

		$this->assertTrue( Plugin::instance()->events()->cancel( $event->id ) );
		$this->assertSame( 'cancelled', $repository->find( $event->id )?->status->value );
		$this->assertTrue( Plugin::instance()->events()->retry( $event->id ), 'A cancelled update can be sent after all.' );
	}

	public function test_changing_credentials_forgets_the_outlet_and_resumes(): void {
		set_transient( 'pixalink_loyalty_outlet_id', 'stale', HOUR_IN_SECONDS );
		update_option( Options::DELIVERY_PAUSED, EventDeliverer::PAUSE_AUTH_FAILED );

		Plugin::instance()->credentials()->store( \Pixalink\Loyalty\Tests\FakeServer\Signature::FAKE_API_KEY . 'x' );

		$this->assertFalse( get_transient( 'pixalink_loyalty_outlet_id' ) );
		$this->assertFalse( Plugin::instance()->events()->deliverer()->is_paused() );
	}

	public function test_health_snapshot(): void {
		$health = Plugin::instance()->events()->health();

		$this->assertTrue( $health['connected'] );
		$this->assertSame( '', $health['paused'] );
		$this->assertSame( 0, $health['stats']['dead'] );
		$this->assertIsInt( $health['overdue_actions'] );
		$this->assertIsBool( $health['cron_stalled'] );
	}
}
