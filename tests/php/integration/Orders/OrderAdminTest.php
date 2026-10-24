<?php
/**
 * Integration tests for OrderAdmin and ThankYou.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Orders;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Pixalink\Loyalty\Orders\OrderAdmin;
use Pixalink\Loyalty\Orders\OrderMeta;
use Pixalink\Loyalty\Orders\ThankYou;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Tests\Integration\Support\OrderFixtures;

/**
 * Order screen meta box (HPOS or posts screen), the "Resend to Pixalink" action and the order-received line.
 *
 * @covers \Pixalink\Loyalty\Orders\OrderAdmin
 * @covers \Pixalink\Loyalty\Orders\ThankYou
 */
final class OrderAdminTest extends \WP_UnitTestCase {

	use OrderFixtures;

	public function set_up(): void {
		parent::set_up();

		$this->connect_store();
	}

	public function tear_down(): void {
		$this->disconnect_store();

		parent::tear_down();
	}

	private function admin(): OrderAdmin {
		return new OrderAdmin( Plugin::instance()->events()->repository(), Plugin::instance()->events()->lifecycle() );
	}

	public function test_meta_box_targets_the_active_order_screen(): void {
		$expected = OrderUtil::custom_orders_table_usage_is_enabled() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

		$this->assertSame( $expected, OrderAdmin::screen_id() );
	}

	public function test_meta_box_shows_points_and_the_event_timeline(): void {
		$order = $this->create_order();
		$order->payment_complete();
		$this->deliver_all( $order );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		ob_start();
		$this->admin()->render( $fresh );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Points earned', $html );
		$this->assertStringContainsString( '145', $html );
		$this->assertStringContainsString( 'Order paid', $html );
		$this->assertStringContainsString( 'Delivered', $html );
	}

	public function test_meta_box_accepts_a_post_on_the_posts_screen(): void {
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$this->markTestSkipped( 'Posts storage only.' );
		}

		$order = $this->create_order();
		ob_start();
		$this->admin()->render( get_post( $order->get_id() ) );

		$this->assertStringContainsString( 'No events sent to Pixalink', (string) ob_get_clean() );
	}

	public function test_resend_order_action(): void {
		$order = $this->create_order();
		$order->payment_complete();
		$event = $this->events_of( $order )[0];
		Plugin::instance()->events()->repository()->mark_dead( $event->id, 12, 503, 'http_503', array() );

		$actions = $this->admin()->order_actions( array() );
		$this->assertArrayHasKey( OrderAdmin::RESEND_ACTION, $actions );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->admin()->resend( $fresh );

		$this->assertSame( 'retry', Plugin::instance()->events()->repository()->find( $event->id )?->status->value );
		$this->assertContains( 'Resend to Pixalink: 1 event queued.', $this->notes_of( $fresh ) );
	}

	public function test_thank_you_line(): void {
		$thank_you = new ThankYou( Plugin::instance()->credentials() );
		$order     = $this->create_order();

		$this->assertSame( '', $thank_you->message( $order ), 'No member or phone stamped: nothing to promise.' );

		$order->update_meta_data( OrderMeta::PHONE_E164, '+60123456789' );
		$order->save();
		$this->assertStringContainsString( 'once your payment is confirmed', $thank_you->message( $order ) );

		$order->update_meta_data( OrderMeta::POINTS_EARNED, '145' );
		$order->save();
		$this->assertSame( 'You earned 145 loyalty points with this order.', $thank_you->message( $order ) );
	}
}
