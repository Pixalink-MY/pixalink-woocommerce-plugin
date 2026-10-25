<?php
/**
 * Integration tests for LoyaltyEndpoint and AccountView.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Account;

use Pixalink\Loyalty\Account\LoyaltyEndpoint;
use Pixalink\Loyalty\Identity\Link;
use Pixalink\Loyalty\Identity\ShopperContext;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\FakeServer\Responses;
use Pixalink\Loyalty\Tests\FakeServer\Server;
use Pixalink\Loyalty\Tests\Integration\Support\StoreFixtures;

/**
 * My Account › Loyalty for each shopper state: logged out, unlinked (phone form, no balances), verified (summary,
 * vouchers, QR) and Pixalink unreachable.
 *
 * @covers \Pixalink\Loyalty\Account\LoyaltyEndpoint
 * @covers \Pixalink\Loyalty\Account\AccountView
 * @covers \Pixalink\Loyalty\Account\QrService
 * @covers \Pixalink\Loyalty\Account\SummaryService
 */
final class LoyaltyEndpointTest extends \WP_UnitTestCase {

	use StoreFixtures;

	public function set_up(): void {
		parent::set_up();

		$this->set_up_store();
		delete_option( Options::ACCOUNT_TAB_ENABLED );
		delete_option( Options::ACCOUNT_TAB_TITLE );
		delete_option( Options::ACCOUNT_SHOW_QR );
	}

	public function tear_down(): void {
		$this->tear_down_store();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	private static function tab(): string {
		ob_start();
		do_action( 'woocommerce_account_' . LoyaltyEndpoint::ENDPOINT . '_endpoint' );

		return (string) ob_get_clean();
	}

	private function verified_member(): int {
		$user_id = $this->make_user();
		UserMeta::store_link( $user_id, new Link( Responses::MEMBER_ID, Responses::MEMBER_PHONE, 'MY', '2026-10-25T02:00:00Z', Link::VIA_OTP ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	public function test_the_endpoint_and_menu_item_are_registered_before_log_out(): void {
		$this->assertArrayHasKey( LoyaltyEndpoint::ENDPOINT, WC()->query->get_query_vars() );

		$items = wc_get_account_menu_items();
		$keys  = array_keys( $items );
		$this->assertSame( 'Loyalty', $items[ LoyaltyEndpoint::ENDPOINT ] );
		$this->assertSame( array_search( 'customer-logout', $keys, true ) - 1, array_search( LoyaltyEndpoint::ENDPOINT, $keys, true ) );

		update_option( Options::ACCOUNT_TAB_TITLE, 'Rewards' );
		$this->assertSame( 'Rewards', wc_get_account_menu_items()[ LoyaltyEndpoint::ENDPOINT ] );

		update_option( Options::ACCOUNT_TAB_ENABLED, 'no' );
		$this->assertArrayNotHasKey( LoyaltyEndpoint::ENDPOINT, wc_get_account_menu_items() );
		$this->assertSame( '', self::tab() );
	}

	public function test_the_rewrite_endpoint_exists_and_install_asks_for_one_flush(): void {
		global $wp_rewrite;

		$this->assertContains( LoyaltyEndpoint::ENDPOINT, array_column( $wp_rewrite->endpoints, 1 ) );

		delete_option( Options::FLUSH_REWRITE_RULES );
		\Pixalink\Loyalty\Install\Installer::install();
		$this->assertTrue( ( new Options() )->get_bool( Options::FLUSH_REWRITE_RULES ) );

		$callback = null;
		foreach ( $GLOBALS['wp_filter']['init']->callbacks[99] ?? array() as $hooked ) {
			if ( is_array( $hooked['function'] ) && $hooked['function'][0] instanceof LoyaltyEndpoint ) {
				$callback = $hooked['function'];
			}
		}
		$this->assertIsCallable( $callback );
		call_user_func( $callback );
		$this->assertFalse( ( new Options() )->get_bool( Options::FLUSH_REWRITE_RULES ), 'Flushed once, then the flag is gone.' );
	}

	public function test_the_checkout_verify_link_opens_the_tab_for_logged_in_shoppers_only(): void {
		$this->assertSame( '', apply_filters( 'pixalink_loyalty_verify_url', '' ), 'Guests keep the prompt without a link.' );

		wp_set_current_user( $this->make_user() );
		$this->assertSame( wc_get_account_endpoint_url( LoyaltyEndpoint::ENDPOINT ), apply_filters( 'pixalink_loyalty_verify_url', '' ) );

		update_option( Options::ACCOUNT_TAB_ENABLED, 'no' );
		$this->assertSame( '', apply_filters( 'pixalink_loyalty_verify_url', '' ), 'No tab, no link.' );
	}

	public function test_a_verify_link_set_by_another_callback_is_kept(): void {
		wp_set_current_user( $this->make_user() );
		$custom = static fn (): string => 'https://shop.example.test/verify-phone/';
		add_filter( 'pixalink_loyalty_verify_url', $custom, 1 );

		$this->assertSame( 'https://shop.example.test/verify-phone/', apply_filters( 'pixalink_loyalty_verify_url', '' ) );

		remove_filter( 'pixalink_loyalty_verify_url', $custom, 1 );
	}

	public function test_a_logged_out_visitor_is_asked_to_log_in_by_the_shortcode(): void {
		$html = do_shortcode( '[' . LoyaltyEndpoint::SUMMARY_SHORTCODE . ']' );

		$this->assertStringContainsString( 'Log in</a> to see your points', $html );
		$this->assertSame( '', do_shortcode( '[' . LoyaltyEndpoint::QR_SHORTCODE . ']' ) );
		$this->assertSame( array(), Server::recorded() );
	}

	public function test_a_guest_recognised_from_the_portal_is_told_so_above_the_login_prompt(): void {
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'yes' );
		Plugin::instance()->identity()->guests()->put( ShopperContext::current_session_id(), new Link( Responses::MEMBER_ID, Responses::MEMBER_PHONE, 'MY', '2026-10-25T02:00:00Z', Link::VIA_SSO_TICKET ) );

		$html = do_shortcode( '[' . LoyaltyEndpoint::SUMMARY_SHORTCODE . ']' );

		$this->assertStringContainsString( "We've recognised your Pixalink membership for this visit, so you'll earn points on your order. Create an account at checkout to see your balance here.", $html );
		$this->assertStringContainsString( 'Log in</a> to see your points', $html );
		$this->assertStringNotContainsString( '1,250', $html, 'A guest link never shows balances.' );

		ob_start();
		$GLOBALS['wp']->query_vars[ LoyaltyEndpoint::ENDPOINT ] = '';
		do_action( 'woocommerce_before_customer_login_form' );
		unset( $GLOBALS['wp']->query_vars[ LoyaltyEndpoint::ENDPOINT ] );
		do_action( 'woocommerce_before_customer_login_form' );
		$login_form = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $login_form, 'recognised your Pixalink membership' ), 'Above the login form of the Loyalty tab only.' );
	}

	public function test_a_logged_out_visitor_without_a_guest_link_sees_no_recognised_message(): void {
		$this->assertStringNotContainsString( 'recognised', do_shortcode( '[' . LoyaltyEndpoint::SUMMARY_SHORTCODE . ']' ) );
	}

	public function test_a_staff_account_is_told_why_instead_of_getting_the_phone_form(): void {
		wp_set_current_user( $this->make_user( 'staff@example.com', 'shop_manager' ) );

		$html = self::tab();

		$this->assertStringContainsString( "You're signed in to this store as staff, so a Pixalink membership can't be connected to this account. Log out to shop as a member.", $html );
		$this->assertStringNotContainsString( 'data-pixalink-link-form', $html );
		$this->assertSame( array(), Server::recorded() );
	}

	public function test_an_unlinked_shopper_sees_the_phone_form_and_never_a_balance(): void {
		$user_id = $this->make_user();
		update_user_meta( $user_id, UserMeta::PHONE_E164, '+60123456789' );
		update_user_meta( $user_id, UserMeta::PHONE_COUNTRY, 'MY' );
		update_user_meta( $user_id, UserMeta::CUSTOMER_ID, Responses::MEMBER_ID ); // No verification time: not a link.
		wp_set_current_user( $user_id );

		$html = self::tab();

		$this->assertStringContainsString( 'Verify your phone to see your points', $html );
		$this->assertStringContainsString( 'data-pixalink-link-form', $html );
		$this->assertStringContainsString( 'value="123456789"', $html, 'The saved number is prefilled.' );
		$this->assertStringNotContainsString( '1,250', $html );
		$this->assertSame( array(), Server::recorded(), 'Unverified shoppers cause no summary call.' );
		$this->assertSame( '', do_shortcode( '[' . LoyaltyEndpoint::QR_SHORTCODE . ']' ) );
	}

	public function test_a_verified_member_sees_points_credits_tier_vouchers_and_the_qr(): void {
		$this->verified_member();

		$html = self::tab();

		$this->assertStringContainsString( '1,250', $html );
		$this->assertStringContainsString( '200 points expire on', $html );
		$this->assertStringContainsString( 'Credits: 15.00', $html );
		$this->assertStringNotContainsString( 'RM15', $html, 'Credits are never shown as money.' );
		$this->assertStringContainsString( 'Silver', $html );
		$this->assertStringContainsString( '300 more to reach Gold', $html );
		$this->assertStringContainsString( 'RM10 off', $html );
		$this->assertStringContainsString( 'Use it at <a href="' . esc_url( wc_get_checkout_url() ) . '">checkout</a>', $html );
		$this->assertStringContainsString( 'Not available in this online store.', $html );
		$this->assertStringContainsString( 'Coffee Club', $html );
		$this->assertStringContainsString( 'data-pixalink-qr="' . Responses::MEMBER_PHONE . '"', $html );
		$this->assertStringContainsString( 'https://fake-pixalink.test/loyalty', $html );

		$this->assertStringContainsString( 'data-pixalink-qr', do_shortcode( '[' . LoyaltyEndpoint::QR_SHORTCODE . ']' ) );
		$this->assertStringContainsString( '1,250', do_shortcode( '[' . LoyaltyEndpoint::SUMMARY_SHORTCODE . ']' ) );
		$this->assertCount( 1, Server::recorded(), 'One summary call, then the 60 s cache.' );
	}

	public function test_the_qr_can_be_switched_off(): void {
		update_option( Options::ACCOUNT_SHOW_QR, 'no' );
		$this->verified_member();

		$this->assertStringNotContainsString( 'data-pixalink-qr', self::tab() );
	}

	public function test_an_outage_shows_a_notice_instead_of_balances(): void {
		$member_id = $this->verified_member();
		$request   = new \WP_REST_Request( 'POST', '/' . Server::CONTROL_NAMESPACE . '/script' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'method' => 'GET',
					'path'   => 'customers/*',
					'status' => 503,
					'body'   => array(
						'message' => 'Down.',
						'code'    => 'maintenance',
					),
				)
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 201, rest_do_request( $request )->get_status() );
		wp_set_current_user( $member_id );

		$html = self::tab();

		$this->assertStringContainsString( 'temporarily unavailable', $html );
		$this->assertStringNotContainsString( 'Verify your phone', $html, 'An outage never unlinks.' );
	}

	public function test_templates_can_be_overridden_by_the_theme(): void {
		$this->verified_member();
		add_filter(
			'wc_get_template',
			static function ( $template, $name ) {
				return 'myaccount/loyalty.php' === $name ? __DIR__ . '/fixtures/loyalty-override.php' : $template;
			},
			10,
			2
		);

		$this->assertStringContainsString( 'Theme loyalty template', self::tab() );
	}
}
