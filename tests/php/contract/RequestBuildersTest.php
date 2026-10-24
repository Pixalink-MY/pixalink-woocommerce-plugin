<?php
/**
 * Contract: request bodies built by the plugin.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Contract;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Api\Resources\Connect;
use Pixalink\Loyalty\Connect\LeadPayload;
use Pixalink\Loyalty\Connect\PkceState;
use Pixalink\Loyalty\Support\Uuid;
use Pixalink\Loyalty\Tests\FakeServer\ContractValidator;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * The plugin's request builders produce bodies the server's schemas accept.
 *
 * Builders for customers, quote, redemptions and events join this suite as work units P05-P09 add them.
 *
 * @covers \Pixalink\Loyalty\Api\Resources\Connect
 * @covers \Pixalink\Loyalty\Connect\LeadPayload
 * @covers \Pixalink\Loyalty\Connect\PkceState
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
