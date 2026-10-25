<?php
/**
 * Activation, deactivation and schema upgrades.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Install;

use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Lifecycle hooks registered from the main plugin file.
 *
 * Activation never redirects and never contacts Pixalink; connecting is an explicit merchant action.
 */
final class Installer {

	/**
	 * Action Scheduler group used by every scheduled action of the plugin.
	 */
	public const ACTION_GROUP = 'pixalink-loyalty';

	/**
	 * Activation hook: seed options and create tables.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 * @return void
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install();
				restore_current_blog();
			}

			return;
		}

		self::install();
	}

	/**
	 * Deactivation hook: stop scheduled work but keep all data.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::ACTION_GROUP );
		}
	}

	/**
	 * Run install steps when the stored schema version is behind (called on every boot).
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$options = new Options();
		if ( $options->get_int( Options::DB_VERSION ) < Schema::VERSION ) {
			self::install();
		}
	}

	/**
	 * Idempotent install: seed option defaults, create/upgrade tables, record the schema version.
	 *
	 * @return void
	 */
	public static function install(): void {
		$options = new Options();
		$options->seed_defaults();
		Schema::install();
		$options->set( Options::DB_VERSION, Schema::VERSION );
		// The My Account endpoint registers on init; flush its rewrite rules then (Account\LoyaltyEndpoint).
		$options->set( Options::FLUSH_REWRITE_RULES, 'yes' );

		/**
		 * Fires after the plugin's options and tables are installed or upgraded.
		 *
		 * @since 0.1.0
		 *
		 * @param int $schema_version Installed schema version.
		 */
		do_action( 'pixalink_loyalty_installed', Schema::VERSION );
	}
}
