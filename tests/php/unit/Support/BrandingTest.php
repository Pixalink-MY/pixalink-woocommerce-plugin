<?php
/**
 * Tests for Branding.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Support;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Support\Branding;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * The portal look from the heartbeat: every colour must be lower-case #rrggbb, every URL https (http only on local
 * development hosts); anything else is dropped field by field, and a server without the block means no branding.
 *
 * @covers \Pixalink\Loyalty\Support\Branding
 */
final class BrandingTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();

		Functions\when( 'wp_parse_url' )->alias( static fn ( string $url, int $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}

	/**
	 * A valid heartbeat branding block.
	 *
	 * @return array<string,mixed>
	 */
	private static function block(): array {
		return array(
			'name'              => 'Pixalink Bookstore',
			'logo_url'          => 'https://demo.pixalink.io/storage/logo.png',
			'primary_color'     => '#123962',
			'primary_color_on'  => '#ffffff',
			'primary_color_ink' => '#123962',
			'secondary_color'   => '#647aa6',
			'portal_url'        => 'https://demo.pixalink.io/l/pixalink-bookstore',
			'sign_in_url'       => 'https://demo.pixalink.io/l/pixalink-bookstore/store-sign-in/9a3c1f0e-6b1d-4c39-9f7e-0c2b5d6a7e81',
		);
	}

	public function test_a_valid_block_is_kept_whole(): void {
		$branding = Branding::from_remote_status( array( 'branding' => self::block() ) );

		$this->assertInstanceOf( Branding::class, $branding );
		$this->assertSame( 'Pixalink Bookstore', $branding->name );
		$this->assertSame( 'https://demo.pixalink.io/storage/logo.png', $branding->logo_url );
		$this->assertSame( '#123962', $branding->primary_color );
		$this->assertSame( '#ffffff', $branding->primary_color_on );
		$this->assertSame( '#123962', $branding->primary_color_ink );
		$this->assertSame( '#647aa6', $branding->secondary_color );
		$this->assertSame( 'https://demo.pixalink.io/l/pixalink-bookstore', $branding->portal_url );
		$this->assertSame( 'demo.pixalink.io', $branding->portal_host() );
		$this->assertSame( 'https://demo.pixalink.io/l/pixalink-bookstore/store-sign-in/9a3c1f0e-6b1d-4c39-9f7e-0c2b5d6a7e81', $branding->sign_in_url );
		$this->assertTrue( $branding->has_colors() );
	}

	/**
	 * Colours the contract does not allow.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function bad_colors(): array {
		return array(
			'short hex'           => array( '#ABC' ),
			'short lower hex'     => array( '#abc' ),
			'upper-case hex'      => array( '#ABCDEF' ),
			'named colour'        => array( 'red' ),
			'rgb()'               => array( 'rgb(18, 57, 98)' ),
			'no hash'             => array( '123962' ),
			'alpha hex'           => array( '#12396280' ),
			'css injection'       => array( '#123962;}body{display:none' ),
			'trailing newline'    => array( "#123962\n" ),
			'not a string'        => array( 1193314 ),
			'null'                => array( null ),
			'var() of something'  => array( 'var(--x)' ),
			'url() of something'  => array( 'url(https://evil.example/x.png)' ),
			'hex with whitespace' => array( ' #123962' ),
		);
	}

	/**
	 * @dataProvider bad_colors
	 *
	 * @param mixed $color Colour.
	 */
	public function test_a_colour_outside_lower_case_rrggbb_is_dropped( $color ): void {
		$branding = Branding::from_remote_status( array( 'branding' => array( 'primary_color' => $color ) + self::block() ) );

		$this->assertInstanceOf( Branding::class, $branding );
		$this->assertNull( $branding->primary_color );
		$this->assertSame( '#ffffff', $branding->primary_color_on, 'Other fields survive.' );
		$this->assertFalse( $branding->has_colors(), 'No brand fill without the primary colour.' );
	}

	/**
	 * URLs the plugin refuses for the logo and the portal.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function bad_urls(): array {
		return array(
			'http on a public host' => array( 'http://demo.pixalink.io/storage/logo.png' ),
			'javascript:'           => array( 'javascript:alert(1)' ),
			'data:'                 => array( 'data:image/png;base64,AAAA' ),
			'relative'              => array( '/storage/logo.png' ),
			'protocol-relative'     => array( '//demo.pixalink.io/logo.png' ),
			'quote breaking out'    => array( 'https://demo.pixalink.io/logo.png"onerror="x' ),
			'spaces'                => array( 'https://demo.pixalink.io/a logo.png' ),
			'not a string'          => array( array( 'https://demo.pixalink.io' ) ),
			'empty'                 => array( '' ),
			'trailing newline'      => array( "https://demo.pixalink.io/logo.png\n" ),
		);
	}

	/**
	 * @dataProvider bad_urls
	 *
	 * @param mixed $url URL.
	 */
	public function test_a_logo_or_portal_url_that_is_not_https_is_dropped( $url ): void {
		$branding = Branding::from_remote_status(
			array(
				'branding' => array(
					'logo_url'   => $url,
					'portal_url' => $url,
				) + self::block(),
			)
		);

		$this->assertInstanceOf( Branding::class, $branding );
		$this->assertNull( $branding->logo_url );
		$this->assertNull( $branding->portal_url );
		$this->assertNull( $branding->portal_host() );
		$this->assertNull( $branding->sign_in_url, 'Without a portal address there is no host to check the sign-in page against.' );
		$this->assertSame( '#123962', $branding->primary_color );
	}

	/**
	 * @dataProvider bad_urls
	 *
	 * @param mixed $url URL.
	 */
	public function test_a_sign_in_url_that_is_not_https_is_dropped( $url ): void {
		$branding = Branding::from_remote_status( array( 'branding' => array( 'sign_in_url' => $url ) + self::block() ) );

		$this->assertInstanceOf( Branding::class, $branding );
		$this->assertNull( $branding->sign_in_url );
		$this->assertSame( 'https://demo.pixalink.io/l/pixalink-bookstore', $branding->portal_url, 'Other fields survive.' );
	}

	/**
	 * Sign-in pages that are not on the portal's own host.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function foreign_sign_in_urls(): array {
		return array(
			'another host'           => array( 'https://evil.example/l/pixalink-bookstore/store-sign-in/x' ),
			'a sub-domain'           => array( 'https://shop.demo.pixalink.io/l/pixalink-bookstore/store-sign-in/x' ),
			'a look-alike suffix'    => array( 'https://demo.pixalink.io.evil.example/store-sign-in/x' ),
			'credentials before host' => array( 'https://demo.pixalink.io@evil.example/store-sign-in/x' ),
		);
	}

	/**
	 * @dataProvider foreign_sign_in_urls
	 *
	 * @param string $url URL.
	 */
	public function test_a_sign_in_url_off_the_portal_host_is_dropped( string $url ): void {
		$branding = Branding::from_remote_status( array( 'branding' => array( 'sign_in_url' => $url ) + self::block() ) );

		$this->assertNull( $branding?->sign_in_url );
	}

	public function test_the_sign_in_url_host_is_compared_without_case(): void {
		$branding = Branding::from_remote_status( array( 'branding' => array( 'sign_in_url' => 'https://Demo.Pixalink.IO/l/pixalink-bookstore/store-sign-in/x' ) + self::block() ) );

		$this->assertSame( 'https://Demo.Pixalink.IO/l/pixalink-bookstore/store-sign-in/x', $branding?->sign_in_url );
	}

	public function test_a_server_older_than_the_sign_in_field_means_no_sign_in_page(): void {
		$block = self::block();
		unset( $block['sign_in_url'] );

		$this->assertNull( Branding::from_remote_status( array( 'branding' => $block ) )?->sign_in_url );
	}

	public function test_http_is_accepted_on_local_development_hosts_only(): void {
		$local = Branding::from_remote_status(
			array(
				'branding' => array(
					'logo_url'   => 'http://localhost:8888/logo.svg',
					'portal_url' => 'http://explore.pixalink.test/l/demo',
				) + self::block(),
			)
		);

		$this->assertInstanceOf( Branding::class, $local );
		$this->assertSame( 'http://localhost:8888/logo.svg', $local->logo_url );
		$this->assertSame( 'explore.pixalink.test', $local->portal_host() );

		$local_sign_in = Branding::from_remote_status(
			array(
				'branding' => array(
					'portal_url'  => 'http://explore.pixalink.test/l/demo',
					'sign_in_url' => 'http://explore.pixalink.test/l/demo/store-sign-in/x',
				) + self::block(),
			)
		);
		$this->assertSame( 'http://explore.pixalink.test/l/demo/store-sign-in/x', $local_sign_in?->sign_in_url );
	}

	public function test_a_null_logo_is_allowed(): void {
		$branding = Branding::from_remote_status( array( 'branding' => array( 'logo_url' => null ) + self::block() ) );

		$this->assertInstanceOf( Branding::class, $branding );
		$this->assertNull( $branding->logo_url );
		$this->assertTrue( $branding->has_colors() );
	}

	public function test_a_blank_or_oversized_name_is_dropped(): void {
		$blank = Branding::from_remote_status( array( 'branding' => array( 'name' => '   ' ) + self::block() ) );
		$long  = Branding::from_remote_status( array( 'branding' => array( 'name' => str_repeat( 'a', 300 ) ) + self::block() ) );

		$this->assertNull( $blank?->name );
		$this->assertSame( 191, mb_strlen( (string) $long?->name ) );
	}

	public function test_an_older_server_without_the_block_means_no_branding(): void {
		$this->assertNull( Branding::from_remote_status( array( 'status' => 'active' ) ) );
		$this->assertNull( Branding::from_remote_status( array() ) );
		$this->assertNull( Branding::from_remote_status( array( 'branding' => 'navy' ) ) );
		$this->assertNull( Branding::from_remote_status( array( 'branding' => null ) ) );
	}

	public function test_active_branding_follows_the_merchant_setting_and_the_cached_heartbeat(): void {
		$options = new Options();

		$this->assertNull( Branding::active( $options ), 'No heartbeat yet.' );

		$options->set( Options::REMOTE_STATUS, array( 'branding' => self::block() ) );
		$this->assertSame( '#123962', Branding::active( $options )?->primary_color, 'On by default.' );

		$options->set( Options::PORTAL_BRANDING, 'no' );
		$this->assertNull( Branding::active( $options ), 'The merchant turned it off.' );
		$this->assertSame( 'demo.pixalink.io', Branding::remote( $options )?->portal_host(), 'The portal address is still known.' );
	}
}
