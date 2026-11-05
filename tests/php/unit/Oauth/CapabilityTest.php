<?php
/**
 * Tests for Capability.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Oauth;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Oauth\Capability;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * "Sign in with the member portal" over OAuth only when the heartbeat advertises it completely and safely: every
 * endpoint https (http on local development hosts only), the authorize page on the member portal's own host, S256
 * PKCE and both scopes the store asks for. Anything less, and the store keeps the ticket flow.
 *
 * @covers \Pixalink\Loyalty\Oauth\Capability
 */
final class CapabilityTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();

		Functions\when( 'wp_parse_url' )->alias( static fn ( string $url, int $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}

	/**
	 * A heartbeat that advertises OAuth (as connection-heartbeat.response.json's example).
	 *
	 * @return array<string,mixed>
	 */
	private static function status(): array {
		return array(
			'branding' => array(
				'name'              => 'Pixalink Bookstore',
				'logo_url'          => null,
				'primary_color'     => '#123962',
				'primary_color_on'  => '#ffffff',
				'primary_color_ink' => '#123962',
				'secondary_color'   => '#647aa6',
				'portal_url'        => 'https://demo.pixalink.io/l/pixalink-bookstore',
			),
			'oauth'    => array(
				'authorization_endpoint'           => 'https://demo.pixalink.io/l/pixalink-bookstore/oauth/authorize',
				'token_endpoint'                   => 'https://explore.pixalink.io/oauth/customer/token',
				'revocation_endpoint'              => 'https://explore.pixalink.io/oauth/customer/revoke',
				'scopes_supported'                 => array( 'profile', 'loyalty:read', 'store:link' ),
				'code_challenge_methods_supported' => array( 'S256' ),
			),
		);
	}

	public function test_a_complete_block_is_kept(): void {
		$capability = Capability::from_remote_status( self::status() );

		$this->assertInstanceOf( Capability::class, $capability );
		$this->assertSame( 'https://demo.pixalink.io/l/pixalink-bookstore/oauth/authorize', $capability->authorization_endpoint );
		$this->assertSame( 'https://explore.pixalink.io/oauth/customer/token', $capability->token_endpoint );
		$this->assertSame( 'https://explore.pixalink.io/oauth/customer/revoke', $capability->revocation_endpoint );
		$this->assertSame( 'profile store:link', Capability::SCOPE );
	}

	public function test_local_development_hosts_may_use_http(): void {
		$status                                     = self::status();
		$status['branding']['portal_url']           = 'http://explore.test/l/cafe';
		$status['oauth']['authorization_endpoint'] = 'http://explore.test/l/cafe/oauth/authorize';
		$status['oauth']['token_endpoint']         = 'http://explore.test/oauth/customer/token';
		$status['oauth']['revocation_endpoint']    = 'http://explore.test/oauth/customer/revoke';

		$this->assertInstanceOf( Capability::class, Capability::from_remote_status( $status ) );
	}

	/**
	 * Heartbeats that do not make OAuth usable.
	 *
	 * @return array<string,array{0:callable}>
	 */
	public static function unusable(): array {
		return array(
			'no oauth block'                => array( static fn ( array &$s ) => ( $s['oauth'] = null ) ),
			'oauth not an object'           => array( static fn ( array &$s ) => ( $s['oauth'] = 'yes' ) ),
			'no branding'                   => array( static fn ( array &$s ) => ( $s['branding'] = null ) ),
			'authorize on http'             => array( static fn ( array &$s ) => ( $s['oauth']['authorization_endpoint'] = 'http://demo.pixalink.io/l/pixalink-bookstore/oauth/authorize' ) ),
			'authorize off the portal host' => array( static fn ( array &$s ) => ( $s['oauth']['authorization_endpoint'] = 'https://evil.example/oauth/authorize' ) ),
			'token on http'                 => array( static fn ( array &$s ) => ( $s['oauth']['token_endpoint'] = 'http://explore.pixalink.io/oauth/customer/token' ) ),
			'token missing'                 => array(
				static function ( array &$s ): void {
					unset( $s['oauth']['token_endpoint'] );
				},
			),
			'revocation not a url'          => array( static fn ( array &$s ) => ( $s['oauth']['revocation_endpoint'] = 'javascript:alert(1)' ) ),
			'plain pkce only'               => array( static fn ( array &$s ) => ( $s['oauth']['code_challenge_methods_supported'] = array( 'plain' ) ) ),
			'no pkce methods'               => array(
				static function ( array &$s ): void {
					unset( $s['oauth']['code_challenge_methods_supported'] );
				},
			),
			'no store:link scope'           => array( static fn ( array &$s ) => ( $s['oauth']['scopes_supported'] = array( 'profile', 'loyalty:read' ) ) ),
			'no profile scope'              => array( static fn ( array &$s ) => ( $s['oauth']['scopes_supported'] = array( 'store:link' ) ) ),
			'scopes not a list'             => array( static fn ( array &$s ) => ( $s['oauth']['scopes_supported'] = 'profile store:link' ) ),
		);
	}

	/**
	 * @dataProvider unusable
	 *
	 * @param callable $change Changes the heartbeat (by reference).
	 */
	public function test_an_incomplete_or_unsafe_block_means_no_oauth( callable $change ): void {
		$status = self::status();
		$change( $status );

		$this->assertNull( Capability::from_remote_status( $status ) );
	}

	public function test_it_reads_the_cached_heartbeat(): void {
		$this->options[ Options::REMOTE_STATUS ] = array( self::status(), true );
		$this->assertSame( 'https://explore.pixalink.io/oauth/customer/token', Capability::remote( new Options() )?->token_endpoint );

		$this->options[ Options::REMOTE_STATUS ] = array( array(), true );
		$this->assertNull( Capability::remote( new Options() ), 'A server older than the field: no OAuth.' );
	}
}
