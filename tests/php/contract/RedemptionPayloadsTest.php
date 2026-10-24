<?php
/**
 * Contract: quote and hold request bodies built by the plugin.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Contract;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Api\Resources\Redemptions;
use Pixalink\Loyalty\Orders\OrderMeta;
use Pixalink\Loyalty\Redemption\CartPayload;
use Pixalink\Loyalty\Redemption\HoldCoordinator;
use Pixalink\Loyalty\Redemption\HoldRefusedException;
use Pixalink\Loyalty\Tests\FakeServer\ContractValidator;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * `POST quote` and `PUT orders/{order_key}/redemptions` bodies from Mockery doubles of WooCommerce's cart and
 * order; the integration suite sends the same bodies from real carts to the fake server, which answers 422 on any
 * contract violation.
 *
 * @covers \Pixalink\Loyalty\Redemption\CartPayload
 * @covers \Pixalink\Loyalty\Api\Resources\Redemptions
 * @covers \Pixalink\Loyalty\Redemption\HoldCoordinator
 */
final class RedemptionPayloadsTest extends TestCase {

	/**
	 * Whether prices include tax.
	 *
	 * @var bool
	 */
	private bool $inclusive = false;

	protected function set_up(): void {
		parent::set_up();

		$this->inclusive = false;
		Functions\when( 'wc_prices_include_tax' )->alias( fn (): bool => $this->inclusive );
		Functions\when( 'wc_strtolower' )->alias( 'strtolower' );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn ( string $text ): string => trim( strip_tags( $text ) ) );
		Functions\when( 'get_the_terms' )->alias(
			static function ( int $product_id ) {
				if ( 21 !== $product_id ) {
					return false;
				}
				$term       = new \WP_Term();
				$term->slug = 'coffee';
				$term->name = 'Coffee';

				return array( $term );
			}
		);
	}

	/**
	 * A cart: 2 lattes (12.00 each, 10% tax in tax mode), 1 bag of beans without SKU, a store coupon and points.
	 *
	 * @return \WC_Cart
	 */
	private function cart(): \WC_Cart {
		$latte = \Mockery::mock( \WC_Product::class );
		$latte->allows(
			array(
				'get_sku'  => 'LATTE-12',
				'get_name' => 'Latte <em>12oz</em>',
				'get_id'   => 21,
			)
		);
		$beans = \Mockery::mock( \WC_Product::class );
		$beans->allows(
			array(
				'get_sku'  => '',
				'get_name' => 'House Blend',
				'get_id'   => 36,
			)
		);

		$cart = \Mockery::mock( 'WC_Cart' );
		$cart->allows( 'get_cart' )->andReturn(
			array(
				'c4ca4238a0b923820dcc509a6f75849b' => array(
					'data'              => $latte,
					'quantity'          => 2,
					'product_id'        => 21,
					'variation_id'      => 0,
					'line_subtotal'     => 24.0,
					'line_subtotal_tax' => 2.4,
				),
				'c81e728d9d4c2f636f067f89cc14862c' => array(
					'data'              => $beans,
					'quantity'          => 1,
					'product_id'        => 35,
					'variation_id'      => 36,
					'line_subtotal'     => 126.0,
					'line_subtotal_tax' => 12.6,
				),
			)
		);
		$cart->allows(
			array(
				'get_shipping_total'  => '10.00',
				'get_total_tax'       => '13.50',
				'get_applied_coupons' => array( 'summer5', 'pxl-points' ),
			)
		);
		$cart->allows( 'get_coupon_discount_amount' )->andReturnUsing(
			static function ( string $code, bool $ex_tax = true ): float {
				$discounts = array(
					'summer5'    => array( 5.0, 0.5 ),
					'pxl-points' => array( 10.0, 1.0 ),
				);

				return $discounts[ $code ][0] + ( $ex_tax ? 0.0 : $discounts[ $code ][1] );
			}
		);

		return $cart;
	}

	public function test_quote_body_from_a_cart_satisfies_the_schema(): void {
		$cart    = CartPayload::from_cart( $this->cart(), array( 'CH-01JABCDEF', 'not-a-hold' ) );
		$payload = Redemptions::quote_payload( '5001', $cart );

		$this->assertSame( array(), ContractValidator::validate( 'quote.request.json', $payload ) );
		$this->assertSame( 15000, $cart['subtotal_cents'], 'lines before any coupon' );
		$this->assertSame( 500, $cart['discount_cents'], "only the store's own coupons, never points" );
		$this->assertSame( 1000, $cart['shipping_cents'] );
		$this->assertSame( 1350, $cart['tax_cents'] );
		$this->assertFalse( $cart['prices_include_tax'] );
		$this->assertSame( array( 'CH-01JABCDEF' ), $cart['held_refs'] );

		$latte = $cart['lines'][0];
		$this->assertSame( 'c4ca4238a0b923820dcc509a6f75849b', $latte['line_id'] );
		$this->assertSame( 'Latte 12oz', $latte['name'] );
		$this->assertSame( 1200, $latte['unit_price_cents'] );
		$this->assertSame( 2400, $latte['line_total_cents'] );
		$this->assertSame( 'coffee', $latte['category_code'] );
		$this->assertSame( '21', $latte['external_product_id'] );

		$beans = $cart['lines'][1];
		$this->assertNull( $beans['sku'] );
		$this->assertSame( '36', $beans['external_product_id'], 'the variation is the purchasable product' );
		$this->assertArrayNotHasKey( 'category_code', $beans );
	}

	public function test_tax_inclusive_stores_send_amounts_with_tax(): void {
		$this->inclusive = true;

		$cart = CartPayload::from_cart( $this->cart() );

		$this->assertSame( array(), ContractValidator::validate( 'quote.request.json', Redemptions::quote_payload( '5001', $cart ) ) );
		$this->assertTrue( $cart['prices_include_tax'] );
		$this->assertSame( 2640, $cart['lines'][0]['line_total_cents'] );
		$this->assertSame( 1320, $cart['lines'][0]['unit_price_cents'] );
		$this->assertSame( 16500, $cart['subtotal_cents'] );
		$this->assertSame( 550, $cart['discount_cents'] );
	}

	public function test_the_quote_signature_ignores_our_coupons_shipping_and_tax(): void {
		$cart  = CartPayload::from_cart( $this->cart() );
		$other = array_merge(
			$cart,
			array(
				'shipping_cents' => 0,
				'tax_cents'      => 0,
				'held_refs'      => array( 'CH-X1' ),
			)
		);

		$this->assertSame( CartPayload::signature( $cart ), CartPayload::signature( $other ) );

		$other['lines'][0]['quantity'] = 3;
		$this->assertNotSame( CartPayload::signature( $cart ), CartPayload::signature( $other ) );
	}

	/**
	 * Coupon line double.
	 *
	 * @param string              $code     Code.
	 * @param string              $discount Discount.
	 * @param string              $tax      Discount tax.
	 * @param array<string,mixed> $meta     Meta.
	 * @return \WC_Order_Item_Coupon
	 */
	private static function line( string $code, string $discount, string $tax, array $meta ): \WC_Order_Item_Coupon {
		$line = \Mockery::mock( \WC_Order_Item_Coupon::class );
		$line->allows(
			array(
				'get_code'         => $code,
				'get_discount'     => $discount,
				'get_discount_tax' => $tax,
			)
		);
		$line->allows( 'get_meta' )->andReturnUsing( static fn ( string $key ) => $meta[ $key ] ?? '' );

		return $line;
	}

	/**
	 * Order double with coupon lines.
	 *
	 * @param list<\WC_Order_Item_Coupon> $lines Lines.
	 * @return \WC_Order
	 */
	private static function order( array $lines ): \WC_Order {
		$order = \Mockery::mock( \WC_Order::class );
		$order->allows( 'get_items' )->with( 'coupon' )->andReturn( $lines );

		return $order;
	}

	public function test_hold_body_from_the_coupon_lines_satisfies_the_schema(): void {
		$order = self::order(
			array(
				self::line(
					'pxl-welcome-88',
					'10',
					'1',
					array(
						OrderMeta::COUPON_KIND       => 'voucher',
						OrderMeta::COUPON_VOUCHER_ID => '88',
					)
				),
				self::line(
					'pxl-points',
					'9.37',
					'0.94',
					array(
						OrderMeta::COUPON_KIND   => 'points',
						OrderMeta::COUPON_POINTS => '937',
					)
				),
				self::line(
					'pxl-credits',
					'2.5',
					'0.25',
					array(
						OrderMeta::COUPON_KIND         => 'credit',
						OrderMeta::COUPON_AMOUNT_CENTS => '250',
					)
				),
				self::line( 'summer5', '5', '0.5', array() ),
			)
		);

		$redemptions = HoldCoordinator::redemptions( $order );
		$payload     = Redemptions::hold_payload( '5001', CartPayload::from_cart( $this->cart() ), $redemptions );

		$this->assertSame( array(), ContractValidator::validate( 'redemptions-put.request.json', $payload ) );
		$this->assertSame(
			array(
				array(
					'kind'               => 'voucher',
					'coupon_code'        => 'pxl-welcome-88',
					'discount_cents'     => 1000,
					'discount_tax_cents' => 100,
					'voucher_id'         => '88',
				),
				array(
					'kind'               => 'points',
					'coupon_code'        => 'pxl-points',
					'discount_cents'     => 937,
					'discount_tax_cents' => 94,
					'points'             => 937,
				),
				array(
					'kind'               => 'credit',
					'coupon_code'        => 'pxl-credits',
					'discount_cents'     => 250,
					'discount_tax_cents' => 25,
					'amount_cents'       => 250,
				),
			),
			$redemptions,
			'store coupons are not ours to hold'
		);
	}

	public function test_an_empty_set_releases_everything_and_satisfies_the_schema(): void {
		$payload = Redemptions::hold_payload( '5001', CartPayload::from_cart( $this->cart(), array( 'CH-01JABCDEF' ) ), HoldCoordinator::redemptions( self::order( array() ) ) );

		$this->assertSame( array(), $payload['redemptions'] );
		$this->assertSame( array(), ContractValidator::validate( 'redemptions-put.request.json', $payload ) );
	}

	public function test_a_voucher_line_without_its_voucher_id_is_never_sent_unheld(): void {
		$order = self::order( array( self::line( 'pxl-welcome-88', '10', '0', array( OrderMeta::COUPON_KIND => 'voucher' ) ) ) );

		$this->expectException( HoldRefusedException::class );
		HoldCoordinator::redemptions( $order );
	}

	public function test_zero_value_points_and_credits_lines_are_skipped(): void {
		$order = self::order(
			array(
				self::line( 'pxl-points', '0', '0', array( OrderMeta::COUPON_POINTS => '0' ) ),
				self::line( 'pxl-credits', '0', '0', array( OrderMeta::COUPON_AMOUNT_CENTS => '0' ) ),
			)
		);

		$this->assertSame( array(), HoldCoordinator::redemptions( $order ) );
	}

	public function test_hold_idempotency_keys_follow_the_body(): void {
		$payload = Redemptions::hold_payload( '5001', CartPayload::from_cart( $this->cart() ), array() );
		$key     = Redemptions::hold_idempotency_key( 'wc_order_abc', $payload );

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9-]{16,64}$/', $key, 'a valid signature nonce' );
		$this->assertSame( $key, Redemptions::hold_idempotency_key( 'wc_order_abc', $payload ), 'a retry replays' );
		$this->assertNotSame( $key, Redemptions::hold_idempotency_key( 'wc_order_def', $payload ), 'another order is another request' );

		$payload['customer_id'] = '5002';
		$this->assertNotSame( $key, Redemptions::hold_idempotency_key( 'wc_order_abc', $payload ), 'a changed body is a new request' );
		$this->assertSame( 'orders/wc_order_abc/redemptions', Redemptions::path( 'wc_order_abc' ) );
	}
}
