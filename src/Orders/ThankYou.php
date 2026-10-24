<?php
/**
 * Order-received page note.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Orders;

use Pixalink\Loyalty\Api\Credentials;

defined( 'ABSPATH' ) || exit;

/**
 * One line on the order-received page: points earned when Pixalink already answered, otherwise that points follow
 * once payment is confirmed. Shown only when the order can earn (connected store, a known member or phone number).
 */
final class ThankYou {

	/**
	 * Outlet credentials.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Constructor.
	 *
	 * @param Credentials $credentials Outlet credentials.
	 */
	public function __construct( Credentials $credentials ) {
		$this->credentials = $credentials;
	}

	/**
	 * Register the hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_thankyou', array( $this, 'render' ), 20, 1 );
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

		return __( 'Loyalty points for this order will be added to your account once your payment is confirmed.', 'pixalink-loyalty-for-woocommerce' );
	}
}
