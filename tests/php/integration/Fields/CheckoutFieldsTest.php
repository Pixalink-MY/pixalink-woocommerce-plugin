<?php
/**
 * Integration tests for CheckoutFields.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Fields;

use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields as BlocksCheckoutFields;
use Automattic\WooCommerce\Blocks\Package as BlocksPackage;
use Pixalink\Loyalty\Fields\CheckoutFields;
use Pixalink\Loyalty\Fields\ConsentText;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Orders\OrderMeta;
use Pixalink\Loyalty\Tests\Integration\Support\StoreFixtures;

/**
 * Phone and consent persist on orders from the Checkout block (Store API) and the classic checkout, in both order
 * storage modes; consent is never pre-ticked and an unticked box records nothing.
 *
 * @covers \Pixalink\Loyalty\Fields\CheckoutFields
 * @covers \Pixalink\Loyalty\Fields\OrderIdentity
 * @covers \Pixalink\Loyalty\Fields\BillingPhoneSync
 * @covers \Pixalink\Loyalty\Identity\CustomerEvents
 */
final class CheckoutFieldsTest extends \WP_UnitTestCase {

	use StoreFixtures;

	public function set_up(): void {
		parent::set_up();

		$this->set_up_store();
		$this->fill_cart();
	}

	public function tear_down(): void {
		$this->tear_down_store();

		parent::tear_down();
	}

	private function consent_hash(): string {
		return ConsentText::hash( ConsentText::default_text() );
	}

	public function test_the_checkout_block_fields_are_registered_in_the_contact_section(): void {
		$service = BlocksPackage::container()->get( BlocksCheckoutFields::class );
		$fields  = $service->get_fields_for_location( 'contact' );

		$this->assertArrayHasKey( CheckoutFields::BLOCKS_COUNTRY, $fields );
		$this->assertArrayHasKey( CheckoutFields::BLOCKS_PHONE, $fields );
		$this->assertArrayHasKey( CheckoutFields::BLOCKS_CONSENT, $fields );
		$this->assertSame( 'checkbox', $fields[ CheckoutFields::BLOCKS_CONSENT ]['type'] );
		$this->assertFalse( $fields[ CheckoutFields::BLOCKS_CONSENT ]['required'] );
		$this->assertSame( ConsentText::default_text(), $fields[ CheckoutFields::BLOCKS_CONSENT ]['label'] );
		$this->assertContains( 'MY', array_column( $fields[ CheckoutFields::BLOCKS_COUNTRY ]['options'], 'value' ) );
	}

	public function test_blocks_checkout_stores_e164_and_the_ticked_consent_on_the_order(): void {
		$response = $this->blocks_checkout(
			array(
				CheckoutFields::BLOCKS_COUNTRY => 'MY',
				CheckoutFields::BLOCKS_PHONE   => '012-345 6789',
				CheckoutFields::BLOCKS_CONSENT => true,
			)
		);
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		$order = wc_get_order( (int) $response->get_data()['order_id'] );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertSame( '+60123456789', OrderMeta::get_string( $order, OrderMeta::PHONE_E164 ) );
		$this->assertSame( 'MY', OrderMeta::get_string( $order, OrderMeta::PHONE_COUNTRY ) );
		$this->assertSame( 'yes', OrderMeta::get_string( $order, OrderMeta::MARKETING_CONSENT ) );
		$this->assertSame( 'checkout', OrderMeta::get_string( $order, OrderMeta::CONSENT_SOURCE ) );
		$this->assertSame( $this->consent_hash(), OrderMeta::get_string( $order, OrderMeta::CONSENT_TEXT_HASH ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', OrderMeta::get_string( $order, OrderMeta::CONSENT_CAPTURED_AT ) );
		$this->assertSame( '+60123456789', $order->get_billing_phone(), 'An empty billing phone is filled one way.' );

		$this->assertSame( array( 'customer.upserted' ), $this->outbox_types(), 'A guest giving consent is reported once.' );
		$this->assertTrue( $this->last_payload()['customer']['is_guest'] );
	}

	public function test_blocks_checkout_with_the_box_unticked_records_no_consent(): void {
		$response = $this->blocks_checkout(
			array(
				CheckoutFields::BLOCKS_COUNTRY => 'SG',
				CheckoutFields::BLOCKS_PHONE   => '9123 4567',
				CheckoutFields::BLOCKS_CONSENT => false,
			)
		);
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		$order = wc_get_order( (int) $response->get_data()['order_id'] );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertSame( '+6591234567', OrderMeta::get_string( $order, OrderMeta::PHONE_E164 ) );
		$this->assertSame( '', OrderMeta::get_string( $order, OrderMeta::MARKETING_CONSENT ), 'Never inferred.' );
		$this->assertSame( array(), $this->outbox_types() );
	}

	public function test_blocks_checkout_rejects_an_invalid_number_with_our_message(): void {
		$response = $this->blocks_checkout(
			array(
				CheckoutFields::BLOCKS_COUNTRY => 'MY',
				CheckoutFields::BLOCKS_PHONE   => '012-FLOWERS',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'A phone number can only contain digits', (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_a_logged_in_shopper_gets_the_number_and_consent_on_their_account_and_the_box_is_not_pre_ticked_next_time(): void {
		$user_id = $this->make_user();
		wp_set_current_user( $user_id );
		WC()->customer = new \WC_Customer( $user_id, true );

		$response = $this->blocks_checkout(
			array(
				CheckoutFields::BLOCKS_COUNTRY => 'MY',
				CheckoutFields::BLOCKS_PHONE   => '0123456789',
				CheckoutFields::BLOCKS_CONSENT => true,
			)
		);
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		$this->assertSame( array( '+60123456789', 'MY' ), UserMeta::phone( $user_id ) );
		$this->assertTrue( UserMeta::consent( $user_id )?->value );
		$this->assertSame( array( 'customer.upserted' ), $this->outbox_types() );
		$this->assertSame( $user_id, $this->last_payload()['customer']['wp_user_id'] );
		$this->assertSame( '+60123456789', get_user_meta( $user_id, 'billing_phone', true ) );

		$customer = new \WC_Customer( $user_id );
		$service  = BlocksPackage::container()->get( BlocksCheckoutFields::class );
		$this->assertFalse( $customer->meta_exists( BlocksCheckoutFields::OTHER_FIELDS_PREFIX . CheckoutFields::BLOCKS_CONSENT ), 'The consent answer is not kept on the profile.' );
		$this->assertArrayNotHasKey( CheckoutFields::BLOCKS_CONSENT, $service->get_all_fields_from_object( $customer, 'other' ), 'The next checkout gets no consent value to pre-fill.' );
		// An unanswered checkbox reads as false before WooCommerce 11.3 and as '' (not set) from 11.3 (woocommerce#68780).
		$this->assertContains( $service->get_field_from_object( CheckoutFields::BLOCKS_CONSENT, $customer, 'other' ), array( false, '' ), 'The box is not shown ticked.' );
		$this->assertSame( '0123456789', $service->get_field_from_object( CheckoutFields::BLOCKS_PHONE, $customer, 'other' ), 'The typed number is kept for the next checkout.' );
	}

	public function test_a_verified_number_is_never_overwritten_by_checkout(): void {
		$user_id = $this->make_user();
		update_user_meta( $user_id, UserMeta::CUSTOMER_ID, '5001' );
		update_user_meta( $user_id, UserMeta::PHONE_E164, '+60123456789' );
		update_user_meta( $user_id, UserMeta::PHONE_COUNTRY, 'MY' );
		update_user_meta( $user_id, UserMeta::PHONE_VERIFIED_AT, '2026-10-25T02:00:00Z' );
		wp_set_current_user( $user_id );
		WC()->customer = new \WC_Customer( $user_id, true );

		$response = $this->blocks_checkout(
			array(
				CheckoutFields::BLOCKS_COUNTRY => 'MY',
				CheckoutFields::BLOCKS_PHONE   => '0199999999',
			)
		);
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		$order = wc_get_order( (int) $response->get_data()['order_id'] );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertSame( '+60199999999', OrderMeta::get_string( $order, OrderMeta::PHONE_E164 ), 'The order keeps what was typed.' );
		$this->assertSame( '5001', OrderMeta::get_string( $order, OrderMeta::CUSTOMER_ID ), 'The verified member is stamped on the order.' );
		$this->assertSame( array( '+60123456789', 'MY' ), UserMeta::phone( $user_id ), 'The verified number stays.' );
		$this->assertSame( array(), $this->outbox_types() );
	}

	public function test_the_checkout_block_prefills_the_account_number_when_the_profile_has_none(): void {
		$user_id = $this->make_user();
		update_user_meta( $user_id, UserMeta::PHONE_E164, '+6591234567' );
		update_user_meta( $user_id, UserMeta::PHONE_COUNTRY, 'SG' );

		$customer = new \WC_Customer( $user_id );
		$service  = BlocksPackage::container()->get( BlocksCheckoutFields::class );

		$this->assertSame( '91234567', $service->get_field_from_object( CheckoutFields::BLOCKS_PHONE, $customer, 'other' ) );
		$this->assertSame( 'SG', $service->get_field_from_object( CheckoutFields::BLOCKS_COUNTRY, $customer, 'other' ) );
		$this->assertSame( 'MY', $service->get_field_from_object( CheckoutFields::BLOCKS_COUNTRY, new \WC_Customer( $this->make_user( 'other@example.com' ) ), 'other' ), 'Otherwise the store country.' );
	}

	public function test_classic_checkout_offers_the_fields_and_stores_e164_and_consent(): void {
		$fields = WC()->checkout()->get_checkout_fields( 'billing' );
		$this->assertArrayHasKey( CheckoutFields::CLASSIC_COUNTRY, $fields );
		$this->assertArrayHasKey( CheckoutFields::CLASSIC_PHONE, $fields );
		$this->assertArrayHasKey( CheckoutFields::CLASSIC_CONSENT, $fields );
		$this->assertSame( 'MY', WC()->checkout()->get_value( CheckoutFields::CLASSIC_COUNTRY ), 'Defaults to the store country.' );
		$this->assertSame( '', WC()->checkout()->get_value( CheckoutFields::CLASSIC_CONSENT ), 'Never pre-ticked.' );

		$order = $this->classic_checkout(
			array(
				CheckoutFields::CLASSIC_COUNTRY => 'MY',
				CheckoutFields::CLASSIC_PHONE   => '+60 12-345 6789',
				CheckoutFields::CLASSIC_CONSENT => 1,
			)
		);

		$this->assertSame( '+60123456789', OrderMeta::get_string( $order, OrderMeta::PHONE_E164 ) );
		$this->assertSame( 'yes', OrderMeta::get_string( $order, OrderMeta::MARKETING_CONSENT ) );
		$this->assertSame( $this->consent_hash(), OrderMeta::get_string( $order, OrderMeta::CONSENT_TEXT_HASH ) );
		$this->assertSame( array( 'customer.upserted' ), $this->outbox_types() );
	}

	public function test_classic_checkout_without_consent_records_none(): void {
		$order = $this->classic_checkout(
			array(
				CheckoutFields::CLASSIC_COUNTRY => 'MY',
				CheckoutFields::CLASSIC_PHONE   => '0123456789',
				CheckoutFields::CLASSIC_CONSENT => 0,
			)
		);

		$this->assertSame( '+60123456789', OrderMeta::get_string( $order, OrderMeta::PHONE_E164 ) );
		$this->assertSame( '', OrderMeta::get_string( $order, OrderMeta::MARKETING_CONSENT ) );
		$this->assertSame( array(), $this->outbox_types() );
	}

	public function test_classic_validation_explains_a_bad_number(): void {
		$errors = new \WP_Error();
		do_action(
			'woocommerce_after_checkout_validation',
			array(
				CheckoutFields::CLASSIC_COUNTRY => 'MY',
				CheckoutFields::CLASSIC_PHONE   => '0123',
			),
			$errors
		);

		$this->assertSame( array( 'This phone number is too short.' ), $errors->get_error_messages( 'pixalink_loyalty_phone_validation' ) );
	}
}
