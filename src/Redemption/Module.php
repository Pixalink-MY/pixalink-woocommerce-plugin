<?php
/**
 * Composition root of redemption (vouchers, points and credits at checkout).
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Redemption;

use Pixalink\Loyalty\Api\Resources\Redemptions;
use Pixalink\Loyalty\Checkout\Blocks\BlocksIntegration;
use Pixalink\Loyalty\Checkout\Blocks\StoreApiExtension;
use Pixalink\Loyalty\Checkout\Classic\ClassicPanel;
use Pixalink\Loyalty\Checkout\PanelData;
use Pixalink\Loyalty\Identity\ShopperContextInterface;
use Pixalink\Loyalty\Orders\OrderLifecycle;
use Pixalink\Loyalty\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and registers every redemption service, keeping ADR 0001's hook map.
 */
final class Module {

	/**
	 * Shopper state.
	 *
	 * @var RedemptionState
	 */
	private RedemptionState $state;

	/**
	 * Quotes.
	 *
	 * @var QuoteService
	 */
	private QuoteService $quotes;

	/**
	 * Virtual coupons.
	 *
	 * @var VirtualCoupons
	 */
	private VirtualCoupons $coupons;

	/**
	 * Cart actions.
	 *
	 * @var CartActions
	 */
	private CartActions $actions;

	/**
	 * Holds.
	 *
	 * @var HoldCoordinator
	 */
	private HoldCoordinator $holds;

	/**
	 * Sweeper.
	 *
	 * @var HoldSweeper
	 */
	private HoldSweeper $sweeper;

	/**
	 * Panel data.
	 *
	 * @var PanelData
	 */
	private PanelData $panel;

	/**
	 * Store API wiring.
	 *
	 * @var StoreApiExtension
	 */
	private StoreApiExtension $store_api;

	/**
	 * Blocks integration.
	 *
	 * @var BlocksIntegration
	 */
	private BlocksIntegration $blocks;

	/**
	 * Classic panel.
	 *
	 * @var ClassicPanel
	 */
	private ClassicPanel $classic;

	/**
	 * Kill switches.
	 *
	 * @var Features
	 */
	private Features $features;

	/**
	 * Whether register() ran.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Constructor.
	 *
	 * @param Redemptions             $api       Checkout API.
	 * @param ShopperContextInterface $shopper   Who the shopper is.
	 * @param Features                $features  Kill switches.
	 * @param OrderLifecycle          $lifecycle Order events.
	 * @param Logger                  $logger    Logger.
	 * @param RedemptionState|null    $state     Shopper state (tests); defaults to the WC session's.
	 */
	public function __construct( Redemptions $api, ShopperContextInterface $shopper, Features $features, OrderLifecycle $lifecycle, Logger $logger, ?RedemptionState $state = null ) {
		$this->features  = $features;
		$this->state     = $state ?? RedemptionState::for_wc_session();
		$this->quotes    = new QuoteService( $api, $this->state, $shopper, $features, $logger );
		$this->coupons   = new VirtualCoupons( $this->state, $shopper, $features, $this->quotes );
		$this->actions   = new CartActions( $this->state, $this->quotes, $shopper, $features );
		$this->holds     = new HoldCoordinator( $api, $this->quotes, $shopper, $lifecycle, $logger );
		$this->sweeper   = new HoldSweeper( $api, $logger );
		$this->panel     = new PanelData( $this->state, $this->quotes, $shopper, $features, $this->coupons );
		$this->store_api = new StoreApiExtension( $this->actions, $this->panel );
		$this->blocks    = new BlocksIntegration( $features );
		$this->classic   = new ClassicPanel( $this->panel, $features );
	}

	/**
	 * Register every hook (idempotent).
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		$this->state->register();
		$this->coupons->register();
		$this->holds->register();
		$this->sweeper->register();
		$this->store_api->register();
		$this->blocks->register();
		$this->classic->register();
	}

	/**
	 * Shopper state.
	 *
	 * @return RedemptionState
	 */
	public function state(): RedemptionState {
		return $this->state;
	}

	/**
	 * Quotes.
	 *
	 * @return QuoteService
	 */
	public function quotes(): QuoteService {
		return $this->quotes;
	}

	/**
	 * Virtual coupons.
	 *
	 * @return VirtualCoupons
	 */
	public function coupons(): VirtualCoupons {
		return $this->coupons;
	}

	/**
	 * Cart actions.
	 *
	 * @return CartActions
	 */
	public function actions(): CartActions {
		return $this->actions;
	}

	/**
	 * Holds.
	 *
	 * @return HoldCoordinator
	 */
	public function holds(): HoldCoordinator {
		return $this->holds;
	}

	/**
	 * Sweeper.
	 *
	 * @return HoldSweeper
	 */
	public function sweeper(): HoldSweeper {
		return $this->sweeper;
	}

	/**
	 * Panel data.
	 *
	 * @return PanelData
	 */
	public function panel(): PanelData {
		return $this->panel;
	}

	/**
	 * Store API wiring.
	 *
	 * @return StoreApiExtension
	 */
	public function store_api(): StoreApiExtension {
		return $this->store_api;
	}

	/**
	 * Blocks integration.
	 *
	 * @return BlocksIntegration
	 */
	public function blocks(): BlocksIntegration {
		return $this->blocks;
	}

	/**
	 * Classic panel.
	 *
	 * @return ClassicPanel
	 */
	public function classic(): ClassicPanel {
		return $this->classic;
	}

	/**
	 * Kill switches.
	 *
	 * @return Features
	 */
	public function features(): Features {
		return $this->features;
	}
}
