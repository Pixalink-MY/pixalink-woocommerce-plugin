<?php
/**
 * Why linking a shopper to Pixalink failed.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Identity;

use Pixalink\Loyalty\Api\ApiException;

defined( 'ABSPATH' ) || exit;

/**
 * A link, OTP or portal sign-in failure with a machine reason, the HTTP status the plugin's REST routes answer with,
 * and a shopper-facing message.
 */
final class LinkException extends \RuntimeException {

	public const NOT_CONNECTED         = 'not_connected';
	public const NO_PENDING_LINK       = 'no_pending_link';
	public const RESEND_TOO_SOON       = 'resend_too_soon';
	public const THROTTLED             = 'throttled';
	public const OTP_INVALID           = 'otp_invalid';
	public const OTP_EXPIRED           = 'otp_expired';
	public const OTP_ATTEMPTS_EXCEEDED = 'otp_attempts_exceeded';
	public const TICKET_INVALID        = 'ticket_invalid';
	public const INVALID_PHONE         = 'invalid_phone';
	public const UNAVAILABLE           = 'unavailable';
	public const STAFF_ACCOUNT         = 'staff_account';

	private const STATUSES = array(
		self::NOT_CONNECTED         => 503,
		self::NO_PENDING_LINK       => 409,
		self::RESEND_TOO_SOON       => 429,
		self::THROTTLED             => 429,
		self::OTP_INVALID           => 422,
		self::OTP_EXPIRED           => 422,
		self::OTP_ATTEMPTS_EXCEEDED => 422,
		self::TICKET_INVALID        => 410,
		self::INVALID_PHONE         => 400,
		self::UNAVAILABLE           => 503,
		self::STAFF_ACCOUNT         => 403,
	);

	/**
	 * Machine reason.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Seconds to wait before retrying.
	 *
	 * @var int|null
	 */
	private ?int $retry_after;

	/**
	 * Constructor.
	 *
	 * @param string          $reason      Machine reason (one of the constants).
	 * @param int|null        $retry_after Seconds to wait, when known.
	 * @param \Throwable|null $previous    Cause.
	 */
	public function __construct( string $reason, ?int $retry_after = null, ?\Throwable $previous = null ) {
		parent::__construct( sprintf( 'Pixalink link failed (%s).', $reason ), 0, $previous );

		$this->reason      = $reason;
		$this->retry_after = $retry_after;
	}

	/**
	 * Translate an API failure.
	 *
	 * @param ApiException $exception API failure.
	 * @return LinkException
	 */
	public static function from_api( ApiException $exception ): LinkException {
		$code   = $exception->error_code();
		$status = $exception->http_status();

		if ( ApiException::NOT_CONNECTED === $code ) {
			return new self( self::NOT_CONNECTED, null, $exception );
		}

		if ( 429 === $status ) {
			return new self( self::THROTTLED, $exception->retry_after() ?? 60, $exception );
		}

		if ( in_array( $code, array( self::OTP_INVALID, self::OTP_EXPIRED, self::OTP_ATTEMPTS_EXCEEDED, self::TICKET_INVALID ), true ) ) {
			return new self( $code, null, $exception );
		}

		if ( 410 === $status ) {
			return new self( self::TICKET_INVALID, null, $exception );
		}

		return new self( self::UNAVAILABLE, $exception->retry_after(), $exception );
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
	 * Seconds to wait before retrying, when known.
	 *
	 * @return int|null
	 */
	public function retry_after(): ?int {
		return $this->retry_after;
	}

	/**
	 * HTTP status for the plugin's REST routes.
	 *
	 * @return int
	 */
	public function http_status(): int {
		return self::STATUSES[ $this->reason ] ?? 500;
	}

	/**
	 * Shopper-facing message.
	 *
	 * @return string
	 */
	public function user_message(): string {
		return match ( $this->reason ) {
			self::NOT_CONNECTED, self::UNAVAILABLE => __( 'Loyalty is temporarily unavailable. Please try again in a few minutes.', 'pixalink-loyalty-for-woocommerce' ),
			self::NO_PENDING_LINK => __( 'Your code has expired. Enter your phone number again to get a new one.', 'pixalink-loyalty-for-woocommerce' ),
			self::RESEND_TOO_SOON, self::THROTTLED => sprintf(
				/* translators: %d: seconds to wait. */
				_n( 'Please wait %d second before asking for another code.', 'Please wait %d seconds before asking for another code.', max( 1, (int) $this->retry_after ), 'pixalink-loyalty-for-woocommerce' ),
				max( 1, (int) $this->retry_after )
			),
			self::OTP_INVALID => __( 'That code is not right. Check the message and try again.', 'pixalink-loyalty-for-woocommerce' ),
			self::OTP_EXPIRED => __( 'That code has expired. Ask for a new one.', 'pixalink-loyalty-for-woocommerce' ),
			self::OTP_ATTEMPTS_EXCEEDED => __( 'Too many wrong codes. Ask for a new one.', 'pixalink-loyalty-for-woocommerce' ),
			self::TICKET_INVALID => __( 'This sign-in link has expired or was already used. Open the store from your loyalty app again, or verify your phone number here.', 'pixalink-loyalty-for-woocommerce' ),
			self::INVALID_PHONE => __( 'Enter a valid phone number.', 'pixalink-loyalty-for-woocommerce' ),
			self::STAFF_ACCOUNT => __( 'You\'re signed in to this store as staff, so a Pixalink membership can\'t be connected to this account. Log out to shop as a member.', 'pixalink-loyalty-for-woocommerce' ),
			default => __( 'Something went wrong. Please try again.', 'pixalink-loyalty-for-woocommerce' ),
		};
	}
}
