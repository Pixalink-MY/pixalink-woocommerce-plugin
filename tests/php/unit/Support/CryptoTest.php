<?php
/**
 * Tests for Crypto.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Support;

use Pixalink\Loyalty\Support\Crypto;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * @covers \Pixalink\Loyalty\Support\Crypto
 */
final class CryptoTest extends TestCase {

	public function test_round_trip(): void {
		$crypto = new Crypto( 'logged-in-key' . 'logged-in-salt' );

		$this->assertSame( '1|secret-api-key', $crypto->decrypt( $crypto->encrypt( '1|secret-api-key' ) ) );
		$this->assertSame( '', $crypto->decrypt( $crypto->encrypt( '' ) ) );
	}

	public function test_ciphertext_is_prefixed_and_uses_a_fresh_nonce(): void {
		$crypto = new Crypto( 'material' );

		$first  = $crypto->encrypt( 'same plaintext' );
		$second = $crypto->encrypt( 'same plaintext' );

		$this->assertStringStartsWith( Crypto::PAYLOAD_PREFIX, $first );
		$this->assertNotSame( $first, $second );
		$this->assertStringNotContainsString( 'same plaintext', $first );
	}

	public function test_a_different_key_cannot_decrypt(): void {
		$payload = ( new Crypto( 'old-salts' ) )->encrypt( 'secret' );

		$this->assertNull( ( new Crypto( 'rotated-salts' ) )->decrypt( $payload ) );
	}

	public function test_tampered_or_malformed_payloads_return_null(): void {
		$crypto  = new Crypto( 'material' );
		$payload = $crypto->encrypt( 'secret' );
		$decoded = base64_decode( substr( $payload, strlen( Crypto::PAYLOAD_PREFIX ) ) );
		$flipped = $decoded;

		$flipped[ strlen( $flipped ) - 1 ] = chr( ord( $flipped[ strlen( $flipped ) - 1 ] ) ^ 0x01 );

		$this->assertNull( $crypto->decrypt( Crypto::PAYLOAD_PREFIX . base64_encode( $flipped ) ) );
		$this->assertNull( $crypto->decrypt( 'plaintext-without-prefix' ) );
		$this->assertNull( $crypto->decrypt( Crypto::PAYLOAD_PREFIX . '%%%not-base64%%%' ) );
		$this->assertNull( $crypto->decrypt( Crypto::PAYLOAD_PREFIX . base64_encode( 'short' ) ) );
	}

	public function test_default_key_material_comes_from_wp_salt_when_constants_are_missing(): void {
		\Brain\Monkey\Functions\expect( 'wp_salt' )->once()->with( 'logged_in' )->andReturn( 'salt-from-db' );

		$this->assertSame( 'salt-from-db', Crypto::default_key_material() );
	}

	public function test_is_available_on_this_php_build(): void {
		$this->assertTrue( Crypto::is_available() );
	}
}
