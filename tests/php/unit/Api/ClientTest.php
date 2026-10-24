<?php
/**
 * Tests for Client.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Api;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\Client;
use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Api\Signer;
use Pixalink\Loyalty\Support\Clock;
use Pixalink\Loyalty\Support\Crypto;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * Unit coverage for headers, signing nonces, error mapping and the timestamp-skew retry.
 *
 * The same retry is exercised against real WordPress HTTP (pre_http_request) in the integration suite.
 *
 * @covers \Pixalink\Loyalty\Api\Client
 */
final class ClientTest extends TestCase {

	private const API_KEY   = '1|abcdefghijklmnopqrstuvwxyz0123456789ABCD';
	private const LOCAL_NOW = 1761300000;

	/**
	 * Captured wp_remote_request() calls: [url, args].
	 *
	 * @var list<array{0:string,1:array<string,mixed>}>
	 */
	private array $requests = array();

	/**
	 * Queued fake responses (arrays shaped like WP_Http results, or WP_Error stand-ins).
	 *
	 * @var list<mixed>
	 */
	private array $responses = array();

	/**
	 * Whether an API key "constant" is visible.
	 *
	 * @var bool
	 */
	private bool $connected = true;

	protected function set_up(): void {
		parent::set_up();

		$this->requests  = array();
		$this->responses = array();
		$this->connected = true;

		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ) {
				$this->requests[] = array( $url, $args );
				$this->assertNotEmpty( $this->responses, 'Unexpected extra HTTP request.' );

				return array_shift( $this->responses );
			}
		);
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ): bool => $thing instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn ( array $response ): int => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( array $response ): string => $response['body'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			static fn ( array $response, string $name ): string => $response['headers'][ strtolower( $name ) ] ?? ''
		);
	}

	private function client(): Client {
		$options     = new Options();
		$credentials = new Credentials(
			new Crypto( 'salts' ),
			$options,
			fn ( string $name ) => ( $this->connected && Credentials::API_KEY_CONSTANT === $name ) ? self::API_KEY : null
		);

		return new Client( $credentials, new Clock( $options, static fn (): int => self::LOCAL_NOW ), new Logger(), $options, '1.2.3' );
	}

	/**
	 * Queue a JSON response.
	 *
	 * @param int                  $status  HTTP status.
	 * @param array<mixed>|string  $body    Body (array is JSON-encoded).
	 * @param array<string,string> $headers Lower-case headers.
	 */
	private function respond( int $status, $body, array $headers = array() ): void {
		$this->responses[] = array(
			'response' => array( 'code' => $status ),
			'body'     => is_array( $body ) ? (string) json_encode( $body ) : $body,
			'headers'  => $headers,
		);
	}

	/**
	 * Headers of a captured request.
	 *
	 * @param int $index Request index.
	 * @return array<string,string>
	 */
	private function headers( int $index = 0 ): array {
		return $this->requests[ $index ][1]['headers'];
	}

	private function assert_signed( int $index, string $expected_nonce ): void {
		$headers = $this->headers( $index );
		$body    = $this->requests[ $index ][1]['body'] ?? '';

		$this->assertSame( 'Bearer ' . self::API_KEY, $headers['Authorization'] );
		$this->assertTrue(
			Signer::verify(
				Signer::derive_signing_key( self::API_KEY ),
				$headers['X-Pixalink-Signature'],
				(int) $headers['X-Pixalink-Timestamp'],
				$expected_nonce,
				$body,
				(int) $headers['X-Pixalink-Timestamp']
			),
			'Signature does not verify with the expected nonce.'
		);
	}

	public function test_get_is_signed_with_the_read_nonce_and_carries_identity_headers(): void {
		$this->respond( 200, array( 'data' => array() ) );

		$response = $this->client()->get( 'customers', array( 'phone' => '+60123456789' ) );

		$this->assertSame( 200, $response->status );
		[ $url, $args ] = $this->requests[0];
		$this->assertSame( 'https://explore.pixalink.io/api/commerce/v1/customers?phone=%2B60123456789', $url );
		$this->assertSame( 'GET', $args['method'] );
		$this->assertSame( 8, $args['timeout'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertArrayNotHasKey( 'body', $args );
		$this->assertStringStartsWith( 'PixalinkLoyaltyForWooCommerce/1.2.3 ', $args['user-agent'] );
		$this->assertSame( 'https://shop.example.test', $this->headers()['X-Pixalink-Site'] );
		$this->assertSame( 'pixalink-loyalty-for-woocommerce/1.2.3', $this->headers()['X-Pixalink-Plugin'] );
		$this->assertSame( (string) self::LOCAL_NOW, $this->headers()['X-Pixalink-Timestamp'] );
		$this->assertArrayNotHasKey( 'Idempotency-Key', $this->headers() );
		$this->assertArrayNotHasKey( 'X-Pixalink-Event-Id', $this->headers() );
		$this->assert_signed( 0, Signer::READ_NONCE );
	}

	public function test_mutations_sign_with_the_idempotency_key(): void {
		$this->respond( 201, array( 'customer' => array( 'id' => '42' ) ) );

		$this->client()->post( 'customers', array( 'phone_e164' => '+60123456789' ), array( 'idempotency_key' => 'idem-0000000000000123' ) );

		$this->assertSame( 'idem-0000000000000123', $this->headers()['Idempotency-Key'] );
		$this->assertSame( '{"phone_e164":"+60123456789"}', $this->requests[0][1]['body'] );
		$this->assertSame( 'application/json; charset=utf-8', $this->headers()['Content-Type'] );
		$this->assertSame( 10, $this->requests[0][1]['timeout'] );
		$this->assert_signed( 0, 'idem-0000000000000123' );
	}

	public function test_mutations_generate_an_idempotency_key_when_none_is_given(): void {
		$this->respond( 200, array( 'redemptions' => array() ) );

		$this->client()->put( 'orders/wc_order_abc/redemptions', array( 'redemptions' => array() ) );

		$key = $this->headers()['Idempotency-Key'];
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $key );
		$this->assertSame( 6, $this->requests[0][1]['timeout'], 'Checkout calls use the short timeout.' );
		$this->assert_signed( 0, $key );
	}

	public function test_events_sign_with_the_event_id_and_send_no_idempotency_key(): void {
		$this->respond( 200, array( 'status' => 'processed' ) );

		$this->client()->send_event(
			array(
				'id'   => 'a6417789-0a05-5068-bab0-90d991946d97',
				'type' => 'ping',
			)
		);

		$this->assertSame( 'a6417789-0a05-5068-bab0-90d991946d97', $this->headers()['X-Pixalink-Event-Id'] );
		$this->assertArrayNotHasKey( 'Idempotency-Key', $this->headers() );
		$this->assertSame( 15, $this->requests[0][1]['timeout'] );
		$this->assert_signed( 0, 'a6417789-0a05-5068-bab0-90d991946d97' );
	}

	public function test_events_without_an_id_are_rejected_before_sending(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->client()->request( 'POST', 'events', array( 'body' => array( 'type' => 'ping' ) ) );
	}

	public function test_event_ids_outside_the_server_nonce_format_are_rejected_before_sending(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->client()->send_event( array( 'id' => 'evt.0000000000000001' ) );
	}

	public function test_idempotency_keys_outside_the_server_nonce_format_are_rejected_before_sending(): void {
		try {
			$this->client()->post( 'customers', array(), array( 'idempotency_key' => 'short' ) );
			$this->fail( 'Expected InvalidArgumentException.' );
		} catch ( \InvalidArgumentException $exception ) {
			$this->assertStringContainsString( 'Idempotency keys must match', $exception->getMessage() );
		}

		$this->assertSame( array(), $this->requests );
	}

	public function test_timestamp_skew_realigns_the_clock_and_retries_once_with_the_same_nonce(): void {
		$this->respond(
			401,
			array(
				'message'     => 'Timestamp outside the allowed window.',
				'code'        => 'timestamp_skew',
				'server_time' => self::LOCAL_NOW + 900,
			)
		);
		$this->respond( 200, array( 'status' => 'active' ) );

		$response = $this->client()->post( 'connection/heartbeat', array( 'pending' => 0 ), array( 'idempotency_key' => 'idem-skew-0000000001' ) );

		$this->assertSame( 'active', $response->data['status'] );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( (string) self::LOCAL_NOW, $this->headers( 0 )['X-Pixalink-Timestamp'] );
		$this->assertSame( (string) ( self::LOCAL_NOW + 900 ), $this->headers( 1 )['X-Pixalink-Timestamp'] );
		$this->assertSame( 'idem-skew-0000000001', $this->headers( 1 )['Idempotency-Key'] );
		$this->assertSame( 900, $this->options[ Options::CLOCK_OFFSET ][0] );
		$this->assert_signed( 1, 'idem-skew-0000000001' );
	}

	public function test_timestamp_skew_falls_back_to_the_date_header(): void {
		$this->respond(
			401,
			array( 'code' => 'timestamp_skew' ),
			array( 'date' => gmdate( 'D, d M Y H:i:s', self::LOCAL_NOW - 600 ) . ' GMT' )
		);
		$this->respond( 200, array( 'data' => array() ) );

		$this->client()->get( 'outlet' );

		$this->assertCount( 2, $this->requests );
		$this->assertSame( -600, $this->options[ Options::CLOCK_OFFSET ][0] );
		$this->assertSame( (string) ( self::LOCAL_NOW - 600 ), $this->headers( 1 )['X-Pixalink-Timestamp'] );
	}

	public function test_timestamp_skew_is_retried_only_once(): void {
		$skew = array(
			'code'        => 'timestamp_skew',
			'server_time' => self::LOCAL_NOW + 900,
		);
		$this->respond( 401, $skew );
		$this->respond( 401, $skew );

		try {
			$this->client()->get( 'outlet' );
			$this->fail( 'Expected ApiException.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( 'timestamp_skew', $exception->error_code() );
			$this->assertFalse( $exception->is_auth_failure() );
		}

		$this->assertCount( 2, $this->requests );
	}

	public function test_other_401s_are_not_retried(): void {
		$this->respond(
			401,
			array(
				'code'        => 'signature_invalid',
				'server_time' => self::LOCAL_NOW + 900,
			)
		);

		try {
			$this->client()->get( 'outlet' );
			$this->fail( 'Expected ApiException.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( 'signature_invalid', $exception->error_code() );
			$this->assertTrue( $exception->is_auth_failure() );
		}

		$this->assertCount( 1, $this->requests );
		$this->assertArrayNotHasKey( Options::CLOCK_OFFSET, $this->options );
	}

	public function test_error_envelope_is_mapped_to_an_api_exception(): void {
		$this->respond(
			409,
			array(
				'message'   => 'Not enough points.',
				'code'      => 'points_insufficient',
				'available' => 120,
			),
			array( 'retry-after' => '30' )
		);

		try {
			$this->client()->put( 'orders/wc_order_abc/redemptions', array( 'redemptions' => array() ) );
			$this->fail( 'Expected ApiException.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( 'points_insufficient', $exception->error_code() );
			$this->assertSame( 409, $exception->http_status() );
			$this->assertSame( 'Not enough points.', $exception->getMessage() );
			$this->assertSame( array( 'available' => 120 ), $exception->details() );
			$this->assertSame( 30, $exception->retry_after() );
			$this->assertTrue( $exception->is_retryable() );
		}
	}

	public function test_errors_without_an_envelope_get_an_http_code(): void {
		$this->respond( 502, '<html>Bad gateway</html>' );

		try {
			$this->client()->get( 'outlet' );
			$this->fail( 'Expected ApiException.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( 'http_502', $exception->error_code() );
			$this->assertTrue( $exception->is_retryable() );
		}
	}

	public function test_non_json_success_body_is_rejected(): void {
		$this->respond( 200, 'OK' );

		$this->expectException( ApiException::class );
		$this->expectExceptionMessage( 'not a JSON object' );

		$this->client()->get( 'outlet' );
	}

	public function test_transport_errors_are_retryable_network_errors(): void {
		$error = \Mockery::mock( 'WP_Error' );
		$error->shouldReceive( 'get_error_message' )->andReturn( 'cURL error 28: Operation timed out' );
		$error->shouldReceive( 'get_error_code' )->andReturn( 'http_request_failed' );
		$this->responses[] = $error;

		try {
			$this->client()->get( 'outlet' );
			$this->fail( 'Expected ApiException.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( ApiException::NETWORK_ERROR, $exception->error_code() );
			$this->assertSame( 0, $exception->http_status() );
			$this->assertSame( array( 'transport' => 'http_request_failed' ), $exception->details() );
			$this->assertTrue( $exception->is_retryable() );
		}
	}

	public function test_authenticated_calls_fail_fast_when_not_connected(): void {
		$this->connected = false;

		try {
			$this->client()->get( 'outlet' );
			$this->fail( 'Expected ApiException.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( ApiException::NOT_CONNECTED, $exception->error_code() );
		}

		$this->assertSame( array(), $this->requests );
	}

	public function test_unauthenticated_calls_send_no_credentials(): void {
		$this->connected = false;
		$this->respond( 200, array( 'api_key' => '5|new' ) );

		$this->client()->post( 'connect/exchange', array( 'code' => 'abc' ), array( 'authenticated' => false ) );

		$headers = $this->headers();
		$this->assertArrayNotHasKey( 'Authorization', $headers );
		$this->assertArrayNotHasKey( 'X-Pixalink-Signature', $headers );
		$this->assertArrayNotHasKey( 'X-Pixalink-Timestamp', $headers );
		$this->assertSame( 20, $this->requests[0][1]['timeout'] );
	}

	public function test_base_url_comes_from_the_option_then_the_filter(): void {
		$this->assertSame( Client::DEFAULT_BASE_URL, $this->client()->base_url() );

		$this->options[ Options::API_BASE ] = array( 'https://uat.example.test/api/commerce/v1/', true );
		$this->assertSame( 'https://uat.example.test/api/commerce/v1', $this->client()->base_url() );

		Filters\expectApplied( 'pixalink_loyalty_api_base' )
			->once()
			->with( 'https://uat.example.test/api/commerce/v1/' )
			->andReturn( 'https://filtered.example.test/v1' );
		$this->assertSame( 'https://filtered.example.test/v1', $this->client()->base_url() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_base_url_constant_wins_over_filter_and_option(): void {
		define( 'PIXALINK_LOYALTY_API_BASE', 'https://fake-pixalink.test/api/commerce/v1/' );
		$this->options[ Options::API_BASE ] = array( 'https://uat.example.test/api/commerce/v1', true );
		Filters\expectApplied( 'pixalink_loyalty_api_base' )->never();

		$this->assertSame( 'https://fake-pixalink.test/api/commerce/v1', $this->client()->base_url() );
	}

	public function test_timeout_filter_can_shorten_calls(): void {
		Filters\expectApplied( 'pixalink_loyalty_api_timeout' )->once()->with( 8, Client::ENDPOINT_READ, 'outlet' )->andReturn( 3 );
		$this->respond( 200, array() );

		$this->client()->get( 'outlet' );

		$this->assertSame( 3, $this->requests[0][1]['timeout'] );
	}

	/**
	 * @dataProvider endpoint_classes
	 *
	 * @param string $method   HTTP method.
	 * @param string $path     Path.
	 * @param string $expected Endpoint class.
	 */
	public function test_infer_endpoint_class( string $method, string $path, string $expected ): void {
		$this->assertSame( $expected, Client::infer_endpoint_class( $method, $path ) );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function endpoint_classes(): array {
		return array(
			'lookup'           => array( 'GET', 'customers', Client::ENDPOINT_READ ),
			'summary'          => array( 'GET', 'customers/42', Client::ENDPOINT_READ ),
			'create customer'  => array( 'POST', 'customers', Client::ENDPOINT_WRITE ),
			'otp'              => array( 'POST', 'customers/42/otp', Client::ENDPOINT_WRITE ),
			'quote'            => array( 'POST', 'quote', Client::ENDPOINT_CHECKOUT ),
			'hold'             => array( 'PUT', 'orders/wc_order_1/redemptions', Client::ENDPOINT_CHECKOUT ),
			'release'          => array( 'DELETE', 'orders/wc_order_1/redemptions', Client::ENDPOINT_CHECKOUT ),
			'events'           => array( 'POST', 'events', Client::ENDPOINT_EVENTS ),
			'exchange'         => array( 'POST', 'connect/exchange', Client::ENDPOINT_CONNECT ),
			'heartbeat'        => array( 'POST', 'connection/heartbeat', Client::ENDPOINT_CONNECT ),
		);
	}
}
