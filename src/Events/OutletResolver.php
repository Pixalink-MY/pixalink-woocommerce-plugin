<?php
/**
 * Outlet id for event envelopes.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Events;

use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the `outlet_id` every envelope carries.
 *
 * Precedence: the `pixalink_loyalty_outlet_id` filter (the Connect layer knows the outlet it connected to), then a
 * one-day transient, then `GET outlet` with the stored key. The transient is dropped whenever the credentials change.
 */
final class OutletResolver {

	public const TRANSIENT = 'pixalink_loyalty_outlet_id';

	/**
	 * API client.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * Constructor.
	 *
	 * @param Client $client API client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * The connected outlet's id.
	 *
	 * @return string
	 * @throws ApiException When the outlet cannot be determined (not connected, network, rejected key).
	 */
	public function outlet_id(): string {
		/**
		 * Filters the Pixalink outlet id sent in event envelopes.
		 *
		 * Return a non-empty string to skip the `GET outlet` lookup.
		 *
		 * @since 0.1.0
		 *
		 * @param string $outlet_id Outlet id, empty when unknown.
		 */
		$filtered = apply_filters( 'pixalink_loyalty_outlet_id', '' );
		if ( is_scalar( $filtered ) && '' !== trim( (string) $filtered ) ) {
			return trim( (string) $filtered );
		}

		$cached = get_transient( self::TRANSIENT );
		if ( is_scalar( $cached ) && '' !== (string) $cached ) {
			return (string) $cached;
		}

		$response = $this->client->get( 'outlet' );
		$outlet   = isset( $response->data['outlet'] ) && is_array( $response->data['outlet'] ) ? $response->data['outlet'] : array();
		$id       = isset( $outlet['id'] ) && is_scalar( $outlet['id'] ) ? trim( (string) $outlet['id'] ) : '';
		if ( '' === $id ) {
			$exception = ApiException::invalid_response( $response->status );
			throw $exception;
		}

		set_transient( self::TRANSIENT, $id, DAY_IN_SECONDS );

		return $id;
	}

	/**
	 * Forget the cached outlet id.
	 *
	 * @return void
	 */
	public function forget(): void {
		delete_transient( self::TRANSIENT );
	}
}
