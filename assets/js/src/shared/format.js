/**
 * Formatting helpers shared by the Blocks and classic loyalty panels.
 *
 * Translated strings come from PHP (one translation source) with `%s` / `%1$s` placeholders.
 */

/**
 * Fill `%s` and `%1$s` placeholders.
 *
 * @param {string}    template Template from PHP.
 * @param {...string} values   Values in order.
 * @return {string} Filled string.
 */
export const fill = ( template, ...values ) => {
	let next = 0;
	return String( template || '' ).replace(
		/%(?:(\d+)\$)?s/g,
		( match, position ) => {
			const index = position ? Number( position ) - 1 : next++;
			return undefined === values[ index ]
				? ''
				: String( values[ index ] );
		}
	);
};

/**
 * Group the integer part of a number with a separator.
 *
 * @param {string} digits    Integer digits.
 * @param {string} separator Thousand separator.
 * @return {string} Grouped digits.
 */
const group = ( digits, separator ) =>
	digits.replace( /\B(?=(\d{3})+(?!\d))/g, separator );

/**
 * A number of hundredths as a decimal string, e.g. 1050 -> "10.50".
 *
 * @param {number} cents   Hundredths.
 * @param {Object} options { decimals, decimalSeparator, thousandSeparator }.
 * @return {string} Formatted number.
 */
export const formatCents = ( cents, options = {} ) => {
	const decimals = Number.isInteger( options.decimals )
		? options.decimals
		: 2;
	const fixed = ( Math.max( 0, Number( cents ) || 0 ) / 100 ).toFixed(
		decimals
	);
	const [ whole, fraction ] = fixed.split( '.' );
	const grouped = group( whole, options.thousandSeparator ?? ',' );

	return fraction
		? `${ grouped }${ options.decimalSeparator ?? '.' }${ fraction }`
		: grouped;
};

/**
 * Whole numbers with thousand separators, e.g. 1250 -> "1,250".
 *
 * @param {number} value     Number.
 * @param {string} separator Thousand separator.
 * @return {string} Formatted number.
 */
export const formatNumber = ( value, separator = ',' ) =>
	group(
		String( Math.max( 0, Math.floor( Number( value ) || 0 ) ) ),
		separator
	);

/**
 * Cents a number of points is worth, rounded down (like the server and PointsMath).
 *
 * @param {number} points        Points.
 * @param {string} valuePerPoint Value of one point in major units ("0.01").
 * @return {number} Cents.
 */
export const pointsValueCents = ( points, valuePerPoint ) => {
	const match = /^(\d+)(?:\.(\d+))?$/.exec( String( valuePerPoint || '' ) );
	if ( ! match ) {
		return 0;
	}
	// Major units as integer micro-cents: 2 digits for cents, 6 for precision.
	const fraction = ( match[ 2 ] || '' ).padEnd( 8, '0' ).slice( 0, 8 );
	const micro = Number( match[ 1 ] ) * 100000000 + Number( fraction );

	return Math.floor(
		( Math.max( 0, Number( points ) || 0 ) * micro ) / 1000000
	);
};
