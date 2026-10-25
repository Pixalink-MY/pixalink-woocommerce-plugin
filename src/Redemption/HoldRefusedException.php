<?php
/**
 * A hold Pixalink refused or could not place.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Redemption;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the shopper-facing message (the exception message) and the server's machine code.
 */
final class HoldRefusedException extends \RuntimeException {

	/**
	 * Server error code (points_insufficient, voucher_unavailable, discount_mismatch, network_error, ...).
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * Constructor.
	 *
	 * @param string          $error_code Server error code.
	 * @param string          $message    Shopper-facing message.
	 * @param \Throwable|null $previous   Cause.
	 */
	public function __construct( string $error_code, string $message, ?\Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
		$this->error_code = $error_code;
	}

	/**
	 * Server error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return $this->error_code;
	}
}
