<?php
/**
 * Typed access to the plugin's wp_options rows.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Registry and typed accessors for every option the plugin owns.
 *
 * Every option is prefixed with `pixalink_loyalty_` and declared in {@see Options::registry()} so the
 * installer can seed defaults and the uninstaller can remove exactly what the plugin created.
 */
final class Options {

	public const PREFIX = 'pixalink_loyalty_';

	public const DB_VERSION               = 'pixalink_loyalty_db_version';
	public const CREDENTIALS              = 'pixalink_loyalty_credentials';
	public const API_BASE                 = 'pixalink_loyalty_api_base';
	public const CLOCK_OFFSET             = 'pixalink_loyalty_clock_offset';
	public const DELETE_DATA_ON_UNINSTALL = 'pixalink_loyalty_delete_data_on_uninstall';
	public const DELIVERY_PAUSED          = 'pixalink_loyalty_delivery_paused';

	/**
	 * Connection record `{status, organisation, outlet, connected_at, connected_by, method, api_base_url, dashboard_url, ...}`.
	 */
	public const CONNECTION = 'pixalink_loyalty_connection';

	/**
	 * Last successful heartbeat response plus `received_at`, `last_attempt_at` and `last_error`.
	 */
	public const REMOTE_STATUS = 'pixalink_loyalty_remote_status';

	/**
	 * The store owner's own Pixalink marketing consent `{value, source, captured_at, text_hash, user_id}`.
	 */
	public const MARKETING_CONSENT = 'pixalink_loyalty_marketing_consent';

	// Checkout section.
	public const CHECKOUT_PANEL_ENABLED         = 'pixalink_loyalty_checkout_panel_enabled';
	public const VOUCHERS_ENABLED               = 'pixalink_loyalty_vouchers_enabled';
	public const POINTS_REDEMPTION_ENABLED      = 'pixalink_loyalty_points_redemption_enabled';
	public const PHONE_FIELD                    = 'pixalink_loyalty_phone_field';
	public const CHECKOUT_CONSENT_ENABLED       = 'pixalink_loyalty_checkout_consent_enabled';
	public const CHECKOUT_CONSENT_TEXT          = 'pixalink_loyalty_checkout_consent_text';
	public const CHECKOUT_CONSENT_DEFAULT_VALUE = 'pixalink_loyalty_checkout_consent_default';

	// My Account section.
	public const ACCOUNT_TAB_ENABLED = 'pixalink_loyalty_account_tab_enabled';
	public const ACCOUNT_TAB_TITLE   = 'pixalink_loyalty_account_tab_title';
	public const ACCOUNT_SHOW_QR     = 'pixalink_loyalty_account_show_qr';

	// Advanced section.
	public const DEBUG_LOGGING = 'pixalink_loyalty_debug_logging';

	/**
	 * Every option the plugin owns, with its default and autoload flag.
	 *
	 * `seed` marks options the installer creates on activation; the rest are written on demand.
	 *
	 * @return array<string,array{default:mixed,autoload:bool,seed:bool}>
	 */
	public static function registry(): array {
		return array(
			self::DB_VERSION               => array(
				'default'  => 0,
				'autoload' => true,
				'seed'     => false,
			),
			self::CREDENTIALS              => array(
				'default'  => '',
				'autoload' => false,
				'seed'     => false,
			),
			self::API_BASE                 => array(
				'default'  => '',
				'autoload' => true,
				'seed'     => false,
			),
			self::CLOCK_OFFSET             => array(
				'default'  => 0,
				'autoload' => true,
				'seed'     => true,
			),
			self::DELETE_DATA_ON_UNINSTALL => array(
				'default'  => 'no',
				'autoload' => false,
				'seed'     => true,
			),
			self::CONNECTION               => array(
				'default'  => array(),
				'autoload' => true,
				'seed'     => false,
			),
			self::REMOTE_STATUS            => array(
				'default'  => array(),
				'autoload' => true,
				'seed'     => false,
			),
			self::MARKETING_CONSENT        => array(
				'default'  => array(),
				'autoload' => false,
				'seed'     => false,
			),
			// Why event delivery is paused ("auth_failed" | "not_connected"); empty while delivering.
			self::DELIVERY_PAUSED          => array(
				'default'  => '',
				'autoload' => false,
				'seed'     => false,
			),
		) + self::settings_registry();
	}

	/**
	 * Merchant-editable settings shown under WooCommerce › Settings › Pixalink Loyalty (saved by WooCommerce).
	 *
	 * Defaults here are the single source for both the settings fields and runtime reads.
	 *
	 * @return array<string,array{default:mixed,autoload:bool,seed:bool}>
	 */
	public static function settings_registry(): array {
		$frontend = static fn ( $default_value ): array => array(
			'default'  => $default_value,
			'autoload' => true,
			'seed'     => false,
		);

		return array(
			self::CHECKOUT_PANEL_ENABLED         => $frontend( 'yes' ),
			self::VOUCHERS_ENABLED               => $frontend( 'yes' ),
			self::POINTS_REDEMPTION_ENABLED      => $frontend( 'yes' ),
			self::PHONE_FIELD                    => $frontend( 'optional' ),
			self::CHECKOUT_CONSENT_ENABLED       => $frontend( 'yes' ),
			self::CHECKOUT_CONSENT_TEXT          => $frontend( '' ),
			self::CHECKOUT_CONSENT_DEFAULT_VALUE => $frontend( 'no' ),
			self::ACCOUNT_TAB_ENABLED            => $frontend( 'yes' ),
			self::ACCOUNT_TAB_TITLE              => $frontend( '' ),
			self::ACCOUNT_SHOW_QR                => $frontend( 'yes' ),
			self::DEBUG_LOGGING                  => array(
				'default'  => 'no',
				'autoload' => true,
				'seed'     => false,
			),
		);
	}

	/**
	 * Registered default of an option.
	 *
	 * @param string $name Option name (must be registered).
	 * @return mixed
	 */
	public static function default_value( string $name ) {
		return self::registry()[ self::assert_known( $name ) ]['default'];
	}

	/**
	 * Names of every registered option.
	 *
	 * @return list<string>
	 */
	public static function names(): array {
		return array_keys( self::registry() );
	}

	/**
	 * Read an option as a string.
	 *
	 * @param string      $name     Option name (must be registered).
	 * @param string|null $fallback Value returned when the option is missing or not scalar (null: registered default).
	 * @return string
	 */
	public function get_string( string $name, ?string $fallback = null ): string {
		if ( null === $fallback ) {
			$default  = self::default_value( $name );
			$fallback = is_scalar( $default ) ? (string) $default : '';
		}

		$value = get_option( self::assert_known( $name ), $fallback );

		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	/**
	 * Read an option as an integer.
	 *
	 * @param string   $name     Option name (must be registered).
	 * @param int|null $fallback Value returned when the option is missing or not numeric (null: registered default).
	 * @return int
	 */
	public function get_int( string $name, ?int $fallback = null ): int {
		if ( null === $fallback ) {
			$default  = self::default_value( $name );
			$fallback = is_numeric( $default ) ? (int) $default : 0;
		}

		$value = get_option( self::assert_known( $name ), $fallback );

		return is_numeric( $value ) ? (int) $value : $fallback;
	}

	/**
	 * Read a WooCommerce-style yes/no option as a boolean.
	 *
	 * @param string    $name     Option name (must be registered).
	 * @param bool|null $fallback Value returned when the option is missing (null: registered default).
	 * @return bool
	 */
	public function get_bool( string $name, ?bool $fallback = null ): bool {
		if ( null === $fallback ) {
			$fallback = self::is_truthy( self::default_value( $name ) );
		}

		$value = get_option( self::assert_known( $name ), null );

		if ( null === $value || false === $value ) {
			return $fallback;
		}

		return self::is_truthy( $value );
	}

	/**
	 * Read an option holding an array (connection record, cached remote status).
	 *
	 * @param string $name Option name (must be registered).
	 * @return array<mixed>
	 */
	public function get_array( string $name ): array {
		$value = get_option( self::assert_known( $name ), array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Write an option using the autoload flag declared in the registry.
	 *
	 * @param string $name  Option name (must be registered).
	 * @param mixed  $value Value to store.
	 * @return bool Whether the value changed.
	 */
	public function set( string $name, $value ): bool {
		$registry = self::registry();

		return update_option( self::assert_known( $name ), $value, $registry[ $name ]['autoload'] );
	}

	/**
	 * Delete an option.
	 *
	 * @param string $name Option name (must be registered).
	 * @return bool
	 */
	public function delete( string $name ): bool {
		return delete_option( self::assert_known( $name ) );
	}

	/**
	 * Create seeded options with their defaults without overwriting existing values.
	 *
	 * @return void
	 */
	public function seed_defaults(): void {
		foreach ( self::registry() as $name => $definition ) {
			if ( ! $definition['seed'] ) {
				continue;
			}
			add_option( $name, $definition['default'], '', $definition['autoload'] );
		}
	}

	/**
	 * Whether a stored value means "yes".
	 *
	 * @param mixed $value Stored value.
	 * @return bool
	 */
	private static function is_truthy( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return is_scalar( $value ) && in_array( strtolower( (string) $value ), array( 'yes', '1', 'true', 'on' ), true );
	}

	/**
	 * Guard against typos and un-prefixed names.
	 *
	 * @param string $name Option name.
	 * @return string The same name.
	 * @throws \InvalidArgumentException When the option is not registered.
	 */
	private static function assert_known( string $name ): string {
		if ( ! array_key_exists( $name, self::registry() ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown Pixalink Loyalty option "%s".', esc_html( $name ) ) );
		}

		return $name;
	}
}
