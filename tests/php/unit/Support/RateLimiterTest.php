<?php
/**
 * Unit tests for RateLimiter.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Support;

use Pixalink\Loyalty\Support\RateLimiter;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * Fixed windows per action and subject.
 *
 * @covers \Pixalink\Loyalty\Support\RateLimiter
 */
final class RateLimiterTest extends TestCase {

	public function test_hits_are_allowed_up_to_the_limit_then_refused_until_the_window_resets(): void {
		$now     = 1761300000;
		$limiter = new RateLimiter( static function () use ( &$now ): int {
			return $now;
		} );

		$this->assertNull( $limiter->hit( 'otp_send', 'user:17', 2, 900 ) );
		$this->assertNull( $limiter->hit( 'otp_send', 'user:17', 2, 900 ) );
		$this->assertSame( 900, $limiter->hit( 'otp_send', 'user:17', 2, 900 ) );

		$now += 600;
		$this->assertSame( 300, $limiter->hit( 'otp_send', 'user:17', 2, 900 ), 'Refusals do not extend the window.' );

		$now += 300;
		$this->assertNull( $limiter->hit( 'otp_send', 'user:17', 2, 900 ), 'A new window starts.' );
	}

	public function test_actions_and_subjects_are_counted_separately(): void {
		$limiter = new RateLimiter( static fn (): int => 1761300000 );

		$this->assertNull( $limiter->hit( 'otp_send', 'user:17', 1, 60 ) );
		$this->assertNotNull( $limiter->hit( 'otp_send', 'user:17', 1, 60 ) );
		$this->assertNull( $limiter->hit( 'otp_send', 'user:18', 1, 60 ) );
		$this->assertNull( $limiter->hit( 'otp_verify', 'user:17', 1, 60 ) );
		$this->assertSame( 60, $this->transients[ RateLimiter::key( 'otp_send', 'user:17' ) ][1] );
	}
}
