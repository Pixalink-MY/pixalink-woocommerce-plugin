<?php
/**
 * The current shopper's loyalty identity.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * Resolved once per call from user meta (logged in) or the guest link store (guests).
 *
 * A logged-in shopper's state comes from their account only: a link a guest verified earlier in the same browser
 * session is moved to the account at login ({@see Module::on_login()}), never read through.
 */
final class ShopperContext implements ShopperContextInterface {

	/**
	 * Constructor.
	 *
	 * @param int       $user_id    WordPress user id (0 for guests).
	 * @param string    $session_id WooCommerce session customer id ('' when there is no session).
	 * @param Link|null $link       Verified link, if any.
	 */
	private function __construct(
		private readonly int $user_id,
		private readonly string $session_id,
		private readonly ?Link $link
	) {}

	/**
	 * Resolve a shopper.
	 *
	 * @param int            $user_id    WordPress user id (0 for guests).
	 * @param string         $session_id WooCommerce session customer id.
	 * @param GuestLinkStore $guests     Guest links.
	 * @return ShopperContext
	 */
	public static function resolve( int $user_id, string $session_id, GuestLinkStore $guests ): ShopperContext {
		$link = $user_id > 0 ? UserMeta::link( $user_id ) : $guests->get( $session_id );

		return new self( max( 0, $user_id ), $session_id, $link );
	}

	/**
	 * The shopper on the current request.
	 *
	 * @param GuestLinkStore $guests Guest links.
	 * @return ShopperContext
	 */
	public static function current( GuestLinkStore $guests ): ShopperContext {
		return self::resolve( get_current_user_id(), self::current_session_id(), $guests );
	}

	/**
	 * WooCommerce session customer id of the current request ('' without a session).
	 *
	 * @return string
	 */
	public static function current_session_id(): string {
		$session = function_exists( 'WC' ) ? WC()->session : null;
		if ( ! $session instanceof \WC_Session ) {
			return '';
		}

		$id = $session->get_customer_id();

		return is_scalar( $id ) ? (string) $id : '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function state(): string {
		if ( null !== $this->link ) {
			return self::STATE_VERIFIED;
		}

		return $this->user_id > 0 ? self::STATE_UNLINKED : self::STATE_GUEST;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string|null
	 */
	public function pixalink_customer_id(): ?string {
		return $this->link?->customer_id;
	}

	/**
	 * The verified link, if any.
	 *
	 * @return Link|null
	 */
	public function link(): ?Link {
		return $this->link;
	}

	/**
	 * Whether the shopper is verified.
	 *
	 * @return bool
	 */
	public function is_verified(): bool {
		return null !== $this->link;
	}

	/**
	 * Whether the shopper is logged in.
	 *
	 * @return bool
	 */
	public function is_logged_in(): bool {
		return $this->user_id > 0;
	}

	/**
	 * WordPress user id (0 for guests).
	 *
	 * @return int
	 */
	public function user_id(): int {
		return $this->user_id;
	}

	/**
	 * WooCommerce session customer id.
	 *
	 * @return string
	 */
	public function session_id(): string {
		return $this->session_id;
	}

	/**
	 * Stable key for per-shopper state (pending OTP, rate limits): `user:{id}` or `session:{id}`.
	 *
	 * @return string
	 */
	public function subject(): string {
		return $this->user_id > 0 ? 'user:' . $this->user_id : 'session:' . $this->session_id;
	}
}
