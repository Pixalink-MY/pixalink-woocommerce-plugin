<?php
/**
 * The member portal sends the shopper back after an OAuth sign-in.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Oauth;

use Pixalink\Loyalty\Account\LoyaltyEndpoint;
use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Api\Resources\Oauth;
use Pixalink\Loyalty\Identity\CustomerLinker;
use Pixalink\Loyalty\Identity\Link;
use Pixalink\Loyalty\Identity\LinkException;
use Pixalink\Loyalty\Identity\MemberLanding;
use Pixalink\Loyalty\Identity\PortalSignIn;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * `?pixalink_oauth=callback` ({@see ClientCredentials::redirect_uri()}), on `template_redirect` before anything else:
 *
 * 1. The request this browser started ({@see AuthorizationRequest::take()}) is taken and its cookie cleared, whatever
 *    happens. Without it, with another `state` (compared in constant time), or when another account is logged in now
 *    than the one that started it, nothing is exchanged.
 * 2. `error` (access_denied when the member cancelled, or any other): a notice, back where they started.
 * 3. A staff account is refused before anything is exchanged ({@see MemberLanding::refuse_staff()}).
 * 4. The code is exchanged at the token endpoint ({@see Oauth::exchange_code()}: form-encoded, HTTP Basic, the PKCE
 *    verifier). `invalid_client` means Pixalink rotated or revoked the client: it is provisioned again, once.
 * 5. The access token is redeemed with `POST customers` (verified_via `oauth`, the account id when logged in); Pixalink
 *    revokes it there, so the store keeps no token.
 * 6. Then exactly what a portal ticket does ({@see MemberLanding}): a logged-in shopper is linked (never switched from
 *    another member; no second confirmation, since this browser started it), a logged-out one signed in, their account
 *    created as for the store's own sign-in button, or kept as a recognised guest. The link reads `via: oauth`.
 *
 * The shopper is redirected (302, no-store, no Referer) to the page kept in the request; without it (the cookie expired
 * or never came back), to the Loyalty tab when logged in, otherwise to My Account's login form.
 *
 * Every way the round trip can end without the member signed in or linked writes one warning, "Portal sign-in failed.",
 * with a `reason` (an {@see AuthorizationRequest}::FAILURE_* code, `state_mismatch`, `account_mismatch`, `cancelled`,
 * `portal_error`, `code_missing`, `oauth_unavailable`, `token_exchange_failed`, `redeem_failed`, or {@see MemberLanding}'s
 * `staff_account`, `linked_to_another_member` and `link_failed`) and safe context (HTTP status, error code, purpose,
 * user ids). Codes, tokens, states, verifiers, cookies and secrets are never logged. The shopper's notice for a
 * cancelled or failed round trip travels in the URL ({@see PortalSignIn::query_notice()}), since a logged-out shopper
 * has no WooCommerce session to carry it across the redirect.
 */
final class CallbackHandler {

	/**
	 * Linker.
	 *
	 * @var CustomerLinker
	 */
	private CustomerLinker $linker;

	/**
	 * Outlet credentials.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * The store's OAuth client.
	 *
	 * @var ClientCredentials
	 */
	private ClientCredentials $client;

	/**
	 * Token endpoint.
	 *
	 * @var Oauth
	 */
	private Oauth $oauth;

	/**
	 * The request kept in the browser.
	 *
	 * @var AuthorizationRequest
	 */
	private AuthorizationRequest $requests;

	/**
	 * Sign-in and linking once the member is proven.
	 *
	 * @var MemberLanding
	 */
	private MemberLanding $landing;

	/**
	 * Option storage (the cached heartbeat).
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
	 * Constructor.
	 *
	 * @param CustomerLinker       $linker      Linker.
	 * @param Credentials          $credentials Outlet credentials.
	 * @param ClientCredentials    $client      The store's OAuth client.
	 * @param Oauth                $oauth       Token endpoint.
	 * @param AuthorizationRequest $requests    The request kept in the browser.
	 * @param MemberLanding        $landing     Sign-in and linking.
	 * @param Options              $options     Option storage.
	 * @param Logger               $logger      Logger.
	 */
	public function __construct( CustomerLinker $linker, Credentials $credentials, ClientCredentials $client, Oauth $oauth, AuthorizationRequest $requests, MemberLanding $landing, Options $options, Logger $logger ) {
		$this->linker      = $linker;
		$this->credentials = $credentials;
		$this->client      = $client;
		$this->oauth       = $oauth;
		$this->requests    = $requests;
		$this->landing     = $landing;
		$this->options     = $options;
		$this->logger      = $logger;
	}

	/**
	 * Register the hook (early, before themes and other plugins redirect).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'on_template_redirect' ), 0 );
	}

	/**
	 * `template_redirect` callback.
	 *
	 * @return void
	 */
	public function on_template_redirect(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The state kept in this browser's encrypted cookie is the check (handle()).
		if ( ! isset( $_GET[ ClientCredentials::CALLBACK_PARAM ] ) || ClientCredentials::CALLBACK_VALUE !== $_GET[ ClientCredentials::CALLBACK_PARAM ] ) {
			return;
		}

		$query = array();
		foreach ( array( 'code', 'state', 'error' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
			if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
				$query[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}

		$target = $this->handle( $query );

		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
		}
		wp_safe_redirect( $target, 302, 'Pixalink Loyalty' );
		exit;
	}

	/**
	 * Finish the sign-in; returns where to send the shopper.
	 *
	 * @param array<string,string> $query `code`, `state` and `error` from the callback.
	 * @return string Absolute URL on this store.
	 */
	public function handle( array $query ): string {
		$request = $this->requests->take();
		$user_id = get_current_user_id();
		if ( null === $request ) {
			$this->failure( $this->requests->failure() ?? AuthorizationRequest::FAILURE_MISSING, array( 'user_id' => $user_id ) );

			return self::with_notice( self::fallback_target( $user_id ), PortalSignIn::NOTICE_FAILED );
		}

		$target  = PortalSignIn::return_url( $request['return'] );
		$context = array(
			'purpose' => $request['purpose'],
			'user_id' => $user_id,
		);
		if ( ! hash_equals( $request['state'], $query['state'] ?? '' ) ) {
			$this->failure( 'state_mismatch', $context );

			return self::with_notice( $target, PortalSignIn::NOTICE_FAILED );
		}

		if ( $request['user_id'] !== $user_id ) {
			$this->failure( 'account_mismatch', $context + array( 'started_by' => $request['user_id'] ) );

			return self::with_notice( $target, PortalSignIn::NOTICE_FAILED );
		}

		if ( isset( $query['error'] ) ) {
			$cancelled = 'access_denied' === $query['error'];
			$this->failure( $cancelled ? 'cancelled' : 'portal_error', $context + array( 'error' => sanitize_key( $query['error'] ) ) );

			return self::with_notice( $target, $cancelled ? PortalSignIn::NOTICE_CANCELLED : PortalSignIn::NOTICE_FAILED );
		}

		if ( CustomerLinker::is_staff( $user_id ) ) {
			$this->landing->refuse_staff( $user_id, null );

			return MemberLanding::where_notices_show( $target );
		}

		$customer = $this->redeem( $query['code'] ?? '', $request['verifier'], $user_id, $context );
		if ( null === $customer ) {
			return self::with_notice( $target, PortalSignIn::NOTICE_FAILED );
		}

		$link = $this->landing->link_for( $customer, Link::VIA_OAUTH );
		if ( $user_id < 1 ) {
			$this->landing->sign_in( $customer, $link, true );

			return $target;
		}

		$unlinked = null === UserMeta::link( $user_id );
		if ( $this->landing->link_account( $user_id, $link ) && $unlinked ) {
			MemberLanding::notice( __( 'Your account is now linked to your loyalty membership.', 'pixalink-loyalty-for-woocommerce' ) );
		}

		return $target;
	}

	/**
	 * Exchange the code and redeem the token; null (after logging why) when either fails.
	 *
	 * @param string              $code     Authorization code.
	 * @param string              $verifier PKCE verifier kept in the cookie.
	 * @param int                 $user_id  Logged-in user id (0 when nobody is logged in).
	 * @param array<string,mixed> $context  Log context of this round trip.
	 * @return array{id:string,phone:string,verified:bool,phone_country:string|null,email:string|null,first_name:string|null,last_name:string|null}|null
	 */
	private function redeem( string $code, string $verifier, int $user_id, array $context ): ?array {
		if ( '' === $code ) {
			$this->failure( 'code_missing', $context );

			return null;
		}

		$capability = Capability::remote( $this->options );
		$client     = $this->client->get();
		if ( null === $capability || null === $client || ! $this->credentials->is_configured() ) {
			$this->failure(
				'oauth_unavailable',
				$context + array(
					'advertised' => null !== $capability,
					'has_client' => null !== $client,
					'connected'  => $this->credentials->is_configured(),
				)
			);

			return null;
		}

		try {
			$token = $this->oauth->exchange_code( $capability->token_endpoint, $client['client_id'], $client['client_secret'], $code, ClientCredentials::redirect_uri(), $verifier );
		} catch ( ApiException $exception ) {
			$reprovision = 'invalid_client' === $exception->error_code();
			$this->failure(
				'token_exchange_failed',
				$context + array(
					'error_code'    => $exception->error_code(),
					'http_status'   => $exception->http_status(),
					'reprovisioned' => $reprovision,
				)
			);
			if ( $reprovision ) {
				$this->client->reprovision();
			}

			return null;
		}

		try {
			return $this->linker->redeem_oauth( $token, $user_id );
		} catch ( LinkException $exception ) {
			$previous = $exception->getPrevious();
			$this->failure(
				'redeem_failed',
				$context + array(
					'error_code'  => $exception->reason(),
					'http_status' => $previous instanceof ApiException ? $previous->http_status() : null,
				)
			);

			return null;
		}
	}

	/**
	 * Log why the round trip did not finish: one warning, never codes, tokens, states, verifiers or secrets.
	 *
	 * @param string              $reason  Reason code.
	 * @param array<string,mixed> $context Safe context.
	 * @return void
	 */
	private function failure( string $reason, array $context ): void {
		$this->logger->warning( 'Portal sign-in failed.', array( 'reason' => $reason ) + $context );
	}

	/**
	 * Where a shopper goes when the request kept in the browser is gone, and with it the page they started from: the
	 * Loyalty tab when logged in and it is on, otherwise My Account (the login form for a logged-out shopper).
	 *
	 * @param int $user_id Logged-in user id (0 when nobody is logged in).
	 * @return string
	 */
	private function fallback_target( int $user_id ): string {
		if ( $user_id > 0 && $this->options->get_bool( Options::ACCOUNT_TAB_ENABLED ) ) {
			return wc_get_account_endpoint_url( LoyaltyEndpoint::ENDPOINT );
		}

		return wc_get_page_permalink( 'myaccount' );
	}

	/**
	 * The landing page with the notice to show there ({@see PortalSignIn::query_notice()}).
	 *
	 * @param string $target Landing URL.
	 * @param string $notice PortalSignIn::NOTICE_*.
	 * @return string
	 */
	private static function with_notice( string $target, string $notice ): string {
		return add_query_arg( PortalSignIn::NOTICE_PARAM, $notice, $target );
	}
}
