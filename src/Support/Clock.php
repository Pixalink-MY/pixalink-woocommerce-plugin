<?php
/**
 * Server-aligned clock for request signing.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Local time corrected by a stored offset to Pixalink's clock.
 *
 * Shared hosting clocks drift; the API rejects signatures outside a ±300 s window with 401 `timestamp_skew`
 * and reports its own time. The client stores the difference here and retries.
 */
final class Clock {

	/**
	 * Largest offset accepted from the server (one day); anything larger is treated as bogus input.
	 */
	public const MAX_OFFSET = 86400;

	/**
	 * Option storage.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Local time source (unix seconds).
	 *
	 * @var \Closure():int
	 */
	private \Closure $time_source;

	/**
	 * Constructor.
	 *
	 * @param Options       $options     Option storage.
	 * @param \Closure|null $time_source Returns the local unix time; defaults to time().
	 */
	public function __construct( Options $options, ?\Closure $time_source = null ) {
		$this->options     = $options;
		$this->time_source = $time_source ?? static fn (): int => time();
	}

	/**
	 * Current time on Pixalink's clock (unix seconds).
	 *
	 * @return int
	 */
	public function now(): int {
		return $this->local_now() + $this->offset();
	}

	/**
	 * Current local time (unix seconds), uncorrected.
	 *
	 * @return int
	 */
	public function local_now(): int {
		return ( $this->time_source )();
	}

	/**
	 * Stored offset in seconds (server minus local).
	 *
	 * @return int
	 */
	public function offset(): int {
		return self::clamp( $this->options->get_int( Options::CLOCK_OFFSET, 0 ) );
	}

	/**
	 * Persist a new offset.
	 *
	 * @param int $seconds Server minus local, in seconds.
	 * @return int The offset actually stored after clamping.
	 */
	public function set_offset( int $seconds ): int {
		$seconds = self::clamp( $seconds );
		$this->options->set( Options::CLOCK_OFFSET, $seconds );

		return $seconds;
	}

	/**
	 * Align to a server timestamp.
	 *
	 * @param int $server_time Server unix time.
	 * @return int The stored offset.
	 */
	public function sync_to( int $server_time ): int {
		return $this->set_offset( $server_time - $this->local_now() );
	}

	/**
	 * Current Pixalink time as ISO 8601 UTC ("2026-10-24T08:00:00Z").
	 *
	 * @return string
	 */
	public function now_iso8601(): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', $this->now() );
	}

	/**
	 * Parse a server time value: unix seconds (int or numeric string), ISO 8601 or an RFC 7231 HTTP date.
	 *
	 * @param mixed $value Raw value from a response body or Date header.
	 * @return int|null
	 */
	public static function parse_server_time( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		$value = trim( $value );
		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}

		$timestamp = strtotime( $value );

		return false === $timestamp ? null : $timestamp;
	}

	/**
	 * Keep an offset within ±MAX_OFFSET.
	 *
	 * @param int $seconds Offset.
	 * @return int
	 */
	private static function clamp( int $seconds ): int {
		return max( -self::MAX_OFFSET, min( self::MAX_OFFSET, $seconds ) );
	}
}
