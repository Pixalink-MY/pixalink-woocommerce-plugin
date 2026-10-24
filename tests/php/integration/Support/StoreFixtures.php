<?php
/**
 * Store, cart and Store API helpers for the W2 integration tests.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Support;

use Pixalink\Loyalty\Account\SummaryService;
use Pixalink\Loyalty\Events\EventRepository;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\FakeServer\Server;
use Pixalink\Loyalty\Tests\FakeServer\Signature;

/**
 * A Malaysian store connected to the fake Pixalink server, with BACS, guest checkout, a loaded cart and helpers
 * that dispatch Store API requests the way the Checkout block does.
 */
trait StoreFixtures {

	/**
	 * Configure the store and connect it.
	 *
	 * @return void
	 */
	protected function set_up_store(): void {
		global $wpdb;

		update_option( 'woocommerce_default_country', 'MY:KUL' );
		update_option( 'woocommerce_currency', 'MYR' );
		update_option( 'woocommerce_calc_taxes', 'no' );
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		update_option( 'woocommerce_enable_myaccount_registration', 'yes' );
		update_option(
			'woocommerce_bacs_settings',
			array(
				'enabled' => 'yes',
				'title'   => 'Direct bank transfer',
			)
		);
		WC()->payment_gateways()->init();

		Server::reset();
		delete_option( Options::DELIVERY_PAUSED );
		delete_option( Options::CLOCK_OFFSET );
		delete_transient( 'pixalink_loyalty_outlet_id' );
		Plugin::instance()->credentials()->store( Signature::FAKE_API_KEY );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', ( new EventRepository() )->table() ) );

		wc_load_cart();
		WC()->cart->empty_cart();
		WC()->session->set( 'store_api_draft_order', 0 );
		WC()->session->set( 'order_awaiting_payment', 0 );
		wc_clear_notices();

		add_filter( 'send_auth_cookies', '__return_false' );
	}

	/**
	 * Undo the store connection.
	 *
	 * @return void
	 */
	protected function tear_down_store(): void {
		if ( null !== WC()->cart ) {
			WC()->cart->empty_cart();
		}
		wc_clear_notices();
		remove_filter( 'send_auth_cookies', '__return_false' );
		Plugin::instance()->credentials()->clear();
	}

	/**
	 * A virtual product in the cart.
	 *
	 * @return int Product id.
	 */
	protected function fill_cart(): int {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Kaya Toast' );
		$product->set_sku( 'KAYA-' . wp_generate_password( 6, false ) );
		$product->set_regular_price( '12.00' );
		$product->set_virtual( true );
		$product->save();

		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->cart->calculate_totals();

		return $product->get_id();
	}

	/**
	 * A user, optionally with a verified Pixalink link.
	 *
	 * @param string $email    E-mail.
	 * @param string $role     Role.
	 * @return int
	 */
	protected function make_user( string $email = 'member@example.com', string $role = 'customer' ): int {
		$user_id = self::factory()->user->create(
			array(
				'role'       => $role,
				'user_email' => $email,
				'first_name' => 'Aisyah',
				'last_name'  => 'Rahman',
			)
		);
		Plugin::instance()->identity()->summaries()->bust( $user_id );
		delete_transient( SummaryService::key( $user_id ) );

		return $user_id;
	}

	/**
	 * Dispatch a Store API request with the `wc_store_api` nonce.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $route  Route under /wc/store/v1.
	 * @param array<string,mixed> $body   JSON body.
	 * @return \WP_REST_Response
	 */
	protected function store_api( string $method, string $route, array $body = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/wc/store/v1/' . ltrim( $route, '/' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		if ( array() !== $body ) {
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		// A fresh server is a fresh Store API route object, like a new HTTP request.
		$GLOBALS['wp_rest_server'] = null;

		$response = rest_ensure_response( rest_get_server()->dispatch( $request ) );
		$response->set_data( json_decode( (string) wp_json_encode( $response->get_data() ), true ) );

		return $response;
	}

	/**
	 * Billing address both checkouts accept.
	 *
	 * @return array<string,string>
	 */
	protected function billing_address(): array {
		return array(
			'first_name' => 'Aisyah',
			'last_name'  => 'Rahman',
			'address_1'  => '1 Jalan Ampang',
			'city'       => 'Kuala Lumpur',
			'state'      => 'KUL',
			'postcode'   => '50450',
			'country'    => 'MY',
			'email'      => 'member@example.com',
			'phone'      => '',
		);
	}

	/**
	 * POST /wc/store/v1/checkout with additional fields.
	 *
	 * @param array<string,mixed> $additional Additional field values.
	 * @return \WP_REST_Response
	 */
	protected function blocks_checkout( array $additional ): \WP_REST_Response {
		return $this->store_api(
			'POST',
			'checkout',
			array(
				'billing_address'   => $this->billing_address(),
				'payment_method'    => 'bacs',
				'additional_fields' => $additional,
			)
		);
	}

	/**
	 * Classic checkout: WC_Checkout::create_order() with posted data, then the order-processed hook.
	 *
	 * @param array<string,mixed> $fields Extra posted fields.
	 * @return \WC_Order
	 */
	protected function classic_checkout( array $fields ): \WC_Order {
		$posted = array( 'payment_method' => 'bacs' ) + $fields;
		foreach ( $this->billing_address() as $key => $value ) {
			$posted[ 'billing_' . $key ] = $value;
		}

		WC()->cart->calculate_totals();
		$order_id = WC()->checkout()->create_order( $posted );
		$this->assertIsInt( $order_id, is_wp_error( $order_id ) ? $order_id->get_error_message() : 'create_order failed' );

		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $order );
		do_action( 'woocommerce_checkout_order_processed', $order_id, $posted, $order );

		$fresh = wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $fresh );

		return $fresh;
	}

	/**
	 * Types of the events recorded in the outbox.
	 *
	 * @return list<string>
	 */
	protected function outbox_types(): array {
		global $wpdb;

		return array_map( 'strval', $wpdb->get_col( $wpdb->prepare( 'SELECT type FROM %i ORDER BY id', ( new EventRepository() )->table() ) ) );
	}

	/**
	 * Payload of the last recorded event.
	 *
	 * @return array<mixed>
	 */
	protected function last_payload(): array {
		global $wpdb;

		$payload = $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM %i ORDER BY id DESC LIMIT 1', ( new EventRepository() )->table() ) );
		$decoded = is_string( $payload ) ? json_decode( $payload, true ) : null;

		return is_array( $decoded ) ? $decoded : array();
	}
}
