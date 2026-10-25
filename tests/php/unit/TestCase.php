<?php
/**
 * Base class for Brain Monkey unit tests.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillTestCase;

/**
 * Sets up Brain Monkey and an in-memory wp_options table for each test.
 */
abstract class TestCase extends PolyfillTestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * In-memory wp_options rows: name => [value, autoload].
	 *
	 * @var array<string,array{0:mixed,1:mixed}>
	 */
	protected array $options = array();

	/**
	 * In-memory transients: name => [value, expiration seconds].
	 *
	 * @var array<string,array{0:mixed,1:int}>
	 */
	protected array $transients = array();

	/**
	 * Set up Brain Monkey and common WordPress function stubs.
	 */
	protected function set_up(): void {
		parent::set_up();
		Monkey\setUp();

		$this->options    = array();
		$this->transients = array();

		Functions\when( 'get_transient' )->alias(
			fn ( string $name ) => array_key_exists( $name, $this->transients ) ? $this->transients[ $name ][0] : false
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $name, $value, int $expiration = 0 ): bool {
				$this->transients[ $name ] = array( $value, $expiration );

				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $name ): bool {
				$existed = array_key_exists( $name, $this->transients );
				unset( $this->transients[ $name ] );

				return $existed;
			}
		);

		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();
		Functions\when( 'wp_json_encode' )->alias(
			static fn ( $data, int $flags = 0, int $depth = 512 ) => json_encode( $data, $flags, $depth )
		);
		Functions\when( 'home_url' )->justReturn( 'https://shop.example.test' );
		Functions\when( 'get_option' )->alias(
			fn ( string $name, $fallback = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ][0] : $fallback
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value, $autoload = null ): bool {
				$changed                = ! array_key_exists( $name, $this->options ) || $this->options[ $name ][0] !== $value;
				$this->options[ $name ] = array( $value, $autoload );

				return $changed;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( string $name, $value = '', $deprecated = '', $autoload = null ): bool {
				if ( array_key_exists( $name, $this->options ) ) {
					return false;
				}
				$this->options[ $name ] = array( $value, $autoload );

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				$existed = array_key_exists( $name, $this->options );
				unset( $this->options[ $name ] );

				return $existed;
			}
		);
	}

	/**
	 * Tear down Brain Monkey.
	 */
	protected function tear_down(): void {
		Monkey\tearDown();
		parent::tear_down();
	}
}
