<?php
/**
 * Strict E.164 normalisation of shopper-typed phone numbers.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Turns what a shopper typed (national or international form) plus the chosen country into E.164.
 *
 * Rules:
 *
 * - Only ASCII digits, spaces, `-`, `.`, `/`, brackets and one leading `+` are accepted. Letters, extensions, `*`, `#`
 *   and non-ASCII digits are rejected, never stripped. Invisible characters that spreadsheet and WhatsApp copies carry
 *   (no-break space, non-breaking hyphen, zero-width and bidi marks) count as separators.
 * - `+` or `00` starts an international number; the calling code then decides the country (the chosen one when it
 *   shares the code, otherwise the code's primary country).
 * - In national form the country's trunk prefix is dropped once (`012-345 6789` in Malaysia → `+60123456789`); a number
 *   typed with the calling code but no `+` (`60123456789`) is recognised when it is too long to be national.
 * - Lengths are checked against the country's known national length, else against E.164 (7 to 15 digits in total).
 */
final class PhoneNormalizer {

	public const E164_PATTERN = '/^\+[1-9][0-9]{6,14}$/';

	/**
	 * Characters treated as separators: no-break and other Unicode spaces, Unicode hyphens and dashes, zero-width
	 * characters, bidi marks and the byte-order mark.
	 */
	private const INVISIBLE_SEPARATORS = '/[\x{00A0}\x{1680}\x{2000}-\x{200F}\x{2010}-\x{2015}\x{2028}-\x{202F}\x{205F}-\x{2064}\x{2066}-\x{2069}\x{3000}\x{FEFF}]/u';

	/**
	 * Allowed characters once trimmed (ASCII only).
	 */
	private const ALLOWED = '/^\+?[0-9 .()\/-]+$/';

	/**
	 * Country data keyed by ISO code.
	 *
	 * @var array<string,array{code:string,trunk?:string,min?:int,max?:int,primary?:bool}>
	 */
	private array $countries;

	/**
	 * Countries by calling code, primary first.
	 *
	 * @var array<string,list<string>>
	 */
	private array $by_code = array();

	/**
	 * Constructor.
	 *
	 * @param array<string,array{code:string,trunk?:string,min?:int,max?:int,primary?:bool}> $countries Country data ({@see PhoneCountries::data()}).
	 */
	public function __construct( array $countries ) {
		$this->countries = $countries;

		foreach ( $countries as $iso => $definition ) {
			$code                   = $definition['code'];
			$this->by_code[ $code ] = $this->by_code[ $code ] ?? array();
			if ( ! empty( $definition['primary'] ) ) {
				array_unshift( $this->by_code[ $code ], $iso );
				continue;
			}
			$this->by_code[ $code ][] = $iso;
		}
	}

	/**
	 * Normalise, or null when the input is not a valid number.
	 *
	 * @param string $raw     What the shopper typed.
	 * @param string $country Chosen ISO country (used for national-form input).
	 * @return PhoneNumber|null
	 */
	public function normalize( string $raw, string $country ): ?PhoneNumber {
		try {
			return $this->parse( $raw, $country );
		} catch ( InvalidPhoneNumber $exception ) {
			return null;
		}
	}

	/**
	 * Normalise or explain why not.
	 *
	 * @param string $raw     What the shopper typed.
	 * @param string $country Chosen ISO country (used for national-form input).
	 * @return PhoneNumber
	 * @throws InvalidPhoneNumber When the input is not a valid number.
	 */
	public function parse( string $raw, string $country ): PhoneNumber {
		$cleaned = preg_replace( self::INVISIBLE_SEPARATORS, ' ', $raw );
		$cleaned = trim( is_string( $cleaned ) ? $cleaned : $raw );

		if ( '' !== $cleaned && 1 !== preg_match( self::ALLOWED, $cleaned ) ) {
			self::fail( InvalidPhoneNumber::INVALID_CHARACTERS );
		}

		$digits = (string) preg_replace( '/[^0-9]/', '', $cleaned );
		if ( '' === $digits ) {
			self::fail( InvalidPhoneNumber::EMPTY );
		}

		$country       = strtoupper( trim( $country ) );
		$international = 0 === strpos( $cleaned, '+' );
		if ( ! $international && 0 === strpos( $digits, '00' ) ) {
			$international = true;
			$digits        = substr( $digits, 2 );
		}

		return $international ? $this->international( $digits, $country ) : $this->national( $digits, $country );
	}

	/**
	 * A number given with its calling code.
	 *
	 * @param string $digits  Digits after "+" or "00".
	 * @param string $country Chosen country.
	 * @return PhoneNumber
	 * @throws InvalidPhoneNumber When invalid.
	 */
	private function international( string $digits, string $country ): PhoneNumber {
		if ( strlen( $digits ) > 15 ) {
			self::fail( InvalidPhoneNumber::TOO_LONG );
		}

		$code = $this->calling_code_of( $digits );
		if ( null === $code ) {
			self::fail( InvalidPhoneNumber::UNKNOWN_CALLING_CODE );
		}

		$filed_under = ( $this->countries[ $country ]['code'] ?? null ) === $code ? $country : $this->by_code[ $code ][0];
		$national    = substr( $digits, strlen( $code ) );

		// "+60 012 ..." repeats the national trunk prefix after the calling code.
		$trunk = $this->trunk( $filed_under );
		if ( '0' === $trunk && 0 === strpos( $national, '0' ) ) {
			$national = substr( $national, 1 );
		}

		return $this->build( $code, $national, $filed_under );
	}

	/**
	 * A number in national form for the chosen country.
	 *
	 * @param string $digits  Digits.
	 * @param string $country Chosen country.
	 * @return PhoneNumber
	 * @throws InvalidPhoneNumber When invalid.
	 */
	private function national( string $digits, string $country ): PhoneNumber {
		if ( ! isset( $this->countries[ $country ] ) ) {
			self::fail( InvalidPhoneNumber::UNKNOWN_COUNTRY );
		}

		$code  = $this->countries[ $country ]['code'];
		$trunk = $this->trunk( $country );

		if ( '' !== $trunk && 0 === strpos( $digits, $trunk ) ) {
			$digits = substr( $digits, strlen( $trunk ) );
		} else {
			// Typed with the calling code but without "+": only when too long to be a national number.
			[ , $max ] = $this->bounds( $country, $code );
			$without   = substr( $digits, strlen( $code ) );
			if ( strlen( $digits ) > $max && 0 === strpos( $digits, $code ) && $this->within_bounds( $without, $country, $code ) ) {
				$digits = $without;
			}
		}

		return $this->build( $code, $digits, $country );
	}

	/**
	 * Validate the national significant number and build the result.
	 *
	 * @param string $code     Calling code.
	 * @param string $national National significant number.
	 * @param string $country  Country filed under.
	 * @return PhoneNumber
	 * @throws InvalidPhoneNumber When invalid.
	 */
	private function build( string $code, string $national, string $country ): PhoneNumber {
		[ $min, $max ] = $this->bounds( $country, $code );

		if ( strlen( $national ) < $min ) {
			self::fail( InvalidPhoneNumber::TOO_SHORT );
		}
		if ( strlen( $national ) > $max ) {
			self::fail( InvalidPhoneNumber::TOO_LONG );
		}

		// A leading zero left after the trunk prefix is a typo, except where national numbers keep it (Italy).
		if ( '' !== $this->trunk( $country ) && 0 === strpos( $national, '0' ) ) {
			self::fail( InvalidPhoneNumber::INVALID );
		}

		$e164 = '+' . $code . $national;
		if ( 1 !== preg_match( self::E164_PATTERN, $e164 ) ) {
			self::fail( InvalidPhoneNumber::INVALID );
		}

		return new PhoneNumber( $e164, $country, $national );
	}

	/**
	 * Abort with a reason.
	 *
	 * @param string $reason InvalidPhoneNumber reason.
	 * @return never
	 * @throws InvalidPhoneNumber Always.
	 */
	private static function fail( string $reason ): never {
		$exception = new InvalidPhoneNumber( $reason );
		throw $exception;
	}

	/**
	 * Longest known calling code at the start of the digits (codes are prefix-free, so at most one matches).
	 *
	 * @param string $digits Digits.
	 * @return string|null
	 */
	private function calling_code_of( string $digits ): ?string {
		for ( $length = 1; $length <= 3; $length++ ) {
			$candidate = substr( $digits, 0, $length );
			if ( isset( $this->by_code[ $candidate ] ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Trunk prefix of a country ('0' unless the data says otherwise).
	 *
	 * @param string $country Country.
	 * @return string
	 */
	private function trunk( string $country ): string {
		return $this->countries[ $country ]['trunk'] ?? '0';
	}

	/**
	 * Allowed national length `[min, max]`: the country's own, else what E.164 leaves after the calling code.
	 *
	 * @param string $country Country.
	 * @param string $code    Calling code.
	 * @return array{0:int,1:int}
	 */
	private function bounds( string $country, string $code ): array {
		$definition = $this->countries[ $country ] ?? array();

		return array(
			$definition['min'] ?? max( 4, 7 - strlen( $code ) ),
			$definition['max'] ?? 15 - strlen( $code ),
		);
	}

	/**
	 * Whether a national number has an allowed length for the country.
	 *
	 * @param string $national National number.
	 * @param string $country  Country.
	 * @param string $code     Calling code.
	 * @return bool
	 */
	private function within_bounds( string $national, string $country, string $code ): bool {
		[ $min, $max ] = $this->bounds( $country, $code );

		return strlen( $national ) >= $min && strlen( $national ) <= $max;
	}
}
