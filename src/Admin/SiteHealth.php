<?php
/**
 * Tools › Site Health test and debug information.
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
 * Site Health "Pixalink loyalty updates are being delivered" test, plus a debug-information section.
 *
 * Critical when delivery is paused because Pixalink rejected the key; recommended when not connected, with failed
 * updates, a backlog older than an hour or stalled background tasks; good otherwise.
 */
final class SiteHealth {

	public const TEST_ID = 'pixalink_loyalty_event_delivery';

	/**
	 * A waiting update older than this is a backlog (seconds).
	 */
	public const BACKLOG_SECONDS = 3600;

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
	 * Register the test and debug section.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
		add_filter( 'debug_information', array( $this, 'add_debug_information' ) );
	}

	/**
	 * `site_status_tests` filter.
	 *
	 * @param mixed $tests Tests.
	 * @return mixed
	 */
	public function add_tests( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		$tests['direct'][ self::TEST_ID ] = array(
			'label' => __( 'Pixalink loyalty updates', 'pixalink-loyalty-for-woocommerce' ),
			'test'  => array( $this, 'test_event_delivery' ),
		);

		return $tests;
	}

	/**
	 * The test.
	 *
	 * @return array{label:string,status:string,badge:array{label:string,color:string},description:string,actions:string,test:string}
	 */
	public function test_event_delivery(): array {
		$health  = $this->events->health();
		$stats   = $health['stats'];
		$oldest  = Event::timestamp( $stats['oldest_open_at'] );
		$actions = sprintf( '<p><a href="%s">%s</a></p>', esc_url( Module::events_url() ), esc_html__( 'View Pixalink updates', 'pixalink-loyalty-for-woocommerce' ) );

		if ( ! $health['connected'] ) {
			return $this->result(
				'recommended',
				__( 'Pixalink Loyalty is not connected', 'pixalink-loyalty-for-woocommerce' ),
				__( 'Orders are not sent to Pixalink until the store is connected, so customers do not earn points yet.', 'pixalink-loyalty-for-woocommerce' ),
				sprintf( '<p><a href="%s">%s</a></p>', esc_url( Module::settings_url() ), esc_html__( 'Connect to Pixalink', 'pixalink-loyalty-for-woocommerce' ) )
			);
		}

		if ( EventDeliverer::PAUSE_AUTH_FAILED === $health['paused'] || EventDeliverer::PAUSE_NOT_CONNECTED === $health['paused'] ) {
			return $this->result(
				'critical',
				__( 'Pixalink rejected this store\'s key', 'pixalink-loyalty-for-woocommerce' ),
				__( 'Sending order updates to Pixalink is paused, so points are not being added. Reconnect the store; waiting updates are sent automatically afterwards.', 'pixalink-loyalty-for-woocommerce' ),
				sprintf( '<p><a href="%s">%s</a></p>', esc_url( Module::settings_url() ), esc_html__( 'Reconnect', 'pixalink-loyalty-for-woocommerce' ) )
			);
		}

		$problems = array();
		if ( $stats['dead'] > 0 ) {
			/* translators: %s: number of updates. */
			$problems[] = sprintf( _n( '%s update could not be delivered.', '%s updates could not be delivered.', $stats['dead'], 'pixalink-loyalty-for-woocommerce' ), number_format_i18n( $stats['dead'] ) );
		}
		if ( null !== $oldest && $oldest < time() - self::BACKLOG_SECONDS ) {
			/* translators: %s: age such as "3 hours". */
			$problems[] = sprintf( __( 'The oldest waiting update is %s old.', 'pixalink-loyalty-for-woocommerce' ), human_time_diff( $oldest ) );
		}
		if ( $health['cron_stalled'] || $health['overdue_actions'] > 0 ) {
			$problems[] = $health['cron_disabled']
				? __( 'DISABLE_WP_CRON is set but background tasks are not running: a server cron job must call wp-cron.php every minute.', 'pixalink-loyalty-for-woocommerce' )
				: __( 'WordPress background tasks are running late. On low-traffic stores, a server cron job that calls wp-cron.php every minute fixes this.', 'pixalink-loyalty-for-woocommerce' );
		}

		if ( array() !== $problems ) {
			return $this->result( 'recommended', __( 'Some Pixalink loyalty updates are delayed', 'pixalink-loyalty-for-woocommerce' ), implode( ' ', $problems ), $actions );
		}

		return $this->result( 'good', __( 'Pixalink loyalty updates are being delivered', 'pixalink-loyalty-for-woocommerce' ), __( 'Paid, completed, cancelled and refunded orders reach Pixalink.', 'pixalink-loyalty-for-woocommerce' ), $actions );
	}

	/**
	 * `debug_information` filter.
	 *
	 * @param mixed $info Debug sections.
	 * @return mixed
	 */
	public function add_debug_information( $info ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}

		$fields = array();
		foreach ( ( new SystemStatus( $this->events ) )->rows() as $key => $row ) {
			$fields[ sanitize_key( $key ) ] = array(
				'label' => $row[0],
				'value' => $row[1],
			);
		}

		$info['pixalink-loyalty'] = array(
			'label'  => __( 'Pixalink Loyalty', 'pixalink-loyalty-for-woocommerce' ),
			'fields' => $fields,
		);

		return $info;
	}

	/**
	 * A Site Health result.
	 *
	 * @param string $status      good|recommended|critical.
	 * @param string $label       Headline.
	 * @param string $description Plain-text description.
	 * @param string $actions     Actions HTML (already escaped).
	 * @return array{label:string,status:string,badge:array{label:string,color:string},description:string,actions:string,test:string}
	 */
	private function result( string $status, string $label, string $description, string $actions ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Pixalink Loyalty', 'pixalink-loyalty-for-woocommerce' ),
				'color' => 'critical' === $status ? 'red' : 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => $actions,
			'test'        => self::TEST_ID,
		);
	}
}
