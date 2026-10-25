<?php
/**
 * Integration tests for Uninstaller.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Integration\Install;

use Pixalink\Loyalty\Install\Installer;
use Pixalink\Loyalty\Install\Uninstaller;
use Pixalink\Loyalty\Support\Options;

/**
 * Uninstall removes data only when the merchant opted in.
 *
 * @covers \Pixalink\Loyalty\Install\Uninstaller
 */
final class UninstallerTest extends \WP_UnitTestCase {

	/**
	 * Shopper with plugin user meta.
	 *
	 * @var int
	 */
	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		Installer::activate();
		update_option( Options::CREDENTIALS, 'pxl1:encrypted', false );
		set_transient( 'pixalink_loyalty_quote_cache', array( 'x' => 1 ), HOUR_IN_SECONDS );
		update_option( 'unrelated_plugin_option', 'keep' );

		$this->user_id = self::factory()->user->create();
		update_user_meta( $this->user_id, 'pixalink_customer_id', '5001' );
		update_user_meta( $this->user_id, 'billing_phone', '+60123456789' );
	}

	public function test_data_is_kept_by_default(): void {
		$this->assertFalse( Uninstaller::uninstall_site() );

		$this->assertSame( 'pxl1:encrypted', get_option( Options::CREDENTIALS ) );
		$this->assertSame( '5001', get_user_meta( $this->user_id, 'pixalink_customer_id', true ) );
		$this->assertNotFalse( get_transient( 'pixalink_loyalty_quote_cache' ) );
	}

	public function test_opted_in_uninstall_removes_only_plugin_data(): void {
		update_option( Options::DELETE_DATA_ON_UNINSTALL, 'yes', false );

		$this->assertTrue( Uninstaller::uninstall_site() );

		foreach ( Options::names() as $option ) {
			$this->assertFalse( get_option( $option ), $option . ' survived uninstall.' );
		}
		$this->assertSame( '', get_user_meta( $this->user_id, 'pixalink_customer_id', true ) );
		$this->assertFalse( get_transient( 'pixalink_loyalty_quote_cache' ) );

		$this->assertSame( 'keep', get_option( 'unrelated_plugin_option' ) );
		$this->assertSame( '+60123456789', get_user_meta( $this->user_id, 'billing_phone', true ) );
	}
}
