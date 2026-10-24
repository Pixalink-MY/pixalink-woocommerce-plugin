<?php
/**
 * WooCommerce › Status › System status section.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Admin;

use Pixalink\Loyalty\Events\Event;
use Pixalink\Loyalty\Events\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Pixalink Loyalty" table to the system status report (and its copy-for-support export).
 *
 * Never includes keys, customer data or payloads: only versions, connection state and queue counts.
 */
final class SystemStatus {

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
	 * Register the report section.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_system_status_report', array( $this, 'render' ) );
	}

	/**
	 * Report rows: export label => [label, value, is_warning].
	 *
	 * @return array<string,array{0:string,1:string,2:bool}>
	 */
	public function rows(): array {
		$health = $this->events->health();
		$stats  = $health['stats'];
		$last   = $health['last_delivered'];
		$hpos   = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		return array(
			'Version'                 => array( __( 'Version', 'pixalink-loyalty-for-woocommerce' ), PIXALINK_LOYALTY_VERSION . ' (' . PIXALINK_LOYALTY_DISTRIBUTION . ')', false ),
			'Connected'               => array( __( 'Connected', 'pixalink-loyalty-for-woocommerce' ), $health['connected'] ? __( 'Yes', 'pixalink-loyalty-for-woocommerce' ) : __( 'No', 'pixalink-loyalty-for-woocommerce' ), ! $health['connected'] ),
			'Delivery'                => array( __( 'Event delivery', 'pixalink-loyalty-for-woocommerce' ), '' === $health['paused'] ? __( 'Running', 'pixalink-loyalty-for-woocommerce' ) : __( 'Paused', 'pixalink-loyalty-for-woocommerce' ) . ' (' . $health['paused'] . ')', '' !== $health['paused'] ),
			'Waiting'                 => array( __( 'Waiting to send', 'pixalink-loyalty-for-woocommerce' ), (string) ( $stats['pending'] + $stats['sending'] + $stats['retry'] ) . ' (' . $stats['retry'] . ' retrying)', false ),
			'Failed'                  => array( __( 'Failed', 'pixalink-loyalty-for-woocommerce' ), (string) $stats['dead'], $stats['dead'] > 0 ),
			'Oldest waiting'          => array( __( 'Oldest waiting update', 'pixalink-loyalty-for-woocommerce' ), Event::iso8601( $stats['oldest_open_at'] ) ?? '–', false ),
			'Last delivered'          => array( __( 'Last delivered', 'pixalink-loyalty-for-woocommerce' ), null === $last ? '–' : $last->type->value . ' @ ' . ( Event::iso8601( $last->delivered_at ) ?? '' ), false ),
			'Overdue scheduled tasks' => array( __( 'Overdue background tasks', 'pixalink-loyalty-for-woocommerce' ), (string) $health['overdue_actions'], $health['overdue_actions'] > 0 ),
			'DISABLE_WP_CRON'         => array( 'DISABLE_WP_CRON', $health['cron_disabled'] ? 'true' : 'false', $health['cron_disabled'] && $health['cron_stalled'] ),
			'HPOS'                    => array( __( 'Order storage', 'pixalink-loyalty-for-woocommerce' ), $hpos ? __( 'High-performance order storage', 'pixalink-loyalty-for-woocommerce' ) : __( 'WordPress posts', 'pixalink-loyalty-for-woocommerce' ), false ),
		);
	}

	/**
	 * `woocommerce_system_status_report` callback.
	 *
	 * @return void
	 */
	public function render(): void {
		echo '<table class="wc_status_table widefat" cellspacing="0"><thead><tr><th colspan="3" data-export-label="Pixalink Loyalty"><h2>' . esc_html__( 'Pixalink Loyalty', 'pixalink-loyalty-for-woocommerce' ) . '</h2></th></tr></thead><tbody>';

		foreach ( $this->rows() as $export_label => $row ) {
			[ $label, $value, $warning ] = $row;
			printf(
				'<tr><td data-export-label="%s">%s:</td><td class="help">&nbsp;</td><td>%s</td></tr>',
				esc_attr( $export_label ),
				esc_html( $label ),
				$warning ? '<mark class="error"><span class="dashicons dashicons-warning"></span> ' . esc_html( $value ) . '</mark>' : '<mark class="yes">' . esc_html( $value ) . '</mark>'
			);
		}

		echo '</tbody></table>';
	}
}
