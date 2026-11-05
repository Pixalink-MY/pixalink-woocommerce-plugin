<?php
/**
 * Integration tests for CallbackHandler.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Oauth;

use Pixalink\Loyalty\Api\Resources\Oauth;
use Pixalink\Loyalty\Connect\Connection;
use Pixalink\Loyalty\Connect\PkceState;
use Pixalink\Loyalty\Identity\GuestLinkStore;
use Pixalink\Loyalty\Identity\Link;
use Pixalink\Loyalty\Identity\MemberLanding;
use Pixalink\Loyalty\Identity\ShopperContext;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Oauth\AuthorizationRequest;
use Pixalink\Loyalty\Oauth\CallbackHandler;
use Pixalink\Loyalty\Oauth\Capability;
use Pixalink\Loyalty\Oauth\ClientCredentials;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\FakeServer\Responses;
use Pixalink\Loyalty\Tests\FakeServer\Server;
use Pixalink\Loyalty\Tests\Integration\Support\StoreFixtures;

/**
 * The member portal sends the shopper back to `?pixalink_oauth=callback`: the state must be the one this browser kept,
 * the code is exchanged (form-encoded, HTTP Basic) and the access token redeemed with `POST customers` (verified_via
 * oauth). Then the same rules as a portal ticket: logged-out shoppers are signed in (accounts created as for the
 * store's own button), a logged-in shopper who pressed "Verify with WhatsApp" is linked without a second confirmation,
 * an account is never switched from another member, staff are refused, and nothing happens on a cancelled, forged or
 * expired round trip.
 *
 * @covers \Pixalink\Loyalty\Oauth\CallbackHandler
 * @covers \Pixalink\Loyalty\Oauth\ClientCredentials
 * @covers \Pixalink\Loyalty\Identity\MemberLanding
 * @covers \Pixalink\Loyalty\Identity\CustomerLinker::redeem_oauth
 * @covers \Pixalink\Loyalty\Api\Resources\Oauth
 */
final class CallbackHandlerTest extends \WP_UnitTestCase {

	use StoreFixtures;

	/**
	 * Cookies set: [name, value, options].
	 *
	 * @var list<array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $cookies = array();

	/**
	 * The authorize URL of the round trip under test.
	 *
	 * @var string
	 */
	private string $authorize_url = '';

	public function set_up(): void {
		parent::set_up();

		$this->set_up_store();
		$this->account_page();
		wp_set_current_user( 0 );
		$_COOKIE       = array();
		$this->cookies = array();
		delete_option( Options::PORTAL_SIGN_IN );
		( new Connection( new Options() ) )->store_remote_status( Responses::samples( time() )['heartbeat']['response']['body'] );
		$this->client_credentials()->ensure();
		Server::reset();
	}

	public function tear_down(): void {
		$_COOKIE = array();
		delete_option( Options::REMOTE_STATUS );
		delete_option( Options::PORTAL_SIGN_IN );
		delete_option( Options::OAUTH_CLIENT );
		$this->tear_down_store();

		parent::tear_down();
	}

	/**
	 * A published My Account page.
	 *
	 * @return void
	 */
	private function account_page(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => 'my-account',
				'post_content' => '[woocommerce_my_account]',
			)
		);
		update_option( 'woocommerce_myaccount_page_id', $page );
	}

	private function client_credentials(): ClientCredentials {
		return new ClientCredentials( Plugin::instance()->connect(), new Options(), Plugin::instance()->crypto(), Plugin::instance()->logger() );
	}

	private function requests(): AuthorizationRequest {
		return new AuthorizationRequest(
			Plugin::instance()->crypto(),
			function ( string $name, string $value, array $options ): void {
				$this->cookies[] = array( $name, $value, $options );
			}
		);
	}

	private function handler(): CallbackHandler {
		$identity = Plugin::instance()->identity();

		return new CallbackHandler(
			$identity->linker(),
			Plugin::instance()->credentials(),
			$this->client_credentials(),
			new Oauth( Plugin::instance()->client() ),
			$this->requests(),
			new MemberLanding( $identity->linker(), $identity->guests(), $identity->normalizer(), Plugin::instance()->logger(), new Options() ),
			new Options(),
			Plugin::instance()->logger()
		);
	}

	/**
	 * The shopper pressed the button: a request is kept in this browser's cookie.
	 *
	 * @param string   $purpose    Purpose.
	 * @param string   $return_url Page to land on.
	 * @param int|null $user_id    Account that started it (default: the current one).
	 * @return string The state sent to the portal.
	 */
	private function started( string $purpose = AuthorizationRequest::PURPOSE_SIGN_IN, string $return_url = '', ?int $user_id = null ): string {
		$client     = (array) $this->client_credentials()->get();
		$capability = Capability::remote();
		$this->assertNotNull( $capability, 'The heartbeat advertises OAuth.' );
		$this->authorize_url = $this->requests()->start(
			$capability,
			(string) ( $client['client_id'] ?? '' ),
			ClientCredentials::redirect_uri(),
			$purpose,
			'' !== $return_url ? $return_url : home_url( '/my-account/' ),
			$user_id ?? get_current_user_id()
		);
		$_COOKIE[ AuthorizationRequest::COOKIE ] = $this->cookies[0][1];
		$this->cookies                           = array();

		return self::query( $this->authorize_url )['state'];
	}

	/**
	 * Come back from the portal with a code.
	 *
	 * @param string $code  Code.
	 * @param string $state State.
	 * @return string Where the shopper is sent.
	 */
	private function back_with( string $code, string $state ): string {
		return $this->handler()->handle(
			array(
				ClientCredentials::CALLBACK_PARAM => ClientCredentials::CALLBACK_VALUE,
				'code'                            => $code,
				'state'                           => $state,
			)
		);
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

	private static function notices(): string {
		return implode( ' ', array_column( wc_get_notices( 'notice' ), 'notice' ) );
	}

	/**
	 * Recorded requests to one path.
	 *
	 * @param string $path Path.
	 * @return list<array<string,mixed>>
	 */
	private static function recorded( string $path ): array {
		return array_values( array_filter( Server::recorded(), static fn ( array $request ): bool => $path === $request['path'] ) );
	}

	private function cookie_cleared(): bool {
		foreach ( $this->cookies as $cookie ) {
			if ( AuthorizationRequest::COOKIE === $cookie[0] && '' === $cookie[1] && $cookie[2]['expires'] < time() ) {
				return ! isset( $_COOKIE[ AuthorizationRequest::COOKIE ] );
			}
		}

		return false;
	}

	private function link_member( int $user_id ): void {
		UserMeta::store_link( $user_id, new Link( Responses::MEMBER_ID, Responses::MEMBER_PHONE, 'MY', '2026-10-20T00:00:00Z', Link::VIA_OTP ) );
	}

	public function test_the_callback_is_handled_early_on_template_redirect(): void {
		$this->assertSame( 0, has_action( 'template_redirect', array( Plugin::instance()->identity()->oauth_callback(), 'on_template_redirect' ) ) );
	}

	public function test_a_logged_out_member_is_signed_in_to_their_linked_account(): void {
		$user_id = $this->make_user();
		$this->link_member( $user_id );
		$state = $this->started();

		$target = $this->back_with( Responses::OAUTH_CODE_MEMBER . 'one', $state );

		$this->assertSame( home_url( '/my-account/' ), $target );
		$this->assertSame( $user_id, get_current_user_id() );
		$this->assertSame( Link::VIA_OAUTH, UserMeta::link( $user_id )?->via );
		$this->assertTrue( $this->cookie_cleared() );

		$token = self::recorded( 'oauth/customer/token' );
		$this->assertCount( 1, $token );
		$this->assertSame( 200, $token[0]['status'], 'The fake token endpoint validated the form against the contract.' );
		$this->assertSame( Responses::OAUTH_CLIENT_ID, $token[0]['body']['basic_client_id'], 'HTTP Basic client authentication.' );
		$this->assertSame( ClientCredentials::redirect_uri(), $token[0]['body']['redirect_uri'] );
		$this->assertSame( self::query( $this->authorize_url )['code_challenge'], PkceState::challenge( $token[0]['body']['code_verifier'] ), 'The verifier behind the challenge the portal saw.' );

		$redeem = self::recorded( 'customers' );
		$this->assertCount( 1, $redeem );
		$this->assertSame( 200, $redeem[0]['status'] );
		$this->assertSame( 'oauth', $redeem[0]['body']['verified_via'] );
		$this->assertNull( $redeem[0]['body']['external_id'], 'Nobody was logged in.' );
		$this->assertStringStartsWith( Responses::OAUTH_TOKEN_PREFIX . 'member-', $redeem[0]['body']['oauth_access_token'] );
	}

	public function test_a_new_member_gets_an_account_even_with_registration_off(): void {
		update_option( 'woocommerce_enable_myaccount_registration', 'no' );
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );
		$state = $this->started();

		$this->back_with( Responses::OAUTH_CODE_NEW . 'two', $state );

		$user = get_user_by( 'email', 'portal.member@example.com' );
		$this->assertInstanceOf( \WP_User::class, $user, 'They pressed the store\'s own sign-in button.' );
		$this->assertSame( (int) $user->ID, get_current_user_id() );
		$this->assertSame( Responses::NEW_CUSTOMER_ID, UserMeta::link( (int) $user->ID )?->customer_id );
	}

	public function test_a_member_without_an_email_stays_a_recognised_guest(): void {
		$state = $this->started();

		$this->back_with( Responses::OAUTH_CODE_NO_EMAIL . 'three', $state );

		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( Responses::NEW_CUSTOMER_ID, ( new GuestLinkStore() )->get( ShopperContext::current_session_id() )?->customer_id );
		$this->assertStringContainsString( "We've recognised your Pixalink membership for this visit", self::notices() );
	}

	public function test_verify_links_the_logged_in_account_without_a_second_confirmation(): void {
		$user_id = $this->make_user( 'shopper@example.com' );
		wp_set_current_user( $user_id );
		$loyalty_tab = home_url( '/my-account/pixalink-loyalty/' );
		$state       = $this->started( AuthorizationRequest::PURPOSE_VERIFY, $loyalty_tab );

		$target = $this->back_with( Responses::OAUTH_CODE_MEMBER . 'four', $state );

		$this->assertSame( $loyalty_tab, $target, 'Back on the Loyalty tab.' );
		$this->assertSame( Responses::MEMBER_ID, UserMeta::link( $user_id )?->customer_id );
		$this->assertSame( Link::VIA_OAUTH, UserMeta::link( $user_id )?->via, 'Read back as oauth, not otp.' );
		$this->assertSame( (string) $user_id, self::recorded( 'customers' )[0]['body']['external_id'] );
		$this->assertStringContainsString( 'Your account is now linked to your loyalty membership.', self::notices() );
		$this->assertStringNotContainsString( 'Link my account', self::notices() );
	}

	public function test_verify_never_switches_an_account_linked_to_another_member(): void {
		$user_id = $this->make_user( 'shopper@example.com' );
		UserMeta::store_link( $user_id, new Link( '7777', '+60111111111', 'MY', '2026-10-20T00:00:00Z', Link::VIA_OTP ) );
		wp_set_current_user( $user_id );
		$state = $this->started( AuthorizationRequest::PURPOSE_VERIFY );

		$this->back_with( Responses::OAUTH_CODE_MEMBER . 'five', $state );

		$this->assertSame( '7777', UserMeta::link( $user_id )?->customer_id );
		$this->assertStringContainsString( 'already linked to a different loyalty member', self::notices() );
		$this->assertStringNotContainsString( 'now linked', self::notices() );
	}

	public function test_verify_for_the_member_already_linked_changes_nothing(): void {
		$user_id = $this->make_user( 'shopper@example.com' );
		$this->link_member( $user_id );
		wp_set_current_user( $user_id );
		$state = $this->started( AuthorizationRequest::PURPOSE_VERIFY );

		$this->back_with( Responses::OAUTH_CODE_MEMBER . 'six', $state );

		$this->assertSame( Responses::MEMBER_ID, UserMeta::link( $user_id )?->customer_id );
		$this->assertSame( $user_id, get_current_user_id() );
		$this->assertStringNotContainsString( 'different loyalty member', self::notices() );
		$this->assertStringNotContainsString( 'now linked', self::notices(), 'It already was.' );
	}

	public function test_staff_are_refused_before_anything_is_exchanged(): void {
		$staff_id = $this->make_user( 'staff@example.com', 'shop_manager' );
		wp_set_current_user( $staff_id );
		$refusals = array();
		add_action(
			'pixalink_loyalty_sso_refused_staff',
			static function ( $user_id, $customer ) use ( &$refusals ): void {
				$refusals[] = array( $user_id, $customer );
			},
			10,
			2
		);
		$state = $this->started( AuthorizationRequest::PURPOSE_VERIFY );

		$this->back_with( Responses::OAUTH_CODE_MEMBER . 'seven', $state );

		$this->assertNull( UserMeta::link( $staff_id ) );
		$this->assertSame( array(), Server::recorded(), 'The code is never exchanged for a staff session.' );
		$this->assertStringContainsString( 'signed in to this store as staff', self::notices() );
		$this->assertSame( array( array( $staff_id, null ) ), $refusals );
	}

	public function test_a_state_that_does_not_match_the_cookie_is_refused(): void {
		$this->started();

		$target = $this->back_with( Responses::OAUTH_CODE_MEMBER . 'eight', 'q8VJ2nX5rT1wZ7bD4fH9kM3pS6uY0aC2eG5iL8oR1tW4' );

		$this->assertSame( home_url( '/my-account/' ), $target );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( array(), Server::recorded(), 'A forged callback exchanges nothing.' );
		$this->assertStringContainsString( "Signing in with the member portal didn't finish. Please try again.", self::notices() );
		$this->assertTrue( $this->cookie_cleared() );
	}

	public function test_without_the_cookie_nothing_happens(): void {
		$target = $this->back_with( Responses::OAUTH_CODE_MEMBER . 'nine', 'q8VJ2nX5rT1wZ7bD4fH9kM3pS6uY0aC2eG5iL8oR1tW4' );

		$this->assertSame( wc_get_page_permalink( 'myaccount' ), $target );
		$this->assertSame( array(), Server::recorded() );
		$this->assertStringContainsString( "didn't finish", self::notices() );
	}

	public function test_a_round_trip_started_by_another_account_is_refused(): void {
		$state = $this->started( AuthorizationRequest::PURPOSE_SIGN_IN, '', 0 );
		wp_set_current_user( $this->make_user( 'someone@example.com' ) );

		$this->back_with( Responses::OAUTH_CODE_MEMBER . 'ten', $state );

		$this->assertSame( array(), Server::recorded() );
		$this->assertNull( UserMeta::link( get_current_user_id() ) );
		$this->assertStringContainsString( "didn't finish", self::notices() );
	}

	public function test_a_member_who_cancels_goes_back_with_a_notice(): void {
		$return = home_url( '/checkout/' );
		$state  = $this->started( AuthorizationRequest::PURPOSE_SIGN_IN, $return );

		$target = $this->handler()->handle(
			array(
				'error' => 'access_denied',
				'state' => $state,
			)
		);

		$this->assertSame( $return, $target );
		$this->assertSame( array(), Server::recorded() );
		$this->assertStringContainsString( 'You cancelled signing in with the member portal.', self::notices() );
	}

	public function test_another_error_from_the_portal_shows_the_generic_notice(): void {
		$state = $this->started();

		$this->handler()->handle(
			array(
				'error' => 'invalid_request',
				'state' => $state,
			)
		);

		$this->assertSame( array(), Server::recorded() );
		$this->assertStringContainsString( "didn't finish", self::notices() );
	}

	public function test_a_used_code_signs_nobody_in(): void {
		$state = $this->started();

		$this->back_with( 'someone-elses-code', $state );

		$this->assertSame( 400, self::recorded( 'oauth/customer/token' )[0]['status'] );
		$this->assertSame( array(), self::recorded( 'customers' ) );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertStringContainsString( "didn't finish", self::notices() );
	}

	public function test_a_rejected_client_is_provisioned_again_once(): void {
		$before = $this->client_credentials()->get();
		$state  = $this->started();
		$script = new \WP_REST_Request( 'POST', '/' . Server::CONTROL_NAMESPACE . '/script' );
		$script->set_header( 'content-type', 'application/json' );
		$script->set_body(
			(string) wp_json_encode(
				array(
					'method' => 'POST',
					'path'   => 'oauth/customer/token',
					'status' => 401,
					'body'   => array( 'error' => 'invalid_client' ),
				)
			)
		);
		Server::add_script( $script );

		$this->back_with( Responses::OAUTH_CODE_MEMBER . 'eleven', $state );

		$this->assertSame( array( 'oauth/customer/token', 'connection/oauth-client' ), array_column( Server::recorded(), 'path' ) );
		$this->assertNotSame( $before['client_secret'] ?? null, $this->client_credentials()->get()['client_secret'] ?? null );
		$this->assertSame( 0, get_current_user_id() );
		$this->assertStringContainsString( "didn't finish", self::notices() );
	}

	public function test_a_spent_token_is_refused_by_pixalink(): void {
		$state  = $this->started();
		$script = new \WP_REST_Request( 'POST', '/' . Server::CONTROL_NAMESPACE . '/script' );
		$script->set_header( 'content-type', 'application/json' );
		$script->set_body(
			(string) wp_json_encode(
				array(
					'method' => 'POST',
					'path'   => 'customers',
					'status' => 410,
					'body'   => array(
						'message' => 'This sign-in has expired or was already used.',
						'code'    => 'oauth_token_invalid',
					),
				)
			)
		);
		Server::add_script( $script );

		$this->back_with( Responses::OAUTH_CODE_MEMBER . 'twelve', $state );

		$this->assertSame( 0, get_current_user_id() );
		$this->assertStringContainsString( "Signing in with the member portal didn't finish. Please try again.", self::notices() );
	}

	public function test_codes_and_tokens_never_reach_the_log(): void {
		update_option( Options::DEBUG_LOGGING, 'yes' );
		$logger = new class() extends \WC_Logger {
			/**
			 * Entries: message and context.
			 *
			 * @var list<string>
			 */
			public array $entries = array();

			public function log( $level, $message, $context = array() ) {
				$this->entries[] = (string) $message . ' ' . (string) wp_json_encode( $context );
			}
		};
		add_filter( 'woocommerce_logging_class', static fn () => $logger );
		$state = $this->started();

		$this->back_with( Responses::OAUTH_CODE_MEMBER . 'secret-code-13', $state );
		delete_option( Options::DEBUG_LOGGING );

		$log = implode( "\n", $logger->entries );
		$this->assertStringContainsString( 'Portal sign-in.', $log, 'The sign-in itself is logged.' );
		$this->assertStringNotContainsString( 'secret-code-13', $log );
		$this->assertStringNotContainsString( Responses::OAUTH_TOKEN_PREFIX, $log );
		$this->assertStringNotContainsString( $state, $log );
		$this->assertStringNotContainsString( Responses::OAUTH_CLIENT_SECRET_PREFIX, $log );
	}
}
