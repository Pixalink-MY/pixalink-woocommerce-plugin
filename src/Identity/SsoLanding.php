<?php
/**
 * Arrival from the Pixalink member portal ("Order", or the store's "Sign in with the member portal" button).
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Identity;

use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Fields\PhoneNormalizer;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Consumes the `?pixalink_sso=<ticket>` parameter the Pixalink portal appends to any store URL when a member taps
 * "Order", on `template_redirect`, then redirects to the same URL without it (the ticket never stays in the address bar
 * or history; the redirect also drops it from Referer headers).
 *
 * The ticket is redeemed with `POST customers` (verified_via `sso_ticket`). Then:
 *
 * - logged in as staff (anything more than a shopper, {@see CustomerLinker::is_staff()}): refused. Nothing is linked,
 *   parked or signed in; the ticket is spent without the account id (so Pixalink links nothing to the staff account
 *   either), `pixalink_loyalty_sso_refused_staff` fires and a notice asks them to log out and open the link again. The
 *   same refusal answers a confirmation (below) sent from a staff account;
 * - logged in and not linked yet: nothing is redeemed or linked on the bare link. The ticket waits (for this account
 *   only, {@see SsoLanding::PENDING_TTL}) behind a notice asking the shopper to confirm; its link carries a nonce bound
 *   to the account, and only that confirmation redeems the ticket and links the account. A ticket is proof of the
 *   member, not of the signed-in shopper's consent, so a link sent by someone else cannot attach their membership;
 * - logged in and linked: the link is refreshed, unless it is to a different member (nothing changes);
 * - logged out: the account linked to the member (or holding the member's verified number) is signed in; with none,
 *   a customer account is created when the store allows registration and the e-mail is free. Accounts that can
 *   do more than shop (editors, shop managers, administrators) are never signed in this way. Otherwise the shopper
 *   stays a guest with a verified link for this session, and a notice says they were recognised for this visit.
 *
 * Linking, signing in and the staff refusal are {@see MemberLanding}'s, shared with OAuth sign-in
 * ({@see \Pixalink\Loyalty\Oauth\CallbackHandler}), so both arrivals behave the same.
 *
 * When the shopper pressed the store's own sign-in button ({@see PortalSignIn}), the browser still holds the state
 * cookie ({@see SignInState}): its value is sent with the ticket as `sso_state` (Pixalink refuses a ticket bound to a
 * different one) and the cookie is cleared whatever happens. A member who comes back this way also gets a customer
 * account where the store has registration off, as long as "Sign in with your member portal" is on and Pixalink has
 * their e-mail address: they asked to sign in, so the store gives them an account to sign in to.
 */
final class SsoLanding {

	public const PARAM = 'pixalink_sso';

	/**
	 * Query parameter of the confirmation link; carries the nonce.
	 */
	public const CONFIRM_PARAM = 'pixalink_sso_link';

	/**
	 * Seconds a ticket waits for the signed-in shopper to confirm.
	 */
	public const PENDING_TTL = 600;

	/**
	 * Transient prefix of a ticket waiting for confirmation (suffix: user id).
	 */
	private const PENDING_TRANSIENT = 'pixalink_loyalty_sso_pending_';

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
	 * Sign-in and linking once the member is proven.
	 *
	 * @var MemberLanding
	 */
	private MemberLanding $landing;

	/**
	 * State cookie of the sign-in button.
	 *
	 * @var SignInState
	 */
	private SignInState $state;

	/**
	 * Constructor.
	 *
	 * @param CustomerLinker   $linker      Linker.
	 * @param Credentials      $credentials Outlet credentials.
	 * @param GuestLinkStore   $guests      Guest links.
	 * @param PhoneNormalizer  $normalizer  Phone normaliser.
	 * @param Logger           $logger      Logger.
	 * @param Options|null     $options     Option storage.
	 * @param SignInState|null $state       State cookie of the sign-in button.
	 */
	public function __construct( CustomerLinker $linker, Credentials $credentials, GuestLinkStore $guests, PhoneNormalizer $normalizer, Logger $logger, ?Options $options = null, ?SignInState $state = null ) {
		$this->linker      = $linker;
		$this->credentials = $credentials;
		$this->landing     = new MemberLanding( $linker, $guests, $normalizer, $logger, $options ?? new Options() );
		$this->state       = $state ?? new SignInState();
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
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- confirm() verifies this nonce.
		if ( isset( $_GET[ self::CONFIRM_PARAM ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
			$nonce  = is_string( $_GET[ self::CONFIRM_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::CONFIRM_PARAM ] ) ) : '';
			$target = $this->confirm( $nonce, $request_uri );
		} elseif ( isset( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The single-use ticket Pixalink minted is the credential; nothing is submitted from this site.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
			$ticket = is_string( $_GET[ self::PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) : '';
			$target = $this->handle( $ticket, $request_uri );
		} else {
			return;
		}

		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
		}
		wp_safe_redirect( $target, 302, 'Pixalink Loyalty' );
		exit;
	}

	/**
	 * Redeem a ticket and sign in or link; returns where to redirect (the same URL without the parameter).
	 *
	 * @param string $ticket      Ticket from the query string.
	 * @param string $request_uri Request URI (path and query).
	 * @return string Absolute URL.
	 */
	public function handle( string $ticket, string $request_uri ): string {
		$target = self::target( $request_uri );
		// Taken (and the cookie cleared) before anything else: one try per press of the button, whatever happens.
		$state = $this->state->take();

		if ( ! $this->credentials->is_configured() ) {
			return $target;
		}

		$user_id = get_current_user_id();
		if ( $this->refused_staff( $user_id, $ticket, $state ) ) {
			return MemberLanding::where_notices_show( $target );
		}

		if ( $user_id > 0 && null === UserMeta::link( $user_id ) ) {
			$this->ask_to_link( $user_id, $ticket, $target );

			return $target;
		}

		try {
			$customer = $this->linker->redeem_ticket( $ticket, $user_id, $state );
		} catch ( LinkException $exception ) {
			MemberLanding::notice( $exception->user_message() );

			return $target;
		}

		$link = $this->landing->link_for( $customer, Link::VIA_SSO_TICKET );
		if ( $user_id > 0 ) {
			$this->landing->link_account( $user_id, $link );
		} else {
			$this->landing->sign_in( $customer, $link, null !== $state );
		}

		return $target;
	}

	/**
	 * The signed-in shopper confirmed: redeem the ticket waiting for their account and link it.
	 *
	 * @param string $nonce       Nonce from the confirmation link.
	 * @param string $request_uri Request URI (path and query).
	 * @return string Absolute URL to redirect to.
	 */
	public function confirm( string $nonce, string $request_uri ): string {
		$target  = self::target( $request_uri );
		$user_id = get_current_user_id();
		if ( $user_id < 1 || false === wp_verify_nonce( $nonce, self::nonce_action( $user_id ) ) ) {
			return $target;
		}

		$ticket = get_transient( self::PENDING_TRANSIENT . $user_id );
		delete_transient( self::PENDING_TRANSIENT . $user_id );
		if ( $this->refused_staff( $user_id, is_string( $ticket ) ? $ticket : '' ) ) {
			return MemberLanding::where_notices_show( $target );
		}

		if ( ! is_string( $ticket ) || '' === $ticket ) {
			MemberLanding::notice( ( new LinkException( LinkException::TICKET_INVALID ) )->user_message() );

			return $target;
		}

		if ( ! $this->credentials->is_configured() ) {
			return $target;
		}

		try {
			$customer = $this->linker->redeem_ticket( $ticket, $user_id );
		} catch ( LinkException $exception ) {
			MemberLanding::notice( $exception->user_message() );

			return $target;
		}

		$this->landing->link_account( $user_id, $this->landing->link_for( $customer, Link::VIA_SSO_TICKET ) );

		return $target;
	}

	/**
	 * The request URL without the ticket parameter.
	 *
	 * @param string $request_uri Request URI.
	 * @return string Absolute URL on the home origin.
	 */
	public static function target( string $request_uri ): string {
		$relative = remove_query_arg( array( self::PARAM, self::CONFIRM_PARAM ), '' !== $request_uri ? $request_uri : '/' );
		$relative = '/' === substr( $relative, 0, 1 ) ? $relative : '/' . $relative;
		$home     = wp_parse_url( home_url() );
		$origin   = is_array( $home ) && isset( $home['scheme'], $home['host'] )
			? $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' )
			: untrailingslashit( home_url() );

		return $origin . rtrim( $relative, '?' );
	}

	/**
	 * Refuse a ticket opened in a staff account's session: no link, no parked ticket, nobody signed in.
	 *
	 * The ticket is still redeemed, without the account id, so it is spent (the portal mints a new one for every tap)
	 * and the refusal can name the member.
	 *
	 * @param int         $user_id Logged-in user id (0 when nobody is logged in).
	 * @param string      $ticket  Ticket ('' when none is left).
	 * @param string|null $state   State of the sign-in button (null when none).
	 * @return bool Whether the ticket was refused.
	 */
	private function refused_staff( int $user_id, string $ticket, ?string $state = null ): bool {
		if ( ! CustomerLinker::is_staff( $user_id ) ) {
			return false;
		}

		$customer = null;
		if ( $this->credentials->is_configured() && 1 === preg_match( CustomerLinker::TICKET_PATTERN, $ticket ) ) {
			try {
				$customer = $this->linker->redeem_ticket( $ticket, 0, $state );
			} catch ( LinkException $exception ) {
				unset( $exception ); // Spent, foreign or unreachable: the refusal is the same (the linker logged the failure).
			}
		}

		$this->landing->refuse_staff( $user_id, $customer );

		return true;
	}

	/**
	 * Keep the ticket for this account and ask the shopper to confirm the link.
	 *
	 * @param int    $user_id User id.
	 * @param string $ticket  Ticket from the query string.
	 * @param string $target  Where the shopper lands.
	 * @return void
	 */
	private function ask_to_link( int $user_id, string $ticket, string $target ): void {
		if ( 1 !== preg_match( CustomerLinker::TICKET_PATTERN, $ticket ) ) {
			MemberLanding::notice( ( new LinkException( LinkException::TICKET_INVALID ) )->user_message() );

			return;
		}

		set_transient( self::PENDING_TRANSIENT . $user_id, $ticket, self::PENDING_TTL );

		$url = add_query_arg( self::CONFIRM_PARAM, wp_create_nonce( self::nonce_action( $user_id ) ), $target );
		MemberLanding::notice(
			sprintf(
				/* translators: %s: link that confirms. */
				__( 'Link this account to your loyalty membership? %s', 'pixalink-loyalty-for-woocommerce' ),
				'<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Link my account', 'pixalink-loyalty-for-woocommerce' ) . '</a>'
			)
		);
	}

	/**
	 * Nonce action of the confirmation link (bound to the account; WordPress also binds it to the session).
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private static function nonce_action( int $user_id ): string {
		return 'pixalink_sso_link_' . $user_id;
	}

	/**
	 * Notice for a member recognised for this visit only ({@see MemberLanding::recognised_guest_message()}).
	 *
	 * @return string HTML (a link at most).
	 */
	public static function recognised_guest_message(): string {
		return MemberLanding::recognised_guest_message();
	}
}
