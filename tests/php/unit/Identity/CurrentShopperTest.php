<?php
/**
 * Unit tests for CurrentShopper.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Identity;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Identity\CurrentShopper;
use Pixalink\Loyalty\Identity\GuestLinkStore;
use Pixalink\Loyalty\Identity\Link;
use Pixalink\Loyalty\Identity\ShopperContextInterface;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * The context redemption holds answers for whoever the shopper is at the moment it is asked.
 *
 * @covers \Pixalink\Loyalty\Identity\CurrentShopper
 */
final class CurrentShopperTest extends TestCase {

	/**
	 * Current user id.
	 *
	 * @var int
	 */
	private int $user_id = 0;

	/**
	 * User meta: user id => key => value.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $user_meta = array();

	protected function set_up(): void {
		parent::set_up();

		$this->user_id   = 0;
		$this->user_meta = array();
		Functions\when( 'get_current_user_id' )->alias( fn (): int => $this->user_id );
		Functions\when( 'get_user_meta' )->alias( fn ( int $user_id, string $key = '', bool $single = false ) => $this->user_meta[ $user_id ][ $key ] ?? '' );
		Functions\when( 'update_user_meta' )->alias(
			function ( int $user_id, string $key, $value ): bool {
				$this->user_meta[ $user_id ][ $key ] = $value;

				return true;
			}
		);
	}

	public function test_it_follows_the_current_user_on_every_question(): void {
		$shopper = new CurrentShopper( new GuestLinkStore() );
		UserMeta::store_link( 17, new Link( '5001', '+60123456789', 'MY', '2026-10-25T02:00:00Z', Link::VIA_OTP ) );

		$this->assertSame( ShopperContextInterface::STATE_GUEST, $shopper->state() );
		$this->assertNull( $shopper->pixalink_customer_id() );

		$this->user_id = 18;
		$this->assertSame( ShopperContextInterface::STATE_UNLINKED, $shopper->state() );
		$this->assertNull( $shopper->pixalink_customer_id() );

		$this->user_id = 17;
		$this->assertSame( ShopperContextInterface::STATE_VERIFIED, $shopper->state() );
		$this->assertSame( '5001', $shopper->pixalink_customer_id() );

		unset( $this->user_meta[17][ UserMeta::PHONE_VERIFIED_AT ] );
		$this->assertSame( ShopperContextInterface::STATE_UNLINKED, $shopper->state(), 'A link removed mid-request is not remembered.' );
		$this->assertNull( $shopper->pixalink_customer_id() );
	}
}
