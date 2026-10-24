<?php
/**
 * Integration tests for SiteHealth.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Admin;

use Pixalink\Loyalty\Admin\SiteHealth;
use Pixalink\Loyalty\Events\EventDeliverer;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\Integration\Support\OrderFixtures;

/**
 * Site Health test statuses and the debug-information section.
 *
 * @covers \Pixalink\Loyalty\Admin\SiteHealth
 * @covers \Pixalink\Loyalty\Admin\SystemStatus
 */
final class SiteHealthTest extends \WP_UnitTestCase {

	use OrderFixtures;

	public function set_up(): void {
		parent::set_up();

		$this->connect_store();
	}

	public function tear_down(): void {
		$this->disconnect_store();

		parent::tear_down();
	}

	private function site_health(): SiteHealth {
		return new SiteHealth( Plugin::instance()->events() );
	}

	public function test_the_test_is_registered(): void {
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );

		$this->assertArrayHasKey( SiteHealth::TEST_ID, $tests['direct'] );
	}

	public function test_good_when_connected_and_nothing_is_stuck(): void {
		// A long test run would otherwise look like stalled cron.
		wp_clear_scheduled_hook( 'action_scheduler_run_queue' );
		as_unschedule_all_actions( '', array(), 'pixalink-loyalty' );

		$result = $this->site_health()->test_event_delivery();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( SiteHealth::TEST_ID, $result['test'] );
	}

	public function test_critical_when_pixalink_rejected_the_key(): void {
		update_option( Options::DELIVERY_PAUSED, EventDeliverer::PAUSE_AUTH_FAILED );

		$this->assertSame( 'critical', $this->site_health()->test_event_delivery()['status'] );
	}

	public function test_recommended_when_updates_failed_or_not_connected(): void {
		$order = $this->create_order();
		$order->payment_complete();
		$event = $this->events_of( $order )[0];
		Plugin::instance()->events()->repository()->mark_dead( $event->id, 12, 500, 'boom', array() );

		$failed = $this->site_health()->test_event_delivery();
		$this->assertSame( 'recommended', $failed['status'] );
		$this->assertStringContainsString( '1 update could not be delivered.', $failed['description'] );

		$this->disconnect_store();
		$this->assertSame( 'recommended', $this->site_health()->test_event_delivery()['status'] );
	}

	public function test_debug_information_has_no_secrets(): void {
		$info = $this->site_health()->add_debug_information( array() );

		$this->assertArrayHasKey( 'pixalink-loyalty', $info );
		$encoded = (string) wp_json_encode( $info );
		$this->assertStringContainsString( 'Waiting to send', $encoded );
		$this->assertStringNotContainsString( \Pixalink\Loyalty\Tests\FakeServer\Signature::FAKE_API_KEY, $encoded );
	}

	public function test_system_status_report_renders(): void {
		ob_start();
		( new \Pixalink\Loyalty\Admin\SystemStatus( Plugin::instance()->events() ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-export-label="Pixalink Loyalty"', $html );
		$this->assertStringContainsString( 'data-export-label="Failed"', $html );
	}
}
