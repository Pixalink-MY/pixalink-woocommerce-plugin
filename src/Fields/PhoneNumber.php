<?php
/**
 * A normalised phone number.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable E.164 number with the country it was filed under and its national significant number.
 */
final class PhoneNumber {

	/**
	 * Constructor.
	 *
	 * @param string $e164     E.164 form, e.g. "+60123456789".
	 * @param string $country  ISO 3166-1 alpha-2 country, e.g. "MY".
	 * @param string $national National significant number (no trunk prefix), e.g. "123456789".
	 */
	public function __construct(
		public readonly string $e164,
		public readonly string $country,
		public readonly string $national
	) {}
}
