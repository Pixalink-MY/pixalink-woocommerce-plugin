<?php
/**
 * Loyalty card on My Account › Dashboard.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Account;

use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Identity\GuestLinkStore;
use Pixalink\Loyalty\Identity\ShopperContext;
use Pixalink\Loyalty\Support\Branding;
use Pixalink\Loyalty\Support\BrandingStyles;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * A compact summary below WooCommerce's dashboard text (`woocommerce_account_dashboard`), rendered from the
 * overridable `templates/myaccount/dashboard-card.php` (themes: `{theme}/pixalink-loyalty/myaccount/dashboard-card.php`).
 *
 * Verified members see a compact member card (points, the soonest expiry, their tier and how many vouchers work in this
 * store) with "Open member portal", which signs them in through {@see PortalHandoff}; logged-in shoppers who have not
 * verified their phone get one line that opens the Loyalty tab, where the one-time code flow lives, and the signed-out
 * portal link. The summary comes from {@see SummaryService} and its per-user cache, so an unverified shopper causes no call.
 * Nothing renders while the setting or the Loyalty tab is off, the store is not connected, or the summary cannot be
 * loaded: the dashboard never shows an error for it.
 */
final class DashboardCard {

	public const TEMPLATE = 'myaccount/dashboard-card.php';

	public const STYLE = 'pixalink-loyalty-account';

	/**
	 * The Loyalty tab the card opens.
	 *
	 * @var LoyaltyEndpoint
	 */
	private LoyaltyEndpoint $endpoint;

	/**
	 * Summaries.
	 *
	 * @var SummaryService
	 */
	private SummaryService $summaries;

	/**
	 * Option storage.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Outlet credentials.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Guest links.
	 *
	 * @var GuestLinkStore
	 */
	private GuestLinkStore $guests;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Portal links.
	 *
	 * @var PortalHandoff
	 */
	private PortalHandoff $handoff;

	/**
	 * Constructor.
	 *
	 * @param LoyaltyEndpoint $endpoint    The Loyalty tab.
	 * @param SummaryService  $summaries   Summaries.
	 * @param Options         $options     Option storage.
	 * @param Credentials     $credentials Outlet credentials.
	 * @param GuestLinkStore  $guests      Guest links.
	 * @param Logger          $logger      Logger.
	 * @param PortalHandoff   $handoff     Portal links.
	 */
	public function __construct( LoyaltyEndpoint $endpoint, SummaryService $summaries, Options $options, Credentials $credentials, GuestLinkStore $guests, Logger $logger, PortalHandoff $handoff ) {
		$this->endpoint    = $endpoint;
		$this->summaries   = $summaries;
		$this->options     = $options;
		$this->credentials = $credentials;
		$this->guests      = $guests;
		$this->logger      = $logger;
		$this->handoff     = $handoff;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_account_dashboard', array( $this, 'render' ) );
	}

	/**
	 * `woocommerce_account_dashboard` callback.
	 *
	 * @return void
	 */
	public function render(): void {
		$args = $this->template_args();
		if ( null === $args ) {
			return;
		}

		Assets::enqueue_account_style( Branding::active( $this->options ) );
		wc_get_template( self::TEMPLATE, $args, AccountView::TEMPLATE_PATH, PIXALINK_LOYALTY_DIR . 'templates/' );
	}

	/**
	 * Template variables, or null when nothing should render.
	 *
	 * @return array<string,mixed>|null
	 */
	private function template_args(): ?array {
		if ( ! $this->options->get_bool( Options::ACCOUNT_DASHBOARD_CARD ) || ! $this->options->get_bool( Options::ACCOUNT_TAB_ENABLED ) || ! $this->credentials->is_configured() ) {
			return null;
		}

		$shopper = ShopperContext::current( $this->guests );
		if ( ! $shopper->is_logged_in() ) {
			return null;
		}

		$branding = Branding::active( $this->options );
		$args     = array(
			'card'        => null,
			'title'       => $this->endpoint->tab_title(),
			'loyalty_url' => wc_get_account_endpoint_url( LoyaltyEndpoint::ENDPOINT ),
			'branded'     => BrandingStyles::is_branded( $branding ),
			'logo_url'    => $branding?->logo_url,
			'store_name'  => AccountView::store_name( $branding ),
			'portal_url'  => $this->handoff->portal_url( 0 ),
			'tier_style'  => '',
		);
		if ( ! $shopper->is_verified() ) {
			return $args;
		}

		$result = $this->summaries->for_user( $shopper->user_id() );
		if ( SummaryService::UNVERIFIED === $result['state'] ) {
			return $args;
		}

		if ( SummaryService::VERIFIED !== $result['state'] || null === $result['summary'] ) {
			$this->logger->debug( 'Loyalty summary unavailable; the My Account dashboard card was left out.', array( 'user_id' => $shopper->user_id() ) );

			return null;
		}

		$args['card']       = self::present( $result['summary'] );
		$args['portal_url'] = PortalHandoff::url( 'home' );
		$args['tier_style'] = BrandingStyles::tier_style( $args['card']['tier']['color'] ?? null );

		return $args;
	}

	/**
	 * Card view model of a normalised summary ({@see SummaryService::normalize()}); unreadable parts are dropped.
	 *
	 * Credits are left out: the card shows points, the soonest expiring points, the tier and the number of vouchers
	 * that can be used in this online store.
	 *
	 * @param array<mixed> $summary Normalised summary.
	 * @return array{points:int,expiring:array{points:int,expires_at:string}|null,tier:array{name:string,color:string|null,remaining:int|null,next_tier_name:string|null,percent:int|null}|null,vouchers_ready:int}
	 */
	public static function present( array $summary ): array {
		$expiring    = null;
		$soonest_at  = PHP_INT_MAX;
		$expiry_rows = is_array( $summary['points_expiring'] ?? null ) ? $summary['points_expiring'] : array();
		foreach ( $expiry_rows as $row ) {
			if ( ! is_array( $row ) || ! is_int( $row['points'] ?? null ) || $row['points'] < 1 || ! is_string( $row['expires_at'] ?? null ) ) {
				continue;
			}

			$expires_at = strtotime( $row['expires_at'] );
			if ( false !== $expires_at && $expires_at < $soonest_at ) {
				$soonest_at = $expires_at;
				$expiring   = array(
					'points'     => $row['points'],
					'expires_at' => $row['expires_at'],
				);
			}
		}

		$tier = null;
		$raw  = $summary['tier'] ?? null;
		if ( is_array( $raw ) && is_string( $raw['name'] ?? null ) && '' !== trim( $raw['name'] ) ) {
			$tier = array(
				'name'           => $raw['name'],
				'color'          => Branding::color( $raw['color'] ?? null ),
				'remaining'      => is_int( $raw['remaining'] ?? null ) ? max( 0, $raw['remaining'] ) : null,
				'next_tier_name' => is_string( $raw['next_tier_name'] ?? null ) && '' !== trim( $raw['next_tier_name'] ) ? $raw['next_tier_name'] : null,
				'percent'        => is_int( $raw['percent'] ?? null ) ? min( 100, max( 0, $raw['percent'] ) ) : null,
			);
		}

		$vouchers_ready = 0;
		$vouchers       = is_array( $summary['vouchers'] ?? null ) ? $summary['vouchers'] : array();
		foreach ( $vouchers as $voucher ) {
			if ( is_array( $voucher ) && true === ( $voucher['redeemable_here'] ?? null ) ) {
				++$vouchers_ready;
			}
		}

		return array(
			'points'         => is_int( $summary['points_balance'] ?? null ) ? max( 0, $summary['points_balance'] ) : 0,
			'expiring'       => $expiring,
			'tier'           => $tier,
			'vouchers_ready' => $vouchers_ready,
		);
	}
}
