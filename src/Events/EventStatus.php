<?php
/**
 * Outbox row states.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Life cycle of an outbox row: pending → sending → delivered, or → retry → … → dead. `cancelled` is set by a merchant.
 */
enum EventStatus: string {

	case PENDING   = 'pending';
	case SENDING   = 'sending';
	case RETRY     = 'retry';
	case DELIVERED = 'delivered';
	case DEAD      = 'dead';
	case CANCELLED = 'cancelled';

	/**
	 * Whether a delivery attempt is still expected.
	 *
	 * @return bool
	 */
	public function is_open(): bool {
		return in_array( $this, array( self::PENDING, self::SENDING, self::RETRY ), true );
	}

	/**
	 * Whether a delivery worker may claim a row in this state (stale `sending` rows are handled separately).
	 *
	 * @return bool
	 */
	public function is_claimable(): bool {
		return self::PENDING === $this || self::RETRY === $this;
	}

	/**
	 * Translated label for admin screens.
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::PENDING   => __( 'Pending', 'pixalink-loyalty-for-woocommerce' ),
			self::SENDING   => __( 'Sending', 'pixalink-loyalty-for-woocommerce' ),
			self::RETRY     => __( 'Retrying', 'pixalink-loyalty-for-woocommerce' ),
			self::DELIVERED => __( 'Delivered', 'pixalink-loyalty-for-woocommerce' ),
			self::DEAD      => __( 'Failed', 'pixalink-loyalty-for-woocommerce' ),
			self::CANCELLED => __( 'Cancelled', 'pixalink-loyalty-for-woocommerce' ),
		};
	}
}
