<?php
/**
 * Integration: order-received and order-view notes.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Orders;

use Pixalink\Loyalty\Connect\Connection;
use Pixalink\Loyalty\Orders\OrderMeta;
use Pixalink\Loyalty\Orders\ThankYou;
use Pixalink\Loyalty\Tests\Integration\Redemption\RedemptionTestCase;

/**
 * @covers \Pixalink\Loyalty\Orders\ThankYou
 * @covers \Pixalink\Loyalty\Orders\OrderAdmin
 */
final class ThankYouTest extends RedemptionTestCase {

	public function test_earned_points_are_shown_once_pixalink_answered(): void {
		$order = $this->order_with_rewards();
		OrderMeta::update( $order, array( OrderMeta::POINTS_EARNED => '145' ) );

		$this->assertSame( 'You earned 145 loyalty points with this order.', $this->thank_you()->message( $order ) );
	}

	public function test_points_follow_payment_or_completion_as_the_outlet_earns(): void {
		$order = $this->order_with_rewards();

		$this->assertSame( 'Loyalty points for this order will be added to your account once your payment is confirmed.', $this->thank_you()->message( $order ) );

		$this->switch_features( true, true, true, 'completed' );
		$this->assertSame( 'Loyalty points for this order will be added to your account when your order is completed.', $this->thank_you()->message( $order ) );
	}

	public function test_no_points_are_promised_while_pixalink_has_paused_the_store(): void {
		$order = $this->order_with_rewards();
		$this->switch_features( true, true, true );
		$connection = \Pixalink\Loyalty\Plugin::instance()->connection();
		$connection->mark_connected( array(), array( 'id' => '101' ), 1, Connection::METHOD_HOSTED, null, null );
		$connection->mark_commerce_paused();

		$this->assertSame( '', $this->thank_you()->message( $order ) );

		OrderMeta::update( $order, array( OrderMeta::POINTS_EARNED => '145' ) );
		$this->assertSame( 'You earned 145 loyalty points with this order.', $this->thank_you()->message( $order ), 'Points already earned are still shown.' );
	}

	public function test_the_rewards_used_are_listed_below_the_order_details(): void {
		$order = $this->order_with_rewards();

		ob_start();
		$this->thank_you()->render_rewards( $order );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Rewards used', $html );
		$this->assertStringContainsString( 'Points (1,000 pts)', $html );
		$this->assertStringContainsString( 'Voucher: Welcome 10 off', $html );
		$this->assertStringContainsString( 'Credits (5.00 credits)', $html );
		$this->assertStringContainsString( '10.00', $html );
	}

	public function test_orders_without_rewards_show_nothing(): void {
		$order = wc_create_order();

		ob_start();
		$this->thank_you()->render_rewards( $order );

		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_the_order_meta_box_lists_the_holds(): void {
		$order = $this->order_with_rewards();
		$admin = new \Pixalink\Loyalty\Orders\OrderAdmin( new \Pixalink\Loyalty\Events\EventRepository(), \Pixalink\Loyalty\Plugin::instance()->events()->lifecycle() );

		ob_start();
		$admin->render( $order );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Held until', $html );
		$this->assertStringContainsString( 'Points (1,000 pts)', $html );
		foreach ( OrderMeta::hold_refs( $order ) as $ref ) {
			$this->assertStringContainsString( $ref, $html );
		}
	}

	/**
	 * A placed order using 1,000 points, the welcome voucher and 5.00 credits.
	 *
	 * @return \WC_Order
	 */
	private function order_with_rewards(): \WC_Order {
		$this->fill_cart();
		$this->act( array( 'action' => 'refresh_quote' ) );
		$this->act(
			array(
				'action' => 'set_points',
				'points' => 1000,
			)
		);
		$this->act(
			array(
				'action'       => 'set_credits',
				'amount_cents' => 500,
			)
		);
		$this->act(
			array(
				'action' => 'apply_voucher',
				'code'   => 'PXL-WELCOME-88',
			)
		);

		$order_id = $this->classic_create_order();
		$this->assertIsInt( $order_id, is_wp_error( $order_id ) ? $order_id->get_error_message() : '' );

		return wc_get_order( $order_id );
	}

	/**
	 * A ThankYou wired like the plugin's.
	 *
	 * @return ThankYou
	 */
	private function thank_you(): ThankYou {
		return new ThankYou( \Pixalink\Loyalty\Plugin::instance()->credentials(), \Pixalink\Loyalty\Plugin::instance()->connection() );
	}
}
