<?php
/**
 * Connect, test and disconnect the store.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Connect;

use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Api\Resources\Connect;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The Connect state machine.
 *
 * Path B (primary, existing vendors): {@see ConnectService::start_hosted()} redirects the admin to Pixalink's hosted
 * page with a PKCE S256 challenge; Pixalink redirects back with a one-time code that
 * {@see ConnectService::complete_hosted()} exchanges for the outlet API key. Guests on the hosted page can request
 * access instead and come back with `error=access_denied&reason=lead_submitted`.
 *
 * Path A: {@see ConnectService::connect_with_key()} stores a pasted outlet key and sends a signed heartbeat, which
 * makes Pixalink adopt this site (409 `site_already_connected` when the outlet already serves another store).
 */
final class ConnectService {

	public const OUTCOME_CONNECTED      = 'connected';
	public const OUTCOME_LEAD_SUBMITTED = 'lead_submitted';
	public const OUTCOME_CANCELLED      = 'cancelled';

	/**
	 * The wp-config constant overriding the hosted connect page URL (local fake server, dedicated deployments).
	 */
	public const CONNECT_URL_CONSTANT = 'PIXALINK_LOYALTY_CONNECT_URL';

	/**
	 * Shape of an outlet API key (`{token id}|{secret}`), from connect-exchange.response.json.
	 */
	public const API_KEY_PATTERN = '/^[0-9]+\|[A-Za-z0-9]{20,}$/';

	public const DISCONNECT_REASON = 'merchant_request';

	/**
	 * Connect API resource.
	 *
	 * @var Connect
	 */
	private Connect $api;

	/**
	 * Credentials.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Connection state.
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * PKCE + state store.
	 *
	 * @var PkceState
	 */
	private PkceState $pkce;

	/**
	 * Heartbeat.
	 *
	 * @var Heartbeat
	 */
	private Heartbeat $heartbeat;

	/**
	 * Legacy webhook detector.
	 *
	 * @var LegacyWebhookDetector
	 */
	private LegacyWebhookDetector $legacy_webhooks;

	/**
	 * Lead payload builder.
	 *
	 * @var LeadPayload
	 */
	private LeadPayload $lead_payload;

	/**
	 * Option storage.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private string $plugin_version;

	/**
	 * Constructor.
	 *
	 * @param Connect               $api             Connect API resource.
	 * @param Credentials           $credentials     Credentials.
	 * @param Connection            $connection      Connection state.
	 * @param PkceState             $pkce            PKCE + state store.
	 * @param Heartbeat             $heartbeat       Heartbeat.
	 * @param LegacyWebhookDetector $legacy_webhooks Legacy webhook detector.
	 * @param LeadPayload           $lead_payload    Lead payload builder.
	 * @param Options               $options         Option storage.
	 * @param Logger                $logger          Logger.
	 * @param string                $plugin_version  Plugin version.
	 */
	public function __construct(
		Connect $api,
		Credentials $credentials,
		Connection $connection,
		PkceState $pkce,
		Heartbeat $heartbeat,
		LegacyWebhookDetector $legacy_webhooks,
		LeadPayload $lead_payload,
		Options $options,
		Logger $logger,
		string $plugin_version
	) {
		$this->api             = $api;
		$this->credentials     = $credentials;
		$this->connection      = $connection;
		$this->pkce            = $pkce;
		$this->heartbeat       = $heartbeat;
		$this->legacy_webhooks = $legacy_webhooks;
		$this->lead_payload    = $lead_payload;
		$this->options         = $options;
		$this->logger          = $logger;
		$this->plugin_version  = $plugin_version;
	}

	/**
	 * Pixalink web base (scheme + host, plus any path before `/api/`), derived from the API base.
	 *
	 * @return string
	 */
	public function pixalink_base_url(): string {
		$api_base = $this->api->client()->base_url();
		$position = strpos( $api_base, '/api/' );
		$base     = false === $position ? $api_base : substr( $api_base, 0, $position );

		/**
		 * Filters the Pixalink web base used for the hosted connect page and dashboard links.
		 *
		 * @since 0.1.0
		 *
		 * @param string $base     Base URL derived from the API base, e.g. "https://explore.pixalink.io".
		 * @param string $api_base Current API base URL.
		 */
		$filtered = apply_filters( 'pixalink_loyalty_web_base', $base, $api_base );

		return rtrim( is_string( $filtered ) && '' !== trim( $filtered ) ? trim( $filtered ) : $base, '/' );
	}

	/**
	 * URL of the hosted connect page (without query).
	 *
	 * @return string
	 */
	public function authorize_endpoint(): string {
		if ( defined( self::CONNECT_URL_CONSTANT ) ) {
			$constant = constant( self::CONNECT_URL_CONSTANT );
			if ( is_string( $constant ) && '' !== trim( $constant ) ) {
				return trim( $constant );
			}
		}

		$default = $this->pixalink_base_url() . '/' . Connect::AUTHORIZE_PATH;

		/**
		 * Filters the hosted connect page URL (ignored when PIXALINK_LOYALTY_CONNECT_URL is defined).
		 *
		 * @since 0.1.0
		 *
		 * @param string $url Default "{pixalink base}/connect/woocommerce".
		 */
		$url = apply_filters( 'pixalink_loyalty_connect_url', $default );

		return is_string( $url ) && '' !== trim( $url ) ? trim( $url ) : $default;
	}

	/**
	 * Hosts the admin may be redirected to (for allowed_redirect_hosts).
	 *
	 * @return list<string>
	 */
	public function redirect_hosts(): array {
		$hosts = array();
		foreach ( array( $this->authorize_endpoint(), $this->pixalink_base_url() ) as $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				$hosts[] = strtolower( $host );
			}
		}

		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Callback URL Pixalink redirects back to.
	 *
	 * @return string
	 */
	public static function return_url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=pixalink_loyalty&pixalink_connect=callback' );
	}

	/**
	 * Store URL sent as `site` (the same value the API client sends as X-Pixalink-Site).
	 *
	 * @return string
	 */
	public static function site_url(): string {
		return rtrim( (string) home_url(), '/' );
	}

	/**
	 * Begin the hosted flow: remember PKCE + state for this admin and build the redirect URL.
	 *
	 * @param int       $user_id           Admin user id.
	 * @param string    $intent            Connect::INTENT_CONNECT or Connect::INTENT_REQUEST_ACCESS.
	 * @param bool      $pause_legacy      Pause legacy Pixalink webhooks once connected.
	 * @param bool|null $marketing_consent The admin's Pixalink marketing choice (null when the box was not shown).
	 * @return string Absolute URL of the hosted page.
	 * @throws ConnectException When wp-config credentials make a hosted connect pointless.
	 */
	public function start_hosted( int $user_id, string $intent = Connect::INTENT_CONNECT, bool $pause_legacy = true, ?bool $marketing_consent = null ): string {
		$intent = Connect::INTENT_REQUEST_ACCESS === $intent ? Connect::INTENT_REQUEST_ACCESS : Connect::INTENT_CONNECT;
		if ( Connect::INTENT_CONNECT === $intent && $this->credentials->is_overridden() ) {
			$failure = new ConnectException( ConnectException::CREDENTIALS_OVERRIDDEN );
			throw $failure;
		}

		$consent = null === $marketing_consent ? null : $this->record_marketing_consent( $user_id, $marketing_consent );
		$pkce    = $this->pkce->begin(
			$user_id,
			array(
				'intent'       => $intent,
				'pause_legacy' => $pause_legacy,
			)
		);

		$lead = null;
		if ( Connect::INTENT_REQUEST_ACCESS === $intent ) {
			$user = get_userdata( $user_id );
			$lead = $this->lead_payload->build(
				false === $user ? '' : (string) $user->user_email,
				false === $user ? '' : (string) $user->display_name,
				null === $consent ? null : self::public_consent( $consent )
			);
		}

		$query = Connect::authorize_query(
			self::site_url(),
			self::return_url(),
			$pkce['state'],
			$pkce['challenge'],
			$this->plugin_version,
			LeadPayload::wc_version(),
			$intent,
			$lead
		);

		$endpoint = $this->authorize_endpoint();

		return $endpoint . ( false === strpos( $endpoint, '?' ) ? '?' : '&' ) . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Finish the hosted flow with the code Pixalink returned.
	 *
	 * @param int    $user_id Admin user id (must be the one who started).
	 * @param string $code    One-time code.
	 * @param string $state   State echoed by Pixalink.
	 * @return int Number of legacy webhooks paused.
	 * @throws ConnectException On a bad state, a rejected code or an unusable response.
	 */
	public function complete_hosted( int $user_id, string $code, string $state ): int {
		$pending = $this->pkce->consume( $user_id, $state );
		if ( null !== $pending && '' === trim( $code ) ) {
			$failure = new ConnectException( ConnectException::INVALID_GRANT, 'The connect callback carried no code.' );
			throw $failure;
		}
		if ( null === $pending ) {
			$failure = new ConnectException( ConnectException::INVALID_STATE, 'The connect state is missing, expired or does not match.' );
			throw $failure;
		}

		if ( $this->credentials->is_overridden() ) {
			$failure = new ConnectException( ConnectException::CREDENTIALS_OVERRIDDEN );
			throw $failure;
		}

		try {
			$exchange = $this->api->exchange( $code, $pending['verifier'], self::site_url() );
		} catch ( ApiException $exception ) {
			$this->logger->warning(
				'Pixalink connect code exchange failed.',
				array(
					'status'     => $exception->http_status(),
					'error_code' => $exception->error_code(),
				)
			);

			// Any 4xx from connect/exchange means the code is unusable (the server burns it either way).
			$failure = ConnectException::from_api( $exception, ConnectException::INVALID_GRANT );
			throw $failure;
		}

		$api_key = isset( $exchange['api_key'] ) && is_string( $exchange['api_key'] ) ? trim( $exchange['api_key'] ) : '';
		$outlet  = self::assoc( $exchange['outlet'] ?? null );
		if ( 1 !== preg_match( self::API_KEY_PATTERN, $api_key ) || ! isset( $outlet['id'] ) ) {
			$failure = new ConnectException( ConnectException::INVALID_RESPONSE, 'connect/exchange returned no usable API key or outlet.' );
			throw $failure;
		}

		$api_base_url  = self::acceptable_url( $exchange['api_base_url'] ?? null );
		$dashboard_url = self::acceptable_url( $exchange['dashboard_url'] ?? null );

		$this->credentials->store( $api_key );
		if ( null !== $api_base_url ) {
			$this->options->set( Options::API_BASE, $api_base_url );
		}

		$this->connection->mark_connected(
			self::assoc( $exchange['organisation'] ?? null ),
			$outlet,
			$user_id,
			Connection::METHOD_HOSTED,
			$api_base_url ?? $this->api->client()->base_url(),
			$dashboard_url
		);

		return $this->after_connect( ! empty( $pending['context']['pause_legacy'] ) );
	}

	/**
	 * Handle a callback that carries `error` instead of a code.
	 *
	 * @param int    $user_id Admin user id.
	 * @param string $state   State echoed by Pixalink.
	 * @param string $error   `access_denied` or `invalid_request`.
	 * @param string $reason  Optional detail, e.g. `lead_submitted`.
	 * @return string OUTCOME_LEAD_SUBMITTED or OUTCOME_CANCELLED.
	 * @throws ConnectException On a bad state or a request Pixalink refused.
	 */
	public function fail_hosted( int $user_id, string $state, string $error, string $reason = '' ): string {
		$matched = null !== $this->pkce->consume( $user_id, $state );

		// Pixalink may not be able to echo the state of a request it could not parse; reporting it changes nothing.
		if ( 'invalid_request' === $error ) {
			$failure = new ConnectException( ConnectException::INVALID_REQUEST, 'Pixalink refused the connect request (invalid_request).' );
			throw $failure;
		}

		if ( ! $matched ) {
			$failure = new ConnectException( ConnectException::INVALID_STATE, 'The connect state is missing, expired or does not match.' );
			throw $failure;
		}

		if ( 'access_denied' === $error ) {
			return 'lead_submitted' === $reason ? self::OUTCOME_LEAD_SUBMITTED : self::OUTCOME_CANCELLED;
		}

		$failure = new ConnectException( ConnectException::HOSTED_ERROR, 'Pixalink returned an unknown connect error.' );
		throw $failure;
	}

	/**
	 * Path A: store a pasted outlet key and let Pixalink adopt this site.
	 *
	 * The previous key (if any) is restored when the new one is not accepted.
	 *
	 * @param int    $user_id      Admin user id.
	 * @param string $api_key      Pasted key.
	 * @param bool   $pause_legacy Pause legacy Pixalink webhooks once connected.
	 * @return int Number of legacy webhooks paused.
	 * @throws ConnectException When the key is malformed, rejected, or the outlet serves another store.
	 */
	public function connect_with_key( int $user_id, string $api_key, bool $pause_legacy = true ): int {
		$api_key = trim( $api_key );
		if ( 1 !== preg_match( self::API_KEY_PATTERN, $api_key ) ) {
			$failure = new ConnectException( ConnectException::INVALID_KEY_FORMAT );
			throw $failure;
		}

		if ( $this->credentials->is_overridden() ) {
			$failure = new ConnectException( ConnectException::CREDENTIALS_OVERRIDDEN );
			throw $failure;
		}

		$previous = Credentials::SOURCE_OPTION === $this->credentials->source() ? $this->credentials->api_key() : null;
		$this->credentials->store( $api_key );

		try {
			return $this->complete_key_connection( $user_id, $pause_legacy );
		} catch ( ConnectException $exception ) {
			if ( null === $previous ) {
				$this->credentials->clear();
			} else {
				$this->credentials->store( $previous );
			}

			throw $exception;
		}
	}

	/**
	 * Path A with the key from wp-config.php (PIXALINK_LOYALTY_API_KEY).
	 *
	 * @param int  $user_id      Admin user id.
	 * @param bool $pause_legacy Pause legacy Pixalink webhooks once connected.
	 * @return int Number of legacy webhooks paused.
	 * @throws ConnectException When no constant is configured or the key is not accepted.
	 */
	public function adopt_configured_key( int $user_id, bool $pause_legacy = true ): int {
		if ( ! $this->credentials->is_overridden() ) {
			$failure = new ConnectException( ConnectException::NOT_CONNECTED );
			throw $failure;
		}

		return $this->complete_key_connection( $user_id, $pause_legacy );
	}

	/**
	 * Run a heartbeat and the outlet lookup now (the "Test connection" button).
	 *
	 * @return void
	 * @throws ConnectException When not connected or Pixalink rejects the call.
	 */
	public function test(): void {
		if ( ! $this->credentials->is_configured() || ! $this->connection->exists() ) {
			$failure = new ConnectException( ConnectException::NOT_CONNECTED );
			throw $failure;
		}

		try {
			$this->heartbeat->run();
			$outlet = $this->api->outlet();
		} catch ( ApiException $exception ) {
			$failure = ConnectException::from_api( $exception );
			throw $failure;
		}

		$this->connection->update_details( self::assoc( $outlet['outlet'] ?? null ), self::assoc( $outlet['organisation'] ?? null ) );
	}

	/**
	 * Disconnect: tell Pixalink (best effort), forget the stored key, stop the heartbeat, keep the history.
	 *
	 * @param int $user_id Admin user id.
	 * @return bool Whether Pixalink acknowledged the disconnect.
	 */
	public function disconnect( int $user_id ): bool {
		$acknowledged = false;
		if ( $this->credentials->is_configured() ) {
			try {
				$this->api->disconnect( self::DISCONNECT_REASON );
				$acknowledged = true;
			} catch ( ApiException $exception ) {
				$this->logger->warning(
					'Pixalink did not acknowledge the disconnect; local credentials are removed anyway.',
					array(
						'status'     => $exception->http_status(),
						'error_code' => $exception->error_code(),
					)
				);
			}
		}

		$this->credentials->clear();
		$this->options->delete( Options::API_BASE );
		$this->connection->mark_disconnected( $user_id );
		$this->heartbeat->unschedule();

		$this->logger->info( 'Store disconnected from Pixalink.', array( 'acknowledged' => $acknowledged ) );

		/**
		 * Fires after the store was disconnected from Pixalink.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $acknowledged Whether Pixalink confirmed the disconnect.
		 */
		do_action( 'pixalink_loyalty_disconnected', $acknowledged );

		return $acknowledged;
	}

	/**
	 * Post-connect work: pause legacy webhooks, schedule the heartbeat and send the first one.
	 *
	 * @param bool              $pause_legacy    Pause legacy Pixalink webhooks.
	 * @param array<mixed>|null $first_heartbeat Heartbeat response already received (key path), or null to send one.
	 * @return int Number of legacy webhooks paused.
	 */
	public function after_connect( bool $pause_legacy, ?array $first_heartbeat = null ): int {
		$paused = $pause_legacy ? $this->legacy_webhooks->pause_all() : 0;

		$this->heartbeat->schedule();
		if ( null !== $first_heartbeat ) {
			$this->heartbeat->apply( $first_heartbeat );
		} else {
			try {
				$this->heartbeat->run();
			} catch ( ApiException $exception ) {
				// Recorded by run(); the scheduled heartbeat retries.
				unset( $exception );
			}
		}

		$data = $this->connection->data();
		$this->logger->info(
			'Store connected to Pixalink.',
			array(
				'method'          => $data['method'],
				'outlet_id'       => $data['outlet']['id'] ?? null,
				'organisation_id' => $data['organisation']['id'] ?? null,
				'legacy_paused'   => $paused,
			)
		);

		/**
		 * Fires after the store connected to a Pixalink outlet.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,mixed> $connection Connection record (no secrets).
		 */
		do_action( 'pixalink_loyalty_connected', $data );

		return $paused;
	}

	/**
	 * Disable active legacy Pixalink webhooks.
	 *
	 * @return int Number of webhooks disabled.
	 */
	public function pause_legacy_webhooks(): int {
		$paused = $this->legacy_webhooks->pause_all();
		if ( $paused > 0 ) {
			$this->logger->info( 'Legacy Pixalink webhooks paused.', array( 'count' => $paused ) );
		}

		return $paused;
	}

	/**
	 * Store the admin's own Pixalink marketing choice (evidence: when, who, and the hash of the text shown).
	 *
	 * @param int  $user_id Admin user id.
	 * @param bool $value   Whether the box was ticked.
	 * @return array{value:bool,source:string,captured_at:string,text_hash:string,user_id:int}
	 */
	public function record_marketing_consent( int $user_id, bool $value ): array {
		$record = array(
			'value'       => $value,
			'source'      => 'admin',
			'captured_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'text_hash'   => hash( 'sha256', self::marketing_consent_text() ),
			'user_id'     => $user_id,
		);
		$this->options->set( Options::MARKETING_CONSENT, $record );

		return $record;
	}

	/**
	 * Label of the store owner's marketing-consent checkbox (hashed into the consent record).
	 *
	 * @return string
	 */
	public static function marketing_consent_text(): string {
		return __( 'Pixalink may e-mail me about product updates, tips and offers. I can unsubscribe at any time.', 'pixalink-loyalty-for-woocommerce' );
	}

	/**
	 * Shared tail of the key paths: heartbeat (adopts the site), outlet lookup, connection record.
	 *
	 * @param int  $user_id      Admin user id.
	 * @param bool $pause_legacy Pause legacy webhooks.
	 * @return int Number of legacy webhooks paused.
	 * @throws ConnectException When Pixalink does not accept the key.
	 */
	private function complete_key_connection( int $user_id, bool $pause_legacy ): int {
		try {
			$heartbeat = $this->heartbeat->request();
		} catch ( ApiException $exception ) {
			$failure = ConnectException::from_api( $exception, ConnectException::KEY_REJECTED );
			throw $failure;
		}

		$outlet       = self::assoc( $heartbeat['outlet'] ?? null );
		$organisation = array();
		try {
			$details      = $this->api->outlet();
			$outlet       = self::assoc( $details['outlet'] ?? null ) + $outlet;
			$organisation = self::assoc( $details['organisation'] ?? null );
		} catch ( ApiException $exception ) {
			// The heartbeat already proved the key; the organisation name is cosmetic.
			$this->logger->warning( 'Pixalink outlet lookup failed after connecting with a key.', array( 'error_code' => $exception->error_code() ) );
		}

		$this->connection->mark_connected( $organisation, $outlet, $user_id, Connection::METHOD_API_KEY, $this->api->client()->base_url(), null );

		return $this->after_connect( $pause_legacy, $heartbeat );
	}

	/**
	 * The consent object as sent to Pixalink (common.json#/$defs/marketing_consent).
	 *
	 * @param array{value:bool,source:string,captured_at:string,text_hash:string,user_id:int} $consent Stored record.
	 * @return array{value:bool,source:string,captured_at:string,text_hash:string}
	 */
	private static function public_consent( array $consent ): array {
		return array(
			'value'       => $consent['value'],
			'source'      => $consent['source'],
			'captured_at' => $consent['captured_at'],
			'text_hash'   => $consent['text_hash'],
		);
	}

	/**
	 * An absolute https URL (http only for local development hosts), or null.
	 *
	 * @param mixed $url Candidate.
	 * @return string|null
	 */
	private static function acceptable_url( $url ): ?string {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return null;
		}

		$url   = rtrim( trim( $url ), '/' );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return null;
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		$local  = 'localhost' === $host || str_ends_with( $host, '.test' ) || str_ends_with( $host, '.localhost' );

		return 'https' === $scheme || ( 'http' === $scheme && $local ) ? $url : null;
	}

	/**
	 * A string-keyed array (empty for anything else).
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 */
	private static function assoc( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$assoc = array();
		foreach ( $value as $key => $item ) {
			$assoc[ (string) $key ] = $item;
		}

		return $assoc;
	}
}
