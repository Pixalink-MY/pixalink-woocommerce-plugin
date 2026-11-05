<?php
/**
 * The store's OAuth client at Pixalink.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Oauth;

use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\Resources\Connect;
use Pixalink\Loyalty\Connect\Connection;
use Pixalink\Loyalty\Support\Crypto;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Provisions the outlet's OAuth client once the heartbeat advertises OAuth ({@see Capability}): `POST
 * connection/oauth-client` (signed, Idempotency-Key) for {@see ClientCredentials::redirect_uri()}. The client id is
 * kept as is and the secret only encrypted with {@see Crypto}, in `pixalink_loyalty_oauth_client` (autoload off).
 *
 * A new client is provisioned when none is kept, when the store's address changed (the kept one was issued for another
 * callback URL), when the secret can no longer be decrypted (the site's salts changed), and once when the token
 * endpoint answers `invalid_client` ({@see ClientCredentials::reprovision()}). Each call rotates the client at
 * Pixalink. The client is forgotten whenever the store's connection changes (another key, a disconnect).
 */
final class ClientCredentials {

	/**
	 * Query parameter (and value) of the store's callback.
	 */
	public const CALLBACK_PARAM = 'pixalink_oauth';
	public const CALLBACK_VALUE = 'callback';

	/**
	 * Connect endpoints.
	 *
	 * @var Connect
	 */
	private Connect $api;

	/**
	 * Option storage.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Encrypts the secret.
	 *
	 * @var Crypto
	 */
	private Crypto $crypto;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Connect $api     Connect endpoints.
	 * @param Options $options Option storage.
	 * @param Crypto  $crypto  Encrypts the secret.
	 * @param Logger  $logger  Logger.
	 */
	public function __construct( Connect $api, Options $options, Crypto $crypto, Logger $logger ) {
		$this->api     = $api;
		$this->options = $options;
		$this->crypto  = $crypto;
		$this->logger  = $logger;
	}

	/**
	 * Register hooks: provision after a heartbeat, forget when the connection changes.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'pixalink_loyalty_heartbeat_received', array( $this, 'ensure' ), 20, 0 );

		add_action( Connection::CREDENTIALS_CHANGED_ACTION, array( $this, 'clear' ), 10, 0 );
		add_action( 'pixalink_loyalty_disconnected', array( $this, 'clear' ), 10, 0 );
		foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $prefix ) {
			add_action( $prefix . Options::CREDENTIALS, array( $this, 'clear' ), 10, 0 );
		}
	}

	/**
	 * The store's callback: its home page with `pixalink_oauth=callback`.
	 *
	 * @return string
	 */
	public static function redirect_uri(): string {
		return home_url( '/?' . self::CALLBACK_PARAM . '=' . self::CALLBACK_VALUE );
	}

	/**
	 * The kept client, when it was issued for the current callback URL and its secret decrypts.
	 *
	 * @return array{client_id:string,client_secret:string}|null
	 */
	public function get(): ?array {
		$stored = $this->options->get_array( Options::OAUTH_CLIENT );
		$id     = isset( $stored['client_id'] ) && is_string( $stored['client_id'] ) ? $stored['client_id'] : '';
		$secret = isset( $stored['client_secret'] ) && is_string( $stored['client_secret'] ) ? $this->crypto->decrypt( $stored['client_secret'] ) : null;
		if ( '' === $id || null === $secret || '' === $secret || ( $stored['redirect_uri'] ?? null ) !== self::redirect_uri() ) {
			return null;
		}

		return array(
			'client_id'     => $id,
			'client_secret' => $secret,
		);
	}

	/**
	 * `pixalink_loyalty_heartbeat_received` callback: provision a client while OAuth is advertised and none is usable.
	 *
	 * @return void
	 */
	public function ensure(): void {
		if ( null === Capability::remote( $this->options ) || null !== $this->get() ) {
			return;
		}

		$this->provision();
	}

	/**
	 * The token endpoint refused the client (`invalid_client`): forget it and provision a new one, once.
	 *
	 * @return void
	 */
	public function reprovision(): void {
		$this->clear();
		$this->provision();
	}

	/**
	 * Forget the client.
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->options->delete( Options::OAUTH_CLIENT );
	}

	/**
	 * Ask Pixalink for a client and keep it; a failure is logged and leaves nothing kept.
	 *
	 * @return void
	 */
	private function provision(): void {
		$redirect_uri = self::redirect_uri();

		try {
			$client = $this->api->oauth_client( $redirect_uri );
		} catch ( ApiException $exception ) {
			$this->logger->warning(
				'Pixalink did not issue the store\'s OAuth client; portal sign-in keeps the ticket flow.',
				array(
					'error_code'  => $exception->error_code(),
					'http_status' => $exception->http_status(),
				)
			);

			return;
		}

		$this->options->set(
			Options::OAUTH_CLIENT,
			array(
				'client_id'     => $client['client_id'],
				'client_secret' => $this->crypto->encrypt( $client['client_secret'] ),
				'redirect_uri'  => $redirect_uri,
			)
		);
		$this->logger->info( 'OAuth client for member portal sign-in provisioned.' );
	}
}
