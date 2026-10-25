<?php
/**
 * One outbox row.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable snapshot of a `{prefix}pixalink_events` row. Times are UTC `Y-m-d H:i:s` strings, as stored.
 */
final class Event {

	public const OBJECT_ORDER    = 'order';
	public const OBJECT_CUSTOMER = 'customer';
	public const OBJECT_NONE     = '';

	/**
	 * Envelope schema version sent as `version`.
	 */
	public const ENVELOPE_VERSION = 1;

	/**
	 * Constructor.
	 *
	 * @param int                 $id               Row id.
	 * @param string              $uuid             Event id (also X-Pixalink-Event-Id).
	 * @param EventType           $type             Event type.
	 * @param string              $object_type      One of the OBJECT_* constants.
	 * @param int                 $object_id        Order or user id (0 when none).
	 * @param int                 $sequence         Per-order sequence (0 when no order).
	 * @param array<mixed>        $payload          Envelope `data`.
	 * @param EventStatus         $status           Row state.
	 * @param int                 $attempts         Delivery attempts made.
	 * @param string|null         $next_attempt_at  Earliest next attempt (UTC).
	 * @param string|null         $locked_at        When a worker claimed the row (UTC).
	 * @param int|null            $last_http_status Last HTTP status (0 for transport errors).
	 * @param string|null         $last_error       Last error code and message.
	 * @param array<mixed>|null   $response         Last decoded response or error body.
	 * @param string              $created_at       When the event was recorded (UTC); sent as `occurred_at`.
	 * @param string|null         $delivered_at     When Pixalink accepted it (UTC).
	 * @param string|null         $outlet_id        Outlet the event was recorded for (null when unknown then).
	 * @param string|null         $api_base         API base the event was recorded for (null on older rows).
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $uuid,
		public readonly EventType $type,
		public readonly string $object_type,
		public readonly int $object_id,
		public readonly int $sequence,
		public readonly array $payload,
		public readonly EventStatus $status,
		public readonly int $attempts,
		public readonly ?string $next_attempt_at,
		public readonly ?string $locked_at,
		public readonly ?int $last_http_status,
		public readonly ?string $last_error,
		public readonly ?array $response,
		public readonly string $created_at,
		public readonly ?string $delivered_at,
		public readonly ?string $outlet_id = null,
		public readonly ?string $api_base = null
	) {}

	/**
	 * Build from a database row (ARRAY_A).
	 *
	 * @param array<string,mixed> $row Row.
	 * @return Event|null Null when the row is unreadable (unknown type or status).
	 */
	public static function from_row( array $row ): ?Event {
		$type   = EventType::tryFrom( self::string( $row['type'] ?? '' ) );
		$status = EventStatus::tryFrom( self::string( $row['status'] ?? '' ) );
		if ( null === $type || null === $status ) {
			return null;
		}

		return new self(
			(int) self::string( $row['id'] ?? '0' ),
			self::string( $row['uuid'] ?? '' ),
			$type,
			self::string( $row['object_type'] ?? '' ),
			(int) self::string( $row['object_id'] ?? '0' ),
			(int) self::string( $row['sequence'] ?? '0' ),
			self::decode( $row['payload'] ?? null ) ?? array(),
			$status,
			(int) self::string( $row['attempts'] ?? '0' ),
			self::nullable_string( $row['next_attempt_at'] ?? null ),
			self::nullable_string( $row['locked_at'] ?? null ),
			null === ( $row['last_http_status'] ?? null ) ? null : (int) self::string( $row['last_http_status'] ),
			self::nullable_string( $row['last_error'] ?? null ),
			self::decode( $row['response'] ?? null ),
			self::string( $row['created_at'] ?? '' ),
			self::nullable_string( $row['delivered_at'] ?? null ),
			self::nullable_string( $row['outlet_id'] ?? null ),
			self::nullable_string( $row['api_base'] ?? null )
		);
	}

	/**
	 * The envelope POSTed to `/events`.
	 *
	 * @param string $site_url  Store URL.
	 * @param string $outlet_id Pixalink outlet id.
	 * @return array<string,mixed>
	 */
	public function to_envelope( string $site_url, string $outlet_id ): array {
		return array(
			'id'          => $this->uuid,
			'type'        => $this->type->value,
			'version'     => self::ENVELOPE_VERSION,
			'occurred_at' => self::iso8601( $this->created_at ) ?? gmdate( 'Y-m-d\TH:i:s\Z' ),
			'sequence'    => $this->sequence,
			'site_url'    => rtrim( $site_url, '/' ),
			'outlet_id'   => $outlet_id,
			// An empty PHP array would encode as [] but the contract requires an object.
			'data'        => array() === $this->payload ? new \stdClass() : $this->payload,
		);
	}

	/**
	 * Whether the event belongs to an order.
	 *
	 * @return bool
	 */
	public function is_for_order(): bool {
		return self::OBJECT_ORDER === $this->object_type && $this->object_id > 0;
	}

	/**
	 * Convert a stored UTC `Y-m-d H:i:s` time to ISO 8601 (`2026-10-24T08:00:00Z`).
	 *
	 * @param string|null $mysql_time Stored time.
	 * @return string|null
	 */
	public static function iso8601( ?string $mysql_time ): ?string {
		if ( null === $mysql_time || '' === $mysql_time || 0 === strpos( $mysql_time, '0000-00-00' ) ) {
			return null;
		}

		$timestamp = strtotime( $mysql_time . ' UTC' );

		return false === $timestamp ? null : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	/**
	 * Unix time of a stored UTC time.
	 *
	 * @param string|null $mysql_time Stored time.
	 * @return int|null
	 */
	public static function timestamp( ?string $mysql_time ): ?int {
		if ( null === $mysql_time || '' === $mysql_time || 0 === strpos( $mysql_time, '0000-00-00' ) ) {
			return null;
		}

		$timestamp = strtotime( $mysql_time . ' UTC' );

		return false === $timestamp ? null : $timestamp;
	}

	/**
	 * Decode a JSON column.
	 *
	 * @param mixed $value Column value.
	 * @return array<mixed>|null
	 */
	private static function decode( $value ): ?array {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Scalar column as string.
	 *
	 * @param mixed $value Column value.
	 * @return string
	 */
	private static function string( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Nullable scalar column as string.
	 *
	 * @param mixed $value Column value.
	 * @return string|null
	 */
	private static function nullable_string( $value ): ?string {
		return null === $value || ! is_scalar( $value ) ? null : (string) $value;
	}
}
