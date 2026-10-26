<?php
/**
 * Unit tests for SummaryService.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Account;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Account\SummaryService;
use Pixalink\Loyalty\Api\Resources\Customers;
use Pixalink\Loyalty\Identity\Link;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Tests\FakeServer\Responses;
use Pixalink\Loyalty\Tests\Unit\Doubles\FakePixalinkHttp;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * Verified members get a 60 s cached, normalised summary; everyone else gets nothing and no API call.
 *
 * @covers \Pixalink\Loyalty\Account\SummaryService
 */
final class SummaryServiceTest extends TestCase {

	use FakePixalinkHttp;

	/**
	 * User meta: user id => key => value.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $user_meta = array();

	protected function set_up(): void {
		parent::set_up();

		$this->fake_pixalink();
		$this->user_meta = array();
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

	private function service(): SummaryService {
		return new SummaryService( new Customers( $this->fake_client() ), new Logger() );
	}

	private function verify_user( int $user_id, string $customer_id = Responses::MEMBER_ID ): void {
		UserMeta::store_link( $user_id, new Link( $customer_id, Responses::MEMBER_PHONE, 'MY', '2026-10-25T02:00:00Z', Link::VIA_OTP ) );
	}

	public function test_an_unverified_shopper_gets_no_summary_and_causes_no_call(): void {
		$result = $this->service()->for_user( 17 );

		$this->assertSame( SummaryService::UNVERIFIED, $result['state'] );
		$this->assertNull( $result['summary'] );
		$this->assertSame( array(), $this->http_requests );
		$this->assertSame( SummaryService::UNVERIFIED, $this->service()->for_user( 0 )['state'] );
	}

	public function test_a_verified_member_gets_the_normalised_summary_cached_for_a_minute(): void {
		$this->verify_user( 17 );

		$result = $this->service()->for_user( 17 );
		$this->assertSame( SummaryService::VERIFIED, $result['state'] );
		$summary = $result['summary'];
		$this->assertIsArray( $summary );

		$this->assertSame( 1250, $summary['points_balance'] );
		$this->assertSame( 200, $summary['points_expiring'][0]['points'] );
		$this->assertSame( 1500, $summary['credits_cents'] );
		$this->assertSame( 'Silver', $summary['tier']['name'] ?? null );
		$this->assertSame( 300, $summary['tier']['remaining'] ?? null );
		$this->assertSame( 'Gold', $summary['tier']['next_tier_name'] ?? null );
		$this->assertSame( 70, $summary['tier']['percent'] ?? null );
		$this->assertCount( 2, $summary['vouchers'] );
		$this->assertTrue( $summary['vouchers'][0]['redeemable_here'] );
		$this->assertFalse( $summary['vouchers'][1]['redeemable_here'] );
		$this->assertNull( $summary['vouchers'][1]['expires_at'] );
		$this->assertSame( 'Coffee Club', $summary['memberships'][0]['name'] );
		$this->assertSame( 120, $summary['activity'][0]['points'] );
		$this->assertSame( Responses::MEMBER_PHONE, $summary['qr'] );
		$this->assertSame( 'https://fake-pixalink.test/loyalty', $summary['portal_url'] );

		$this->assertSame( array( 'GET customers/5001' ), $this->http_paths() );
		$this->assertSame( SummaryService::TTL, $this->transients[ SummaryService::key( 17 ) ][1] );

		$this->service()->for_user( 17 );
		$this->assertCount( 1, $this->http_requests, 'Served from the cache.' );

		$this->service()->bust( 17 );
		$this->service()->for_user( 17 );
		$this->assertCount( 2, $this->http_requests, 'A bust refetches.' );
	}

	public function test_an_empty_summary_normalises_without_tier_or_credits(): void {
		$this->verify_user( 23, Responses::NEW_CUSTOMER_ID );

		$summary = $this->service()->for_user( 23 )['summary'];

		$this->assertIsArray( $summary );
		$this->assertSame( 0, $summary['points_balance'] );
		$this->assertNull( $summary['credits_cents'] );
		$this->assertNull( $summary['tier'] );
		$this->assertSame( array(), $summary['vouchers'] );
	}

	public function test_a_link_the_server_no_longer_recognises_is_dropped(): void {
		$this->verify_user( 17 );
		$this->script_http( 'GET', 'customers/*', 403, array( 'message' => 'Verify first.', 'code' => 'verification_required' ) );

		$this->assertSame( SummaryService::UNVERIFIED, $this->service()->for_user( 17 )['state'] );
		$this->assertFalse( UserMeta::is_verified( 17 ) );
		$this->assertSame( Responses::MEMBER_PHONE, $this->user_meta[17][ UserMeta::PHONE_E164 ], 'The number stays, unverified.' );
	}

	public function test_a_store_paused_by_pixalink_keeps_the_link(): void {
		$this->verify_user( 17 );
		$this->script_http( 'GET', 'customers/*', 403, array( 'message' => 'This store is paused in Pixalink.', 'code' => 'commerce_paused' ) );

		$this->assertSame( SummaryService::UNAVAILABLE, $this->service()->for_user( 17 )['state'] );
		$this->assertTrue( UserMeta::is_verified( 17 ), 'The member stays verified for when Pixalink resumes the store.' );
		$this->assertArrayNotHasKey( SummaryService::key( 17 ), $this->transients );
	}

	public function test_an_outage_is_reported_and_not_cached(): void {
		$this->verify_user( 17 );
		$this->script_http( 'GET', 'customers/*', 503, array( 'message' => 'Down.', 'code' => 'maintenance' ) );

		$this->assertSame( SummaryService::UNAVAILABLE, $this->service()->for_user( 17 )['state'] );
		$this->assertTrue( UserMeta::is_verified( 17 ), 'An outage never unlinks.' );
		$this->assertArrayNotHasKey( SummaryService::key( 17 ), $this->transients );
	}

	public function test_normalisation_tolerates_partial_or_hostile_payloads(): void {
		$summary = SummaryService::normalize(
			array(
				'points'          => array( 'balance' => '12' ),
				'credits'         => array( 'balance_cents' => -5 ),
				'tier'            => array(
					'name'     => 'Gold',
					'progress' => null,
				),
				'vouchers'        => array( 'not-a-voucher', array( 'id' => '1' ), array( 'id' => '2', 'code' => 'PXL-2', 'title' => '<b>Bold</b>', 'image_url' => 'javascript:alert(1)' ) ),
				'recent_activity' => array( array( 'description' => 'x' ) ),
				'qr'              => array(
					'type'  => 'barcode',
					'value' => '123',
				),
				'portal_url'      => 'ftp://example.test',
			)
		);

		$this->assertSame( 0, $summary['points_balance'], 'Only integers count.' );
		$this->assertNull( $summary['credits_cents'] );
		$this->assertSame( 'Gold', $summary['tier']['name'] ?? null );
		$this->assertNull( $summary['tier']['remaining'] ?? null );
		$this->assertCount( 1, $summary['vouchers'] );
		$this->assertSame( '<b>Bold</b>', $summary['vouchers'][0]['title'], 'Escaping happens in the template.' );
		$this->assertNull( $summary['vouchers'][0]['image_url'] );
		$this->assertSame( array(), $summary['activity'] );
		$this->assertNull( $summary['qr'], 'Only phone QR codes are rendered.' );
		$this->assertNull( $summary['portal_url'] );
	}
}
