<?php
/**
 * Tests for ClientCredentials.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Oauth;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Api\Resources\Connect;
use Pixalink\Loyalty\Oauth\ClientCredentials;
use Pixalink\Loyalty\Support\Crypto;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\FakeServer\Responses;
use Pixalink\Loyalty\Tests\Unit\Doubles\FakePixalinkHttp;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * The store's own OAuth client at Pixalink: provisioned (`POST connection/oauth-client`, signed, with an
 * Idempotency-Key) once the heartbeat advertises OAuth, for this store's callback URL; the client id kept as is and the
 * secret only encrypted; provisioned again when the callback URL changed or the secret can no longer be read; and gone
 * when the store's connection changes.
 *
 * @covers \Pixalink\Loyalty\Oauth\ClientCredentials
 * @covers \Pixalink\Loyalty\Api\Resources\Connect::oauth_client
 * @covers \Pixalink\Loyalty\Api\Resources\Connect::oauth_client_payload
 */
final class ClientCredentialsTest extends TestCase {

	use FakePixalinkHttp;

	/**
	 * The store's home URL.
	 *
	 * @var string
	 */
	private string $home = 'https://shop.example.test';

	protected function set_up(): void {
		parent::set_up();

		$this->fake_pixalink();
		Functions\when( 'home_url' )->alias( fn ( string $path = '' ): string => $this->home . $path );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$this->options[ Options::REMOTE_STATUS ] = array( Responses::samples( time() )['heartbeat']['response']['body'], true );
	}

	private function credentials( string $salts = 'salts' ): ClientCredentials {
		return new ClientCredentials( new Connect( $this->fake_client() ), new Options(), new Crypto( $salts ), new Logger() );
	}

	public function test_the_callback_is_the_home_page_with_the_oauth_parameter(): void {
		$this->assertSame( 'https://shop.example.test/?pixalink_oauth=callback', ClientCredentials::redirect_uri() );
	}

	public function test_a_heartbeat_with_oauth_provisions_the_client_once(): void {
		$credentials = $this->credentials();

		$credentials->ensure();

		$this->assertSame( array( 'POST connection/oauth-client' ), $this->http_paths() );
		$request = $this->http_requests[0];
		$this->assertSame( array( 'redirect_uri' => 'https://shop.example.test/?pixalink_oauth=callback' ), $request['body'], 'Validated against connection-oauth-client.request.json by the double.' );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9-]{16,64}$/', $request['headers']['idempotency-key'] ?? '' );
		$this->assertNotEmpty( $request['headers']['x-pixalink-signature'] ?? '', 'Signed with the outlet key.' );

		$client = $credentials->get();
		$this->assertSame( Responses::OAUTH_CLIENT_ID, $client['client_id'] ?? null );
		$this->assertStringStartsWith( Responses::OAUTH_CLIENT_SECRET_PREFIX, $client['client_secret'] ?? '' );

		$credentials->ensure();
		$this->assertCount( 1, $this->http_requests, 'Kept: no second call while the callback URL is the same.' );
	}

	public function test_the_secret_is_stored_encrypted_and_the_id_as_is(): void {
		$this->credentials()->ensure();

		$stored = $this->options[ Options::OAUTH_CLIENT ][0] ?? null;
		$this->assertIsArray( $stored );
		$this->assertSame( Responses::OAUTH_CLIENT_ID, $stored['client_id'] );
		$this->assertSame( 'https://shop.example.test/?pixalink_oauth=callback', $stored['redirect_uri'] );
		$this->assertStringStartsWith( Crypto::PAYLOAD_PREFIX, $stored['client_secret'] );
		$this->assertStringNotContainsString( Responses::OAUTH_CLIENT_SECRET_PREFIX, (string) wp_json_encode( $stored ), 'The plain secret is never stored.' );
		$this->assertFalse( $this->options[ Options::OAUTH_CLIENT ][1], 'Not autoloaded.' );
	}

	public function test_a_new_store_address_provisions_a_new_client(): void {
		$this->credentials()->ensure();
		$this->home = 'https://new-shop.example.test';

		$this->assertNull( $this->credentials()->get(), 'Issued for the old callback URL.' );
		$this->credentials()->ensure();

		$this->assertCount( 2, $this->http_requests );
		$this->assertSame( 'https://new-shop.example.test/?pixalink_oauth=callback', $this->http_requests[1]['body']['redirect_uri'] );
		$this->assertNotNull( $this->credentials()->get() );
	}

	public function test_a_secret_that_cannot_be_decrypted_any_more_is_provisioned_again(): void {
		$this->credentials( 'old-salts' )->ensure();

		$this->assertNull( $this->credentials()->get(), 'The site\'s salts changed.' );
		$this->credentials()->ensure();

		$this->assertCount( 2, $this->http_requests );
		$this->assertNotNull( $this->credentials()->get() );
	}

	public function test_without_oauth_in_the_heartbeat_nothing_is_provisioned(): void {
		$status = Responses::samples( time() )['heartbeat']['response']['body'];
		unset( $status['oauth'] );
		$this->options[ Options::REMOTE_STATUS ] = array( $status, true );

		$this->credentials()->ensure();

		$this->assertSame( array(), $this->http_requests );
		$this->assertNull( $this->credentials()->get() );
	}

	public function test_a_refused_callback_url_stores_nothing_and_does_not_throw(): void {
		$this->script_http(
			'POST',
			'connection/oauth-client',
			422,
			array(
				'message' => 'The redirect URI must be on the connected store.',
				'code'    => 'invalid_redirect_uri',
			)
		);

		$this->credentials()->ensure();

		$this->assertNull( $this->credentials()->get() );
		$this->assertArrayNotHasKey( Options::OAUTH_CLIENT, $this->options );
	}

	public function test_an_answer_without_a_secret_stores_nothing(): void {
		$this->script_http( 'POST', 'connection/oauth-client', 200, array( 'client_id' => Responses::OAUTH_CLIENT_ID ) );

		$this->credentials()->ensure();

		$this->assertNull( $this->credentials()->get() );
	}

	public function test_provisioning_again_rotates_the_secret(): void {
		$credentials = $this->credentials();
		$credentials->ensure();
		$first = $credentials->get();

		$credentials->reprovision();

		$this->assertCount( 2, $this->http_requests );
		$this->assertNotSame( $first['client_secret'] ?? null, $credentials->get()['client_secret'] ?? null );
	}

	public function test_clearing_forgets_the_client(): void {
		$credentials = $this->credentials();
		$credentials->ensure();

		$credentials->clear();

		$this->assertNull( $credentials->get() );
		$this->assertArrayNotHasKey( Options::OAUTH_CLIENT, $this->options );
	}

	public function test_it_follows_the_heartbeat_and_forgets_the_client_when_the_connection_changes(): void {
		$credentials = $this->credentials();
		$hooks       = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback ) use ( &$hooks ): bool {
				$hooks[ $hook ] = $callback;

				return true;
			}
		);

		$credentials->register();

		$this->assertSame( array( $credentials, 'ensure' ), $hooks['pixalink_loyalty_heartbeat_received'] ?? null );
		foreach ( array( 'pixalink_loyalty_credentials_changed', 'pixalink_loyalty_disconnected', 'add_option_' . Options::CREDENTIALS, 'update_option_' . Options::CREDENTIALS, 'delete_option_' . Options::CREDENTIALS ) as $hook ) {
			$this->assertSame( array( $credentials, 'clear' ), $hooks[ $hook ] ?? null, $hook );
		}
	}
}
