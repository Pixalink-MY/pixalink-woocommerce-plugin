<?php
/**
 * Unit tests for ShopperContext.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Identity;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Identity\GuestLinkStore;
use Pixalink\Loyalty\Identity\Link;
use Pixalink\Loyalty\Identity\ShopperContext;
use Pixalink\Loyalty\Identity\ShopperContextInterface;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * Guest, unlinked and verified, from user meta for logged-in shoppers and a session-keyed transient for guests.
 *
 * @covers \Pixalink\Loyalty\Identity\ShopperContext
 * @covers \Pixalink\Loyalty\Identity\GuestLinkStore
 * @covers \Pixalink\Loyalty\Identity\UserMeta
 * @covers \Pixalink\Loyalty\Identity\Link
 */
final class ShopperContextTest extends TestCase {

	/**
	 * User meta: user id => key => value.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $user_meta = array();

	protected function set_up(): void {
		parent::set_up();

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

	private function link(): Link {
		return new Link( '5001', '+60123456789', 'MY', '2026-10-25T02:00:00Z', Link::VIA_OTP );
	}

	public function test_a_guest_without_a_session_link_is_a_guest(): void {
		$shopper = ShopperContext::resolve( 0, 'guest-session-1', new GuestLinkStore() );

		$this->assertInstanceOf( ShopperContextInterface::class, $shopper );
		$this->assertSame( ShopperContextInterface::STATE_GUEST, $shopper->state() );
		$this->assertNull( $shopper->pixalink_customer_id() );
		$this->assertFalse( $shopper->is_logged_in() );
		$this->assertSame( 'session:guest-session-1', $shopper->subject() );
	}

	public function test_a_guest_who_verified_in_this_session_is_verified(): void {
		$guests = new GuestLinkStore();
		$guests->put( 'guest-session-1', $this->link() );

		$shopper = ShopperContext::resolve( 0, 'guest-session-1', $guests );

		$this->assertSame( ShopperContextInterface::STATE_VERIFIED, $shopper->state() );
		$this->assertSame( '5001', $shopper->pixalink_customer_id() );
		$this->assertSame( '+60123456789', $shopper->link()?->phone_e164 );

		// Another session does not see it, and the key never contains the raw session id.
		$this->assertSame( ShopperContextInterface::STATE_GUEST, ShopperContext::resolve( 0, 'guest-session-2', $guests )->state() );
		$this->assertSame( array( GuestLinkStore::key( 'guest-session-1' ) ), array_keys( $this->transients ) );
		$this->assertStringNotContainsString( 'guest-session-1', GuestLinkStore::key( 'guest-session-1' ) );
		$this->assertSame( GuestLinkStore::TTL, $this->transients[ GuestLinkStore::key( 'guest-session-1' ) ][1] );
	}

	public function test_a_guest_without_any_session_is_a_guest(): void {
		$guests = new GuestLinkStore();
		$guests->put( '', $this->link() );

		$this->assertSame( array(), $this->transients, 'No session, nothing to key the link by.' );
		$this->assertSame( ShopperContextInterface::STATE_GUEST, ShopperContext::resolve( 0, '', $guests )->state() );
	}

	public function test_a_logged_in_shopper_without_a_link_is_unlinked(): void {
		$shopper = ShopperContext::resolve( 17, 'session-17', new GuestLinkStore() );

		$this->assertSame( ShopperContextInterface::STATE_UNLINKED, $shopper->state() );
		$this->assertNull( $shopper->pixalink_customer_id() );
		$this->assertTrue( $shopper->is_logged_in() );
		$this->assertSame( 17, $shopper->user_id() );
		$this->assertSame( 'user:17', $shopper->subject() );
	}

	public function test_a_customer_id_without_a_verification_time_is_not_a_verified_link(): void {
		$this->user_meta[17] = array(
			UserMeta::CUSTOMER_ID => '5001',
			UserMeta::PHONE_E164  => '+60123456789',
		);

		$shopper = ShopperContext::resolve( 17, 'session-17', new GuestLinkStore() );

		$this->assertSame( ShopperContextInterface::STATE_UNLINKED, $shopper->state() );
		$this->assertNull( $shopper->pixalink_customer_id() );
	}

	public function test_a_logged_in_shopper_with_a_verified_link_is_verified(): void {
		UserMeta::store_link( 17, $this->link() );

		$shopper = ShopperContext::resolve( 17, 'session-17', new GuestLinkStore() );

		$this->assertSame( ShopperContextInterface::STATE_VERIFIED, $shopper->state() );
		$this->assertSame( '5001', $shopper->pixalink_customer_id() );
		$this->assertSame( '2026-10-25T02:00:00Z', $this->user_meta[17][ UserMeta::PHONE_VERIFIED_AT ] );
		$this->assertSame( 'otp', $this->user_meta[17][ UserMeta::LINKED_VIA ] );
		$this->assertSame( '+60123456789', $this->user_meta[17][ UserMeta::PHONE_E164 ] );
		$this->assertSame( 'MY', $this->user_meta[17][ UserMeta::PHONE_COUNTRY ] );
	}

	public function test_a_session_link_does_not_verify_a_logged_in_account(): void {
		$guests = new GuestLinkStore();
		$guests->put( 'session-17', $this->link() );

		$this->assertSame( ShopperContextInterface::STATE_UNLINKED, ShopperContext::resolve( 17, 'session-17', $guests )->state() );
	}

	public function test_clearing_a_link_keeps_the_phone_as_unverified(): void {
		UserMeta::store_link( 17, $this->link() );
		UserMeta::clear_link( 17 );

		$this->assertSame( ShopperContextInterface::STATE_UNLINKED, ShopperContext::resolve( 17, '', new GuestLinkStore() )->state() );
		$this->assertSame( '+60123456789', $this->user_meta[17][ UserMeta::PHONE_E164 ] );
		$this->assertArrayNotHasKey( UserMeta::CUSTOMER_ID, $this->user_meta[17] );
	}

	public function test_malformed_stored_links_are_ignored(): void {
		$this->transients[ GuestLinkStore::key( 'session-x' ) ] = array( array( 'customer_id' => '' ), 0 );
		$this->user_meta[18] = array(
			UserMeta::CUSTOMER_ID       => '5001',
			UserMeta::PHONE_E164        => 'not a phone',
			UserMeta::PHONE_VERIFIED_AT => '2026-10-25T02:00:00Z',
		);

		$this->assertSame( ShopperContextInterface::STATE_GUEST, ShopperContext::resolve( 0, 'session-x', new GuestLinkStore() )->state() );
		$this->assertSame( ShopperContextInterface::STATE_UNLINKED, ShopperContext::resolve( 18, '', new GuestLinkStore() )->state() );
	}
}
