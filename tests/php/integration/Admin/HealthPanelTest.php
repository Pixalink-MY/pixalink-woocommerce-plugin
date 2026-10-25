<?php
/**
 * Integration tests for HealthPanel.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Admin;

use Pixalink\Loyalty\Admin\HealthPanel;
use Pixalink\Loyalty\Events\EventDeliverer;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\Integration\Support\OrderFixtures;

/**
 * The settings field renders queue health and nonce-protected buttons.
 *
 * @covers \Pixalink\Loyalty\Admin\HealthPanel
 */
final class HealthPanelTest extends \WP_UnitTestCase {

	use OrderFixtures;

	public function set_up(): void {
		parent::set_up();

		$this->connect_store();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$this->disconnect_store();

		parent::tear_down();
	}

	private function render(): string {
		ob_start();
		( new HealthPanel( Plugin::instance()->events() ) )->render_field(
			array(
				'type'  => HealthPanel::FIELD_TYPE,
				'title' => 'Event delivery',
			)
		);

		return (string) ob_get_clean();
	}

	public function test_renders_queue_depth_and_buttons(): void {
		$order = $this->create_order();
		$order->payment_complete();

		$html = $this->render();

		$this->assertStringContainsString( '<th scope="row" class="titledesc">Event delivery</th>', $html );
		$this->assertStringContainsString( 'Waiting to send', $html );
		$this->assertMatchesRegularExpression( '#Waiting to send</th><td>1</td>#', $html );
		$this->assertStringContainsString( 'action=' . HealthPanel::ACTION_PING, $html );
		$this->assertMatchesRegularExpression( '#action=' . HealthPanel::ACTION_RESEND . '(&amp;|&\#038;)days=30#', $html );
		$this->assertStringContainsString( '_wpnonce=', $html );
		$this->assertStringContainsString( 'Retry failed</span>', $html, 'Retry is disabled while nothing failed.' );
	}

	public function test_warns_when_delivery_is_paused(): void {
		update_option( Options::DELIVERY_PAUSED, EventDeliverer::PAUSE_AUTH_FAILED );

		$this->assertStringContainsString( 'sending is paused', $this->render() );
	}

	public function test_warns_when_not_connected(): void {
		$this->disconnect_store();

		$this->assertStringContainsString( 'not connected to Pixalink yet', $this->render() );
	}

	public function test_field_renderer_is_registered_for_admin_screens(): void {
		( new HealthPanel( Plugin::instance()->events() ) )->register();

		$this->assertNotFalse( has_action( 'woocommerce_admin_field_' . HealthPanel::FIELD_TYPE ) );
		$this->assertNotFalse( has_action( 'admin_post_' . HealthPanel::ACTION_PING ) );
	}

	public function test_flash_notice_is_shown_once(): void {
		HealthPanel::flash( 'success', 'Test ping delivered.' );
		$panel = new HealthPanel( Plugin::instance()->events() );

		ob_start();
		$panel->render_notice();
		$first = (string) ob_get_clean();
		ob_start();
		$panel->render_notice();
		$second = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $first );
		$this->assertStringContainsString( 'Test ping delivered.', $first );
		$this->assertSame( '', $second );
	}
}
