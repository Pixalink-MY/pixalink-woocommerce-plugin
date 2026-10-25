<?php
/**
 * Fixed-window rate limits in transients.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Counts hits per action and subject (`user:17`) in a transient that expires with its window.
 *
 * Not a precise limiter (concurrent requests can both pass the last free slot), but enough to stop a shopper's
 * browser, or a script using their cookies, from flooding Pixalink with OTP requests.
 */
final class RateLimiter {

	public const PREFIX = 'pixalink_loyalty_rl_';

	/**
	 * Current unix time.
	 *
	 * @var \Closure():int
	 */
	private \Closure $now;

	/**
	 * Constructor.
	 *
	 * @param \Closure|null $now Unix time source (tests); defaults to time().
	 */
	public function __construct( ?\Closure $now = null ) {
		$this->now = $now ?? static fn (): int => time();
	}

	/**
	 * Count one hit.
	 *
	 * @param string $action  Action name, e.g. "otp_send".
	 * @param string $subject Who is acting, e.g. "user:17".
	 * @param int    $limit   Hits allowed per window.
	 * @param int    $window  Window length in seconds.
	 * @return int|null Null when allowed; otherwise seconds until the window resets.
	 */
	public function hit( string $action, string $subject, int $limit, int $window ): ?int {
		$key   = self::key( $action, $subject );
		$now   = ( $this->now )();
		$state = get_transient( $key );

		if ( ! is_array( $state ) || ! isset( $state['count'], $state['reset_at'] ) || ! is_int( $state['count'] ) || ! is_int( $state['reset_at'] ) || $state['reset_at'] <= $now ) {
			$state = array(
				'count'    => 0,
				'reset_at' => $now + $window,
			);
		}

		// Unix times are far from PHP_INT_MAX, so the difference is always an integer.
		$remaining = max( 1, (int) ( $state['reset_at'] - $now ) );
		if ( $state['count'] >= $limit ) {
			return $remaining;
		}

		++$state['count'];
		set_transient( $key, $state, $remaining );

		return null;
	}

	/**
	 * Transient name.
	 *
	 * @param string $action  Action.
	 * @param string $subject Subject.
	 * @return string
	 */
	public static function key( string $action, string $subject ): string {
		return self::PREFIX . md5( $action . '|' . $subject );
	}
}
