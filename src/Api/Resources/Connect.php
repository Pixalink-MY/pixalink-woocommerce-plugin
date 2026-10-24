<?php
/**
 * Connect endpoints of the Pixalink Commerce API.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Api\Resources;

use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\Client;

defined( 'ABSPATH' ) || exit;

/**
 * `POST connect/exchange` (PKCE code → outlet API key) and `POST connection/heartbeat`.
 *
 * The static `*_payload()` builders are the single source of the request bodies and are validated against
 * `tests/contract/schemas/*.request.json` by the contract suite. The Connect wizard itself arrives in P04.
 */
final class Connect {

	public const EXCHANGE_PATH  = 'connect/exchange';
	public const HEARTBEAT_PATH = 'connection/heartbeat';

	/**
	 * API client.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * Constructor.
	 *
	 * @param Client $client API client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Body for POST connect/exchange.
	 *
	 * @param string $code          One-time authorisation code from the hosted connect page.
	 * @param string $code_verifier PKCE code verifier (43-128 chars).
	 * @param string $site          Store URL the code was issued for.
	 * @return array{code:string,code_verifier:string,site:string}
	 */
	public static function exchange_payload( string $code, string $code_verifier, string $site ): array {
		return array(
			'code'          => $code,
			'code_verifier' => $code_verifier,
			'site'          => rtrim( $site, '/' ),
		);
	}

	/**
	 * Body for POST connection/heartbeat.
	 *
	 * @param string      $site              Store URL.
	 * @param string      $plugin_version    Plugin version.
	 * @param string      $wc_version        WooCommerce version.
	 * @param int         $pending           Outbox events waiting for delivery.
	 * @param int         $failed            Outbox events that exhausted their retries.
	 * @param string|null $oldest_pending_at ISO 8601 UTC time of the oldest pending event.
	 * @return array{site:string,plugin_version:string,wc_version:string,pending:int,failed:int,oldest_pending_at:string|null}
	 */
	public static function heartbeat_payload( string $site, string $plugin_version, string $wc_version, int $pending, int $failed, ?string $oldest_pending_at ): array {
		return array(
			'site'              => rtrim( $site, '/' ),
			'plugin_version'    => $plugin_version,
			'wc_version'        => $wc_version,
			'pending'           => max( 0, $pending ),
			'failed'            => max( 0, $failed ),
			'oldest_pending_at' => $oldest_pending_at,
		);
	}

	/**
	 * Exchange a one-time code for the outlet API key (unauthenticated, unsigned).
	 *
	 * @param string $code          One-time code.
	 * @param string $code_verifier PKCE verifier.
	 * @param string $site          Store URL.
	 * @return array<mixed> `{api_key, outlet{id,uuid,name}, organisation{id,name}, api_base_url, dashboard_url}`.
	 * @throws ApiException On failure (400 `invalid_grant` for any bad code).
	 */
	public function exchange( string $code, string $code_verifier, string $site ): array {
		return $this->client->post(
			self::EXCHANGE_PATH,
			self::exchange_payload( $code, $code_verifier, $site ),
			array(
				'authenticated'  => false,
				'endpoint_class' => Client::ENDPOINT_CONNECT,
			)
		)->data;
	}

	/**
	 * Report connection health and receive remote feature flags.
	 *
	 * @param array{site:string,plugin_version:string,wc_version:string,pending:int,failed:int,oldest_pending_at:string|null} $payload Body from {@see Connect::heartbeat_payload()}.
	 * @return array<mixed> `{status, outlet, features, redemption_rules, earn_on, min_plugin_version, server_time}`.
	 * @throws ApiException On failure.
	 */
	public function heartbeat( array $payload ): array {
		return $this->client->post( self::HEARTBEAT_PATH, $payload, array( 'endpoint_class' => Client::ENDPOINT_CONNECT ) )->data;
	}
}
