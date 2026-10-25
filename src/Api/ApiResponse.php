<?php
/**
 * Successful Pixalink API response.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Api;

defined( 'ABSPATH' ) || exit;

/**
 * A 2xx response with its decoded JSON object body.
 */
final class ApiResponse {

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	public readonly int $status;

	/**
	 * Decoded JSON object body (empty for 204).
	 *
	 * @var array<mixed>
	 */
	public readonly array $data;

	/**
	 * Response headers keyed by lower-case name.
	 *
	 * @var array<string,string>
	 */
	private array $headers;

	/**
	 * Constructor.
	 *
	 * @param int                  $status  HTTP status.
	 * @param array<mixed>         $data    Decoded body.
	 * @param array<string,string> $headers Headers (any case).
	 */
	public function __construct( int $status, array $data, array $headers = array() ) {
		$this->status  = $status;
		$this->data    = $data;
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
	}

	/**
	 * A response header value.
	 *
	 * @param string $name Header name (case-insensitive).
	 * @return string|null
	 */
	public function header( string $name ): ?string {
		return $this->headers[ strtolower( $name ) ] ?? null;
	}
}
