<?php
/**
 * Integration tests for InboundController.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Webhooks;

use Pixalink\Loyalty\Account\SummaryService;
use Pixalink\Loyalty\Identity\Link;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Tests\FakeServer\ContractValidator;
use Pixalink\Loyalty\Tests\FakeServer\Responses;
use Pixalink\Loyalty\Tests\FakeServer\Server;
use Pixalink\Loyalty\Tests\FakeServer\Signature;
use Pixalink\Loyalty\Tests\Integration\Support\StoreFixtures;

/**
 * Webhooks signed by the fake Pixalink server (its own signer) are accepted once; forged, stale or replayed ones are
 * not acted on.
 *
 * @covers \Pixalink\Loyalty\Webhooks\InboundController
 */
final class InboundControllerTest extends \WP_UnitTestCase {

	use StoreFixtures;

	private int $user_id = 0;

	public function set_up(): void {
		parent::set_up();

		$this->set_up_store();
		$this->user_id = $this->make_user();
		UserMeta::store_link( $this->user_id, new Link( Responses::MEMBER_ID, Responses::MEMBER_PHONE, 'MY', '2026-10-25T02:00:00Z', Link::VIA_OTP ) );
		UserMeta::set_consent( $this->user_id, \Pixalink\Loyalty\Fields\ConsentRecord::given( true, 'checkout', 'Send me offers.', time() ) );
		set_transient( SummaryService::key( $this->user_id ), array( 'cached' => true ), 60 );
	}

	public function tear_down(): void {
		$this->tear_down_store();

		parent::tear_down();
	}

	/**
	 * Post a raw webhook with chosen headers.
	 *
	 * @param string               $raw_body Body.
	 * @param array<string,string> $headers  Headers.
	 * @return \WP_REST_Response
	 */
	private static function post_raw( string $raw_body, array $headers ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/pixalink-loyalty/v1/webhook' );
		$request->set_header( 'content-type', 'application/json' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_body( $raw_body );

		return rest_ensure_response( rest_do_request( $request ) );
	}

	public function test_a_signed_customer_update_busts_the_cache_and_records_a_blocked_withdrawal(): void {
		$response = Server::send_webhook(
			'customer.updated',
			array(
				'customer_id'      => Responses::MEMBER_ID,
				'marketing_status' => 'blocked',
			),
			'5f0c2a1e-8d4b-4c7a-9e2f-1b3d5a7c9e01'
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'status' => 'processed' ), $response->get_data() );
		$this->assertSame( array(), ContractValidator::validate( 'webhook.response.json', $response->get_data() ) );
		$this->assertFalse( get_transient( SummaryService::key( $this->user_id ) ) );

		$consent = UserMeta::consent( $this->user_id );
		$this->assertNotNull( $consent );
		$this->assertFalse( $consent->value );
		$this->assertSame( 'pixalink', $consent->source );
		$this->assertSame( array(), $this->outbox_types(), 'A withdrawal Pixalink reported is not sent back.' );
	}

	public function test_a_replayed_event_is_acknowledged_without_acting_again(): void {
		$id = '7a1d3c5e-9f2b-4d6a-8c0e-2b4d6f8a0c12';
		$this->assertSame( 'processed', Server::send_webhook( 'voucher.issued', array( 'customer_id' => Responses::MEMBER_ID ), $id )->get_data()['status'] );

		set_transient( SummaryService::key( $this->user_id ), array( 'cached' => true ), 60 );
		$again = Server::send_webhook( 'voucher.issued', array( 'customer_id' => Responses::MEMBER_ID ), $id );

		$this->assertSame( 'duplicate', $again->get_data()['status'] );
		$this->assertNotFalse( get_transient( SummaryService::key( $this->user_id ) ), 'Nothing ran the second time.' );
	}

	public function test_unknown_types_and_strangers_are_ignored(): void {
		$this->assertSame( 'ignored', Server::send_webhook( 'tier.changed', array( 'customer_id' => Responses::MEMBER_ID ) )->get_data()['status'] );
		$this->assertSame( 'ignored', Server::send_webhook( 'voucher.issued', array( 'customer_id' => '999' ) )->get_data()['status'] );
	}

	public function test_forged_stale_and_unsigned_webhooks_are_rejected(): void {
		$id   = '9b1f3c5e-7a2d-4c4b-8e6f-1a3c5e7b9d2f';
		$body = (string) wp_json_encode(
			array(
				'id'          => $id,
				'type'        => 'customer.updated',
				'occurred_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'data'        => array(
					'customer_id'      => Responses::MEMBER_ID,
					'marketing_status' => 'blocked',
				),
			)
		);

		$forged = self::post_raw( $body, Signature::webhook_headers( '2|someone-elses-outlet-key-000000', $id, $body, time() ) );
		$this->assertSame( 401, $forged->get_status() );
		$this->assertSame( 'signature_invalid', $forged->get_data()['code'] );

		$stale = self::post_raw( $body, Signature::webhook_headers( Signature::FAKE_API_KEY, $id, $body, time() - 600 ) );
		$this->assertSame( 401, $stale->get_status() );
		$this->assertSame( 'timestamp_skew', $stale->get_data()['code'] );
		$this->assertIsInt( $stale->get_data()['server_time'] );

		$this->assertSame( 401, self::post_raw( $body, array() )->get_status() );

		$headers = Signature::webhook_headers( Signature::FAKE_API_KEY, $id, $body, time() );
		$this->assertSame( 401, self::post_raw( str_replace( 'blocked', 'subscribed', $body ), $headers )->get_status(), 'A tampered body fails.' );

		$this->assertTrue( UserMeta::consent( $this->user_id )?->value, 'Nothing was applied.' );
		$this->assertNotFalse( get_transient( SummaryService::key( $this->user_id ) ) );
	}

	public function test_a_disconnected_store_answers_503(): void {
		Plugin::instance()->credentials()->clear();

		$response = Server::send_webhook( 'voucher.issued', array( 'customer_id' => Responses::MEMBER_ID ) );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'not_connected', $response->get_data()['code'] );
	}
}
