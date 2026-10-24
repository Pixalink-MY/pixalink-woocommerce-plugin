<?php
/**
 * What both checkouts' loyalty panels show.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Checkout;

use Pixalink\Loyalty\Identity\ShopperContextInterface;
use Pixalink\Loyalty\Redemption\CartPayload;
use Pixalink\Loyalty\Redemption\CouponMapper;
use Pixalink\Loyalty\Redemption\Features;
use Pixalink\Loyalty\Redemption\Labels;
use Pixalink\Loyalty\Redemption\PointsMath;
use Pixalink\Loyalty\Redemption\QuoteService;
use Pixalink\Loyalty\Redemption\RedemptionState;
use Pixalink\Loyalty\Redemption\VirtualCoupons;

defined( 'ABSPATH' ) || exit;

/**
 * One description of the shopper's rewards for the current cart: the Store API exposes it on the cart
 * (`extensions['pixalink-loyalty']`, read by the Blocks UI from the `wc/store/cart` store) and the classic
 * template renders it.
 *
 * Balances appear only for verified shoppers and only from a quote for that shopper. What is applied comes from
 * the cart's coupons, not from the stored selection, so the UI stays truthful when WooCommerce removed a coupon.
 */
final class PanelData {

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
	 * Virtual coupons (refusal reasons).
	 *
	 * @var VirtualCoupons
	 */
	private VirtualCoupons $coupons;

	/**
	 * Constructor.
	 *
	 * @param RedemptionState         $state    Shopper state.
	 * @param QuoteService            $quotes   Quotes.
	 * @param ShopperContextInterface $shopper  Who the shopper is.
	 * @param Features                $features Kill switches.
	 * @param VirtualCoupons          $coupons  Virtual coupons.
	 */
	public function __construct( RedemptionState $state, QuoteService $quotes, ShopperContextInterface $shopper, Features $features, VirtualCoupons $coupons ) {
		$this->state    = $state;
		$this->quotes   = $quotes;
		$this->shopper  = $shopper;
		$this->features = $features;
		$this->coupons  = $coupons;
	}

	/**
	 * The panel data.
	 *
	 * @return array<string,mixed>
	 */
	public function build(): array {
		$cart     = function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ? WC()->cart : null;
		$verified = ShopperContextInterface::STATE_VERIFIED === $this->shopper->state();
		$quoted   = $verified && $this->state->has_quote() && $this->state->customer_id() === $this->shopper->pixalink_customer_id();

		$data = array(
			'show'          => $this->features->panel_enabled(),
			'shopper'       => $this->shopper->state(),
			'verified'      => $verified,
			'verify_prompt' => Labels::verify_prompt(),
			'verify_url'    => self::verify_url(),
			'enabled'       => array(
				'vouchers' => $this->features->enabled( CouponMapper::KIND_VOUCHER ),
				'points'   => $this->features->enabled( CouponMapper::KIND_POINTS ),
				'credits'  => $this->features->enabled( CouponMapper::KIND_CREDIT ),
			),
			'quote'         => array(
				'available' => $quoted,
				'stale'     => null !== $cart && $verified && $this->quotes->is_stale( $cart ),
				'error'     => $verified ? $this->state->quote_error() : '',
			),
			'codes'         => array(
				'points'  => CouponMapper::POINTS_CODE,
				'credits' => CouponMapper::CREDITS_CODE,
			),
			'points'        => null,
			'credits'       => null,
			'vouchers'      => array(),
			'labels'        => null === $cart ? array() : $this->labels( $cart ),
			'earn_preview'  => $quoted ? $this->state->earn_preview_points() : null,
		);

		if ( ! $quoted || null === $cart ) {
			return $data;
		}

		$data['points']   = $this->points( $cart );
		$data['credits']  = $this->credits( $cart );
		$data['vouchers'] = $this->vouchers( $cart );

		return $data;
	}

	/**
	 * Coupon code => chip / review row label for our applied coupons.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return array<string,string>
	 */
	public function labels( \WC_Cart $cart ): array {
		$labels = array();
		foreach ( $cart->get_applied_coupons() as $code ) {
			$label = $this->label( (string) $code );
			if ( null !== $label ) {
				$labels[ CouponMapper::normalise( (string) $code ) ] = $label;
			}
		}

		return $labels;
	}

	/**
	 * Label of one of our coupon codes (null for store coupons and unknown vouchers).
	 *
	 * @param string $code Coupon code.
	 * @return string|null
	 */
	public function label( string $code ): ?string {
		$kind = CouponMapper::kind_of( $code );
		if ( CouponMapper::KIND_POINTS === $kind ) {
			$config = $this->state->points_config();

			return Labels::points( null === $config ? $this->state->points() : min( $this->state->points(), $config['available'], $config['max_for_cart'] ) );
		}

		if ( CouponMapper::KIND_CREDIT === $kind ) {
			$config = $this->state->credits_config();

			return Labels::credits( null === $config ? $this->state->credits_cents() : min( $this->state->credits_cents(), $config['balance_cents'], $config['max_for_cart_cents'] ) );
		}

		if ( CouponMapper::KIND_VOUCHER === $kind ) {
			$voucher = $this->state->voucher( $code );
			if ( null !== $voucher && isset( $voucher['title'] ) && is_string( $voucher['title'] ) && '' !== $voucher['title'] ) {
				return Labels::voucher( $voucher['title'] );
			}
		}

		return null;
	}

	/**
	 * Points block.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return array<string,mixed>|null
	 */
	private function points( \WC_Cart $cart ): ?array {
		$config = $this->state->points_config();
		if ( null === $config || ! $this->features->enabled( CouponMapper::KIND_POINTS ) ) {
			return null;
		}

		$applied = $cart->has_discount( CouponMapper::POINTS_CODE ) ? min( $this->state->points(), $config['available'], $config['max_for_cart'] ) : 0;
		$max     = PointsMath::max_points( $config, CartPayload::remaining_cents( $cart, array( CouponMapper::KIND_POINTS, CouponMapper::KIND_CREDIT ) ) );

		return array(
			'code'            => CouponMapper::POINTS_CODE,
			'balance'         => $config['balance'],
			'available'       => $config['available'],
			'value_per_point' => $config['value_per_point'],
			'min'             => PointsMath::smallest_points( $config ),
			'max'             => $max,
			'step'            => $config['step'],
			'applied'         => $applied,
			'applied_cents'   => PointsMath::value_cents( $applied, $config['value_per_point'] ),
			'usable'          => $max >= PointsMath::smallest_points( $config ),
			'label'           => Labels::points( $applied ),
			/* translators: %s: points balance, formatted. */
			'balance_label'   => sprintf( _n( '%s point', '%s points', $config['balance'], 'pixalink-loyalty-for-woocommerce' ), number_format_i18n( $config['balance'] ) ),
		);
	}

	/**
	 * Credits block.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return array<string,mixed>|null
	 */
	private function credits( \WC_Cart $cart ): ?array {
		$config = $this->state->credits_config();
		if ( null === $config || ! $this->features->enabled( CouponMapper::KIND_CREDIT ) ) {
			return null;
		}

		$applied = $cart->has_discount( CouponMapper::CREDITS_CODE ) ? min( $this->state->credits_cents(), $config['balance_cents'], $config['max_for_cart_cents'] ) : 0;
		$max     = PointsMath::clamp_cents( $config['balance_cents'], $config['balance_cents'], $config['max_for_cart_cents'], CartPayload::remaining_cents( $cart, array( CouponMapper::KIND_CREDIT ) ) );

		return array(
			'code'          => CouponMapper::CREDITS_CODE,
			'balance_cents' => $config['balance_cents'],
			'max_cents'     => $max,
			'applied_cents' => $applied,
			'usable'        => $max > 0,
			'label'         => Labels::credits( $applied ),
			'balance_label' => Labels::credit_amount( $config['balance_cents'] ),
		);
	}

	/**
	 * Voucher cards.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return list<array<string,mixed>>
	 */
	private function vouchers( \WC_Cart $cart ): array {
		if ( ! $this->features->enabled( CouponMapper::KIND_VOUCHER ) ) {
			return array();
		}

		$cards = array();
		foreach ( $this->state->vouchers() as $voucher ) {
			$code   = (string) $voucher['coupon_code'];
			$reason = $this->coupons->refusal( CouponMapper::KIND_VOUCHER, $code );

			$cards[] = array(
				'code'           => $code,
				'voucher_code'   => isset( $voucher['code'] ) && is_scalar( $voucher['code'] ) ? (string) $voucher['code'] : '',
				'id'             => (string) $voucher['id'],
				'title'          => isset( $voucher['title'] ) && is_string( $voucher['title'] ) && '' !== $voucher['title'] ? $voucher['title'] : (string) ( $voucher['code'] ?? $code ),
				'description'    => isset( $voucher['description'] ) && is_string( $voucher['description'] ) ? $voucher['description'] : '',
				'image_url'      => isset( $voucher['image_url'] ) && is_string( $voucher['image_url'] ) ? $voucher['image_url'] : '',
				'expires_at'     => isset( $voucher['expires_at'] ) && is_string( $voucher['expires_at'] ) ? $voucher['expires_at'] : '',
				'expires_label'  => self::expiry_label( $voucher['expires_at'] ?? null ),
				'discount_cents' => isset( $voucher['discount_cents'] ) && is_int( $voucher['discount_cents'] ) ? $voucher['discount_cents'] : 0,
				'usable'         => null === $reason,
				'reason'         => $reason ?? '',
				'applied'        => $cart->has_discount( $code ),
			);
		}

		return $cards;
	}

	/**
	 * "Expires 31 Dec 2026" in the site's date format.
	 *
	 * @param mixed $expires_at ISO 8601 time.
	 * @return string
	 */
	private static function expiry_label( $expires_at ): string {
		$timestamp = is_string( $expires_at ) ? strtotime( $expires_at ) : false;
		if ( false === $timestamp ) {
			return '';
		}

		/* translators: %s: expiry date. */
		return sprintf( __( 'Expires %s', 'pixalink-loyalty-for-woocommerce' ), (string) wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $timestamp ) );
	}

	/**
	 * Where a shopper verifies their phone: My Account › Loyalty for logged-in shoppers
	 * ({@see \Pixalink\Loyalty\Account\LoyaltyEndpoint::verify_url()}), nothing for guests.
	 *
	 * @return string
	 */
	private static function verify_url(): string {
		/**
		 * Filters the URL of the page where shoppers verify their phone number to use their rewards.
		 *
		 * @since 0.2.0
		 *
		 * @param string $url Default: empty (no link, the prompt only).
		 */
		$url = apply_filters( 'pixalink_loyalty_verify_url', '' );

		return is_string( $url ) ? $url : '';
	}
}
