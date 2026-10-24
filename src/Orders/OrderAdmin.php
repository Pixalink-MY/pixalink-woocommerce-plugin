<?php
/**
 * Order edit screen: Pixalink meta box and "Resend to Pixalink" action.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Orders;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Pixalink\Loyalty\Events\Event;
use Pixalink\Loyalty\Events\EventRepository;
use Pixalink\Loyalty\Events\EventStatus;
use Pixalink\Loyalty\Events\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Side meta box on the order edit screen (HPOS `woocommerce_page_wc-orders` and posts `shop_order`) showing the
 * linked Pixalink customer, points earned, redemption state and the order's event timeline, plus an order action
 * that records missing events and requeues failed ones.
 */
final class OrderAdmin {

	public const META_BOX_ID   = 'pixalink-loyalty-order';
	public const RESEND_ACTION = 'pixalink_loyalty_resend';

	/**
	 * Outbox reads.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;

	/**
	 * Order hooks.
	 *
	 * @var OrderLifecycle
	 */
	private OrderLifecycle $lifecycle;

	/**
	 * Constructor.
	 *
	 * @param EventRepository $events    Outbox reads.
	 * @param OrderLifecycle  $lifecycle Order hooks.
	 */
	public function __construct( EventRepository $events, OrderLifecycle $lifecycle ) {
		$this->events    = $events;
		$this->lifecycle = $lifecycle;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 30, 0 );
		add_filter( 'woocommerce_order_actions', array( $this, 'order_actions' ), 10, 1 );
		add_action( 'woocommerce_order_action_' . self::RESEND_ACTION, array( $this, 'resend' ), 10, 1 );
	}

	/**
	 * Screen id of the order edit screen for the active order storage.
	 *
	 * @return string
	 */
	public static function screen_id(): string {
		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return wc_get_page_screen_id( 'shop-order' );
		}

		return 'shop_order';
	}

	/**
	 * `add_meta_boxes` callback.
	 *
	 * @return void
	 */
	public function add_meta_box(): void {
		add_meta_box(
			self::META_BOX_ID,
			__( 'Pixalink Loyalty', 'pixalink-loyalty-for-woocommerce' ),
			array( $this, 'render' ),
			self::screen_id(),
			'side',
			'default'
		);
	}

	/**
	 * Meta box body.
	 *
	 * @param mixed $post_or_order WP_Post (posts storage) or WC_Order (HPOS).
	 * @return void
	 */
	public function render( $post_or_order ): void {
		$order = $post_or_order instanceof \WP_Post ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$customer_id = OrderMeta::get_string( $order, OrderMeta::CUSTOMER_ID );
		$points      = OrderMeta::get_string( $order, OrderMeta::POINTS_EARNED );
		$earn_state  = OrderMeta::earn_state( $order );
		$redemption  = OrderMeta::get_string( $order, OrderMeta::REDEMPTION_STATE );
		$events      = $this->events->for_object( Event::OBJECT_ORDER, (int) $order->get_id(), 20 );

		echo '<p><strong>' . esc_html__( 'Pixalink customer', 'pixalink-loyalty-for-woocommerce' ) . ':</strong> ';
		echo '' !== $customer_id ? esc_html( '#' . $customer_id ) : esc_html__( 'Not linked', 'pixalink-loyalty-for-woocommerce' );
		echo '</p>';

		echo '<p><strong>' . esc_html__( 'Points earned', 'pixalink-loyalty-for-woocommerce' ) . ':</strong> ';
		echo '' !== $points ? esc_html( number_format_i18n( (int) $points ) ) : esc_html( self::earn_state_label( $earn_state ) );
		echo '</p>';

		if ( '' !== $redemption ) {
			echo '<p><strong>' . esc_html__( 'Redemptions', 'pixalink-loyalty-for-woocommerce' ) . ':</strong> ' . esc_html( $redemption ) . '</p>';
		}

		if ( array() === $events ) {
			echo '<p class="description">' . esc_html__( 'No events sent to Pixalink for this order yet.', 'pixalink-loyalty-for-woocommerce' ) . '</p>';
			return;
		}

		echo '<ul class="pixalink-loyalty-timeline" style="margin:0">';
		foreach ( $events as $event ) {
			$when = $event->delivered_at ?? $event->created_at;
			echo '<li style="border-top:1px solid #f0f0f1;padding:6px 0;margin:0">';
			echo '<strong>' . esc_html( $event->type->label() ) . '</strong> &middot; ' . esc_html( $event->status->label() );
			echo '<br><span class="description">' . esc_html( self::local_time( $when ) );
			if ( $event->attempts > 1 ) {
				/* translators: %d: number of delivery attempts. */
				echo ' &middot; ' . esc_html( sprintf( _n( '%d attempt', '%d attempts', $event->attempts, 'pixalink-loyalty-for-woocommerce' ), $event->attempts ) );
			}
			echo '</span>';
			if ( null !== $event->last_error && ( $event->status->is_open() || EventStatus::DEAD === $event->status ) ) {
				echo '<br><code style="font-size:11px;word-break:break-all">' . esc_html( (string) $event->last_error ) . '</code>';
			}
			echo '</li>';
		}
		echo '</ul>';

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( Module::events_url( array( 's' => (string) $order->get_id() ) ) ),
			esc_html__( 'View all events', 'pixalink-loyalty-for-woocommerce' )
		);
	}

	/**
	 * `woocommerce_order_actions` filter.
	 *
	 * @param mixed $actions Action id => label.
	 * @return array<string,string>
	 */
	public function order_actions( $actions ): array {
		$actions                        = is_array( $actions ) ? $actions : array();
		$actions[ self::RESEND_ACTION ] = __( 'Resend to Pixalink', 'pixalink-loyalty-for-woocommerce' );

		return $actions;
	}

	/**
	 * `woocommerce_order_action_pixalink_loyalty_resend` callback.
	 *
	 * WooCommerce runs order actions from the order save handler, after its own nonce and edit-capability checks.
	 *
	 * @param mixed $order Order.
	 * @return void
	 */
	public function resend( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$count = $this->lifecycle->resend( $order );
		$order->add_order_note(
			0 === $count
				? __( 'Resend to Pixalink: every event of this order is already delivered or on its way.', 'pixalink-loyalty-for-woocommerce' )
				/* translators: %d: number of events. */
				: sprintf( _n( 'Resend to Pixalink: %d event queued.', 'Resend to Pixalink: %d events queued.', $count, 'pixalink-loyalty-for-woocommerce' ), $count ),
			0,
			true
		);
	}

	/**
	 * Label for an earn state without points.
	 *
	 * @param string $state Earn state meta.
	 * @return string
	 */
	private static function earn_state_label( string $state ): string {
		return match ( $state ) {
			OrderMeta::EARN_QUEUED    => __( 'Sending to Pixalink…', 'pixalink-loyalty-for-woocommerce' ),
			OrderMeta::EARN_DELIVERED => __( 'Recorded (no points reported)', 'pixalink-loyalty-for-woocommerce' ),
			OrderMeta::EARN_DEAD      => __( 'Not recorded: delivery failed', 'pixalink-loyalty-for-woocommerce' ),
			default                   => __( 'Not sent', 'pixalink-loyalty-for-woocommerce' ),
		};
	}

	/**
	 * Stored UTC time in the site's date and time format.
	 *
	 * @param string $mysql_time UTC time.
	 * @return string
	 */
	private static function local_time( string $mysql_time ): string {
		$timestamp = Event::timestamp( $mysql_time );
		if ( null === $timestamp ) {
			return '';
		}

		$format = trim( (string) get_option( 'date_format', 'Y-m-d' ) . ' ' . (string) get_option( 'time_format', 'H:i' ) );

		return (string) wp_date( $format, $timestamp );
	}
}
