<?php
/**
 * Unit: quote data → WooCommerce coupon props.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Redemption;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Redemption\CouponMapper;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * Local catalogue: SKU PXL-A = product 11, PXL-B = 12, variation PXL-B-RED = 13; category "coffee" = term 7.
 *
 * @covers \Pixalink\Loyalty\Redemption\CouponMapper
 */
final class CouponMapperTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();

		Functions\when( 'wc_strtolower' )->alias( 'strtolower' );
		Functions\when( 'wc_get_product_id_by_sku' )->alias(
			static fn ( string $sku ): int => array(
				'PXL-A'     => 11,
				'PXL-B'     => 12,
				'PXL-B-RED' => 13,
			)[ $sku ] ?? 0
		);
		Functions\when( 'get_term_by' )->alias(
			static function ( string $field, string $value ) {
				if ( 'slug' !== $field || 'coffee' !== $value ) {
					return false;
				}
				$term          = new \WP_Term();
				$term->term_id = 7;

				return $term;
			}
		);
	}

	/**
	 * A woo_coupon spec with every field at its neutral value.
	 *
	 * @param array<string,mixed> $overrides Overrides.
	 * @return array<string,mixed>
	 */
	private static function spec( array $overrides = array() ): array {
		return array_merge(
			array(
				'discount_type'          => 'fixed_cart',
				'amount'                 => '10.00',
				'product_skus'           => array(),
				'excluded_product_skus'  => array(),
				'category_slugs'         => array(),
				'minimum_amount'         => null,
				'maximum_amount'         => null,
				'limit_usage_to_x_items' => null,
				'exclude_sale_items'     => false,
				'individual_use'         => false,
			),
			$overrides
		);
	}

	/**
	 * Every `woo_coupon` shape the server sends, with the props WooCommerce must receive.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>}>
	 */
	public static function shapes(): array {
		return array(
			'fixed_cart, whole cart, minimum spend' => array(
				self::spec( array( 'minimum_amount' => '50.00' ) ),
				array(
					'discount_type'  => 'fixed_cart',
					'amount'         => '10.00',
					'product_ids'    => array(),
					'minimum_amount' => '50.00',
					'maximum_amount' => '',
				),
			),
			'percent of the whole cart'             => array(
				self::spec(
					array(
						'discount_type' => 'percent',
						'amount'        => '10',
					)
				),
				array(
					'discount_type' => 'percent',
					'amount'        => '10',
					'product_ids'   => array(),
				),
			),
			'fixed_product on SKUs'                 => array(
				self::spec(
					array(
						'discount_type' => 'fixed_product',
						'amount'        => '5.00',
						'product_skus'  => array( 'PXL-A', 'PXL-B-RED' ),
					)
				),
				array(
					'discount_type' => 'fixed_product',
					'product_ids'   => array( 11, 13 ),
				),
			),
			'free item: percent 100, one unit'      => array(
				self::spec(
					array(
						'discount_type'          => 'percent',
						'amount'                 => '100',
						'product_skus'           => array( 'PXL-B' ),
						'limit_usage_to_x_items' => 1,
					)
				),
				array(
					'discount_type'          => 'percent',
					'amount'                 => '100',
					'product_ids'            => array( 12 ),
					'limit_usage_to_x_items' => 1,
				),
			),
			'excluded SKUs (membership lines)'      => array(
				self::spec( array( 'excluded_product_skus' => array( 'PXL-B', 'GONE' ) ) ),
				array(
					'product_ids'          => array(),
					'excluded_product_ids' => array( 12 ),
				),
			),
			'category scoped'                       => array(
				self::spec( array( 'category_slugs' => array( 'coffee', 'tea' ) ) ),
				array( 'product_categories' => array( 7 ) ),
			),
			'maximum spend, sale items, individual' => array(
				self::spec(
					array(
						'maximum_amount'     => '500.00',
						'exclude_sale_items' => true,
						'individual_use'     => true,
					)
				),
				array(
					'maximum_amount'     => '500.00',
					'exclude_sale_items' => true,
					'individual_use'     => true,
				),
			),
			'unknown discount type falls back'      => array(
				self::spec( array( 'discount_type' => 'store_credit' ) ),
				array( 'discount_type' => 'fixed_cart' ),
			),
			'malformed amount becomes zero'         => array(
				self::spec( array( 'amount' => 10 ) ),
				array( 'amount' => '0' ),
			),
		);
	}

	/**
	 * @dataProvider shapes
	 *
	 * @param array<string,mixed> $spec     woo_coupon spec.
	 * @param array<string,mixed> $expected Subset of props.
	 */
	public function test_voucher_props_from_every_woo_coupon_shape( array $spec, array $expected ): void {
		$props = CouponMapper::voucher( $spec );

		foreach ( $expected as $key => $value ) {
			$this->assertSame( $value, $props[ $key ], $key );
		}
		$this->assertSame( array(), $props['excluded_product_categories'] );
		$this->assertArrayHasKey( 'description', $props );
	}

	public function test_unresolved_skus_are_detected(): void {
		$this->assertTrue( CouponMapper::has_unresolved_skus( self::spec( array( 'product_skus' => array( 'NO-SUCH-SKU' ) ) ) ) );
		$this->assertFalse( CouponMapper::has_unresolved_skus( self::spec( array( 'product_skus' => array( 'NO-SUCH-SKU', 'PXL-A' ) ) ) ), 'one local product is enough' );
		$this->assertFalse( CouponMapper::has_unresolved_skus( self::spec() ), 'no SKUs = the whole cart' );
	}

	public function test_points_and_credits_are_fixed_cart_coupons(): void {
		$points = CouponMapper::points( 1000, '0.01' );
		$this->assertSame( 'fixed_cart', $points['discount_type'] );
		$this->assertSame( '10.00', $points['amount'] );
		$this->assertFalse( $points['individual_use'] );

		$this->assertSame( '1.66', CouponMapper::points( 333, '0.005' )['amount'], 'never worth more than the points' );

		$credits = CouponMapper::credits( 1250 );
		$this->assertSame( 'fixed_cart', $credits['discount_type'] );
		$this->assertSame( '12.50', $credits['amount'] );
	}

	public function test_codes_are_claimed_case_insensitively(): void {
		foreach ( array( 'pxl-points', 'PXL-POINTS', 'PXL-WELCOME-88', 'pxl-credits', ' Pxl-x ' ) as $code ) {
			$this->assertTrue( CouponMapper::is_ours( $code ), $code );
		}
		foreach ( array( 'summer10', 'pxlpoints', 'xpxl-1', '' ) as $code ) {
			$this->assertFalse( CouponMapper::is_ours( $code ), $code );
		}

		$this->assertSame( CouponMapper::KIND_POINTS, CouponMapper::kind_of( 'PXL-Points' ) );
		$this->assertSame( CouponMapper::KIND_CREDIT, CouponMapper::kind_of( 'pxl-credits' ) );
		$this->assertSame( CouponMapper::KIND_VOUCHER, CouponMapper::kind_of( 'PXL-WELCOME-88' ) );
		$this->assertNull( CouponMapper::kind_of( 'summer10' ) );
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public static function voucher_codes(): array {
		return array(
			'already prefixed'          => array(
				array(
					'id'   => '88',
					'code' => 'PXL-WELCOME-88',
				),
				'pxl-welcome-88',
			),
			'prefix added'              => array(
				array(
					'id'   => '89',
					'code' => 'Birthday-2026',
				),
				'pxl-birthday-2026',
			),
			'whitespace trimmed'        => array(
				array(
					'id'   => '90',
					'code' => '  pxl-abc ',
				),
				'pxl-abc',
			),
			'would shadow points'       => array(
				array(
					'id'   => '91',
					'code' => 'POINTS',
				),
				'pxl-v-91',
			),
			'would shadow credits'      => array(
				array(
					'id'   => '92',
					'code' => 'pxl-credits',
				),
				'pxl-v-92',
			),
			'empty code falls back too' => array(
				array(
					'id'   => '93',
					'code' => '',
				),
				'pxl-v-93',
			),
		);
	}

	/**
	 * @dataProvider voucher_codes
	 *
	 * @param array<string,mixed> $voucher  Quote voucher.
	 * @param string              $expected WooCommerce coupon code.
	 */
	public function test_voucher_coupon_code( array $voucher, string $expected ): void {
		$this->assertSame( $expected, CouponMapper::voucher_code( $voucher ) );
		$this->assertSame( CouponMapper::KIND_VOUCHER, CouponMapper::kind_of( $expected ) );
	}

	public function test_placeholder_is_a_worthless_fixed_cart_coupon(): void {
		$placeholder = CouponMapper::placeholder();

		$this->assertSame( 'fixed_cart', $placeholder['discount_type'] );
		$this->assertSame( '0', $placeholder['amount'] );
	}

	public function test_snapshot_keeps_what_recalculation_needs(): void {
		$coupon = \Mockery::mock( 'WC_Coupon' );
		$coupon->allows(
			array(
				'get_discount_type'               => 'percent',
				'get_amount'                      => '100',
				'get_product_ids'                 => array( 12 ),
				'get_excluded_product_ids'        => array(),
				'get_product_categories'          => array( 7 ),
				'get_excluded_product_categories' => array(),
				'get_limit_usage_to_x_items'      => 1,
				'get_exclude_sale_items'          => false,
				'get_minimum_amount'              => '',
				'get_maximum_amount'              => '',
				'get_individual_use'              => false,
			)
		);
		Functions\when( 'wc_format_decimal' )->alias( static fn ( $value ): string => (string) $value );

		$this->assertSame(
			array(
				'discount_type'               => 'percent',
				'amount'                      => '100',
				'product_ids'                 => array( 12 ),
				'excluded_product_ids'        => array(),
				'product_categories'          => array( 7 ),
				'excluded_product_categories' => array(),
				'limit_usage_to_x_items'      => 1,
				'exclude_sale_items'          => false,
				'minimum_amount'              => '',
				'maximum_amount'              => '',
				'individual_use'              => false,
			),
			CouponMapper::snapshot( $coupon )
		);
	}
}
