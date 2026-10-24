<?php
/**
 * Unit: per-session redemption state outside the WC session blob.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Redemption;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Redemption\RedemptionState;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * @covers \Pixalink\Loyalty\Redemption\RedemptionState
 */
final class RedemptionStateTest extends TestCase {

	private const NOW = 1761300000;

	/**
	 * User meta: user id => key => value.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $user_meta = array();

	/**
	 * Current time.
	 *
	 * @var int
	 */
	private int $now = self::NOW;

	/**
	 * WC session customer id (null = no session).
	 *
	 * @var string|null
	 */
	private ?string $session = 't_guest123';

	/**
	 * Logged-in user id.
	 *
	 * @var int
	 */
	private int $user_id = 0;

	protected function set_up(): void {
		parent::set_up();

		$this->user_meta = array();
		$this->now       = self::NOW;
		$this->session   = 't_guest123';
		$this->user_id   = 0;

		Functions\when( 'wc_strtolower' )->alias( 'strtolower' );
		Functions\when( 'get_user_meta' )->alias( fn ( int $user_id, string $key ) => $this->user_meta[ $user_id ][ $key ] ?? '' );
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

	private function state(): RedemptionState {
		return new RedemptionState(
			fn (): ?string => $this->session,
			fn (): int => $this->user_id,
			fn (): int => $this->now
		);
	}

	/**
	 * A quote response.
	 *
	 * @param int $ttl ttl_seconds.
	 * @return array<string,mixed>
	 */
	private static function quote( int $ttl = 300 ): array {
		return array(
			'vouchers'     => array(
				array(
					'id'         => '88',
					'code'       => 'PXL-WELCOME-88',
					'title'      => 'Welcome 10 off',
					'eligible'   => true,
					'woo_coupon' => array( 'discount_type' => 'fixed_cart' ),
				),
				array(
					'id'         => '89',
					'code'       => 'BIRTHDAY',
					'title'      => 'Birthday treat',
					'eligible'   => true,
					'woo_coupon' => null,
				),
			),
			'points'       => array(
				'balance'         => 1250,
				'available'       => 1250,
				'value_per_point' => '0.01',
				'min'             => 100,
				'max_for_cart'    => 1200,
				'step'            => 100,
			),
			'credits'      => array(
				'balance_cents'      => 2500,
				'max_for_cart_cents' => 2000,
				'applies_as'         => 'discount',
			),
			'lines'        => array(),
			'earn_preview' => array( 'points' => 150 ),
			'ttl_seconds'  => $ttl,
		);
	}

	private function transient_key(): string {
		return RedemptionState::KEY_PREFIX . md5( 't_guest123' );
	}

	public function test_without_a_session_nothing_is_stored(): void {
		$this->session = null;
		$state         = $this->state();

		$state->update( array( 'points' => 500 ) );

		$this->assertFalse( $state->is_available() );
		$this->assertSame( 0, $state->points() );
		$this->assertFalse( $state->has_quote() );
		$this->assertSame( array(), $this->transients );
	}

	public function test_state_lives_in_its_own_transient_keyed_by_the_session_customer_id(): void {
		$this->state()->update( array( 'points' => 500 ) );

		$this->assertArrayHasKey( $this->transient_key(), $this->transients );
		$this->assertSame( 500, $this->state()->points(), 'a fresh instance (next request) reads it back' );
		$this->assertSame( array(), $this->user_meta, 'guests have no user meta mirror' );
	}

	public function test_the_quote_goes_stale_after_ttl_seconds_but_the_selection_outlives_it(): void {
		$state = $this->state();
		$state->store_quote( '5001', self::quote( 300 ), 'hash-1' );
		$state->update(
			array(
				'points'        => 1000,
				'credits_cents' => 500,
			)
		);

		$this->assertTrue( $state->has_quote() );
		$this->assertTrue( $state->is_quote_fresh() );
		$this->assertSame( self::NOW + 300, $state->get()['expires_at'] );
		$this->assertSame( RedemptionState::SELECTION_TTL, $this->transients[ $this->transient_key() ][1], 'stored for the WC session lifetime' );

		$this->now = self::NOW + 299;
		$this->assertTrue( $this->state()->is_quote_fresh() );

		$this->now = self::NOW + 300;
		$later     = $this->state();
		$this->assertFalse( $later->is_quote_fresh(), 'stale at ttl_seconds' );
		$this->assertTrue( $later->has_quote(), 'the last quote stays readable: coupons keep resolving without HTTP' );
		$this->assertSame( 1000, $later->points() );
		$this->assertSame( 500, $later->credits_cents() );
	}

	public function test_a_quote_ttl_longer_than_the_session_extends_the_storage(): void {
		$this->state()->store_quote( '5001', self::quote( 3 * 86400 ), 'hash-1' );

		$this->assertSame( 3 * 86400, $this->transients[ $this->transient_key() ][1] );
	}

	public function test_a_zero_ttl_quote_is_stale_at_once(): void {
		$state = $this->state();
		$state->store_quote( '5001', self::quote( 0 ), 'hash-1' );

		$this->assertFalse( $state->is_quote_fresh() );
	}

	public function test_logged_in_shoppers_are_mirrored_to_user_meta_and_restored_from_it(): void {
		$this->user_id = 17;
		$this->session = '17';
		$this->state()->store_quote( '5001', self::quote(), 'hash-1' );
		$this->state()->update( array( 'points' => 700 ) );

		$mirror = $this->user_meta[17][ RedemptionState::USER_META ];
		$this->assertIsArray( $mirror );
		$this->assertSame( 700, $mirror['points'] );
		$this->assertSame( self::NOW + RedemptionState::SELECTION_TTL, $mirror['stored_until'] );

		// The transient is gone (object cache flush, another device): the mirror restores it.
		$this->transients = array();
		$restored         = $this->state();
		$this->assertSame( 700, $restored->points() );
		$this->assertSame( '5001', $restored->customer_id() );
		$this->assertArrayHasKey( RedemptionState::KEY_PREFIX . md5( '17' ), $this->transients, 'transient re-seeded' );
	}

	public function test_an_expired_mirror_is_ignored(): void {
		$this->user_id = 17;
		$this->session = '17';
		$this->state()->update( array( 'points' => 700 ) );
		$this->transients = array();

		$this->now = self::NOW + RedemptionState::SELECTION_TTL + 1;

		$this->assertSame( 0, $this->state()->points() );
	}

	public function test_reset_forgets_the_transient_and_the_mirror(): void {
		$this->user_id = 17;
		$this->session = '17';
		$this->state()->update( array( 'points' => 700 ) );

		$this->state()->reset();

		$this->assertSame( array(), $this->transients );
		$this->assertArrayNotHasKey( RedemptionState::USER_META, $this->user_meta[17] );
		$this->assertSame( 0, $this->state()->points() );
	}

	public function test_clearing_the_selection_keeps_the_quote(): void {
		$state = $this->state();
		$state->store_quote( '5001', self::quote(), 'hash-1' );
		$state->update(
			array(
				'points'        => 700,
				'credits_cents' => 300,
			)
		);

		$state->clear_selection();

		$this->assertSame( 0, $state->points() );
		$this->assertSame( 0, $state->credits_cents() );
		$this->assertTrue( $state->has_quote() );
	}

	public function test_quote_accessors_normalise_the_server_data(): void {
		$state = $this->state();
		$this->assertNull( $state->points_config(), 'no quote, no points' );

		$state->store_quote( '5001', self::quote(), 'hash-1' );

		$this->assertSame(
			array(
				'balance'         => 1250,
				'available'       => 1250,
				'value_per_point' => '0.01',
				'min'             => 100,
				'max_for_cart'    => 1200,
				'step'            => 100,
			),
			$state->points_config()
		);
		$this->assertSame(
			array(
				'balance_cents'      => 2500,
				'max_for_cart_cents' => 2000,
			),
			$state->credits_config()
		);
		$this->assertSame( 'hash-1', $state->quote_hash() );
		$this->assertSame( 150, $state->earn_preview_points() );
	}

	public function test_null_points_and_credits_mean_switched_off(): void {
		$quote            = self::quote();
		$quote['points']  = null;
		$quote['credits'] = null;
		$state            = $this->state();
		$state->store_quote( '5001', $quote, 'hash-1' );

		$this->assertNull( $state->points_config() );
		$this->assertNull( $state->credits_config() );
	}

	public function test_vouchers_are_found_by_their_coupon_code_in_any_case(): void {
		$state = $this->state();
		$state->store_quote( '5001', self::quote(), 'hash-1' );

		$this->assertSame( '88', $state->voucher( 'pxl-welcome-88' )['id'] ?? null );
		$this->assertSame( '88', $state->voucher( 'PXL-WELCOME-88' )['id'] ?? null );
		$this->assertSame( '89', $state->voucher( 'pxl-birthday' )['id'] ?? null, 'PXL- prefixed when the code lacks it' );
		$this->assertSame( '89', $state->voucher( 'BIRTHDAY' )['id'] ?? null, 'the Pixalink code itself is accepted too' );
		$this->assertNull( $state->voucher( 'pxl-nope' ) );
		$this->assertSame( array( 'pxl-welcome-88', 'pxl-birthday' ), array_column( $state->vouchers(), 'coupon_code' ) );
	}

	public function test_malformed_stored_values_degrade_to_defaults(): void {
		$this->transients[ $this->transient_key() ] = array(
			array(
				'points'        => '-5',
				'credits_cents' => 'lots',
				'quote'         => 'nope',
				'customer_id'   => array(),
			),
			0,
		);

		$state = $this->state();

		$this->assertSame( 0, $state->points() );
		$this->assertSame( 0, $state->credits_cents() );
		$this->assertFalse( $state->has_quote() );
		$this->assertSame( '', $state->customer_id() );
	}
}
