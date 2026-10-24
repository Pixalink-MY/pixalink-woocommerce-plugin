<?php
/**
 * Unit tests for Logger.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Support;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * Debug entries follow the "Debug log" setting; warnings and errors are always written; secrets never are.
 *
 * @covers \Pixalink\Loyalty\Support\Logger
 */
final class LoggerTest extends TestCase {

	/**
	 * Entries written: [level, message, context].
	 *
	 * @var list<array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $written = array();

	protected function set_up(): void {
		parent::set_up();

		$this->written = array();
		$sink          = new class( $this->written ) {
			/**
			 * Entries.
			 *
			 * @var list<array{0:string,1:string,2:array<string,mixed>}>
			 */
			public array $entries;

			/**
			 * Constructor.
			 *
			 * @param list<array{0:string,1:string,2:array<string,mixed>}> $entries Shared entries.
			 */
			public function __construct( array &$entries ) {
				$this->entries = &$entries;
			}

			/**
			 * WC_Logger::log().
			 *
			 * @param string              $level   Level.
			 * @param string              $message Message.
			 * @param array<string,mixed> $context Context.
			 */
			public function log( string $level, string $message, array $context ): void {
				$this->entries[] = array( $level, $message, $context );
			}
		};
		Functions\when( 'wc_get_logger' )->justReturn( $sink );
	}

	public function test_debug_entries_follow_the_setting(): void {
		$options = new Options();
		$logger  = new Logger( static fn (): bool => $options->get_bool( Options::DEBUG_LOGGING ) );

		$logger->debug( 'Pixalink API request completed.' );
		$this->assertSame( array(), $this->written, 'Debug logging is off by default.' );

		$this->options[ Options::DEBUG_LOGGING ] = array( 'yes', true );
		$logger->debug( 'Pixalink API request completed.', array( 'path' => 'customers' ) );
		$this->assertCount( 1, $this->written );
		$this->assertSame( 'debug', $this->written[0][0] );
		$this->assertSame( Logger::SOURCE, $this->written[0][2]['source'] );
	}

	public function test_warnings_and_errors_are_written_whatever_the_setting(): void {
		$logger = new Logger( static fn (): bool => false );

		$logger->warning( 'w' );
		$logger->error( 'e' );
		$logger->info( 'i' );

		$this->assertSame( array( 'warning', 'error', 'info' ), array_column( $this->written, 0 ) );
	}

	public function test_without_a_switch_debug_is_written_as_before(): void {
		( new Logger() )->debug( 'd', array( 'phone_e164' => '+60123456789' ) );

		$this->assertSame( '+*******6789', $this->written[0][2]['phone_e164'] );
	}
}
