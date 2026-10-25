<?php
/**
 * Contract: the portal look the heartbeat and the summary carry.
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Contract;

use Brain\Monkey\Functions;
use Pixalink\Loyalty\Account\SummaryService;
use Pixalink\Loyalty\Support\Branding;
use Pixalink\Loyalty\Tests\FakeServer\ContractValidator;
use Pixalink\Loyalty\Tests\FakeServer\Responses;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * Whatever the schemas allow for `branding` and `tier.color` survives the plugin's stricter checks unchanged.
 *
 * @covers \Pixalink\Loyalty\Support\Branding
 * @covers \Pixalink\Loyalty\Account\SummaryService
 */
final class BrandingPayloadsTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();

		Functions\when( 'wp_parse_url' )->alias( static fn ( string $url, int $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}

	/**
	 * Examples of a schema file.
	 *
	 * @param string $file Schema file.
	 * @return list<array<string,mixed>>
	 */
	private static function examples( string $file ): array {
		$schema = json_decode( (string) file_get_contents( ContractValidator::schema_dir() . '/' . $file ), true );

		return $schema['examples'];
	}

	public function test_the_heartbeat_example_branding_parses_whole(): void {
		$example  = self::examples( 'connection-heartbeat.response.json' )[0];
		$branding = Branding::from_remote_status( $example );

		$this->assertInstanceOf( Branding::class, $branding );
		$this->assertSame( $example['branding']['name'], $branding->name );
		$this->assertSame( $example['branding']['logo_url'], $branding->logo_url );
		$this->assertSame( $example['branding']['primary_color'], $branding->primary_color );
		$this->assertSame( $example['branding']['primary_color_on'], $branding->primary_color_on );
		$this->assertSame( $example['branding']['primary_color_ink'], $branding->primary_color_ink );
		$this->assertSame( $example['branding']['secondary_color'], $branding->secondary_color );
		$this->assertSame( $example['branding']['portal_url'], $branding->portal_url );
		$this->assertSame( $example['branding']['sign_in_url'], $branding->sign_in_url, 'The sign-in page is on the portal host.' );
	}

	public function test_a_heartbeat_without_the_sign_in_page_is_valid_and_hides_the_button(): void {
		$example = self::examples( 'connection-heartbeat.response.json' )[0];
		unset( $example['branding']['sign_in_url'] );

		$this->assertSame( array(), ContractValidator::validate( 'connection-heartbeat.response.json', $example ), 'Servers older than the field omit it.' );
		$this->assertNull( Branding::from_remote_status( $example )?->sign_in_url );
	}

	public function test_a_heartbeat_without_branding_is_still_valid_and_means_no_branding(): void {
		$example = self::examples( 'connection-heartbeat.response.json' )[0];
		unset( $example['branding'] );

		$this->assertSame( array(), ContractValidator::validate( 'connection-heartbeat.response.json', $example ), 'Older servers omit the block.' );
		$this->assertNull( Branding::from_remote_status( $example ) );
	}

	public function test_the_schema_refuses_colours_the_plugin_would_drop(): void {
		$example                              = self::examples( 'connection-heartbeat.response.json' )[0];
		$example['branding']['primary_color'] = '#ABCDEF';

		$this->assertNotSame( array(), ContractValidator::validate( 'connection-heartbeat.response.json', $example ) );
	}

	public function test_the_fake_server_heartbeat_carries_usable_branding(): void {
		$heartbeat = Responses::samples( 1761300000 )['heartbeat']['response']['body'];
		$branding  = Branding::from_remote_status( $heartbeat );

		$this->assertInstanceOf( Branding::class, $branding );
		$this->assertTrue( $branding->has_colors() );
		$this->assertSame( Responses::PORTAL_HOST, $branding->portal_host() );
		$this->assertSame( Responses::SIGN_IN_URL, $branding->sign_in_url );
	}

	public function test_the_summary_tier_colour_is_kept(): void {
		$example = self::examples( 'customer-summary.response.json' )[0];
		$color   = static function ( array $raw ) {
			$tier = SummaryService::normalize( $raw )['tier'];

			return is_array( $tier ) && array_key_exists( 'color', $tier ) ? $tier['color'] : 'missing';
		};

		$this->assertSame( '#c0c0c0', $color( $example ) );

		$example['tier']['color'] = null;
		$this->assertNull( $color( $example ) );

		unset( $example['tier']['color'] );
		$this->assertNull( $color( $example ), 'Older servers omit it.' );

		$example['tier']['color'] = 'silver';
		$this->assertNull( $color( $example ) );
	}
}
