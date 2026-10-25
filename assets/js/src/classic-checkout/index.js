/**
 * Classic (shortcode) checkout loyalty panel.
 *
 * Every action POSTs `{ namespace, data }` to /wc/store/v1/cart/extensions with only the Store API `Nonce` header
 * (the same callback the Checkout block reaches through `extensionCartUpdate()`), then triggers `update_checkout`:
 * WooCommerce recalculates, re-renders the review table (whose coupon rows carry our labels) and, through the order
 * review fragments, this panel. A stale quote (none yet, expired, another cart) is refreshed on load and after each
 * `updated_checkout`.
 */
import {
	formatCents,
	formatNumber,
	pointsValueCents,
	fill,
} from '../shared/format';

/**
 * Refreshes per page view that are not started by the shopper (stops a loop if Pixalink keeps answering stale).
 */
const MAX_AUTOMATIC_REFRESHES = 3;

const config = window.pixalinkLoyaltyClassic;
const $ = window.jQuery;

/**
 * POST one action to the Store API.
 *
 * @param {Object} data Action data.
 * @return {Promise<Object>} Cart response.
 */
const send = async ( data ) => {
	const response = await window.fetch( config.endpoint, {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/json', Nonce: config.nonce },
		body: JSON.stringify( { namespace: config.namespace, data } ),
	} );
	config.nonce = response.headers.get( 'Nonce' ) || config.nonce;
	const body = await response.json().catch( () => ( {} ) );
	if ( ! response.ok ) {
		throw new Error( body.message || config.strings.error );
	}
	return body;
};

/**
 * The panel element (re-rendered by the fragments, so never cached).
 *
 * @return {HTMLElement|null} Panel.
 */
const panel = () => document.querySelector( '[data-pixalink-panel]' );

/**
 * Show or clear the panel's error.
 *
 * @param {string} message Message ('' clears).
 */
const showError = ( message ) => {
	const element = panel()?.querySelector( '[data-pixalink-error]' );
	if ( element ) {
		element.textContent = message;
	}
};

/**
 * A price in the store currency.
 *
 * @param {number} cents Hundredths.
 * @return {string} Formatted price.
 */
const money = ( cents ) => {
	const currency = config.currency || {};
	const amount = formatCents( cents, {
		decimals: Math.min( 2, currency.decimals ?? 2 ),
		decimalSeparator: currency.decimal_separator ?? '.',
		thousandSeparator: currency.thousand_separator ?? ',',
	} );
	return fill( currency.format || '%1$s%2$s', currency.symbol || '', amount );
};

/**
 * Update the points preview ("1,000 points = $10.00 off").
 *
 * @param {HTMLElement} root Points section.
 */
const preview = ( root ) => {
	const input = root.querySelector( '[data-pixalink-points-input]' );
	const output = root.querySelector( '[data-pixalink-points-preview]' );
	if ( ! input || ! output ) {
		return;
	}
	const points = Math.max( 0, Number( input.value ) || 0 );
	output.textContent = fill(
		config.strings.points_preview,
		formatNumber( points ),
		money( pointsValueCents( points, root.dataset.valuePerPoint ) )
	);
};

/**
 * Run an action, then let WooCommerce refresh the checkout.
 *
 * @param {Object} data Action data.
 * @return {Promise<void>} Done.
 */
const run = ( data ) => {
	const root = panel();
	root?.classList.add( 'is-busy' );
	showError( '' );

	return send( data )
		.then( () => {
			$( document.body ).trigger( 'update_checkout' );
		} )
		.catch( ( exception ) => {
			showError( exception.message || config.strings.error );
		} )
		.finally( () => {
			panel()?.classList.remove( 'is-busy' );
		} );
};

/**
 * The action data a panel button stands for.
 *
 * @param {HTMLElement} button Button.
 * @param {HTMLElement} root   Panel.
 * @return {Object|null} Action data.
 */
const actionFor = ( button, root ) => {
	const action = button.dataset.pixalinkAction;
	switch ( action ) {
		case 'set_points':
			return {
				action,
				points:
					Number(
						root.querySelector( '[data-pixalink-points-input]' )
							?.value
					) || 0,
			};
		case 'remove_points':
			return { action: 'set_points', points: 0 };
		case 'set_credits': {
			const raw =
				root.querySelector( '[data-pixalink-credits-input]' )?.value ||
				'0';
			return {
				action,
				amount_cents:
					Math.round( parseFloat( raw.replace( ',', '.' ) ) * 100 ) ||
					0,
			};
		}
		case 'remove_credits':
			return { action: 'set_credits', amount_cents: 0 };
		case 'apply_voucher':
		case 'remove_voucher':
			return { action, code: button.dataset.code || '' };
	}
	return null;
};

let automaticRefreshes = 0;
let refreshing = false;

/**
 * Ask for a fresh quote when the server marked the panel stale.
 */
const maybeRefresh = () => {
	const root = panel();
	if (
		! root ||
		'1' !== root.dataset.verified ||
		'1' !== root.dataset.stale ||
		refreshing ||
		automaticRefreshes >= MAX_AUTOMATIC_REFRESHES
	) {
		return;
	}

	refreshing = true;
	automaticRefreshes++;
	send( { action: 'refresh_quote' } )
		.then( () => $( document.body ).trigger( 'update_checkout' ) )
		.catch( () => {} )
		.finally( () => {
			refreshing = false;
		} );
};

if ( config && $ ) {
	document.addEventListener( 'click', ( event ) => {
		const button =
			event.target instanceof window.Element
				? event.target.closest( '[data-pixalink-action]' )
				: null;
		const root = button?.closest( '[data-pixalink-panel]' );
		if ( ! button || ! root ) {
			return;
		}
		event.preventDefault();

		const data = actionFor( button, root );
		if ( data ) {
			// The shopper acted: automatic refreshes may run again afterwards.
			automaticRefreshes = 0;
			run( data );
		}
	} );

	document.addEventListener( 'input', ( event ) => {
		const target =
			event.target instanceof window.Element ? event.target : null;
		const root = target?.closest( '[data-pixalink-points]' );
		if ( ! root ) {
			return;
		}
		const slider = root.querySelector( '[data-pixalink-points-slider]' );
		const input = root.querySelector( '[data-pixalink-points-input]' );
		if ( target === slider && input ) {
			input.value = slider.value;
		} else if ( target === input && slider ) {
			slider.value = input.value;
		}
		preview( root );
	} );

	const refreshView = () => {
		document
			.querySelectorAll( '[data-pixalink-points]' )
			.forEach( preview );
		maybeRefresh();
	};

	$( document.body ).on( 'updated_checkout', refreshView );
	refreshView();
}
