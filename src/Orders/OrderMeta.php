<?php
/**
 * Order meta owned by the plugin.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Order meta keys and typed access through the WooCommerce CRUD API only (`get_meta()` / `update_meta_data()`),
 * so the same code works with HPOS custom order tables and the legacy posts store.
 *
 * Meta written by later work units (checkout identity fields, redemptions) is read here with null fallbacks so
 * event payloads stay valid before those units ship.
 */
final class OrderMeta {

	// Identity captured at checkout (written by the fields/identity units).
	public const CUSTOMER_ID         = '_pixalink_customer_id';
	public const PHONE_E164          = '_pixalink_phone_e164';
	public const PHONE_COUNTRY       = '_pixalink_phone_country';
	public const MARKETING_CONSENT   = '_pixalink_marketing_consent';
	public const CONSENT_SOURCE      = '_pixalink_marketing_consent_source';
	public const CONSENT_CAPTURED_AT = '_pixalink_marketing_consent_at';
	public const CONSENT_TEXT_HASH   = '_pixalink_consent_text_hash';

	// Redemptions held at checkout (written by the redemption unit).
	public const REDEMPTIONS           = '_pixalink_redemptions';
	public const REDEMPTION_STATE      = '_pixalink_redemption_state';
	public const REDEMPTION_EXPIRES_AT = '_pixalink_redemption_expires_at';

	// Event delivery and its results (written here).
	public const EARN_STATE         = '_pixalink_earn_state';
	public const EARN_OUTCOME       = '_pixalink_earn_outcome';
	public const POINTS_EARNED      = '_pixalink_points_earned';
	public const POS_TRANSACTION_ID = '_pixalink_pos_transaction_id';
	public const EVENT_SEQ          = '_pixalink_event_seq';

	// Coupon line item meta (written by the redemption unit on virtual coupons).
	public const COUPON_KIND          = '_pixalink_kind';
	public const COUPON_VOUCHER_ID    = '_pixalink_voucher_id';
	public const COUPON_POINTS        = '_pixalink_points';
	public const COUPON_REDEMPTION_ID = '_pixalink_redemption_id';

	/**
	 * `order.paid` was recorded in the outbox.
	 */
	public const EARN_QUEUED = 'queued';

	/**
	 * Pixalink accepted `order.paid`.
	 */
	public const EARN_DELIVERED = 'delivered';

	/**
	 * `order.paid` exhausted its retries.
	 */
	public const EARN_DEAD = 'dead';

	public const REDEMPTION_SETTLED  = 'settled';
	public const REDEMPTION_RELEASED = 'released';

	/**
	 * Read a meta value as a trimmed string ('' when missing or not scalar).
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $key   Meta key.
	 * @return string
	 */
	public static function get_string( \WC_Order $order, string $key ): string {
		$value = $order->get_meta( $key, true );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Read a meta value as-is.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $key   Meta key.
	 * @return mixed
	 */
	public static function get( \WC_Order $order, string $key ) {
		return $order->get_meta( $key, true );
	}

	/**
	 * Write meta values and persist only the meta (no status transition is re-triggered).
	 *
	 * @param \WC_Order           $order  Order.
	 * @param array<string,mixed> $values Key => value; null deletes the key.
	 * @return void
	 */
	public static function update( \WC_Order $order, array $values ): void {
		foreach ( $values as $key => $value ) {
			if ( null === $value ) {
				$order->delete_meta_data( $key );
				continue;
			}
			$order->update_meta_data( $key, $value );
		}

		if ( $order->get_id() > 0 ) {
			$order->save_meta_data();
		}
	}

	/**
	 * Delivery state of `order.paid`: '', queued, delivered or dead.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function earn_state( \WC_Order $order ): string {
		return self::get_string( $order, self::EARN_STATE );
	}

	/**
	 * Whether `order.paid` is already in the outbox or delivered.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function is_paid_event_recorded( \WC_Order $order ): bool {
		return in_array( self::earn_state( $order ), array( self::EARN_QUEUED, self::EARN_DELIVERED ), true );
	}

	/**
	 * Whether the order carries Pixalink redemptions (points, vouchers, credits held at checkout).
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function has_redemptions( \WC_Order $order ): bool {
		$redemptions = self::get( $order, self::REDEMPTIONS );

		return ( is_array( $redemptions ) && array() !== $redemptions )
			|| ( is_string( $redemptions ) && '' !== trim( $redemptions ) && '[]' !== trim( $redemptions ) )
			|| '' !== self::get_string( $order, self::REDEMPTION_STATE );
	}
}
