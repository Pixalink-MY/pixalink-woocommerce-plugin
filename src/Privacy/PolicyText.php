<?php
/**
 * Suggested privacy policy text.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a suggested section to Settings › Privacy › Policy Guide.
 */
final class PolicyText {

	/**
	 * Register the hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'add' ) );
	}

	/**
	 * `admin_init` callback.
	 *
	 * @return void
	 */
	public function add(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( __( 'Pixalink Loyalty for WooCommerce', 'pixalink-loyalty-for-woocommerce' ), wp_kses_post( wpautop( self::text(), false ) ) );
		}
	}

	/**
	 * Suggested text.
	 *
	 * @return string
	 */
	public static function text(): string {
		return implode(
			"\n\n",
			array(
				'<strong>' . __( 'Loyalty programme', 'pixalink-loyalty-for-woocommerce' ) . '</strong>',
				__( 'Our loyalty programme is run with Pixalink. When you place an order we send Pixalink your name, e-mail address, the mobile number you enter for loyalty points, your account number on this site (if you have one), the order details and your marketing choice, so that you earn points and can use your vouchers in our outlets and online.', 'pixalink-loyalty-for-woocommerce' ),
				__( 'When you verify your mobile number, Pixalink sends you a one-time code by WhatsApp or SMS. Your points, vouchers and loyalty history are kept by Pixalink; this site only stores your loyalty member number, your mobile number and when it was verified.', 'pixalink-loyalty-for-woocommerce' ),
				__( 'We only send you offers if you tick the marketing box. We record when you agreed and the exact wording you saw. You can withdraw at any time under My Account › Account details.', 'pixalink-loyalty-for-woocommerce' ),
				sprintf(
					/* translators: %s: Pixalink privacy policy URL. */
					__( 'Pixalink\'s privacy policy: %s', 'pixalink-loyalty-for-woocommerce' ),
					'https://pixalink.io/privacy-policy'
				),
			)
		);
	}
}
