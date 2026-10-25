<?php
/**
 * Loyalty summary of a verified member.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Account;

use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\Resources\Customers;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Support\Branding;
use Pixalink\Loyalty\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches `GET customers/{id}` for verified members only, caches it for 60 seconds per user and normalises it into
 * the view model the templates render.
 *
 * Unverified shoppers never trigger a call. A 403 `verification_required` or 404 means the server no longer accepts the
 * link, so it is dropped (the phone stays as the user's unverified number). Outages are reported, never cached.
 */
final class SummaryService {

	public const VERIFIED    = 'verified';
	public const UNVERIFIED  = 'unverified';
	public const UNAVAILABLE = 'unavailable';

	public const PREFIX = 'pixalink_loyalty_summary_';

	/**
	 * Cache lifetime (seconds).
	 */
	public const TTL = 60;

	/**
	 * Customer endpoints.
	 *
	 * @var Customers
	 */
	private Customers $customers;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Customers $customers Customer endpoints.
	 * @param Logger    $logger    Logger.
	 */
	public function __construct( Customers $customers, Logger $logger ) {
		$this->customers = $customers;
		$this->logger    = $logger;
	}

	/**
	 * Summary of a user.
	 *
	 * @param int $user_id User id.
	 * @return array{state:string,summary:array<string,mixed>|null}
	 */
	public function for_user( int $user_id ): array {
		$link = UserMeta::link( $user_id );
		if ( null === $link ) {
			return self::result( self::UNVERIFIED );
		}

		$cached = get_transient( self::key( $user_id ) );
		if ( is_array( $cached ) && ( $cached['customer_id'] ?? null ) === $link->customer_id && isset( $cached['summary'] ) && is_array( $cached['summary'] ) ) {
			return self::result( self::VERIFIED, $cached['summary'] );
		}

		try {
			$summary = self::normalize( $this->customers->summary( $link->customer_id ) );
		} catch ( ApiException $exception ) {
			if ( in_array( $exception->http_status(), array( 403, 404 ), true ) ) {
				$this->logger->info(
					'Pixalink no longer accepts this account link; it was removed.',
					array(
						'user_id'    => $user_id,
						'error_code' => $exception->error_code(),
					)
				);
				UserMeta::clear_link( $user_id );
				$this->bust( $user_id );

				return self::result( self::UNVERIFIED );
			}

			return self::result( self::UNAVAILABLE );
		}

		set_transient(
			self::key( $user_id ),
			array(
				'customer_id' => $link->customer_id,
				'summary'     => $summary,
			),
			self::TTL
		);

		return self::result( self::VERIFIED, $summary );
	}

	/**
	 * Forget a user's cached summary.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function bust( int $user_id ): void {
		if ( $user_id > 0 ) {
			delete_transient( self::key( $user_id ) );
		}
	}

	/**
	 * Cache key of a user.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function key( int $user_id ): string {
		return self::PREFIX . $user_id;
	}

	/**
	 * View model of a summary response (unknown or malformed parts are dropped, never guessed).
	 *
	 * @param array<mixed> $raw Response body.
	 * @return array{points_balance:int,points_expiring:list<array{points:int,expires_at:string}>,credits_cents:int|null,tier:array{name:string,color:string|null,current:int|null,next:int|null,remaining:int|null,next_tier_name:string|null,percent:int|null}|null,vouchers:list<array{id:string,code:string,title:string,description:string|null,image_url:string|null,expires_at:string|null,redeemable_here:bool}>,memberships:list<array{id:string,name:string,status:string|null,expires_at:string|null}>,activity:list<array{type:string,description:string,points:int|null,occurred_at:string}>,qr:string|null,portal_url:string|null}
	 */
	public static function normalize( array $raw ): array {
		$points = self::map( $raw['points'] ?? null );

		$expiring = array();
		foreach ( self::list( $points['expiring_soon'] ?? null ) as $row ) {
			if ( isset( $row['points'], $row['expires_at'] ) && is_int( $row['points'] ) && $row['points'] > 0 && is_string( $row['expires_at'] ) ) {
				$expiring[] = array(
					'points'     => $row['points'],
					'expires_at' => $row['expires_at'],
				);
			}
		}

		$credits = self::map( $raw['credits'] ?? null );
		$qr      = self::map( $raw['qr'] ?? null );

		return array(
			'points_balance'  => isset( $points['balance'] ) && is_int( $points['balance'] ) ? max( 0, $points['balance'] ) : 0,
			'points_expiring' => $expiring,
			'credits_cents'   => isset( $credits['balance_cents'] ) && is_int( $credits['balance_cents'] ) && $credits['balance_cents'] >= 0 ? $credits['balance_cents'] : null,
			'tier'            => self::tier( $raw['tier'] ?? null ),
			'vouchers'        => self::vouchers( $raw['vouchers'] ?? null ),
			'memberships'     => self::memberships( $raw['memberships'] ?? null ),
			'activity'        => self::activity( $raw['recent_activity'] ?? null ),
			'qr'              => 'phone' === ( $qr['type'] ?? null ) && isset( $qr['value'] ) && is_string( $qr['value'] ) && '' !== $qr['value'] && strlen( $qr['value'] ) <= 64 ? $qr['value'] : null,
			'portal_url'      => self::url( $raw['portal_url'] ?? null ),
		);
	}

	/**
	 * Tier view model.
	 *
	 * @param mixed $tier Raw tier.
	 * @return array{name:string,color:string|null,current:int|null,next:int|null,remaining:int|null,next_tier_name:string|null,percent:int|null}|null
	 */
	private static function tier( $tier ): ?array {
		$tier = self::map( $tier );
		$name = self::string( $tier['name'] ?? null );
		if ( null === $name ) {
			return null;
		}

		$progress  = self::map( $tier['progress'] ?? null );
		$current   = isset( $progress['current'] ) && is_int( $progress['current'] ) ? $progress['current'] : null;
		$next      = isset( $progress['next'] ) && is_int( $progress['next'] ) ? $progress['next'] : null;
		$remaining = isset( $progress['remaining'] ) && is_int( $progress['remaining'] ) ? max( 0, $progress['remaining'] ) : null;
		$percent   = null !== $current && null !== $next && $next > 0 ? (int) min( 100, max( 0, floor( $current * 100 / $next ) ) ) : null;

		return array(
			'name'           => $name,
			'color'          => Branding::color( $tier['color'] ?? null ),
			'current'        => $current,
			'next'           => $next,
			'remaining'      => $remaining,
			'next_tier_name' => self::string( $progress['next_tier_name'] ?? null ),
			'percent'        => $percent,
		);
	}

	/**
	 * Voucher view models (entries without id, code or title are dropped).
	 *
	 * @param mixed $vouchers Raw list.
	 * @return list<array{id:string,code:string,title:string,description:string|null,image_url:string|null,expires_at:string|null,redeemable_here:bool}>
	 */
	private static function vouchers( $vouchers ): array {
		$result = array();
		foreach ( self::list( $vouchers ) as $voucher ) {
			$id    = self::string( $voucher['id'] ?? null );
			$code  = self::string( $voucher['code'] ?? null );
			$title = self::string( $voucher['title'] ?? null );
			if ( null === $id || null === $code || null === $title ) {
				continue;
			}

			$result[] = array(
				'id'              => $id,
				'code'            => $code,
				'title'           => $title,
				'description'     => self::string( $voucher['description'] ?? null ),
				'image_url'       => self::url( $voucher['image_url'] ?? null ),
				'expires_at'      => self::string( $voucher['expires_at'] ?? null ),
				'redeemable_here' => true === ( $voucher['redeemable_here'] ?? null ),
			);
		}

		return $result;
	}

	/**
	 * Membership view models.
	 *
	 * @param mixed $memberships Raw list.
	 * @return list<array{id:string,name:string,status:string|null,expires_at:string|null}>
	 */
	private static function memberships( $memberships ): array {
		$result = array();
		foreach ( self::list( $memberships ) as $membership ) {
			$id   = self::string( $membership['id'] ?? null );
			$name = self::string( $membership['name'] ?? null );
			if ( null === $id || null === $name ) {
				continue;
			}

			$result[] = array(
				'id'         => $id,
				'name'       => $name,
				'status'     => self::string( $membership['status'] ?? null ),
				'expires_at' => self::string( $membership['expires_at'] ?? null ),
			);
		}

		return $result;
	}

	/**
	 * Activity view models.
	 *
	 * @param mixed $activity Raw list.
	 * @return list<array{type:string,description:string,points:int|null,occurred_at:string}>
	 */
	private static function activity( $activity ): array {
		$result = array();
		foreach ( self::list( $activity ) as $row ) {
			$type        = self::string( $row['type'] ?? null );
			$description = self::string( $row['description'] ?? null );
			$occurred_at = self::string( $row['occurred_at'] ?? null );
			if ( null === $type || null === $description || null === $occurred_at ) {
				continue;
			}

			$result[] = array(
				'type'        => $type,
				'description' => $description,
				'points'      => isset( $row['points'] ) && is_int( $row['points'] ) ? $row['points'] : null,
				'occurred_at' => $occurred_at,
			);
		}

		return array_slice( $result, 0, 50 );
	}

	/**
	 * An array or empty.
	 *
	 * @param mixed $value Value.
	 * @return array<mixed>
	 */
	private static function map( $value ): array {
		return is_array( $value ) ? $value : array();
	}

	/**
	 * The arrays inside a list.
	 *
	 * @param mixed $value Value.
	 * @return list<array<mixed>>
	 */
	private static function list( $value ): array {
		return is_array( $value ) ? array_values( array_filter( $value, 'is_array' ) ) : array();
	}

	/**
	 * A non-empty string or null.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private static function string( $value ): ?string {
		if ( is_int( $value ) ) {
			return (string) $value;
		}

		return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : null;
	}

	/**
	 * An http(s) URL or null.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private static function url( $value ): ?string {
		return is_string( $value ) && 1 === preg_match( '#^https?://[^\s<>"\']+$#i', $value ) ? $value : null;
	}

	/**
	 * Result shape.
	 *
	 * @param string                   $state   State.
	 * @param array<string,mixed>|null $summary Summary.
	 * @return array{state:string,summary:array<string,mixed>|null}
	 */
	private static function result( string $state, ?array $summary = null ): array {
		return array(
			'state'   => $state,
			'summary' => $summary,
		);
	}
}
