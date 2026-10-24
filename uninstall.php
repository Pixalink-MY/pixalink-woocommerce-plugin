<?php
/**
 * Uninstall entry point.
 *
 * Data is removed only when "Delete all data on uninstall" (pixalink_loyalty_delete_data_on_uninstall) is enabled.
 *
 * @package Pixalink\Loyalty
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	require_once __DIR__ . '/src/Support/Options.php';
	require_once __DIR__ . '/src/Install/Schema.php';
	require_once __DIR__ . '/src/Install/Installer.php';
	require_once __DIR__ . '/src/Install/Uninstaller.php';
}

\Pixalink\Loyalty\Install\Uninstaller::uninstall();
