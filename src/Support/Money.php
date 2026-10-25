<?php
/**
 * Money normalisation for the Pixalink API.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Converts WooCommerce amounts to the API's representations.
 *
 * The API exchanges money as decimal strings ("12.50") alongside a currency code, and a few fields as
 * integer cents (hundredths, e.g. `discount_cents`). Floats never cross the wire.
 */
final class Money {

	/**
	 * Decimal places used for "cents" fields in the API contract.
	 */
	public const CENTS_DECIMALS = 2;

	/**
	 * Normalise an amount to a fixed-precision decimal string using wc_format_decimal().
	 *
	 * @param string|int|float $amount   Amount as WooCommerce stores it (may carry locale separators).
	 * @param int|null         $decimals Decimal places; defaults to the store's price decimals.
	 * @return string For example "12.50" or "-3.00".
	 */
	public static function to_decimal( string|int|float $amount, ?int $decimals = null ): string {
		$decimals = $decimals ?? self::store_decimals();

		$formatted = function_exists( 'wc_format_decimal' )
			? (string) wc_format_decimal( $amount, $decimals, false )
			: number_format( (float) $amount, $decimals, '.', '' );

		// Normalise "-0.00" (which number_format can produce) to "0.00".
		if ( 0 === (int) preg_match( '/[1-9]/', $formatted ) ) {
			$formatted = ltrim( $formatted, '-' );
		}

		return '' === $formatted ? number_format( 0, $decimals, '.', '' ) : $formatted;
	}

	/**
	 * Convert an amount to integer cents (hundredths), rounding half away from zero.
	 *
	 * @param string|int|float $amount Amount.
	 * @return int
	 */
	public static function to_cents( string|int|float $amount ): int {
		$decimal  = self::to_decimal( $amount, self::CENTS_DECIMALS );
		$negative = 0 === strpos( $decimal, '-' );
		$unsigned = ltrim( $decimal, '-' );
		$parts    = explode( '.', $unsigned, 2 );
		$whole    = (int) $parts[0];
		$fraction = (int) str_pad( substr( $parts[1] ?? '0', 0, self::CENTS_DECIMALS ), self::CENTS_DECIMALS, '0' );
		$cents    = ( $whole * 100 ) + $fraction;

		return $negative ? -$cents : $cents;
	}

	/**
	 * Convert integer cents back to a two-decimal string.
	 *
	 * @param int $cents Hundredths.
	 * @return string
	 */
	public static function from_cents( int $cents ): string {
		$sign     = $cents < 0 ? '-' : '';
		$absolute = abs( $cents );

		return sprintf( '%s%d.%02d', $sign, intdiv( $absolute, 100 ), $absolute % 100 );
	}

	/**
	 * The store's configured price decimals (2 when WooCommerce is not loaded).
	 *
	 * @return int
	 */
	public static function store_decimals(): int {
		return function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
	}

	/**
	 * The store's currency code (empty when WooCommerce is not loaded).
	 *
	 * @return string
	 */
	public static function store_currency(): string {
		return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
	}
}
