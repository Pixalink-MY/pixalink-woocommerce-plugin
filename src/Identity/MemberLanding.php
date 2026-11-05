<?php
/**
 * What happens once the Pixalink member portal proved who the shopper is.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Identity;

use Pixalink\Loyalty\Fields\PhoneNormalizer;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by the portal ticket ({@see SsoLanding}) and OAuth ({@see \Pixalink\Loyalty\Oauth\CallbackHandler}) arrivals,
 * so both behave the same:
 *
 * - logged in as staff (anything more than a shopper, {@see CustomerLinker::is_staff()}): refused
 *   ({@see MemberLanding::refuse_staff()}): nothing linked or signed in, a notice, `pixalink_loyalty_sso_refused_staff`;
 * - logged in: the account is linked ({@see MemberLanding::link_account()}), never switched from another member (the
 *   link to the same member is refreshed);
 * - logged out ({@see MemberLanding::sign_in()}): the account linked to the member (or holding the member's verified
 *   number) is signed in; with none, a customer account is created when the store allows registration, or the shopper
 *   pressed the store's own sign-in button while "Sign in from Customer Portal" is on, and the e-mail is free. Accounts
 *   that can do more than shop are never signed in this way. Otherwise the shopper stays a guest with a verified link
 *   for this session, and a notice says they were recognised for this visit.
 */
final class MemberLanding {

	/**
	 * Linker.
	 *
	 * @var CustomerLinker
	 */
	private CustomerLinker $linker;

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
	 * Option storage (the sign-in button setting).
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Constructor.
	 *
	 * @param CustomerLinker  $linker     Linker.
	 * @param GuestLinkStore  $guests     Guest links.
	 * @param PhoneNormalizer $normalizer Phone normaliser.
	 * @param Logger          $logger     Logger.
	 * @param Options         $options    Option storage.
	 */
	public function __construct( CustomerLinker $linker, GuestLinkStore $guests, PhoneNormalizer $normalizer, Logger $logger, Options $options ) {
		$this->linker     = $linker;
		$this->guests     = $guests;
		$this->normalizer = $normalizer;
		$this->logger     = $logger;
		$this->options    = $options;
	}

	/**
	 * The verified link a redeemed proof gives.
	 *
	 * @param array{id:string,phone:string,verified:bool,phone_country:string|null,email:string|null,first_name:string|null,last_name:string|null} $customer Customer.
	 * @param string                                                                                                                               $via      {@see Link}::VIA_*.
	 * @return Link
	 */
	public function link_for( array $customer, string $via ): Link {
		$country = $customer['phone_country'] ?? $this->normalizer->normalize( $customer['phone'], '' )->country ?? '';

		return new Link( $customer['id'], $customer['phone'], (string) $country, gmdate( 'Y-m-d\TH:i:s\Z' ), $via );
	}

	/**
	 * Refuse a staff account's session: log it, tell them, and fire `pixalink_loyalty_sso_refused_staff`.
	 *
	 * @param int                      $user_id  The signed-in staff account.
	 * @param array<string,mixed>|null $customer The member the proof named, or null when it was not redeemed.
	 * @return void
	 */
	public function refuse_staff( int $user_id, ?array $customer ): void {
		$this->logger->info(
			'Portal sign-in refused: a staff account is signed in.',
			array(
				'user_id'     => $user_id,
				'customer_id' => $customer['id'] ?? null,
			)
		);
		self::notice( __( 'You\'re signed in to this store as staff, so we didn\'t connect your Pixalink membership to this account. Log out and open the link again to shop as a member.', 'pixalink-loyalty-for-woocommerce' ) );

		/**
		 * Fires when a Pixalink portal sign-in was refused because a staff account is signed in (nothing was linked).
		 *
		 * @since 0.1.0
		 *
		 * @param int                      $user_id  The signed-in staff account.
		 * @param array<string,mixed>|null $customer The Pixalink customer the portal proved `{id, phone, email, ...}`, or
		 *                                           null when it was not redeemed.
		 */
		do_action( 'pixalink_loyalty_sso_refused_staff', $user_id, $customer );
	}

	/**
	 * Link the logged-in account (never switch an account linked to another member).
	 *
	 * @param int  $user_id User id.
	 * @param Link $link    Verified link.
	 * @return bool Whether the account is linked to the member now.
	 */
	public function link_account( int $user_id, Link $link ): bool {
		$existing = UserMeta::link( $user_id );
		if ( null !== $existing && $existing->customer_id !== $link->customer_id ) {
			$this->logger->info( 'Portal sign-in for another member ignored on a linked account.', array( 'user_id' => $user_id ) );
			self::notice( __( 'This account is already linked to a different loyalty member, so nothing was changed.', 'pixalink-loyalty-for-woocommerce' ) );

			return false;
		}

		try {
			$this->linker->store( ShopperContext::resolve( $user_id, '', $this->guests ), $link );
		} catch ( LinkException $exception ) {
			self::notice( $exception->user_message() );

			return false;
		}

		return true;
	}

	/**
	 * Sign the member in, create their account, or keep them as a verified guest.
	 *
	 * @param array{id:string,phone:string,verified:bool,phone_country:string|null,email:string|null,first_name:string|null,last_name:string|null} $customer    Customer.
	 * @param Link                                                                                                                                 $link        Verified link.
	 * @param bool                                                                                                                                 $from_button Whether the shopper pressed the store's sign-in button.
	 * @return void
	 */
	public function sign_in( array $customer, Link $link, bool $from_button ): void {
		$user    = self::linked_user( $link );
		$created = false;
		if ( null === $user ) {
			$user    = $this->create_account( $customer, $from_button );
			$created = null !== $user;
		}

		if ( null === $user || CustomerLinker::is_staff( $user->ID ) ) {
			$this->keep_as_guest( $link );
			if ( null !== $user || ( null !== $customer['email'] && false !== email_exists( $customer['email'] ) ) ) {
				self::notice( __( 'Welcome back! Log in to your account to see your points.', 'pixalink-loyalty-for-woocommerce' ) );
			} else {
				self::notice( self::recognised_guest_message() );
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
				'user_id'     => $user->ID,
				'created'     => $created,
				'from_button' => $from_button,
				'via'         => $link->via,
			)
		);
	}

	/**
	 * Where a notice for the shopper is seen: the landing page when WooCommerce prints notices there (shop, product,
	 * cart, checkout, My Account), otherwise My Account (when the store has that page).
	 *
	 * @param string $target Landing URL.
	 * @return string
	 */
	public static function where_notices_show( string $target ): string {
		$account = wc_get_page_permalink( 'myaccount', '' );
		// The requested page itself: is_cart() and is_checkout() also answer true while an order is being placed.
		$pages = array_values( array_filter( array( wc_get_page_id( 'cart' ), wc_get_page_id( 'checkout' ) ), static fn ( int $id ): bool => $id > 0 ) );
		if ( '' === $account || is_woocommerce() || is_account_page() || ( array() !== $pages && is_page( $pages ) ) ) {
			return $target;
		}

		return $account;
	}

	/**
	 * Notice for a member recognised for this visit only (no account to sign in to): they earn on this order, and are
	 * told how to get an account where the store offers one.
	 *
	 * @return string HTML (a link at most).
	 */
	public static function recognised_guest_message(): string {
		$message = __( 'Welcome back! We\'ve recognised your Pixalink membership for this visit, so you\'ll earn points on your order.', 'pixalink-loyalty-for-woocommerce' );

		if ( 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout' ) ) {
			return $message . ' ' . __( 'Create an account at checkout to see your balance here.', 'pixalink-loyalty-for-woocommerce' );
		}

		$account = wc_get_page_permalink( 'myaccount', '' );
		if ( 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) && '' !== $account ) {
			return $message . ' ' . sprintf(
				/* translators: %s: URL of My Account, where shoppers register. */
				__( '<a href="%s">Create an account</a> to see your balance here.', 'pixalink-loyalty-for-woocommerce' ),
				esc_url( $account )
			);
		}

		return $message;
	}

	/**
	 * Show a notice on the next page.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function notice( string $message ): void {
		if ( function_exists( 'wc_add_notice' ) && function_exists( 'WC' ) && null !== WC()->session ) {
			wc_add_notice( $message, 'notice' );
		}
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
	 * Create a customer account for the member when the store allows sign-ups (or the shopper pressed the store's
	 * sign-in button while that setting is on) and the e-mail is free.
	 *
	 * @param array{id:string,phone:string,verified:bool,phone_country:string|null,email:string|null,first_name:string|null,last_name:string|null} $customer    Customer.
	 * @param bool                                                                                                                                 $from_button Whether the shopper pressed the store's sign-in button.
	 * @return \WP_User|null
	 */
	private function create_account( array $customer, bool $from_button ): ?\WP_User {
		$email   = $customer['email'];
		$allowed = 'yes' === get_option( 'woocommerce_enable_myaccount_registration' )
			|| 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout' )
			|| ( $from_button && $this->options->get_bool( Options::PORTAL_SIGN_IN ) );

		/**
		 * Filters whether a portal sign-in may create a WooCommerce customer account.
		 *
		 * @since 0.1.0
		 *
		 * @param bool                $allowed     Default: whether the store lets shoppers register, or the shopper pressed
		 *                                         "Sign in with the member portal" while that setting is on.
		 * @param array<string,mixed> $customer    The Pixalink customer `{id, phone, email, first_name, last_name}`.
		 * @param bool                $from_button Whether the shopper pressed the store's sign-in button.
		 */
		$allowed = (bool) apply_filters( 'pixalink_loyalty_sso_create_account', $allowed, $customer, $from_button );

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
}
