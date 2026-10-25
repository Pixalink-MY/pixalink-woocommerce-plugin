<?php
/**
 * Integration tests for SettingsPage and ConnectionField.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Admin;

use Pixalink\Loyalty\Admin\ConnectionField;
use Pixalink\Loyalty\Admin\SettingsPage;
use Pixalink\Loyalty\Connect\ConnectController;
use Pixalink\Loyalty\Connect\Connection;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Support\Options;

/**
 * The WooCommerce settings tab renders through WooCommerce's own settings API.
 *
 * @covers \Pixalink\Loyalty\Admin\SettingsPage
 * @covers \Pixalink\Loyalty\Admin\ConnectionField
 */
final class SettingsPageTest extends \WP_UnitTestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		include_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';
		include_once WC_ABSPATH . 'includes/admin/settings/class-wc-settings-page.php';
	}

	public function set_up(): void {
		parent::set_up();

		Plugin::instance()->credentials()->clear();
		delete_option( Options::CONNECTION );
		delete_option( Options::REMOTE_STATUS );
		unset( $GLOBALS['hide_save_button'] );
		$GLOBALS['current_section'] = '';

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		unset( $GLOBALS['hide_save_button'], $GLOBALS['current_section'] );
		parent::tear_down();
	}

	private function page(): SettingsPage {
		foreach ( apply_filters( 'woocommerce_get_settings_pages', array() ) as $page ) {
			if ( $page instanceof SettingsPage ) {
				return $page;
			}
		}

		$this->fail( 'The Pixalink settings page is not registered.' );
	}

	private function render( string $section = '' ): string {
		$GLOBALS['current_section'] = $section;
		ob_start();
		$this->page()->output();

		return (string) ob_get_clean();
	}

	public function test_the_tab_and_its_sections_are_registered(): void {
		$page = $this->page();

		$this->assertSame( 'pixalink_loyalty', $page->get_id() );
		$this->assertSame( 'Pixalink Loyalty', $page->get_label() );
		$this->assertSame( array( '', 'checkout', 'account', 'advanced' ), array_keys( $page->get_sections() ) );
		$this->assertArrayHasKey( 'pixalink_loyalty', apply_filters( 'woocommerce_settings_tabs_array', array() ) );
	}

	public function test_the_connection_section_offers_both_connect_paths_without_a_save_button(): void {
		$html = $this->render();

		$this->assertTrue( $GLOBALS['hide_save_button'] );
		$this->assertStringContainsString( 'Connect existing Pixalink account', $html );
		$this->assertStringContainsString( 'I have an outlet API key', $html );
		$this->assertStringContainsString( 'Request access', $html );
		$this->assertStringContainsString( 'formaction="' . esc_url( admin_url( 'admin-post.php?action=pixalink_loyalty_connect_start' ) ) . '"', $html );
		$this->assertStringContainsString( 'formaction="' . esc_url( admin_url( 'admin-post.php?action=pixalink_loyalty_connect_key' ) ) . '"', $html );
		$this->assertStringContainsString( 'name="' . ConnectController::NONCE_FIELDS[ ConnectController::ACTION_START ] . '"', $html );
		$this->assertStringContainsString( 'name="' . ConnectController::NONCE_FIELDS[ ConnectController::ACTION_KEY ] . '"', $html );
		$this->assertMatchesRegularExpression( '/<input type="checkbox" name="pixalink_loyalty_marketing_consent" value="1" \/>/', $html, 'The marketing box is separate and unticked.' );
		$this->assertStringContainsString( 'Privacy policy', $html );
		$this->assertStringNotContainsString( 'Disconnect', $html );
	}

	public function test_a_connected_store_shows_its_outlet_and_the_test_and_disconnect_actions(): void {
		Plugin::instance()->connection()->mark_connected(
			array(
				'id'   => '229',
				'name' => 'Fake Cafe',
			),
			array(
				'id'   => '101',
				'name' => 'Fake Online Store',
			),
			get_current_user_id(),
			Connection::METHOD_HOSTED,
			null,
			'https://fake-pixalink.test/admin'
		);
		Plugin::instance()->connection()->store_remote_status(
			array(
				'status'   => 'active',
				'features' => array(
					'earn'              => true,
					'vouchers'          => true,
					'points_redemption' => false,
					'credits'           => false,
				),
				'earn_on'  => 'completed',
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( 'Fake Cafe', $html );
		$this->assertStringContainsString( 'Fake Online Store', $html );
		$this->assertStringContainsString( 'Test connection', $html );
		$this->assertStringContainsString( 'data-pixalink-confirm=', $html, 'Disconnect asks first.' );
		$this->assertStringContainsString( 'when the order is completed', $html );
		$this->assertStringContainsString( 'Points at checkout: off', $html );
		$this->assertStringContainsString( 'https://fake-pixalink.test/admin', $html );
		$this->assertStringNotContainsString( 'Connect existing Pixalink account', $html );
	}

	public function test_a_rejected_key_asks_to_reconnect(): void {
		Plugin::instance()->connection()->mark_connected( array(), array( 'name' => 'Fake Online Store' ), 1, Connection::METHOD_API_KEY, null, null );
		Plugin::instance()->connection()->mark_auth_failed( 'api_key_revoked' );

		$html = $this->render();

		$this->assertStringContainsString( 'Reconnect to Pixalink', $html );
		$this->assertStringContainsString( 'Connect existing Pixalink account', $html );
		$this->assertStringContainsString( 'Disconnect', $html );
	}

	public function test_other_sections_are_regular_woocommerce_settings_with_registered_defaults(): void {
		$html = $this->render( 'checkout' );
		$this->assertStringContainsString( 'id="' . Options::CHECKOUT_PANEL_ENABLED . '"', $html );
		$this->assertArrayNotHasKey( 'hide_save_button', $GLOBALS, 'Settings sections keep the Save button.' );

		$fields = array_column( $this->page()->get_settings_for_section( 'checkout' ), null, 'id' );
		$this->assertSame( 'yes', $fields[ Options::POINTS_REDEMPTION_ENABLED ]['default'] );
		$this->assertSame( 'yes', $fields[ Options::CREDITS_ENABLED ]['default'] );
		$this->assertArrayHasKey( 'desc_tip', $fields[ Options::CREDITS_ENABLED ], 'before the first heartbeat: Pixalink still has to confirm the feature' );
		$this->assertSame( 'optional', $fields[ Options::PHONE_FIELD ]['default'] );
		$this->assertArrayHasKey( Options::CHECKOUT_CONSENT_TEXT, $fields );
		$this->assertArrayNotHasKey( Options::CHECKOUT_CONSENT_DEFAULT_VALUE, $fields, 'Consent is never pre-ticked, so there is no setting for it.' );

		$account = array_column( $this->page()->get_settings_for_section( 'account' ), null, 'id' );
		$this->assertSame( 'checkbox', $account[ Options::ACCOUNT_DASHBOARD_CARD ]['type'] );
		$this->assertSame( 'yes', $account[ Options::ACCOUNT_DASHBOARD_CARD ]['default'] );
		$this->assertSame( 'checkbox', $account[ Options::PORTAL_BRANDING ]['type'] );
		$this->assertSame( 'yes', $account[ Options::PORTAL_BRANDING ]['default'], 'Match your member portal is on by default.' );
		$this->assertSame( 'Match your member portal', $account[ Options::PORTAL_BRANDING ]['title'] );
		$this->assertSame( "Use the logo and colours from your Pixalink member portal on My Account and checkout. Turn off to use your theme's colours.", $account[ Options::PORTAL_BRANDING ]['desc'] );

		$this->assertSame( 'checkbox', $account[ Options::PORTAL_SIGN_IN ]['type'] );
		$this->assertSame( 'yes', $account[ Options::PORTAL_SIGN_IN ]['default'], 'Sign in with your member portal is on by default.' );
		$this->assertSame( 'Sign in with your member portal', $account[ Options::PORTAL_SIGN_IN ]['title'] );
		$this->assertSame( "Adds a 'Sign in with your member portal' button to the login form. Members who sign in this way get a store account if they don't have one yet (they need an e-mail address in Pixalink).", $account[ Options::PORTAL_SIGN_IN ]['desc'], 'Before the heartbeat names the portal.' );

		( new \Pixalink\Loyalty\Connect\Connection( new Options() ) )->store_remote_status( \Pixalink\Loyalty\Tests\FakeServer\Responses::samples( time() )['heartbeat']['response']['body'] );
		$account = array_column( $this->page()->get_settings_for_section( 'account' ), null, 'id' );
		$this->assertStringStartsWith( "Adds a 'Sign in with Fake Cafe' button to the login form.", $account[ Options::PORTAL_SIGN_IN ]['desc'], 'The portal by its name.' );
		delete_option( Options::REMOTE_STATUS );

		$advanced = array_column( $this->page()->get_settings_for_section( 'advanced' ), null, 'id' );
		$this->assertArrayHasKey( Options::DEBUG_LOGGING, $advanced );
		$this->assertArrayHasKey( Options::DELETE_DATA_ON_UNINSTALL, $advanced );
	}

	public function test_redemption_toggles_say_when_pixalink_switched_the_feature_off(): void {
		( new \Pixalink\Loyalty\Connect\Connection( new Options() ) )->store_remote_status(
			array(
				'status'   => 'active',
				'features' => array(
					'earn'              => true,
					'vouchers'          => true,
					'points_redemption' => true,
					'credits'           => false,
					'otp_channels'      => array(),
				),
			)
		);

		$fields = array_column( $this->page()->get_settings_for_section( 'checkout' ), null, 'id' );

		$this->assertStringContainsString( 'switched off by Pixalink', $fields[ Options::CREDITS_ENABLED ]['desc'] );
		$this->assertStringNotContainsString( 'switched off by Pixalink', $fields[ Options::VOUCHERS_ENABLED ]['desc'] );
	}

	public function test_saving_a_section_writes_the_registered_options(): void {
		$fields = $this->page()->get_settings_for_section( 'account' );

		\WC_Admin_Settings::save_fields(
			$fields,
			array(
				Options::ACCOUNT_TAB_TITLE => 'Rewards',
				Options::ACCOUNT_SHOW_QR   => '',
			)
		);

		$options = new Options();
		$this->assertSame( 'Rewards', $options->get_string( Options::ACCOUNT_TAB_TITLE ) );
		$this->assertFalse( $options->get_bool( Options::ACCOUNT_SHOW_QR ) );
		$this->assertFalse( $options->get_bool( Options::ACCOUNT_TAB_ENABLED ), 'An unticked checkbox saves as "no".' );
	}

	public function test_the_connection_card_is_not_saved_as_an_option(): void {
		$card = array_values( array_filter( $this->page()->get_settings_for_section( '' ), static fn ( array $field ): bool => ConnectionField::TYPE === $field['type'] ) );

		$this->assertCount( 1, $card );
		$this->assertFalse( $card[0]['is_option'] );
	}

	public function test_the_settings_link_is_added_on_the_plugins_screen(): void {
		$links = apply_filters( 'plugin_action_links_' . plugin_basename( PIXALINK_LOYALTY_FILE ), array( 'deactivate' => '<a>Deactivate</a>' ) );

		$this->assertSame( array( 'settings', 'deactivate' ), array_keys( $links ) );
		$this->assertStringContainsString( 'tab=pixalink_loyalty', $links['settings'] );
		$this->assertStringContainsString( '>Connect<', $links['settings'], 'Not connected yet.' );
	}
}
