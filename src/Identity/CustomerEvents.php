<?php
/**
 * Records customer.upserted events.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Identity;

use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Events\EventQueue;
use Pixalink\Loyalty\Events\EventType;

defined( 'ABSPATH' ) || exit;

/**
 * Records `customer.upserted` in the outbox (only while the store is connected) when a shopper's phone number,
 * consent or link changes. Changes Pixalink itself reported (webhooks) are never echoed back.
 */
final class CustomerEvents {

	/**
	 * Outbox writer.
	 *
	 * @var EventQueue
	 */
	private EventQueue $queue;

	/**
	 * Outlet credentials.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Payload builder.
	 *
	 * @var CustomerPayloadBuilder
	 */
	private CustomerPayloadBuilder $payloads;

	/**
	 * Constructor.
	 *
	 * @param EventQueue             $queue       Outbox writer.
	 * @param Credentials            $credentials Outlet credentials.
	 * @param CustomerPayloadBuilder $payloads    Payload builder.
	 */
	public function __construct( EventQueue $queue, Credentials $credentials, CustomerPayloadBuilder $payloads ) {
		$this->queue       = $queue;
		$this->credentials = $credentials;
		$this->payloads    = $payloads;
	}

	/**
	 * A registered shopper's phone, consent or link changed.
	 *
	 * @param int $user_id User id.
	 * @return bool Whether an event was recorded.
	 */
	public function user_changed( int $user_id ): bool {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user instanceof \WP_User || ! $this->credentials->is_configured() ) {
			return false;
		}

		$data = $this->payloads->for_user( $user );
		if ( null === $data['customer']['phone_e164'] && null === $data['customer']['marketing_consent'] && null === $data['customer']['pixalink_customer_id'] ) {
			// Nothing Pixalink could act on.
			return false;
		}

		return null !== $this->queue->enqueue( EventType::CUSTOMER_UPSERTED, $user, $data );
	}

	/**
	 * A guest placed an order with new marketing consent.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool Whether an event was recorded.
	 */
	public function guest_order( \WC_Order $order ): bool {
		if ( $order->get_customer_id() > 0 || ! $this->credentials->is_configured() ) {
			return false;
		}

		$data = $this->payloads->for_order( $order );
		if ( null === $data['customer']['phone_e164'] || null === $data['customer']['marketing_consent'] ) {
			return false;
		}

		return null !== $this->queue->enqueue( EventType::CUSTOMER_UPSERTED, null, $data );
	}
}
