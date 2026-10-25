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
 * dbDelta() when the stored `pixalink_loyalty_db_version` is lower.
 *
 * Tables:
 * - `{prefix}pixalink_events`: the event outbox (see {@see \Pixalink\Loyalty\Events\EventRepository}). Times are UTC.
 *   `outlet_id` / `api_base` (v3) are the connection a row was recorded for; NULL on rows recorded before v3.
 */
final class Schema {

	/**
	 * Current schema version.
	 */
	public const VERSION = 3;

	/**
	 * Unprefixed name of the event outbox table.
	 */
	public const EVENTS_TABLE = 'pixalink_events';

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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Opt-in uninstall drops the plugin's own tables.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) );
		}
	}

	/**
	 * Column/index definitions keyed by unprefixed table name (dbDelta format: two spaces before PRIMARY KEY).
	 *
	 * @return array<string,string>
	 */
	private static function definitions(): array {
		return array(
			self::EVENTS_TABLE => implode(
				"\n",
				array(
					'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,',
					'uuid char(36) NOT NULL,',
					'type varchar(64) NOT NULL,',
					"object_type varchar(20) NOT NULL DEFAULT '',",
					'object_id bigint(20) unsigned NOT NULL DEFAULT 0,',
					'sequence int(10) unsigned NOT NULL DEFAULT 0,',
					'payload longtext NOT NULL,',
					'outlet_id varchar(64) NULL DEFAULT NULL,',
					'api_base varchar(255) NULL DEFAULT NULL,',
					"status varchar(20) NOT NULL DEFAULT 'pending',",
					'attempts smallint(5) unsigned NOT NULL DEFAULT 0,',
					'next_attempt_at datetime NULL DEFAULT NULL,',
					'locked_at datetime NULL DEFAULT NULL,',
					'last_http_status smallint(5) unsigned NULL DEFAULT NULL,',
					'last_error text NULL,',
					'response longtext NULL,',
					'created_at datetime NOT NULL,',
					'delivered_at datetime NULL DEFAULT NULL,',
					'PRIMARY KEY  (id),',
					'UNIQUE KEY uuid (uuid),',
					'KEY object_sequence (object_type,object_id,sequence),',
					'KEY status_next_attempt (status,next_attempt_at),',
					'KEY status_created (status,created_at),',
					'KEY status_delivered (status,delivered_at)',
				)
			),
		);
	}
}
