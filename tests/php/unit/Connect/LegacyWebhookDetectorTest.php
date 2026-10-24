<?php
/**
 * Tests for LegacyWebhookDetector.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Connect;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Pixalink\Loyalty\Connect\LegacyWebhookDetector;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * @covers \Pixalink\Loyalty\Connect\LegacyWebhookDetector
 */
final class LegacyWebhookDetectorTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();

		Functions\when( 'wp_parse_url' )->alias( static fn ( string $url, int $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}

	/**
	 * Delivery URLs and whether they are legacy Pixalink webhooks.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function urls(): array {
		return array(
			'production order webhook'       => array( 'https://explore.pixalink.io/woocommerce/webhooks/order-created/9a3c1f0e', true ),
			'dedicated subdomain'            => array( 'https://sd.pixalink.io/woocommerce/webhooks/customer/abc', true ),
			'api-prefixed path'              => array( 'https://explore.pixalink.io/api/woocommerce/webhooks/order', true ),
			'upper-case host'                => array( 'HTTPS://Explore.Pixalink.IO/woocommerce/webhooks/x', true ),
			'bare domain'                    => array( 'https://pixalink.io/woocommerce/webhooks/x', true ),
			'path without trailing segment'  => array( 'https://explore.pixalink.io/woocommerce/webhooks', true ),
			'other pixalink endpoint'        => array( 'https://explore.pixalink.io/api/commerce/v1/events', false ),
			'look-alike domain'              => array( 'https://pixalink.io.evil.test/woocommerce/webhooks/x', false ),
			'suffix look-alike'              => array( 'https://notpixalink.io/woocommerce/webhooks/x', false ),
			'third-party webhook'            => array( 'https://hooks.zapier.com/woocommerce/webhooks/x', false ),
			'not a url'                      => array( 'pixalink.io/woocommerce/webhooks/x', false ),
			'non-http scheme'                => array( 'ftp://explore.pixalink.io/woocommerce/webhooks/x', false ),
			'webhooks fragment only in query' => array( 'https://explore.pixalink.io/hook?to=/woocommerce/webhooks/x', false ),
		);
	}

	/**
	 * @dataProvider urls
	 *
	 * @param string $url      Delivery URL.
	 * @param bool   $expected Whether it is legacy.
	 */
	public function test_legacy_delivery_urls_are_recognised( string $url, bool $expected ): void {
		$this->assertSame( $expected, ( new LegacyWebhookDetector() )->is_legacy_url( $url ) );
	}

	public function test_the_configured_pixalink_host_and_filtered_hosts_count(): void {
		$detector = new LegacyWebhookDetector( array( 'Loyalty.Example.com' ) );

		$this->assertTrue( $detector->is_legacy_url( 'https://loyalty.example.com/woocommerce/webhooks/order' ) );

		Filters\expectApplied( 'pixalink_loyalty_legacy_webhook_hosts' )->andReturn( array( 'crm.example.org' ) );
		$this->assertTrue( $detector->is_legacy_url( 'https://crm.example.org/woocommerce/webhooks/order' ) );
	}

	public function test_nothing_is_found_without_woocommerce(): void {
		$this->assertSame( array(), ( new LegacyWebhookDetector() )->find_active() );
		$this->assertSame( 0, ( new LegacyWebhookDetector() )->pause_all() );
	}
}
