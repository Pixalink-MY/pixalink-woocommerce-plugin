<?php
/**
 * Classic (shortcode) checkout loyalty panel.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Checkout\Classic;

use Pixalink\Loyalty\Checkout\PanelData;
use Pixalink\Loyalty\Checkout\Strings;
use Pixalink\Loyalty\Redemption\CartActions;
use Pixalink\Loyalty\Redemption\CouponMapper;
use Pixalink\Loyalty\Redemption\Features;
use Pixalink\Loyalty\Support\Branding;
use Pixalink\Loyalty\Support\BrandingStyles;

defined( 'ABSPATH' ) || exit;

/**
 * Renders `templates/checkout/loyalty-panel.php` above the classic order review
 * (`woocommerce_checkout_before_order_review`; `woocommerce_review_order_before_payment` is skipped on AJAX
 * refreshes, ADR pitfall 11) and refreshes it with every `update_order_review` through the order review fragments.
 *
 * Every action goes through the Store API (`POST /wc/store/v1/cart/extensions`, the same callback the Checkout block
 * uses) with only the Store API `Nonce` header, then `update_checkout` refreshes the review table, whose coupon
 * rows carry our labels.
 */
final class ClassicPanel {

	public const SCRIPT   = 'pixalink-loyalty-classic-checkout';
	public const STYLE    = 'pixalink-loyalty-checkout';
	public const SELECTOR = '.pixalink-loyalty-panel';

	/**
	 * Panel data.
	 *
	 * @var PanelData
	 */
	private PanelData $panel;

	/**
	 * Kill switches.
	 *
	 * @var Features
	 */
	private Features $features;

	/**
	 * Constructor.
	 *
	 * @param PanelData $panel    Panel data.
	 * @param Features  $features Kill switches.
	 */
	public function __construct( PanelData $panel, Features $features ) {
		$this->panel    = $panel;
		$this->features = $features;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_checkout_before_order_review', array( $this, 'render' ), 10, 0 );
		add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'fragments' ), 10, 1 );
		add_filter( 'woocommerce_cart_totals_coupon_label', array( $this, 'coupon_label' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20, 0 );
	}

	/**
	 * Print the panel.
	 *
	 * @return void
	 */
	public function render(): void {
		echo $this->html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes every value.
	}

	/**
	 * `woocommerce_update_order_review_fragments` filter: re-render the panel with the recalculated cart.
	 *
	 * @param mixed $fragments Selector => HTML.
	 * @return mixed
	 */
	public function fragments( $fragments ) {
		if ( ! is_array( $fragments ) ) {
			return $fragments;
		}

		$fragments[ self::SELECTOR ] = $this->html();

		return $fragments;
	}

	/**
	 * Panel HTML ('' when the panel is switched off).
	 *
	 * @return string
	 */
	public function html(): string {
		$data = $this->panel->build();
		if ( ! $data['show'] ) {
			// An empty placeholder keeps the fragment target in the page if the panel comes back.
			return '<div class="pixalink-loyalty-panel" hidden></div>';
		}

		return (string) wc_get_template_html(
			'checkout/loyalty-panel.php',
			array(
				'data'    => $data,
				'strings' => Strings::all(),
				'branded' => BrandingStyles::is_branded( Branding::active() ),
			),
			'pixalink-loyalty/',
			PIXALINK_LOYALTY_DIR . 'templates/'
		);
	}

	/**
	 * `woocommerce_cart_totals_coupon_label` filter: "Points (1,000 pts)", "Credits (10.00 credits)",
	 * "Voucher: Welcome 10 off" on the classic cart and checkout review rows.
	 *
	 * @param mixed $label  Label.
	 * @param mixed $coupon Coupon.
	 * @return mixed
	 */
	public function coupon_label( $label, $coupon ) {
		if ( ! $coupon instanceof \WC_Coupon || ! CouponMapper::is_ours( (string) $coupon->get_code() ) ) {
			return $label;
		}

		$ours = $this->panel->label( (string) $coupon->get_code() );

		return null === $ours ? $label : esc_html( $ours );
	}

	/**
	 * Enqueue the script and style on the classic checkout page.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) || has_block( 'woocommerce/checkout' ) || ! $this->features->panel_enabled() ) {
			return;
		}

		$asset   = self::asset_manifest( PIXALINK_LOYALTY_DIR . 'build/classic-checkout.asset.php' );
		$deps    = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$version = isset( $asset['version'] ) && is_string( $asset['version'] ) ? $asset['version'] : PIXALINK_LOYALTY_VERSION;

		wp_enqueue_style( self::STYLE, PIXALINK_LOYALTY_URL . 'assets/css/checkout.css', array(), PIXALINK_LOYALTY_VERSION );
		BrandingStyles::attach( self::STYLE, BrandingStyles::CHECKOUT_SELECTORS, Branding::active() );
		wp_enqueue_script( self::SCRIPT, PIXALINK_LOYALTY_URL . 'build/classic-checkout.js', array_values( array_unique( array_merge( $deps, array( 'jquery', 'wc-checkout' ) ) ) ), $version, true );
		wp_add_inline_script( self::SCRIPT, 'window.pixalinkLoyaltyClassic = ' . wp_json_encode( self::config() ) . ';', 'before' );
	}

	/**
	 * Script configuration.
	 *
	 * @return array<string,mixed>
	 */
	public static function config(): array {
		return array(
			'endpoint'  => rest_url( 'wc/store/v1/cart/extensions' ),
			'namespace' => CartActions::STORE_API_NAMESPACE,
			// The Store API's own CSRF nonce; its authentication keeps the cookie session without X-WP-Nonce.
			'nonce'     => wp_create_nonce( 'wc_store_api' ),
			'strings'   => Strings::all(),
			'currency'  => array(
				'symbol'             => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'decimals'           => wc_get_price_decimals(),
				'decimal_separator'  => wc_get_price_decimal_separator(),
				'thousand_separator' => wc_get_price_thousand_separator(),
				'format'             => get_woocommerce_price_format(),
			),
		);
	}

	/**
	 * The `*.asset.php` manifest written by `wp-scripts build` (empty before the first build).
	 *
	 * @param string $path Absolute path.
	 * @return array<mixed>
	 */
	private static function asset_manifest( string $path ): array {
		$asset = is_readable( $path ) ? require $path : array();

		return is_array( $asset ) ? $asset : array();
	}
}
