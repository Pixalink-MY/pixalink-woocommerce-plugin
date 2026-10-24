<?php
/**
 * SPIKE (P02, throwaway): wires the virtual-coupon redemption experiment.
 *
 * Not production code. It exists to prove the design recorded in docs/adr/0001-discounts-and-holds.md
 * on current WooCommerce (Blocks + classic) and is deleted once W3 lands the real Redemption\* / Checkout\* classes.
 * Only active when the PIXALINK_LOYALTY_SPIKE constant is true (wp-env e2e config and the integration bootstrap).
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Spike;

use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Composition root for the spike.
 */
final class Spike {

	/**
	 * Store API extension namespace (cart/extensions callback + endpoint data key).
	 */
	public const STORE_API_NAMESPACE = 'pixalink-loyalty';

	/**
	 * Shared instance once registered.
	 *
	 * @var Spike|null
	 */
	private static ?Spike $instance = null;

	/**
	 * Virtual coupon hooks.
	 *
	 * @var VirtualCoupons
	 */
	private VirtualCoupons $coupons;

	/**
	 * The single cart mutation entry point.
	 *
	 * @var CartActions
	 */
	private CartActions $actions;

	/**
	 * Commit-at-submit hold hooks.
	 *
	 * @var HoldCoordinator
	 */
	private HoldCoordinator $holds;

	/**
	 * Build the services.
	 */
	private function __construct() {
		$this->coupons = new VirtualCoupons();
		$this->actions = new CartActions();
		$this->holds   = new HoldCoordinator();
	}

	/**
	 * Whether the spike is switched on for this site.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return defined( 'PIXALINK_LOYALTY_SPIKE' ) && (bool) constant( 'PIXALINK_LOYALTY_SPIKE' );
	}

	/**
	 * `pixalink_loyalty_booted` callback.
	 *
	 * @return void
	 */
	public static function maybe_register(): void {
		if ( ! self::enabled() || null !== self::$instance ) {
			return;
		}

		self::$instance = new self();
		self::$instance->register();
	}

	/**
	 * The registered instance, if any.
	 *
	 * @return Spike|null
	 */
	public static function instance(): ?Spike {
		return self::$instance;
	}

	/**
	 * Cart actions service.
	 *
	 * @return CartActions
	 */
	public function actions(): CartActions {
		return $this->actions;
	}

	/**
	 * Virtual coupons service.
	 *
	 * @return VirtualCoupons
	 */
	public function coupons(): VirtualCoupons {
		return $this->coupons;
	}

	/**
	 * Hold coordinator service.
	 *
	 * @return HoldCoordinator
	 */
	public function holds(): HoldCoordinator {
		return $this->holds;
	}

	/**
	 * Register every hook the spike needs.
	 *
	 * @return void
	 */
	private function register(): void {
		$this->coupons->register();
		$this->holds->register();
		ClassicPanel::register();
		SpikeDebug::register();

		/*
		 * woocommerce_blocks_loaded fires inside plugins_loaded:10 (Automattic\WooCommerce\Packages::on_init). Whether it
		 * ran before this plugin booted depends on plugin load order (alphabetical on a real site, WooCommerce first in
		 * the integration bootstrap), so handle both.
		 */
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->register_store_api();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api' ) );
		}

		add_action( 'woocommerce_blocks_cart_block_registration', array( BlocksIntegration::class, 'register_with' ) );
		add_action( 'woocommerce_blocks_checkout_block_registration', array( BlocksIntegration::class, 'register_with' ) );

		// E2E convenience: /?pixalink_spike_reset=1 empties the cart and the redemption state before add-to-cart runs (wp_loaded:20).
		add_action( 'wp_loaded', array( self::class, 'maybe_reset' ), 5 );
	}

	/**
	 * Register the Store API update callback and the cart endpoint data.
	 *
	 * @return void
	 */
	public function register_store_api(): void {
		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => self::STORE_API_NAMESPACE,
				'callback'  => array( $this->actions, 'handle' ),
			)
		);

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CartSchema::IDENTIFIER,
				'namespace'       => self::STORE_API_NAMESPACE,
				'data_callback'   => array( StoreApiExtension::class, 'data' ),
				'schema_callback' => array( StoreApiExtension::class, 'schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * `wp_loaded` callback for the e2e reset parameter.
	 *
	 * @return void
	 */
	public static function maybe_reset(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Spike-only e2e helper behind PIXALINK_LOYALTY_SPIKE.
		if ( ! isset( $_GET['pixalink_spike_reset'] ) || ! function_exists( 'WC' ) || null === WC()->cart ) {
			return;
		}

		WC()->cart->empty_cart();
		RedemptionState::reset();
	}
}
