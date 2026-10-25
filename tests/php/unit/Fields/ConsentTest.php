<?php
/**
 * Unit tests for ConsentText and ConsentRecord.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Fields;

use Brain\Monkey\Filters;
use Pixalink\Loyalty\Fields\ConsentRecord;
use Pixalink\Loyalty\Fields\ConsentText;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\FakeServer\ContractValidator;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * The consent text shown is the text hashed; records match the contract only for reportable sources.
 *
 * @covers \Pixalink\Loyalty\Fields\ConsentText
 * @covers \Pixalink\Loyalty\Fields\ConsentRecord
 */
final class ConsentTest extends TestCase {

	public function test_the_hash_is_sha256_of_exactly_the_shown_text(): void {
		$text = 'Send me offers by WhatsApp. I can opt out at any time.';

		$this->assertSame( hash( 'sha256', $text ), ConsentText::hash( $text ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', ConsentText::hash( $text ) );
		$this->assertNotSame( ConsentText::hash( $text ), ConsentText::hash( $text . ' ' ), 'Any change to the wording is a different text.' );
		$this->assertSame( '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08', ConsentText::hash( 'test' ), 'Known sha256 test vector.' );
	}

	public function test_text_defaults_then_uses_the_setting_then_the_filter(): void {
		$consent = new ConsentText( new Options() );
		$this->assertSame( ConsentText::default_text(), $consent->text( ConsentRecord::SOURCE_CHECKOUT ) );

		$this->options[ Options::CHECKOUT_CONSENT_TEXT ] = array( '  Yes, send me Kopi Club news.  ', true );
		$this->assertSame( 'Yes, send me Kopi Club news.', $consent->text( ConsentRecord::SOURCE_CHECKOUT ) );

		Filters\expectApplied( 'pixalink_loyalty_consent_text' )
			->once()
			->with( 'Yes, send me Kopi Club news.', ConsentRecord::SOURCE_MY_ACCOUNT )
			->andReturn( 'Filtered wording' );
		$this->assertSame( 'Filtered wording', $consent->text( ConsentRecord::SOURCE_MY_ACCOUNT ) );
	}

	public function test_a_filter_returning_nothing_falls_back_to_the_default(): void {
		Filters\expectApplied( 'pixalink_loyalty_consent_text' )->once()->andReturn( '' );

		$this->assertSame( ConsentText::default_text(), ( new ConsentText( new Options() ) )->text( ConsentRecord::SOURCE_CHECKOUT ) );
	}

	public function test_a_given_answer_records_the_hash_time_and_source_and_matches_the_contract(): void {
		$record = ConsentRecord::given( true, ConsentRecord::SOURCE_CHECKOUT, 'Send me offers.', 1761300000 );

		$this->assertTrue( $record->value );
		$this->assertSame( '2025-10-24T10:00:00Z', $record->captured_at );
		$this->assertSame( hash( 'sha256', 'Send me offers.' ), $record->text_hash );
		$this->assertTrue( $record->is_reportable() );
		$this->assertSame(
			array(
				'value'       => true,
				'source'      => 'checkout',
				'captured_at' => '2025-10-24T10:00:00Z',
				'text_hash'   => hash( 'sha256', 'Send me offers.' ),
			),
			$record->to_array()
		);

		// The shared definition the order and customer payloads reference.
		$this->assertSame(
			array(),
			ContractValidator::validate(
				'customers-create.request.json',
				array(
					'phone'             => '+60123456789',
					'phone_country'     => 'MY',
					'marketing_consent' => $record->to_array(),
					'verified_via'      => 'none',
				)
			)
		);
	}

	public function test_a_withdrawal_by_pixalink_is_kept_locally_but_never_reported(): void {
		$record = ConsentRecord::given( false, ConsentRecord::SOURCE_PIXALINK, '', 1761300000 );

		$this->assertFalse( $record->is_reportable() );
		$this->assertNull( $record->to_reportable_array() );
		$this->assertNotNull( ConsentRecord::given( false, ConsentRecord::SOURCE_MY_ACCOUNT, 'x', 1761300000 )->to_reportable_array() );
	}

	public function test_records_round_trip_through_storage_and_reject_garbage(): void {
		$record = ConsentRecord::given( false, ConsentRecord::SOURCE_MY_ACCOUNT, 'Send me offers.', 1761300000 );

		$this->assertEquals( $record, ConsentRecord::from_parts( 'no', 'my_account', '2025-10-24T10:00:00Z', $record->text_hash ) );
		$this->assertEquals( $record, ConsentRecord::from_parts( '0', 'my_account', '1761300000', $record->text_hash ), 'Unix times are accepted.' );
		$this->assertNull( ConsentRecord::from_parts( '', 'my_account', '2025-10-24T10:00:00Z', $record->text_hash ), 'No answer.' );
		$this->assertNull( ConsentRecord::from_parts( 'maybe', 'my_account', '2025-10-24T10:00:00Z', $record->text_hash ) );
		$this->assertNull( ConsentRecord::from_parts( 'yes', 'newsletter', '2025-10-24T10:00:00Z', $record->text_hash ), 'Unknown source.' );
		$this->assertNull( ConsentRecord::from_parts( 'yes', 'checkout', 'yesterday-ish', $record->text_hash ) );
		$this->assertNull( ConsentRecord::from_parts( 'yes', 'checkout', '2025-10-24T10:00:00Z', 'not-a-hash' ) );
	}
}
