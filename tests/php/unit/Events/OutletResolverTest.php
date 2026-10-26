<?php
/**
 * Tests for OutletResolver.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Events;

use Brain\Monkey\Filters;
use Pixalink\Loyalty\Events\OutletResolver;
use Pixalink\Loyalty\Tests\FakeServer\Signature;
use Pixalink\Loyalty\Tests\Unit\Doubles\FakePixalinkHttp;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * The cached outlet id follows the key and API base it was resolved for.
 *
 * @covers \Pixalink\Loyalty\Events\OutletResolver
 */
final class OutletResolverTest extends TestCase {

	use FakePixalinkHttp;

	private const BASE = 'https://explore.pixalink.io/api/commerce/v1';

	/**
	 * The key the resolver currently "sees" (wp-config.php can change it under the plugin).
	 *
	 * @var string
	 */
	private string $api_key = Signature::FAKE_API_KEY;

	/**
	 * The API base the resolver currently "sees".
	 *
	 * @var string
	 */
	private string $api_base = self::BASE;

	protected function set_up(): void {
		parent::set_up();

		$this->fake_pixalink();
		$this->api_key  = Signature::FAKE_API_KEY;
		$this->api_base = self::BASE;
		Filters\expectApplied( 'pixalink_loyalty_outlet_id' )->zeroOrMoreTimes()->andReturn( '' );
	}

	private function resolver(): OutletResolver {
		return new OutletResolver( $this->fake_client(), fn (): string => OutletResolver::fingerprint( $this->api_key, $this->api_base ) );
	}

	private function script_outlet( string $id ): void {
		$this->script_http( 'GET', 'outlet', 200, array( 'outlet' => array( 'id' => $id ) ) );
	}

	public function test_an_unchanged_key_keeps_the_cache_so_only_the_first_event_looks_the_outlet_up(): void {
		$this->script_outlet( '11' );
		$resolver = $this->resolver();

		$this->assertSame( '11', $resolver->outlet_id() );
		$this->assertSame( '11', $resolver->outlet_id() );
		$this->assertSame( '11', $this->resolver()->outlet_id(), 'A later request reads the transient.' );

		$this->assertSame( array( 'GET outlet' ), $this->http_paths() );
		$this->assertFalse( $resolver->is_stale() );
		$this->assertStringNotContainsString( Signature::FAKE_API_KEY, (string) wp_json_encode( $this->transients[ OutletResolver::TRANSIENT ][0] ), 'The key itself is never cached.' );
	}

	public function test_a_key_changed_in_wp_config_invalidates_the_cached_outlet(): void {
		$this->script_outlet( '11' );
		$resolver = $this->resolver();
		$resolver->outlet_id();

		$this->api_key = '78|anotheroutletkeyanotheroutletkeyanot';

		$this->assertNull( $resolver->known_outlet_id(), 'Outlet 11 was resolved for the old key.' );
		$this->assertTrue( $resolver->is_stale() );

		$this->script_outlet( '78' );
		$this->assertSame( '78', $resolver->outlet_id() );
		$this->assertFalse( $resolver->is_stale() );
		$this->assertSame( array( 'GET outlet', 'GET outlet' ), $this->http_paths() );
	}

	public function test_a_changed_api_base_invalidates_the_cached_outlet(): void {
		$this->script_outlet( '11' );
		$resolver = $this->resolver();
		$resolver->outlet_id();

		$this->api_base = 'https://sd.pixalink.io/api/commerce/v1';

		$this->assertNull( $resolver->known_outlet_id() );
		$this->assertTrue( $resolver->is_stale() );
	}

	public function test_a_trailing_slash_on_the_api_base_is_the_same_base(): void {
		$this->assertSame( OutletResolver::fingerprint( 'k', self::BASE ), OutletResolver::fingerprint( 'k', self::BASE . '/' ) );
	}

	public function test_a_cache_written_before_fingerprints_is_not_trusted(): void {
		set_transient( OutletResolver::TRANSIENT, '11', DAY_IN_SECONDS );
		$resolver = $this->resolver();

		$this->assertNull( $resolver->known_outlet_id() );
		$this->assertTrue( $resolver->is_stale() );
	}

	public function test_a_heartbeat_for_another_outlet_forgets_the_cache(): void {
		$resolver = $this->resolver();
		$resolver->remember( '11' );

		$this->assertTrue( $resolver->forget_unless( '78' ) );
		$this->assertArrayNotHasKey( OutletResolver::TRANSIENT, $this->transients );
		$this->assertFalse( $resolver->is_stale(), 'Nothing cached is nothing stale.' );
	}

	public function test_a_heartbeat_for_the_same_outlet_keeps_the_cache(): void {
		$resolver = $this->resolver();
		$resolver->remember( '11' );

		$this->assertFalse( $resolver->forget_unless( '11' ) );
		$this->assertFalse( $resolver->forget_unless( '' ) );
		$this->assertSame( '11', $resolver->known_outlet_id() );
	}
}
