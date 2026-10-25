<?php
/**
 * Outbox persistence on `{prefix}pixalink_events`.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Events;

use Pixalink\Loyalty\Install\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The outbox is a custom table; rows change on every delivery attempt, so they are never cached.

/**
 * All SQL against the event outbox.
 *
 * Exactly-once is enforced by the UNIQUE `uuid` key (INSERT IGNORE), concurrency by a conditional UPDATE that
 * claims a row. Times are UTC `Y-m-d H:i:s` strings supplied by the caller, so the database time zone never matters.
 */
final class EventRepository implements EventStore {

	/**
	 * Prefixed table name.
	 *
	 * @return string
	 */
	public function table(): string {
		global $wpdb;

		return $wpdb->prefix . Schema::EVENTS_TABLE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string       $uuid        Event id.
	 * @param EventType    $type        Event type.
	 * @param string       $object_type Object type.
	 * @param int          $object_id   Object id.
	 * @param array<mixed> $payload     Envelope data.
	 * @param string       $now         Creation time.
	 * @param string|null  $outlet_id   Outlet the event is recorded for.
	 * @param string|null  $api_base    API base the event is recorded for.
	 * @return array{id:int,created:bool,sequence:int}
	 * @throws \RuntimeException When the row can be neither inserted nor found.
	 */
	public function insert( string $uuid, EventType $type, string $object_type, int $object_id, array $payload, string $now, ?string $outlet_id = null, ?string $api_base = null ): array {
		global $wpdb;

		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			throw new \RuntimeException( 'Event payload cannot be encoded as JSON.' );
		}

		if ( Event::OBJECT_ORDER === $object_type && $object_id > 0 ) {
			// The per-order sequence is computed in the same statement, so concurrent inserts serialise on the index.
			$affected = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO %i (uuid, type, object_type, object_id, sequence, payload, outlet_id, api_base, status, attempts, created_at)
					SELECT %s, %s, %s, %d, COALESCE(MAX(sequence), 0) + 1, %s, NULLIF(%s, ''), NULLIF(%s, ''), 'pending', 0, %s FROM %i WHERE object_type = %s AND object_id = %d",
					$this->table(),
					$uuid,
					$type->value,
					$object_type,
					$object_id,
					$json,
					(string) $outlet_id,
					(string) $api_base,
					$now,
					$this->table(),
					$object_type,
					$object_id
				)
			);
		} else {
			$affected = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO %i (uuid, type, object_type, object_id, sequence, payload, outlet_id, api_base, status, attempts, created_at) VALUES (%s, %s, %s, %d, 0, %s, NULLIF(%s, ''), NULLIF(%s, ''), 'pending', 0, %s)",
					$this->table(),
					$uuid,
					$type->value,
					$object_type,
					max( 0, $object_id ),
					$json,
					(string) $outlet_id,
					(string) $api_base,
					$now
				)
			);
		}

		$created = 1 === (int) $affected;
		$event   = $this->find_by_uuid( $uuid );
		if ( null === $event ) {
			throw new \RuntimeException( sprintf( 'Event %s could not be stored: %s', esc_html( $uuid ), esc_html( (string) $wpdb->last_error ) ) );
		}

		return array(
			'id'       => $event->id,
			'created'  => $created,
			'sequence' => $event->sequence,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Row id.
	 * @return Event|null
	 */
	public function find( int $id ): ?Event {
		global $wpdb;

		return $this->hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $uuid Event id.
	 * @return Event|null
	 */
	public function find_by_uuid( string $uuid ): ?Event {
		global $wpdb;

		return $this->hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE uuid = %s', $this->table(), $uuid ), ARRAY_A ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $id           Row id.
	 * @param string $now          Lock time.
	 * @param string $stale_before Abandoned-lock threshold.
	 * @return bool
	 */
	public function claim( int $id, string $now, string $stale_before ): bool {
		global $wpdb;

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'sending', locked_at = %s WHERE id = %d AND ( status IN ('pending', 'retry') OR ( status = 'sending' AND ( locked_at IS NULL OR locked_at < %s ) ) )",
				$this->table(),
				$now,
				$id,
				$stale_before
			)
		);

		return 1 === (int) $affected;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Event $event Event.
	 * @return Event|null
	 */
	public function first_open_before( Event $event ): ?Event {
		global $wpdb;

		if ( ! $event->is_for_order() ) {
			return null;
		}

		return $this->hydrate(
			$wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE object_type = %s AND object_id = %d AND sequence < %d AND id <> %d AND status IN ('pending', 'sending', 'retry') ORDER BY sequence ASC, id ASC LIMIT 1",
					$this->table(),
					$event->object_type,
					$event->object_id,
					$event->sequence,
					$event->id
				),
				ARRAY_A
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Event $event Event.
	 * @return list<int>
	 */
	public function open_ids_after( Event $event ): array {
		global $wpdb;

		if ( ! $event->is_for_order() ) {
			return array();
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE object_type = %s AND object_id = %d AND sequence > %d AND id <> %d AND status IN ('pending', 'retry') ORDER BY sequence ASC, id ASC",
				$this->table(),
				$event->object_type,
				$event->object_id,
				$event->sequence,
				$event->id
			)
		);

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int          $id          Row id.
	 * @param int          $attempts    Attempts.
	 * @param int          $http_status HTTP status.
	 * @param array<mixed> $response    Response body.
	 * @param string       $now         Delivery time.
	 * @return void
	 */
	public function mark_delivered( int $id, int $attempts, int $http_status, array $response, string $now ): void {
		$this->update(
			$id,
			array(
				'status'           => EventStatus::DELIVERED->value,
				'attempts'         => $attempts,
				'last_http_status' => $http_status,
				'last_error'       => null,
				'response'         => self::encode( $response ),
				'locked_at'        => null,
				'next_attempt_at'  => null,
				'delivered_at'     => $now,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int          $id              Row id.
	 * @param int          $attempts        Attempts.
	 * @param string       $next_attempt_at Next attempt.
	 * @param int          $http_status     HTTP status.
	 * @param string       $error           Error.
	 * @param array<mixed> $response        Error body.
	 * @return void
	 */
	public function mark_retry( int $id, int $attempts, string $next_attempt_at, int $http_status, string $error, array $response ): void {
		$this->update(
			$id,
			array(
				'status'           => EventStatus::RETRY->value,
				'attempts'         => $attempts,
				'last_http_status' => $http_status,
				'last_error'       => self::truncate( $error ),
				'response'         => self::encode( $response ),
				'locked_at'        => null,
				'next_attempt_at'  => $next_attempt_at,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int          $id          Row id.
	 * @param int          $attempts    Attempts.
	 * @param int          $http_status HTTP status.
	 * @param string       $error       Error.
	 * @param array<mixed> $response    Error body.
	 * @return void
	 */
	public function mark_dead( int $id, int $attempts, int $http_status, string $error, array $response ): void {
		$this->update(
			$id,
			array(
				'status'           => EventStatus::DEAD->value,
				'attempts'         => $attempts,
				'last_http_status' => $http_status,
				'last_error'       => self::truncate( $error ),
				'response'         => self::encode( $response ),
				'locked_at'        => null,
				'next_attempt_at'  => null,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $id              Row id.
	 * @param string $next_attempt_at Next attempt.
	 * @param string $note            Reason.
	 * @return void
	 */
	public function defer( int $id, string $next_attempt_at, string $note ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = IF(attempts > 0, 'retry', 'pending'), locked_at = NULL, next_attempt_at = %s, last_error = %s WHERE id = %d AND status IN ('pending', 'sending', 'retry')",
				$this->table(),
				$next_attempt_at,
				self::truncate( $note ),
				$id
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $now          Current time.
	 * @param string $stale_before Abandoned-lock threshold.
	 * @param int    $limit        Maximum ids.
	 * @return list<int>
	 */
	public function due_ids( string $now, string $stale_before, int $limit ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE ( status IN ('pending', 'retry') AND ( next_attempt_at IS NULL OR next_attempt_at <= %s ) )
				OR ( status = 'sending' AND ( locked_at IS NULL OR locked_at < %s ) ) ORDER BY id ASC LIMIT %d",
				$this->table(),
				$now,
				$stale_before,
				max( 1, $limit )
			)
		);

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $id  Row id.
	 * @param string $now Next attempt.
	 * @return bool
	 */
	public function requeue( int $id, string $now ): bool {
		global $wpdb;

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'retry', attempts = 0, locked_at = NULL, next_attempt_at = %s WHERE id = %d AND status IN ('dead', 'cancelled')",
				$this->table(),
				$now,
				$id
			)
		);

		return 1 === (int) $affected;
	}

	/**
	 * Cancel an open or dead row (merchant action): it will never be sent.
	 *
	 * @param int $id Row id.
	 * @return bool Whether the row was cancelled.
	 */
	public function cancel( int $id ): bool {
		global $wpdb;

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'cancelled', locked_at = NULL, next_attempt_at = NULL WHERE id = %d AND status IN ('pending', 'retry', 'dead')",
				$this->table(),
				$id
			)
		);

		return 1 === (int) $affected;
	}

	/**
	 * Row counts per status plus the creation time of the oldest open row.
	 *
	 * @return array{pending:int,sending:int,retry:int,delivered:int,dead:int,cancelled:int,oldest_open_at:string|null}
	 */
	public function stats(): array {
		global $wpdb;

		$stats = array(
			'pending'        => 0,
			'sending'        => 0,
			'retry'          => 0,
			'delivered'      => 0,
			'dead'           => 0,
			'cancelled'      => 0,
			'oldest_open_at' => null,
		);

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i GROUP BY status', $this->table() ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$status = is_array( $row ) && isset( $row['status'] ) && is_string( $row['status'] ) ? $row['status'] : '';
			if ( '' !== $status && array_key_exists( $status, $stats ) && 'oldest_open_at' !== $status ) {
				$stats[ $status ] = (int) ( is_scalar( $row['total'] ?? null ) ? $row['total'] : 0 );
			}
		}

		$oldest = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(created_at) FROM %i WHERE status IN ('pending', 'sending', 'retry')",
				$this->table()
			)
		);

		$stats['oldest_open_at'] = is_string( $oldest ) && '' !== $oldest ? $oldest : null;

		return $stats;
	}

	/**
	 * Queue depth as reported in `POST connection/heartbeat`.
	 *
	 * @return array{pending:int,failed:int,oldest_pending_at:string|null} `oldest_pending_at` is ISO 8601 UTC.
	 */
	public function queue_stats(): array {
		$stats = $this->stats();

		return array(
			'pending'           => $stats['pending'] + $stats['sending'] + $stats['retry'],
			'failed'            => $stats['dead'],
			'oldest_pending_at' => Event::iso8601( $stats['oldest_open_at'] ),
		);
	}

	/**
	 * The most recently delivered event.
	 *
	 * @return Event|null
	 */
	public function last_delivered(): ?Event {
		global $wpdb;

		return $this->hydrate(
			$wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM %i WHERE status = 'delivered' ORDER BY delivered_at DESC, id DESC LIMIT 1", $this->table() ),
				ARRAY_A
			)
		);
	}

	/**
	 * Ids of dead rows, oldest first.
	 *
	 * @param int $limit Maximum ids.
	 * @return list<int>
	 */
	public function dead_ids( int $limit ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM %i WHERE status = 'dead' ORDER BY id ASC LIMIT %d", $this->table(), max( 1, $limit ) )
		);

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Events of one object, newest first.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @param int    $limit       Maximum rows.
	 * @return list<Event>
	 */
	public function for_object( string $object_type, int $object_id, int $limit = 50 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE object_type = %s AND object_id = %d ORDER BY sequence DESC, id DESC LIMIT %d',
				$this->table(),
				$object_type,
				$object_id,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return $this->hydrate_all( $rows );
	}

	/**
	 * Paginated listing for the admin events table.
	 *
	 * @param array{status?:string,type?:string,search?:string,orderby?:string,order?:string,per_page?:int,page?:int} $args Filters.
	 * @return array{items:list<Event>,total:int}
	 */
	public function search( array $args ): array {
		global $wpdb;

		$where  = array( '1 = 1' );
		$params = array( $this->table() );

		$status = EventStatus::tryFrom( (string) ( $args['status'] ?? '' ) );
		if ( null !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status->value;
		}

		$type = EventType::tryFrom( (string) ( $args['type'] ?? '' ) );
		if ( null !== $type ) {
			$where[]  = 'type = %s';
			$params[] = $type->value;
		}

		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			if ( ctype_digit( $search ) ) {
				$where[]  = '( object_id = %d OR id = %d )';
				$params[] = (int) $search;
				$params[] = (int) $search;
			} else {
				$where[]  = 'uuid LIKE %s';
				$params[] = $wpdb->esc_like( strtolower( $search ) ) . '%';
			}
		}

		$orderby  = in_array( $args['orderby'] ?? '', array( 'id', 'created_at', 'delivered_at', 'attempts', 'type', 'status' ), true ) ? (string) $args['orderby'] : 'id';
		$order    = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$clause   = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $clause only holds fixed fragments with placeholders matched by $params; $orderby/$order are allow-listed above.
		$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$clause}", $params ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE {$clause} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'items' => $this->hydrate_all( $rows ),
			'total' => (int) $total,
		);
	}

	/**
	 * Delete delivered rows older than `$delivered_before` and dead/cancelled rows older than `$dead_before`.
	 *
	 * @param string $delivered_before Delivered-at threshold.
	 * @param string $dead_before      Created-at threshold for dead and cancelled rows.
	 * @param int    $limit            Maximum rows per statement.
	 * @return int Rows deleted.
	 */
	public function prune( string $delivered_before, string $dead_before, int $limit ): int {
		global $wpdb;

		$limit     = max( 1, $limit );
		$delivered = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE status = 'delivered' AND delivered_at < %s ORDER BY id LIMIT %d",
				$this->table(),
				$delivered_before,
				$limit
			)
		);
		$dead      = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE status IN ('dead', 'cancelled') AND created_at < %s ORDER BY id LIMIT %d",
				$this->table(),
				$dead_before,
				$limit
			)
		);

		return (int) $delivered + (int) $dead;
	}

	/**
	 * Update columns of one row.
	 *
	 * @param int                 $id      Row id.
	 * @param array<string,mixed> $columns Column => value (null writes NULL).
	 * @return void
	 */
	private function update( int $id, array $columns ): void {
		global $wpdb;

		$formats = array();
		foreach ( $columns as $column => $value ) {
			$formats[] = is_int( $value ) ? '%d' : '%s';
		}

		$wpdb->update( $this->table(), $columns, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Hydrate one row.
	 *
	 * @param mixed $row Row from get_row(ARRAY_A).
	 * @return Event|null
	 */
	private function hydrate( $row ): ?Event {
		if ( ! is_array( $row ) ) {
			return null;
		}

		/**
		 * Column map.
		 *
		 * @var array<string,mixed> $row
		 */
		return Event::from_row( $row );
	}

	/**
	 * Hydrate many rows, skipping unreadable ones.
	 *
	 * @param mixed $rows Rows from get_results(ARRAY_A).
	 * @return list<Event>
	 */
	private function hydrate_all( $rows ): array {
		$events = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$event = $this->hydrate( $row );
			if ( null !== $event ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/**
	 * JSON for a response column.
	 *
	 * @param array<mixed> $value Decoded body.
	 * @return string|null
	 */
	private static function encode( array $value ): ?string {
		if ( array() === $value ) {
			return null;
		}

		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return null;
		}

		if ( strlen( $json ) > 65535 ) {
			// Keep the column readable JSON even when a server returns a huge body.
			$json = (string) wp_json_encode(
				array(
					'truncated' => true,
					'excerpt'   => substr( $json, 0, 2000 ),
				)
			);
		}

		return $json;
	}

	/**
	 * Keep error text within the TEXT column.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function truncate( string $text ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 2000 ) : substr( $text, 0, 2000 );
	}
}
