<?php
/**
 * Integration tests for Heartbeat.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Connect;

use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Connect\Connection;
use Pixalink\Loyalty\Connect\Heartbeat;
use Pixalink\Loyalty\Install\Installer;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\FakeServer\Server;
use Pixalink\Loyalty\Tests\FakeServer\Signature;

/**
 * Heartbeat through Action Scheduler and the signed fake server.
 *
 * @covers \Pixalink\Loyalty\Connect\Heartbeat
 */
final class HeartbeatTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		Server::reset();
		Plugin::instance()->credentials()->clear();
		delete_option( Options::CONNECTION );
		delete_option( Options::REMOTE_STATUS );
		delete_option( Options::CLOCK_OFFSET );
		as_unschedule_all_actions( Heartbeat::HOOK, array(), Installer::ACTION_GROUP );
	}

	private function connect( string $api_key = Signature::FAKE_API_KEY ): void {
		Plugin::instance()->credentials()->store( $api_key );
		Plugin::instance()->connection()->mark_connected( array( 'name' => 'Fake Cafe' ), array( 'id' => '101' ), 1, Connection::METHOD_HOSTED, null, null );
	}

	public function test_a_scheduled_heartbeat_caches_the_remote_status(): void {
		$this->connect();
		set_transient( 'pixalink_loyalty_fake_clock_offset', 120, HOUR_IN_SECONDS );

		do_action( Heartbeat::HOOK );

		$remote = Plugin::instance()->connection()->remote_status();
		$this->assertSame( 'active', $remote['status'] );
		$this->assertSame( 'paid', $remote['earn_on'] );
		$this->assertTrue( Plugin::instance()->connection()->feature( 'vouchers' ) );
		$this->assertEqualsWithDelta( 120, (int) get_option( Options::CLOCK_OFFSET ), 2 );
		$this->assertSame( 'Fake Online Store', Plugin::instance()->connection()->data()['outlet']['name'] );
	}

	public function test_schedule_creates_one_recurring_action_every_six_hours(): void {
		$heartbeat = Plugin::instance()->heartbeat();

		$heartbeat->schedule();
		$heartbeat->schedule();

		$actions = as_get_scheduled_actions(
			array(
				'hook'   => Heartbeat::HOOK,
				'group'  => Installer::ACTION_GROUP,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			)
		);
		$this->assertCount( 1, $actions );
		$schedule = reset( $actions )->get_schedule();
		$this->assertInstanceOf( \ActionScheduler_IntervalSchedule::class, $schedule );
		$this->assertSame( Heartbeat::INTERVAL, (int) $schedule->get_recurrence() );
	}

	public function test_admin_init_repairs_a_missing_schedule_only_while_connected(): void {
		$heartbeat = Plugin::instance()->heartbeat();
		$this->assertNotFalse( has_action( 'admin_init', array( $heartbeat, 'ensure_scheduled' ) ) );

		// Calling the callback directly: firing admin_init would also run WooCommerce's own admin redirects.
		$heartbeat->ensure_scheduled();
		$this->assertFalse( as_has_scheduled_action( Heartbeat::HOOK, array(), Installer::ACTION_GROUP ) );

		$this->connect();
		$heartbeat->ensure_scheduled();
		$this->assertTrue( as_has_scheduled_action( Heartbeat::HOOK, array(), Installer::ACTION_GROUP ) );
	}

	public function test_a_rejected_key_marks_the_connection_auth_failed(): void {
		$this->connect( '5|revokedrevokedrevokedrevokedrevoked' );

		try {
			Plugin::instance()->heartbeat()->run();
			$this->fail( 'Expected ApiException.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( 401, $exception->http_status() );
		}

		$this->assertSame( Connection::STATUS_AUTH_FAILED, Plugin::instance()->connection()->status() );
		$this->assertSame( 'unauthenticated', Plugin::instance()->connection()->data()['auth_error'] );
	}

	public function test_deactivation_stops_the_heartbeat(): void {
		$this->connect();
		Plugin::instance()->heartbeat()->schedule();

		Installer::deactivate();

		$this->assertFalse( as_has_scheduled_action( Heartbeat::HOOK, array(), Installer::ACTION_GROUP ) );
	}
}
