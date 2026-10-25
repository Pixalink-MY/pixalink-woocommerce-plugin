<?php
/**
 * Who the current shopper is, as far as Pixalink is concerned.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * The loyalty identity of the shopper on the current request.
 *
 * Three states:
 *
 * - {@see ShopperContextInterface::STATE_GUEST}: not logged in and no verified link in this WooCommerce session.
 * - {@see ShopperContextInterface::STATE_UNLINKED}: logged in, but the account has no verified Pixalink link yet.
 * - {@see ShopperContextInterface::STATE_VERIFIED}: the shopper proved the phone number (OTP or a Pixalink portal
 *   sign-in ticket). For logged-in shoppers the link is durable user meta; for guests it lives in a transient keyed by
 *   the WooCommerce session customer id (never inside the WC session blob, ADR 0001 decision 2).
 *
 * Balances, vouchers and redemption are only ever offered in the verified state.
 */
interface ShopperContextInterface {

	public const STATE_GUEST    = 'guest';
	public const STATE_UNLINKED = 'unlinked';
	public const STATE_VERIFIED = 'verified';

	/**
	 * The shopper's state: one of the STATE_* constants.
	 *
	 * @return string
	 */
	public function state(): string;

	/**
	 * The Pixalink customer id of a verified shopper.
	 *
	 * @return string|null Null unless {@see ShopperContextInterface::state()} is STATE_VERIFIED.
	 */
	public function pixalink_customer_id(): ?string;
}
