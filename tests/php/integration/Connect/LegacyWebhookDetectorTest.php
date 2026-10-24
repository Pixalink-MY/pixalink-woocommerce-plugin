<?php
/**
 * Integration tests for LegacyWebhookDetector.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Connect;

use Pixalink\Loyalty\Plugin;

/**
 * Real WC_Webhook records: only active Pixalink webhooks are found and paused.
 *
 * @covers \Pixalink\Loyalty\Connect\LegacyWebhookDetector
 */
final class LegacyWebhookDetectorTest extends \WP_UnitTestCase {

	/**
	 * Block any webhook ping from leaving the test.
	 *
	 * @var \Closure
	 */
	private \Closure $no_http;

	public function set_up(): void {
		parent::set_up();

		$this->no_http = static fn ( $preempt, $args, $url ) => false === strpos( (string) $url, 'fake-pixalink.test' )
			? array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			)
			: $preempt;
		add_filter( 'pre_http_request', $this->no_http, 1, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', $this->no_http, 1 );
		parent::tear_down();
	}

	private function webhook( string $name, string $url, string $status = 'active' ): \WC_Webhook {
		$webhook = new \WC_Webhook();
		$webhook->set_name( $name );
		$webhook->set_topic( 'order.created' );
		$webhook->set_delivery_url( $url );
		$webhook->set_secret( 'secret' );
		$webhook->set_status( $status );
		$webhook->set_user_id( 1 );
		$webhook->save();

		return $webhook;
	}

	public function test_active_pixalink_webhooks_are_found_and_paused(): void {
		$legacy   = $this->webhook( 'Pixalink order created', 'https://explore.pixalink.io/woocommerce/webhooks/order-created/9a3c1f0e' );
		$paused   = $this->webhook( 'Pixalink customer', 'https://explore.pixalink.io/woocommerce/webhooks/customer/9a3c1f0e', 'paused' );
		$other    = $this->webhook( 'Zapier', 'https://hooks.zapier.com/hooks/catch/1/abc' );
		$detector = Plugin::instance()->legacy_webhooks();

		$this->assertSame( array( $legacy->get_id() ), array_map( static fn ( \WC_Webhook $webhook ): int => $webhook->get_id(), $detector->find_active() ) );

		$this->assertSame( 1, $detector->pause_all() );

		$this->assertSame( 'disabled', wc_get_webhook( $legacy->get_id() )->get_status() );
		$this->assertSame( 'paused', wc_get_webhook( $paused->get_id() )->get_status(), 'Non-active webhooks are left alone.' );
		$this->assertSame( 'active', wc_get_webhook( $other->get_id() )->get_status() );
		$this->assertSame( array(), $detector->find_active() );
	}
}
