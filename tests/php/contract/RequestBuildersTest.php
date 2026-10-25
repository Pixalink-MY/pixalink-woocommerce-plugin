<?php
/**
 * Contract: request bodies built by the plugin.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Contract;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Api\Resources\Connect;
use Pixalink\Loyalty\Api\Resources\Customers;
use Pixalink\Loyalty\Connect\LeadPayload;
use Pixalink\Loyalty\Connect\PkceState;
use Pixalink\Loyalty\Fields\ConsentRecord;
use Pixalink\Loyalty\Fields\PhoneNumber;
use Pixalink\Loyalty\Identity\PortalSignIn;
use Pixalink\Loyalty\Identity\SignInState;
use Pixalink\Loyalty\Support\Uuid;
use Pixalink\Loyalty\Tests\FakeServer\ContractValidator;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * The plugin's request builders produce bodies the server's schemas accept.
 *
 * Builders for quote and redemptions join this suite as work unit W3 adds them.
 *
 * @covers \Pixalink\Loyalty\Api\Resources\Connect
 * @covers \Pixalink\Loyalty\Api\Resources\Customers
 * @covers \Pixalink\Loyalty\Connect\LeadPayload
 * @covers \Pixalink\Loyalty\Connect\PkceState
 * @covers \Pixalink\Loyalty\Identity\PortalSignIn::sign_in_location
 * @covers \Pixalink\Loyalty\Identity\SignInState::generate
 */
final class RequestBuildersTest extends TestCase {

	public function test_connect_exchange_payload(): void {
		$payload = Connect::exchange_payload(
			'fake-code-0123456789abcdef0123456789abcdef01234567',
			'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk',
			'https://shop.example.test/'
		);

		$this->assertSame( 'https://shop.example.test', $payload['site'] );
		$this->assertSame( array(), ContractValidator::validate( 'connect-exchange.request.json', $payload ) );
	}

	public function test_connection_heartbeat_payload(): void {
		$healthy = Connect::heartbeat_payload( 'https://shop.example.test', '0.1.0', '11.2.1', 0, 0, null );
		$backlog = Connect::heartbeat_payload( 'https://shop.example.test', '0.1.0-beta.1', '11.2.1', 3, 1, '2026-10-24T08:00:00Z' );
		$clamped = Connect::heartbeat_payload( 'https://shop.example.test', '0.1.0', '11.2.1', -5, -1, null );

		$this->assertSame( array(), ContractValidator::validate( 'connection-heartbeat.request.json', $healthy ) );
		$this->assertSame( array(), ContractValidator::validate( 'connection-heartbeat.request.json', $backlog ) );
		$this->assertSame( array(), ContractValidator::validate( 'connection-heartbeat.request.json', $clamped ) );
		$this->assertSame( 0, $clamped['pending'] );
	}

	public function test_connection_disconnect_payload(): void {
		$this->assertSame( array(), ContractValidator::validate( 'connection-disconnect.request.json', Connect::disconnect_payload( 'merchant_request' ) ) );
	}

	public function test_hosted_connect_query(): void {
		$attempt = ( new PkceState() )->begin( 1 );
		$query   = Connect::authorize_query(
			'https://shop.example.test/',
			'https://shop.example.test/wp-admin/admin.php?page=wc-settings&tab=pixalink_loyalty&pixalink_connect=callback',
			$attempt['state'],
			$attempt['challenge'],
			'0.1.0',
			'11.2.1'
		);

		$this->assertSame( array(), ContractValidator::validate( 'connect-authorize.query.json', $query ) );
		$this->assertArrayNotHasKey( 'lead', $query );

		// Every value is a string: this is a URL query.
		foreach ( $query as $value ) {
			$this->assertIsString( $value );
		}
	}

	public function test_request_access_query_carries_a_lead_matching_its_schema(): void {
		$GLOBALS['wp_version'] = '7.0';
		Functions\when( 'site_url' )->justReturn( 'https://shop.example.test' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Demo Shop' );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_locale' )->justReturn( 'de_DE_formal' );
		Functions\when( 'wp_timezone_string' )->justReturn( '+08:00' );
		Functions\when( 'wc_get_page_id' )->justReturn( 12 );
		Functions\when( 'has_block' )->justReturn( false );
		$this->options['woocommerce_default_country'] = array( 'MY:14', true );

		$lead    = ( new LeadPayload( '0.1.0' ) )->build(
			'owner@shop.example.test',
			'Siti Owner',
			array(
				'value'       => false,
				'source'      => 'admin',
				'captured_at' => '2026-10-24T08:00:00Z',
				'text_hash'   => hash( 'sha256', 'consent' ),
			)
		);
		$attempt = ( new PkceState() )->begin( 1 );
		$query   = Connect::authorize_query(
			'https://shop.example.test',
			'https://shop.example.test/wp-admin/admin.php?page=wc-settings&tab=pixalink_loyalty&pixalink_connect=callback',
			$attempt['state'],
			$attempt['challenge'],
			'0.1.0',
			'11.2.1',
			Connect::INTENT_REQUEST_ACCESS,
			$lead
		);
		unset( $GLOBALS['wp_version'] );

		$this->assertSame( array(), ContractValidator::validate( 'connect-authorize.query.json', $query ) );
		$this->assertSame( array(), ContractValidator::validate( 'connect-lead.json', $lead ) );

		$decoded = json_decode( (string) base64_decode( strtr( $query['lead'], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$this->assertSame( $lead, $decoded, 'lead is unpadded base64url(JSON).' );
	}

	public function test_customer_lookup_query(): void {
		$this->assertSame( array(), ContractValidator::validate( 'customers-lookup.query.json', Customers::lookup_query( '+60123456789' ) ) );
		$this->assertSame( array( 'phone' => '+60123456789' ), Customers::lookup_query( '+60123456789' ) );
	}

	public function test_customer_create_payload_for_a_member_with_consent(): void {
		$payload = Customers::create_payload(
			17,
			new PhoneNumber( '+60123456789', 'MY', '123456789' ),
			'member@example.com',
			'Aisyah',
			'Rahman',
			ConsentRecord::given( true, ConsentRecord::SOURCE_MY_ACCOUNT, 'Send me offers.', 1761300000 )
		);

		$this->assertSame( array(), ContractValidator::validate( 'customers-create.request.json', $payload ) );
		$this->assertSame( '17', $payload['external_id'] );
		$this->assertSame( 'none', $payload['verified_via'] );
		$this->assertArrayNotHasKey( 'sso_ticket', $payload );
	}

	public function test_customer_create_payload_for_a_guest_without_details(): void {
		$payload = Customers::create_payload( 0, new PhoneNumber( '+6591234567', 'SG', '91234567' ), 'not-an-email', '', '  ', null );

		$this->assertSame( array(), ContractValidator::validate( 'customers-create.request.json', $payload ) );
		$this->assertNull( $payload['external_id'] );
		$this->assertNull( $payload['email'], 'Invalid e-mail addresses are sent as null.' );
		$this->assertNull( $payload['first_name'] );
		$this->assertNull( $payload['marketing_consent'] );
	}

	public function test_withdrawn_consent_from_pixalink_is_never_echoed_back(): void {
		$payload = Customers::create_payload( 17, new PhoneNumber( '+60123456789', 'MY', '123456789' ), null, null, null, ConsentRecord::given( false, ConsentRecord::SOURCE_PIXALINK, '', 1761300000 ) );

		$this->assertNull( $payload['marketing_consent'] );
		$this->assertSame( array(), ContractValidator::validate( 'customers-create.request.json', $payload ) );
	}

	public function test_sso_ticket_payload(): void {
		$guest = Customers::ticket_payload( 'kP3xV7nQ2mR8sT4wY6zA1bC5dE9fG0hJ', 0 );
		$user  = Customers::ticket_payload( 'kP3xV7nQ2mR8sT4wY6zA1bC5dE9fG0hJ', 17 );

		$this->assertSame( array(), ContractValidator::validate( 'customers-create.request.json', $guest ) );
		$this->assertSame( array(), ContractValidator::validate( 'customers-create.request.json', $user ) );
		$this->assertSame( 'sso_ticket', $guest['verified_via'] );
		$this->assertNull( $guest['external_id'] );
		$this->assertSame( '17', $user['external_id'] );
	}

	public function test_sso_ticket_payload_with_the_state_of_the_sign_in_button(): void {
		$payload = Customers::ticket_payload( 'kP3xV7nQ2mR8sT4wY6zA1bC5dE9fG0hJ', 0, SignInState::generate() );

		$this->assertSame( array(), ContractValidator::validate( 'customers-create.request.json', $payload ) );
		$this->assertMatchesRegularExpression( SignInState::PATTERN, $payload['sso_state'] ?? '' );
	}

	public function test_store_sign_in_query(): void {
		$location = PortalSignIn::sign_in_location(
			'https://demo.pixalink.io/l/pixalink-bookstore/store-sign-in/9a3c1f0e-6b1d-4c39-9f7e-0c2b5d6a7e81',
			'https://shop.example.test/my-account/?a=1&b=two%20words',
			'q8VJ2nX5rT1wZ7bD4fH9kM3pS6uY0aC2eG5iL8oR1tW4'
		);
		parse_str( (string) parse_url( $location, PHP_URL_QUERY ), $query ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

		$this->assertStringStartsWith( 'https://demo.pixalink.io/l/pixalink-bookstore/store-sign-in/9a3c1f0e-6b1d-4c39-9f7e-0c2b5d6a7e81?', $location );
		$this->assertSame(
			array(
				'return' => 'https://shop.example.test/my-account/?a=1&b=two%20words',
				'state'  => 'q8VJ2nX5rT1wZ7bD4fH9kM3pS6uY0aC2eG5iL8oR1tW4',
			),
			$query,
			'The return page survives the round trip whole.'
		);
		$this->assertSame( array(), ContractValidator::validate( 'store-sign-in.query.json', $query ) );
	}

	public function test_otp_payloads(): void {
		$this->assertSame( array(), ContractValidator::validate( 'customer-otp.request.json', Customers::otp_payload() ) );
		$this->assertSame( array(), ContractValidator::validate( 'customer-otp-verify.request.json', Customers::verify_payload( ' 123456 ' ) ) );
		$this->assertSame( array( 'code' => '123456' ), Customers::verify_payload( ' 123456 ' ) );
	}

	public function test_portal_session_payloads(): void {
		foreach ( array( 'home', 'rewards', 'points', 'credits', 'not-a-page' ) as $destination ) {
			$payload = Customers::portal_session_payload( 17, $destination );

			$this->assertSame( array(), ContractValidator::validate( 'customer-portal-session.request.json', $payload ), $destination );
		}

		$this->assertSame( 'customers/5001/portal-session', Customers::portal_session_path( '5001' ) );
	}

	public function test_the_portal_session_answer_is_read_from_the_schema_examples(): void {
		$schema = json_decode( (string) file_get_contents( ContractValidator::schema_dir() . '/customer-portal-session.response.json' ), true );

		foreach ( $schema['examples'] as $example ) {
			$this->assertSame(
				array(
					'url'        => $example['url'],
					'expires_at' => $example['expires_at'],
				),
				Customers::portal_session_response( $example )
			);
		}
	}

	public function test_customer_paths_encode_the_id(): void {
		$this->assertSame( 'customers/5001', Customers::summary_path( '5001' ) );
		$this->assertSame( 'customers/a%2Fb/otp', Customers::otp_path( 'a/b' ) );
		$this->assertSame( 'customers/5001/otp/verify', Customers::verify_path( '5001' ) );
	}

	public function test_event_ids_satisfy_the_envelope_schema(): void {
		$envelope = array(
			'id'          => Uuid::event_id( 'https://shop.example.test|ping|0' ),
			'type'        => 'ping',
			'version'     => 1,
			'occurred_at' => '2026-10-24T08:00:00Z',
			'sequence'    => 0,
			'site_url'    => 'https://shop.example.test',
			'outlet_id'   => '101',
			'data'        => new \stdClass(),
		);

		$this->assertSame( array(), ContractValidator::validate( 'event.request.json', $envelope ) );
	}
}
