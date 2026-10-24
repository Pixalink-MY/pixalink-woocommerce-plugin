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
				'currency'    => 'myr',
				'redemptions' => array(
					array(
						'kind'         => 'points',
						'coupon_code'  => 'pxl-points',
						'discount'     => '5',
						'discount_tax' => '0',
					),
				),
			)
		);

		$this->assertNotEmpty( $violations );
		$this->assertStringContainsString( '/currency', implode( "\n", $violations ) );
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

	public function test_manifest_nonce_rule_matches_the_signer(): void {
		$manifest = json_decode( (string) file_get_contents( ContractValidator::schema_dir() . '/endpoints.json' ), true );

		$this->assertSame( Signer::NONCE_PATTERN, '/' . $manifest['signature']['nonce_pattern'] . '/' );
		$this->assertSame( Signer::WINDOW_SECONDS, $manifest['signature']['window_seconds'] );
	}
}
