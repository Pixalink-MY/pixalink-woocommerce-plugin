<?php
/**
 * Event delivery health panel.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Admin;

use Pixalink\Loyalty\Events\Event;
use Pixalink\Loyalty\Events\EventDeliverer;
use Pixalink\Loyalty\Events\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the WooCommerce settings field type `pixalink_loyalty_health` and handles its buttons.
 *
 * Add `array( 'type' => 'pixalink_loyalty_health', 'id' => 'pixalink_loyalty_health', 'title' => … )` to a
 * settings section to show it. Shows the last delivered event, queue depth, the oldest waiting event, overdue
 * background tasks and cron warnings, with buttons to send a test ping, retry failed events and resend recent orders.
 * Buttons are nonce-protected admin-post.php links (the field renders inside the settings form, so no nested forms).
 */
final class HealthPanel {

	public const FIELD_TYPE   = 'pixalink_loyalty_health';
	public const NONCE_ACTION = 'pixalink_loyalty_health';
	public const CAPABILITY   = 'manage_woocommerce';

	public const ACTION_PING         = 'pixalink_loyalty_send_ping';
	public const ACTION_RETRY_DEAD   = 'pixalink_loyalty_retry_dead';
	public const ACTION_RESEND       = 'pixalink_loyalty_resend_orders';
	public const ACTION_RETRY_EVENT  = 'pixalink_loyalty_retry_event';
	public const ACTION_CANCEL_EVENT = 'pixalink_loyalty_cancel_event';

	/**
	 * Per-user transient holding the result of the last button press.
	 */
	private const NOTICE_TRANSIENT = 'pixalink_loyalty_notice_';

	/**
	 * Events module.
	 *
	 * @var Module
	 */
	private Module $events;

	/**
	 * Constructor.
	 *
	 * @param Module $events Events module.
	 */
	public function __construct( Module $events ) {
		$this->events = $events;
	}

	/**
	 * Register the field renderer, button handlers and the result notice.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_admin_field_' . self::FIELD_TYPE, array( $this, 'render_field' ), 10, 1 );
		add_action( 'admin_post_' . self::ACTION_PING, array( $this, 'handle_ping' ) );
		add_action( 'admin_post_' . self::ACTION_RETRY_DEAD, array( $this, 'handle_retry_dead' ) );
		add_action( 'admin_post_' . self::ACTION_RESEND, array( $this, 'handle_resend' ) );
		add_action( 'admin_post_' . self::ACTION_RETRY_EVENT, array( $this, 'handle_retry_event' ) );
		add_action( 'admin_post_' . self::ACTION_CANCEL_EVENT, array( $this, 'handle_cancel_event' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * `woocommerce_admin_field_pixalink_loyalty_health` callback (inside the settings form table).
	 *
	 * @param mixed $field Field definition.
	 * @return void
	 */
	public function render_field( $field ): void {
		$title = is_array( $field ) && isset( $field['title'] ) && is_string( $field['title'] ) ? $field['title'] : __( 'Event delivery', 'pixalink-loyalty-for-woocommerce' );

		echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html( $title ) . '</th><td class="forminp">';
		$this->render();
		echo '</td></tr>';
	}

	/**
	 * The panel.
	 *
	 * @return void
	 */
	public function render(): void {
		$health = $this->events->health();
		$stats  = $health['stats'];
		$last   = $health['last_delivered'];
		$oldest = Event::timestamp( $stats['oldest_open_at'] );
		$now    = time();

		echo '<div class="pixalink-loyalty-health" style="max-width:760px">';

		if ( ! $health['connected'] ) {
			self::inline_notice( 'warning', __( 'This store is not connected to Pixalink yet, so orders are not being sent. Connect it to start earning points.', 'pixalink-loyalty-for-woocommerce' ) );
		} elseif ( EventDeliverer::PAUSE_AUTH_FAILED === $health['paused'] ) {
			self::inline_notice( 'error', __( 'Pixalink rejected this store\'s key, so sending is paused. Reconnect the store; waiting orders are sent automatically afterwards.', 'pixalink-loyalty-for-woocommerce' ) );
		} elseif ( EventDeliverer::PAUSE_NOT_CONNECTED === $health['paused'] ) {
			self::inline_notice( 'error', __( 'Sending is paused because the store\'s Pixalink key is missing. Reconnect the store to resume.', 'pixalink-loyalty-for-woocommerce' ) );
		} elseif ( $health['store_paused'] ) {
			self::inline_notice( 'warning', __( 'Pixalink has paused this store, so points and vouchers are off and order updates wait here. They are sent automatically once Pixalink turns the store back on. Contact Pixalink support.', 'pixalink-loyalty-for-woocommerce' ) );
		}

		echo '<table class="widefat striped" style="margin:0 0 12px"><tbody>';

		self::row(
			__( 'Last order update sent', 'pixalink-loyalty-for-woocommerce' ),
			null === $last
				? __( 'None yet', 'pixalink-loyalty-for-woocommerce' )
				/* translators: 1: event label such as "Order paid", 2: time ago such as "5 mins". */
				: sprintf( __( '%1$s, %2$s ago', 'pixalink-loyalty-for-woocommerce' ), $last->type->label(), human_time_diff( Event::timestamp( $last->delivered_at ) ?? $now, $now ) )
		);

		$waiting = $stats['pending'] + $stats['sending'] + $stats['retry'];
		self::row(
			__( 'Waiting to send', 'pixalink-loyalty-for-woocommerce' ),
			$stats['retry'] > 0
				/* translators: 1: events waiting, 2: of which retrying. */
				? sprintf( __( '%1$s (%2$s retrying)', 'pixalink-loyalty-for-woocommerce' ), number_format_i18n( $waiting ), number_format_i18n( $stats['retry'] ) )
				: number_format_i18n( $waiting )
		);

		self::row(
			__( 'Oldest waiting update', 'pixalink-loyalty-for-woocommerce' ),
			null === $oldest ? '—' : human_time_diff( $oldest, $now )
		);

		self::row( __( 'Failed', 'pixalink-loyalty-for-woocommerce' ), number_format_i18n( $stats['dead'] ), $stats['dead'] > 0 ? Module::events_url( array( 'status' => 'dead' ) ) : '' );

		self::row( __( 'Overdue background tasks', 'pixalink-loyalty-for-woocommerce' ), number_format_i18n( $health['overdue_actions'] ), $health['overdue_actions'] > 0 ? admin_url( 'admin.php?page=wc-status&tab=action-scheduler&status=past-due&s=pixalink' ) : '' );

		echo '</tbody></table>';

		if ( $health['cron_disabled'] && ( $health['cron_stalled'] || $health['overdue_actions'] > 0 ) ) {
			self::inline_notice( 'warning', __( 'WordPress background tasks are not running: DISABLE_WP_CRON is set but no server cron job calls wp-cron.php. Ask your host to run it every minute, otherwise points are added late.', 'pixalink-loyalty-for-woocommerce' ) );
		} elseif ( $health['cron_stalled'] ) {
			self::inline_notice( 'warning', __( 'WordPress background tasks look stalled, so updates to Pixalink may be delayed. A server cron job that calls wp-cron.php every minute fixes this on low-traffic stores.', 'pixalink-loyalty-for-woocommerce' ) );
		}

		echo '<p>';
		self::button( $this->action_url( self::ACTION_PING ), __( 'Send test ping', 'pixalink-loyalty-for-woocommerce' ), $health['connected'] );
		self::button( $this->action_url( self::ACTION_RETRY_DEAD ), __( 'Retry failed', 'pixalink-loyalty-for-woocommerce' ), $stats['dead'] > 0 );
		self::button( $this->action_url( self::ACTION_RESEND, array( 'days' => 7 ) ), __( 'Resend last 7 days', 'pixalink-loyalty-for-woocommerce' ), $health['connected'] );
		self::button( $this->action_url( self::ACTION_RESEND, array( 'days' => 30 ) ), __( 'Resend last 30 days', 'pixalink-loyalty-for-woocommerce' ), $health['connected'] );
		printf( ' <a href="%s">%s</a>', esc_url( Module::events_url() ), esc_html__( 'View all updates', 'pixalink-loyalty-for-woocommerce' ) );
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Resending only sends what Pixalink has not received yet, so points are never added twice.', 'pixalink-loyalty-for-woocommerce' ) . '</p>';

		echo '</div>';
	}

	/**
	 * Nonce-protected admin-post.php URL for a button.
	 *
	 * @param string                   $action admin-post action.
	 * @param array<string,string|int> $args   Extra query args.
	 * @return string
	 */
	public function action_url( string $action, array $args = array() ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => $action ) + $args, admin_url( 'admin-post.php' ) ), self::NONCE_ACTION );
	}

	/**
	 * "Send test ping".
	 *
	 * @return void
	 */
	public function handle_ping(): void {
		$this->authorize();

		$result = $this->events->send_ping();
		$event  = $result['event'];

		if ( EventDeliverer::OUTCOME_DELIVERED === $result['outcome'] ) {
			self::flash( 'success', __( 'Test ping delivered. This store is talking to Pixalink.', 'pixalink-loyalty-for-woocommerce' ) );
		} else {
			self::flash(
				'error',
				sprintf(
					/* translators: %s: error details. */
					__( 'Test ping failed: %s', 'pixalink-loyalty-for-woocommerce' ),
					null !== $event && null !== $event->last_error ? $event->last_error : __( 'the store is not connected.', 'pixalink-loyalty-for-woocommerce' )
				)
			);
		}

		$this->redirect_back();
	}

	/**
	 * "Retry failed".
	 *
	 * @return void
	 */
	public function handle_retry_dead(): void {
		$this->authorize();

		$count = $this->events->retry_dead();
		/* translators: %s: number of updates. */
		self::flash( 'success', sprintf( _n( '%s failed update will be retried.', '%s failed updates will be retried.', $count, 'pixalink-loyalty-for-woocommerce' ), number_format_i18n( $count ) ) );

		$this->redirect_back();
	}

	/**
	 * "Resend last N days".
	 *
	 * @return void
	 */
	public function handle_resend(): void {
		$this->authorize();

		$days = isset( $_GET['days'] ) ? absint( wp_unslash( $_GET['days'] ) ) : 7; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in authorize().
		$days = in_array( $days, array( 7, 30 ), true ) ? $days : 7;
		$this->events->resend( $days );

		/* translators: %d: number of days. */
		self::flash( 'success', sprintf( _n( 'Resending orders paid in the last %d day in the background.', 'Resending orders paid in the last %d days in the background.', $days, 'pixalink-loyalty-for-woocommerce' ), $days ) );

		$this->redirect_back();
	}

	/**
	 * "Retry" on one event.
	 *
	 * @return void
	 */
	public function handle_retry_event(): void {
		$this->authorize();

		$id = isset( $_GET['event'] ) ? absint( wp_unslash( $_GET['event'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in authorize().
		if ( $id > 0 && $this->events->retry( $id ) ) {
			self::flash( 'success', __( 'The update will be sent again shortly.', 'pixalink-loyalty-for-woocommerce' ) );
		} else {
			self::flash( 'error', __( 'This update cannot be retried (it was already delivered or is being sent).', 'pixalink-loyalty-for-woocommerce' ) );
		}

		$this->redirect_back();
	}

	/**
	 * "Cancel" on one event.
	 *
	 * @return void
	 */
	public function handle_cancel_event(): void {
		$this->authorize();

		$id = isset( $_GET['event'] ) ? absint( wp_unslash( $_GET['event'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified in authorize().
		if ( $id > 0 && $this->events->cancel( $id ) ) {
			self::flash( 'success', __( 'The update was cancelled and will not be sent.', 'pixalink-loyalty-for-woocommerce' ) );
		} else {
			self::flash( 'error', __( 'This update cannot be cancelled.', 'pixalink-loyalty-for-woocommerce' ) );
		}

		$this->redirect_back();
	}

	/**
	 * `admin_notices` callback: the result of the last button press.
	 *
	 * @return void
	 */
	public function render_notice(): void {
		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info' ),
			esc_html( (string) $notice['message'] )
		);
	}

	/**
	 * Remember a result for the next admin page (shown once by {@see HealthPanel::render_notice()}).
	 *
	 * @param string $type    success|error|warning|info.
	 * @param string $message Message.
	 * @return void
	 */
	public static function flash( string $type, string $message ): void {
		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Capability and nonce check for button handlers.
	 *
	 * @return void
	 */
	private function authorize(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Pixalink Loyalty.', 'pixalink-loyalty-for-woocommerce' ), 403 );
		}

		check_admin_referer( self::NONCE_ACTION );
	}

	/**
	 * Back to the screen the button was on.
	 *
	 * @return void
	 */
	private function redirect_back(): void {
		$referer = wp_get_referer();
		wp_safe_redirect( false !== $referer ? $referer : Module::settings_url() );
		exit;
	}

	/**
	 * One table row.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $url   Optional link around the value.
	 * @return void
	 */
	private static function row( string $label, string $value, string $url = '' ): void {
		echo '<tr><th scope="row" style="width:40%">' . esc_html( $label ) . '</th><td>';
		if ( '' !== $url ) {
			printf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $value ) );
		} else {
			echo esc_html( $value );
		}
		echo '</td></tr>';
	}

	/**
	 * A link styled as a button.
	 *
	 * @param string $url     Target.
	 * @param string $label   Label.
	 * @param bool   $enabled Whether the button is usable.
	 * @return void
	 */
	private static function button( string $url, string $label, bool $enabled ): void {
		if ( $enabled ) {
			printf( '<a class="button" href="%s">%s</a> ', esc_url( $url ), esc_html( $label ) );
			return;
		}

		printf( '<span class="button disabled" aria-disabled="true">%s</span> ', esc_html( $label ) );
	}

	/**
	 * An inline notice inside the panel.
	 *
	 * @param string $type    error|warning|info.
	 * @param string $message Message.
	 * @return void
	 */
	private static function inline_notice( string $type, string $message ): void {
		printf( '<div class="notice notice-%s inline" style="margin:0 0 12px"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}
}
