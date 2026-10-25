<?php
/**
 * Integer cap maths for points and credits.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Redemption;

defined( 'ABSPATH' ) || exit;

/**
 * Converts between points and cents and clamps a shopper's choice to what the quote and the cart allow.
 *
 * A point's value arrives as a decimal string in major units (`"0.01"`, `"0.005"`). It is turned into integer
 * micro-cents once, so no float ever decides how much a shopper pays. Rounding always favours neither side
 * unfairly: a number of points is worth the cents it covers rounded down, and a discount costs the points that
 * cover it rounded up.
 */
final class PointsMath {

	/**
	 * Micro-cents per cent.
	 */
	private const SCALE = 1000000;

	/**
	 * Cents a number of points is worth (rounded down).
	 *
	 * @param int    $points          Points.
	 * @param string $value_per_point Value of one point in major units.
	 * @return int
	 */
	public static function value_cents( int $points, string $value_per_point ): int {
		$micro = self::micro_cents( $value_per_point );
		if ( $points < 1 || $micro < 1 ) {
			return 0;
		}

		return intdiv( $points * $micro, self::SCALE );
	}

	/**
	 * The fewest points that cover a discount (rounded up to the whole point).
	 *
	 * @param int    $cents           Discount in cents.
	 * @param string $value_per_point Value of one point.
	 * @return int
	 */
	public static function points_for_cents( int $cents, string $value_per_point ): int {
		$micro = self::micro_cents( $value_per_point );
		if ( $cents < 1 || $micro < 1 ) {
			return 0;
		}

		return intdiv( $cents * self::SCALE + $micro - 1, $micro );
	}

	/**
	 * The most points a discount of this size can absorb (rounded down).
	 *
	 * @param int    $cents           Cents available.
	 * @param string $value_per_point Value of one point.
	 * @return int
	 */
	public static function max_points_for_cents( int $cents, string $value_per_point ): int {
		$micro = self::micro_cents( $value_per_point );
		if ( $cents < 1 || $micro < 1 ) {
			return 0;
		}

		return intdiv( $cents * self::SCALE, $micro );
	}

	/**
	 * The most points this cart takes: the balance, the quote's cap and what the cart can still absorb, on a step.
	 *
	 * @param array{available:int,value_per_point:string,max_for_cart:int,step:int} $config          Quote points.
	 * @param int                                                                    $remaining_cents Cents the cart can still absorb.
	 * @return int
	 */
	public static function max_points( array $config, int $remaining_cents ): int {
		$cap = min(
			max( 0, $config['available'] ),
			max( 0, $config['max_for_cart'] ),
			self::max_points_for_cents( $remaining_cents, $config['value_per_point'] )
		);

		return self::floor_to_step( $cap, $config['step'] );
	}

	/**
	 * A requested number of points, clamped to {@see PointsMath::max_points()} and rounded down to the step.
	 *
	 * The minimum is not applied here: a result between 1 and `min` is the caller's to refuse
	 * ({@see PointsMath::is_below_minimum()}).
	 *
	 * @param int                                                                    $requested       Requested points.
	 * @param array{available:int,value_per_point:string,max_for_cart:int,step:int} $config          Quote points.
	 * @param int                                                                    $remaining_cents Cents the cart can still absorb.
	 * @return int
	 */
	public static function clamp_points( int $requested, array $config, int $remaining_cents ): int {
		return self::floor_to_step( min( max( 0, $requested ), self::max_points( $config, $remaining_cents ) ), $config['step'] );
	}

	/**
	 * The smallest amount of points that can be redeemed: the minimum, rounded up to the step.
	 *
	 * @param array{min:int,step:int} $config Quote points.
	 * @return int
	 */
	public static function smallest_points( array $config ): int {
		$step = max( 1, $config['step'] );

		return intdiv( max( 1, $config['min'] ) + $step - 1, $step ) * $step;
	}

	/**
	 * Whether a non-zero amount of points is below the quote's minimum.
	 *
	 * @param int               $points Points.
	 * @param array{min:int}    $config Quote points.
	 * @return bool
	 */
	public static function is_below_minimum( int $points, array $config ): bool {
		return $points > 0 && $points < $config['min'];
	}

	/**
	 * Points a coupon line actually spent: what the shopper selected, or fewer when WooCommerce capped the
	 * fixed_cart discount at the cart subtotal (the fewest points that cover the discount given).
	 *
	 * @param int    $selected        Points the shopper selected.
	 * @param int    $value_cents     Value WooCommerce took off for them.
	 * @param string $value_per_point Value of one point.
	 * @return int
	 */
	public static function points_spent( int $selected, int $value_cents, string $value_per_point ): int {
		if ( $selected < 1 ) {
			return 0;
		}

		return min( $selected, self::points_for_cents( $value_cents, $value_per_point ) );
	}

	/**
	 * A requested amount of credits in cents, clamped to the balance, the quote's cap and what the cart absorbs.
	 *
	 * @param int $requested       Requested cents.
	 * @param int $balance         Balance in cents.
	 * @param int $max_for_cart    Quote cap in cents.
	 * @param int $remaining_cents Cents the cart can still absorb.
	 * @return int
	 */
	public static function clamp_cents( int $requested, int $balance, int $max_for_cart, int $remaining_cents ): int {
		return max( 0, min( $requested, $balance, $max_for_cart, $remaining_cents ) );
	}

	/**
	 * Round down to a multiple of the step (a step below 1 counts as 1).
	 *
	 * @param int $points Points.
	 * @param int $step   Step.
	 * @return int
	 */
	public static function floor_to_step( int $points, int $step ): int {
		$step = max( 1, $step );

		return $points < 1 ? 0 : intdiv( $points, $step ) * $step;
	}

	/**
	 * A non-negative decimal string in major units as integer micro-cents (0 when malformed).
	 *
	 * @param string $value Decimal such as "0.01".
	 * @return int
	 */
	public static function micro_cents( string $value ): int {
		$value = trim( $value );
		if ( 1 !== preg_match( '/^([0-9]{1,9})(?:\.([0-9]{1,12}))?$/', $value, $parts ) ) {
			return 0;
		}

		// Major units to micro-cents is a factor of 10^8: two digits for cents, six for the micro scale.
		$fraction = substr( str_pad( $parts[2] ?? '', 8, '0' ), 0, 8 );

		return (int) $parts[1] * 100 * self::SCALE + (int) $fraction;
	}
}
