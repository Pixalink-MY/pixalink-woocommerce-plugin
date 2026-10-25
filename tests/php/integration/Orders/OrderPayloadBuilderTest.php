<?php
/**
 * Integration tests for OrderPayloadBuilder and RefundPayloadBuilder.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Orders;

use Pixalink\Loyalty\Events\Event;
use Pixalink\Loyalty\Events\EventQueue;
use Pixalink\Loyalty\Events\EventType;
use Pixalink\Loyalty\Orders\OrderMeta;
use Pixalink\Loyalty\Orders\OrderPayloadBuilder;
use Pixalink\Loyalty\Orders\RefundPayloadBuilder;
use Pixalink\Loyalty\Tests\FakeServer\ContractValidator;
use Pixalink\Loyalty\Tests\Integration\Support\OrderFixtures;

/**
 * Payloads built from real WooCommerce orders (HPOS or posts) satisfy event.request.json.
 *
 * @covers \Pixalink\Loyalty\Orders\OrderPayloadBuilder
 * @covers \Pixalink\Loyalty\Orders\RefundPayloadBuilder
 */
final class OrderPayloadBuilderTest extends \WP_UnitTestCase {

	use OrderFixtures;

	/**
	 * Wrap data in an envelope and validate it.
	 *
	 * @param EventType    $type  Type.
	 * @param array<mixed> $data  Data.
	 * @param \WC_Order    $order Order.
	 */
	private function assert_valid_envelope( EventType $type, array $data, \WC_Order $order ): void {
		$event = Event::from_row(
			array(
				'id'          => 1,
				'uuid'        => EventQueue::event_uuid( $type, home_url(), $order->get_order_key() ),
				'type'        => $type->value,
				'object_type' => 'order',
				'object_id'   => $order->get_id(),
				'sequence'    => 1,
				'payload'     => (string) wp_json_encode( $data ),
				'status'      => 'pending',
				'attempts'    => 0,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$this->assertNotNull( $event );

		$envelope = $event->to_envelope( home_url(), '101' );
		$this->assertSame( array(), ContractValidator::validate( 'event.request.json', $envelope ), (string) wp_json_encode( $envelope, JSON_PRETTY_PRINT ) );
	}

	public function test_a_real_paid_order_with_a_pixalink_coupon_line(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order   = $this->create_order( $user_id );

		$coupon = new \WC_Order_Item_Coupon();
		$coupon->set_code( 'pxl-points' );
		$coupon->set_discount( '5' );
		$coupon->set_discount_tax( '0' );
		$coupon->add_meta_data( OrderMeta::COUPON_KIND, 'points' );
		$coupon->add_meta_data( OrderMeta::COUPON_REDEMPTION_ID, 'CH-01JABCDEG' );
		$coupon->add_meta_data( OrderMeta::COUPON_POINTS, '500' );
		$order->add_item( $coupon );

		$order->update_meta_data( OrderMeta::CUSTOMER_ID, '5001' );
		$order->update_meta_data( OrderMeta::PHONE_E164, '+60123456789' );
		$order->update_meta_data( OrderMeta::PHONE_COUNTRY, 'MY' );
		$order->update_meta_data( OrderMeta::MARKETING_CONSENT, 'yes' );
		$order->update_meta_data( OrderMeta::CONSENT_SOURCE, 'checkout' );
		$order->update_meta_data( OrderMeta::CONSENT_CAPTURED_AT, gmdate( 'Y-m-d H:i:s' ) );
		$order->update_meta_data( OrderMeta::CONSENT_TEXT_HASH, hash( 'sha256', 'I agree to receive offers.' ) );
		$order->save();
		$order->payment_complete();

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$data = ( new OrderPayloadBuilder() )->build( $order, EventType::ORDER_PAID );

		$this->assert_valid_envelope( EventType::ORDER_PAID, $data, $order );

		$line = $data['order']['line_items'][0];
		$this->assertSame( 'Latte 12oz', $line['name'] );
		$this->assertSame( 2, $line['quantity'] );
		$this->assertSame( '24.00', $line['subtotal'] );
		$this->assertStringStartsWith( 'LATTE-', (string) $line['sku'] );
		$this->assertCount( 1, $line['category_slugs'] );

		$this->assertSame( 'pxl-points', $data['order']['coupon_lines'][0]['code'] );
		$this->assertSame( 'CH-01JABCDEG', $data['order']['coupon_lines'][0]['meta']['pixalink_redemption_id'] );
		$this->assertSame( 500, $data['order']['coupon_lines'][0]['meta']['pixalink_points'] );

		$this->assertSame( $user_id, $data['customer']['wp_user_id'] );
		$this->assertFalse( $data['customer']['is_guest'] );
		$this->assertSame( '5001', $data['customer']['pixalink_customer_id'] );
		$this->assertTrue( $data['customer']['marketing_consent']['value'] );
		$this->assertNotNull( $data['order']['date_paid'] );
	}

	public function test_a_real_guest_refund(): void {
		$order = $this->create_order();
		$order->payment_complete();
		$item_id = array_key_first( $order->get_items() );
		$refund  = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => '12.00',
				'line_items' => array(
					$item_id => array(
						'qty'          => 1,
						'refund_total' => 12,
					),
				),
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$data = ( new RefundPayloadBuilder( new OrderPayloadBuilder() ) )->build( $order, $refund );

		$this->assert_valid_envelope( EventType::ORDER_REFUNDED, $data, $order );
		$this->assertTrue( $data['customer']['is_guest'] );
		$this->assertSame( '+60123456789', $data['customer']['phone_e164'], 'An E.164 billing phone is used when no verified phone is stored.' );
		$this->assertSame( '12.00', $data['refund']['amount'] );
		$this->assertSame( $item_id, $data['refund']['line_items'][0]['line_item_id'] );
		$this->assertSame( 1, $data['refund']['line_items'][0]['quantity'] );
	}
}
