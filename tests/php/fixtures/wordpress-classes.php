<?php
/**
 * Empty WordPress / WooCommerce class shells for Brain Monkey tests.
 *
 * Unit and contract tests run without WordPress. Mockery can mock classes that do not exist, but each such mock is a
 * root class, so a mocked `WC_Order_Item_Product` would not be a `WC_Order_Item`. Declaring the real hierarchy (and
 * nothing else) keeps the type declarations in src/ honest; every method is mocked per test.
 *
 * @package Pixalink\Loyalty\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile, Squiz.Commenting.ClassComment.Missing

if ( ! class_exists( 'WC_Data' ) ) {
	abstract class WC_Data {}
	abstract class WC_Abstract_Order extends WC_Data {}
	class WC_Order extends WC_Abstract_Order {}
	class WC_Order_Refund extends WC_Abstract_Order {}
	class WC_Order_Item extends WC_Data {}
	class WC_Order_Item_Product extends WC_Order_Item {}
	class WC_Order_Item_Coupon extends WC_Order_Item {}
	class WC_Product extends WC_Data {}
}

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		/**
		 * Slug.
		 *
		 * @var string
		 */
		public $slug = '';

		/**
		 * Name.
		 *
		 * @var string
		 */
		public $name = '';
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		/**
		 * User id.
		 *
		 * @var int
		 */
		public $ID = 0;

		/**
		 * E-mail address.
		 *
		 * @var string
		 */
		public $user_email = '';
	}
}
