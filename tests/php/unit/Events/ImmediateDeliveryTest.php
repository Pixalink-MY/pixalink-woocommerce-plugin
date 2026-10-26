<?php
/**
 * Tests for ImmediateDelivery.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Events;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\ApiResponse;
use Pixalink\Loyalty\Api\Client;
use Pixalink\Loyalty\Events\Event;
use Pixalink\Loyalty\Events\EventDeliverer;
use Pixalink\Loyalty\Events\EventQueue;
use Pixalink\Loyalty\Events\EventType;
use Pixalink\Loyalty\Events\ImmediateDelivery;
use Pixalink\Loyalty\Events\RetryPolicy;
use Pixalink\Loyalty\Events\Scheduler;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Support\Uuid;
use Pixalink\Loyalty\Tests\Unit\Doubles\InMemoryEventStore;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * End-of-request delivery: sent after the response, in order, claimed like a queue attempt, and left to the queue
 * whenever it cannot go through.
 *
 * @covers \Pixalink\Loyalty\Events\ImmediateDelivery
 * @covers \Pixalink\Loyalty\Events\EventQueue
 */
final class ImmediateDeliveryTest extends TestCase {

	private const NOW = 1761300000;

	private InMemoryEventStore $store;

	/**
	 * Envelopes handed to the sender.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $sent = array();

	/**
	 * Scripted sender results: ApiResponse to return or Throwable to throw.
	 *
	 * @var list<ApiResponse|\Throwable>
	 */
	private array $script = array();

	/**
	 * Captured as_enqueue_async_action() calls.
	 *
	 * @var list<array<int,mixed>>
	 */
	private array $enqueued = array();

	/**
	 * Captured as_schedule_single_action() calls.
	 *
	 * @var list<array<int,mixed>>
	 */
	private array $scheduled = array();

	/**
	 * Times the response was finished.
	 *
	 * @var int
	 */
	private int $finished = 0;

	private ImmediateDelivery $immediate;

	/**
	 * What the `pixalink_loyalty_send_events_immediately` filter answers (null: the default).
	 *
	 * @var bool|null
	 */
	private ?bool $switched_on = true;

	protected function set_up(): void {
		parent::set_up();

		$this->store     = new InMemoryEventStore();
		$this->sent      = array();
		$this->script    = array();
		$this->enqueued  = array();
		$this->scheduled = array();
		$this->finished  = 0;

		Functions\when( 'as_enqueue_async_action' )->alias(
			function ( ...$args ): int {
				$this->enqueued[] = $args;
				return count( $this->enqueued );
			}
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( ...$args ): int {
				$this->scheduled[] = $args;
				return count( $this->scheduled );
			}
		);
		$this->switched_on = true;
		Filters\expectApplied( 'pixalink_loyalty_send_events_immediately' )->zeroOrMoreTimes()->andReturnUsing(
			fn ( bool $default ): bool => $this->switched_on ?? $default
		);

		$this->immediate = new ImmediateDelivery(
			$this->store,
			$this->deliverer(),
			function (): void {
				++$this->finished;
			}
		);
	}

	private function deliverer(): EventDeliverer {
		return new EventDeliverer(
			$this->store,
			new Scheduler(),
			new RetryPolicy( static fn (): float => 0.0 ),
			new Options(),
			new Logger(),
			function ( array $envelope ): ApiResponse {
				$this->assertSame( 1, $this->finished, 'Sent only after the response was finished.' );
				$this->sent[] = $envelope;
				$this->assertNotEmpty( $this->script, 'Unexpected delivery attempt.' );
				$next = array_shift( $this->script );
				if ( $next instanceof \Throwable ) {
					throw $next;
				}

				return $next;
			},
			static fn (): string => '101',
			static fn (): int => self::NOW
		);
	}

	private function queue(): EventQueue {
		return new EventQueue( $this->store, new Scheduler(), static fn (): int => self::NOW, null, $this->immediate );
	}

	/**
	 * A WC_Order double.
	 *
	 * @param int $id Order id.
	 * @return \WC_Order
	 */
	private function order( int $id = 1001 ): \WC_Order {
		$order = \Mockery::mock( \WC_Order::class );
		$order->allows( 'get_id' )->andReturn( $id );
		$order->allows( 'get_order_key' )->andReturn( 'wc_order_' . $id );

		return $order;
	}

	/**
	 * Record an order event through the queue, as OrderLifecycle does.
	 *
	 * @param EventType $type     Type.
	 * @param int       $order_id Order id.
	 * @return int Row id.
	 */
	private function record( EventType $type = EventType::ORDER_PAID, int $order_id = 1001 ): int {
		$stored = $this->queue()->enqueue( $type, $this->order( $order_id ), array( 'order' => array( 'id' => $order_id ) ) );
		$this->assertNotNull( $stored );

		return $stored['id'];
	}

	private function processed(): ApiResponse {
		return new ApiResponse(
			200,
			array(
				'status' => 'processed',
				'result' => null,
			)
		);
	}

	public function test_an_event_recorded_in_a_request_is_delivered_at_its_shutdown(): void {
		$id             = $this->record();
		$this->script[] = $this->processed();

		$this->assertSame( ImmediateDelivery::SHUTDOWN_PRIORITY, has_action( 'shutdown', array( $this->immediate, 'flush' ) ) );
		$this->assertSame( array( $id ), $this->immediate->pending() );
		$this->assertCount( 1, $this->enqueued, 'The queue action is still enqueued as the fallback.' );

		$this->assertSame( 1, $this->immediate->send() );

		$this->assertSame( 1, $this->finished );
		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'order.paid', $this->sent[0]['type'] );
		$this->assertSame( 'delivered', $this->store->rows[ $id ]['status'] );
		$this->assertSame( array(), $this->immediate->pending() );
		$this->assertSame( 0, $this->immediate->send(), 'A second shutdown has nothing left.' );
	}

	public function test_the_queue_action_finds_nothing_left_to_send_afterwards(): void {
		$id             = $this->record();
		$this->script[] = $this->processed();
		$this->immediate->send();

		$this->assertNull( $this->deliverer()->deliver( $id ) );
		$this->assertCount( 1, $this->sent );
	}

	public function test_events_of_one_request_are_sent_in_the_order_they_were_recorded(): void {
		$paid           = $this->record( EventType::ORDER_PAID );
		$completed      = $this->record( EventType::ORDER_COMPLETED );
		$this->script[] = $this->processed();
		$this->script[] = $this->processed();

		$this->assertSame( 2, $this->immediate->send() );

		$this->assertSame( array( 'order.paid', 'order.completed' ), array_column( $this->sent, 'type' ) );
		$this->assertSame( array( 1, 2 ), array_column( $this->sent, 'sequence' ) );
		$this->assertSame( 'delivered', $this->store->rows[ $paid ]['status'] );
		$this->assertSame( 'delivered', $this->store->rows[ $completed ]['status'] );
	}

	public function test_a_failed_attempt_leaves_the_event_pending_for_the_queue_and_stops_the_rest(): void {
		$id             = $this->record( EventType::ORDER_PAID, 1001 );
		$other          = $this->record( EventType::ORDER_PAID, 1002 );
		$this->script[] = Client::exception_for(
			503,
			array(
				'message' => 'Down for maintenance',
				'code'    => 'service_unavailable',
			),
			array()
		);

		$this->assertSame( 0, $this->immediate->send() );

		$this->assertCount( 1, $this->sent, 'Pixalink is down: the next event is not tried as well.' );
		$row = $this->store->rows[ $id ];
		$this->assertSame( 'retry', $row['status'] );
		$this->assertSame( 1, $row['attempts'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW + 60 ), $row['next_attempt_at'], 'The normal backoff.' );
		$this->assertSame( array( Scheduler::DELIVER_HOOK, array( 'event_id' => $id ) ), array_slice( $this->scheduled[0], 1, 2 ) );
		$this->assertSame( 'pending', $this->store->rows[ $other ]['status'] );
		$this->assertSame( 0, $this->store->rows[ $other ]['attempts'] );
	}

	public function test_a_transport_error_is_retried_by_the_queue(): void {
		$id             = $this->record();
		$this->script[] = ApiException::network( 'http_request_failed', 'cURL error 28: Operation timed out after 5000 milliseconds' );

		$this->immediate->send();

		$this->assertSame( 'retry', $this->store->rows[ $id ]['status'] );
	}

	public function test_an_earlier_open_event_of_the_same_order_blocks_the_immediate_send(): void {
		$earlier = $this->store->insert(
			Uuid::event_id( 'https://shop.example.test|order.paid|wc_order_1001|' ),
			EventType::ORDER_PAID,
			Event::OBJECT_ORDER,
			1001,
			array( 'order' => array( 'id' => 1001 ) ),
			'2025-10-24 09:00:00'
		)['id'];
		$this->store->mark_retry( $earlier, 1, '2025-10-24 10:05:00', 503, 'http_503', array() );

		$id = $this->record( EventType::ORDER_COMPLETED );

		$this->assertSame( 0, $this->immediate->send() );

		$this->assertSame( array(), $this->sent );
		$row = $this->store->rows[ $id ];
		$this->assertSame( 'pending', $row['status'], 'Left untouched for the queue, which sends it after the earlier one.' );
		$this->assertNull( $row['next_attempt_at'] );
		$this->assertNull( $row['last_error'] );
	}

	public function test_no_double_delivery_when_the_queue_claimed_the_event_first(): void {
		$id = $this->record();
		$this->assertTrue( $this->store->claim( $id, gmdate( 'Y-m-d H:i:s', self::NOW ), gmdate( 'Y-m-d H:i:s', self::NOW - EventDeliverer::LOCK_TIMEOUT ) ) );

		$this->assertSame( 0, $this->immediate->send() );

		$this->assertSame( array(), $this->sent );
		$this->assertSame( 'sending', $this->store->rows[ $id ]['status'] );
	}

	public function test_no_double_delivery_when_the_queue_already_delivered_it(): void {
		$id = $this->record();
		$this->store->mark_delivered( $id, 1, 200, array( 'status' => 'processed' ), gmdate( 'Y-m-d H:i:s', self::NOW ) );

		$this->assertSame( 0, $this->immediate->send() );

		$this->assertSame( array(), $this->sent );
	}

	/**
	 * Pause reasons.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function pause_reasons(): array {
		return array(
			'store paused by Pixalink' => array( EventDeliverer::PAUSE_COMMERCE_PAUSED ),
			'key rejected'             => array( EventDeliverer::PAUSE_AUTH_FAILED ),
			'key missing'              => array( EventDeliverer::PAUSE_NOT_CONNECTED ),
		);
	}

	/**
	 * A paused connection skips the attempt.
	 *
	 * @dataProvider pause_reasons
	 *
	 * @param string $reason Pause reason.
	 */
	public function test_a_paused_connection_skips_the_immediate_attempt( string $reason ): void {
		update_option( Options::DELIVERY_PAUSED, $reason );
		$id = $this->record();

		$this->assertSame( 0, $this->immediate->send() );

		$this->assertSame( array(), $this->sent );
		$this->assertSame( 0, $this->finished, 'Nothing to send, so the response is left alone.' );
		$this->assertSame( 'pending', $this->store->rows[ $id ]['status'] );
		$this->assertSame( 0, $this->store->rows[ $id ]['attempts'] );
		$this->assertSame( array(), $this->immediate->pending() );
	}

	public function test_a_duplicate_insert_is_not_sent_again(): void {
		$this->record();
		$this->script[] = $this->processed();
		$this->immediate->send();

		$this->record();

		$this->assertSame( array(), $this->immediate->pending(), 'The INSERT was ignored, so there is nothing new to send.' );
	}

	public function test_pings_are_not_collected(): void {
		$this->queue()->enqueue( EventType::PING, null, array() );

		$this->assertSame( array(), $this->immediate->pending() );
	}

	public function test_nothing_is_collected_when_switched_off(): void {
		$this->switched_on = false;

		$this->record();

		$this->assertSame( array(), $this->immediate->pending() );
		$this->assertFalse( has_action( 'shutdown', array( $this->immediate, 'flush' ) ) );
	}

	public function test_off_by_default_where_the_response_cannot_be_finished_early(): void {
		$this->switched_on = null;

		$this->assertFalse( ImmediateDelivery::enabled(), 'PHPUnit runs on the CLI SAPI, without fastcgi_finish_request().' );
	}

	public function test_at_most_a_bounded_number_of_events_per_request(): void {
		$ids = array();
		for ( $order = 1; $order <= ImmediateDelivery::MAX_PER_REQUEST + 2; $order++ ) {
			$ids[]          = $this->record( EventType::ORDER_PAID, 2000 + $order );
			$this->script[] = $this->processed();
		}

		$this->assertSame( ImmediateDelivery::MAX_PER_REQUEST, $this->immediate->send() );

		$this->assertSame( 'pending', $this->store->rows[ end( $ids ) ]['status'], 'The rest is left to the queue.' );
	}
}
