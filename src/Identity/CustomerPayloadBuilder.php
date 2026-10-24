<?php
/**
 * `data` of customer.upserted.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Identity;

use Pixalink\Loyalty\Orders\OrderPayloadBuilder;

defined( 'ABSPATH' ) || exit;

/**
 * `{customer: {pixalink_customer_id, wp_user_id, is_guest, phone_e164, phone_country, email, first_name, last_name,
 * marketing_consent}}` (event.request.json `customer_upserted_data`).
 *
 * The customer id is sent only for a verified link, and consent only when it is reportable (a withdrawal Pixalink
 * itself reported is never echoed back).
 */
final class CustomerPayloadBuilder {

	/**
	 * Order payloads (guest orders reuse their `customer` object).
	 *
	 * @var OrderPayloadBuilder
	 */
	private OrderPayloadBuilder $orders;

	/**
	 * Constructor.
	 *
	 * @param OrderPayloadBuilder $orders Order payloads.
	 */
	public function __construct( OrderPayloadBuilder $orders ) {
		$this->orders = $orders;
	}

	/**
	 * Data for a registered shopper.
	 *
	 * @param \WP_User $user User.
	 * @return array{customer:array<string,mixed>}
	 */
	public function for_user( \WP_User $user ): array {
		$user_id = (int) $user->ID;
		$link    = UserMeta::link( $user_id );
		$phone   = UserMeta::phone( $user_id );
		$email   = trim( (string) $user->user_email );
		$country = null !== $phone && 1 === preg_match( '/^[A-Z]{2}$/', $phone[1] ) ? $phone[1] : null;

		return array(
			'customer' => array(
				'pixalink_customer_id' => $link?->customer_id,
				'wp_user_id'           => $user_id,
				'is_guest'             => false,
				'phone_e164'           => null !== $phone ? $phone[0] : null,
				'phone_country'        => null !== $phone ? $country : null,
				'email'                => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : null,
				'first_name'           => self::name( $user_id, 'first_name' ),
				'last_name'            => self::name( $user_id, 'last_name' ),
				'marketing_consent'    => UserMeta::consent( $user_id )?->to_reportable_array(),
			),
		);
	}

	/**
	 * Data for a guest order that carried new consent.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{customer:array<string,mixed>}
	 */
	public function for_order( \WC_Order $order ): array {
		return array( 'customer' => $this->orders->customer( $order ) );
	}

	/**
	 * Profile name, else the billing name.
	 *
	 * @param int    $user_id User id.
	 * @param string $field   first_name or last_name.
	 * @return string|null
	 */
	private static function name( int $user_id, string $field ): ?string {
		$name = UserMeta::get( $user_id, $field );
		if ( '' === $name ) {
			$name = UserMeta::get( $user_id, 'billing_' . $field );
		}

		return '' === $name ? null : $name;
	}
}
