<?php
/**
 * Unit: points and credits cap maths.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Redemption;

use Pixalink\Loyalty\Redemption\PointsMath;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * Integer arithmetic only: a point's value is a decimal string ("0.01", "0.005"), never a float.
 *
 * @covers \Pixalink\Loyalty\Redemption\PointsMath
 */
final class PointsMathTest extends TestCase {

	/**
	 * Points config as the quote sends it.
	 *
	 * @param array<string,mixed> $overrides Overrides.
	 * @return array{balance:int,available:int,value_per_point:string,min:int,max_for_cart:int,step:int}
	 */
	private static function config( array $overrides = array() ): array {
		return array_merge(
			array(
				'balance'         => 1250,
				'available'       => 1250,
				'value_per_point' => '0.01',
				'min'             => 100,
				'max_for_cart'    => 1200,
				'step'            => 100,
			),
			$overrides
		);
	}

	/**
	 * @return array<string,array{0:int,1:string,2:int}>
	 */
	public static function values(): array {
		return array(
			'1,000 pts at 0.01'         => array( 1000, '0.01', 1000 ),
			'1 pt at 0.01'              => array( 1, '0.01', 1 ),
			'333 pts at 0.005 (floors)' => array( 333, '0.005', 166 ),
			'10 pts at 1'               => array( 10, '1', 1000 ),
			'7 pts at 0.10'             => array( 7, '0.10', 70 ),
			'3 pts at 0.333'            => array( 3, '0.333', 99 ),
			'zero'                      => array( 0, '0.01', 0 ),
			'malformed value'           => array( 100, 'abc', 0 ),
			'negative value'            => array( 100, '-0.01', 0 ),
		);
	}

	/**
	 * @dataProvider values
	 *
	 * @param int    $points   Points.
	 * @param string $value    Value per point.
	 * @param int    $expected Cents.
	 */
	public function test_value_of_points_never_rounds_up( int $points, string $value, int $expected ): void {
		$this->assertSame( $expected, PointsMath::value_cents( $points, $value ) );
	}

	public function test_points_needed_for_a_discount_round_up_to_the_whole_point(): void {
		$this->assertSame( 1000, PointsMath::points_for_cents( 1000, '0.01' ) );
		$this->assertSame( 1500, PointsMath::points_for_cents( 1500, '0.01' ) );
		$this->assertSame( 5124, PointsMath::points_for_cents( 1537, '0.003' ), '15.37 at 0.3 cents a point is 5,123.3 points' );
		$this->assertSame( 2, PointsMath::points_for_cents( 101, '1' ) );
		$this->assertSame( 0, PointsMath::points_for_cents( 0, '0.01' ) );
		$this->assertSame( 0, PointsMath::points_for_cents( 500, '0' ), 'worthless points cannot pay for anything' );
	}

	public function test_most_points_a_discount_can_absorb_round_down(): void {
		$this->assertSame( 1500, PointsMath::max_points_for_cents( 1500, '0.01' ) );
		$this->assertSame( 3074, PointsMath::max_points_for_cents( 1537, '0.005' ) );
		$this->assertSame( 0, PointsMath::max_points_for_cents( 99, '1' ) );
		$this->assertSame( 0, PointsMath::max_points_for_cents( 1000, '0' ) );
	}

	/**
	 * @return array<string,array{0:int,1:array<string,mixed>,2:int,3:int}>
	 */
	public static function clamps(): array {
		return array(
			'rounds down to the step'         => array( 1234, array(), 100000, 1200 ),
			'capped at max_for_cart'          => array( 999999, array(), 100000, 1200 ),
			'capped at available'             => array( 999999, array( 'available' => 700, 'max_for_cart' => 5000 ), 100000, 700 ),
			'capped at what the cart absorbs' => array( 1200, array(), 1550, 1200 ),
			'cart absorbs less than asked'    => array( 1200, array(), 950, 900 ),
			'cart absorbs nothing'            => array( 1200, array(), 0, 0 ),
			'negative request'                => array( -50, array(), 100000, 0 ),
			'step 1'                          => array( 1234, array( 'step' => 1 ), 100000, 1200 ),
			'step 0 treated as 1'             => array( 1199, array( 'step' => 0 ), 100000, 1199 ),
			'less than one step rounds to 0'  => array( 50, array(), 100000, 0 ),
			'exactly the minimum'             => array( 100, array(), 100000, 100 ),
		);
	}

	/**
	 * @dataProvider clamps
	 *
	 * @param int                 $requested Requested points.
	 * @param array<string,mixed> $overrides Config overrides.
	 * @param int                 $remaining Cents the cart can still absorb.
	 * @param int                 $expected  Points.
	 */
	public function test_clamp_points( int $requested, array $overrides, int $remaining, int $expected ): void {
		$this->assertSame( $expected, PointsMath::clamp_points( $requested, self::config( $overrides ), $remaining ) );
	}

	public function test_the_most_points_a_cart_takes(): void {
		$this->assertSame( 1200, PointsMath::max_points( self::config(), 100000 ) );
		$this->assertSame( 900, PointsMath::max_points( self::config(), 999 ) );
		$this->assertSame( 0, PointsMath::max_points( self::config( array( 'available' => 0 ) ), 100000 ) );
	}

	public function test_below_minimum(): void {
		$this->assertTrue( PointsMath::is_below_minimum( 50, self::config() ) );
		$this->assertFalse( PointsMath::is_below_minimum( 100, self::config() ) );
		$this->assertFalse( PointsMath::is_below_minimum( 0, self::config() ), 'zero means "no points", not too few' );
	}

	public function test_smallest_redeemable_amount_is_the_minimum_on_a_step(): void {
		$this->assertSame( 100, PointsMath::smallest_points( self::config() ) );
		$this->assertSame( 200, PointsMath::smallest_points( self::config( array( 'min' => 150 ) ) ) );
		$this->assertSame( 100, PointsMath::smallest_points( self::config( array( 'min' => 0 ) ) ), 'at least one step' );
		$this->assertSame( 1, PointsMath::smallest_points( self::config( array( 'min' => 0, 'step' => 1 ) ) ) );
	}

	public function test_points_actually_spent_for_a_capped_discount(): void {
		// 1,200 pts selected (12.00), WooCommerce capped the fixed_cart coupon at a 9.37 line.
		$this->assertSame( 937, PointsMath::points_spent( 1200, 937, '0.01' ) );
		$this->assertSame( 1200, PointsMath::points_spent( 1200, 1200, '0.01' ), 'uncapped: what was selected' );
		$this->assertSame( 1200, PointsMath::points_spent( 1200, 1300, '0.01' ), 'never more than selected' );
		$this->assertSame( 0, PointsMath::points_spent( 0, 1000, '0.01' ) );
	}

	public function test_clamp_credits(): void {
		$this->assertSame( 1000, PointsMath::clamp_cents( 1000, 2500, 2000, 100000 ) );
		$this->assertSame( 2000, PointsMath::clamp_cents( 5000, 2500, 2000, 100000 ), 'max_for_cart_cents' );
		$this->assertSame( 1500, PointsMath::clamp_cents( 5000, 1500, 2000, 100000 ), 'balance' );
		$this->assertSame( 750, PointsMath::clamp_cents( 5000, 2500, 2000, 750 ), 'what the cart absorbs' );
		$this->assertSame( 0, PointsMath::clamp_cents( -1, 2500, 2000, 750 ) );
	}
}
