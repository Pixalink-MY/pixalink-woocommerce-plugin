<?php
/**
 * Tests for BrandingStyles.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Support;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Support\Branding;
use Pixalink\Loyalty\Support\BrandingStyles;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * The inline CSS that carries the portal colours: custom properties only, on the plugin's own wrappers only, from
 * validated colours only.
 *
 * @covers \Pixalink\Loyalty\Support\BrandingStyles
 */
final class BrandingStylesTest extends TestCase {

	private static function branding( ?string $primary = '#123962', ?string $on = '#ffffff', ?string $ink = '#123962', ?string $accent = '#647aa6' ): Branding {
		return new Branding( 'Pixalink Bookstore', null, $primary, $on, $ink, $accent, 'https://demo.pixalink.io/l/pixalink-bookstore' );
	}

	public function test_every_colour_becomes_a_custom_property(): void {
		$this->assertSame(
			array(
				'--pxl-primary'    => '#123962',
				'--pxl-on-primary' => '#ffffff',
				'--pxl-ink'        => '#123962',
				'--pxl-accent'     => '#647aa6',
			),
			BrandingStyles::variables( self::branding() )
		);
	}

	public function test_the_rule_is_scoped_to_the_plugin_wrappers(): void {
		$css = BrandingStyles::css( self::branding(), BrandingStyles::ACCOUNT_SELECTORS );

		$this->assertSame(
			'.pixalink-loyalty-account,.pixalink-loyalty-dashboard-card,.pixalink-loyalty-link{--pxl-primary:#123962;--pxl-on-primary:#ffffff;--pxl-ink:#123962;--pxl-accent:#647aa6}',
			$css
		);
		$this->assertStringNotContainsString( ':root', $css );
		$this->assertStringNotContainsString( 'body', $css );
		$this->assertSame( '.pixalink-loyalty-panel{--pxl-primary:#123962;--pxl-on-primary:#ffffff;--pxl-ink:#123962;--pxl-accent:#647aa6}', BrandingStyles::css( self::branding(), BrandingStyles::CHECKOUT_SELECTORS ) );
	}

	public function test_missing_colours_are_left_to_the_stylesheet_fallbacks(): void {
		$this->assertSame(
			'.pixalink-loyalty-panel{--pxl-primary:#123962;--pxl-on-primary:#ffffff}',
			BrandingStyles::css( self::branding( '#123962', '#ffffff', null, null ), BrandingStyles::CHECKOUT_SELECTORS )
		);
	}

	public function test_nothing_is_printed_without_a_brand_fill_and_its_text_colour(): void {
		$this->assertSame( '', BrandingStyles::css( null, BrandingStyles::ACCOUNT_SELECTORS ) );
		$this->assertSame( '', BrandingStyles::css( self::branding( null ), BrandingStyles::ACCOUNT_SELECTORS ), 'Text colour alone would be unreadable on the theme fill.' );
		$this->assertSame( '', BrandingStyles::css( self::branding( '#123962', null ), BrandingStyles::ACCOUNT_SELECTORS ) );
	}

	public function test_a_value_that_slipped_past_validation_is_still_refused(): void {
		// The constructor is public: the builder checks again rather than trusting whoever built the object.
		$css = BrandingStyles::css( self::branding( '#123962', '#ffffff', '#123962;}body{display:none', 'red' ), BrandingStyles::CHECKOUT_SELECTORS );

		$this->assertSame( '.pixalink-loyalty-panel{--pxl-primary:#123962;--pxl-on-primary:#ffffff}', $css );
	}

	public function test_attach_adds_the_rule_to_a_registered_handle_only_when_there_is_one(): void {
		$added = array();
		Functions\when( 'wp_add_inline_style' )->alias(
			static function ( string $handle, string $css ) use ( &$added ): bool {
				$added[] = array( $handle, $css );

				return true;
			}
		);

		$this->assertTrue( BrandingStyles::attach( 'pixalink-loyalty-checkout', BrandingStyles::CHECKOUT_SELECTORS, self::branding() ) );
		$this->assertFalse( BrandingStyles::attach( 'pixalink-loyalty-checkout', BrandingStyles::CHECKOUT_SELECTORS, null ) );
		$this->assertSame( array( array( 'pixalink-loyalty-checkout', '.pixalink-loyalty-panel{--pxl-primary:#123962;--pxl-on-primary:#ffffff;--pxl-ink:#123962;--pxl-accent:#647aa6}' ) ), $added );
	}

	public function test_a_tier_colour_is_returned_only_when_valid(): void {
		$this->assertSame( '--pxl-tier:#c0c0c0', BrandingStyles::tier_style( '#c0c0c0' ) );
		$this->assertSame( '', BrandingStyles::tier_style( '#C0C0C0' ) );
		$this->assertSame( '', BrandingStyles::tier_style( 'silver' ) );
		$this->assertSame( '', BrandingStyles::tier_style( null ) );
	}
}
