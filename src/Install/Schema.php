<?php
/**
 * Custom table definitions.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the plugin's custom tables and their dbDelta() definitions.
 *
 * Bump {@see Schema::VERSION} whenever a definition changes; {@see Installer::maybe_upgrade()} re-runs
 * dbDelta() when the stored `pixalink_loyalty_db_version` is lower. The outbox table
 * `{prefix}pixalink_events` is added by work unit P05.
 */
final class Schema {

	/**
	 * Current schema version.
	 */
	public const VERSION = 1;

	/**
	 * Unprefixed names of every custom table.
	 *
	 * @return list<string>
	 */
	public static function table_names(): array {
		return array_keys( self::definitions() );
	}

	/**
	 * Create or update every custom table.
	 *
	 * @return void
	 */
	public static function install(): void {
		$definitions = self::definitions();
		if ( array() === $definitions ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		foreach ( $definitions as $table => $columns ) {
			dbDelta( "CREATE TABLE {$wpdb->prefix}{$table} (\n{$columns}\n) {$charset_collate};" );
		}
	}

	/**
	 * Drop every custom table (uninstall only).
	 *
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;

		foreach ( self::table_names() as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from a fixed list.
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
		}
	}

	/**
	 * Column/index definitions keyed by unprefixed table name (dbDelta format: two spaces before PRIMARY KEY).
	 *
	 * @return array<string,string>
	 */
	private static function definitions(): array {
		return array();
	}
}
