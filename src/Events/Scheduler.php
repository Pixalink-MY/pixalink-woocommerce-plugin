<?php
/**
 * Action Scheduler wiring for the outbox.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Events;

use Pixalink\Loyalty\Install\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Owns every Action Scheduler call the outbox makes (group `pixalink-loyalty`).
 *
 * Hooks:
 * - `pixalink_loyalty_deliver_event` (`event_id`): one delivery attempt, enqueued per event.
 * - `pixalink_loyalty_sweep_events`: every 10 minutes, re-enqueue due rows whose action was lost and reclaim stale locks.
 * - `pixalink_loyalty_reconcile_events`: daily, enqueue `order.paid` for paid orders of the last 7 days without one.
 * - `pixalink_loyalty_prune_events`: daily, delete delivered rows after 30 days and dead rows after 90 days.
 * - `pixalink_loyalty_resend_orders` (`days`, `page`): one batch of a merchant-requested resend.
 */
final class Scheduler {

	public const DELIVER_HOOK   = 'pixalink_loyalty_deliver_event';
	public const SWEEP_HOOK     = 'pixalink_loyalty_sweep_events';
	public const RECONCILE_HOOK = 'pixalink_loyalty_reconcile_events';
	public const PRUNE_HOOK     = 'pixalink_loyalty_prune_events';
	public const RESEND_HOOK    = 'pixalink_loyalty_resend_orders';

	/**
	 * Recurring actions: hook => interval in seconds.
	 */
	public const RECURRING = array(
		self::SWEEP_HOOK     => 600,
		self::RECONCILE_HOOK => 86400,
		self::PRUNE_HOOK     => 86400,
	);

	/**
	 * Transient that throttles the recurring-action check to once an hour.
	 */
	public const CHECK_TRANSIENT = 'pixalink_loyalty_schedules_checked';

	/**
	 * Register the throttled recurring-action check.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'action_scheduler_init', array( $this, 'maybe_ensure_recurring' ) );
		add_action( 'pixalink_loyalty_installed', array( $this, 'reset_check' ) );
		// Deactivation unschedules the group; forget the throttle so reactivation re-schedules at once.
		add_action( 'deactivate_' . plugin_basename( PIXALINK_LOYALTY_FILE ), array( $this, 'reset_check' ) );
	}

	/**
	 * Enqueue an immediate delivery attempt (deduplicated while one is pending).
	 *
	 * @param int $event_id Row id.
	 * @return void
	 */
	public function deliver_now( int $event_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		as_enqueue_async_action( self::DELIVER_HOOK, array( 'event_id' => $event_id ), Installer::ACTION_GROUP, true );
	}

	/**
	 * Schedule a delivery attempt at a given time.
	 *
	 * Not unique on purpose: this is called from inside the running delivery action, which Action Scheduler still
	 * counts as in progress, so a unique schedule would be silently dropped. Duplicate attempts are harmless because
	 * a worker must claim the row first.
	 *
	 * @param int $event_id  Row id.
	 * @param int $timestamp Unix time.
	 * @return void
	 */
	public function deliver_at( int $event_id, int $timestamp ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		as_schedule_single_action( $timestamp, self::DELIVER_HOOK, array( 'event_id' => $event_id ), Installer::ACTION_GROUP );
	}

	/**
	 * Run the sweep as soon as possible (after a pause is lifted).
	 *
	 * @return void
	 */
	public function sweep_now(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		as_enqueue_async_action( self::SWEEP_HOOK, array(), Installer::ACTION_GROUP, true );
	}

	/**
	 * Enqueue one batch of a resend.
	 *
	 * @param int $days Look-back window in days.
	 * @param int $page 1-based batch number.
	 * @return void
	 */
	public function resend_page( int $days, int $page ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		as_enqueue_async_action(
			self::RESEND_HOOK,
			array(
				'days' => $days,
				'page' => $page,
			),
			Installer::ACTION_GROUP,
			true
		);
	}

	/**
	 * `action_scheduler_init` callback: make sure the recurring actions exist, at most once an hour.
	 *
	 * @return void
	 */
	public function maybe_ensure_recurring(): void {
		if ( false !== get_transient( self::CHECK_TRANSIENT ) ) {
			return;
		}

		$this->ensure_recurring();
		set_transient( self::CHECK_TRANSIENT, 1, HOUR_IN_SECONDS );
	}

	/**
	 * Schedule any missing recurring action.
	 *
	 * @return void
	 */
	public function ensure_recurring(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		$offset = 0;
		foreach ( self::RECURRING as $hook => $interval ) {
			if ( ! as_has_scheduled_action( $hook, array(), Installer::ACTION_GROUP ) ) {
				// Stagger the first runs so a fresh install does not fire everything at once.
				as_schedule_recurring_action( time() + 300 + $offset, $interval, $hook, array(), Installer::ACTION_GROUP, true );
			}
			$offset += 600;
		}
	}

	/**
	 * Forget the throttle so the next request re-checks (after install or upgrade).
	 *
	 * @return void
	 */
	public function reset_check(): void {
		delete_transient( self::CHECK_TRANSIENT );
	}

	/**
	 * Pending actions of the plugin's group that should already have run (cron not firing).
	 *
	 * @param int $grace_seconds How late an action may be before it counts as overdue.
	 * @return int
	 */
	public function overdue_count( int $grace_seconds = 600 ): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( \ActionScheduler_Store::class ) ) {
			return 0;
		}

		$ids = as_get_scheduled_actions(
			array(
				'group'        => Installer::ACTION_GROUP,
				'status'       => \ActionScheduler_Store::STATUS_PENDING,
				'date'         => gmdate( 'Y-m-d H:i:s', time() - $grace_seconds ),
				'date_compare' => '<=',
				'per_page'     => 500,
			),
			'ids'
		);

		return is_array( $ids ) ? count( $ids ) : 0;
	}
}
