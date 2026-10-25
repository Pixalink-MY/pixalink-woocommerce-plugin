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
use Pixalink\Loyalty\Identity\SsoLanding;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Tests\FakeServer\Responses;
use Pixalink\Loyalty\Tests\FakeServer\Server;
use Pixalink\Loyalty\Tests\Integration\Support\StoreFixtures;

/**
 * `?pixalink_sso=` from the Pixalink portal: redeemed once, signs shoppers in (never staff), links accounts, and the
 * redirect target never carries the ticket.
 *
 * @covers \Pixalink\Loyalty\Identity\SsoLanding
 * @covers \Pixalink\Loyalty\Identity\CustomerLinker::redeem_ticket
 * @covers \Pixalink\Loyalty\Identity\Module::on_shopper_verified
 */
final class SsoLandingTest extends \WP_UnitTestCase {

	use StoreFixtures;

	public function set_up(): void {
		parent::set_up();

		$this->set_up_store();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$this->tear_down_store();

		parent::tear_down();
	}

	private function landing(): SsoLanding {
		return new SsoLanding(
			Plugin::instance()->identity()->linker(),
			Plugin::instance()->credentials(),
			Plugin::instance()->identity()->guests(),
			Plugin::instance()->identity()->normalizer(),
			Plugin::instance()->logger()
		);
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
	}

	public function test_without_an_email_the_member_stays_a_verified_guest(): void {
		$this->landing()->handle( Responses::SSO_TICKET_NO_EMAIL . 'abc1', '/?pixalink_sso=' . Responses::SSO_TICKET_NO_EMAIL . 'abc1' );

		$this->assertSame( 0, get_current_user_id() );
		$session_id = ShopperContext::current_session_id();
		$this->assertNotSame( '', $session_id );
		$this->assertSame( Responses::NEW_CUSTOMER_ID, ( new GuestLinkStore() )->get( $session_id )?->customer_id );
		$this->assertSame( ShopperContextInterface::STATE_VERIFIED, Plugin::instance()->shopper()->state() );
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
