<?php
/**
 * Tests for ConnectService.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Connect;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Admin\Notices;
use Pixalink\Loyalty\Api\Client;
use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Api\Resources\Connect;
use Pixalink\Loyalty\Connect\ConnectException;
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
 * The Connect state machine against a scripted HTTP transport.
 *
 * @covers \Pixalink\Loyalty\Connect\ConnectService
 * @covers \Pixalink\Loyalty\Connect\ConnectException
 */
final class ConnectServiceTest extends TestCase {

	private const NOW     = 1761300000;
	private const USER_ID = 7;
	private const API_KEY = '12|abcdefghijklmnopqrstuvwxyz0123456789ABCD';
	private const OLD_KEY = '11|oldoldoldoldoldoldoldoldoldoldoldoldo';

	/**
	 * Captured wp_remote_request() calls: [url, args].
	 *
	 * @var list<array{0:string,1:array<string,mixed>}>
	 */
	private array $requests = array();

	/**
	 * Queued fake responses.
	 *
	 * @var list<mixed>
	 */
	private array $responses = array();

	/**
	 * Action Scheduler calls: [function, args].
	 *
	 * @var list<array{0:string,1:array<int,mixed>}>
	 */
	private array $scheduler = array();

	/**
	 * Whether the heartbeat action is "scheduled".
	 *
	 * @var bool
	 */
	private bool $scheduled = false;

	protected function set_up(): void {
		parent::set_up();

		$this->requests  = array();
		$this->responses = array();
		$this->scheduler = array();
		$this->scheduled = false;

		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ) {
				$this->requests[] = array( $url, $args );
				$this->assertNotEmpty( $this->responses, 'Unexpected extra HTTP request to ' . $url );

				return array_shift( $this->responses );
			}
		);
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ): bool => $thing instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn ( array $response ): int => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( array $response ): string => $response['body'] );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
		Functions\when( 'wp_parse_url' )->alias( static fn ( string $url, int $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		Functions\when( 'admin_url' )->alias( static fn ( string $path = '' ): string => 'https://shop.example.test/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'site_url' )->justReturn( 'https://shop.example.test' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Demo Shop' );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'wp_timezone_string' )->justReturn( 'Asia/Kuala_Lumpur' );
		Functions\when( 'wc_get_page_id' )->justReturn( -1 );
		Functions\when( 'get_userdata' )->justReturn(
			(object) array(
				'user_email'   => 'owner@shop.example.test',
				'display_name' => 'Siti Owner',
			)
		);
		Functions\when( 'as_has_scheduled_action' )->alias( fn (): bool => $this->scheduled );
		Functions\when( 'as_schedule_recurring_action' )->alias(
			function ( ...$args ): int {
				$this->scheduler[] = array( 'schedule', $args );
				$this->scheduled   = true;

				return 1;
			}
		);
		Functions\when( 'as_unschedule_all_actions' )->alias(
			function ( ...$args ): void {
				$this->scheduler[] = array( 'unschedule', $args );
				$this->scheduled   = false;
			}
		);
	}

	/**
	 * Fresh service graph sharing one Options/Credentials instance.
	 *
	 * @return array{service:ConnectService,credentials:Credentials,connection:Connection,options:Options,pkce:PkceState,clock:Clock}
	 */
	private function graph(): array {
		$options     = new Options();
		$credentials = new Credentials( new Crypto( 'salts' ), $options, static fn () => null );
		$clock       = new Clock( $options, static fn (): int => self::NOW );
		$logger      = new Logger();
		$client      = new Client( $credentials, $clock, $logger, $options, '0.1.0' );
		$api         = new Connect( $client );
		$connection  = new Connection( $options, static fn (): int => self::NOW );
		$pkce        = new PkceState( static fn (): int => self::NOW );
		$heartbeat   = new Heartbeat( $api, $credentials, $connection, $clock, $logger, '0.1.0' );

		return array(
			'service'     => new ConnectService( $api, $credentials, $connection, $pkce, $heartbeat, new LegacyWebhookDetector(), new LeadPayload( '0.1.0' ), $options, $logger, '0.1.0' ),
			'credentials' => $credentials,
			'connection'  => $connection,
			'options'     => $options,
			'pkce'        => $pkce,
			'clock'       => $clock,
		);
	}

	/**
	 * Queue a JSON response.
	 *
	 * @param int                 $status HTTP status.
	 * @param array<mixed>|string $body   Body (array is JSON-encoded).
	 */
	private function respond( int $status, $body ): void {
		$this->responses[] = array(
			'response' => array( 'code' => $status ),
			'body'     => is_array( $body ) ? (string) json_encode( $body ) : $body,
			'headers'  => array(),
		);
	}

	private function respond_exchange(): void {
		$this->respond(
			200,
			array(
				'api_key'       => self::API_KEY,
				'outlet'        => array(
					'id'   => '101',
					'uuid' => '9a3c1f0e-6b1d-4c39-9f7e-0c2b5d6a7e81',
					'name' => 'Online Store',
				),
				'organisation'  => array(
					'id'   => '229',
					'name' => 'Demo Cafe',
				),
				'api_base_url'  => 'https://sd.pixalink.io/api/commerce/v1',
				'dashboard_url' => 'https://sd.pixalink.io/admin',
			)
		);
	}

	private function respond_heartbeat( int $server_time = self::NOW ): void {
		$this->respond(
			200,
			array(
				'status'             => 'active',
				'outlet'             => array(
					'id'   => '101',
					'uuid' => '9a3c1f0e-6b1d-4c39-9f7e-0c2b5d6a7e81',
					'name' => 'Online Store',
				),
				'features'           => array(
					'earn'              => true,
					'vouchers'          => true,
					'points_redemption' => false,
					'credits'           => false,
					'otp_channels'      => array( 'whatsapp' ),
				),
				'redemption_rules'   => new \stdClass(),
				'earn_on'            => 'paid',
				'min_plugin_version' => '0.1.0',
				'server_time'        => $server_time,
			)
		);
	}

	/**
	 * Query of a URL.
	 *
	 * @param string $url URL.
	 * @return array<string,mixed>
	 */
	private static function query_of( string $url ): array {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

		return $query;
	}

	/**
	 * JSON body of a captured request.
	 *
	 * @param int $index Request index.
	 * @return array<mixed>
	 */
	private function body( int $index ): array {
		return json_decode( $this->requests[ $index ][1]['body'] ?? '{}', true );
	}

	private function assert_reason( string $reason, callable $operation ): void {
		try {
			$operation();
			$this->fail( 'Expected ConnectException ' . $reason . '.' );
		} catch ( ConnectException $exception ) {
			$this->assertSame( $reason, $exception->reason() );
		}
	}

	public function test_start_hosted_builds_the_authorize_url_with_an_s256_challenge(): void {
		$graph = $this->graph();

		$url   = $graph['service']->start_hosted( self::USER_ID );
		$query = self::query_of( $url );

		$this->assertStringStartsWith( 'https://explore.pixalink.io/connect/woocommerce?', $url, 'The web base is the API base without /api/commerce/v1.' );
		$this->assertSame( 'https://shop.example.test', $query['site'] );
		$this->assertSame( 'https://shop.example.test/wp-admin/admin.php?page=wc-settings&tab=pixalink_loyalty&pixalink_connect=callback', $query['return'] );
		$this->assertSame( 'S256', $query['code_challenge_method'] );
		$this->assertSame( '0.1.0', $query['plugin_version'] );
		$this->assertArrayNotHasKey( 'intent', $query );
		$this->assertArrayNotHasKey( 'lead', $query, 'Existing vendors share no lead data.' );

		$stored = $this->transients[ 'pixalink_loyalty_connect_' . self::USER_ID ][0];
		$this->assertSame( $stored['state'], $query['state'] );
		$this->assertSame( PkceState::challenge( $stored['verifier'] ), $query['code_challenge'] );
		$this->assertSame( array(), $this->requests, 'Starting never calls the API.' );
	}

	public function test_request_access_adds_the_intent_and_a_lead_with_the_admins_consent(): void {
		$graph = $this->graph();

		$query = self::query_of( $graph['service']->start_hosted( self::USER_ID, Connect::INTENT_REQUEST_ACCESS, true, true ) );
		$lead  = json_decode( (string) base64_decode( strtr( $query['lead'], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		$this->assertSame( 'request_access', $query['intent'] );
		$this->assertSame( 'owner@shop.example.test', $lead['admin_email'] );
		$this->assertSame( 'Siti Owner', $lead['admin_name'] );
		$this->assertSame( 'Demo Shop', $lead['store_name'] );
		$this->assertTrue( $lead['marketing_consent']['value'] );
		$this->assertSame( 'admin', $lead['marketing_consent']['source'] );
		$this->assertSame( hash( 'sha256', ConnectService::marketing_consent_text() ), $lead['marketing_consent']['text_hash'] );

		$recorded = $graph['options']->get_array( Options::MARKETING_CONSENT );
		$this->assertTrue( $recorded['value'] );
		$this->assertSame( self::USER_ID, $recorded['user_id'] );
	}

	public function test_complete_hosted_exchanges_the_code_and_connects(): void {
		$graph = $this->graph();
		$query = self::query_of( $graph['service']->start_hosted( self::USER_ID ) );
		$this->respond_exchange();
		$this->respond_heartbeat( self::NOW + 42 );

		$paused = $graph['service']->complete_hosted( self::USER_ID, 'pxlc_0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJ', $query['state'] );

		$this->assertSame( 0, $paused );

		// Exchange: unauthenticated, verifier matches the challenge sent to the hosted page.
		$this->assertSame( 'https://explore.pixalink.io/api/commerce/v1/connect/exchange', $this->requests[0][0] );
		$this->assertArrayNotHasKey( 'Authorization', $this->requests[0][1]['headers'] );
		$exchange = $this->body( 0 );
		$this->assertSame( $query['code_challenge'], PkceState::challenge( $exchange['code_verifier'] ) );
		$this->assertSame( 'https://shop.example.test', $exchange['site'] );

		// First heartbeat goes to the API base the exchange returned, signed with the new key.
		$this->assertSame( 'https://sd.pixalink.io/api/commerce/v1/connection/heartbeat', $this->requests[1][0] );
		$this->assertSame( 'Bearer ' . self::API_KEY, $this->requests[1][1]['headers']['Authorization'] );

		$this->assertSame( self::API_KEY, $graph['credentials']->api_key() );
		$this->assertSame( 'https://sd.pixalink.io/api/commerce/v1', $graph['options']->get_string( Options::API_BASE ) );

		$data = $graph['connection']->data();
		$this->assertSame( Connection::STATUS_CONNECTED, $data['status'] );
		$this->assertSame( 'Demo Cafe', $data['organisation']['name'] );
		$this->assertSame( 'Online Store', $data['outlet']['name'] );
		$this->assertSame( self::USER_ID, $data['connected_by'] );
		$this->assertSame( self::NOW, $data['connected_at'] );
		$this->assertSame( Connection::METHOD_HOSTED, $data['method'] );
		$this->assertSame( 'https://sd.pixalink.io/admin', $data['dashboard_url'] );

		$this->assertSame( 'active', $graph['connection']->remote_status()['status'] );
		$this->assertSame( 42, $graph['clock']->offset() );
		$this->assertTrue( $this->scheduled, 'The recurring heartbeat is scheduled.' );
		$this->assertSame( Heartbeat::HOOK, $this->scheduler[0][1][2] );
		$this->assertSame( Heartbeat::INTERVAL, $this->scheduler[0][1][1] );
	}

	public function test_complete_hosted_rejects_a_state_that_does_not_match(): void {
		$graph = $this->graph();
		$graph['service']->start_hosted( self::USER_ID );

		$this->assert_reason(
			ConnectException::INVALID_STATE,
			fn () => $graph['service']->complete_hosted( self::USER_ID, 'pxlc_0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJ', 'forged' )
		);
		$this->assertSame( array(), $this->requests, 'No code is exchanged without a matching state.' );
		$this->assertFalse( $graph['connection']->exists() );
	}

	public function test_complete_hosted_rejects_a_callback_for_another_user(): void {
		$graph = $this->graph();
		$query = self::query_of( $graph['service']->start_hosted( self::USER_ID ) );

		$this->assert_reason(
			ConnectException::INVALID_STATE,
			fn () => $graph['service']->complete_hosted( self::USER_ID + 1, 'pxlc_0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJ', $query['state'] )
		);
	}

	public function test_an_exchange_400_is_an_invalid_grant_and_burns_the_attempt(): void {
		$graph = $this->graph();
		$query = self::query_of( $graph['service']->start_hosted( self::USER_ID ) );
		$this->respond(
			400,
			array(
				'message' => 'The authorisation code is invalid or expired.',
				'code'    => 'invalid_grant',
			)
		);

		$this->assert_reason(
			ConnectException::INVALID_GRANT,
			fn () => $graph['service']->complete_hosted( self::USER_ID, 'pxlc_0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJ', $query['state'] )
		);

		$this->assertNull( $graph['credentials']->api_key() );
		$this->assertSame( Connection::STATUS_DISCONNECTED, $graph['connection']->status() );
		$this->assert_reason(
			ConnectException::INVALID_STATE,
			fn () => $graph['service']->complete_hosted( self::USER_ID, 'pxlc_0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJ', $query['state'] )
		);
	}

	public function test_an_oauth_style_error_body_is_an_invalid_grant_too(): void {
		$graph = $this->graph();
		$query = self::query_of( $graph['service']->start_hosted( self::USER_ID ) );
		$this->respond( 400, array( 'error' => 'invalid_grant' ) );

		$this->assert_reason(
			ConnectException::INVALID_GRANT,
			fn () => $graph['service']->complete_hosted( self::USER_ID, 'pxlc_0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJ', $query['state'] )
		);
	}

	public function test_an_exchange_without_a_usable_key_is_refused(): void {
		$graph = $this->graph();
		$query = self::query_of( $graph['service']->start_hosted( self::USER_ID ) );
		$this->respond(
			200,
			array(
				'api_key' => 'not-a-key',
				'outlet'  => array( 'id' => '101' ),
			)
		);

		$this->assert_reason(
			ConnectException::INVALID_RESPONSE,
			fn () => $graph['service']->complete_hosted( self::USER_ID, 'pxlc_0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJ', $query['state'] )
		);
		$this->assertNull( $graph['credentials']->api_key() );
	}

	public function test_hosted_errors_map_to_outcomes(): void {
		$graph = $this->graph();

		$query = self::query_of( $graph['service']->start_hosted( self::USER_ID ) );
		$this->assertSame( ConnectService::OUTCOME_LEAD_SUBMITTED, $graph['service']->fail_hosted( self::USER_ID, $query['state'], 'access_denied', 'lead_submitted' ) );

		$query = self::query_of( $graph['service']->start_hosted( self::USER_ID ) );
		$this->assertSame( ConnectService::OUTCOME_CANCELLED, $graph['service']->fail_hosted( self::USER_ID, $query['state'], 'access_denied' ) );

		$query = self::query_of( $graph['service']->start_hosted( self::USER_ID ) );
		$this->assert_reason( ConnectException::INVALID_REQUEST, fn () => $graph['service']->fail_hosted( self::USER_ID, $query['state'], 'invalid_request' ) );

		$this->assert_reason( ConnectException::INVALID_STATE, fn () => $graph['service']->fail_hosted( self::USER_ID, 'forged', 'access_denied', 'lead_submitted' ) );
		$this->assert_reason( ConnectException::INVALID_REQUEST, fn () => $graph['service']->fail_hosted( self::USER_ID, '', 'invalid_request' ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_a_callback_without_a_code_is_refused_without_calling_pixalink(): void {
		$graph = $this->graph();
		$query = self::query_of( $graph['service']->start_hosted( self::USER_ID ) );

		$this->assert_reason( ConnectException::INVALID_GRANT, fn () => $graph['service']->complete_hosted( self::USER_ID, '', $query['state'] ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_connect_with_key_adopts_the_site_and_reads_the_organisation(): void {
		$graph = $this->graph();
		$this->respond_heartbeat( self::NOW - 30 );
		$this->respond(
			200,
			array(
				'outlet'       => array(
					'id'       => '101',
					'uuid'     => '9a3c1f0e-6b1d-4c39-9f7e-0c2b5d6a7e81',
					'name'     => 'Online Store',
					'currency' => 'MYR',
				),
				'organisation' => array(
					'id'   => '229',
					'name' => 'Demo Cafe',
				),
			)
		);

		$graph['service']->connect_with_key( self::USER_ID, '  ' . self::API_KEY . ' ' );

		$this->assertStringEndsWith( '/connection/heartbeat', $this->requests[0][0] );
		$this->assertSame( 'Bearer ' . self::API_KEY, $this->requests[0][1]['headers']['Authorization'] );
		$this->assertSame( 'https://shop.example.test', $this->body( 0 )['site'] );
		$this->assertStringEndsWith( '/outlet', $this->requests[1][0] );
		$this->assertCount( 2, $this->requests, 'The probe heartbeat is the first heartbeat; no second one is sent.' );

		$data = $graph['connection']->data();
		$this->assertSame( Connection::STATUS_CONNECTED, $data['status'] );
		$this->assertSame( Connection::METHOD_API_KEY, $data['method'] );
		$this->assertSame( 'Demo Cafe', $data['organisation']['name'] );
		$this->assertSame( 'MYR', $data['outlet']['currency'] );
		$this->assertSame( -30, $graph['clock']->offset() );
		$this->assertTrue( $graph['connection']->feature( 'earn' ) );
		$this->assertTrue( $this->scheduled );
	}

	public function test_connect_with_key_reports_a_site_already_connected_and_forgets_the_key(): void {
		$graph = $this->graph();
		$this->respond(
			409,
			array(
				'message' => 'This outlet is already connected to another site.',
				'code'    => 'site_already_connected',
			)
		);

		try {
			$graph['service']->connect_with_key( self::USER_ID, self::API_KEY );
			$this->fail( 'Expected ConnectException.' );
		} catch ( ConnectException $exception ) {
			$this->assertSame( ConnectException::SITE_ALREADY_CONNECTED, $exception->reason() );
			$this->assertStringContainsString( 'already connected to another WooCommerce store', Notices::error_message( $exception->reason() ) );
		}

		$this->assertNull( $graph['credentials']->api_key() );
		$this->assertSame( Connection::STATUS_DISCONNECTED, $graph['connection']->status() );
		$this->assertFalse( $this->scheduled );
	}

	public function test_a_rejected_key_restores_the_previous_one_and_keeps_the_connection(): void {
		$graph = $this->graph();
		$graph['credentials']->store( self::OLD_KEY );
		$graph['connection']->mark_connected( array( 'name' => 'Demo Cafe' ), array( 'id' => '101' ), self::USER_ID, Connection::METHOD_HOSTED, null, null );
		$this->respond(
			401,
			array(
				'message' => 'Unauthenticated.',
				'code'    => 'unauthenticated',
			)
		);

		$this->assert_reason( ConnectException::KEY_REJECTED, fn () => $graph['service']->connect_with_key( self::USER_ID, self::API_KEY ) );

		$this->assertSame( self::OLD_KEY, $graph['credentials']->api_key() );
		$this->assertSame( Connection::STATUS_CONNECTED, $graph['connection']->status(), 'Probing a new key never marks the live connection as failed.' );
	}

	public function test_malformed_keys_are_rejected_before_any_request(): void {
		$graph = $this->graph();

		foreach ( array( '', 'abc', 'abcdefghijklmnopqrstuvwxyz0123456789', '12|short', 'x|abcdefghijklmnopqrstuvwxyz0123' ) as $key ) {
			$this->assert_reason( ConnectException::INVALID_KEY_FORMAT, fn () => $graph['service']->connect_with_key( self::USER_ID, $key ) );
		}

		$this->assertSame( array(), $this->requests );
	}

	public function test_wp_config_credentials_block_the_hosted_and_key_paths(): void {
		$options     = new Options();
		$credentials = new Credentials( new Crypto( 'salts' ), $options, static fn ( string $name ) => Credentials::API_KEY_CONSTANT === $name ? self::API_KEY : null );
		$clock       = new Clock( $options, static fn (): int => self::NOW );
		$api         = new Connect( new Client( $credentials, $clock, new Logger(), $options, '0.1.0' ) );
		$connection  = new Connection( $options );
		$service     = new ConnectService( $api, $credentials, $connection, new PkceState(), new Heartbeat( $api, $credentials, $connection, $clock, new Logger(), '0.1.0' ), new LegacyWebhookDetector(), new LeadPayload( '0.1.0' ), $options, new Logger(), '0.1.0' );

		$this->assert_reason( ConnectException::CREDENTIALS_OVERRIDDEN, fn () => $service->start_hosted( self::USER_ID ) );
		$this->assert_reason( ConnectException::CREDENTIALS_OVERRIDDEN, fn () => $service->connect_with_key( self::USER_ID, self::OLD_KEY ) );
		$this->assertStringContainsString( 'request_access', $service->start_hosted( self::USER_ID, Connect::INTENT_REQUEST_ACCESS ), 'Requesting access stays possible.' );
	}

	public function test_disconnect_tells_pixalink_and_clears_credentials_but_keeps_history(): void {
		$graph = $this->graph();
		$graph['credentials']->store( self::API_KEY );
		$graph['options']->set( Options::API_BASE, 'https://sd.pixalink.io/api/commerce/v1' );
		$graph['connection']->mark_connected(
			array(
				'id'   => '229',
				'name' => 'Demo Cafe',
			),
			array(
				'id'   => '101',
				'name' => 'Online Store',
			),
			self::USER_ID,
			Connection::METHOD_HOSTED,
			null,
			null
		);
		$graph['connection']->store_remote_status( array( 'status' => 'active' ) );
		$this->scheduled = true;
		$this->respond( 204, '' );

		$this->assertTrue( $graph['service']->disconnect( self::USER_ID ) );

		$this->assertSame( 'https://sd.pixalink.io/api/commerce/v1/connection/disconnect', $this->requests[0][0] );
		$this->assertSame( array( 'reason' => 'merchant_request' ), $this->body( 0 ) );
		$this->assertNull( $graph['credentials']->api_key() );
		$this->assertArrayNotHasKey( Options::CREDENTIALS, $this->options );
		$this->assertArrayNotHasKey( Options::API_BASE, $this->options );
		$this->assertSame( array(), $graph['connection']->remote_status() );
		$this->assertFalse( $this->scheduled );

		$data = $graph['connection']->data();
		$this->assertSame( Connection::STATUS_DISCONNECTED, $data['status'] );
		$this->assertSame( 'Demo Cafe', $data['organisation']['name'], 'History is kept.' );
		$this->assertSame( self::NOW, $data['connected_at'] );
		$this->assertSame( self::NOW, $data['disconnected_at'] );
		$this->assertSame( self::USER_ID, $data['disconnected_by'] );
	}

	public function test_disconnect_still_forgets_the_key_when_pixalink_is_unreachable(): void {
		$graph = $this->graph();
		$graph['credentials']->store( self::API_KEY );
		$graph['connection']->mark_connected( array(), array( 'id' => '101' ), self::USER_ID, Connection::METHOD_API_KEY, null, null );
		$error = \Mockery::mock( 'WP_Error' );
		$error->shouldReceive( 'get_error_code' )->andReturn( 'http_request_failed' );
		$error->shouldReceive( 'get_error_message' )->andReturn( 'cURL error 28' );
		$this->responses[] = $error;

		$this->assertFalse( $graph['service']->disconnect( self::USER_ID ) );
		$this->assertNull( $graph['credentials']->api_key() );
		$this->assertSame( Connection::STATUS_DISCONNECTED, $graph['connection']->status() );
	}

	public function test_test_requires_a_connection(): void {
		$this->assert_reason( ConnectException::NOT_CONNECTED, fn () => $this->graph()['service']->test() );
	}

	public function test_test_connection_on_a_store_pixalink_paused_says_so_and_keeps_the_connection(): void {
		$graph = $this->graph();
		$graph['credentials']->store( self::API_KEY );
		$graph['connection']->mark_connected( array( 'name' => 'Demo Cafe' ), array( 'id' => '101' ), self::USER_ID, Connection::METHOD_HOSTED, null, null );
		$this->respond(
			200,
			array(
				'status'             => 'commerce_paused',
				'features'           => array(
					'earn'              => false,
					'vouchers'          => false,
					'points_redemption' => false,
					'credits'           => false,
					'otp_channels'      => array(),
				),
				'redemption_rules'   => new \stdClass(),
				'earn_on'            => 'paid',
				'min_plugin_version' => '0.1.0',
				'server_time'        => self::NOW,
			)
		);
		$this->respond(
			403,
			array(
				'message' => 'This store is paused in Pixalink.',
				'code'    => 'commerce_paused',
			)
		);

		$this->assert_reason( ConnectException::STORE_PAUSED, fn () => $graph['service']->test() );

		$this->assertSame( Connection::STATUS_CONNECTED, $graph['connection']->status() );
		$this->assertTrue( $graph['connection']->is_commerce_paused() );
	}

	public function test_the_web_base_follows_a_dedicated_api_base(): void {
		$graph = $this->graph();
		$graph['options']->set( Options::API_BASE, 'https://loyalty.example.com/api/commerce/v1' );

		$this->assertSame( 'https://loyalty.example.com', $graph['service']->pixalink_base_url() );
		$this->assertSame( 'https://loyalty.example.com/connect/woocommerce', $graph['service']->authorize_endpoint() );
		$this->assertSame( array( 'loyalty.example.com' ), $graph['service']->redirect_hosts() );
	}
}
