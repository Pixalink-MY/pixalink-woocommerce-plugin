<?php
/**
 * The marketing consent wording shoppers see.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Fields;

use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the consent text (setting, else default, then the `pixalink_loyalty_consent_text` filter) and hashes it.
 *
 * The hash recorded with a shopper's answer is sha256 of exactly the string shown, so Pixalink can prove which
 * wording the shopper agreed to.
 */
final class ConsentText {

	/**
	 * Option storage.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Constructor.
	 *
	 * @param Options $options Option storage.
	 */
	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * Default wording (also the settings placeholder).
	 *
	 * @return string
	 */
	public static function default_text(): string {
		return __( 'Send me offers and news by WhatsApp, SMS and e-mail. I can opt out at any time.', 'pixalink-loyalty-for-woocommerce' );
	}

	/**
	 * The text to show next to the consent checkbox.
	 *
	 * @param string $context Where it is shown: a {@see ConsentRecord} source (checkout, my_account, registration).
	 * @return string Plain text.
	 */
	public function text( string $context ): string {
		$stored = trim( $this->options->get_string( Options::CHECKOUT_CONSENT_TEXT ) );
		$text   = '' !== $stored ? $stored : self::default_text();

		/**
		 * Filters the marketing consent wording. The exact string returned is shown and hashed into the record.
		 *
		 * @since 0.1.0
		 *
		 * @param string $text    Plain-text wording.
		 * @param string $context checkout, my_account or registration.
		 */
		$filtered = apply_filters( 'pixalink_loyalty_consent_text', $text, $context );

		return is_string( $filtered ) && '' !== trim( $filtered ) ? $filtered : self::default_text();
	}

	/**
	 * The SHA-256 of the shown text (64 lower-case hex characters).
	 *
	 * @param string $text Text exactly as shown.
	 * @return string
	 */
	public static function hash( string $text ): string {
		return hash( 'sha256', $text );
	}
}
