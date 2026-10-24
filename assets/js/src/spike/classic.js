/**
 * SPIKE (P02, throwaway): classic checkout panel.
 *
 * POSTs the same `{ namespace, data }` body as Blocks' extensionCartUpdate() to /wc/store/v1/cart/extensions
 * (one server callback for both checkouts), then lets WooCommerce refresh the review table via `update_checkout`.
 */
const config = window.pixalinkLoyaltySpike;

const send = async ( data ) => {
	const response = await window.fetch( config.endpoint, {
		method: 'POST',
		credentials: 'same-origin',
		// The Store API's own CSRF nonce is enough: its rest_authentication_errors callback keeps cookie auth without X-WP-Nonce.
		headers: { 'Content-Type': 'application/json', Nonce: config.nonce },
		body: JSON.stringify( { namespace: config.namespace, data } ),
	} );
	config.nonce = response.headers.get( 'Nonce' ) || config.nonce;
	const body = await response.json();
	if ( ! response.ok ) {
		throw new Error( body.message || 'Error' );
	}
	return body;
};

document.addEventListener( 'click', ( event ) => {
	const button = event.target.closest( '[data-pixalink-action]' );
	if ( ! button ) {
		return;
	}
	event.preventDefault();

	const panel = button.closest( '.pixalink-loyalty-spike-classic' );
	const error = panel.querySelector( '.pixalink-spike-error' );
	const action = button.dataset.pixalinkAction;
	const data = { action };
	if ( 'set_points' === action ) {
		data.points = Number(
			panel.querySelector( '#pixalink-spike-points' ).value
		);
	}
	if ( button.dataset.code ) {
		data.code = button.dataset.code;
	}

	error.textContent = '';
	send( data )
		.then( () => {
			if ( 'spike_link' === action ) {
				window.location.reload();
				return;
			}
			window.jQuery( document.body ).trigger( 'update_checkout' );
		} )
		.catch( ( exception ) => {
			error.textContent = exception.message;
		} );
} );
