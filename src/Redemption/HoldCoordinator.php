<?php
/**
 * Holds placed when the shopper submits.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Redemption;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\Resources\Redemptions;
use Pixalink\Loyalty\Identity\ShopperContextInterface;
use Pixalink\Loyalty\Orders\OrderLifecycle;
use Pixalink\Loyalty\Orders\OrderMeta;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Places the holds at submit, never during preview (ADR 0001, decisions 3 and 4), with a declarative
 * `PUT orders/{order_key}/redemptions` built from the order's Pixalink coupon lines.
 *
 * | Checkout | Hook                                              | Order at that point                        | Refusal |
 * |----------|---------------------------------------------------|--------------------------------------------|---------|
 * | Classic  | `woocommerce_checkout_create_order` @20           | unsaved, no key (we set it)                | `\Exception` → WP_Error notice, nothing saved |
 * | Blocks   | `woocommerce_store_api_checkout_order_processed` @10 | saved, status already `pending`         | back to `checkout-draft`, `RouteException` 409 |
 *
 * A refusal re-quotes first, so the shopper sees the current balances and the clamped discount when they look
 * at the total again. A payment that fails releases the holds through the outbox (`order.redemptions_released`);
 * a cancelled order releases through `order.cancelled`; abandoned orders are left to {@see HoldSweeper}.
 *
 * Paying for an existing order (My Account "Pay", the order-pay link) runs neither checkout hook, so holds that
 * were released or expired since submit are placed again at `woocommerce_before_pay_action`, priced on the order
 * ({@see HoldCoordinator::hold_pay_for_order()}).
 */
final class HoldCoordinator {

	public const CLASSIC_PRIORITY = 20;
	public const BLOCKS_PRIORITY  = 10;

	/**
	 * Checkout API.
	 *
	 * @var Redemptions
	 */
	private Redemptions $api;

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
	 * Order events.
	 *
	 * @var OrderLifecycle
	 */
	private OrderLifecycle $lifecycle;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Order keys this request placed classic holds for (released again if the order is never saved).
	 *
	 * @var array<string,true>
	 */
	private array $classic_holds = array();

	/**
	 * Constructor.
	 *
	 * @param Redemptions             $api       Checkout API.
	 * @param QuoteService            $quotes    Quotes.
	 * @param ShopperContextInterface $shopper   Who the shopper is.
	 * @param OrderLifecycle          $lifecycle Order events.
	 * @param Logger                  $logger    Logger.
	 */
	public function __construct( Redemptions $api, QuoteService $quotes, ShopperContextInterface $shopper, OrderLifecycle $lifecycle, Logger $logger ) {
		$this->api       = $api;
		$this->quotes    = $quotes;
		$this->shopper   = $shopper;
		$this->lifecycle = $lifecycle;
		$this->logger    = $logger;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'hold_classic' ), self::CLASSIC_PRIORITY, 2 );
		add_action( 'woocommerce_checkout_order_exception', array( $this, 'release_unsaved_classic' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'hold_blocks' ), self::BLOCKS_PRIORITY, 1 );
		add_action( 'woocommerce_order_status_failed', array( $this, 'on_failed' ), 20, 2 );
		add_action( 'woocommerce_before_pay_action', array( $this, 'hold_pay_for_order' ), 10, 1 );
	}

	/**
	 * Classic checkout: the order is not saved yet and has no key; set it so the key we PUT to is the one saved.
	 *
	 * @param mixed $order Unsaved order.
	 * @param mixed $data  Posted checkout data.
	 * @return void
	 * @throws \Exception When Pixalink refuses (WC_Checkout::create_order() turns it into a WP_Error notice).
	 */
	public function hold_classic( $order, $data ): void {
		unset( $data );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Both data stores keep a key that is already set when they create the order.
		if ( '' === (string) $order->get_order_key() ) {
			$order->set_order_key( wc_generate_order_key() );
		}

		try {
			if ( $this->place( $order ) ) {
				$this->classic_holds[ (string) $order->get_order_key() ] = true;
			}
		} catch ( HoldRefusedException $refused ) {
			throw new \Exception( esc_html( $refused->getMessage() ) );
		}
	}

	/**
	 * Classic checkout failed after our hold (a later hook threw, or saving failed): release what we held.
	 *
	 * @param mixed $order Order that was not created.
	 * @return void
	 */
	public function release_unsaved_classic( $order ): void {
		if ( ! $order instanceof \WC_Order || $order->get_id() > 0 ) {
			return;
		}

		$key = (string) $order->get_order_key();
		if ( ! isset( $this->classic_holds[ $key ] ) ) {
			return;
		}

		unset( $this->classic_holds[ $key ] );
		try {
			$this->api->release( $key );
		} catch ( ApiException $exception ) {
			// The server's hold TTL is the backstop.
			$this->logger->warning( 'Could not release the holds of a classic checkout that failed after holding.', array( 'error_code' => $exception->error_code() ) );
		}
	}

	/**
	 * Blocks / Store API checkout: the order is saved and `pending`, before payment.
	 *
	 * On a refusal the order goes back to `checkout-draft` before the Store API sees the error: a pending order is
	 * only reused while the cart hash is unchanged, and the shopper is about to change the cart, which would orphan
	 * it (ADR pitfall 5).
	 *
	 * @param mixed $order Saved order.
	 * @return void
	 * @throws RouteException 409 `pixalink_loyalty_hold_failed` when Pixalink refuses.
	 */
	public function hold_blocks( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		try {
			if ( $this->place( $order ) ) {
				$order->save();
			}
		} catch ( HoldRefusedException $refused ) {
			$order->set_status( 'checkout-draft' );
			$order->save();

			throw new RouteException( 'pixalink_loyalty_hold_failed', esc_html( $refused->getMessage() ), 409, array( 'pixalink_code' => sanitize_key( $refused->error_code() ) ) );
		}
	}

	/**
	 * Pay-for-order (`woocommerce_before_pay_action`): an order whose Pixalink coupon lines are no longer held (the
	 * payment failed, or the holds were swept or expired) is held again before the gateway runs.
	 *
	 * When Pixalink refuses, the Pixalink coupons come off the order, its totals are recalculated and an error
	 * notice stops this payment (WC_Form_Handler::pay_action() skips the gateway once an error notice exists), so
	 * the shopper sees the full total before paying. When Pixalink cannot be reached, the payment stops and the
	 * discount stays for the next attempt.
	 *
	 * @param mixed $order Order about to be paid (the instance pay_action() saves afterwards).
	 * @return void
	 */
	public function hold_pay_for_order( $order ): void {
		if ( ! $order instanceof \WC_Order || ! self::needs_new_holds( $order ) ) {
			return;
		}

		try {
			$this->place( $order, CartPayload::from_order( $order ) );
		} catch ( HoldRefusedException $refused ) {
			if ( self::is_transient( $refused ) ) {
				wc_add_notice( $refused->getMessage(), 'error' );

				return;
			}

			$this->remove_redemptions( $order, $refused->error_code() );
			wc_add_notice( Labels::pay_rewards_removed(), 'error' );
		}
	}

	/**
	 * `woocommerce_order_status_failed` action: give the held value back.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $order    Order.
	 * @return void
	 */
	public function on_failed( $order_id, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : ( is_numeric( $order_id ) ? wc_get_order( (int) $order_id ) : null );
		if ( $order instanceof \WC_Order ) {
			$this->lifecycle->release_holds( $order, 'payment_failed' );
		}
	}

	/**
	 * PUT the order's redemptions and record the result.
	 *
	 * @param \WC_Order                $order Order (its coupon lines carry our meta).
	 * @param array<string,mixed>|null $cart  `cart` object to price the holds on (default: the shopper's cart).
	 * @return bool Whether a request was sent and accepted.
	 * @throws HoldRefusedException When Pixalink refuses or cannot be reached.
	 */
	public function place( \WC_Order $order, ?array $cart = null ): bool {
		$redemptions = self::redemptions( $order );
		$had_holds   = OrderMeta::is_held( $order );
		if ( array() === $redemptions && ! $had_holds ) {
			return false;
		}

		$customer_id = $this->shopper->pixalink_customer_id();
		if ( null === $customer_id ) {
			if ( array() !== $redemptions ) {
				throw new HoldRefusedException( 'verification_required', Labels::hold_refused( 'verification_required' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal; escaped where it is shown.
			}
			$customer_id = OrderMeta::get_string( $order, OrderMeta::CUSTOMER_ID );
			if ( '' === $customer_id ) {
				return false;
			}
		}

		$cart    = $cart ?? ( function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ? CartPayload::from_cart( WC()->cart, OrderMeta::hold_refs( $order ) ) : self::empty_cart() );
		$payload = Redemptions::hold_payload( $customer_id, $cart, $redemptions );

		try {
			$response = $this->api->hold( (string) $order->get_order_key(), $payload );
		} catch ( ApiException $exception ) {
			$this->logger->warning(
				'Pixalink refused or could not place the checkout holds.',
				array(
					'status'     => $exception->http_status(),
					'error_code' => $exception->error_code(),
				)
			);

			if ( $exception->http_status() >= 400 && $exception->http_status() < 500 ) {
				// Re-quote and recalculate, so the cart the Store API returns with the 409 shows the new total.
				$this->quotes->refresh( true );
				if ( function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ) {
					WC()->cart->calculate_totals();
				}
			}

			throw new HoldRefusedException( $exception->error_code(), Labels::hold_refused( $exception->error_code() ), $exception ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal; escaped where it is shown.
		}

		$this->record( $order, $customer_id, $redemptions, $response );

		return true;
	}

	/**
	 * PUT body entries from the order's Pixalink coupon lines: what WooCommerce actually discounted.
	 *
	 * @param \WC_Order $order Order.
	 * @return list<array<string,mixed>>
	 * @throws HoldRefusedException When a voucher line lost its voucher id (it must never discount unheld).
	 */
	public static function redemptions( \WC_Order $order ): array {
		$entries = array();
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Coupon ) {
				continue;
			}

			$entry = self::redemption( $item );
			if ( null !== $entry ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * One PUT body entry, or null for lines that are not ours or discount nothing.
	 *
	 * @param \WC_Order_Item_Coupon $item Coupon line.
	 * @return array<string,mixed>|null
	 * @throws HoldRefusedException When a voucher line has no voucher id.
	 */
	private static function redemption( \WC_Order_Item_Coupon $item ): ?array {
		$code = (string) $item->get_code();
		$kind = CouponMapper::kind_of( $code );
		if ( null === $kind ) {
			return null;
		}

		$entry = array(
			'kind'               => $kind,
			'coupon_code'        => $code,
			'discount_cents'     => max( 0, Money::to_cents( (float) $item->get_discount() ) ),
			'discount_tax_cents' => max( 0, Money::to_cents( (float) $item->get_discount_tax() ) ),
		);

		if ( CouponMapper::KIND_POINTS === $kind ) {
			$points = (int) $item->get_meta( OrderMeta::COUPON_POINTS, true );
			if ( $points < 1 ) {
				return null;
			}
			$entry['points'] = $points;

			return $entry;
		}

		if ( CouponMapper::KIND_CREDIT === $kind ) {
			$amount = (int) $item->get_meta( OrderMeta::COUPON_AMOUNT_CENTS, true );
			if ( $amount < 1 ) {
				return null;
			}
			$entry['amount_cents'] = $amount;

			return $entry;
		}

		$voucher_id = trim( (string) $item->get_meta( OrderMeta::COUPON_VOUCHER_ID, true ) );
		if ( '' === $voucher_id ) {
			throw new HoldRefusedException( 'voucher_unavailable', Labels::hold_refused( 'voucher_unavailable' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal; escaped where it is shown.
		}
		$entry['voucher_id'] = $voucher_id;

		return $entry;
	}

	/**
	 * Write the accepted holds onto the order and its coupon lines.
	 *
	 * @param \WC_Order                 $order       Order.
	 * @param string                    $customer_id Pixalink customer id.
	 * @param list<array<string,mixed>> $sent        PUT entries.
	 * @param array<mixed>              $response    PUT response.
	 * @return void
	 */
	private function record( \WC_Order $order, string $customer_id, array $sent, array $response ): void {
		$held     = isset( $response['redemptions'] ) && is_array( $response['redemptions'] ) ? array_values( $response['redemptions'] ) : array();
		$records  = array();
		$earliest = null;

		foreach ( $sent as $index => $entry ) {
			$hold = self::matching_hold( $held, $entry, $index );
			if ( null === $hold ) {
				continue;
			}

			$expires = isset( $hold['expires_at'] ) && is_string( $hold['expires_at'] ) ? strtotime( $hold['expires_at'] ) : false;
			if ( false !== $expires ) {
				$earliest = null === $earliest ? $expires : min( $earliest, $expires );
			}

			$records[] = array(
				'redemption_id' => (string) ( $hold['redemption_id'] ?? '' ),
				'kind'          => $entry['kind'],
				'status'        => (string) ( $hold['status'] ?? 'held' ),
				'expires_at'    => is_string( $hold['expires_at'] ?? null ) ? $hold['expires_at'] : null,
				'coupon_code'   => $entry['coupon_code'],
				'value_cents'   => (int) $entry['discount_cents'] + ( $order->get_prices_include_tax() ? (int) $entry['discount_tax_cents'] : 0 ),
			) + array_intersect_key( $entry, array_flip( array( 'points', 'voucher_id', 'amount_cents' ) ) );

			$this->stamp_line( $order, $entry['coupon_code'], (string) ( $hold['redemption_id'] ?? '' ) );
		}

		$generation = (int) OrderMeta::get_string( $order, OrderMeta::HOLD_GENERATION );
		$meta       = array(
			OrderMeta::REDEMPTIONS           => $records,
			OrderMeta::REDEMPTION_STATE      => array() === $records ? OrderMeta::REDEMPTION_RELEASED : OrderMeta::REDEMPTION_HELD,
			OrderMeta::REDEMPTION_EXPIRES_AT => $earliest,
			OrderMeta::HOLD_GENERATION       => array() === $records ? $generation : $generation + 1,
		);
		if ( '' === OrderMeta::get_string( $order, OrderMeta::CUSTOMER_ID ) ) {
			$meta[ OrderMeta::CUSTOMER_ID ] = $customer_id;
		}

		OrderMeta::update( $order, $meta );
	}

	/**
	 * The held entry answering a sent entry: same coupon code, else the same position.
	 *
	 * @param list<mixed>         $held  Response entries.
	 * @param array<string,mixed> $entry Sent entry.
	 * @param int                 $index Position of the sent entry.
	 * @return array<string,mixed>|null
	 */
	private static function matching_hold( array $held, array $entry, int $index ): ?array {
		foreach ( $held as $candidate ) {
			if ( is_array( $candidate ) && isset( $candidate['coupon_code'] ) && is_string( $candidate['coupon_code'] ) && CouponMapper::normalise( $candidate['coupon_code'] ) === CouponMapper::normalise( (string) $entry['coupon_code'] ) ) {
				return $candidate;
			}
		}

		$candidate = $held[ $index ] ?? null;

		return is_array( $candidate ) && ( $candidate['kind'] ?? null ) === $entry['kind'] ? $candidate : null;
	}

	/**
	 * Put the redemption id on the matching coupon line.
	 *
	 * @param \WC_Order $order         Order.
	 * @param string    $code          Coupon code.
	 * @param string    $redemption_id Hold reference.
	 * @return void
	 */
	private function stamp_line( \WC_Order $order, string $code, string $redemption_id ): void {
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Coupon && CouponMapper::normalise( (string) $item->get_code() ) === CouponMapper::normalise( $code ) ) {
				$item->update_meta_data( OrderMeta::COUPON_REDEMPTION_ID, $redemption_id );
				if ( $item->get_id() > 0 ) {
					$item->save_meta_data();
				}
			}
		}
	}

	/**
	 * Whether an order about to be paid carries Pixalink coupon lines whose holds are gone (released, or expired).
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	private static function needs_new_holds( \WC_Order $order ): bool {
		$state   = OrderMeta::get_string( $order, OrderMeta::REDEMPTION_STATE );
		$expires = (int) OrderMeta::get_string( $order, OrderMeta::REDEMPTION_EXPIRES_AT );
		$gone    = OrderMeta::REDEMPTION_RELEASED === $state || ( OrderMeta::REDEMPTION_HELD === $state && $expires > 0 && $expires <= time() );
		if ( ! $gone ) {
			return false;
		}

		foreach ( $order->get_coupon_codes() as $code ) {
			if ( CouponMapper::is_ours( (string) $code ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a refusal only means Pixalink could not answer now (network, 5xx, rate limit), not that it said no.
	 *
	 * @param HoldRefusedException $refused Refusal.
	 * @return bool
	 */
	private static function is_transient( HoldRefusedException $refused ): bool {
		$cause = $refused->getPrevious();
		if ( ! $cause instanceof ApiException || ApiException::NOT_CONNECTED === $cause->error_code() ) {
			return false;
		}

		$status = $cause->http_status();

		return 0 === $status || $status >= 500 || in_array( $status, array( 408, 425, 429 ), true );
	}

	/**
	 * Take the Pixalink coupons off an order whose holds were refused, and say so on the order.
	 *
	 * WC_Abstract_Order::remove_coupon() recalculates and saves the order.
	 *
	 * @param \WC_Order $order      Order.
	 * @param string    $error_code Server error code.
	 * @return void
	 */
	private function remove_redemptions( \WC_Order $order, string $error_code ): void {
		foreach ( $order->get_coupon_codes() as $code ) {
			if ( CouponMapper::is_ours( (string) $code ) ) {
				$order->remove_coupon( (string) $code );
			}
		}

		OrderMeta::update(
			$order,
			array(
				OrderMeta::REDEMPTIONS           => array(),
				OrderMeta::REDEMPTION_EXPIRES_AT => null,
			)
		);
		$order->add_order_note(
			sprintf(
				/* translators: %s: Pixalink error code. */
				__( 'Pixalink: the points, vouchers or credits on this order could not be held again before payment (%s), so they were removed and the total was recalculated.', 'pixalink-loyalty-for-woocommerce' ),
				$error_code
			)
		);
		$this->logger->info(
			'Pay-for-order: Pixalink refused new holds; the Pixalink coupons were removed from the order.',
			array(
				'order_id'   => $order->get_id(),
				'error_code' => $error_code,
			)
		);
	}

	/**
	 * A `cart` object for requests made without a shopper cart.
	 *
	 * @return array<string,mixed>
	 */
	private static function empty_cart(): array {
		$currency = Money::store_currency();

		return array(
			'lines'              => array(),
			'subtotal_cents'     => 0,
			'currency'           => '' !== $currency ? strtoupper( $currency ) : 'MYR',
			'shipping_cents'     => 0,
			'discount_cents'     => 0,
			'tax_cents'          => 0,
			'prices_include_tax' => wc_prices_include_tax(),
			'held_refs'          => array(),
		);
	}
}
