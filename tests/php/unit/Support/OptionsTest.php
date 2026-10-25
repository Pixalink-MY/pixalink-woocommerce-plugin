<?php
/**
 * Tests for Options.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Support;

use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * @covers \Pixalink\Loyalty\Support\Options
 */
final class OptionsTest extends TestCase {

	public function test_every_option_is_prefixed(): void {
		foreach ( Options::names() as $name ) {
			$this->assertStringStartsWith( Options::PREFIX, $name );
		}
	}

	public function test_missing_options_fall_back_to_their_registered_default(): void {
		$options = new Options();

		$this->assertTrue( $options->get_bool( Options::CHECKOUT_PANEL_ENABLED ) );
		$this->assertFalse( $options->get_bool( Options::DEBUG_LOGGING ) );
		$this->assertSame( 'optional', $options->get_string( Options::PHONE_FIELD ) );
		$this->assertSame( 0, $options->get_int( Options::CLOCK_OFFSET ) );
		$this->assertSame( array(), $options->get_array( Options::CONNECTION ) );
	}

	public function test_explicit_fallbacks_still_win(): void {
		$options = new Options();

		$this->assertFalse( $options->get_bool( Options::CHECKOUT_PANEL_ENABLED, false ) );
		$this->assertSame( 'required', $options->get_string( Options::PHONE_FIELD, 'required' ) );
	}

	public function test_stored_values_are_read_with_their_types(): void {
		$options = new Options();
		$options->set( Options::CHECKOUT_PANEL_ENABLED, 'no' );
		$options->set( Options::CONNECTION, array( 'status' => 'connected' ) );
		$options->set( Options::REMOTE_STATUS, 'not an array' );

		$this->assertFalse( $options->get_bool( Options::CHECKOUT_PANEL_ENABLED ) );
		$this->assertSame( array( 'status' => 'connected' ), $options->get_array( Options::CONNECTION ) );
		$this->assertSame( array(), $options->get_array( Options::REMOTE_STATUS ) );
	}

	public function test_frontend_settings_autoload_and_secrets_do_not(): void {
		$registry = Options::registry();

		$this->assertTrue( $registry[ Options::CONNECTION ]['autoload'] );
		$this->assertTrue( $registry[ Options::REMOTE_STATUS ]['autoload'] );
		$this->assertTrue( $registry[ Options::CHECKOUT_PANEL_ENABLED ]['autoload'] );
		$this->assertFalse( $registry[ Options::CREDENTIALS ]['autoload'] );
		$this->assertFalse( $registry[ Options::MARKETING_CONSENT ]['autoload'] );
		$this->assertSame( array_keys( Options::settings_registry() ), array_values( array_intersect( Options::names(), array_keys( Options::settings_registry() ) ) ) );
	}

	public function test_unknown_options_are_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Options::default_value( 'pixalink_loyalty_typo' );
	}
}
