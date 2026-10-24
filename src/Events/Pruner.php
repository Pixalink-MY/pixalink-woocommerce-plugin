<?php
/**
 * Outbox retention.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Daily: delete delivered rows 30 days after delivery and dead or cancelled rows 90 days after creation.
 *
 * Open rows are never pruned. Deleting a delivered row is safe: event ids are deterministic, so a later re-enqueue
 * of the same fact is answered "duplicate" by Pixalink.
 */
final class Pruner {

	public const DELIVERED_RETENTION_DAYS = 30;
	public const DEAD_RETENTION_DAYS      = 90;

	/**
	 * Rows per DELETE statement.
	 */
	private const BATCH = 1000;

	/**
	 * Upper bound of statements per run.
	 */
	private const MAX_BATCHES = 50;

	/**
	 * Outbox persistence.
	 *
	 * @var EventRepository
	 */
	private EventRepository $repository;

	/**
	 * Current unix time.
	 *
	 * @var \Closure():int
	 */
	private \Closure $now;

	/**
	 * Constructor.
	 *
	 * @param EventRepository $repository Outbox persistence.
	 * @param \Closure|null   $now        Unix time source (tests); defaults to time().
	 */
	public function __construct( EventRepository $repository, ?\Closure $now = null ) {
		$this->repository = $repository;
		$this->now        = $now ?? static fn (): int => time();
	}

	/**
	 * Register the Action Scheduler callback.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( Scheduler::PRUNE_HOOK, array( $this, 'handle_prune' ), 10, 0 );
	}

	/**
	 * `pixalink_loyalty_prune_events` callback.
	 *
	 * @return void
	 */
	public function handle_prune(): void {
		$this->prune();
	}

	/**
	 * Delete rows past their retention.
	 *
	 * @return int Rows deleted.
	 */
	public function prune(): int {
		$now              = ( $this->now )();
		$delivered_before = gmdate( 'Y-m-d H:i:s', $now - self::DELIVERED_RETENTION_DAYS * 86400 );
		$dead_before      = gmdate( 'Y-m-d H:i:s', $now - self::DEAD_RETENTION_DAYS * 86400 );
		$deleted          = 0;

		for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
			$rows     = $this->repository->prune( $delivered_before, $dead_before, self::BATCH );
			$deleted += $rows;
			if ( $rows < self::BATCH ) {
				break;
			}
		}

		return $deleted;
	}
}
