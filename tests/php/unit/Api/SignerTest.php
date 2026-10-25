<?php
/**
 * Tests for Signer.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Api;

use Pixalink\Loyalty\Api\Signer;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * Pins the signing contract shared with the Pixalink server.
 *
 * The expected values were computed independently with Python's hmac/hashlib; the server's
 * CommerceEventSignature test must pin the same vector.
 *
 * @covers \Pixalink\Loyalty\Api\Signer
 */
final class SignerTest extends TestCase {

	private const API_KEY     = '1|abcdefghijklmnopqrstuvwxyz0123456789ABCD';
	private const TIMESTAMP   = 1761300000;
	private const NONCE       = 'evt-00000000-0000-4000-8000-000000000001';
	private const BODY        = '{"a":1}';
	private const SIGNING_KEY = '5e8acd03c512288352360298f68daa81895f12174ab510a4715d1dc6bbcdf27a';
	private const SIGNATURE   = 'v1=82f883fca7230c8941465a33caeff1d8464b89e020cac7a701aa3a23abaa9539';

	public function test_signing_key_is_derived_from_the_token_secret_hash(): void {
		$this->assertSame( 'abcdefghijklmnopqrstuvwxyz0123456789ABCD', Signer::token_secret( self::API_KEY ) );
		$this->assertSame( '86cd2d2a95a72152010970f791b1f8e592a70efc2e988a4830937842ff1c7b85', hash( 'sha256', Signer::token_secret( self::API_KEY ) ) );
		$this->assertSame( self::SIGNING_KEY, Signer::derive_signing_key( self::API_KEY ) );
	}

	public function test_signature_matches_the_cross_repo_vector(): void {
		$this->assertSame( '1761300000.evt-00000000-0000-4000-8000-000000000001.{"a":1}', Signer::string_to_sign( self::TIMESTAMP, self::NONCE, self::BODY ) );
		$this->assertSame( self::SIGNATURE, Signer::header_value( self::SIGNING_KEY, self::TIMESTAMP, self::NONCE, self::BODY ) );
	}

	public function test_signature_matches_the_server_teams_pinned_vector(): void {
		// Pinned by explore's App\Support\Commerce\CommerceEventSignature test (branch feat/commerce-x2-outlet-auth).
		$api_key = '1|pixalink-commerce-test-vector-token-0001';
		$nonce   = 'evt-0000000000000001';
		$body    = '{"id":"evt-0000000000000001","type":"ping"}';

		$this->assertSame( 'c70ac131c8478d57ae527c81d6e3ff17a93c9f54c1a39db67e1fc37a7ff2abc4', hash( 'sha256', Signer::token_secret( $api_key ) ) );
		$signing_key = Signer::derive_signing_key( $api_key );
		$this->assertSame( '17e1b3c793c2e4ca4eca73b5d6bc40a796b21d9a7ba3241d836ff57ea64001a0', $signing_key );
		$this->assertSame( '1761300000.evt-0000000000000001.' . $body, Signer::string_to_sign( self::TIMESTAMP, $nonce, $body ) );
		$this->assertSame(
			'v1=8c95a624c8fa5d76883f8d94595fca401715f7f1808af51c917ec5c812930bf4',
			Signer::header_value( $signing_key, self::TIMESTAMP, $nonce, $body )
		);
	}

	/**
	 * @dataProvider nonce_cases
	 *
	 * @param string $nonce    Candidate.
	 * @param bool   $expected Whether the server accepts it.
	 */
	public function test_nonce_format_matches_the_server_rule( string $nonce, bool $expected ): void {
		$this->assertSame( $expected, Signer::is_valid_nonce( $nonce ) );
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function nonce_cases(): array {
		return array(
			'uuid v4'          => array( '3f2c1a9e-8d4b-4e1f-9a6c-2b7d5e8f0a1c', true ),
			'uuid v5'          => array( 'a6417789-0a05-5068-bab0-90d991946d97', true ),
			'16 chars'         => array( 'evt-000000000001', true ),
			'64 chars'         => array( str_repeat( 'a', 64 ), true ),
			'too short'        => array( 'evt-00000000001', false ),
			'too long'         => array( str_repeat( 'a', 65 ), false ),
			'dot'              => array( 'evt.0000000000000001', false ),
			'underscore'       => array( 'evt_0000000000000001', false ),
			'read dash nonce'  => array( Signer::READ_NONCE, false ),
		);
	}

	public function test_read_requests_sign_an_empty_body_with_the_dash_nonce(): void {
		$this->assertSame(
			'v1=58770e9c16ac72c17ec9a24ab4fe0b61bbe1cd230276ffa23184b16db5be1047',
			Signer::header_value( self::SIGNING_KEY, self::TIMESTAMP, Signer::READ_NONCE, '' )
		);
	}

	public function test_key_without_separator_uses_the_whole_key(): void {
		$this->assertSame( 'plainkey', Signer::token_secret( 'plainkey' ) );
		$this->assertSame( 'b|c', Signer::token_secret( 'a|b|c' ) );
	}

	public function test_verify_accepts_within_the_window_and_rejects_outside_it(): void {
		$this->assertTrue( Signer::verify( self::SIGNING_KEY, self::SIGNATURE, self::TIMESTAMP, self::NONCE, self::BODY, self::TIMESTAMP + 300 ) );
		$this->assertTrue( Signer::verify( self::SIGNING_KEY, self::SIGNATURE, self::TIMESTAMP, self::NONCE, self::BODY, self::TIMESTAMP - 300 ) );
		$this->assertFalse( Signer::verify( self::SIGNING_KEY, self::SIGNATURE, self::TIMESTAMP, self::NONCE, self::BODY, self::TIMESTAMP + 301 ) );
	}

	public function test_verify_rejects_tampering(): void {
		$now = self::TIMESTAMP;

		$this->assertFalse( Signer::verify( self::SIGNING_KEY, self::SIGNATURE, self::TIMESTAMP, self::NONCE, '{"a":2}', $now ) );
		$this->assertFalse( Signer::verify( self::SIGNING_KEY, self::SIGNATURE, self::TIMESTAMP, 'other-nonce', self::BODY, $now ) );
		$this->assertFalse( Signer::verify( self::SIGNING_KEY, self::SIGNATURE, self::TIMESTAMP + 1, self::NONCE, self::BODY, $now ) );
		$this->assertFalse( Signer::verify( Signer::derive_signing_key( '2|other' ), self::SIGNATURE, self::TIMESTAMP, self::NONCE, self::BODY, $now ) );
	}
}
