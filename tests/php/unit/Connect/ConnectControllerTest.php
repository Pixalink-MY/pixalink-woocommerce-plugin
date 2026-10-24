<?php
/**
 * Tests for ConnectController.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Connect;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Api\Client;
use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Api\Resources\Connect;
use Pixalink\Loyalty\Connect\ConnectController;
use Pixalink\Loyalty\Connect\Connection;
use Pixalink\Loyalty\Connect\ConnectService;
use Pixalink\Loyalty\Connect\Heartbeat;
use Pixalink\Loyalty\Connect\LeadPayload;
use Pixalink\Loyalty\Connect\LegacyWebhookDetector;
use Pixalink\Loyalty\Connect\PkceState;
use Pixalink\Loyalty\Support\Clock;
use Pixalink\Loyalty\Support\Crypto;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * Request parsing and redirect targets (the WordPress round trip is covered by the integration suite).
 *
 * @covers \Pixalink\Loyalty\Connect\ConnectController
 */
final class ConnectControllerTest extends TestCase {

	private const SETTINGS = 'https://shop.example.test/wp-admin/admin.php?page=wc-settings&tab=pixalink_loyalty';

	protected function set_up(): void {
		parent::set_up();

		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'admin_url' )->alias( static fn ( string $path = '' ): string => 'https://shop.example.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn ( array $args, string $url ): string => $url . '&' . http_build_query( $args ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn ( string $value ): string => trim( $value ) );
		Functions\when( 'wp_parse_url' )->alias( static fn ( string $url, int $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		Functions\when( 'wp_remote_request' )->alias(
			function (): void {
				$this->fail( 'No HTTP request expected.' );
			}
		);
	}

	private function controller(): ConnectController {
		$options     = new Options();
		$credentials = new Credentials( new Crypto( 'salts' ), $options, static fn () => null );
		$clock       = new Clock( $options );
		$api         = new Connect( new Client( $credentials, $clock, new Logger(), $options, '0.1.0' ) );
		$connection  = new Connection( $options );
		$service     = new ConnectService( $api, $credentials, $connection, new PkceState(), new Heartbeat( $api, $credentials, $connection, $clock, new Logger(), '0.1.0' ), new LegacyWebhookDetector(), new LeadPayload( '0.1.0' ), $options, new Logger(), '0.1.0' );

		return new ConnectController( $service );
	}

	public function test_only_the_settings_tab_callback_is_handled(): void {
		$this->assertTrue(
			ConnectController::is_callback(
				array(
					'page'             => 'wc-settings',
					'tab'              => 'pixalink_loyalty',
					'pixalink_connect' => 'callback',
				)
			)
		);
		$this->assertFalse(
			ConnectController::is_callback(
				array(
					'page' => 'wc-settings',
					'tab'  => 'pixalink_loyalty',
				)
			)
		);
		$this->assertFalse(
			ConnectController::is_callback(
				array(
					'page'             => 'wc-settings',
					'tab'              => 'general',
					'pixalink_connect' => 'callback',
				)
			)
		);
	}

	public function test_callbacks_redirect_to_the_tab_with_an_outcome_code(): void {
		$controller = $this->controller();

		$this->assertSame(
			self::SETTINGS . '&pixalink_notice=error_invalid_state',
			$controller->handle_callback(
				array(
					'code'  => 'pxlc_0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJ',
					'state' => 'no-attempt-was-started',
				)
			)
		);

		$state = $this->start_attempt();
		$this->assertSame(
			self::SETTINGS . '&pixalink_notice=lead_submitted',
			$controller->handle_callback(
				array(
					'error'  => 'access_denied',
					'reason' => 'lead_submitted',
					'state'  => $state,
				)
			)
		);
	}

	public function test_a_malformed_pasted_key_redirects_with_an_error(): void {
		$this->assertSame(
			self::SETTINGS . '&pixalink_notice=error_invalid_key_format',
			$this->controller()->handle_connect_key( array( ConnectController::FIELD_API_KEY => 'nope' ) )
		);
	}

	public function test_unticked_consent_is_recorded_as_an_explicit_no_only_when_the_box_was_shown(): void {
		$controller = $this->controller();

		$controller->handle_connect_key( array( ConnectController::FIELD_API_KEY => 'nope' ) );
		$this->assertArrayNotHasKey( Options::MARKETING_CONSENT, $this->options );

		$controller->handle_connect_key(
			array(
				ConnectController::FIELD_API_KEY       => 'nope',
				ConnectController::FIELD_CONSENT_SHOWN => '1',
			)
		);
		$this->assertFalse( $this->options[ Options::MARKETING_CONSENT ][0]['value'] );
	}

	public function test_the_pixalink_host_is_an_allowed_redirect_target(): void {
		$this->assertSame( array( 'example.org', 'explore.pixalink.io' ), $this->controller()->allow_pixalink_host( array( 'example.org' ) ) );
		$this->assertContains( ConnectController::NOTICE_ARG, $this->controller()->removable_query_args( array() ) );
	}

	/**
	 * Start a hosted attempt for user 7 and return its state.
	 *
	 * @return string
	 */
	private function start_attempt(): string {
		return ( new PkceState() )->begin( 7 )['state'];
	}
}
