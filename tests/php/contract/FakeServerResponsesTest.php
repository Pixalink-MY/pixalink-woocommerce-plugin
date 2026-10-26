<?php
/**
 * Contract: the fake Pixalink server's canned responses.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Contract;

use Pixalink\Loyalty\Tests\FakeServer\ContractValidator;
use Pixalink\Loyalty\Tests\FakeServer\Responses;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * Every default response of the fake server matches the schema the real server must satisfy.
 *
 * @covers \Pixalink\Loyalty\Tests\FakeServer\Responses
 */
final class FakeServerResponsesTest extends TestCase {

	/**
	 * Canned responses keyed by scenario.
	 *
	 * @return array<string,array{0:array{status:int,body:mixed},1:string}>
	 */
	public static function samples(): array {
		$cases = array();
		foreach ( Responses::samples( 1761300000 ) as $name => $sample ) {
			$cases[ $name ] = array( $sample['response'], $sample['schema'] );
		}

		return $cases;
	}

	/**
	 * @dataProvider samples
	 *
	 * @param array{status:int,body:mixed} $response Canned response.
	 * @param string                       $schema   Schema file.
	 */
	public function test_canned_response_matches_its_schema( array $response, string $schema ): void {
		$this->assertSame( array(), ContractValidator::validate( $schema, $response['body'] ) );

		if ( 'error.response.json' === $schema ) {
			$this->assertGreaterThanOrEqual( 400, $response['status'] );
		} else {
			$this->assertContains( $response['status'], array( 200, 201 ) );
		}
	}

	public function test_scenarios_return_the_documented_status_codes(): void {
		$samples = Responses::samples( 1761300000 );

		$this->assertSame( 400, $samples['exchange invalid']['response']['status'] );
		$this->assertSame( 201, $samples['create new']['response']['status'] );
		$this->assertSame( 404, $samples['lookup miss']['response']['status'] );
		$this->assertFalse( $samples['create existing']['response']['body']['customer']['verified'], 'Only a ticket proves the phone.' );
		$this->assertTrue( $samples['sso member']['response']['body']['customer']['verified'] );
		$this->assertNull( $samples['sso no email']['response']['body']['customer']['email'] );
		$this->assertSame( 410, $samples['sso unknown']['response']['status'] );
		$this->assertSame( 410, $samples['sso replayed']['response']['status'], 'Tickets are single use.' );
		$this->assertSame( 'ticket_invalid', $samples['sso replayed']['response']['body']['code'] );
		$this->assertSame( 'sms', $samples['otp sms']['response']['body']['sent_via'] );
		$this->assertSame( 422, $samples['otp verify wrong']['response']['status'] );
		$this->assertSame( 'otp_invalid', $samples['otp verify wrong']['response']['body']['code'] );
		$this->assertSame( 'otp_expired', $samples['otp verify expired']['response']['body']['code'] );
		$this->assertSame( 'otp_attempts_exceeded', $samples['otp verify exhausted']['response']['body']['code'] );
		$this->assertSame( 404, $samples['summary missing']['response']['status'] );
		$this->assertSame( 409, $samples['hold too many points']['response']['status'] );
		$this->assertSame( 'points_insufficient', $samples['hold too many points']['response']['body']['code'] );
		$this->assertSame( Responses::POINTS_AVAILABLE, $samples['hold too many points']['response']['body']['available'] );
		$this->assertSame( 409, $samples['hold unknown voucher']['response']['status'] );
		$this->assertSame( 'voucher_unavailable', $samples['hold unknown voucher']['response']['body']['code'] );
		$this->assertSame( 409, $samples['hold too few credits']['response']['status'] );
		$this->assertSame( Responses::CREDITS_BALANCE_CENTS, $samples['hold too few credits']['response']['body']['available_cents'] );
		$this->assertSame( 422, $samples['hold mismatch']['response']['status'] );
		$this->assertSame( 'discount_mismatch', $samples['hold mismatch']['response']['body']['code'] );
		$this->assertSame( 'duplicate', $samples['event duplicate']['response']['body']['status'] );
		$this->assertSame( 1761300000, $samples['timestamp skew']['response']['body']['server_time'] );
		$this->assertSame( 409, $samples['heartbeat other site']['response']['status'] );
		$this->assertSame( 'site_already_connected', $samples['heartbeat other site']['response']['body']['code'] );
	}

	public function test_a_paused_store_answers_403_on_every_signed_endpoint_but_the_heartbeat_and_disconnect(): void {
		$context = array(
			'now'             => 1761300000,
			'commerce_paused' => true,
		);

		foreach ( ContractValidator::endpoints() as $endpoint ) {
			if ( 'signed' !== $endpoint['auth'] ) {
				continue;
			}

			$name     = $endpoint['method'] . ' ' . $endpoint['path'];
			$response = Responses::respond( $endpoint['method'], $endpoint['path'], array(), array(), array(), $context );
			if ( 'connection/heartbeat' === $endpoint['path'] ) {
				$this->assertSame( 200, $response['status'], $name );
				$this->assertSame( 'commerce_paused', $response['body']['status'] );
				$this->assertSame( array( false, false, false, false ), array( $response['body']['features']['earn'], $response['body']['features']['vouchers'], $response['body']['features']['points_redemption'], $response['body']['features']['credits'] ) );
				$this->assertSame( array(), ContractValidator::validate( 'connection-heartbeat.response.json', $response['body'] ) );
			} elseif ( 'connection/disconnect' === $endpoint['path'] ) {
				$this->assertSame( 204, $response['status'], $name );
			} else {
				$this->assertSame( 403, $response['status'], $name );
				$this->assertSame( 'commerce_paused', $response['body']['code'], $name );
				$this->assertSame( array(), ContractValidator::validate( 'error.response.json', $response['body'] ), $name );
			}
		}

		$this->assertContains(
			array(
				'status' => 403,
				'code'   => 'commerce_paused',
			),
			array_map( static fn ( array $error ): array => array_intersect_key( $error, array_flip( array( 'status', 'code' ) ) ), ContractValidator::documented_errors() ),
			'endpoints.json documents the 403 for every signed endpoint.'
		);
	}

	public function test_holds_echo_the_coupon_codes_in_request_order(): void {
		$held = Responses::samples( 1761300000 )['hold']['response']['body']['redemptions'];

		$this->assertSame( array( 'pxl-welcome-88', 'pxl-points', 'pxl-credits' ), array_column( $held, 'coupon_code' ) );
		$this->assertSame( array( 'voucher', 'points', 'credit' ), array_column( $held, 'kind' ) );
	}

	public function test_the_quote_caps_points_and_credits_at_what_the_cart_absorbs(): void {
		$cart  = array(
			'subtotal_cents' => 1550,
			'discount_cents' => 500,
		);
		$quote = Responses::respond( 'POST', 'quote', array(), array(), array( 'cart' => $cart ), array( 'points_balance' => 5000 ) )['body'];

		$this->assertSame( 1000, $quote['points']['max_for_cart'], '10.50 absorbable at 1 cent a point, on a 100 step' );
		$this->assertSame( 1050, $quote['credits']['max_for_cart_cents'] );
		$this->assertSame( 5000, $quote['points']['available'] );
	}

	public function test_disconnect_answers_no_content(): void {
		$this->assertSame(
			array(
				'status' => 204,
				'body'   => null,
			),
			Responses::respond( 'POST', 'connection/disconnect', array(), array(), array( 'reason' => 'merchant_request' ), array() )
		);
	}

	public function test_a_heartbeat_from_the_connected_site_is_accepted(): void {
		$response = Responses::respond(
			'POST',
			'connection/heartbeat',
			array(),
			array(),
			array( 'site' => 'https://shop.example.test/' ),
			array(
				'now'            => 1761300000,
				'connected_site' => 'https://shop.example.test',
			)
		);

		$this->assertSame( 200, $response['status'] );
	}
}
