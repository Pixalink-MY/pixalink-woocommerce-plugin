<?php
/**
 * Contract: plugin signer vs the fake server's independent verifier.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Contract;

use Pixalink\Loyalty\Api\Signer;
use Pixalink\Loyalty\Tests\FakeServer\Signature;
use Pixalink\Loyalty\Tests\Unit\TestCase;
use Pixalink\Loyalty\Webhooks\InboundController;

/**
 * Requests signed by the plugin's Signer verify under an independent implementation of the server rules.
 *
 * @covers \Pixalink\Loyalty\Tests\FakeServer\Signature
 * @covers \Pixalink\Loyalty\Webhooks\InboundController::verify_signature
 */
final class SignatureCompatibilityTest extends TestCase {

	private const NOW = 1761300000;

	/**
	 * Headers the plugin's Client would send.
	 *
	 * @param string      $method   HTTP method.
	 * @param string      $path     Relative path.
	 * @param string      $raw_body Body.
	 * @param string|null $nonce    Event id or idempotency key (null for reads).
	 * @param int         $ts       Timestamp.
	 * @return array<string,string>
	 */
	private function signed_headers( string $method, string $path, string $raw_body, ?string $nonce, int $ts = self::NOW ): array {
		$headers = array(
			'authorization'        => 'Bearer ' . Signature::FAKE_API_KEY,
			'x-pixalink-timestamp' => (string) $ts,
		);

		$signed_nonce = $nonce ?? Signer::READ_NONCE;
		if ( 'events' === $path ) {
			$headers['x-pixalink-event-id'] = (string) $nonce;
		} elseif ( null !== $nonce ) {
			$headers['idempotency-key'] = $nonce;
		}

		$headers['x-pixalink-signature'] = Signer::header_value( Signer::derive_signing_key( Signature::FAKE_API_KEY ), $ts, $signed_nonce, $raw_body );
		unset( $method );

		return $headers;
	}

	public function test_both_implementations_derive_the_same_signing_key(): void {
		$this->assertSame( Signer::derive_signing_key( Signature::FAKE_API_KEY ), Signature::signing_key( Signature::FAKE_API_KEY ) );
		$this->assertSame( '17e1b3c793c2e4ca4eca73b5d6bc40a796b21d9a7ba3241d836ff57ea64001a0', Signature::signing_key( '1|pixalink-commerce-test-vector-token-0001' ) );
	}

	public function test_signed_event_verifies(): void {
		$body = '{"id":"a6417789-0a05-5068-bab0-90d991946d97","type":"ping"}';

		$this->assertNull( Signature::check( 'POST', 'events', $this->signed_headers( 'POST', 'events', $body, 'a6417789-0a05-5068-bab0-90d991946d97' ), $body, self::NOW, Signature::FAKE_API_KEY ) );
	}

	public function test_signed_mutation_and_read_verify(): void {
		$body = '{"pending":0}';

		$this->assertNull( Signature::check( 'POST', 'connection/heartbeat', $this->signed_headers( 'POST', 'connection/heartbeat', $body, 'idem-0000000000000001' ), $body, self::NOW, Signature::FAKE_API_KEY ) );
		$this->assertNull( Signature::check( 'GET', 'outlet', $this->signed_headers( 'GET', 'outlet', '', null ), '', self::NOW, Signature::FAKE_API_KEY ) );
	}

	public function test_server_accepts_a_bare_hex_signature(): void {
		$headers = $this->signed_headers( 'POST', 'quote', '{}', 'idem-0000000000000002' );

		$headers['x-pixalink-signature'] = substr( $headers['x-pixalink-signature'], 3 );

		$this->assertNull( Signature::check( 'POST', 'quote', $headers, '{}', self::NOW, Signature::FAKE_API_KEY ) );
	}

	public function test_rejections(): void {
		$body    = '{"a":1}';
		$headers = $this->signed_headers( 'POST', 'quote', $body, 'idem-0000000000000003' );

		$this->assertSame( array( 'signature_invalid', 401 ), Signature::check( 'POST', 'quote', $headers, '{"a":2}', self::NOW, Signature::FAKE_API_KEY ) );
		$this->assertSame( array( 'timestamp_skew', 401 ), Signature::check( 'POST', 'quote', $headers, $body, self::NOW + 301, Signature::FAKE_API_KEY ) );
		$this->assertSame( array( 'unauthenticated', 401 ), Signature::check( 'POST', 'quote', $headers, $body, self::NOW, '2|another-outlet-key-0000000000' ) );

		$short = $this->signed_headers( 'POST', 'quote', $body, 'short' );
		$this->assertSame( array( 'signature_invalid', 401 ), Signature::check( 'POST', 'quote', $short, $body, self::NOW, Signature::FAKE_API_KEY ) );

		$wrong_nonce_header = $headers;
		unset( $wrong_nonce_header['idempotency-key'] );
		$wrong_nonce_header['x-pixalink-event-id'] = 'idem-0000000000000003';
		$this->assertSame( array( 'signature_invalid', 401 ), Signature::check( 'POST', 'quote', $wrong_nonce_header, $body, self::NOW, Signature::FAKE_API_KEY ) );
	}

	/**
	 * Header values the store's webhook route reads, from the fake server's independent signer.
	 *
	 * @param string $event_id Event id.
	 * @param string $raw_body Body.
	 * @param int    $ts       Timestamp.
	 * @return array{timestamp:string,event_id:string,signature:string}
	 */
	private static function webhook_headers( string $event_id, string $raw_body, int $ts = self::NOW ): array {
		$headers = Signature::webhook_headers( Signature::FAKE_API_KEY, $event_id, $raw_body, $ts );

		return array(
			'timestamp' => $headers['x-pixalink-timestamp'],
			'event_id'  => $headers['x-pixalink-event-id'],
			'signature' => $headers['x-pixalink-signature'],
		);
	}

	public function test_webhooks_signed_by_pixalink_verify_in_the_store(): void {
		$key  = Signer::derive_signing_key( Signature::FAKE_API_KEY );
		$id   = '5f0c2a1e-8d4b-4c7a-9e2f-1b3d5a7c9e01';
		$body = '{"id":"' . $id . '","type":"customer.updated","occurred_at":"2026-10-25T02:00:00Z","data":{"customer_id":"5001"}}';

		$this->assertNull( InboundController::verify_signature( self::webhook_headers( $id, $body ), $body, $key, self::NOW ) );
		$this->assertNull( InboundController::verify_signature( self::webhook_headers( $id, $body, self::NOW - 300 ), $body, $key, self::NOW ), 'Edge of the window.' );
	}

	public function test_webhook_rejections(): void {
		$key  = Signer::derive_signing_key( Signature::FAKE_API_KEY );
		$id   = '5f0c2a1e-8d4b-4c7a-9e2f-1b3d5a7c9e01';
		$body = '{"id":"' . $id . '","type":"voucher.issued","data":{"customer_id":"5001"}}';
		$good = self::webhook_headers( $id, $body );

		$this->assertSame( 'signature_invalid', InboundController::verify_signature( $good, str_replace( '5001', '5002', $body ), $key, self::NOW ), 'Tampered body.' );
		$this->assertSame( 'signature_invalid', InboundController::verify_signature( $good, $body, Signer::derive_signing_key( '2|another-outlet-key-0000000000' ), self::NOW ), 'Another outlet.' );
		$this->assertSame( 'timestamp_skew', InboundController::verify_signature( $good, $body, $key, self::NOW + 301 ) );
		$this->assertSame( 'signature_invalid', InboundController::verify_signature( array( 'event_id' => 'other-event-id-0000001' ) + $good, $body, $key, self::NOW ), 'The event id is signed.' );
		$this->assertSame( 'signature_invalid', InboundController::verify_signature( array( 'event_id' => 'bad.id' ) + $good, $body, $key, self::NOW ) );
		$this->assertSame( 'signature_invalid', InboundController::verify_signature( array( 'timestamp' => '' ) + $good, $body, $key, self::NOW ) );
		$this->assertSame( 'signature_invalid', InboundController::verify_signature( array( 'signature' => substr( $good['signature'], 3 ) ) + $good, $body, $key, self::NOW ), 'The scheme prefix is required.' );
		$this->assertSame( 'signature_invalid', InboundController::verify_signature( array( 'signature' => '' ) + $good, $body, $key, self::NOW ) );
	}
}
