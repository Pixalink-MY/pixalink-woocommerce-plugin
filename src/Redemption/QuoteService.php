<?php
/**
 * Fetches and keeps the shopper's quote current.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Redemption;

use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\Resources\Redemptions;
use Pixalink\Loyalty\Identity\ShopperContextInterface;
use Pixalink\Loyalty\Orders\OrderMeta;
use Pixalink\Loyalty\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * `POST quote` for the current cart, stored in {@see RedemptionState}.
 *
 * Only verified shoppers are quoted. A quote is re-fetched when it is missing, past its `ttl_seconds`, for another
 * customer, or for a different cart (lines, prices or store coupons changed); bursts for the same cart are
 * debounced. The cart carries the `held_refs` of the shopper's current order, so a shopper retrying a held order
 * is not told their own hold made them short of points.
 *
 * After a new quote the selection is reconciled with it: points and credits are clamped to the new caps, and
 * Pixalink coupons the quote no longer offers are taken off the cart. When a re-quote fails the last quote stays
 * usable, unless it was priced in another currency than the cart's: then it is dropped with the discounts built on it.
 */
final class QuoteService {

	/**
	 * Seconds within which a second quote for the same cart is not sent.
	 */
	public const DEBOUNCE_SECONDS = 3;

	/**
	 * Order statuses whose holds still count as the shopper's own.
	 */
	public const HOLDING_STATUSES = array( 'checkout-draft', 'pending', 'failed' );

	/**
	 * Checkout API.
	 *
	 * @var Redemptions
	 */
	private Redemptions $api;

	/**
	 * Shopper state.
	 *
	 * @var RedemptionState
	 */
	private RedemptionState $state;

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
	 * @param Redemptions             $api      Checkout API.
	 * @param RedemptionState         $state    Shopper state.
	 * @param ShopperContextInterface $shopper  Who the shopper is.
	 * @param Features                $features Kill switches.
	 * @param Logger                  $logger   Logger.
	 * @param \Closure|null           $now      Unix time source (tests).
	 */
	public function __construct( Redemptions $api, RedemptionState $state, ShopperContextInterface $shopper, Features $features, Logger $logger, ?\Closure $now = null ) {
		$this->api      = $api;
		$this->state    = $state;
		$this->shopper  = $shopper;
		$this->features = $features;
		$this->logger   = $logger;
		$this->now      = $now ?? static fn (): int => time();
	}

	/**
	 * Whether the stored quote no longer describes this shopper and cart.
	 *
	 * Always false for shoppers who cannot be quoted (nothing to refresh).
	 *
	 * @param \WC_Cart|null $cart Cart (defaults to WC()->cart).
	 * @return bool
	 */
	public function is_stale( ?\WC_Cart $cart = null ): bool {
		$cart        = $cart ?? self::cart();
		$customer_id = $this->shopper->pixalink_customer_id();
		if ( null === $cart || null === $customer_id || ! $this->features->any_enabled() ) {
			return false;
		}

		return ! $this->state->is_quote_fresh()
			|| $this->state->customer_id() !== $customer_id
			|| $this->state->quote_hash() !== CartPayload::signature( CartPayload::from_cart( $cart ) );
	}

	/**
	 * Fetch a quote when the stored one is stale (or always, when forced).
	 *
	 * @param bool          $force     Quote even when the stored quote is current.
	 * @param \WC_Cart|null $cart      Cart (defaults to WC()->cart).
	 * @param bool          $reconcile Fit the selection and the applied coupons to the new quote (not while
	 *                                 WooCommerce is validating coupons).
	 * @return bool Whether a usable quote for this shopper is stored afterwards.
	 */
	public function refresh( bool $force = false, ?\WC_Cart $cart = null, bool $reconcile = true ): bool {
		$cart        = $cart ?? self::cart();
		$customer_id = $this->shopper->pixalink_customer_id();
		if ( null === $cart || null === $customer_id || ! $this->features->any_enabled() || ! $this->state->is_available() ) {
			return false;
		}

		$payload   = CartPayload::from_cart( $cart, $this->held_refs() );
		$signature = CartPayload::signature( $payload );
		$same      = $this->state->customer_id() === $customer_id && $this->state->quote_hash() === $signature;

		if ( $same && ! $force && ( $this->state->is_quote_fresh() || ( $this->now )() - $this->state->quoted_at() < self::DEBOUNCE_SECONDS ) ) {
			return true;
		}

		try {
			$quote = $this->api->quote( Redemptions::quote_payload( $customer_id, $payload ) );
		} catch ( ApiException $exception ) {
			$this->state->record_quote_error( $exception->error_code() );
			$this->logger->warning(
				'Pixalink quote failed; keeping the last quote.',
				array(
					'status'     => $exception->http_status(),
					'error_code' => $exception->error_code(),
				)
			);

			if ( ! $this->state->has_quote() || $this->state->customer_id() !== $customer_id ) {
				return false;
			}

			if ( strtoupper( (string) ( $this->state->quote()['currency'] ?? '' ) ) !== $payload['currency'] ) {
				// A quote in another currency prices nothing in this one: drop it and the discounts built on it.
				$this->state->update(
					array(
						'quote'         => array(),
						'quote_hash'    => '',
						'points'        => 0,
						'credits_cents' => 0,
					)
				);
				if ( $reconcile ) {
					$this->reconcile( $cart );
				}

				return false;
			}

			return true;
		}

		$this->state->store_quote( $customer_id, self::string_keys( $quote ), $signature );
		if ( $reconcile ) {
			$this->reconcile( $cart );
		}

		return true;
	}

	/**
	 * Clamp the selection to the stored quote and take coupons the quote no longer offers off the cart.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return void
	 */
	public function reconcile( \WC_Cart $cart ): void {
		$points  = $this->state->points_config();
		$credits = $this->state->credits_config();
		$changes = array();

		if ( $this->state->points() > 0 ) {
			$clamped = null === $points || ! $this->features->enabled( CouponMapper::KIND_POINTS )
				? 0
				: min( $this->state->points(), PointsMath::max_points( $points, CartPayload::remaining_cents( $cart, array( CouponMapper::KIND_POINTS, CouponMapper::KIND_CREDIT ) ) ) );
			if ( null !== $points && PointsMath::is_below_minimum( $clamped, $points ) ) {
				$clamped = 0;
			}
			if ( $clamped !== $this->state->points() ) {
				$changes['points'] = $clamped;
			}
		}

		if ( $this->state->credits_cents() > 0 ) {
			$clamped = null === $credits || ! $this->features->enabled( CouponMapper::KIND_CREDIT )
				? 0
				: PointsMath::clamp_cents( $this->state->credits_cents(), $credits['balance_cents'], $credits['max_for_cart_cents'], CartPayload::remaining_cents( $cart, array( CouponMapper::KIND_CREDIT ) ) );
			if ( $clamped !== $this->state->credits_cents() ) {
				$changes['credits_cents'] = $clamped;
			}
		}

		if ( array() !== $changes ) {
			$this->state->update( $changes );
		}

		foreach ( $cart->get_applied_coupons() as $code ) {
			$code = (string) $code;
			if ( ! $this->still_offered( $code ) ) {
				$cart->remove_coupon( $code );
			}
		}
	}

	/**
	 * Holds (`CH-…`) the shopper's current order carries: the Store API draft order or the order awaiting payment.
	 *
	 * @return list<string>
	 */
	public function held_refs(): array {
		$order = $this->current_order();

		return null === $order ? array() : OrderMeta::hold_refs( $order );
	}

	/**
	 * The shopper's current order from the WC session, if it still holds value.
	 *
	 * @return \WC_Order|null
	 */
	public function current_order(): ?\WC_Order {
		$session = function_exists( 'WC' ) ? WC()->session : null;
		if ( ! $session instanceof \WC_Session ) {
			return null;
		}

		foreach ( array( 'store_api_draft_order', 'order_awaiting_payment' ) as $key ) {
			$order_id = $session->get( $key );
			if ( ! is_numeric( $order_id ) || (int) $order_id < 1 ) {
				continue;
			}

			$order = wc_get_order( (int) $order_id );
			if ( $order instanceof \WC_Order && $order->has_status( self::HOLDING_STATUSES ) && OrderMeta::is_held( $order ) ) {
				return $order;
			}
		}

		return null;
	}

	/**
	 * Whether an applied coupon is still something the quote offers (store coupons always are).
	 *
	 * @param string $code Coupon code.
	 * @return bool
	 */
	private function still_offered( string $code ): bool {
		$kind = CouponMapper::kind_of( $code );
		if ( null === $kind ) {
			return true;
		}

		if ( ! $this->features->enabled( $kind ) ) {
			return false;
		}

		if ( CouponMapper::KIND_POINTS === $kind ) {
			return $this->state->points() > 0 && null !== $this->state->points_config();
		}

		if ( CouponMapper::KIND_CREDIT === $kind ) {
			return $this->state->credits_cents() > 0 && null !== $this->state->credits_config();
		}

		$voucher = $this->state->voucher( $code );

		return null !== $voucher && true === ( $voucher['eligible'] ?? false ) && is_array( $voucher['woo_coupon'] ?? null );
	}

	/**
	 * The shopper's cart.
	 *
	 * @return \WC_Cart|null
	 */
	private static function cart(): ?\WC_Cart {
		return function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ? WC()->cart : null;
	}

	/**
	 * Re-key a decoded response with string keys.
	 *
	 * @param array<mixed> $value Response.
	 * @return array<string,mixed>
	 */
	private static function string_keys( array $value ): array {
		$keyed = array();
		foreach ( $value as $key => $item ) {
			$keyed[ (string) $key ] = $item;
		}

		return $keyed;
	}
}
