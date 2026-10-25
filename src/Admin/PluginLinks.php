<?php
/**
 * Links on the Plugins screen.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Admin;

use Pixalink\Loyalty\Connect\ConnectController;
use Pixalink\Loyalty\Connect\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Adds "Settings" (or "Connect" while not connected) to the plugin's row on the Plugins screen.
 */
final class PluginLinks {

	/**
	 * Connection state.
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * Plugin basename, e.g. "pixalink-loyalty-for-woocommerce/pixalink-loyalty-for-woocommerce.php".
	 *
	 * @var string
	 */
	private string $basename;

	/**
	 * Constructor.
	 *
	 * @param Connection $connection Connection state.
	 * @param string     $basename   Plugin basename.
	 */
	public function __construct( Connection $connection, string $basename ) {
		$this->connection = $connection;
		$this->basename   = $basename;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'plugin_action_links_' . $this->basename, array( $this, 'action_links' ) );
	}

	/**
	 * Prepend the settings link.
	 *
	 * @param mixed $links Existing action links.
	 * @return array<mixed>
	 */
	public function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		if ( ! current_user_can( ConnectController::CAPABILITY ) ) {
			return $links;
		}

		$label = $this->connection->exists()
			? __( 'Settings', 'pixalink-loyalty-for-woocommerce' )
			: __( 'Connect', 'pixalink-loyalty-for-woocommerce' );

		return array_merge(
			array( 'settings' => '<a href="' . esc_url( ConnectController::settings_url() ) . '">' . esc_html( $label ) . '</a>' ),
			$links
		);
	}
}
