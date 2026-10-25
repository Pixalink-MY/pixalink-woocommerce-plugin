<?php
/**
 * Unit tests for DashboardCard::present().
 *
 * @package Pixalink\Loyalty\Tests
 */

namespace Pixalink\Loyalty\Tests\Unit\Account;

use Pixalink\Loyalty\Account\DashboardCard;
use Pixalink\Loyalty\Account\SummaryService;
use Pixalink\Loyalty\Tests\Unit\TestCase;

/**
 * The card's view model: the balance, the soonest expiry, the tier and how many vouchers work in this store.
 *
 * @covers \Pixalink\Loyalty\Account\DashboardCard::present
 */
final class DashboardCardTest extends TestCase {

	public function test_a_full_summary_becomes_the_compact_card(): void {
		$summary = SummaryService::normalize(
			array(
				'points'   => array(
					'balance'       => 1250,
					'expiring_soon' => array(
						array(
							'points'     => 80,
							'expires_at' => '2026-12-31T00:00:00Z',
						),
						array(
							'points'     => 200,
							'expires_at' => '2026-11-14T00:00:00Z',
						),
					),
				),
				'credits'  => array( 'balance_cents' => 1500 ),
				'tier'     => array(
					'name'     => 'Silver',
					'progress' => array(
						'current'        => 700,
						'next'           => 1000,
						'remaining'      => 300,
						'next_tier_name' => 'Gold',
					),
				),
				'vouchers' => array(
					array(
						'id'              => '1',
						'code'            => 'PXL-A',
						'title'           => 'RM10 off',
						'redeemable_here' => true,
					),
					array(
						'id'              => '2',
						'code'            => 'PXL-B',
						'title'           => 'Free pastry (in store)',
						'redeemable_here' => false,
					),
					array(
						'id'              => '3',
						'code'            => 'PXL-C',
						'title'           => 'Free delivery',
						'redeemable_here' => true,
					),
				),
			)
		);

		$this->assertSame(
			array(
				'points'         => 1250,
				'expiring'       => array(
					'points'     => 200,
					'expires_at' => '2026-11-14T00:00:00Z',
				),
				'tier'           => array(
					'name'           => 'Silver',
					'remaining'      => 300,
					'next_tier_name' => 'Gold',
					'percent'        => 70,
				),
				'vouchers_ready' => 2,
			),
			DashboardCard::present( $summary )
		);
	}

	public function test_a_new_member_has_points_only(): void {
		$card = DashboardCard::present(
			SummaryService::normalize(
				array(
					'points'   => array( 'balance' => 0 ),
					'tier'     => null,
					'vouchers' => array(),
				)
			)
		);

		$this->assertSame(
			array(
				'points'         => 0,
				'expiring'       => null,
				'tier'           => null,
				'vouchers_ready' => 0,
			),
			$card
		);
	}

	public function test_unreadable_parts_are_dropped_not_guessed(): void {
		$card = DashboardCard::present(
			array(
				'points_balance'  => '1250',
				'points_expiring' => array(
					array(
						'points'     => 50,
						'expires_at' => 'soon',
					),
					'junk',
				),
				'tier'            => array( 'name' => '' ),
				'vouchers'        => 'none',
			)
		);

		$this->assertSame(
			array(
				'points'         => 0,
				'expiring'       => null,
				'tier'           => null,
				'vouchers_ready' => 0,
			),
			$card
		);
	}
}
