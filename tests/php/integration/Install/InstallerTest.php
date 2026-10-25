<?php
/**
 * Integration tests for Installer.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Install;

use Pixalink\Loyalty\Install\Installer;
use Pixalink\Loyalty\Install\Schema;
use Pixalink\Loyalty\Support\Options;

/**
 * Activation seeds options without side effects; upgrades re-run when the schema is behind.
 *
 * @covers \Pixalink\Loyalty\Install\Installer
 * @covers \Pixalink\Loyalty\Install\Schema
 * @covers \Pixalink\Loyalty\Support\Options
 */
final class InstallerTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		foreach ( Options::names() as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * Autoload flag of an option as stored.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	private function is_autoloaded( string $option ): bool {
		global $wpdb;

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) );

		return in_array( $autoload, wp_autoload_values_to_autoload(), true );
	}

	public function test_activation_creates_options_with_the_right_autoload_flags(): void {
		Installer::activate();

		$this->assertSame( Schema::VERSION, (int) get_option( Options::DB_VERSION ) );
		$this->assertSame( 'no', get_option( Options::DELETE_DATA_ON_UNINSTALL ) );
		$this->assertSame( 0, (int) get_option( Options::CLOCK_OFFSET ) );

		$this->assertTrue( $this->is_autoloaded( Options::CLOCK_OFFSET ), 'The clock offset is read on every signed request.' );
		$this->assertFalse( $this->is_autoloaded( Options::DELETE_DATA_ON_UNINSTALL ) );
		$this->assertFalse( get_option( Options::CREDENTIALS ), 'Activation must not create credentials.' );
	}

	public function test_activation_keeps_existing_settings(): void {
		update_option( Options::DELETE_DATA_ON_UNINSTALL, 'yes', false );
		update_option( Options::CLOCK_OFFSET, 42 );

		Installer::activate();

		$this->assertSame( 'yes', get_option( Options::DELETE_DATA_ON_UNINSTALL ) );
		$this->assertSame( 42, (int) get_option( Options::CLOCK_OFFSET ) );
	}

	public function test_activation_makes_no_http_requests_and_does_not_redirect(): void {
		$requests = 0;
		$counter  = static function ( $preempt ) use ( &$requests ) {
			++$requests;
			return $preempt;
		};
		add_filter( 'pre_http_request', $counter, 1 );

		Installer::activate();

		remove_filter( 'pre_http_request', $counter, 1 );
		$this->assertSame( 0, $requests );
		$this->assertFalse( get_transient( '_pixalink_loyalty_activation_redirect' ) );
	}

	public function test_maybe_upgrade_reinstalls_when_the_schema_is_behind(): void {
		update_option( Options::DB_VERSION, 0 );
		$installed = did_action( 'pixalink_loyalty_installed' );

		Installer::maybe_upgrade();

		$this->assertSame( Schema::VERSION, (int) get_option( Options::DB_VERSION ) );
		$this->assertSame( $installed + 1, did_action( 'pixalink_loyalty_installed' ) );

		Installer::maybe_upgrade();
		$this->assertSame( $installed + 1, did_action( 'pixalink_loyalty_installed' ), 'Up-to-date installs do nothing.' );
	}

	public function test_deactivation_unschedules_the_plugins_actions(): void {
		as_schedule_single_action( time() + HOUR_IN_SECONDS, 'pixalink_loyalty_test_action', array(), Installer::ACTION_GROUP );
		$this->assertTrue( as_has_scheduled_action( 'pixalink_loyalty_test_action', null, Installer::ACTION_GROUP ) );

		Installer::deactivate();

		$this->assertFalse( as_has_scheduled_action( 'pixalink_loyalty_test_action', null, Installer::ACTION_GROUP ) );
	}
}
