<?php
/**
 * Self-hosted update channel (removed from WordPress.org and Marketplace builds).
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Updates;

defined( 'ABSPATH' ) || exit;

/**
 * Wires yahnis-elsts/plugin-update-checker to the static metadata at updates.pixalink.io.
 *
 * Only the `selfhosted` build flavour ships this directory and the library; bin/build-zip.sh deletes it for
 * the `wporg` and `marketplace` flavours, where updates come from the directory itself.
 */
final class UpdateChecker {

	public const METADATA_URL = 'https://updates.pixalink.io/wp/pixalink-loyalty-for-woocommerce/info.json';
	public const SLUG         = 'pixalink-loyalty-for-woocommerce';

	/**
	 * Register the update checker when the library is bundled.
	 *
	 * @param string $plugin_file Absolute path of the main plugin file.
	 * @return void
	 */
	public static function register( string $plugin_file ): void {
		if ( ! class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class ) ) {
			return;
		}

		/**
		 * Filters the self-hosted update metadata URL (staging channels, mirrors).
		 *
		 * @since 0.1.0
		 *
		 * @param string $url Metadata (info.json) URL.
		 */
		$url = apply_filters( 'pixalink_loyalty_update_metadata_url', self::METADATA_URL );

		\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker( is_string( $url ) ? $url : self::METADATA_URL, $plugin_file, self::SLUG );
	}
}
