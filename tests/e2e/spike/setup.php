<?php
/**
 * SPIKE (P02, throwaway): prepares the wp-env site for tests/e2e/specs/spike-checkout.spec.ts.
 *
 * Run: npx wp-env run cli wp eval-file wp-content/plugins/pixalink-loyalty-for-woocommerce/tests/e2e/spike/setup.php
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
foreach ( array( 'cheque', 'cod' ) as $pixalink_spike_gateway ) {
	update_option( 'woocommerce_' . $pixalink_spike_gateway . '_settings', array( 'enabled' => 'no' ) );
}

WC_Install::create_pages();

$pixalink_spike_products = array();
foreach ( array( 'PXL-A' => array( 'Spike A', '100' ), 'PXL-B' => array( 'Spike B', '50' ) ) as $pixalink_spike_sku => $pixalink_spike_def ) {
	$pixalink_spike_id = wc_get_product_id_by_sku( $pixalink_spike_sku );
	$pixalink_spike_p  = $pixalink_spike_id ? wc_get_product( $pixalink_spike_id ) : new WC_Product_Simple();
	$pixalink_spike_p->set_name( $pixalink_spike_def[0] );
	$pixalink_spike_p->set_sku( $pixalink_spike_sku );
	$pixalink_spike_p->set_regular_price( $pixalink_spike_def[1] );
	$pixalink_spike_p->set_virtual( true );
	$pixalink_spike_p->set_status( 'publish' );
	$pixalink_spike_p->save();
	$pixalink_spike_products[ $pixalink_spike_sku ] = $pixalink_spike_p->get_id();
}

$pixalink_spike_pages = array();
foreach ( array(
	'classic-checkout' => array( 'Classic checkout', '[woocommerce_checkout]' ),
	'classic-cart'     => array( 'Classic cart', '[woocommerce_cart]' ),
) as $pixalink_spike_slug => $pixalink_spike_page ) {
	$pixalink_spike_existing = get_page_by_path( $pixalink_spike_slug );
	$pixalink_spike_pages[ $pixalink_spike_slug ] = $pixalink_spike_existing instanceof WP_Post
		? $pixalink_spike_existing->ID
		: wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => $pixalink_spike_slug,
				'post_title'   => $pixalink_spike_page[0],
				'post_content' => '<!-- wp:shortcode -->' . $pixalink_spike_page[1] . '<!-- /wp:shortcode -->',
			)
		);
}

// Admin (the logged-in e2e user) checks out with a saved US address, so neither checkout needs form filling.
$pixalink_spike_customer = new WC_Customer( 1 );
$pixalink_spike_customer->set_props(
	array(
		'billing_first_name' => 'Spike',
		'billing_last_name'  => 'Shopper',
		'billing_address_1'  => '1 Market St',
		'billing_city'       => 'San Francisco',
		'billing_state'      => 'CA',
		'billing_postcode'   => '94105',
		'billing_country'    => 'US',
		'billing_email'      => 'shopper@example.com',
		'billing_phone'      => '4155550100',
	)
);
$pixalink_spike_customer->save();

\Pixalink\Loyalty\Plugin::instance()->credentials()->store( \Pixalink\Loyalty\Tests\FakeServer\Signature::FAKE_API_KEY );

$pixalink_spike_checkout_id = wc_get_page_id( 'checkout' );
echo wp_json_encode(
	array(
		'products'             => $pixalink_spike_products,
		'pages'                => $pixalink_spike_pages,
		'checkout_page'        => get_permalink( $pixalink_spike_checkout_id ),
		'checkout_has_block'   => has_block( 'woocommerce/checkout', $pixalink_spike_checkout_id ),
		'spike_enabled'        => \Pixalink\Loyalty\Spike\Spike::enabled(),
		'woocommerce_version'  => WC_VERSION,
		'wordpress_version'    => get_bloginfo( 'version' ),
		'fake_server_attached' => (bool) has_filter( 'pre_http_request', array( \Pixalink\Loyalty\Tests\FakeServer\Server::class, 'intercept' ) ),
	),
	JSON_PRETTY_PRINT
) . "\n";
