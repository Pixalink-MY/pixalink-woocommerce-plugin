<?php
/**
 * The `cart` object of quote and hold requests.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Redemption;

use Pixalink\Loyalty\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Describes a WooCommerce cart the way `POST quote` and `PUT orders/{order_key}/redemptions` expect it
 * (tests/contract/schemas/quote.request.json `cart`).
 *
 * Money is integer cents in the store currency. Product amounts follow the store's price entry: they include tax
 * when `prices_include_tax` is true, exactly the base WooCommerce applies fixed_cart coupons to, so the server's
 * `max_for_cart` and WooCommerce's own cap agree. Lines are priced before any coupon; `discount_cents` is what the
 * store's own (non-Pixalink) coupons take off. Shipping is sent apart and excluding tax; `tax_cents` is all the tax
 * in the cart total.
 */
final class CartPayload {

	/**
	 * The `cart` object for a WooCommerce cart.
	 *
	 * @param \WC_Cart     $cart      Cart (totals calculated).
	 * @param list<string> $held_refs Holds (`CH-…`) the shopper's current order already carries.
	 * @return array{lines:list<array<string,mixed>>,subtotal_cents:int,currency:string,shipping_cents:int,discount_cents:int,tax_cents:int,prices_include_tax:bool,held_refs:list<string>}
	 */
	public static function from_cart( \WC_Cart $cart, array $held_refs = array() ): array {
		$inclusive = wc_prices_include_tax();
		$lines     = array();
		$subtotal  = 0;

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$line = self::line( (string) $key, $item, $inclusive );
			if ( null === $line ) {
				continue;
			}

			$lines[]   = $line;
			$subtotal += $line['line_total_cents'];
		}

		$currency = Money::store_currency();

		return array(
			'lines'              => $lines,
			'subtotal_cents'     => $subtotal,
			'currency'           => '' !== $currency ? strtoupper( $currency ) : 'MYR',
			'shipping_cents'     => max( 0, Money::to_cents( (float) $cart->get_shipping_total() ) ),
			'discount_cents'     => self::store_coupon_discounts( $cart, $inclusive ),
			'tax_cents'          => max( 0, Money::to_cents( (float) $cart->get_total_tax() ) ),
			'prices_include_tax' => $inclusive,
			'held_refs'          => array_values( array_unique( array_filter( $held_refs, static fn ( $ref ): bool => is_string( $ref ) && 1 === preg_match( '/^CH-[A-Za-z0-9]+$/', $ref ) ) ) ),
		);
	}

	/**
	 * Signature of what a quote depends on: the lines, their prices and the store's own discounts.
	 *
	 * Pixalink's own coupons, shipping and tax are left out, so applying points never makes the quote look stale.
	 *
	 * @param array<string,mixed> $cart `cart` object from {@see CartPayload::from_cart()}.
	 * @return string
	 */
	public static function signature( array $cart ): string {
		$lines = array();
		foreach ( is_array( $cart['lines'] ?? null ) ? $cart['lines'] : array() as $line ) {
			if ( is_array( $line ) ) {
				$lines[] = array( $line['line_id'] ?? '', $line['sku'] ?? '', $line['quantity'] ?? 0, $line['line_total_cents'] ?? 0 );
			}
		}

		return md5(
			(string) wp_json_encode(
				array(
					$lines,
					$cart['subtotal_cents'] ?? 0,
					$cart['discount_cents'] ?? 0,
					$cart['currency'] ?? '',
					$cart['prices_include_tax'] ?? false,
				)
			)
		);
	}

	/**
	 * Cents a discount can still take off the cart, ignoring the given Pixalink kinds' own discounts.
	 *
	 * WooCommerce applies the points coupon after every voucher and store coupon, and credits last
	 * ({@see VirtualCoupons::coupon_sort()}), so points may take the subtotal less everything but points and
	 * credits, and credits the subtotal less everything but credits.
	 *
	 * @param \WC_Cart     $cart      Cart (totals calculated).
	 * @param list<string> $excluding Pixalink kinds whose discounts do not count (CouponMapper::KIND_*).
	 * @return int
	 */
	public static function remaining_cents( \WC_Cart $cart, array $excluding ): int {
		$inclusive = wc_prices_include_tax();
		$subtotal  = (float) $cart->get_subtotal() + ( $inclusive ? (float) $cart->get_subtotal_tax() : 0.0 );
		$kinds     = array_values( array_diff( array( CouponMapper::KIND_POINTS, CouponMapper::KIND_CREDIT, CouponMapper::KIND_VOUCHER ), $excluding ) );

		$taken = self::store_coupon_discounts( $cart, $inclusive ) + self::pixalink_discounts( $cart, $kinds, $inclusive );

		return max( 0, Money::to_cents( $subtotal ) - $taken );
	}

	/**
	 * What a coupon line took off for the shopper, in cents: its discount, plus its tax share when prices include
	 * tax (the points or credits value; in a tax-exclusive store the tax share comes off on top).
	 *
	 * @param string|float|int $discount           Discount excluding tax.
	 * @param string|float|int $discount_tax       Tax share of the discount.
	 * @param bool             $prices_include_tax Whether prices include tax.
	 * @return int
	 */
	public static function value_cents( $discount, $discount_tax, bool $prices_include_tax ): int {
		$value = (float) $discount + ( $prices_include_tax ? (float) $discount_tax : 0.0 );

		return max( 0, Money::to_cents( $value ) );
	}

	/**
	 * One `lines[]` entry.
	 *
	 * @param string              $key       Cart item key.
	 * @param array<string,mixed> $item      Cart item.
	 * @param bool                $inclusive Whether prices include tax.
	 * @return array<string,mixed>|null
	 */
	private static function line( string $key, array $item, bool $inclusive ): ?array {
		$product  = $item['data'] ?? null;
		$quantity = isset( $item['quantity'] ) && is_numeric( $item['quantity'] ) ? (float) $item['quantity'] : 0.0;
		if ( ! $product instanceof \WC_Product || $quantity <= 0 ) {
			return null;
		}

		$total = Money::to_cents( (float) ( $item['line_subtotal'] ?? 0 ) + ( $inclusive ? (float) ( $item['line_subtotal_tax'] ?? 0 ) : 0.0 ) );
		$total = max( 0, $total );

		$product_id   = isset( $item['product_id'] ) && is_numeric( $item['product_id'] ) ? (int) $item['product_id'] : (int) $product->get_id();
		$variation_id = isset( $item['variation_id'] ) && is_numeric( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
		$sku          = trim( (string) $product->get_sku() );

		$line = array(
			'line_id'             => $key,
			'sku'                 => '' !== $sku ? $sku : null,
			'external_product_id' => (string) ( $variation_id > 0 ? $variation_id : $product_id ),
			'name'                => wp_strip_all_tags( (string) $product->get_name() ),
			'quantity'            => floor( $quantity ) === $quantity ? (int) $quantity : $quantity,
			'unit_price_cents'    => (int) round( $total / $quantity ),
			'line_total_cents'    => $total,
		);

		$category = self::category( $product_id );
		if ( null !== $category ) {
			$line['category_code'] = $category['slug'];
			$line['category_name'] = $category['name'];
		}

		return $line;
	}

	/**
	 * Discounts of the store's own (non-Pixalink) coupons, in cents.
	 *
	 * @param \WC_Cart $cart      Cart.
	 * @param bool     $inclusive Whether prices include tax.
	 * @return int
	 */
	private static function store_coupon_discounts( \WC_Cart $cart, bool $inclusive ): int {
		$cents = 0;
		foreach ( $cart->get_applied_coupons() as $code ) {
			if ( ! CouponMapper::is_ours( (string) $code ) ) {
				$cents += Money::to_cents( (float) $cart->get_coupon_discount_amount( (string) $code, ! $inclusive ) );
			}
		}

		return max( 0, $cents );
	}

	/**
	 * Discounts of Pixalink coupons of the given kinds, in cents.
	 *
	 * @param \WC_Cart     $cart      Cart.
	 * @param list<string> $kinds     Kinds to count.
	 * @param bool         $inclusive Whether prices include tax.
	 * @return int
	 */
	private static function pixalink_discounts( \WC_Cart $cart, array $kinds, bool $inclusive ): int {
		$cents = 0;
		foreach ( $cart->get_applied_coupons() as $code ) {
			$kind = CouponMapper::kind_of( (string) $code );
			if ( null !== $kind && in_array( $kind, $kinds, true ) ) {
				$cents += Money::to_cents( (float) $cart->get_coupon_discount_amount( (string) $code, ! $inclusive ) );
			}
		}

		return max( 0, $cents );
	}

	/**
	 * First product category (parent product for variations).
	 *
	 * @param int $product_id Product id.
	 * @return array{slug:string,name:string}|null
	 */
	private static function category( int $product_id ): ?array {
		if ( $product_id < 1 ) {
			return null;
		}

		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( ! is_array( $terms ) ) {
			return null;
		}

		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				return array(
					'slug' => (string) $term->slug,
					'name' => (string) $term->name,
				);
			}
		}

		return null;
	}
}
