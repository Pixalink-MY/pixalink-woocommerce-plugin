/**
 * WooCommerce › Settings › Pixalink Loyalty: confirm and copy interactions for the connection card.
 *
 * The card lives inside WooCommerce's settings form and submits to admin-post.php via `formaction`, so:
 * - `[data-pixalink-confirm]` buttons ask before submitting;
 * - any card button clears WooCommerce's "unsaved changes" prompt (it only expects its own Save button);
 * - Enter in the API key field submits the key, not the first button in the form;
 * - `[data-pixalink-copy]` copies its value to the clipboard.
 */
const CARD = '.pixalink-loyalty-card';

document.addEventListener( 'click', ( event ) => {
	const target = event.target instanceof window.Element ? event.target : null;
	if ( ! target ) {
		return;
	}

	const button = target.closest( `${ CARD } [data-pixalink-action]` );
	if ( button ) {
		const question = button.getAttribute( 'data-pixalink-confirm' );
		// eslint-disable-next-line no-alert -- A native confirm keeps the destructive action explicit without a modal library.
		if ( question && ! window.confirm( question ) ) {
			event.preventDefault();
			return;
		}

		window.onbeforeunload = null;
	}

	const copy = target.closest( '[data-pixalink-copy]' );
	if ( copy && window.navigator.clipboard ) {
		event.preventDefault();
		window.navigator.clipboard
			.writeText( copy.getAttribute( 'data-pixalink-copy' ) || '' )
			.catch( () => {} );
	}
} );

document.addEventListener( 'keydown', ( event ) => {
	const field = event.target instanceof window.Element ? event.target : null;
	if (
		event.key !== 'Enter' ||
		! field ||
		! field.matches( `${ CARD } [data-pixalink-submit]` )
	) {
		return;
	}

	const action = field.getAttribute( 'data-pixalink-submit' );
	const submit = document.querySelector(
		`${ CARD } [data-pixalink-action="${ action }"]`
	);
	if ( submit ) {
		event.preventDefault();
		submit.click();
	}
} );
