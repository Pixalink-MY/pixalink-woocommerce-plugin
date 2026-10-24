<?php
/**
 * SPIKE (P02, throwaway): canned quote standing in for POST quote.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Spike;

defined( 'ABSPATH' ) || exit;

/**
 * Quote shaped like tests/contract/schemas/quote.response.json.
 *
 * The fake Pixalink server (tests/e2e/mu-plugins/fake-pixalink) only holds voucher 88 and at most 1,250 points, while
 * this quote offers 2,000 points: selecting more than 1,250 reproduces "balance changed between preview and submit".
 */
final class SpikeQuote {

	/**
	 * Pixalink customer id of the linked shopper (fake server MEMBER_ID).
	 */
	public const CUSTOMER_ID = '5001';

	/**
	 * The quote.
	 *
	 * @return array<string,mixed>
	 */
	public static function canned(): array {
		return array(
			'currency'     => 'USD',
			'vouchers'     => array(
				self::voucher( '88', 'PXL-WELCOME-88', 'Welcome 10 off', 'fixed_cart', '10.00', array(), null, '50.00' ),
				self::voucher( '89', 'PXL-TENPCT-89', '10% off', 'percent', '10', array(), null, null ),
				self::voucher( '90', 'PXL-SKU5-90', '5 off Spike A', 'fixed_product', '5.00', array( 'PXL-A' ), null, null ),
				self::voucher( '91', 'PXL-FREEB-91', 'Free Spike B', 'percent', '100', array( 'PXL-B' ), 1, null ),
				self::voucher( '92', 'PXL-GHOST-92', 'Unknown SKU', 'fixed_product', '5.00', array( 'NO-SUCH-SKU' ), null, null ),
			),
			'points'       => array(
				'balance'         => 2000,
				'available'       => 2000,
				'value_per_point' => '0.01',
				'min'             => 100,
				'max_for_cart'    => 2000,
				'step'            => 100,
			),
			'credits'      => null,
			'lines'        => array(),
			'earn_preview' => array( 'points' => 100 ),
			'ttl_seconds'  => 300,
		);
	}

	/**
	 * One quote voucher.
	 *
	 * @param string            $id             Voucher id.
	 * @param string            $code           Code.
	 * @param string            $title          Title.
	 * @param string            $type           WooCommerce discount type.
	 * @param string            $amount         Amount.
	 * @param array<int,string> $skus           Product SKUs (empty = whole cart).
	 * @param int|null          $limit          limit_usage_to_x_items.
	 * @param string|null       $minimum_amount Minimum spend.
	 * @return array<string,mixed>
	 */
	private static function voucher( string $id, string $code, string $title, string $type, string $amount, array $skus, ?int $limit, ?string $minimum_amount ): array {
		return array(
			'id'                => $id,
			'code'              => $code,
			'title'             => $title,
			'description'       => null,
			'image_url'         => null,
			'expires_at'        => null,
			'redeemable_online' => true,
			'discount_cents'    => 0,
			'eligible'          => true,
			'ineligible_reason' => null,
			'woo_coupon'        => array(
				'discount_type'          => $type,
				'amount'                 => $amount,
				'product_skus'           => $skus,
				'excluded_product_skus'  => array(),
				'category_slugs'         => array(),
				'minimum_amount'         => $minimum_amount,
				'maximum_amount'         => null,
				'limit_usage_to_x_items' => $limit,
				'exclude_sale_items'     => false,
				'individual_use'         => false,
			),
		);
	}
}
