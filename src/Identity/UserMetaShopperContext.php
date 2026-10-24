<?php
/**
 * Minimal shopper context read from user meta.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * Logged-in shoppers whose user meta carries a Pixalink customer id and a phone verification time are verified;
 * other logged-in shoppers are unlinked; everyone else is a guest.
 *
 * This is the stand-in until the identity unit (OTP, My Account) ships its own {@see ShopperContextInterface}
 * through the `pixalink_loyalty_shopper_context` filter. It reads the same durable user meta
 * (`pixalink_customer_id`, `pixalink_phone_verified_at`) that unit writes, so nothing here changes when it lands.
 */
final class UserMetaShopperContext implements ShopperContextInterface {

	public const CUSTOMER_ID_META = 'pixalink_customer_id';
	public const VERIFIED_AT_META = 'pixalink_phone_verified_at';

	/**
	 * Current user id source.
	 *
	 * @var \Closure():int
	 */
	private \Closure $user_id;

	/**
	 * Constructor.
	 *
	 * @param \Closure|null $user_id Current user id source (tests); defaults to get_current_user_id().
	 */
	public function __construct( ?\Closure $user_id = null ) {
		$this->user_id = $user_id ?? static fn (): int => (int) get_current_user_id();
	}

	/**
	 * One of GUEST, UNLINKED or VERIFIED.
	 *
	 * @return string
	 */
	public function state(): string {
		$user_id = ( $this->user_id )();
		if ( $user_id < 1 ) {
			return self::GUEST;
		}

		return null !== $this->linked_customer_id( $user_id ) && '' !== $this->meta( $user_id, self::VERIFIED_AT_META )
			? self::VERIFIED
			: self::UNLINKED;
	}

	/**
	 * Whether the shopper may see balances and spend.
	 *
	 * @return bool
	 */
	public function is_verified(): bool {
		return self::VERIFIED === $this->state();
	}

	/**
	 * The Pixalink customer id of a verified shopper.
	 *
	 * @return string|null
	 */
	public function pixalink_customer_id(): ?string {
		return $this->is_verified() ? $this->linked_customer_id( ( $this->user_id )() ) : null;
	}

	/**
	 * The stored customer id when it fits the contract's id format.
	 *
	 * @param int $user_id User id.
	 * @return string|null
	 */
	private function linked_customer_id( int $user_id ): ?string {
		$customer_id = $this->meta( $user_id, self::CUSTOMER_ID_META );

		return '' !== $customer_id && strlen( $customer_id ) <= 64 ? $customer_id : null;
	}

	/**
	 * A user meta value as a trimmed string.
	 *
	 * @param int    $user_id User id.
	 * @param string $key     Meta key.
	 * @return string
	 */
	private function meta( int $user_id, string $key ): string {
		$value = get_user_meta( $user_id, $key, true );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
