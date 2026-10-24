<?php
/**
 * The one server-side cart mutation path for both checkouts.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Redemption;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Utilities\CartController;
use Pixalink\Loyalty\Identity\ShopperContextInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Store API `cart/extensions` callback for the `pixalink-loyalty` namespace (ADR 0001, decision 5).
 *
 * The Checkout block reaches it through `extensionCartUpdate( { namespace, data } )`; the classic checkout POSTs
 * the same body to /wc/store/v1/cart/extensions with the Store API `Nonce` header and then triggers
 * `update_checkout`. The Store API recalculates the cart after the callback and returns it, so the callback only
 * mutates. Actions (`data.action`):
 *
 * - `refresh_quote`: re-quote when the stored quote is stale (expired, another cart or another customer);
 * - `set_points` `{points}`: 0 removes the points coupon; clamped to the balance, the quote's cap, what the cart can
 *   absorb and the step;
 * - `set_credits` `{amount_cents}`: 0 removes the credits coupon; clamped likewise;
 * - `apply_voucher` / `remove_voucher` `{code}`: the coupon code or the Pixalink voucher code.
 *
 * Coupons are applied through the Store API's CartController::apply_coupon(): the same validation as
 * POST cart/apply-coupon, no wc_add_notice() leaking into the next classic page view, and no wc_coupons_enabled()
 * gate, so loyalty still works on stores that switched merchant coupons off.
 */
final class CartActions {

	public const STORE_API_NAMESPACE = 'pixalink-loyalty';

	/**
	 * Shopper state.
	 *
	 * @var RedemptionState
	 */
	private RedemptionState $state;

	/**
	 * Quotes.
	 *
	 * @var QuoteService
	 */
	private QuoteService $quotes;

	/**
	 * Who the shopper is.
	 *
	 * @var ShopperContextInterface
	 */
	private ShopperContextInterface $shopper;

	/**
	 * Kill switches.
	 *
	 * @var Features
	 */
	private Features $features;

	/**
	 * Constructor.
	 *
	 * @param RedemptionState         $state    Shopper state.
	 * @param QuoteService            $quotes   Quotes.
	 * @param ShopperContextInterface $shopper  Who the shopper is.
	 * @param Features                $features Kill switches.
	 */
	public function __construct( RedemptionState $state, QuoteService $quotes, ShopperContextInterface $shopper, Features $features ) {
		$this->state    = $state;
		$this->quotes   = $quotes;
		$this->shopper  = $shopper;
		$this->features = $features;
	}

	/**
	 * Handle `{ action, ... }`.
	 *
	 * @param mixed $data Request `data` object.
	 * @return void
	 * @throws RouteException On invalid input or a refused redemption (the Store API turns it into the error response).
	 */
	public function handle( $data ): void {
		$data   = is_array( $data ) ? $data : array();
		$action = isset( $data['action'] ) && is_string( $data['action'] ) ? $data['action'] : '';

		/**
		 * Fires for every Pixalink cart action, whichever checkout sent it.
		 *
		 * @since 0.2.0
		 *
		 * @param string              $action Action name.
		 * @param array<string,mixed> $data   Request data.
		 */
		do_action( 'pixalink_loyalty_cart_action', $action, $data );

		try {
			switch ( $action ) {
				case 'refresh_quote':
					// Only re-quotes a stale quote, so a chatty client cannot turn this into a request per call.
					$this->quotes->refresh( false, $this->cart() );
					return;
				case 'set_points':
					$this->set_points( self::int( $data['points'] ?? 0 ) );
					return;
				case 'set_credits':
					$this->set_credits( self::int( $data['amount_cents'] ?? 0 ) );
					return;
				case 'apply_voucher':
					$this->apply_voucher( self::string( $data['code'] ?? '' ) );
					return;
				case 'remove_voucher':
					$this->remove_voucher( self::string( $data['code'] ?? '' ) );
					return;
			}

			throw new RouteException( 'pixalink_loyalty_unknown_action', esc_html__( 'Unknown Pixalink action.', 'pixalink-loyalty-for-woocommerce' ), 400 );
		} finally {
			self::persist_session();
		}
	}

	/**
	 * Select points (0 removes the points coupon).
	 *
	 * @param int $requested Requested points.
	 * @return void
	 * @throws RouteException When points cannot be used or the amount is below the minimum.
	 */
	private function set_points( int $requested ): void {
		$cart = $this->cart();
		if ( $requested < 1 ) {
			$cart->remove_coupon( CouponMapper::POINTS_CODE );
			$this->state->update( array( 'points' => 0 ) );
			return;
		}

		$this->require_spendable( CouponMapper::KIND_POINTS );
		$config = $this->state->points_config();
		if ( null === $config ) {
			throw new RouteException( 'pixalink_loyalty_points_unavailable', esc_html( Labels::unavailable( CouponMapper::KIND_POINTS ) ), 409 );
		}

		$cart->calculate_totals();
		$remaining = CartPayload::remaining_cents( $cart, array( CouponMapper::KIND_POINTS, CouponMapper::KIND_CREDIT ) );
		$smallest  = PointsMath::smallest_points( $config );
		if ( PointsMath::max_points( $config, $remaining ) < $smallest ) {
			throw new RouteException(
				'pixalink_loyalty_points_insufficient',
				/* translators: %s: minimum number of points, formatted. */
				esc_html( sprintf( __( 'Paying with points starts at %s points, which this cart or your balance does not reach.', 'pixalink-loyalty-for-woocommerce' ), number_format_i18n( $smallest ) ) ),
				400
			);
		}

		$points = PointsMath::clamp_points( $requested, $config, $remaining );
		if ( 0 === $points || PointsMath::is_below_minimum( $points, $config ) ) {
			throw new RouteException(
				'pixalink_loyalty_points_below_minimum',
				/* translators: %s: minimum number of points, formatted. */
				esc_html( sprintf( __( 'Use at least %s points.', 'pixalink-loyalty-for-woocommerce' ), number_format_i18n( $smallest ) ) ),
				400
			);
		}

		$this->state->update( array( 'points' => $points ) );

		// Already applied: the new amount re-resolves from the state when the Store API recalculates.
		if ( ! $cart->has_discount( CouponMapper::POINTS_CODE ) ) {
			$this->apply_coupon( CouponMapper::POINTS_CODE );
		}
	}

	/**
	 * Select credits (0 removes the credits coupon).
	 *
	 * @param int $requested Requested credits in cents.
	 * @return void
	 * @throws RouteException When credits cannot be used.
	 */
	private function set_credits( int $requested ): void {
		$cart = $this->cart();
		if ( $requested < 1 ) {
			$cart->remove_coupon( CouponMapper::CREDITS_CODE );
			$this->state->update( array( 'credits_cents' => 0 ) );
			return;
		}

		$this->require_spendable( CouponMapper::KIND_CREDIT );
		$config = $this->state->credits_config();
		if ( null === $config ) {
			throw new RouteException( 'pixalink_loyalty_credits_unavailable', esc_html( Labels::unavailable( CouponMapper::KIND_CREDIT ) ), 409 );
		}

		$cart->calculate_totals();
		$cents = PointsMath::clamp_cents( $requested, $config['balance_cents'], $config['max_for_cart_cents'], CartPayload::remaining_cents( $cart, array( CouponMapper::KIND_CREDIT ) ) );
		if ( 0 === $cents ) {
			throw new RouteException( 'pixalink_loyalty_credits_unavailable', esc_html__( 'There is nothing left for credits to pay for in this cart.', 'pixalink-loyalty-for-woocommerce' ), 400 );
		}

		$this->state->update( array( 'credits_cents' => $cents ) );

		if ( ! $cart->has_discount( CouponMapper::CREDITS_CODE ) ) {
			$this->apply_coupon( CouponMapper::CREDITS_CODE );
		}
	}

	/**
	 * Apply a voucher from the quote, then fit points and credits into what is left of the cart.
	 *
	 * @param string $code Coupon code or Pixalink voucher code.
	 * @return void
	 * @throws RouteException When the voucher is not in the quote or WooCommerce refuses it.
	 */
	private function apply_voucher( string $code ): void {
		$this->require_spendable( CouponMapper::KIND_VOUCHER );

		$voucher = $this->state->voucher( $code );
		if ( null === $voucher ) {
			throw new RouteException( 'pixalink_loyalty_voucher_unknown', esc_html__( 'This Pixalink voucher is not available for your account.', 'pixalink-loyalty-for-woocommerce' ), 404 );
		}

		$cart        = $this->cart();
		$coupon_code = (string) $voucher['coupon_code'];
		if ( ! $cart->has_discount( $coupon_code ) ) {
			$this->apply_coupon( $coupon_code );
		}

		$this->refit( $cart );
	}

	/**
	 * Remove a voucher.
	 *
	 * @param string $code Coupon code or Pixalink voucher code.
	 * @return void
	 * @throws RouteException When the code is not one of ours.
	 */
	private function remove_voucher( string $code ): void {
		$voucher     = $this->state->voucher( $code );
		$coupon_code = null === $voucher ? CouponMapper::normalise( $code ) : (string) $voucher['coupon_code'];
		if ( CouponMapper::KIND_VOUCHER !== CouponMapper::kind_of( $coupon_code ) ) {
			throw new RouteException( 'pixalink_loyalty_voucher_unknown', esc_html__( 'This Pixalink voucher is not available for your account.', 'pixalink-loyalty-for-woocommerce' ), 404 );
		}

		$this->cart()->remove_coupon( $coupon_code );
	}

	/**
	 * After a voucher took part of the cart: lower points and credits that no longer fit (never raise them).
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return void
	 */
	private function refit( \WC_Cart $cart ): void {
		$points  = $this->state->points_config();
		$credits = $this->state->credits_config();
		if ( ( null === $points || 0 === $this->state->points() ) && ( null === $credits || 0 === $this->state->credits_cents() ) ) {
			return;
		}

		$cart->calculate_totals();
		$changes = array();

		if ( null !== $points && $this->state->points() > 0 ) {
			$fit = PointsMath::clamp_points( $this->state->points(), $points, CartPayload::remaining_cents( $cart, array( CouponMapper::KIND_POINTS, CouponMapper::KIND_CREDIT ) ) );
			if ( PointsMath::is_below_minimum( $fit, $points ) ) {
				$fit = 0;
			}
			if ( $fit !== $this->state->points() ) {
				$changes['points'] = $fit;
			}
		}

		if ( null !== $credits && $this->state->credits_cents() > 0 ) {
			$fit = PointsMath::clamp_cents( $this->state->credits_cents(), $credits['balance_cents'], $credits['max_for_cart_cents'], CartPayload::remaining_cents( $cart, array( CouponMapper::KIND_CREDIT ) ) );
			if ( $fit !== $this->state->credits_cents() ) {
				$changes['credits_cents'] = $fit;
			}
		}

		if ( array() === $changes ) {
			return;
		}

		$this->state->update( $changes );
		if ( 0 === ( $changes['points'] ?? null ) ) {
			$cart->remove_coupon( CouponMapper::POINTS_CODE );
		}
		if ( 0 === ( $changes['credits_cents'] ?? null ) ) {
			$cart->remove_coupon( CouponMapper::CREDITS_CODE );
		}
	}

	/**
	 * Apply one of our coupons through the Store API cart controller; a refused coupon is not left selected.
	 *
	 * @param string $code Coupon code.
	 * @return void
	 * @throws RouteException When WooCommerce refuses the coupon (carries our validity message).
	 */
	private function apply_coupon( string $code ): void {
		try {
			( new CartController() )->apply_coupon( $code );
		} catch ( RouteException $exception ) {
			$kind = CouponMapper::kind_of( $code );
			if ( CouponMapper::KIND_POINTS === $kind ) {
				$this->state->update( array( 'points' => 0 ) );
			} elseif ( CouponMapper::KIND_CREDIT === $kind ) {
				$this->state->update( array( 'credits_cents' => 0 ) );
			}

			throw $exception;
		}
	}

	/**
	 * Refuse spending for unverified shoppers and switched-off kinds; make sure a current quote is stored.
	 *
	 * @param string $kind Kind about to be spent.
	 * @return void
	 * @throws RouteException 403 verification_required, 409 when switched off, 503 without any quote.
	 */
	private function require_spendable( string $kind ): void {
		if ( ShopperContextInterface::STATE_VERIFIED !== $this->shopper->state() ) {
			throw new RouteException( 'pixalink_loyalty_verification_required', esc_html( Labels::verify_prompt() ), 403 );
		}

		if ( ! $this->features->enabled( $kind ) ) {
			throw new RouteException( 'pixalink_loyalty_unavailable', esc_html( Labels::unavailable( $kind ) ), 409 );
		}

		if ( ! $this->quotes->refresh( false, $this->cart() ) ) {
			throw new RouteException( 'pixalink_loyalty_quote_failed', esc_html__( 'We could not load your Pixalink rewards right now. Please try again in a moment.', 'pixalink-loyalty-for-woocommerce' ), 503 );
		}
	}

	/**
	 * Write the WC session now instead of at `shutdown:20`. This narrows, but cannot close, the last-writer-wins
	 * window for WooCommerce's own applied coupons (ADR pitfall 1); our state is safe because it lives outside it.
	 *
	 * @return void
	 */
	private static function persist_session(): void {
		$session = function_exists( 'WC' ) ? WC()->session : null;
		if ( $session instanceof \WC_Session_Handler ) {
			$session->save_data();
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

	/**
	 * Integer from JSON input.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private static function int( $value ): int {
		return is_numeric( $value ) ? (int) floor( (float) $value ) : 0;
	}

	/**
	 * String from JSON input.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function string( $value ): string {
		return is_string( $value ) ? trim( $value ) : '';
	}
}
