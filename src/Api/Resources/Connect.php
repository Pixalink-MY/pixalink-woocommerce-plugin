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
 * Connect endpoints: `POST connect/exchange` (PKCE code → outlet API key), `POST connection/heartbeat`,
 * `POST connection/disconnect` and `GET outlet` (connection test), plus the hosted authorize page query.
 *
 * The static builders are the single source of the request bodies and query strings and are validated against
 * `tests/contract/schemas/*.json` by the contract suite. The wizard itself lives in {@see \Pixalink\Loyalty\Connect\ConnectService}.
 */
final class Connect {

	public const EXCHANGE_PATH   = 'connect/exchange';
	public const HEARTBEAT_PATH  = 'connection/heartbeat';
	public const DISCONNECT_PATH = 'connection/disconnect';
	public const OUTLET_PATH     = 'outlet';

	/**
	 * Path of the hosted connect page, relative to the Pixalink web base (not the API base).
	 */
	public const AUTHORIZE_PATH = 'connect/woocommerce';

	public const INTENT_CONNECT        = 'connect';
	public const INTENT_REQUEST_ACCESS = 'request_access';

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
	 * Query string for the hosted page `GET {pixalink}/connect/woocommerce`.
	 *
	 * @param string                   $site           Store URL (home URL).
	 * @param string                   $return_url     Callback URL on this site (admin settings tab).
	 * @param string                   $state          Anti-CSRF state bound to the admin user.
	 * @param string                   $code_challenge PKCE S256 challenge.
	 * @param string                   $plugin_version Plugin version.
	 * @param string                   $wc_version     WooCommerce version.
	 * @param string                   $intent         INTENT_CONNECT or INTENT_REQUEST_ACCESS.
	 * @param array<string,mixed>|null $lead           Lead prefill from {@see \Pixalink\Loyalty\Connect\LeadPayload} (request access only).
	 * @return array<string,string>
	 */
	public static function authorize_query( string $site, string $return_url, string $state, string $code_challenge, string $plugin_version, string $wc_version, string $intent = self::INTENT_CONNECT, ?array $lead = null ): array {
		$query = array(
			'site'                  => rtrim( $site, '/' ),
			'return'                => $return_url,
			'state'                 => $state,
			'code_challenge'        => $code_challenge,
			'code_challenge_method' => 'S256',
			'plugin_version'        => $plugin_version,
			'wc_version'            => $wc_version,
		);

		if ( self::INTENT_REQUEST_ACCESS === $intent ) {
			$query['intent'] = self::INTENT_REQUEST_ACCESS;
			if ( null !== $lead ) {
				$query['lead'] = self::encode_lead( $lead );
			}
		}

		return $query;
	}

	/**
	 * Lead prefill as unpadded base64url JSON (one opaque query parameter keeps types intact).
	 *
	 * @param array<string,mixed> $lead Lead payload.
	 * @return string
	 */
	public static function encode_lead( array $lead ): string {
		$json = wp_json_encode( $lead, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe transport encoding of a JSON query parameter, not obfuscation.
		return rtrim( strtr( base64_encode( false === $json ? '{}' : $json ), '+/', '-_' ), '=' );
	}

	/**
	 * Body for POST connection/disconnect.
	 *
	 * @param string $reason Machine reason, e.g. "merchant_request".
	 * @return array{reason:string}
	 */
	public static function disconnect_payload( string $reason ): array {
		return array( 'reason' => $reason );
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

	/**
	 * Tell Pixalink the merchant disconnected (the server revokes the key; 204 No Content).
	 *
	 * @param string $reason Machine reason.
	 * @return void
	 * @throws ApiException On failure.
	 */
	public function disconnect( string $reason ): void {
		$this->client->post( self::DISCONNECT_PATH, self::disconnect_payload( $reason ), array( 'endpoint_class' => Client::ENDPOINT_CONNECT ) );
	}

	/**
	 * Connection test: the outlet and organisation the key belongs to.
	 *
	 * @return array<mixed> `{outlet{id,uuid,name,...}, organisation{id,name}}`.
	 * @throws ApiException On failure.
	 */
	public function outlet(): array {
		return $this->client->get( self::OUTLET_PATH )->data;
	}

	/**
	 * The underlying API client (base URL lookups).
	 *
	 * @return Client
	 */
	public function client(): Client {
		return $this->client;
	}
}
