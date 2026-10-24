<?php
/**
 * Tests for PkceState.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Connect;

use Pixalink\Loyalty\Connect\PkceState;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * @covers \Pixalink\Loyalty\Connect\PkceState
 */
final class PkceStateTest extends TestCase {

	private const NOW = 1761300000;

	/**
	 * Local time seen by the store under test.
	 *
	 * @var int
	 */
	private int $now = self::NOW;

	private function pkce(): PkceState {
		return new PkceState( fn (): int => $this->now );
	}

	public function test_challenge_matches_the_rfc_7636_appendix_b_vector(): void {
		$this->assertSame(
			'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
			PkceState::challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' )
		);
	}

	public function test_begin_stores_a_fifteen_minute_attempt_per_user(): void {
		$attempt = $this->pkce()->begin( 7, array( 'intent' => 'connect' ) );

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $attempt['verifier'], 'RFC 7636 verifier: 43+ unreserved characters.' );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $attempt['state'] );
		$this->assertNotSame( $attempt['state'], $attempt['verifier'] );
		$this->assertSame( PkceState::challenge( $attempt['verifier'] ), $attempt['challenge'] );
		$this->assertSame( self::NOW + 900, $attempt['exp'] );

		[ $stored, $ttl ] = $this->transients['pixalink_loyalty_connect_7'];
		$this->assertSame( 900, $ttl );
		$this->assertSame( $attempt['state'], $stored['state'] );
		$this->assertSame( $attempt['verifier'], $stored['verifier'] );
		$this->assertSame( array( 'intent' => 'connect' ), $stored['context'] );
	}

	public function test_consume_returns_the_verifier_and_context_exactly_once(): void {
		$pkce    = $this->pkce();
		$attempt = $pkce->begin( 7, array( 'pause_legacy' => true ) );

		$this->assertSame(
			array(
				'verifier' => $attempt['verifier'],
				'context'  => array( 'pause_legacy' => true ),
			),
			$pkce->consume( 7, $attempt['state'] )
		);
		$this->assertNull( $pkce->consume( 7, $attempt['state'] ), 'A state can only be used once.' );
	}

	public function test_a_mismatched_state_is_rejected_without_cancelling_the_attempt(): void {
		$pkce    = $this->pkce();
		$attempt = $pkce->begin( 7 );

		$this->assertNull( $pkce->consume( 7, 'forged-state' ) );
		$this->assertNull( $pkce->consume( 7, '' ) );
		$this->assertTrue( $pkce->has_pending( 7 ) );
		$this->assertNotNull( $pkce->consume( 7, $attempt['state'] ) );
	}

	public function test_an_expired_attempt_is_rejected_and_removed(): void {
		$pkce    = $this->pkce();
		$attempt = $pkce->begin( 7 );

		$this->now = self::NOW + 901;

		$this->assertFalse( $pkce->has_pending( 7 ) );
		$this->assertNull( $pkce->consume( 7, $attempt['state'] ) );
		$this->assertArrayNotHasKey( 'pixalink_loyalty_connect_7', $this->transients );
	}

	public function test_attempts_are_bound_to_the_user_who_started_them(): void {
		$pkce    = $this->pkce();
		$attempt = $pkce->begin( 7 );

		$this->assertNull( $pkce->consume( 8, $attempt['state'] ) );
		$this->assertNotNull( $pkce->consume( 7, $attempt['state'] ) );
	}

	public function test_a_new_attempt_replaces_the_previous_one(): void {
		$pkce  = $this->pkce();
		$first = $pkce->begin( 7 );
		$pkce->begin( 7 );

		$this->assertNull( $pkce->consume( 7, $first['state'] ) );
	}
}
