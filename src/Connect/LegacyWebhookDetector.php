<?php
/**
 * Finds WooCommerce webhooks left over from the pre-plugin Pixalink integration.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Connect;

defined( 'ABSPATH' ) || exit;

/**
 * Detects and pauses legacy Pixalink webhooks (`https://*.pixalink.io/.../woocommerce/webhooks/...`).
 *
 * Once the plugin delivers order events itself, the old REST webhooks would double-count orders on Pixalink
 * (and Pixalink answers them with 410 after the legacy switch-off, which makes WooCommerce disable them noisily).
 */
final class LegacyWebhookDetector {

	/**
	 * Path fragment every legacy Pixalink webhook delivery URL contains.
	 */
	public const PATH_FRAGMENT = '/woocommerce/webhooks/';

	/**
	 * Registrable domain of Pixalink-hosted deployments.
	 */
	public const PIXALINK_DOMAIN = 'pixalink.io';

	/**
	 * Additional hosts treated as Pixalink (for example a dedicated deployment's own domain).
	 *
	 * @var list<string>
	 */
	private array $extra_hosts;

	/**
	 * Constructor.
	 *
	 * @param list<string> $extra_hosts Extra Pixalink hosts, e.g. the host of the configured API base.
	 */
	public function __construct( array $extra_hosts = array() ) {
		$this->extra_hosts = array_values( array_filter( array_map( 'strtolower', $extra_hosts ) ) );
	}

	/**
	 * Whether a webhook delivery URL points at the legacy Pixalink endpoint.
	 *
	 * @param string $url Delivery URL.
	 * @return bool
	 */
	public function is_legacy_url( string $url ): bool {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return false;
		}

		$scheme = strtolower( $parts['scheme'] ?? '' );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		$path = '/' . ltrim( strtolower( $parts['path'] ?? '' ), '/' );
		if ( false === strpos( rtrim( $path, '/' ) . '/', self::PATH_FRAGMENT ) ) {
			return false;
		}

		return $this->is_pixalink_host( strtolower( $parts['host'] ) );
	}

	/**
	 * Active legacy webhooks.
	 *
	 * @return list<\WC_Webhook>
	 */
	public function find_active(): array {
		if ( ! class_exists( \WC_Webhook_Data_Store::class ) || ! function_exists( 'wc_get_webhook' ) ) {
			return array();
		}

		try {
			$ids = ( new \WC_Webhook_Data_Store() )->get_webhooks_ids( 'active' );
		} catch ( \Exception $exception ) {
			return array();
		}

		$legacy = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$webhook = wc_get_webhook( (int) $id );
			if ( $webhook instanceof \WC_Webhook && $this->is_legacy_url( (string) $webhook->get_delivery_url() ) ) {
				$legacy[] = $webhook;
			}
		}

		return $legacy;
	}

	/**
	 * Disable every active legacy webhook.
	 *
	 * @return int Number of webhooks disabled.
	 */
	public function pause_all(): int {
		$paused = 0;
		foreach ( $this->find_active() as $webhook ) {
			$webhook->set_status( 'disabled' );
			$webhook->save();
			++$paused;
		}

		return $paused;
	}

	/**
	 * Whether a host belongs to Pixalink.
	 *
	 * @param string $host Lower-case host.
	 * @return bool
	 */
	private function is_pixalink_host( string $host ): bool {
		if ( self::PIXALINK_DOMAIN === $host || str_ends_with( $host, '.' . self::PIXALINK_DOMAIN ) ) {
			return true;
		}

		/**
		 * Filters the extra hosts whose `/woocommerce/webhooks/` URLs count as legacy Pixalink webhooks.
		 *
		 * @since 0.1.0
		 *
		 * @param list<string> $hosts Lower-case hosts (the configured Pixalink host by default).
		 */
		$hosts = apply_filters( 'pixalink_loyalty_legacy_webhook_hosts', $this->extra_hosts );

		return is_array( $hosts ) && in_array( $host, array_map( static fn ( $item ): string => strtolower( (string) $item ), $hosts ), true );
	}
}
