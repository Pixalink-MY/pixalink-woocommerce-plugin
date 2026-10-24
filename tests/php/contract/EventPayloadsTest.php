<?php
/**
 * Contract: event envelopes built by the plugin.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Contract;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Events\Event;
use Pixalink\Loyalty\Events\EventQueue;
use Pixalink\Loyalty\Events\EventType;
use Pixalink\Loyalty\Identity\CustomerPayloadBuilder;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Orders\OrderMeta;
use Pixalink\Loyalty\Orders\OrderPayloadBuilder;
use Pixalink\Loyalty\Orders\RefundPayloadBuilder;
use Pixalink\Loyalty\Support\Uuid;
use Pixalink\Loyalty\Tests\FakeServer\ContractValidator;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * Every order event payload, wrapped in the envelope the deliverer sends, satisfies event.request.json.
 *
 * Orders are Mockery doubles of the WooCommerce CRUD objects; the integration suite builds the same payloads from
 * real orders in both HPOS modes.
 *
 * @covers \Pixalink\Loyalty\Orders\OrderPayloadBuilder
 * @covers \Pixalink\Loyalty\Orders\RefundPayloadBuilder
 * @covers \Pixalink\Loyalty\Events\Event
 * @covers \Pixalink\Loyalty\Identity\CustomerPayloadBuilder
 */
final class EventPayloadsTest extends TestCase {

	private const CONSENT_HASH = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';

	/**
	 * User meta returned by get_user_meta(): user id => key => value.
	 *
	 * @var array<int,array<string,string>>
	 */
	private array $user_meta = array();

	protected function set_up(): void {
		parent::set_up();

		$this->user_meta = array();
		Functions\when( 'get_user_meta' )->alias( fn ( int $user_id, string $key ) => $this->user_meta[ $user_id ][ $key ] ?? '' );
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
	 * Mock that answers get_meta( $key, true ) from a map.
	 *
	 * @param class-string        $class_name Class.
	 * @param array<string,mixed> $getters    Method => return value.
	 * @param array<string,mixed> $meta       Meta key => value.
	 * @return \Mockery\MockInterface
	 */
	private function double( string $class_name, array $getters, array $meta = array() ): \Mockery\MockInterface {
		$double = \Mockery::mock( $class_name );
		foreach ( $getters as $method => $value ) {
			$double->allows( $method )->andReturn( $value );
		}
		$double->allows( 'get_meta' )->andReturnUsing( static fn ( string $key ) => $meta[ $key ] ?? '' );

		return $double;
	}

	/**
	 * A paid member order: one coffee line, a points coupon and a store coupon.
	 *
	 * @param array<string,mixed> $meta      Order meta.
	 * @param array<string,mixed> $overrides Getter overrides.
	 * @return \WC_Order
	 */
	private function order( array $meta = array(), array $overrides = array() ): \WC_Order {
		$product = $this->double( \WC_Product::class, array( 'get_sku' => 'LATTE-12' ) );
		$line    = $this->double(
			\WC_Order_Item_Product::class,
			array(
				'get_id'           => 11,
				'get_product'      => $product,
				'get_product_id'   => 21,
				'get_variation_id' => 0,
				'get_name'         => 'Latte 12oz',
				'get_quantity'     => 2,
				'get_subtotal'     => '24',
				'get_total'        => '21.6',
				'get_total_tax'    => '0',
			)
		);
		$points  = $this->double(
			\WC_Order_Item_Coupon::class,
			array(
				'get_id'           => 12,
				'get_code'         => 'pxl-points',
				'get_discount'     => '5',
				'get_discount_tax' => '0',
			),
			array(
				OrderMeta::COUPON_KIND          => 'points',
				OrderMeta::COUPON_REDEMPTION_ID => 'CH-01JABCDEG',
				OrderMeta::COUPON_POINTS        => '500',
				OrderMeta::COUPON_AMOUNT_CENTS  => '500',
				OrderMeta::COUPON_SNAPSHOT      => array(
					'discount_type'               => 'fixed_cart',
					'amount'                      => '5.00',
					'product_ids'                 => array(),
					'excluded_product_ids'        => array(),
					'product_categories'          => array(),
					'excluded_product_categories' => array(),
					'limit_usage_to_x_items'      => null,
					'exclude_sale_items'          => false,
					'minimum_amount'              => '',
					'maximum_amount'              => '',
					'individual_use'              => false,
				),
			)
		);
		$store   = $this->double(
			\WC_Order_Item_Coupon::class,
			array(
				'get_id'           => 13,
				'get_code'         => 'welcome10',
				'get_discount'     => '10',
				'get_discount_tax' => '0',
			)
		);

		$getters = $overrides + array(
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
			'get_total'              => '145',
			'get_total_tax'          => '0',
			'get_shipping_total'     => '10',
			'get_discount_total'     => '15',
			'get_customer_id'        => 17,
			'get_billing_phone'      => '012-345 6789',
			'get_billing_email'      => 'member@example.com',
			'get_billing_first_name' => 'Aisyah',
			'get_billing_last_name'  => 'Rahman',
		);

		$order = $this->double( \WC_Order::class, $getters, $meta );
		$order->allows( 'get_items' )->with( 'line_item' )->andReturn( array( 11 => $line ) );
		$order->allows( 'get_items' )->with( 'coupon' )->andReturn(
			array(
				12 => $points,
				13 => $store,
			)
		);

		return $order;
	}

	/**
	 * Member meta as the checkout fields store it.
	 *
	 * @return array<string,mixed>
	 */
	private static function member_meta(): array {
		return array(
			OrderMeta::CUSTOMER_ID         => '5001',
			OrderMeta::PHONE_E164          => '+60123456789',
			OrderMeta::PHONE_COUNTRY       => 'MY',
			OrderMeta::MARKETING_CONSENT   => 'yes',
			OrderMeta::CONSENT_SOURCE      => 'checkout',
			OrderMeta::CONSENT_CAPTURED_AT => '2026-10-24 07:58:00',
			OrderMeta::CONSENT_TEXT_HASH   => self::CONSENT_HASH,
		);
	}

	/**
	 * The envelope the deliverer would send for this data.
	 *
	 * @param EventType    $type          Type.
	 * @param array<mixed> $data          Data.
	 * @param string       $discriminator Discriminator.
	 * @return array<string,mixed>
	 */
	private static function envelope( EventType $type, array $data, string $discriminator = '' ): array {
		$event = Event::from_row(
			array(
				'id'          => 1,
				'uuid'        => $type->has_deterministic_id() ? EventQueue::event_uuid( $type, 'https://shop.example.test', 'wc_order_AbC123xyz', $discriminator ) : Uuid::v4(),
				'type'        => $type->value,
				'object_type' => $type->is_order_event() ? 'order' : '',
				'object_id'   => $type->is_order_event() ? 1001 : 0,
				'sequence'    => $type->is_order_event() ? 2 : 0,
				'payload'     => (string) json_encode( $data ),
				'status'      => 'pending',
				'attempts'    => 0,
				'created_at'  => '2026-10-24 08:00:01',
			)
		);
		self::assertNotNull( $event );

		return $event->to_envelope( 'https://shop.example.test', '101' );
	}

	/**
	 * Assert an envelope satisfies the contract.
	 *
	 * @param array<string,mixed> $envelope Envelope.
	 */
	private function assert_valid( array $envelope ): void {
		$this->assertSame( array(), ContractValidator::validate( 'event.request.json', $envelope ), (string) json_encode( $envelope, JSON_PRETTY_PRINT ) );
	}

	public function test_event_types_match_the_schema_enum(): void {
		$schema = json_decode( (string) file_get_contents( ContractValidator::schema_dir() . '/event.request.json' ), true );

		$this->assertSame( $schema['properties']['type']['enum'], EventType::values() );
	}

	public function test_order_paid_for_a_member(): void {
		$data = ( new OrderPayloadBuilder() )->build( $this->order( self::member_meta() ), EventType::ORDER_PAID );

		$this->assert_valid( self::envelope( EventType::ORDER_PAID, $data ) );

		$order = $data['order'];
		$this->assertSame( '145.00', $order['total'] );
		$this->assertSame( '2026-10-24T08:00:00Z', $order['date_paid'] );
		$this->assertNull( $order['date_completed'] );
		$this->assertSame( array( 'coffee' ), $order['line_items'][0]['category_slugs'] );
		$this->assertSame( array( 'Coffee' ), $order['line_items'][0]['category_names'] );
		$this->assertSame( 'LATTE-12', $order['line_items'][0]['sku'] );
		$this->assertSame( 2, $order['line_items'][0]['quantity'] );
		$this->assertSame(
			array(
				'pixalink_kind'          => 'points',
				'pixalink_redemption_id' => 'CH-01JABCDEG',
				'pixalink_voucher_id'    => null,
				'pixalink_points'        => 500,
				'pixalink_amount_cents'  => 500,
				'pixalink_coupon'        => array(
					'discount_type'               => 'fixed_cart',
					'amount'                      => '5.00',
					'product_ids'                 => array(),
					'excluded_product_ids'        => array(),
					'product_categories'          => array(),
					'excluded_product_categories' => array(),
					'limit_usage_to_x_items'      => null,
					'exclude_sale_items'          => false,
					'minimum_amount'              => '',
					'maximum_amount'              => '',
					'individual_use'              => false,
				),
			),
			$order['coupon_lines'][0]['meta']
		);
		$this->assertSame(
			array(
				'pixalink_kind'          => null,
				'pixalink_redemption_id' => null,
				'pixalink_voucher_id'    => null,
				'pixalink_points'        => null,
				'pixalink_amount_cents'  => null,
				'pixalink_coupon'        => null,
			),
			$order['coupon_lines'][1]['meta'],
			'A store coupon carries no Pixalink meta.'
		);

		$this->assertSame(
			array(
				'pixalink_customer_id' => '5001',
				'wp_user_id'           => 17,
				'is_guest'             => false,
				'phone_e164'           => '+60123456789',
				'phone_country'        => 'MY',
				'email'                => 'member@example.com',
				'first_name'           => 'Aisyah',
				'last_name'            => 'Rahman',
				'marketing_consent'    => array(
					'value'       => true,
					'source'      => 'checkout',
					'captured_at' => '2026-10-24T07:58:00Z',
					'text_hash'   => self::CONSENT_HASH,
				),
			),
			$data['customer']
		);
	}

	public function test_guest_without_identity_fields_gets_contract_nulls(): void {
		$data = ( new OrderPayloadBuilder() )->build(
			$this->order(
				array(),
				array(
					'get_customer_id'        => 0,
					'get_billing_phone'      => '0123456789',
					'get_billing_email'      => 'not-an-email',
					'get_billing_first_name' => '',
					'get_billing_last_name'  => '',
					'get_payment_method'     => '',
					'get_created_via'        => '',
				)
			),
			EventType::ORDER_PAID
		);

		$this->assert_valid( self::envelope( EventType::ORDER_PAID, $data ) );
		$this->assertSame(
			array(
				'pixalink_customer_id' => null,
				'wp_user_id'           => null,
				'is_guest'             => true,
				'phone_e164'           => null,
				'phone_country'        => null,
				'email'                => null,
				'first_name'           => null,
				'last_name'            => null,
				'marketing_consent'    => null,
			),
			$data['customer']
		);
		$this->assertNull( $data['order']['payment_method'] );
	}

	public function test_identity_falls_back_to_user_meta_then_an_e164_billing_phone(): void {
		$this->user_meta[17] = array(
			'pixalink_customer_id'   => '5002',
			'pixalink_phone_country' => 'sg',
		);

		$customer = ( new OrderPayloadBuilder() )->customer( $this->order( array(), array( 'get_billing_phone' => '+65 9123 4567' ) ) );

		$this->assertSame( '5002', $customer['pixalink_customer_id'] );
		$this->assertSame( '+6591234567', $customer['phone_e164'] );
		$this->assertSame( 'SG', $customer['phone_country'] );
		$this->assertNull( $customer['marketing_consent'], 'Consent without source, time and text hash is not evidence.' );
	}

	public function test_consent_stored_as_one_array_is_accepted(): void {
		$consent = ( new OrderPayloadBuilder() )->consent(
			$this->order(
				array(
					OrderMeta::MARKETING_CONSENT => array(
						'value'       => false,
						'source'      => 'my_account',
						'captured_at' => 1761292680,
						'text_hash'   => strtoupper( self::CONSENT_HASH ),
					),
				)
			)
		);

		$this->assertSame(
			array(
				'value'       => false,
				'source'      => 'my_account',
				'captured_at' => '2025-10-24T07:58:00Z',
				'text_hash'   => self::CONSENT_HASH,
			),
			$consent
		);
	}

	public function test_malformed_redemption_meta_degrades_to_nulls(): void {
		$coupon = $this->double(
			\WC_Order_Item_Coupon::class,
			array(
				'get_id'           => 14,
				'get_code'         => 'pxl-points',
				'get_discount'     => '5',
				'get_discount_tax' => '0',
			),
			array(
				OrderMeta::COUPON_KIND          => 'cashback',
				OrderMeta::COUPON_REDEMPTION_ID => 'PC-123',
				OrderMeta::COUPON_POINTS        => '0',
				OrderMeta::COUPON_VOUCHER_ID    => str_repeat( '9', 65 ),
			)
		);

		$this->assertSame(
			array(
				'pixalink_kind'          => null,
				'pixalink_redemption_id' => null,
				'pixalink_voucher_id'    => null,
				'pixalink_points'        => null,
				'pixalink_amount_cents'  => null,
				'pixalink_coupon'        => null,
			),
			( new OrderPayloadBuilder() )->coupon_line( $coupon )['meta']
		);
	}

	public function test_order_completed_carries_the_full_order(): void {
		$data = ( new OrderPayloadBuilder() )->build(
			$this->order(
				self::member_meta(),
				array(
					'get_status'         => 'completed',
					'get_date_completed' => new \DateTimeImmutable( '2026-10-25T09:00:00Z' ),
				)
			),
			EventType::ORDER_COMPLETED
		);

		$this->assert_valid( self::envelope( EventType::ORDER_COMPLETED, $data ) );
		$this->assertSame( '2026-10-25T09:00:00Z', $data['order']['date_completed'] );
		$this->assertCount( 1, $data['order']['line_items'] );
	}

	public function test_order_cancelled_says_whether_it_was_paid_and_where_it_came_from(): void {
		$data = ( new OrderPayloadBuilder() )->cancellation( $this->order( self::member_meta(), array( 'get_status' => 'cancelled' ) ), 'processing', true );

		$this->assert_valid( self::envelope( EventType::ORDER_CANCELLED, $data, 'processing' ) );
		$this->assertTrue( $data['was_paid'] );
		$this->assertSame( 'processing', $data['previous_status'] );

		$data['was_paid'] = 'yes';
		$this->assertSame(
			array( '/data/was_paid: The data (string) must match the type: boolean' ),
			ContractValidator::validate( 'event.request.json', self::envelope( EventType::ORDER_CANCELLED, $data, 'processing' ) ),
			'The schema types the cancellation extras.'
		);
	}

	public function test_partial_refund_carries_unsigned_amounts_and_line_items(): void {
		$refunded_line = $this->double(
			\WC_Order_Item_Product::class,
			array(
				'get_quantity' => -1,
				'get_total'    => '-10.8',
			),
			array( '_refunded_item_id' => 11 )
		);
		$amount_only   = $this->double(
			\WC_Order_Item_Product::class,
			array(
				'get_quantity' => 0,
				'get_total'    => '0',
			),
			array( '_refunded_item_id' => 99 )
		);
		$refund        = $this->double(
			\WC_Order_Refund::class,
			array(
				'get_id'             => 77,
				'get_amount'         => '10.80',
				'get_reason'         => 'Damaged cup',
				'get_date_created'   => new \DateTimeImmutable( '2026-10-25T10:00:00Z' ),
				'get_shipping_total' => '-0',
			)
		);
		$refund->allows( 'get_items' )->with( 'line_item' )->andReturn( array( $refunded_line, $amount_only ) );

		$data = ( new RefundPayloadBuilder( new OrderPayloadBuilder() ) )->build( $this->order( self::member_meta() ), $refund );

		$this->assert_valid( self::envelope( EventType::ORDER_REFUNDED, $data, '77' ) );
		$this->assertSame(
			array(
				'id'             => 77,
				'amount'         => '10.80',
				'reason'         => 'Damaged cup',
				'created_at'     => '2026-10-25T10:00:00Z',
				'shipping_total' => '0.00',
				'line_items'     => array(
					array(
						'line_item_id' => 11,
						'quantity'     => 1,
						'total'        => '10.80',
					),
				),
			),
			$data['refund']
		);
	}

	public function test_reference_only_events(): void {
		$builder = new OrderPayloadBuilder();
		$order   = $this->order();

		$deleted  = $builder->reference_data(
			$order,
			array(
				'refund_id' => 77,
				'reason'    => null,
			)
		);
		$released = $builder->reference_data( $order, array( 'reason' => 'trashed' ) );

		$this->assert_valid( self::envelope( EventType::ORDER_REFUND_DELETED, $deleted, '77' ) );
		$this->assert_valid( self::envelope( EventType::ORDER_REDEMPTIONS_RELEASED, $released, 'released' ) );
		$this->assertSame(
			array(
				'id'        => 1001,
				'number'    => '1001',
				'order_key' => 'wc_order_AbC123xyz',
			),
			$released['order']
		);
	}

	public function test_ping_envelope(): void {
		$envelope = self::envelope( EventType::PING, array() );

		$this->assert_valid( $envelope );
		$this->assertSame( '{}', json_encode( $envelope['data'] ) );
	}

	public function test_filters_can_extend_the_payload_but_bad_returns_are_ignored(): void {
		\Brain\Monkey\Filters\expectApplied( 'pixalink_loyalty_line_item_payload' )->once()->andReturnUsing(
			static fn ( array $line ): array => $line + array( 'brand' => 'House' )
		);
		\Brain\Monkey\Filters\expectApplied( 'pixalink_loyalty_order_payload' )->once()->andReturn( 'not an array' );

		$data = ( new OrderPayloadBuilder() )->build( $this->order( self::member_meta() ), EventType::ORDER_PAID );

		$this->assertSame( 'House', $data['order']['line_items'][0]['brand'] );
		$this->assert_valid( self::envelope( EventType::ORDER_PAID, $data ) );
	}

	public function test_customer_upserted_for_a_member_who_changed_their_consent(): void {
		$this->user_meta[17] = array(
			'first_name'                => 'Aisyah',
			'last_name'                 => 'Rahman',
			UserMeta::CUSTOMER_ID       => '5001',
			UserMeta::PHONE_E164        => '+60123456789',
			UserMeta::PHONE_COUNTRY     => 'MY',
			UserMeta::PHONE_VERIFIED_AT => '2026-10-25T02:00:00Z',
			UserMeta::CONSENT           => 'no',
			UserMeta::CONSENT_SOURCE    => 'my_account',
			UserMeta::CONSENT_AT        => '2026-10-25T03:00:00Z',
			UserMeta::CONSENT_TEXT_HASH => self::CONSENT_HASH,
		);
		$user                = new \WP_User();
		$user->ID            = 17;
		$user->user_email    = 'member@example.com';

		$data = ( new CustomerPayloadBuilder( new OrderPayloadBuilder() ) )->for_user( $user );

		$this->assert_valid( self::envelope( EventType::CUSTOMER_UPSERTED, $data ) );
		$this->assertSame(
			array(
				'pixalink_customer_id' => '5001',
				'wp_user_id'           => 17,
				'is_guest'             => false,
				'phone_e164'           => '+60123456789',
				'phone_country'        => 'MY',
				'email'                => 'member@example.com',
				'first_name'           => 'Aisyah',
				'last_name'            => 'Rahman',
				'marketing_consent'    => array(
					'value'       => false,
					'source'      => 'my_account',
					'captured_at' => '2026-10-25T03:00:00Z',
					'text_hash'   => self::CONSENT_HASH,
				),
			),
			$data['customer']
		);
	}

	public function test_customer_upserted_for_an_unlinked_user_never_sends_an_unverified_customer_id_or_a_pixalink_withdrawal(): void {
		$this->user_meta[18] = array(
			'billing_first_name'        => 'Siti',
			UserMeta::CUSTOMER_ID       => '5001',
			UserMeta::PHONE_E164        => '+60199999999',
			UserMeta::PHONE_COUNTRY     => 'MY',
			UserMeta::CONSENT           => 'no',
			UserMeta::CONSENT_SOURCE    => 'pixalink',
			UserMeta::CONSENT_AT        => '2026-10-25T03:00:00Z',
			UserMeta::CONSENT_TEXT_HASH => self::CONSENT_HASH,
		);
		$user                = new \WP_User();
		$user->ID            = 18;
		$user->user_email    = 'not-an-address';

		$data = ( new CustomerPayloadBuilder( new OrderPayloadBuilder() ) )->for_user( $user );

		$this->assert_valid( self::envelope( EventType::CUSTOMER_UPSERTED, $data ) );
		$this->assertNull( $data['customer']['pixalink_customer_id'], 'No verification time, so no link.' );
		$this->assertNull( $data['customer']['email'] );
		$this->assertSame( 'Siti', $data['customer']['first_name'], 'Billing names fill in for empty profile names.' );
		$this->assertNull( $data['customer']['marketing_consent'], 'A withdrawal Pixalink reported is not echoed back.' );
	}

	public function test_customer_upserted_for_a_guest_order_with_new_consent(): void {
		$data = ( new CustomerPayloadBuilder( new OrderPayloadBuilder() ) )->for_order(
			$this->order(
				array(
					OrderMeta::PHONE_E164          => '+60123456789',
					OrderMeta::PHONE_COUNTRY       => 'MY',
					OrderMeta::MARKETING_CONSENT   => 'yes',
					OrderMeta::CONSENT_SOURCE      => 'checkout',
					OrderMeta::CONSENT_CAPTURED_AT => '2026-10-24T07:58:00Z',
					OrderMeta::CONSENT_TEXT_HASH   => self::CONSENT_HASH,
				),
				array( 'get_customer_id' => 0 )
			)
		);

		$this->assert_valid( self::envelope( EventType::CUSTOMER_UPSERTED, $data ) );
		$this->assertTrue( $data['customer']['is_guest'] );
		$this->assertNull( $data['customer']['wp_user_id'] );
		$this->assertTrue( $data['customer']['marketing_consent']['value'] ?? null );
	}

	public function test_customer_upserted_needs_a_way_to_find_the_customer(): void {
		$envelope = self::envelope(
			EventType::CUSTOMER_UPSERTED,
			array(
				'customer' => array(
					'pixalink_customer_id' => null,
					'wp_user_id'           => null,
					'is_guest'             => true,
					'phone_e164'           => null,
					'phone_country'        => null,
					'email'                => 'member@example.com',
					'first_name'           => null,
					'last_name'            => null,
					'marketing_consent'    => null,
				),
			)
		);

		$this->assertNotSame( array(), ContractValidator::validate( 'event.request.json', $envelope ), 'Neither a user id nor a phone number.' );
	}
}
