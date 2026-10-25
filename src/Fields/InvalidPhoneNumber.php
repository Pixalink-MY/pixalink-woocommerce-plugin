<?php
/**
 * A phone number that cannot be turned into E.164.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Raised by {@see PhoneNormalizer::parse()} with a machine reason, so forms can say what is wrong.
 */
final class InvalidPhoneNumber extends \InvalidArgumentException {

	public const EMPTY                = 'empty';
	public const INVALID_CHARACTERS   = 'invalid_characters';
	public const UNKNOWN_COUNTRY      = 'unknown_country';
	public const UNKNOWN_CALLING_CODE = 'unknown_calling_code';
	public const TOO_SHORT            = 'too_short';
	public const TOO_LONG             = 'too_long';
	public const INVALID              = 'invalid';

	/**
	 * Machine reason (one of the constants).
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Constructor.
	 *
	 * @param string $reason Machine reason.
	 */
	public function __construct( string $reason ) {
		parent::__construct( sprintf( 'Invalid phone number (%s).', $reason ) );

		$this->reason = $reason;
	}

	/**
	 * Machine reason.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Shopper-facing explanation.
	 *
	 * @return string
	 */
	public function user_message(): string {
		return match ( $this->reason ) {
			self::EMPTY                => __( 'Enter your phone number.', 'pixalink-loyalty-for-woocommerce' ),
			self::INVALID_CHARACTERS   => __( 'A phone number can only contain digits, spaces, dashes, brackets and a leading +.', 'pixalink-loyalty-for-woocommerce' ),
			self::UNKNOWN_COUNTRY      => __( 'Choose the country of your phone number.', 'pixalink-loyalty-for-woocommerce' ),
			self::UNKNOWN_CALLING_CODE => __( 'The country code of this phone number is not recognised.', 'pixalink-loyalty-for-woocommerce' ),
			self::TOO_SHORT            => __( 'This phone number is too short.', 'pixalink-loyalty-for-woocommerce' ),
			self::TOO_LONG             => __( 'This phone number is too long.', 'pixalink-loyalty-for-woocommerce' ),
			default                    => __( 'Enter a valid phone number.', 'pixalink-loyalty-for-woocommerce' ),
		};
	}
}
