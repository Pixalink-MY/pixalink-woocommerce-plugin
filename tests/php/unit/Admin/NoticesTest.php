<?php
/**
 * Tests for Notices.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Admin\Notices;
use Pixalink\Loyalty\Connect\ConnectException;
use Pixalink\Loyalty\Connect\Connection;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * Which state notices show where, per-user dismissal and result messages.
 *
 * @covers \Pixalink\Loyalty\Admin\Notices
 */
final class NoticesTest extends TestCase {

	/**
	 * User meta: user id => key => value.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $user_meta = array();

	/**
	 * Whether WooCommerce coupons are enabled.
	 *
	 * @var bool
	 */
	private bool $coupons_enabled = true;

	private Connection $connection;

	protected function set_up(): void {
		parent::set_up();

		$this->user_meta       = array();
		$this->coupons_enabled = true;
		$this->connection      = new Connection( new Options() );

		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'get_user_meta' )->alias( fn ( int $user_id, string $key ) => $this->user_meta[ $user_id ][ $key ] ?? '' );
		Functions\when( 'update_user_meta' )->alias(
			function ( int $user_id, string $key, $value ): bool {
				$this->user_meta[ $user_id ][ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'wc_get_screen_ids' )->justReturn( array( 'woocommerce_page_wc-settings', 'woocommerce_page_wc-orders', 'edit-shop_order' ) );
		Functions\when( 'wc_coupons_enabled' )->alias( fn (): bool => $this->coupons_enabled );
		Functions\when( 'admin_url' )->alias( static fn ( string $path = '' ): string => 'https://shop.example.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn ( array $args, string $url ): string => $url . '&' . http_build_query( $args ) );
	}

	private function notices(): Notices {
		return new Notices( $this->connection, '0.1.0' );
	}

	/**
	 * Ids of the notices for a screen.
	 *
	 * @param string $screen_id Screen.
	 * @param bool   $on_tab    Whether on the plugin's tab.
	 * @return list<string>
	 */
	private function ids( string $screen_id, bool $on_tab = false ): array {
		return array_column( $this->notices()->notices_for_screen( $screen_id, $on_tab ), 'id' );
	}

	private function connect(): void {
		$this->connection->mark_connected( array( 'name' => 'Demo Cafe' ), array( 'id' => '101' ), 3, Connection::METHOD_HOSTED, null, null );
	}

	public function test_not_connected_shows_on_plugins_and_woocommerce_screens_only(): void {
		$this->assertSame( array( Notices::NOT_CONNECTED ), $this->ids( 'plugins' ) );
		$this->assertSame( array(), $this->ids( 'dashboard' ) );
		$this->assertSame( array( Notices::NOT_CONNECTED ), $this->ids( 'woocommerce_page_wc-orders' ) );
		$this->assertSame( array(), $this->ids( 'edit-post' ) );
		$this->assertSame( array(), $this->ids( 'upload' ) );
		$this->assertSame( array(), $this->ids( 'woocommerce_page_wc-settings', true ), 'The connection card already explains it on the plugin tab.' );
	}

	public function test_not_connected_can_be_dismissed_per_user(): void {
		$this->notices()->dismiss_for_user( 3, Notices::NOT_CONNECTED );

		$this->assertSame( array(), $this->ids( 'plugins' ) );
		$this->assertSame( array( Notices::NOT_CONNECTED ), $this->user_meta[3][ Notices::USER_META ] );

		Functions\when( 'get_current_user_id' )->justReturn( 4 );
		$this->assertSame( array( Notices::NOT_CONNECTED ), $this->ids( 'plugins' ), 'Another admin still sees it.' );
	}

	public function test_a_rejected_key_is_an_error_that_cannot_be_dismissed(): void {
		$this->connect();
		$this->connection->mark_auth_failed( 'api_key_revoked' );

		$notices = $this->notices()->notices_for_screen( 'plugins' );

		$this->assertSame( array( Notices::AUTH_FAILED ), array_column( $notices, 'id' ) );
		$this->assertSame( 'error', $notices[0]['type'] );
		$this->assertFalse( $notices[0]['dismissible'] );
		$this->assertStringContainsString( 'tab=pixalink_loyalty', $notices[0]['message'] );
	}

	public function test_a_healthy_connection_shows_nothing(): void {
		$this->connect();
		$this->connection->store_remote_status(
			array(
				'status'             => 'active',
				'min_plugin_version' => '0.1.0',
			)
		);

		$this->assertSame( array(), $this->ids( 'plugins' ) );
	}

	public function test_an_outdated_plugin_and_an_inactive_account_are_flagged(): void {
		$this->connect();
		$this->connection->store_remote_status(
			array(
				'status'             => 'subscription_inactive',
				'min_plugin_version' => '0.3.0',
			)
		);

		$notices = $this->notices()->notices_for_screen( 'woocommerce_page_wc-settings', true );

		$this->assertSame( array( Notices::PLUGIN_OUTDATED, Notices::ACCOUNT_INACTIVE ), array_column( $notices, 'id' ) );
		$this->assertStringContainsString( '0.3.0', $notices[0]['message'] );
	}

	public function test_disabled_coupons_are_flagged_while_connected(): void {
		$this->coupons_enabled = false;
		$this->assertNotContains( Notices::COUPONS_DISABLED, $this->ids( 'plugins' ), 'Irrelevant before connecting.' );

		$this->connect();
		$this->assertSame( array( Notices::COUPONS_DISABLED ), $this->ids( 'plugins' ) );

		$this->notices()->dismiss_for_user( 3, Notices::COUPONS_DISABLED );
		$this->assertSame( array(), $this->ids( 'plugins' ) );
	}

	public function test_result_codes_map_to_messages(): void {
		$connected = Notices::result_notice( 'connected' );
		$this->assertNotNull( $connected );
		$this->assertSame( 'success', $connected['type'] );

		$lead = Notices::result_notice( 'lead_submitted' );
		$this->assertNotNull( $lead );
		$this->assertStringContainsString( 'contact you', $lead['message'] );

		foreach ( ConnectException::REASONS as $reason ) {
			$notice = Notices::result_notice( 'error_' . $reason );
			$this->assertNotNull( $notice, $reason );
			$this->assertSame( 'error', $notice['type'] );
		}

		$this->assertNull( Notices::result_notice( 'error_<script>' ) );
		$this->assertNull( Notices::result_notice( 'whatever' ) );
	}
}
