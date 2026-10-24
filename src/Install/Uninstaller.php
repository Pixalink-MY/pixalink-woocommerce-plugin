<?php
/**
 * Data removal on uninstall.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Install;

use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Removes plugin data, but only when the merchant opted in via `pixalink_loyalty_delete_data_on_uninstall`.
 *
 * Order meta (`_pixalink_*`) is kept even then: it is part of the store's order history and accounting trail.
 */
final class Uninstaller {

	/**
	 * User meta keys owned by the plugin.
	 */
	public const USER_META_KEYS = array(
		'pixalink_customer_id',
		'pixalink_phone_e164',
		'pixalink_phone_country',
		'pixalink_phone_verified_at',
		'pixalink_marketing_consent',
		'pixalink_marketing_consent_at',
		'pixalink_marketing_consent_source',
		'pixalink_linked_at',
		'pixalink_loyalty_dismissed_notices',
	);

	/**
	 * Entry point from uninstall.php (handles multisite).
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		if ( is_multisite() ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::uninstall_site();
				restore_current_blog();
			}

			return;
		}

		self::uninstall_site();
	}

	/**
	 * Remove data for the current site when opted in.
	 *
	 * @return bool Whether data was removed.
	 */
	public static function uninstall_site(): bool {
		Installer::deactivate();

		$options = new Options();
		if ( ! $options->get_bool( Options::DELETE_DATA_ON_UNINSTALL ) ) {
			return false;
		}

		Schema::drop();

		foreach ( self::USER_META_KEYS as $meta_key ) {
			delete_metadata( 'user', 0, $meta_key, '', true );
		}

		self::delete_transients();

		foreach ( Options::names() as $option ) {
			delete_option( $option );
		}

		return true;
	}

	/**
	 * Delete `pixalink_loyalty_*` transients.
	 *
	 * @return void
	 */
	private static function delete_transients(): void {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_pixalink_loyalty_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-off cleanup on uninstall; no API lists transients by prefix.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

		foreach ( $names as $name ) {
			delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
		}
	}
}
