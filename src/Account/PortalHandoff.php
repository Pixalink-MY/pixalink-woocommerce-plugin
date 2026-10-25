<?php
/**
 * Portal buttons that open the member portal already signed in.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Account;

use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\Resources\Customers;
use Pixalink\Loyalty\Connect\Connection;
use Pixalink\Loyalty\Identity\GuestLinkStore;
use Pixalink\Loyalty\Identity\ShopperContext;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Support\Branding;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * `admin-post.php?action=pixalink_loyalty_open_portal&destination=rewards&_wpnonce=…` (no rewrite rule).
 *
 * A logged-out click (a page left open after the session ended) goes to the My Account login form with
 * `redirect_to` set to the Loyalty tab, without any API call. WooCommerce's login form honours `redirect_to` (it
 * becomes the form's `redirect` field, validated at login), and "Sign in with the member portal" comes back there too.
 *
 * When a verified member clicks a portal button, the store asks Pixalink for a single-use sign-in link
 * (`POST customers/{id}/portal-session`, valid once for 60 seconds) and redirects the browser to it at once. The link
 * is minted at the click, so it is never printed into a page, cached or logged; the redirect carries no-cache headers
 * and `Referrer-Policy: no-referrer`. It is followed only when it is https on the portal's own host (the heartbeat's
 * `branding.portal_url`, else the summary's `portal_url`).
 *
 * Outcomes, reported through `pixalink_loyalty_portal_handoff`:
 *
 * - `redirected`: the member is on their way to the portal, signed in.
 * - `verification_required`: the shopper is not verified here, or Pixalink no longer accepts the link (403); they
 *   are sent to the Loyalty tab to verify again (the link is dropped in the second case).
 * - `fallback`: anything else (404, 429, 5xx, a timeout, a link on another host); the member gets the signed-out
 *   portal and a warning is logged.
 */
final class PortalHandoff {

	public const ACTION       = 'pixalink_loyalty_open_portal';
	public const NONCE_ACTION = 'pixalink_loyalty_open_portal';

	public const OUTCOME_REDIRECTED            = 'redirected';
	public const OUTCOME_VERIFICATION_REQUIRED = 'verification_required';
	public const OUTCOME_FALLBACK              = 'fallback';

	/**
	 * Query argument the Loyalty tab reads to say why the member is back.
	 */
	public const NOTICE_PARAM = 'pixalink_loyalty_notice';

	public const NOTICE_VERIFY_AGAIN = 'verify_again';

	/**
	 * Customer endpoints.
	 *
	 * @var Customers
	 */
	private Customers $customers;

	/**
	 * Summaries (the portal address when the heartbeat has none).
	 *
	 * @var SummaryService
	 */
	private SummaryService $summaries;

	/**
	 * Option storage.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Connection state (the credits feature switch).
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * Guest links.
	 *
	 * @var GuestLinkStore
	 */
	private GuestLinkStore $guests;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Sends one header line.
	 *
	 * @var \Closure(string):void
	 */
	private \Closure $emit_header;

	/**
	 * Ends the request after the redirect.
	 *
	 * @var \Closure():void
	 */
	private \Closure $terminate;

	/**
	 * Constructor.
	 *
	 * @param Customers     $customers   Customer endpoints.
	 * @param SummaryService $summaries  Summaries.
	 * @param Options       $options     Option storage.
	 * @param Connection    $connection  Connection state.
	 * @param GuestLinkStore $guests     Guest links.
	 * @param Logger        $logger      Logger.
	 * @param \Closure|null $emit_header Sends a header line (default: header()).
	 * @param \Closure|null $terminate   Ends the request (default: exit).
	 */
	public function __construct( Customers $customers, SummaryService $summaries, Options $options, Connection $connection, GuestLinkStore $guests, Logger $logger, ?\Closure $emit_header = null, ?\Closure $terminate = null ) {
		$this->customers   = $customers;
		$this->summaries   = $summaries;
		$this->options     = $options;
		$this->connection  = $connection;
		$this->guests      = $guests;
		$this->logger      = $logger;
		$this->emit_header = $emit_header ?? static function ( string $line ): void {
			if ( ! headers_sent() ) {
				header( $line );
			}
		};
		$this->terminate   = $terminate ?? static function (): void {
			exit;
		};
	}

	/**
	 * Query argument of the login form's "come back here after logging in".
	 */
	public const REDIRECT_PARAM = 'redirect_to';

	/**
	 * Register the handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_logged_out' ) );
	}

	/**
	 * `admin_post_nopriv_pixalink_loyalty_open_portal` callback: to the login form, then back to the Loyalty tab.
	 *
	 * @return void
	 */
	public function handle_logged_out(): void {
		$this->send( self::internal( self::login_url() ) );
		( $this->terminate )();
	}

	/**
	 * The My Account login form, with the Loyalty tab to come back to.
	 *
	 * @return string
	 */
	public static function login_url(): string {
		return add_query_arg( self::REDIRECT_PARAM, rawurlencode( self::loyalty_url() ), wc_get_page_permalink( 'myaccount' ) );
	}

	/**
	 * Where the login form was asked to send the shopper afterwards (`redirect_to`): a page of this store, or ''.
	 *
	 * @return string
	 */
	public static function requested_redirect(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A destination, validated to this site.
		$raw = isset( $_GET[ self::REDIRECT_PARAM ] ) && is_string( $_GET[ self::REDIRECT_PARAM ] ) ? sanitize_url( wp_unslash( $_GET[ self::REDIRECT_PARAM ] ) ) : '';

		return '' === $raw ? '' : wp_validate_redirect( $raw, '' );
	}

	/**
	 * URL a portal button points at (never the sign-in link itself, which is minted at the click).
	 *
	 * @param string $destination One of {@see Customers::PORTAL_DESTINATIONS}.
	 * @return string
	 */
	public static function url( string $destination ): string {
		// A raw URL (wp_nonce_url() would return it HTML-escaped); templates escape it with esc_url().
		return add_query_arg(
			array(
				'action'      => self::ACTION,
				'destination' => in_array( $destination, Customers::PORTAL_DESTINATIONS, true ) ? $destination : Customers::PORTAL_HOME,
				'_wpnonce'    => wp_create_nonce( self::NONCE_ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Portal pages this store offers (credits only while Pixalink has the credits feature on).
	 *
	 * @return list<string>
	 */
	public function destinations(): array {
		$destinations = array( 'home', 'rewards', 'points' );
		if ( $this->connection->feature( 'credits' ) ) {
			$destinations[] = 'credits';
		}

		return $destinations;
	}

	/**
	 * `admin_post_pixalink_loyalty_open_portal` callback.
	 *
	 * @return void
	 */
	public function handle_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- resolve() verifies the nonce.
		$destination = isset( $_GET['destination'] ) && is_string( $_GET['destination'] ) ? sanitize_key( wp_unslash( $_GET['destination'] ) ) : Customers::PORTAL_HOME;
		$nonce       = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$redirect = $this->resolve( $nonce, $destination );
		if ( null === $redirect ) {
			wp_die(
				esc_html__( 'This link has expired. Go back to your account and try again.', 'pixalink-loyalty-for-woocommerce' ),
				esc_html__( 'Member portal', 'pixalink-loyalty-for-woocommerce' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		$this->send( $redirect );
		( $this->terminate )();
	}

	/**
	 * Decide where a click goes.
	 *
	 * @param string $nonce       `_wpnonce` of the link.
	 * @param string $destination Requested portal page.
	 * @return array{location:string,status:int,external:bool,headers:array<string,string>}|null Null when the link is refused (bad or missing nonce).
	 */
	public function resolve( string $nonce, string $destination ): ?array {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return self::internal( self::login_url() );
		}

		if ( false === wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return null;
		}

		$destination = in_array( $destination, $this->destinations(), true ) ? $destination : Customers::PORTAL_HOME;
		$shopper     = ShopperContext::current( $this->guests );
		$customer_id = $shopper->pixalink_customer_id();
		if ( ! $shopper->is_verified() || null === $customer_id ) {
			$this->report( $user_id, $destination, self::OUTCOME_VERIFICATION_REQUIRED );

			return self::internal( self::loyalty_url() );
		}

		try {
			$session = $this->customers->portal_session( $customer_id, $user_id, $destination );
		} catch ( ApiException $exception ) {
			if ( 403 === $exception->http_status() && 'verification_required' === $exception->error_code() ) {
				return $this->verify_again( $user_id, $destination );
			}

			return $this->fallback(
				$user_id,
				$destination,
				array(
					'reason'     => 'api_error',
					'status'     => $exception->http_status(),
					'error_code' => $exception->error_code(),
				)
			);
		}

		if ( ! $this->is_trusted( $session['url'], $user_id ) ) {
			return $this->fallback( $user_id, $destination, array( 'reason' => 'untrusted_link' ) );
		}

		$this->report( $user_id, $destination, self::OUTCOME_REDIRECTED );

		return self::external( $session['url'] );
	}

	/**
	 * Send the headers and the redirect.
	 *
	 * @param array{location:string,status:int,external:bool,headers:array<string,string>} $redirect Redirect.
	 * @return void
	 */
	public function send( array $redirect ): void {
		foreach ( $redirect['headers'] as $name => $value ) {
			( $this->emit_header )( $name . ': ' . $value );
		}

		if ( $redirect['external'] ) {
			// The portal is on Pixalink's host, so wp_safe_redirect() would refuse it; the URL was checked in resolve().
			wp_redirect( $redirect['location'], $redirect['status'], 'Pixalink Loyalty' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		} else {
			wp_safe_redirect( $redirect['location'], $redirect['status'], 'Pixalink Loyalty' );
		}
	}

	/**
	 * The signed-out portal home this store knows (heartbeat branding first, then the member's summary).
	 *
	 * @param int $user_id User id (0 skips the summary).
	 * @return string|null
	 */
	public function portal_url( int $user_id ): ?string {
		$branding = Branding::remote( $this->options );
		if ( null !== $branding && null !== $branding->portal_url ) {
			return $branding->portal_url;
		}

		if ( $user_id < 1 ) {
			return null;
		}

		$result = $this->summaries->for_user( $user_id );
		$url    = is_array( $result['summary'] ) ? ( $result['summary']['portal_url'] ?? null ) : null;

		return Branding::url( $url );
	}

	/**
	 * Pixalink no longer accepts the link: drop it and send the member to verify again.
	 *
	 * @param int    $user_id     User id.
	 * @param string $destination Portal page.
	 * @return array{location:string,status:int,external:bool,headers:array<string,string>}
	 */
	private function verify_again( int $user_id, string $destination ): array {
		$this->logger->info( 'Pixalink asked this member to verify again before opening the member portal; the account link was removed.', array( 'user_id' => $user_id ) );
		UserMeta::clear_link( $user_id );
		$this->summaries->bust( $user_id );
		$this->report( $user_id, $destination, self::OUTCOME_VERIFICATION_REQUIRED );

		return self::internal( add_query_arg( self::NOTICE_PARAM, self::NOTICE_VERIFY_AGAIN, self::loyalty_url() ) );
	}

	/**
	 * The signed-out portal instead (or the Loyalty tab when no portal address is known).
	 *
	 * @param int                 $user_id     User id.
	 * @param string              $destination Portal page.
	 * @param array<string,mixed> $context     Why (never the link).
	 * @return array{location:string,status:int,external:bool,headers:array<string,string>}
	 */
	private function fallback( int $user_id, string $destination, array $context ): array {
		$this->logger->warning(
			'The member portal could not be opened signed in; the member was sent to the signed-out portal.',
			array(
				'user_id'     => $user_id,
				'destination' => $destination,
			) + $context
		);
		$this->report( $user_id, $destination, self::OUTCOME_FALLBACK );

		$portal = $this->portal_url( $user_id );

		return null === $portal ? self::internal( self::loyalty_url() ) : self::external( $portal );
	}

	/**
	 * Whether a sign-in link is https on the portal's own host.
	 *
	 * @param string $url     Link from Pixalink.
	 * @param int    $user_id User id.
	 * @return bool
	 */
	private function is_trusted( string $url, int $user_id ): bool {
		if ( 1 !== preg_match( '#^https://[^\s<>"\'`\\\\]+$#iD', $url ) ) {
			return false;
		}

		$expected = $this->portal_url( $user_id );
		$host     = Branding::host( $url );

		return null !== $expected && null !== $host && Branding::host( $expected ) === $host;
	}

	/**
	 * Fire `pixalink_loyalty_portal_handoff`.
	 *
	 * @param int    $user_id     User id.
	 * @param string $destination Portal page.
	 * @param string $outcome     One of the OUTCOME_* constants.
	 * @return void
	 */
	private function report( int $user_id, string $destination, string $outcome ): void {
		/**
		 * Fires when a member clicked a portal button.
		 *
		 * @since 0.1.0
		 *
		 * @param int    $user_id     WordPress user id.
		 * @param string $destination Portal page: home, rewards, points or credits.
		 * @param string $outcome     "redirected" (signed in), "verification_required" (sent to verify) or "fallback"
		 *                            (signed-out portal).
		 */
		do_action( 'pixalink_loyalty_portal_handoff', $user_id, $destination, $outcome );
	}

	/**
	 * URL of the Loyalty tab.
	 *
	 * @return string
	 */
	private static function loyalty_url(): string {
		return wc_get_account_endpoint_url( LoyaltyEndpoint::ENDPOINT );
	}

	/**
	 * A redirect within the store.
	 *
	 * @param string $location URL.
	 * @return array{location:string,status:int,external:bool,headers:array<string,string>}
	 */
	private static function internal( string $location ): array {
		return array(
			'location' => $location,
			'status'   => 303,
			'external' => false,
			'headers'  => self::headers(),
		);
	}

	/**
	 * A redirect to the portal.
	 *
	 * @param string $location URL.
	 * @return array{location:string,status:int,external:bool,headers:array<string,string>}
	 */
	private static function external( string $location ): array {
		return array(
			'location' => $location,
			'status'   => 303,
			'external' => true,
			'headers'  => self::headers(),
		);
	}

	/**
	 * The headers nocache_headers() sends, plus no Referer for the next request.
	 *
	 * @return array<string,string>
	 */
	private static function headers(): array {
		$headers = array();
		foreach ( wp_get_nocache_headers() as $name => $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				$headers[ (string) $name ] = $value;
			}
		}
		$headers['Referrer-Policy'] = 'no-referrer';

		return $headers;
	}
}
