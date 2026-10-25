<?php
/**
 * Cart & Checkout blocks integration.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Checkout\Blocks;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry;
use Pixalink\Loyalty\Checkout\Strings;
use Pixalink\Loyalty\Redemption\CartActions;
use Pixalink\Loyalty\Redemption\Features;
use Pixalink\Loyalty\Support\Branding;
use Pixalink\Loyalty\Support\BrandingStyles;

defined( 'ABSPATH' ) || exit;

/**
 * Loads build/blocks.js in the Cart and Checkout blocks: the loyalty panel in the `ExperimentalDiscountsMeta` slot
 * (the only experimental API ADR 0001 allows) and the `coupons` checkout filter that relabels our chips.
 *
 * The bundle reads `window.wc.blocksCheckout`, `window.wc.wcSettings` and `window.wp.*`, which dependency
 * extraction cannot see, so their handles are declared here.
 */
final class BlocksIntegration implements IntegrationInterface {

	public const NAME         = 'pixalink-loyalty';
	public const SCRIPT       = 'pixalink-loyalty-blocks';
	public const STYLE        = 'pixalink-loyalty-checkout';
	public const DEPENDENCIES = array( 'wc-blocks-checkout', 'wc-settings', 'wp-plugins', 'wp-element', 'wp-data' );

	/**
	 * Kill switches.
	 *
	 * @var Features
	 */
	private Features $features;

	/**
	 * Constructor.
	 *
	 * @param Features $features Kill switches.
	 */
	public function __construct( Features $features ) {
		$this->features = $features;
	}

	/**
	 * Register the block registration hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_blocks_checkout_block_registration', array( $this, 'register_with' ), 10, 1 );
		add_action( 'woocommerce_blocks_cart_block_registration', array( $this, 'register_with' ), 10, 1 );
	}

	/**
	 * `woocommerce_blocks_{cart,checkout}_block_registration` callback.
	 *
	 * @param mixed $registry Integration registry.
	 * @return void
	 */
	public function register_with( $registry ): void {
		if ( $registry instanceof IntegrationRegistry && ! $registry->is_registered( self::NAME ) ) {
			$registry->register( $this );
		}
	}

	/**
	 * Integration name (script data is exposed as `pixalink-loyalty_data`).
	 *
	 * @return string
	 */
	public function get_name() {
		return self::NAME;
	}

	/**
	 * Register the script and its style.
	 *
	 * @return void
	 */
	public function initialize() {
		$asset   = self::asset_manifest( PIXALINK_LOYALTY_DIR . 'build/blocks.asset.php' );
		$deps    = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$version = isset( $asset['version'] ) && is_string( $asset['version'] ) ? $asset['version'] : PIXALINK_LOYALTY_VERSION;

		wp_register_script( self::SCRIPT, PIXALINK_LOYALTY_URL . 'build/blocks.js', array_values( array_unique( array_merge( $deps, self::DEPENDENCIES ) ) ), $version, true );
		wp_register_style( self::STYLE, PIXALINK_LOYALTY_URL . 'assets/css/checkout.css', array(), PIXALINK_LOYALTY_VERSION );
		wp_enqueue_style( self::STYLE );
		// Both checkouts read the same custom properties from the same handle; is-branded comes with the script data.
		if ( ! self::has_branding_rule() ) {
			BrandingStyles::attach( self::STYLE, BrandingStyles::CHECKOUT_SELECTORS, Branding::active() );
		}
	}

	/**
	 * Whether the stylesheet already carries the portal colours (the Cart and Checkout blocks both initialise us).
	 *
	 * @return bool
	 */
	private static function has_branding_rule(): bool {
		$after = wp_styles()->get_data( self::STYLE, 'after' );

		return is_array( $after ) && array() !== array_filter( $after, static fn ( $css ): bool => is_string( $css ) && str_contains( $css, '--pxl-primary' ) );
	}

	/**
	 * Front-end script handles.
	 *
	 * @return array<int,string>
	 */
	public function get_script_handles() {
		return array( self::SCRIPT );
	}

	/**
	 * Editor script handles (the panel only renders on the front end).
	 *
	 * @return array<int,string>
	 */
	public function get_editor_script_handles() {
		return array();
	}

	/**
	 * Data for `getSetting( 'pixalink-loyalty_data' )`.
	 *
	 * @return array<string,mixed>
	 */
	public function get_script_data() {
		return array(
			'namespace' => CartActions::STORE_API_NAMESPACE,
			'enabled'   => $this->features->panel_enabled(),
			'branded'   => BrandingStyles::is_branded( Branding::active() ),
			'strings'   => Strings::all(),
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
