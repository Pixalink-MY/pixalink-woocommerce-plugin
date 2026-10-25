<?php
/**
 * In-memory EventStore for unit tests.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Doubles;

use Pixalink\Loyalty\Events\Event;
use Pixalink\Loyalty\Events\EventStatus;
use Pixalink\Loyalty\Events\EventStore;
use Pixalink\Loyalty\Events\EventType;

/**
 * Mirrors EventRepository's semantics (unique uuid, per-order sequence, conditional claim) without a database.
 */
final class InMemoryEventStore implements EventStore {

	/**
	 * Rows keyed by id, in EventRepository's column shape.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $rows = array();

	/**
	 * Next auto-increment id.
	 *
	 * @var int
	 */
	private int $next_id = 1;

	public function insert( string $uuid, EventType $type, string $object_type, int $object_id, array $payload, string $now, ?string $outlet_id = null, ?string $api_base = null, int $sequence_after = 0 ): array {
		foreach ( $this->rows as $row ) {
			if ( $row['uuid'] === $uuid ) {
				return array(
					'id'       => (int) $row['id'],
					'created'  => false,
					'sequence' => (int) $row['sequence'],
				);
			}
		}

		$sequence = 0;
		if ( Event::OBJECT_ORDER === $object_type && $object_id > 0 ) {
			$sequence = max( 0, $sequence_after );
			foreach ( $this->rows as $row ) {
				if ( $row['object_type'] === $object_type && (int) $row['object_id'] === $object_id ) {
					$sequence = max( $sequence, (int) $row['sequence'] );
				}
			}
			++$sequence;
		}

		$id                = $this->next_id++;
		$this->rows[ $id ] = array(
			'id'               => $id,
			'uuid'             => $uuid,
			'type'             => $type->value,
			'object_type'      => $object_type,
			'object_id'        => $object_id,
			'sequence'         => $sequence,
			'payload'          => (string) json_encode( $payload ),
			'outlet_id'        => '' !== (string) $outlet_id ? $outlet_id : null,
			'api_base'         => '' !== (string) $api_base ? $api_base : null,
			'status'           => 'pending',
			'attempts'         => 0,
			'next_attempt_at'  => null,
			'locked_at'        => null,
			'last_http_status' => null,
			'last_error'       => null,
			'response'         => null,
			'created_at'       => $now,
			'delivered_at'     => null,
		);

		return array(
			'id'       => $id,
			'created'  => true,
			'sequence' => $sequence,
		);
	}

	public function find( int $id ): ?Event {
		return isset( $this->rows[ $id ] ) ? Event::from_row( $this->rows[ $id ] ) : null;
	}

	public function find_by_uuid( string $uuid ): ?Event {
		foreach ( $this->rows as $row ) {
			if ( $row['uuid'] === $uuid ) {
				return Event::from_row( $row );
			}
		}

		return null;
	}

	public function claim( int $id, string $now, string $stale_before ): bool {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return false;
		}

		$row       = $this->rows[ $id ];
		$claimable = in_array( $row['status'], array( 'pending', 'retry' ), true )
			|| ( 'sending' === $row['status'] && ( null === $row['locked_at'] || $row['locked_at'] < $stale_before ) );
		if ( ! $claimable ) {
			return false;
		}

		$this->rows[ $id ]['status']    = 'sending';
		$this->rows[ $id ]['locked_at'] = $now;

		return true;
	}

	public function first_open_before( Event $event ): ?Event {
		if ( ! $event->is_for_order() ) {
			return null;
		}

		$blocking = null;
		foreach ( $this->rows as $row ) {
			if ( $row['object_type'] === $event->object_type && (int) $row['object_id'] === $event->object_id
				&& (int) $row['sequence'] < $event->sequence && (int) $row['id'] !== $event->id
				&& in_array( $row['status'], array( 'pending', 'sending', 'retry' ), true )
				&& ( null === $blocking || (int) $row['sequence'] < (int) $blocking['sequence'] ) ) {
				$blocking = $row;
			}
		}

		return null === $blocking ? null : Event::from_row( $blocking );
	}

	public function open_ids_after( Event $event ): array {
		if ( ! $event->is_for_order() ) {
			return array();
		}

		$rows = array_filter(
			$this->rows,
			static fn ( array $row ): bool => $row['object_type'] === $event->object_type && (int) $row['object_id'] === $event->object_id
				&& (int) $row['sequence'] > $event->sequence && (int) $row['id'] !== $event->id
				&& in_array( $row['status'], array( 'pending', 'retry' ), true )
		);
		usort( $rows, static fn ( array $a, array $b ): int => (int) $a['sequence'] <=> (int) $b['sequence'] );

		return array_map( static fn ( array $row ): int => (int) $row['id'], array_values( $rows ) );
	}

	public function mark_delivered( int $id, int $attempts, int $http_status, array $response, string $now ): void {
		$this->patch(
			$id,
			array(
				'status'           => EventStatus::DELIVERED->value,
				'attempts'         => $attempts,
				'last_http_status' => $http_status,
				'last_error'       => null,
				'response'         => (string) json_encode( $response ),
				'locked_at'        => null,
				'next_attempt_at'  => null,
				'delivered_at'     => $now,
			)
		);
	}

	public function mark_retry( int $id, int $attempts, string $next_attempt_at, int $http_status, string $error, array $response ): void {
		$this->patch(
			$id,
			array(
				'status'           => EventStatus::RETRY->value,
				'attempts'         => $attempts,
				'last_http_status' => $http_status,
				'last_error'       => $error,
				'response'         => (string) json_encode( $response ),
				'locked_at'        => null,
				'next_attempt_at'  => $next_attempt_at,
			)
		);
	}

	public function mark_dead( int $id, int $attempts, int $http_status, string $error, array $response ): void {
		$this->patch(
			$id,
			array(
				'status'           => EventStatus::DEAD->value,
				'attempts'         => $attempts,
				'last_http_status' => $http_status,
				'last_error'       => $error,
				'response'         => (string) json_encode( $response ),
				'locked_at'        => null,
				'next_attempt_at'  => null,
			)
		);
	}

	public function defer( int $id, string $next_attempt_at, string $note ): void {
		if ( ! isset( $this->rows[ $id ] ) || ! in_array( $this->rows[ $id ]['status'], array( 'pending', 'sending', 'retry' ), true ) ) {
			return;
		}

		$this->patch(
			$id,
			array(
				'status'          => (int) $this->rows[ $id ]['attempts'] > 0 ? 'retry' : 'pending',
				'locked_at'       => null,
				'next_attempt_at' => $next_attempt_at,
				'last_error'      => $note,
			)
		);
	}

	public function due_ids( string $now, string $stale_before, int $limit ): array {
		$ids = array();
		foreach ( $this->rows as $row ) {
			$due = ( in_array( $row['status'], array( 'pending', 'retry' ), true ) && ( null === $row['next_attempt_at'] || $row['next_attempt_at'] <= $now ) )
				|| ( 'sending' === $row['status'] && ( null === $row['locked_at'] || $row['locked_at'] < $stale_before ) );
			if ( $due ) {
				$ids[] = (int) $row['id'];
			}
		}

		return array_slice( $ids, 0, $limit );
	}

	public function requeue( int $id, string $now ): bool {
		if ( ! isset( $this->rows[ $id ] ) || ! in_array( $this->rows[ $id ]['status'], array( 'dead', 'cancelled' ), true ) ) {
			return false;
		}

		$this->patch(
			$id,
			array(
				'status'          => 'retry',
				'attempts'        => 0,
				'locked_at'       => null,
				'next_attempt_at' => $now,
			)
		);

		return true;
	}

	/**
	 * Overwrite columns of a row (test setup and the methods above).
	 *
	 * @param int                 $id      Row id.
	 * @param array<string,mixed> $columns Columns.
	 */
	public function patch( int $id, array $columns ): void {
		$this->rows[ $id ] = array_merge( $this->rows[ $id ], $columns );
	}
}
