<?php
/**
 * A verified link between a shopper and a Pixalink customer.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable record of who the shopper proved to be, and how.
 */
final class Link {

	public const VIA_OTP        = 'otp';
	public const VIA_SSO_TICKET = 'sso_ticket';

	private const E164_PATTERN = '/^\+[1-9][0-9]{6,14}$/';

	/**
	 * Constructor.
	 *
	 * @param string $customer_id Pixalink customer id.
	 * @param string $phone_e164  Verified phone number.
	 * @param string $phone_country ISO country of the phone number ('' when unknown).
	 * @param string $verified_at ISO 8601 UTC time of the proof.
	 * @param string $via         VIA_OTP or VIA_SSO_TICKET.
	 */
	public function __construct(
		public readonly string $customer_id,
		public readonly string $phone_e164,
		public readonly string $phone_country,
		public readonly string $verified_at,
		public readonly string $via
	) {}

	/**
	 * Rebuild from stored values, or null when anything essential is missing or malformed.
	 *
	 * @param array<mixed> $values `{customer_id, phone_e164, phone_country, verified_at, via}`.
	 * @return Link|null
	 */
	public static function from_array( array $values ): ?Link {
		$string = static fn ( string $key ): string => isset( $values[ $key ] ) && is_scalar( $values[ $key ] ) ? trim( (string) $values[ $key ] ) : '';

		$customer_id = $string( 'customer_id' );
		$phone       = $string( 'phone_e164' );
		$country     = strtoupper( $string( 'phone_country' ) );
		$verified_at = $string( 'verified_at' );
		$via         = $string( 'via' );

		if ( '' === $customer_id || strlen( $customer_id ) > 64 || 1 !== preg_match( self::E164_PATTERN, $phone ) || '' === $verified_at ) {
			return null;
		}

		$known_country = 1 === preg_match( '/^[A-Z]{2}$/', $country );
		$known_via     = in_array( $via, array( self::VIA_OTP, self::VIA_SSO_TICKET ), true );

		return new self( $customer_id, $phone, $known_country ? $country : '', $verified_at, $known_via ? $via : self::VIA_OTP );
	}

	/**
	 * Storable form.
	 *
	 * @return array{customer_id:string,phone_e164:string,phone_country:string,verified_at:string,via:string}
	 */
	public function to_array(): array {
		return array(
			'customer_id'   => $this->customer_id,
			'phone_e164'    => $this->phone_e164,
			'phone_country' => $this->phone_country,
			'verified_at'   => $this->verified_at,
			'via'           => $this->via,
		);
	}
}
