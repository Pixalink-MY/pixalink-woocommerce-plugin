<?php
/**
 * Vouchers, points and credits as virtual WooCommerce coupons.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Redemption;

use Pixalink\Loyalty\Identity\ShopperContextInterface;
use Pixalink\Loyalty\Orders\OrderMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves `pxl-points`, `pxl-credits` and `pxl-<voucher>` codes from the stored quote (ADR 0001, decision 1),
 * vetoes them with our own message (decision 7), stamps reconciliation meta on the order's coupon lines and keeps
 * admin recalculation pinned to the line's snapshot (decision 6).
 *
 * `woocommerce_get_shop_coupon_data` runs on every `new WC_Coupon( $code )` (cart totals, order screens, reports),
 * so nothing here makes an HTTP call: it reads the {@see RedemptionState} only, and answers false without a
 * shopper session (admin, cron, gateway callbacks), where WooCommerce falls back to the order line.
 */
final class VirtualCoupons {

	/**
	 * `woocommerce_coupon_sort` of the points coupon: after every voucher and store coupon (fixed_cart is 3).
	 */
	public const POINTS_SORT = 4;

	/**
	 * `woocommerce_coupon_sort` of the credits coupon: last.
	 */
	public const CREDITS_SORT = 5;

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
	 * Quotes (only for a shopper who types a voucher code before any quote was fetched).
	 *
	 * @var QuoteService
	 */
	private QuoteService $quotes;

	/**
	 * Whether this request already fetched a quote for a typed code.
	 *
	 * @var bool
	 */
	private bool $quoted_for_typed_code = false;

	/**
	 * Constructor.
	 *
	 * @param RedemptionState         $state    Shopper state.
	 * @param ShopperContextInterface $shopper  Who the shopper is.
	 * @param Features                $features Kill switches.
	 * @param QuoteService            $quotes   Quotes.
	 */
	public function __construct( RedemptionState $state, ShopperContextInterface $shopper, Features $features, QuoteService $quotes ) {
		$this->state    = $state;
		$this->shopper  = $shopper;
		$this->features = $features;
		$this->quotes   = $quotes;
	}

	/**
	 * Register hooks (ADR 0001 hook map).
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_shop_coupon_data', array( $this, 'coupon_data' ), 10, 3 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'is_valid' ), 10, 3 );
		add_filter( 'woocommerce_coupon_error', array( $this, 'removal_message' ), 10, 3 );
		add_filter( 'woocommerce_coupon_sort', array( $this, 'coupon_sort' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_coupon_item', array( $this, 'stamp_coupon_item' ), 10, 4 );
		add_filter( 'woocommerce_order_recalculate_coupons_coupon_object', array( $this, 'rebuild_for_recalculation' ), 10, 4 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'refresh_draft_coupon_lines' ), 10, 1 );
	}

	/**
	 * `woocommerce_get_shop_coupon_data` filter: props for our codes.
	 *
	 * @param mixed $data   False, or props another extension already supplied.
	 * @param mixed $code   Coupon code (or an id when WooCommerce loads by id).
	 * @param mixed $coupon WC_Coupon being built.
	 * @return mixed
	 */
	public function coupon_data( $data, $code, $coupon ) {
		unset( $coupon );
		if ( false !== $data || ! is_string( $code ) || '' === $code ) {
			return $data;
		}

		$kind = CouponMapper::kind_of( $code );
		if ( null === $kind || ! $this->state->is_available() ) {
			return $data;
		}

		if ( ! $this->features->enabled( $kind ) || ShopperContextInterface::STATE_VERIFIED !== $this->shopper->state() ) {
			return CouponMapper::placeholder();
		}

		if ( CouponMapper::KIND_POINTS === $kind ) {
			$config = $this->state->points_config();
			$points = null === $config ? 0 : min( $this->state->points(), $config['available'], $config['max_for_cart'] );

			return null !== $config && $points > 0 ? CouponMapper::points( $points, $config['value_per_point'] ) : CouponMapper::placeholder();
		}

		if ( CouponMapper::KIND_CREDIT === $kind ) {
			$config = $this->state->credits_config();
			$cents  = null === $config ? 0 : min( $this->state->credits_cents(), $config['balance_cents'], $config['max_for_cart_cents'] );

			return $cents > 0 ? CouponMapper::credits( $cents ) : CouponMapper::placeholder();
		}

		$voucher = $this->state->voucher( $code );

		return null !== $voucher && is_array( $voucher['woo_coupon'] ?? null ) ? CouponMapper::voucher( $voucher['woo_coupon'] ) : CouponMapper::placeholder();
	}

	/**
	 * `woocommerce_coupon_is_valid` filter. Throwing keeps our message: WC_Discounts::is_coupon_valid() catches the
	 * exception and shows its text, while returning false shows the generic "Coupon is not valid.".
	 *
	 * @param mixed $valid     Validity so far.
	 * @param mixed $coupon    Coupon.
	 * @param mixed $discounts WC_Discounts (cart or order).
	 * @return mixed
	 * @throws \Exception When the shopper may not use this Pixalink coupon.
	 */
	public function is_valid( $valid, $coupon, $discounts ) {
		if ( ! $coupon instanceof \WC_Coupon ) {
			return $valid;
		}

		$code = (string) $coupon->get_code();
		$kind = CouponMapper::kind_of( $code );
		if ( null === $kind ) {
			return $valid;
		}

		// Never veto on order screens (admin edits, order-pay recalculation) or without a shopper session.
		if ( ( $discounts instanceof \WC_Discounts && $discounts->get_object() instanceof \WC_Order ) || ! $this->state->is_available() ) {
			return $valid;
		}

		$this->quote_for_typed_code( $kind );

		$reason = $this->refusal( $kind, $code );
		if ( null !== $reason ) {
			throw new \Exception( esc_html( $reason ) );
		}

		return $valid;
	}

	/**
	 * A verified shopper typed a voucher code into the coupon field before any quote was stored (the panel never
	 * loaded): fetch one, once per request, so the typed code resolves (ADR 0001, fallback 2). Never from the
	 * coupon-data filter, which runs everywhere.
	 *
	 * @param string $kind Kind being validated.
	 * @return void
	 */
	private function quote_for_typed_code( string $kind ): void {
		if ( CouponMapper::KIND_VOUCHER !== $kind || $this->quoted_for_typed_code || ShopperContextInterface::STATE_VERIFIED !== $this->shopper->state() || ! $this->features->enabled( $kind ) ) {
			return;
		}

		if ( $this->state->has_quote() && $this->state->customer_id() === $this->shopper->pixalink_customer_id() ) {
			return;
		}

		$this->quoted_for_typed_code = true;
		$this->quotes->refresh( false, null, false );
	}

	/**
	 * Why the current shopper may not use one of our coupons right now (null when they may).
	 *
	 * @param string $kind Kind.
	 * @param string $code Coupon code.
	 * @return string|null
	 */
	public function refusal( string $kind, string $code ): ?string {
		if ( ! $this->features->enabled( $kind ) ) {
			return Labels::unavailable( $kind );
		}

		if ( ShopperContextInterface::STATE_VERIFIED !== $this->shopper->state() ) {
			return Labels::verify_prompt();
		}

		if ( CouponMapper::KIND_POINTS === $kind ) {
			if ( null === $this->state->points_config() ) {
				return Labels::unavailable( $kind );
			}

			return $this->state->points() > 0 ? null : __( 'Choose how many points to use first.', 'pixalink-loyalty-for-woocommerce' );
		}

		if ( CouponMapper::KIND_CREDIT === $kind ) {
			if ( null === $this->state->credits_config() ) {
				return Labels::unavailable( $kind );
			}

			return $this->state->credits_cents() > 0 ? null : __( 'Choose how many credits to use first.', 'pixalink-loyalty-for-woocommerce' );
		}

		$voucher = $this->state->voucher( $code );
		if ( null === $voucher ) {
			return __( 'This Pixalink voucher is not available for your account.', 'pixalink-loyalty-for-woocommerce' );
		}

		if ( false === ( $voucher['redeemable_online'] ?? true ) ) {
			return __( 'This voucher can only be redeemed in store.', 'pixalink-loyalty-for-woocommerce' );
		}

		if ( true !== ( $voucher['eligible'] ?? false ) || ! is_array( $voucher['woo_coupon'] ?? null ) ) {
			$reason = $voucher['ineligible_reason'] ?? null;

			return is_string( $reason ) && '' !== $reason ? $reason : __( 'This voucher cannot be used for this cart.', 'pixalink-loyalty-for-woocommerce' );
		}

		if ( CouponMapper::has_unresolved_skus( $voucher['woo_coupon'] ) ) {
			return __( 'This voucher does not apply to any product sold in this store.', 'pixalink-loyalty-for-woocommerce' );
		}

		return null;
	}

	/**
	 * `woocommerce_coupon_error` filter. WC_Cart::check_cart_coupons() swaps our reason for 'Sorry, it seems the
	 * coupon "pxl-points" is invalid…' (code 101), which also exposes the internal code (ADR pitfall 4).
	 *
	 * @param mixed $message  Error message.
	 * @param mixed $err_code WC_Coupon::E_* code.
	 * @param mixed $coupon   Coupon (null for some codes).
	 * @return mixed
	 */
	public function removal_message( $message, $err_code, $coupon ) {
		if ( \WC_Coupon::E_WC_COUPON_INVALID_REMOVED !== $err_code || ! $coupon instanceof \WC_Coupon ) {
			return $message;
		}

		$kind = CouponMapper::kind_of( (string) $coupon->get_code() );
		if ( null === $kind ) {
			return $message;
		}

		return esc_html( trim( Labels::removed( $kind ) . ' ' . wp_strip_all_tags( (string) $coupon->get_error_message() ) ) );
	}

	/**
	 * `woocommerce_coupon_sort` filter: points after every voucher and store coupon, credits last, so the caps in
	 * {@see CartPayload::remaining_cents()} hold.
	 *
	 * @param mixed $sort   Sort key.
	 * @param mixed $coupon Coupon.
	 * @return mixed
	 */
	public function coupon_sort( $sort, $coupon ) {
		if ( ! $coupon instanceof \WC_Coupon ) {
			return $sort;
		}

		$kind = CouponMapper::kind_of( (string) $coupon->get_code() );
		if ( CouponMapper::KIND_POINTS === $kind ) {
			return self::POINTS_SORT;
		}

		return CouponMapper::KIND_CREDIT === $kind ? self::CREDITS_SORT : $sort;
	}

	/**
	 * `woocommerce_checkout_create_order_coupon_item` action (classic create_order and Store API draft orders).
	 *
	 * The line already carries `discount` and `discount_tax`; the points recorded are what that discount actually
	 * cost (fewer than selected when WooCommerce capped it at the subtotal).
	 *
	 * @param mixed $item   Coupon line about to be added.
	 * @param mixed $code   Coupon code.
	 * @param mixed $coupon Resolved coupon.
	 * @param mixed $order  Order.
	 * @return void
	 */
	public function stamp_coupon_item( $item, $code, $coupon, $order ): void {
		unset( $order );
		if ( ! $item instanceof \WC_Order_Item_Coupon || ! $coupon instanceof \WC_Coupon || ! is_string( $code ) ) {
			return;
		}

		$kind = CouponMapper::kind_of( $code );
		if ( null === $kind ) {
			return;
		}

		$value = CartPayload::value_cents( $item->get_discount(), $item->get_discount_tax(), wc_prices_include_tax() );

		$item->add_meta_data( OrderMeta::COUPON_SNAPSHOT, CouponMapper::snapshot( $coupon ), true );
		$item->add_meta_data( OrderMeta::COUPON_KIND, $kind, true );
		$item->add_meta_data( OrderMeta::COUPON_AMOUNT_CENTS, (string) $value, true );

		if ( CouponMapper::KIND_POINTS === $kind ) {
			$config = $this->state->points_config();
			$spent  = null === $config ? 0 : PointsMath::points_spent( min( $this->state->points(), $config['max_for_cart'] ), $value, $config['value_per_point'] );
			$item->add_meta_data( OrderMeta::COUPON_POINTS, (string) $spent, true );
			$item->add_meta_data( OrderMeta::COUPON_TITLE, Labels::points( $spent ), true );
			return;
		}

		if ( CouponMapper::KIND_CREDIT === $kind ) {
			$item->add_meta_data( OrderMeta::COUPON_TITLE, Labels::credits( $value ), true );
			return;
		}

		$voucher = $this->state->voucher( $code );
		$item->add_meta_data( OrderMeta::COUPON_VOUCHER_ID, null === $voucher ? '' : (string) $voucher['id'], true );
		if ( null !== $voucher && isset( $voucher['title'] ) && is_string( $voucher['title'] ) && '' !== $voucher['title'] ) {
			$item->add_meta_data( OrderMeta::COUPON_TITLE, $voucher['title'], true );
		}
	}

	/**
	 * `woocommerce_order_recalculate_coupons_coupon_object` filter: rebuild our coupon from the line's snapshot.
	 *
	 * WC_Abstract_Order::recalculate_coupons() (admin Apply/Remove coupon, REST order updates, order-pay) rebuilds
	 * virtual coupons from `coupon_info`, which keeps only type and amount: a "free item" voucher would become
	 * 100% off everything (ADR pitfall 6).
	 *
	 * @param mixed $coupon_object Coupon WooCommerce rebuilt.
	 * @param mixed $code          Coupon code.
	 * @param mixed $item          Order coupon line.
	 * @param mixed $order         Order.
	 * @return mixed
	 */
	public function rebuild_for_recalculation( $coupon_object, $code, $item, $order ) {
		unset( $order );
		if ( ! $item instanceof \WC_Order_Item_Coupon || ! is_string( $code ) || null === CouponMapper::kind_of( $code ) ) {
			return $coupon_object;
		}

		$snapshot = $item->get_meta( OrderMeta::COUPON_SNAPSHOT, true );
		if ( ! is_array( $snapshot ) || array() === $snapshot ) {
			return $coupon_object;
		}

		return CouponMapper::from_snapshot( $snapshot, $code );
	}

	/**
	 * `woocommerce_store_api_checkout_update_order_meta` action (POST /checkout, right after the draft order is
	 * created or synced from the cart).
	 *
	 * The Store API rebuilds a reused draft's coupon lines only when `_coupons_hash` (the applied *codes*)
	 * changes; a new points amount keeps the code `pxl-points`, so the retry after a refused hold would re-send the
	 * old amount (ADR pitfall 14). Rebuild whenever one of ours is involved.
	 *
	 * @param mixed $order Draft order.
	 * @return void
	 */
	public function refresh_draft_coupon_lines( $order ): void {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		if ( ! $order instanceof \WC_Order || ! $cart instanceof \WC_Cart ) {
			return;
		}

		$codes = array_merge( $order->get_coupon_codes(), $cart->get_applied_coupons() );
		if ( array() === array_filter( array_map( 'strval', $codes ), array( CouponMapper::class, 'is_ours' ) ) ) {
			return;
		}

		$order->remove_order_items( 'coupon' );
		WC()->checkout()->create_order_coupon_lines( $order, $cart );
	}
}
