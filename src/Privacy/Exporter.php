<?php
/**
 * Personal data export of the loyalty link.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Privacy;

use Pixalink\Loyalty\Identity\UserMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Adds "Pixalink loyalty" to Tools › Export Personal Data: the account's Pixalink customer id, phone number and
 * country, when and how it was verified, and the recorded marketing consent.
 */
final class Exporter {

	public const ID = 'pixalink-loyalty';

	/**
	 * Register the exporter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add' ) );
	}

	/**
	 * `wp_privacy_personal_data_exporters` callback.
	 *
	 * @param mixed $exporters Exporters.
	 * @return mixed
	 */
	public function add( $exporters ) {
		if ( is_array( $exporters ) ) {
			$exporters[ self::ID ] = array(
				'exporter_friendly_name' => __( 'Pixalink loyalty', 'pixalink-loyalty-for-woocommerce' ),
				'callback'               => array( $this, 'export' ),
			);
		}

		return $exporters;
	}

	/**
	 * Export callback.
	 *
	 * @param string $email_address E-mail address of the request.
	 * @param int    $page          Page (one page is enough).
	 * @return array{data:list<array{group_id:string,group_label:string,group_description:string,item_id:string,data:list<array{name:string,value:string}>}>,done:bool}
	 */
	public function export( $email_address, $page = 1 ): array {
		unset( $page );

		$user = get_user_by( 'email', (string) $email_address );
		if ( ! $user instanceof \WP_User ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$labels = array(
			UserMeta::CUSTOMER_ID       => __( 'Pixalink customer id', 'pixalink-loyalty-for-woocommerce' ),
			UserMeta::PHONE_E164        => __( 'Loyalty phone number', 'pixalink-loyalty-for-woocommerce' ),
			UserMeta::PHONE_COUNTRY     => __( 'Phone country', 'pixalink-loyalty-for-woocommerce' ),
			UserMeta::PHONE_VERIFIED_AT => __( 'Phone verified at', 'pixalink-loyalty-for-woocommerce' ),
			UserMeta::LINKED_AT         => __( 'Linked to Pixalink at', 'pixalink-loyalty-for-woocommerce' ),
			UserMeta::LINKED_VIA        => __( 'Linked by', 'pixalink-loyalty-for-woocommerce' ),
			UserMeta::CONSENT           => __( 'Marketing consent', 'pixalink-loyalty-for-woocommerce' ),
			UserMeta::CONSENT_AT        => __( 'Marketing consent recorded at', 'pixalink-loyalty-for-woocommerce' ),
			UserMeta::CONSENT_SOURCE    => __( 'Marketing consent given on', 'pixalink-loyalty-for-woocommerce' ),
			UserMeta::CONSENT_TEXT_HASH => __( 'Marketing consent wording (SHA-256)', 'pixalink-loyalty-for-woocommerce' ),
		);

		$data = array();
		foreach ( $labels as $key => $label ) {
			$value = UserMeta::get( (int) $user->ID, $key );
			if ( '' !== $value ) {
				$data[] = array(
					'name'  => $label,
					'value' => $value,
				);
			}
		}

		if ( array() === $data ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		return array(
			'data' => array(
				array(
					'group_id'          => self::ID,
					'group_label'       => __( 'Pixalink loyalty', 'pixalink-loyalty-for-woocommerce' ),
					'group_description' => __( 'How this account is linked to the store\'s Pixalink loyalty programme. Points, vouchers and history are held by Pixalink.', 'pixalink-loyalty-for-woocommerce' ),
					'item_id'           => 'pixalink-loyalty-' . (int) $user->ID,
					'data'              => $data,
				),
			),
			'done' => true,
		);
	}
}
