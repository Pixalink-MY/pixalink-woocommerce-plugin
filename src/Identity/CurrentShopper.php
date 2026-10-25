<?php
/**
 * The shopper on the current request, asked again every time.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * A {@see ShopperContextInterface} for long-lived services.
 *
 * {@see ShopperContext} is a snapshot of one moment: the user and WooCommerce session it was resolved for. Redemption
 * services are built once per request and asked about the shopper many times, sometimes after a login or after the
 * Store API started a session, so they hold this instead: each question resolves a fresh {@see ShopperContext}.
 */
final class CurrentShopper implements ShopperContextInterface {

	/**
	 * Constructor.
	 *
	 * @param GuestLinkStore $guests Guest links.
	 */
	public function __construct( private readonly GuestLinkStore $guests ) {}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function state(): string {
		return ShopperContext::current( $this->guests )->state();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string|null
	 */
	public function pixalink_customer_id(): ?string {
		return ShopperContext::current( $this->guests )->pixalink_customer_id();
	}
}
