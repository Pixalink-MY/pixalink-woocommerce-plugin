<?php
/**
 * Contract: request bodies built by the plugin.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Contract;

use Pixalink\Loyalty\Api\Resources\Connect;
use Pixalink\Loyalty\Support\Uuid;
use Pixalink\Loyalty\Tests\FakeServer\ContractValidator;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * The plugin's request builders produce bodies the server's schemas accept.
 *
 * Builders for customers, quote, redemptions and events join this suite as work units P05-P09 add them.
 *
 * @covers \Pixalink\Loyalty\Api\Resources\Connect
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
