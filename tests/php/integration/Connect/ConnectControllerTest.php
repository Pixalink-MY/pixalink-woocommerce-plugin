<?php
/**
 * Integration tests for ConnectController.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Connect;

use Pixalink\Loyalty\Api\Resources\Connect;
use Pixalink\Loyalty\Connect\ConnectController;
use Pixalink\Loyalty\Connect\Connection;
use Pixalink\Loyalty\Connect\Heartbeat;
use Pixalink\Loyalty\Install\Installer;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\FakeServer\Server;
use Pixalink\Loyalty\Tests\FakeServer\Signature;

/**
 * The whole Connect wizard through WordPress: start → fake hosted page → callback → exchange → heartbeat,
 * the key path, and disconnect.
 *
 * @covers \Pixalink\Loyalty\Connect\ConnectController
 * @covers \Pixalink\Loyalty\Connect\ConnectService
 * @covers \Pixalink\Loyalty\Connect\Heartbeat
 */
final class ConnectControllerTest extends \WP_UnitTestCase {

	/**
	 * Admin user id.
	 *
	 * @var int
	 */
	private int $admin_id;

	public function set_up(): void {
		parent::set_up();

		Server::reset();
		// clear() also drops the shared Credentials instance's per-request memo.
		Plugin::instance()->credentials()->clear();
		foreach ( array( Options::CONNECTION, Options::API_BASE, Options::REMOTE_STATUS, Options::CLOCK_OFFSET, Options::MARKETING_CONSENT ) as $option ) {
			delete_option( $option );
		}
		as_unschedule_all_actions( Heartbeat::HOOK, array(), Installer::ACTION_GROUP );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	private function controller(): ConnectController {
		return Plugin::instance()->connect_controller();
	}

	/**
	 * Query parameters of a URL.
	 *
	 * @param string $url URL.
	 * @return array<string,mixed>
	 */
	private static function query_of( string $url ): array {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return $query;
	}

	/**
	 * Follow the start redirect through the fake hosted page and return the callback query.
	 *
	 * @param string $start_url Hosted page URL from handle_start().
	 * @return array<string,mixed>
	 */
	private static function visit_hosted_page( string $start_url ): array {
		$request = new \WP_REST_Request( 'GET', '/' . Server::CONTROL_NAMESPACE . '/connect/woocommerce' );
		$request->set_query_params( self::query_of( $start_url ) );
		$response = rest_do_request( $request );

		self::assertSame( 302, $response->get_status() );
		$location = $response->get_headers()['Location'];
		self::assertStringStartsWith( admin_url( 'admin.php?page=wc-settings&tab=pixalink_loyalty&pixalink_connect=callback' ), $location );

		return self::query_of( $location );
	}

	/**
	 * Recorded fake-server paths with their statuses.
	 *
	 * @return list<string>
	 */
	private static function recorded(): array {
		return array_map( static fn ( array $request ): string => $request['method'] . ' ' . $request['path'] . ' ' . $request['status'], Server::recorded() );
	}

	private function connect_via_hosted(): string {
		$start = $this->controller()->handle_start(
			array(
				ConnectController::FIELD_INTENT        => Connect::INTENT_CONNECT,
				ConnectController::FIELD_PAUSE_LEGACY  => '1',
				ConnectController::FIELD_CONSENT_SHOWN => '1',
			)
		);

		return $this->controller()->handle_callback( self::visit_hosted_page( $start ) );
	}

	public function test_hosted_connect_end_to_end(): void {
		$start = $this->controller()->handle_start(
			array(
				ConnectController::FIELD_INTENT        => Connect::INTENT_CONNECT,
				ConnectController::FIELD_CONSENT_SHOWN => '1',
			)
		);
		$endpoint = Plugin::instance()->connect_service()->authorize_endpoint();
		// wp-env's fake hosted page already has a query (?rest_route=...), so ours is appended with "&".
		$this->assertStringStartsWith( $endpoint . ( false === strpos( $endpoint, '?' ) ? '?' : '&' ) . 'site=', $start );
		$this->assertSame( home_url(), self::query_of( $start )['site'] );

		$callback = self::visit_hosted_page( $start );
		$this->assertMatchesRegularExpression( '/^fake-code-[A-Za-z0-9]{38}$/', $callback['code'] );

		$redirect = $this->controller()->handle_callback( $callback );

		$this->assertSame( ConnectController::settings_url( array( 'pixalink_notice' => 'connected' ) ), $redirect );

		$connection = Plugin::instance()->connection()->data();
		$this->assertSame( Connection::STATUS_CONNECTED, $connection['status'] );
		$this->assertSame( 'Fake Cafe', $connection['organisation']['name'] );
		$this->assertSame( 'Fake Online Store', $connection['outlet']['name'] );
		$this->assertSame( $this->admin_id, $connection['connected_by'] );
		$this->assertSame( 'https://fake-pixalink.test/admin', $connection['dashboard_url'] );

		$this->assertSame( Signature::FAKE_API_KEY, Plugin::instance()->credentials()->api_key() );
		$this->assertStringStartsWith( 'pxl1:', (string) get_option( Options::CREDENTIALS ), 'The key is stored encrypted.' );
		$this->assertStringNotContainsString( Signature::FAKE_API_KEY, (string) get_option( Options::CREDENTIALS ) );
		$this->assertSame( 'https://fake-pixalink.test/api/commerce/v1', get_option( Options::API_BASE ) );
		$this->assertSame( 'active', Plugin::instance()->connection()->remote_status()['status'] );
		$this->assertTrue( as_has_scheduled_action( Heartbeat::HOOK, array(), Installer::ACTION_GROUP ) );
		$this->assertFalse( get_option( Options::MARKETING_CONSENT )['value'], 'The unticked box is recorded as an explicit no.' );

		$this->assertSame(
			array( 'GET hosted:connect/woocommerce 302', 'POST connect/exchange 200', 'POST connection/heartbeat 200' ),
			self::recorded()
		);
	}

	public function test_a_replayed_callback_is_rejected(): void {
		$start    = $this->controller()->handle_start( array() );
		$callback = self::visit_hosted_page( $start );
		$this->controller()->handle_callback( $callback );

		$this->assertSame(
			ConnectController::settings_url( array( 'pixalink_notice' => 'error_invalid_state' ) ),
			$this->controller()->handle_callback( $callback )
		);
	}

	public function test_a_code_minted_for_another_attempt_fails_the_pkce_check(): void {
		$first  = self::visit_hosted_page( $this->controller()->handle_start( array() ) );
		$second = self::query_of( $this->controller()->handle_start( array() ) );

		$redirect = $this->controller()->handle_callback(
			array(
				'code'  => $first['code'],
				'state' => $second['state'],
			)
		);

		$this->assertSame( ConnectController::settings_url( array( 'pixalink_notice' => 'error_invalid_grant' ) ), $redirect );
		$this->assertFalse( Plugin::instance()->connection()->exists() );
		$this->assertNull( Plugin::instance()->credentials()->api_key() );
		$this->assertSame( 'POST connect/exchange 400', self::recorded()[1] );
	}

	public function test_request_access_returns_with_lead_submitted(): void {
		set_transient( 'pixalink_loyalty_fake_hosted_outcome', 'lead', HOUR_IN_SECONDS );

		$start = $this->controller()->handle_start(
			array(
				ConnectController::FIELD_INTENT            => Connect::INTENT_REQUEST_ACCESS,
				ConnectController::FIELD_CONSENT_SHOWN     => '1',
				ConnectController::FIELD_MARKETING_CONSENT => '1',
			)
		);
		$query = self::query_of( $start );
		$this->assertSame( 'request_access', $query['intent'] );
		$lead = json_decode( (string) base64_decode( strtr( $query['lead'], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$this->assertSame( get_userdata( $this->admin_id )->user_email, $lead['admin_email'] );
		$this->assertTrue( $lead['marketing_consent']['value'] );

		$redirect = $this->controller()->handle_callback( self::visit_hosted_page( $start ) );

		$this->assertSame( ConnectController::settings_url( array( 'pixalink_notice' => 'lead_submitted' ) ), $redirect );
		$this->assertFalse( Plugin::instance()->connection()->exists() );
		$this->assertSame( array( 'GET hosted:connect/woocommerce 302' ), self::recorded(), 'No exchange for a lead.' );
	}

	public function test_connect_with_a_pasted_key(): void {
		$redirect = $this->controller()->handle_connect_key( array( ConnectController::FIELD_API_KEY => Signature::FAKE_API_KEY ) );

		$this->assertSame( ConnectController::settings_url( array( 'pixalink_notice' => 'connected' ) ), $redirect );
		$connection = Plugin::instance()->connection()->data();
		$this->assertSame( Connection::STATUS_CONNECTED, $connection['status'] );
		$this->assertSame( Connection::METHOD_API_KEY, $connection['method'] );
		$this->assertSame( 'Fake Cafe', $connection['organisation']['name'] );
		$this->assertSame( array( 'POST connection/heartbeat 200', 'GET outlet 200' ), self::recorded() );
	}

	public function test_a_key_whose_outlet_serves_another_store_is_refused(): void {
		set_transient( 'pixalink_loyalty_fake_connected_site', 'https://another-store.example', DAY_IN_SECONDS );

		$redirect = $this->controller()->handle_connect_key( array( ConnectController::FIELD_API_KEY => Signature::FAKE_API_KEY ) );

		$this->assertSame( ConnectController::settings_url( array( 'pixalink_notice' => 'error_site_already_connected' ) ), $redirect );
		$this->assertNull( Plugin::instance()->credentials()->api_key() );
		$this->assertFalse( get_option( Options::CREDENTIALS ) );
		$this->assertFalse( Plugin::instance()->connection()->exists() );
	}

	public function test_an_unknown_key_is_rejected(): void {
		$redirect = $this->controller()->handle_connect_key( array( ConnectController::FIELD_API_KEY => '99|unknownunknownunknownunknownunknown' ) );

		$this->assertSame( ConnectController::settings_url( array( 'pixalink_notice' => 'error_key_rejected' ) ), $redirect );
	}

	public function test_disconnect_clears_credentials_but_keeps_history(): void {
		$this->connect_via_hosted();
		Server::reset();

		$redirect = $this->controller()->handle_disconnect();

		$this->assertSame( ConnectController::settings_url( array( 'pixalink_notice' => 'disconnected' ) ), $redirect );
		$this->assertSame( array( 'POST connection/disconnect 204' ), self::recorded() );
		$this->assertSame( array( 'reason' => 'merchant_request' ), Server::recorded()[0]['body'] );

		$this->assertFalse( get_option( Options::CREDENTIALS ) );
		$this->assertFalse( get_option( Options::API_BASE ) );
		$this->assertFalse( get_option( Options::REMOTE_STATUS ) );
		$this->assertNull( Plugin::instance()->credentials()->api_key() );
		$this->assertFalse( as_has_scheduled_action( Heartbeat::HOOK, array(), Installer::ACTION_GROUP ) );

		$connection = Plugin::instance()->connection()->data();
		$this->assertSame( Connection::STATUS_DISCONNECTED, $connection['status'] );
		$this->assertSame( 'Fake Cafe', $connection['organisation']['name'], 'History is kept.' );
		$this->assertSame( $this->admin_id, $connection['connected_by'] );
		$this->assertSame( $this->admin_id, $connection['disconnected_by'] );
		$this->assertNotNull( $connection['disconnected_at'] );
	}

	public function test_test_connection_refreshes_the_heartbeat(): void {
		$this->connect_via_hosted();
		Server::reset();

		$this->assertSame( ConnectController::settings_url( array( 'pixalink_notice' => 'test_ok' ) ), $this->controller()->handle_test() );
		$this->assertSame( array( 'POST connection/heartbeat 200', 'GET outlet 200' ), self::recorded() );
	}

	public function test_the_callback_hook_ignores_other_admin_pages(): void {
		$_GET = array(
			'page' => 'wc-settings',
			'tab'  => 'general',
		);

		$this->controller()->maybe_handle_callback();

		$this->assertSame( array(), Server::recorded() );
		$_GET = array();
	}

	public function test_admin_post_actions_are_registered(): void {
		foreach ( array_keys( ConnectController::NONCE_FIELDS ) as $action ) {
			$this->assertNotFalse( has_action( 'admin_post_' . $action ), $action );
		}
		$this->assertSame( 'https://fake-pixalink.test/connect/woocommerce', wp_validate_redirect( 'https://fake-pixalink.test/connect/woocommerce', '' ), 'The Pixalink host is an allowed redirect target.' );
		$this->assertSame( '', wp_validate_redirect( 'https://evil.example/connect/woocommerce', '' ) );
	}
}
