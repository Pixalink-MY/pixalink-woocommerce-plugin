<?php
/**
 * Integration tests for AccountFields.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Fields;

use Pixalink\Loyalty\Fields\AccountFields;
use Pixalink\Loyalty\Fields\ConsentText;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Tests\Integration\Support\StoreFixtures;

/**
 * Registration records an explicit "yes" only; Account details shows the saved choice so it can be withdrawn and
 * keeps a verified number read-only.
 *
 * @covers \Pixalink\Loyalty\Fields\AccountFields
 */
final class AccountFieldsTest extends \WP_UnitTestCase {

	use StoreFixtures;

	public function set_up(): void {
		parent::set_up();

		$this->set_up_store();
		$_POST = array();
	}

	public function tear_down(): void {
		$_POST = array();
		$this->tear_down_store();

		parent::tear_down();
	}

	private static function capture( string $hook ): string {
		ob_start();
		do_action( $hook );

		return (string) ob_get_clean();
	}

	public function test_the_registration_form_shows_the_phone_and_an_unticked_consent_box(): void {
		$html = self::capture( 'woocommerce_register_form' );

		$this->assertStringContainsString( 'name="' . AccountFields::PHONE . '"', $html );
		$this->assertStringContainsString( 'name="' . AccountFields::COUNTRY . '"', $html );
		$this->assertMatchesRegularExpression( '/<option value="MY"\s+selected=\'selected\'/', $html, 'The store country is preselected.' );
		$this->assertStringContainsString( 'name="' . AccountFields::CONSENT . '"', $html );
		$this->assertStringNotContainsString( 'checked', $html, 'Never pre-ticked.' );
		$this->assertStringContainsString( esc_html( ConsentText::default_text() ), $html );
	}

	public function test_registering_with_a_phone_and_consent_records_both(): void {
		$_POST = array(
			AccountFields::COUNTRY => 'MY',
			AccountFields::PHONE   => '012-345 6789',
			AccountFields::CONSENT => '1',
		);

		$errors = apply_filters( 'woocommerce_process_registration_errors', new \WP_Error(), 'new', 'secret', 'new@example.com' );
		$this->assertFalse( $errors->has_errors() );

		$user_id = wc_create_new_customer( 'new@example.com', '', wp_generate_password() );
		$this->assertIsInt( $user_id );

		$this->assertSame( array( '+60123456789', 'MY' ), UserMeta::phone( $user_id ) );
		$consent = UserMeta::consent( $user_id );
		$this->assertNotNull( $consent );
		$this->assertTrue( $consent->value );
		$this->assertSame( 'registration', $consent->source );
		$this->assertSame( ConsentText::hash( ConsentText::default_text() ), $consent->text_hash );
		$this->assertSame( array( 'customer.upserted' ), $this->outbox_types() );
	}

	public function test_registering_without_ticking_records_no_consent(): void {
		$_POST = array(
			AccountFields::COUNTRY => 'MY',
			AccountFields::PHONE   => '0123456789',
		);
		apply_filters( 'woocommerce_process_registration_errors', new \WP_Error(), 'new', 'secret', 'new@example.com' );
		$user_id = wc_create_new_customer( 'new@example.com', '', wp_generate_password() );
		$this->assertIsInt( $user_id );

		$this->assertNull( UserMeta::consent( $user_id ) );
	}

	public function test_an_invalid_registration_phone_is_refused(): void {
		$_POST = array(
			AccountFields::COUNTRY => 'MY',
			AccountFields::PHONE   => 'call me',
		);

		$errors = apply_filters( 'woocommerce_process_registration_errors', new \WP_Error(), 'new', 'secret', 'new@example.com' );

		$this->assertSame( array( 'pixalink_loyalty_phone' ), $errors->get_error_codes() );
	}

	public function test_a_checkout_sign_up_is_not_mistaken_for_the_registration_form(): void {
		$_POST = array(
			AccountFields::PHONE   => '0123456789',
			AccountFields::CONSENT => '1',
		);

		$user_id = wc_create_new_customer( 'checkout@example.com', '', wp_generate_password() );
		$this->assertIsInt( $user_id );

		$this->assertNull( UserMeta::consent( $user_id ), 'Checkout records its own answer with source checkout.' );
	}

	public function test_account_details_show_the_saved_choice_and_a_verified_number_read_only(): void {
		$user_id = $this->make_user();
		update_user_meta( $user_id, UserMeta::CUSTOMER_ID, '5001' );
		update_user_meta( $user_id, UserMeta::PHONE_E164, '+60123456789' );
		update_user_meta( $user_id, UserMeta::PHONE_COUNTRY, 'MY' );
		update_user_meta( $user_id, UserMeta::PHONE_VERIFIED_AT, '2026-10-25T02:00:00Z' );
		update_user_meta( $user_id, UserMeta::CONSENT, 'yes' );
		update_user_meta( $user_id, UserMeta::CONSENT_SOURCE, 'checkout' );
		update_user_meta( $user_id, UserMeta::CONSENT_AT, '2026-10-24T08:00:00Z' );
		update_user_meta( $user_id, UserMeta::CONSENT_TEXT_HASH, ConsentText::hash( 'x' ) );
		wp_set_current_user( $user_id );

		$html = self::capture( 'woocommerce_edit_account_form' );

		$this->assertStringContainsString( 'value="123456789"', $html );
		$this->assertStringContainsString( 'readonly', $html );
		$this->assertStringContainsString( 'Verified with Pixalink', $html );
		$this->assertMatchesRegularExpression( '/name="' . AccountFields::CONSENT . '"[^>]*checked/', $html, 'The saved yes is shown so it can be withdrawn.' );
	}

	public function test_unticking_the_saved_yes_records_a_withdrawal_and_reports_it(): void {
		$user_id = $this->make_user();
		update_user_meta( $user_id, UserMeta::CONSENT, 'yes' );
		update_user_meta( $user_id, UserMeta::CONSENT_SOURCE, 'checkout' );
		update_user_meta( $user_id, UserMeta::CONSENT_AT, '2026-10-24T08:00:00Z' );
		update_user_meta( $user_id, UserMeta::CONSENT_TEXT_HASH, ConsentText::hash( 'x' ) );
		wp_set_current_user( $user_id );

		$_POST = array(
			'pixalink_loyalty_consent_shown' => '1',
			AccountFields::COUNTRY           => 'MY',
			AccountFields::PHONE             => '0123456789',
		);
		$errors = new \WP_Error();
		$user   = get_userdata( $user_id );
		do_action_ref_array( 'woocommerce_save_account_details_errors', array( &$errors, &$user ) );
		$this->assertFalse( $errors->has_errors() );
		do_action( 'woocommerce_save_account_details', $user_id );

		$consent = UserMeta::consent( $user_id );
		$this->assertNotNull( $consent );
		$this->assertFalse( $consent->value );
		$this->assertSame( 'my_account', $consent->source );
		$this->assertSame( array( '+60123456789', 'MY' ), UserMeta::phone( $user_id ) );
		$this->assertSame( array( 'customer.upserted' ), $this->outbox_types() );
		$this->assertFalse( $this->last_payload()['customer']['marketing_consent']['value'] );
	}

	public function test_saving_unchanged_details_records_nothing(): void {
		$user_id = $this->make_user();
		wp_set_current_user( $user_id );

		$_POST = array( 'pixalink_loyalty_consent_shown' => '1' );
		do_action( 'woocommerce_save_account_details', $user_id );

		$this->assertNull( UserMeta::consent( $user_id ), 'An unticked box that showed no is not an answer.' );
		$this->assertSame( array(), $this->outbox_types() );
	}
}
