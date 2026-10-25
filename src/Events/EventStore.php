<?php
/**
 * Outbox storage contract.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Events;

defined( 'ABSPATH' ) || exit;

/**
 * The writes {@see EventQueue} and {@see EventDeliverer} need from the outbox.
 *
 * {@see EventRepository} implements it on `{prefix}pixalink_events`; unit tests use an in-memory double.
 * Times are UTC `Y-m-d H:i:s` strings.
 */
interface EventStore {

	/**
	 * Insert an event unless its uuid already exists (exactly-once by uuid).
	 *
	 * Order events get the next per-order sequence (1, 2, …); other events get 0.
	 *
	 * @param string       $uuid        Event id.
	 * @param EventType    $type        Event type.
	 * @param string       $object_type One of the Event::OBJECT_* constants.
	 * @param int          $object_id   Order or user id.
	 * @param array<mixed> $payload     Envelope `data`.
	 * @param string       $now         Creation time.
	 * @param string|null  $outlet_id   Outlet the event is recorded for (null when not known locally).
	 * @param string|null  $api_base    API base the event is recorded for.
	 * @return array{id:int,created:bool,sequence:int} The new or existing row.
	 */
	public function insert( string $uuid, EventType $type, string $object_type, int $object_id, array $payload, string $now, ?string $outlet_id = null, ?string $api_base = null ): array;

	/**
	 * Row by id.
	 *
	 * @param int $id Row id.
	 * @return Event|null
	 */
	public function find( int $id ): ?Event;

	/**
	 * Row by event id.
	 *
	 * @param string $uuid Event id.
	 * @return Event|null
	 */
	public function find_by_uuid( string $uuid ): ?Event;

	/**
	 * Atomically move a pending/retry row (or a `sending` row locked before `$stale_before`) to `sending`.
	 *
	 * @param int    $id           Row id.
	 * @param string $now          Lock time.
	 * @param string $stale_before Locks older than this are considered abandoned.
	 * @return bool Whether this worker owns the row now.
	 */
	public function claim( int $id, string $now, string $stale_before ): bool;

	/**
	 * The earliest event of the same order with a lower sequence that is still pending, sending or retrying.
	 *
	 * @param Event $event Event.
	 * @return Event|null
	 */
	public function first_open_before( Event $event ): ?Event;

	/**
	 * Ids of open events of the same order with a higher sequence, lowest first.
	 *
	 * @param Event $event Event.
	 * @return list<int>
	 */
	public function open_ids_after( Event $event ): array;

	/**
	 * Record a successful delivery.
	 *
	 * @param int          $id          Row id.
	 * @param int          $attempts    Attempts including this one.
	 * @param int          $http_status HTTP status.
	 * @param array<mixed> $response    Decoded response body.
	 * @param string       $now         Delivery time.
	 * @return void
	 */
	public function mark_delivered( int $id, int $attempts, int $http_status, array $response, string $now ): void;

	/**
	 * Record a failed attempt that will be retried.
	 *
	 * @param int          $id              Row id.
	 * @param int          $attempts        Attempts including this one.
	 * @param string       $next_attempt_at Earliest next attempt.
	 * @param int          $http_status     HTTP status (0 for transport errors).
	 * @param string       $error           Error code and message.
	 * @param array<mixed> $response        Error body.
	 * @return void
	 */
	public function mark_retry( int $id, int $attempts, string $next_attempt_at, int $http_status, string $error, array $response ): void;

	/**
	 * Record a permanent failure.
	 *
	 * @param int          $id          Row id.
	 * @param int          $attempts    Attempts including this one.
	 * @param int          $http_status HTTP status (0 when no request was made).
	 * @param string       $error       Error code and message.
	 * @param array<mixed> $response    Error body.
	 * @return void
	 */
	public function mark_dead( int $id, int $attempts, int $http_status, string $error, array $response ): void;

	/**
	 * Push an open row back without counting an attempt (ordering guard, paused delivery).
	 *
	 * @param int    $id              Row id.
	 * @param string $next_attempt_at Earliest next attempt.
	 * @param string $note            Why the row waits (stored as last_error).
	 * @return void
	 */
	public function defer( int $id, string $next_attempt_at, string $note ): void;

	/**
	 * Ids of rows whose delivery is due: pending/retry rows past `next_attempt_at`, and abandoned `sending` rows.
	 *
	 * @param string $now          Current time.
	 * @param string $stale_before Abandoned-lock threshold.
	 * @param int    $limit        Maximum ids.
	 * @return list<int>
	 */
	public function due_ids( string $now, string $stale_before, int $limit ): array;

	/**
	 * Give a dead or cancelled row a fresh set of attempts.
	 *
	 * @param int    $id  Row id.
	 * @param string $now Earliest next attempt.
	 * @return bool Whether the row was requeued.
	 */
	public function requeue( int $id, string $now ): bool;
}
