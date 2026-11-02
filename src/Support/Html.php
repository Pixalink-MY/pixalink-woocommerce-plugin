<?php
/**
 * Allowed markup of the plugin's rendered templates.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The `wp_kses()` allow-list for template HTML that is printed or handed back to WordPress (shortcodes, the account
 * tab, the checkout panel, the portal sign-in button).
 *
 * The templates escape every value themselves; the `wp_kses()` pass covers what they cannot vouch for, such as a
 * theme override of a template. The list is exactly the markup the templates use: forms and the code dialog, inline
 * SVG icons, ARIA and `data-*` attributes.
 */
final class Html {

	/**
	 * Tags and attributes the templates use.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function allowed(): array {
		$global = array_fill_keys(
			array(
				'class',
				'id',
				'role',
				'hidden',
				'title',
				'style',
				'tabindex',
				'aria-controls',
				'aria-describedby',
				'aria-expanded',
				'aria-hidden',
				'aria-label',
				'aria-labelledby',
				'aria-live',
				'aria-valuemax',
				'aria-valuemin',
				'aria-valuenow',
				'data-*',
			),
			true
		);

		$with = static fn ( array $attributes ): array => $global + array_fill_keys( $attributes, true );

		$svg_paint = array( 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray' );

		return array(
			'a'        => $with( array( 'href', 'target', 'rel' ) ),
			'br'       => $global,
			'button'   => $with( array( 'type', 'name', 'value', 'disabled' ) ),
			'dialog'   => $with( array( 'open' ) ),
			'div'      => $global,
			'em'       => $global,
			'form'     => $with( array( 'method', 'action', 'novalidate' ) ),
			'h2'       => $global,
			'h3'       => $global,
			'h4'       => $global,
			'img'      => $with( array( 'src', 'alt', 'width', 'height', 'loading', 'decoding' ) ),
			'input'    => $with( array( 'type', 'name', 'value', 'placeholder', 'autocomplete', 'inputmode', 'pattern', 'maxlength', 'min', 'max', 'step', 'required', 'disabled', 'checked', 'readonly' ) ),
			'label'    => $with( array( 'for' ) ),
			'li'       => $global,
			'noscript' => array(),
			'option'   => $with( array( 'value', 'selected', 'disabled' ) ),
			'p'        => $global,
			'section'  => $global,
			'select'   => $with( array( 'name', 'required', 'disabled', 'autocomplete' ) ),
			'span'     => $global,
			'strong'   => $global,
			'time'     => $with( array( 'datetime' ) ),
			'ul'       => $global,
			'svg'      => $with( array( 'viewbox', 'width', 'height', 'focusable', 'xmlns' ) ),
			'path'     => $with( array_merge( array( 'd' ), $svg_paint ) ),
			'rect'     => $with( array_merge( array( 'x', 'y', 'width', 'height', 'rx', 'ry' ), $svg_paint ) ),
			'circle'   => $with( array_merge( array( 'cx', 'cy', 'r' ), $svg_paint ) ),
		);
	}
}
