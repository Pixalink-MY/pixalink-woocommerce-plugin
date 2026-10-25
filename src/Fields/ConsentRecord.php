<?php
/**
 * One recorded marketing consent answer.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * `{value, source, captured_at, text_hash}` as the contract's `marketing_consent` defines it.
 *
 * Consent is only ever recorded from an explicit act: ticking the box (true) or unticking a box that showed a saved
 * "yes" (false, My Account). An unticked box at checkout or registration records nothing. A withdrawal Pixalink
 * reports by webhook (Blocked / Do not contact) is kept locally with source `pixalink` and never sent back.
 */
final class ConsentRecord {

	public const SOURCE_CHECKOUT     = 'checkout';
	public const SOURCE_MY_ACCOUNT   = 'my_account';
	public const SOURCE_REGISTRATION = 'registration';
	public const SOURCE_ADMIN        = 'admin';
	public const SOURCE_PIXALINK     = 'pixalink';

	/**
	 * Sources the contract accepts.
	 */
	public const REPORTABLE_SOURCES = array( self::SOURCE_CHECKOUT, self::SOURCE_MY_ACCOUNT, self::SOURCE_REGISTRATION, self::SOURCE_ADMIN );

	private const SHA256_PATTERN = '/^[0-9a-f]{64}$/';

	/**
	 * Constructor.
	 *
	 * @param bool   $value       Whether the shopper agreed.
	 * @param string $source      One of the SOURCE_* constants.
	 * @param string $captured_at ISO 8601 UTC time of the answer.
	 * @param string $text_hash   sha256 of the text shown.
	 */
	public function __construct(
		public readonly bool $value,
		public readonly string $source,
		public readonly string $captured_at,
		public readonly string $text_hash
	) {}

	/**
	 * An answer given now.
	 *
	 * @param bool   $value  Whether the shopper agreed.
	 * @param string $source One of the SOURCE_* constants.
	 * @param string $text   Text exactly as shown.
	 * @param int    $now    Unix time.
	 * @return ConsentRecord
	 */
	public static function given( bool $value, string $source, string $text, int $now ): ConsentRecord {
		return new self( $value, $source, gmdate( 'Y-m-d\TH:i:s\Z', $now ), ConsentText::hash( $text ) );
	}

	/**
	 * Rebuild from stored parts, or null when any part is missing or malformed.
	 *
	 * @param mixed $value       Stored yes/no flag.
	 * @param mixed $source      Stored source.
	 * @param mixed $captured_at Stored ISO 8601 or unix time.
	 * @param mixed $text_hash   Stored hash.
	 * @return ConsentRecord|null
	 */
	public static function from_parts( $value, $source, $captured_at, $text_hash ): ?ConsentRecord {
		$flag = self::flag( $value );
		if ( null === $flag || ! is_string( $source ) || ! in_array( $source, array_merge( self::REPORTABLE_SOURCES, array( self::SOURCE_PIXALINK ) ), true ) ) {
			return null;
		}

		$time = self::iso8601( $captured_at );
		$hash = is_string( $text_hash ) ? strtolower( $text_hash ) : '';
		if ( null === $time || 1 !== preg_match( self::SHA256_PATTERN, $hash ) ) {
			return null;
		}

		return new self( $flag, $source, $time, $hash );
	}

	/**
	 * Whether the record may be sent to Pixalink.
	 *
	 * @return bool
	 */
	public function is_reportable(): bool {
		return in_array( $this->source, self::REPORTABLE_SOURCES, true ) && 1 === preg_match( self::SHA256_PATTERN, $this->text_hash );
	}

	/**
	 * Contract shape.
	 *
	 * @return array{value:bool,source:string,captured_at:string,text_hash:string}
	 */
	public function to_array(): array {
		return array(
			'value'       => $this->value,
			'source'      => $this->source,
			'captured_at' => $this->captured_at,
			'text_hash'   => $this->text_hash,
		);
	}

	/**
	 * Contract shape, or null when the record must not be sent.
	 *
	 * @return array{value:bool,source:string,captured_at:string,text_hash:string}|null
	 */
	public function to_reportable_array(): ?array {
		return $this->is_reportable() ? $this->to_array() : null;
	}

	/**
	 * Interpret a stored yes/no flag.
	 *
	 * @param mixed $value Flag.
	 * @return bool|null
	 */
	private static function flag( $value ): ?bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = strtolower( trim( (string) $value ) );
		if ( in_array( $value, array( 'yes', '1', 'true', 'on' ), true ) ) {
			return true;
		}

		return in_array( $value, array( 'no', '0', 'false', 'off' ), true ) ? false : null;
	}

	/**
	 * ISO 8601 UTC from an ISO string or unix time.
	 *
	 * @param mixed $time Time.
	 * @return string|null
	 */
	private static function iso8601( $time ): ?string {
		if ( is_int( $time ) || ( is_string( $time ) && ctype_digit( $time ) ) ) {
			return (int) $time > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $time ) : null;
		}

		if ( ! is_string( $time ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $time ) ) {
			return null;
		}

		$timestamp = strtotime( $time );

		return false === $timestamp ? null : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}
}
