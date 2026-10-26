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
 * one-day transient, then `GET outlet` with the stored key.
 *
 * The transient records which credentials it was resolved for: a SHA-256 fingerprint of the API key and the API base,
 * never the key itself. A cached id whose fingerprint no longer matches (the key in wp-config.php or the API base
 * changed since) is not used, so a store reconnected to another outlet never stamps its events with the old one.
 * It is also dropped whenever the credentials change through the plugin, a connection is recorded, or a heartbeat
 * reports another outlet ({@see Module}).
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
	 * Fingerprint of the current credentials, `fn (): string`.
	 *
	 * @var \Closure
	 */
	private \Closure $fingerprint;

	/**
	 * Constructor.
	 *
	 * @param Client        $client      API client.
	 * @param \Closure|null $fingerprint `fn (): string`, see {@see OutletResolver::fingerprint()}; defaults to none.
	 */
	public function __construct( Client $client, ?\Closure $fingerprint = null ) {
		$this->client      = $client;
		$this->fingerprint = $fingerprint ?? static fn (): string => '';
	}

	/**
	 * Fingerprint of an API key and API base (a hash: the key is never stored with the outlet id).
	 *
	 * @param string|null $api_key  API key, null when none.
	 * @param string      $api_base API base URL.
	 * @return string
	 */
	public static function fingerprint( ?string $api_key, string $api_base ): string {
		return hash( 'sha256', (string) $api_key . "\n" . rtrim( trim( $api_base ), '/' ) );
	}

	/**
	 * The connected outlet's id.
	 *
	 * @return string
	 * @throws ApiException When the outlet cannot be determined (not connected, network, rejected key).
	 */
	public function outlet_id(): string {
		$known = $this->known_outlet_id();
		if ( null !== $known ) {
			return $known;
		}

		$response = $this->client->get( 'outlet' );
		$outlet   = isset( $response->data['outlet'] ) && is_array( $response->data['outlet'] ) ? $response->data['outlet'] : array();
		$id       = isset( $outlet['id'] ) && is_scalar( $outlet['id'] ) ? trim( (string) $outlet['id'] ) : '';
		if ( '' === $id ) {
			$exception = ApiException::invalid_response( $response->status );
			throw $exception;
		}

		$this->remember( $id );

		return $id;
	}

	/**
	 * The outlet id known without a request (the filter, then a cache made for the current credentials), or null.
	 *
	 * @return string|null
	 */
	public function known_outlet_id(): ?string {
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

		$cached = $this->cached();
		if ( null === $cached || ! hash_equals( $cached['fingerprint'], ( $this->fingerprint )() ) ) {
			return null;
		}

		return $cached['id'];
	}

	/**
	 * Whether a cached outlet id exists that was resolved for other credentials (or by a version that did not record
	 * them). The connection record may be just as old then, so it should not be trusted as the current outlet either.
	 *
	 * @return bool
	 */
	public function is_stale(): bool {
		$raw = get_transient( self::TRANSIENT );
		if ( false === $raw || '' === $raw ) {
			return false;
		}

		$cached = $this->cached();

		return null === $cached || ! hash_equals( $cached['fingerprint'], ( $this->fingerprint )() );
	}

	/**
	 * Cache an outlet id for the current credentials.
	 *
	 * @param string $id Outlet id.
	 * @return void
	 */
	public function remember( string $id ): void {
		set_transient(
			self::TRANSIENT,
			array(
				'id'          => $id,
				'fingerprint' => ( $this->fingerprint )(),
			),
			DAY_IN_SECONDS
		);
	}

	/**
	 * Pixalink reported the outlet the key belongs to (heartbeat): drop a cached id for any other outlet.
	 *
	 * @param string $reported_id Outlet id Pixalink reported.
	 * @return bool Whether the cache was dropped.
	 */
	public function forget_unless( string $reported_id ): bool {
		$reported_id = trim( $reported_id );
		$cached      = $this->cached();
		if ( '' === $reported_id || null === $cached || $cached['id'] === $reported_id ) {
			return false;
		}

		$this->forget();

		return true;
	}

	/**
	 * Forget the cached outlet id.
	 *
	 * @return void
	 */
	public function forget(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * The cached entry, or null when there is none or it predates fingerprints.
	 *
	 * @return array{id:string,fingerprint:string}|null
	 */
	private function cached(): ?array {
		$cached = get_transient( self::TRANSIENT );
		if ( ! is_array( $cached ) ) {
			return null;
		}

		$id          = isset( $cached['id'] ) && is_scalar( $cached['id'] ) ? trim( (string) $cached['id'] ) : '';
		$fingerprint = isset( $cached['fingerprint'] ) && is_string( $cached['fingerprint'] ) ? $cached['fingerprint'] : '';

		return '' === $id ? null : array(
			'id'          => $id,
			'fingerprint' => $fingerprint,
		);
	}
}
