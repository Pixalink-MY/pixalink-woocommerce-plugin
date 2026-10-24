<?php
/**
 * SPIKE (P02, throwaway): shared fixture for the virtual-coupon experiment.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Spike;

use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Spike\RedemptionState;
use Pixalink\Loyalty\Spike\Spike;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\FakeServer\Server;
use Pixalink\Loyalty\Tests\FakeServer\Signature;

/**
 * Store with two virtual products (A $100 SKU PXL-A, B $50 SKU PXL-B), BACS enabled, a loaded WC cart/session,
 * credentials for the fake Pixalink server, and Store API dispatch helpers.
 */
abstract class SpikeTestCase extends \WP_UnitTestCase {

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

	public function set_up(): void {
		parent::set_up();

		$this->assertNotNull( Spike::instance(), 'Spike must be registered by the bootstrap (PIXALINK_LOYALTY_SPIKE).' );

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

		Server::reset();
		delete_option( Options::CLOCK_OFFSET );
		Plugin::instance()->credentials()->store( Signature::FAKE_API_KEY );

		wc_load_cart();
		WC()->cart->empty_cart();
		WC()->session->set( 'store_api_draft_order', 0 );
		WC()->session->set( 'order_awaiting_payment', 0 );
		RedemptionState::reset();
		wc_clear_notices();

		$this->product_a = $this->product( 'Spike A', 'PXL-A', '100' );
		$this->product_b = $this->product( 'Spike B', 'PXL-B', '50' );
	}

	public function tear_down(): void {
		if ( null !== WC()->cart ) {
			WC()->cart->empty_cart();
		}
		RedemptionState::reset();
		wc_clear_notices();

		parent::tear_down();
	}

	/**
	 * Create a virtual simple product.
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
	 * Switch on a 10% standard rate, prices entered inclusive or exclusive of tax, taxed at the store base address.
	 * Prices are re-entered so both modes share the same net amounts (A 100 / B 50 before tax).
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

		foreach ( array( $this->product_a => 100, $this->product_b => 50 ) as $id => $net ) {
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

		// Fresh server = fresh Store API route objects, like a new HTTP request (the Checkout route keeps $this->order).
		$GLOBALS['wp_rest_server'] = null;

		$response = rest_ensure_response( rest_get_server()->dispatch( $request ) );

		// Store API schemas return nested stdClass objects (totals, extensions); compare what a client would decode.
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
				'namespace' => Spike::STORE_API_NAMESPACE,
				'data'      => $data,
			)
		);
	}

	/**
	 * Billing address accepted by both checkouts (US / CA).
	 *
	 * @return array<string,string>
	 */
	protected function billing_address(): array {
		return array(
			'first_name' => 'Spike',
			'last_name'  => 'Shopper',
			'address_1'  => '1 Market St',
			'city'       => 'San Francisco',
			'state'      => 'CA',
			'postcode'   => '94105',
			'country'    => 'US',
			'email'      => 'shopper@example.com',
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
	 * Requests the fake Pixalink server received for a method + path prefix.
	 *
	 * @param string $method Method.
	 * @param string $prefix Path prefix.
	 * @return array<int,array<string,mixed>>
	 */
	protected function pixalink_requests( string $method, string $prefix ): array {
		return array_values(
			array_filter(
				Server::recorded(),
				static fn ( array $request ): bool => $method === $request['method'] && 0 === strpos( (string) $request['path'], $prefix )
			)
		);
	}
}
