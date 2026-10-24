<?php
/**
 * My Account › Loyalty.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Account;

use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The `pixalink-loyalty` My Account endpoint, its menu item and the `[pixalink_loyalty_summary]` /
 * `[pixalink_loyalty_qr]` shortcodes.
 *
 * WooCommerce registers the rewrite endpoint from `woocommerce_get_query_vars`; rewrite rules are flushed once after
 * activation or an upgrade (flag {@see Options::FLUSH_REWRITE_RULES}).
 */
final class LoyaltyEndpoint {

	public const ENDPOINT = 'pixalink-loyalty';

	public const SUMMARY_SHORTCODE = 'pixalink_loyalty_summary';
	public const QR_SHORTCODE      = 'pixalink_loyalty_qr';

	/**
	 * Option storage.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Account content.
	 *
	 * @var AccountView
	 */
	private AccountView $view;

	/**
	 * Constructor.
	 *
	 * @param Options     $options Option storage.
	 * @param AccountView $view    Account content.
	 */
	public function __construct( Options $options, AccountView $view ) {
		$this->options = $options;
		$this->view    = $view;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_query_vars', array( $this, 'query_vars' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 99 );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_items' ), 20, 1 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'title' ), 10, 1 );

		add_shortcode( self::SUMMARY_SHORTCODE, array( $this, 'summary_shortcode' ) );
		add_shortcode( self::QR_SHORTCODE, array( $this, 'qr_shortcode' ) );
	}

	/**
	 * Tab title.
	 *
	 * @return string
	 */
	public function tab_title(): string {
		$title = trim( $this->options->get_string( Options::ACCOUNT_TAB_TITLE ) );

		return '' !== $title ? $title : __( 'Loyalty', 'pixalink-loyalty-for-woocommerce' );
	}

	/**
	 * `woocommerce_get_query_vars` callback.
	 *
	 * @param mixed $vars Query vars.
	 * @return mixed
	 */
	public function query_vars( $vars ) {
		if ( is_array( $vars ) ) {
			$vars[ self::ENDPOINT ] = self::ENDPOINT;
		}

		return $vars;
	}

	/**
	 * Flush rewrite rules once after activation (the endpoint is registered by then), or from wp-admin when the stored
	 * rules lack the endpoint (a site that ran an earlier build, or another plugin flushed before ours loaded).
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite_rules(): void {
		$flagged = $this->options->get_bool( Options::FLUSH_REWRITE_RULES );
		if ( ! $flagged && ( ! is_admin() || self::rules_have_endpoint() ) ) {
			return;
		}

		if ( $flagged ) {
			$this->options->delete( Options::FLUSH_REWRITE_RULES );
		}
		flush_rewrite_rules( false );
	}

	/**
	 * Whether the stored rewrite rules include the endpoint (always true with plain permalinks, which have none).
	 *
	 * @return bool
	 */
	private static function rules_have_endpoint(): bool {
		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) || array() === $rules ) {
			return true;
		}

		foreach ( array_keys( $rules ) as $pattern ) {
			if ( false !== strpos( (string) $pattern, self::ENDPOINT ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * `woocommerce_account_menu_items` callback: the tab goes before "Log out".
	 *
	 * @param mixed $items Menu items.
	 * @return mixed
	 */
	public function menu_items( $items ) {
		if ( ! is_array( $items ) || ! $this->options->get_bool( Options::ACCOUNT_TAB_ENABLED ) ) {
			return $items;
		}

		$logout = array();
		if ( isset( $items['customer-logout'] ) ) {
			$logout = array( 'customer-logout' => $items['customer-logout'] );
			unset( $items['customer-logout'] );
		}

		return $items + array( self::ENDPOINT => $this->tab_title() ) + $logout;
	}

	/**
	 * `woocommerce_endpoint_pixalink-loyalty_title` callback.
	 *
	 * @param mixed $title Default title.
	 * @return mixed
	 */
	public function title( $title ) {
		unset( $title );

		return $this->tab_title();
	}

	/**
	 * Endpoint content.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->options->get_bool( Options::ACCOUNT_TAB_ENABLED ) ) {
			return;
		}

		echo $this->view->render_current(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template output, escaped inside the templates.
	}

	/**
	 * `[pixalink_loyalty_summary]`.
	 *
	 * @return string
	 */
	public function summary_shortcode(): string {
		return '<div class="pixalink-loyalty-shortcode">' . $this->view->render_current() . '</div>';
	}

	/**
	 * `[pixalink_loyalty_qr]`.
	 *
	 * @return string
	 */
	public function qr_shortcode(): string {
		return $this->view->render_qr();
	}
}
