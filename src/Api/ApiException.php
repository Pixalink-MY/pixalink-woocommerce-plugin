<?php
/**
 * Error raised for failed Pixalink API calls.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Api;

defined( 'ABSPATH' ) || exit;

/**
 * A failed API call: transport error, non-2xx response or unreadable body.
 *
 * Mirrors the server's error envelope `{message, code, errors?}`; `code` is a stable machine string such as
 * `timestamp_skew`, `points_insufficient` or `verification_required`.
 */
final class ApiException extends \RuntimeException {

	/**
	 * Transport-level failure (DNS, TLS, timeout); `http_status` is 0.
	 */
	public const NETWORK_ERROR = 'network_error';

	/**
	 * 2xx response whose body is not a JSON object.
	 */
	public const INVALID_RESPONSE = 'invalid_response';

	/**
	 * No credentials stored for an authenticated call.
	 */
	public const NOT_CONNECTED = 'not_connected';

	/**
	 * Stable machine error code.
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * HTTP status (0 for transport errors).
	 *
	 * @var int
	 */
	private int $http_status;

	/**
	 * Seconds to wait before retrying, from Retry-After.
	 *
	 * @var int|null
	 */
	private ?int $retry_after;

	/**
	 * Decoded error body (validation `errors`, `available` points, ...).
	 *
	 * @var array<string,mixed>
	 */
	private array $details;

	/**
	 * Server clock reported with the error (unix seconds).
	 *
	 * @var int|null
	 */
	private ?int $server_time;

	/**
	 * Constructor.
	 *
	 * @param string              $error_code  Stable machine error code.
	 * @param int                 $http_status HTTP status, 0 for transport errors.
	 * @param int|null            $retry_after Seconds from Retry-After.
	 * @param array<string,mixed> $details     Decoded error body.
	 * @param int|null            $server_time Server unix time, when reported.
	 * @param string              $message     Human-readable message (English, for logs).
	 * @param \Throwable|null     $previous    Previous exception.
	 */
	public function __construct(
		string $error_code,
		int $http_status,
		?int $retry_after = null,
		array $details = array(),
		?int $server_time = null,
		string $message = '',
		?\Throwable $previous = null
	) {
		parent::__construct( '' !== $message ? $message : sprintf( 'Pixalink API error "%s" (HTTP %d).', $error_code, $http_status ), $http_status, $previous );

		$this->error_code  = $error_code;
		$this->http_status = $http_status;
		$this->retry_after = $retry_after;
		$this->details     = $details;
		$this->server_time = $server_time;
	}

	/**
	 * No credentials are stored for an authenticated call.
	 *
	 * @return ApiException
	 */
	public static function not_connected(): ApiException {
		return new self( self::NOT_CONNECTED, 0, null, array(), null, 'This store is not connected to Pixalink.' );
	}

	/**
	 * Transport failure before any HTTP response (DNS, TLS, timeout).
	 *
	 * @param string $transport_code WP_Error code (for example "http_request_failed").
	 * @param string $message        WP_Error message.
	 * @return ApiException
	 */
	public static function network( string $transport_code, string $message ): ApiException {
		return new self( self::NETWORK_ERROR, 0, null, array( 'transport' => $transport_code ), null, $message );
	}

	/**
	 * A 2xx response whose body is not a JSON object.
	 *
	 * @param int $http_status HTTP status.
	 * @return ApiException
	 */
	public static function invalid_response( int $http_status ): ApiException {
		return new self( self::INVALID_RESPONSE, $http_status, null, array(), null, 'Pixalink returned a response that is not a JSON object.' );
	}

	/**
	 * Stable machine error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * HTTP status (0 for transport errors).
	 *
	 * @return int
	 */
	public function http_status(): int {
		return $this->http_status;
	}

	/**
	 * Seconds to wait before retrying, when the server said so.
	 *
	 * @return int|null
	 */
	public function retry_after(): ?int {
		return $this->retry_after;
	}

	/**
	 * Decoded error body.
	 *
	 * @return array<string,mixed>
	 */
	public function details(): array {
		return $this->details;
	}

	/**
	 * Server time reported with the error.
	 *
	 * @return int|null
	 */
	public function server_time(): ?int {
		return $this->server_time;
	}

	/**
	 * Whether retrying the same request later can succeed (outbox backoff uses this).
	 *
	 * @return bool
	 */
	public function is_retryable(): bool {
		if ( self::NETWORK_ERROR === $this->error_code ) {
			return true;
		}

		return in_array( $this->http_status, array( 408, 409, 425, 429 ), true ) || $this->http_status >= 500;
	}

	/**
	 * Whether the outlet key was rejected (delivery should pause until reconnected).
	 *
	 * @return bool
	 */
	public function is_auth_failure(): bool {
		return in_array( $this->http_status, array( 401, 403 ), true ) && 'timestamp_skew' !== $this->error_code;
	}
}
