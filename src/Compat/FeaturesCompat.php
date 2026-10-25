<?php
/**
 * WooCommerce feature compatibility declarations.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Declares compatibility with High-Performance Order Storage and the Cart & Checkout blocks.
 *
 * Hooked from the main plugin file on `before_woocommerce_init` (the only point where WooCommerce accepts
 * declarations). All order access goes through WC CRUD (`wc_get_order()`, `$order->get_meta()`), never post meta.
 */
final class FeaturesCompat {

	/**
	 * Features this plugin is compatible with.
	 */
	public const FEATURES = array( 'custom_order_tables', 'cart_checkout_blocks' );

	/**
	 * Declare compatibility for every feature in {@see FeaturesCompat::FEATURES}.
	 *
	 * @return void
	 */
	public static function declare_compatibility(): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		foreach ( self::FEATURES as $feature ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( $feature, PIXALINK_LOYALTY_FILE, true );
		}
	}
}
