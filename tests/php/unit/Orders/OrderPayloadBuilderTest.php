<?php
/**
 * Unit: the order object sent with order events.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Orders;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Events\EventType;
use Pixalink\Loyalty\Orders\OrderPayloadBuilder;
use Pixalink\Loyalty\Orders\RefundPayloadBuilder;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * A guest order without lines: only the order-level fields matter here. The contract suite validates full payloads.
 *
 * @covers \Pixalink\Loyalty\Orders\OrderPayloadBuilder
 * @covers \Pixalink\Loyalty\Orders\RefundPayloadBuilder
 */
final class OrderPayloadBuilderTest extends TestCase {

	/**
	 * Guest order double that recorded whether its prices included tax.
	 *
	 * @param bool $prices_include_tax WC_Order::get_prices_include_tax().
	 * @return \WC_Order
	 */
	private static function order( bool $prices_include_tax ): \WC_Order {
		$getters = array(
			'get_id'                 => 1001,
			'get_order_number'       => '1001',
			'get_order_key'          => 'wc_order_AbC123xyz',
			'get_status'             => 'processing',
			'get_currency'           => 'MYR',
			'get_date_created'       => new \DateTimeImmutable( '2026-10-24T07:58:00Z' ),
			'get_date_paid'          => new \DateTimeImmutable( '2026-10-24T08:00:00Z' ),
			'get_date_completed'     => null,
			'get_payment_method'     => 'bacs',
			'get_created_via'        => 'store-api',
			'get_total'              => '106',
			'get_total_tax'          => '6',
			'get_shipping_total'     => '0',
			'get_discount_total'     => '0',
			'get_prices_include_tax' => $prices_include_tax,
			'get_customer_id'        => 0,
			'get_billing_phone'      => '',
			'get_billing_email'      => '',
			'get_billing_first_name' => '',
			'get_billing_last_name'  => '',
		);

		$order = \Mockery::mock( \WC_Order::class );
		foreach ( $getters as $method => $value ) {
			$order->allows( $method )->andReturn( $value );
		}
		$order->allows( 'get_items' )->andReturn( array() );
		$order->allows( 'get_meta' )->andReturn( '' );

		return $order;
	}

	/**
	 * Both answers WooCommerce can record on an order.
	 *
	 * @return array<string,array{0:bool}>
	 */
	public static function tax_modes(): array {
		return array(
			'tax-inclusive prices' => array( true ),
			'tax-exclusive prices' => array( false ),
		);
	}

	/**
	 * @dataProvider tax_modes
	 *
	 * @param bool $prices_include_tax What the order recorded.
	 */
	public function test_prices_include_tax_is_what_the_order_recorded_not_the_current_store_setting( bool $prices_include_tax ): void {
		Functions\when( 'wc_prices_include_tax' )->justReturn( ! $prices_include_tax );

		$order = ( new OrderPayloadBuilder() )->build( self::order( $prices_include_tax ), EventType::ORDER_PAID )['order'];

		$this->assertArrayHasKey( 'prices_include_tax', $order );
		$this->assertSame( $prices_include_tax, $order['prices_include_tax'] );
	}

	public function test_refund_and_cancellation_payloads_carry_it_on_their_order(): void {
		$refund = \Mockery::mock( \WC_Order_Refund::class );
		$refund->allows( 'get_items' )->with( 'line_item' )->andReturn( array() );
		$refund->allows( 'get_id' )->andReturn( 77 );
		$refund->allows( 'get_amount' )->andReturn( '10.60' );
		$refund->allows( 'get_reason' )->andReturn( '' );
		$refund->allows( 'get_date_created' )->andReturn( new \DateTimeImmutable( '2026-10-25T10:00:00Z' ) );
		$refund->allows( 'get_shipping_total' )->andReturn( '0' );

		$builder   = new OrderPayloadBuilder();
		$refunded  = ( new RefundPayloadBuilder( $builder ) )->build( self::order( true ), $refund );
		$cancelled = $builder->cancellation( self::order( true ), 'processing', true );

		$this->assertTrue( $refunded['order']['prices_include_tax'] );
		$this->assertTrue( $cancelled['order']['prices_include_tax'] );
	}
}
