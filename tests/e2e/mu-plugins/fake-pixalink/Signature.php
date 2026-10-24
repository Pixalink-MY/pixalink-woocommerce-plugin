<?php
/**
 * Fake Pixalink server: request authentication.
 *
 * @package Pixalink\Loyalty\Tests
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Tests\FakeServer;

/**
 * Verifies bearer + v1 signatures the way the Pixalink server does.
 *
 * Deliberately written without the plugin's Signer class so the contract suite compares two
 * independent implementations. Stricter than production in one respect: reads are verified too
 * (with the "-" nonce), because the plugin always signs them.
 */
final class Signature {

	/**
	 * The key connect/exchange hands out and the only key the fake server accepts by default.
	 */
	public const FAKE_API_KEY = '1|abcdefghijklmnopqrstuvwxyz0123456789ABCD';

	public const KEY_CONTEXT    = 'pixalink-commerce-events-v1';
	public const WINDOW_SECONDS = 300;
	public const NONCE_PATTERN  = '/^[A-Za-z0-9-]{16,64}$/';

	/**
	 * Derive the signing key from a bearer token.
	 *
	 * @param string $bearer Plain-text API key.
	 * @return string
	 */
	public static function signing_key( string $bearer ): string {
		$separator = strpos( $bearer, '|' );
		$secret    = false === $separator ? $bearer : substr( $bearer, $separator + 1 );

		return hash_hmac( 'sha256', self::KEY_CONTEXT, hash( 'sha256', $secret ) );
	}

	/**
	 * Check one request.
	 *
	 * @param string               $method       HTTP method.
	 * @param string               $path         Path relative to /api/commerce/v1, e.g. "events".
	 * @param array<string,string> $headers      Request headers keyed by lower-case, dash-separated name.
	 * @param string               $raw_body     Raw request body.
	 * @param int                  $now          Server unix time.
	 * @param string               $expected_key API key the server knows.
	 * @return array{0:string,1:int}|null Null when valid, otherwise [error code, HTTP status].
	 */
	public static function check( string $method, string $path, array $headers, string $raw_body, int $now, string $expected_key ): ?array {
		$authorization = $headers['authorization'] ?? '';
		if ( 1 !== preg_match( '/^Bearer\s+(\S+)$/', $authorization, $matches ) || ! hash_equals( $expected_key, $matches[1] ) ) {
			return array( 'unauthenticated', 401 );
		}

		$timestamp = $headers['x-pixalink-timestamp'] ?? '';
		if ( '' === $timestamp || ! ctype_digit( $timestamp ) ) {
			return array( 'signature_invalid', 401 );
		}

		if ( abs( $now - (int) $timestamp ) > self::WINDOW_SECONDS ) {
			return array( 'timestamp_skew', 401 );
		}

		$nonce = self::nonce( strtoupper( $method ), $path, $headers );
		if ( null === $nonce ) {
			return array( 'signature_invalid', 401 );
		}

		$signature = $headers['x-pixalink-signature'] ?? '';
		if ( 0 === strpos( $signature, 'v1=' ) ) {
			$signature = substr( $signature, 3 );
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $raw_body, self::signing_key( $matches[1] ) );

		return hash_equals( $expected, $signature ) ? null : array( 'signature_invalid', 401 );
	}

	/**
	 * Nonce for a request, or null when the required header is missing or malformed.
	 *
	 * @param string               $method  Upper-case HTTP method.
	 * @param string               $path    Relative path.
	 * @param array<string,string> $headers Headers.
	 * @return string|null
	 */
	private static function nonce( string $method, string $path, array $headers ): ?string {
		if ( 'events' === $path || 0 === strpos( $path, 'events/' ) ) {
			$nonce = $headers['x-pixalink-event-id'] ?? '';
		} elseif ( in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return '-';
		} else {
			$nonce = $headers['idempotency-key'] ?? '';
		}

		return 1 === preg_match( self::NONCE_PATTERN, $nonce ) ? $nonce : null;
	}
}
