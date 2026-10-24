<?php
/**
 * Prepares a wp-env site for tests/e2e/specs/checkout-redemption.spec.ts.
 *
 * Run: npx wp-env run cli wp eval-file wp-content/plugins/pixalink-loyalty-for-woocommerce/tests/e2e/bin/setup-checkout.php
 *
 * A USD store with BACS only and no tax, two products (PXL-A 100.00, PXL-B 50.00), a classic checkout page next to
 * the Checkout block page, the store connected to the fake Pixalink server (heartbeat run, so the remote switches
 * are known), and the admin (the logged-in e2e user) as a verified Pixalink member (customer 5001) with a saved US
 * billing address.
 *
 * @package Pixalink\Loyalty\Tests
 */

defined( 'ABSPATH' ) || exit;

update_option( 'woocommerce_default_country', 'US:CA' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_enable_coupons', 'yes' );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option(
	'woocommerce_bacs_settings',
	array(
		'enabled' => 'yes',
		'title'   => 'Direct bank transfer',
	)
);
foreach ( array( 'cheque', 'cod' ) as $pixalink_loyalty_gateway ) {
	update_option( 'woocommerce_' . $pixalink_loyalty_gateway . '_settings', array( 'enabled' => 'no' ) );
}

WC_Install::create_pages();

$pixalink_loyalty_products = array();
foreach ( array(
	'PXL-A' => array( 'Product A', '100' ),
	'PXL-B' => array( 'Product B', '50' ),
) as $pixalink_loyalty_sku => $pixalink_loyalty_definition ) {
	$pixalink_loyalty_id      = wc_get_product_id_by_sku( $pixalink_loyalty_sku );
	$pixalink_loyalty_product = $pixalink_loyalty_id ? wc_get_product( $pixalink_loyalty_id ) : new WC_Product_Simple();
	$pixalink_loyalty_product->set_name( $pixalink_loyalty_definition[0] );
	$pixalink_loyalty_product->set_sku( $pixalink_loyalty_sku );
	$pixalink_loyalty_product->set_regular_price( $pixalink_loyalty_definition[1] );
	$pixalink_loyalty_product->set_virtual( true );
	$pixalink_loyalty_product->set_status( 'publish' );
	$pixalink_loyalty_product->save();
	$pixalink_loyalty_products[ $pixalink_loyalty_sku ] = $pixalink_loyalty_product->get_id();
}

$pixalink_loyalty_classic = get_page_by_path( 'classic-checkout' );
if ( ! $pixalink_loyalty_classic instanceof WP_Post ) {
	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => 'classic-checkout',
			'post_title'   => 'Classic checkout',
			'post_content' => '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->',
		)
	);
}

// The admin checks out with a saved address, so neither checkout needs form filling.
$pixalink_loyalty_customer = new WC_Customer( 1 );
$pixalink_loyalty_customer->set_props(
	array(
		'billing_first_name' => 'Aisyah',
		'billing_last_name'  => 'Rahman',
		'billing_address_1'  => '1 Market St',
		'billing_city'       => 'San Francisco',
		'billing_state'      => 'CA',
		'billing_postcode'   => '94105',
		'billing_country'    => 'US',
		'billing_email'      => 'member@example.com',
		'billing_phone'      => '4155550100',
	)
);
$pixalink_loyalty_customer->save();
\Pixalink\Loyalty\Identity\UserMeta::store_link(
	1,
	new \Pixalink\Loyalty\Identity\Link(
		\Pixalink\Loyalty\Tests\FakeServer\Responses::MEMBER_ID,
		\Pixalink\Loyalty\Tests\FakeServer\Responses::MEMBER_PHONE,
		'MY',
		gmdate( 'Y-m-d\\TH:i:s\\Z' ),
		\Pixalink\Loyalty\Identity\Link::VIA_OTP
	)
);
delete_user_meta( 1, \Pixalink\Loyalty\Redemption\RedemptionState::USER_META );

$pixalink_loyalty_plugin = \Pixalink\Loyalty\Plugin::instance();
$pixalink_loyalty_plugin->credentials()->store( \Pixalink\Loyalty\Tests\FakeServer\Signature::FAKE_API_KEY );
$pixalink_loyalty_heartbeat = $pixalink_loyalty_plugin->heartbeat()->run();

echo wp_json_encode(
	array(
		'products'           => $pixalink_loyalty_products,
		'checkout_page'      => get_permalink( wc_get_page_id( 'checkout' ) ),
		'checkout_has_block' => has_block( 'woocommerce/checkout', wc_get_page_id( 'checkout' ) ),
		'features'           => $pixalink_loyalty_heartbeat['features'] ?? null,
		'hpos'               => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
		'woocommerce'        => WC_VERSION,
		'wordpress'          => get_bloginfo( 'version' ),
	),
	JSON_PRETTY_PRINT
) . "\n";
