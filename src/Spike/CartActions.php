<?php
/**
 * SPIKE (P02, throwaway): the one server-side cart mutation path for both checkouts.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Spike;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Utilities\CartController;

defined( 'ABSPATH' ) || exit;

/**
 * Store API `cart/extensions` callback for namespace `pixalink-loyalty`.
 *
 * Blocks reach it through `extensionCartUpdate()`; the classic checkout POSTs the same JSON to
 * /wc/store/v1/cart/extensions with the `wc_store_api` nonce and then triggers `update_checkout`.
 * The Store API recalculates the cart and returns it after the callback, so the callback only mutates.
 */
final class CartActions {

	/**
	 * Handle `{ action, ... }`.
	 *
	 * @param mixed $data Request `data` object.
	 * @return void
	 * @throws RouteException On invalid input or a refused redemption (the Store API turns it into the error response).
	 */
	public function handle( $data ): void {
		$data   = is_array( $data ) ? $data : array();
		$action = is_string( $data['action'] ?? null ) ? $data['action'] : '';

		/**
		 * Fires for every Pixalink cart action, whichever checkout sent it.
		 *
		 * @since 0.0.0 Spike only.
		 *
		 * @param string              $action Action name.
		 * @param array<string,mixed> $data   Request data.
		 */
		do_action( 'pixalink_loyalty_spike_cart_action', $action, $data );

		try {
			switch ( $action ) {
				case 'spike_link':
					$this->link();
					return;
				case 'set_points':
					$this->set_points( (int) ( $data['points'] ?? 0 ) );
					return;
				case 'apply_voucher':
					$this->apply_voucher( is_string( $data['code'] ?? null ) ? $data['code'] : '' );
					return;
				case 'remove_voucher':
					$this->remove_voucher( is_string( $data['code'] ?? null ) ? $data['code'] : '' );
					return;
			}

			throw new RouteException( 'pixalink_loyalty_unknown_action', esc_html__( 'Unknown Pixalink action.', 'pixalink-loyalty-for-woocommerce' ), 400 );
		} finally {
			self::persist_session();
		}
	}

	/**
	 * Write the session now instead of at `shutdown:20`. The WC session is one last-writer-wins blob, and other
	 * requests on the same session can load it before that save and write their copy back afterwards. Action
	 * Scheduler's async queue runner does exactly that: at `shutdown:10` it fires a loopback admin-ajax request that
	 * forwards this shopper's cookies ($_COOKIE). The e2e run lost the linked state that way on its first attempt.
	 *
	 * @return void
	 */
	private static function persist_session(): void {
		$session = function_exists( 'WC' ) ? WC()->session : null;
		if ( is_object( $session ) && method_exists( $session, 'save_data' ) ) {
			$session->save_data();
		}
	}

	/**
	 * Spike stand-in for "OTP verified + quote fetched": marks the shopper verified with the canned quote.
	 *
	 * @return void
	 */
	private function link(): void {
		RedemptionState::update(
			array(
				'verified'    => true,
				'customer_id' => SpikeQuote::CUSTOMER_ID,
				'quote'       => SpikeQuote::canned(),
			)
		);
	}

	/**
	 * Select a number of points (0 removes the points coupon).
	 *
	 * @param int $requested Requested points.
	 * @return void
	 * @throws RouteException When the shopper is not verified or the amount is below the minimum.
	 */
	private function set_points( int $requested ): void {
		$this->require_verified();

		$config = RedemptionState::points_config();
		$cap    = min( $config['available'], $config['max_for_cart'] );
		$points = (int) ( floor( min( max( 0, $requested ), $cap ) / $config['step'] ) * $config['step'] );

		if ( $points > 0 && $points < $config['min'] ) {
			throw new RouteException(
				'pixalink_loyalty_points_below_minimum',
				/* translators: %s: minimum points. */
				esc_html( sprintf( __( 'Redeem at least %s points.', 'pixalink-loyalty-for-woocommerce' ), number_format_i18n( $config['min'] ) ) ),
				400
			);
		}

		$cart = $this->cart();
		if ( 0 === $points ) {
			$cart->remove_coupon( CouponMapper::POINTS_CODE );
			RedemptionState::update( array( 'points' => 0 ) );
			return;
		}

		RedemptionState::update( array( 'points' => $points ) );

		// Already applied: the amount re-resolves from the state when the Store API recalculates after this callback.
		if ( ! $cart->has_discount( CouponMapper::POINTS_CODE ) ) {
			$this->apply_coupon( CouponMapper::POINTS_CODE );
		}
	}

	/**
	 * Apply a voucher from the quote.
	 *
	 * @param string $code Voucher code.
	 * @return void
	 * @throws RouteException When the voucher is not in the quote or WooCommerce refuses it.
	 */
	private function apply_voucher( string $code ): void {
		$this->require_verified();

		$voucher = RedemptionState::voucher( $code );
		if ( null === $voucher ) {
			throw new RouteException( 'pixalink_loyalty_voucher_unknown', esc_html__( 'This Pixalink voucher is not available for your account.', 'pixalink-loyalty-for-woocommerce' ), 404 );
		}

		$code     = wc_format_coupon_code( $code );
		$selected = RedemptionState::get()['vouchers'];
		if ( ! in_array( $code, $selected, true ) ) {
			$selected[] = $code;
		}
		RedemptionState::update( array( 'vouchers' => $selected ) );

		if ( ! $this->cart()->has_discount( $code ) ) {
			$this->apply_coupon( $code );
		}
	}

	/**
	 * Remove a voucher.
	 *
	 * @param string $code Voucher code.
	 * @return void
	 */
	private function remove_voucher( string $code ): void {
		$this->cart()->remove_coupon( wc_format_coupon_code( $code ) );
	}

	/**
	 * Apply through the Store API cart controller: same validation as POST /cart/apply-coupon, no wc_add_notice()
	 * side effects (classic WC_Cart::apply_coupon() would queue notices for the next page view), and no
	 * wc_coupons_enabled() gate, so loyalty still works on stores that switched merchant coupons off.
	 *
	 * @param string $code Coupon code.
	 * @return void
	 * @throws RouteException When WooCommerce refuses the coupon (carries the woocommerce_coupon_is_valid message).
	 */
	private function apply_coupon( string $code ): void {
		try {
			( new CartController() )->apply_coupon( $code );
		} catch ( RouteException $exception ) {
			$this->deselect( $code );
			throw $exception;
		}
	}

	/**
	 * Drop a code from the selection after WooCommerce refused it.
	 *
	 * @param string $code Coupon code.
	 * @return void
	 */
	private function deselect( string $code ): void {
		if ( wc_is_same_coupon( $code, CouponMapper::POINTS_CODE ) ) {
			RedemptionState::update( array( 'points' => 0 ) );
			return;
		}

		$remaining = array_values(
			array_filter(
				RedemptionState::get()['vouchers'],
				static fn ( string $selected ): bool => ! wc_is_same_coupon( $selected, $code )
			)
		);
		RedemptionState::update( array( 'vouchers' => $remaining ) );
	}

	/**
	 * Refuse spending for unverified shoppers.
	 *
	 * @return void
	 * @throws RouteException 403 verification_required.
	 */
	private function require_verified(): void {
		if ( ! RedemptionState::is_verified() ) {
			throw new RouteException( 'pixalink_loyalty_verification_required', esc_html( VirtualCoupons::verify_message() ), 403 );
		}
	}

	/**
	 * The cart.
	 *
	 * @return \WC_Cart
	 * @throws RouteException When there is no cart.
	 */
	private function cart(): \WC_Cart {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		if ( ! $cart instanceof \WC_Cart ) {
			throw new RouteException( 'pixalink_loyalty_no_cart', esc_html__( 'Cart unavailable.', 'pixalink-loyalty-for-woocommerce' ), 500 );
		}

		return $cart;
	}
}
