<?php
/**
 * `data` of order events.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Orders;

use Pixalink\Loyalty\Events\EventType;
use Pixalink\Loyalty\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the WooCommerce REST v3-shaped subset sent with `order.*` events (see event.request.json `order_data`).
 *
 * Money is a decimal string in the order currency, dates are ISO 8601 UTC, ids are integers (WooCommerce's own).
 * Customer identity comes from the plugin's order meta, then the shopper's user meta, then billing details; any
 * field that does not satisfy the contract is sent as null rather than guessed.
 */
final class OrderPayloadBuilder {

	private const E164_PATTERN    = '/^\+[1-9][0-9]{6,14}$/';
	private const COUNTRY_PATTERN = '/^[A-Z]{2}$/';
	private const HOLD_PATTERN    = '/^CH-[A-Za-z0-9]+$/';
	private const SHA256_PATTERN  = '/^[0-9a-f]{64}$/';

	/**
	 * Consent sources accepted by the contract.
	 */
	private const CONSENT_SOURCES = array( 'checkout', 'my_account', 'registration', 'admin' );

	/**
	 * Redemption kinds accepted by the contract.
	 */
	private const REDEMPTION_KINDS = array( 'points', 'voucher', 'credit' );

	/**
	 * `{order, customer}` for order.paid, order.completed and (with refund/cancel extras) the other order events.
	 *
	 * @param \WC_Order $order Order.
	 * @param EventType $type  Event being built.
	 * @return array<string,mixed>
	 */
	public function build( \WC_Order $order, EventType $type ): array {
		$data = array(
			'order'    => $this->order( $order ),
			'customer' => $this->customer( $order ),
		);

		/**
		 * Filters the `data` of an order event before it is stored in the outbox.
		 *
		 * The result must still satisfy the Pixalink contract (event.request.json); extra keys are allowed.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,mixed> $data  `{order, customer, ...}`.
		 * @param \WC_Order           $order The order.
		 * @param string              $type  Event type, e.g. "order.paid".
		 */
		$filtered = apply_filters( 'pixalink_loyalty_order_payload', $data, $order, $type->value );

		return is_array( $filtered ) ? $filtered : $data;
	}

	/**
	 * `data` of order.cancelled: the full payload plus whether it had been paid and the status it left.
	 *
	 * @param \WC_Order $order           Order.
	 * @param string    $previous_status Status before cancellation (without "wc-").
	 * @param bool      $was_paid        Whether the order had been paid.
	 * @return array<string,mixed>
	 */
	public function cancellation( \WC_Order $order, string $previous_status, bool $was_paid ): array {
		return $this->build( $order, EventType::ORDER_CANCELLED ) + array(
			'was_paid'        => $was_paid,
			'previous_status' => $previous_status,
		);
	}

	/**
	 * `data` of events that only need to identify the order (refund deleted, redemptions released).
	 *
	 * @param \WC_Order           $order Order.
	 * @param array<string,mixed> $extra Extra keys (refund_id, reason).
	 * @return array<string,mixed>
	 */
	public function reference_data( \WC_Order $order, array $extra = array() ): array {
		return array( 'order' => $this->reference( $order ) ) + $extra;
	}

	/**
	 * `{id, number, order_key}`.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{id:int,number:string,order_key:string}
	 */
	public function reference( \WC_Order $order ): array {
		$number = (string) $order->get_order_number();

		return array(
			'id'        => (int) $order->get_id(),
			'number'    => '' !== $number ? $number : (string) $order->get_id(),
			'order_key' => (string) $order->get_order_key(),
		);
	}

	/**
	 * The `order` object.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	public function order( \WC_Order $order ): array {
		$line_items = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$line_items[] = $this->line_item( $item, $order );
			}
		}

		$coupon_lines = array();
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Coupon ) {
				$coupon_lines[] = $this->coupon_line( $item );
			}
		}

		$currency = (string) $order->get_currency();

		return $this->reference( $order ) + array(
			'status'             => (string) $order->get_status(),
			'currency'           => '' !== $currency ? strtoupper( $currency ) : Money::store_currency(),
			'date_created'       => self::iso8601( $order->get_date_created() ) ?? gmdate( 'Y-m-d\TH:i:s\Z' ),
			'date_paid'          => self::iso8601( $order->get_date_paid() ),
			'date_completed'     => self::iso8601( $order->get_date_completed() ),
			'payment_method'     => self::nullable( $order->get_payment_method() ),
			'created_via'        => self::nullable( $order->get_created_via() ),
			'total'              => Money::to_decimal( $order->get_total() ),
			'total_tax'          => Money::to_decimal( $order->get_total_tax() ),
			'shipping_total'     => Money::to_decimal( $order->get_shipping_total() ),
			'discount_total'     => Money::to_decimal( $order->get_discount_total() ),
			// The order's own flag, not wc_prices_include_tax(): a refund days later keeps the setting it was sold under.
			'prices_include_tax' => (bool) $order->get_prices_include_tax(),
			'line_items'         => $line_items,
			'coupon_lines'       => $coupon_lines,
		);
	}

	/**
	 * One `line_items[]` entry.
	 *
	 * @param \WC_Order_Item_Product $item  Line item.
	 * @param \WC_Order              $order Order.
	 * @return array<string,mixed>
	 */
	public function line_item( \WC_Order_Item_Product $item, \WC_Order $order ): array {
		$product    = $item->get_product();
		$product_id = (int) $item->get_product_id();
		$sku        = $product instanceof \WC_Product ? (string) $product->get_sku() : '';
		$categories = self::categories( $product_id );

		$line = array(
			'id'             => (int) $item->get_id(),
			'product_id'     => $product_id,
			'variation_id'   => (int) $item->get_variation_id(),
			'sku'            => '' !== $sku ? $sku : null,
			'name'           => (string) $item->get_name(),
			'category_slugs' => array_keys( $categories ),
			'category_names' => array_values( $categories ),
			'quantity'       => self::quantity( $item->get_quantity() ),
			'subtotal'       => Money::to_decimal( $item->get_subtotal() ),
			'total'          => Money::to_decimal( $item->get_total() ),
			'total_tax'      => Money::to_decimal( $item->get_total_tax() ),
		);

		/**
		 * Filters one line item of an order event (for example to add a custom SKU mapping).
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,mixed>    $line  Line payload.
		 * @param \WC_Order_Item_Product $item  The line item.
		 * @param \WC_Order              $order The order.
		 */
		$filtered = apply_filters( 'pixalink_loyalty_line_item_payload', $line, $item, $order );

		return is_array( $filtered ) ? $filtered : $line;
	}

	/**
	 * One `coupon_lines[]` entry, with the Pixalink redemption meta of virtual coupons.
	 *
	 * @param \WC_Order_Item_Coupon $item Coupon line.
	 * @return array<string,mixed>
	 */
	public function coupon_line( \WC_Order_Item_Coupon $item ): array {
		$kind          = self::meta_string( $item, OrderMeta::COUPON_KIND );
		$redemption_id = self::meta_string( $item, OrderMeta::COUPON_REDEMPTION_ID );
		$voucher_id    = self::meta_string( $item, OrderMeta::COUPON_VOUCHER_ID );
		$points        = (int) self::meta_string( $item, OrderMeta::COUPON_POINTS );
		$amount        = self::meta_string( $item, OrderMeta::COUPON_AMOUNT_CENTS );
		$is_ours       = in_array( $kind, self::REDEMPTION_KINDS, true );

		return array(
			'id'           => (int) $item->get_id(),
			'code'         => (string) $item->get_code(),
			'discount'     => Money::to_decimal( $item->get_discount() ),
			'discount_tax' => Money::to_decimal( $item->get_discount_tax() ),
			'meta'         => array(
				'pixalink_kind'          => $is_ours ? $kind : null,
				'pixalink_redemption_id' => 1 === preg_match( self::HOLD_PATTERN, $redemption_id ) ? $redemption_id : null,
				'pixalink_voucher_id'    => '' !== $voucher_id && strlen( $voucher_id ) <= 64 ? $voucher_id : null,
				'pixalink_points'        => $points >= 1 ? $points : null,
				'pixalink_amount_cents'  => $is_ours && ctype_digit( $amount ) ? (int) $amount : null,
				'pixalink_coupon'        => $is_ours ? self::snapshot( $item->get_meta( OrderMeta::COUPON_SNAPSHOT, true ) ) : null,
			),
		);
	}

	/**
	 * The `_pixalink_coupon` snapshot of a coupon line, reduced to the contract's fields (null when unreadable).
	 *
	 * @param mixed $snapshot Stored snapshot.
	 * @return array<string,mixed>|null
	 */
	private static function snapshot( $snapshot ): ?array {
		$types = array( 'fixed_cart', 'percent', 'fixed_product' );
		if ( ! is_array( $snapshot ) || ! isset( $snapshot['discount_type'] ) || ! in_array( $snapshot['discount_type'], $types, true ) ) {
			return null;
		}

		$ids   = static fn ( $value ): array => is_array( $value ) ? array_values( array_filter( array_map( 'intval', $value ), static fn ( int $id ): bool => $id > 0 ) ) : array();
		$limit = $snapshot['limit_usage_to_x_items'] ?? null;

		return array(
			'discount_type'               => $snapshot['discount_type'],
			'amount'                      => Money::to_decimal( is_numeric( $snapshot['amount'] ?? null ) ? (float) $snapshot['amount'] : 0.0 ),
			'product_ids'                 => $ids( $snapshot['product_ids'] ?? array() ),
			'excluded_product_ids'        => $ids( $snapshot['excluded_product_ids'] ?? array() ),
			'product_categories'          => $ids( $snapshot['product_categories'] ?? array() ),
			'excluded_product_categories' => $ids( $snapshot['excluded_product_categories'] ?? array() ),
			'limit_usage_to_x_items'      => is_numeric( $limit ) && (int) $limit > 0 ? (int) $limit : null,
			'exclude_sale_items'          => true === ( $snapshot['exclude_sale_items'] ?? false ),
			'minimum_amount'              => is_scalar( $snapshot['minimum_amount'] ?? null ) ? (string) $snapshot['minimum_amount'] : '',
			'maximum_amount'              => is_scalar( $snapshot['maximum_amount'] ?? null ) ? (string) $snapshot['maximum_amount'] : '',
			'individual_use'              => true === ( $snapshot['individual_use'] ?? false ),
		);
	}

	/**
	 * The `customer` object.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string,mixed>
	 */
	public function customer( \WC_Order $order ): array {
		$user_id = (int) $order->get_customer_id();

		$customer_id = OrderMeta::get_string( $order, OrderMeta::CUSTOMER_ID );
		if ( '' === $customer_id ) {
			$customer_id = self::user_meta( $user_id, 'pixalink_customer_id' );
		}

		$phone = self::e164( OrderMeta::get_string( $order, OrderMeta::PHONE_E164 ) )
			?? self::e164( self::user_meta( $user_id, 'pixalink_phone_e164' ) )
			?? self::e164( (string) $order->get_billing_phone() );

		$country = self::country( OrderMeta::get_string( $order, OrderMeta::PHONE_COUNTRY ) )
			?? self::country( self::user_meta( $user_id, 'pixalink_phone_country' ) );

		$email = trim( (string) $order->get_billing_email() );

		return array(
			'pixalink_customer_id' => '' !== $customer_id && strlen( $customer_id ) <= 64 ? $customer_id : null,
			'wp_user_id'           => $user_id > 0 ? $user_id : null,
			'is_guest'             => $user_id < 1,
			'phone_e164'           => $phone,
			'phone_country'        => null === $phone ? null : $country,
			'email'                => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : null,
			'first_name'           => self::nullable( $order->get_billing_first_name() ),
			'last_name'            => self::nullable( $order->get_billing_last_name() ),
			'marketing_consent'    => $this->consent( $order ),
		);
	}

	/**
	 * `marketing_consent{value, source, captured_at, text_hash}`, or null unless every part is recorded.
	 *
	 * Accepts the consent meta either as one array or as a yes/no flag with its source, time and text hash in
	 * sibling keys.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{value:bool,source:string,captured_at:string,text_hash:string}|null
	 */
	public function consent( \WC_Order $order ): ?array {
		$raw = OrderMeta::get( $order, OrderMeta::MARKETING_CONSENT );

		if ( is_array( $raw ) ) {
			$value       = $raw['value'] ?? null;
			$source      = $raw['source'] ?? '';
			$captured_at = $raw['captured_at'] ?? '';
			$text_hash   = $raw['text_hash'] ?? '';
		} else {
			$value       = $raw;
			$source      = OrderMeta::get( $order, OrderMeta::CONSENT_SOURCE );
			$captured_at = OrderMeta::get( $order, OrderMeta::CONSENT_CAPTURED_AT );
			$text_hash   = OrderMeta::get( $order, OrderMeta::CONSENT_TEXT_HASH );
		}

		$value       = self::bool( $value );
		$source      = is_string( $source ) ? $source : '';
		$captured_at = self::iso8601( $captured_at );
		$text_hash   = is_string( $text_hash ) ? strtolower( $text_hash ) : '';

		if ( null === $value || ! in_array( $source, self::CONSENT_SOURCES, true ) || null === $captured_at || 1 !== preg_match( self::SHA256_PATTERN, $text_hash ) ) {
			return null;
		}

		return array(
			'value'       => $value,
			'source'      => $source,
			'captured_at' => $captured_at,
			'text_hash'   => $text_hash,
		);
	}

	/**
	 * ISO 8601 UTC from a WC_DateTime, a unix time or a date string.
	 *
	 * @param mixed $date Date.
	 * @return string|null
	 */
	public static function iso8601( $date ): ?string {
		if ( $date instanceof \DateTimeInterface ) {
			return gmdate( 'Y-m-d\TH:i:s\Z', $date->getTimestamp() );
		}

		if ( is_int( $date ) || ( is_string( $date ) && ctype_digit( $date ) ) ) {
			return (int) $date > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $date ) : null;
		}

		if ( is_string( $date ) && '' !== trim( $date ) ) {
			$timestamp = strtotime( $date );

			return false === $timestamp ? null : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
		}

		return null;
	}

	/**
	 * Category slug => name of a product (parent product for variations).
	 *
	 * @param int $product_id Product id.
	 * @return array<string,string>
	 */
	private static function categories( int $product_id ): array {
		if ( $product_id < 1 ) {
			return array();
		}

		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$categories = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$categories[ (string) $term->slug ] = (string) $term->name;
			}
		}

		return $categories;
	}

	/**
	 * Quantity as int when whole, float otherwise (some stores sell by weight).
	 *
	 * @param mixed $quantity Quantity.
	 * @return int|float
	 */
	public static function quantity( $quantity ): int|float {
		$number = is_numeric( $quantity ) ? (float) $quantity : 0.0;

		return floor( $number ) === $number ? (int) $number : $number;
	}

	/**
	 * Valid E.164 number (spaces, dashes, dots and parentheses removed), or null.
	 *
	 * @param string $phone Phone.
	 * @return string|null
	 */
	private static function e164( string $phone ): ?string {
		$normalised = (string) preg_replace( '/[\s\-().]/', '', $phone );

		return 1 === preg_match( self::E164_PATTERN, $normalised ) ? $normalised : null;
	}

	/**
	 * ISO 3166-1 alpha-2 code, or null.
	 *
	 * @param string $country Country.
	 * @return string|null
	 */
	private static function country( string $country ): ?string {
		$country = strtoupper( trim( $country ) );

		return 1 === preg_match( self::COUNTRY_PATTERN, $country ) ? $country : null;
	}

	/**
	 * A user meta string ('' for guests or when missing).
	 *
	 * @param int    $user_id User id.
	 * @param string $key     Meta key.
	 * @return string
	 */
	private static function user_meta( int $user_id, string $key ): string {
		if ( $user_id < 1 ) {
			return '';
		}

		$value = get_user_meta( $user_id, $key, true );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Item meta as a trimmed string.
	 *
	 * @param \WC_Order_Item $item Item.
	 * @param string         $key  Meta key.
	 * @return string
	 */
	private static function meta_string( \WC_Order_Item $item, string $key ): string {
		$value = $item->get_meta( $key, true );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Interpret a stored consent flag.
	 *
	 * @param mixed $value Flag.
	 * @return bool|null
	 */
	private static function bool( $value ): ?bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) ) {
			return 1 === $value ? true : ( 0 === $value ? false : null );
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = strtolower( trim( $value ) );
		if ( in_array( $value, array( 'yes', '1', 'true', 'on' ), true ) ) {
			return true;
		}

		return in_array( $value, array( 'no', '0', 'false', 'off' ), true ) ? false : null;
	}

	/**
	 * Empty string → null.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private static function nullable( $value ): ?string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		return '' === $value ? null : $value;
	}
}
