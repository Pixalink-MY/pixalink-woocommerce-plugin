<?php
/**
 * How the merchant's Pixalink member portal looks.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The heartbeat's optional `branding` block (logo, colours, portal address, sign-in page), validated field by field.
 *
 * A colour is kept only when it is lower-case `#rrggbb`; the logo and portal URLs only when they are https (http is
 * accepted for local development hosts: localhost, `*.localhost`, `*.test`). The sign-in page behind the store's
 * "Sign in with the member portal" button must pass the same check and sit on the portal's own host. Anything else
 * becomes null, so the CSS built from this object can only ever contain six hex digits per colour. A server older than
 * the field sends no block at all, which means no branding.
 */
final class Branding {

	public const COLOR_PATTERN = '/^#[0-9a-f]{6}$/D';

	/**
	 * Longest portal name kept (the rest is cut).
	 */
	private const MAX_NAME_LENGTH = 191;

	/**
	 * Constructor.
	 *
	 * @param string|null $name              The portal's app title.
	 * @param string|null $logo_url          Logo URL.
	 * @param string|null $primary_color     Brand colour.
	 * @param string|null $primary_color_on  Readable text colour on the brand colour.
	 * @param string|null $primary_color_ink Brand colour adjusted to stay readable as text on white.
	 * @param string|null $secondary_color   Accent colour.
	 * @param string|null $portal_url        The member portal's signed-out home page.
	 * @param string|null $sign_in_url       The portal page behind "Sign in with the member portal" (on the portal host).
	 */
	public function __construct(
		public readonly ?string $name,
		public readonly ?string $logo_url,
		public readonly ?string $primary_color,
		public readonly ?string $primary_color_on,
		public readonly ?string $primary_color_ink,
		public readonly ?string $secondary_color,
		public readonly ?string $portal_url,
		public readonly ?string $sign_in_url = null
	) {}

	/**
	 * Branding from a cached heartbeat response, or null when the server sent none.
	 *
	 * @param array<mixed> $status `pixalink_loyalty_remote_status` (heartbeat response keys).
	 * @return Branding|null
	 */
	public static function from_remote_status( array $status ): ?Branding {
		$block = $status['branding'] ?? null;
		if ( ! is_array( $block ) ) {
			return null;
		}

		$portal_url = self::url( $block['portal_url'] ?? null );

		return new self(
			self::name( $block['name'] ?? null ),
			self::url( $block['logo_url'] ?? null ),
			self::color( $block['primary_color'] ?? null ),
			self::color( $block['primary_color_on'] ?? null ),
			self::color( $block['primary_color_ink'] ?? null ),
			self::color( $block['secondary_color'] ?? null ),
			$portal_url,
			self::on_host_of( self::url( $block['sign_in_url'] ?? null ), $portal_url )
		);
	}

	/**
	 * A URL when its host is the host of another, or null (also when either is unknown).
	 *
	 * @param string|null $url   Candidate (already validated).
	 * @param string|null $other URL whose host it must share.
	 * @return string|null
	 */
	private static function on_host_of( ?string $url, ?string $other ): ?string {
		if ( null === $url || null === $other ) {
			return null;
		}

		$host = self::host( $url );

		return null !== $host && self::host( $other ) === $host ? $url : null;
	}

	/**
	 * What the last heartbeat said about the portal, whatever the merchant's setting (the portal address is needed
	 * to check sign-in links even when the store keeps its theme colours).
	 *
	 * @param Options|null $options Option storage.
	 * @return Branding|null
	 */
	public static function remote( ?Options $options = null ): ?Branding {
		return self::from_remote_status( ( $options ?? new Options() )->get_array( Options::REMOTE_STATUS ) );
	}

	/**
	 * The branding My Account and checkout use: null while the merchant turned "Match your member portal" off or the
	 * server sent none.
	 *
	 * @param Options|null $options Option storage.
	 * @return Branding|null
	 */
	public static function active( ?Options $options = null ): ?Branding {
		$options = $options ?? new Options();
		if ( ! $options->get_bool( Options::PORTAL_BRANDING ) ) {
			return null;
		}

		return self::remote( $options );
	}

	/**
	 * Whether there is a brand fill and a text colour for it (the minimum to paint anything).
	 *
	 * @return bool
	 */
	public function has_colors(): bool {
		return null !== $this->primary_color && null !== $this->primary_color_on;
	}

	/**
	 * Lower-case host of the portal URL.
	 *
	 * @return string|null
	 */
	public function portal_host(): ?string {
		return null === $this->portal_url ? null : self::host( $this->portal_url );
	}

	/**
	 * A lower-case `#rrggbb` colour or null.
	 *
	 * @param mixed $value Candidate.
	 * @return string|null
	 */
	public static function color( $value ): ?string {
		return is_string( $value ) && 1 === preg_match( self::COLOR_PATTERN, $value ) ? $value : null;
	}

	/**
	 * An absolute https URL (http only on local development hosts), or null.
	 *
	 * @param mixed $value Candidate.
	 * @return string|null
	 */
	public static function url( $value ): ?string {
		if ( ! is_string( $value ) || 1 !== preg_match( '#^https?://[^\s<>"\'`\\\\]+$#iD', $value ) ) {
			return null;
		}

		$scheme = wp_parse_url( $value, PHP_URL_SCHEME );
		$host   = self::host( $value );
		if ( ! is_string( $scheme ) || null === $host ) {
			return null;
		}

		$scheme = strtolower( $scheme );

		return 'https' === $scheme || ( 'http' === $scheme && self::is_local_host( $host ) ) ? $value : null;
	}

	/**
	 * Lower-case host of a URL.
	 *
	 * @param string $url URL.
	 * @return string|null
	 */
	public static function host( string $url ): ?string {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) && '' !== $host ? strtolower( $host ) : null;
	}

	/**
	 * Whether a host is a local development host.
	 *
	 * @param string $host Lower-case host.
	 * @return bool
	 */
	private static function is_local_host( string $host ): bool {
		return 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.test' );
	}

	/**
	 * A trimmed, non-empty name of at most 191 characters, or null.
	 *
	 * @param mixed $value Candidate.
	 * @return string|null
	 */
	private static function name( $value ): ?string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		return mb_substr( trim( $value ), 0, self::MAX_NAME_LENGTH );
	}
}
