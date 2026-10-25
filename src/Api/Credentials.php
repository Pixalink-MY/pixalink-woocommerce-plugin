<?php
/**
 * Outlet API credentials storage.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Api;

use Pixalink\Loyalty\Support\Crypto;
use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the outlet API key and request signing key.
 *
 * Precedence:
 * 1. wp-config constants `PIXALINK_LOYALTY_API_KEY` / `PIXALINK_LOYALTY_SIGNING_KEY` (the signing key is derived
 *    from the constant API key when only the key is defined; a stored signing key is never mixed with it).
 * 2. The encrypted `pixalink_loyalty_credentials` option (autoload off) written by the Connect flow.
 */
final class Credentials {

	public const API_KEY_CONSTANT     = 'PIXALINK_LOYALTY_API_KEY';
	public const SIGNING_KEY_CONSTANT = 'PIXALINK_LOYALTY_SIGNING_KEY';

	public const SOURCE_CONSTANT = 'constant';
	public const SOURCE_OPTION   = 'option';
	public const SOURCE_NONE     = 'none';

	/**
	 * Secret encryption.
	 *
	 * @var Crypto
	 */
	private Crypto $crypto;

	/**
	 * Option storage.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Reads a constant by name and returns its value, or null when undefined.
	 *
	 * @var \Closure(string):mixed
	 */
	private \Closure $constant_reader;

	/**
	 * Decrypted option payload, memoised per request.
	 *
	 * @var array{api_key?:string,signing_key?:string}|null
	 */
	private ?array $stored = null;

	/**
	 * Constructor.
	 *
	 * @param Crypto        $crypto          Secret encryption.
	 * @param Options       $options         Option storage.
	 * @param \Closure|null $constant_reader Constant lookup override (tests); defaults to defined()/constant().
	 */
	public function __construct( Crypto $crypto, Options $options, ?\Closure $constant_reader = null ) {
		$this->crypto          = $crypto;
		$this->options         = $options;
		$this->constant_reader = $constant_reader ?? static fn ( string $name ) => defined( $name ) ? constant( $name ) : null;
	}

	/**
	 * The outlet API key, or null when not connected.
	 *
	 * @return string|null
	 */
	public function api_key(): ?string {
		$constant = $this->constant_string( self::API_KEY_CONSTANT );
		if ( null !== $constant ) {
			return $constant;
		}

		return $this->stored()['api_key'] ?? null;
	}

	/**
	 * The request signing key, or null when not connected.
	 *
	 * @return string|null
	 */
	public function signing_key(): ?string {
		$constant = $this->constant_string( self::SIGNING_KEY_CONSTANT );
		if ( null !== $constant ) {
			return $constant;
		}

		$constant_api_key = $this->constant_string( self::API_KEY_CONSTANT );
		if ( null !== $constant_api_key ) {
			return Signer::derive_signing_key( $constant_api_key );
		}

		$stored = $this->stored();
		if ( isset( $stored['signing_key'] ) ) {
			return $stored['signing_key'];
		}

		return isset( $stored['api_key'] ) ? Signer::derive_signing_key( $stored['api_key'] ) : null;
	}

	/**
	 * Whether both keys are available.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return null !== $this->api_key() && null !== $this->signing_key();
	}

	/**
	 * Where the API key comes from.
	 *
	 * @return string One of the SOURCE_* constants.
	 */
	public function source(): string {
		if ( null !== $this->constant_string( self::API_KEY_CONSTANT ) ) {
			return self::SOURCE_CONSTANT;
		}

		return isset( $this->stored()['api_key'] ) ? self::SOURCE_OPTION : self::SOURCE_NONE;
	}

	/**
	 * Whether wp-config constants override the stored credentials (the settings UI should be read-only).
	 *
	 * @return bool
	 */
	public function is_overridden(): bool {
		return self::SOURCE_CONSTANT === $this->source();
	}

	/**
	 * Encrypt and store credentials (autoload off).
	 *
	 * @param string      $api_key     Plain-text outlet API key.
	 * @param string|null $signing_key Signing key; derived from the API key when omitted.
	 * @return void
	 * @throws \InvalidArgumentException When the API key is empty.
	 */
	public function store( string $api_key, ?string $signing_key = null ): void {
		$api_key = trim( $api_key );
		if ( '' === $api_key ) {
			throw new \InvalidArgumentException( 'API key must not be empty.' );
		}

		$payload = array(
			'api_key'     => $api_key,
			'signing_key' => $signing_key ?? Signer::derive_signing_key( $api_key ),
		);

		$this->options->set( Options::CREDENTIALS, $this->crypto->encrypt( (string) wp_json_encode( $payload ) ) );
		$this->stored = $payload;
	}

	/**
	 * Forget stored credentials (constants are untouched).
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->options->delete( Options::CREDENTIALS );
		$this->stored = array();
	}

	/**
	 * Decrypted option payload.
	 *
	 * @return array{api_key?:string,signing_key?:string}
	 */
	private function stored(): array {
		if ( null !== $this->stored ) {
			return $this->stored;
		}

		$this->stored = array();
		$encrypted    = $this->options->get_string( Options::CREDENTIALS );
		if ( '' === $encrypted ) {
			return $this->stored;
		}

		$json = $this->crypto->decrypt( $encrypted );
		if ( null === $json ) {
			return $this->stored;
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return $this->stored;
		}

		foreach ( array( 'api_key', 'signing_key' ) as $field ) {
			if ( isset( $decoded[ $field ] ) && is_string( $decoded[ $field ] ) && '' !== $decoded[ $field ] ) {
				$this->stored[ $field ] = $decoded[ $field ];
			}
		}

		return $this->stored;
	}

	/**
	 * A non-empty string constant, or null.
	 *
	 * @param string $name Constant name.
	 * @return string|null
	 */
	private function constant_string( string $name ): ?string {
		$value = ( $this->constant_reader )( $name );

		return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : null;
	}
}
