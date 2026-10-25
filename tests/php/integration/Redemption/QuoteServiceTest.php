<?php
/**
 * Integration: quotes for the current cart.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Redemption;

use Pixalink\Loyalty\Orders\OrderMeta;

/**
 * @covers \Pixalink\Loyalty\Redemption\QuoteService
 * @covers \Pixalink\Loyalty\Redemption\CartPayload
 */
final class QuoteServiceTest extends RedemptionTestCase {

	public function test_a_quote_goes_stale_when_the_cart_changes_but_not_when_our_coupons_do(): void {
		$quotes = $this->module()->quotes();
		$this->fill_cart();
		$this->assertTrue( $quotes->is_stale(), 'no quote yet' );

		$this->assertTrue( $quotes->refresh() );
		$this->assertFalse( $quotes->is_stale() );

		$this->act(
			array(
				'action' => 'set_points',
				'points' => 1000,
			)
		);
		$this->assertFalse( $quotes->is_stale(), 'our own discount does not change what the quote priced' );

		WC()->cart->add_to_cart( $this->product_a, 1 );
		WC()->cart->calculate_totals();
		$this->assertTrue( $quotes->is_stale(), 'a new line does' );
	}

	public function test_another_customer_makes_the_quote_stale(): void {
		$quotes = $this->module()->quotes();
		$this->fill_cart();
		$quotes->refresh();

		update_user_meta( $this->shopper, 'pixalink_customer_id', '5002' );

		$this->assertTrue( $quotes->is_stale() );
	}

	public function test_shoppers_who_cannot_be_quoted_are_never_stale_and_never_quoted(): void {
		delete_user_meta( $this->shopper, 'pixalink_phone_verified_at' );
		$this->fill_cart();

		$this->assertFalse( $this->module()->quotes()->is_stale() );
		$this->assertFalse( $this->module()->quotes()->refresh( true ) );
		$this->assertSame( array(), $this->pixalink_requests( 'POST', 'quote' ) );
	}

	public function test_nothing_is_quoted_while_every_redemption_is_switched_off(): void {
		$this->switch_features( false, false, false );
		$this->fill_cart();

		$this->assertFalse( $this->module()->quotes()->refresh( true ) );
		$this->assertSame( array(), $this->pixalink_requests( 'POST', 'quote' ) );
	}

	public function test_a_failed_quote_keeps_the_last_one_and_records_why(): void {
		$quotes = $this->module()->quotes();
		$this->fill_cart();
		$quotes->refresh();

		add_filter( 'pre_http_request', array( HoldCoordinatorTest::class, 'network_down' ), 5, 3 );
		$kept = $quotes->refresh( true );
		remove_filter( 'pre_http_request', array( HoldCoordinatorTest::class, 'network_down' ), 5 );

		$this->assertTrue( $kept, 'the last quote is still usable' );
		$this->assertSame( 'network_error', $this->module()->state()->quote_error() );
		$this->assertSame( 1250, $this->module()->state()->points_config()['balance'] ?? null );
	}

	public function test_a_failed_quote_never_keeps_a_quote_priced_in_another_currency(): void {
		$quotes = $this->module()->quotes();
		$this->fill_cart();
		$this->act( array( 'action' => 'refresh_quote' ) );
		$this->act(
			array(
				'action' => 'set_points',
				'points' => 1000,
			)
		);
		$this->assertContains( 'pxl-points', WC()->cart->get_applied_coupons() );

		// A currency switcher moves the cart from USD to EUR, and the re-quote fails.
		$eur = static fn (): string => 'EUR';
		add_filter( 'woocommerce_currency', $eur );
		add_filter( 'pre_http_request', array( HoldCoordinatorTest::class, 'network_down' ), 5, 3 );
		$kept = $quotes->refresh( true );
		remove_filter( 'pre_http_request', array( HoldCoordinatorTest::class, 'network_down' ), 5 );
		remove_filter( 'woocommerce_currency', $eur );

		$this->assertFalse( $kept, 'a USD quote prices nothing in EUR' );
		$this->assertNotContains( 'pxl-points', WC()->cart->get_applied_coupons(), 'the discount built on it is taken off' );
		$this->assertSame( 0, $this->module()->state()->points() );
		$this->assertNull( $this->module()->state()->points_config() );
	}

	public function test_the_current_orders_holds_are_sent_as_held_refs(): void {
		$this->fill_cart();
		$this->act( array( 'action' => 'refresh_quote' ) );
		$this->act(
			array(
				'action' => 'set_points',
				'points' => 1000,
			)
		);
		$order_id = $this->classic_create_order();
		$this->assertIsInt( $order_id );
		$order = wc_get_order( $order_id );
		$refs  = OrderMeta::hold_refs( $order );
		$this->assertCount( 1, $refs );

		// Back from the payment page with the order still awaiting payment.
		WC()->session->set( 'order_awaiting_payment', $order_id );
		$this->module()->quotes()->refresh( true );

		$this->assertSame( $refs, $this->last_request( 'POST', 'quote' )['body']['cart']['held_refs'] );
		$this->assertSame( $refs, $this->module()->quotes()->held_refs() );

		// Released holds are no longer the shopper's.
		OrderMeta::update( $order, array( OrderMeta::REDEMPTION_STATE => OrderMeta::REDEMPTION_RELEASED ) );
		$this->assertSame( array(), $this->module()->quotes()->held_refs() );
	}

	public function test_a_requote_takes_vouchers_the_quote_no_longer_offers_off_the_cart(): void {
		$this->fill_cart();
		$this->act( array( 'action' => 'refresh_quote' ) );
		$this->act(
			array(
				'action' => 'apply_voucher',
				'code'   => 'PXL-TENPCT-89',
			)
		);

		$quote             = $this->module()->state()->quote();
		$quote['vouchers'] = array();
		$this->module()->state()->update( array( 'quote' => $quote ) );
		$this->module()->quotes()->reconcile( WC()->cart );

		$this->assertSame( array(), WC()->cart->get_applied_coupons() );
	}
}
