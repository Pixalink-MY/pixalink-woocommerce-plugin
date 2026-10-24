<?php
/**
 * SPIKE (P02, throwaway): vouchers and points as virtual WooCommerce coupons.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Spike;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves `pxl-points` and `pxl-*` voucher codes from the session state, vetoes them for unverified shoppers,
 * stamps reconciliation meta on the order's coupon lines and keeps admin recalculation stable.
 */
final class VirtualCoupons {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_shop_coupon_data', array( $this, 'coupon_data' ), 10, 3 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'is_valid' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order_coupon_item', array( $this, 'stamp_coupon_item' ), 10, 4 );
		add_filter( 'woocommerce_order_recalculate_coupons_coupon_object', array( $this, 'rebuild_for_recalculation' ), 10, 4 );
		add_filter( 'woocommerce_cart_totals_coupon_label', array( $this, 'classic_label' ), 10, 2 );
		add_filter( 'woocommerce_coupon_error', array( $this, 'removal_message' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'refresh_draft_coupon_lines' ) );
		add_action( 'woocommerce_removed_coupon', array( $this, 'forget_removed' ) );
		add_action( 'woocommerce_cart_emptied', array( $this, 'forget_selection' ) );
	}

	/**
	 * Server kill switch stand-in (W3: heartbeat `features.points_redemption` / `features.vouchers`).
	 *
	 * @return bool
	 */
	public static function redemption_enabled(): bool {
		/**
		 * Server kill switch stand-in.
		 *
		 * @since 0.0.0 Spike only.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'pixalink_loyalty_spike_redemption_enabled', true );
	}

	/**
	 * Message shown when an unverified shopper tries to spend.
	 *
	 * @return string
	 */
	public static function verify_message(): string {
		return __( 'Verify your Pixalink membership to use points or vouchers.', 'pixalink-loyalty-for-woocommerce' );
	}

	/**
	 * Display label for the points coupon.
	 *
	 * @param int $points Points redeemed.
	 * @return string
	 */
	public static function points_label( int $points ): string {
		/* translators: %s: number of points, formatted. */
		return sprintf( __( 'Points (%s pts)', 'pixalink-loyalty-for-woocommerce' ), number_format_i18n( $points ) );
	}

	/**
	 * `woocommerce_get_shop_coupon_data` filter: virtual coupon props for our codes.
	 *
	 * Called from every `new WC_Coupon( $data )` (cart totals, order screens, reports), so: no HTTP, session only.
	 *
	 * @param mixed $data   False, or props another extension already supplied.
	 * @param mixed $code   Coupon code (string), or an ID / object when WooCommerce loads by ID.
	 * @param mixed $coupon WC_Coupon being constructed.
	 * @return mixed
	 */
	public function coupon_data( $data, $code, $coupon ) {
		unset( $coupon );
		if ( false !== $data || ! is_string( $code ) || '' === $code || ! CouponMapper::is_ours( $code ) ) {
			return $data;
		}

		// Admin, cron and gateway callbacks have no session: let WooCommerce fall back to the order line's coupon_info.
		if ( ! RedemptionState::is_available() ) {
			return $data;
		}

		if ( ! self::redemption_enabled() || ! RedemptionState::is_verified() ) {
			return CouponMapper::placeholder();
		}

		if ( wc_is_same_coupon( $code, CouponMapper::POINTS_CODE ) ) {
			$points = RedemptionState::get()['points'];

			return $points > 0
				? CouponMapper::points( $points, RedemptionState::points_config()['value_per_point'] )
				: CouponMapper::placeholder();
		}

		$voucher = RedemptionState::voucher( $code );
		if ( null === $voucher || ! is_array( $voucher['woo_coupon'] ?? null ) ) {
			return CouponMapper::placeholder();
		}

		return CouponMapper::voucher( $voucher['woo_coupon'] );
	}

	/**
	 * `woocommerce_coupon_is_valid` filter. Throwing keeps our message: WC_Discounts::is_coupon_valid() catches the
	 * Exception and uses its message, while returning false yields the generic "Coupon is not valid.".
	 *
	 * @param mixed $valid     Validity so far.
	 * @param mixed $coupon    Coupon.
	 * @param mixed $discounts WC_Discounts (cart or order).
	 * @return mixed
	 * @throws \Exception When the shopper may not use this Pixalink coupon.
	 */
	public function is_valid( $valid, $coupon, $discounts ) {
		if ( ! $coupon instanceof \WC_Coupon || ! CouponMapper::is_ours( $coupon->get_code() ) ) {
			return $valid;
		}

		// Never veto on admin order edits (WC_Discounts built from an order) or without a session.
		if ( ( $discounts instanceof \WC_Discounts && $discounts->get_object() instanceof \WC_Order ) || ! RedemptionState::is_available() ) {
			return $valid;
		}

		if ( ! self::redemption_enabled() ) {
			throw new \Exception( esc_html__( 'Pixalink points and vouchers are temporarily unavailable.', 'pixalink-loyalty-for-woocommerce' ) );
		}

		if ( ! RedemptionState::is_verified() ) {
			throw new \Exception( esc_html( self::verify_message() ) );
		}

		if ( wc_is_same_coupon( $coupon->get_code(), CouponMapper::POINTS_CODE ) ) {
			if ( RedemptionState::get()['points'] <= 0 ) {
				throw new \Exception( esc_html__( 'Choose how many points to redeem first.', 'pixalink-loyalty-for-woocommerce' ) );
			}

			return $valid;
		}

		$voucher = RedemptionState::voucher( $coupon->get_code() );
		if ( null === $voucher || ! is_array( $voucher['woo_coupon'] ?? null ) ) {
			throw new \Exception( esc_html__( 'This Pixalink voucher is not available for your account.', 'pixalink-loyalty-for-woocommerce' ) );
		}

		if ( CouponMapper::has_unresolved_skus( $voucher['woo_coupon'] ) ) {
			throw new \Exception( esc_html__( 'This voucher does not apply to any product sold in this store.', 'pixalink-loyalty-for-woocommerce' ) );
		}

		if ( false === ( $voucher['eligible'] ?? true ) ) {
			$reason = is_string( $voucher['ineligible_reason'] ?? null ) ? $voucher['ineligible_reason'] : __( 'This voucher cannot be used for this cart.', 'pixalink-loyalty-for-woocommerce' );
			throw new \Exception( esc_html( $reason ) );
		}

		return $valid;
	}

	/**
	 * `woocommerce_checkout_create_order_coupon_item` action (classic create_order and Store API draft orders alike).
	 *
	 * @param \WC_Order_Item_Coupon $item   Coupon line about to be added.
	 * @param string                $code   Coupon code.
	 * @param \WC_Coupon            $coupon Resolved coupon.
	 * @param \WC_Order             $order  Order.
	 * @return void
	 */
	public function stamp_coupon_item( $item, $code, $coupon, $order ): void {
		unset( $order );
		if ( ! $item instanceof \WC_Order_Item_Coupon || ! $coupon instanceof \WC_Coupon || ! is_string( $code ) || ! CouponMapper::is_ours( $code ) ) {
			return;
		}

		$item->add_meta_data( '_pixalink_coupon', CouponMapper::snapshot( $coupon ), true );

		if ( wc_is_same_coupon( $code, CouponMapper::POINTS_CODE ) ) {
			$item->add_meta_data( '_pixalink_kind', 'points', true );
			$item->add_meta_data( '_pixalink_points', (string) RedemptionState::get()['points'], true );
			return;
		}

		$voucher = RedemptionState::voucher( $code );
		$item->add_meta_data( '_pixalink_kind', 'voucher', true );
		$item->add_meta_data( '_pixalink_voucher_id', is_array( $voucher ) ? (string) ( $voucher['id'] ?? '' ) : '', true );
	}

	/**
	 * `woocommerce_order_recalculate_coupons_coupon_object` filter: rebuild our coupon from the line's snapshot.
	 *
	 * WC_Abstract_Order::recalculate_coupons() (admin apply/remove coupon, REST v4 updates) rebuilds virtual coupons
	 * from the `coupon_info` meta, which only keeps type and amount: a "free item" voucher would become 100% off
	 * everything. The snapshot carries product_ids / limit_usage_to_x_items so the totals stay put.
	 *
	 * @param mixed $coupon_object Coupon WooCommerce rebuilt.
	 * @param mixed $code          Coupon code.
	 * @param mixed $item          Order coupon line.
	 * @param mixed $order         Order.
	 * @return mixed
	 */
	public function rebuild_for_recalculation( $coupon_object, $code, $item, $order ) {
		unset( $order );
		if ( ! $item instanceof \WC_Order_Item_Coupon || ! is_string( $code ) ) {
			return $coupon_object;
		}

		$snapshot = $item->get_meta( '_pixalink_coupon', true );
		if ( ! is_array( $snapshot ) || array() === $snapshot ) {
			return $coupon_object;
		}

		$rebuilt = new \WC_Coupon();
		$rebuilt->set_props( $snapshot );
		$rebuilt->set_code( $code );
		$rebuilt->set_virtual( true );

		return $rebuilt;
	}

	/**
	 * `woocommerce_store_api_checkout_update_order_meta` action (POST /checkout, right after the draft order is
	 * created or synced from the cart).
	 *
	 * OrderController::update_line_items_from_cart() only rebuilds coupon lines when `_coupons_hash`
	 * (md5 of the applied coupon *codes*) changes. Changing the points amount keeps the code `pxl-points`, so a reused
	 * draft (retry after a refused hold) keeps the old line: stale `discount`, `discount_tax` and `_pixalink_points`.
	 * Rebuild the coupon lines whenever one of ours is present.
	 *
	 * @param mixed $order Draft order.
	 * @return void
	 */
	public function refresh_draft_coupon_lines( $order ): void {
		/**
		 * Whether to rebuild the draft order's coupon lines at POST /checkout.
		 *
		 * @since 0.0.0 Spike only.
		 *
		 * @param bool $refresh Default true.
		 */
		if ( ! $order instanceof \WC_Order || ! WC()->cart instanceof \WC_Cart || ! (bool) apply_filters( 'pixalink_loyalty_spike_refresh_draft_coupon_lines', true ) ) {
			return;
		}

		$ours = array_filter( $order->get_coupon_codes(), array( CouponMapper::class, 'is_ours' ) );
		if ( array() === $ours ) {
			return;
		}

		$order->remove_order_items( 'coupon' );
		WC()->checkout()->create_order_coupon_lines( $order, WC()->cart );
	}

	/**
	 * `woocommerce_cart_totals_coupon_label` filter (classic cart / checkout review table).
	 *
	 * @param mixed $label  Label HTML.
	 * @param mixed $coupon Coupon.
	 * @return mixed
	 */
	public function classic_label( $label, $coupon ) {
		if ( ! $coupon instanceof \WC_Coupon || ! CouponMapper::is_ours( $coupon->get_code() ) ) {
			return $label;
		}

		if ( wc_is_same_coupon( $coupon->get_code(), CouponMapper::POINTS_CODE ) ) {
			return esc_html( self::points_label( RedemptionState::get()['points'] ) );
		}

		$voucher = RedemptionState::voucher( $coupon->get_code() );
		if ( is_array( $voucher ) && is_string( $voucher['title'] ?? null ) ) {
			/* translators: %s: voucher title. */
			return esc_html( sprintf( __( 'Voucher: %s', 'pixalink-loyalty-for-woocommerce' ), $voucher['title'] ) );
		}

		return $label;
	}

	/**
	 * `woocommerce_coupon_error` filter. WC_Cart::check_cart_coupons() (classic cart/checkout re-validation) drops the
	 * validity message and shows 'Sorry, it seems the coupon "pxl-points" is invalid - it has now been removed from
	 * your order.' (code 101). Put our reason back and do not show the internal code.
	 *
	 * @param mixed $message  Error message.
	 * @param mixed $err_code WC_Coupon::E_* code.
	 * @param mixed $coupon   Coupon (null for some codes).
	 * @return mixed
	 */
	public function removal_message( $message, $err_code, $coupon ) {
		if ( \WC_Coupon::E_WC_COUPON_INVALID_REMOVED !== $err_code || ! $coupon instanceof \WC_Coupon || ! CouponMapper::is_ours( $coupon->get_code() ) ) {
			return $message;
		}

		$reason = $coupon->get_error_message();
		$what   = wc_is_same_coupon( $coupon->get_code(), CouponMapper::POINTS_CODE )
			? __( 'Your Pixalink points discount was removed.', 'pixalink-loyalty-for-woocommerce' )
			: __( 'Your Pixalink voucher was removed.', 'pixalink-loyalty-for-woocommerce' );

		return esc_html( trim( $what . ' ' . $reason ) );
	}

	/**
	 * `woocommerce_removed_coupon` action: keep the state in sync when the shopper removes a chip / row.
	 *
	 * @param mixed $code Removed coupon code.
	 * @return void
	 */
	public function forget_removed( $code ): void {
		if ( ! is_string( $code ) || ! CouponMapper::is_ours( $code ) ) {
			return;
		}

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
	 * `woocommerce_cart_emptied` action (also after a paid order): drop the selection, keep verification + quote.
	 *
	 * @return void
	 */
	public function forget_selection(): void {
		RedemptionState::update(
			array(
				'points'   => 0,
				'vouchers' => array(),
			)
		);
	}
}
