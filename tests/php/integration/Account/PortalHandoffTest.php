<?php
/**
 * Integration tests for PortalHandoff.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Account;

use Pixalink\Loyalty\Account\LoyaltyEndpoint;
use Pixalink\Loyalty\Account\PortalHandoff;
use Pixalink\Loyalty\Account\SummaryService;
use Pixalink\Loyalty\Api\Resources\Customers;
use Pixalink\Loyalty\Connect\Connection;
use Pixalink\Loyalty\Identity\Link;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Plugin;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\FakeServer\Responses;
use Pixalink\Loyalty\Tests\FakeServer\Server;
use Pixalink\Loyalty\Tests\Integration\Support\StoreFixtures;

/**
 * A click on a portal button: refused without a valid nonce, the login form for a logged-out visitor, the Loyalty tab
 * (and no API call) for an unverified shopper, and for a verified member a freshly minted sign-in link followed with a
 * 303, no-cache headers and no Referer, or the signed-out portal when anything about the link is off.
 *
 * @covers \Pixalink\Loyalty\Account\PortalHandoff
 * @covers \Pixalink\Loyalty\Api\Resources\Customers
 */
final class PortalHandoffTest extends \WP_UnitTestCase {

	use StoreFixtures;

	/**
	 * Header lines the handler sent.
	 *
	 * @var list<string>
	 */
	private array $headers = array();

	/**
	 * Redirects: [location, status].
	 *
	 * @var list<array{0:string,1:int}>
	 */
	private array $redirects = array();

	/**
	 * `pixalink_loyalty_portal_handoff` calls: [user id, destination, outcome].
	 *
	 * @var list<array{0:int,1:string,2:string}>
	 */
	private array $outcomes = array();

	/**
	 * Captures log entries.
	 *
	 * @var \WC_Logger&object{entries:list<array{0:string,1:string,2:array<mixed>}>}
	 */
	private \WC_Logger $logger;

	public function set_up(): void {
		parent::set_up();

		$this->set_up_store();
		$this->heartbeat();
		$_GET = array();

		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10, 2 );
		add_action( 'pixalink_loyalty_portal_handoff', array( $this, 'capture_outcome' ), 10, 3 );

		$this->logger = new class() extends \WC_Logger {
			/**
			 * Entries: [level, message, context].
			 *
			 * @var list<array{0:string,1:string,2:array<mixed>}>
			 */
			public array $entries = array();

			public function log( $level, $message, $context = array() ) {
				$this->entries[] = array( (string) $level, (string) $message, is_array( $context ) ? $context : array() );
			}
		};
		$logger       = $this->logger;
		add_filter( 'woocommerce_logging_class', static fn () => $logger );
	}

	public function tear_down(): void {
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10 );
		remove_action( 'pixalink_loyalty_portal_handoff', array( $this, 'capture_outcome' ), 10 );
		remove_all_filters( 'woocommerce_logging_class' );
		delete_option( Options::REMOTE_STATUS );
		delete_option( Options::PORTAL_BRANDING );
		$_GET = array();
		$this->tear_down_store();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * `wp_redirect` filter: record, and stop WordPress from sending the Location header in the test process.
	 *
	 * @param mixed $location Location.
	 * @param mixed $status   Status.
	 * @return false
	 */
	public function capture_redirect( $location, $status ): bool {
		$this->redirects[] = array( (string) $location, (int) $status );

		return false;
	}

	/**
	 * `pixalink_loyalty_portal_handoff` callback.
	 *
	 * @param mixed $user_id     User id.
	 * @param mixed $destination Destination.
	 * @param mixed $outcome     Outcome.
	 * @return void
	 */
	public function capture_outcome( $user_id, $destination, $outcome ): void {
		$this->outcomes[] = array( (int) $user_id, (string) $destination, (string) $outcome );
	}

	/**
	 * Cache a heartbeat (with branding unless told otherwise).
	 *
	 * @param bool $with_branding Whether the server sends branding.
	 * @param bool $credits       Whether the credits feature is on.
	 * @return void
	 */
	private function heartbeat( bool $with_branding = true, bool $credits = true ): void {
		$status                        = Responses::samples( time() )['heartbeat']['response']['body'];
		$status['features']['credits'] = $credits;
		if ( ! $with_branding ) {
			unset( $status['branding'] );
		}

		( new Connection( new Options() ) )->store_remote_status( $status );
	}

	/**
	 * The handler with captured headers and no exit.
	 *
	 * @return PortalHandoff
	 */
	private function handoff(): PortalHandoff {
		$identity = Plugin::instance()->identity();

		return new PortalHandoff(
			new Customers( Plugin::instance()->client() ),
			$identity->summaries(),
			new Options(),
			new Connection( new Options() ),
			$identity->guests(),
			Plugin::instance()->logger(),
			function ( string $line ): void {
				$this->headers[] = $line;
			},
			static function (): void {}
		);
	}

	/**
	 * Click a portal button.
	 *
	 * @param string      $destination Destination.
	 * @param string|null $nonce       Nonce (null: a valid one for the current user).
	 * @return void
	 */
	private function click( string $destination, ?string $nonce = null ): void {
		$_GET = array(
			'action'      => PortalHandoff::ACTION,
			'destination' => $destination,
			'_wpnonce'    => $nonce ?? wp_create_nonce( PortalHandoff::NONCE_ACTION ),
		);

		$this->handoff()->handle_request();
	}

	private function verified_member(): int {
		$user_id = $this->make_user();
		UserMeta::store_link( $user_id, new Link( Responses::MEMBER_ID, Responses::MEMBER_PHONE, 'MY', '2026-10-25T02:00:00Z', Link::VIA_OTP ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Queue a scripted answer on the fake server.
	 *
	 * @param int                  $status  Status.
	 * @param array<string,mixed>  $body    Body.
	 * @param array<string,string> $headers Headers.
	 * @return void
	 */
	private function script_portal_session( int $status, array $body, array $headers = array() ): void {
		$request = new \WP_REST_Request( 'POST', '/' . Server::CONTROL_NAMESPACE . '/script' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'method'  => 'POST',
					'path'    => 'customers/*/portal-session',
					'status'  => $status,
					'body'    => $body,
					'headers' => $headers,
				)
			)
		);
		Server::add_script( $request );
	}

	/**
	 * Requests the plugin made to portal-session.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function session_requests(): array {
		return array_values( array_filter( Server::recorded(), static fn ( array $request ): bool => str_ends_with( (string) $request['path'], '/portal-session' ) ) );
	}

	/**
	 * Every log line, flattened.
	 *
	 * @return string
	 */
	private function log_text(): string {
		return (string) wp_json_encode( $this->logger->entries );
	}

	public function test_the_handler_is_for_logged_in_visitors_only(): void {
		$this->assertNotFalse( has_action( 'admin_post_' . PortalHandoff::ACTION ) );
		$this->assertFalse( has_action( 'admin_post_nopriv_' . PortalHandoff::ACTION ), 'No nopriv handler.' );
	}

	public function test_button_links_carry_a_nonce_and_never_a_sign_in_link(): void {
		$this->verified_member();

		$url = PortalHandoff::url( 'rewards' );
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertStringStartsWith( admin_url( 'admin-post.php' ), $url );
		$this->assertSame( PortalHandoff::ACTION, $query['action'] ?? null );
		$this->assertSame( 'rewards', $query['destination'] ?? null );
		$this->assertNotFalse( wp_verify_nonce( (string) ( $query['_wpnonce'] ?? '' ), PortalHandoff::NONCE_ACTION ) );
		$this->assertStringContainsString( 'destination=home', PortalHandoff::url( 'elsewhere' ) );
		$this->assertSame( array(), self::session_requests(), 'Building a link never mints a ticket.' );
	}

	/**
	 * Nonces that must be refused.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function bad_nonces(): array {
		return array(
			'missing' => array( '' ),
			'wrong'   => array( 'abcdef1234' ),
		);
	}

	/**
	 * @dataProvider bad_nonces
	 *
	 * @param string $nonce Nonce.
	 */
	public function test_a_bad_or_missing_nonce_is_refused( string $nonce ): void {
		$this->verified_member();

		try {
			$this->click( 'rewards', $nonce );
			$this->fail( 'Expected wp_die().' );
		} catch ( \WPDieException $exception ) {
			$this->assertStringContainsString( 'This link has expired', $exception->getMessage() );
		}

		$this->assertSame( array(), self::session_requests() );
		$this->assertSame( array(), $this->redirects );
		$this->assertSame( array(), $this->outcomes );
	}

	public function test_a_nonce_made_for_another_user_is_refused(): void {
		wp_set_current_user( $this->make_user( 'someone@example.com' ) );
		$nonce = wp_create_nonce( PortalHandoff::NONCE_ACTION );
		$this->verified_member();

		$this->expectException( \WPDieException::class );
		$this->click( 'rewards', $nonce );
	}

	public function test_a_logged_out_visitor_goes_to_the_login_form(): void {
		$redirect = $this->handoff()->resolve( '', 'rewards' );

		$this->assertNotNull( $redirect );
		$this->assertFalse( $redirect['external'] );
		$this->assertSame( wc_get_account_endpoint_url( LoyaltyEndpoint::ENDPOINT ), $redirect['location'] );
		$this->assertSame( array(), self::session_requests() );
		$this->assertSame( array(), $this->outcomes );
	}

	public function test_an_unverified_shopper_makes_no_api_call_and_is_sent_to_verify(): void {
		$user_id = $this->make_user();
		update_user_meta( $user_id, UserMeta::CUSTOMER_ID, Responses::MEMBER_ID ); // Not verified: no verification time.
		wp_set_current_user( $user_id );

		$this->click( 'rewards' );

		$this->assertSame( array(), Server::recorded(), 'No API call at all.' );
		$this->assertSame( array( array( wc_get_account_endpoint_url( LoyaltyEndpoint::ENDPOINT ), 303 ) ), $this->redirects );
		$this->assertSame( array( array( $user_id, 'rewards', PortalHandoff::OUTCOME_VERIFICATION_REQUIRED ) ), $this->outcomes );
	}

	public function test_a_verified_member_is_redirected_to_a_fresh_sign_in_link_without_cache_or_referer(): void {
		$user_id = $this->verified_member();

		$this->click( 'rewards' );

		$requests = self::session_requests();
		$this->assertCount( 1, $requests );
		$this->assertSame( 'POST', $requests[0]['method'] );
		$this->assertSame( 'customers/' . Responses::MEMBER_ID . '/portal-session', $requests[0]['path'] );
		$this->assertSame(
			array(
				'wp_user_id'  => $user_id,
				'destination' => 'rewards',
			),
			$requests[0]['body']
		);
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) ( $requests[0]['headers']['idempotency-key'] ?? '' ), 'A fresh UUID Idempotency-Key.' );
		$this->assertSame( 201, $requests[0]['status'] );

		$this->assertCount( 1, $this->redirects );
		[ $location, $status ] = $this->redirects[0];
		$this->assertSame( 303, $status );
		$this->assertStringStartsWith( Responses::PORTAL_URL . '/handoff/', $location );
		$this->assertContains( 'Referrer-Policy: no-referrer', $this->headers );
		$this->assertContains( 'Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private', $this->headers );
		$this->assertNotEmpty( preg_grep( '/^Expires: /', $this->headers ) );
		$this->assertSame( array( array( $user_id, 'rewards', PortalHandoff::OUTCOME_REDIRECTED ) ), $this->outcomes );

		$this->assertStringNotContainsString( '/handoff/', $this->log_text(), 'The sign-in link is never logged.' );
		$this->assertStringNotContainsString( '/handoff/', (string) wp_json_encode( get_transient( SummaryService::key( $user_id ) ) ), 'Nor cached.' );
	}

	public function test_each_click_mints_a_new_ticket(): void {
		$this->verified_member();

		$this->click( 'home' );
		$this->click( 'home' );

		$requests = self::session_requests();
		$this->assertCount( 2, $requests );
		$this->assertNotSame( $requests[0]['headers']['idempotency-key'], $requests[1]['headers']['idempotency-key'] );
		$this->assertNotSame( $this->redirects[0][0], $this->redirects[1][0] );
	}

	public function test_credits_open_only_while_the_credits_feature_is_on(): void {
		$this->verified_member();

		$this->click( 'credits' );
		$this->heartbeat( true, false );
		$this->click( 'credits' );
		$this->click( 'nonsense' );

		$this->assertSame( array( 'credits', 'home', 'home' ), array_map( static fn ( array $request ): string => (string) $request['body']['destination'], self::session_requests() ) );
	}

	public function test_a_link_on_another_host_falls_back_to_the_signed_out_portal(): void {
		$user_id = $this->verified_member();
		$this->script_portal_session(
			201,
			array(
				'url'        => 'https://evil.example/l/fake-cafe/handoff/abc123',
				'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 60 ),
			)
		);

		$this->click( 'rewards' );

		$this->assertSame( array( array( Responses::PORTAL_URL, 303 ) ), $this->redirects );
		$this->assertContains( 'Referrer-Policy: no-referrer', $this->headers );
		$this->assertSame( array( array( $user_id, 'rewards', PortalHandoff::OUTCOME_FALLBACK ) ), $this->outcomes );
		$this->assertStringContainsString( 'untrusted_link', $this->log_text() );
		$this->assertStringNotContainsString( 'abc123', $this->log_text() );
		$this->assertSame( 'warning', $this->logger->entries[ count( $this->logger->entries ) - 1 ][0] );
	}

	public function test_a_plain_http_link_on_the_portal_host_falls_back(): void {
		$this->verified_member();
		$this->script_portal_session(
			201,
			array(
				'url'        => 'http://' . Responses::PORTAL_HOST . '/l/fake-cafe/handoff/abc123',
				'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 60 ),
			)
		);

		$this->click( 'home' );

		$this->assertSame( array( array( Responses::PORTAL_URL, 303 ) ), $this->redirects );
		$this->assertSame( PortalHandoff::OUTCOME_FALLBACK, $this->outcomes[0][2] );
	}

	/**
	 * API failures that fall back to the signed-out portal.
	 *
	 * @return array<string,array{0:int,1:string}>
	 */
	public static function api_failures(): array {
		return array(
			'not found'    => array( 404, 'not_found' ),
			'rate limited' => array( 429, 'rate_limited' ),
			'server error' => array( 503, 'maintenance' ),
			'other 403'    => array( 403, 'commerce_not_enabled' ),
		);
	}

	/**
	 * @dataProvider api_failures
	 *
	 * @param int    $status Status.
	 * @param string $code   Error code.
	 */
	public function test_an_api_error_falls_back_to_the_signed_out_portal( int $status, string $code ): void {
		$user_id = $this->verified_member();
		$this->script_portal_session(
			$status,
			array(
				'message' => 'No.',
				'code'    => $code,
			),
			429 === $status ? array( 'Retry-After' => '30' ) : array()
		);

		$this->click( 'points' );

		$this->assertSame( array( array( Responses::PORTAL_URL, 303 ) ), $this->redirects );
		$this->assertSame( array( array( $user_id, 'points', PortalHandoff::OUTCOME_FALLBACK ) ), $this->outcomes );
		$this->assertStringContainsString( $code, $this->log_text() );
		$this->assertTrue( UserMeta::is_verified( $user_id ), 'Only verification_required drops the link.' );
	}

	public function test_a_timeout_falls_back_to_the_signed_out_portal(): void {
		$user_id = $this->verified_member();
		$fail    = static fn ( $preempt, $args, $url ) => str_contains( (string) $url, '/portal-session' ) ? new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) : $preempt;
		add_filter( 'pre_http_request', $fail, 1, 3 );

		$this->click( 'home' );

		remove_filter( 'pre_http_request', $fail, 1 );
		$this->assertSame( array( array( Responses::PORTAL_URL, 303 ) ), $this->redirects );
		$this->assertSame( array( array( $user_id, 'home', PortalHandoff::OUTCOME_FALLBACK ) ), $this->outcomes );
	}

	public function test_verification_required_sends_the_member_back_to_verify_with_a_notice(): void {
		$user_id = $this->verified_member();
		$this->script_portal_session(
			403,
			array(
				'message' => 'Verify the phone number first.',
				'code'    => 'verification_required',
			)
		);

		$this->click( 'rewards' );

		$this->assertSame( array( array( add_query_arg( PortalHandoff::NOTICE_PARAM, PortalHandoff::NOTICE_VERIFY_AGAIN, wc_get_account_endpoint_url( LoyaltyEndpoint::ENDPOINT ) ), 303 ) ), $this->redirects );
		$this->assertSame( array( array( $user_id, 'rewards', PortalHandoff::OUTCOME_VERIFICATION_REQUIRED ) ), $this->outcomes );
		$this->assertFalse( UserMeta::is_verified( $user_id ), 'The link Pixalink refused is dropped, so the tab offers the code again.' );

		$_GET[ PortalHandoff::NOTICE_PARAM ] = PortalHandoff::NOTICE_VERIFY_AGAIN;
		ob_start();
		do_action( 'woocommerce_account_' . LoyaltyEndpoint::ENDPOINT . '_endpoint' );
		$tab = (string) ob_get_clean();

		$this->assertStringContainsString( 'Please verify your phone number again to open the member portal.', $tab );
		$this->assertStringContainsString( 'data-pixalink-link-form', $tab );
	}

	public function test_without_branding_the_summary_portal_host_is_trusted(): void {
		$this->heartbeat( false );
		$this->verified_member();

		$this->click( 'home' );

		$this->assertStringStartsWith( 'https://' . Responses::PORTAL_HOST . '/', $this->redirects[0][0] );
		$this->assertSame( PortalHandoff::OUTCOME_REDIRECTED, $this->outcomes[0][2] );
	}

	public function test_turning_the_portal_look_off_keeps_the_signed_in_link(): void {
		update_option( Options::PORTAL_BRANDING, 'no' );
		$this->verified_member();

		$this->click( 'rewards' );

		$this->assertSame( PortalHandoff::OUTCOME_REDIRECTED, $this->outcomes[0][2] );
	}
}
