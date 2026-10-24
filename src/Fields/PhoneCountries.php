<?php
/**
 * Countries offered by the phone field.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Calling codes from `data/phone-countries.php`, labelled with WooCommerce's translated country names.
 */
final class PhoneCountries {

	/**
	 * Fallback when the store's base country has no calling code in the data file.
	 */
	public const FALLBACK_COUNTRY = 'MY';

	/**
	 * Country data keyed by ISO code.
	 *
	 * @var array<string,array{code:string,trunk?:string,min?:int,max?:int,primary?:bool}>
	 */
	private array $countries;

	/**
	 * Constructor.
	 *
	 * @param array<string,array{code:string,trunk?:string,min?:int,max?:int,primary?:bool}> $countries Country data ({@see PhoneCountries::data()}).
	 */
	public function __construct( array $countries ) {
		$this->countries = $countries;
	}

	/**
	 * The shipped country data.
	 *
	 * @return array<string,array{code:string,trunk?:string,min?:int,max?:int,primary?:bool}>
	 */
	public static function data(): array {
		static $data = null;
		if ( null === $data ) {
			$loaded = require PIXALINK_LOYALTY_DIR . 'data/phone-countries.php';
			$data   = is_array( $loaded ) ? $loaded : array();
		}

		/**
		 * Country data.
		 *
		 * @var array<string,array{code:string,trunk?:string,min?:int,max?:int,primary?:bool}> $data
		 */
		return $data;
	}

	/**
	 * Country data keyed by ISO code.
	 *
	 * @return array<string,array{code:string,trunk?:string,min?:int,max?:int,primary?:bool}>
	 */
	public function all(): array {
		return $this->countries;
	}

	/**
	 * Whether a country can be chosen.
	 *
	 * @param string $country ISO code (any case).
	 * @return bool
	 */
	public function is_supported( string $country ): bool {
		return isset( $this->countries[ strtoupper( trim( $country ) ) ] );
	}

	/**
	 * Calling code of a country, without "+".
	 *
	 * @param string $country ISO code (any case).
	 * @return string|null
	 */
	public function calling_code( string $country ): ?string {
		return $this->countries[ strtoupper( trim( $country ) ) ]['code'] ?? null;
	}

	/**
	 * Default country: the store's base country when it has a calling code, else Malaysia.
	 *
	 * @return string
	 */
	public function default_country(): string {
		$base = function_exists( 'WC' ) && null !== WC()->countries ? (string) WC()->countries->get_base_country() : '';

		/**
		 * Filters the phone country preselected at checkout and on account forms.
		 *
		 * @since 0.1.0
		 *
		 * @param string $country ISO 3166-1 alpha-2 code; default the store's base country.
		 */
		$country = apply_filters( 'pixalink_loyalty_default_phone_country', $base );
		$country = is_string( $country ) ? strtoupper( $country ) : '';

		return $this->is_supported( $country ) ? $country : self::FALLBACK_COUNTRY;
	}

	/**
	 * Select options `ISO => "Malaysia (+60)"`, sorted by translated name.
	 *
	 * @return array<string,string>
	 */
	public function options(): array {
		$names = function_exists( 'WC' ) && null !== WC()->countries ? WC()->countries->get_countries() : array();

		$options = array();
		foreach ( $this->countries as $iso => $definition ) {
			$name = isset( $names[ $iso ] ) && is_string( $names[ $iso ] ) ? html_entity_decode( $names[ $iso ], ENT_QUOTES, 'UTF-8' ) : $iso;
			/* translators: 1: country name, 2: calling code such as 60. */
			$options[ $iso ] = sprintf( __( '%1$s (+%2$s)', 'pixalink-loyalty-for-woocommerce' ), $name, $definition['code'] );
		}

		asort( $options, SORT_NATURAL | SORT_FLAG_CASE );

		return $options;
	}
}
