<?php
/**
 * WooCommerce logger wrapper that never writes secrets or full contact details.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Writes to WooCommerce › Status › Logs under the `pixalink-loyalty` source.
 *
 * Messages and context are masked before they reach the log: API keys, bearer tokens, signatures and
 * secrets are redacted; phone numbers and e-mail addresses keep only enough to correlate a support case.
 * Debug entries are written only while the "Debug log" setting is on; info and above always are.
 */
final class Logger {

	public const SOURCE = 'pixalink-loyalty';

	private const REDACTED = '[redacted]';

	/**
	 * Context keys whose values are always fully redacted (matched as substrings, case-insensitive).
	 */
	private const SECRET_KEY_FRAGMENTS = array( 'api_key', 'apikey', 'signing_key', 'secret', 'password', 'token', 'authorization', 'signature', 'verifier', 'otp', 'cookie' );

	/**
	 * Context keys whose values are redacted when they match exactly (case-insensitive).
	 */
	private const SECRET_KEYS = array( 'code', 'key' );

	/**
	 * Whether debug entries are written (the "Debug log" setting); null writes them always.
	 *
	 * @var \Closure():bool|null
	 */
	private ?\Closure $debug_enabled;

	/**
	 * Constructor.
	 *
	 * @param \Closure|null $debug_enabled Returns whether debug entries are written; null writes them always.
	 */
	public function __construct( ?\Closure $debug_enabled = null ) {
		$this->debug_enabled = $debug_enabled;
	}

	/**
	 * Log at debug level.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( 'debug', $message, $context );
	}

	/**
	 * Log at info level.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( 'info', $message, $context );
	}

	/**
	 * Log at warning level.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * Log at error level.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( 'error', $message, $context );
	}

	/**
	 * Log a masked entry.
	 *
	 * @param string              $level   PSR-3 level understood by WC_Logger.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		if ( 'debug' === $level && null !== $this->debug_enabled && true !== ( $this->debug_enabled )() ) {
			return;
		}

		$context           = self::mask_context( $context );
		$context['source'] = self::SOURCE;

		wc_get_logger()->log( $level, self::mask( $message ), $context );
	}

	/**
	 * Mask secrets, phone numbers and e-mail addresses inside free text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function mask( string $text ): string {
		$patterns = array(
			// Sanctum-style "id|secret" tokens.
			'/\b(\d+)\|[A-Za-z0-9]{8,}/' => '$1|****',
			// Bearer credentials in copied headers.
			'/(Bearer\s+)[^\s"\',;]+/i'  => '$1****',
			// Request signatures.
			'/\bv1=[0-9a-f]{16,}/i'      => 'v1=****',
			// Derived signing keys and other 64-hex secrets.
			'/\b[0-9a-f]{64}\b/i'        => '****',
		);

		foreach ( $patterns as $pattern => $replacement ) {
			$text = (string) preg_replace( $pattern, $replacement, $text );
		}

		$text = (string) preg_replace_callback(
			'/(?<![\w.])\+\d[\d\s\-]{6,}\d/',
			static fn ( array $matches ): string => self::mask_phone( $matches[0] ),
			$text
		);

		return (string) preg_replace_callback(
			'/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
			static fn ( array $matches ): string => self::mask_email( $matches[0] ),
			$text
		);
	}

	/**
	 * Mask a context array recursively by key and by value.
	 *
	 * @param array<int|string,mixed> $context Context.
	 * @return array<int|string,mixed>
	 */
	public static function mask_context( array $context ): array {
		$masked = array();

		foreach ( $context as $key => $value ) {
			$normalised_key = strtolower( (string) $key );

			if ( self::is_secret_key( $normalised_key ) ) {
				$masked[ $key ] = self::REDACTED;
				continue;
			}

			if ( is_array( $value ) ) {
				$masked[ $key ] = self::mask_context( $value );
			} elseif ( is_string( $value ) && str_contains( $normalised_key, 'phone' ) ) {
				$masked[ $key ] = self::mask_phone( $value );
			} elseif ( is_string( $value ) && str_contains( $normalised_key, 'email' ) ) {
				$masked[ $key ] = self::mask_email( $value );
			} elseif ( is_string( $value ) ) {
				$masked[ $key ] = self::mask( $value );
			} else {
				$masked[ $key ] = $value;
			}
		}

		return $masked;
	}

	/**
	 * Keep the leading "+" and the last four digits of a phone number.
	 *
	 * @param string $phone Phone number in any format.
	 * @return string For example "+*********4567".
	 */
	public static function mask_phone( string $phone ): string {
		$digits = (string) preg_replace( '/\D/', '', $phone );
		if ( strlen( $digits ) <= 4 ) {
			return str_repeat( '*', strlen( $digits ) );
		}

		$prefix = 0 === strpos( ltrim( $phone ), '+' ) ? '+' : '';

		return $prefix . str_repeat( '*', strlen( $digits ) - 4 ) . substr( $digits, -4 );
	}

	/**
	 * Keep the first character of the local part and the domain of an e-mail address.
	 *
	 * @param string $email E-mail address.
	 * @return string For example "j***@example.com".
	 */
	public static function mask_email( string $email ): string {
		$at = strrpos( $email, '@' );
		if ( false === $at || 0 === $at ) {
			return self::REDACTED;
		}

		return substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}

	/**
	 * Whether a context key names a secret.
	 *
	 * @param string $key Lower-cased key.
	 * @return bool
	 */
	private static function is_secret_key( string $key ): bool {
		if ( in_array( $key, self::SECRET_KEYS, true ) ) {
			return true;
		}

		foreach ( self::SECRET_KEY_FRAGMENTS as $fragment ) {
			if ( str_contains( $key, $fragment ) ) {
				return true;
			}
		}

		return false;
	}
}
