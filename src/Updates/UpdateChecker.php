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
 * Wires yahnis-elsts/plugin-update-checker to the plugin's public GitHub releases.
 *
 * The update is the `*-selfhosted.zip` attached to the latest release (drafts and pre-releases are skipped). A
 * release without that asset is ignored instead of falling back to GitHub's source zip, which is not installable.
 *
 * Only the `selfhosted` build flavour ships this directory and the library; bin/build-zip.sh deletes it for
 * the `wporg` and `marketplace` flavours, where updates come from the directory itself.
 */
final class UpdateChecker {

	public const REPOSITORY_URL = 'https://github.com/Pixalink-MY/pixalink-woocommerce-plugin/';
	public const SLUG           = 'pixalink-loyalty-for-woocommerce';
	public const ASSET_PATTERN  = '/-selfhosted\.zip$/';

	/**
	 * The library's `Vcs\Api::REQUIRE_RELEASE_ASSETS`, spelled out because its namespace carries the library's
	 * minor version (v5p7), which a Composer update can change.
	 */
	private const REQUIRE_RELEASE_ASSETS = 2;

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
		 * Filters the GitHub repository the self-hosted build takes its updates from (a fork or a mirror).
		 *
		 * @since 1.0.0
		 *
		 * @param string $url Repository URL.
		 */
		$url = apply_filters( 'pixalink_loyalty_update_repository_url', self::REPOSITORY_URL );

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			is_string( $url ) && '' !== $url ? $url : self::REPOSITORY_URL,
			$plugin_file,
			self::SLUG
		);

		if ( method_exists( $checker, 'getVcsApi' ) ) {
			$api = $checker->getVcsApi();
			if ( is_object( $api ) && method_exists( $api, 'enableReleaseAssets' ) ) {
				$api->enableReleaseAssets( self::ASSET_PATTERN, self::REQUIRE_RELEASE_ASSETS );
			}
		}
	}
}
