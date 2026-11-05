<?php
/**
 * "Sign in with the member portal" on the store's login forms.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Identity;

use Pixalink\Loyalty\Account\AccountView;
use Pixalink\Loyalty\Account\Assets;
use Pixalink\Loyalty\Account\LoyaltyEndpoint;
use Pixalink\Loyalty\Account\PortalHandoff;
use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Oauth\AuthorizationRequest;
use Pixalink\Loyalty\Oauth\Capability;
use Pixalink\Loyalty\Oauth\ClientCredentials;
use Pixalink\Loyalty\Support\Branding;
use Pixalink\Loyalty\Support\BrandingStyles;
use Pixalink\Loyalty\Support\Html;
use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * A "Sign in from Customer Portal" button on WooCommerce's login forms (My Account and the classic checkout's
 * "Returning customer?" form, both through `woocommerce_login_form_end`; the Checkout block has no such form), and
 * `admin-post.php?action=pixalink_loyalty_portal_sign_in&redirect_to=<page>`, where it starts.
 *
 * The button shows for logged-out shoppers while "Sign in from Customer Portal" is on, the store is connected and
 * the portal can sign them in: over OAuth when the heartbeat advertises it ({@see Capability}) and the store has its
 * client ({@see ClientCredentials}), otherwise through the portal's sign-in page (`branding.sign_in_url`, on the
 * portal host). Every press sends the browser (303, no cache, no Referer) to the portal and nothing to Pixalink:
 *
 * - OAuth: an {@see AuthorizationRequest} (state and PKCE verifier in an encrypted ten-minute cookie) and the
 *   authorize page; the portal sends the shopper back to the store's callback, {@see \Pixalink\Loyalty\Oauth\CallbackHandler};
 * - otherwise: a random state in a ten-minute cookie ({@see SignInState}) and the sign-in page with `return` (the page
 *   to come back to, on this store) and `state`; the portal sends them back to `return` with a `pixalink_sso` ticket
 *   bound to the state, which {@see SsoLanding} redeems there.
 *
 * Over OAuth only, the same round trip verifies a logged-in shopper who is not linked yet ("Verify with WhatsApp" on
 * My Account › Loyalty and the dashboard card, {@see PortalSignIn::verify_url()}, whose start link carries
 * `intent=verify`): the member confirms on the portal (inverse OTP or OTP, as the merchant set it) and the account is
 * linked on return without a second confirmation. Never for staff or an account that is already linked; without OAuth
 * the button is not offered and the one-time code form stays the way to verify.
 */
final class PortalSignIn {

	public const ACTION = 'pixalink_loyalty_portal_sign_in';

	public const TEMPLATE = 'global/portal-sign-in.php';

	/**
	 * Query argument that says why the shopper is back (shared with {@see PortalHandoff}).
	 */
	public const NOTICE_PARAM = PortalHandoff::NOTICE_PARAM;

	public const NOTICE_UNAVAILABLE = 'sign_in_unavailable';

	/**
	 * `intent` of the start link when a logged-in shopper verifies their membership ("Verify with WhatsApp").
	 */
	public const INTENT_VERIFY = 'verify';

	/**
	 * Option storage.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Outlet credentials.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * State cookie.
	 *
	 * @var SignInState
	 */
	private SignInState $state;

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
	 * The store's OAuth client (null: the ticket flow only).
	 *
	 * @var ClientCredentials|null
	 */
	private ?ClientCredentials $oauth_client;

	/**
	 * OAuth requests kept in the browser (null: the ticket flow only).
	 *
	 * @var AuthorizationRequest|null
	 */
	private ?AuthorizationRequest $oauth_requests;

	/**
	 * Constructor.
	 *
	 * @param Options       $options     Option storage.
	 * @param Credentials   $credentials Outlet credentials.
	 * @param SignInState   $state       State cookie.
	 * @param \Closure|null $emit_header Sends a header line (default: header()).
	 * @param \Closure|null $terminate   Ends the request (default: exit).
	 * @param ClientCredentials|null    $oauth_client   The store's OAuth client (null: the ticket flow only).
	 * @param AuthorizationRequest|null $oauth_requests OAuth requests kept in the browser.
	 */
	public function __construct( Options $options, Credentials $credentials, SignInState $state, ?\Closure $emit_header = null, ?\Closure $terminate = null, ?ClientCredentials $oauth_client = null, ?AuthorizationRequest $oauth_requests = null ) {
		$this->options        = $options;
		$this->credentials    = $credentials;
		$this->state          = $state;
		$this->emit_header    = $emit_header ?? static function ( string $line ): void {
			if ( ! headers_sent() ) {
				header( $line );
			}
		};
		$this->terminate      = $terminate ?? static function (): void {
			exit;
		};
		$this->oauth_client   = $oauth_client;
		$this->oauth_requests = $oauth_requests;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_login_form_end', array( $this, 'render_button' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_style' ) );
		add_action( 'template_redirect', array( $this, 'unavailable_notice' ), 20 );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_request' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * The portal's sign-in page while the button may be offered (setting on, store connected, page known).
	 *
	 * @return string|null
	 */
	public function sign_in_url(): ?string {
		if ( ! $this->options->get_bool( Options::PORTAL_SIGN_IN ) || ! $this->credentials->is_configured() ) {
			return null;
		}

		return Branding::remote( $this->options )?->sign_in_url;
	}

	/**
	 * What OAuth sign-in needs, while it may be offered (setting on, store connected, OAuth advertised, client kept).
	 *
	 * @return array{capability:Capability,client_id:string}|null
	 */
	private function oauth(): ?array {
		if ( null === $this->oauth_client || null === $this->oauth_requests || ! $this->options->get_bool( Options::PORTAL_SIGN_IN ) || ! $this->credentials->is_configured() ) {
			return null;
		}

		$capability = Capability::remote( $this->options );
		$client     = null === $capability ? null : $this->oauth_client->get();

		return null === $capability || null === $client
			? null
			: array(
				'capability' => $capability,
				'client_id'  => $client['client_id'],
			);
	}

	/**
	 * Whether the current visitor gets the button.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return ! is_user_logged_in() && ( null !== $this->oauth() || null !== $this->sign_in_url() );
	}

	/**
	 * Whether this logged-in shopper may verify through the portal: not linked yet, not staff, OAuth available.
	 *
	 * @param int $user_id User id (0 when nobody is logged in).
	 * @return bool
	 */
	public function can_verify( int $user_id ): bool {
		return $user_id > 0
			&& null === UserMeta::link( $user_id )
			&& ! CustomerLinker::is_staff( $user_id )
			&& null !== $this->oauth();
	}

	/**
	 * Where "Verify with WhatsApp" points (back to the Loyalty tab), or null when the shopper does not get it.
	 *
	 * @param int $user_id User id.
	 * @return string|null Raw URL (templates escape it).
	 */
	public function verify_url( int $user_id ): ?string {
		if ( ! $this->can_verify( $user_id ) ) {
			return null;
		}

		return self::url( wc_get_account_endpoint_url( LoyaltyEndpoint::ENDPOINT ), self::INTENT_VERIFY );
	}

	/**
	 * URL the button points at.
	 *
	 * @param string $redirect_to Page to come back to.
	 * @param string $intent      '' to sign in, {@see PortalSignIn::INTENT_VERIFY} to verify a logged-in shopper.
	 * @return string Raw URL (templates escape it).
	 */
	public static function url( string $redirect_to, string $intent = '' ): string {
		$args = array(
			'action'      => self::ACTION,
			'redirect_to' => rawurlencode( $redirect_to ),
		);
		if ( '' !== $intent ) {
			$args['intent'] = $intent;
		}

		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/**
	 * `woocommerce_login_form_end` callback.
	 *
	 * @return void
	 */
	public function render_button(): void {
		echo wp_kses( $this->button_html(), Html::allowed() );
	}

	/**
	 * The divider and the button ('' when the visitor does not get one).
	 *
	 * @return string
	 */
	public function button_html(): string {
		if ( ! $this->is_available() ) {
			return '';
		}

		$active = Branding::active( $this->options );
		Assets::enqueue_sign_in_style( $active );

		$label = __( 'Sign in from Customer Portal', 'pixalink-loyalty-for-woocommerce' );

		return (string) wc_get_template_html(
			self::TEMPLATE,
			array(
				'url'      => self::url( self::current_page() ),
				'label'    => $label,
				'logo_url' => $active?->logo_url,
				'branded'  => BrandingStyles::is_branded( $active ),
			),
			AccountView::TEMPLATE_PATH,
			PIXALINK_LOYALTY_DIR . 'templates/'
		);
	}

	/**
	 * `wp_enqueue_scripts` callback: the stylesheet in the head of the pages whose login form shows the button.
	 *
	 * Login forms elsewhere (a shortcode on another page) still get it, in the footer, when the button renders.
	 *
	 * @return void
	 */
	public function enqueue_style(): void {
		if ( ! function_exists( 'is_account_page' ) || ! ( is_account_page() || is_checkout() ) || ! $this->is_available() ) {
			return;
		}

		Assets::enqueue_sign_in_style( Branding::active( $this->options ) );
	}

	/**
	 * `template_redirect` callback: say why the shopper is back when the portal sign-in could not start.
	 *
	 * @return void
	 */
	public function unavailable_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects a fixed message.
		$notice = isset( $_GET[ self::NOTICE_PARAM ] ) && is_string( $_GET[ self::NOTICE_PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_PARAM ] ) ) : '';
		if ( self::NOTICE_UNAVAILABLE !== $notice || is_user_logged_in() || ! function_exists( 'wc_add_notice' ) || null === WC()->session ) {
			return;
		}

		wc_add_notice( __( 'Signing in with the member portal isn\'t available right now. Log in with your password, or try again later.', 'pixalink-loyalty-for-woocommerce' ), 'notice' );
	}

	/**
	 * `admin_post(_nopriv)_pixalink_loyalty_portal_sign_in` callback.
	 *
	 * @return void
	 */
	public function handle_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Starts a sign-in elsewhere; validated to this store in return_url().
		$redirect_to = isset( $_GET['redirect_to'] ) && is_string( $_GET['redirect_to'] ) ? sanitize_url( wp_unslash( $_GET['redirect_to'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only compared with a fixed value; can_verify() decides.
		$intent = isset( $_GET['intent'] ) && is_string( $_GET['intent'] ) ? sanitize_key( wp_unslash( $_GET['intent'] ) ) : '';

		$this->send( $this->resolve( $redirect_to, $intent ) );
		( $this->terminate )();
	}

	/**
	 * Decide where a press of the button goes.
	 *
	 * @param string $redirect_to Requested page to come back to.
	 * @param string $intent      Requested intent ({@see PortalSignIn::INTENT_VERIFY}, or '').
	 * @return array{location:string,external:bool}
	 */
	public function resolve( string $redirect_to, string $intent = '' ): array {
		$return  = self::return_url( $redirect_to );
		$user_id = get_current_user_id();
		$oauth   = $this->oauth();
		if ( $user_id > 0 ) {
			if ( self::INTENT_VERIFY !== $intent || null === $oauth || ! $this->can_verify( $user_id ) ) {
				return array(
					'location' => $return,
					'external' => false,
				);
			}

			return $this->authorize( $oauth, AuthorizationRequest::PURPOSE_VERIFY, $return, $user_id );
		}

		if ( null !== $oauth ) {
			return $this->authorize( $oauth, AuthorizationRequest::PURPOSE_SIGN_IN, $return, 0 );
		}

		$sign_in_url = $this->sign_in_url();
		if ( null === $sign_in_url ) {
			return array(
				'location' => add_query_arg( self::NOTICE_PARAM, self::NOTICE_UNAVAILABLE, $return ),
				'external' => false,
			);
		}

		return array(
			'location' => self::sign_in_location( $sign_in_url, $return, $this->state->issue() ),
			'external' => true,
		);
	}

	/**
	 * Keep an OAuth request in the browser and send it to the authorize page.
	 *
	 * @param array{capability:Capability,client_id:string} $oauth      What OAuth sign-in needs.
	 * @param string                                        $purpose    AuthorizationRequest::PURPOSE_*.
	 * @param string                                        $return_url Absolute page of this store to land on.
	 * @param int                                           $user_id    The account that starts it (0: nobody).
	 * @return array{location:string,external:bool}
	 */
	private function authorize( array $oauth, string $purpose, string $return_url, int $user_id ): array {
		$requests = $this->oauth_requests;
		if ( null === $requests ) {
			return array(
				'location' => $return_url,
				'external' => false,
			);
		}

		return array(
			'location' => $requests->start( $oauth['capability'], $oauth['client_id'], ClientCredentials::redirect_uri(), $purpose, $return_url, $user_id ),
			'external' => true,
		);
	}

	/**
	 * The portal's sign-in page with `return` and `state` added (store-sign-in.query.json).
	 *
	 * @param string $sign_in_url The heartbeat's `branding.sign_in_url`.
	 * @param string $return_url  Absolute page of this store to come back to.
	 * @param string $state       State kept in the cookie.
	 * @return string
	 */
	public static function sign_in_location( string $sign_in_url, string $return_url, string $state ): string {
		$fragment = '';
		$hash     = strpos( $sign_in_url, '#' );
		if ( false !== $hash ) {
			$fragment    = substr( $sign_in_url, $hash );
			$sign_in_url = substr( $sign_in_url, 0, $hash );
		}

		$query = http_build_query(
			array(
				'return' => $return_url,
				'state'  => $state,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		return $sign_in_url . ( false === strpos( $sign_in_url, '?' ) ? '?' : '&' ) . $query . $fragment;
	}

	/**
	 * An absolute page of this store to come back to: `redirect_to` when it is one (never with a spent ticket), else
	 * My Account.
	 *
	 * @param string $redirect_to Requested page (absolute or root-relative).
	 * @return string
	 */
	public static function return_url( string $redirect_to ): string {
		$fallback = wc_get_page_permalink( 'myaccount' );
		$url      = '' === $redirect_to ? '' : wp_validate_redirect( $redirect_to, '' );
		if ( '' === $url ) {
			return $fallback;
		}

		// The portal only returns to the store's own host: hosts other plugins allow for redirects are not enough.
		if ( 1 === preg_match( '#^/(?!/)#', $url ) ) {
			return SsoLanding::target( $url );
		}

		$home = wp_parse_url( home_url() );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_array( $home ) || ! isset( $home['host'] ) || ! is_string( $host ) || strtolower( $host ) !== strtolower( $home['host'] ) ) {
			return $fallback;
		}

		return remove_query_arg( array( SsoLanding::PARAM, SsoLanding::CONFIRM_PARAM ), $url );
	}

	/**
	 * The page the button is on, to come back to: the `redirect_to` the login form was opened with (the Loyalty tab
	 * after a portal button, say) when it is a page of this store, otherwise this page itself.
	 *
	 * @return string Absolute URL.
	 */
	public static function current_page(): string {
		$requested = PortalHandoff::requested_redirect();
		if ( '' !== $requested ) {
			return self::return_url( $requested );
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		return remove_query_arg( self::NOTICE_PARAM, SsoLanding::target( $request_uri ) );
	}

	/**
	 * Send the headers and the 303.
	 *
	 * @param array{location:string,external:bool} $redirect Redirect.
	 * @return void
	 */
	private function send( array $redirect ): void {
		foreach ( wp_get_nocache_headers() as $name => $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				( $this->emit_header )( $name . ': ' . $value );
			}
		}
		( $this->emit_header )( 'Referrer-Policy: no-referrer' );

		if ( $redirect['external'] ) {
			// The sign-in and authorize pages are on the portal's host (checked in Branding and Capability), which
			// wp_safe_redirect() would refuse.
			wp_redirect( $redirect['location'], 303, 'Pixalink Loyalty' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		} else {
			wp_safe_redirect( $redirect['location'], 303, 'Pixalink Loyalty' );
		}
	}
}
