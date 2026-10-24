<?php
/**
 * Translated strings of the checkout panels' scripts.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * The Blocks and classic scripts render the same panel, so they receive the same translated strings from PHP
 * (one translation source; placeholders are `%s` / `%1$s`, filled in by the scripts).
 */
final class Strings {

	/**
	 * Every string, keyed by its use.
	 *
	 * @return array<string,string>
	 */
	public static function all(): array {
		return array(
			'title'            => __( 'Your rewards', 'pixalink-loyalty-for-woocommerce' ),
			/* translators: %s: balances such as "1,250 points · 25.00 credits". */
			'balance'          => __( 'You have %s', 'pixalink-loyalty-for-woocommerce' ),
			'separator'        => _x( ' · ', 'between balances', 'pixalink-loyalty-for-woocommerce' ),
			'vouchers_heading' => __( 'Your vouchers', 'pixalink-loyalty-for-woocommerce' ),
			'voucher_apply'    => __( 'Apply', 'pixalink-loyalty-for-woocommerce' ),
			'voucher_remove'   => __( 'Remove', 'pixalink-loyalty-for-woocommerce' ),
			/* translators: %s: voucher title. */
			'voucher_apply_a'  => __( 'Apply %s', 'pixalink-loyalty-for-woocommerce' ),
			/* translators: %s: voucher title. */
			'voucher_remove_a' => __( 'Remove %s', 'pixalink-loyalty-for-woocommerce' ),
			'points_heading'   => __( 'Pay with points', 'pixalink-loyalty-for-woocommerce' ),
			'points_label'     => __( 'Points to use', 'pixalink-loyalty-for-woocommerce' ),
			'points_apply'     => __( 'Use points', 'pixalink-loyalty-for-woocommerce' ),
			'points_remove'    => __( 'Remove points', 'pixalink-loyalty-for-woocommerce' ),
			/* translators: 1: number of points, 2: discount such as "RM10.00". */
			'points_preview'   => __( '%1$s points = %2$s off', 'pixalink-loyalty-for-woocommerce' ),
			/* translators: %s: smallest number of points that can be used. */
			'points_unusable'  => __( 'Paying with points starts at %s points.', 'pixalink-loyalty-for-woocommerce' ),
			'credits_heading'  => __( 'Pay with credits', 'pixalink-loyalty-for-woocommerce' ),
			'credits_label'    => __( 'Credits to use', 'pixalink-loyalty-for-woocommerce' ),
			'credits_apply'    => __( 'Use credits', 'pixalink-loyalty-for-woocommerce' ),
			'credits_remove'   => __( 'Remove credits', 'pixalink-loyalty-for-woocommerce' ),
			/* translators: %s: most credits usable, such as "25.00". */
			'credits_max'      => __( 'Up to %s credits on this order', 'pixalink-loyalty-for-woocommerce' ),
			/* translators: %s: number of points. */
			'earn_preview'     => __( 'You will earn about %s points with this order.', 'pixalink-loyalty-for-woocommerce' ),
			'loading'          => __( 'Updating your rewards…', 'pixalink-loyalty-for-woocommerce' ),
			'quote_error'      => __( 'We could not load your rewards right now. Please try again in a moment.', 'pixalink-loyalty-for-woocommerce' ),
			'error'            => __( 'Something went wrong. Please try again.', 'pixalink-loyalty-for-woocommerce' ),
			'nothing'          => __( 'You have no rewards to use on this order yet.', 'pixalink-loyalty-for-woocommerce' ),
			'verify_link'      => __( 'Verify now', 'pixalink-loyalty-for-woocommerce' ),
		);
	}
}
