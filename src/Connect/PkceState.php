<?php
/**
 * PKCE verifier and anti-CSRF state for the hosted connect flow.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Connect;

defined( 'ABSPATH' ) || exit;

/**
 * One pending hosted-connect attempt per admin user, kept in the transient `pixalink_loyalty_connect_{user_id}`.
 *
 * The transient holds `{state, verifier, exp, context}`; `exp` is checked as well because persistent object
 * caches may keep transients past their TTL. The S256 challenge follows RFC 7636 §4.2:
 * `BASE64URL( SHA256( ASCII( code_verifier ) ) )` without padding.
 */
final class PkceState {

	public const TRANSIENT_PREFIX = 'pixalink_loyalty_connect_';

	/**
	 * Lifetime of a pending attempt (15 minutes; the server's code TTL is 10).
	 */
	public const TTL_SECONDS = 900;

	/**
	 * Local time source (unix seconds).
	 *
	 * @var \Closure():int
	 */
	private \Closure $time_source;

	/**
	 * Random byte source (tests pin it).
	 *
	 * @var \Closure(int):string
	 */
	private \Closure $random_bytes;

	/**
	 * Constructor.
	 *
	 * @param \Closure|null $time_source  Returns the local unix time; defaults to time().
	 * @param \Closure|null $random_bytes Returns N random bytes; defaults to random_bytes().
	 */
	public function __construct( ?\Closure $time_source = null, ?\Closure $random_bytes = null ) {
		$this->time_source  = $time_source ?? static fn (): int => time();
		$this->random_bytes = $random_bytes ?? static fn ( int $length ): string => random_bytes( max( 1, $length ) );
	}

	/**
	 * Start an attempt for a user, replacing any earlier one.
	 *
	 * @param int                 $user_id Admin user id.
	 * @param array<string,mixed> $context Choices to carry to the callback (intent, pause_legacy, ...).
	 * @return array{state:string,verifier:string,challenge:string,exp:int}
	 */
	public function begin( int $user_id, array $context = array() ): array {
		$state    = self::base64url( ( $this->random_bytes )( 32 ) );
		$verifier = self::base64url( ( $this->random_bytes )( 32 ) );
		$exp      = ( $this->time_source )() + self::TTL_SECONDS;

		set_transient(
			self::transient_name( $user_id ),
			array(
				'state'    => $state,
				'verifier' => $verifier,
				'exp'      => $exp,
				'context'  => $context,
			),
			self::TTL_SECONDS
		);

		return array(
			'state'     => $state,
			'verifier'  => $verifier,
			'challenge' => self::challenge( $verifier ),
			'exp'       => $exp,
		);
	}

	/**
	 * Validate the state returned to the callback and consume the attempt.
	 *
	 * A mismatching state leaves the pending attempt untouched (a forged callback cannot cancel it);
	 * an expired attempt is deleted.
	 *
	 * @param int    $user_id Admin user id.
	 * @param string $state   State from the callback query.
	 * @return array{verifier:string,context:array<string,mixed>}|null Null when missing, expired or mismatched.
	 */
	public function consume( int $user_id, string $state ): ?array {
		$name    = self::transient_name( $user_id );
		$pending = get_transient( $name );
		if ( ! is_array( $pending ) || ! isset( $pending['state'], $pending['verifier'], $pending['exp'] ) || ! is_string( $pending['state'] ) || ! is_string( $pending['verifier'] ) ) {
			return null;
		}

		if ( (int) $pending['exp'] < ( $this->time_source )() ) {
			delete_transient( $name );
			return null;
		}

		if ( '' === $state || ! hash_equals( $pending['state'], $state ) ) {
			return null;
		}

		delete_transient( $name );

		$context = array();
		if ( isset( $pending['context'] ) && is_array( $pending['context'] ) ) {
			foreach ( $pending['context'] as $key => $value ) {
				$context[ (string) $key ] = $value;
			}
		}

		return array(
			'verifier' => $pending['verifier'],
			'context'  => $context,
		);
	}

	/**
	 * Whether a user has an unexpired attempt pending.
	 *
	 * @param int $user_id Admin user id.
	 * @return bool
	 */
	public function has_pending( int $user_id ): bool {
		$pending = get_transient( self::transient_name( $user_id ) );

		return is_array( $pending ) && isset( $pending['exp'] ) && (int) $pending['exp'] >= ( $this->time_source )();
	}

	/**
	 * RFC 7636 S256 code challenge.
	 *
	 * @param string $verifier Code verifier (43-128 unreserved characters).
	 * @return string
	 */
	public static function challenge( string $verifier ): string {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * Transient name for a user.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function transient_name( int $user_id ): string {
		return self::TRANSIENT_PREFIX . $user_id;
	}

	/**
	 * Unpadded base64url.
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	private static function base64url( string $bytes ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7636 requires base64url, not obfuscation.
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
