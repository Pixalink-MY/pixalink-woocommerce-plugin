<?php
/**
 * Integration tests for RestController.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Identity;

use Pixalink\Loyalty\Identity\RestController;
use Pixalink\Loyalty\Identity\ShopperContextInterface;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Tests\FakeServer\Responses;
use Pixalink\Loyalty\Tests\Integration\Support\StoreFixtures;

/**
 * The OTP dialog's routes end to end against the fake server: link → verify → summary, the nonce and login gates,
 * server errors and the per-user rate limit.
 *
 * @covers \Pixalink\Loyalty\Identity\RestController
 * @covers \Pixalink\Loyalty\Identity\CustomerLinker
 * @covers \Pixalink\Loyalty\Support\RateLimiter
 */
final class RestControllerTest extends \WP_UnitTestCase {

	use StoreFixtures;

	private int $user_id = 0;

	public function set_up(): void {
		parent::set_up();

		$this->set_up_store();
		$this->user_id = $this->make_user();
		wp_set_current_user( $this->user_id );
	}

	public function tear_down(): void {
		$this->tear_down_store();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Dispatch a plugin route.
	 *
	 * @param string              $method Method.
	 * @param string              $route  Route below the namespace.
	 * @param array<string,mixed> $body   JSON body.
	 * @param bool                $nonce  Send X-WP-Nonce.
	 * @return \WP_REST_Response
	 */
	private function call( string $method, string $route, array $body = array(), bool $nonce = true ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/' . RestController::ROUTE_NAMESPACE . '/' . $route );
		$request->set_header( 'content-type', 'application/json' );
		if ( $nonce ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		if ( array() !== $body ) {
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_ensure_response( rest_do_request( $request ) );
	}

	public function test_link_then_verify_links_the_account_and_unlocks_the_summary(): void {
		$this->assertSame( 403, $this->call( 'GET', 'summary' )->get_status(), 'Nothing to see before verifying.' );

		$sent = $this->call(
			'POST',
			'link',
			array(
				'phone_country' => 'MY',
				'phone'         => '012-345 6789',
			)
		);
		$this->assertSame( 200, $sent->get_status(), (string) wp_json_encode( $sent->get_data() ) );
		$this->assertSame( 'code_sent', $sent->get_data()['status'] );
		$this->assertSame( 'whatsapp', $sent->get_data()['sent_via'] );
		$this->assertSame( '+*******6789', $sent->get_data()['phone_masked'] );
		$this->assertSame( ShopperContextInterface::STATE_UNLINKED, Plugin::instance()->shopper()->state() );

		$wrong = $this->call( 'POST', 'otp/verify', array( 'code' => '000000' ) );
		$this->assertSame( 422, $wrong->get_status() );
		$this->assertSame( 'pixalink_loyalty_otp_invalid', $wrong->get_data()['code'] );

		$verified = $this->call( 'POST', 'otp/verify', array( 'code' => Responses::OTP_CODE ) );
		$this->assertSame( 200, $verified->get_status() );
		$this->assertSame( ShopperContextInterface::STATE_VERIFIED, Plugin::instance()->shopper()->state() );
		$this->assertSame( Responses::MEMBER_ID, Plugin::instance()->shopper()->pixalink_customer_id() );
		$this->assertSame( 'otp', get_user_meta( $this->user_id, UserMeta::LINKED_VIA, true ) );
		$this->assertContains( 'customer.upserted', $this->outbox_types() );

		$summary = $this->call( 'GET', 'summary' );
		$this->assertSame( 200, $summary->get_status() );
		$this->assertSame( 1250, $summary->get_data()['summary']['points_balance'] );
	}

	public function test_an_invalid_phone_is_refused_before_any_call(): void {
		$response = $this->call(
			'POST',
			'link',
			array(
				'phone_country' => 'MY',
				'phone'         => '12',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pixalink_loyalty_invalid_phone', $response->get_data()['code'] );
	}

	public function test_resending_too_soon_is_refused_with_retry_after(): void {
		$this->call(
			'POST',
			'link',
			array(
				'phone_country' => 'MY',
				'phone'         => '0123456789',
			)
		);

		$response = $this->call( 'POST', 'otp/send' );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'pixalink_loyalty_resend_too_soon', $response->get_data()['code'] );
		$this->assertGreaterThan( 0, (int) $response->get_headers()['Retry-After'] );
	}

	public function test_routes_need_a_logged_in_user_and_the_nonce(): void {
		$this->assertSame( 403, $this->call( 'POST', 'otp/send', array(), false )->get_status(), 'Logged in, but no nonce.' );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->call( 'GET', 'summary' )->get_status() );
		$this->assertSame(
			401,
			$this->call(
				'POST',
				'link',
				array(
					'phone_country' => 'MY',
					'phone'         => '0123456789',
				)
			)->get_status()
		);
	}

	public function test_link_attempts_are_rate_limited_per_user(): void {
		for ( $attempt = 1; $attempt <= 5; $attempt++ ) {
			$this->assertSame( 400, $this->call( 'POST', 'link', array( 'phone_country' => 'MY', 'phone' => '1' ) )->get_status() ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}

		$limited = $this->call( 'POST', 'link', array( 'phone_country' => 'MY', 'phone' => '0123456789' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 429, $limited->get_status() );
		$this->assertSame( 'pixalink_loyalty_throttled', $limited->get_data()['code'] );
		$this->assertArrayHasKey( 'Retry-After', $limited->get_headers() );
	}
}
