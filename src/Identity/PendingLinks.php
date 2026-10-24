<?php
/**
 * Links waiting for their one-time code.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * One pending link per shopper subject (`user:{id}` or `session:{id}`), in a 15-minute transient.
 *
 * The customer id stays here until the code is checked, so an unverified number never becomes the account's
 * `pixalink_customer_id`.
 */
final class PendingLinks {

	public const PREFIX = 'pixalink_loyalty_otp_';

	/**
	 * Lifetime of a pending link (seconds).
	 */
	public const TTL = 900;

	/**
	 * The pending link of a subject.
	 *
	 * @param string $subject Shopper subject.
	 * @return array{customer_id:string,phone_e164:string,phone_country:string,sent_via:string,resend_at:int}|null
	 */
	public function get( string $subject ): ?array {
		$stored = get_transient( self::key( $subject ) );
		if ( ! is_array( $stored ) || ! isset( $stored['customer_id'], $stored['phone_e164'] ) || ! is_string( $stored['customer_id'] ) || ! is_string( $stored['phone_e164'] ) ) {
			return null;
		}

		return array(
			'customer_id'   => $stored['customer_id'],
			'phone_e164'    => $stored['phone_e164'],
			'phone_country' => isset( $stored['phone_country'] ) && is_string( $stored['phone_country'] ) ? $stored['phone_country'] : '',
			'sent_via'      => isset( $stored['sent_via'] ) && is_string( $stored['sent_via'] ) ? $stored['sent_via'] : '',
			'resend_at'     => isset( $stored['resend_at'] ) && is_int( $stored['resend_at'] ) ? $stored['resend_at'] : 0,
		);
	}

	/**
	 * Store a pending link.
	 *
	 * @param string                                                                                         $subject Shopper subject.
	 * @param array{customer_id:string,phone_e164:string,phone_country:string,sent_via:string,resend_at:int} $pending Pending link.
	 * @return void
	 */
	public function put( string $subject, array $pending ): void {
		set_transient( self::key( $subject ), $pending, self::TTL );
	}

	/**
	 * Forget a subject's pending link.
	 *
	 * @param string $subject Shopper subject.
	 * @return void
	 */
	public function forget( string $subject ): void {
		delete_transient( self::key( $subject ) );
	}

	/**
	 * Transient name.
	 *
	 * @param string $subject Shopper subject.
	 * @return string
	 */
	public static function key( string $subject ): string {
		return self::PREFIX . md5( $subject );
	}
}
