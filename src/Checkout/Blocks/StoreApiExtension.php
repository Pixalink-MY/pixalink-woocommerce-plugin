<?php
/**
 * Store API wiring: the cart update callback and `extensions.pixalink-loyalty` on the cart.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Checkout\Blocks;

use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use Pixalink\Loyalty\Checkout\PanelData;
use Pixalink\Loyalty\Redemption\CartActions;

defined( 'ABSPATH' ) || exit;

/**
 * Registers {@see CartActions::handle()} as the `pixalink-loyalty` update callback of POST cart/extensions (used by
 * both checkouts) and exposes {@see PanelData} on every Store API cart response.
 *
 * `woocommerce_blocks_loaded` fires inside `plugins_loaded:10`, the same priority as this plugin's boot, so it may
 * already have fired (ADR pitfall 7): register at once when it has.
 */
final class StoreApiExtension {

	/**
	 * Cart actions.
	 *
	 * @var CartActions
	 */
	private CartActions $actions;

	/**
	 * Panel data.
	 *
	 * @var PanelData
	 */
	private PanelData $panel;

	/**
	 * Whether the Store API registration ran.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Constructor.
	 *
	 * @param CartActions $actions Cart actions.
	 * @param PanelData   $panel   Panel data.
	 */
	public function __construct( CartActions $actions, PanelData $panel ) {
		$this->actions = $actions;
		$this->panel   = $panel;
	}

	/**
	 * Register now or once WooCommerce Blocks has loaded.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->register_store_api();
			return;
		}

		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api' ) );
	}

	/**
	 * Register the update callback and the cart endpoint data (once).
	 *
	 * @return void
	 */
	public function register_store_api(): void {
		if ( $this->registered || ! function_exists( 'woocommerce_store_api_register_update_callback' ) || ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		$this->registered = true;

		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => CartActions::STORE_API_NAMESPACE,
				'callback'  => array( $this->actions, 'handle' ),
			)
		);

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CartSchema::IDENTIFIER,
				'namespace'       => CartActions::STORE_API_NAMESPACE,
				'data_callback'   => array( $this, 'data' ),
				'schema_callback' => array( $this, 'schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * `data_callback`: the panel data.
	 *
	 * @return array<string,mixed>
	 */
	public function data(): array {
		return $this->panel->build();
	}

	/**
	 * `schema_callback`.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function schema(): array {
		$field = static fn ( string|array $type, string $description ): array => array(
			'description' => $description,
			'type'        => $type,
			'context'     => array( 'view', 'edit' ),
			'readonly'    => true,
		);

		return array(
			'show'          => $field( 'boolean', 'Whether the loyalty panel is shown.' ),
			'shopper'       => $field( 'string', 'guest, unlinked or verified.' ),
			'verified'      => $field( 'boolean', 'Whether the shopper may use their rewards.' ),
			'verify_prompt' => $field( 'string', 'Prompt for shoppers who must verify their phone first.' ),
			'verify_url'    => $field( 'string', 'Where shoppers verify their phone (may be empty).' ),
			'enabled'       => $field( 'object', 'Which redemptions are switched on.' ),
			'quote'         => $field( 'object', 'Quote freshness: available, stale, error.' ),
			'codes'         => $field( 'object', 'Coupon codes of points and credits.' ),
			'points'        => $field( array( 'object', 'null' ), 'Points offer for this cart, or null.' ),
			'credits'       => $field( array( 'object', 'null' ), 'Credits offer for this cart, or null.' ),
			'vouchers'      => $field( 'array', 'Voucher cards.' ),
			'labels'        => $field( 'object', 'Coupon code => label of the applied Pixalink coupons.' ),
			'earn_preview'  => $field( array( 'integer', 'null' ), 'Points this cart is expected to earn, or null.' ),
		);
	}
}
