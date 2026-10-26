<?php
/**
 * Integration tests for ImmediateDelivery.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Events;

use Pixalink\Loyalty\Events\EventDeliverer;
use Pixalink\Loyalty\Events\ImmediateDelivery;
use Pixalink\Loyalty\Events\Scheduler;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\FakeServer\Server;
use Pixalink\Loyalty\Tests\Integration\Support\OrderFixtures;

/**
 * Order events recorded in a request reach the fake Pixalink server at its end, through the real outbox and client.
 *
 * @covers \Pixalink\Loyalty\Events\ImmediateDelivery
 * @covers \Pixalink\Loyalty\Events\Module
 */
final class ImmediateDeliveryTest extends \WP_UnitTestCase {

	use OrderFixtures;

	public function set_up(): void {
		parent::set_up();

		$this->connect_store();
		// PHPUnit runs on the CLI SAPI, where the default is off (no fastcgi_finish_request()).
		add_filter( 'pixalink_loyalty_send_events_immediately', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'pixalink_loyalty_send_events_immediately', '__return_true' );
		$this->disconnect_store();

		parent::tear_down();
	}

	private function immediate(): ImmediateDelivery {
		return Plugin::instance()->events()->immediate_delivery();
	}

	/**
	 * Requests the plugin made to POST /events.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function event_requests(): array {
		return array_values( array_filter( Server::recorded(), static fn ( array $request ): bool => 'events' === ( $request['path'] ?? null ) ) );
	}

	public function test_a_paid_order_is_delivered_at_the_end_of_the_request(): void {
		$order = $this->create_order();
		$order->payment_complete();
		$event = $this->events_of( $order )[0];

		$this->assertSame( ImmediateDelivery::SHUTDOWN_PRIORITY, has_action( 'shutdown', array( $this->immediate(), 'flush' ) ) );
		$this->assertSame( array( $event->id ), $this->immediate()->pending() );
		$this->assertSame( 'pending', $event->status->value, 'Nothing is sent before the response is out.' );
		$this->assertTrue( as_has_scheduled_action( Scheduler::DELIVER_HOOK, array( 'event_id' => $event->id ), 'pixalink-loyalty' ), 'The queue action is the fallback.' );

		$this->assertSame( 1, $this->immediate()->send() );

		$this->assertSame( 'delivered', Plugin::instance()->events()->repository()->find( $event->id )?->status->value );
		$this->assertCount( 1, $this->event_requests() );
		$this->assertNull( Plugin::instance()->events()->deliverer()->deliver( $event->id ), 'The queue action finds it already sent.' );
		$this->assertCount( 1, $this->event_requests() );
	}

	public function test_a_failed_attempt_leaves_the_event_to_the_queue(): void {
		$request = new \WP_REST_Request( 'POST', '/' . Server::CONTROL_NAMESPACE . '/script' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'method' => 'POST',
					'path'   => 'events',
					'status' => 503,
					'body'   => array(
						'message' => 'Down for maintenance.',
						'code'    => 'service_unavailable',
					),
				)
			)
		);
		Server::add_script( $request );

		$order = $this->create_order();
		$order->payment_complete();
		$event = $this->events_of( $order )[0];

		$this->assertSame( 0, $this->immediate()->send() );

		$row = Plugin::instance()->events()->repository()->find( $event->id );
		$this->assertSame( 'retry', $row?->status->value );
		$this->assertSame( 1, $row?->attempts );
		$this->assertTrue( as_has_scheduled_action( Scheduler::DELIVER_HOOK, array( 'event_id' => $event->id ), 'pixalink-loyalty' ) );
	}

	public function test_a_paused_store_is_not_attempted(): void {
		update_option( Options::DELIVERY_PAUSED, EventDeliverer::PAUSE_COMMERCE_PAUSED );
		$order = $this->create_order();
		$order->payment_complete();
		$event = $this->events_of( $order )[0];

		$this->assertSame( 0, $this->immediate()->send() );

		$this->assertSame( array(), $this->event_requests() );
		$this->assertSame( 'pending', Plugin::instance()->events()->repository()->find( $event->id )?->status->value );
	}

	public function test_nothing_is_collected_when_switched_off(): void {
		remove_filter( 'pixalink_loyalty_send_events_immediately', '__return_true' );
		$order = $this->create_order();
		$order->payment_complete();

		$this->assertSame( array(), $this->immediate()->pending() );
	}
}
