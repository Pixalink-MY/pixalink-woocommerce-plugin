<?php
/**
 * Loyalty identity captured on an order.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Fields;

use Pixalink\Loyalty\Identity\CustomerEvents;
use Pixalink\Loyalty\Identity\ShopperContext;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Orders\OrderMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the phone, consent and (for verified shoppers) Pixalink customer id onto an order, then carries a logged-in
 * shopper's new phone and consent into their account and records `customer.upserted`.
 *
 * Order meta (W1 {@see OrderMeta}): `_pixalink_phone_e164`, `_pixalink_phone_country`, `_pixalink_marketing_consent`
 * (`yes` only: an unticked box records nothing), `_pixalink_marketing_consent_source` (`checkout`),
 * `_pixalink_marketing_consent_at`, `_pixalink_consent_text_hash` and `_pixalink_customer_id`.
 */
final class OrderIdentity {

	/**
	 * Records customer.upserted events.
	 *
	 * @var CustomerEvents
	 */
	private CustomerEvents $events;

	/**
	 * Billing phone sync.
	 *
	 * @var BillingPhoneSync
	 */
	private BillingPhoneSync $sync;

	/**
	 * Constructor.
	 *
	 * @param CustomerEvents   $events customer.upserted events.
	 * @param BillingPhoneSync $sync   Billing phone sync.
	 */
	public function __construct( CustomerEvents $events, BillingPhoneSync $sync ) {
		$this->events = $events;
		$this->sync   = $sync;
	}

	/**
	 * Put the identity on an order (the caller saves it).
	 *
	 * @param \WC_Order          $order   Order.
	 * @param PhoneNumber|null   $phone   Phone entered (null when none).
	 * @param ConsentRecord|null $consent Consent given (null when the box was not ticked or not shown).
	 * @param ShopperContext     $shopper Shopper placing the order.
	 * @return void
	 */
	public function record( \WC_Order $order, ?PhoneNumber $phone, ?ConsentRecord $consent, ShopperContext $shopper ): void {
		// A reused draft or resumed order must not keep an answer from an earlier attempt.
		if ( null !== $phone ) {
			$order->update_meta_data( OrderMeta::PHONE_E164, $phone->e164 );
			$order->update_meta_data( OrderMeta::PHONE_COUNTRY, $phone->country );
			$this->sync->order( $order, $phone );
		} else {
			$order->delete_meta_data( OrderMeta::PHONE_E164 );
			$order->delete_meta_data( OrderMeta::PHONE_COUNTRY );
		}

		if ( null !== $consent ) {
			$order->update_meta_data( OrderMeta::MARKETING_CONSENT, $consent->value ? 'yes' : 'no' );
			$order->update_meta_data( OrderMeta::CONSENT_SOURCE, $consent->source );
			$order->update_meta_data( OrderMeta::CONSENT_CAPTURED_AT, $consent->captured_at );
			$order->update_meta_data( OrderMeta::CONSENT_TEXT_HASH, $consent->text_hash );
		} else {
			foreach ( array( OrderMeta::MARKETING_CONSENT, OrderMeta::CONSENT_SOURCE, OrderMeta::CONSENT_CAPTURED_AT, OrderMeta::CONSENT_TEXT_HASH ) as $key ) {
				$order->delete_meta_data( $key );
			}
		}

		$customer_id = $shopper->pixalink_customer_id();
		if ( null !== $customer_id ) {
			$order->update_meta_data( OrderMeta::CUSTOMER_ID, $customer_id );
		}
	}

	/**
	 * After the order exists: update the shopper's account and record customer.upserted when something changed.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public function after_order( \WC_Order $order ): void {
		$phone   = self::phone_of( $order );
		$consent = self::consent_of( $order );
		$user_id = (int) $order->get_customer_id();

		if ( $user_id < 1 ) {
			if ( null !== $consent ) {
				$this->events->guest_order( $order );
			}

			return;
		}

		$changed = false;
		if ( null !== $phone ) {
			$previous = UserMeta::phone( $user_id );
			if ( UserMeta::set_phone( $user_id, $phone ) ) {
				$changed = true;
				$this->sync->user( $user_id, $phone, null !== $previous ? $previous[0] : null );
			}
		}

		if ( null !== $consent && UserMeta::set_consent( $user_id, $consent ) ) {
			$changed = true;
		}

		if ( $changed ) {
			$this->events->user_changed( $user_id );
		}
	}

	/**
	 * The phone stored on an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return PhoneNumber|null
	 */
	public static function phone_of( \WC_Order $order ): ?PhoneNumber {
		$e164    = OrderMeta::get_string( $order, OrderMeta::PHONE_E164 );
		$country = strtoupper( OrderMeta::get_string( $order, OrderMeta::PHONE_COUNTRY ) );
		if ( 1 !== preg_match( '/^\+[1-9][0-9]{6,14}$/', $e164 ) || 1 !== preg_match( '/^[A-Z]{2}$/', $country ) ) {
			return null;
		}

		return new PhoneNumber( $e164, $country, '' );
	}

	/**
	 * The consent stored on an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return ConsentRecord|null
	 */
	public static function consent_of( \WC_Order $order ): ?ConsentRecord {
		return ConsentRecord::from_parts(
			OrderMeta::get_string( $order, OrderMeta::MARKETING_CONSENT ),
			OrderMeta::get_string( $order, OrderMeta::CONSENT_SOURCE ),
			OrderMeta::get_string( $order, OrderMeta::CONSENT_CAPTURED_AT ),
			OrderMeta::get_string( $order, OrderMeta::CONSENT_TEXT_HASH )
		);
	}
}
