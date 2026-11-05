<?php
/**
 * Tests for AuthorizationRequest.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Oauth;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Connect\PkceState;
use Pixalink\Loyalty\Oauth\AuthorizationRequest;
use Pixalink\Loyalty\Oauth\Capability;
use Pixalink\Loyalty\Support\Crypto;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * One sign-in through the member portal: a random state and PKCE verifier kept, encrypted, in a ten-minute HttpOnly
 * SameSite=Lax cookie (so it works for shoppers who are not logged in), an authorize URL that carries only the state and
 * the S256 challenge, and a cookie that is read back once, and only when it is ours, untampered and unexpired.
 *
 * @covers \Pixalink\Loyalty\Oauth\AuthorizationRequest
 */
final class AuthorizationRequestTest extends TestCase {

	private const AUTHORIZE = 'https://demo.pixalink.io/l/pixalink-bookstore/oauth/authorize';

	/**
	 * Cookies the class set: [name, value, options].
	 *
	 * @var list<array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $cookies = array();

	/**
	 * Unix time the class sees.
	 *
	 * @var int
	 */
	private int $now = 1761300000;

	protected function set_up(): void {
		parent::set_up();

		$_COOKIE       = array();
		$this->cookies = array();
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( static fn ( string $url, int $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}

	protected function tear_down(): void {
		$_COOKIE = array();

		parent::tear_down();
	}

	private function request( string $salts = 'salts' ): AuthorizationRequest {
		return new AuthorizationRequest(
			new Crypto( $salts ),
			function ( string $name, string $value, array $options ): void {
				$this->cookies[] = array( $name, $value, $options );
			},
			fn (): int => $this->now
		);
	}

	private static function capability(): Capability {
		return new Capability( self::AUTHORIZE, 'https://explore.pixalink.io/oauth/customer/token', 'https://explore.pixalink.io/oauth/customer/revoke' );
	}

	/**
	 * Query arguments of a URL.
	 *
	 * @param string $url URL.
	 * @return array<string,mixed>
	 */
	private static function query( string $url ): array {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

		return $query;
	}

	/**
	 * The browser comes back: the cookie the class set is sent with the request.
	 *
	 * @return void
	 */
	private function come_back(): void {
		$_COOKIE[ AuthorizationRequest::COOKIE ] = $this->cookies[0][1];
		$this->cookies                           = array();
	}

	public function test_starting_sends_the_browser_to_authorize_with_state_and_an_s256_challenge(): void {
		$url = $this->request()->start( self::capability(), 'client-uuid', 'https://shop.example.test/?pixalink_oauth=callback', AuthorizationRequest::PURPOSE_SIGN_IN, 'https://shop.example.test/my-account/', 0 );

		$this->assertStringStartsWith( self::AUTHORIZE . '?', $url );
		$query = self::query( $url );
		$this->assertSame( array( 'response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method' ), array_keys( $query ) );
		$this->assertSame( 'code', $query['response_type'] );
		$this->assertSame( 'client-uuid', $query['client_id'] );
		$this->assertSame( 'https://shop.example.test/?pixalink_oauth=callback', $query['redirect_uri'] );
		$this->assertSame( 'profile store:link', $query['scope'] );
		$this->assertStringContainsString( 'scope=profile%20store%3Alink', $url, 'RFC 3986 encoding: a space is %20.' );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/D', $query['state'], '32 random bytes, base64url.' );
		$this->assertSame( 'S256', $query['code_challenge_method'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/D', $query['code_challenge'] );

		$this->come_back();
		$taken = $this->request()->take();
		$this->assertNotNull( $taken );
		$this->assertSame( $query['state'], $taken['state'], 'The state in the URL is the one in the cookie.' );
		$this->assertSame( PkceState::challenge( $taken['verifier'] ), $query['code_challenge'], 'The challenge is S256 of the kept verifier.' );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43,128}$/D', $taken['verifier'] );
		$this->assertSame( AuthorizationRequest::PURPOSE_SIGN_IN, $taken['purpose'] );
		$this->assertSame( 'https://shop.example.test/my-account/', $taken['return'] );
		$this->assertSame( 0, $taken['user_id'] );
	}

	public function test_an_authorize_endpoint_with_a_query_keeps_it(): void {
		$capability = new Capability( self::AUTHORIZE . '?lang=ms', 'https://x.test/t', 'https://x.test/r' );

		$url = $this->request()->start( $capability, 'c', 'https://shop.example.test/?pixalink_oauth=callback', AuthorizationRequest::PURPOSE_SIGN_IN, 'https://shop.example.test/', 0 );

		$this->assertStringStartsWith( self::AUTHORIZE . '?lang=ms&response_type=code&', $url );
	}

	public function test_the_cookie_is_encrypted_httponly_lax_secure_and_lives_ten_minutes(): void {
		$this->request()->start( self::capability(), 'client-uuid', 'https://shop.example.test/?pixalink_oauth=callback', AuthorizationRequest::PURPOSE_VERIFY, 'https://shop.example.test/my-account/pixalink-loyalty/', 17 );

		$this->assertCount( 1, $this->cookies );
		[ $name, $value, $options ] = $this->cookies[0];
		$this->assertSame( 'pixalink_loyalty_oauth', $name );
		$this->assertStringStartsWith( Crypto::PAYLOAD_PREFIX, $value );
		$this->assertStringNotContainsString( 'verifier', $value, 'Nothing readable in the browser.' );
		$this->assertStringNotContainsString( 'pixalink-loyalty', $value );
		$this->assertSame( $this->now + 600, $options['expires'] );
		$this->assertTrue( $options['httponly'] );
		$this->assertTrue( $options['secure'] );
		$this->assertSame( 'Lax', $options['samesite'], 'Lax: it comes back on the portal\'s top-level redirect.' );
		$this->assertSame( COOKIEPATH, $options['path'] );
		$this->assertSame( '', $options['domain'] );

		$this->come_back();
		$taken = $this->request()->take();
		$this->assertSame( AuthorizationRequest::PURPOSE_VERIFY, $taken['purpose'] ?? null );
		$this->assertSame( 17, $taken['user_id'] ?? null );
	}

	public function test_each_start_has_a_new_state_and_verifier(): void {
		$first  = self::query( $this->request()->start( self::capability(), 'c', 'https://shop.example.test/?pixalink_oauth=callback', AuthorizationRequest::PURPOSE_SIGN_IN, 'https://shop.example.test/', 0 ) );
		$second = self::query( $this->request()->start( self::capability(), 'c', 'https://shop.example.test/?pixalink_oauth=callback', AuthorizationRequest::PURPOSE_SIGN_IN, 'https://shop.example.test/', 0 ) );

		$this->assertNotSame( $first['state'], $second['state'] );
		$this->assertNotSame( $first['code_challenge'], $second['code_challenge'] );
	}

	public function test_taking_clears_the_cookie_so_it_works_once(): void {
		$this->request()->start( self::capability(), 'c', 'https://shop.example.test/?pixalink_oauth=callback', AuthorizationRequest::PURPOSE_SIGN_IN, 'https://shop.example.test/', 0 );
		$this->come_back();

		$this->assertNotNull( $this->request()->take() );
		$this->assertArrayNotHasKey( AuthorizationRequest::COOKIE, $_COOKIE );
		$this->assertCount( 1, $this->cookies );
		$this->assertSame( array( AuthorizationRequest::COOKIE, '' ), array_slice( $this->cookies[0], 0, 2 ) );
		$this->assertLessThan( $this->now, $this->cookies[0][2]['expires'], 'Expired in the browser too.' );
		$this->assertNull( $this->request()->take(), 'Nothing left to take.' );
	}

	public function test_an_expired_request_is_refused_even_if_the_browser_kept_the_cookie(): void {
		$this->request()->start( self::capability(), 'c', 'https://shop.example.test/?pixalink_oauth=callback', AuthorizationRequest::PURPOSE_SIGN_IN, 'https://shop.example.test/', 0 );
		$this->come_back();
		$this->now += 601;

		$this->assertNull( $this->request()->take() );
		$this->assertSame( '', $this->cookies[0][1] ?? null, 'Cleared all the same.' );
	}

	/**
	 * Cookies the class did not write, or that were changed.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function foreign_cookies(): array {
		return array(
			'plain json'     => array( '{"state":"x","verifier":"y","purpose":"sign_in","return":"https://shop.example.test/","user_id":0,"expires":9999999999}' ),
			'garbage'        => array( 'pxl1:not-base64!' ),
			'not a string'   => array( array( 'state' => 'x' ) ),
			'empty'          => array( '' ),
		);
	}

	/**
	 * @dataProvider foreign_cookies
	 *
	 * @param mixed $value Cookie value.
	 */
	public function test_a_cookie_the_store_did_not_write_is_refused( $value ): void {
		$_COOKIE[ AuthorizationRequest::COOKIE ] = $value;

		$this->assertNull( $this->request()->take() );
	}

	public function test_a_tampered_cookie_is_refused(): void {
		$this->request()->start( self::capability(), 'c', 'https://shop.example.test/?pixalink_oauth=callback', AuthorizationRequest::PURPOSE_SIGN_IN, 'https://shop.example.test/', 0 );
		$value = $this->cookies[0][1];
		$this->come_back();
		$_COOKIE[ AuthorizationRequest::COOKIE ] = substr( $value, 0, -4 ) . ( 'AAAA' === substr( $value, -4 ) ? 'BBBB' : 'AAAA' );

		$this->assertNull( $this->request()->take() );
	}

	public function test_a_cookie_from_another_site_key_is_refused(): void {
		$this->request( 'other-site-salts' )->start( self::capability(), 'c', 'https://shop.example.test/?pixalink_oauth=callback', AuthorizationRequest::PURPOSE_SIGN_IN, 'https://shop.example.test/', 0 );
		$this->come_back();

		$this->assertNull( $this->request()->take() );
	}

	public function test_no_cookie_means_no_request(): void {
		$this->assertNull( $this->request()->take() );
		$this->assertSame( array(), $this->cookies, 'Nothing to clear.' );
	}
}
