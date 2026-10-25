<?php
/**
 * WooCommerce order hooks → outbox events, and delivery results → order meta and notes.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Orders;

use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Events\Event;
use Pixalink\Loyalty\Events\EventQueue;
use Pixalink\Loyalty\Events\EventRepository;
use Pixalink\Loyalty\Events\EventStatus;
use Pixalink\Loyalty\Events\EventType;
use Pixalink\Loyalty\Events\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Maps order life-cycle hooks to events:
 *
 * | Hook                                                         | Event                          | Discriminator |
 * |--------------------------------------------------------------|--------------------------------|---------------|
 * | `woocommerce_payment_complete`, status → a paid status       | `order.paid` (once per order)  | —             |
 * | status → completed                                           | `order.completed` (full order) | —             |
 * | status → cancelled                                           | `order.cancelled`              | from-status   |
 * | `woocommerce_order_refunded`                                 | `order.refunded`               | refund id     |
 * | `woocommerce_refund_deleted` (refund was sent)               | `order.refund_deleted`         | refund id     |
 * | trash / delete of an unpaid order (HPOS hooks and CPT hooks) | `order.redemptions_released`   | —             |
 *
 * `order.paid` is guarded by `_pixalink_earn_state` (queued/delivered) and by the deterministic event id, so a
 * duplicate IPN plus the return-URL handler record it once. Events are only recorded while the store is connected;
 * the daily reconciler picks up paid orders missed while it was not.
 */
final class OrderLifecycle {

	/**
	 * Remembers the status an order was cancelled from (for resends).
	 */
	public const CANCELLED_FROM_META = '_pixalink_cancelled_from';

	/**
	 * Outbox writer.
	 *
	 * @var EventQueue
	 */
	private EventQueue $queue;

	/**
	 * Outbox reads (resend, refund-deleted guard).
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;

	/**
	 * Action Scheduler wiring.
	 *
	 * @var Scheduler
	 */
	private Scheduler $scheduler;

	/**
	 * Order payloads.
	 *
	 * @var OrderPayloadBuilder
	 */
	private OrderPayloadBuilder $orders;

	/**
	 * Refund payloads.
	 *
	 * @var RefundPayloadBuilder
	 */
	private RefundPayloadBuilder $refunds;

	/**
	 * Outlet credentials (events are only recorded while connected).
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Constructor.
	 *
	 * @param EventQueue           $queue       Outbox writer.
	 * @param EventRepository      $events      Outbox reads.
	 * @param Scheduler            $scheduler   Action Scheduler wiring.
	 * @param OrderPayloadBuilder  $orders      Order payloads.
	 * @param RefundPayloadBuilder $refunds     Refund payloads.
	 * @param Credentials          $credentials Outlet credentials.
	 */
	public function __construct( EventQueue $queue, EventRepository $events, Scheduler $scheduler, OrderPayloadBuilder $orders, RefundPayloadBuilder $refunds, Credentials $credentials ) {
		$this->queue       = $queue;
		$this->events      = $events;
		$this->scheduler   = $scheduler;
		$this->orders      = $orders;
		$this->refunds     = $refunds;
		$this->credentials = $credentials;
	}

	/**
	 * Register WooCommerce and outbox hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_payment_complete', array( $this, 'on_payment_complete' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_refunded' ), 20, 2 );
		add_action( 'woocommerce_refund_deleted', array( $this, 'on_refund_deleted' ), 20, 2 );

		// HPOS and CRUD deletes.
		add_action( 'woocommerce_before_trash_order', array( $this, 'on_before_trash_order' ), 20, 2 );
		add_action( 'woocommerce_before_delete_order', array( $this, 'on_before_delete_order' ), 20, 2 );
		// Posts storage: the orders list trashes and deletes posts directly.
		add_action( 'wp_trash_post', array( $this, 'on_trash_post' ), 20, 1 );
		add_action( 'before_delete_post', array( $this, 'on_before_delete_post' ), 20, 1 );

		add_action( 'pixalink_loyalty_event_delivered', array( $this, 'on_event_delivered' ), 10, 2 );
		add_action( 'pixalink_loyalty_event_dead', array( $this, 'on_event_dead' ), 10, 2 );
	}

	/**
	 * `woocommerce_payment_complete` callback (gateway IPN, return URL, admin "mark paid").
	 *
	 * @param mixed $order_id Order id.
	 * @return void
	 */
	public function on_payment_complete( $order_id ): void {
		$order = self::order( $order_id );
		if ( null !== $order ) {
			$this->record_paid( $order );
		}
	}

	/**
	 * `woocommerce_order_status_changed` callback.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $from     Previous status (without "wc-").
	 * @param mixed $to       New status (without "wc-").
	 * @param mixed $order    Order instance (WooCommerce 3.0+).
	 * @return void
	 */
	public function on_status_changed( $order_id, $from, $to, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : self::order( $order_id );
		if ( null === $order ) {
			return;
		}

		$from = is_string( $from ) ? $from : '';
		$to   = is_string( $to ) ? $to : '';

		if ( in_array( $to, self::paid_statuses(), true ) ) {
			$this->record_paid( $order );
		}

		if ( 'completed' === $to ) {
			$this->record_completed( $order );
		}

		if ( 'cancelled' === $to ) {
			$this->record_cancelled( $order, $from );
		}
	}

	/**
	 * `woocommerce_order_refunded` callback: one event per refund id.
	 *
	 * @param mixed $order_id  Order id.
	 * @param mixed $refund_id Refund id.
	 * @return void
	 */
	public function on_refunded( $order_id, $refund_id ): void {
		$order  = self::order( $order_id );
		$refund = is_numeric( $refund_id ) ? wc_get_order( (int) $refund_id ) : false;
		if ( null === $order || ! $refund instanceof \WC_Order_Refund ) {
			return;
		}

		$this->record_refund( $order, $refund );
	}

	/**
	 * `woocommerce_refund_deleted` callback (fires after the refund is gone).
	 *
	 * @param mixed $refund_id Refund id.
	 * @param mixed $order_id  Order id.
	 * @return void
	 */
	public function on_refund_deleted( $refund_id, $order_id ): void {
		$order = self::order( $order_id );
		if ( null === $order || ! is_numeric( $refund_id ) || (int) $refund_id < 1 || ! $this->is_tracked( $order, EventType::ORDER_REFUND_DELETED ) ) {
			return;
		}

		// Only refunds Pixalink heard about need an undo; the order remembers them once their rows are pruned.
		$reported = in_array( (int) $refund_id, self::reported_refunds( $order ), true )
			|| null !== $this->events->find_by_uuid( EventQueue::event_uuid( EventType::ORDER_REFUNDED, EventQueue::site_url(), (string) $order->get_order_key(), (string) (int) $refund_id ) );
		if ( ! $reported ) {
			return;
		}

		$this->enqueue(
			$order,
			EventType::ORDER_REFUND_DELETED,
			$this->orders->reference_data(
				$order,
				array(
					'refund_id' => (int) $refund_id,
					'reason'    => null,
				)
			),
			(string) (int) $refund_id
		);
	}

	/**
	 * `woocommerce_before_trash_order` callback.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $order    Order.
	 * @return void
	 */
	public function on_before_trash_order( $order_id, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : self::order( $order_id );
		if ( null !== $order ) {
			$this->release_unpaid( $order, 'trashed' );
		}
	}

	/**
	 * `woocommerce_before_delete_order` callback.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $order    Order.
	 * @return void
	 */
	public function on_before_delete_order( $order_id, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : self::order( $order_id );
		if ( null !== $order ) {
			$this->release_unpaid( $order, 'deleted' );
		}
	}

	/**
	 * `wp_trash_post` callback for orders stored as posts.
	 *
	 * @param mixed $post_id Post id.
	 * @return void
	 */
	public function on_trash_post( $post_id ): void {
		$order = self::order_post( $post_id );
		if ( null !== $order ) {
			$this->release_unpaid( $order, 'trashed' );
		}
	}

	/**
	 * `before_delete_post` callback for orders stored as posts.
	 *
	 * @param mixed $post_id Post id.
	 * @return void
	 */
	public function on_before_delete_post( $post_id ): void {
		$order = self::order_post( $post_id );
		if ( null !== $order ) {
			$this->release_unpaid( $order, 'deleted' );
		}
	}

	/**
	 * Record `order.paid` unless it already is.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool Whether a new event was recorded.
	 */
	public function record_paid( \WC_Order $order ): bool {
		if ( OrderMeta::is_paid_event_recorded( $order ) || ! $this->is_tracked( $order, EventType::ORDER_PAID ) ) {
			return false;
		}

		return $this->enqueue( $order, EventType::ORDER_PAID, $this->orders->build( $order, EventType::ORDER_PAID ) );
	}

	/**
	 * Record `order.completed` (full payload: outlets that earn on completion settle from it).
	 *
	 * @param \WC_Order $order Order.
	 * @return bool Whether a new event was recorded.
	 */
	public function record_completed( \WC_Order $order ): bool {
		if ( ! $this->is_tracked( $order, EventType::ORDER_COMPLETED ) ) {
			return false;
		}

		return $this->enqueue( $order, EventType::ORDER_COMPLETED, $this->orders->build( $order, EventType::ORDER_COMPLETED ) );
	}

	/**
	 * Record `order.cancelled`.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $from  Status the order was cancelled from.
	 * @return bool Whether a new event was recorded.
	 */
	public function record_cancelled( \WC_Order $order, string $from ): bool {
		if ( ! $this->is_tracked( $order, EventType::ORDER_CANCELLED ) ) {
			return false;
		}

		$was_paid = null !== $order->get_date_paid() || '' !== OrderMeta::earn_state( $order );
		OrderMeta::update( $order, array( self::CANCELLED_FROM_META => $from ) );

		return $this->enqueue( $order, EventType::ORDER_CANCELLED, $this->orders->cancellation( $order, $from, $was_paid ), $from );
	}

	/**
	 * Record `order.refunded` for one refund (records `order.paid` first if a paid order never sent it).
	 *
	 * @param \WC_Order        $order  Order.
	 * @param \WC_Order_Refund $refund Refund.
	 * @return bool Whether a new event was recorded.
	 */
	public function record_refund( \WC_Order $order, \WC_Order_Refund $refund ): bool {
		if ( ! $this->is_tracked( $order, EventType::ORDER_REFUNDED ) ) {
			return false;
		}

		if ( null === $order->get_date_paid() && '' === OrderMeta::earn_state( $order ) ) {
			// Never paid: nothing was earned, so there is nothing to reverse.
			return false;
		}

		$this->record_paid( $order );

		return $this->enqueue( $order, EventType::ORDER_REFUNDED, $this->refunds->build( $order, $refund ), (string) $refund->get_id() );
	}

	/**
	 * Record `order.redemptions_released` when an unpaid order is trashed or deleted.
	 *
	 * Paid orders keep their loyalty history; cancelled orders already released through `order.cancelled`.
	 * Draft checkouts without redemptions are cleaned up by WooCommerce daily and never held anything.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $reason "trashed" or "deleted".
	 * @return bool Whether a new event was recorded.
	 */
	public function release_unpaid( \WC_Order $order, string $reason ): bool {
		$status = (string) $order->get_status();
		if ( null !== $order->get_date_paid() || '' !== OrderMeta::earn_state( $order ) || in_array( $status, array( 'cancelled', 'trash' ), true ) ) {
			return false;
		}

		if ( in_array( $status, array( 'checkout-draft', 'auto-draft' ), true ) && ! OrderMeta::has_redemptions( $order ) ) {
			return false;
		}

		if ( ! $this->is_tracked( $order, EventType::ORDER_REDEMPTIONS_RELEASED ) ) {
			return false;
		}

		return $this->enqueue( $order, EventType::ORDER_REDEMPTIONS_RELEASED, $this->orders->reference_data( $order, array( 'reason' => $reason ) ), 'released' );
	}

	/**
	 * Record `order.redemptions_released` for the order's current holds (payment failed, or another reason).
	 *
	 * The event names the holds it releases (`redemption_ids`), so a release still in the outbox never undoes a
	 * newer hold the shopper placed on the same order meanwhile; the discriminator carries the hold generation,
	 * so an order held again can be released again. The order's redemption state is `released` from here on.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $reason Machine reason, e.g. "failed".
	 * @return bool Whether a new event was recorded.
	 */
	public function release_holds( \WC_Order $order, string $reason ): bool {
		$refs = OrderMeta::hold_refs( $order );
		if ( ! OrderMeta::is_held( $order ) || array() === $refs || ! $this->is_tracked( $order, EventType::ORDER_REDEMPTIONS_RELEASED ) ) {
			return false;
		}

		$generation = max( 1, (int) OrderMeta::get_string( $order, OrderMeta::HOLD_GENERATION ) );
		$recorded   = $this->enqueue(
			$order,
			EventType::ORDER_REDEMPTIONS_RELEASED,
			$this->orders->reference_data(
				$order,
				array(
					'reason'         => $reason,
					'redemption_ids' => $refs,
				)
			),
			'released-g' . $generation
		);

		OrderMeta::update( $order, array( OrderMeta::REDEMPTION_STATE => OrderMeta::REDEMPTION_RELEASED ) );

		return $recorded;
	}

	/**
	 * Make sure every event this order should have produced exists, and give failed ones a fresh set of attempts.
	 *
	 * Delivered events are not re-sent: their ids are the server's exactly-once key, so Pixalink would only answer
	 * "duplicate".
	 *
	 * @param \WC_Order $order Order.
	 * @return int Events recorded or requeued.
	 */
	public function resend( \WC_Order $order ): int {
		if ( ! $this->is_tracked( $order, EventType::ORDER_PAID ) ) {
			return 0;
		}

		$count  = 0;
		$status = (string) $order->get_status();
		$paid   = null !== $order->get_date_paid() || in_array( $status, self::paid_statuses(), true );

		if ( $paid ) {
			$count += $this->ensure( $order, EventType::ORDER_PAID, fn (): array => $this->orders->build( $order, EventType::ORDER_PAID ) );
			if ( 'completed' === $status ) {
				$count += $this->ensure( $order, EventType::ORDER_COMPLETED, fn (): array => $this->orders->build( $order, EventType::ORDER_COMPLETED ) );
			}

			foreach ( $order->get_refunds() as $refund ) {
				if ( $refund instanceof \WC_Order_Refund ) {
					$count += $this->ensure( $order, EventType::ORDER_REFUNDED, fn (): array => $this->refunds->build( $order, $refund ), (string) $refund->get_id() );
				}
			}
		}

		if ( 'cancelled' === $status ) {
			$from   = OrderMeta::get_string( $order, self::CANCELLED_FROM_META );
			$from   = '' !== $from ? $from : ( $paid ? 'processing' : 'pending' );
			$count += $this->ensure( $order, EventType::ORDER_CANCELLED, fn (): array => $this->orders->cancellation( $order, $from, $paid ), $from );
		}

		return $count;
	}

	/**
	 * Whether this order's events should be recorded at all.
	 *
	 * @param \WC_Order $order Order.
	 * @param EventType $type  Event.
	 * @return bool
	 */
	public function is_tracked( \WC_Order $order, EventType $type ): bool {
		if ( ! $this->credentials->is_configured() || 'shop_order' !== $order->get_type() ) {
			return false;
		}

		/**
		 * Filters whether an order event is sent to Pixalink (for example to skip wholesale or test orders).
		 *
		 * @since 0.1.0
		 *
		 * @param bool      $send  Whether to send. Default true.
		 * @param \WC_Order $order The order.
		 * @param string    $type  Event type, e.g. "order.paid".
		 */
		return (bool) apply_filters( 'pixalink_loyalty_should_send_order', true, $order, $type->value );
	}

	/**
	 * `pixalink_loyalty_event_delivered` callback: write results to the order.
	 *
	 * @param mixed $event  Delivered event.
	 * @param mixed $result Response body `{status, result}`.
	 * @return void
	 */
	public function on_event_delivered( $event, $result ): void {
		if ( ! $event instanceof Event || ! $event->is_for_order() ) {
			return;
		}

		$order = self::order( $event->object_id );
		if ( null === $order ) {
			return;
		}

		$result  = is_array( $result ) ? $result : array();
		$status  = isset( $result['status'] ) && is_string( $result['status'] ) ? $result['status'] : 'processed';
		$details = isset( $result['result'] ) && is_array( $result['result'] ) ? $result['result'] : array();
		$meta    = array();
		$note    = '';

		switch ( $event->type ) {
			case EventType::ORDER_PAID:
				$meta[ OrderMeta::EARN_STATE ] = OrderMeta::EARN_DELIVERED;
				$meta                         += self::earn_meta( $details );
				if ( OrderMeta::has_redemptions( $order ) && 'ignored' !== $status ) {
					$meta[ OrderMeta::REDEMPTION_STATE ] = OrderMeta::REDEMPTION_SETTLED;
				}
				$note = self::paid_note( $status, $details );
				break;

			case EventType::ORDER_COMPLETED:
				$earn = self::earn_meta( $details );
				$meta = isset( $earn[ OrderMeta::POINTS_EARNED ] ) && $earn[ OrderMeta::POINTS_EARNED ] > 0 ? $earn : array();
				if ( array() !== $meta ) {
					/* translators: %d: points earned. */
					$note = sprintf( _n( 'Pixalink: %d point earned on completion.', 'Pixalink: %d points earned on completion.', (int) $meta[ OrderMeta::POINTS_EARNED ], 'pixalink-loyalty-for-woocommerce' ), (int) $meta[ OrderMeta::POINTS_EARNED ] );
				}
				break;

			case EventType::ORDER_CANCELLED:
				if ( OrderMeta::has_redemptions( $order ) ) {
					$meta[ OrderMeta::REDEMPTION_STATE ] = OrderMeta::REDEMPTION_RELEASED;
				}
				if ( 'duplicate' !== $status ) {
					$note = __( 'Pixalink recorded the cancellation. Any points or vouchers held for this order were released.', 'pixalink-loyalty-for-woocommerce' );
				}
				break;

			case EventType::ORDER_REFUNDED:
				if ( 'duplicate' !== $status ) {
					$note = self::refund_note( $event, $details );
				}
				break;

			case EventType::ORDER_REFUND_DELETED:
				if ( 'ignored' === $status ) {
					$refund_id = isset( $event->payload['refund_id'] ) && is_numeric( $event->payload['refund_id'] ) ? (int) $event->payload['refund_id'] : 0;
					/* translators: %d: refund id. */
					$note = sprintf( __( 'Pixalink did not undo deleted refund #%d. If points were reversed for it, adjust the customer in Pixalink.', 'pixalink-loyalty-for-woocommerce' ), $refund_id );
				}
				break;

			case EventType::ORDER_REDEMPTIONS_RELEASED:
				// A release naming older holds must not mark holds placed since then as released.
				$released = isset( $event->payload['redemption_ids'] ) && is_array( $event->payload['redemption_ids'] ) ? $event->payload['redemption_ids'] : null;
				if ( null === $released || ! OrderMeta::is_held( $order ) || array() !== array_intersect( OrderMeta::hold_refs( $order ), $released ) ) {
					$meta[ OrderMeta::REDEMPTION_STATE ] = OrderMeta::REDEMPTION_RELEASED;
				}
				break;

			default:
				break;
		}

		if ( array() !== $meta ) {
			OrderMeta::update( $order, $meta );
		}

		if ( '' !== $note ) {
			$order->add_order_note( $note );
		}
	}

	/**
	 * `pixalink_loyalty_event_dead` callback: flag the order for the merchant.
	 *
	 * @param mixed $event Failed event.
	 * @param mixed $error `{code, message, http_status, attempts}`.
	 * @return void
	 */
	public function on_event_dead( $event, $error ): void {
		if ( ! $event instanceof Event || ! $event->is_for_order() ) {
			return;
		}

		$order = self::order( $event->object_id );
		if ( null === $order ) {
			return;
		}

		if ( EventType::ORDER_PAID === $event->type ) {
			OrderMeta::update( $order, array( OrderMeta::EARN_STATE => OrderMeta::EARN_DEAD ) );
		}

		$code = is_array( $error ) && isset( $error['code'] ) && is_string( $error['code'] ) ? $error['code'] : 'unknown_error';
		$order->add_order_note(
			sprintf(
				/* translators: 1: event label such as "Order paid", 2: machine error code. */
				__( 'Pixalink could not record "%1$s" for this order (%2$s). Use "Resend to Pixalink" in the order actions, or retry it under WooCommerce › Status › Pixalink events.', 'pixalink-loyalty-for-woocommerce' ),
				$event->type->label(),
				$code
			)
		);
	}

	/**
	 * Paid statuses (filterable by WooCommerce).
	 *
	 * @return list<string>
	 */
	public static function paid_statuses(): array {
		$statuses = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );

		return array_values( array_filter( array_map( 'strval', (array) $statuses ) ) );
	}

	/**
	 * Enqueue an event and keep the order's earn state and sequence meta in step.
	 *
	 * @param \WC_Order    $order         Order.
	 * @param EventType    $type          Event.
	 * @param array<mixed> $data          Event data.
	 * @param string       $discriminator Discriminator.
	 * @return bool Whether a new event was recorded.
	 */
	private function enqueue( \WC_Order $order, EventType $type, array $data, string $discriminator = '' ): bool {
		// The order's last sequence outlives its pruned outbox rows, so the next one keeps rising.
		$last   = (int) OrderMeta::get_string( $order, OrderMeta::EVENT_SEQ );
		$stored = $this->queue->enqueue( $type, $order, $data, $discriminator, $last );
		if ( null === $stored ) {
			return false;
		}

		$meta = array();
		if ( $stored['created'] ) {
			$meta[ OrderMeta::EVENT_SEQ ] = max( $last, $stored['sequence'] );
		}

		if ( EventType::ORDER_PAID === $type ) {
			$meta[ OrderMeta::EARN_STATE ] = $stored['created'] ? OrderMeta::EARN_QUEUED : $this->earn_state_of( $stored['id'] );
		}

		if ( EventType::ORDER_REFUNDED === $type && $stored['created'] && (int) $discriminator > 0 ) {
			$meta[ OrderMeta::REPORTED_REFUNDS ] = array_values( array_unique( array_merge( self::reported_refunds( $order ), array( (int) $discriminator ) ) ) );
		}

		if ( array() !== $meta ) {
			OrderMeta::update( $order, $meta );
		}

		return $stored['created'];
	}

	/**
	 * Enqueue an event if missing, or requeue it when it failed or was cancelled.
	 *
	 * @param \WC_Order      $order         Order.
	 * @param EventType      $type          Event.
	 * @param \Closure():array<mixed> $data Builds the event data (only when needed).
	 * @param string         $discriminator Discriminator.
	 * @return int 1 when recorded or requeued, else 0.
	 */
	private function ensure( \WC_Order $order, EventType $type, \Closure $data, string $discriminator = '' ): int {
		$existing = $this->events->find_by_uuid( EventQueue::event_uuid( $type, EventQueue::site_url(), (string) $order->get_order_key(), $discriminator ) );

		if ( null === $existing ) {
			return $this->enqueue( $order, $type, $data(), $discriminator ) ? 1 : 0;
		}

		if ( ! in_array( $existing->status, array( EventStatus::DEAD, EventStatus::CANCELLED ), true ) ) {
			return 0;
		}

		if ( ! $this->events->requeue( $existing->id, gmdate( 'Y-m-d H:i:s' ) ) ) {
			return 0;
		}

		$this->scheduler->deliver_now( $existing->id );
		if ( EventType::ORDER_PAID === $type ) {
			OrderMeta::update( $order, array( OrderMeta::EARN_STATE => OrderMeta::EARN_QUEUED ) );
		}

		return 1;
	}

	/**
	 * Refund ids `order.refunded` was recorded for.
	 *
	 * @param \WC_Order $order Order.
	 * @return list<int>
	 */
	private static function reported_refunds( \WC_Order $order ): array {
		$ids = OrderMeta::get( $order, OrderMeta::REPORTED_REFUNDS );

		return is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) ) : array();
	}

	/**
	 * Earn state matching an existing `order.paid` row.
	 *
	 * @param int $event_id Row id.
	 * @return string
	 */
	private function earn_state_of( int $event_id ): string {
		$event = $this->events->find( $event_id );
		if ( null === $event ) {
			return OrderMeta::EARN_QUEUED;
		}

		return match ( $event->status ) {
			EventStatus::DELIVERED => OrderMeta::EARN_DELIVERED,
			EventStatus::DEAD, EventStatus::CANCELLED => OrderMeta::EARN_DEAD,
			default => OrderMeta::EARN_QUEUED,
		};
	}

	/**
	 * Earn-related meta from a settlement result.
	 *
	 * @param array<mixed> $details `result` of the response.
	 * @return array<string,int|string>
	 */
	private static function earn_meta( array $details ): array {
		$meta = array();
		if ( isset( $details['points_earned'] ) && is_int( $details['points_earned'] ) ) {
			$meta[ OrderMeta::POINTS_EARNED ] = $details['points_earned'];
		}
		if ( isset( $details['earn_state'] ) && is_string( $details['earn_state'] ) ) {
			$meta[ OrderMeta::EARN_OUTCOME ] = $details['earn_state'];
		}
		if ( isset( $details['pos_transaction_id'] ) && is_scalar( $details['pos_transaction_id'] ) ) {
			$meta[ OrderMeta::POS_TRANSACTION_ID ] = (string) $details['pos_transaction_id'];
		}

		return $meta;
	}

	/**
	 * Order note for a delivered `order.paid`.
	 *
	 * @param string       $status  Response status.
	 * @param array<mixed> $details `result` of the response.
	 * @return string
	 */
	private static function paid_note( string $status, array $details ): string {
		if ( 'duplicate' === $status ) {
			return '';
		}

		if ( 'ignored' === $status ) {
			$reason = isset( $details['reason'] ) && is_string( $details['reason'] ) ? $details['reason'] : 'ignored';
			/* translators: %s: machine reason code. */
			return sprintf( __( 'Pixalink did not record this order (%s).', 'pixalink-loyalty-for-woocommerce' ), $reason );
		}

		$earn_state = isset( $details['earn_state'] ) && is_string( $details['earn_state'] ) ? $details['earn_state'] : '';
		$points     = isset( $details['points_earned'] ) && is_int( $details['points_earned'] ) ? $details['points_earned'] : 0;

		if ( 'pending_completion' === $earn_state ) {
			return __( 'Pixalink recorded the order. Points will be added when the order is completed.', 'pixalink-loyalty-for-woocommerce' );
		}

		if ( 'skipped' === $earn_state || ( 'earned' === $earn_state && $points < 1 ) ) {
			return __( 'Pixalink recorded the order. No points were earned for it.', 'pixalink-loyalty-for-woocommerce' );
		}

		if ( $points > 0 ) {
			/* translators: %d: points earned. */
			return sprintf( _n( 'Pixalink: %d point earned.', 'Pixalink: %d points earned.', $points, 'pixalink-loyalty-for-woocommerce' ), $points );
		}

		return __( 'Pixalink recorded the order.', 'pixalink-loyalty-for-woocommerce' );
	}

	/**
	 * Order note for a delivered `order.refunded`.
	 *
	 * Credits are a Pixalink balance, not money, so they are never shown with a currency symbol.
	 *
	 * @param Event        $event   Event.
	 * @param array<mixed> $details `result` of the response.
	 * @return string
	 */
	private static function refund_note( Event $event, array $details ): string {
		$refund    = isset( $event->payload['refund'] ) && is_array( $event->payload['refund'] ) ? $event->payload['refund'] : array();
		$refund_id = isset( $refund['id'] ) && is_numeric( $refund['id'] ) ? (int) $refund['id'] : 0;
		$restored  = isset( $details['restored'] ) && is_array( $details['restored'] ) ? $details['restored'] : array();

		$parts   = array();
		$points  = isset( $restored['points'] ) && is_int( $restored['points'] ) ? $restored['points'] : 0;
		$credits = isset( $restored['credits_cents'] ) && is_int( $restored['credits_cents'] ) ? $restored['credits_cents'] : 0;
		$voucher = isset( $restored['vouchers'] ) && is_array( $restored['vouchers'] ) ? count( $restored['vouchers'] ) : 0;

		if ( $points > 0 ) {
			/* translators: %d: points. */
			$parts[] = sprintf( _n( '%d point', '%d points', $points, 'pixalink-loyalty-for-woocommerce' ), $points );
		}
		if ( $credits > 0 ) {
			/* translators: %s: credit amount such as "2.50". */
			$parts[] = sprintf( __( '%s credits', 'pixalink-loyalty-for-woocommerce' ), number_format_i18n( $credits / 100, 2 ) );
		}
		if ( $voucher > 0 ) {
			/* translators: %d: number of vouchers. */
			$parts[] = sprintf( _n( '%d voucher', '%d vouchers', $voucher, 'pixalink-loyalty-for-woocommerce' ), $voucher );
		}

		if ( array() === $parts ) {
			/* translators: %d: refund id. */
			return sprintf( __( 'Pixalink recorded refund #%d.', 'pixalink-loyalty-for-woocommerce' ), $refund_id );
		}

		/* translators: 1: refund id, 2: list such as "50 points, 2.50 credits". */
		return sprintf( __( 'Pixalink recorded refund #%1$d and returned %2$s to the customer.', 'pixalink-loyalty-for-woocommerce' ), $refund_id, implode( ', ', $parts ) );
	}

	/**
	 * Load an order (not a refund) by id.
	 *
	 * @param mixed $order_id Order id.
	 * @return \WC_Order|null
	 */
	private static function order( $order_id ): ?\WC_Order {
		if ( ! is_numeric( $order_id ) || (int) $order_id < 1 ) {
			return null;
		}

		$order = wc_get_order( (int) $order_id );

		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * Load the order behind a `shop_order` post (posts storage only).
	 *
	 * @param mixed $post_id Post id.
	 * @return \WC_Order|null
	 */
	private static function order_post( $post_id ): ?\WC_Order {
		if ( ! is_numeric( $post_id ) || 'shop_order' !== get_post_type( (int) $post_id ) ) {
			return null;
		}

		return self::order( $post_id );
	}
}
