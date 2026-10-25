<?php
/**
 * Tests for Uuid.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Support;

use Pixalink\Loyalty\Support\Uuid;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * @covers \Pixalink\Loyalty\Support\Uuid
 */
final class UuidTest extends TestCase {

	public function test_v5_matches_the_rfc_4122_reference_vector(): void {
		// Python: uuid.uuid5(uuid.NAMESPACE_DNS, 'www.example.com').
		$this->assertSame( '2ed6657d-e927-568b-95e1-2665a8aea6a2', Uuid::v5( Uuid::NAMESPACE_DNS, 'www.example.com' ) );
	}

	public function test_v5_is_deterministic_and_name_sensitive(): void {
		$first  = Uuid::v5( Uuid::EVENT_NAMESPACE, 'https://shop.example|order.paid|123|1' );
		$second = Uuid::v5( Uuid::EVENT_NAMESPACE, 'https://shop.example|order.paid|123|1' );
		$other  = Uuid::v5( Uuid::EVENT_NAMESPACE, 'https://shop.example|order.paid|123|2' );

		$this->assertSame( $first, $second );
		$this->assertNotSame( $first, $other );
		$this->assertSame( '5', $first[14] );
	}

	public function test_event_namespace_is_derived_from_its_documented_name(): void {
		$this->assertSame( Uuid::EVENT_NAMESPACE, Uuid::v5( Uuid::NAMESPACE_URL, Uuid::EVENT_NAMESPACE_NAME ) );
	}

	public function test_event_id_pins_the_cross_repo_vector(): void {
		// Python: uuid.uuid5(uuid.uuid5(uuid.NAMESPACE_URL, EVENT_NAMESPACE_NAME), 'https://shop.example|order.paid|123|1').
		$this->assertSame( 'a6417789-0a05-5068-bab0-90d991946d97', Uuid::event_id( 'https://shop.example|order.paid|123|1' ) );
	}

	public function test_v4_sets_version_and_variant_bits_and_is_random(): void {
		$ids = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$id = Uuid::v4();
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id );
			$ids[] = $id;
		}

		$this->assertCount( 50, array_unique( $ids ) );
	}

	public function test_is_valid(): void {
		$this->assertTrue( Uuid::is_valid( Uuid::NAMESPACE_URL ) );
		$this->assertTrue( Uuid::is_valid( strtoupper( Uuid::NAMESPACE_URL ) ) );
		$this->assertFalse( Uuid::is_valid( 'not-a-uuid' ) );
		$this->assertFalse( Uuid::is_valid( '6ba7b8119dad11d180b400c04fd430c8' ) );
	}

	public function test_v5_rejects_an_invalid_namespace(): void {
		$this->expectException( \InvalidArgumentException::class );

		Uuid::v5( 'nope', 'name' );
	}
}
