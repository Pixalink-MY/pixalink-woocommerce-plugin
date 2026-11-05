<?php
/**
 * Integration tests for PortalSignIn.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Identity;

use Pixalink\Loyalty\Account\Assets;
use Pixalink\Loyalty\Connect\Connection;
use Pixalink\Loyalty\Identity\Link;
use Pixalink\Loyalty\Identity\PortalSignIn;
use Pixalink\Loyalty\Identity\SignInState;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Oauth\AuthorizationRequest;
use Pixalink\Loyalty\Oauth\ClientCredentials;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\FakeServer\Responses;
use Pixalink\Loyalty\Tests\FakeServer\Server;
use Pixalink\Loyalty\Tests\Integration\Support\StoreFixtures;

/**
 * "Sign in with the member portal": the button on the My Account and classic checkout login forms (only for
 * logged-out shoppers, while the setting is on, the store is connected and the heartbeat named the portal's sign-in
 * page or advertised OAuth), and the start handler that keeps a random state in a short-lived cookie and sends the
 * browser to the portal with the page to come back to. With OAuth (and the store's client provisioned) it goes to the
 * authorize page instead, and logged-in shoppers who are not linked get "Verify with WhatsApp" the same way.
 *
 * @covers \Pixalink\Loyalty\Identity\PortalSignIn
 * @covers \Pixalink\Loyalty\Oauth\AuthorizationRequest
 * @covers \Pixalink\Loyalty\Identity\SignInState
 * @covers \Pixalink\Loyalty\Account\Assets::enqueue_sign_in_style
 */
final class PortalSignInTest extends \WP_UnitTestCase {

	use StoreFixtures;

	/**
	 * Header lines the handler sent.
	 *
	 * @var list<string>
	 */
	private array $headers = array();

	/**
	 * Redirects: [location, status].
	 *
	 * @var list<array{0:string,1:int}>
	 */
	private array $redirects = array();

	/**
	 * Cookies set: [name, value, options].
	 *
	 * @var list<array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $cookies = array();

	/**
	 * My Account page id.
	 *
	 * @var int
	 */
	private int $account_page = 0;

	public function set_up(): void {
		parent::set_up();

		$this->set_up_store();
		$this->account_page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'My account',
				'post_name'    => 'my-account',
				'post_content' => '[woocommerce_my_account]',
			)
		);
		update_option( 'woocommerce_myaccount_page_id', $this->account_page );
		delete_option( Options::PORTAL_SIGN_IN );
		delete_option( Options::PORTAL_BRANDING );
		$this->heartbeat();
		$_GET                   = array();
		$_COOKIE                = array();
		$_SERVER['REQUEST_URI'] = '/my-account/';
		$GLOBALS['wp_styles']   = null;
		wp_set_current_user( 0 );

		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10 );
		delete_option( Options::REMOTE_STATUS );
		delete_option( Options::OAUTH_CLIENT );
		delete_option( Options::PORTAL_SIGN_IN );
		delete_option( Options::PORTAL_BRANDING );
		$_GET                   = array();
		$_COOKIE                = array();
		$_SERVER['REQUEST_URI'] = '/';
		$GLOBALS['wp_styles']   = null;
		$this->tear_down_store();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * `wp_redirect` filter: record, and stop WordPress from sending the Location header in the test process.
	 *
	 * @param mixed $location Location.
	 * @param mixed $status   Status.
	 * @return false
	 */
	public function capture_redirect( $location, $status ): bool {
		$this->redirects[] = array( (string) $location, (int) $status );

		return false;
	}

	/**
	 * Cache the fake server's heartbeat.
	 *
	 * @param callable|null $change Changes the branding block before it is cached (receives it by reference).
	 * @return void
	 */
	private function heartbeat( ?callable $change = null ): void {
		$status = Responses::samples( time() )['heartbeat']['response']['body'];
		if ( null !== $change ) {
			$change( $status['branding'] );
		}
		( new Connection( new Options() ) )->store_remote_status( $status );
	}

	/**
	 * The handler with captured headers and cookies, and no exit.
	 *
	 * @return PortalSignIn
	 */
	private function sign_in(): PortalSignIn {
		return new PortalSignIn(
			new Options(),
			Plugin::instance()->credentials(),
			new SignInState(
				function ( string $name, string $value, array $options ): void {
					$this->cookies[] = array( $name, $value, $options );
				}
			),
			function ( string $line ): void {
				$this->headers[] = $line;
			},
			static function (): void {},
			$this->oauth_client(),
			new AuthorizationRequest(
				Plugin::instance()->crypto(),
				function ( string $name, string $value, array $options ): void {
					$this->cookies[] = array( $name, $value, $options );
				}
			)
		);
	}

	/**
	 * Press the button: the start handler with this `redirect_to`.
	 *
	 * @param string $redirect_to Raw query value.
	 * @return void
	 */
	private function press( string $redirect_to ): void {
		$_GET = array(
			'action'      => PortalSignIn::ACTION,
			'redirect_to' => $redirect_to,
		);

		$this->sign_in()->handle_request();
	}

	/**
	 * WooCommerce's My Account login form, as a logged-out visitor sees it.
	 *
	 * @return string
	 */
	private static function account_login_form(): string {
		return (string) wc_get_template_html( 'myaccount/form-login.php' );
	}

	/**
	 * WooCommerce's classic checkout login form ("Returning customer?").
	 *
	 * @return string
	 */
	private static function checkout_login_form(): string {
		ob_start();
		woocommerce_login_form(
			array(
				'message'  => '',
				'redirect' => wc_get_checkout_url(),
				'hidden'   => true,
			)
		);

		return (string) ob_get_clean();
	}

	/**
	 * The button's link in some HTML ('' when there is none).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function button_href( string $html ): string {
		return 1 === preg_match( '/<a[^>]+class="[^"]*pixalink-loyalty-sign-in__button[^"]*"[^>]+href="([^"]+)"/', $html, $match )
			|| 1 === preg_match( '/<a[^>]+href="([^"]+)"[^>]+class="[^"]*pixalink-loyalty-sign-in__button/', $html, $match )
			? html_entity_decode( $match[1] )
			: '';
	}

	/**
	 * Query arguments of a URL.
	 *
	 * @param string $url URL.
	 * @return array<string,mixed>
	 */
	private static function query( string $url ): array {
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return $query;
	}

	public function test_the_handler_answers_logged_out_and_logged_in_visitors(): void {
		$this->assertNotFalse( has_action( 'admin_post_nopriv_' . PortalSignIn::ACTION ) );
		$this->assertNotFalse( has_action( 'admin_post_' . PortalSignIn::ACTION ) );
		$this->assertNotFalse( has_action( 'woocommerce_login_form_end' ) );
	}

	public function test_the_my_account_login_form_offers_the_button_in_the_portal_colours(): void {
		$_SERVER['REQUEST_URI'] = '/my-account/?orderby=x&pixalink_sso=stale';

		$html = self::account_login_form();

		$this->assertStringContainsString( 'class="pixalink-loyalty-sign-in is-branded"', $html );
		$this->assertStringContainsString( 'Sign in from Customer Portal', $html );
		$this->assertStringContainsString( '>or<', $html, 'A divider sets it apart from the password form.' );
		$href = self::button_href( $html );
		$this->assertStringStartsWith( admin_url( 'admin-post.php' ), $href );
		$this->assertSame(
			array(
				'action'      => PortalSignIn::ACTION,
				'redirect_to' => home_url( '/my-account/?orderby=x' ),
			),
			self::query( $href ),
			'Back to this page, never with a spent ticket.'
		);
		$this->assertLessThan( strpos( $html, '</form>' ), strpos( $html, 'pixalink-loyalty-sign-in' ), 'Inside the login form, after its fields.' );
		$this->assertTrue( wp_style_is( Assets::SIGN_IN_STYLE, 'enqueued' ) );
		$this->assertStringContainsString( '.pixalink-loyalty-sign-in{--pxl-primary:' . Responses::BRAND_PRIMARY, implode( '', (array) wp_styles()->get_data( Assets::SIGN_IN_STYLE, 'after' ) ) );
		$this->assertSame( array(), Server::recorded(), 'Rendering the button calls nothing.' );
	}

	public function test_a_login_form_opened_with_redirect_to_brings_the_member_back_there(): void {
		$loyalty_tab            = home_url( '/?page_id=' . $this->account_page . '&pixalink-loyalty' );
		$_SERVER['REQUEST_URI'] = '/my-account/?redirect_to=x';
		$_GET                   = array( 'redirect_to' => $loyalty_tab );

		$this->assertSame( $loyalty_tab, self::query( self::button_href( self::account_login_form() ) )['redirect_to'] ?? null, 'The Loyalty tab a portal button was clicked on.' );

		$_GET = array( 'redirect_to' => 'https://evil.example/phish' );
		$this->assertSame( home_url( '/my-account/?redirect_to=x' ), self::query( self::button_href( self::account_login_form() ) )['redirect_to'] ?? null, 'Never off the store.' );
	}

	public function test_the_classic_checkout_login_form_offers_the_button_too(): void {
		$_SERVER['REQUEST_URI'] = '/checkout/';

		$html = self::checkout_login_form();

		$this->assertStringContainsString( 'Sign in from Customer Portal', $html );
		$this->assertSame( home_url( '/checkout/' ), self::query( self::button_href( $html ) )['redirect_to'] ?? null, 'Back to checkout, cart intact.' );
	}

	public function test_the_logo_shows_while_the_portal_look_is_on(): void {
		$this->heartbeat(
			static function ( array &$branding ): void {
				$branding['logo_url'] = 'https://fake-pixalink.test/storage/logo.png';
			}
		);

		$this->assertStringContainsString( 'src="https://fake-pixalink.test/storage/logo.png"', self::account_login_form() );

		update_option( Options::PORTAL_BRANDING, 'no' );
		$neutral = self::account_login_form();

		$this->assertStringContainsString( 'class="pixalink-loyalty-sign-in"', $neutral, 'Neutral styling: no is-branded.' );
		$this->assertStringNotContainsString( 'logo.png', $neutral );
		$this->assertStringContainsString( 'Sign in from Customer Portal', $neutral, 'Same label without the portal look.' );
	}

	public function test_the_label_does_not_depend_on_the_portal_name(): void {
		$this->heartbeat(
			static function ( array &$branding ): void {
				$branding['name'] = '';
			}
		);

		$this->assertStringContainsString( 'Sign in from Customer Portal', self::account_login_form() );
	}

	/**
	 * Situations in which the button stays hidden.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function hidden_cases(): array {
		return array(
			'logged in'           => array( 'logged_in' ),
			'setting off'         => array( 'option_off' ),
			'no sign-in page'     => array( 'no_sign_in_url' ),
			'sign-in page off-host' => array( 'foreign_sign_in_url' ),
			'no branding at all'  => array( 'no_branding' ),
			'store not connected' => array( 'disconnected' ),
		);
	}

	/**
	 * @dataProvider hidden_cases
	 *
	 * @param string $case Situation.
	 */
	public function test_the_button_is_hidden( string $case ): void {
		switch ( $case ) {
			case 'logged_in':
				wp_set_current_user( $this->make_user() );
				break;
			case 'option_off':
				update_option( Options::PORTAL_SIGN_IN, 'no' );
				break;
			case 'no_sign_in_url':
				$this->heartbeat(
					static function ( array &$branding ): void {
						unset( $branding['sign_in_url'] );
					}
				);
				break;
			case 'foreign_sign_in_url':
				$this->heartbeat(
					static function ( array &$branding ): void {
						$branding['sign_in_url'] = 'https://evil.example/store-sign-in/x';
					}
				);
				break;
			case 'no_branding':
				$status = Responses::samples( time() )['heartbeat']['response']['body'];
				unset( $status['branding'] );
				( new Connection( new Options() ) )->store_remote_status( $status );
				break;
			case 'disconnected':
				Plugin::instance()->credentials()->clear();
				break;
		}

		$this->assertStringNotContainsString( 'pixalink-loyalty-sign-in', self::account_login_form() );
		$this->assertStringNotContainsString( 'pixalink-loyalty-sign-in', self::checkout_login_form() );
		$this->assertFalse( wp_style_is( Assets::SIGN_IN_STYLE, 'enqueued' ) );
	}

	public function test_pressing_the_button_keeps_a_state_cookie_and_sends_the_browser_to_the_portal(): void {
		$this->press( home_url( '/checkout/?step=2' ) );

		$this->assertCount( 1, $this->cookies );
		[ $name, $state, $options ] = $this->cookies[0];
		$this->assertSame( 'pixalink_loyalty_sso_state', $name );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43,}$/D', $state );
		$this->assertTrue( $options['httponly'] );
		$this->assertSame( is_ssl(), $options['secure'] );
		$this->assertSame( 'Lax', $options['samesite'] );
		$this->assertSame( COOKIEPATH, $options['path'] );
		$this->assertSame( is_string( COOKIE_DOMAIN ) ? COOKIE_DOMAIN : '', $options['domain'] );
		$this->assertEqualsWithDelta( time() + 600, $options['expires'], 5 );

		$this->assertCount( 1, $this->redirects );
		[ $location, $status ] = $this->redirects[0];
		$this->assertSame( 303, $status );
		$this->assertStringStartsWith( Responses::SIGN_IN_URL . '?', $location );
		$this->assertSame(
			array(
				'return' => home_url( '/checkout/?step=2' ),
				'state'  => $state,
			),
			self::query( $location ),
			'The state in the URL is the one in the cookie.'
		);
		$this->assertContains( 'Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private', $this->headers );
		$this->assertContains( 'Referrer-Policy: no-referrer', $this->headers );
		$this->assertSame( array(), Server::recorded(), 'The store calls nothing; the portal mints the ticket.' );
	}

	public function test_a_relative_redirect_comes_back_as_an_absolute_store_url(): void {
		$this->press( '/shop/?orderby=price' );

		$this->assertSame( home_url( '/shop/?orderby=price' ), self::query( $this->redirects[0][0] )['return'] ?? null );
	}

	/**
	 * `redirect_to` values that must not leave the store.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function off_site_redirects(): array {
		return array(
			'another site'      => array( 'https://evil.example/phish' ),
			'protocol-relative' => array( '//evil.example/phish' ),
			'javascript'        => array( 'javascript:alert(1)' ),
			'empty'             => array( '' ),
		);
	}

	/**
	 * @dataProvider off_site_redirects
	 *
	 * @param string $redirect_to Value.
	 */
	public function test_an_off_site_redirect_falls_back_to_my_account( string $redirect_to ): void {
		$this->press( $redirect_to );

		$this->assertSame( (string) get_permalink( $this->account_page ), self::query( $this->redirects[0][0] )['return'] ?? null );
	}

	public function test_a_logged_in_visitor_just_goes_back(): void {
		wp_set_current_user( $this->make_user() );

		$this->press( home_url( '/checkout/' ) );

		$this->assertSame( array( array( home_url( '/checkout/' ), 303 ) ), $this->redirects );
		$this->assertSame( array(), $this->cookies, 'No state for a sign-in that will not happen.' );
	}

	/**
	 * The store has its OAuth client (provisioned from the cached heartbeat, which advertises OAuth).
	 *
	 * @return void
	 */
	private function oauth_ready(): void {
		$this->oauth_client()->ensure();
		Server::reset();
	}

	private function oauth_client(): ClientCredentials {
		return new ClientCredentials( Plugin::instance()->connect(), new Options(), Plugin::instance()->crypto(), Plugin::instance()->logger() );
	}

	/**
	 * Press "Verify with WhatsApp": the start handler with `intent=verify`.
	 *
	 * @param string $redirect_to Raw query value.
	 * @return void
	 */
	private function press_to_verify( string $redirect_to ): void {
		$_GET = array(
			'action'      => PortalSignIn::ACTION,
			'redirect_to' => $redirect_to,
			'intent'      => PortalSignIn::INTENT_VERIFY,
		);

		$this->sign_in()->handle_request();
	}

	/**
	 * Assert a 303 to the fake portal's authorize page; returns its query.
	 *
	 * @return array<string,mixed>
	 */
	private function assert_sent_to_authorize(): array {
		$this->assertCount( 1, $this->redirects );
		[ $location, $status ] = $this->redirects[0];
		$this->assertSame( 303, $status );
		$this->assertStringStartsWith( Responses::OAUTH_AUTHORIZE . '?', $location );
		$query = self::query( $location );
		$this->assertSame( 'code', $query['response_type'] ?? null );
		$this->assertSame( Responses::OAUTH_CLIENT_ID, $query['client_id'] ?? null );
		$this->assertSame( ClientCredentials::redirect_uri(), $query['redirect_uri'] ?? null );
		$this->assertSame( 'profile store:link', $query['scope'] ?? null );
		$this->assertSame( 'S256', $query['code_challenge_method'] ?? null );
		$this->assertCount( 1, $this->cookies );
		$this->assertSame( AuthorizationRequest::COOKIE, $this->cookies[0][0], 'The OAuth request, not the ticket state.' );
		$this->assertContains( 'Referrer-Policy: no-referrer', $this->headers );
		$this->assertContains( 'Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private', $this->headers );
		$this->assertSame( array(), Server::recorded(), 'Nothing is sent to Pixalink until the shopper comes back.' );

		return $query;
	}

	/**
	 * The request the start handler kept in the cookie.
	 *
	 * @return array{state:string,verifier:string,purpose:string,return:string,user_id:int}|null
	 */
	private function kept_request(): ?array {
		$_COOKIE[ AuthorizationRequest::COOKIE ] = $this->cookies[0][1];

		return ( new AuthorizationRequest( Plugin::instance()->crypto(), static function (): void {} ) )->take();
	}

	public function test_with_oauth_a_logged_out_shopper_signs_in_through_the_authorize_page(): void {
		$this->oauth_ready();

		$this->press( home_url( '/checkout/?step=2' ) );

		$query = $this->assert_sent_to_authorize();
		$kept  = $this->kept_request();
		$this->assertSame( $kept['state'] ?? null, $query['state'] ?? null );
		$this->assertSame( AuthorizationRequest::PURPOSE_SIGN_IN, $kept['purpose'] ?? null );
		$this->assertSame( home_url( '/checkout/?step=2' ), $kept['return'] ?? null );
		$this->assertSame( 0, $kept['user_id'] ?? null );
	}

	public function test_with_oauth_a_logged_in_unverified_shopper_verifies_through_the_authorize_page(): void {
		$this->oauth_ready();
		$user_id = $this->make_user();
		wp_set_current_user( $user_id );
		$loyalty_tab = wc_get_account_endpoint_url( 'pixalink-loyalty' );

		$this->press_to_verify( $loyalty_tab );

		$this->assert_sent_to_authorize();
		$kept = $this->kept_request();
		$this->assertSame( AuthorizationRequest::PURPOSE_VERIFY, $kept['purpose'] ?? null );
		$this->assertSame( $loyalty_tab, $kept['return'] ?? null, 'Back on the Loyalty tab.' );
		$this->assertSame( $user_id, $kept['user_id'] ?? null );
	}

	public function test_without_oauth_the_logged_out_button_keeps_the_ticket_flow(): void {
		$status = Responses::samples( time() )['heartbeat']['response']['body'];
		unset( $status['oauth'] );
		( new Connection( new Options() ) )->store_remote_status( $status );

		$this->press( home_url( '/my-account/' ) );

		$this->assertStringStartsWith( Responses::SIGN_IN_URL . '?', $this->redirects[0][0] );
		$this->assertSame( SignInState::COOKIE, $this->cookies[0][0] );
	}

	public function test_without_a_provisioned_client_the_logged_out_button_keeps_the_ticket_flow(): void {
		$this->press( home_url( '/my-account/' ) );

		$this->assertStringStartsWith( Responses::SIGN_IN_URL . '?', $this->redirects[0][0], 'OAuth is advertised but the store has no client yet.' );
	}

	public function test_with_oauth_and_no_ticket_page_the_logged_out_button_still_shows(): void {
		$this->oauth_ready();
		$this->heartbeat(
			static function ( array &$branding ): void {
				unset( $branding['sign_in_url'] );
			}
		);

		$this->assertStringContainsString( 'Sign in from Customer Portal', self::account_login_form() );
	}

	public function test_without_oauth_verify_is_not_offered_and_a_press_just_goes_back(): void {
		$user_id = $this->make_user();
		wp_set_current_user( $user_id );

		$this->assertNull( $this->sign_in()->verify_url( $user_id ), 'The ticket flow cannot verify a logged-in shopper.' );

		$this->press_to_verify( home_url( '/my-account/pixalink-loyalty/' ) );

		$this->assertSame( array( array( home_url( '/my-account/pixalink-loyalty/' ), 303 ) ), $this->redirects );
		$this->assertSame( array(), $this->cookies );
	}

	/**
	 * Logged-in shoppers who may not verify through the portal.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function refused_verifiers(): array {
		return array(
			'shop manager'   => array( 'staff' ),
			'already linked' => array( 'linked' ),
			'no oauth'       => array( 'no_oauth' ),
			'setting off'    => array( 'option_off' ),
			'disconnected'   => array( 'disconnected' ),
		);
	}

	/**
	 * @dataProvider refused_verifiers
	 *
	 * @param string $case Situation.
	 */
	public function test_a_verify_press_that_is_not_allowed_just_goes_back( string $case ): void {
		$this->oauth_ready();
		$user_id = $this->make_user( 'member@example.com', 'staff' === $case ? 'shop_manager' : 'customer' );
		wp_set_current_user( $user_id );
		switch ( $case ) {
			case 'linked':
				UserMeta::store_link( $user_id, new Link( Responses::MEMBER_ID, Responses::MEMBER_PHONE, 'MY', '2026-10-20T00:00:00Z', Link::VIA_OTP ) );
				break;
			case 'no_oauth':
				$status = Responses::samples( time() )['heartbeat']['response']['body'];
				unset( $status['oauth'] );
				( new Connection( new Options() ) )->store_remote_status( $status );
				break;
			case 'option_off':
				update_option( Options::PORTAL_SIGN_IN, 'no' );
				break;
			case 'disconnected':
				Plugin::instance()->credentials()->clear();
				break;
		}

		$this->assertNull( $this->sign_in()->verify_url( $user_id ), 'No button to press.' );

		$this->press_to_verify( home_url( '/my-account/pixalink-loyalty/' ) );

		$this->assertSame( array( array( home_url( '/my-account/pixalink-loyalty/' ), 303 ) ), $this->redirects );
		$this->assertSame( array(), $this->cookies );
	}

	public function test_the_verify_button_returns_to_the_loyalty_tab_with_the_intent(): void {
		$this->oauth_ready();
		$user_id = $this->make_user();
		wp_set_current_user( $user_id );

		$url = (string) $this->sign_in()->verify_url( $user_id );

		$this->assertStringStartsWith( admin_url( 'admin-post.php' ), $url );
		$this->assertSame(
			array(
				'action'      => PortalSignIn::ACTION,
				'redirect_to' => wc_get_account_endpoint_url( 'pixalink-loyalty' ),
				'intent'      => PortalSignIn::INTENT_VERIFY,
			),
			self::query( $url )
		);
		$this->assertNull( $this->sign_in()->verify_url( 0 ), 'Never for a logged-out visitor.' );
	}

	public function test_without_a_sign_in_page_the_shopper_goes_back_with_a_notice(): void {
		$this->heartbeat(
			static function ( array &$branding ): void {
				unset( $branding['sign_in_url'] );
			}
		);

		$this->press( home_url( '/my-account/' ) );

		$this->assertSame( 303, $this->redirects[0][1] );
		$this->assertSame( home_url( '/my-account/' ), remove_query_arg( PortalSignIn::NOTICE_PARAM, $this->redirects[0][0] ) );
		$this->assertSame( PortalSignIn::NOTICE_UNAVAILABLE, self::query( $this->redirects[0][0] )[ PortalSignIn::NOTICE_PARAM ] ?? null );
		$this->assertSame( array(), $this->cookies );

		$_GET = array( PortalSignIn::NOTICE_PARAM => PortalSignIn::NOTICE_UNAVAILABLE );
		$this->sign_in()->unavailable_notice();
		$this->assertStringContainsString( 'Signing in with the member portal isn\'t available right now', implode( ' ', array_column( wc_get_notices( 'notice' ), 'notice' ) ) );
	}

	public function test_the_setting_turned_off_after_the_page_loaded_sends_the_shopper_back(): void {
		update_option( Options::PORTAL_SIGN_IN, 'no' );

		$this->press( home_url( '/my-account/' ) );

		$this->assertStringStartsWith( home_url( '/my-account/' ), $this->redirects[0][0] );
		$this->assertSame( array(), $this->cookies );
	}

	public function test_the_setting_is_on_by_default_and_listed_under_my_account(): void {
		$this->assertTrue( ( new Options() )->get_bool( Options::PORTAL_SIGN_IN ) );
		$this->assertSame( 'pixalink_loyalty_portal_sign_in', Options::PORTAL_SIGN_IN );
	}
}
