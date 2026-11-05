<?php
/**
 * Tests for the OAuth token exchange.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Api\Resources;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\Client;
use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Api\Resources\Oauth;
use Pixalink\Loyalty\Support\Clock;
use Pixalink\Loyalty\Support\Crypto;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * The code from the member portal is exchanged at the token endpoint the heartbeat named: an absolute URL, a
 * form-encoded body, HTTP Basic client authentication, and none of the Commerce API's outlet key or signature. Only a
 * Bearer access token is accepted back; OAuth errors (`{"error": ...}`) surface as their code.
 *
 * @covers \Pixalink\Loyalty\Api\Resources\Oauth
 * @covers \Pixalink\Loyalty\Api\Client::post_form
 */
final class OauthTest extends TestCase {

	private const TOKEN_ENDPOINT = 'https://explore.pixalink.io/oauth/customer/token';

	/**
	 * Captured wp_remote_request() calls: [url, args].
	 *
	 * @var list<array{0:string,1:array<string,mixed>}>
	 */
	private array $requests = array();

	/**
	 * Queued responses.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $responses = array();

	protected function set_up(): void {
		parent::set_up();

		$this->requests  = array();
		$this->responses = array();
		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ) {
				$this->requests[] = array( $url, $args );

				return array_shift( $this->responses );
			}
		);
		Functions\when( 'is_wp_error' )->alias( static fn ( $thing ): bool => $thing instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn ( array $response ): int => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( array $response ): string => $response['body'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias( static fn (): string => '' );
		Functions\when( 'wp_parse_url' )->alias( static fn ( string $url, int $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	private function oauth(): Oauth {
		$options     = new Options();
		$credentials = new Credentials( new Crypto( 'salts' ), $options, static fn ( string $name ) => Credentials::API_KEY_CONSTANT === $name ? '1|abcdefghijklmnopqrstuvwxyz0123456789ABCD' : null );

		return new Oauth( new Client( $credentials, new Clock( $options, static fn (): int => 1761300000 ), new Logger(), $options, '1.2.3' ) );
	}

	/**
	 * Queue a JSON response.
	 *
	 * @param int          $status Status.
	 * @param array<mixed> $body   Body.
	 * @return void
	 */
	private function respond( int $status, array $body ): void {
		$this->responses[] = array(
			'response' => array( 'code' => $status ),
			'body'     => (string) json_encode( $body ),
			'headers'  => array(),
		);
	}

	private function exchange(): string {
		return $this->oauth()->exchange_code( self::TOKEN_ENDPOINT, 'client-uuid', 'se:cr&et', 'the-code', 'https://shop.example.test/?pixalink_oauth=callback', 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' );
	}

	public function test_the_code_is_exchanged_form_encoded_with_basic_client_authentication(): void {
		$this->respond(
			200,
			array(
				'token_type'    => 'Bearer',
				'expires_in'    => 3600,
				'access_token'  => 'eyJ0eXAiOiJKV1QifQ.eyJzdWIiOiI1MDAxIn0.c2lnbmF0dXJl',
				'refresh_token' => 'def50200refresh',
			)
		);

		$this->assertSame( 'eyJ0eXAiOiJKV1QifQ.eyJzdWIiOiI1MDAxIn0.c2lnbmF0dXJl', $this->exchange() );

		$this->assertCount( 1, $this->requests );
		[ $url, $args ] = $this->requests[0];
		$this->assertSame( self::TOKEN_ENDPOINT, $url, 'The absolute URL from the heartbeat, not under the API base.' );
		$this->assertSame( 'POST', $args['method'] );
		$this->assertSame( 0, $args['redirection'], 'Never follows a redirect with the client secret.' );
		$this->assertSame( 'application/x-www-form-urlencoded', $args['headers']['Content-Type'] );
		$this->assertSame( 'application/json', $args['headers']['Accept'] );
		// RFC 6749 §2.3.1: id and secret are form-encoded before Basic.
		$this->assertSame( 'Basic ' . base64_encode( 'client-uuid:se%3Acr%26et' ), $args['headers']['Authorization'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		parse_str( $args['body'], $fields );
		$this->assertSame(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => 'the-code',
				'redirect_uri'  => 'https://shop.example.test/?pixalink_oauth=callback',
				'code_verifier' => 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk',
			),
			$fields
		);
		foreach ( array( 'X-Pixalink-Signature', 'X-Pixalink-Timestamp', 'Idempotency-Key' ) as $header ) {
			$this->assertArrayNotHasKey( $header, $args['headers'], 'Not a Commerce API call: ' . $header );
		}
		$this->assertStringContainsString( 'PixalinkLoyaltyForWooCommerce/1.2.3', $args['user-agent'] );
	}

	/**
	 * OAuth error answers.
	 *
	 * @return array<string,array{0:int,1:string}>
	 */
	public static function oauth_errors(): array {
		return array(
			'bad code'   => array( 400, 'invalid_grant' ),
			'bad client' => array( 401, 'invalid_client' ),
		);
	}

	/**
	 * @dataProvider oauth_errors
	 *
	 * @param int    $status Status.
	 * @param string $error  OAuth error.
	 */
	public function test_an_oauth_error_surfaces_as_its_code( int $status, string $error ): void {
		$this->respond(
			$status,
			array(
				'error'             => $error,
				'error_description' => 'Nope.',
			)
		);

		try {
			$this->exchange();
			$this->fail( 'Expected an ApiException.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( $error, $exception->error_code() );
			$this->assertSame( $status, $exception->http_status() );
		}
	}

	/**
	 * Answers that are not a usable Bearer token.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function unusable_tokens(): array {
		return array(
			'no token'          => array( array( 'token_type' => 'Bearer' ) ),
			'not bearer'        => array(
				array(
					'token_type'   => 'mac',
					'access_token' => 'abc',
				),
			),
			'token with spaces' => array(
				array(
					'token_type'   => 'Bearer',
					'access_token' => 'a b',
				),
			),
		);
	}

	/**
	 * @dataProvider unusable_tokens
	 *
	 * @param array<string,mixed> $body Body.
	 */
	public function test_anything_but_a_bearer_token_is_refused( array $body ): void {
		$this->respond( 200, $body );

		$this->expectException( ApiException::class );
		$this->exchange();
	}

	public function test_a_lower_case_bearer_type_is_accepted(): void {
		$this->respond(
			200,
			array(
				'token_type'   => 'bearer',
				'access_token' => 'token-123',
			)
		);

		$this->assertSame( 'token-123', $this->exchange() );
	}
}
