<?php
/**
 * SPIKE (P02, throwaway): quote data -> WooCommerce coupon props.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Spike;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the arrays returned from `woocommerce_get_shop_coupon_data` (WC_Coupon::read_manual_coupon() props).
 */
final class CouponMapper {

	/**
	 * Fixed code of the points coupon.
	 */
	public const POINTS_CODE = 'pxl-points';

	/**
	 * Pixalink voucher codes are issued with this prefix, so the plugin can claim them before a quote exists.
	 */
	public const VOUCHER_PREFIX = 'pxl-';

	/**
	 * Props for the points coupon: a fixed_cart discount worth points x value_per_point (pre-tax unless prices include tax).
	 *
	 * @param int    $points          Points to redeem.
	 * @param string $value_per_point Decimal value of one point in store currency.
	 * @return array<string,mixed>
	 */
	public static function points( int $points, string $value_per_point ): array {
		return array(
			'discount_type'  => 'fixed_cart',
			'amount'         => self::points_value( $points, $value_per_point ),
			'individual_use' => false,
			'description'    => 'Pixalink points',
		);
	}

	/**
	 * Money value of a number of points, 2 dp.
	 *
	 * @param int    $points          Points.
	 * @param string $value_per_point Value of one point.
	 * @return string
	 */
	public static function points_value( int $points, string $value_per_point ): string {
		return wc_format_decimal( (string) ( $points * (float) $value_per_point ), 2 );
	}

	/**
	 * Props for a voucher coupon from the quote's `woo_coupon` spec (SKUs and slugs resolved to local IDs).
	 *
	 * @param array<string,mixed> $spec `woo_coupon` object (tests/contract/schemas/common.json#/$defs/woo_coupon).
	 * @return array<string,mixed>
	 */
	public static function voucher( array $spec ): array {
		return array(
			'discount_type'               => in_array( $spec['discount_type'] ?? '', array( 'fixed_cart', 'percent', 'fixed_product' ), true ) ? $spec['discount_type'] : 'fixed_cart',
			'amount'                      => is_string( $spec['amount'] ?? null ) ? $spec['amount'] : '0',
			'product_ids'                 => self::product_ids( self::strings( $spec['product_skus'] ?? array() ) ),
			'excluded_product_ids'        => self::product_ids( self::strings( $spec['excluded_product_skus'] ?? array() ) ),
			'product_categories'          => self::category_ids( self::strings( $spec['category_slugs'] ?? array() ) ),
			'minimum_amount'              => is_string( $spec['minimum_amount'] ?? null ) ? $spec['minimum_amount'] : '',
			'maximum_amount'              => is_string( $spec['maximum_amount'] ?? null ) ? $spec['maximum_amount'] : '',
			'limit_usage_to_x_items'      => is_int( $spec['limit_usage_to_x_items'] ?? null ) ? $spec['limit_usage_to_x_items'] : null,
			'exclude_sale_items'          => (bool) ( $spec['exclude_sale_items'] ?? false ),
			'individual_use'              => (bool) ( $spec['individual_use'] ?? false ),
			'description'                 => 'Pixalink voucher',
			'excluded_product_categories' => array(),
		);
	}

	/**
	 * Whether a SKU-scoped voucher matched no local product. An empty product_ids list means "any product" to
	 * WooCommerce, so such a voucher must be refused rather than silently becoming cart-wide.
	 *
	 * @param array<string,mixed> $spec `woo_coupon` object.
	 * @return bool
	 */
	public static function has_unresolved_skus( array $spec ): bool {
		$skus = self::strings( $spec['product_skus'] ?? array() );

		return array() !== $skus && array() === self::product_ids( $skus );
	}

	/**
	 * Placeholder for one of our codes that cannot be resolved right now (unverified shopper, unknown voucher).
	 * Returning data (instead of false) makes WooCommerce run `woocommerce_coupon_is_valid`, where our message is set;
	 * returning false would surface WooCommerce's generic 'Coupon "x" cannot be applied because it does not exist.'.
	 *
	 * @return array<string,mixed>
	 */
	public static function placeholder(): array {
		return array(
			'discount_type' => 'fixed_cart',
			'amount'        => '0',
			'description'   => 'Pixalink (unavailable)',
		);
	}

	/**
	 * Whether a coupon code belongs to Pixalink.
	 *
	 * @param string $code Coupon code.
	 * @return bool
	 */
	public static function is_ours( string $code ): bool {
		return 0 === strpos( wc_strtolower( $code ), self::VOUCHER_PREFIX );
	}

	/**
	 * Snapshot stored on the order's coupon line, enough to rebuild the coupon without a session or quote.
	 *
	 * @param \WC_Coupon $coupon Resolved coupon.
	 * @return array<string,mixed>
	 */
	public static function snapshot( \WC_Coupon $coupon ): array {
		return array(
			'discount_type'          => $coupon->get_discount_type( 'edit' ),
			'amount'                 => wc_format_decimal( $coupon->get_amount( 'edit' ) ),
			'product_ids'            => $coupon->get_product_ids( 'edit' ),
			'excluded_product_ids'   => $coupon->get_excluded_product_ids( 'edit' ),
			'product_categories'     => $coupon->get_product_categories( 'edit' ),
			'limit_usage_to_x_items' => $coupon->get_limit_usage_to_x_items( 'edit' ),
			'exclude_sale_items'     => $coupon->get_exclude_sale_items( 'edit' ),
		);
	}

	/**
	 * Only string entries.
	 *
	 * @param mixed $values Candidate list.
	 * @return array<int,string>
	 */
	private static function strings( $values ): array {
		return is_array( $values ) ? array_values( array_filter( $values, 'is_string' ) ) : array();
	}

	/**
	 * Local product (or variation) IDs for SKUs; unknown SKUs are dropped.
	 *
	 * @param array<int,string> $skus SKUs.
	 * @return array<int,int>
	 */
	private static function product_ids( array $skus ): array {
		$ids = array();
		foreach ( $skus as $sku ) {
			$id = (int) wc_get_product_id_by_sku( $sku );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Local product_cat term IDs for slugs.
	 *
	 * @param array<int,string> $slugs Slugs.
	 * @return array<int,int>
	 */
	private static function category_ids( array $slugs ): array {
		$ids = array();
		foreach ( $slugs as $slug ) {
			$term = get_term_by( 'slug', $slug, 'product_cat' );
			if ( $term instanceof \WP_Term ) {
				$ids[] = (int) $term->term_id;
			}
		}

		return $ids;
	}
}
