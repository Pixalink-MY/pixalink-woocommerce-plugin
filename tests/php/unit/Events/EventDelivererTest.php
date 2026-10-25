<?php
/**
 * Tests for EventDeliverer.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Events;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\ApiResponse;
use Pixalink\Loyalty\Api\Client;
use Pixalink\Loyalty\Events\Event;
use Pixalink\Loyalty\Events\EventDeliverer;
use Pixalink\Loyalty\Events\EventType;
use Pixalink\Loyalty\Events\RetryPolicy;
use Pixalink\Loyalty\Events\Scheduler;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Support\Uuid;
use Pixalink\Loyalty\Tests\Unit\Doubles\InMemoryEventStore;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * Classification matrix, backoff, pause, ordering guard and lock handling, against a fake sender.
 *
 * @covers \Pixalink\Loyalty\Events\EventDeliverer
 * @covers \Pixalink\Loyalty\Events\Event
 */
final class EventDelivererTest extends TestCase {

	private const NOW = 1761300000;

	private const API_BASE = 'https://api.example.test/commerce/v1';

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

	protected function set_up(): void {
		parent::set_up();

		$this->store     = new InMemoryEventStore();
		$this->sent      = array();
		$this->script    = array();
		$this->enqueued  = array();
		$this->scheduled = array();

		Functions\when( 'as_enqueue_async_action' )->alias(
			function ( ...$args ): int {
				$this->enqueued[] = $args;
				return 1;
			}
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( ...$args ): int {
				$this->scheduled[] = $args;
				return 1;
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
				$this->sent[] = $envelope;
				$this->assertNotEmpty( $this->script, 'Unexpected delivery attempt.' );
				$next = array_shift( $this->script );
				if ( $next instanceof \Throwable ) {
					throw $next;
				}

				return $next;
			},
			static fn (): string => '101',
			static fn (): int => self::NOW,
			static fn (): string => self::API_BASE
		);
	}

	/**
	 * Store an event.
	 *
	 * @param EventType $type     Type.
	 * @param int       $order_id Order id (0 for none).
	 * @param string    $name     Unique name.
	 * @return int Row id.
	 */
	private function event( EventType $type = EventType::ORDER_PAID, int $order_id = 1001, string $name = 'paid' ): int {
		return $this->store->insert(
			Uuid::event_id( 'https://shop.example.test|' . $name . '|' . $order_id ),
			$type,
			$order_id > 0 ? Event::OBJECT_ORDER : Event::OBJECT_NONE,
			$order_id,
			$order_id > 0 ? array( 'order' => array( 'id' => $order_id ) ) : array(),
			'2025-10-24 09:59:00'
		)['id'];
	}

	private function processed(): ApiResponse {
		return new ApiResponse(
			200,
			array(
				'status' => 'processed',
				'result' => array( 'points_earned' => 145 ),
			)
		);
	}

	/**
	 * Error response mapped exactly like the client does.
	 *
	 * @param int                  $status  HTTP status.
	 * @param string               $code    Error code.
	 * @param array<string,string> $headers Lower-case headers.
	 * @return ApiException
	 */
	private static function error( int $status, string $code, array $headers = array() ): ApiException {
		return Client::exception_for(
			$status,
			array(
				'message' => 'Fake ' . $code,
				'code'    => $code,
			),
			$headers
		);
	}

	public function test_success_marks_delivered_and_fires_the_delivered_action(): void {
		$id             = $this->event();
		$this->script[] = $this->processed();

		Actions\expectDone( 'pixalink_loyalty_event_delivered' )->once()->with(
			\Mockery::on( static fn ( $event ): bool => $event instanceof Event && 'delivered' === $event->status->value && 1 === $event->attempts ),
			array(
				'status' => 'processed',
				'result' => array( 'points_earned' => 145 ),
			)
		);

		$this->assertSame( EventDeliverer::OUTCOME_DELIVERED, $this->deliverer()->deliver( $id ) );

		$row = $this->store->rows[ $id ];
		$this->assertSame( 'delivered', $row['status'] );
		$this->assertSame( 200, $row['last_http_status'] );
		$this->assertSame( '2025-10-24 10:00:00', $row['delivered_at'] );
		$this->assertNull( $row['locked_at'] );

		$this->assertCount( 1, $this->sent );
		$envelope = $this->sent[0];
		$this->assertSame( $row['uuid'], $envelope['id'] );
		$this->assertSame( 'order.paid', $envelope['type'] );
		$this->assertSame( 1, $envelope['version'] );
		$this->assertSame( '2025-10-24T09:59:00Z', $envelope['occurred_at'] );
		$this->assertSame( 1, $envelope['sequence'] );
		$this->assertSame( 'https://shop.example.test', $envelope['site_url'] );
		$this->assertSame( '101', $envelope['outlet_id'] );
		$this->assertSame( array( 'order' => array( 'id' => 1001 ) ), $envelope['data'] );
	}

	/**
	 * Connections other than the current one (outlet 101 at API_BASE).
	 *
	 * @return array<string,array{0:array<string,string>}>
	 */
	public static function other_connections(): array {
		return array(
			'another outlet'   => array(
				array(
					'outlet_id' => '202',
					'api_base'  => self::API_BASE,
				),
			),
			'another API base' => array(
				array(
					'outlet_id' => '101',
					'api_base'  => 'https://uat.example.test/commerce/v1',
				),
			),
		);
	}

	/**
	 * @dataProvider other_connections
	 *
	 * @param array<string,string> $recorded_for Connection the event was recorded for.
	 */
	public function test_an_event_recorded_for_another_connection_is_marked_dead_and_never_sent( array $recorded_for ): void {
		$id = $this->event();
		$this->store->patch( $id, $recorded_for );

		$this->assertSame( EventDeliverer::OUTCOME_DEAD, $this->deliverer()->deliver( $id ) );

		$this->assertSame( array(), $this->sent );
		$row = $this->store->rows[ $id ];
		$this->assertSame( 'dead', $row['status'] );
		$this->assertSame( 0, $row['attempts'], 'Nothing was attempted.' );
		$this->assertStringStartsWith( EventDeliverer::CONNECTION_CHANGED, (string) $row['last_error'] );
	}

	public function test_an_event_recorded_for_this_connection_or_before_connections_were_recorded_is_sent(): void {
		$bound = $this->event( EventType::ORDER_PAID, 1001, 'bound' );
		$this->store->patch(
			$bound,
			array(
				'outlet_id' => '101',
				'api_base'  => self::API_BASE . '/',
			)
		);
		$legacy         = $this->event( EventType::ORDER_PAID, 1002, 'legacy' );
		$this->script[] = $this->processed();
		$this->script[] = $this->processed();

		$this->assertSame( EventDeliverer::OUTCOME_DELIVERED, $this->deliverer()->deliver( $bound ) );
		$this->assertSame( EventDeliverer::OUTCOME_DELIVERED, $this->deliverer()->deliver( $legacy ) );
		$this->assertCount( 2, $this->sent );
	}

	public function test_empty_data_is_sent_as_a_json_object(): void {
		$id             = $this->event( EventType::PING, 0, 'ping' );
		$this->script[] = $this->processed();

		$this->deliverer()->deliver( $id );

		$this->assertSame( '{}', json_encode( $this->sent[0]['data'] ) );
		$this->assertSame( 0, $this->sent[0]['sequence'] );
	}

	/**
	 * Failure → [outcome, row status, attempts counted].
	 *
	 * @return array<string,array{0:\Closure():ApiException,1:string,2:string,3:int}>
	 */
	public static function failures(): array {
		return array(
			'409 duplicate'            => array( static fn () => self::error( 409, 'duplicate' ), EventDeliverer::OUTCOME_DELIVERED, 'delivered', 1 ),
			'409 order_not_settled'    => array( static fn () => self::error( 409, 'order_not_settled' ), EventDeliverer::OUTCOME_RETRY, 'retry', 1 ),
			'409 event_in_progress'    => array( static fn () => self::error( 409, 'event_in_progress' ), EventDeliverer::OUTCOME_RETRY, 'retry', 1 ),
			'425 dependency_pending'   => array( static fn () => self::error( 425, 'dependency_pending' ), EventDeliverer::OUTCOME_RETRY, 'retry', 1 ),
			'429 throttled'            => array( static fn () => self::error( 429, 'too_many_requests' ), EventDeliverer::OUTCOME_RETRY, 'retry', 1 ),
			'500 server error'         => array( static fn () => self::error( 500, 'server_error' ), EventDeliverer::OUTCOME_RETRY, 'retry', 1 ),
			'503 unavailable'          => array( static fn () => self::error( 503, 'http_503' ), EventDeliverer::OUTCOME_RETRY, 'retry', 1 ),
			'network error'            => array( static fn () => ApiException::network( 'http_request_failed', 'cURL error 28: timed out' ), EventDeliverer::OUTCOME_RETRY, 'retry', 1 ),
			'2xx without JSON'         => array( static fn () => ApiException::invalid_response( 200 ), EventDeliverer::OUTCOME_RETRY, 'retry', 1 ),
			'401 repeated clock skew'  => array( static fn () => self::error( 401, 'timestamp_skew' ), EventDeliverer::OUTCOME_RETRY, 'retry', 1 ),
			'401 unauthenticated'      => array( static fn () => self::error( 401, 'unauthenticated' ), EventDeliverer::OUTCOME_PAUSE, 'pending', 0 ),
			'403 outlet key required'  => array( static fn () => self::error( 403, 'outlet_key_required' ), EventDeliverer::OUTCOME_PAUSE, 'pending', 0 ),
			'not connected'            => array( static fn () => ApiException::not_connected(), EventDeliverer::OUTCOME_PAUSE, 'pending', 0 ),
			'400 bad request'          => array( static fn () => self::error( 400, 'invalid_request' ), EventDeliverer::OUTCOME_DEAD, 'dead', 1 ),
			'404 order not found'      => array( static fn () => self::error( 404, 'not_found' ), EventDeliverer::OUTCOME_DEAD, 'dead', 1 ),
			'410 gone'                 => array( static fn () => self::error( 410, 'gone' ), EventDeliverer::OUTCOME_DEAD, 'dead', 1 ),
			'422 business rejection'   => array( static fn () => self::error( 422, 'validation_failed' ), EventDeliverer::OUTCOME_DEAD, 'dead', 1 ),
		);
	}

	/**
	 * @dataProvider failures
	 *
	 * @param \Closure():ApiException $failure  Failure factory.
	 * @param string                  $outcome  Expected outcome.
	 * @param string                  $status   Expected row status.
	 * @param int                     $attempts Expected attempts.
	 */
	public function test_classification_matrix( \Closure $failure, string $outcome, string $status, int $attempts ): void {
		$id             = $this->event();
		$exception      = $failure();
		$this->script[] = $exception;

		$this->assertSame( $outcome, EventDeliverer::classify( $exception ) );
		$this->assertSame( $outcome, $this->deliverer()->deliver( $id ) );
		$this->assertSame( $status, $this->store->rows[ $id ]['status'] );
		$this->assertSame( $attempts, $this->store->rows[ $id ]['attempts'] );
		$this->assertNull( $this->store->rows[ $id ]['locked_at'] );
	}

	public function test_retry_schedules_the_next_attempt_with_backoff(): void {
		$id             = $this->event();
		$this->script[] = self::error( 503, 'http_503' );

		$this->deliverer()->deliver( $id );

		$row = $this->store->rows[ $id ];
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW + 60 ), $row['next_attempt_at'] );
		$this->assertSame( 503, $row['last_http_status'] );
		$this->assertStringStartsWith( 'http_503 (HTTP 503)', (string) $row['last_error'] );
		$this->assertSame(
			array( array( self::NOW + 60, Scheduler::DELIVER_HOOK, array( 'event_id' => $id ), 'pixalink-loyalty' ) ),
			$this->scheduled
		);
	}

	public function test_retry_after_is_honoured(): void {
		$id             = $this->event();
		$this->script[] = self::error( 429, 'too_many_requests', array( 'retry-after' => '600' ) );

		$this->deliverer()->deliver( $id );

		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW + 600 ), $this->store->rows[ $id ]['next_attempt_at'] );
		$this->assertSame( self::NOW + 600, $this->scheduled[0][0] );
	}

	public function test_the_twelfth_failed_attempt_marks_the_event_dead(): void {
		$id = $this->event();
		$this->store->patch(
			$id,
			array(
				'status'   => 'retry',
				'attempts' => RetryPolicy::MAX_ATTEMPTS - 1,
			)
		);
		$this->script[] = self::error( 503, 'http_503' );

		Actions\expectDone( 'pixalink_loyalty_event_dead' )->once()->with(
			\Mockery::type( Event::class ),
			\Mockery::on( static fn ( array $error ): bool => 'http_503' === $error['code'] && 12 === $error['attempts'] && 503 === $error['http_status'] )
		);

		$this->assertSame( EventDeliverer::OUTCOME_DEAD, $this->deliverer()->deliver( $id ) );
		$this->assertSame( 'dead', $this->store->rows[ $id ]['status'] );
		$this->assertSame( 12, $this->store->rows[ $id ]['attempts'] );
		$this->assertStringContainsString( 'gave up after 12 attempts', (string) $this->store->rows[ $id ]['last_error'] );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_unexpected_errors_mark_the_event_dead(): void {
		$id             = $this->event();
		$this->script[] = new \InvalidArgumentException( 'Request body cannot be encoded as JSON.' );

		$this->assertSame( EventDeliverer::OUTCOME_DEAD, $this->deliverer()->deliver( $id ) );
		$this->assertStringContainsString( 'cannot be encoded', (string) $this->store->rows[ $id ]['last_error'] );
	}

	public function test_auth_failure_pauses_all_delivery_until_a_manual_ping_succeeds(): void {
		$paid           = $this->event();
		$other          = $this->event( EventType::ORDER_PAID, 1002, 'paid-2' );
		$this->script[] = self::error( 401, 'unauthenticated' );

		Actions\expectDone( 'pixalink_loyalty_delivery_paused' )->once()->with( EventDeliverer::PAUSE_AUTH_FAILED );

		$deliverer = $this->deliverer();
		$this->assertSame( EventDeliverer::OUTCOME_PAUSE, $deliverer->deliver( $paid ) );
		$this->assertTrue( $deliverer->is_paused() );
		$this->assertSame( EventDeliverer::PAUSE_AUTH_FAILED, $this->options[ Options::DELIVERY_PAUSED ][0] );
		$this->assertStringStartsWith( 'auth_failed: unauthenticated (HTTP 401)', (string) $this->store->rows[ $paid ]['last_error'] );

		// Paused: nothing is attempted and the sweep enqueues nothing.
		$this->assertNull( $deliverer->deliver( $other ) );
		$this->assertSame( 0, $deliverer->sweep() );
		$this->assertCount( 1, $this->sent );

		// A manual ping bypasses the pause; its success resumes delivery and kicks the sweep.
		$ping           = $this->event( EventType::PING, 0, 'ping' );
		$this->script[] = $this->processed();
		Actions\expectDone( 'pixalink_loyalty_delivery_resumed' )->once();

		$this->assertSame( EventDeliverer::OUTCOME_DELIVERED, $deliverer->deliver( $ping, true ) );
		$this->assertFalse( $deliverer->is_paused() );
		$this->assertContains( array( Scheduler::SWEEP_HOOK, array(), 'pixalink-loyalty', true ), $this->enqueued );
	}

	public function test_a_later_event_waits_for_an_earlier_open_event_of_the_same_order(): void {
		$paid   = $this->event( EventType::ORDER_PAID, 1001, 'paid' );
		$refund = $this->event( EventType::ORDER_REFUNDED, 1001, 'refund' );
		$this->assertSame( 2, $this->store->rows[ $refund ]['sequence'] );

		$deliverer = $this->deliverer();
		$this->assertSame( EventDeliverer::OUTCOME_DEFERRED, $deliverer->deliver( $refund ) );
		$this->assertSame( array(), $this->sent, 'Nothing is sent while order.paid is open.' );
		$this->assertSame( 0, $this->store->rows[ $refund ]['attempts'], 'Waiting does not count as an attempt.' );
		$this->assertSame( 'pending', $this->store->rows[ $refund ]['status'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW + 60 ), $this->store->rows[ $refund ]['next_attempt_at'] );
		$this->assertSame( self::NOW + 60, $this->scheduled[0][0] );

		// Delivering order.paid wakes the refund immediately.
		$this->script[] = $this->processed();
		$deliverer->deliver( $paid );
		$this->assertContains( array( Scheduler::DELIVER_HOOK, array( 'event_id' => $refund ), 'pixalink-loyalty', true ), $this->enqueued );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::NOW ), $this->store->rows[ $refund ]['next_attempt_at'] );

		$this->script[] = $this->processed();
		$this->assertSame( EventDeliverer::OUTCOME_DELIVERED, $deliverer->deliver( $refund ), 'The woken refund is due right away.' );
	}

	public function test_a_later_event_in_its_own_backoff_is_not_woken(): void {
		$paid   = $this->event( EventType::ORDER_PAID, 1001, 'paid' );
		$refund = $this->event( EventType::ORDER_REFUNDED, 1001, 'refund' );
		$later  = gmdate( 'Y-m-d H:i:s', self::NOW + 900 );
		$this->store->patch(
			$refund,
			array(
				'status'          => 'retry',
				'attempts'        => 3,
				'next_attempt_at' => $later,
				'last_error'      => 'http_503 (HTTP 503): down',
			)
		);
		$this->script[] = $this->processed();

		$this->deliverer()->deliver( $paid );

		$this->assertSame( $later, $this->store->rows[ $refund ]['next_attempt_at'] );
		$this->assertSame( array(), $this->enqueued );
	}

	public function test_waiting_lasts_until_the_blocking_events_own_retry(): void {
		$paid   = $this->event( EventType::ORDER_PAID, 1001, 'paid' );
		$refund = $this->event( EventType::ORDER_REFUNDED, 1001, 'refund' );
		$this->store->patch(
			$paid,
			array(
				'status'          => 'retry',
				'attempts'        => 5,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', self::NOW + 3 * 3600 ),
			)
		);

		$this->deliverer()->deliver( $refund );

		$this->assertSame( self::NOW + 3 * 3600 + 60, $this->scheduled[0][0], 'No minute-by-minute polling behind a long backoff.' );
	}

	public function test_dead_earlier_events_do_not_block_later_ones(): void {
		$paid   = $this->event( EventType::ORDER_PAID, 1001, 'paid' );
		$refund = $this->event( EventType::ORDER_REFUNDED, 1001, 'refund' );
		$this->store->patch( $paid, array( 'status' => 'dead' ) );
		$this->script[] = $this->processed();

		$this->assertSame( EventDeliverer::OUTCOME_DELIVERED, $this->deliverer()->deliver( $refund ) );
	}

	public function test_events_of_other_orders_do_not_block(): void {
		$this->event( EventType::ORDER_PAID, 1001, 'paid' );
		$other          = $this->event( EventType::ORDER_REFUNDED, 1002, 'refund' );
		$this->script[] = $this->processed();

		$this->assertSame( EventDeliverer::OUTCOME_DELIVERED, $this->deliverer()->deliver( $other ) );
	}

	public function test_a_stale_sending_lock_is_reclaimed_but_a_fresh_one_is_respected(): void {
		$stale = $this->event( EventType::ORDER_PAID, 1001, 'stale' );
		$fresh = $this->event( EventType::ORDER_PAID, 1002, 'fresh' );
		$this->store->patch(
			$stale,
			array(
				'status'    => 'sending',
				'locked_at' => gmdate( 'Y-m-d H:i:s', self::NOW - EventDeliverer::LOCK_TIMEOUT - 1 ),
			)
		);
		$this->store->patch(
			$fresh,
			array(
				'status'    => 'sending',
				'locked_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 60 ),
			)
		);
		$this->script[] = $this->processed();

		$deliverer = $this->deliverer();
		$this->assertSame( EventDeliverer::OUTCOME_DELIVERED, $deliverer->deliver( $stale ) );
		$this->assertNull( $deliverer->deliver( $fresh ) );
		$this->assertSame( 'sending', $this->store->rows[ $fresh ]['status'] );
	}

	public function test_rows_not_yet_due_or_closed_are_skipped(): void {
		$future = $this->event( EventType::ORDER_PAID, 1001, 'future' );
		$done   = $this->event( EventType::ORDER_PAID, 1002, 'done' );
		$this->store->patch(
			$future,
			array(
				'status'          => 'retry',
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', self::NOW + 3600 ),
			)
		);
		$this->store->patch( $done, array( 'status' => 'delivered' ) );

		$deliverer = $this->deliverer();
		$this->assertNull( $deliverer->deliver( $future ) );
		$this->assertNull( $deliverer->deliver( $done ) );
		$this->assertNull( $deliverer->deliver( 999 ) );
		$this->assertSame( array(), $this->sent );
	}

	public function test_sweep_enqueues_due_rows_and_stale_locks(): void {
		$due    = $this->event( EventType::ORDER_PAID, 1001, 'due' );
		$later  = $this->event( EventType::ORDER_PAID, 1002, 'later' );
		$stale  = $this->event( EventType::ORDER_PAID, 1003, 'stale' );
		$closed = $this->event( EventType::ORDER_PAID, 1004, 'closed' );
		$this->store->patch( $later, array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', self::NOW + 3600 ) ) );
		$this->store->patch(
			$stale,
			array(
				'status'    => 'sending',
				'locked_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 3600 ),
			)
		);
		$this->store->patch( $closed, array( 'status' => 'delivered' ) );

		$this->assertSame( 2, $this->deliverer()->sweep() );
		$this->assertSame(
			array( $due, $stale ),
			array_map( static fn ( array $call ): int => $call[1]['event_id'], $this->enqueued )
		);
	}

	public function test_handle_accepts_the_positional_action_scheduler_argument(): void {
		$id             = $this->event();
		$this->script[] = $this->processed();

		$this->deliverer()->handle( (string) $id );

		$this->assertSame( 'delivered', $this->store->rows[ $id ]['status'] );
	}
}
