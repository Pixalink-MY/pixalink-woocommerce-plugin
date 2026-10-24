<?php
/**
 * WooCommerce › Settings › Pixalink Loyalty.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Admin;

use Pixalink\Loyalty\Connect\ConnectController;
use Pixalink\Loyalty\Fields\ConsentText;
use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's settings tab (decision D11).
 *
 * Sections: Connection & Health (`''`, the connection card, no Save button), Checkout, My Account and Advanced.
 * Field defaults come from {@see Options::settings_registry()} so runtime reads and the form never disagree.
 * Loaded only from the `woocommerce_get_settings_pages` filter, after WooCommerce has loaded WC_Settings_Page, so
 * nothing outside that filter may reference this class's members (see {@see ConnectController::SETTINGS_TAB}).
 */
final class SettingsPage extends \WC_Settings_Page {

	public const ID = ConnectController::SETTINGS_TAB;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id    = self::ID;
		$this->label = __( 'Pixalink Loyalty', 'pixalink-loyalty-for-woocommerce' );

		parent::__construct();
	}

	/**
	 * `woocommerce_get_settings_pages` callback.
	 *
	 * @param mixed $pages Settings pages.
	 * @return array<mixed>
	 */
	public static function add_page( $pages ): array {
		$pages   = is_array( $pages ) ? $pages : array();
		$pages[] = new self();

		return $pages;
	}

	/**
	 * Sections of the tab.
	 *
	 * @return array<string,string>
	 */
	protected function get_own_sections(): array {
		return array(
			''         => __( 'Connection & Health', 'pixalink-loyalty-for-woocommerce' ),
			'checkout' => __( 'Checkout', 'pixalink-loyalty-for-woocommerce' ),
			'account'  => __( 'My Account', 'pixalink-loyalty-for-woocommerce' ),
			'advanced' => __( 'Advanced', 'pixalink-loyalty-for-woocommerce' ),
		);
	}

	/**
	 * Render the current section; the connection section has its own buttons instead of Save.
	 *
	 * @return void
	 */
	public function output(): void {
		$section = isset( $GLOBALS['current_section'] ) && is_string( $GLOBALS['current_section'] ) ? $GLOBALS['current_section'] : '';
		if ( '' === $section ) {
			$GLOBALS['hide_save_button'] = true; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce's own flag for hiding "Save changes".
		}

		parent::output();
	}

	/**
	 * Connection & Health.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_default_section(): array {
		return array(
			array(
				'title' => __( 'Connection', 'pixalink-loyalty-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'pixalink_loyalty_connection_section',
			),
			array(
				'type'      => ConnectionField::TYPE,
				'id'        => 'pixalink_loyalty_connection_card',
				'is_option' => false,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'pixalink_loyalty_connection_section',
			),
		);
	}

	/**
	 * Checkout.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_checkout_section(): array {
		return array(
			array(
				'title' => __( 'Checkout', 'pixalink-loyalty-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Redemption options appear only for shoppers who verified their phone number, and only while Pixalink keeps the matching feature switched on.', 'pixalink-loyalty-for-woocommerce' ),
				'id'    => 'pixalink_loyalty_checkout_section',
			),
			self::checkbox( Options::CHECKOUT_PANEL_ENABLED, __( 'Loyalty panel', 'pixalink-loyalty-for-woocommerce' ), __( 'Show members their points, credits and vouchers at checkout', 'pixalink-loyalty-for-woocommerce' ) ),
			self::redemption_checkbox( Options::VOUCHERS_ENABLED, 'vouchers', __( 'Vouchers', 'pixalink-loyalty-for-woocommerce' ), __( 'Let members apply their Pixalink vouchers', 'pixalink-loyalty-for-woocommerce' ) ),
			self::redemption_checkbox( Options::POINTS_REDEMPTION_ENABLED, 'points_redemption', __( 'Points', 'pixalink-loyalty-for-woocommerce' ), __( 'Let members turn points into a discount', 'pixalink-loyalty-for-woocommerce' ) ),
			self::redemption_checkbox( Options::CREDITS_ENABLED, 'credits', __( 'Credits', 'pixalink-loyalty-for-woocommerce' ), __( 'Let members pay part of an order with their Pixalink credits (applied as a discount; credit-paid spend earns no points)', 'pixalink-loyalty-for-woocommerce' ) ),
			array(
				'title'    => __( 'Phone number', 'pixalink-loyalty-for-woocommerce' ),
				'id'       => Options::PHONE_FIELD,
				'type'     => 'select',
				'default'  => Options::default_value( Options::PHONE_FIELD ),
				'autoload' => true,
				'options'  => array(
					'optional' => __( 'Optional', 'pixalink-loyalty-for-woocommerce' ),
					'required' => __( 'Required', 'pixalink-loyalty-for-woocommerce' ),
				),
				'desc'     => __( 'Points are credited to the phone number entered at checkout. Shoppers without one cannot earn.', 'pixalink-loyalty-for-woocommerce' ),
				'desc_tip' => true,
			),
			self::checkbox( Options::CHECKOUT_CONSENT_ENABLED, __( 'Marketing consent', 'pixalink-loyalty-for-woocommerce' ), __( 'Ask shoppers at checkout and sign-up whether they want to receive offers (the box is never ticked for them)', 'pixalink-loyalty-for-woocommerce' ) ),
			array(
				'title'       => __( 'Consent text', 'pixalink-loyalty-for-woocommerce' ),
				'id'          => Options::CHECKOUT_CONSENT_TEXT,
				'type'        => 'textarea',
				'default'     => Options::default_value( Options::CHECKOUT_CONSENT_TEXT ),
				'autoload'    => true,
				'placeholder' => self::default_consent_text(),
				'desc'        => __( 'Leave empty to use the default. The exact text shown is recorded with each shopper\'s choice.', 'pixalink-loyalty-for-woocommerce' ),
				'desc_tip'    => true,
				'css'         => 'min-width: 400px; min-height: 60px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'pixalink_loyalty_checkout_section',
			),
		);
	}

	/**
	 * My Account.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_account_section(): array {
		return array(
			array(
				'title' => __( 'My Account', 'pixalink-loyalty-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'pixalink_loyalty_account_section',
			),
			self::checkbox( Options::ACCOUNT_TAB_ENABLED, __( 'Loyalty tab', 'pixalink-loyalty-for-woocommerce' ), __( 'Add a tab with balance, tier and vouchers to My Account', 'pixalink-loyalty-for-woocommerce' ) ),
			array(
				'title'       => __( 'Tab title', 'pixalink-loyalty-for-woocommerce' ),
				'id'          => Options::ACCOUNT_TAB_TITLE,
				'type'        => 'text',
				'default'     => Options::default_value( Options::ACCOUNT_TAB_TITLE ),
				'autoload'    => true,
				'placeholder' => __( 'Loyalty', 'pixalink-loyalty-for-woocommerce' ),
			),
			self::checkbox( Options::ACCOUNT_SHOW_QR, __( 'Member QR code', 'pixalink-loyalty-for-woocommerce' ), __( 'Show a QR code members can scan at your outlets', 'pixalink-loyalty-for-woocommerce' ) ),
			array(
				'type' => 'sectionend',
				'id'   => 'pixalink_loyalty_account_section',
			),
		);
	}

	/**
	 * Advanced.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_advanced_section(): array {
		return array(
			array(
				'title' => __( 'Advanced', 'pixalink-loyalty-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'pixalink_loyalty_advanced_section',
			),
			self::checkbox( Options::DEBUG_LOGGING, __( 'Debug log', 'pixalink-loyalty-for-woocommerce' ), __( 'Log every Pixalink request to WooCommerce › Status › Logs (secrets and contact details are masked)', 'pixalink-loyalty-for-woocommerce' ) ),
			self::checkbox( Options::DELETE_DATA_ON_UNINSTALL, __( 'Uninstall', 'pixalink-loyalty-for-woocommerce' ), __( 'Delete all plugin data when the plugin is deleted (order history is always kept)', 'pixalink-loyalty-for-woocommerce' ) ),
			array(
				'type' => 'sectionend',
				'id'   => 'pixalink_loyalty_advanced_section',
			),
		);
	}

	/**
	 * Default checkout consent text.
	 *
	 * @return string
	 */
	public static function default_consent_text(): string {
		return ConsentText::default_text();
	}

	/**
	 * A redemption toggle that also says when Pixalink itself switched the feature off (the remote switch wins).
	 *
	 * @param string $option  Option name.
	 * @param string $feature Heartbeat `features.*` key.
	 * @param string $title   Left-column title.
	 * @param string $desc    Checkbox label.
	 * @return array<string,mixed>
	 */
	private static function redemption_checkbox( string $option, string $feature, string $title, string $desc ): array {
		$field      = self::checkbox( $option, $title, $desc );
		$connection = \Pixalink\Loyalty\Plugin::instance()->connection();

		if ( null === $connection->last_heartbeat_at() ) {
			$field['desc_tip'] = __( 'Pixalink confirms whether this is available for your store after the first connection check.', 'pixalink-loyalty-for-woocommerce' );
		} elseif ( ! $connection->feature( $feature ) ) {
			$field['desc'] .= ' — ' . __( 'currently switched off by Pixalink for your account', 'pixalink-loyalty-for-woocommerce' );
		}

		return $field;
	}

	/**
	 * A yes/no checkbox field with the registered default and autoload flag.
	 *
	 * @param string $option Option name.
	 * @param string $title  Left-column title.
	 * @param string $desc   Checkbox label.
	 * @return array<string,mixed>
	 */
	private static function checkbox( string $option, string $title, string $desc ): array {
		$registry = Options::registry();

		return array(
			'title'    => $title,
			'desc'     => $desc,
			'id'       => $option,
			'type'     => 'checkbox',
			'default'  => Options::default_value( $option ),
			'autoload' => $registry[ $option ]['autoload'],
		);
	}
}
