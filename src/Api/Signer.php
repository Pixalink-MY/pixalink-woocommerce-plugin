<?php
/**
 * Request signing shared with the Pixalink Commerce API.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Api;

defined( 'ABSPATH' ) || exit;

/**
 * HMAC-SHA256 request signatures (scheme "v1").
 *
 * Contract (must match the server's CommerceEventSignature exactly):
 *
 * - signing key  = hash_hmac( 'sha256', 'pixalink-commerce-events-v1', hash( 'sha256', <api key part after the first "|"> ) )
 *                  as a 64-char lower-case hex string. The server derives the same value from the Sanctum token hash it
 *                  already stores, so nothing extra is persisted server-side and the key rotates with the API key.
 * - string       = "{timestamp}.{nonce}.{raw_body}"
 * - nonce        = the event id for /events*, the Idempotency-Key for other mutations, "-" for reads.
 *                  Event ids and idempotency keys must match {@see Signer::NONCE_PATTERN} (no "." ever).
 * - header       = X-Pixalink-Signature: v1={hex hmac_sha256( string, signing key )}
 * - window       = ±300 seconds around the server clock.
 *
 * Reads are bearer-only on the server; the plugin still signs them with the "-" nonce.
 */
final class Signer {

	public const KEY_CONTEXT    = 'pixalink-commerce-events-v1';
	public const SCHEME         = 'v1';
	public const READ_NONCE     = '-';
	public const WINDOW_SECONDS = 300;

	/**
	 * Format the server enforces for X-Pixalink-Event-Id and Idempotency-Key (UUID v4/v5 fit).
	 */
	public const NONCE_PATTERN = '/^[A-Za-z0-9-]{16,64}$/';

	/**
	 * Whether a string is acceptable as an event id or idempotency key.
	 *
	 * @param string $nonce Candidate.
	 * @return bool
	 */
	public static function is_valid_nonce( string $nonce ): bool {
		return 1 === preg_match( self::NONCE_PATTERN, $nonce );
	}

	/**
	 * The secret half of an API key: everything after the first "|" (the whole key when there is none).
	 *
	 * @param string $api_key Plain-text API key, e.g. "12|abc...".
	 * @return string
	 */
	public static function token_secret( string $api_key ): string {
		$parts = explode( '|', $api_key, 2 );

		return $parts[1] ?? $parts[0];
	}

	/**
	 * Derive the request signing key from the plain-text API key.
	 *
	 * @param string $api_key Plain-text API key.
	 * @return string 64-char lower-case hex.
	 */
	public static function derive_signing_key( string $api_key ): string {
		return hash_hmac( 'sha256', self::KEY_CONTEXT, hash( 'sha256', self::token_secret( $api_key ) ) );
	}

	/**
	 * The exact string that is signed.
	 *
	 * @param int    $timestamp Unix seconds sent in X-Pixalink-Timestamp.
	 * @param string $nonce     Event id, Idempotency-Key or "-".
	 * @param string $raw_body  Request body bytes exactly as sent ("" for none).
	 * @return string
	 */
	public static function string_to_sign( int $timestamp, string $nonce, string $raw_body ): string {
		return $timestamp . '.' . $nonce . '.' . $raw_body;
	}

	/**
	 * Hex HMAC-SHA256 signature.
	 *
	 * @param string $signing_key Derived signing key.
	 * @param int    $timestamp   Unix seconds.
	 * @param string $nonce       Nonce.
	 * @param string $raw_body    Raw body.
	 * @return string
	 */
	public static function sign( string $signing_key, int $timestamp, string $nonce, string $raw_body ): string {
		return hash_hmac( 'sha256', self::string_to_sign( $timestamp, $nonce, $raw_body ), $signing_key );
	}

	/**
	 * Value for the X-Pixalink-Signature header.
	 *
	 * @param string $signing_key Derived signing key.
	 * @param int    $timestamp   Unix seconds.
	 * @param string $nonce       Nonce.
	 * @param string $raw_body    Raw body.
	 * @return string "v1={hex}".
	 */
	public static function header_value( string $signing_key, int $timestamp, string $nonce, string $raw_body ): string {
		return self::SCHEME . '=' . self::sign( $signing_key, $timestamp, $nonce, $raw_body );
	}

	/**
	 * Verify a signature header (used for inbound webhooks and by the test fake server's contract test).
	 *
	 * @param string $signing_key  Derived signing key.
	 * @param string $header_value X-Pixalink-Signature value.
	 * @param int    $timestamp    X-Pixalink-Timestamp value.
	 * @param string $nonce        Nonce.
	 * @param string $raw_body     Raw body.
	 * @param int    $now          Current unix time.
	 * @param int    $window       Allowed skew in seconds.
	 * @return bool
	 */
	public static function verify( string $signing_key, string $header_value, int $timestamp, string $nonce, string $raw_body, int $now, int $window = self::WINDOW_SECONDS ): bool {
		if ( abs( $now - $timestamp ) > $window ) {
			return false;
		}

		$expected = self::header_value( $signing_key, $timestamp, $nonce, $raw_body );

		return hash_equals( $expected, trim( $header_value ) );
	}
}
