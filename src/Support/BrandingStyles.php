<?php
/**
 * The portal colours as CSS custom properties.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the one inline rule that hands the portal colours to the plugin's stylesheets.
 *
 * The rule only sets custom properties, only on the plugin's own wrappers (never `:root` or `body`), and only from
 * colours that match `#rrggbb` again here, whoever built the {@see Branding}. Without a brand fill and its text colour
 * nothing is printed and the stylesheets' neutral fallbacks (taken from the theme) apply.
 *
 * Theme developers can override any of them on the same wrappers:
 *
 * - `--pxl-primary`: brand fill (member card, buttons, slider).
 * - `--pxl-on-primary`: text and icons on the brand fill.
 * - `--pxl-ink`: brand colour used as text or outline on white.
 * - `--pxl-accent`: secondary colour, used for tints only (never text).
 * - `--pxl-tier`: the member's tier colour (set per member on the tier badge).
 */
final class BrandingStyles {

	/**
	 * Wrappers of My Account › Loyalty, the dashboard card and the phone verification form.
	 */
	public const ACCOUNT_SELECTORS = array( '.pixalink-loyalty-account', '.pixalink-loyalty-dashboard-card', '.pixalink-loyalty-link' );

	/**
	 * Wrapper of the loyalty panel in both checkouts.
	 */
	public const CHECKOUT_SELECTORS = array( '.pixalink-loyalty-panel' );

	/**
	 * Custom property per Branding colour.
	 */
	private const PROPERTIES = array(
		'primary_color'     => '--pxl-primary',
		'primary_color_on'  => '--pxl-on-primary',
		'primary_color_ink' => '--pxl-ink',
		'secondary_color'   => '--pxl-accent',
	);

	/**
	 * Custom properties of a branding (only valid colours).
	 *
	 * @param Branding $branding Branding.
	 * @return array<string,string> Property => `#rrggbb`.
	 */
	public static function variables( Branding $branding ): array {
		$values = array(
			'primary_color'     => $branding->primary_color,
			'primary_color_on'  => $branding->primary_color_on,
			'primary_color_ink' => $branding->primary_color_ink,
			'secondary_color'   => $branding->secondary_color,
		);

		$variables = array();
		foreach ( self::PROPERTIES as $field => $property ) {
			$color = Branding::color( $values[ $field ] );
			if ( null !== $color ) {
				$variables[ $property ] = $color;
			}
		}

		return $variables;
	}

	/**
	 * The inline rule, or '' when there is nothing to paint.
	 *
	 * @param Branding|null $branding  Branding.
	 * @param list<string>  $selectors One of the *_SELECTORS constants.
	 * @return string
	 */
	public static function css( ?Branding $branding, array $selectors ): string {
		if ( null === $branding ) {
			return '';
		}

		$variables = self::variables( $branding );
		if ( ! isset( $variables['--pxl-primary'], $variables['--pxl-on-primary'] ) || array() === $selectors ) {
			return '';
		}

		$declarations = array();
		foreach ( $variables as $property => $color ) {
			$declarations[] = $property . ':' . $color;
		}

		return implode( ',', $selectors ) . '{' . implode( ';', $declarations ) . '}';
	}

	/**
	 * Add the rule to a registered stylesheet handle.
	 *
	 * @param string        $handle    Style handle.
	 * @param list<string>  $selectors One of the *_SELECTORS constants.
	 * @param Branding|null $branding  Branding.
	 * @return bool Whether a rule was added.
	 */
	public static function attach( string $handle, array $selectors, ?Branding $branding ): bool {
		$css = self::css( $branding, $selectors );
		if ( '' === $css ) {
			return false;
		}

		return (bool) wp_add_inline_style( $handle, $css );
	}

	/**
	 * Whether a branding paints the plugin's UI (wrappers then carry `is-branded`).
	 *
	 * @param Branding|null $branding Branding.
	 * @return bool
	 */
	public static function is_branded( ?Branding $branding ): bool {
		return null !== $branding && '' !== self::css( $branding, self::CHECKOUT_SELECTORS );
	}

	/**
	 * Inline `style` value carrying a member's tier colour ('' unless it is `#rrggbb`).
	 *
	 * @param mixed $color Tier colour from the summary.
	 * @return string
	 */
	public static function tier_style( $color ): string {
		$color = Branding::color( $color );

		return null === $color ? '' : '--pxl-tier:' . $color;
	}
}
