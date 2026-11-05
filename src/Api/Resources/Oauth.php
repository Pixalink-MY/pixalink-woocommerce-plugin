<?php
/**
 * The OAuth token endpoint of the Pixalink member portal.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Api\Resources;

use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Exchanges the authorization code the member portal sent back for an access token (oauth-token.request.json):
 * `POST {token_endpoint}` form-encoded, HTTP Basic client authentication, the PKCE verifier. The store keeps no token:
 * it hands the access token to `POST customers` straight away ({@see Customers::oauth_payload()}), and Pixalink revokes
 * it there.
 */
final class Oauth {

	public const GRANT_TYPE = 'authorization_code';

	/**
	 * Shape of a Bearer token (RFC 6750 b64token).
	 */
	public const TOKEN_PATTERN = '#^[A-Za-z0-9._~+/-]+=*$#D';

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
	 * Form fields of the token request.
	 *
	 * @param string $code         Authorization code from the callback.
	 * @param string $redirect_uri The redirect_uri the authorize request carried.
	 * @param string $verifier     PKCE code verifier.
	 * @return array{grant_type:string,code:string,redirect_uri:string,code_verifier:string}
	 */
	public static function token_payload( string $code, string $redirect_uri, string $verifier ): array {
		return array(
			'grant_type'    => self::GRANT_TYPE,
			'code'          => $code,
			'redirect_uri'  => $redirect_uri,
			'code_verifier' => $verifier,
		);
	}

	/**
	 * Exchange a code for an access token.
	 *
	 * @param string $token_endpoint The heartbeat's `oauth.token_endpoint`.
	 * @param string $client_id      Client id.
	 * @param string $client_secret  Client secret.
	 * @param string $code           Authorization code.
	 * @param string $redirect_uri   Redirect URI of the authorize request.
	 * @param string $verifier       PKCE verifier.
	 * @return string The access token.
	 * @throws ApiException On failure (400 `invalid_grant`, 401 `invalid_client`, or no Bearer token in the answer).
	 */
	public function exchange_code( string $token_endpoint, string $client_id, string $client_secret, string $code, string $redirect_uri, string $verifier ): string {
		$data = $this->client->post_form( $token_endpoint, self::token_payload( $code, $redirect_uri, $verifier ), $client_id, $client_secret )->data;

		$type  = isset( $data['token_type'] ) && is_string( $data['token_type'] ) ? strtolower( $data['token_type'] ) : '';
		$token = isset( $data['access_token'] ) && is_string( $data['access_token'] ) ? $data['access_token'] : '';
		if ( 'bearer' !== $type || 1 !== preg_match( self::TOKEN_PATTERN, $token ) ) {
			$exception = ApiException::invalid_response( 200 );
			throw $exception;
		}

		return $token;
	}
}
