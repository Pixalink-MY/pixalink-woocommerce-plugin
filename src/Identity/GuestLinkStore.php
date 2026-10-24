<?php
/**
 * Verified links of guests, per WooCommerce session.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * A transient keyed by an md5 of the WooCommerce session customer id (ADR 0001 decision 2: never inside the WC session
 * blob, which is last-writer-wins and can be rolled back by Action Scheduler's loopback request).
 */
final class GuestLinkStore {

	public const PREFIX = 'pixalink_loyalty_shopper_';

	/**
	 * Lifetime of a guest link (WooCommerce sessions last 48 hours).
	 */
	public const TTL = 2 * 86400;

	/**
	 * The link verified in a session.
	 *
	 * @param string $session_id WooCommerce session customer id.
	 * @return Link|null
	 */
	public function get( string $session_id ): ?Link {
		if ( '' === $session_id ) {
			return null;
		}

		$stored = get_transient( self::key( $session_id ) );

		return is_array( $stored ) ? Link::from_array( $stored ) : null;
	}

	/**
	 * Remember a link for a session.
	 *
	 * @param string $session_id WooCommerce session customer id.
	 * @param Link   $link       Verified link.
	 * @return void
	 */
	public function put( string $session_id, Link $link ): void {
		if ( '' === $session_id ) {
			return;
		}

		set_transient( self::key( $session_id ), $link->to_array(), self::TTL );
	}

	/**
	 * Forget a session's link.
	 *
	 * @param string $session_id WooCommerce session customer id.
	 * @return void
	 */
	public function forget( string $session_id ): void {
		if ( '' !== $session_id ) {
			delete_transient( self::key( $session_id ) );
		}
	}

	/**
	 * Transient name for a session.
	 *
	 * @param string $session_id WooCommerce session customer id.
	 * @return string
	 */
	public static function key( string $session_id ): string {
		return self::PREFIX . md5( $session_id );
	}
}
