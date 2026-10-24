<?php
/**
 * Releases the holds of abandoned orders.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Redemption;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\Resources\Redemptions;
use Pixalink\Loyalty\Orders\OrderMeta;
use Pixalink\Loyalty\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Every 15 minutes (Action Scheduler, `pixalink_loyalty_sweep_holds`), `DELETE orders/{order_key}/redemptions` for
 * unpaid orders whose holds outlived their TTL: the shopper went to a payment page and never came back, or left
 * a checkout draft behind.
 *
 * An order counts as abandoned when its holds' earliest `expires_at` has passed, or when it is a checkout draft
 * not touched for an hour. Pixalink's own TTL is the backstop; this keeps the store's view (order meta and
 * notes) in step and gives the value back sooner. A paid order is never swept: settlement takes its holds.
 */
final class HoldSweeper {

	public const HOOK = 'pixalink_loyalty_sweep_holds';

	/**
	 * Interval of the recurring action in seconds.
	 */
	public const INTERVAL = 900;

	/**
	 * Orders per run.
	 */
	public const BATCH = 50;

	/**
	 * Seconds after which an untouched checkout draft counts as abandoned.
	 */
	public const DRAFT_IDLE_SECONDS = 3600;

	/**
	 * Unpaid statuses whose holds may be swept.
	 */
	public const STATUSES = array( 'checkout-draft', 'pending', 'failed' );

	/**
	 * Checkout API.
	 *
	 * @var Redemptions
	 */
	private Redemptions $api;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Unix time source.
	 *
	 * @var \Closure():int
	 */
	private \Closure $now;

	/**
	 * Constructor.
	 *
	 * @param Redemptions   $api    Checkout API.
	 * @param Logger        $logger Logger.
	 * @param \Closure|null $now    Unix time source (tests).
	 */
	public function __construct( Redemptions $api, Logger $logger, ?\Closure $now = null ) {
		$this->api    = $api;
		$this->logger = $logger;
		$this->now    = $now ?? static fn (): int => time();
	}

	/**
	 * Register the action callback (the recurring schedule lives in {@see \Pixalink\Loyalty\Events\Scheduler::RECURRING}).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'handle_scheduled' ), 10, 0 );
	}

	/**
	 * Action Scheduler callback; never throws.
	 *
	 * @return void
	 */
	public function handle_scheduled(): void {
		$this->sweep();
	}

	/**
	 * Release the holds of abandoned orders.
	 *
	 * @return int Orders released.
	 */
	public function sweep(): int {
		$query = array(
			'limit'   => self::BATCH,
			'status'  => self::STATUSES,
			'orderby' => 'date',
			'order'   => 'ASC',
			'type'    => 'shop_order',
		);

		// HPOS queries meta through meta_query; the posts store only takes WP_Query's meta_key / meta_value.
		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$query['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded batch on the order meta index, every 15 minutes.
				array(
					'key'   => OrderMeta::REDEMPTION_STATE,
					'value' => OrderMeta::REDEMPTION_HELD,
				),
			);
		} else {
			$query['meta_key']   = OrderMeta::REDEMPTION_STATE; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- As above.
			$query['meta_value'] = OrderMeta::REDEMPTION_HELD; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
		}

		$orders = wc_get_orders( $query );

		$released = 0;
		foreach ( is_array( $orders ) ? $orders : array() as $order ) {
			if ( $order instanceof \WC_Order && $this->is_abandoned( $order ) && $this->release( $order ) ) {
				++$released;
			}
		}

		return $released;
	}

	/**
	 * Whether an unpaid order's holds should be given back now.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public function is_abandoned( \WC_Order $order ): bool {
		if ( ! OrderMeta::is_held( $order ) || ! $order->has_status( self::STATUSES ) || null !== $order->get_date_paid() ) {
			return false;
		}

		$now     = ( $this->now )();
		$expires = (int) OrderMeta::get_string( $order, OrderMeta::REDEMPTION_EXPIRES_AT );
		if ( $expires > 0 && $expires <= $now ) {
			return true;
		}

		$modified = $order->get_date_modified() ?? $order->get_date_created();

		return $order->has_status( 'checkout-draft' ) && null !== $modified && $modified->getTimestamp() <= $now - self::DRAFT_IDLE_SECONDS;
	}

	/**
	 * DELETE the order's holds and record it.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool Whether Pixalink confirmed.
	 */
	public function release( \WC_Order $order ): bool {
		try {
			$this->api->release( (string) $order->get_order_key() );
		} catch ( ApiException $exception ) {
			$this->logger->warning(
				'Could not release the holds of an abandoned order; retrying on the next sweep.',
				array(
					'order_id'   => $order->get_id(),
					'error_code' => $exception->error_code(),
				)
			);

			return false;
		}

		OrderMeta::update( $order, array( OrderMeta::REDEMPTION_STATE => OrderMeta::REDEMPTION_RELEASED ) );
		$order->add_order_note( __( 'Pixalink: the points, vouchers or credits held for this unpaid order were released because it was abandoned.', 'pixalink-loyalty-for-woocommerce' ) );

		return true;
	}
}
