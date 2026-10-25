<?php
/**
 * Tests for the portal-session and ticket parts of Customers.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Api\Resources;

use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\Resources\Customers;
use Pixalink\Loyalty\Api\Signer;
use Pixalink\Loyalty\Tests\Unit\Doubles\FakePixalinkHttp;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * `POST customers/{id}/portal-session`: the body names the WordPress user and the page to open, every call carries a
 * fresh Idempotency-Key, and the 201 is read into `{url, expires_at}` or refused.
 *
 * @covers \Pixalink\Loyalty\Api\Resources\Customers
 */
final class CustomersTest extends TestCase {

	use FakePixalinkHttp;

	protected function set_up(): void {
		parent::set_up();

		$this->fake_pixalink();
	}

	public function test_the_payload_names_the_user_and_a_known_destination(): void {
		$this->assertSame(
			array(
				'wp_user_id'  => 17,
				'destination' => 'rewards',
			),
			Customers::portal_session_payload( 17, 'rewards' )
		);
		$this->assertSame( 'points', Customers::portal_session_payload( 17, 'points' )['destination'] );
		$this->assertSame( 'credits', Customers::portal_session_payload( 17, 'credits' )['destination'] );
		$this->assertSame( 'home', Customers::portal_session_payload( 17, 'vouchers' )['destination'], 'Unknown pages open the portal home.' );
		$this->assertSame( 'home', Customers::portal_session_payload( 17, '' )['destination'] );
	}

	public function test_the_ticket_payload_carries_the_state_only_when_given(): void {
		$ticket = 'kP3xV7nQ2mR8sT4wY6zA1bC5dE9fG0hJ';
		$state  = 'q8VJ2nX5rT1wZ7bD4fH9kM3pS6uY0aC2eG5iL8oR1tW4';

		$this->assertSame(
			array(
				'external_id'  => null,
				'verified_via' => 'sso_ticket',
				'sso_ticket'   => $ticket,
			),
			Customers::ticket_payload( $ticket, 0 ),
			'A ticket from the portal\'s Order button travels alone.'
		);
		$this->assertArrayNotHasKey( 'sso_state', Customers::ticket_payload( $ticket, 0, null ) );
		$this->assertArrayNotHasKey( 'sso_state', Customers::ticket_payload( $ticket, 0, '' ), 'An empty state is no state.' );
		$this->assertSame(
			array(
				'external_id'  => '17',
				'verified_via' => 'sso_ticket',
				'sso_ticket'   => $ticket,
				'sso_state'    => $state,
			),
			Customers::ticket_payload( $ticket, 17, $state )
		);
	}

	public function test_the_payload_refuses_a_missing_user(): void {
		$this->expectException( \InvalidArgumentException::class );

		Customers::portal_session_payload( 0, 'home' );
	}

	public function test_the_path_encodes_the_customer_id(): void {
		$this->assertSame( 'customers/5001/portal-session', Customers::portal_session_path( '5001' ) );
		$this->assertSame( 'customers/a%2Fb/portal-session', Customers::portal_session_path( 'a/b' ) );
	}

	public function test_a_session_is_minted_with_a_fresh_idempotency_key_each_time(): void {
		$keys = array();
		$customers = new Customers( $this->fake_client() );

		$first  = $customers->portal_session( '5001', 17, 'rewards' );
		$second = $customers->portal_session( '5001', 17, 'rewards' );

		foreach ( $this->http_requests as $request ) {
			$this->assertSame( 'POST customers/5001/portal-session', $request['method'] . ' ' . $request['path'] );
			$this->assertSame(
				array(
					'wp_user_id'  => 17,
					'destination' => 'rewards',
				),
				$request['body']
			);
			$keys[] = $request['headers']['idempotency-key'] ?? '';
		}

		$this->assertMatchesRegularExpression( '#^https://fake-pixalink\.test/l/[a-z0-9-]+/handoff/[A-Za-z0-9]+$#', $first['url'] );
		$this->assertNotSame( '', $first['expires_at'] );
		$this->assertNotSame( $first['url'], $second['url'], 'Tickets are single use.' );
		$this->assertCount( 2, array_unique( $keys ) );
		foreach ( $keys as $key ) {
			$this->assertTrue( Signer::is_valid_nonce( $key ) );
		}
	}

	/**
	 * 201 bodies the plugin cannot use.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function unusable_bodies(): array {
		return array(
			'no url'        => array( array( 'expires_at' => '2026-10-25T08:01:00Z' ) ),
			'empty url'     => array(
				array(
					'url'        => '',
					'expires_at' => '2026-10-25T08:01:00Z',
				),
			),
			'url not text'  => array(
				array(
					'url'        => array( 'https://demo.pixalink.io' ),
					'expires_at' => '2026-10-25T08:01:00Z',
				),
			),
			'no expiry'     => array( array( 'url' => 'https://demo.pixalink.io/l/demo/handoff/abc' ) ),
			'expiry blank'  => array(
				array(
					'url'        => 'https://demo.pixalink.io/l/demo/handoff/abc',
					'expires_at' => '',
				),
			),
			'nothing at all' => array( array() ),
		);
	}

	/**
	 * @dataProvider unusable_bodies
	 *
	 * @param array<string,mixed> $body Response body.
	 */
	public function test_an_unusable_answer_is_an_invalid_response( array $body ): void {
		try {
			Customers::portal_session_response( $body );
			$this->fail( 'Expected an ApiException.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( ApiException::INVALID_RESPONSE, $exception->error_code() );
			$this->assertSame( 201, $exception->http_status() );
		}
	}

	public function test_a_refusal_surfaces_as_the_server_error(): void {
		$this->script_http(
			'POST',
			'customers/5001/portal-session',
			429,
			array(
				'message' => 'Slow down.',
				'code'    => 'rate_limited',
			),
			array( 'retry-after' => '30' )
		);

		try {
			( new Customers( $this->fake_client() ) )->portal_session( '5001', 17, 'home' );
			$this->fail( 'Expected an ApiException.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( 'rate_limited', $exception->error_code() );
			$this->assertSame( 30, $exception->retry_after() );
		}
	}
}
