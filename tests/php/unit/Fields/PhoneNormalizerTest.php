<?php
/**
 * Unit tests for PhoneNormalizer.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Fields;

use Pixalink\Loyalty\Fields\InvalidPhoneNumber;
use Pixalink\Loyalty\Fields\PhoneCountries;
use Pixalink\Loyalty\Fields\PhoneNormalizer;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * Strict E.164 from national or international input, with the country data shipped in data/phone-countries.php.
 *
 * @covers \Pixalink\Loyalty\Fields\PhoneNormalizer
 * @covers \Pixalink\Loyalty\Fields\PhoneNumber
 * @covers \Pixalink\Loyalty\Fields\InvalidPhoneNumber
 * @covers \Pixalink\Loyalty\Fields\PhoneCountries
 */
final class PhoneNormalizerTest extends TestCase {

	private function normalizer(): PhoneNormalizer {
		return new PhoneNormalizer( PhoneCountries::data() );
	}

	/**
	 * Valid inputs: raw, selected country, expected E.164, expected country, expected national number.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string,4:string}>
	 */
	public static function valid_numbers(): array {
		return array(
			'MY mobile, national with trunk 0'        => array( '012-345 6789', 'MY', '+60123456789', 'MY', '123456789' ),
			'MY mobile 011 (10 digit NSN)'            => array( '011-1234 5678', 'MY', '+601112345678', 'MY', '1112345678' ),
			'MY mobile without the trunk 0'           => array( '123456789', 'MY', '+60123456789', 'MY', '123456789' ),
			'MY landline KL'                          => array( '03-2345 6789', 'MY', '+60323456789', 'MY', '323456789' ),
			'MY landline Sarawak (8 digit NSN)'       => array( '082-123456', 'MY', '+6082123456', 'MY', '82123456' ),
			'MY landline Seremban starts with 6'      => array( '06-765 4321', 'MY', '+6067654321', 'MY', '67654321' ),
			'MY typed with country code, no plus'     => array( '60123456789', 'MY', '+60123456789', 'MY', '123456789' ),
			'MY international'                        => array( '+60 12-345 6789', 'MY', '+60123456789', 'MY', '123456789' ),
			'MY international with redundant trunk 0' => array( '+60 012 345 6789', 'MY', '+60123456789', 'MY', '123456789' ),
			'MY 00 international prefix'              => array( '0060123456789', 'MY', '+60123456789', 'MY', '123456789' ),
			'MY with parentheses and dots'            => array( '(012) 345.6789', 'MY', '+60123456789', 'MY', '123456789' ),
			'Lower-case country code'                 => array( '0123456789', 'my', '+60123456789', 'MY', '123456789' ),
			'SG 8 digits'                             => array( '9123 4567', 'SG', '+6591234567', 'SG', '91234567' ),
			'SG number typed while MY is selected'    => array( '+65 9123 4567', 'MY', '+6591234567', 'SG', '91234567' ),
			'ID mobile'                               => array( '0812-3456-7890', 'ID', '+6281234567890', 'ID', '81234567890' ),
			'TH mobile'                               => array( '081 234 5678', 'TH', '+66812345678', 'TH', '812345678' ),
			'PH mobile'                               => array( '0917 123 4567', 'PH', '+639171234567', 'PH', '9171234567' ),
			'GB mobile'                               => array( '07400 123456', 'GB', '+447400123456', 'GB', '7400123456' ),
			'US with NANP trunk 1'                    => array( '1 (415) 555-2671', 'US', '+14155552671', 'US', '4155552671' ),
			'US without trunk'                        => array( '415-555-2671', 'US', '+14155552671', 'US', '4155552671' ),
			'CA keeps the selected NANP country'      => array( '+1 604 555 0100', 'CA', '+16045550100', 'CA', '6045550100' ),
			'+1 from a non-NANP selection files US'   => array( '+1 415 555 2671', 'MY', '+14155552671', 'US', '4155552671' ),
			'IT keeps the leading zero'               => array( '06 1234 5678', 'IT', '+390612345678', 'IT', '0612345678' ),
			'IT international keeps the zero'         => array( '+39 06 1234 5678', 'IT', '+390612345678', 'IT', '0612345678' ),
			'RU trunk 8'                              => array( '8 916 123-45-67', 'RU', '+79161234567', 'RU', '9161234567' ),
			'HU trunk 06'                             => array( '06 30 123 4567', 'HU', '+36301234567', 'HU', '301234567' ),
			'Leading and trailing whitespace'         => array( "  \t0123456789 \n", 'MY', '+60123456789', 'MY', '123456789' ),
			'NBSP and non-breaking hyphen (paste)'    => array( "012\u{2011}345\u{00A0}6789", 'MY', '+60123456789', 'MY', '123456789' ),
			'Bidi marks from a WhatsApp copy'         => array( "\u{202A}+60 12-345 6789\u{202C}", 'MY', '+60123456789', 'MY', '123456789' ),
			'Zero-width space and en dash'            => array( "012\u{200B}345\u{2013}6789", 'MY', '+60123456789', 'MY', '123456789' ),
			'Unknown selected country, international' => array( '+60123456789', 'ZZ', '+60123456789', 'MY', '123456789' ),
		);
	}

	/**
	 * @dataProvider valid_numbers
	 *
	 * @param string $raw      Input.
	 * @param string $country  Selected country.
	 * @param string $e164     Expected E.164.
	 * @param string $expected Expected country.
	 * @param string $national Expected national significant number.
	 */
	public function test_valid_numbers_normalise_to_e164( string $raw, string $country, string $e164, string $expected, string $national ): void {
		$number = $this->normalizer()->parse( $raw, $country );

		$this->assertSame( $e164, $number->e164 );
		$this->assertSame( $expected, $number->country );
		$this->assertSame( $national, $number->national );
		$this->assertMatchesRegularExpression( '/^\+[1-9][0-9]{6,14}$/', $number->e164 );
	}

	/**
	 * Invalid inputs: raw, selected country, expected reason.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function invalid_numbers(): array {
		return array(
			'empty'                               => array( '', 'MY', InvalidPhoneNumber::EMPTY ),
			'whitespace only'                     => array( '   ', 'MY', InvalidPhoneNumber::EMPTY ),
			'punctuation only'                    => array( '()- .', 'MY', InvalidPhoneNumber::EMPTY ),
			'letters'                             => array( '012-FLOWERS', 'MY', InvalidPhoneNumber::INVALID_CHARACTERS ),
			'extension'                           => array( '0123456789 ext 12', 'MY', InvalidPhoneNumber::INVALID_CHARACTERS ),
			'hash and star'                       => array( '*123#', 'MY', InvalidPhoneNumber::INVALID_CHARACTERS ),
			'plus in the middle'                  => array( '012+3456789', 'MY', InvalidPhoneNumber::INVALID_CHARACTERS ),
			'double plus'                         => array( '++60123456789', 'MY', InvalidPhoneNumber::INVALID_CHARACTERS ),
			'non-ASCII digits'                    => array( '٠١٢٣٤٥٦٧٨٩', 'MY', InvalidPhoneNumber::INVALID_CHARACTERS ),
			'full-width digits'                   => array( '０１２３４５６７８９', 'MY', InvalidPhoneNumber::INVALID_CHARACTERS ),
			'emoji'                               => array( '012345678☎', 'MY', InvalidPhoneNumber::INVALID_CHARACTERS ),
			'HTML'                                => array( '<b>0123456789</b>', 'MY', InvalidPhoneNumber::INVALID_CHARACTERS ),
			'newline inside the number'           => array( "012\n3456789", 'MY', InvalidPhoneNumber::INVALID_CHARACTERS ),
			'MY too short'                        => array( '012345', 'MY', InvalidPhoneNumber::TOO_SHORT ),
			'MY too long'                         => array( '0123456789012', 'MY', InvalidPhoneNumber::TOO_LONG ),
			'SG too short'                        => array( '9123456', 'SG', InvalidPhoneNumber::TOO_SHORT ),
			'national number for an unknown land' => array( '0123456789', 'ZZ', InvalidPhoneNumber::UNKNOWN_COUNTRY ),
			'national number without a country'   => array( '0123456789', '', InvalidPhoneNumber::UNKNOWN_COUNTRY ),
			'unassigned calling code'             => array( '+999 123 4567', 'MY', InvalidPhoneNumber::UNKNOWN_CALLING_CODE ),
			'plus zero'                           => array( '+0123456789', 'MY', InvalidPhoneNumber::UNKNOWN_CALLING_CODE ),
			'calling code only'                   => array( '+60', 'MY', InvalidPhoneNumber::TOO_SHORT ),
			'longer than E.164 allows'            => array( '+8812345678901234', 'MY', InvalidPhoneNumber::TOO_LONG ),
			'00 then a short +1 number'           => array( '00123456789', 'MY', InvalidPhoneNumber::TOO_SHORT ),
			'national number still starting 0'    => array( '+60 0012 345 678', 'MY', InvalidPhoneNumber::INVALID ),
		);
	}

	/**
	 * @dataProvider invalid_numbers
	 *
	 * @param string $raw     Input.
	 * @param string $country Selected country.
	 * @param string $reason  Expected InvalidPhoneNumber reason.
	 */
	public function test_invalid_numbers_are_rejected_with_a_reason( string $raw, string $country, string $reason ): void {
		try {
			$this->normalizer()->parse( $raw, $country );
			$this->fail( 'Expected InvalidPhoneNumber for ' . $raw );
		} catch ( InvalidPhoneNumber $exception ) {
			$this->assertSame( $reason, $exception->reason() );
		}

		$this->assertNull( $this->normalizer()->normalize( $raw, $country ) );
	}

	public function test_normalize_returns_the_number_or_null(): void {
		$this->assertSame( '+60123456789', $this->normalizer()->normalize( '012-345 6789', 'MY' )?->e164 );
		$this->assertNull( $this->normalizer()->normalize( 'call me', 'MY' ) );
	}

	public function test_every_shipped_country_has_a_well_formed_calling_code_and_shared_codes_have_one_primary(): void {
		$primaries = array();
		$by_code   = array();
		foreach ( PhoneCountries::data() as $iso => $definition ) {
			$this->assertMatchesRegularExpression( '/^[A-Z]{2}$/', $iso );
			$this->assertMatchesRegularExpression( '/^[1-9][0-9]{0,2}$/', $definition['code'], $iso );
			$this->assertMatchesRegularExpression( '/^[0-9]{0,2}$/', $definition['trunk'] ?? '0', $iso );
			if ( isset( $definition['min'], $definition['max'] ) ) {
				$this->assertLessThanOrEqual( $definition['max'], $definition['min'], $iso );
				$this->assertLessThanOrEqual( 15, strlen( $definition['code'] ) + $definition['max'], $iso );
			}

			$by_code[ $definition['code'] ][] = $iso;
			if ( ! empty( $definition['primary'] ) ) {
				$primaries[ $definition['code'] ][] = $iso;
			}
		}

		foreach ( $by_code as $code => $countries ) {
			if ( count( $countries ) > 1 ) {
				$this->assertCount( 1, $primaries[ $code ] ?? array(), '+' . $code . ' is shared by ' . implode( ', ', $countries ) . ' and needs exactly one primary country.' );
			}
		}

		// Calling codes are prefix-free (ITU-T E.164): no code is a prefix of another.
		$codes = array_keys( $by_code );
		foreach ( $codes as $code ) {
			foreach ( $codes as $other ) {
				if ( $code !== $other ) {
					$this->assertStringStartsNotWith( (string) $code, (string) $other, '+' . $code . ' is a prefix of +' . $other );
				}
			}
		}
	}

	public function test_the_calling_code_of_a_country(): void {
		$countries = new PhoneCountries( PhoneCountries::data() );

		$this->assertSame( '60', $countries->calling_code( 'MY' ) );
		$this->assertSame( '60', $countries->calling_code( 'my' ) );
		$this->assertNull( $countries->calling_code( 'ZZ' ) );
		$this->assertTrue( $countries->is_supported( 'SG' ) );
		$this->assertFalse( $countries->is_supported( 'XX' ) );
	}
}
