<?php
/**
 * Fake Pixalink server: WordPress integration.
 *
 * @package Pixalink\Loyalty\Tests
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Tests\FakeServer;

/**
 * Serves /api/commerce/v1 from WP REST routes for local, e2e and integration tests.
 *
 * - API routes:      /wp-json/fake-pixalink/api/commerce/v1/*  (signature-checked like production).
 * - Control routes:  /wp-json/fake-pixalink/v1/{requests,script,clock}  (manage_options only).
 * - Outgoing calls from the plugin to https://fake-pixalink.test/api/commerce/v1/* are dispatched in-process
 *   via pre_http_request, so no loopback networking is needed inside containers.
 */
final class Server {

	public const API_NAMESPACE     = 'fake-pixalink/api/commerce/v1';
	public const CONTROL_NAMESPACE = 'fake-pixalink/v1';
	public const HOST              = 'fake-pixalink.test';
	public const BASE_PATH         = '/api/commerce/v1/';

	private const REQUESTS_TRANSIENT = 'pixalink_loyalty_fake_requests';
	private const SCRIPT_TRANSIENT   = 'pixalink_loyalty_fake_script';
	private const CLOCK_TRANSIENT    = 'pixalink_loyalty_fake_clock_offset';
	private const EVENTS_TRANSIENT   = 'pixalink_loyalty_fake_seen_events';
	private const MAX_RECORDED       = 200;

	/**
	 * Route registrations: [regex, methods, route template, authenticated].
	 */
	private const API_ROUTES = array(
		array( 'connect/exchange', 'POST', 'connect/exchange', false ),
		array( 'connection/heartbeat', 'POST', 'connection/heartbeat', true ),
		array( 'outlet', 'GET', 'outlet', true ),
		array( 'customers', 'GET', 'customers', true ),
		array( 'customers', 'POST', 'customers', true ),
		array( 'customers/(?P<id>[A-Za-z0-9-]+)', 'GET', 'customers/{id}', true ),
		array( 'customers/(?P<id>[A-Za-z0-9-]+)/otp', 'POST', 'customers/{id}/otp', true ),
		array( 'customers/(?P<id>[A-Za-z0-9-]+)/otp/verify', 'POST', 'customers/{id}/otp/verify', true ),
		array( 'quote', 'POST', 'quote', true ),
		array( 'orders/(?P<order_ref>[A-Za-z0-9_-]+)/redemptions', 'PUT', 'orders/{order_ref}/redemptions', true ),
		array( 'orders/(?P<order_ref>[A-Za-z0-9_-]+)/redemptions', 'DELETE', 'orders/{order_ref}/redemptions', true ),
		array( 'events', 'POST', 'events', true ),
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_filter( 'pre_http_request', array( self::class, 'intercept' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( self::class, 'format_errors' ), 10, 3 );
	}

	/**
	 * Register API and control routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$grouped = array();
		foreach ( self::API_ROUTES as $definition ) {
			$grouped[ $definition[0] ][] = $definition;
		}

		foreach ( $grouped as $pattern => $definitions ) {
			$endpoints = array();
			foreach ( $definitions as $definition ) {
				$route       = $definition[2];
				$endpoints[] = array(
					'methods'             => $definition[1],
					'callback'            => static fn ( \WP_REST_Request $request ): \WP_REST_Response => self::handle( $route, $request ),
					'permission_callback' => $definition[3] ? array( self::class, 'authorize' ) : array( self::class, 'allow_unauthenticated' ),
				);
			}
			register_rest_route( self::API_NAMESPACE, '/' . $pattern, $endpoints );
		}

		$admin_only = static fn (): bool => current_user_can( 'manage_options' );

		register_rest_route(
			self::CONTROL_NAMESPACE,
			'/requests',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => static fn (): \WP_REST_Response => new \WP_REST_Response( array( 'requests' => self::recorded() ) ),
					'permission_callback' => $admin_only,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => static function (): \WP_REST_Response {
						self::reset();
						return new \WP_REST_Response( array( 'reset' => true ) );
					},
					'permission_callback' => $admin_only,
				),
			)
		);

		register_rest_route(
			self::CONTROL_NAMESPACE,
			'/script',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'add_script' ),
				'permission_callback' => $admin_only,
			)
		);

		register_rest_route(
			self::CONTROL_NAMESPACE,
			'/clock',
			array(
				'methods'             => 'POST',
				'callback'            => static function ( \WP_REST_Request $request ): \WP_REST_Response {
					$offset = (int) $request->get_param( 'offset' );
					set_transient( self::CLOCK_TRANSIENT, $offset, HOUR_IN_SECONDS );
					return new \WP_REST_Response( array( 'offset' => $offset ) );
				},
				'permission_callback' => $admin_only,
			)
		);
	}

	/**
	 * Permission callback for signed API routes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function authorize( \WP_REST_Request $request ) {
		$path  = self::relative_path( $request );
		$error = Signature::check( $request->get_method(), $path, self::headers( $request ), $request->get_body(), self::now(), self::api_key() );
		if ( null === $error ) {
			return true;
		}

		[ $code, $status ] = $error;
		$data              = array( 'status' => $status );
		if ( 'timestamp_skew' === $code ) {
			$data['server_time'] = self::now();
		}

		self::record( $request, $path, $status, $code );

		return new \WP_Error( $code, 'Fake Pixalink rejected the request: ' . $code . '.', $data );
	}

	/**
	 * Permission callback for connect/exchange, which is unauthenticated by design (the one-time code is the credential).
	 *
	 * @return bool Whether the fake server is enabled.
	 */
	public static function allow_unauthenticated(): bool {
		return self::is_enabled();
	}

	/**
	 * Whether the fake server may answer (explicit constant, or a local/development environment).
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		if ( defined( 'PIXALINK_FAKE_SERVER' ) ) {
			return (bool) constant( 'PIXALINK_FAKE_SERVER' );
		}

		return in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
	}

	/**
	 * Route callback.
	 *
	 * @param string           $route   Route template.
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle( string $route, \WP_REST_Request $request ): \WP_REST_Response {
		$method  = $request->get_method();
		$path    = self::relative_path( $request );
		$decoded = json_decode( $request->get_body(), true );
		$body    = is_array( $decoded ) ? $decoded : array();
		$query   = $request->get_query_params();

		$result = self::next_script( $method, $path );
		if ( null === $result ) {
			$violations = self::contract_violations( $method, $route, $request->get_body(), $query );
			if ( array() !== $violations ) {
				$result = Responses::error( 422, 'contract_violation', 'Request does not match the contract schema.', array( 'errors' => array( 'body' => $violations ) ) );
			} else {
				$context = array(
					'now'        => self::now(),
					'api_key'    => self::api_key(),
					'event_seen' => 'events' === $route && self::event_seen( (string) ( $body['id'] ?? '' ) ),
				);
				$result  = Responses::respond( $method, $route, $request->get_url_params(), $query, $body, $context );
			}
		}

		if ( 'events' === $route && $result['status'] < 300 ) {
			self::remember_event( (string) ( $body['id'] ?? '' ) );
		}

		self::record( $request, $path, $result['status'], null );

		$response = new \WP_REST_Response( $result['body'], $result['status'] );
		if ( isset( $result['headers'] ) && is_array( $result['headers'] ) ) {
			foreach ( $result['headers'] as $name => $value ) {
				$response->header( (string) $name, (string) $value );
			}
		}

		return $response;
	}

	/**
	 * Re-shape WP_Error responses on API routes into the Pixalink envelope {message, code, ...}.
	 *
	 * @param \WP_HTTP_Response $result  Response.
	 * @param \WP_REST_Server   $server  Server.
	 * @param \WP_REST_Request  $request Request.
	 * @return \WP_HTTP_Response
	 */
	public static function format_errors( $result, $server, $request ) {
		unset( $server );
		if ( ! $result instanceof \WP_HTTP_Response || 0 !== strpos( $request->get_route(), '/' . self::API_NAMESPACE ) || $result->get_status() < 400 ) {
			return $result;
		}

		$data = $result->get_data();
		if ( ! is_array( $data ) || ! isset( $data['code'], $data['message'] ) || ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
			return $result;
		}

		$extra = $data['data'];
		unset( $extra['status'] );
		$result->set_data(
			array(
				'message' => (string) $data['message'],
				'code'    => (string) $data['code'],
			) + $extra
		);

		return $result;
	}

	/**
	 * Answer plugin calls to https://fake-pixalink.test/api/commerce/v1/* in-process.
	 *
	 * @param false|array<mixed>|\WP_Error $preempt Earlier short-circuit value.
	 * @param array<string,mixed>          $args    HTTP request arguments.
	 * @param string                       $url     Request URL.
	 * @return false|array<mixed>|\WP_Error
	 */
	public static function intercept( $preempt, $args, $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || self::HOST !== ( $parts['host'] ?? '' ) ) {
			return $preempt;
		}

		$path = $parts['path'] ?? '/';
		if ( 0 !== strpos( $path, self::BASE_PATH ) ) {
			return self::http_response(
				404,
				array(
					'message' => 'Not found.',
					'code'    => 'not_found',
				),
				array()
			);
		}

		$method  = isset( $args['method'] ) && is_string( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';
		$request = new \WP_REST_Request( $method, '/' . self::API_NAMESPACE . '/' . substr( $path, strlen( self::BASE_PATH ) ) );

		$headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
		foreach ( $headers as $name => $value ) {
			$request->set_header( (string) $name, is_array( $value ) ? implode( ',', $value ) : (string) $value );
		}
		if ( isset( $args['user-agent'] ) && is_string( $args['user-agent'] ) ) {
			$request->set_header( 'user-agent', $args['user-agent'] );
		}

		if ( isset( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			$request->set_query_params( $query );
		}
		$request->set_body( isset( $args['body'] ) && is_string( $args['body'] ) ? $args['body'] : '' );

		// rest_do_request() skips rest_post_dispatch (only serve_request() applies it), so format errors here.
		$server   = rest_get_server();
		$response = self::format_errors( rest_do_request( $request ), $server, $request );
		$response = $response instanceof \WP_REST_Response ? $response : rest_ensure_response( $response );
		$data     = $server->response_to_data( $response, false );

		return self::http_response( $response->get_status(), $data, $response->get_headers() );
	}

	/**
	 * Queue a scripted response: {method, path (fnmatch pattern), status, body, headers?, times?}.
	 *
	 * @param \WP_REST_Request $request Control request.
	 * @return \WP_REST_Response
	 */
	public static function add_script( \WP_REST_Request $request ): \WP_REST_Response {
		$params  = $request->get_json_params();
		$params  = is_array( $params ) ? $params : array();
		$scripts = self::scripts();

		$scripts[] = array(
			'method'  => strtoupper( (string) ( $params['method'] ?? 'POST' ) ),
			'path'    => (string) ( $params['path'] ?? '' ),
			'status'  => (int) ( $params['status'] ?? 200 ),
			'body'    => $params['body'] ?? new \stdClass(),
			'headers' => isset( $params['headers'] ) && is_array( $params['headers'] ) ? $params['headers'] : array(),
			'times'   => max( 1, (int) ( $params['times'] ?? 1 ) ),
		);
		set_transient( self::SCRIPT_TRANSIENT, $scripts, HOUR_IN_SECONDS );

		return new \WP_REST_Response( array( 'queued' => count( $scripts ) ), 201 );
	}

	/**
	 * Recorded API requests, oldest first.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function recorded(): array {
		$recorded = get_transient( self::REQUESTS_TRANSIENT );

		return is_array( $recorded ) ? array_values( $recorded ) : array();
	}

	/**
	 * Clear recordings, scripts, the fake clock and seen events.
	 *
	 * @return void
	 */
	public static function reset(): void {
		delete_transient( self::REQUESTS_TRANSIENT );
		delete_transient( self::SCRIPT_TRANSIENT );
		delete_transient( self::CLOCK_TRANSIENT );
		delete_transient( self::EVENTS_TRANSIENT );
	}

	/**
	 * Fake server time (real time plus the scripted offset).
	 *
	 * @return int
	 */
	public static function now(): int {
		return time() + (int) get_transient( self::CLOCK_TRANSIENT );
	}

	/**
	 * API key the fake server accepts.
	 *
	 * @return string
	 */
	public static function api_key(): string {
		if ( defined( 'PIXALINK_FAKE_SERVER_API_KEY' ) && is_string( constant( 'PIXALINK_FAKE_SERVER_API_KEY' ) ) ) {
			return constant( 'PIXALINK_FAKE_SERVER_API_KEY' );
		}

		return Signature::FAKE_API_KEY;
	}

	/**
	 * Build a wp_remote_request()-shaped response.
	 *
	 * @param int                 $status  HTTP status.
	 * @param mixed               $data    Body data.
	 * @param array<string,mixed> $headers Headers.
	 * @return array<string,mixed>
	 */
	private static function http_response( int $status, $data, array $headers ): array {
		$headers = array_change_key_case( $headers, CASE_LOWER ) + array(
			'content-type' => 'application/json; charset=utf-8',
			'date'         => gmdate( 'D, d M Y H:i:s', self::now() ) . ' GMT',
		);

		return array(
			'headers'  => class_exists( '\WpOrg\Requests\Utility\CaseInsensitiveDictionary' ) ? new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers ) : $headers,
			'body'     => (string) wp_json_encode( $data ),
			'response' => array(
				'code'    => $status,
				'message' => get_status_header_desc( $status ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Path relative to the API namespace, e.g. "customers/5001/otp".
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string
	 */
	private static function relative_path( \WP_REST_Request $request ): string {
		return ltrim( substr( $request->get_route(), strlen( '/' . self::API_NAMESPACE ) ), '/' );
	}

	/**
	 * Request headers keyed by lower-case, dash-separated name.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,string>
	 */
	private static function headers( \WP_REST_Request $request ): array {
		$headers = array();
		foreach ( $request->get_headers() as $name => $values ) {
			$headers[ str_replace( '_', '-', strtolower( (string) $name ) ) ] = implode( ',', (array) $values );
		}

		return $headers;
	}

	/**
	 * Request/query contract violations (empty when opis/json-schema is not installed).
	 *
	 * @param string              $method   HTTP method.
	 * @param string              $route    Route template.
	 * @param string              $raw_body Raw body.
	 * @param array<string,mixed> $query    Query parameters.
	 * @return list<string>
	 */
	private static function contract_violations( string $method, string $route, string $raw_body, array $query ): array {
		if ( ! ContractValidator::is_available() ) {
			return array();
		}

		$endpoint = ContractValidator::endpoint( $method, $route );
		if ( null === $endpoint ) {
			return array();
		}

		$violations = array();
		if ( null !== $endpoint['request'] ) {
			$violations = ContractValidator::validate( $endpoint['request'], json_decode( '' === $raw_body ? 'null' : $raw_body ) );
		}
		if ( isset( $endpoint['query'] ) ) {
			$violations = array_merge( $violations, ContractValidator::validate( $endpoint['query'], (object) $query ) );
		}

		return $violations;
	}

	/**
	 * Pop the next scripted response matching a request.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Relative path.
	 * @return array{status:int,body:mixed,headers:array<string,string>}|null
	 */
	private static function next_script( string $method, string $path ): ?array {
		$scripts = self::scripts();
		foreach ( $scripts as $index => $script ) {
			if ( $script['method'] !== $method || ! fnmatch( $script['path'], $path ) ) {
				continue;
			}

			$match = array(
				'status'  => $script['status'],
				'body'    => $script['body'],
				'headers' => $script['headers'],
			);

			--$scripts[ $index ]['times'];
			if ( $scripts[ $index ]['times'] < 1 ) {
				unset( $scripts[ $index ] );
			}
			set_transient( self::SCRIPT_TRANSIENT, array_values( $scripts ), HOUR_IN_SECONDS );

			return $match;
		}

		return null;
	}

	/**
	 * Queued scripts.
	 *
	 * @return list<array{method:string,path:string,status:int,body:mixed,headers:array<string,string>,times:int}>
	 */
	private static function scripts(): array {
		$scripts = get_transient( self::SCRIPT_TRANSIENT );

		return is_array( $scripts ) ? array_values( $scripts ) : array();
	}

	/**
	 * Whether an event id was already processed.
	 *
	 * @param string $event_id Event id.
	 * @return bool
	 */
	private static function event_seen( string $event_id ): bool {
		$seen = get_transient( self::EVENTS_TRANSIENT );

		return '' !== $event_id && is_array( $seen ) && in_array( $event_id, $seen, true );
	}

	/**
	 * Remember a processed event id.
	 *
	 * @param string $event_id Event id.
	 * @return void
	 */
	private static function remember_event( string $event_id ): void {
		if ( '' === $event_id ) {
			return;
		}

		$seen   = get_transient( self::EVENTS_TRANSIENT );
		$seen   = is_array( $seen ) ? $seen : array();
		$seen[] = $event_id;
		set_transient( self::EVENTS_TRANSIENT, array_slice( array_values( array_unique( $seen ) ), -1000 ), HOUR_IN_SECONDS );
	}

	/**
	 * Record a request for assertions (secrets masked).
	 *
	 * @param \WP_REST_Request $request    Request.
	 * @param string           $path       Relative path.
	 * @param int              $status     Response status.
	 * @param string|null      $auth_error Authentication error code, if rejected.
	 * @return void
	 */
	private static function record( \WP_REST_Request $request, string $path, int $status, ?string $auth_error ): void {
		$headers = self::headers( $request );
		$kept    = array();
		foreach ( $headers as $name => $value ) {
			if ( 0 === strpos( $name, 'x-pixalink-' ) || in_array( $name, array( 'idempotency-key', 'content-type', 'user-agent' ), true ) ) {
				$kept[ $name ] = $value;
			}
		}
		$kept['authorization'] = isset( $headers['authorization'] ) ? 'Bearer ****' . substr( $headers['authorization'], -4 ) : null;

		$decoded = json_decode( $request->get_body(), true );

		$recorded   = self::recorded();
		$recorded[] = array(
			'method'     => $request->get_method(),
			'path'       => $path,
			'query'      => $request->get_query_params(),
			'headers'    => $kept,
			'body'       => null === $decoded ? $request->get_body() : $decoded,
			'status'     => $status,
			'auth_error' => $auth_error,
			'at'         => gmdate( 'Y-m-d\TH:i:s\Z', self::now() ),
		);

		set_transient( self::REQUESTS_TRANSIENT, array_slice( $recorded, -self::MAX_RECORDED ), HOUR_IN_SECONDS );
	}
}
