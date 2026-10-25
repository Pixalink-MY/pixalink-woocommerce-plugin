<?php
/**
 * Personal data erasure of the loyalty link.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Privacy;

use Pixalink\Loyalty\Account\SummaryService;
use Pixalink\Loyalty\Identity\PendingLinks;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Orders\OrderMeta;
use Pixalink\Loyalty\Redemption\RedemptionState;

defined( 'ABSPATH' ) || exit;

/**
 * Adds "Pixalink loyalty" to Tools › Erase Personal Data: removes the account's loyalty user meta, its stored
 * checkout redemption state, its cached summary and any pending code. When WooCommerce anonymises an order it also drops the order's loyalty phone number.
 *
 * Data held by Pixalink itself is erased in Pixalink (the store owner's Pixalink account), not here.
 */
final class Eraser {

	public const ID = 'pixalink-loyalty';

	/**
	 * Register the eraser.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add' ) );
		add_action( 'woocommerce_privacy_remove_order_personal_data', array( $this, 'anonymise_order' ), 10, 1 );
	}

	/**
	 * `wp_privacy_personal_data_erasers` callback.
	 *
	 * @param mixed $erasers Erasers.
	 * @return mixed
	 */
	public function add( $erasers ) {
		if ( is_array( $erasers ) ) {
			$erasers[ self::ID ] = array(
				'eraser_friendly_name' => __( 'Pixalink loyalty', 'pixalink-loyalty-for-woocommerce' ),
				'callback'             => array( $this, 'erase' ),
			);
		}

		return $erasers;
	}

	/**
	 * Erase callback.
	 *
	 * @param string $email_address E-mail address of the request.
	 * @param int    $page          Page (one page is enough).
	 * @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool}
	 */
	public function erase( $email_address, $page = 1 ): array {
		unset( $page );

		$result = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);

		$user = get_user_by( 'email', (string) $email_address );
		if ( ! $user instanceof \WP_User ) {
			return $result;
		}

		$user_id = (int) $user->ID;
		foreach ( UserMeta::KEYS as $key ) {
			if ( '' !== UserMeta::get( $user_id, $key ) ) {
				delete_user_meta( $user_id, $key );
				$result['items_removed'] = true;
			}
		}

		// The checkout redemption state mirrored to the account: the member's quote, balances and chosen rewards.
		if ( metadata_exists( 'user', $user_id, RedemptionState::USER_META ) ) {
			delete_user_meta( $user_id, RedemptionState::USER_META );
			$result['items_removed'] = true;
		}

		delete_transient( SummaryService::key( $user_id ) );
		delete_transient( PendingLinks::key( 'user:' . $user_id ) );

		if ( $result['items_removed'] ) {
			$result['messages'][] = __( 'The Pixalink loyalty link was removed from this account. Points and vouchers held by Pixalink are not affected; erase them in Pixalink.', 'pixalink-loyalty-for-woocommerce' );
		}

		return $result;
	}

	/**
	 * `woocommerce_privacy_remove_order_personal_data` callback.
	 *
	 * @param mixed $order Order being anonymised.
	 * @return void
	 */
	public function anonymise_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// WooCommerce has already saved the anonymised order when this fires, so persist the meta change ourselves.
		OrderMeta::update(
			$order,
			array(
				OrderMeta::PHONE_E164    => null,
				OrderMeta::PHONE_COUNTRY => null,
			)
		);
	}
}
