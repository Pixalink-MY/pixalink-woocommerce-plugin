/**
 * "Sign in with the member portal" on the My Account login form, for a logged-out shopper.
 *
 * Needs tests/e2e/bin/setup-checkout.php: the store is connected and the heartbeat it ran advertises the fake portal's
 * OAuth endpoints, so the store provisioned its OAuth client. The fake portal host does not resolve, so the spec lets
 * the store's start handler answer for real, reads its 303 and the cookie it sets, and shows a stand-in portal page
 * instead. It then comes back to the store's callback the way the portal would, with a code the fake token endpoint
 * exchanges for a new member's token.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const AUTHORIZE_URL = 'https://fake-pixalink.test/l/fake-cafe/oauth/authorize';
const CLIENT_ID = '9f1c2b7e-3d4a-4c5b-8e6f-7a8b9c0d1e2f';

test.describe( 'Sign in with the member portal', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	test( 'the My Account login form offers it, it starts at the authorize page and the member comes back signed in', async ( {
		page,
		context,
	} ) => {
		const starts: Array< {
			status: number;
			headers: Record< string, string >;
		} > = [];
		await context.route(
			'**/wp-admin/admin-post.php?**',
			async ( route ) => {
				const response = await route.fetch( { maxRedirects: 0 } );
				starts.push( {
					status: response.status(),
					headers: response.headers(),
				} );
				await route.fulfill( {
					status: 200,
					contentType: 'text/html',
					body: '<!doctype html><title>Fake portal</title><h1>Fake portal</h1>',
				} );
			}
		);

		await page.goto( '/my-account/' );

		const form = page.locator( 'form.woocommerce-form-login' );
		const button = form.getByRole( 'link', {
			name: 'Sign in from Customer Portal',
		} );
		await expect( button ).toBeVisible();
		await expect( form.locator( '.pixalink-loyalty-sign-in' ) ).toHaveClass(
			/is-branded/
		);
		await expect( button ).toHaveCSS(
			'background-color',
			'rgb(15, 92, 86)'
		);

		const href = new URL(
			( await button.getAttribute( 'href' ) ) ?? '',
			page.url()
		);
		expect( href.pathname ).toBe( '/wp-admin/admin-post.php' );
		expect( href.searchParams.get( 'action' ) ).toBe(
			'pixalink_loyalty_portal_sign_in'
		);
		expect( href.searchParams.get( 'redirect_to' ) ).toBe(
			new URL( '/my-account/', page.url() ).toString()
		);

		await button.click();
		await expect(
			page.getByRole( 'heading', { name: 'Fake portal' } )
		).toBeVisible();

		expect( starts ).toHaveLength( 1 );
		expect( starts[ 0 ].status ).toBe( 303 );
		expect( starts[ 0 ].headers[ 'cache-control' ] ).toContain(
			'no-store'
		);
		expect( starts[ 0 ].headers[ 'referrer-policy' ] ).toBe(
			'no-referrer'
		);

		// OAuth 2.1 authorization request: code flow, S256 PKCE, the store's callback, both scopes.
		const location = new URL( starts[ 0 ].headers.location );
		expect( `${ location.origin }${ location.pathname }` ).toBe(
			AUTHORIZE_URL
		);
		const query = Object.fromEntries( location.searchParams.entries() );
		expect( Object.keys( query ) ).toEqual( [
			'response_type',
			'client_id',
			'redirect_uri',
			'scope',
			'state',
			'code_challenge',
			'code_challenge_method',
		] );
		expect( query.response_type ).toBe( 'code' );
		expect( query.client_id ).toBe( CLIENT_ID );
		expect( query.redirect_uri ).toBe(
			new URL( '/?pixalink_oauth=callback', page.url() ).toString()
		);
		expect( query.scope ).toBe( 'profile store:link' );
		expect( query.state ).toMatch( /^[A-Za-z0-9_-]{43}$/ );
		expect( query.code_challenge ).toMatch( /^[A-Za-z0-9_-]{43}$/ );
		expect( query.code_challenge_method ).toBe( 'S256' );

		// The verifier and state stay in this browser only, encrypted.
		const cookie = starts[ 0 ].headers[ 'set-cookie' ] ?? '';
		expect( cookie ).toMatch( /^pixalink_loyalty_oauth=pxl1/ );
		expect( cookie ).toMatch( /HttpOnly/i );
		expect( cookie ).toMatch( /SameSite=Lax/i );
		expect( cookie ).not.toContain( query.state );
		const value = cookie
			.split( ';' )[ 0 ]
			.split( '=' )
			.slice( 1 )
			.join( '=' );
		await context.addCookies( [
			{
				name: 'pixalink_loyalty_oauth',
				value,
				url: new URL( '/', page.url() ).toString(),
			},
		] );

		// The portal sends the member back with a code: a new member, so the store creates their account.
		await page.goto(
			`/?pixalink_oauth=callback&code=fake-oauth-new-e2e&state=${ encodeURIComponent(
				query.state
			) }`
		);

		await expect( page ).toHaveURL( /\/my-account\/$/ );
		await expect(
			page
				.locator( '.woocommerce-MyAccount-navigation' )
				.getByRole( 'link', { name: 'Log out' } )
		).toBeVisible();
	} );
} );
