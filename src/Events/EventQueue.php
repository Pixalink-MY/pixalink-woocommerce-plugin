<?php
/**
 * Outbox writer.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Events;

use Pixalink\Loyalty\Support\Uuid;

defined( 'ABSPATH' ) || exit;

/**
 * Records an event in the outbox and enqueues its delivery.
 *
 * Order events get a deterministic UUID v5 over `"{home_url}|{type}|{order_key}|{discriminator}"` in
 * {@see Uuid::EVENT_NAMESPACE}: a duplicate IPN, a return-URL hit or the reconciler re-enqueueing the same fact
 * produce the same id, the INSERT is ignored and Pixalink's `(outlet, id)` key dedupes anything that slipped
 * through. The discriminator separates repeatable facts of one order (the refund id for `order.refunded`).
 * `customer.upserted` and `ping` get random v4 ids.
 */
final class EventQueue {

	/**
	 * Outbox storage.
	 *
	 * @var EventStore
	 */
	private EventStore $store;

	/**
	 * Action Scheduler wiring.
	 *
	 * @var Scheduler
	 */
	private Scheduler $scheduler;

	/**
	 * Current unix time.
	 *
	 * @var \Closure():int
	 */
	private \Closure $now;

	/**
	 * Constructor.
	 *
	 * @param EventStore    $store     Outbox storage.
	 * @param Scheduler     $scheduler Action Scheduler wiring.
	 * @param \Closure|null $now       Unix time source (tests); defaults to time().
	 */
	public function __construct( EventStore $store, Scheduler $scheduler, ?\Closure $now = null ) {
		$this->store     = $store;
		$this->scheduler = $scheduler;
		$this->now       = $now ?? static fn (): int => time();
	}

	/**
	 * Record an event and enqueue its delivery.
	 *
	 * @param EventType    $type          Event type.
	 * @param object|null  $subject       \WC_Order for order events, \WP_User for customer events, null otherwise.
	 * @param array<mixed> $data          Envelope `data`.
	 * @param string       $discriminator Separates repeatable facts of one order (refund id); empty otherwise.
	 * @return array{id:int,created:bool,sequence:int,uuid:string}|null Null when the subject cannot carry this event.
	 */
	public function enqueue( EventType $type, ?object $subject, array $data, string $discriminator = '' ): ?array {
		$target = self::target( $type, $subject );
		if ( null === $target ) {
			return null;
		}

		$uuid = $type->has_deterministic_id()
			? self::event_uuid( $type, self::site_url(), $target['key'], $discriminator )
			: Uuid::v4();

		$stored = $this->store->insert( $uuid, $type, $target['object_type'], $target['object_id'], $data, gmdate( 'Y-m-d H:i:s', ( $this->now )() ) );

		if ( $stored['created'] ) {
			$this->scheduler->deliver_now( $stored['id'] );
		}

		return $stored + array( 'uuid' => $uuid );
	}

	/**
	 * Deterministic id of an order event.
	 *
	 * @param EventType $type          Event type.
	 * @param string    $site_url      Store URL (trailing slash ignored).
	 * @param string    $order_key     WooCommerce order key.
	 * @param string    $discriminator Discriminator.
	 * @return string
	 */
	public static function event_uuid( EventType $type, string $site_url, string $order_key, string $discriminator = '' ): string {
		return Uuid::event_id( self::event_name( $type, $site_url, $order_key, $discriminator ) );
	}

	/**
	 * The name hashed into an order event id.
	 *
	 * @param EventType $type          Event type.
	 * @param string    $site_url      Store URL.
	 * @param string    $order_key     Order key.
	 * @param string    $discriminator Discriminator.
	 * @return string
	 */
	public static function event_name( EventType $type, string $site_url, string $order_key, string $discriminator = '' ): string {
		return rtrim( $site_url, '/' ) . '|' . $type->value . '|' . $order_key . '|' . $discriminator;
	}

	/**
	 * Store URL used in event ids and envelopes.
	 *
	 * @return string
	 */
	public static function site_url(): string {
		return rtrim( (string) home_url(), '/' );
	}

	/**
	 * Resolve what an event is about.
	 *
	 * @param EventType   $type    Event type.
	 * @param object|null $subject Subject.
	 * @return array{object_type:string,object_id:int,key:string}|null
	 */
	private static function target( EventType $type, ?object $subject ): ?array {
		if ( $type->is_order_event() ) {
			// WC_Order_Refund is not a WC_Order, so refunds never pass.
			if ( ! $subject instanceof \WC_Order ) {
				return null;
			}

			$order_id  = (int) $subject->get_id();
			$order_key = (string) $subject->get_order_key();
			if ( $order_id < 1 || '' === $order_key ) {
				return null;
			}

			return array(
				'object_type' => Event::OBJECT_ORDER,
				'object_id'   => $order_id,
				'key'         => $order_key,
			);
		}

		if ( $subject instanceof \WP_User ) {
			return array(
				'object_type' => Event::OBJECT_CUSTOMER,
				'object_id'   => (int) $subject->ID,
				'key'         => (string) $subject->ID,
			);
		}

		return array(
			'object_type' => Event::OBJECT_NONE,
			'object_id'   => 0,
			'key'         => '',
		);
	}
}
