<?php
/**
 * Integration tests for DashboardCard.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Account;

use Pixalink\Loyalty\Account\Assets;
use Pixalink\Loyalty\Account\DashboardCard;
use Pixalink\Loyalty\Account\LoyaltyEndpoint;
use Pixalink\Loyalty\Connect\Connection;
use Pixalink\Loyalty\Identity\Link;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\FakeServer\Responses;
use Pixalink\Loyalty\Tests\FakeServer\Server;
use Pixalink\Loyalty\Tests\Integration\Support\StoreFixtures;

/**
 * The loyalty card on My Account › Dashboard for each shopper state: verified (balance, tier, vouchers), unlinked
 * (a prompt that opens the Loyalty tab, no API call) and every case that renders nothing (outage, setting off, store
 * not connected).
 *
 * @covers \Pixalink\Loyalty\Account\DashboardCard
 */
final class DashboardCardTest extends \WP_UnitTestCase {

	use StoreFixtures;

	private const THEME_DIR = __DIR__ . '/fixtures/theme';

	public function set_up(): void {
		parent::set_up();

		$this->set_up_store();
		delete_option( Options::ACCOUNT_DASHBOARD_CARD );
		delete_option( Options::ACCOUNT_TAB_ENABLED );
		delete_option( Options::DEBUG_LOGGING );
		delete_option( Options::PORTAL_BRANDING );
		delete_option( Options::REMOTE_STATUS );
		$GLOBALS['wp_styles'] = null;
		wp_dequeue_style( DashboardCard::STYLE );
		wp_dequeue_script( Assets::ACCOUNT_HANDLE );
	}

	public function tear_down(): void {
		delete_option( Options::PORTAL_BRANDING );
		delete_option( Options::REMOTE_STATUS );
		$GLOBALS['wp_styles'] = null;
		remove_all_filters( 'woocommerce_logging_class' );
		remove_filter( 'stylesheet_directory', array( self::class, 'theme_dir' ) );
		wp_set_template_globals();
		$this->tear_down_store();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * My Account content with no endpoint in the request: WooCommerce's dashboard template.
	 *
	 * @return string
	 */
	private static function dashboard(): string {
		$GLOBALS['wp']->query_vars = array();

		ob_start();
		woocommerce_account_content();

		return (string) ob_get_clean();
	}

	/**
	 * The fake server's heartbeat body.
	 *
	 * @return array<string,mixed>
	 */
	private static function heartbeat(): array {
		return Responses::samples( time() )['heartbeat']['response']['body'];
	}

	/**
	 * Cache a heartbeat response as the plugin would.
	 *
	 * @param array<string,mixed> $status Heartbeat body.
	 * @return void
	 */
	private function cache_heartbeat( array $status ): void {
		( new Connection( new Options() ) )->store_remote_status( $status );
	}

	/**
	 * What the account stylesheet handle prints (link tag and inline rule).
	 *
	 * @return string
	 */
	private static function printed_account_styles(): string {
		ob_start();
		wp_styles()->do_items( array( Assets::ACCOUNT_STYLE ) );

		return (string) ob_get_clean();
	}

	private function verified_member(): int {
		$user_id = $this->make_user();
		UserMeta::store_link( $user_id, new Link( Responses::MEMBER_ID, Responses::MEMBER_PHONE, 'MY', '2026-10-25T02:00:00Z', Link::VIA_OTP ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	private function unlinked_shopper(): int {
		$user_id = $this->make_user();
		update_user_meta( $user_id, UserMeta::PHONE_E164, '+60123456789' );
		update_user_meta( $user_id, UserMeta::PHONE_COUNTRY, 'MY' );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	public static function theme_dir(): string {
		return self::THEME_DIR;
	}

	public function test_the_setting_is_on_by_default(): void {
		$this->assertTrue( ( new Options() )->get_bool( Options::ACCOUNT_DASHBOARD_CARD ) );
	}

	public function test_a_verified_member_sees_balance_tier_and_vouchers_with_a_link_to_the_loyalty_tab(): void {
		$this->verified_member();

		$html = self::dashboard();

		$this->assertStringContainsString( 'From your account dashboard', $html, 'WooCommerce\'s own dashboard text stays.' );
		$this->assertStringContainsString( 'pixalink-loyalty-dashboard-card', $html );
		$this->assertStringContainsString( '1,250', $html );
		$this->assertStringContainsString( '200 points expire on', $html );
		$this->assertStringContainsString( 'Silver', $html );
		$this->assertStringContainsString( '300 more to reach Gold', $html );
		$this->assertStringContainsString( '1 voucher ready', $html, 'Only vouchers usable in this online store count.' );
		$this->assertStringContainsString( 'href="' . esc_url( wc_get_account_endpoint_url( LoyaltyEndpoint::ENDPOINT ) ) . '"', $html );
		$this->assertStringNotContainsString( 'Credits', $html, 'Credits stay on the Loyalty tab.' );
		$this->assertStringNotContainsString( '15.00', $html );
		$this->assertStringNotContainsString( 'data-pixalink-link-form', $html );
		$this->assertTrue( wp_style_is( DashboardCard::STYLE, 'enqueued' ) );
		$this->assertCount( 1, Server::recorded() );
	}

	public function test_the_card_reuses_the_summary_the_loyalty_tab_just_fetched(): void {
		$this->verified_member();

		ob_start();
		do_action( 'woocommerce_account_' . LoyaltyEndpoint::ENDPOINT . '_endpoint' );
		ob_end_clean();
		$html = self::dashboard();

		$this->assertStringContainsString( '1,250', $html );
		$this->assertCount( 1, Server::recorded(), 'The dashboard reads the 60 s cache; no second summary call.' );
	}

	public function test_an_unlinked_shopper_gets_a_prompt_to_the_loyalty_tab_and_no_api_call(): void {
		$this->unlinked_shopper();

		$html = self::dashboard();

		$this->assertStringContainsString( 'Link your Pixalink membership to see your points and vouchers', $html );
		$this->assertStringContainsString( 'href="' . esc_url( wc_get_account_endpoint_url( LoyaltyEndpoint::ENDPOINT ) ) . '"', $html );
		$this->assertStringNotContainsString( 'data-pixalink-link-form', $html, 'The code flow stays on the Loyalty tab.' );
		$this->assertStringNotContainsString( 'data-pixalink-otp-dialog', $html );
		$this->assertFalse( wp_script_is( Assets::ACCOUNT_HANDLE, 'enqueued' ) );
		$this->assertStringNotContainsString( '1,250', $html, 'No balance before the phone is verified.' );
		$this->assertSame( array(), Server::recorded(), 'Unverified shoppers cause no summary call.' );
	}

	public function test_the_card_wears_the_portal_colours_and_opens_the_portal_signed_in(): void {
		$this->cache_heartbeat( self::heartbeat() );
		$this->verified_member();

		$html = self::dashboard();
		$css  = self::printed_account_styles();

		$this->assertStringContainsString( 'pixalink-loyalty-dashboard-card is-branded', $html );
		$this->assertStringContainsString( 'pixalink-loyalty-card pixalink-loyalty-card--compact', $html );
		$this->assertStringContainsString( '.pixalink-loyalty-account,.pixalink-loyalty-dashboard-card,.pixalink-loyalty-link{--pxl-primary:' . Responses::BRAND_PRIMARY, $css );
		$this->assertStringContainsString( 'style="--pxl-tier:#c0c0c0"', $html );
		$this->assertMatchesRegularExpression( '#href="' . preg_quote( esc_url( admin_url( 'admin-post.php' ) ), '#' ) . '\?action=pixalink_loyalty_open_portal&\#038;destination=home&\#038;_wpnonce=[0-9a-f]+" target="_blank" rel="noopener">Open member portal</a>#', $html );
		$this->assertStringNotContainsString( '/handoff/', $html );
		$this->assertCount( 1, Server::recorded(), 'Only the summary; the sign-in link is minted at the click.' );
	}

	public function test_no_portal_colours_on_the_card_with_the_setting_off_or_without_branding(): void {
		update_option( Options::PORTAL_BRANDING, 'no' );
		$this->cache_heartbeat( self::heartbeat() );
		$member_id = $this->verified_member();

		$html = self::dashboard();
		$this->assertStringNotContainsString( 'is-branded', $html );
		$this->assertStringNotContainsString( '--pxl-primary:', self::printed_account_styles() );
		$this->assertStringContainsString( 'Open member portal', $html, 'The signed-in link stays.' );

		delete_option( Options::PORTAL_BRANDING );
		$status = self::heartbeat();
		unset( $status['branding'] );
		$this->cache_heartbeat( $status );
		$GLOBALS['wp_styles'] = null;
		wp_set_current_user( $member_id );

		$this->assertStringNotContainsString( 'is-branded', self::dashboard() );
		$this->assertStringNotContainsString( '--pxl-primary:', self::printed_account_styles() );
	}

	public function test_an_unlinked_shopper_keeps_the_verify_link_first_and_a_signed_out_portal_link(): void {
		$this->cache_heartbeat( self::heartbeat() );
		$this->unlinked_shopper();

		$html = self::dashboard();

		$this->assertLessThan( (int) strpos( $html, 'Open member portal' ), (int) strpos( $html, 'Link your membership' ) );
		$this->assertStringContainsString( 'href="' . Responses::PORTAL_URL . '" target="_blank" rel="noopener">Open member portal</a>', $html );
		$this->assertStringNotContainsString( 'admin-post.php', $html );
		$this->assertSame( array(), Server::recorded() );
	}

	public function test_a_failed_summary_renders_nothing_logs_at_debug_and_the_dashboard_still_renders(): void {
		update_option( Options::DEBUG_LOGGING, 'yes' );
		$logger = new class() extends \WC_Logger {
			/**
			 * Entries: [level, message, source].
			 *
			 * @var list<array{0:string,1:string,2:string}>
			 */
			public array $entries = array();

			public function log( $level, $message, $context = array() ) {
				$this->entries[] = array( (string) $level, (string) $message, (string) ( $context['source'] ?? '' ) );
			}
		};
		add_filter( 'woocommerce_logging_class', static fn () => $logger );

		$member_id = $this->verified_member();
		$request   = new \WP_REST_Request( 'POST', '/' . Server::CONTROL_NAMESPACE . '/script' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'method' => 'GET',
					'path'   => 'customers/*',
					'status' => 500,
					'body'   => array(
						'message' => 'Server error.',
						'code'    => 'server_error',
					),
				)
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 201, rest_do_request( $request )->get_status() );
		wp_set_current_user( $member_id );

		$html = self::dashboard();

		$this->assertStringContainsString( 'From your account dashboard', $html );
		$this->assertStringNotContainsString( 'pixalink-loyalty', $html, 'No card, no notice, no spinner.' );
		$this->assertFalse( wp_style_is( DashboardCard::STYLE, 'enqueued' ) );
		$this->assertTrue( UserMeta::is_verified( $member_id ), 'An outage never unlinks.' );

		$card_entries = array_values( array_filter( $logger->entries, static fn ( array $entry ): bool => str_contains( $entry[1], 'dashboard' ) ) );
		$this->assertCount( 1, $card_entries );
		$this->assertSame( array( 'debug', Logger::SOURCE ), array( $card_entries[0][0], $card_entries[0][2] ) );
	}

	public function test_nothing_renders_when_the_card_is_switched_off(): void {
		update_option( Options::ACCOUNT_DASHBOARD_CARD, 'no' );
		$this->verified_member();

		$html = self::dashboard();

		$this->assertStringContainsString( 'From your account dashboard', $html );
		$this->assertStringNotContainsString( 'pixalink-loyalty', $html );
		$this->assertSame( array(), Server::recorded() );
	}

	public function test_nothing_renders_without_the_loyalty_tab_it_links_to(): void {
		update_option( Options::ACCOUNT_TAB_ENABLED, 'no' );
		$this->unlinked_shopper();

		$this->assertStringNotContainsString( 'pixalink-loyalty', self::dashboard() );
	}

	public function test_nothing_renders_while_the_store_is_not_connected(): void {
		Plugin::instance()->credentials()->clear();
		$this->verified_member();

		$html = self::dashboard();

		$this->assertStringNotContainsString( 'pixalink-loyalty', $html );
		$this->assertSame( array(), Server::recorded() );
	}

	public function test_nothing_renders_while_pixalink_has_paused_the_store(): void {
		$this->cache_heartbeat( array( 'status' => 'commerce_paused' ) + self::heartbeat() );
		$this->verified_member();

		$html = self::dashboard();

		$this->assertStringContainsString( 'From your account dashboard', $html );
		$this->assertStringNotContainsString( 'pixalink-loyalty', $html );
		$this->assertSame( array(), Server::recorded(), 'No summary call while the store is paused.' );
	}

	public function test_a_template_in_the_theme_overrides_the_card(): void {
		add_filter( 'stylesheet_directory', array( self::class, 'theme_dir' ) );
		wp_set_template_globals();
		$this->verified_member();

		$html = self::dashboard();

		$this->assertStringContainsString( 'Theme dashboard card: 1250 points', $html );
		$this->assertStringNotContainsString( 'pixalink-loyalty-dashboard-card__points', $html );
	}
}
