<?php
/**
 * Commerce event types.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Every event type the outbox can deliver to `POST /events` (mirrors the `type` enum of event.request.json).
 */
enum EventType: string {

	case ORDER_PAID                 = 'order.paid';
	case ORDER_COMPLETED            = 'order.completed';
	case ORDER_CANCELLED            = 'order.cancelled';
	case ORDER_REFUNDED             = 'order.refunded';
	case ORDER_REFUND_DELETED       = 'order.refund_deleted';
	case ORDER_REDEMPTIONS_RELEASED = 'order.redemptions_released';
	case CUSTOMER_UPSERTED          = 'customer.upserted';
	case PING                       = 'ping';

	/**
	 * Every wire value.
	 *
	 * @return list<string>
	 */
	public static function values(): array {
		return array_map( static fn ( EventType $type ): string => $type->value, self::cases() );
	}

	/**
	 * Whether the event describes an order (carries a per-order sequence and a deterministic id).
	 *
	 * @return bool
	 */
	public function is_order_event(): bool {
		return 0 === strpos( $this->value, 'order.' );
	}

	/**
	 * Whether the event id is a UUID v5 derived from the order and a discriminator.
	 *
	 * `customer.upserted` and `ping` describe a moment rather than a business fact, so they get random v4 ids.
	 *
	 * @return bool
	 */
	public function has_deterministic_id(): bool {
		return $this->is_order_event();
	}

	/**
	 * Short, translated label for admin screens.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::ORDER_PAID                 => __( 'Order paid', 'pixalink-loyalty-for-woocommerce' ),
			self::ORDER_COMPLETED            => __( 'Order completed', 'pixalink-loyalty-for-woocommerce' ),
			self::ORDER_CANCELLED            => __( 'Order cancelled', 'pixalink-loyalty-for-woocommerce' ),
			self::ORDER_REFUNDED             => __( 'Order refunded', 'pixalink-loyalty-for-woocommerce' ),
			self::ORDER_REFUND_DELETED       => __( 'Refund deleted', 'pixalink-loyalty-for-woocommerce' ),
			self::ORDER_REDEMPTIONS_RELEASED => __( 'Redemptions released', 'pixalink-loyalty-for-woocommerce' ),
			self::CUSTOMER_UPSERTED          => __( 'Customer updated', 'pixalink-loyalty-for-woocommerce' ),
			self::PING                       => __( 'Test ping', 'pixalink-loyalty-for-woocommerce' ),
		};
	}
}
