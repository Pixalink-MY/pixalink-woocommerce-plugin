<?php
/**
 * Contract: the fake Pixalink server's canned responses.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Contract;

use Pixalink\Loyalty\Tests\FakeServer\ContractValidator;
use Pixalink\Loyalty\Tests\FakeServer\Responses;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * Every default response of the fake server matches the schema the real server must satisfy.
 *
 * @covers \Pixalink\Loyalty\Tests\FakeServer\Responses
 */
final class FakeServerResponsesTest extends TestCase {

	/**
	 * Canned responses keyed by scenario.
	 *
	 * @return array<string,array{0:array{status:int,body:mixed},1:string}>
	 */
	public static function samples(): array {
		$cases = array();
		foreach ( Responses::samples( 1761300000 ) as $name => $sample ) {
			$cases[ $name ] = array( $sample['response'], $sample['schema'] );
		}

		return $cases;
	}

	/**
	 * @dataProvider samples
	 *
	 * @param array{status:int,body:mixed} $response Canned response.
	 * @param string                       $schema   Schema file.
	 */
	public function test_canned_response_matches_its_schema( array $response, string $schema ): void {
		$this->assertSame( array(), ContractValidator::validate( $schema, $response['body'] ) );

		if ( 'error.response.json' === $schema ) {
			$this->assertGreaterThanOrEqual( 400, $response['status'] );
		} else {
			$this->assertContains( $response['status'], array( 200, 201 ) );
		}
	}

	public function test_scenarios_return_the_documented_status_codes(): void {
		$samples = Responses::samples( 1761300000 );

		$this->assertSame( 400, $samples['exchange invalid']['response']['status'] );
		$this->assertSame( 201, $samples['create new']['response']['status'] );
		$this->assertSame( 422, $samples['otp verify wrong']['response']['status'] );
		$this->assertSame( 409, $samples['hold too many points']['response']['status'] );
		$this->assertSame( Responses::POINTS_AVAILABLE, $samples['hold too many points']['response']['body']['available'] );
		$this->assertSame( 'duplicate', $samples['event duplicate']['response']['body']['status'] );
		$this->assertSame( 1761300000, $samples['timestamp skew']['response']['body']['server_time'] );
	}
}
