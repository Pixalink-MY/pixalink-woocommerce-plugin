<?php
/**
 * Authenticated symmetric encryption for secrets stored in wp_options.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Libsodium secretbox (XSalsa20-Poly1305) keyed from the site's logged-in salts.
 *
 * Key = sha256( LOGGED_IN_KEY . LOGGED_IN_SALT ). Rotating those salts makes stored secrets unreadable,
 * which {@see Crypto::decrypt()} reports as null so callers can ask the merchant to reconnect.
 */
final class Crypto {

	/**
	 * Version tag prepended to every ciphertext so the format can evolve.
	 */
	public const PAYLOAD_PREFIX = 'pxl1:';

	/**
	 * Raw key material (hashed into the 32-byte secretbox key).
	 *
	 * @var string|null
	 */
	private ?string $key_material;

	/**
	 * Constructor.
	 *
	 * @param string|null $key_material Key material override (tests); defaults to the site's logged-in salts.
	 */
	public function __construct( ?string $key_material = null ) {
		$this->key_material = $key_material;
	}

	/**
	 * Whether libsodium secretbox is available on this PHP build.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_secretbox_open' );
	}

	/**
	 * Encrypt a plaintext string.
	 *
	 * @param string $plaintext Secret to protect.
	 * @return string Versioned, base64-encoded nonce + ciphertext.
	 */
	public function encrypt( string $plaintext ): string {
		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $this->key() );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext must be stored as text.
		return self::PAYLOAD_PREFIX . base64_encode( $nonce . $ciphertext );
	}

	/**
	 * Decrypt a payload produced by {@see Crypto::encrypt()}.
	 *
	 * @param string $payload Stored payload.
	 * @return string|null Plaintext, or null when the payload is malformed, tampered with or the salts changed.
	 */
	public function decrypt( string $payload ): ?string {
		if ( 0 !== strpos( $payload, self::PAYLOAD_PREFIX ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- See encrypt().
		$decoded = base64_decode( substr( $payload, strlen( self::PAYLOAD_PREFIX ) ), true );
		if ( false === $decoded || strlen( $decoded ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}

		$nonce      = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		try {
			$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $this->key() );
		} catch ( \SodiumException $exception ) {
			return null;
		}

		return false === $plaintext ? null : $plaintext;
	}

	/**
	 * Default key material: the site's logged-in key and salt.
	 *
	 * @return string
	 * @throws \RuntimeException When no salts are available (WordPress not loaded).
	 */
	public static function default_key_material(): string {
		if ( defined( 'LOGGED_IN_KEY' ) && defined( 'LOGGED_IN_SALT' ) ) {
			return (string) constant( 'LOGGED_IN_KEY' ) . (string) constant( 'LOGGED_IN_SALT' );
		}

		if ( function_exists( 'wp_salt' ) ) {
			return wp_salt( 'logged_in' );
		}

		throw new \RuntimeException( 'Pixalink Loyalty cannot derive an encryption key: LOGGED_IN_KEY and LOGGED_IN_SALT are not defined.' );
	}

	/**
	 * The 32-byte secretbox key.
	 *
	 * @return string
	 */
	private function key(): string {
		return hash( 'sha256', $this->key_material ?? self::default_key_material(), true );
	}
}
