<?php
/**
 * Shopper-facing labels and messages of redemptions.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Redemption;

defined( 'ABSPATH' ) || exit;

/**
 * One place for the words shoppers see on coupon chips, review rows, the loyalty panel and refusals, so both
 * checkouts say the same thing.
 *
 * Credits are a Pixalink balance, not money: they are shown as "10.00 credits", never with a currency symbol.
 * Only the discount they give in the order totals is a currency amount.
 */
final class Labels {

	/**
	 * "Points (1,000 pts)".
	 *
	 * @param int $points Points.
	 * @return string
	 */
	public static function points( int $points ): string {
		/* translators: %s: number of points, formatted. */
		return sprintf( __( 'Points (%s pts)', 'pixalink-loyalty-for-woocommerce' ), number_format_i18n( $points ) );
	}

	/**
	 * "Credits (10.00 credits)".
	 *
	 * @param int $cents Credits in hundredths.
	 * @return string
	 */
	public static function credits( int $cents ): string {
		/* translators: %s: credits, formatted such as "10.00 credits". */
		return sprintf( __( 'Credits (%s)', 'pixalink-loyalty-for-woocommerce' ), self::credit_amount( $cents ) );
	}

	/**
	 * "10.00 credits".
	 *
	 * @param int $cents Credits in hundredths.
	 * @return string
	 */
	public static function credit_amount( int $cents ): string {
		/* translators: %s: credit amount such as "10.00". */
		return sprintf( __( '%s credits', 'pixalink-loyalty-for-woocommerce' ), number_format_i18n( $cents / 100, 2 ) );
	}

	/**
	 * "Voucher: Welcome 10 off".
	 *
	 * @param string $title Voucher title.
	 * @return string
	 */
	public static function voucher( string $title ): string {
		/* translators: %s: voucher title. */
		return sprintf( __( 'Voucher: %s', 'pixalink-loyalty-for-woocommerce' ), $title );
	}

	/**
	 * Prompt shown to guests and unlinked shoppers instead of their rewards.
	 *
	 * @return string
	 */
	public static function verify_prompt(): string {
		return __( 'Verify your phone to use your rewards.', 'pixalink-loyalty-for-woocommerce' );
	}

	/**
	 * A kind is switched off (remote kill switch or merchant setting).
	 *
	 * @param string $kind CouponMapper::KIND_*.
	 * @return string
	 */
	public static function unavailable( string $kind ): string {
		switch ( $kind ) {
			case CouponMapper::KIND_POINTS:
				return __( 'Paying with Pixalink points is temporarily unavailable.', 'pixalink-loyalty-for-woocommerce' );
			case CouponMapper::KIND_CREDIT:
				return __( 'Paying with Pixalink credits is temporarily unavailable.', 'pixalink-loyalty-for-woocommerce' );
		}

		return __( 'Pixalink vouchers are temporarily unavailable.', 'pixalink-loyalty-for-woocommerce' );
	}

	/**
	 * Lead-in of the notice WooCommerce shows when it removes one of our coupons.
	 *
	 * @param string $kind CouponMapper::KIND_*.
	 * @return string
	 */
	public static function removed( string $kind ): string {
		switch ( $kind ) {
			case CouponMapper::KIND_POINTS:
				return __( 'Your Pixalink points discount was removed.', 'pixalink-loyalty-for-woocommerce' );
			case CouponMapper::KIND_CREDIT:
				return __( 'Your Pixalink credits discount was removed.', 'pixalink-loyalty-for-woocommerce' );
		}

		return __( 'Your Pixalink voucher was removed.', 'pixalink-loyalty-for-woocommerce' );
	}

	/**
	 * Shopper-facing message for a refused hold.
	 *
	 * @param string $error_code Server error code.
	 * @return string
	 */
	public static function hold_refused( string $error_code ): string {
		switch ( $error_code ) {
			case 'points_insufficient':
				return __( 'Your Pixalink points balance changed, so we updated your points discount. Please check your order total and place the order again.', 'pixalink-loyalty-for-woocommerce' );
			case 'credits_insufficient':
				return __( 'Your Pixalink credits balance changed, so we updated your credits discount. Please check your order total and place the order again.', 'pixalink-loyalty-for-woocommerce' );
			case 'voucher_unavailable':
				return __( 'One of your Pixalink vouchers can no longer be used. Please check your order total and place the order again.', 'pixalink-loyalty-for-woocommerce' );
			case 'discount_mismatch':
				return __( 'Your Pixalink rewards were updated. Please check your order total and place the order again.', 'pixalink-loyalty-for-woocommerce' );
			case 'verification_required':
				return self::verify_prompt();
		}

		return __( 'We could not reserve your Pixalink rewards. Please try again, or remove them to place the order without them.', 'pixalink-loyalty-for-woocommerce' );
	}
}
