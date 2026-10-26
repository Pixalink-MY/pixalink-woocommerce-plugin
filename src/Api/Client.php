<?php
/**
 * HTTP client for the Pixalink Commerce API.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Api;

use Pixalink\Loyalty\Support\Clock;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Support\Uuid;

defined( 'ABSPATH' ) || exit;

/**
 * Signed JSON requests to `/api/commerce/v1` over wp_remote_request().
 *
 * Every authenticated request carries `Authorization: Bearer {api key}`, `X-Pixalink-Timestamp`,
 * `X-Pixalink-Signature`, `X-Pixalink-Site`, `X-Pixalink-Plugin` and a descriptive User-Agent. Requests to
 * `/events*` add `X-Pixalink-Event-Id`; other mutations add `Idempotency-Key`. A 401 `timestamp_skew` response
 * re-aligns {@see Clock} from the server's time and is retried exactly once.
 */
final class Client {

	public const DEFAULT_BASE_URL  = 'https://explore.pixalink.io/api/commerce/v1';
	public const BASE_URL_CONSTANT = 'PIXALINK_LOYALTY_API_BASE';

	/**
	 * Lookups and summaries (My Account, admin screens).
	 */
	public const ENDPOINT_READ = 'read';

	/**
	 * Calls on the shopper's checkout path (quote, holds); kept short so checkout never hangs.
	 */
	public const ENDPOINT_CHECKOUT = 'checkout';

	/**
	 * Other mutations (customers, OTP).
	 */
	public const ENDPOINT_WRITE = 'write';

	/**
	 * Background event delivery from the outbox.
	 */
	public const ENDPOINT_EVENTS = 'events';

	/**
	 * Connect exchange and heartbeat (admin, background).
	 */
	public const ENDPOINT_CONNECT = 'connect';

	/**
	 * Default timeouts in seconds per endpoint class.
	 */
	private const TIMEOUTS = array(
		self::ENDPOINT_READ     => 8,
		self::ENDPOINT_CHECKOUT => 6,
		self::ENDPOINT_WRITE    => 10,
		self::ENDPOINT_EVENTS   => 15,
		self::ENDPOINT_CONNECT  => 20,
	);

	private const MUTATING_METHODS = array( 'POST', 'PUT', 'PATCH', 'DELETE' );

	/**
	 * Credentials.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Server-aligned clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Option storage.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Plugin version reported to the server.
	 *
	 * @var string
	 */
	private string $plugin_version;

	/**
	 * Constructor.
	 *
	 * @param Credentials $credentials    Credentials.
	 * @param Clock       $clock          Server-aligned clock.
	 * @param Logger      $logger         Logger.
	 * @param Options     $options        Option storage.
	 * @param string      $plugin_version Plugin version reported in X-Pixalink-Plugin.
	 */
	public function __construct( Credentials $credentials, Clock $clock, Logger $logger, Options $options, string $plugin_version ) {
		$this->credentials    = $credentials;
		$this->clock          = $clock;
		$this->logger         = $logger;
		$this->options        = $options;
		$this->plugin_version = $plugin_version;
	}

	/**
	 * API base URL without trailing slash.
	 *
	 * Precedence: `PIXALINK_LOYALTY_API_BASE` constant, then the `pixalink_loyalty_api_base` filter, then the
	 * `pixalink_loyalty_api_base` option, then {@see Client::DEFAULT_BASE_URL}.
	 *
	 * @return string
	 */
	public function base_url(): string {
		if ( defined( self::BASE_URL_CONSTANT ) ) {
			$constant = constant( self::BASE_URL_CONSTANT );
			if ( is_string( $constant ) && '' !== trim( $constant ) ) {
				return rtrim( trim( $constant ), '/' );
			}
		}

		$stored = trim( $this->options->get_string( Options::API_BASE ) );

		/**
		 * Filters the Pixalink Commerce API base URL (ignored when PIXALINK_LOYALTY_API_BASE is defined).
		 *
		 * @since 0.1.0
		 *
		 * @param string $base_url Base URL from the option, or the production default.
		 */
		$base_url = apply_filters( 'pixalink_loyalty_api_base', '' !== $stored ? $stored : self::DEFAULT_BASE_URL );

		return rtrim( is_string( $base_url ) && '' !== trim( $base_url ) ? trim( $base_url ) : self::DEFAULT_BASE_URL, '/' );
	}

	/**
	 * GET request.
	 *
	 * @param string              $path           Path relative to the base URL, e.g. "customers".
	 * @param array<string,mixed> $query          Query parameters.
	 * @param string              $endpoint_class One of the ENDPOINT_* constants.
	 * @return ApiResponse
	 */
	public function get( string $path, array $query = array(), string $endpoint_class = self::ENDPOINT_READ ): ApiResponse {
		return $this->request(
			'GET',
			$path,
			array(
				'query'          => $query,
				'endpoint_class' => $endpoint_class,
			)
		);
	}

	/**
	 * POST request.
	 *
	 * @param string              $path    Path.
	 * @param array<string,mixed> $body    JSON body.
	 * @param array<string,mixed> $options See {@see Client::request()}.
	 * @return ApiResponse
	 */
	public function post( string $path, array $body, array $options = array() ): ApiResponse {
		return $this->request( 'POST', $path, array( 'body' => $body ) + $options );
	}

	/**
	 * PUT request.
	 *
	 * @param string              $path    Path.
	 * @param array<string,mixed> $body    JSON body.
	 * @param array<string,mixed> $options See {@see Client::request()}.
	 * @return ApiResponse
	 */
	public function put( string $path, array $body, array $options = array() ): ApiResponse {
		return $this->request( 'PUT', $path, array( 'body' => $body ) + $options );
	}

	/**
	 * DELETE request.
	 *
	 * @param string              $path    Path.
	 * @param array<string,mixed> $options See {@see Client::request()}.
	 * @return ApiResponse
	 */
	public function delete( string $path, array $options = array() ): ApiResponse {
		return $this->request( 'DELETE', $path, $options );
	}

	/**
	 * Deliver one event envelope to POST /events (the envelope id becomes X-Pixalink-Event-Id).
	 *
	 * @param array<string,mixed> $envelope Event envelope `{id, type, version, occurred_at, sequence, site_url, outlet_id, data}`.
	 * @return ApiResponse
	 * @throws \InvalidArgumentException When the envelope has no id.
	 */
	public function send_event( array $envelope ): ApiResponse {
		if ( ! isset( $envelope['id'] ) || ! is_string( $envelope['id'] ) || '' === $envelope['id'] ) {
			throw new \InvalidArgumentException( 'Event envelope must carry a string id.' );
		}

		return $this->request(
			'POST',
			'events',
			array(
				'body'     => $envelope,
				'event_id' => $envelope['id'],
			)
		);
	}

	/**
	 * Send a request.
	 *
	 * @param string              $method  HTTP method.
	 * @param string              $path    Path relative to the base URL.
	 * @param array<string,mixed> $options {
	 *     Optional.
	 *
	 *     @type array<string,mixed>|null $body            JSON body (null for none).
	 *     @type array<string,mixed>      $query           Query parameters.
	 *     @type string                   $event_id        Nonce for /events (required there).
	 *     @type string                   $idempotency_key Nonce for other mutations (generated when absent).
	 *     @type string                   $endpoint_class  One of the ENDPOINT_* constants (inferred when absent).
	 *     @type bool                     $authenticated   Send credentials and a signature (default true).
	 * }
	 * @return ApiResponse
	 * @throws ApiException On transport errors, non-2xx responses or unreadable bodies.
	 */
	public function request( string $method, string $path, array $options = array() ): ApiResponse {
		$method        = strtoupper( $method );
		$path          = ltrim( $path, '/' );
		$authenticated = ! array_key_exists( 'authenticated', $options ) || (bool) $options['authenticated'];
		$body          = isset( $options['body'] ) && is_array( $options['body'] ) ? $options['body'] : null;
		$raw_body      = null === $body ? '' : self::encode_json( $body );
		$query         = isset( $options['query'] ) && is_array( $options['query'] ) ? $options['query'] : array();
		$class         = isset( $options['endpoint_class'] ) && is_string( $options['endpoint_class'] ) ? $options['endpoint_class'] : self::infer_endpoint_class( $method, $path );
		$url           = $this->base_url() . '/' . $path . ( array() === $query ? '' : '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) );
		$nonce         = $this->resolve_nonce( $method, $path, $options );

		$api_key     = null;
		$signing_key = null;
		if ( $authenticated ) {
			$api_key     = $this->credentials->api_key();
			$signing_key = $this->credentials->signing_key();
			if ( null === $api_key || null === $signing_key ) {
				throw ApiException::not_connected();
			}
		}

		$attempt = 0;
		while ( true ) {
			++$attempt;
			$timestamp = $this->clock->now();
			$headers   = $this->build_headers( $method, $path, $nonce, $raw_body, $timestamp, $api_key, $signing_key );

			try {
				return $this->send( $method, $url, $path, $headers, $raw_body, $class );
			} catch ( ApiException $exception ) {
				$server_time = $exception->server_time();
				if ( 1 === $attempt && 401 === $exception->http_status() && 'timestamp_skew' === $exception->error_code() && null !== $server_time ) {
					$offset = $this->clock->sync_to( $server_time );
					$this->logger->warning(
						'Pixalink rejected the request timestamp; clock re-aligned and retrying once.',
						array(
							'path'   => $path,
							'offset' => $offset,
						)
					);
					continue;
				}

				throw $exception;
			}
		}
	}

	/**
	 * Default endpoint class for a request.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path.
	 * @return string
	 */
	public static function infer_endpoint_class( string $method, string $path ): string {
		if ( self::is_events_path( $path ) ) {
			return self::ENDPOINT_EVENTS;
		}

		if ( 0 === strpos( $path, 'connect/' ) || 0 === strpos( $path, 'connection/' ) ) {
			return self::ENDPOINT_CONNECT;
		}

		if ( 'quote' === $path || 1 === preg_match( '#^orders/[^/]+/redemptions$#', $path ) ) {
			return self::ENDPOINT_CHECKOUT;
		}

		return in_array( $method, self::MUTATING_METHODS, true ) ? self::ENDPOINT_WRITE : self::ENDPOINT_READ;
	}

	/**
	 * Whether a path is the events endpoint (nonce = event id).
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function is_events_path( string $path ): bool {
		return 'events' === $path || 0 === strpos( $path, 'events/' );
	}

	/**
	 * Perform one HTTP exchange.
	 *
	 * @param string               $method   HTTP method.
	 * @param string               $url      Absolute URL.
	 * @param string               $path     Path (for logs).
	 * @param array<string,string> $headers  Request headers.
	 * @param string               $raw_body Raw body.
	 * @param string               $endpoint_class Endpoint class.
	 * @return ApiResponse
	 * @throws ApiException On failure.
	 */
	private function send( string $method, string $url, string $path, array $headers, string $raw_body, string $endpoint_class ): ApiResponse {
		$user_agent = $headers['User-Agent'];
		unset( $headers['User-Agent'] );

		$args = array(
			'method'      => $method,
			'timeout'     => $this->timeout( $endpoint_class, $path ),
			'redirection' => 0,
			'headers'     => $headers,
			'user-agent'  => $user_agent,
		);
		if ( '' !== $raw_body ) {
			$args['body'] = $raw_body;
		}

		$started  = microtime( true );
		$response = wp_remote_request( $url, $args );
		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			$this->logger->warning(
				'Pixalink API request failed before a response was received.',
				array(
					'method'      => $method,
					'path'        => $path,
					'duration_ms' => $duration,
					'error'       => $response->get_error_message(),
				)
			);

			$exception = ApiException::network( (string) $response->get_error_code(), $response->get_error_message() );
			throw $exception;
		}

		$status           = (int) wp_remote_retrieve_response_code( $response );
		$raw              = (string) wp_remote_retrieve_body( $response );
		$decoded          = '' === trim( $raw ) ? array() : json_decode( $raw, true );
		$data             = is_array( $decoded ) ? $decoded : null;
		$response_headers = array(
			'date'        => self::header_string( wp_remote_retrieve_header( $response, 'date' ) ),
			'retry-after' => self::header_string( wp_remote_retrieve_header( $response, 'retry-after' ) ),
		);

		$this->logger->debug(
			'Pixalink API request completed.',
			array(
				'method'      => $method,
				'path'        => $path,
				'status'      => $status,
				'duration_ms' => $duration,
			)
		);

		if ( 204 === $status ) {
			return new ApiResponse( $status, array(), array_filter( $response_headers ) );
		}

		if ( $status >= 200 && $status < 300 ) {
			if ( null === $data ) {
				$exception = ApiException::invalid_response( $status );
				throw $exception;
			}

			return new ApiResponse( $status, $data, array_filter( $response_headers ) );
		}

		$exception = self::exception_for( $status, $data ?? array(), $response_headers );
		$this->logger->warning(
			'Pixalink API returned an error.',
			array(
				'method'     => $method,
				'path'       => $path,
				'status'     => $status,
				'error_code' => $exception->error_code(),
			)
		);

		if ( $exception->is_commerce_paused() ) {
			/**
			 * Fires when Pixalink answers 403 `commerce_paused`: staff paused this outlet's store.
			 *
			 * The heartbeat keeps the paused state ({@see \Pixalink\Loyalty\Connect\Heartbeat}) until it reports the
			 * store active again.
			 *
			 * @since 0.3.0
			 *
			 * @param string $path Request path relative to the API base.
			 */
			do_action( 'pixalink_loyalty_commerce_paused', $path );
		}

		throw $exception;
	}

	/**
	 * Build an ApiException from an error response.
	 *
	 * @param int                     $status  HTTP status.
	 * @param array<int|string,mixed> $body    Decoded body (may be empty).
	 * @param array<string,string>    $headers Lower-case response headers.
	 * @return ApiException
	 */
	public static function exception_for( int $status, array $body, array $headers ): ApiException {
		$code = 'http_' . $status;
		if ( isset( $body['code'] ) && is_string( $body['code'] ) && '' !== $body['code'] ) {
			$code = $body['code'];
		} elseif ( isset( $body['error'] ) && is_string( $body['error'] ) && '' !== $body['error'] ) {
			// OAuth-style envelope (`{"error":"invalid_grant"}`), accepted for connect/exchange.
			$code = $body['error'];
		}
		$message = isset( $body['message'] ) && is_string( $body['message'] ) ? $body['message'] : '';

		$server_time = Clock::parse_server_time( $body['server_time'] ?? null );
		if ( null === $server_time && 'timestamp_skew' === $code ) {
			$server_time = Clock::parse_server_time( $headers['date'] ?? null );
		}

		$details = array();
		foreach ( $body as $key => $value ) {
			if ( is_string( $key ) && ! in_array( $key, array( 'code', 'message', 'error' ), true ) ) {
				$details[ $key ] = $value;
			}
		}

		return new ApiException( $code, $status, self::parse_retry_after( $headers['retry-after'] ?? '' ), $details, $server_time, $message );
	}

	/**
	 * Request headers (User-Agent is split off into the wp_remote_request arg by send()).
	 *
	 * @param string      $method      HTTP method.
	 * @param string      $path        Path.
	 * @param string      $nonce       Signature nonce.
	 * @param string      $raw_body    Raw body.
	 * @param int         $timestamp   Unix seconds on the server clock.
	 * @param string|null $api_key     API key (null for unauthenticated calls).
	 * @param string|null $signing_key Signing key (null for unauthenticated calls).
	 * @return array<string,string>
	 */
	private function build_headers( string $method, string $path, string $nonce, string $raw_body, int $timestamp, ?string $api_key, ?string $signing_key ): array {
		$headers = array(
			'Accept'            => 'application/json',
			'User-Agent'        => $this->user_agent(),
			'X-Pixalink-Site'   => $this->site_url(),
			'X-Pixalink-Plugin' => 'pixalink-loyalty-for-woocommerce/' . $this->plugin_version,
		);

		if ( '' !== $raw_body ) {
			$headers['Content-Type'] = 'application/json; charset=utf-8';
		}

		if ( self::is_events_path( $path ) ) {
			$headers['X-Pixalink-Event-Id'] = $nonce;
		} elseif ( Signer::READ_NONCE !== $nonce ) {
			$headers['Idempotency-Key'] = $nonce;
		}

		if ( null !== $api_key && null !== $signing_key ) {
			$headers['Authorization']        = 'Bearer ' . $api_key;
			$headers['X-Pixalink-Timestamp'] = (string) $timestamp;
			$headers['X-Pixalink-Signature'] = Signer::header_value( $signing_key, $timestamp, $nonce, $raw_body );
		}

		return $headers;
	}

	/**
	 * Signature nonce: event id for /events, Idempotency-Key for mutations, "-" for reads.
	 *
	 * @param string              $method  HTTP method.
	 * @param string              $path    Path.
	 * @param array<string,mixed> $options Request options.
	 * @return string
	 * @throws \InvalidArgumentException When an /events request has no event id, or a nonce has the wrong format.
	 */
	private function resolve_nonce( string $method, string $path, array $options ): string {
		if ( self::is_events_path( $path ) ) {
			if ( ! isset( $options['event_id'] ) || ! is_string( $options['event_id'] ) || ! Signer::is_valid_nonce( $options['event_id'] ) ) {
				throw new \InvalidArgumentException( 'Requests to /events must carry an event_id matching ' . esc_html( Signer::NONCE_PATTERN ) . '.' );
			}

			return $options['event_id'];
		}

		if ( ! in_array( $method, self::MUTATING_METHODS, true ) ) {
			return Signer::READ_NONCE;
		}

		if ( ! isset( $options['idempotency_key'] ) ) {
			return Uuid::v4();
		}

		if ( ! is_string( $options['idempotency_key'] ) || ! Signer::is_valid_nonce( $options['idempotency_key'] ) ) {
			throw new \InvalidArgumentException( 'Idempotency keys must match ' . esc_html( Signer::NONCE_PATTERN ) . '.' );
		}

		return $options['idempotency_key'];
	}

	/**
	 * Timeout in seconds for an endpoint class.
	 *
	 * @param string $endpoint_class Endpoint class.
	 * @param string $path           Path.
	 * @return int
	 */
	private function timeout( string $endpoint_class, string $path ): int {
		$default = self::TIMEOUTS[ $endpoint_class ] ?? self::TIMEOUTS[ self::ENDPOINT_WRITE ];

		/**
		 * Filters the timeout (seconds) of a Pixalink API request.
		 *
		 * @since 0.1.0
		 *
		 * @param int    $timeout        Default timeout for the endpoint class.
		 * @param string $endpoint_class One of read, checkout, write, events, connect.
		 * @param string $path           Request path relative to the API base.
		 */
		$timeout = apply_filters( 'pixalink_loyalty_api_timeout', $default, $endpoint_class, $path );

		return is_numeric( $timeout ) && (int) $timeout > 0 ? (int) $timeout : $default;
	}

	/**
	 * Store URL sent as X-Pixalink-Site.
	 *
	 * @return string
	 */
	private function site_url(): string {
		return rtrim( (string) home_url(), '/' );
	}

	/**
	 * Descriptive User-Agent.
	 *
	 * @return string
	 */
	private function user_agent(): string {
		$wp_version = isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : 'unknown';
		$wc_version = defined( 'WC_VERSION' ) && is_string( constant( 'WC_VERSION' ) ) ? constant( 'WC_VERSION' ) : 'unknown';

		return sprintf(
			'PixalinkLoyaltyForWooCommerce/%s (WordPress/%s; WooCommerce/%s; PHP/%s; +%s)',
			$this->plugin_version,
			$wp_version,
			$wc_version,
			PHP_VERSION,
			$this->site_url()
		);
	}

	/**
	 * Encode a request body.
	 *
	 * @param array<string,mixed> $body Body.
	 * @return string
	 * @throws \InvalidArgumentException When the body cannot be encoded.
	 */
	private static function encode_json( array $body ): string {
		$json = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			throw new \InvalidArgumentException( 'Request body cannot be encoded as JSON.' );
		}

		return $json;
	}

	/**
	 * Seconds from a Retry-After header (delta-seconds or HTTP date).
	 *
	 * @param string $value Header value.
	 * @return int|null
	 */
	private static function parse_retry_after( string $value ): ?int {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}

		$timestamp = strtotime( $value );

		return false === $timestamp ? null : max( 0, $timestamp - time() );
	}

	/**
	 * Normalise a wp_remote_retrieve_header() result.
	 *
	 * @param mixed $value Header value (string or list of strings).
	 * @return string
	 */
	private static function header_string( $value ): string {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		return is_string( $value ) ? $value : '';
	}
}
