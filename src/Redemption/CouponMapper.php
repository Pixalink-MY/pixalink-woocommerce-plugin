<?php
/**
 * Quote data → WooCommerce coupon props.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Redemption;

use Pixalink\Loyalty\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the props `woocommerce_get_shop_coupon_data` returns for Pixalink's virtual coupons, and the snapshot
 * stored on an order's coupon line so the coupon can be rebuilt without a session.
 *
 * Codes: `pxl-points` and `pxl-credits` are fixed; a voucher's code is `PXL-` plus the Pixalink voucher code
 * (unless it already starts with `PXL-`). WooCommerce lower-cases every coupon code, so every code here is
 * compared and returned lower-cased. The whole `pxl-` prefix is reserved: a merchant coupon named `pxl-…` is
 * shadowed by the plugin (see readme.txt).
 */
final class CouponMapper {

	public const POINTS_CODE  = 'pxl-points';
	public const CREDITS_CODE = 'pxl-credits';
	public const PREFIX       = 'pxl-';

	public const KIND_POINTS  = 'points';
	public const KIND_VOUCHER = 'voucher';
	public const KIND_CREDIT  = 'credit';

	private const DISCOUNT_TYPES = array( 'fixed_cart', 'percent', 'fixed_product' );

	/**
	 * Whether a coupon code belongs to Pixalink.
	 *
	 * @param string $code Coupon code in any case.
	 * @return bool
	 */
	public static function is_ours( string $code ): bool {
		return 0 === strpos( self::normalise( $code ), self::PREFIX );
	}

	/**
	 * Redemption kind of one of our codes (null for any other code).
	 *
	 * @param string $code Coupon code.
	 * @return string|null One of the KIND_* constants.
	 */
	public static function kind_of( string $code ): ?string {
		$code = self::normalise( $code );
		if ( self::POINTS_CODE === $code ) {
			return self::KIND_POINTS;
		}
		if ( self::CREDITS_CODE === $code ) {
			return self::KIND_CREDIT;
		}

		return 0 === strpos( $code, self::PREFIX ) ? self::KIND_VOUCHER : null;
	}

	/**
	 * WooCommerce coupon code of a quote voucher (lower case).
	 *
	 * `PXL-` is added unless the Pixalink code already carries it. A code that would collide with `pxl-points`
	 * or `pxl-credits` (or is empty) becomes `pxl-v-<voucher id>`.
	 *
	 * @param array<string,mixed> $voucher Quote voucher (`id`, `code`).
	 * @return string
	 */
	public static function voucher_code( array $voucher ): string {
		$code = isset( $voucher['code'] ) && is_scalar( $voucher['code'] ) ? self::normalise( (string) $voucher['code'] ) : '';
		if ( '' !== $code && 0 !== strpos( $code, self::PREFIX ) ) {
			$code = self::PREFIX . $code;
		}

		if ( '' === $code || self::PREFIX === $code || in_array( $code, array( self::POINTS_CODE, self::CREDITS_CODE ), true ) ) {
			$id   = isset( $voucher['id'] ) && is_scalar( $voucher['id'] ) ? (string) $voucher['id'] : '';
			$code = self::PREFIX . 'v-' . self::normalise( (string) preg_replace( '/[^A-Za-z0-9-]/', '', $id ) );
		}

		return $code;
	}

	/**
	 * Props of the points coupon: a fixed_cart discount worth the points (applied before tax, like every
	 * WooCommerce fixed_cart coupon; WooCommerce caps it silently at the discountable subtotal).
	 *
	 * @param int    $points          Points to redeem.
	 * @param string $value_per_point Value of one point in major units.
	 * @return array<string,mixed>
	 */
	public static function points( int $points, string $value_per_point ): array {
		return array(
			'discount_type'  => 'fixed_cart',
			'amount'         => Money::from_cents( PointsMath::value_cents( $points, $value_per_point ) ),
			'individual_use' => false,
			'description'    => 'Pixalink points',
		);
	}

	/**
	 * Props of the credits coupon (credits are a discount in v1, so credit-paid spend does not earn).
	 *
	 * @param int $amount_cents Credits to use, in cents.
	 * @return array<string,mixed>
	 */
	public static function credits( int $amount_cents ): array {
		return array(
			'discount_type'  => 'fixed_cart',
			'amount'         => Money::from_cents( max( 0, $amount_cents ) ),
			'individual_use' => false,
			'description'    => 'Pixalink credits',
		);
	}

	/**
	 * Props of a voucher coupon from the quote's `woo_coupon` spec, SKUs and category slugs resolved locally.
	 *
	 * @param array<string,mixed> $spec `woo_coupon` (tests/contract/schemas/common.json#/$defs/woo_coupon).
	 * @return array<string,mixed>
	 */
	public static function voucher( array $spec ): array {
		$type = isset( $spec['discount_type'] ) && in_array( $spec['discount_type'], self::DISCOUNT_TYPES, true ) ? $spec['discount_type'] : 'fixed_cart';

		return array(
			'discount_type'               => $type,
			'amount'                      => self::decimal_or( $spec['amount'] ?? null, '0' ),
			'product_ids'                 => self::product_ids( self::strings( $spec['product_skus'] ?? array() ) ),
			'excluded_product_ids'        => self::product_ids( self::strings( $spec['excluded_product_skus'] ?? array() ) ),
			'product_categories'          => self::category_ids( self::strings( $spec['category_slugs'] ?? array() ) ),
			'excluded_product_categories' => array(),
			'minimum_amount'              => self::decimal_or( $spec['minimum_amount'] ?? null, '' ),
			'maximum_amount'              => self::decimal_or( $spec['maximum_amount'] ?? null, '' ),
			'limit_usage_to_x_items'      => isset( $spec['limit_usage_to_x_items'] ) && is_int( $spec['limit_usage_to_x_items'] ) && $spec['limit_usage_to_x_items'] > 0 ? $spec['limit_usage_to_x_items'] : null,
			'exclude_sale_items'          => true === ( $spec['exclude_sale_items'] ?? false ),
			'individual_use'              => true === ( $spec['individual_use'] ?? false ),
			'description'                 => 'Pixalink voucher',
		);
	}

	/**
	 * Whether a SKU-scoped voucher matched no local product.
	 *
	 * To WooCommerce an empty `product_ids` list means "every product", so such a voucher must be refused rather
	 * than silently becoming a whole-cart discount (ADR 0001, pitfall 3).
	 *
	 * @param array<string,mixed> $spec `woo_coupon` spec.
	 * @return bool
	 */
	public static function has_unresolved_skus( array $spec ): bool {
		$skus = self::strings( $spec['product_skus'] ?? array() );

		return array() !== $skus && array() === self::product_ids( $skus );
	}

	/**
	 * Props for one of our codes that cannot be resolved right now (unverified shopper, unknown voucher, switched
	 * off). Returning props instead of false makes WooCommerce run `woocommerce_coupon_is_valid`, where our message
	 * is set; false would surface "Coupon "x" cannot be applied because it does not exist".
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
	 * Snapshot stored on the order's coupon line (`_pixalink_coupon`): enough to rebuild the coupon later.
	 *
	 * @param \WC_Coupon $coupon Resolved coupon.
	 * @return array{discount_type:string,amount:string,product_ids:array<int,int>,excluded_product_ids:array<int,int>,product_categories:array<int,int>,excluded_product_categories:array<int,int>,limit_usage_to_x_items:int|null,exclude_sale_items:bool,minimum_amount:string,maximum_amount:string,individual_use:bool}
	 */
	public static function snapshot( \WC_Coupon $coupon ): array {
		$limit = $coupon->get_limit_usage_to_x_items( 'edit' );

		return array(
			'discount_type'               => (string) $coupon->get_discount_type( 'edit' ),
			'amount'                      => (string) wc_format_decimal( $coupon->get_amount( 'edit' ) ),
			'product_ids'                 => self::ints( $coupon->get_product_ids( 'edit' ) ),
			'excluded_product_ids'        => self::ints( $coupon->get_excluded_product_ids( 'edit' ) ),
			'product_categories'          => self::ints( $coupon->get_product_categories( 'edit' ) ),
			'excluded_product_categories' => self::ints( $coupon->get_excluded_product_categories( 'edit' ) ),
			'limit_usage_to_x_items'      => is_numeric( $limit ) && (int) $limit > 0 ? (int) $limit : null,
			'exclude_sale_items'          => (bool) $coupon->get_exclude_sale_items( 'edit' ),
			'minimum_amount'              => (string) $coupon->get_minimum_amount( 'edit' ),
			'maximum_amount'              => (string) $coupon->get_maximum_amount( 'edit' ),
			'individual_use'              => (bool) $coupon->get_individual_use( 'edit' ),
		);
	}

	/**
	 * A virtual coupon rebuilt from a coupon line's snapshot (admin recalculation, order-pay).
	 *
	 * @param array<string,mixed> $snapshot `_pixalink_coupon`.
	 * @param string              $code     Coupon code.
	 * @return \WC_Coupon
	 */
	public static function from_snapshot( array $snapshot, string $code ): \WC_Coupon {
		$allowed = array( 'discount_type', 'amount', 'product_ids', 'excluded_product_ids', 'product_categories', 'excluded_product_categories', 'limit_usage_to_x_items', 'exclude_sale_items', 'minimum_amount', 'maximum_amount', 'individual_use' );

		$coupon = new \WC_Coupon();
		$coupon->set_props( array_intersect_key( $snapshot, array_flip( $allowed ) ) );
		$coupon->set_code( $code );
		$coupon->set_virtual( true );

		return $coupon;
	}

	/**
	 * Lower-case, trimmed code (WooCommerce's own normalisation).
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function normalise( string $code ): string {
		return wc_strtolower( trim( $code ) );
	}

	/**
	 * A non-negative decimal string, or the fallback.
	 *
	 * @param mixed  $value    Candidate.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function decimal_or( $value, string $fallback ): string {
		return is_string( $value ) && 1 === preg_match( '/^[0-9]+(\.[0-9]+)?$/', $value ) ? $value : $fallback;
	}

	/**
	 * Only the string entries of a list.
	 *
	 * @param mixed $values Candidate list.
	 * @return list<string>
	 */
	private static function strings( $values ): array {
		return is_array( $values ) ? array_values( array_filter( $values, 'is_string' ) ) : array();
	}

	/**
	 * Positive integers of a list.
	 *
	 * @param mixed $values Candidate list.
	 * @return array<int,int>
	 */
	private static function ints( $values ): array {
		return is_array( $values ) ? array_values( array_filter( array_map( 'intval', $values ), static fn ( int $id ): bool => $id > 0 ) ) : array();
	}

	/**
	 * Local product (or variation) ids for SKUs; unknown SKUs are dropped.
	 *
	 * @param list<string> $skus SKUs.
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
	 * Local product_cat term ids for slugs; unknown slugs are dropped.
	 *
	 * @param list<string> $slugs Slugs.
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
