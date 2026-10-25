<?php
/**
 * Integration tests for SsoLanding.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Identity;

use Pixalink\Loyalty\Identity\GuestLinkStore;
use Pixalink\Loyalty\Identity\Link;
use Pixalink\Loyalty\Identity\ShopperContext;
use Pixalink\Loyalty\Identity\ShopperContextInterface;
use Pixalink\Loyalty\Identity\SignInState;
use Pixalink\Loyalty\Identity\SsoLanding;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\FakeServer\Responses;
use Pixalink\Loyalty\Tests\FakeServer\Server;
use Pixalink\Loyalty\Tests\Integration\Support\StoreFixtures;

/**
 * `?pixalink_sso=` from the Pixalink portal: redeemed once, signs shoppers in (never staff), links accounts (never
 * staff ones), tells recognised guests they earn on this visit, and the redirect target never carries the ticket. A
 * ticket from the store's own sign-in button travels with the state cookie (cleared whatever happens), and creates
 * the account even where the store has registration off.
 *
 * @covers \Pixalink\Loyalty\Identity\SsoLanding
 * @covers \Pixalink\Loyalty\Identity\CustomerLinker::redeem_ticket
 * @covers \Pixalink\Loyalty\Identity\CustomerLinker::is_staff
 * @covers \Pixalink\Loyalty\Identity\Module::on_shopper_verified
 * @covers \Pixalink\Loyalty\Identity\Module::on_login
 * @covers \Pixalink\Loyalty\Identity\SignInState
 */
final class SsoLandingTest extends \WP_UnitTestCase {

	use StoreFixtures;

	/**
	 * Cookies the landing set: [name, value, options].
	 *
	 * @var list<array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $cookies = array();

	public function set_up(): void {
		parent::set_up();

		$this->set_up_store();
		wp_set_current_user( 0 );
		$_COOKIE       = array();
		$this->cookies = array();
		delete_option( Options::PORTAL_SIGN_IN );
	}

	public function tear_down(): void {
		$_COOKIE = array();
		delete_option( Options::PORTAL_SIGN_IN );
		$this->tear_down_store();

		parent::tear_down();
	}

	private function landing(): SsoLanding {
		return new SsoLanding(
			Plugin::instance()->identity()->linker(),
			Plugin::instance()->credentials(),
			Plugin::instance()->identity()->guests(),
			Plugin::instance()->identity()->normalizer(),
			Plugin::instance()->logger(),
			new Options(),
			new SignInState(
				function ( string $name, string $value, array $options ): void {
					$this->cookies[] = array( $name, $value, $options );
				}
			)
		);
	}

	/**
	 * The browser comes back from "Sign in with the member portal": it still holds the state cookie.
	 *
	 * @return string The state.
	 */
	private function pressed_the_button(): string {
		$state                          = SignInState::generate();
		$_COOKIE[ SignInState::COOKIE ] = $state;

		return $state;
	}

	/**
	 * Store registration off everywhere.
	 *
	 * @return void
	 */
	private static function registration_off(): void {
		update_option( 'woocommerce_enable_myaccount_registration', 'no' );
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );
	}

	/**
	 * Whether the state cookie was expired.
	 *
	 * @return bool
	 */
	private function state_cookie_cleared(): bool {
		foreach ( $this->cookies as $cookie ) {
			if ( SignInState::COOKIE === $cookie[0] && '' === $cookie[1] && $cookie[2]['expires'] < time() ) {
				return ! isset( $_COOKIE[ SignInState::COOKIE ] );
			}
		}

		return false;
	}

	public function test_the_state_cookie_is_sent_with_the_ticket_and_cleared(): void {
		$user_id = $this->make_user();
		$this->link_member( $user_id );
		$state = $this->pressed_the_button();

		$target = $this->landing()->handle( Responses::SSO_TICKET_MEMBER . 'state01', '/my-account/?pixalink_sso=' . Responses::SSO_TICKET_MEMBER . 'state01' );

		$this->assertSame( home_url( '/my-account/' ), $target, 'Back on the page the button was pressed on, without the ticket.' );
		$this->assertSame( $user_id, get_current_user_id() );
		$recorded = Server::recorded();
		$this->assertSame( $state, $recorded[0]['body']['sso_state'] ?? null );
		$this->assertSame( 200, $recorded[0]['status'], 'The fake server validated the body against the contract.' );
		$this->assertTrue( $this->state_cookie_cleared() );
	}

	public function test_without_the_cookie_no_state_is_sent(): void {
		$this->landing()->handle( Responses::SSO_TICKET_MEMBER . 'abc123', '/?pixalink_sso=x' );

		$this->assertArrayNotHasKey( 'sso_state', Server::recorded()[0]['body'] );
		$this->assertSame( array(), $this->cookies, 'No cookie to clear.' );
	}

	public function test_a_refused_ticket_still_clears_the_cookie_and_says_the_link_expired(): void {
		$this->pressed_the_button();

		$this->landing()->handle( 'someone-elses-ticket-0001', '/my-account/?pixalink_sso=x' );

		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 410, Server::recorded()[0]['status'] );
		$this->assertStringContainsString( 'This sign-in link has expired or was already used.', self::notices() );
		$this->assertTrue( $this->state_cookie_cleared() );
	}

	public function test_a_button_sign_in_creates_the_account_even_with_registration_off(): void {
		self::registration_off();
		$this->pressed_the_button();

		$this->landing()->handle( Responses::SSO_TICKET_NEW . 'button1', '/my-account/?pixalink_sso=x' );

		$user = get_user_by( 'email', 'portal.member@example.com' );
		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertContains( 'customer', $user->roles );
		$this->assertSame( (int) $user->ID, get_current_user_id() );
		$this->assertSame( Responses::NEW_CUSTOMER_ID, get_user_meta( $user->ID, UserMeta::CUSTOMER_ID, true ) );
		$this->assertSame( 'Portal', get_user_meta( $user->ID, 'first_name', true ) );
	}

	public function test_a_button_sign_in_without_an_email_keeps_the_guest_link(): void {
		self::registration_off();
		$this->pressed_the_button();

		$this->landing()->handle( Responses::SSO_TICKET_NO_EMAIL . 'btn1', '/my-account/?pixalink_sso=x' );

		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( Responses::NEW_CUSTOMER_ID, ( new GuestLinkStore() )->get( ShopperContext::current_session_id() )?->customer_id );
		$this->assertStringContainsString( "We've recognised your Pixalink membership for this visit, so you'll earn points on your order.", self::notices() );
	}

	public function test_a_portal_order_link_does_not_create_an_account_with_registration_off(): void {
		self::registration_off();

		$this->landing()->handle( Responses::SSO_TICKET_NEW . 'order01', '/shop/?pixalink_sso=x' );

		$this->assertSame( 0, get_current_user_id() );
		$this->assertFalse( email_exists( 'portal.member@example.com' ), 'No state cookie: the Order button path is unchanged.' );
		$this->assertSame( Responses::NEW_CUSTOMER_ID, ( new GuestLinkStore() )->get( ShopperContext::current_session_id() )?->customer_id );
	}

	public function test_with_the_setting_off_a_button_sign_in_follows_the_store_registration_setting(): void {
		self::registration_off();
		update_option( Options::PORTAL_SIGN_IN, 'no' );
		$this->pressed_the_button();

		$this->landing()->handle( Responses::SSO_TICKET_NEW . 'button2', '/my-account/?pixalink_sso=x' );

		$this->assertFalse( email_exists( 'portal.member@example.com' ) );
		$this->assertTrue( $this->state_cookie_cleared() );
	}

	public function test_a_button_sign_in_never_takes_over_an_account_holding_the_email(): void {
		self::registration_off();
		$existing = $this->make_user( 'portal.member@example.com' );
		$this->pressed_the_button();

		$this->landing()->handle( Responses::SSO_TICKET_NEW . 'button3', '/my-account/?pixalink_sso=x' );

		$this->assertSame( 0, get_current_user_id() );
		$this->assertFalse( UserMeta::is_verified( $existing ) );
		$this->assertStringContainsString( 'Log in to your account', self::notices() );
	}

	public function test_a_button_sign_in_is_still_refused_for_staff(): void {
		$staff_id = $this->make_user( 'staff@example.com', 'shop_manager' );
		wp_set_current_user( $staff_id );
		$state = $this->pressed_the_button();

		$this->landing()->handle( Responses::SSO_TICKET_MEMBER . 'staff09', '/my-account/?pixalink_sso=x' );

		$this->assertSame( '', get_user_meta( $staff_id, UserMeta::CUSTOMER_ID, true ) );
		$this->assertStringContainsString( 'signed in to this store as staff', self::notices() );
		$recorded = Server::recorded();
		$this->assertNull( $recorded[0]['body']['external_id'] );
		$this->assertSame( $state, $recorded[0]['body']['sso_state'] ?? null, 'Spent with its state, like any other redemption.' );
		$this->assertTrue( $this->state_cookie_cleared() );
	}

	private function link_member( int $user_id ): void {
		UserMeta::store_link( $user_id, new Link( Responses::MEMBER_ID, Responses::MEMBER_PHONE, 'MY', '2026-10-20T00:00:00Z', Link::VIA_OTP ) );
	}

	/**
	 * Redirect targets keep everything but the ticket.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function uris(): array {
		return array(
			'only the ticket'      => array( '/?pixalink_sso=abc', '/' ),
			'ticket first'         => array( '/shop/?pixalink_sso=abc&orderby=price', '/shop/?orderby=price' ),
			'ticket last'          => array( '/product/kaya/?attribute_size=l&pixalink_sso=abc', '/product/kaya/?attribute_size=l' ),
			'array-shaped ticket'  => array( '/?pixalink_sso[]=abc', '/' ),
			'no leading slash'     => array( 'menu/?pixalink_sso=abc', '/menu/' ),
		);
	}

	/**
	 * @dataProvider uris
	 *
	 * @param string $uri      Request URI.
	 * @param string $expected Expected path and query.
	 */
	public function test_the_redirect_target_drops_only_the_ticket( string $uri, string $expected ): void {
		$this->assertSame( home_url() . $expected, SsoLanding::target( $uri ) );
	}

	public function test_a_member_with_a_linked_account_is_signed_in_and_the_ticket_is_stripped(): void {
		$user_id = $this->make_user();
		$this->link_member( $user_id );

		$target = $this->landing()->handle( Responses::SSO_TICKET_MEMBER . 'abc123', '/shop/?pixalink_sso=' . Responses::SSO_TICKET_MEMBER . 'abc123&orderby=price' );

		$this->assertSame( home_url( '/shop/?orderby=price' ), $target );
		$this->assertStringNotContainsString( 'pixalink_sso', $target );
		$this->assertSame( $user_id, get_current_user_id() );
		$this->assertSame( 'sso_ticket', get_user_meta( $user_id, UserMeta::LINKED_VIA, true ) );
		$this->assertSame( ShopperContextInterface::STATE_VERIFIED, Plugin::instance()->shopper()->state() );

		$recorded = Server::recorded();
		$this->assertSame( 'customers', $recorded[0]['path'] );
		$this->assertSame( 'sso_ticket', $recorded[0]['body']['verified_via'] );
		$this->assertSame( 200, $recorded[0]['status'] );
	}

	public function test_a_new_member_gets_a_customer_account_and_is_signed_in(): void {
		$target = $this->landing()->handle( Responses::SSO_TICKET_NEW . 'abc123', '/?pixalink_sso=' . Responses::SSO_TICKET_NEW . 'abc123' );

		$this->assertSame( home_url( '/' ), $target );
		$user = get_user_by( 'email', 'portal.member@example.com' );
		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertContains( 'customer', $user->roles );
		$this->assertSame( (int) $user->ID, get_current_user_id() );
		$this->assertSame( Responses::NEW_CUSTOMER_ID, get_user_meta( $user->ID, UserMeta::CUSTOMER_ID, true ) );
		$this->assertSame( '+60199999999', get_user_meta( $user->ID, UserMeta::PHONE_E164, true ) );
		$this->assertNotSame( '', get_user_meta( $user->ID, UserMeta::PHONE_VERIFIED_AT, true ) );
		$this->assertSame( 'Portal', get_user_meta( $user->ID, 'first_name', true ) );
		$this->assertContains( 'customer.upserted', $this->outbox_types(), 'The link is reported with the WordPress user id.' );
		$this->assertStringNotContainsString( 'for this visit', self::notices(), 'A signed-in member is no guest.' );
	}

	public function test_without_an_email_the_member_stays_a_verified_guest_and_is_told_so(): void {
		$account_page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'My account' ) );
		update_option( 'woocommerce_myaccount_page_id', $account_page );

		$this->landing()->handle( Responses::SSO_TICKET_NO_EMAIL . 'abc1', '/?pixalink_sso=' . Responses::SSO_TICKET_NO_EMAIL . 'abc1' );

		$this->assertSame( 0, get_current_user_id() );
		$session_id = ShopperContext::current_session_id();
		$this->assertNotSame( '', $session_id );
		$this->assertSame( Responses::NEW_CUSTOMER_ID, ( new GuestLinkStore() )->get( $session_id )?->customer_id );
		$this->assertSame( ShopperContextInterface::STATE_VERIFIED, Plugin::instance()->shopper()->state() );

		$notices = self::notices();
		$this->assertStringContainsString( "We've recognised your Pixalink membership for this visit, so you'll earn points on your order.", $notices );
		$this->assertStringContainsString( 'href="' . esc_url( (string) get_permalink( $account_page ) ) . '">Create an account</a> to see your balance here.', $notices, 'My Account registration is on.' );
	}

	public function test_a_recognised_guest_who_then_logs_in_as_staff_does_not_link_the_staff_account(): void {
		$this->landing()->handle( Responses::SSO_TICKET_NO_EMAIL . 'abc1', '/?pixalink_sso=x' );
		$staff = get_userdata( $this->make_user( 'staff@example.com', 'shop_manager' ) );

		do_action( 'wp_login', $staff->user_login, $staff );

		$this->assertSame( '', get_user_meta( $staff->ID, UserMeta::CUSTOMER_ID, true ) );
	}

	public function test_a_guest_the_store_cannot_register_is_told_they_were_recognised_without_an_account_sentence(): void {
		update_option( 'woocommerce_enable_myaccount_registration', 'no' );
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );

		$this->landing()->handle( Responses::SSO_TICKET_MEMBER . 'abc123', '/?pixalink_sso=x' );

		$this->assertSame( 0, get_current_user_id() );
		$this->assertFalse( email_exists( Responses::MEMBER_EMAIL ), 'No account was created.' );
		$this->assertSame( Responses::MEMBER_ID, ( new GuestLinkStore() )->get( ShopperContext::current_session_id() )?->customer_id );
		$notices = self::notices();
		$this->assertStringContainsString( "Welcome back! We've recognised your Pixalink membership for this visit, so you'll earn points on your order.", $notices );
		$this->assertStringNotContainsStringIgnoringCase( 'create an account', $notices );
	}

	public function test_a_guest_without_an_email_is_pointed_to_checkout_when_only_checkout_sign_up_is_on(): void {
		// With an e-mail, checkout sign-up alone already lets the landing create the account; without one it cannot.
		update_option( 'woocommerce_enable_myaccount_registration', 'no' );
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'yes' );

		$this->landing()->handle( Responses::SSO_TICKET_NO_EMAIL . 'abc1', '/?pixalink_sso=x' );

		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( Responses::NEW_CUSTOMER_ID, ( new GuestLinkStore() )->get( ShopperContext::current_session_id() )?->customer_id );
		$this->assertStringContainsString( 'recognised your Pixalink membership for this visit, so you\'ll earn points on your order. Create an account at checkout to see your balance here.', self::notices() );
	}

	/**
	 * Accounts that can do more than shop.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function staff_roles(): array {
		return array(
			'administrator' => array( 'administrator' ),
			'shop manager'  => array( 'shop_manager' ),
			'editor'        => array( 'editor' ),
		);
	}

	/**
	 * @dataProvider staff_roles
	 *
	 * @param string $role Role of the signed-in account.
	 */
	public function test_a_signed_in_staff_account_is_refused_and_the_ticket_is_spent_without_its_account_id( string $role ): void {
		$account_page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'My account' ) );
		update_option( 'woocommerce_myaccount_page_id', $account_page );
		$staff_id = $this->make_user( 'staff@example.com', $role );
		wp_set_current_user( $staff_id );
		$refused = $this->record_refusals();

		$target = $this->landing()->handle( Responses::SSO_TICKET_MEMBER . 'staff01', '/?pixalink_sso=x' );

		foreach ( UserMeta::KEYS as $key ) {
			$this->assertSame( '', get_user_meta( $staff_id, $key, true ), $key . ' stays empty on the staff account.' );
		}
		$this->assertFalse( get_transient( 'pixalink_loyalty_sso_pending_' . $staff_id ), 'No ticket waits for a confirmation.' );
		$this->assertStringNotContainsString( 'Link my account', self::notices() );
		$this->assertSame( $staff_id, get_current_user_id(), 'Nobody else is signed in.' );
		$this->assertNull( ( new GuestLinkStore() )->get( ShopperContext::current_session_id() ) );
		$this->assertStringContainsString( "You're signed in to this store as staff, so we didn't connect your Pixalink membership to this account. Log out and open the link again to shop as a member.", self::notices() );
		$this->assertSame( array( array( $staff_id, Responses::MEMBER_ID ) ), $refused->calls );

		// Spent once, and without the account id, so Pixalink links nothing to the staff account either.
		$recorded = Server::recorded();
		$this->assertCount( 1, $recorded );
		$this->assertSame( 'customers', $recorded[0]['path'] );
		$this->assertSame( 'sso_ticket', $recorded[0]['body']['verified_via'] );
		$this->assertNull( $recorded[0]['body']['external_id'] );

		$this->assertSame( get_permalink( $account_page ), $target, 'The home page prints no notices, so the shopper lands on My Account.' );
	}

	public function test_a_staff_account_already_linked_is_not_refreshed_by_a_ticket(): void {
		$admin_id = $this->make_user( 'staff@example.com', 'administrator' );
		$this->link_member( $admin_id );
		wp_set_current_user( $admin_id );

		$this->landing()->handle( Responses::SSO_TICKET_MEMBER . 'staff02', '/?pixalink_sso=x' );

		$this->assertSame( Link::VIA_OTP, get_user_meta( $admin_id, UserMeta::LINKED_VIA, true ), 'The link is left as it was.' );
		$this->assertNull( Server::recorded()[0]['body']['external_id'] );
		$this->assertStringContainsString( 'signed in to this store as staff', self::notices() );
	}

	public function test_a_refused_staff_account_stays_on_a_page_that_prints_notices(): void {
		foreach ( array( 'cart', 'myaccount' ) as $page ) {
			update_option( 'woocommerce_' . $page . '_page_id', self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $page ) ) );
		}
		$this->go_to( (string) get_permalink( wc_get_page_id( 'cart' ) ) );
		wp_set_current_user( $this->make_user( 'staff@example.com', 'administrator' ) );

		$target = $this->landing()->handle( Responses::SSO_TICKET_MEMBER . 'staff03', '/cart/?pixalink_sso=x' );

		$this->assertSame( home_url( '/cart/' ), $target );
	}

	public function test_a_confirmation_from_an_account_that_became_staff_is_refused(): void {
		$user_id = $this->make_user( 'shopper@example.com' );
		wp_set_current_user( $user_id );
		$this->landing()->handle( Responses::SSO_TICKET_MEMBER . 'abc123', '/cart/?pixalink_sso=x' );
		$nonce = $this->confirmation_nonce( $this->confirmation_url() );
		( new \WP_User( $user_id ) )->set_role( 'administrator' );
		wc_clear_notices();
		$refused = $this->record_refusals();

		$this->landing()->confirm( $nonce, '/cart/?' . SsoLanding::CONFIRM_PARAM . '=x' );

		$this->assertSame( '', get_user_meta( $user_id, UserMeta::CUSTOMER_ID, true ) );
		$this->assertFalse( get_transient( 'pixalink_loyalty_sso_pending_' . $user_id ), 'The parked ticket is gone.' );
		$this->assertStringContainsString( 'signed in to this store as staff', self::notices() );
		$this->assertSame( array( array( $user_id, Responses::MEMBER_ID ) ), $refused->calls );
		$this->assertNull( Server::recorded()[0]['body']['external_id'] );
	}

	/**
	 * Notices queued for the next page.
	 *
	 * @return string
	 */
	private static function notices(): string {
		return implode( ' ', array_column( wc_get_notices( 'notice' ), 'notice' ) );
	}

	/**
	 * Record each `pixalink_loyalty_sso_refused_staff` as [user id, customer id].
	 *
	 * @return object{calls: list<array{0:mixed,1:mixed}>}
	 */
	private function record_refusals(): object {
		$recorder = new class() {
			/**
			 * Calls.
			 *
			 * @var list<array{0:mixed,1:mixed}>
			 */
			public array $calls = array();

			public function record( $user_id, $customer ): void {
				$this->calls[] = array( $user_id, is_array( $customer ) ? ( $customer['id'] ?? null ) : $customer );
			}
		};
		add_action( 'pixalink_loyalty_sso_refused_staff', array( $recorder, 'record' ), 10, 2 );

		return $recorder;
	}

	public function test_staff_accounts_are_never_signed_in_by_a_ticket(): void {
		$admin_id = $this->make_user( 'member@example.com', 'administrator' );
		$this->link_member( $admin_id );

		$this->landing()->handle( Responses::SSO_TICKET_MEMBER . 'abc123', '/?pixalink_sso=x' );

		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( ShopperContextInterface::STATE_VERIFIED, Plugin::instance()->shopper()->state(), 'Verified as a guest for this session only.' );
		$this->assertStringContainsString( 'Log in to your account', implode( ' ', array_column( wc_get_notices( 'notice' ), 'notice' ) ) );
	}

	public function test_an_existing_unlinked_account_with_the_same_email_is_not_taken_over(): void {
		$user_id = $this->make_user( Responses::MEMBER_EMAIL );

		$this->landing()->handle( Responses::SSO_TICKET_MEMBER . 'abc123', '/?pixalink_sso=x' );

		$this->assertSame( 0, get_current_user_id(), 'An e-mail match is not proof of the account.' );
		$this->assertFalse( UserMeta::is_verified( $user_id ) );
		$this->assertStringContainsString( 'Log in to your account', implode( ' ', array_column( wc_get_notices( 'notice' ), 'notice' ) ) );
	}

	public function test_a_logged_in_shopper_is_linked_only_after_confirming(): void {
		$user_id = $this->make_user( 'shopper@example.com' );
		wp_set_current_user( $user_id );

		$target = $this->landing()->handle( Responses::SSO_TICKET_MEMBER . 'abc123', '/cart/?pixalink_sso=x' );

		$this->assertSame( home_url( '/cart/' ), $target );
		$this->assertSame( '', get_user_meta( $user_id, UserMeta::CUSTOMER_ID, true ), 'A bare link never links a signed-in account.' );
		$this->assertSame( array(), Server::recorded(), 'The ticket is not redeemed, so the account id is not sent either.' );
		$confirm = $this->confirmation_url();
		$this->assertStringStartsWith( home_url( '/cart/?' . SsoLanding::CONFIRM_PARAM . '=' ), $confirm );

		$target = $this->landing()->confirm( $this->confirmation_nonce( $confirm ), '/cart/?' . SsoLanding::CONFIRM_PARAM . '=x' );

		$this->assertSame( home_url( '/cart/' ), $target );
		$this->assertSame( Responses::MEMBER_ID, get_user_meta( $user_id, UserMeta::CUSTOMER_ID, true ) );
		$this->assertSame( (string) $user_id, Server::recorded()[0]['body']['external_id'] );
	}

	public function test_a_ticket_planted_on_a_signed_in_account_cannot_take_it_over(): void {
		$victim_id = $this->make_user( 'victim@example.com' );
		wp_set_current_user( $victim_id );

		// The attacker sends the victim a store link carrying a ticket for the attacker's own member.
		$this->landing()->handle( Responses::SSO_TICKET_MEMBER . 'attack1', '/?pixalink_sso=x' );
		$this->assertFalse( UserMeta::is_verified( $victim_id ) );

		// Another visit with a forged confirmation, or one minted for a different account, links nothing either.
		$this->landing()->confirm( 'forged', '/?' . SsoLanding::CONFIRM_PARAM . '=forged' );
		$this->landing()->confirm( wp_create_nonce( 'pixalink_sso_link_' . ( $victim_id + 1 ) ), '/' );
		$this->assertFalse( UserMeta::is_verified( $victim_id ) );
		$this->assertSame( array(), Server::recorded() );

		// Then, with a fresh ticket in their own browser, the attacker is not signed in to the victim's account.
		wp_set_current_user( 0 );
		$this->landing()->handle( Responses::SSO_TICKET_MEMBER . 'attack2', '/?pixalink_sso=x' );

		$this->assertNotSame( $victim_id, get_current_user_id() );
	}

	public function test_the_redirect_target_drops_the_confirmation_too(): void {
		$this->assertSame( home_url( '/shop/?orderby=price' ), SsoLanding::target( '/shop/?' . SsoLanding::CONFIRM_PARAM . '=abc&orderby=price' ) );
	}

	/**
	 * The confirmation link in the notice the landing left for the signed-in shopper.
	 *
	 * @return string
	 */
	private function confirmation_url(): string {
		$notices = implode( ' ', array_column( wc_get_notices( 'notice' ), 'notice' ) );
		$this->assertSame( 1, preg_match( '/href="([^"]+)"/', $notices, $match ), 'The notice links to the confirmation: ' . $notices );

		return html_entity_decode( $match[1] );
	}

	/**
	 * The nonce a confirmation link carries.
	 *
	 * @param string $url Confirmation link.
	 * @return string
	 */
	private function confirmation_nonce( string $url ): string {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return (string) $query[ SsoLanding::CONFIRM_PARAM ];
	}

	public function test_an_account_linked_to_another_member_is_not_switched(): void {
		$user_id = $this->make_user( 'shopper@example.com' );
		UserMeta::store_link( $user_id, new Link( '7777', '+60111111111', 'MY', '2026-10-20T00:00:00Z', Link::VIA_OTP ) );
		wp_set_current_user( $user_id );

		$this->landing()->handle( Responses::SSO_TICKET_MEMBER . 'abc123', '/?pixalink_sso=x' );

		$this->assertSame( '7777', get_user_meta( $user_id, UserMeta::CUSTOMER_ID, true ) );
		$this->assertStringContainsString( 'already linked to a different loyalty member', implode( ' ', array_column( wc_get_notices( 'notice' ), 'notice' ) ) );
	}

	public function test_a_ticket_works_once(): void {
		$user_id = $this->make_user();
		$this->link_member( $user_id );
		$ticket = Responses::SSO_TICKET_MEMBER . 'once01';

		$this->landing()->handle( $ticket, '/?pixalink_sso=' . $ticket );
		$this->assertSame( $user_id, get_current_user_id() );

		wp_set_current_user( 0 );
		$target = $this->landing()->handle( $ticket, '/?pixalink_sso=' . $ticket );

		$this->assertSame( home_url( '/' ), $target );
		$this->assertSame( 0, get_current_user_id() );
		$recorded = Server::recorded();
		$this->assertSame( 410, end( $recorded )['status'] );
		$this->assertStringContainsString( 'expired or was already used', implode( ' ', array_column( wc_get_notices( 'notice' ), 'notice' ) ) );
	}

	public function test_without_a_connection_the_ticket_is_only_stripped(): void {
		Plugin::instance()->credentials()->clear();

		$target = $this->landing()->handle( Responses::SSO_TICKET_MEMBER . 'abc123', '/?pixalink_sso=x&a=1' );

		$this->assertSame( home_url( '/?a=1' ), $target );
		$this->assertSame( array(), Server::recorded() );
	}
}
