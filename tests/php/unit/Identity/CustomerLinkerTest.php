<?php
/**
 * Unit tests for CustomerLinker.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Identity;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Pixalink\Loyalty\Api\Resources\Customers;
use Pixalink\Loyalty\Fields\PhoneNumber;
use Pixalink\Loyalty\Identity\CustomerLinker;
use Pixalink\Loyalty\Identity\GuestLinkStore;
use Pixalink\Loyalty\Identity\Link;
use Pixalink\Loyalty\Identity\LinkException;
use Pixalink\Loyalty\Identity\PendingLinks;
use Pixalink\Loyalty\Identity\ShopperContext;
use Pixalink\Loyalty\Identity\ShopperContextInterface;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Tests\FakeServer\Responses;
use Pixalink\Loyalty\Tests\Unit\Doubles\FakePixalinkHttp;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * Lookup → create → OTP → verify against the fake server's canned responses, with every request body validated.
 *
 * @covers \Pixalink\Loyalty\Identity\CustomerLinker
 * @covers \Pixalink\Loyalty\Identity\PendingLinks
 * @covers \Pixalink\Loyalty\Identity\LinkException
 * @covers \Pixalink\Loyalty\Api\Resources\Customers
 */
final class CustomerLinkerTest extends TestCase {

	use FakePixalinkHttp;

	private const NOW = 1761360000;

	/**
	 * User meta: user id => key => value.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $user_meta = array();

	private GuestLinkStore $guests;

	protected function set_up(): void {
		parent::set_up();

		$this->fake_pixalink();
		$this->user_meta = array();
		$this->guests    = new GuestLinkStore();
		Functions\when( 'get_user_meta' )->alias( fn ( int $user_id, string $key = '', bool $single = false ) => $this->user_meta[ $user_id ][ $key ] ?? '' );
		Functions\when( 'update_user_meta' )->alias(
			function ( int $user_id, string $key, $value ): bool {
				$this->user_meta[ $user_id ][ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_user_meta' )->alias(
			function ( int $user_id, string $key ): bool {
				unset( $this->user_meta[ $user_id ][ $key ] );

				return true;
			}
		);
	}

	private function linker(): CustomerLinker {
		return new CustomerLinker( new Customers( $this->fake_client() ), new PendingLinks(), $this->guests, new Logger(), static fn (): int => self::NOW );
	}

	private function member_phone(): PhoneNumber {
		return new PhoneNumber( Responses::MEMBER_PHONE, 'MY', '123456789' );
	}

	/**
	 * Profile of user 17.
	 *
	 * @return array{email:string,first_name:string,last_name:string}
	 */
	private static function profile(): array {
		return array(
			'email'      => Responses::MEMBER_EMAIL,
			'first_name' => 'Aisyah',
			'last_name'  => 'Rahman',
		);
	}

	public function test_an_existing_member_is_found_by_phone_and_sent_a_code_without_being_created(): void {
		$shopper = ShopperContext::resolve( 17, 'session-17', $this->guests );

		$sent = $this->linker()->start( $shopper, $this->member_phone(), self::profile() );

		$this->assertSame( array( 'GET customers', 'POST customers/5001/otp' ), $this->http_paths() );
		$this->assertSame( array( 'phone' => Responses::MEMBER_PHONE ), $this->http_requests[0]['query'] );
		$this->assertSame( 'whatsapp', $sent['sent_via'] );
		$this->assertSame( 60, $sent['retry_after_seconds'] );
		$this->assertSame( '+*******6789', $sent['phone_masked'] );

		$pending = ( new PendingLinks() )->get( 'user:17' );
		$this->assertSame( Responses::MEMBER_ID, $pending['customer_id'] ?? null );
		$this->assertSame( self::NOW + 60, $pending['resend_at'] ?? null );
		$this->assertSame( PendingLinks::TTL, $this->transients[ PendingLinks::key( 'user:17' ) ][1] );

		// Nothing is linked before the code is checked.
		$this->assertSame( ShopperContextInterface::STATE_UNLINKED, ShopperContext::resolve( 17, 'session-17', $this->guests )->state() );
		$this->assertArrayNotHasKey( 17, $this->user_meta );
	}

	public function test_an_unknown_phone_creates_the_customer_with_the_account_details_then_sends_a_code(): void {
		$shopper = ShopperContext::resolve( 23, '', $this->guests );

		$this->linker()->start( $shopper, new PhoneNumber( '+60199999999', 'MY', '199999999' ), array( 'email' => 'new@example.com' ) );

		$this->assertSame( array( 'GET customers', 'POST customers', 'POST customers/6001/otp' ), $this->http_paths() );
		$this->assertSame( '23', $this->http_requests[1]['body']['external_id'] );
		$this->assertSame( 'none', $this->http_requests[1]['body']['verified_via'] );
		$this->assertSame( 'new@example.com', $this->http_requests[1]['body']['email'] );
		$this->assertSame( Responses::NEW_CUSTOMER_ID, ( new PendingLinks() )->get( 'user:23' )['customer_id'] ?? null );
	}

	public function test_a_correct_code_links_the_account_durably_and_announces_it(): void {
		$shopper = ShopperContext::resolve( 17, 'session-17', $this->guests );
		$this->linker()->start( $shopper, $this->member_phone(), self::profile() );

		Actions\expectDone( 'pixalink_loyalty_shopper_verified' )->once()->with( \Mockery::type( Link::class ), 17, 'session-17' );
		$link = $this->linker()->verify( $shopper, ' 123456 ' );

		$this->assertSame( 'POST customers/5001/otp/verify', end( $this->http_requests )['method'] . ' ' . end( $this->http_requests )['path'] );
		$this->assertSame( array( 'code' => '123456' ), end( $this->http_requests )['body'] );
		$this->assertSame( Link::VIA_OTP, $link->via );
		$this->assertSame( '2025-10-25T02:40:00Z', $link->verified_at );
		$this->assertSame( Responses::MEMBER_ID, $this->user_meta[17][ UserMeta::CUSTOMER_ID ] );
		$this->assertSame( ShopperContextInterface::STATE_VERIFIED, ShopperContext::resolve( 17, 'session-17', $this->guests )->state() );
		$this->assertNull( ( new PendingLinks() )->get( 'user:17' ), 'The pending link is used up.' );
		$this->assertNull( $this->guests->get( 'session-17' ), 'Logged-in links never live in the session store.' );
	}

	public function test_a_guest_link_lives_in_the_session_store_only(): void {
		$shopper = ShopperContext::resolve( 0, 'guest-abc', $this->guests );
		$this->linker()->start( $shopper, $this->member_phone(), array() );
		$this->linker()->verify( $shopper, Responses::OTP_CODE );

		$this->assertSame( Responses::MEMBER_ID, $this->guests->get( 'guest-abc' )?->customer_id );
		$this->assertSame( ShopperContextInterface::STATE_VERIFIED, ShopperContext::resolve( 0, 'guest-abc', $this->guests )->state() );
		$this->assertSame( array(), $this->user_meta );
		$this->assertSame( array( 'GET customers', 'POST customers/5001/otp', 'POST customers/5001/otp/verify' ), $this->http_paths() );
	}

	/**
	 * Server rejections of a code.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function wrong_codes(): array {
		return array(
			'wrong'     => array( '000000', LinkException::OTP_INVALID ),
			'expired'   => array( Responses::OTP_CODE_EXPIRED, LinkException::OTP_EXPIRED ),
			'exhausted' => array( Responses::OTP_CODE_EXHAUSTED, LinkException::OTP_ATTEMPTS_EXCEEDED ),
		);
	}

	/**
	 * @dataProvider wrong_codes
	 *
	 * @param string $code   Code typed.
	 * @param string $reason Expected LinkException code.
	 */
	public function test_a_rejected_code_links_nothing_and_says_why( string $code, string $reason ): void {
		$shopper = ShopperContext::resolve( 17, '', $this->guests );
		$this->linker()->start( $shopper, $this->member_phone(), self::profile() );

		try {
			$this->linker()->verify( $shopper, $code );
			$this->fail( 'Expected a LinkException.' );
		} catch ( LinkException $exception ) {
			$this->assertSame( $reason, $exception->reason() );
			$this->assertSame( 422, $exception->http_status() );
			$this->assertNotSame( '', $exception->user_message() );
		}

		$this->assertSame( ShopperContextInterface::STATE_UNLINKED, ShopperContext::resolve( 17, '', $this->guests )->state() );
		$this->assertNotNull( ( new PendingLinks() )->get( 'user:17' ), 'The shopper can try again or ask for a new code.' );
	}

	public function test_a_malformed_code_is_refused_without_a_call(): void {
		$shopper = ShopperContext::resolve( 17, '', $this->guests );
		$this->linker()->start( $shopper, $this->member_phone(), self::profile() );
		$calls = count( $this->http_requests );

		try {
			$this->linker()->verify( $shopper, '12ab' );
			$this->fail( 'Expected a LinkException.' );
		} catch ( LinkException $exception ) {
			$this->assertSame( LinkException::OTP_INVALID, $exception->reason() );
		}

		$this->assertCount( $calls, $this->http_requests );
	}

	public function test_verifying_or_resending_without_a_started_link_fails_cleanly(): void {
		$shopper = ShopperContext::resolve( 17, '', $this->guests );

		foreach ( array( 'verify', 'resend' ) as $operation ) {
			try {
				'verify' === $operation ? $this->linker()->verify( $shopper, '123456' ) : $this->linker()->resend( $shopper );
				$this->fail( 'Expected a LinkException for ' . $operation );
			} catch ( LinkException $exception ) {
				$this->assertSame( LinkException::NO_PENDING_LINK, $exception->reason() );
				$this->assertSame( 409, $exception->http_status() );
			}
		}

		$this->assertSame( array(), $this->http_requests );
	}

	public function test_a_resend_before_the_server_allows_it_is_refused_locally(): void {
		$shopper = ShopperContext::resolve( 17, '', $this->guests );
		$this->linker()->start( $shopper, $this->member_phone(), self::profile() );
		$calls = count( $this->http_requests );

		try {
			$this->linker()->resend( $shopper );
			$this->fail( 'Expected a LinkException.' );
		} catch ( LinkException $exception ) {
			$this->assertSame( LinkException::RESEND_TOO_SOON, $exception->reason() );
			$this->assertSame( 60, $exception->retry_after() );
			$this->assertSame( 429, $exception->http_status() );
		}
		$this->assertCount( $calls, $this->http_requests );

		$later = new CustomerLinker( new Customers( $this->fake_client() ), new PendingLinks(), $this->guests, new Logger(), static fn (): int => self::NOW + 61 );
		$this->assertSame( 'whatsapp', $later->resend( $shopper )['sent_via'] );
		$this->assertSame( 'POST customers/5001/otp', end( $this->http_requests )['method'] . ' ' . end( $this->http_requests )['path'] );
	}

	public function test_a_server_throttle_carries_retry_after(): void {
		$this->script_http( 'POST', 'customers/*/otp', 429, array( 'message' => 'Slow down.', 'code' => 'otp_throttled' ), array( 'retry-after' => '120' ) );

		try {
			$this->linker()->start( ShopperContext::resolve( 17, '', $this->guests ), $this->member_phone(), self::profile() );
			$this->fail( 'Expected a LinkException.' );
		} catch ( LinkException $exception ) {
			$this->assertSame( LinkException::THROTTLED, $exception->reason() );
			$this->assertSame( 120, $exception->retry_after() );
			$this->assertSame( 429, $exception->http_status() );
		}
	}

	public function test_an_outage_is_reported_as_unavailable(): void {
		$this->script_http( 'GET', 'customers', 503, array( 'message' => 'Down.', 'code' => 'maintenance' ) );

		try {
			$this->linker()->start( ShopperContext::resolve( 17, '', $this->guests ), $this->member_phone(), self::profile() );
			$this->fail( 'Expected a LinkException.' );
		} catch ( LinkException $exception ) {
			$this->assertSame( LinkException::UNAVAILABLE, $exception->reason() );
			$this->assertSame( 503, $exception->http_status() );
		}
	}

	public function test_a_portal_ticket_resolves_the_member_verified(): void {
		$customer = $this->linker()->redeem_ticket( Responses::SSO_TICKET_MEMBER . 'abc123', 0 );

		$this->assertSame( Responses::MEMBER_ID, $customer['id'] );
		$this->assertTrue( $customer['verified'] );
		$this->assertSame( array( 'POST customers' ), $this->http_paths() );
		$this->assertSame( 'sso_ticket', $this->http_requests[0]['body']['verified_via'] );
	}

	public function test_a_spent_or_unverified_ticket_is_refused(): void {
		try {
			$this->linker()->redeem_ticket( 'someone-elses-ticket-0001', 0 );
			$this->fail( 'Expected a LinkException.' );
		} catch ( LinkException $exception ) {
			$this->assertSame( LinkException::TICKET_INVALID, $exception->reason() );
		}

		$this->script_http( 'POST', 'customers', 200, array( 'customer' => array( 'id' => '5001', 'phone' => '+60123456789', 'verified' => false ) ) );
		try {
			$this->linker()->redeem_ticket( Responses::SSO_TICKET_MEMBER . 'abc123', 0 );
			$this->fail( 'A ticket answer that does not prove the phone must not link.' );
		} catch ( LinkException $exception ) {
			$this->assertSame( LinkException::TICKET_INVALID, $exception->reason() );
		}
	}

	public function test_malformed_tickets_never_reach_the_server(): void {
		foreach ( array( '', 'short', str_repeat( 'a', 256 ), 'has spaces in it 123', '<script>alert(1)</script>' ) as $ticket ) {
			try {
				$this->linker()->redeem_ticket( $ticket, 0 );
				$this->fail( 'Expected a LinkException for ' . $ticket );
			} catch ( LinkException $exception ) {
				$this->assertSame( LinkException::TICKET_INVALID, $exception->reason() );
			}
		}

		$this->assertSame( array(), $this->http_requests );
	}
}
