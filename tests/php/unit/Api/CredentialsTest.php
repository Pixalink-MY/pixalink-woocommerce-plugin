<?php
/**
 * Tests for Credentials.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Api;

use Brain\Monkey;
use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Api\Signer;
use Pixalink\Loyalty\Support\Crypto;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * @covers \Pixalink\Loyalty\Api\Credentials
 */
final class CredentialsTest extends TestCase {

	private const STORED_KEY   = '7|storedstoredstoredstoredstoredstoredst';
	private const CONSTANT_KEY = '9|constantconstantconstantconstantconst';

	/**
	 * Constant values visible to the credentials under test.
	 *
	 * @var array<string,string>
	 */
	private array $constants = array();

	private function credentials( string $salts = 'salts' ): Credentials {
		return new Credentials(
			new Crypto( $salts ),
			new Options(),
			fn ( string $name ) => $this->constants[ $name ] ?? null
		);
	}

	public function test_nothing_configured(): void {
		$credentials = $this->credentials();

		$this->assertNull( $credentials->api_key() );
		$this->assertNull( $credentials->signing_key() );
		$this->assertFalse( $credentials->is_configured() );
		$this->assertSame( Credentials::SOURCE_NONE, $credentials->source() );
	}

	public function test_store_encrypts_with_autoload_off_and_derives_the_signing_key(): void {
		$this->credentials()->store( self::STORED_KEY );

		[ $stored, $autoload ] = $this->options[ Options::CREDENTIALS ];
		$this->assertFalse( $autoload );
		$this->assertIsString( $stored );
		$this->assertStringStartsWith( Crypto::PAYLOAD_PREFIX, $stored );
		$this->assertStringNotContainsString( 'stored', $stored );

		$fresh = $this->credentials();
		$this->assertSame( self::STORED_KEY, $fresh->api_key() );
		$this->assertSame( Signer::derive_signing_key( self::STORED_KEY ), $fresh->signing_key() );
		$this->assertSame( Credentials::SOURCE_OPTION, $fresh->source() );
		$this->assertTrue( $fresh->is_configured() );
		$this->assertFalse( $fresh->is_overridden() );
	}

	public function test_constant_api_key_overrides_the_option_and_never_mixes_the_stored_signing_key(): void {
		$this->credentials()->store( self::STORED_KEY, 'stored-signing-key' );
		$this->constants[ Credentials::API_KEY_CONSTANT ] = self::CONSTANT_KEY;

		$credentials = $this->credentials();

		$this->assertSame( self::CONSTANT_KEY, $credentials->api_key() );
		$this->assertSame( Signer::derive_signing_key( self::CONSTANT_KEY ), $credentials->signing_key() );
		$this->assertSame( Credentials::SOURCE_CONSTANT, $credentials->source() );
		$this->assertTrue( $credentials->is_overridden() );
	}

	public function test_signing_key_constant_wins_over_everything(): void {
		$this->credentials()->store( self::STORED_KEY, 'stored-signing-key' );
		$this->constants[ Credentials::API_KEY_CONSTANT ]     = self::CONSTANT_KEY;
		$this->constants[ Credentials::SIGNING_KEY_CONSTANT ] = 'constant-signing-key';

		$this->assertSame( 'constant-signing-key', $this->credentials()->signing_key() );
	}

	public function test_stored_signing_key_is_used_when_only_the_option_is_set(): void {
		$this->credentials()->store( self::STORED_KEY, 'stored-signing-key' );

		$this->assertSame( 'stored-signing-key', $this->credentials()->signing_key() );
	}

	public function test_blank_constants_are_ignored(): void {
		$this->credentials()->store( self::STORED_KEY );
		$this->constants[ Credentials::API_KEY_CONSTANT ] = '   ';

		$this->assertSame( self::STORED_KEY, $this->credentials()->api_key() );
	}

	public function test_rotated_salts_make_stored_credentials_unreadable(): void {
		$this->credentials( 'old-salts' )->store( self::STORED_KEY );

		$credentials = $this->credentials( 'new-salts' );

		$this->assertNull( $credentials->api_key() );
		$this->assertSame( Credentials::SOURCE_NONE, $credentials->source() );
	}

	public function test_clear_removes_the_option_but_not_constants(): void {
		$credentials = $this->credentials();
		$credentials->store( self::STORED_KEY );
		$credentials->clear();

		$this->assertArrayNotHasKey( Options::CREDENTIALS, $this->options );
		$this->assertNull( $credentials->api_key() );

		$this->constants[ Credentials::API_KEY_CONSTANT ] = self::CONSTANT_KEY;
		$credentials->clear();
		$this->assertSame( self::CONSTANT_KEY, $credentials->api_key() );
	}

	public function test_store_rejects_an_empty_key(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->credentials()->store( '  ' );
	}

	/**
	 * The default reader resolves real wp-config constants.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_default_constant_reader_uses_wp_config_constants(): void {
		define( 'PIXALINK_LOYALTY_API_KEY', self::CONSTANT_KEY );

		$credentials = new Credentials( new Crypto( 'salts' ), new Options() );

		$this->assertSame( self::CONSTANT_KEY, $credentials->api_key() );
		$this->assertSame( Credentials::SOURCE_CONSTANT, $credentials->source() );
		Monkey\tearDown();
	}
}
