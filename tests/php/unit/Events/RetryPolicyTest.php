<?php
/**
 * Tests for RetryPolicy.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Events;

use Pixalink\Loyalty\Events\RetryPolicy;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * @covers \Pixalink\Loyalty\Events\RetryPolicy
 */
final class RetryPolicyTest extends TestCase {

	/**
	 * Policy whose jitter source always returns the given factor in [-1, 1].
	 *
	 * @param float $factor Jitter factor.
	 * @return RetryPolicy
	 */
	private function policy( float $factor = 0.0 ): RetryPolicy {
		return new RetryPolicy( static fn (): float => $factor );
	}

	/**
	 * Failed attempts so far → base delay before the next attempt.
	 *
	 * @return array<string,array{0:int,1:int}>
	 */
	public static function schedule(): array {
		return array(
			'after attempt 1'  => array( 1, 60 ),
			'after attempt 2'  => array( 2, 300 ),
			'after attempt 3'  => array( 3, 900 ),
			'after attempt 4'  => array( 4, 3600 ),
			'after attempt 5'  => array( 5, 10800 ),
			'after attempt 6'  => array( 6, 21600 ),
			'after attempt 7'  => array( 7, 43200 ),
			'after attempt 8'  => array( 8, 86400 ),
			'after attempt 9'  => array( 9, 86400 ),
			'after attempt 11' => array( 11, 86400 ),
		);
	}

	/**
	 * @dataProvider schedule
	 *
	 * @param int $attempts Failed attempts so far.
	 * @param int $expected Base delay in seconds.
	 */
	public function test_backoff_table( int $attempts, int $expected ): void {
		$this->assertSame( $expected, $this->policy()->base_delay( $attempts ) );
		$this->assertSame( $expected, $this->policy()->delay( $attempts ) );
	}

	public function test_jitter_stays_within_twenty_percent(): void {
		$this->assertSame( 48, $this->policy( -1.0 )->delay( 1 ) );
		$this->assertSame( 72, $this->policy( 1.0 )->delay( 1 ) );
		$this->assertSame( 69120, $this->policy( -1.0 )->delay( 8 ) );
		$this->assertSame( 103680, $this->policy( 1.0 )->delay( 8 ) );

		$random = new RetryPolicy();
		for ( $i = 0; $i < 200; $i++ ) {
			$delay = $random->delay( 4 );
			$this->assertGreaterThanOrEqual( 2880, $delay );
			$this->assertLessThanOrEqual( 4320, $delay );
		}
	}

	public function test_out_of_range_jitter_is_clamped(): void {
		$this->assertSame( 72, $this->policy( 5.0 )->delay( 1 ) );
		$this->assertSame( 48, $this->policy( -5.0 )->delay( 1 ) );
	}

	public function test_retry_after_is_honoured_when_longer_than_the_backoff(): void {
		$this->assertSame( 600, $this->policy()->delay( 1, 600 ) );
		$this->assertSame( 60, $this->policy()->delay( 1, 10 ), 'A shorter Retry-After never makes us retry sooner than the backoff.' );
		$this->assertSame( RetryPolicy::MAX_RETRY_AFTER, $this->policy()->delay( 2, 10 * 86400 ), 'Retry-After is capped at one day.' );
	}

	public function test_gives_up_after_twelve_attempts(): void {
		$policy = $this->policy();

		$this->assertSame( 12, RetryPolicy::MAX_ATTEMPTS );
		$this->assertFalse( $policy->should_give_up( 11 ) );
		$this->assertTrue( $policy->should_give_up( 12 ) );
		$this->assertTrue( $policy->should_give_up( 13 ) );
	}

	public function test_total_retry_window_is_about_six_days(): void {
		$total = 0;
		for ( $attempt = 1; $attempt < RetryPolicy::MAX_ATTEMPTS; $attempt++ ) {
			$total += $this->policy()->base_delay( $attempt );
		}

		$this->assertSame( 60 + 300 + 900 + 3600 + 10800 + 21600 + 43200 + 4 * 86400, $total );
	}
}
