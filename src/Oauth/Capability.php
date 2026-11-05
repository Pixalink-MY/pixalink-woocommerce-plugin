<?php
/**
 * Whether Pixalink signs members in to this store over OAuth.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Oauth;

use Pixalink\Loyalty\Support\Branding;
use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The heartbeat's optional `oauth` block (connection-heartbeat.response.json), kept only when the store can use it
 * safely: the authorize, token and revocation endpoints are https (http only on local development hosts, as
 * {@see Branding::url()}), the authorize page is on the member portal's own host (`branding.portal_url`), S256 PKCE is
 * supported and so are both scopes the store asks for ({@see Capability::SCOPE}). A server older than the block, or a
 * block that fails any check, means no OAuth: the store keeps the portal ticket flow.
 */
final class Capability {

	/**
	 * Scopes the store asks for: who the member is, and linking them to a store account.
	 */
	public const SCOPE = 'profile store:link';

	/**
	 * Constructor.
	 *
	 * @param string $authorization_endpoint Member portal page that signs the member in and asks for consent.
	 * @param string $token_endpoint         Where the store exchanges the code (form-encoded, HTTP Basic).
	 * @param string $revocation_endpoint    RFC 7009 revocation (the store keeps no tokens, so it is not called).
	 */
	public function __construct(
		public readonly string $authorization_endpoint,
		public readonly string $token_endpoint,
		public readonly string $revocation_endpoint
	) {}

	/**
	 * The capability a cached heartbeat advertises, or null.
	 *
	 * @param array<mixed> $status `pixalink_loyalty_remote_status` (heartbeat response keys).
	 * @return Capability|null
	 */
	public static function from_remote_status( array $status ): ?Capability {
		$block       = $status['oauth'] ?? null;
		$portal_host = Branding::from_remote_status( $status )?->portal_host();
		if ( ! is_array( $block ) || null === $portal_host ) {
			return null;
		}

		$authorize  = Branding::url( $block['authorization_endpoint'] ?? null );
		$token      = Branding::url( $block['token_endpoint'] ?? null );
		$revocation = Branding::url( $block['revocation_endpoint'] ?? null );
		if ( null === $authorize || null === $token || null === $revocation || Branding::host( $authorize ) !== $portal_host ) {
			return null;
		}

		if ( ! self::lists( $block['code_challenge_methods_supported'] ?? null, array( 'S256' ) ) || ! self::lists( $block['scopes_supported'] ?? null, explode( ' ', self::SCOPE ) ) ) {
			return null;
		}

		return new self( $authorize, $token, $revocation );
	}

	/**
	 * What the last heartbeat advertised.
	 *
	 * @param Options|null $options Option storage.
	 * @return Capability|null
	 */
	public static function remote( ?Options $options = null ): ?Capability {
		return self::from_remote_status( ( $options ?? new Options() )->get_array( Options::REMOTE_STATUS ) );
	}

	/**
	 * Whether a value is a list holding every wanted string.
	 *
	 * @param mixed        $values Value from the heartbeat.
	 * @param list<string> $wanted Strings it must hold.
	 * @return bool
	 */
	private static function lists( $values, array $wanted ): bool {
		return is_array( $values ) && array() === array_diff( $wanted, array_filter( $values, 'is_string' ) );
	}
}
