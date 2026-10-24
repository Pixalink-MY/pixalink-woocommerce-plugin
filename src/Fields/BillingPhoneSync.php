<?php
/**
 * One-way sync of the Pixalink phone into WooCommerce's billing phone.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * The Pixalink phone fills WooCommerce's `billing_phone` when that is empty (or still holds the number synced last
 * time); the billing phone never flows back. The verified Pixalink phone is never overwritten by either side:
 * {@see \Pixalink\Loyalty\Identity\UserMeta::set_phone()} refuses once a number is verified.
 */
final class BillingPhoneSync {

	/**
	 * Fill an order's empty billing phone.
	 *
	 * @param \WC_Order   $order Order.
	 * @param PhoneNumber $phone Pixalink phone.
	 * @return void
	 */
	public function order( \WC_Order $order, PhoneNumber $phone ): void {
		if ( '' === trim( (string) $order->get_billing_phone() ) ) {
			$order->set_billing_phone( $phone->e164 );
		}
	}

	/**
	 * Update a user's billing phone when it is empty or still the previously synced Pixalink number.
	 *
	 * @param int         $user_id  User id.
	 * @param PhoneNumber $phone    New Pixalink phone.
	 * @param string|null $previous Pixalink phone before the change (E.164), if any.
	 * @return bool Whether the billing phone changed.
	 */
	public function user( int $user_id, PhoneNumber $phone, ?string $previous ): bool {
		if ( $user_id < 1 ) {
			return false;
		}

		$billing = get_user_meta( $user_id, 'billing_phone', true );
		$billing = is_scalar( $billing ) ? trim( (string) $billing ) : '';
		if ( $billing === $phone->e164 || ( '' !== $billing && $billing !== $previous ) ) {
			return false;
		}

		update_user_meta( $user_id, 'billing_phone', $phone->e164 );

		return true;
	}
}
