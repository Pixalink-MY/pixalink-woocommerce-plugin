<?php
/**
 * The check value of "Sign in with the member portal".
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * A random value kept in the shopper's browser while they sign in on the Pixalink member portal (login-CSRF defence).
 *
 * {@see PortalSignIn} issues it when the shopper presses the button: 32 random bytes as unpadded base64url (43
 * characters), sent to the portal as `state` and kept in the `pixalink_loyalty_sso_state` cookie (HttpOnly,
 * SameSite=Lax so it comes back on the portal's top-level redirect, Secure on https, ten minutes). Pixalink binds the
 * ticket it mints to the value; {@see SsoLanding} takes the cookie when the ticket arrives and sends it back as
 * `sso_state`, so a ticket only works in the browser that pressed the button.
 */
final class SignInState {

	public const COOKIE = 'pixalink_loyalty_sso_state';

	/**
	 * Seconds the cookie lives (the shopper may have to sign in or register on the portal first).
	 */
	public const TTL = 600;

	/**
	 * Shape of a state the contract accepts (`sso_state` in customers-create.request.json).
	 */
	public const PATTERN = '/^[A-Za-z0-9_-]{32,128}$/D';

	/**
	 * Random bytes per state.
	 */
	private const BYTES = 32;

	/**
	 * Sets a cookie: (name, value, setcookie() options).
	 *
	 * @var \Closure(string,string,array<string,mixed>):void
	 */
	private \Closure $set_cookie;

	/**
	 * Current unix time.
	 *
	 * @var \Closure():int
	 */
	private \Closure $now;

	/**
	 * Constructor.
	 *
	 * @param \Closure|null $set_cookie Sets a cookie (default: setcookie() while headers can still be sent).
	 * @param \Closure|null $now        Unix time source (default: time()).
	 */
	public function __construct( ?\Closure $set_cookie = null, ?\Closure $now = null ) {
		$this->set_cookie = $set_cookie ?? static function ( string $name, string $value, array $options ): void {
			if ( ! headers_sent() ) {
				setcookie( $name, $value, $options );
			}
		};
		$this->now        = $now ?? static fn (): int => time();
	}

	/**
	 * A new state: 32 random bytes, unpadded base64url (43 URL-safe characters).
	 *
	 * @return string
	 */
	public static function generate(): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url of random bytes, not obfuscation.
		return rtrim( strtr( base64_encode( random_bytes( self::BYTES ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Generate a state and keep it in the cookie.
	 *
	 * @return string The state, for the portal's `state` parameter.
	 */
	public function issue(): string {
		$state = self::generate();
		( $this->set_cookie )( self::COOKIE, $state, $this->options( ( $this->now )() + self::TTL ) );

		return $state;
	}

	/**
	 * The state in the cookie, once: the cookie is expired whatever it held, so a ticket gets one try.
	 *
	 * @return string|null Null when there is no cookie or it is not a state this plugin issued.
	 */
	public function take(): ?string {
		if ( ! isset( $_COOKIE[ self::COOKIE ] ) ) {
			return null;
		}

		$value = $_COOKIE[ self::COOKIE ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Compared with PATTERN below, never stored or printed.
		unset( $_COOKIE[ self::COOKIE ] );
		( $this->set_cookie )( self::COOKIE, '', $this->options( ( $this->now )() - self::TTL ) );

		return is_string( $value ) && 1 === preg_match( self::PATTERN, $value ) ? $value : null;
	}

	/**
	 * Cookie attributes, as WordPress sets its own front-end cookies.
	 *
	 * @param int $expires Unix time.
	 * @return array{expires:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string}
	 */
	private function options( int $expires ): array {
		return array(
			'expires'  => $expires,
			'path'     => '' !== COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '', // WordPress defines it false for a host-only cookie.
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
	}
}
