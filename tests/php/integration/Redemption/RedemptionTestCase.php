<?php
/**
 * Shared fixture of the redemption integration tests.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Redemption;

use Pixalink\Loyalty\Connect\Connection;
use Pixalink\Loyalty\Events\EventRepository;
use Pixalink\Loyalty\Identity\UserMetaShopperContext;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Redemption\CartActions;
use Pixalink\Loyalty\Redemption\Module;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\FakeServer\Server;
use Pixalink\Loyalty\Tests\FakeServer\Signature;

/**
 * A connected store (fake Pixalink server, every redemption switched on by the heartbeat), two virtual products
 * (A 100.00 SKU PXL-A, B 50.00 SKU PXL-B), BACS, a loaded WC cart and session, and a verified member (Pixalink
 * customer 5001, 1,250 points and 25.00 credits on the fake server) logged in as the shopper.
 */
abstract class RedemptionTestCase extends \WP_UnitTestCase {

	/**
	 * Product A id.
	 *
	 * @var int
	 */
	protected int $product_a = 0;

	/**
	 * Product B id.
	 *
	 * @var int
	 */
	protected int $product_b = 0;

	/**
	 * The shopper.
	 *
	 * @var int
	 */
	protected int $shopper = 0;

	public function set_up(): void {
		parent::set_up();

		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_enable_coupons', 'yes' );
		update_option( 'woocommerce_calc_taxes', 'no' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		update_option( 'woocommerce_calc_discounts_sequentially', 'no' );
		update_option(
			'woocommerce_bacs_settings',
			array(
				'enabled' => 'yes',
				'title'   => 'Direct bank transfer',
			)
		);
		WC()->payment_gateways()->init();

		$this->connect_store();
		$this->switch_features( true, true, true );

		$this->shopper = self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'member@example.com',
			)
		);
		update_user_meta( $this->shopper, UserMetaShopperContext::CUSTOMER_ID_META, '5001' );
		update_user_meta( $this->shopper, UserMetaShopperContext::VERIFIED_AT_META, gmdate( 'Y-m-d H:i:s' ) );
		wp_set_current_user( $this->shopper );

		$this->fresh_session();

		$this->product_a = $this->product( 'Product A', 'PXL-A', '100' );
		$this->product_b = $this->product( 'Product B', 'PXL-B', '50' );
	}

	public function tear_down(): void {
		if ( null !== WC()->cart ) {
			WC()->cart->empty_cart();
		}
		$this->module()->state()->reset();
		wc_clear_notices();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * The redemption module.
	 *
	 * @return Module
	 */
	protected function module(): Module {
		return Plugin::instance()->redemption();
	}

	/**
	 * Credentials for the fake server, an empty outbox and no recorded requests.
	 *
	 * @return void
	 */
	protected function connect_store(): void {
		global $wpdb;

		Server::reset();
		delete_option( Options::CLOCK_OFFSET );
		delete_option( Options::DELIVERY_PAUSED );
		delete_transient( 'pixalink_loyalty_outlet_id' );
		Plugin::instance()->credentials()->store( Signature::FAKE_API_KEY );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', ( new EventRepository() )->table() ) );
	}

	/**
	 * Heartbeat state with the given remote switches.
	 *
	 * @param bool   $vouchers Vouchers.
	 * @param bool   $points   Points redemption.
	 * @param bool   $credits  Credits.
	 * @param string $earn_on  "paid" or "completed".
	 * @return void
	 */
	protected function switch_features( bool $vouchers, bool $points, bool $credits, string $earn_on = 'paid' ): void {
		( new Connection( new Options() ) )->store_remote_status(
			array(
				'status'   => 'active',
				'features' => array(
					'earn'              => true,
					'vouchers'          => $vouchers,
					'points_redemption' => $points,
					'credits'           => $credits,
					'otp_channels'      => array( 'whatsapp' ),
				),
				'earn_on'  => $earn_on,
			)
		);
	}

	/**
	 * Empty cart, no draft order, no redemption state, no notices.
	 *
	 * @return void
	 */
	protected function fresh_session(): void {
		wc_load_cart();
		WC()->cart->empty_cart();
		WC()->session->set( 'store_api_draft_order', 0 );
		WC()->session->set( 'order_awaiting_payment', 0 );
		$this->module()->state()->reset();
		wc_clear_notices();
	}

	/**
	 * A virtual, taxable simple product.
	 *
	 * @param string $name  Name.
	 * @param string $sku   SKU.
	 * @param string $price Regular price.
	 * @return int
	 */
	protected function product( string $name, string $sku, string $price ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_sku( $sku );
		$product->set_regular_price( $price );
		$product->set_virtual( true );
		$product->set_tax_status( 'taxable' );
		$product->save();

		return $product->get_id();
	}

	/**
	 * A 10% standard rate with prices entered inclusive or exclusive of tax; prices re-entered so both modes share
	 * the same net amounts (A 100 / B 50 before tax).
	 *
	 * @param bool $prices_include_tax Inclusive store.
	 * @return void
	 */
	protected function use_taxes( bool $prices_include_tax ): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', $prices_include_tax ? 'yes' : 'no' );
		update_option( 'woocommerce_tax_based_on', 'base' );
		update_option( 'woocommerce_tax_round_at_subtotal', 'no' );
		\WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => '',
				'tax_rate_state'    => '',
				'tax_rate'          => '10.0000',
				'tax_rate_name'     => 'TAX',
				'tax_rate_priority' => '1',
				'tax_rate_compound' => '0',
				'tax_rate_shipping' => '1',
				'tax_rate_order'    => '1',
				'tax_rate_class'    => '',
			)
		);

		foreach ( array(
			$this->product_a => 100,
			$this->product_b => 50,
		) as $id => $net ) {
			$product = wc_get_product( $id );
			$product->set_regular_price( (string) ( $prices_include_tax ? $net * 1.1 : $net ) );
			$product->save();
		}
	}

	/**
	 * Cart: 1 x A, 2 x B.
	 *
	 * @return void
	 */
	protected function fill_cart(): void {
		WC()->cart->add_to_cart( $this->product_a, 1 );
		WC()->cart->add_to_cart( $this->product_b, 2 );
		WC()->cart->calculate_totals();
	}

	/**
	 * Dispatch a Store API request with the `wc_store_api` nonce (what Blocks and the classic fetch both send).
	 *
	 * @param string              $method HTTP method.
	 * @param string              $route  Route under /wc/store/v1.
	 * @param array<string,mixed> $body   JSON body.
	 * @param bool                $nonce  Send the Nonce header.
	 * @return \WP_REST_Response
	 */
	protected function store_api( string $method, string $route, array $body = array(), bool $nonce = true ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/wc/store/v1/' . ltrim( $route, '/' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $nonce ) {
			$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		}
		if ( array() !== $body ) {
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		// A fresh server is a fresh set of Store API route objects, like a new HTTP request (Checkout keeps $this->order).
		$GLOBALS['wp_rest_server'] = null;

		$response = rest_ensure_response( rest_get_server()->dispatch( $request ) );

		// Store API schemas return nested stdClass objects; compare what a client would decode.
		$response->set_data( json_decode( (string) wp_json_encode( $response->get_data() ), true ) );

		return $response;
	}

	/**
	 * POST /cart/extensions for our namespace.
	 *
	 * @param array<string,mixed> $data Action data.
	 * @return \WP_REST_Response
	 */
	protected function cart_action( array $data ): \WP_REST_Response {
		return $this->store_api(
			'POST',
			'cart/extensions',
			array(
				'namespace' => CartActions::STORE_API_NAMESPACE,
				'data'      => $data,
			)
		);
	}

	/**
	 * A cart action that must succeed.
	 *
	 * @param array<string,mixed> $data Action data.
	 * @return array<string,mixed> Cart response.
	 */
	protected function act( array $data ): array {
		$response = $this->cart_action( $data );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	/**
	 * Billing address accepted by both checkouts (US / CA).
	 *
	 * @return array<string,string>
	 */
	protected function billing_address(): array {
		return array(
			'first_name' => 'Aisyah',
			'last_name'  => 'Rahman',
			'address_1'  => '1 Market St',
			'city'       => 'San Francisco',
			'state'      => 'CA',
			'postcode'   => '94105',
			'country'    => 'US',
			'email'      => 'member@example.com',
			'phone'      => '4155550100',
		);
	}

	/**
	 * Classic checkout: WC_Checkout::create_order() with posted data.
	 *
	 * @return int|\WP_Error
	 */
	protected function classic_create_order() {
		$posted = array( 'payment_method' => 'bacs' );
		foreach ( $this->billing_address() as $key => $value ) {
			$posted[ 'billing_' . $key ] = $value;
		}

		WC()->cart->calculate_totals();

		return WC()->checkout()->create_order( $posted );
	}

	/**
	 * Blocks checkout: POST /wc/store/v1/checkout.
	 *
	 * @return \WP_REST_Response
	 */
	protected function blocks_checkout(): \WP_REST_Response {
		return $this->store_api(
			'POST',
			'checkout',
			array(
				'billing_address' => $this->billing_address(),
				'payment_method'  => 'bacs',
			)
		);
	}

	/**
	 * The order's coupon line for a code.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $code  Code.
	 * @return \WC_Order_Item_Coupon
	 */
	protected function coupon_line( \WC_Order $order, string $code ): \WC_Order_Item_Coupon {
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Coupon && wc_is_same_coupon( $item->get_code(), $code ) ) {
				return $item;
			}
		}

		$this->fail( sprintf( 'Order has no coupon line "%s"; lines: %s', $code, implode( ', ', $order->get_coupon_codes() ) ) );
	}

	/**
	 * Requests the fake Pixalink server received for a method and path prefix.
	 *
	 * @param string $method Method.
	 * @param string $prefix Path prefix.
	 * @return list<array<string,mixed>>
	 */
	protected function pixalink_requests( string $method, string $prefix ): array {
		return array_values(
			array_filter(
				Server::recorded(),
				static fn ( array $request ): bool => $method === $request['method'] && 0 === strpos( (string) $request['path'], $prefix )
			)
		);
	}

	/**
	 * The last request the fake server received for a method and path prefix.
	 *
	 * @param string $method Method.
	 * @param string $prefix Path prefix.
	 * @return array<string,mixed>
	 */
	protected function last_request( string $method, string $prefix ): array {
		$requests = $this->pixalink_requests( $method, $prefix );
		$this->assertNotEmpty( $requests, sprintf( 'no %s %s recorded', $method, $prefix ) );

		return end( $requests );
	}

	/**
	 * IDs of all orders, drafts included.
	 *
	 * @return list<int>
	 */
	protected function order_ids(): array {
		return array_values(
			array_map(
				'intval',
				wc_get_orders(
					array(
						'limit'  => -1,
						'return' => 'ids',
						'status' => array_keys( wc_get_order_statuses() ),
					)
				)
			)
		);
	}
}
