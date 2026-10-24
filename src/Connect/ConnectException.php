<?php
/**
 * A Connect step that could not complete.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Connect;

use Pixalink\Loyalty\Api\ApiException;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a stable reason the admin UI maps to a translated message ({@see \Pixalink\Loyalty\Admin\Notices}).
 */
final class ConnectException extends \RuntimeException {

	public const INVALID_STATE          = 'invalid_state';
	public const INVALID_GRANT          = 'invalid_grant';
	public const INVALID_REQUEST        = 'invalid_request';
	public const HOSTED_ERROR           = 'hosted_error';
	public const INVALID_KEY_FORMAT     = 'invalid_key_format';
	public const KEY_REJECTED           = 'key_rejected';
	public const SITE_ALREADY_CONNECTED = 'site_already_connected';
	public const ACCOUNT_INACTIVE       = 'account_inactive';
	public const NETWORK_ERROR          = 'network_error';
	public const SERVER_ERROR           = 'server_error';
	public const INVALID_RESPONSE       = 'invalid_response';
	public const NOT_CONNECTED          = 'not_connected';
	public const CREDENTIALS_OVERRIDDEN = 'credentials_overridden';

	/**
	 * Every reason (redirect query values are checked against this list).
	 */
	public const REASONS = array(
		self::INVALID_STATE,
		self::INVALID_GRANT,
		self::INVALID_REQUEST,
		self::HOSTED_ERROR,
		self::INVALID_KEY_FORMAT,
		self::KEY_REJECTED,
		self::SITE_ALREADY_CONNECTED,
		self::ACCOUNT_INACTIVE,
		self::NETWORK_ERROR,
		self::SERVER_ERROR,
		self::INVALID_RESPONSE,
		self::NOT_CONNECTED,
		self::CREDENTIALS_OVERRIDDEN,
	);

	/**
	 * Stable reason.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Constructor.
	 *
	 * @param string          $reason   One of the reason constants.
	 * @param string          $message  English message for logs.
	 * @param \Throwable|null $previous Previous exception.
	 */
	public function __construct( string $reason, string $message = '', ?\Throwable $previous = null ) {
		parent::__construct( '' !== $message ? $message : 'Pixalink connect failed: ' . $reason . '.', 0, $previous );
		$this->reason = $reason;
	}

	/**
	 * Map an API failure to a Connect reason.
	 *
	 * @param ApiException $exception API failure.
	 * @param string       $fallback  Reason for a 4xx without a more specific mapping.
	 * @return ConnectException
	 */
	public static function from_api( ApiException $exception, string $fallback = self::SERVER_ERROR ): ConnectException {
		$code   = $exception->error_code();
		$status = $exception->http_status();

		if ( ApiException::NETWORK_ERROR === $code ) {
			$reason = self::NETWORK_ERROR;
		} elseif ( ApiException::INVALID_RESPONSE === $code ) {
			$reason = self::INVALID_RESPONSE;
		} elseif ( self::SITE_ALREADY_CONNECTED === $code ) {
			$reason = self::SITE_ALREADY_CONNECTED;
		} elseif ( 403 === $status && in_array( $code, Heartbeat::ACCOUNT_STATE_CODES, true ) ) {
			$reason = self::ACCOUNT_INACTIVE;
		} elseif ( $exception->is_auth_failure() ) {
			$reason = self::KEY_REJECTED;
		} elseif ( $status >= 500 || 0 === $status ) {
			$reason = self::SERVER_ERROR;
		} else {
			$reason = $fallback;
		}

		return new self( $reason, $exception->getMessage(), $exception );
	}

	/**
	 * Stable reason.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}
}
