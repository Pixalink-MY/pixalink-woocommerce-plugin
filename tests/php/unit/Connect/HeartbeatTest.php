<?php
/**
 * Tests for Heartbeat.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Connect;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\Client;
use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Api\Resources\Connect;
use Pixalink\Loyalty\Connect\Connection;
use Pixalink\Loyalty\Connect\Heartbeat;
use Pixalink\Loyalty\Install\Installer;
use Pixalink\Loyalty\Redemption\CouponMapper;
use Pixalink\Loyalty\Redemption\Features;
use Pixalink\Loyalty\Support\Clock;
use Pixalink\Loyalty\Support\Crypto;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * @covers \Pixalink\Loyalty\Connect\Heartbeat
 * @covers \Pixalink\Loyalty\Connect\Connection
 * @covers \Pixalink\Loyalty\Redemption\Features
 */
final class HeartbeatTest extends TestCase {

	private const NOW     = 1761300000;
	private const API_KEY = '12|abcdefghijklmnopqrstuvwxyz0123456789ABCD';

	/**
	 * Queued fake responses.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $responses = array();

	/**
	 * Captured request bodies.
	 *
	 * @var list<array<mixed>>
	 */
	private array $bodies = array();

	/**
	 * Action Scheduler calls: [function, args].
	 *
	 * @var list<array{0:string,1:array<int,mixed>}>
	 */
	private array $scheduler = array();

	/**
	 * Whether the action is "scheduled".
	 *
	 * @var bool
	 */
	private bool $scheduled = false;

	private Options $store;
	private Credentials $credentials;
	private Connection $connection;
	private Clock $clock;

	protected function set_up(): void {
		parent::set_up();

		$this->responses = array();
		$this->bodies    = array();
		$this->scheduler = array();
		$this->scheduled = false;

		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ) {
				$this->bodies[] = json_decode( $args['body'] ?? '{}', true );
				$this->assertNotEmpty( $this->responses, 'Unexpected extra HTTP request to ' . $url );

				return array_shift( $this->responses );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn ( array $response ): int => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( array $response ): string => $response['body'] );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
		Functions\when( 'as_has_scheduled_action' )->alias( fn (): bool => $this->scheduled );
		Functions\when( 'as_schedule_recurring_action' )->alias(
			function ( ...$args ): int {
				$this->scheduler[] = array( 'schedule', $args );
				$this->scheduled   = true;

				return 1;
			}
		);
		Functions\when( 'as_unschedule_all_actions' )->alias(
			function ( ...$args ): void {
				$this->scheduler[] = array( 'unschedule', $args );
				$this->scheduled   = false;
			}
		);

		$this->store       = new Options();
		$this->credentials = new Credentials( new Crypto( 'salts' ), $this->store, static fn () => null );
		$this->clock       = new Clock( $this->store, static fn (): int => self::NOW );
		$this->connection  = new Connection( $this->store, static fn (): int => self::NOW );
	}

	private function heartbeat(): Heartbeat {
		$api = new Connect( new Client( $this->credentials, $this->clock, new Logger(), $this->store, '0.1.0' ) );

		return new Heartbeat( $api, $this->credentials, $this->connection, $this->clock, new Logger(), '0.1.0' );
	}

	private function connect(): void {
		$this->credentials->store( self::API_KEY );
		$this->connection->mark_connected(
			array( 'name' => 'Demo Cafe' ),
			array(
				'id'   => '101',
				'name' => 'Old Name',
			),
			7,
			Connection::METHOD_HOSTED,
			null,
			null
		);
	}

	/**
	 * Queue a JSON response.
	 *
	 * @param int          $status HTTP status.
	 * @param array<mixed> $body   Body.
	 */
	private function respond( int $status, array $body ): void {
		$this->responses[] = array(
			'response' => array( 'code' => $status ),
			'body'     => (string) json_encode( $body ),
			'headers'  => array(),
		);
	}

	/**
	 * A heartbeat response body.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 * @return array<string,mixed>
	 */
	private static function response( array $overrides = array() ): array {
		return $overrides + array(
			'status'             => 'active',
			'outlet'             => array(
				'id'   => '101',
				'uuid' => '9a3c1f0e-6b1d-4c39-9f7e-0c2b5d6a7e81',
				'name' => 'Online Store',
			),
			'features'           => array(
				'earn'              => true,
				'vouchers'          => false,
				'points_redemption' => true,
				'credits'           => false,
				'otp_channels'      => array( 'whatsapp', 'sms' ),
			),
			'redemption_rules'   => array(),
			'earn_on'            => 'completed',
			'min_plugin_version' => '0.2.0',
			'server_time'        => self::NOW + 95,
		);
	}

	public function test_a_successful_heartbeat_caches_the_status_and_aligns_the_clock(): void {
		$this->connect();
		$this->respond( 200, self::response() );

		$result = $this->heartbeat()->run();

		$this->assertSame( 'active', $result['status'] );
		$remote = $this->connection->remote_status();
		$this->assertSame( 'completed', $remote['earn_on'] );
		$this->assertSame( self::NOW, $remote['received_at'] );
		$this->assertNull( $remote['last_error'] );
		$this->assertSame( self::NOW, $this->connection->last_heartbeat_at() );
		$this->assertSame( 95, $this->clock->offset() );
		$this->assertSame( 95, $this->store->get_int( Options::CLOCK_OFFSET ) );

		$this->assertTrue( $this->connection->feature( 'earn' ) );
		$this->assertFalse( $this->connection->feature( 'vouchers' ) );
		$this->assertTrue( $this->connection->is_plugin_outdated( '0.1.0' ) );
		$this->assertFalse( $this->connection->is_plugin_outdated( '0.2.0' ) );
		$this->assertSame( 'Online Store', $this->connection->data()['outlet']['name'], 'Outlet renames are picked up.' );
		$this->assertSame( 'Demo Cafe', $this->connection->data()['organisation']['name'] );
	}

	public function test_the_payload_reports_outbox_statistics_from_the_filter(): void {
		$this->connect();
		Filters\expectApplied( 'pixalink_loyalty_heartbeat_queue_stats' )->once()->andReturn(
			array(
				'pending'           => 3,
				'failed'            => 1,
				'oldest_pending_at' => '2026-10-24T08:00:00Z',
			)
		);
		$this->respond( 200, self::response() );

		$this->heartbeat()->run();

		$this->assertSame(
			array(
				'site'              => 'https://shop.example.test',
				'plugin_version'    => '0.1.0',
				'wc_version'        => '0.0.0',
				'pending'           => 3,
				'failed'            => 1,
				'oldest_pending_at' => '2026-10-24T08:00:00Z',
			),
			$this->bodies[0]
		);
	}

	public function test_a_rejected_key_marks_the_connection_auth_failed(): void {
		$this->connect();
		$this->respond( 200, self::response() );
		$this->heartbeat()->run();
		$this->respond(
			401,
			array(
				'message' => 'The API key was revoked.',
				'code'    => 'api_key_revoked',
			)
		);

		try {
			$this->heartbeat()->run();
			$this->fail( 'Expected ApiException.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( 'api_key_revoked', $exception->error_code() );
		}

		$data = $this->connection->data();
		$this->assertSame( Connection::STATUS_AUTH_FAILED, $data['status'] );
		$this->assertSame( 'api_key_revoked', $data['auth_error'] );
		$this->assertSame( self::NOW, $data['auth_failed_at'] );
		$this->assertTrue( $this->connection->exists() );
		$this->assertFalse( $this->connection->is_connected() );

		$remote = $this->connection->remote_status();
		$this->assertSame( 'active', $remote['status'], 'The last good response is kept.' );
		$this->assertSame(
			array(
				'code'   => 'api_key_revoked',
				'status' => 401,
				'at'     => self::NOW,
			),
			$remote['last_error']
		);
	}

	public function test_a_forbidden_key_marks_the_connection_auth_failed(): void {
		$this->connect();
		$this->respond(
			403,
			array(
				'message' => 'This key is not an outlet key.',
				'code'    => 'outlet_key_required',
			)
		);

		$this->expectException( ApiException::class );
		try {
			$this->heartbeat()->run();
		} finally {
			$this->assertSame( Connection::STATUS_AUTH_FAILED, $this->connection->status() );
		}
	}

	public function test_an_inactive_subscription_keeps_the_connection(): void {
		$this->connect();
		$this->respond(
			403,
			array(
				'message' => 'Subscription inactive.',
				'code'    => 'subscription_inactive',
			)
		);

		try {
			$this->heartbeat()->run();
			$this->fail( 'Expected ApiException.' );
		} catch ( ApiException $exception ) {
			$this->assertFalse( Heartbeat::is_key_rejection( $exception ) );
		}

		$this->assertSame( Connection::STATUS_CONNECTED, $this->connection->status() );
		$this->assertSame( 'subscription_inactive', $this->connection->remote_status()['last_error']['code'] );
	}

	/**
	 * Heartbeat body while Pixalink staff have paused the store.
	 *
	 * @return array<string,mixed>
	 */
	private static function paused_response(): array {
		return self::response(
			array(
				'status'   => 'commerce_paused',
				'features' => array(
					'earn'              => false,
					'vouchers'          => false,
					'points_redemption' => false,
					'credits'           => false,
					'otp_channels'      => array(),
				),
			)
		);
	}

	public function test_a_paused_heartbeat_keeps_the_connection_and_switches_loyalty_off(): void {
		$this->connect();
		$this->store->set( Options::POINTS_REDEMPTION_ENABLED, 'yes' );
		$this->respond( 200, self::response() );
		$this->heartbeat()->run();
		$features = new Features( $this->connection, $this->store, $this->credentials );
		$this->assertTrue( $features->enabled( CouponMapper::KIND_POINTS ), 'Points are on while the store is active.' );

		$this->respond( 200, self::paused_response() );
		Actions\expectDone( 'pixalink_loyalty_heartbeat_received' )->once()->with( \Mockery::on( static fn ( array $response ): bool => 'commerce_paused' === $response['status'] ) );

		$this->heartbeat()->run();

		$this->assertSame( Connection::STATUS_CONNECTED, $this->connection->status(), 'The key is still good.' );
		$this->assertTrue( $this->connection->is_commerce_paused() );
		$this->assertTrue( $this->connection->is_account_paused() );
		$this->assertFalse( $this->connection->feature( 'earn' ) );
		$this->assertFalse( $features->enabled( CouponMapper::KIND_POINTS ) );
		$this->assertFalse( $features->any_enabled() );
	}

	public function test_a_commerce_paused_403_marks_the_store_paused_without_rejecting_the_key(): void {
		$this->connect();
		$this->respond( 200, self::response() );
		$this->heartbeat()->run();
		$this->respond(
			403,
			array(
				'message' => 'This store is paused in Pixalink.',
				'code'    => 'commerce_paused',
			)
		);
		Actions\expectDone( 'pixalink_loyalty_commerce_paused' )->once()->with( 'connection/heartbeat' );

		try {
			$this->heartbeat()->run();
			$this->fail( 'Expected ApiException.' );
		} catch ( ApiException $exception ) {
			$this->assertTrue( $exception->is_commerce_paused() );
			$this->assertFalse( Heartbeat::is_key_rejection( $exception ) );
		}
		$this->assertSame( Connection::STATUS_CONNECTED, $this->connection->status() );

		// What the `pixalink_loyalty_commerce_paused` listener does (Brain Monkey does not run listeners).
		$this->heartbeat()->on_commerce_paused();

		$this->assertTrue( $this->connection->is_commerce_paused() );
		$remote = $this->connection->remote_status();
		$this->assertSame(
			array(
				'earn'              => false,
				'vouchers'          => false,
				'points_redemption' => false,
				'credits'           => false,
				'otp_channels'      => array( 'whatsapp', 'sms' ),
			),
			$remote['features'],
			'Every switch is off; other details of the last heartbeat stay.'
		);
		$this->assertSame( 'completed', $remote['earn_on'] );
		$this->assertSame( 'commerce_paused', $remote['last_error']['code'] );
	}

	public function test_an_active_heartbeat_clears_the_paused_state(): void {
		$this->connect();
		$this->respond( 200, self::paused_response() );
		$this->heartbeat()->run();
		$this->assertTrue( $this->connection->is_commerce_paused() );

		$this->respond( 200, self::response() );
		Actions\expectDone( 'pixalink_loyalty_heartbeat_received' )->once()->with( \Mockery::on( static fn ( array $response ): bool => 'active' === $response['status'] ) );

		$this->heartbeat()->run();

		$this->assertFalse( $this->connection->is_commerce_paused() );
		$this->assertFalse( $this->connection->is_account_paused() );
		$this->assertTrue( $this->connection->feature( 'earn' ) );
	}

	public function test_a_paused_403_before_connecting_records_nothing(): void {
		$this->connection->mark_commerce_paused();

		$this->assertSame( array(), $this->connection->remote_status() );
		$this->assertFalse( $this->connection->is_commerce_paused() );
	}

	public function test_an_accepted_heartbeat_recovers_from_auth_failed(): void {
		$this->connect();
		$this->connection->mark_auth_failed( 'unauthenticated' );
		$this->respond( 200, self::response() );

		$this->heartbeat()->run();

		$data = $this->connection->data();
		$this->assertSame( Connection::STATUS_CONNECTED, $data['status'] );
		$this->assertNull( $data['auth_error'] );
	}

	public function test_schedule_registers_one_recurring_action_in_the_plugin_group(): void {
		$heartbeat = $this->heartbeat();

		$heartbeat->schedule();
		$heartbeat->schedule();

		$this->assertCount( 1, $this->scheduler );
		[ $function, $args ] = $this->scheduler[0];
		$this->assertSame( 'schedule', $function );
		$this->assertSame( Heartbeat::INTERVAL, $args[1] );
		$this->assertSame( 21600, Heartbeat::INTERVAL, 'Every 6 hours.' );
		$this->assertSame( 'pixalink_loyalty_heartbeat', $args[2] );
		$this->assertSame( Installer::ACTION_GROUP, $args[4] );
		$this->assertTrue( $args[5], 'Unique action.' );
	}

	public function test_the_scheduled_run_unschedules_itself_once_disconnected(): void {
		$this->scheduled = true;

		$this->heartbeat()->handle_scheduled();

		$this->assertSame( 'unschedule', $this->scheduler[0][0] );
		$this->assertFalse( $this->scheduled );
	}

	public function test_the_scheduled_run_swallows_api_failures(): void {
		$this->connect();
		$this->respond(
			500,
			array(
				'message' => 'Server error.',
				'code'    => 'server_error',
			)
		);

		$this->heartbeat()->handle_scheduled();

		$this->assertSame( Connection::STATUS_CONNECTED, $this->connection->status() );
		$this->assertSame( 500, $this->connection->remote_status()['last_error']['status'] );
	}

	public function test_ensure_scheduled_only_schedules_a_live_connection(): void {
		$heartbeat = $this->heartbeat();

		$heartbeat->ensure_scheduled();
		$this->assertSame( array(), $this->scheduler );

		$this->connect();
		$heartbeat->ensure_scheduled();
		$this->assertTrue( $this->scheduled );
	}
}
