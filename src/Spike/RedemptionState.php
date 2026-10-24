<?php
/**
 * SPIKE (P02, throwaway): per-shopper redemption state kept in the WooCommerce session.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Spike;

defined( 'ABSPATH' ) || exit;

/**
 * What the shopper chose (points, vouchers) plus the last quote, keyed by the WC session's customer id.
 *
 * Stored in its own transient, NOT inside the WC session blob: that blob is last-writer-wins, and same-session
 * requests that started earlier (Action Scheduler's async queue runner forwards the shopper's cookies on a loopback
 * at shutdown) write their stale copy back and erase our keys. Seen in the e2e trace; see ADR 0001 pitfall 1.
 *
 * The virtual coupons are re-resolved from this on every `new WC_Coupon( $code )`, so it must be cheap: no HTTP.
 */
final class RedemptionState {

	/**
	 * Transient key prefix (suffix = md5 of the WC session customer id).
	 */
	public const SESSION_KEY = 'pixalink_loyalty_redemption';

	/**
	 * Lifetime, aligned with the WC session (48 h).
	 */
	private const TTL = 2 * DAY_IN_SECONDS;

	/**
	 * Storage key for the current session.
	 *
	 * @return string|null
	 */
	private static function key(): ?string {
		if ( ! self::is_available() ) {
			return null;
		}

		$customer = (string) WC()->session->get_customer_id();

		return '' === $customer ? null : self::SESSION_KEY . '_' . md5( $customer );
	}

	/**
	 * Whether a WC session exists in this request (front end / Store API; not admin, cron or IPN callbacks).
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'WC' ) && WC()->session instanceof \WC_Session;
	}

	/**
	 * Raw state.
	 *
	 * @return array{verified:bool,customer_id:string,quote:array<string,mixed>,points:int,vouchers:array<int,string>}
	 */
	public static function get(): array {
		$defaults = array(
			'verified'    => false,
			'customer_id' => '',
			'quote'       => array(),
			'points'      => 0,
			'vouchers'    => array(),
		);

		$key = self::key();
		if ( null === $key ) {
			return $defaults;
		}

		$stored = get_transient( $key );
		if ( ! is_array( $stored ) ) {
			return $defaults;
		}

		return array(
			'verified'    => (bool) ( $stored['verified'] ?? false ),
			'customer_id' => is_string( $stored['customer_id'] ?? null ) ? $stored['customer_id'] : '',
			'quote'       => is_array( $stored['quote'] ?? null ) ? $stored['quote'] : array(),
			'points'      => max( 0, (int) ( $stored['points'] ?? 0 ) ),
			'vouchers'    => is_array( $stored['vouchers'] ?? null ) ? array_values( array_map( 'strval', $stored['vouchers'] ) ) : array(),
		);
	}

	/**
	 * Merge and persist.
	 *
	 * @param array<string,mixed> $changes Keys to replace.
	 * @return void
	 */
	public static function update( array $changes ): void {
		$key = self::key();
		if ( null === $key ) {
			return;
		}

		set_transient( $key, array_merge( self::get(), $changes ), self::TTL );
	}

	/**
	 * Forget everything.
	 *
	 * @return void
	 */
	public static function reset(): void {
		$key = self::key();
		if ( null !== $key ) {
			delete_transient( $key );
		}
	}

	/**
	 * Whether the shopper proved ownership of the Pixalink member (OTP / SSO in W3).
	 *
	 * @return bool
	 */
	public static function is_verified(): bool {
		$state = self::get();

		return $state['verified'] && '' !== $state['customer_id'];
	}

	/**
	 * Points config from the quote.
	 *
	 * @return array{balance:int,available:int,value_per_point:string,min:int,max_for_cart:int,step:int}
	 */
	public static function points_config(): array {
		$points = self::get()['quote']['points'] ?? array();
		$points = is_array( $points ) ? $points : array();

		return array(
			'balance'         => (int) ( $points['balance'] ?? 0 ),
			'available'       => (int) ( $points['available'] ?? 0 ),
			'value_per_point' => is_string( $points['value_per_point'] ?? null ) ? $points['value_per_point'] : '0',
			'min'             => (int) ( $points['min'] ?? 0 ),
			'max_for_cart'    => (int) ( $points['max_for_cart'] ?? 0 ),
			'step'            => max( 1, (int) ( $points['step'] ?? 1 ) ),
		);
	}

	/**
	 * Quote voucher whose code matches (WooCommerce lower-cases coupon codes, Pixalink issues upper case).
	 *
	 * @param string $code Coupon code in any case.
	 * @return array<string,mixed>|null
	 */
	public static function voucher( string $code ): ?array {
		$vouchers = self::get()['quote']['vouchers'] ?? array();
		if ( ! is_array( $vouchers ) ) {
			return null;
		}

		foreach ( $vouchers as $voucher ) {
			if ( is_array( $voucher ) && is_string( $voucher['code'] ?? null ) && wc_is_same_coupon( $voucher['code'], $code ) ) {
				return $voucher;
			}
		}

		return null;
	}
}
