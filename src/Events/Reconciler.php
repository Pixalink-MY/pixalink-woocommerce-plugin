<?php
/**
 * Safety net for missed `order.paid` events, and merchant-requested resends.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Events;

use Pixalink\Loyalty\Orders\OrderLifecycle;
use Pixalink\Loyalty\Orders\OrderMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Daily: every order paid in the last 7 days that has no `_pixalink_earn_state` yet gets its `order.paid` recorded,
 * followed by whatever happened since (`order.completed`, `order.refunded`, `order.cancelled`) — orders paid while
 * the store was disconnected, a gateway that skipped `woocommerce_payment_complete`, a crash between payment and the
 * outbox insert. Orders whose `order.paid` is dead are left to the merchant.
 *
 * Manual resend (health panel): every paid order of the last N days, in Action Scheduler batches of 50, gets its
 * missing events recorded and its failed ones requeued (see {@see OrderLifecycle::resend()}).
 */
final class Reconciler {

	/**
	 * Orders per batch.
	 */
	public const BATCH_SIZE = 50;

	/**
	 * Look-back window of the daily run, in days.
	 */
	public const RECONCILE_DAYS = 7;

	/**
	 * Upper bound of batches in one daily run (10 000 orders).
	 */
	private const MAX_PAGES = 200;

	/**
	 * Order hooks.
	 *
	 * @var OrderLifecycle
	 */
	private OrderLifecycle $lifecycle;

	/**
	 * Action Scheduler wiring.
	 *
	 * @var Scheduler
	 */
	private Scheduler $scheduler;

	/**
	 * Constructor.
	 *
	 * @param OrderLifecycle $lifecycle Order hooks.
	 * @param Scheduler      $scheduler Action Scheduler wiring.
	 */
	public function __construct( OrderLifecycle $lifecycle, Scheduler $scheduler ) {
		$this->lifecycle = $lifecycle;
		$this->scheduler = $scheduler;
	}

	/**
	 * Register the Action Scheduler callbacks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( Scheduler::RECONCILE_HOOK, array( $this, 'handle_reconcile' ), 10, 0 );
		add_action( Scheduler::RESEND_HOOK, array( $this, 'handle_resend' ), 10, 2 );
	}

	/**
	 * `pixalink_loyalty_reconcile_events` callback.
	 *
	 * @return void
	 */
	public function handle_reconcile(): void {
		$this->reconcile();
	}

	/**
	 * `pixalink_loyalty_resend_orders` callback.
	 *
	 * @param mixed $days Look-back window in days.
	 * @param mixed $page 1-based batch number.
	 * @return void
	 */
	public function handle_resend( $days, $page = 1 ): void {
		$this->resend_batch( $days, $page );
	}

	/**
	 * Record events of recently paid orders that have none.
	 *
	 * @param int $days Look-back window in days.
	 * @return int Orders whose events were recorded.
	 */
	public function reconcile( int $days = self::RECONCILE_DAYS ): int {
		$recorded = 0;

		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$ids = $this->paid_order_ids( $days, $page );
			foreach ( $ids as $order_id ) {
				$order = wc_get_order( $order_id );
				// Dead events are left for the merchant (health panel): retrying them daily would loop forever.
				if ( ! $order instanceof \WC_Order || '' !== OrderMeta::earn_state( $order ) ) {
					continue;
				}

				// Paid, then completed, refunded or cancelled while disconnected: record the whole story in order.
				$recorded += $this->lifecycle->resend( $order ) > 0 ? 1 : 0;
			}

			if ( count( $ids ) < self::BATCH_SIZE ) {
				break;
			}
		}

		return $recorded;
	}

	/**
	 * Start a resend of the last N days (runs in the background).
	 *
	 * @param int $days Look-back window in days (1-365).
	 * @return void
	 */
	public function schedule_resend( int $days ): void {
		$this->scheduler->resend_page( max( 1, min( 365, $days ) ), 1 );
	}

	/**
	 * One batch of a resend, then enqueue the next.
	 *
	 * @param mixed $days Look-back window in days.
	 * @param mixed $page 1-based batch number.
	 * @return int Events recorded or requeued in this batch.
	 */
	public function resend_batch( $days, $page = 1 ): int {
		$days  = is_numeric( $days ) ? max( 1, min( 365, (int) $days ) ) : self::RECONCILE_DAYS;
		$page  = is_numeric( $page ) ? max( 1, (int) $page ) : 1;
		$ids   = $this->paid_order_ids( $days, $page );
		$count = 0;

		foreach ( $ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$count += $this->lifecycle->resend( $order );
			}
		}

		if ( count( $ids ) >= self::BATCH_SIZE ) {
			$this->scheduler->resend_page( $days, $page + 1 );
		}

		return $count;
	}

	/**
	 * Ids of orders paid within the window, oldest first.
	 *
	 * @param int $days Look-back window in days.
	 * @param int $page 1-based page.
	 * @return list<int>
	 */
	public function paid_order_ids( int $days, int $page ): array {
		$ids = wc_get_orders(
			array(
				'type'      => 'shop_order',
				'status'    => array_map( static fn ( string $status ): string => 'wc-' . $status, array_merge( OrderLifecycle::paid_statuses(), array( 'refunded', 'cancelled' ) ) ),
				'date_paid' => '>' . ( time() - $days * DAY_IN_SECONDS ),
				'orderby'   => 'ID',
				'order'     => 'ASC',
				'limit'     => self::BATCH_SIZE,
				'paged'     => $page,
				'return'    => 'ids',
			)
		);

		$order_ids = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			if ( is_numeric( $id ) ) {
				$order_ids[] = (int) $id;
			} elseif ( $id instanceof \WC_Order ) {
				$order_ids[] = (int) $id->get_id();
			}
		}

		return $order_ids;
	}
}
