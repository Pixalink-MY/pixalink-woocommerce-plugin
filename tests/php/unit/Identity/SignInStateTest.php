<?php
/**
 * Tests for SignInState.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Identity;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Identity\SignInState;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * The check value behind "Sign in with the member portal": 43 URL-safe characters from 32 random bytes, kept in a
 * short-lived HttpOnly, SameSite=Lax cookie, read back only when it has the contract's shape, and gone once taken.
 *
 * @covers \Pixalink\Loyalty\Identity\SignInState
 */
final class SignInStateTest extends TestCase {

	/**
	 * Cookies the class set: [name, value, options].
	 *
	 * @var list<array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $cookies = array();

	protected function set_up(): void {
		parent::set_up();

		$_COOKIE       = array();
		$this->cookies = array();
		Functions\when( 'is_ssl' )->justReturn( true );
	}

	protected function tear_down(): void {
		$_COOKIE = array();

		parent::tear_down();
	}

	private function state( int $now = 1761300000 ): SignInState {
		return new SignInState(
			function ( string $name, string $value, array $options ): void {
				$this->cookies[] = array( $name, $value, $options );
			},
			static fn (): int => $now
		);
	}

	public function test_a_state_is_43_url_safe_characters_and_never_repeats(): void {
		$seen = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$state = SignInState::generate();

			$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/D', $state, '32 random bytes, unpadded base64url.' );
			$this->assertMatchesRegularExpression( SignInState::PATTERN, $state, 'The contract accepts it as sso_state.' );
			$seen[ $state ] = true;
		}

		$this->assertCount( 50, $seen );
	}

	public function test_issuing_a_state_sets_a_ten_minute_httponly_lax_cookie(): void {
		$state = $this->state()->issue();

		$this->assertCount( 1, $this->cookies );
		[ $name, $value, $options ] = $this->cookies[0];
		$this->assertSame( SignInState::COOKIE, $name );
		$this->assertSame( 'pixalink_loyalty_sso_state', $name );
		$this->assertSame( $state, $value );
		$this->assertSame( 1761300000 + 600, $options['expires'] );
		$this->assertTrue( $options['httponly'] );
		$this->assertTrue( $options['secure'], 'Secure on https.' );
		$this->assertSame( 'Lax', $options['samesite'] );
		$this->assertSame( COOKIEPATH, $options['path'] );
		$this->assertSame( '', $options['domain'], 'WordPress leaves COOKIE_DOMAIN false: a host-only cookie.' );
	}

	public function test_the_cookie_is_not_secure_on_plain_http(): void {
		Functions\when( 'is_ssl' )->justReturn( false );

		$this->state()->issue();

		$this->assertFalse( $this->cookies[0][2]['secure'] );
	}

	public function test_taking_the_state_returns_it_once_and_expires_the_cookie(): void {
		$_COOKIE[ SignInState::COOKIE ] = 'q8VJ2nX5rT1wZ7bD4fH9kM3pS6uY0aC2eG5iL8oR1tW4';
		$state                          = $this->state();

		$this->assertSame( 'q8VJ2nX5rT1wZ7bD4fH9kM3pS6uY0aC2eG5iL8oR1tW4', $state->take() );
		$this->assertArrayNotHasKey( SignInState::COOKIE, $_COOKIE );
		$this->assertCount( 1, $this->cookies );
		$this->assertSame( '', $this->cookies[0][1] );
		$this->assertLessThan( 1761300000, $this->cookies[0][2]['expires'], 'Expired, so the browser drops it.' );
		$this->assertTrue( $this->cookies[0][2]['httponly'] );

		$this->assertNull( $state->take(), 'Gone once taken.' );
		$this->assertCount( 1, $this->cookies, 'Nothing to expire the second time.' );
	}

	/**
	 * Cookie values that are no state the plugin issued.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function bad_cookies(): array {
		return array(
			'too short'      => array( 'abc' ),
			'padding'        => array( str_repeat( 'a', 42 ) . '=' ),
			'spaces'         => array( str_repeat( 'a', 40 ) . ' bc' ),
			'too long'       => array( str_repeat( 'a', 129 ) ),
			'array'          => array( array( 'q8VJ2nX5rT1wZ7bD4fH9kM3pS6uY0aC2eG5iL8oR1tW4' ) ),
			'trailing break' => array( "q8VJ2nX5rT1wZ7bD4fH9kM3pS6uY0aC2eG5iL8oR1tW4\n" ),
		);
	}

	/**
	 * @dataProvider bad_cookies
	 *
	 * @param mixed $value Cookie value.
	 */
	public function test_a_malformed_cookie_is_never_sent_but_still_expired( $value ): void {
		$_COOKIE[ SignInState::COOKIE ] = $value;

		$this->assertNull( $this->state()->take() );
		$this->assertCount( 1, $this->cookies, 'The bad cookie is cleared all the same.' );
	}

	public function test_without_a_cookie_nothing_is_taken_or_set(): void {
		$this->assertNull( $this->state()->take() );
		$this->assertSame( array(), $this->cookies );
	}
}
