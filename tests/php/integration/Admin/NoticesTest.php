<?php
/**
 * Integration tests for Notices.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Admin;

use Pixalink\Loyalty\Admin\Notices;
use Pixalink\Loyalty\Connect\Connection;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\FakeServer\Server;
use Pixalink\Loyalty\Tests\FakeServer\Signature;

/**
 * Which admin screens show which notices, through the real `admin_notices` hook.
 *
 * @covers \Pixalink\Loyalty\Admin\Notices
 */
final class NoticesTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		delete_option( Options::CONNECTION );
		delete_option( Options::REMOTE_STATUS );
		update_option( 'woocommerce_enable_coupons', 'yes' );
		$_GET = array();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$_GET = array();
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Pixalink notice ids printed on a screen.
	 *
	 * @param string $screen Screen id.
	 * @return list<string>
	 */
	private function notices_on( string $screen ): array {
		set_current_screen( $screen );
		ob_start();
		Plugin::instance()->notices()->render();
		$html = (string) ob_get_clean();

		preg_match_all( '/data-pixalink-notice="([a-z_]+)"/', $html, $matches );

		return $matches[1];
	}

	public function test_not_connected_shows_only_on_plugins_and_woocommerce_screens(): void {
		$this->assertSame( array( Notices::NOT_CONNECTED ), $this->notices_on( 'plugins' ) );
		$this->assertSame( array( Notices::NOT_CONNECTED ), $this->notices_on( 'woocommerce_page_wc-orders' ) );
		$this->assertSame( array(), $this->notices_on( 'edit-post' ) );
		$this->assertSame( array(), $this->notices_on( 'dashboard' ) );
		$this->assertSame( array(), $this->notices_on( 'options-general' ) );
		$this->assertNotFalse( has_action( 'admin_notices', array( Plugin::instance()->notices(), 'render' ) ) );
	}

	public function test_dismissing_hides_it_for_that_user_only(): void {
		$first = get_current_user_id();
		Plugin::instance()->notices()->dismiss_for_user( $first, Notices::NOT_CONNECTED );

		$this->assertSame( array(), $this->notices_on( 'plugins' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( array( Notices::NOT_CONNECTED ), $this->notices_on( 'plugins' ) );
	}

	public function test_users_who_cannot_manage_woocommerce_see_nothing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( array(), $this->notices_on( 'plugins' ) );
	}

	public function test_auth_failure_and_disabled_coupons_while_connected(): void {
		Plugin::instance()->connection()->mark_connected( array(), array( 'id' => '101' ), 1, Connection::METHOD_HOSTED, null, null );
		Plugin::instance()->connection()->mark_auth_failed( 'api_key_revoked' );
		update_option( 'woocommerce_enable_coupons', 'no' );

		$this->assertSame( array( Notices::AUTH_FAILED, Notices::COUPONS_DISABLED ), $this->notices_on( 'edit-shop_order' ) );
	}

	public function test_a_heartbeat_from_a_store_pixalink_paused_shows_why_until_it_is_back_on(): void {
		Server::reset();
		Server::set_commerce_paused( true );
		Plugin::instance()->credentials()->store( Signature::FAKE_API_KEY );
		Plugin::instance()->connection()->mark_connected( array(), array( 'id' => '101' ), 1, Connection::METHOD_HOSTED, null, null );

		try {
			Plugin::instance()->heartbeat()->run();

			$this->assertSame( array( Notices::COMMERCE_PAUSED ), $this->notices_on( 'plugins' ) );
			set_current_screen( 'woocommerce_page_wc-orders' );
			ob_start();
			Plugin::instance()->notices()->render();
			$this->assertStringContainsString( 'Pixalink has paused this store. Points and vouchers are off until Pixalink turns it back on. Contact Pixalink support.', (string) ob_get_clean() );

			Server::set_commerce_paused( false );
			Plugin::instance()->heartbeat()->run();

			$this->assertSame( array(), $this->notices_on( 'plugins' ) );
		} finally {
			Plugin::instance()->credentials()->clear();
			Server::reset();
		}
	}

	public function test_the_settings_tab_shows_the_result_of_the_last_action(): void {
		Plugin::instance()->connection()->mark_connected( array(), array( 'id' => '101' ), 1, Connection::METHOD_HOSTED, null, null );
		$_GET = array(
			'page'            => 'wc-settings',
			'tab'             => 'pixalink_loyalty',
			'pixalink_notice' => 'connected',
		);

		$this->assertSame( array( 'result' ), $this->notices_on( 'woocommerce_page_wc-settings' ) );

		$_GET['pixalink_notice'] = 'error_site_already_connected';
		set_current_screen( 'woocommerce_page_wc-settings' );
		ob_start();
		Plugin::instance()->notices()->render();
		$this->assertStringContainsString( 'already connected to another WooCommerce store', (string) ob_get_clean() );
	}
}
