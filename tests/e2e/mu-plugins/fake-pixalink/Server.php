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
 * - Hosted page:     /wp-json/fake-pixalink/v1/connect/woocommerce  (stands in for {pixalink}/connect/woocommerce:
 *                    redirects back at once with a PKCE-bound code; point PIXALINK_LOYALTY_CONNECT_URL here).
 * - Control routes:  /wp-json/fake-pixalink/v1/{requests,script,clock,hosted,balance,webhook}  (manage_options only);
 *                    `balance` sets the member's points balance, `webhook` signs a server-to-store notification and
 *                    delivers it to the plugin in-process.
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
	private const TICKETS_TRANSIENT  = 'pixalink_loyalty_fake_used_tickets';
	private const SITE_TRANSIENT     = 'pixalink_loyalty_fake_connected_site';
	private const CODES_TRANSIENT    = 'pixalink_loyalty_fake_connect_codes';
	private const OUTCOME_TRANSIENT  = 'pixalink_loyalty_fake_hosted_outcome';
	private const BALANCE_TRANSIENT  = 'pixalink_loyalty_fake_points_balance';
	private const MAX_RECORDED       = 200;

	/**
	 * Outcomes the fake hosted page can be told to produce (POST /fake-pixalink/v1/hosted {outcome}).
	 */
	public const HOSTED_OUTCOMES = array( 'approve', 'lead', 'deny', 'invalid' );

	/**
	 * Prefix of codes minted by the fake hosted page (48 characters in total, like production).
	 */
	public const MINTED_CODE_PREFIX = 'fake-code-';

	/**
	 * Route registrations: [regex, methods, route template, authenticated].
	 */
	private const API_ROUTES = array(
		array( 'connect/exchange', 'POST', 'connect/exchange', false ),
		array( 'connection/heartbeat', 'POST', 'connection/heartbeat', true ),
		array( 'connection/disconnect', 'POST', 'connection/disconnect', true ),
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
			'/connect/woocommerce',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'hosted_connect' ),
				'permission_callback' => array( self::class, 'allow_unauthenticated' ),
			)
		);

		register_rest_route(
			self::CONTROL_NAMESPACE,
			'/hosted',
			array(
				'methods'             => 'POST',
				'callback'            => static function ( \WP_REST_Request $request ): \WP_REST_Response {
					$outcome = (string) $request->get_param( 'outcome' );
					if ( ! in_array( $outcome, self::HOSTED_OUTCOMES, true ) ) {
						return new \WP_REST_Response( array( 'outcomes' => self::HOSTED_OUTCOMES ), 400 );
					}
					set_transient( self::OUTCOME_TRANSIENT, $outcome, HOUR_IN_SECONDS );
					return new \WP_REST_Response( array( 'outcome' => $outcome ) );
				},
				'permission_callback' => $admin_only,
			)
		);

		register_rest_route(
			self::CONTROL_NAMESPACE,
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => static function ( \WP_REST_Request $request ): \WP_REST_Response {
					$params = $request->get_json_params();
					$params = is_array( $params ) ? $params : array();
					$data   = isset( $params['data'] ) && is_array( $params['data'] ) ? $params['data'] : array();

					return self::send_webhook( (string) ( $params['type'] ?? '' ), $data, isset( $params['id'] ) ? (string) $params['id'] : null );
				},
				'permission_callback' => $admin_only,
			)
		);

		register_rest_route(
			self::CONTROL_NAMESPACE,
			'/balance',
			array(
				'methods'             => 'POST',
				'callback'            => static function ( \WP_REST_Request $request ): \WP_REST_Response {
					self::set_points_balance( (int) $request->get_param( 'points' ) );
					return new \WP_REST_Response( array( 'points' => self::points_balance() ) );
				},
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
			} elseif ( 'connect/exchange' === $route && ! self::redeem_minted_code( $body ) ) {
				$result = Responses::error( 400, 'invalid_grant', 'The authorisation code is invalid or expired.' );
			} else {
				$context = array(
					'now'            => self::now(),
					'points_balance' => self::points_balance(),
					'api_key'        => self::api_key(),
					'event_seen'     => 'events' === $route && self::event_seen( (string) ( $body['id'] ?? '' ) ),
					'ticket_used'    => 'customers' === $route && self::ticket_used( $body ),
					'connected_site' => (string) get_transient( self::SITE_TRANSIENT ),
				);
				$result  = Responses::respond( $method, $route, $request->get_url_params(), $query, $body, $context );
			}
		}

		if ( 'events' === $route && $result['status'] < 300 ) {
			self::remember_event( (string) ( $body['id'] ?? '' ) );
		}

		// Portal sign-in tickets are single use: any ticket presented is used up, whatever the outcome.
		if ( 'customers' === $route && 'POST' === $method ) {
			self::remember_ticket( $body );
		}

		// The exchange binds the site; a heartbeat with a pasted key adopts it while unconnected; disconnect frees it.
		if ( in_array( $route, array( 'connect/exchange', 'connection/heartbeat' ), true ) && $result['status'] < 300 && isset( $body['site'] ) && is_string( $body['site'] ) ) {
			set_transient( self::SITE_TRANSIENT, rtrim( $body['site'], '/' ), DAY_IN_SECONDS );
		}
		if ( 'connection/disconnect' === $route && $result['status'] < 300 ) {
			delete_transient( self::SITE_TRANSIENT );
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
	 * Fake hosted connect page: validate the query like Pixalink, then redirect back to `return`.
	 *
	 * Outcome (POST /fake-pixalink/v1/hosted): approve (default; mints a PKCE-bound code), lead (a guest submitted
	 * the request-access form), deny (the vendor cancelled), invalid (invalid_request).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function hosted_connect( \WP_REST_Request $request ): \WP_REST_Response {
		$query = $request->get_query_params();
		unset( $query['rest_route'] ); // Present when the page is reached through ?rest_route= (plain permalinks).
		$return = isset( $query['return'] ) && is_string( $query['return'] ) ? $query['return'] : '';
		$site   = isset( $query['site'] ) && is_string( $query['site'] ) ? rtrim( $query['site'], '/' ) : '';
		$state  = isset( $query['state'] ) && is_string( $query['state'] ) ? $query['state'] : '';

		self::record( $request, 'hosted:connect/woocommerce', 302, null );

		if ( ! self::is_return_under_site( $return, $site ) ) {
			return new \WP_REST_Response(
				array(
					'message' => 'The return URL must be on the connecting site.',
					'code'    => 'invalid_return',
				),
				400
			);
		}

		$outcome = (string) get_transient( self::OUTCOME_TRANSIENT );
		$outcome = in_array( $outcome, self::HOSTED_OUTCOMES, true ) ? $outcome : 'approve';
		$valid   = ! ContractValidator::is_available() || array() === ContractValidator::validate( 'connect-authorize.query.json', (object) $query );

		if ( ! $valid || 'invalid' === $outcome ) {
			$args = array(
				'error' => 'invalid_request',
				'state' => $state,
			);
		} elseif ( 'lead' === $outcome || 'deny' === $outcome ) {
			$args = array(
				'error'  => 'access_denied',
				'reason' => 'lead' === $outcome ? 'lead_submitted' : 'user_denied',
				'state'  => $state,
			);
		} else {
			$args = array(
				'code'  => self::mint_code( (string) ( $query['code_challenge'] ?? '' ), $site ),
				'state' => $state,
			);
		}

		$response = new \WP_REST_Response( null, 302 );
		$response->header( 'Location', $return . ( false === strpos( $return, '?' ) ? '?' : '&' ) . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ) );

		return $response;
	}

	/**
	 * Mint a one-time code bound to a PKCE challenge and site (10-minute TTL, like production).
	 *
	 * @param string $challenge S256 challenge.
	 * @param string $site      Site URL.
	 * @return string
	 */
	public static function mint_code( string $challenge, string $site ): string {
		$code  = self::MINTED_CODE_PREFIX . wp_generate_password( 48 - strlen( self::MINTED_CODE_PREFIX ), false );
		$codes = get_transient( self::CODES_TRANSIENT );
		$codes = is_array( $codes ) ? $codes : array();

		$codes[ hash( 'sha256', $code ) ] = array(
			'challenge' => $challenge,
			'site'      => rtrim( $site, '/' ),
			'exp'       => self::now() + 600,
		);
		set_transient( self::CODES_TRANSIENT, $codes, HOUR_IN_SECONDS );

		return $code;
	}

	/**
	 * Burn a code minted by the hosted page and check its PKCE verifier and site.
	 *
	 * Literal codes the hosted page never minted fall through to the canned (prefix-based) behaviour, so tests can
	 * still exchange fixed codes; a minted-shaped code that is unknown (replayed or expired) fails.
	 *
	 * @param array<mixed> $body Exchange body.
	 * @return bool
	 */
	private static function redeem_minted_code( array $body ): bool {
		$code  = isset( $body['code'] ) && is_string( $body['code'] ) ? $body['code'] : '';
		$codes = get_transient( self::CODES_TRANSIENT );
		$codes = is_array( $codes ) ? $codes : array();
		$hash  = hash( 'sha256', $code );

		if ( ! isset( $codes[ $hash ] ) || ! is_array( $codes[ $hash ] ) ) {
			return 1 !== preg_match( '/^' . preg_quote( self::MINTED_CODE_PREFIX, '/' ) . '[A-Za-z0-9]{38}$/', $code );
		}

		$minted = $codes[ $hash ];
		unset( $codes[ $hash ] );
		set_transient( self::CODES_TRANSIENT, $codes, HOUR_IN_SECONDS );

		$verifier  = isset( $body['code_verifier'] ) && is_string( $body['code_verifier'] ) ? $body['code_verifier'] : '';
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7636 S256.
		$site      = isset( $body['site'] ) && is_string( $body['site'] ) ? rtrim( $body['site'], '/' ) : '';

		return (int) ( $minted['exp'] ?? 0 ) >= self::now()
			&& hash_equals( (string) ( $minted['challenge'] ?? '' ), $challenge )
			&& ( $minted['site'] ?? null ) === $site;
	}

	/**
	 * Whether `return` is same-origin with `site` and its path lies under the site's path.
	 *
	 * @param string $return_url Return URL.
	 * @param string $site       Site URL.
	 * @return bool
	 */
	private static function is_return_under_site( string $return_url, string $site ): bool {
		$return_parts = wp_parse_url( $return_url );
		$site_parts   = wp_parse_url( $site );
		if ( ! is_array( $return_parts ) || ! is_array( $site_parts ) || ! isset( $return_parts['host'], $site_parts['host'] ) ) {
			return false;
		}

		$same_origin = strtolower( $return_parts['scheme'] ?? '' ) === strtolower( $site_parts['scheme'] ?? '' )
			&& strtolower( $return_parts['host'] ) === strtolower( $site_parts['host'] )
			&& ( $return_parts['port'] ?? null ) === ( $site_parts['port'] ?? null );

		return $same_origin && 0 === strpos( $return_parts['path'] ?? '/', rtrim( $site_parts['path'] ?? '', '/' ) . '/' );
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
		delete_transient( self::TICKETS_TRANSIENT );
		delete_transient( self::SITE_TRANSIENT );
		delete_transient( self::CODES_TRANSIENT );
		delete_transient( self::OUTCOME_TRANSIENT );
		delete_transient( self::BALANCE_TRANSIENT );
	}

	/**
	 * The member's points balance (quote and hold both read it; lower it to simulate spending elsewhere).
	 *
	 * @return int
	 */
	public static function points_balance(): int {
		$balance = get_transient( self::BALANCE_TRANSIENT );

		return is_numeric( $balance ) ? max( 0, (int) $balance ) : Responses::POINTS_AVAILABLE;
	}

	/**
	 * Set the member's points balance.
	 *
	 * @param int $points Points.
	 * @return void
	 */
	public static function set_points_balance( int $points ): void {
		set_transient( self::BALANCE_TRANSIENT, max( 0, $points ), HOUR_IN_SECONDS );
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
			'body'     => 204 === $status ? '' : (string) wp_json_encode( $data ),
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
	 * Whether a POST customers body presents a ticket the server already saw.
	 *
	 * @param array<mixed> $body Request body.
	 * @return bool
	 */
	private static function ticket_used( array $body ): bool {
		$ticket = isset( $body['sso_ticket'] ) && is_string( $body['sso_ticket'] ) ? $body['sso_ticket'] : '';
		$used   = get_transient( self::TICKETS_TRANSIENT );

		return '' !== $ticket && is_array( $used ) && in_array( hash( 'sha256', $ticket ), $used, true );
	}

	/**
	 * Burn the ticket a POST customers body presents.
	 *
	 * @param array<mixed> $body Request body.
	 * @return void
	 */
	private static function remember_ticket( array $body ): void {
		$ticket = isset( $body['sso_ticket'] ) && is_string( $body['sso_ticket'] ) ? $body['sso_ticket'] : '';
		if ( '' === $ticket ) {
			return;
		}

		$used   = get_transient( self::TICKETS_TRANSIENT );
		$used   = is_array( $used ) ? $used : array();
		$used[] = hash( 'sha256', $ticket );
		set_transient( self::TICKETS_TRANSIENT, array_slice( array_values( array_unique( $used ) ), -1000 ), HOUR_IN_SECONDS );
	}

	/**
	 * Sign a webhook like Pixalink and deliver it to the plugin's route in-process.
	 *
	 * @param string       $type     Webhook type, e.g. "customer.updated".
	 * @param array<mixed> $data     Webhook data.
	 * @param string|null  $event_id Event id (random when null).
	 * @return \WP_REST_Response The plugin's response.
	 */
	public static function send_webhook( string $type, array $data, ?string $event_id = null ): \WP_REST_Response {
		$event_id = $event_id ?? wp_generate_uuid4();
		$raw_body = (string) wp_json_encode(
			array(
				'id'          => $event_id,
				'type'        => $type,
				'occurred_at' => gmdate( 'Y-m-d\TH:i:s\Z', self::now() ),
				'data'        => array() === $data ? new \stdClass() : $data,
			),
			JSON_UNESCAPED_SLASHES
		);

		$request = new \WP_REST_Request( 'POST', '/pixalink-loyalty/v1/webhook' );
		$request->set_header( 'content-type', 'application/json' );
		foreach ( Signature::webhook_headers( self::api_key(), $event_id, $raw_body, self::now() ) as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_body( $raw_body );

		$response = rest_do_request( $request );
		self::record( $request, 'webhook:' . $type, $response->get_status(), null );

		return $response;
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

		$decoded = json_decode( (string) $request->get_body(), true );

		$recorded   = self::recorded();
		$recorded[] = array(
			'method'     => $request->get_method(),
			'path'       => $path,
			'query'      => $request->get_query_params(),
			'headers'    => $kept,
			'body'       => null === $decoded ? (string) $request->get_body() : $decoded,
			'status'     => $status,
			'auth_error' => $auth_error,
			'at'         => gmdate( 'Y-m-d\TH:i:s\Z', self::now() ),
		);

		set_transient( self::REQUESTS_TRANSIENT, array_slice( $recorded, -self::MAX_RECORDED ), HOUR_IN_SECONDS );
	}
}
