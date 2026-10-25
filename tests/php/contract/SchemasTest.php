<?php
/**
 * Contract: the JSON Schemas themselves.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Contract;

use Pixalink\Loyalty\Api\Signer;
use Pixalink\Loyalty\Tests\FakeServer\ContractValidator;
use Pixalink\Loyalty\Tests\FakeServer\Responses;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * Every schema compiles, carries a matching $id and accepts its own examples; the manifest is complete.
 *
 * @coversNothing
 */
final class SchemasTest extends TestCase {

	/**
	 * Schema files (excluding the manifest).
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function schema_files(): array {
		$files = array();
		foreach ( glob( dirname( __DIR__, 2 ) . '/contract/schemas/*.json' ) ?: array() as $path ) {
			$name = basename( $path );
			if ( 'endpoints.json' !== $name ) {
				$files[ $name ] = array( $name );
			}
		}

		return $files;
	}

	/**
	 * @dataProvider schema_files
	 *
	 * @param string $file Schema file name.
	 */
	public function test_schema_declares_draft_2020_12_and_its_own_id( string $file ): void {
		$schema = json_decode( (string) file_get_contents( ContractValidator::schema_dir() . '/' . $file ), true );

		$this->assertIsArray( $schema, $file . ' is not valid JSON.' );
		$this->assertSame( 'https://json-schema.org/draft/2020-12/schema', $schema['$schema'] ?? null );
		$this->assertSame( ContractValidator::ID_PREFIX . $file, $schema['$id'] ?? null );
	}

	/**
	 * @dataProvider schema_files
	 *
	 * @param string $file Schema file name.
	 */
	public function test_schema_accepts_its_examples( string $file ): void {
		$schema = json_decode( (string) file_get_contents( ContractValidator::schema_dir() . '/' . $file ) );
		if ( 'common.json' === $file ) {
			$this->assertIsObject( $schema->{'$defs'} ?? null );
			return;
		}

		$this->assertNotEmpty( $schema->examples ?? array(), $file . ' must carry at least one example for the explore mirror.' );
		foreach ( $schema->examples as $index => $example ) {
			$this->assertSame( array(), ContractValidator::validate( $file, $example ), $file . ' example #' . $index );
		}
	}

	public function test_the_validator_rejects_contract_violations(): void {
		$violations = ContractValidator::validate(
			'redemptions-put.request.json',
			array(
				'customer_id' => '5001',
				'cart'        => array(
					'lines'              => array(),
					'subtotal_cents'     => 0,
					'currency'           => 'myr',
					'shipping_cents'     => 0,
					'discount_cents'     => 0,
					'tax_cents'          => 0,
					'prices_include_tax' => false,
					'held_refs'          => array(),
				),
				'redemptions' => array(
					array(
						'kind'               => 'points',
						'coupon_code'        => 'pxl-points',
						'discount_cents'     => 500,
						'discount_tax_cents' => 0,
					),
				),
			)
		);

		$this->assertNotEmpty( $violations );
		$this->assertStringContainsString( '/cart/currency', implode( "\n", $violations ) );
		$this->assertStringContainsString( '/redemptions/0', implode( "\n", $violations ), 'Points redemptions must carry points.' );

		// Cross-file $ref (common.json#/$defs/outlet) is resolved and enforced.
		$this->assertSame(
			array( '/outlet: The required properties (uuid) are missing' ),
			ContractValidator::validate(
				'outlet.response.json',
				array(
					'outlet'       => array(
						'id'   => '1',
						'name' => 'Outlet',
					),
					'organisation' => array(
						'id'   => '1',
						'name' => 'Org',
					),
				)
			)
		);

		// Type-conditional event data is enforced.
		$this->assertSame(
			array( '/data: The required properties (order, customer) are missing' ),
			ContractValidator::validate(
				'event.request.json',
				array(
					'id'          => 'a6417789-0a05-5068-bab0-90d991946d97',
					'type'        => 'order.paid',
					'version'     => 1,
					'occurred_at' => '2026-10-24T08:00:00Z',
					'sequence'    => 1,
					'site_url'    => 'https://shop.example.test',
					'outlet_id'   => '101',
					'data'        => new \stdClass(),
				)
			)
		);
	}

	public function test_manifest_references_existing_schemas_and_covers_every_fake_route(): void {
		$routes = array();
		foreach ( ContractValidator::endpoints() as $endpoint ) {
			foreach ( array( 'request', 'response', 'query' ) as $field ) {
				if ( isset( $endpoint[ $field ] ) ) {
					$this->assertFileExists( ContractValidator::schema_dir() . '/' . $endpoint[ $field ] );
				}
			}
			$routes[] = $endpoint['path'];
		}

		$this->assertEqualsCanonicalizing( Responses::ROUTES, array_values( array_unique( $routes ) ) );
	}

	public function test_hosted_pages_reference_existing_schemas(): void {
		$hosted = ContractValidator::hosted();

		$this->assertSame( array( 'connect/woocommerce', 'l/{space}/handoff/{ticket}' ), array_column( $hosted, 'path' ) );
		foreach ( $hosted as $page ) {
			foreach ( array( 'query', 'lead', 'callback' ) as $field ) {
				if ( isset( $page[ $field ] ) ) {
					$this->assertFileExists( ContractValidator::schema_dir() . '/' . $page[ $field ] );
				}
			}
		}
	}

	public function test_plugin_routes_reference_existing_schemas_and_are_signed_with_the_event_id(): void {
		$manifest = json_decode( (string) file_get_contents( ContractValidator::schema_dir() . '/endpoints.json' ), true );
		$plugin   = $manifest['plugin'] ?? array();

		$this->assertSame( array( 'pixalink-loyalty/v1/webhook' ), array_column( $plugin, 'path' ) );
		foreach ( $plugin as $route ) {
			$this->assertSame( 'event_id', $route['nonce'] );
			$this->assertSame( 'signature', $route['auth'] );
			$this->assertFileExists( ContractValidator::schema_dir() . '/' . $route['request'] );
			$this->assertFileExists( ContractValidator::schema_dir() . '/' . $route['response'] );
		}
	}

	public function test_customer_create_requires_a_phone_unless_it_redeems_a_ticket(): void {
		$this->assertNotSame( array(), ContractValidator::validate( 'customers-create.request.json', array( 'verified_via' => 'none' ) ), 'No phone.' );
		$this->assertNotSame( array(), ContractValidator::validate( 'customers-create.request.json', array( 'verified_via' => 'sso_ticket' ) ), 'No ticket.' );
		$this->assertNotSame(
			array(),
			ContractValidator::validate(
				'customers-create.request.json',
				array(
					'phone'         => '+60123456789',
					'phone_country' => 'MY',
					'verified_via'  => 'none',
					'sso_ticket'    => 'fake-sso-member-0000',
				)
			),
			'A ticket only travels with verified_via sso_ticket.'
		);
		$this->assertSame(
			array(),
			ContractValidator::validate(
				'customers-create.request.json',
				array(
					'verified_via' => 'sso_ticket',
					'sso_ticket'   => 'fake-sso-member-0000',
				)
			)
		);
	}

	public function test_every_route_lists_its_errors_and_each_error_carries_what_the_envelope_requires(): void {
		$manifest = json_decode( (string) file_get_contents( ContractValidator::schema_dir() . '/endpoints.json' ), true );
		foreach ( array_merge( $manifest['endpoints'], $manifest['plugin'] ) as $route ) {
			$this->assertIsArray( $route['errors'] ?? null, $route['method'] . ' ' . $route['path'] . ' must list its errors (empty when none).' );
		}

		$envelope_fields = array( 'server_time', 'available', 'available_cents', 'errors' );
		$values          = array(
			'server_time'     => 1761300000,
			'available'       => 120,
			'available_cents' => 2500,
			'errors'          => array( 'phone' => array( 'The phone field is required.' ) ),
		);
		foreach ( ContractValidator::documented_errors() as $error ) {
			$label = $error['status'] . ' ' . $error['code'];
			$this->assertGreaterThanOrEqual( 400, $error['status'], $label );
			$this->assertLessThan( 600, $error['status'], $label );
			$this->assertMatchesRegularExpression( '/^[a-z][a-z0-9_]*$/', $error['code'], $label );

			$carries = $error['carries'] ?? array();
			$this->assertSame( array(), array_diff( $carries, $envelope_fields ), $label );

			$body = array(
				'message' => 'x',
				'code'    => $error['code'],
			);
			$this->assertSame( array(), ContractValidator::validate( 'error.response.json', $body + array_intersect_key( $values, array_flip( $carries ) ) ), $label );
			if ( array() !== $carries ) {
				$this->assertNotSame( array(), ContractValidator::validate( 'error.response.json', $body ), $label . ' without ' . implode( ', ', $carries ) );
			}
		}
	}

	public function test_every_error_the_fake_server_answers_is_documented_with_the_same_status(): void {
		$documented = array();
		foreach ( ContractValidator::documented_errors() as $error ) {
			$documented[ $error['code'] ][] = $error['status'];
		}

		foreach ( Responses::samples( 1761300000 ) as $name => $sample ) {
			if ( 'error.response.json' !== $sample['schema'] ) {
				continue;
			}

			$code = $sample['response']['body']['code'];
			$this->assertArrayHasKey( $code, $documented, $name . ': ' . $code . ' is not in endpoints.json.' );
			$this->assertContains( $sample['response']['status'], $documented[ $code ], $name );
		}
	}

	public function test_bodiless_endpoints_declare_their_status(): void {
		foreach ( ContractValidator::endpoints() as $endpoint ) {
			if ( null === $endpoint['response'] ) {
				$this->assertSame( 204, $endpoint['status'] ?? null, $endpoint['path'] );
			}
		}
	}

	public function test_callback_schema_tells_success_from_failure(): void {
		$this->assertNotSame( array(), ContractValidator::validate( 'connect-callback.query.json', array( 'state' => 'x' ) ), 'Neither code nor error.' );
		$this->assertNotSame(
			array(),
			ContractValidator::validate(
				'connect-callback.query.json',
				array(
					'code'  => 'pxlc_0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJ',
					'error' => 'access_denied',
					'state' => 'x',
				)
			),
			'Both code and error.'
		);
	}

	public function test_manifest_nonce_rule_matches_the_signer(): void {
		$manifest = json_decode( (string) file_get_contents( ContractValidator::schema_dir() . '/endpoints.json' ), true );

		$this->assertSame( Signer::NONCE_PATTERN, '/' . $manifest['signature']['nonce_pattern'] . '/' );
		$this->assertSame( Signer::WINDOW_SECONDS, $manifest['signature']['window_seconds'] );
	}
}
