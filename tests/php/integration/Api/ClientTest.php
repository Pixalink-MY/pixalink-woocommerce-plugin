<?php
/**
 * Integration tests for Client.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Api;

use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\Client;
use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Api\Resources\Connect;
use Pixalink\Loyalty\Support\Clock;
use Pixalink\Loyalty\Support\Crypto;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\FakeServer\Server;
use Pixalink\Loyalty\Tests\FakeServer\Signature;

/**
 * Real WordPress HTTP API: the timestamp-skew retry via pre_http_request, and signed round trips
 * through the fake Pixalink server (independent signature verification + error envelope).
 *
 * @covers \Pixalink\Loyalty\Api\Client
 * @covers \Pixalink\Loyalty\Api\Resources\Connect
 */
final class ClientTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		Server::reset();
		delete_option( Options::CLOCK_OFFSET );
		delete_option( Options::CREDENTIALS );
	}

	/**
	 * A client wired to fresh service instances.
	 *
	 * @param string $api_key Outlet API key to store.
	 * @return Client
	 */
	private function client( string $api_key = Signature::FAKE_API_KEY ): Client {
		$options     = new Options();
		$credentials = new Credentials( new Crypto(), $options );
		$credentials->store( $api_key );

		return new Client( $credentials, new Clock( $options ), new Logger(), $options, PIXALINK_LOYALTY_VERSION );
	}

	public function test_timestamp_skew_retry_through_pre_http_request(): void {
		$calls  = array();
		$mocked = static function ( $preempt, $args, $url ) use ( &$calls ) {
			$calls[] = $args;
			if ( 1 === count( $calls ) ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'message'     => 'Timestamp outside the allowed window.',
							'code'        => 'timestamp_skew',
							'server_time' => time() + 900,
						)
					),
					'response' => array(
						'code'    => 401,
						'message' => 'Unauthorized',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}

			return array(
				'headers'  => array(),
				'body'     => '{"outlet":{"id":"101"}}',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $mocked, 1, 3 );

		$response = $this->client()->get( 'outlet' );

		remove_filter( 'pre_http_request', $mocked, 1 );
		$this->assertSame( '101', $response->data['outlet']['id'] );
		$this->assertCount( 2, $calls );
		$this->assertEqualsWithDelta( 900, (int) get_option( Options::CLOCK_OFFSET ), 2 );
		$this->assertEqualsWithDelta( time() + 900, (int) $calls[1]['headers']['X-Pixalink-Timestamp'], 2 );
	}

	public function test_signed_heartbeat_round_trips_through_the_fake_server(): void {
		$connect = new Connect( $this->client() );

		$result = $connect->heartbeat( Connect::heartbeat_payload( home_url(), '0.1.0', WC_VERSION, 0, 0, null ) );

		$this->assertSame( 'active', $result['status'] );
		$this->assertSame( 'paid', $result['earn_on'] );

		$recorded = Server::recorded();
		$this->assertCount( 1, $recorded );
		$this->assertSame( 'connection/heartbeat', $recorded[0]['path'] );
		$this->assertSame( 200, $recorded[0]['status'] );
		$this->assertNull( $recorded[0]['auth_error'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9-]{16,64}$/', $recorded[0]['headers']['idempotency-key'] );
	}

	public function test_signed_event_round_trip_and_duplicate_detection(): void {
		$client   = $this->client();
		$envelope = array(
			'id'          => \Pixalink\Loyalty\Support\Uuid::event_id( home_url() . '|ping|integration' ),
			'type'        => 'ping',
			'version'     => 1,
			'occurred_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'sequence'    => 0,
			'site_url'    => home_url(),
			'outlet_id'   => '101',
			'data'        => new \stdClass(),
		);

		$this->assertSame( 'processed', $client->send_event( $envelope )->data['status'] );
		$this->assertSame( 'duplicate', $client->send_event( $envelope )->data['status'] );
	}

	public function test_fake_server_skew_is_recovered_end_to_end(): void {
		set_transient( 'pixalink_loyalty_fake_clock_offset', 1000, HOUR_IN_SECONDS );

		$response = $this->client()->get( 'outlet' );

		$this->assertSame( '101', $response->data['outlet']['id'] );
		$this->assertEqualsWithDelta( 1000, (int) get_option( Options::CLOCK_OFFSET ), 2 );
		$statuses = array_column( Server::recorded(), 'status' );
		$this->assertSame( array( 401, 200 ), $statuses );
	}

	public function test_unknown_key_is_rejected_with_the_pixalink_error_envelope(): void {
		try {
			$this->client( '2|someoneelsesoutletkey000000000000' )->get( 'outlet' );
			$this->fail( 'Expected ApiException.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( 'unauthenticated', $exception->error_code() );
			$this->assertSame( 401, $exception->http_status() );
			$this->assertTrue( $exception->is_auth_failure() );
		}
	}

	public function test_exchange_is_unauthenticated_and_maps_invalid_grant(): void {
		$connect = new Connect( $this->client() );

		$exchange = $connect->exchange( 'fake-code-0123456789abcdef0123456789abcdef', str_repeat( 'v', 43 ), home_url() );
		$this->assertSame( Signature::FAKE_API_KEY, $exchange['api_key'] );
		$this->assertNull( Server::recorded()[0]['headers']['authorization'] );

		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( 'invalid or expired' );
		$connect->exchange( 'fake-code-invalid-0000000000000000', str_repeat( 'v', 43 ), home_url() );
	}

	public function test_base_url_points_at_the_fake_server(): void {
		$this->assertSame( 'https://fake-pixalink.test/api/commerce/v1', $this->client()->base_url() );
		$this->assertNotSame( Client::DEFAULT_BASE_URL, $this->client()->base_url() );
	}
}
