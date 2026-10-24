<?php
/**
 * Order fixtures shared by the order-event integration tests.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Support;

use Pixalink\Loyalty\Events\Event;
use Pixalink\Loyalty\Events\EventRepository;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\FakeServer\Server;
use Pixalink\Loyalty\Tests\FakeServer\Signature;

/**
 * Connects the store to the fake Pixalink server and builds real WooCommerce orders through the CRUD API, so the
 * same tests exercise HPOS and posts storage (PIXALINK_LOYALTY_HPOS=1|0).
 */
trait OrderFixtures {

	/**
	 * Connect to the fake server and start from an empty outbox.
	 *
	 * @return void
	 */
	protected function connect_store(): void {
		global $wpdb;

		Server::reset();
		delete_option( Options::DELIVERY_PAUSED );
		delete_transient( 'pixalink_loyalty_outlet_id' );
		Plugin::instance()->credentials()->store( Signature::FAKE_API_KEY );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', ( new EventRepository() )->table() ) );
	}

	/**
	 * Forget the credentials.
	 *
	 * @return void
	 */
	protected function disconnect_store(): void {
		Plugin::instance()->credentials()->clear();
	}

	/**
	 * A saved, unpaid order for two lattes (RM 24.00) in the "Coffee" category.
	 *
	 * @param int $customer_id Customer user id (0 = guest).
	 * @return \WC_Order
	 */
	protected function create_order( int $customer_id = 0 ): \WC_Order {
		$category = wp_insert_term( 'Coffee ' . wp_generate_password( 6, false ), 'product_cat' );
		$this->assertIsArray( $category );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Latte 12oz' );
		$product->set_regular_price( '12.00' );
		$product->set_sku( 'LATTE-' . wp_generate_password( 8, false ) );
		$product->set_category_ids( array( (int) $category['term_id'] ) );
		$product->save();

		$order = wc_create_order( array( 'customer_id' => $customer_id ) );
		$this->assertInstanceOf( \WC_Order::class, $order );

		$order->add_product( $product, 2 );
		$order->set_billing_first_name( 'Aisyah' );
		$order->set_billing_last_name( 'Rahman' );
		$order->set_billing_email( 'member@example.com' );
		$order->set_billing_phone( '+60123456789' );
		$order->set_payment_method( 'bacs' );
		$order->set_currency( 'MYR' );
		$order->calculate_totals();
		$order->save();

		return $order;
	}

	/**
	 * Outbox rows of an order, oldest first.
	 *
	 * @param \WC_Order $order Order.
	 * @return list<Event>
	 */
	protected function events_of( \WC_Order $order ): array {
		return array_reverse( ( new EventRepository() )->for_object( Event::OBJECT_ORDER, (int) $order->get_id() ) );
	}

	/**
	 * Event types of an order, oldest first.
	 *
	 * @param \WC_Order $order Order.
	 * @return list<string>
	 */
	protected function event_types_of( \WC_Order $order ): array {
		return array_map( static fn ( Event $event ): string => $event->type->value, $this->events_of( $order ) );
	}

	/**
	 * Deliver every open event of an order through the real client and the fake server.
	 *
	 * @param \WC_Order $order Order.
	 * @return list<string|null> Outcomes.
	 */
	protected function deliver_all( \WC_Order $order ): array {
		$outcomes = array();
		foreach ( $this->events_of( $order ) as $event ) {
			$outcomes[] = Plugin::instance()->events()->deliverer()->deliver( $event->id );
		}

		return $outcomes;
	}

	/**
	 * Order note contents, newest first.
	 *
	 * @param \WC_Order $order Order.
	 * @return list<string>
	 */
	protected function notes_of( \WC_Order $order ): array {
		return array_values( array_map( static fn ( $note ): string => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ) );
	}
}
