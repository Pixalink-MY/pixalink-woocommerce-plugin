<?php
/**
 * Tests for Money.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Support;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Support\Money;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * @covers \Pixalink\Loyalty\Support\Money
 */
final class MoneyTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();

		// Same number_format() path wc_format_decimal() takes when $dp is an integer.
		Functions\when( 'wc_format_decimal' )->alias(
			static fn ( $number, $dp = false, $trim_zeros = false ): string => number_format( (float) str_replace( ',', '.', (string) $number ), (int) $dp, '.', '' )
		);
		Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );
	}

	/**
	 * @dataProvider decimal_cases
	 *
	 * @param string|int|float $amount   Input.
	 * @param int|null         $decimals Decimals.
	 * @param string           $expected Output.
	 */
	public function test_to_decimal( $amount, ?int $decimals, string $expected ): void {
		$this->assertSame( $expected, Money::to_decimal( $amount, $decimals ) );
	}

	/**
	 * @return array<string,array{0:string|int|float,1:int|null,2:string}>
	 */
	public static function decimal_cases(): array {
		return array(
			'string with store decimals' => array( '12.5', null, '12.50' ),
			'integer'                    => array( 10, null, '10.00' ),
			'exact half rounds up'       => array( 0.125, 2, '0.13' ),
			'zero decimals'              => array( '99.6', 0, '100' ),
			'negative'                   => array( '-3', 2, '-3.00' ),
			'negative zero normalised'   => array( '-0.001', 2, '0.00' ),
			'locale comma separator'     => array( '7,25', 2, '7.25' ),
		);
	}

	/**
	 * @dataProvider cents_cases
	 *
	 * @param string|int|float $amount   Input.
	 * @param int              $expected Cents.
	 */
	public function test_to_cents( $amount, int $expected ): void {
		$this->assertSame( $expected, Money::to_cents( $amount ) );
	}

	/**
	 * @return array<string,array{0:string|int|float,1:int}>
	 */
	public static function cents_cases(): array {
		return array(
			'whole'          => array( '12', 1200 ),
			'two decimals'   => array( '12.34', 1234 ),
			'one decimal'    => array( '0.5', 50 ),
			'rounds'         => array( '0.125', 13 ),
			'float artefact' => array( 0.1 + 0.2, 30 ),
			'negative'       => array( '-4.05', -405 ),
			'large'          => array( '123456.78', 12345678 ),
		);
	}

	public function test_from_cents(): void {
		$this->assertSame( '12.34', Money::from_cents( 1234 ) );
		$this->assertSame( '0.05', Money::from_cents( 5 ) );
		$this->assertSame( '0.00', Money::from_cents( 0 ) );
		$this->assertSame( '-4.05', Money::from_cents( -405 ) );
	}

	public function test_cents_round_trip(): void {
		foreach ( array( '0.01', '19.99', '-250.00', '1000000.10' ) as $amount ) {
			$this->assertSame( $amount, Money::from_cents( Money::to_cents( $amount ) ) );
		}
	}

	public function test_store_currency_reads_woocommerce(): void {
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'MYR' );

		$this->assertSame( 'MYR', Money::store_currency() );
	}
}
