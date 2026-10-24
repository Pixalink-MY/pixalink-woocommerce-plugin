<?php
/**
 * Member QR code.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Account;

defined( 'ABSPATH' ) || exit;

/**
 * Markup for the member QR code: the bundled `qrcode-generator` library (MIT) draws it client-side into the
 * placeholder from `data-pixalink-qr`. The value never leaves the page: no external image API is involved.
 * Without JavaScript the value itself is shown, so outlet staff can still type it in.
 */
final class QrService {

	/**
	 * Largest value encoded (summary `qr.value` is at most 64 characters).
	 */
	public const MAX_LENGTH = 64;

	/**
	 * QR placeholder markup, or '' for an unusable value.
	 *
	 * @param string $value Value to encode (the member's phone number).
	 * @return string
	 */
	public function markup( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > self::MAX_LENGTH || 1 !== preg_match( '/^[\x20-\x7E]+$/', $value ) ) {
			return '';
		}

		Assets::enqueue_account();

		return sprintf(
			'<div class="pixalink-loyalty-qr" data-pixalink-qr="%1$s" role="img" aria-label="%2$s"><span class="pixalink-loyalty-qr__fallback">%3$s</span></div>',
			esc_attr( $value ),
			esc_attr__( 'Member QR code to scan at our outlets', 'pixalink-loyalty-for-woocommerce' ),
			esc_html( $value )
		);
	}
}
