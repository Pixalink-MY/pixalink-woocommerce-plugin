<?php
/**
 * WooCommerce › Status › Pixalink events.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Pixalink\Loyalty\Events\Event;
use Pixalink\Loyalty\Events\EventStatus;
use Pixalink\Loyalty\Events\Module;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Outbox rows with status views, search by order or event id, the stored payload and response, and retry/cancel
 * row and bulk actions. Lives as a tab of WooCommerce › Status next to the logs.
 */
final class EventsListTable extends \WP_List_Table {

	/**
	 * Screen id of WooCommerce › Status.
	 */
	public const SCREEN = 'woocommerce_page_wc-status';

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

		parent::__construct(
			array(
				'singular' => 'pixalink_event',
				'plural'   => 'pixalink_events',
				'ajax'     => false,
				'screen'   => self::SCREEN,
			)
		);
	}

	/**
	 * Register the status tab and the bulk-action handler.
	 *
	 * @param Module $events Events module.
	 * @return void
	 */
	public static function register_hooks( Module $events ): void {
		add_filter(
			'woocommerce_admin_status_tabs',
			static function ( $tabs ) {
				$tabs                       = is_array( $tabs ) ? $tabs : array();
				$tabs[ Module::EVENTS_TAB ] = __( 'Pixalink events', 'pixalink-loyalty-for-woocommerce' );

				return $tabs;
			}
		);
		add_action( 'woocommerce_admin_status_content_' . Module::EVENTS_TAB, static fn () => self::render_page( $events ) );
		add_action( 'load-' . self::SCREEN, static fn () => self::process_bulk_request( $events ) );
	}

	/**
	 * Tab content.
	 *
	 * @param Module $events Events module.
	 * @return void
	 */
	public static function render_page( Module $events ): void {
		if ( ! current_user_can( HealthPanel::CAPABILITY ) ) {
			return;
		}

		$table = new self( $events );
		$table->prepare_items();

		echo '<h2>' . esc_html__( 'Updates sent to Pixalink', 'pixalink-loyalty-for-woocommerce' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Every paid, completed, cancelled or refunded order is sent to Pixalink from here. Failed updates can be retried; nothing is ever counted twice.', 'pixalink-loyalty-for-woocommerce' ) . '</p>';

		( new HealthPanel( $events ) )->render();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="wc-status" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( Module::EVENTS_TAB ) . '" />';
		$table->views();
		$table->search_box( __( 'Search by order or event id', 'pixalink-loyalty-for-woocommerce' ), 'pixalink-events' );
		$table->display();
		echo '</form>';
	}

	/**
	 * `load-woocommerce_page_wc-status`: run a bulk action before output, then redirect.
	 *
	 * @param Module $events Events module.
	 * @return void
	 */
	public static function process_bulk_request( Module $events ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only routing here; the nonce is checked below before acting.
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$action = isset( $_GET['action'] ) && '-1' !== $_GET['action'] ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( '' === $action && isset( $_GET['action2'] ) && '-1' !== $_GET['action2'] ) {
			$action = sanitize_key( wp_unslash( $_GET['action2'] ) );
		}
		$ids = isset( $_GET['event'] ) && is_array( $_GET['event'] ) ? array_filter( array_map( 'absint', wp_unslash( $_GET['event'] ) ) ) : array();
		// phpcs:enable

		if ( Module::EVENTS_TAB !== $tab || ! in_array( $action, array( 'retry', 'cancel' ), true ) || array() === $ids ) {
			return;
		}

		if ( ! current_user_can( HealthPanel::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Pixalink Loyalty.', 'pixalink-loyalty-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'bulk-pixalink_events' );

		$done = 0;
		foreach ( $ids as $id ) {
			$done += ( 'retry' === $action ? $events->retry( $id ) : $events->cancel( $id ) ) ? 1 : 0;
		}

		HealthPanel::flash(
			'success',
			'retry' === $action
				/* translators: %s: number of updates. */
				? sprintf( _n( '%s update will be sent again.', '%s updates will be sent again.', $done, 'pixalink-loyalty-for-woocommerce' ), number_format_i18n( $done ) )
				/* translators: %s: number of updates. */
				: sprintf( _n( '%s update cancelled.', '%s updates cancelled.', $done, 'pixalink-loyalty-for-woocommerce' ), number_format_i18n( $done ) )
		);

		wp_safe_redirect( remove_query_arg( array( 'action', 'action2', 'event', '_wpnonce', '_wp_http_referer' ) ) );
		exit;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'type'       => __( 'Update', 'pixalink-loyalty-for-woocommerce' ),
			'order'      => __( 'Order', 'pixalink-loyalty-for-woocommerce' ),
			'status'     => __( 'Status', 'pixalink-loyalty-for-woocommerce' ),
			'attempts'   => __( 'Attempts', 'pixalink-loyalty-for-woocommerce' ),
			'created_at' => __( 'Recorded', 'pixalink-loyalty-for-woocommerce' ),
			'outcome'    => __( 'Result', 'pixalink-loyalty-for-woocommerce' ),
			'payload'    => __( 'Details', 'pixalink-loyalty-for-woocommerce' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	protected function get_sortable_columns() {
		return array(
			'type'       => array( 'type', false ),
			'status'     => array( 'status', false ),
			'attempts'   => array( 'attempts', false ),
			'created_at' => array( 'id', true ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions() {
		return array(
			'retry'  => __( 'Retry', 'pixalink-loyalty-for-woocommerce' ),
			'cancel' => __( 'Cancel', 'pixalink-loyalty-for-woocommerce' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,string>
	 */
	protected function get_views() {
		$stats   = $this->events->repository()->stats();
		$current = $this->current_status();
		$total   = $stats['pending'] + $stats['sending'] + $stats['retry'] + $stats['delivered'] + $stats['dead'] + $stats['cancelled'];
		$views   = array(
			'all' => $this->view_link( '', __( 'All', 'pixalink-loyalty-for-woocommerce' ), $total, '' === $current ),
		);

		foreach ( EventStatus::cases() as $status ) {
			$count = $stats[ $status->value ];
			if ( $count > 0 || $current === $status->value ) {
				$views[ $status->value ] = $this->view_link( $status->value, $status->label(), $count, $current === $status->value );
			}
		}

		return $views;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 20;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$type    = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
		// phpcs:enable

		$result = $this->events->repository()->search(
			array(
				'status'   => $this->current_status(),
				'type'     => $type,
				'search'   => $search,
				'orderby'  => $orderby,
				'order'    => $order,
				'per_page' => $per_page,
				'page'     => $this->get_pagenum(),
			)
		);

		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'type' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No updates yet. They appear here as soon as an order is paid.', 'pixalink-loyalty-for-woocommerce' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $item Event.
	 * @return string
	 */
	protected function column_cb( $item ) {
		if ( ! $item instanceof Event ) {
			return '';
		}

		return sprintf( '<input type="checkbox" name="event[]" value="%d" />', $item->id );
	}

	/**
	 * Update type with row actions.
	 *
	 * @param Event $item Event.
	 * @return string
	 */
	public function column_type( Event $item ): string {
		$panel   = new HealthPanel( $this->events );
		$actions = array();

		if ( in_array( $item->status, array( EventStatus::DEAD, EventStatus::CANCELLED, EventStatus::RETRY, EventStatus::PENDING ), true ) ) {
			$actions['retry'] = sprintf( '<a href="%s">%s</a>', esc_url( $panel->action_url( HealthPanel::ACTION_RETRY_EVENT, array( 'event' => $item->id ) ) ), esc_html__( 'Retry now', 'pixalink-loyalty-for-woocommerce' ) );
		}
		if ( in_array( $item->status, array( EventStatus::DEAD, EventStatus::RETRY, EventStatus::PENDING ), true ) ) {
			$actions['cancel'] = sprintf( '<a href="%s">%s</a>', esc_url( $panel->action_url( HealthPanel::ACTION_CANCEL_EVENT, array( 'event' => $item->id ) ) ), esc_html__( 'Cancel', 'pixalink-loyalty-for-woocommerce' ) );
		}

		return sprintf(
			'<strong>%s</strong><br><code style="font-size:11px">%s</code>%s',
			esc_html( $item->type->label() ),
			esc_html( $item->uuid ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Linked order and sequence.
	 *
	 * @param Event $item Event.
	 * @return string
	 */
	public function column_order( Event $item ): string {
		if ( ! $item->is_for_order() ) {
			return '—';
		}

		$url = class_exists( OrderUtil::class ) ? OrderUtil::get_order_admin_edit_url( $item->object_id ) : admin_url( 'post.php?post=' . $item->object_id . '&action=edit' );

		return sprintf(
			'<a href="%s">#%d</a><br><span class="description">%s</span>',
			esc_url( $url ),
			$item->object_id,
			/* translators: %d: per-order sequence number. */
			esc_html( sprintf( __( 'update %d', 'pixalink-loyalty-for-woocommerce' ), $item->sequence ) )
		);
	}

	/**
	 * Status.
	 *
	 * @param Event $item Event.
	 * @return string
	 */
	public function column_status( Event $item ): string {
		$colors = array(
			'delivered' => '#008a20',
			'dead'      => '#d63638',
			'retry'     => '#996800',
		);
		$color  = $colors[ $item->status->value ] ?? '#50575e';

		$html = sprintf( '<span style="color:%s;font-weight:600">%s</span>', esc_attr( $color ), esc_html( $item->status->label() ) );
		if ( in_array( $item->status, array( EventStatus::RETRY, EventStatus::PENDING ), true ) && null !== $item->next_attempt_at ) {
			$next = Event::timestamp( $item->next_attempt_at );
			if ( null !== $next && $next > time() ) {
				/* translators: %s: time until the next attempt, such as "5 mins". */
				$html .= '<br><span class="description">' . esc_html( sprintf( __( 'next try in %s', 'pixalink-loyalty-for-woocommerce' ), human_time_diff( time(), $next ) ) ) . '</span>';
			}
		}

		return $html;
	}

	/**
	 * Attempts.
	 *
	 * @param Event $item Event.
	 * @return string
	 */
	public function column_attempts( Event $item ): string {
		return esc_html( number_format_i18n( $item->attempts ) );
	}

	/**
	 * Creation time.
	 *
	 * @param Event $item Event.
	 * @return string
	 */
	public function column_created_at( Event $item ): string {
		return esc_html( self::local_time( $item->created_at ) );
	}

	/**
	 * Delivery time or last error.
	 *
	 * @param Event $item Event.
	 * @return string
	 */
	public function column_outcome( Event $item ): string {
		if ( EventStatus::DELIVERED === $item->status ) {
			$status = isset( $item->response['status'] ) && is_string( $item->response['status'] ) ? $item->response['status'] : '';

			/* translators: 1: delivery time, 2: Pixalink's answer such as "processed". */
			return esc_html( sprintf( __( '%1$s (%2$s)', 'pixalink-loyalty-for-woocommerce' ), self::local_time( (string) $item->delivered_at ), '' !== $status ? $status : 'ok' ) );
		}

		if ( null === $item->last_error || '' === $item->last_error ) {
			return '—';
		}

		return '<code style="font-size:11px;word-break:break-all">' . esc_html( $item->last_error ) . '</code>';
	}

	/**
	 * Stored payload and response, collapsed.
	 *
	 * @param Event $item Event.
	 * @return string
	 */
	public function column_payload( Event $item ): string {
		$pretty = static fn ( $data ): string => (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$html  = '<details><summary>' . esc_html__( 'View payload', 'pixalink-loyalty-for-woocommerce' ) . '</summary>';
		$html .= '<pre style="max-height:320px;overflow:auto;white-space:pre-wrap;font-size:11px">' . esc_html( $pretty( array() === $item->payload ? new \stdClass() : $item->payload ) ) . '</pre>';
		if ( null !== $item->response ) {
			$html .= '<strong>' . esc_html__( 'Response', 'pixalink-loyalty-for-woocommerce' ) . '</strong>';
			$html .= '<pre style="max-height:200px;overflow:auto;white-space:pre-wrap;font-size:11px">' . esc_html( $pretty( $item->response ) ) . '</pre>';
		}

		return $html . '</details>';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed  $item        Event.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		unset( $item, $column_name );

		return '';
	}

	/**
	 * Status filter from the request.
	 *
	 * @return string
	 */
	private function current_status(): string {
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.

		return null !== EventStatus::tryFrom( $status ) ? $status : '';
	}

	/**
	 * One status view link.
	 *
	 * @param string $status  Status ('' for all).
	 * @param string $label   Label.
	 * @param int    $count   Rows.
	 * @param bool   $current Whether selected.
	 * @return string
	 */
	private function view_link( string $status, string $label, int $count, bool $current ): string {
		return sprintf(
			'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
			esc_url( Module::events_url( '' === $status ? array() : array( 'status' => $status ) ) ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html( $label ),
			esc_html( number_format_i18n( $count ) )
		);
	}

	/**
	 * Stored UTC time in the site's format.
	 *
	 * @param string $mysql_time UTC time.
	 * @return string
	 */
	private static function local_time( string $mysql_time ): string {
		$timestamp = Event::timestamp( $mysql_time );
		if ( null === $timestamp ) {
			return '';
		}

		return (string) wp_date( trim( (string) get_option( 'date_format', 'Y-m-d' ) . ' ' . (string) get_option( 'time_format', 'H:i' ) ), $timestamp );
	}
}
