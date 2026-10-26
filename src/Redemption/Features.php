<?php
/**
 * Which redemptions are on.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Redemption;

use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Connect\Connection;
use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Pixalink's remote kill switches from the heartbeat decide first; the merchant's toggles in
 * WooCommerce › Settings › Pixalink Loyalty › Checkout can only switch a redemption off, never on.
 *
 * Nothing is offered before the first heartbeat confirmed the feature ({@see Connection::feature()}), while the
 * store is disconnected, or while Pixalink reports the account paused (subscription inactive, integration not
 * enabled, or the store paused by Pixalink staff: {@see Connection::PAUSED_STATUSES}).
 */
final class Features {

	/**
	 * Heartbeat `features.*` key per redemption kind.
	 */
	public const SERVER_FEATURES = array(
		CouponMapper::KIND_VOUCHER => 'vouchers',
		CouponMapper::KIND_POINTS  => 'points_redemption',
		CouponMapper::KIND_CREDIT  => 'credits',
	);

	/**
	 * Merchant option per redemption kind.
	 */
	public const MERCHANT_OPTIONS = array(
		CouponMapper::KIND_VOUCHER => Options::VOUCHERS_ENABLED,
		CouponMapper::KIND_POINTS  => Options::POINTS_REDEMPTION_ENABLED,
		CouponMapper::KIND_CREDIT  => Options::CREDITS_ENABLED,
	);

	/**
	 * Connection state.
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * Option storage.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Credentials.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Constructor.
	 *
	 * @param Connection  $connection  Connection state.
	 * @param Options     $options     Option storage.
	 * @param Credentials $credentials Credentials.
	 */
	public function __construct( Connection $connection, Options $options, Credentials $credentials ) {
		$this->connection  = $connection;
		$this->options     = $options;
		$this->credentials = $credentials;
	}

	/**
	 * Whether a redemption kind is on: Pixalink allows it, then the merchant does.
	 *
	 * @param string $kind One of the CouponMapper::KIND_* constants.
	 * @return bool
	 */
	public function enabled( string $kind ): bool {
		$enabled = $this->server_allows( $kind ) && $this->merchant_allows( $kind );

		/**
		 * Filters whether a Pixalink redemption is offered (after the remote kill switch and the merchant setting).
		 *
		 * @since 0.2.0
		 *
		 * @param bool   $enabled Whether it is on.
		 * @param string $kind    "points", "voucher" or "credit".
		 */
		return (bool) apply_filters( 'pixalink_loyalty_redemption_enabled', $enabled, $kind );
	}

	/**
	 * Whether any redemption is on.
	 *
	 * @return bool
	 */
	public function any_enabled(): bool {
		foreach ( array_keys( self::SERVER_FEATURES ) as $kind ) {
			if ( $this->enabled( $kind ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the checkout loyalty panel is shown (typed voucher codes keep working without it).
	 *
	 * @return bool
	 */
	public function panel_enabled(): bool {
		return $this->options->get_bool( Options::CHECKOUT_PANEL_ENABLED ) && $this->any_enabled();
	}

	/**
	 * Whether Pixalink's heartbeat switched a kind on for this store.
	 *
	 * @param string $kind Kind.
	 * @return bool
	 */
	public function server_allows( string $kind ): bool {
		if ( ! isset( self::SERVER_FEATURES[ $kind ] ) || ! $this->credentials->is_configured() ) {
			return false;
		}

		if ( $this->connection->is_account_paused() ) {
			return false;
		}

		return $this->connection->feature( self::SERVER_FEATURES[ $kind ] );
	}

	/**
	 * Whether the merchant left a kind on.
	 *
	 * @param string $kind Kind.
	 * @return bool
	 */
	public function merchant_allows( string $kind ): bool {
		return isset( self::MERCHANT_OPTIONS[ $kind ] ) && $this->options->get_bool( self::MERCHANT_OPTIONS[ $kind ] );
	}
}
