<?php
/**
 * Integration tests for EventsListTable.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Admin;

use Pixalink\Loyalty\Admin\EventsListTable;
use Pixalink\Loyalty\Events\Module;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Tests\Integration\Support\OrderFixtures;

/**
 * WooCommerce › Status › Pixalink events lists rows with their payload and retry links.
 *
 * @covers \Pixalink\Loyalty\Admin\EventsListTable
 */
final class EventsListTableTest extends \WP_UnitTestCase {

	use OrderFixtures;

	public function set_up(): void {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/admin.php';
		$this->connect_store();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( EventsListTable::SCREEN );
	}

	public function tear_down(): void {
		$this->disconnect_store();
		unset( $_GET['status'] );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	public function test_the_status_tab_is_registered(): void {
		// Admin-only hooks are registered when is_admin(); the test runner is not an admin request.
		EventsListTable::register_hooks( Plugin::instance()->events() );

		$tabs = apply_filters( 'woocommerce_admin_status_tabs', array() );

		$this->assertArrayHasKey( Module::EVENTS_TAB, $tabs );
	}

	public function test_lists_events_with_payload_and_row_actions(): void {
		$order = $this->create_order();
		$order->payment_complete();
		$event = $this->events_of( $order )[0];
		Plugin::instance()->events()->repository()->mark_dead( $event->id, 12, 422, 'validation_failed (HTTP 422): bad', array() );

		$table = new EventsListTable( Plugin::instance()->events() );
		$table->prepare_items();
		ob_start();
		$table->display();
		$html = (string) ob_get_clean();

		$this->assertCount( 1, $table->items );
		$this->assertStringContainsString( $event->uuid, $html );
		$this->assertStringContainsString( 'Order paid', $html );
		$this->assertStringContainsString( 'validation_failed (HTTP 422): bad', $html );
		$this->assertStringContainsString( 'View payload', $html );
		$this->assertStringContainsString( 'pixalink_loyalty_retry_event', $html );
		$this->assertStringContainsString( '#' . $order->get_id(), $html );
	}

	public function test_status_view_filters_rows(): void {
		$order = $this->create_order();
		$order->payment_complete();

		$_GET['status'] = 'dead';
		$table          = new EventsListTable( Plugin::instance()->events() );
		$table->prepare_items();

		$this->assertCount( 0, $table->items );
	}
}
