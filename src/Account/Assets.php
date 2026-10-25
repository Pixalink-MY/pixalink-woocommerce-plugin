<?php
/**
 * Front-end scripts of the plugin.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Account;

use Pixalink\Loyalty\Identity\RestController;
use Pixalink\Loyalty\Support\Branding;
use Pixalink\Loyalty\Support\BrandingStyles;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `build/account.js` (OTP link dialog, member QR code and its toggle) from its `*.asset.php` manifest, and
 * `assets/css/account.css` with the member portal's colours.
 *
 * A source checkout without `build/` simply renders the server-side markup (forms still show; the QR shows its value).
 */
final class Assets {

	public const ACCOUNT_HANDLE = 'pixalink-loyalty-account';

	/**
	 * Style handle of My Account (the Loyalty tab, the dashboard card and the shortcodes).
	 */
	public const ACCOUNT_STYLE = 'pixalink-loyalty-account';

	/**
	 * Enqueue the account stylesheet once, with the portal colours as custom properties when branding is on.
	 *
	 * Enqueued on `wp_enqueue_scripts` for My Account pages (so it prints in the head); the templates call it again
	 * for the shortcodes, which can sit on any page (it then prints in the footer).
	 *
	 * @param Branding|null $branding Active branding ({@see Branding::active()}).
	 * @return void
	 */
	public static function enqueue_account_style( ?Branding $branding ): void {
		if ( wp_style_is( self::ACCOUNT_STYLE, 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style( self::ACCOUNT_STYLE, PIXALINK_LOYALTY_URL . 'assets/css/account.css', array(), PIXALINK_LOYALTY_VERSION );
		BrandingStyles::attach( self::ACCOUNT_STYLE, BrandingStyles::ACCOUNT_SELECTORS, $branding );
	}

	/**
	 * Enqueue the account script once, with its REST settings and strings.
	 *
	 * @return bool Whether the script is available.
	 */
	public static function enqueue_account(): bool {
		if ( wp_script_is( self::ACCOUNT_HANDLE, 'enqueued' ) ) {
			return true;
		}

		$asset = self::asset( 'account' );
		if ( null === $asset ) {
			return false;
		}

		wp_enqueue_script( self::ACCOUNT_HANDLE, PIXALINK_LOYALTY_URL . 'build/account.js', $asset['dependencies'], $asset['version'], array( 'in_footer' => true ) );
		wp_add_inline_script(
			self::ACCOUNT_HANDLE,
			'window.pixalinkLoyaltyAccount = ' . wp_json_encode( self::settings() ) . ';',
			'before'
		);

		return true;
	}

	/**
	 * Dependencies and version of a built entry, from `build/{entry}.asset.php`.
	 *
	 * @param string $entry Entry name.
	 * @return array{dependencies:list<non-empty-string>,version:string}|null Null when the entry was not built.
	 */
	private static function asset( string $entry ): ?array {
		$path = PIXALINK_LOYALTY_DIR . 'build/' . $entry . '.asset.php';
		if ( ! is_readable( $path ) ) {
			return null;
		}

		$asset        = require $path;
		$dependencies = array();
		if ( is_array( $asset ) && isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ) {
			foreach ( $asset['dependencies'] as $dependency ) {
				if ( is_string( $dependency ) && '' !== $dependency ) {
					$dependencies[] = $dependency;
				}
			}
		}

		return array(
			'dependencies' => $dependencies,
			'version'      => is_array( $asset ) && isset( $asset['version'] ) && is_string( $asset['version'] ) ? $asset['version'] : PIXALINK_LOYALTY_VERSION,
		);
	}

	/**
	 * Data the script reads from `window.pixalinkLoyaltyAccount`.
	 *
	 * @return array{restUrl:string,nonce:string,i18n:array<string,string>}
	 */
	public static function settings(): array {
		return array(
			'restUrl' => esc_url_raw( rest_url( RestController::ROUTE_NAMESPACE . '/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'i18n'    => array(
				/* translators: %s: masked phone number such as +*******6789. */
				'sentWhatsapp' => __( 'We sent a code by WhatsApp to %s.', 'pixalink-loyalty-for-woocommerce' ),
				/* translators: %s: masked phone number such as +*******6789. */
				'sentSms'      => __( 'We sent a code by SMS to %s.', 'pixalink-loyalty-for-woocommerce' ),
				/* translators: %d: seconds until another code can be requested. */
				'resendIn'     => __( 'You can ask for a new code in %d s.', 'pixalink-loyalty-for-woocommerce' ),
				'resend'       => __( 'Send a new code', 'pixalink-loyalty-for-woocommerce' ),
				'verified'     => __( 'Your phone number is verified. Loading your points…', 'pixalink-loyalty-for-woocommerce' ),
				'failed'       => __( 'Something went wrong. Please try again.', 'pixalink-loyalty-for-woocommerce' ),
				'qrLabel'      => __( 'Member QR code', 'pixalink-loyalty-for-woocommerce' ),
			),
		);
	}
}
