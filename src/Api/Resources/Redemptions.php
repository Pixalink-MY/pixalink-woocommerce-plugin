<?php
/**
 * Checkout endpoints of the Pixalink Commerce API.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Api\Resources;

use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\Client;
use Pixalink\Loyalty\Support\Uuid;

defined( 'ABSPATH' ) || exit;

/**
 * `POST quote` (price vouchers, points and credits for a cart; writes nothing), `PUT orders/{order_key}/redemptions`
 * (the declarative set of holds for an order; empty releases all) and `DELETE orders/{order_key}/redemptions`.
 *
 * The static builders are the single source of the request bodies and are validated against
 * tests/contract/schemas by the contract suite.
 */
final class Redemptions {

	public const QUOTE_PATH = 'quote';

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
	 * Path of an order's redemptions.
	 *
	 * @param string $order_key WooCommerce order key.
	 * @return string
	 */
	public static function path( string $order_key ): string {
		return 'orders/' . rawurlencode( $order_key ) . '/redemptions';
	}

	/**
	 * Body for POST quote.
	 *
	 * @param string              $customer_id Pixalink customer id of the verified shopper.
	 * @param array<string,mixed> $cart        `cart` object ({@see \Pixalink\Loyalty\Redemption\CartPayload::from_cart()}).
	 * @return array{customer_id:string,cart:array<string,mixed>}
	 */
	public static function quote_payload( string $customer_id, array $cart ): array {
		return array(
			'customer_id' => $customer_id,
			'cart'        => $cart,
		);
	}

	/**
	 * Body for PUT orders/{order_key}/redemptions.
	 *
	 * @param string                         $customer_id Pixalink customer id.
	 * @param array<string,mixed>            $cart        `cart` object.
	 * @param list<array<string,mixed>>      $redemptions Entries built from the order's coupon lines.
	 * @return array{customer_id:string,cart:array<string,mixed>,redemptions:list<array<string,mixed>>}
	 */
	public static function hold_payload( string $customer_id, array $cart, array $redemptions ): array {
		return array(
			'customer_id' => $customer_id,
			'cart'        => $cart,
			'redemptions' => array_values( $redemptions ),
		);
	}

	/**
	 * Idempotency key of a hold: the same order and body replay the same hold, a changed body is a new request.
	 *
	 * @param string              $order_key Order key.
	 * @param array<string,mixed> $payload   PUT body.
	 * @return string
	 */
	public static function hold_idempotency_key( string $order_key, array $payload ): string {
		return Uuid::v5( Uuid::EVENT_NAMESPACE, 'redemptions|' . $order_key . '|' . md5( (string) wp_json_encode( $payload ) ) );
	}

	/**
	 * Price a cart.
	 *
	 * @param array<string,mixed> $payload Body from {@see Redemptions::quote_payload()}.
	 * @return array<mixed> Quote response.
	 * @throws ApiException On failure.
	 */
	public function quote( array $payload ): array {
		return $this->client->post( self::QUOTE_PATH, $payload, array( 'endpoint_class' => Client::ENDPOINT_CHECKOUT ) )->data;
	}

	/**
	 * Replace an order's holds.
	 *
	 * @param string              $order_key Order key.
	 * @param array<string,mixed> $payload   Body from {@see Redemptions::hold_payload()}.
	 * @return array<mixed> `{redemptions[{redemption_id, kind, status, expires_at, coupon_code}]}`.
	 * @throws ApiException On failure (409 points_insufficient / credits_insufficient / voucher_unavailable,
	 *                      422 discount_mismatch).
	 */
	public function hold( string $order_key, array $payload ): array {
		return $this->client->put(
			self::path( $order_key ),
			$payload,
			array(
				'endpoint_class'  => Client::ENDPOINT_CHECKOUT,
				'idempotency_key' => self::hold_idempotency_key( $order_key, $payload ),
			)
		)->data;
	}

	/**
	 * Release every hold of an order.
	 *
	 * @param string $order_key Order key.
	 * @return array<mixed> `{redemptions: [], released}`.
	 * @throws ApiException On failure.
	 */
	public function release( string $order_key ): array {
		return $this->client->delete( self::path( $order_key ), array( 'endpoint_class' => Client::ENDPOINT_CHECKOUT ) )->data;
	}
}
