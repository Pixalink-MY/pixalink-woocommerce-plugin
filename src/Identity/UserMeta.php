<?php
/**
 * User meta owned by the plugin.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Identity;

use Pixalink\Loyalty\Fields\ConsentRecord;
use Pixalink\Loyalty\Fields\PhoneNumber;

defined( 'ABSPATH' ) || exit;

/**
 * Keys and typed access for the loyalty identity of a WordPress user.
 *
 * A verified link is `pixalink_customer_id` + `pixalink_phone_verified_at` (+ `pixalink_linked_at`/`_via`); a phone
 * number without them is the shopper's own, unverified number. The verified phone is never overwritten by a form:
 * changing it means verifying the new number.
 */
final class UserMeta {

	public const CUSTOMER_ID       = 'pixalink_customer_id';
	public const PHONE_E164        = 'pixalink_phone_e164';
	public const PHONE_COUNTRY     = 'pixalink_phone_country';
	public const PHONE_VERIFIED_AT = 'pixalink_phone_verified_at';
	public const LINKED_AT         = 'pixalink_linked_at';
	public const LINKED_VIA        = 'pixalink_linked_via';
	public const CONSENT           = 'pixalink_marketing_consent';
	public const CONSENT_AT        = 'pixalink_marketing_consent_at';
	public const CONSENT_SOURCE    = 'pixalink_marketing_consent_source';
	public const CONSENT_TEXT_HASH = 'pixalink_marketing_consent_text_hash';

	/**
	 * Every key above (privacy export/erase and uninstall).
	 */
	public const KEYS = array(
		self::CUSTOMER_ID,
		self::PHONE_E164,
		self::PHONE_COUNTRY,
		self::PHONE_VERIFIED_AT,
		self::LINKED_AT,
		self::LINKED_VIA,
		self::CONSENT,
		self::CONSENT_AT,
		self::CONSENT_SOURCE,
		self::CONSENT_TEXT_HASH,
	);

	private const LINK_KEYS = array( self::CUSTOMER_ID, self::PHONE_VERIFIED_AT, self::LINKED_AT, self::LINKED_VIA );

	/**
	 * A meta value as a trimmed string ('' when missing).
	 *
	 * @param int    $user_id User id.
	 * @param string $key     Meta key.
	 * @return string
	 */
	public static function get( int $user_id, string $key ): string {
		if ( $user_id < 1 ) {
			return '';
		}

		$value = get_user_meta( $user_id, $key, true );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * The user's verified link, if any.
	 *
	 * @param int $user_id User id.
	 * @return Link|null
	 */
	public static function link( int $user_id ): ?Link {
		return Link::from_array(
			array(
				'customer_id'   => self::get( $user_id, self::CUSTOMER_ID ),
				'phone_e164'    => self::get( $user_id, self::PHONE_E164 ),
				'phone_country' => self::get( $user_id, self::PHONE_COUNTRY ),
				'verified_at'   => self::get( $user_id, self::PHONE_VERIFIED_AT ),
				'via'           => self::get( $user_id, self::LINKED_VIA ),
			)
		);
	}

	/**
	 * Whether the user's phone number is verified.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function is_verified( int $user_id ): bool {
		return null !== self::link( $user_id );
	}

	/**
	 * Store a verified link (and its phone number as the user's Pixalink phone).
	 *
	 * @param int  $user_id User id.
	 * @param Link $link    Link.
	 * @return void
	 */
	public static function store_link( int $user_id, Link $link ): void {
		if ( $user_id < 1 ) {
			return;
		}

		update_user_meta( $user_id, self::CUSTOMER_ID, $link->customer_id );
		update_user_meta( $user_id, self::PHONE_E164, $link->phone_e164 );
		if ( '' !== $link->phone_country ) {
			update_user_meta( $user_id, self::PHONE_COUNTRY, $link->phone_country );
		}
		update_user_meta( $user_id, self::PHONE_VERIFIED_AT, $link->verified_at );
		update_user_meta( $user_id, self::LINKED_AT, $link->verified_at );
		update_user_meta( $user_id, self::LINKED_VIA, $link->via );
	}

	/**
	 * Drop the verified link; the phone number stays as the user's unverified number.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function clear_link( int $user_id ): void {
		foreach ( self::LINK_KEYS as $key ) {
			delete_user_meta( $user_id, $key );
		}
	}

	/**
	 * The user's Pixalink phone number (verified or not): `[e164, country]`, or null.
	 *
	 * @param int $user_id User id.
	 * @return array{0:string,1:string}|null
	 */
	public static function phone( int $user_id ): ?array {
		$e164 = self::get( $user_id, self::PHONE_E164 );

		return 1 === preg_match( '/^\+[1-9][0-9]{6,14}$/', $e164 ) ? array( $e164, strtoupper( self::get( $user_id, self::PHONE_COUNTRY ) ) ) : null;
	}

	/**
	 * Set the user's unverified phone number.
	 *
	 * @param int         $user_id User id.
	 * @param PhoneNumber $phone   Phone.
	 * @return bool Whether it changed; false when the user has a verified number (never overwritten here).
	 */
	public static function set_phone( int $user_id, PhoneNumber $phone ): bool {
		if ( $user_id < 1 || self::is_verified( $user_id ) ) {
			return false;
		}

		$current = self::phone( $user_id );
		if ( null !== $current && $current[0] === $phone->e164 && $current[1] === $phone->country ) {
			return false;
		}

		update_user_meta( $user_id, self::PHONE_E164, $phone->e164 );
		update_user_meta( $user_id, self::PHONE_COUNTRY, $phone->country );

		return true;
	}

	/**
	 * The user's recorded consent.
	 *
	 * @param int $user_id User id.
	 * @return ConsentRecord|null
	 */
	public static function consent( int $user_id ): ?ConsentRecord {
		return ConsentRecord::from_parts(
			self::get( $user_id, self::CONSENT ),
			self::get( $user_id, self::CONSENT_SOURCE ),
			self::get( $user_id, self::CONSENT_AT ),
			self::get( $user_id, self::CONSENT_TEXT_HASH )
		);
	}

	/**
	 * Record a consent answer.
	 *
	 * @param int           $user_id User id.
	 * @param ConsentRecord $record  Answer.
	 * @return bool Whether the value changed (a repeated identical answer only refreshes nothing).
	 */
	public static function set_consent( int $user_id, ConsentRecord $record ): bool {
		if ( $user_id < 1 ) {
			return false;
		}

		$current = self::consent( $user_id );
		if ( null !== $current && $current->value === $record->value && $current->source === $record->source && $current->text_hash === $record->text_hash ) {
			return false;
		}

		update_user_meta( $user_id, self::CONSENT, $record->value ? 'yes' : 'no' );
		update_user_meta( $user_id, self::CONSENT_SOURCE, $record->source );
		update_user_meta( $user_id, self::CONSENT_AT, $record->captured_at );
		update_user_meta( $user_id, self::CONSENT_TEXT_HASH, $record->text_hash );

		return true;
	}
}
