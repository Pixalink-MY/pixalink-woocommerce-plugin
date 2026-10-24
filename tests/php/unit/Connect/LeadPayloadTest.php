<?php
/**
 * Tests for LeadPayload.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Connect;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Connect\LeadPayload;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * @covers \Pixalink\Loyalty\Connect\LeadPayload
 */
final class LeadPayloadTest extends TestCase {

	/**
	 * Whether the checkout page contains the Checkout block.
	 *
	 * @var bool
	 */
	private bool $has_checkout_block = true;

	/**
	 * Checkout page id returned by wc_get_page_id().
	 *
	 * @var int
	 */
	private int $checkout_page_id = 12;

	protected function set_up(): void {
		parent::set_up();

		$this->has_checkout_block = true;
		$this->checkout_page_id   = 12;
		$GLOBALS['wp_version']    = '7.0';

		$this->options['woocommerce_default_country']             = array( 'MY:14', true );
		$this->options['woocommerce_currency']                    = array( 'MYR', true );
		$this->options['woocommerce_custom_orders_table_enabled'] = array( 'yes', true );

		Functions\when( 'site_url' )->justReturn( 'https://shop.example.test/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Demo <b>Shop</b>' );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn ( string $text ): string => strip_tags( $text ) );
		Functions\when( 'get_locale' )->justReturn( 'ms_MY' );
		Functions\when( 'wp_timezone_string' )->justReturn( 'Asia/Kuala_Lumpur' );
		Functions\when( 'wc_get_page_id' )->alias( fn (): int => $this->checkout_page_id );
		Functions\when( 'has_block' )->alias(
			function ( string $block, int $post_id ): bool {
				$this->assertSame( 'woocommerce/checkout', $block );
				$this->assertSame( $this->checkout_page_id, $post_id );

				return $this->has_checkout_block;
			}
		);
	}

	protected function tear_down(): void {
		unset( $GLOBALS['wp_version'] );
		parent::tear_down();
	}

	public function test_payload_describes_the_store_and_the_requesting_admin(): void {
		$payload = ( new LeadPayload( '0.1.0' ) )->build( 'owner@shop.example.test', 'Siti Owner', null );

		$this->assertSame(
			array(
				'site_url'       => 'https://shop.example.test',
				'home_url'       => 'https://shop.example.test',
				'store_name'     => 'Demo Shop',
				'admin_email'    => 'owner@shop.example.test',
				'admin_name'     => 'Siti Owner',
				'plugin_version' => '0.1.0',
				'wp_version'     => '7.0',
				'wc_version'     => '0.0.0',
				'php_version'    => PHP_VERSION,
				'locale'         => 'ms_MY',
				'currency'       => 'MYR',
				'country'        => 'MY',
				'timezone'       => 'Asia/Kuala_Lumpur',
				'checkout_type'  => 'blocks',
				'hpos'           => true,
			),
			$payload
		);
		$this->assertArrayNotHasKey( 'marketing_consent', $payload, 'Consent is sent only when the admin was asked.' );
	}

	public function test_marketing_consent_is_included_when_given(): void {
		$consent = array(
			'value'       => true,
			'source'      => 'admin',
			'captured_at' => '2026-10-24T08:00:00Z',
			'text_hash'   => str_repeat( 'a', 64 ),
		);

		$payload = ( new LeadPayload( '0.1.0' ) )->build( 'owner@shop.example.test', 'Siti Owner', $consent );

		$this->assertSame( $consent, $payload['marketing_consent'] );
	}

	public function test_checkout_type_detects_blocks_classic_and_missing_pages(): void {
		$this->assertSame( LeadPayload::CHECKOUT_BLOCKS, LeadPayload::checkout_type() );

		$this->has_checkout_block = false;
		$this->assertSame( LeadPayload::CHECKOUT_CLASSIC, LeadPayload::checkout_type() );

		$this->checkout_page_id = -1;
		$this->assertSame( LeadPayload::CHECKOUT_NONE, LeadPayload::checkout_type() );
	}

	public function test_unknown_store_settings_become_null(): void {
		$this->options['woocommerce_default_country'] = array( '', true );
		$this->options['woocommerce_currency']        = array( 'ringgit', true );
		unset( $this->options['woocommerce_custom_orders_table_enabled'] );

		$payload = ( new LeadPayload( '0.1.0' ) )->build( '', '', null );

		$this->assertNull( $payload['country'] );
		$this->assertNull( $payload['currency'] );
		$this->assertFalse( $payload['hpos'] );
	}
}
