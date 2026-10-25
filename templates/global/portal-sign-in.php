<?php
/**
 * "Sign in with the member portal" at the end of WooCommerce's login forms (My Account and classic checkout).
 *
 * Override by copying to yourtheme/pixalink-loyalty/global/portal-sign-in.php. Colours come from the `--pxl-*` custom
 * properties on `.pixalink-loyalty-sign-in` (assets/css/sign-in.css); `is-branded` is present while the member
 * portal's colours are used.
 *
 * @package Pixalink\Loyalty
 * @version 0.3.0
 *
 * @var string      $url      Where the button goes (the store's start handler, never the portal itself).
 * @var string      $label    "Sign in with {portal name}".
 * @var string|null $logo_url Member portal logo (null: a member card mark instead).
 * @var bool        $branded  Whether the member portal's colours are used.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="pixalink-loyalty-sign-in<?php echo ! empty( $branded ) ? ' is-branded' : ''; ?>">
	<p class="pixalink-loyalty-sign-in__divider"><span><?php echo esc_html_x( 'or', 'between the password login and the member portal sign-in', 'pixalink-loyalty-for-woocommerce' ); ?></span></p>
	<a class="pixalink-loyalty-sign-in__button" href="<?php echo esc_url( $url ); ?>" rel="nofollow">
		<?php if ( ! empty( $logo_url ) ) : ?>
			<span class="pixalink-loyalty-sign-in__logo"><img src="<?php echo esc_url( (string) $logo_url ); ?>" alt="" height="20" decoding="async" /></span>
		<?php else : ?>
			<svg class="pixalink-loyalty-sign-in__mark" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><rect x="2.75" y="5.25" width="18.5" height="13.5" rx="2.75" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="8.25" cy="11" r="1.75" fill="currentColor"/><path d="M5.75 15.5c.55-1.3 1.4-1.95 2.5-1.95s1.95.65 2.5 1.95M13.5 10h4.5M13.5 13h3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
		<?php endif; ?>
		<span class="pixalink-loyalty-sign-in__label"><?php echo esc_html( $label ); ?></span>
	</a>
</div>
