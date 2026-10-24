<?php
/**
 * Order-received and order-view notes.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Orders;

use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Connect\Connection;
use Pixalink\Loyalty\Redemption\CouponMapper;
use Pixalink\Loyalty\Redemption\Labels;

defined( 'ABSPATH' ) || exit;

/**
 * One line on the order-received page about points: earned when Pixalink already answered, otherwise when they
 * will be added (on payment or on completion, the outlet's `earn_on` from the heartbeat). Shown only when the
 * order can earn (connected store, a known member or phone number).
 *
 * Below the order details (order-received and My Account › View order), the Pixalink rewards the order used.
 */
final class ThankYou {

	/**
	 * Outlet credentials.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Connection state (earn timing).
	 *
	 * @var Connection|null
	 */
	private ?Connection $connection;

	/**
	 * Constructor.
	 *
	 * @param Credentials     $credentials Outlet credentials.
	 * @param Connection|null $connection  Connection state (earn timing).
	 */
	public function __construct( Credentials $credentials, ?Connection $connection = null ) {
		$this->credentials = $credentials;
		$this->connection  = $connection;
	}

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_thankyou', array( $this, 'render' ), 20, 1 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_rewards' ), 20, 1 );
	}

	/**
	 * `woocommerce_thankyou` callback.
	 *
	 * @param mixed $order_id Order id.
	 * @return void
	 */
	public function render( $order_id ): void {
		$order = is_numeric( $order_id ) ? wc_get_order( (int) $order_id ) : false;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$message = $this->message( $order );

		/**
		 * Filters the loyalty line on the order-received page (return '' to hide it).
		 *
		 * @since 0.1.0
		 *
		 * @param string    $message Message (plain text).
		 * @param \WC_Order $order   The order.
		 */
		$message = apply_filters( 'pixalink_loyalty_thankyou_message', $message, $order );
		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}

		echo '<p class="pixalink-loyalty-thankyou">' . esc_html( $message ) . '</p>';
	}

	/**
	 * Default message for an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public function message( \WC_Order $order ): string {
		$points = (int) OrderMeta::get_string( $order, OrderMeta::POINTS_EARNED );
		if ( $points > 0 ) {
			/* translators: %s: number of loyalty points. */
			return sprintf( _n( 'You earned %s loyalty point with this order.', 'You earned %s loyalty points with this order.', $points, 'pixalink-loyalty-for-woocommerce' ), number_format_i18n( $points ) );
		}

		$can_earn = '' !== OrderMeta::get_string( $order, OrderMeta::CUSTOMER_ID ) || '' !== OrderMeta::get_string( $order, OrderMeta::PHONE_E164 );
		if ( ! $this->credentials->is_configured() || ! $can_earn || OrderMeta::EARN_DEAD === OrderMeta::earn_state( $order ) ) {
			return '';
		}

		if ( 'completed' === $this->earn_on() || 'pending_completion' === OrderMeta::get_string( $order, OrderMeta::EARN_OUTCOME ) ) {
			return __( 'Loyalty points for this order will be added to your account when your order is completed.', 'pixalink-loyalty-for-woocommerce' );
		}

		return __( 'Loyalty points for this order will be added to your account once your payment is confirmed.', 'pixalink-loyalty-for-woocommerce' );
	}

	/**
	 * `woocommerce_order_details_after_order_table` callback: the rewards the order used.
	 *
	 * @param mixed $order Order.
	 * @return void
	 */
	public function render_rewards( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$rewards = self::rewards( $order );
		if ( array() === $rewards ) {
			return;
		}

		echo '<section class="pixalink-loyalty-rewards-used"><h2>' . esc_html__( 'Rewards used', 'pixalink-loyalty-for-woocommerce' ) . '</h2><ul>';
		foreach ( $rewards as $reward ) {
			echo '<li>' . esc_html( $reward['label'] ) . ' <span class="pixalink-loyalty-rewards-used__amount">&minus;' . wp_kses_post( wc_price( $reward['saving'], array( 'currency' => $order->get_currency() ) ) ) . '</span></li>';
		}
		echo '</ul></section>';
	}

	/**
	 * The Pixalink coupon lines of an order as `{label, saving}` (saving = what the total dropped by).
	 *
	 * @param \WC_Order $order Order.
	 * @return list<array{label:string,saving:float,kind:string,code:string}>
	 */
	public static function rewards( \WC_Order $order ): array {
		$rewards = array();
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Coupon ) {
				continue;
			}

			$code = (string) $item->get_code();
			$kind = CouponMapper::kind_of( $code );
			if ( null === $kind ) {
				continue;
			}

			$rewards[] = array(
				'label'  => self::label( $item, $kind ),
				'saving' => (float) $item->get_discount() + (float) $item->get_discount_tax(),
				'kind'   => $kind,
				'code'   => $code,
			);
		}

		return $rewards;
	}

	/**
	 * Display label of a Pixalink coupon line.
	 *
	 * @param \WC_Order_Item_Coupon $item Line.
	 * @param string                $kind Kind.
	 * @return string
	 */
	private static function label( \WC_Order_Item_Coupon $item, string $kind ): string {
		$title = trim( (string) $item->get_meta( OrderMeta::COUPON_TITLE, true ) );

		if ( CouponMapper::KIND_POINTS === $kind ) {
			$points = (int) $item->get_meta( OrderMeta::COUPON_POINTS, true );

			return $points > 0 ? Labels::points( $points ) : ( '' !== $title ? $title : __( 'Points', 'pixalink-loyalty-for-woocommerce' ) );
		}

		if ( CouponMapper::KIND_CREDIT === $kind ) {
			$cents = (int) $item->get_meta( OrderMeta::COUPON_AMOUNT_CENTS, true );

			return $cents > 0 ? Labels::credits( $cents ) : __( 'Credits', 'pixalink-loyalty-for-woocommerce' );
		}

		return Labels::voucher( '' !== $title ? $title : strtoupper( (string) $item->get_code() ) );
	}

	/**
	 * When the outlet credits points: "paid" (default) or "completed".
	 *
	 * @return string
	 */
	private function earn_on(): string {
		$earn_on = null === $this->connection ? null : ( $this->connection->remote_status()['earn_on'] ?? null );

		return 'completed' === $earn_on ? 'completed' : 'paid';
	}
}
