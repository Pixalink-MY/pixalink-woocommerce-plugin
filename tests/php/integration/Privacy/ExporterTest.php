<?php
/**
 * Integration tests for the privacy exporter and eraser.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Privacy;

use Pixalink\Loyalty\Account\SummaryService;
use Pixalink\Loyalty\Fields\ConsentRecord;
use Pixalink\Loyalty\Identity\Link;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Orders\OrderMeta;
use Pixalink\Loyalty\Privacy\Eraser;
use Pixalink\Loyalty\Privacy\Exporter;
use Pixalink\Loyalty\Redemption\RedemptionState;
use Pixalink\Loyalty\Tests\Integration\Support\StoreFixtures;

/**
 * Tools › Export / Erase Personal Data include the loyalty link (erasure also drops the stored checkout redemption state);
 * WooCommerce's order anonymiser drops the loyalty phone.
 *
 * @covers \Pixalink\Loyalty\Privacy\Exporter
 * @covers \Pixalink\Loyalty\Privacy\Eraser
 */
final class ExporterTest extends \WP_UnitTestCase {

	use StoreFixtures;

	private int $user_id = 0;

	public function set_up(): void {
		parent::set_up();

		$this->set_up_store();
		$this->user_id = $this->make_user( 'privacy@example.com' );
		UserMeta::store_link( $this->user_id, new Link( '5001', '+60123456789', 'MY', '2026-10-25T02:00:00Z', Link::VIA_OTP ) );
		UserMeta::set_consent( $this->user_id, ConsentRecord::given( true, ConsentRecord::SOURCE_CHECKOUT, 'Send me offers.', 1761300000 ) );
	}

	public function tear_down(): void {
		$this->tear_down_store();

		parent::tear_down();
	}

	public function test_both_tools_are_registered_with_wordpress(): void {
		$this->assertArrayHasKey( Exporter::ID, apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( Eraser::ID, apply_filters( 'wp_privacy_personal_data_erasers', array() ) );
	}

	public function test_the_export_lists_the_link_and_the_consent(): void {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$result    = call_user_func( $exporters[ Exporter::ID ]['callback'], 'privacy@example.com', 1 );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['data'] );
		$values = array_column( $result['data'][0]['data'], 'value', 'name' );
		$this->assertSame( '5001', $values['Pixalink customer id'] );
		$this->assertSame( '+60123456789', $values['Loyalty phone number'] );
		$this->assertSame( '2026-10-25T02:00:00Z', $values['Phone verified at'] );
		$this->assertSame( 'yes', $values['Marketing consent'] );
		$this->assertSame( 'checkout', $values['Marketing consent given on'] );

		$this->assertSame( array(), call_user_func( $exporters[ Exporter::ID ]['callback'], 'nobody@example.com', 1 )['data'] );
	}

	public function test_erasure_removes_the_loyalty_meta_and_cache(): void {
		set_transient( SummaryService::key( $this->user_id ), array( 'cached' => true ), 60 );
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$result = call_user_func( $erasers[ Eraser::ID ]['callback'], 'privacy@example.com', 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
		foreach ( UserMeta::KEYS as $key ) {
			$this->assertSame( '', get_user_meta( $this->user_id, $key, true ), $key );
		}
		$this->assertFalse( get_transient( SummaryService::key( $this->user_id ) ) );

		$again = call_user_func( $erasers[ Eraser::ID ]['callback'], 'privacy@example.com', 1 );
		$this->assertFalse( $again['items_removed'] );
	}

	public function test_erasure_removes_the_stored_checkout_redemption_state(): void {
		foreach ( UserMeta::KEYS as $key ) {
			delete_user_meta( $this->user_id, $key );
		}
		update_user_meta(
			$this->user_id,
			RedemptionState::USER_META,
			array(
				'customer_id' => '5001',
				'points'      => 500,
			)
		);
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$result = call_user_func( $erasers[ Eraser::ID ]['callback'], 'privacy@example.com', 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( metadata_exists( 'user', $this->user_id, RedemptionState::USER_META ) );
	}

	public function test_order_anonymisation_drops_the_loyalty_phone(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->update_meta_data( OrderMeta::PHONE_E164, '+60123456789' );
		$order->update_meta_data( OrderMeta::PHONE_COUNTRY, 'MY' );
		$order->set_billing_email( 'privacy@example.com' );
		$order->save();

		\WC_Privacy_Erasers::remove_order_personal_data( $order );

		$fresh = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertSame( '', OrderMeta::get_string( $fresh, OrderMeta::PHONE_E164 ) );
	}
}
