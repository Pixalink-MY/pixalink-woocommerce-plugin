<?php
/**
 * Outbox backoff schedule.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Exponential-ish backoff: 1 m, 5 m, 15 m, 1 h, 3 h, 6 h, 12 h, then 24 h, ±20 % jitter, 12 attempts then dead.
 *
 * A `Retry-After` from the server is honoured as a floor: the next attempt never happens sooner than the server
 * asked, nor sooner than the backoff (so a throttled burst does not collapse into one retry storm).
 */
final class RetryPolicy {

	/**
	 * Attempts after which an event is marked dead.
	 */
	public const MAX_ATTEMPTS = 12;

	/**
	 * Base delay in seconds after the n-th failed attempt (the last entry repeats).
	 */
	public const SCHEDULE = array( 60, 300, 900, 3600, 10800, 21600, 43200, 86400 );

	/**
	 * Jitter as a fraction of the base delay.
	 */
	public const JITTER = 0.2;

	/**
	 * Longest server `Retry-After` honoured (a misbehaving server must not park an event for weeks).
	 */
	public const MAX_RETRY_AFTER = 86400;

	/**
	 * Returns a jitter factor in [-1, 1].
	 *
	 * @var \Closure():float
	 */
	private \Closure $random;

	/**
	 * Constructor.
	 *
	 * @param \Closure|null $random Jitter source returning a float in [-1, 1] (tests); defaults to random_int().
	 */
	public function __construct( ?\Closure $random = null ) {
		$this->random = $random ?? static fn (): float => random_int( -1000, 1000 ) / 1000;
	}

	/**
	 * Whether to stop retrying.
	 *
	 * @param int $attempts Attempts made so far.
	 * @return bool
	 */
	public function should_give_up( int $attempts ): bool {
		return $attempts >= self::MAX_ATTEMPTS;
	}

	/**
	 * Delay before the next attempt, without jitter.
	 *
	 * @param int $attempts Failed attempts so far (1 after the first failure).
	 * @return int Seconds.
	 */
	public function base_delay( int $attempts ): int {
		$index = max( 0, min( $attempts - 1, count( self::SCHEDULE ) - 1 ) );

		return self::SCHEDULE[ $index ];
	}

	/**
	 * Delay before the next attempt.
	 *
	 * @param int      $attempts    Failed attempts so far (1 after the first failure).
	 * @param int|null $retry_after Seconds from the server's Retry-After header.
	 * @return int Seconds.
	 */
	public function delay( int $attempts, ?int $retry_after = null ): int {
		$base   = $this->base_delay( $attempts );
		$factor = max( -1.0, min( 1.0, ( $this->random )() ) );
		$delay  = (int) round( $base * ( 1 + $factor * self::JITTER ) );

		if ( null !== $retry_after && $retry_after > $delay ) {
			$delay = min( $retry_after, max( $delay, self::MAX_RETRY_AFTER ) );
		}

		return max( 1, $delay );
	}
}
