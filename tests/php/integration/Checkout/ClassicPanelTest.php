<?php
/**
 * Integration: the classic checkout loyalty panel.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Checkout;

use Pixalink\Loyalty\Checkout\Classic\ClassicPanel;
use Pixalink\Loyalty\Redemption\Labels;
use Pixalink\Loyalty\Tests\Integration\Redemption\RedemptionTestCase;

/**
 * @covers \Pixalink\Loyalty\Checkout\Classic\ClassicPanel
 * @covers \Pixalink\Loyalty\Checkout\PanelData
 */
final class ClassicPanelTest extends RedemptionTestCase {

	public function test_hooks(): void {
		$classic = $this->module()->classic();

		$this->assertSame( 10, has_action( 'woocommerce_checkout_before_order_review', array( $classic, 'render' ) ), 'outside the AJAX-refreshed payment section (ADR pitfall 11)' );
		$this->assertSame( 10, has_filter( 'woocommerce_update_order_review_fragments', array( $classic, 'fragments' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_cart_totals_coupon_label', array( $classic, 'coupon_label' ) ) );
	}

	public function test_a_verified_shopper_sees_balances_vouchers_points_and_credits(): void {
		$this->fill_cart();
		$this->act( array( 'action' => 'refresh_quote' ) );
		$this->act(
			array(
				'action' => 'set_points',
				'points' => 1000,
			)
		);

		$html = $this->module()->classic()->html();

		$this->assertStringContainsString( 'data-testid="pixalink-loyalty-panel"', $html );
		$this->assertStringContainsString( 'You have 1,250 points · 25.00 credits', $html );
		$this->assertStringContainsString( 'Welcome 10 off', $html );
		$this->assertStringContainsString( 'data-pixalink-action="apply_voucher" data-code="pxl-welcome-88"', $html );
		$this->assertStringContainsString( 'This voucher does not apply to any product sold in this store.', $html, 'unusable vouchers say why' );
		$this->assertStringContainsString( 'max="1200" step="100" value="1000"', $html, 'the slider starts at the applied points' );
		$this->assertStringContainsString( 'data-pixalink-action="remove_points"', $html );
		$this->assertStringContainsString( 'data-value-per-point="0.01"', $html );
		$this->assertStringContainsString( 'data-pixalink-action="set_credits"', $html );
		$this->assertStringNotContainsString( '$', $html, 'credits and points are never shown as money in the panel' );
		$this->assertStringContainsString( 'data-stale="0"', $html );
	}

	public function test_guests_see_the_verify_prompt_only(): void {
		wp_set_current_user( 0 );
		$this->fresh_session();
		$this->fill_cart();

		$html = $this->module()->classic()->html();

		$this->assertStringContainsString( Labels::verify_prompt(), $html );
		$this->assertStringNotContainsString( 'data-pixalink-action', $html );
	}

	public function test_the_panel_is_refreshed_with_the_order_review_fragments(): void {
		$this->fill_cart();

		$fragments = apply_filters( 'woocommerce_update_order_review_fragments', array( '.woocommerce-checkout-review-order-table' => '<table></table>' ) );

		$this->assertArrayHasKey( ClassicPanel::SELECTOR, $fragments );
		$this->assertStringContainsString( 'data-stale="1"', $fragments[ ClassicPanel::SELECTOR ], 'no quote yet: the script refreshes it' );
	}

	public function test_a_switched_off_panel_leaves_an_empty_fragment_target(): void {
		update_option( 'pixalink_loyalty_checkout_panel_enabled', 'no' );

		$this->assertSame( '<div class="pixalink-loyalty-panel" hidden></div>', $this->module()->classic()->html() );
	}

	public function test_review_rows_carry_our_labels(): void {
		$this->fill_cart();
		$this->act( array( 'action' => 'refresh_quote' ) );
		$this->act(
			array(
				'action'       => 'set_credits',
				'amount_cents' => 1000,
			)
		);
		$this->act(
			array(
				'action' => 'apply_voucher',
				'code'   => 'PXL-TENPCT-89',
			)
		);

		$this->assertSame( 'Credits (10.00 credits)', wc_cart_totals_coupon_label( new \WC_Coupon( 'pxl-credits' ), false ) );
		$this->assertSame( 'Voucher: 10% off', wc_cart_totals_coupon_label( new \WC_Coupon( 'pxl-tenpct-89' ), false ) );
	}

	public function test_the_script_config_carries_the_store_api_nonce_and_currency_format(): void {
		$config = ClassicPanel::config();

		$this->assertSame( rest_url( 'wc/store/v1/cart/extensions' ), $config['endpoint'] );
		$this->assertSame( 'pixalink-loyalty', $config['namespace'] );
		$this->assertSame( 1, wp_verify_nonce( $config['nonce'], 'wc_store_api' ) );
		$this->assertSame( '$', $config['currency']['symbol'] );
		$this->assertSame( 2, $config['currency']['decimals'] );
	}
}
