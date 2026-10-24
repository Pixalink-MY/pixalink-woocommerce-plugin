<?php
/**
 * Dependency-free RFC 4122 UUID v4 and v5.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Support;

defined( 'ABSPATH' ) || exit;

/**
 * RFC 4122 UUID helpers.
 *
 * Version 5 ids are deterministic: the same namespace + name always yields the same id, which is what makes
 * outbox event ids stable across retries and re-enqueues.
 */
final class Uuid {

	/**
	 * RFC 4122 Appendix C name-space for fully-qualified domain names.
	 */
	public const NAMESPACE_DNS = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

	/**
	 * RFC 4122 Appendix C name-space for URLs.
	 */
	public const NAMESPACE_URL = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';

	/**
	 * Name-space for commerce event ids: v5( NAMESPACE_URL, 'https://pixalink.io/ns/commerce-events/v1' ).
	 *
	 * Never change this value: event ids derived from it are the server's exactly-once key.
	 */
	public const EVENT_NAMESPACE = '3c60f03a-216c-59df-9061-9ab1ffa29b5d';

	/**
	 * The name hashed into {@see Uuid::EVENT_NAMESPACE}.
	 */
	public const EVENT_NAMESPACE_NAME = 'https://pixalink.io/ns/commerce-events/v1';

	private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * Random (version 4) UUID.
	 *
	 * @return string
	 */
	public static function v4(): string {
		return self::format( self::stamp( random_bytes( 16 ), 4 ) );
	}

	/**
	 * Name-based SHA-1 (version 5) UUID.
	 *
	 * @param string $name_space Name-space UUID.
	 * @param string $name       Name within the name-space.
	 * @return string
	 */
	public static function v5( string $name_space, string $name ): string {
		$hash = sha1( self::to_bytes( $name_space ) . $name, true );

		return self::format( self::stamp( substr( $hash, 0, 16 ), 5 ) );
	}

	/**
	 * Deterministic event id for a commerce event.
	 *
	 * @param string $name Stable, unique description of the event (for example "site|order.paid|123|1").
	 * @return string
	 */
	public static function event_id( string $name ): string {
		return self::v5( self::EVENT_NAMESPACE, $name );
	}

	/**
	 * Whether a string is a canonical UUID.
	 *
	 * @param string $uuid Candidate.
	 * @return bool
	 */
	public static function is_valid( string $uuid ): bool {
		return 1 === preg_match( self::PATTERN, $uuid );
	}

	/**
	 * Set the version and RFC 4122 variant bits.
	 *
	 * @param string $bytes   16 raw bytes.
	 * @param int    $version UUID version.
	 * @return string
	 */
	private static function stamp( string $bytes, int $version ): string {
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | ( $version << 4 ) );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

		return $bytes;
	}

	/**
	 * Raw bytes of a canonical UUID.
	 *
	 * @param string $uuid Canonical UUID.
	 * @return string
	 * @throws \InvalidArgumentException When the UUID is malformed.
	 */
	private static function to_bytes( string $uuid ): string {
		if ( ! self::is_valid( $uuid ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid UUID name-space "%s".', esc_html( $uuid ) ) );
		}

		return (string) hex2bin( str_replace( '-', '', $uuid ) );
	}

	/**
	 * Canonical 8-4-4-4-12 representation.
	 *
	 * @param string $bytes 16 raw bytes.
	 * @return string
	 */
	private static function format( string $bytes ): string {
		$hex = bin2hex( $bytes );

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 12, 4 ),
			substr( $hex, 16, 4 ),
			substr( $hex, 20, 12 )
		);
	}
}
