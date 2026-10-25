<?php
/**
 * wp_remote_request() answered by the fake Pixalink server's canned responses.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Doubles;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Api\Client;
use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Support\Clock;
use Pixalink\Loyalty\Support\Crypto;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\FakeServer\ContractValidator;
use Pixalink\Loyalty\Tests\FakeServer\Responses;
use Pixalink\Loyalty\Tests\FakeServer\Signature;

/**
 * Routes the plugin's HTTP calls to {@see Responses::respond()} (validating each request body against its schema, like
 * the fake server does) unless a scripted answer matches first. Every call is recorded in `$this->http_requests`.
 */
trait FakePixalinkHttp {

	/**
	 * Recorded requests: `{method, path, query, body, headers}` (header names lower-case).
	 *
	 * @var list<array{method:string,path:string,query:array<string,mixed>,body:mixed,headers:array<string,string>}>
	 */
	protected array $http_requests = array();

	/**
	 * Scripted answers: `{method, path (fnmatch), status, body, headers}`.
	 *
	 * @var list<array{method:string,path:string,status:int,body:mixed,headers:array<string,string>}>
	 */
	protected array $http_scripts = array();

	/**
	 * Route templates by regex (mirrors the fake server). A method, not a constant: traits get constants in PHP 8.2.
	 *
	 * @return array<string,string>
	 */
	private static function fake_routes(): array {
		return array(
			'#^customers$#'                                  => 'customers',
			'#^customers/(?P<id>[A-Za-z0-9-]+)$#'            => 'customers/{id}',
			'#^customers/(?P<id>[A-Za-z0-9-]+)/otp$#'        => 'customers/{id}/otp',
			'#^customers/(?P<id>[A-Za-z0-9-]+)/otp/verify$#' => 'customers/{id}/otp/verify',
			'#^customers/(?P<id>[A-Za-z0-9-]+)/portal-session$#' => 'customers/{id}/portal-session',
			'#^events$#'                                     => 'events',
		);
	}

	/**
	 * Install the HTTP stubs.
	 *
	 * @return void
	 */
	protected function fake_pixalink(): void {
		$this->http_requests = array();
		$this->http_scripts  = array();

		Functions\when( 'wp_remote_request' )->alias( fn ( string $url, array $args ) => $this->fake_http_answer( $url, $args ) );
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ): bool => $thing instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn ( array $response ): int => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( array $response ): string => $response['body'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias( static fn ( array $response, string $name ): string => $response['headers'][ strtolower( $name ) ] ?? '' );
		Functions\when( 'wp_parse_url' )->alias( static fn ( string $url, int $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}

	/**
	 * A connected API client (fake outlet key).
	 *
	 * @return Client
	 */
	protected function fake_client(): Client {
		$options     = new Options();
		$credentials = new Credentials( new Crypto( 'salts' ), $options, static fn ( string $name ) => Credentials::API_KEY_CONSTANT === $name ? Signature::FAKE_API_KEY : null );

		return new Client( $credentials, new Clock( $options, static fn (): int => time() ), new Logger(), $options, '0.0.0-test' );
	}

	/**
	 * Script the next matching request.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $path    fnmatch pattern on the path relative to the API base.
	 * @param int                  $status  Status.
	 * @param mixed                $body    Body.
	 * @param array<string,string> $headers Lower-case headers.
	 * @return void
	 */
	protected function script_http( string $method, string $path, int $status, $body, array $headers = array() ): void {
		$this->http_scripts[] = array(
			'method'  => $method,
			'path'    => $path,
			'status'  => $status,
			'body'    => $body,
			'headers' => $headers,
		);
	}

	/**
	 * Paths of recorded requests as "METHOD path".
	 *
	 * @return list<string>
	 */
	protected function http_paths(): array {
		return array_map( static fn ( array $request ): string => $request['method'] . ' ' . $request['path'], $this->http_requests );
	}

	/**
	 * Answer one request.
	 *
	 * @param string              $url  URL.
	 * @param array<string,mixed> $args wp_remote_request() args.
	 * @return array<string,mixed>
	 */
	private function fake_http_answer( string $url, array $args ): array {
		$parts  = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$path   = ltrim( substr( (string) ( $parts['path'] ?? '' ), strlen( '/api/commerce/v1' ) ), '/' );
		$method = (string) $args['method'];
		$query  = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$raw  = isset( $args['body'] ) ? (string) $args['body'] : '';
		$body = '' === $raw ? array() : json_decode( $raw, true );

		$headers = array();
		foreach ( isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array() as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = (string) $value;
		}

		$this->http_requests[] = array(
			'method'  => $method,
			'path'    => $path,
			'query'   => $query,
			'body'    => $body,
			'headers' => $headers,
		);

		foreach ( $this->http_scripts as $index => $script ) {
			if ( $script['method'] === $method && fnmatch( $script['path'], $path ) ) {
				unset( $this->http_scripts[ $index ] );
				$this->http_scripts = array_values( $this->http_scripts );

				return $this->fake_http_response( $script['status'], $script['body'], $script['headers'] );
			}
		}

		foreach ( self::fake_routes() as $pattern => $route ) {
			if ( 1 !== preg_match( $pattern, $path, $matches ) ) {
				continue;
			}

			$endpoint = ContractValidator::endpoint( $method, $route );
			$this->assertNotNull( $endpoint, $method . ' ' . $route . ' is not in the manifest.' );
			if ( null !== $endpoint['request'] ) {
				$this->assertSame( array(), ContractValidator::validate( $endpoint['request'], json_decode( '' === $raw ? 'null' : $raw ) ), $method . ' ' . $path . ' body' );
			}
			if ( isset( $endpoint['query'] ) ) {
				$this->assertSame( array(), ContractValidator::validate( $endpoint['query'], (object) $query ), $method . ' ' . $path . ' query' );
			}

			$params = array_filter( $matches, 'is_string', ARRAY_FILTER_USE_KEY );
			$result = Responses::respond( $method, $route, $params, $query, is_array( $body ) ? $body : array(), array( 'now' => time() ) );

			return $this->fake_http_response( $result['status'], $result['body'], $result['headers'] ?? array() );
		}

		$this->fail( 'No fake route for ' . $method . ' ' . $path );
	}

	/**
	 * A wp_remote_request()-shaped response.
	 *
	 * @param int                  $status  Status.
	 * @param mixed                $body    Body.
	 * @param array<string,string> $headers Headers.
	 * @return array<string,mixed>
	 */
	private function fake_http_response( int $status, $body, array $headers ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => null === $body ? '' : (string) json_encode( $body ),
			'headers'  => array_change_key_case( $headers, CASE_LOWER ),
		);
	}
}
