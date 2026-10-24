<?php
/**
 * SPIKE (P02, throwaway): commit-at-submit holds via declarative PUT orders/{order_key}/redemptions.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Spike;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Places the holds when the shopper submits, and aborts the checkout when Pixalink refuses them.
 *
 * Classic: `woocommerce_checkout_create_order` @20 runs on an unsaved WC_Order (no ID, empty order key); throwing
 * there makes WC_Checkout::create_order() return a WP_Error, so nothing is saved and the message becomes a notice.
 * Blocks: `woocommerce_store_api_checkout_order_processed` runs on the saved order, already moved to `pending`,
 * before payment; a RouteException becomes the REST error the Checkout block shows.
 */
final class HoldCoordinator {

	/**
	 * Classic hook priority: after core/most extensions adjusted the order, before save.
	 */
	public const CLASSIC_PRIORITY = 20;

	/**
	 * Blocks hook priority.
	 */
	public const BLOCKS_PRIORITY = 10;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'hold_classic' ), self::CLASSIC_PRIORITY, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'hold_blocks' ), self::BLOCKS_PRIORITY );
	}

	/**
	 * Whether holds are placed (integration tests that only check totals switch them off).
	 *
	 * @return bool
	 */
	private static function enabled(): bool {
		/**
		 * Whether the spike places holds at submit.
		 *
		 * @since 0.0.0 Spike only.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'pixalink_loyalty_spike_hold_enabled', true );
	}

	/**
	 * Classic checkout.
	 *
	 * @param mixed $order Unsaved order.
	 * @param mixed $data  Posted checkout data.
	 * @return void
	 * @throws \Exception When Pixalink refuses the holds (WC_Checkout::create_order() turns it into a WP_Error).
	 */
	public function hold_classic( $order, $data ): void {
		unset( $data );
		if ( ! $order instanceof \WC_Order || ! self::enabled() ) {
			return;
		}

		// A new classic order has no key until the data store creates it; both data stores keep a key set here.
		if ( '' === $order->get_order_key() ) {
			$order->set_order_key( wc_generate_order_key() );
		}

		try {
			$this->put( $order );
		} catch ( ApiException $exception ) {
			throw new \Exception( esc_html( self::shopper_message( $exception ) ) );
		}
	}

	/**
	 * Blocks / Store API checkout.
	 *
	 * @param mixed $order Saved order (status pending).
	 * @return void
	 * @throws RouteException 409 when Pixalink refuses the holds.
	 */
	public function hold_blocks( $order ): void {
		if ( ! $order instanceof \WC_Order || ! self::enabled() ) {
			return;
		}

		try {
			$this->put( $order );
			$order->save();
		} catch ( ApiException $exception ) {
			/**
			 * Whether to put the order back to checkout-draft before refusing the checkout.
			 *
			 * The Store API moved the order to `pending` just before this hook and re-saves it when we throw. A pending
			 * order is only reused on retry while the cart hash is unchanged, and the shopper is about to change the
			 * cart (fewer points), which would orphan it. Back to checkout-draft keeps it reusable.
			 *
			 * @since 0.0.0 Spike only.
			 *
			 * @param bool $restore Default true.
			 */
			if ( (bool) apply_filters( 'pixalink_loyalty_spike_restore_draft_on_hold_failure', true ) ) {
				$order->set_status( 'checkout-draft' );
			}

			throw new RouteException( 'pixalink_loyalty_hold_failed', esc_html( self::shopper_message( $exception ) ), 409 );
		}
	}

	/**
	 * Declarative PUT of every Pixalink coupon line on the order (empty list = release all).
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 * @throws ApiException When Pixalink refuses.
	 */
	private function put( \WC_Order $order ): void {
		$redemptions = self::redemptions( $order );
		$had_holds   = '' !== (string) $order->get_meta( '_pixalink_redemption_state' );
		if ( array() === $redemptions && ! $had_holds ) {
			return;
		}

		$response = Plugin::instance()->client()->put(
			'orders/' . rawurlencode( $order->get_order_key() ) . '/redemptions',
			array(
				'customer_id' => RedemptionState::get()['customer_id'],
				'currency'    => $order->get_currency(),
				'redemptions' => $redemptions,
			)
		);

		$held = is_array( $response->data['redemptions'] ?? null ) ? $response->data['redemptions'] : array();
		$order->update_meta_data( '_pixalink_redemptions', $held );
		$order->update_meta_data( '_pixalink_redemption_state', array() === $held ? 'released' : 'held' );
	}

	/**
	 * Request body entries built from the order's coupon lines (what WooCommerce actually discounted).
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int,array<string,mixed>>
	 */
	public static function redemptions( \WC_Order $order ): array {
		$redemptions = array();
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Coupon ) {
				continue;
			}

			$kind = (string) $item->get_meta( '_pixalink_kind' );
			if ( 'points' !== $kind && 'voucher' !== $kind ) {
				continue;
			}

			$entry = array(
				'kind'         => $kind,
				'coupon_code'  => $item->get_code(),
				'discount'     => wc_format_decimal( $item->get_discount(), 2 ),
				'discount_tax' => wc_format_decimal( $item->get_discount_tax(), 2 ),
			);

			if ( 'points' === $kind ) {
				$entry['points'] = (int) $item->get_meta( '_pixalink_points' );
			} else {
				$entry['voucher_id'] = (string) $item->get_meta( '_pixalink_voucher_id' );
			}

			$redemptions[] = $entry;
		}

		return $redemptions;
	}

	/**
	 * Shopper-facing message for a refused hold.
	 *
	 * @param ApiException $exception Error.
	 * @return string
	 */
	private static function shopper_message( ApiException $exception ): string {
		switch ( $exception->error_code() ) {
			case 'points_insufficient':
				return __( 'You no longer have enough Pixalink points for this discount. Lower the points and place the order again.', 'pixalink-loyalty-for-woocommerce' );
			case 'voucher_unavailable':
				return __( 'One of your Pixalink vouchers can no longer be used. Remove it and place the order again.', 'pixalink-loyalty-for-woocommerce' );
		}

		return __( 'We could not reserve your Pixalink points or vouchers. Please try again.', 'pixalink-loyalty-for-woocommerce' );
	}
}
