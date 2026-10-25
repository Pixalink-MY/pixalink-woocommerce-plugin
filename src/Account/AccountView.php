<?php
/**
 * What a shopper sees of their loyalty account.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Account;

use Pixalink\Loyalty\Fields\PhoneCountries;
use Pixalink\Loyalty\Fields\PhoneNormalizer;
use Pixalink\Loyalty\Identity\CustomerLinker;
use Pixalink\Loyalty\Identity\GuestLinkStore;
use Pixalink\Loyalty\Identity\LinkException;
use Pixalink\Loyalty\Identity\ShopperContext;
use Pixalink\Loyalty\Identity\SsoLanding;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Support\Branding;
use Pixalink\Loyalty\Support\BrandingStyles;
use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the My Account › Loyalty content and the shortcodes from the overridable templates
 * `templates/myaccount/loyalty.php` (verified) and `templates/myaccount/link-account.php` (not verified yet). Themes
 * override them under `{theme}/pixalink-loyalty/myaccount/`.
 *
 * Unverified shoppers never see balances: they get the phone form and the one-time code dialog. Staff accounts, which
 * are never linked, get a notice saying so instead; a guest the portal recognised for this visit is told so above the
 * login prompt.
 *
 * Verified members' portal buttons point at {@see PortalHandoff} (signed in); everyone else gets the plain, signed-out
 * portal address. With "Match your member portal" on, the templates carry the portal logo and `is-branded`.
 */
final class AccountView {

	public const TEMPLATE_PATH = 'pixalink-loyalty/';

	/**
	 * Summaries.
	 *
	 * @var SummaryService
	 */
	private SummaryService $summaries;

	/**
	 * QR markup.
	 *
	 * @var QrService
	 */
	private QrService $qr;

	/**
	 * Option storage.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Phone countries.
	 *
	 * @var PhoneCountries
	 */
	private PhoneCountries $countries;

	/**
	 * Phone normaliser.
	 *
	 * @var PhoneNormalizer
	 */
	private PhoneNormalizer $normalizer;

	/**
	 * Pending links (to resume the code dialog after a reload).
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
	 * Signed-in portal links.
	 *
	 * @var PortalHandoff
	 */
	private PortalHandoff $handoff;

	/**
	 * Constructor.
	 *
	 * @param SummaryService  $summaries  Summaries.
	 * @param QrService       $qr         QR markup.
	 * @param Options         $options    Option storage.
	 * @param PhoneCountries  $countries  Phone countries.
	 * @param PhoneNormalizer $normalizer Phone normaliser.
	 * @param CustomerLinker  $linker     Linker.
	 * @param GuestLinkStore  $guests     Guest links.
	 * @param PortalHandoff   $handoff    Signed-in portal links.
	 */
	public function __construct( SummaryService $summaries, QrService $qr, Options $options, PhoneCountries $countries, PhoneNormalizer $normalizer, CustomerLinker $linker, GuestLinkStore $guests, PortalHandoff $handoff ) {
		$this->summaries  = $summaries;
		$this->qr         = $qr;
		$this->options    = $options;
		$this->countries  = $countries;
		$this->normalizer = $normalizer;
		$this->linker     = $linker;
		$this->guests     = $guests;
		$this->handoff    = $handoff;
	}

	/**
	 * Loyalty content for the current shopper (tab and `[pixalink_loyalty_summary]`).
	 *
	 * @return string HTML.
	 */
	public function render_current(): string {
		$shopper = ShopperContext::current( $this->guests );
		if ( ! $shopper->is_logged_in() ) {
			return $this->render_recognised_guest() . $this->login_prompt();
		}

		$branding = Branding::active( $this->options );
		Assets::enqueue_account_style( $branding );

		$user_id = $shopper->user_id();
		if ( $shopper->is_verified() ) {
			$result = $this->summaries->for_user( $user_id );
			if ( SummaryService::UNVERIFIED !== $result['state'] ) {
				return $this->template( 'myaccount/loyalty.php', $this->loyalty_args( $result, $branding ) );
			}
		}

		if ( CustomerLinker::is_staff( $user_id ) ) {
			return (string) wc_print_notice( ( new LinkException( LinkException::STAFF_ACCOUNT ) )->user_message(), 'notice', array(), true );
		}

		return $this->verify_again_notice() . $this->link_form( $shopper, $branding );
	}

	/**
	 * Variables of `myaccount/loyalty.php`.
	 *
	 * @param array{state:string,summary:array<string,mixed>|null} $result   Summary result.
	 * @param Branding|null                                        $branding Active branding.
	 * @return array<string,mixed>
	 */
	private function loyalty_args( array $result, ?Branding $branding ): array {
		$summary = $result['summary'];
		$links   = array();
		if ( is_array( $summary ) ) {
			foreach ( array( 'rewards', 'points', 'credits' ) as $destination ) {
				if ( 'credits' === $destination && null === ( $summary['credits_cents'] ?? null ) ) {
					continue;
				}
				if ( in_array( $destination, $this->handoff->destinations(), true ) ) {
					$links[ $destination ] = PortalHandoff::url( $destination );
				}
			}
		}

		$tier = is_array( $summary ) && is_array( $summary['tier'] ?? null ) ? $summary['tier'] : array();

		return array(
			'summary'      => $summary,
			'unavailable'  => SummaryService::UNAVAILABLE === $result['state'],
			'qr_markup'    => $this->options->get_bool( Options::ACCOUNT_SHOW_QR ) && is_array( $summary ) && is_string( $summary['qr'] ?? null ) ? $this->qr->markup( $summary['qr'] ) : '',
			'checkout_url' => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' ),
			'branded'      => BrandingStyles::is_branded( $branding ),
			'logo_url'     => $branding?->logo_url,
			'store_name'   => self::store_name( $branding ),
			'member_name'  => self::member_name( get_current_user_id() ),
			'portal_links' => $links,
			'tier_style'   => BrandingStyles::tier_style( $tier['color'] ?? null ),
			'expiring'     => is_array( $summary ) ? DashboardCard::present( $summary )['expiring'] : null,
		);
	}

	/**
	 * The portal's name while its look is used, else the store's.
	 *
	 * @param Branding|null $branding Active branding.
	 * @return string
	 */
	public static function store_name( ?Branding $branding ): string {
		if ( null !== $branding && null !== $branding->name ) {
			return $branding->name;
		}

		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * A user's name for the member card ('' when they have none).
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function member_name( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return '';
		}

		$name = trim( $user->first_name . ' ' . $user->last_name );

		return '' !== $name ? $name : trim( (string) $user->display_name );
	}

	/**
	 * "Verify again" after Pixalink refused to open the portal for an account link it no longer accepts.
	 *
	 * @return string HTML.
	 */
	private function verify_again_notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects a fixed message.
		$notice = isset( $_GET[ PortalHandoff::NOTICE_PARAM ] ) && is_string( $_GET[ PortalHandoff::NOTICE_PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ PortalHandoff::NOTICE_PARAM ] ) ) : '';
		if ( PortalHandoff::NOTICE_VERIFY_AGAIN !== $notice ) {
			return '';
		}

		return (string) wc_print_notice( __( 'Please verify your phone number again to open the member portal.', 'pixalink-loyalty-for-woocommerce' ), 'notice', array(), true );
	}

	/**
	 * "Recognised for this visit" for a logged-out shopper whose session carries a verified link ('' otherwise).
	 *
	 * @return string HTML.
	 */
	public function render_recognised_guest(): string {
		$shopper = ShopperContext::current( $this->guests );
		if ( $shopper->is_logged_in() || ! $shopper->is_verified() ) {
			return '';
		}

		$portal = $this->handoff->portal_url( 0 );

		return sprintf(
			'<p class="pixalink-loyalty-recognised">%1$s%2$s</p>',
			wp_kses( SsoLanding::recognised_guest_message(), array( 'a' => array( 'href' => array() ) ) ),
			null === $portal ? '' : sprintf( ' <a class="pixalink-loyalty-recognised__portal" href="%1$s" target="_blank" rel="noopener">%2$s</a>', esc_url( $portal ), esc_html__( 'Open member portal', 'pixalink-loyalty-for-woocommerce' ) )
		);
	}

	/**
	 * `[pixalink_loyalty_qr]`: the QR code alone, for verified members only.
	 *
	 * @return string HTML.
	 */
	public function render_qr(): string {
		$shopper = ShopperContext::current( $this->guests );
		if ( ! $shopper->is_logged_in() || ! $shopper->is_verified() ) {
			return '';
		}

		$result = $this->summaries->for_user( $shopper->user_id() );
		$value  = is_array( $result['summary'] ) && is_string( $result['summary']['qr'] ?? null ) ? $result['summary']['qr'] : '';

		return $this->qr->markup( $value );
	}

	/**
	 * Phone form and code dialog for a logged-in shopper who is not verified.
	 *
	 * @param ShopperContext $shopper  Shopper.
	 * @param Branding|null  $branding Active branding.
	 * @return string HTML.
	 */
	private function link_form( ShopperContext $shopper, ?Branding $branding ): string {
		$user_id  = $shopper->user_id();
		$saved    = UserMeta::phone( $user_id );
		$country  = null !== $saved && $this->countries->is_supported( $saved[1] ) ? $saved[1] : $this->countries->default_country();
		$national = null !== $saved ? (string) $this->normalizer->normalize( $saved[0], $saved[1] )?->national : '';
		$pending  = $this->linker->pending( $shopper );

		Assets::enqueue_account();

		return $this->template(
			'myaccount/link-account.php',
			array(
				'countries'  => $this->countries->options(),
				'country'    => $country,
				'national'   => $national,
				'pending'    => null !== $pending,
				'branded'    => BrandingStyles::is_branded( $branding ),
				'portal_url' => $this->handoff->portal_url( 0 ),
			)
		);
	}

	/**
	 * Prompt shown to logged-out visitors by the shortcodes.
	 *
	 * @return string HTML.
	 */
	private function login_prompt(): string {
		$url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();

		return sprintf(
			'<p class="pixalink-loyalty-login">%s</p>',
			wp_kses(
				sprintf(
					/* translators: %s: URL of the login page. */
					__( '<a href="%s">Log in</a> to see your points and vouchers.', 'pixalink-loyalty-for-woocommerce' ),
					esc_url( $url )
				),
				array( 'a' => array( 'href' => array() ) )
			)
		);
	}

	/**
	 * Render an overridable template.
	 *
	 * @param string              $name Template path below templates/.
	 * @param array<string,mixed> $args Variables.
	 * @return string
	 */
	private function template( string $name, array $args ): string {
		return wc_get_template_html( $name, $args, self::TEMPLATE_PATH, PIXALINK_LOYALTY_DIR . 'templates/' );
	}
}
