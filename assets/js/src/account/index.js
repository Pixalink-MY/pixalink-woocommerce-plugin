/**
 * My Account › Loyalty: the phone verification dialog and the member QR code.
 *
 * Talks to the plugin's REST routes (`pixalink-loyalty/v1/link`, `otp/send`, `otp/verify`) with the `wp_rest`
 * nonce printed in `window.pixalinkLoyaltyAccount`. The QR code is drawn locally with qrcode-generator (MIT);
 * nothing is sent to an image service.
 */
import qrcode from 'qrcode-generator';

const settings = window.pixalinkLoyaltyAccount || {
	restUrl: '',
	nonce: '',
	i18n: {},
};
const i18n = settings.i18n || {};

/**
 * Replace the first %s or %d in a translated string.
 *
 * @param {string}        text  Translated text.
 * @param {string|number} value Value.
 * @return {string} Text.
 */
const format = ( text, value ) =>
	String( text || '' ).replace( /%[sd]/, String( value ) );

/**
 * POST to a plugin route.
 *
 * @param {string} route Route below the namespace, e.g. "otp/verify".
 * @param {Object} body  JSON body.
 * @return {Promise<{ok: boolean, status: number, data: Object}>} Result.
 */
const post = async ( route, body = {} ) => {
	const response = await window.fetch( settings.restUrl + route, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': settings.nonce,
		},
		body: JSON.stringify( body ),
	} );
	const data = await response.json().catch( () => ( {} ) );

	return { ok: response.ok, status: response.status, data };
};

/**
 * Draw every QR placeholder.
 */
const renderQrCodes = () => {
	document.querySelectorAll( '[data-pixalink-qr]' ).forEach( ( node ) => {
		const value = node.getAttribute( 'data-pixalink-qr' );
		if ( ! value || node.querySelector( 'svg' ) ) {
			return;
		}

		const code = qrcode( 0, 'M' );
		code.addData( value );
		code.make();
		node.innerHTML = code.createSvgTag( {
			cellSize: 6,
			margin: 24,
			scalable: true,
			alt: i18n.qrLabel || '',
		} );
	} );
};

/**
 * Wire one verification block.
 *
 * @param {HTMLElement} root `[data-pixalink-link]` element.
 */
const setUpLink = ( root ) => {
	const form = root.querySelector( '[data-pixalink-link-form]' );
	const dialog = root.querySelector( '[data-pixalink-otp-dialog]' );
	if ( ! form || ! dialog ) {
		return;
	}

	const message = root.querySelector( '[data-pixalink-message]' );
	const otpForm = dialog.querySelector( '[data-pixalink-otp-form]' );
	const sent = dialog.querySelector( '[data-pixalink-otp-sent]' );
	const otpMessage = dialog.querySelector( '[data-pixalink-otp-message]' );
	const resend = dialog.querySelector( '[data-pixalink-otp-resend]' );
	const close = dialog.querySelector( '[data-pixalink-otp-close]' );
	const codeInput = dialog.querySelector( 'input[name="code"]' );
	let timer = null;

	const say = ( node, text ) => {
		if ( node ) {
			node.textContent = text || '';
		}
	};

	const countdown = ( seconds ) => {
		window.clearInterval( timer );
		let left = Math.max( 0, parseInt( seconds, 10 ) || 0 );
		const tick = () => {
			resend.disabled = left > 0;
			resend.textContent =
				left > 0 ? format( i18n.resendIn, left ) : i18n.resend;
			left -= 1;
			if ( left < 0 ) {
				window.clearInterval( timer );
			}
		};
		tick();
		timer = window.setInterval( tick, 1000 );
	};

	const opened = ( data ) => {
		say(
			sent,
			format(
				'sms' === data.sent_via ? i18n.sentSms : i18n.sentWhatsapp,
				data.phone_masked || ''
			)
		);
		say( otpMessage, '' );
		countdown( data.retry_after_seconds );
		if ( ! dialog.open ) {
			dialog.showModal();
		}
		codeInput.focus();
	};

	const failed = ( node, result ) => {
		say( node, ( result.data && result.data.message ) || i18n.failed );
		if ( result.data && result.data.data && result.data.data.retry_after ) {
			countdown( result.data.data.retry_after );
		}
	};

	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		say( message, '' );
		const result = await post( 'link', {
			phone_country: form.elements.phone_country.value,
			phone: form.elements.phone.value,
		} ).catch( () => ( { ok: false, status: 0, data: {} } ) );

		if ( result.ok ) {
			opened( result.data );
			return;
		}
		failed( message, result );
	} );

	otpForm.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		say( otpMessage, '' );
		const result = await post( 'otp/verify', {
			code: codeInput.value.trim(),
		} ).catch( () => ( { ok: false, status: 0, data: {} } ) );

		if ( result.ok ) {
			say( otpMessage, i18n.verified );
			window.location.reload();
			return;
		}
		failed( otpMessage, result );
		codeInput.select();
	} );

	resend.addEventListener( 'click', async () => {
		resend.disabled = true;
		const result = await post( 'otp/send' ).catch( () => ( {
			ok: false,
			status: 0,
			data: {},
		} ) );
		if ( result.ok ) {
			opened( result.data );
			return;
		}
		failed( otpMessage, result );
		if ( 409 === result.status ) {
			dialog.close();
		}
	} );

	close.addEventListener( 'click', () => {
		window.clearInterval( timer );
		dialog.close();
	} );

	// A code sent in the last 15 minutes: let the shopper type it after a reload.
	if ( dialog.hasAttribute( 'data-pixalink-pending' ) ) {
		resend.disabled = false;
		resend.textContent = i18n.resend;
		dialog.showModal();
	}
};

const init = () => {
	renderQrCodes();
	document.querySelectorAll( '[data-pixalink-link]' ).forEach( setUpLink );
};

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
