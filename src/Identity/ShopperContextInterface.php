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
 * The seam between redemption (W3) and identity (W2).
 *
 * Redemption only asks two questions: which of the three states the shopper is in, and which Pixalink customer a
 * verified shopper is. Only a **verified** shopper (phone ownership proven by OTP or an SSO ticket) may fetch a
 * quote or hold value; guests and unlinked shoppers are offered verification instead.
 *
 * Implementations answer for the current request (current user, current WooCommerce session) every time they are
 * asked, so they never cache across requests.
 */
interface ShopperContextInterface {

	/**
	 * Not logged in and not verified in this session.
	 */
	public const GUEST = 'guest';

	/**
	 * Known to WordPress (or to this session), but phone ownership is not proven.
	 */
	public const UNLINKED = 'unlinked';

	/**
	 * Phone ownership proven: balances and redemption are allowed.
	 */
	public const VERIFIED = 'verified';

	/**
	 * One of GUEST, UNLINKED or VERIFIED.
	 *
	 * @return string
	 */
	public function state(): string;

	/**
	 * Whether the shopper may see balances and spend points, vouchers or credits.
	 *
	 * @return bool
	 */
	public function is_verified(): bool;

	/**
	 * The Pixalink customer id of a verified shopper (null otherwise).
	 *
	 * @return string|null
	 */
	public function pixalink_customer_id(): ?string;
}
