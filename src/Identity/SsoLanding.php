<?php
/**
 * Arrival from the Pixalink member portal ("Order").
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Identity;

use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Fields\PhoneNormalizer;
use Pixalink\Loyalty\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Consumes the `?pixalink_sso=<ticket>` parameter the Pixalink portal appends to any store URL when a member taps
 * "Order", on `template_redirect`, then redirects to the same URL without it (the ticket never stays in the address bar
 * or history; the redirect also drops it from Referer headers).
 *
 * The ticket is redeemed with `POST customers` (verified_via `sso_ticket`). Then:
 *
 * - logged in and not linked yet: nothing is redeemed or linked on the bare link. The ticket waits (for this account
 *   only, {@see SsoLanding::PENDING_TTL}) behind a notice asking the shopper to confirm; its link carries a nonce bound
 *   to the account, and only that confirmation redeems the ticket and links the account. A ticket is proof of the
 *   member, not of the signed-in shopper's consent, so a link sent by someone else cannot attach their membership;
 * - logged in and linked: the link is refreshed, unless it is to a different member (nothing changes);
 * - logged out: the account linked to the member (or holding the member's verified number) is signed in; with none,
 *   a customer account is created when the store allows registration and the e-mail is free. Accounts that can
 *   do more than shop (editors, shop managers, administrators) are never signed in this way. Otherwise the shopper
 *   stays a guest with a verified link for this session.
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
	 * Guest links.
	 *
	 * @var GuestLinkStore
	 */
	private GuestLinkStore $guests;

	/**
	 * Phone normaliser (country of the member's number).
	 *
	 * @var PhoneNormalizer
	 */
	private PhoneNormalizer $normalizer;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param CustomerLinker  $linker      Linker.
	 * @param Credentials     $credentials Outlet credentials.
	 * @param GuestLinkStore  $guests      Guest links.
	 * @param PhoneNormalizer $normalizer  Phone normaliser.
	 * @param Logger          $logger      Logger.
	 */
	public function __construct( CustomerLinker $linker, Credentials $credentials, GuestLinkStore $guests, PhoneNormalizer $normalizer, Logger $logger ) {
		$this->linker      = $linker;
		$this->credentials = $credentials;
		$this->guests      = $guests;
		$this->normalizer  = $normalizer;
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

		if ( ! $this->credentials->is_configured() ) {
			return $target;
		}

		$user_id = get_current_user_id();
		if ( $user_id > 0 && null === UserMeta::link( $user_id ) ) {
			$this->ask_to_link( $user_id, $ticket, $target );

			return $target;
		}

		try {
			$customer = $this->linker->redeem_ticket( $ticket, $user_id );
		} catch ( LinkException $exception ) {
			self::notice( $exception->user_message() );

			return $target;
		}

		$link = $this->link_for( $customer );
		if ( $user_id > 0 ) {
			$this->link_account( $user_id, $link );
		} else {
			$this->sign_in( $customer, $link );
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
		if ( ! is_string( $ticket ) || '' === $ticket ) {
			self::notice( ( new LinkException( LinkException::TICKET_INVALID ) )->user_message() );

			return $target;
		}

		if ( ! $this->credentials->is_configured() ) {
			return $target;
		}

		try {
			$customer = $this->linker->redeem_ticket( $ticket, $user_id );
		} catch ( LinkException $exception ) {
			self::notice( $exception->user_message() );

			return $target;
		}

		$this->link_account( $user_id, $this->link_for( $customer ) );

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
	 * Keep the ticket for this account and ask the shopper to confirm the link.
	 *
	 * @param int    $user_id User id.
	 * @param string $ticket  Ticket from the query string.
	 * @param string $target  Where the shopper lands.
	 * @return void
	 */
	private function ask_to_link( int $user_id, string $ticket, string $target ): void {
		if ( 1 !== preg_match( CustomerLinker::TICKET_PATTERN, $ticket ) ) {
			self::notice( ( new LinkException( LinkException::TICKET_INVALID ) )->user_message() );

			return;
		}

		set_transient( self::PENDING_TRANSIENT . $user_id, $ticket, self::PENDING_TTL );

		$url = add_query_arg( self::CONFIRM_PARAM, wp_create_nonce( self::nonce_action( $user_id ) ), $target );
		self::notice(
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
	 * The verified link a redeemed ticket proves.
	 *
	 * @param array{id:string,phone:string,verified:bool,phone_country:string|null,email:string|null,first_name:string|null,last_name:string|null} $customer Customer.
	 * @return Link
	 */
	private function link_for( array $customer ): Link {
		$country = $customer['phone_country'] ?? $this->normalizer->normalize( $customer['phone'], '' )->country ?? '';

		return new Link( $customer['id'], $customer['phone'], (string) $country, gmdate( 'Y-m-d\TH:i:s\Z' ), Link::VIA_SSO_TICKET );
	}

	/**
	 * Link the logged-in account (never switch an account linked to another member).
	 *
	 * @param int  $user_id User id.
	 * @param Link $link    Verified link.
	 * @return void
	 */
	private function link_account( int $user_id, Link $link ): void {
		$existing = UserMeta::link( $user_id );
		if ( null !== $existing && $existing->customer_id !== $link->customer_id ) {
			$this->logger->info( 'Portal sign-in for another member ignored on a linked account.', array( 'user_id' => $user_id ) );
			self::notice( __( 'This account is already linked to a different loyalty member, so nothing was changed.', 'pixalink-loyalty-for-woocommerce' ) );

			return;
		}

		$this->linker->store( ShopperContext::resolve( $user_id, '', $this->guests ), $link );
	}

	/**
	 * Sign the member in, create their account, or keep them as a verified guest.
	 *
	 * @param array{id:string,phone:string,verified:bool,phone_country:string|null,email:string|null,first_name:string|null,last_name:string|null} $customer Customer.
	 * @param Link                                                                                                                                 $link     Verified link.
	 * @return void
	 */
	private function sign_in( array $customer, Link $link ): void {
		$user    = self::linked_user( $link );
		$created = false;
		if ( null === $user ) {
			$user    = $this->create_account( $customer );
			$created = null !== $user;
		}

		if ( null === $user || ! self::may_sign_in( $user ) ) {
			$this->keep_as_guest( $link );
			if ( null !== $user || ( null !== $customer['email'] && false !== email_exists( $customer['email'] ) ) ) {
				self::notice( __( 'Welcome back! Log in to your account to see your points.', 'pixalink-loyalty-for-woocommerce' ) );
			}

			return;
		}

		wc_set_customer_auth_cookie( $user->ID );

		/**
		 * Fires after a user logs in: WordPress core's own action, fired so session and two-factor plugins see this sign-in.
		 *
		 * @since 0.1.0
		 *
		 * @param string   $user_login Login name.
		 * @param \WP_User $user       The account.
		 */
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's login action, deliberately.

		$this->linker->store( ShopperContext::resolve( $user->ID, '', $this->guests ), $link );
		$this->logger->info(
			'Portal sign-in.',
			array(
				'user_id' => $user->ID,
				'created' => $created,
			)
		);
	}

	/**
	 * Verified link for the guest session.
	 *
	 * @param Link $link Verified link.
	 * @return void
	 */
	private function keep_as_guest( Link $link ): void {
		$session = function_exists( 'WC' ) ? WC()->session : null;
		// The session cookie keeps the guest link for the next requests (headers are still open on template_redirect).
		if ( $session instanceof \WC_Session_Handler && ! $session->has_session() && ! headers_sent() ) {
			$session->set_customer_session_cookie( true );
		}

		$this->linker->store( ShopperContext::resolve( 0, ShopperContext::current_session_id(), $this->guests ), $link );
	}

	/**
	 * The account already linked to the member, or holding the member's verified number.
	 *
	 * @param Link $link Verified link.
	 * @return \WP_User|null
	 */
	private static function linked_user( Link $link ): ?\WP_User {
		foreach ( array(
			UserMeta::CUSTOMER_ID => $link->customer_id,
			UserMeta::PHONE_E164  => $link->phone_e164,
		) as $key => $value ) {
			$ids = get_users(
				array(
					'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One lookup per portal sign-in.
					'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
					'fields'     => 'ID',
					'number'     => 10,
				)
			);

			foreach ( $ids as $id ) {
				$existing = is_numeric( $id ) ? UserMeta::link( (int) $id ) : null;
				if ( null !== $existing && ( $existing->customer_id === $link->customer_id || $existing->phone_e164 === $link->phone_e164 ) ) {
					$user = get_userdata( (int) $id );

					return $user instanceof \WP_User ? $user : null;
				}
			}
		}

		return null;
	}

	/**
	 * Create a customer account for the member when the store allows sign-ups and the e-mail is free.
	 *
	 * @param array{id:string,phone:string,verified:bool,phone_country:string|null,email:string|null,first_name:string|null,last_name:string|null} $customer Customer.
	 * @return \WP_User|null
	 */
	private function create_account( array $customer ): ?\WP_User {
		$email   = $customer['email'];
		$allowed = 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) || 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout' );

		/**
		 * Filters whether a portal sign-in may create a WooCommerce customer account.
		 *
		 * @since 0.1.0
		 *
		 * @param bool                $allowed  Default: whether the store lets shoppers register.
		 * @param array<string,mixed> $customer The Pixalink customer `{id, phone, email, first_name, last_name}`.
		 */
		$allowed = (bool) apply_filters( 'pixalink_loyalty_sso_create_account', $allowed, $customer );

		if ( ! $allowed || null === $email || false !== email_exists( $email ) || ! function_exists( 'wc_create_new_customer' ) ) {
			return null;
		}

		$user_id = wc_create_new_customer(
			$email,
			'',
			wp_generate_password( 24 ),
			array(
				'first_name' => (string) $customer['first_name'],
				'last_name'  => (string) $customer['last_name'],
			)
		);
		if ( is_wp_error( $user_id ) ) {
			$this->logger->warning( 'Portal sign-in could not create the account.', array( 'error' => $user_id->get_error_code() ) );

			return null;
		}

		$user = get_userdata( (int) $user_id );

		return $user instanceof \WP_User ? $user : null;
	}

	/**
	 * Whether a portal ticket may sign this account in (shoppers only).
	 *
	 * @param \WP_User $user Account.
	 * @return bool
	 */
	private static function may_sign_in( \WP_User $user ): bool {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- manage_woocommerce is WooCommerce's shop manager capability.
		$allowed = ! user_can( $user, 'edit_posts' ) && ! user_can( $user, 'manage_woocommerce' ) && ! user_can( $user, 'list_users' );

		/**
		 * Filters whether a Pixalink portal sign-in may log this account in.
		 *
		 * @since 0.1.0
		 *
		 * @param bool     $allowed Default: true for accounts that can only shop.
		 * @param \WP_User $user    The account.
		 */
		return (bool) apply_filters( 'pixalink_loyalty_sso_can_sign_in', $allowed, $user );
	}

	/**
	 * Show a notice on the next page.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private static function notice( string $message ): void {
		if ( function_exists( 'wc_add_notice' ) && function_exists( 'WC' ) && null !== WC()->session ) {
			wc_add_notice( $message, 'notice' );
		}
	}
}
