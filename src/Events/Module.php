<?php
/**
 * Composition root of the event outbox, order events and their admin screens.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Events;

use Pixalink\Loyalty\Admin\EventsListTable;
use Pixalink\Loyalty\Admin\HealthPanel;
use Pixalink\Loyalty\Admin\SiteHealth;
use Pixalink\Loyalty\Admin\SystemStatus;
use Pixalink\Loyalty\Api\Client;
use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Connect\Connection;
use Pixalink\Loyalty\Orders\OrderAdmin;
use Pixalink\Loyalty\Orders\OrderLifecycle;
use Pixalink\Loyalty\Orders\OrderPayloadBuilder;
use Pixalink\Loyalty\Orders\RefundPayloadBuilder;
use Pixalink\Loyalty\Orders\ThankYou;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and registers the outbox services and exposes the operations the admin screens and other units use.
 *
 * Queue depth for `POST connection/heartbeat` is available as `apply_filters( 'pixalink_loyalty_queue_stats', … )`
 * or {@see Module::queue_stats()}, and fills the heartbeat's own `pixalink_loyalty_heartbeat_queue_stats`.
 */
final class Module {

	/**
	 * WooCommerce › Status tab of the events list.
	 */
	public const EVENTS_TAB = 'pixalink-events';

	/**
	 * Requeued dead rows per "Retry failed" click.
	 */
	private const RETRY_DEAD_LIMIT = 500;

	/**
	 * Outbox persistence.
	 *
	 * @var EventRepository
	 */
	private EventRepository $repository;

	/**
	 * Action Scheduler wiring.
	 *
	 * @var Scheduler
	 */
	private Scheduler $scheduler;

	/**
	 * Outbox writer.
	 *
	 * @var EventQueue
	 */
	private EventQueue $queue;

	/**
	 * Delivery worker.
	 *
	 * @var EventDeliverer
	 */
	private EventDeliverer $deliverer;

	/**
	 * End-of-request delivery of the events a request recorded.
	 *
	 * @var ImmediateDelivery
	 */
	private ImmediateDelivery $immediate;

	/**
	 * Outlet id lookup.
	 *
	 * @var OutletResolver
	 */
	private OutletResolver $outlet;

	/**
	 * Order hooks.
	 *
	 * @var OrderLifecycle
	 */
	private OrderLifecycle $lifecycle;

	/**
	 * Missed-event safety net and resends.
	 *
	 * @var Reconciler
	 */
	private Reconciler $reconciler;

	/**
	 * Retention.
	 *
	 * @var Pruner
	 */
	private Pruner $pruner;

	/**
	 * Outlet credentials.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Connection state (earn timing on the order-received page).
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * Whether register() already ran.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Constructor.
	 *
	 * @param Client      $client      API client.
	 * @param Credentials $credentials Outlet credentials.
	 * @param Options     $options     Option storage.
	 * @param Logger      $logger      Logger.
	 */
	public function __construct( Client $client, Credentials $credentials, Options $options, Logger $logger ) {
		$this->credentials = $credentials;
		$this->connection  = new Connection( $options );
		$this->repository  = new EventRepository();
		$this->scheduler   = new Scheduler();
		$this->outlet      = new OutletResolver( $client, static fn (): string => OutletResolver::fingerprint( $credentials->api_key(), $client->base_url() ) );
		$policy            = new RetryPolicy();
		$outlet_id         = fn (): string => $this->outlet->outlet_id();
		$api_base          = static fn (): string => $client->base_url();
		$this->deliverer   = new EventDeliverer(
			$this->repository,
			$this->scheduler,
			$policy,
			$options,
			$logger,
			static fn ( array $envelope ) => $client->send_event( $envelope ),
			$outlet_id,
			null,
			$api_base
		);
		$this->immediate   = new ImmediateDelivery(
			$this->repository,
			new EventDeliverer(
				$this->repository,
				$this->scheduler,
				$policy,
				$options,
				$logger,
				static fn ( array $envelope ) => $client->send_event( $envelope, ImmediateDelivery::TIMEOUT ),
				$outlet_id,
				null,
				$api_base
			)
		);
		$this->queue       = new EventQueue(
			$this->repository,
			$this->scheduler,
			null,
			fn (): array => array(
				// A cache made for other credentials means the connection record may predate the change too: leave the
				// outlet unknown rather than stamp the old one (the deliverer then sends to the outlet it resolves).
				'outlet_id' => $this->outlet->known_outlet_id() ?? ( $this->outlet->is_stale() ? null : $this->connected_outlet_id() ),
				'api_base'  => $client->base_url(),
			),
			$this->immediate
		);

		$orders           = new OrderPayloadBuilder();
		$this->lifecycle  = new OrderLifecycle( $this->queue, $this->repository, $this->scheduler, $orders, new RefundPayloadBuilder( $orders ), $credentials );
		$this->reconciler = new Reconciler( $this->lifecycle, $this->scheduler );
		$this->pruner     = new Pruner( $this->repository );
	}

	/**
	 * Register every hook of the unit (idempotent).
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		$this->scheduler->register();
		$this->deliverer->register();
		$this->lifecycle->register();
		$this->reconciler->register();
		$this->pruner->register();

		add_filter( 'pixalink_loyalty_queue_stats', array( $this, 'filter_queue_stats' ), 10, 1 );
		add_filter( 'pixalink_loyalty_heartbeat_queue_stats', array( $this, 'filter_queue_stats' ), 10, 1 );
		add_action( 'pixalink_loyalty_heartbeat_received', array( $this, 'on_heartbeat' ), 10, 1 );

		// New or removed credentials: forget the cached outlet and resume a paused queue.
		foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $prefix ) {
			add_action( $prefix . Options::CREDENTIALS, array( $this, 'on_credentials_changed' ), 10, 0 );
		}
		// A connection recorded (hosted sign-in, pasted key, or the wp-config.php key adopted): same, whichever key.
		add_action( Connection::CREDENTIALS_CHANGED_ACTION, array( $this, 'on_credentials_changed' ), 10, 0 );

		( new ThankYou( $this->credentials, $this->connection ) )->register();

		if ( is_admin() ) {
			( new OrderAdmin( $this->repository, $this->lifecycle ) )->register();
			( new HealthPanel( $this ) )->register();
			EventsListTable::register_hooks( $this );
		}

		( new SystemStatus( $this ) )->register();
		( new SiteHealth( $this ) )->register();
	}

	/**
	 * Queue depth for the heartbeat.
	 *
	 * @return array{pending:int,failed:int,oldest_pending_at:string|null}
	 */
	public function queue_stats(): array {
		return $this->repository->queue_stats();
	}

	/**
	 * `pixalink_loyalty_queue_stats` filter callback.
	 *
	 * @param mixed $stats Default stats.
	 * @return array{pending:int,failed:int,oldest_pending_at:string|null}
	 */
	public function filter_queue_stats( $stats ): array {
		unset( $stats );

		return $this->queue_stats();
	}

	/**
	 * `pixalink_loyalty_heartbeat_received` callback: drop a cached outlet id the key no longer belongs to, and once
	 * Pixalink reports the store active again, send what waited while it was paused.
	 *
	 * @param mixed $response Heartbeat response.
	 * @return void
	 */
	public function on_heartbeat( $response ): void {
		if ( ! is_array( $response ) ) {
			return;
		}

		$reported = is_array( $response['outlet'] ?? null ) ? ( $response['outlet']['id'] ?? null ) : null;
		if ( is_scalar( $reported ) ) {
			$this->outlet->forget_unless( (string) $reported );
		}

		if ( Connection::REMOTE_ACTIVE === ( $response['status'] ?? null ) ) {
			$this->deliverer->resume_commerce();
		}
	}

	/**
	 * Credentials changed: drop the cached outlet id and lift a pause.
	 *
	 * @return void
	 */
	public function on_credentials_changed(): void {
		$this->outlet->forget();
		$this->deliverer->resume();
	}

	/**
	 * Outlet id of the stored connection, or null.
	 *
	 * @return string|null
	 */
	private function connected_outlet_id(): ?string {
		if ( ! $this->connection->exists() ) {
			return null;
		}

		$id = $this->connection->data()['outlet']['id'] ?? null;

		return is_scalar( $id ) && '' !== trim( (string) $id ) ? trim( (string) $id ) : null;
	}

	/**
	 * Record a `ping` and deliver it right away, bypassing a pause (health panel "Send test ping").
	 *
	 * @return array{outcome:string|null,event:Event|null}
	 */
	public function send_ping(): array {
		$stored = $this->queue->enqueue( EventType::PING, null, array() );
		if ( null === $stored ) {
			return array(
				'outcome' => null,
				'event'   => null,
			);
		}

		$outcome = $this->deliverer->deliver( $stored['id'], true );

		return array(
			'outcome' => $outcome,
			'event'   => $this->repository->find( $stored['id'] ),
		);
	}

	/**
	 * Give every dead row a fresh set of attempts.
	 *
	 * @return int Rows requeued.
	 */
	public function retry_dead(): int {
		$count = 0;
		foreach ( $this->repository->dead_ids( self::RETRY_DEAD_LIMIT ) as $id ) {
			$count += $this->retry( $id ) ? 1 : 0;
		}

		return $count;
	}

	/**
	 * Retry one row now: requeue it if dead or cancelled, then enqueue an attempt.
	 *
	 * @param int $id Row id.
	 * @return bool Whether an attempt was enqueued.
	 */
	public function retry( int $id ): bool {
		$event = $this->repository->find( $id );
		if ( null === $event ) {
			return false;
		}

		if ( in_array( $event->status, array( EventStatus::DEAD, EventStatus::CANCELLED ), true ) ) {
			if ( ! $this->repository->requeue( $id, gmdate( 'Y-m-d H:i:s' ) ) ) {
				return false;
			}
		} elseif ( $event->status->is_claimable() ) {
			$this->repository->defer( $id, gmdate( 'Y-m-d H:i:s' ), (string) $event->last_error );
		} else {
			return false;
		}

		$this->scheduler->deliver_now( $id );

		return true;
	}

	/**
	 * Cancel one row.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public function cancel( int $id ): bool {
		return $this->repository->cancel( $id );
	}

	/**
	 * Start a background resend of the last N days of paid orders.
	 *
	 * @param int $days Look-back window in days.
	 * @return void
	 */
	public function resend( int $days ): void {
		$this->reconciler->schedule_resend( $days );
	}

	/**
	 * Everything the health panel, system status report and Site Health test show.
	 *
	 * `store_paused`: Pixalink staff paused the store (the last heartbeat, a 403 since, or the pause that caused).
	 *
	 * @return array{connected:bool,paused:string,store_paused:bool,stats:array{pending:int,sending:int,retry:int,delivered:int,dead:int,cancelled:int,oldest_open_at:string|null},last_delivered:Event|null,overdue_actions:int,cron_disabled:bool,cron_stalled:bool}
	 */
	public function health(): array {
		$next_queue_run = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( 'action_scheduler_run_queue' ) : false;
		$paused         = $this->deliverer->pause_reason();

		return array(
			'connected'       => $this->credentials->is_configured(),
			'paused'          => $paused,
			'store_paused'    => EventDeliverer::PAUSE_COMMERCE_PAUSED === $paused || $this->connection->is_commerce_paused(),
			'stats'           => $this->repository->stats(),
			'last_delivered'  => $this->repository->last_delivered(),
			'overdue_actions' => $this->scheduler->overdue_count(),
			'cron_disabled'   => defined( 'DISABLE_WP_CRON' ) && (bool) constant( 'DISABLE_WP_CRON' ),
			'cron_stalled'    => is_int( $next_queue_run ) && $next_queue_run < time() - 600,
		);
	}

	/**
	 * Outbox persistence.
	 *
	 * @return EventRepository
	 */
	public function repository(): EventRepository {
		return $this->repository;
	}

	/**
	 * Outbox writer (other units enqueue `customer.upserted` through it).
	 *
	 * @return EventQueue
	 */
	public function queue(): EventQueue {
		return $this->queue;
	}

	/**
	 * Outlet id resolver.
	 *
	 * @return OutletResolver
	 */
	public function outlet_resolver(): OutletResolver {
		return $this->outlet;
	}

	/**
	 * Delivery worker.
	 *
	 * @return EventDeliverer
	 */
	public function deliverer(): EventDeliverer {
		return $this->deliverer;
	}

	/**
	 * End-of-request delivery.
	 *
	 * @return ImmediateDelivery
	 */
	public function immediate_delivery(): ImmediateDelivery {
		return $this->immediate;
	}

	/**
	 * Order hooks.
	 *
	 * @return OrderLifecycle
	 */
	public function lifecycle(): OrderLifecycle {
		return $this->lifecycle;
	}

	/**
	 * Missed-event safety net.
	 *
	 * @return Reconciler
	 */
	public function reconciler(): Reconciler {
		return $this->reconciler;
	}

	/**
	 * Retention.
	 *
	 * @return Pruner
	 */
	public function pruner(): Pruner {
		return $this->pruner;
	}

	/**
	 * Action Scheduler wiring.
	 *
	 * @return Scheduler
	 */
	public function scheduler(): Scheduler {
		return $this->scheduler;
	}

	/**
	 * Admin URL of the events list (WooCommerce › Status › Pixalink events).
	 *
	 * @param array<string,string|int> $args Extra query args.
	 * @return string
	 */
	public static function events_url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => 'wc-status',
					'tab'  => self::EVENTS_TAB,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Admin URL of the plugin settings (where the health panel field lives).
	 *
	 * @return string
	 */
	public static function settings_url(): string {
		/**
		 * Filters the URL of the Pixalink Loyalty settings screen.
		 *
		 * @since 0.1.0
		 *
		 * @param string $url Default: WooCommerce › Settings › Pixalink Loyalty.
		 */
		$url = apply_filters( 'pixalink_loyalty_settings_url', admin_url( 'admin.php?page=wc-settings&tab=pixalink_loyalty' ) );

		return is_string( $url ) ? $url : admin_url( 'admin.php?page=wc-settings' );
	}
}
