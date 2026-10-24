<?php
/**
 * SPIKE (P02, throwaway): `extensions.pixalink-loyalty` on the Store API cart response.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Spike;

defined( 'ABSPATH' ) || exit;

/**
 * Data the Blocks UI renders (and that the coupons checkout filter uses for the points label).
 */
final class StoreApiExtension {

	/**
	 * `data_callback` for ExtendSchema::register_endpoint_data( CartSchema ).
	 *
	 * @return array{linked:bool,points:array{balance:int,value_per_point:string,min:int,max:int,step:int,applied:int},points_code:string,points_label:string,vouchers:array<int,array{code:string,title:string,applied:bool}>}
	 */
	public static function data(): array {
		$state  = RedemptionState::get();
		$config = RedemptionState::points_config();
		$linked = RedemptionState::is_verified();

		$vouchers = array();
		if ( $linked && is_array( $state['quote']['vouchers'] ?? null ) ) {
			foreach ( $state['quote']['vouchers'] as $voucher ) {
				if ( ! is_array( $voucher ) || ! is_string( $voucher['code'] ?? null ) ) {
					continue;
				}
				$code       = wc_format_coupon_code( $voucher['code'] );
				$vouchers[] = array(
					'code'    => $code,
					'title'   => is_string( $voucher['title'] ?? null ) ? $voucher['title'] : $code,
					'applied' => null !== WC()->cart && WC()->cart->has_discount( $code ),
				);
			}
		}

		return array(
			'linked'       => $linked,
			'points'       => array(
				'balance'         => $config['balance'],
				'value_per_point' => $config['value_per_point'],
				'min'             => $config['min'],
				'max'             => min( $config['available'], $config['max_for_cart'] ),
				'step'            => $config['step'],
				'applied'         => $state['points'],
			),
			'points_code'  => CouponMapper::POINTS_CODE,
			'points_label' => VirtualCoupons::points_label( $state['points'] ),
			'vouchers'     => $vouchers,
		);
	}

	/**
	 * `schema_callback`.
	 *
	 * @return array<string,mixed>
	 */
	public static function schema(): array {
		$readonly = array(
			'context'  => array( 'view', 'edit' ),
			'readonly' => true,
		);

		return array(
			'linked'       => array( 'type' => 'boolean' ) + $readonly,
			'points'       => array( 'type' => 'object' ) + $readonly,
			'points_code'  => array( 'type' => 'string' ) + $readonly,
			'points_label' => array( 'type' => 'string' ) + $readonly,
			'vouchers'     => array( 'type' => 'array' ) + $readonly,
		);
	}
}
