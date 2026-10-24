<?php
/**
 * Tests for Clock.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Support;

use Pixalink\Loyalty\Support\Clock;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * @covers \Pixalink\Loyalty\Support\Clock
 */
final class ClockTest extends TestCase {

	private const LOCAL_NOW = 1761300000;

	private function clock(): Clock {
		return new Clock( new Options(), static fn (): int => self::LOCAL_NOW );
	}

	public function test_without_an_offset_now_is_local_time(): void {
		$this->assertSame( self::LOCAL_NOW, $this->clock()->now() );
		$this->assertSame( 0, $this->clock()->offset() );
	}

	public function test_stored_offset_is_applied(): void {
		$this->options[ Options::CLOCK_OFFSET ] = array( -420, true );

		$this->assertSame( self::LOCAL_NOW - 420, $this->clock()->now() );
	}

	public function test_sync_to_server_time_persists_the_offset_with_autoload(): void {
		$clock = $this->clock();

		$offset = $clock->sync_to( self::LOCAL_NOW + 905 );

		$this->assertSame( 905, $offset );
		$this->assertSame( array( 905, true ), $this->options[ Options::CLOCK_OFFSET ] );
		$this->assertSame( self::LOCAL_NOW + 905, $clock->now() );
		$this->assertSame( self::LOCAL_NOW, $clock->local_now() );
	}

	public function test_offsets_are_clamped_to_one_day(): void {
		$clock = $this->clock();

		$this->assertSame( Clock::MAX_OFFSET, $clock->set_offset( 10 * Clock::MAX_OFFSET ) );
		$this->assertSame( -Clock::MAX_OFFSET, $clock->set_offset( -10 * Clock::MAX_OFFSET ) );
	}

	public function test_iso8601_uses_server_time_in_utc(): void {
		$this->options[ Options::CLOCK_OFFSET ] = array( 60, true );

		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', self::LOCAL_NOW + 60 ), $this->clock()->now_iso8601() );
	}

	/**
	 * @dataProvider server_time_cases
	 *
	 * @param mixed    $value    Raw value.
	 * @param int|null $expected Parsed unix time.
	 */
	public function test_parse_server_time( $value, ?int $expected ): void {
		$this->assertSame( $expected, Clock::parse_server_time( $value ) );
	}

	/**
	 * @return array<string,array{0:mixed,1:int|null}>
	 */
	public static function server_time_cases(): array {
		return array(
			'unix int'        => array( 1761300000, 1761300000 ),
			'numeric string'  => array( '1761300000', 1761300000 ),
			'iso 8601'        => array( '2025-10-24T10:00:00Z', 1761300000 ),
			'rfc 7231 date'   => array( 'Fri, 24 Oct 2025 10:00:00 GMT', 1761300000 ),
			'zero'            => array( 0, null ),
			'empty'           => array( '', null ),
			'garbage'         => array( 'soon', null ),
			'not scalar'      => array( array( 1 ), null ),
		);
	}
}
