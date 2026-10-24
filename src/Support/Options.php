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
			// Why event delivery is paused ("auth_failed" | "not_connected"); empty while delivering.
			self::DELIVERY_PAUSED          => array(
				'default'  => '',
				'autoload' => false,
				'seed'     => false,
			),
		);
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
	 * @param string $name    Option name (must be registered).
	 * @param string $fallback Value returned when the option is missing or not scalar.
	 * @return string
	 */
	public function get_string( string $name, string $fallback = '' ): string {
		$value = get_option( self::assert_known( $name ), $fallback );

		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	/**
	 * Read an option as an integer.
	 *
	 * @param string $name     Option name (must be registered).
	 * @param int    $fallback Value returned when the option is missing or not numeric.
	 * @return int
	 */
	public function get_int( string $name, int $fallback = 0 ): int {
		$value = get_option( self::assert_known( $name ), $fallback );

		return is_numeric( $value ) ? (int) $value : $fallback;
	}

	/**
	 * Read a WooCommerce-style yes/no option as a boolean.
	 *
	 * @param string $name     Option name (must be registered).
	 * @param bool   $fallback Value returned when the option is missing.
	 * @return bool
	 */
	public function get_bool( string $name, bool $fallback = false ): bool {
		$value = get_option( self::assert_known( $name ), null );

		if ( null === $value || false === $value ) {
			return $fallback;
		}

		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( (string) $value ), array( 'yes', '1', 'true', 'on' ), true );
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
