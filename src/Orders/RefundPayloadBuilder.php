<?php
/**
 * `data` of order.refunded.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Orders;

use Pixalink\Loyalty\Events\EventType;
use Pixalink\Loyalty\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * One `order.refunded` per WooCommerce refund: the order as it stands after the refund, plus the refund itself.
 *
 * WooCommerce stores refund amounts, line quantities and totals as negative numbers; the contract carries them as
 * positive amounts refunded (`refund.amount` is non-negative), so every refund figure is sent unsigned. A refund
 * without line items is an amount-only refund that Pixalink pro-rates.
 */
final class RefundPayloadBuilder {

	/**
	 * Order payload builder.
	 *
	 * @var OrderPayloadBuilder
	 */
	private OrderPayloadBuilder $orders;

	/**
	 * Constructor.
	 *
	 * @param OrderPayloadBuilder $orders Order payload builder.
	 */
	public function __construct( OrderPayloadBuilder $orders ) {
		$this->orders = $orders;
	}

	/**
	 * `{order, customer, refund}`.
	 *
	 * @param \WC_Order        $order  Refunded order.
	 * @param \WC_Order_Refund $refund The refund.
	 * @return array<string,mixed>
	 */
	public function build( \WC_Order $order, \WC_Order_Refund $refund ): array {
		$data           = $this->orders->build( $order, EventType::ORDER_REFUNDED );
		$data['refund'] = $this->refund( $refund );

		return $data;
	}

	/**
	 * The `refund` object.
	 *
	 * @param \WC_Order_Refund $refund Refund.
	 * @return array<string,mixed>
	 */
	public function refund( \WC_Order_Refund $refund ): array {
		$line_items = array();
		foreach ( $refund->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$line_item_id = (int) $item->get_meta( '_refunded_item_id', true );
			$quantity     = abs( OrderPayloadBuilder::quantity( $item->get_quantity() ) );
			$total        = self::unsigned( $item->get_total() );
			if ( $line_item_id < 1 || ( 0.0 === (float) $quantity && 0 === Money::to_cents( $total ) ) ) {
				continue;
			}

			$line_items[] = array(
				'line_item_id' => $line_item_id,
				'quantity'     => $quantity,
				'total'        => $total,
			);
		}

		$reason = trim( (string) $refund->get_reason() );

		return array(
			'id'             => (int) $refund->get_id(),
			'amount'         => self::unsigned( $refund->get_amount() ),
			'reason'         => '' !== $reason ? $reason : null,
			'created_at'     => OrderPayloadBuilder::iso8601( $refund->get_date_created() ) ?? gmdate( 'Y-m-d\TH:i:s\Z' ),
			'shipping_total' => self::unsigned( $refund->get_shipping_total() ),
			'line_items'     => $line_items,
		);
	}

	/**
	 * Unsigned decimal string.
	 *
	 * @param mixed $amount Amount.
	 * @return string
	 */
	private static function unsigned( $amount ): string {
		return ltrim( Money::to_decimal( is_numeric( $amount ) ? (float) $amount : 0.0 ), '-' );
	}
}
