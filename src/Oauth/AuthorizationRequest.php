<?php
/**
 * One sign-in through the member portal over OAuth, kept in the shopper's browser.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Oauth;

use Pixalink\Loyalty\Connect\PkceState;
use Pixalink\Loyalty\Identity\SignInState;
use Pixalink\Loyalty\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * {@see AuthorizationRequest::start()} generates a state and a PKCE verifier (each 32 random bytes, base64url), keeps
 * them with the purpose, the page to come back to and the account that started it in the `pixalink_loyalty_oauth`
 * cookie, encrypted with {@see Crypto} (HttpOnly, SameSite=Lax so it comes back on the portal's top-level redirect,
 * Secure on https, thirty minutes: a new member registers on the portal first), and returns the authorize URL with the state and the S256 challenge. A cookie rather
 * than a server-side record, so it works for shoppers who are not logged in and costs nothing until they come back.
 *
 * {@see AuthorizationRequest::take()} reads it once: the cookie is expired whatever it held, and only a value this site
 * encrypted, untampered and within its thirty minutes, comes back. When nothing does, {@see AuthorizationRequest::failure()}
 * says why, for the log.
 */
final class AuthorizationRequest {

	public const COOKIE = 'pixalink_loyalty_oauth';

	/**
	 * Seconds the request lives: the shopper may have to sign in, or register on the portal first (phone number, the
	 * WhatsApp confirmation, then the profile form), which took more than ten minutes in practice.
	 */
	public const TTL = 1800;

	/**
	 * {@see AuthorizationRequest::failure()}: no cookie came back (never started in this browser, cleared, or expired by
	 * the browser itself, which is how a request older than {@see AuthorizationRequest::TTL} usually arrives).
	 */
	public const FAILURE_MISSING = 'request_missing';

	/**
	 * {@see AuthorizationRequest::failure()}: a cookie this site did not write, or one that was changed.
	 */
	public const FAILURE_UNREADABLE = 'request_unreadable';

	/**
	 * {@see AuthorizationRequest::failure()}: the browser kept the cookie past its expiry.
	 */
	public const FAILURE_EXPIRED = 'request_expired';

	/**
	 * A logged-out shopper signing in ("Sign in from Customer Portal").
	 */
	public const PURPOSE_SIGN_IN = 'sign_in';

	/**
	 * A logged-in shopper linking their membership ("Verify with WhatsApp").
	 */
	public const PURPOSE_VERIFY = 'verify';

	/**
	 * Encrypts the cookie.
	 *
	 * @var Crypto
	 */
	private Crypto $crypto;

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
	 * Why the last {@see AuthorizationRequest::take()} returned nothing (FAILURE_*), null when it returned the request.
	 *
	 * @var string|null
	 */
	private ?string $failure = null;

	/**
	 * Constructor.
	 *
	 * @param Crypto        $crypto     Encrypts the cookie.
	 * @param \Closure|null $set_cookie Sets a cookie (default: setcookie() while headers can still be sent).
	 * @param \Closure|null $now        Unix time source (default: time()).
	 */
	public function __construct( Crypto $crypto, ?\Closure $set_cookie = null, ?\Closure $now = null ) {
		$this->crypto     = $crypto;
		$this->set_cookie = $set_cookie ?? static function ( string $name, string $value, array $options ): void {
			if ( ! headers_sent() ) {
				setcookie( $name, $value, $options );
			}
		};
		$this->now        = $now ?? static fn (): int => time();
	}

	/**
	 * Keep a new request in the cookie and build the authorize URL.
	 *
	 * @param Capability $capability   What the heartbeat advertised.
	 * @param string     $client_id    The store's OAuth client id.
	 * @param string     $redirect_uri The store's callback ({@see ClientCredentials::redirect_uri()}).
	 * @param string     $purpose      PURPOSE_SIGN_IN or PURPOSE_VERIFY.
	 * @param string     $return_url   Absolute page of this store to land on afterwards.
	 * @param int        $user_id      The account that started it (0 when nobody is logged in).
	 * @return string The authorize URL.
	 */
	public function start( Capability $capability, string $client_id, string $redirect_uri, string $purpose, string $return_url, int $user_id ): string {
		$state    = SignInState::generate();
		$verifier = SignInState::generate();
		$expires  = ( $this->now )() + self::TTL;

		$payload = wp_json_encode(
			array(
				'state'    => $state,
				'verifier' => $verifier,
				'purpose'  => $purpose,
				'return'   => $return_url,
				'user_id'  => $user_id,
				'expires'  => $expires,
			)
		);
		( $this->set_cookie )( self::COOKIE, $this->crypto->encrypt( (string) $payload ), $this->options( $expires ) );

		return self::authorize_url( $capability->authorization_endpoint, $client_id, $redirect_uri, $state, PkceState::challenge( $verifier ) );
	}

	/**
	 * The authorize URL (oauth-authorize.query.json).
	 *
	 * @param string $endpoint     The heartbeat's `oauth.authorization_endpoint`.
	 * @param string $client_id    Client id.
	 * @param string $redirect_uri Callback.
	 * @param string $state        State kept in the cookie.
	 * @param string $challenge    S256 challenge of the kept verifier.
	 * @return string
	 */
	public static function authorize_url( string $endpoint, string $client_id, string $redirect_uri, string $state, string $challenge ): string {
		$query = http_build_query(
			array(
				'response_type'         => 'code',
				'client_id'             => $client_id,
				'redirect_uri'          => $redirect_uri,
				'scope'                 => Capability::SCOPE,
				'state'                 => $state,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		return $endpoint . ( false === strpos( $endpoint, '?' ) ? '?' : '&' ) . $query;
	}

	/**
	 * The request in the cookie, once.
	 *
	 * @return array{state:string,verifier:string,purpose:string,return:string,user_id:int}|null Null when there is no
	 *         cookie, or it is not one this site wrote, was changed or has expired.
	 */
	public function take(): ?array {
		$this->failure = self::FAILURE_MISSING;
		if ( ! isset( $_COOKIE[ self::COOKIE ] ) ) {
			return null;
		}

		$this->failure = self::FAILURE_UNREADABLE;

		$value = $_COOKIE[ self::COOKIE ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Unslashed below, then authenticated by Crypto; never printed.
		unset( $_COOKIE[ self::COOKIE ] );
		( $this->set_cookie )( self::COOKIE, '', $this->options( ( $this->now )() - self::TTL ) );

		$plaintext = is_string( $value ) ? $this->crypto->decrypt( wp_unslash( $value ) ) : null;
		$request   = null === $plaintext ? null : json_decode( $plaintext, true );
		if ( ! is_array( $request ) ) {
			return null;
		}

		foreach ( array( 'state', 'verifier', 'purpose', 'return' ) as $key ) {
			if ( ! isset( $request[ $key ] ) || ! is_string( $request[ $key ] ) || '' === $request[ $key ] ) {
				return null;
			}
		}

		if ( ! isset( $request['user_id'], $request['expires'] ) || ! is_int( $request['user_id'] ) || ! is_int( $request['expires'] ) ) {
			return null;
		}

		if ( $request['expires'] < ( $this->now )() ) {
			$this->failure = self::FAILURE_EXPIRED;

			return null;
		}

		$this->failure = null;

		return array(
			'state'    => $request['state'],
			'verifier' => $request['verifier'],
			'purpose'  => $request['purpose'],
			'return'   => $request['return'],
			'user_id'  => $request['user_id'],
		);
	}

	/**
	 * Why the last {@see AuthorizationRequest::take()} returned nothing.
	 *
	 * @return string|null FAILURE_*, or null when it returned the request (or was not called).
	 */
	public function failure(): ?string {
		return $this->failure;
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
