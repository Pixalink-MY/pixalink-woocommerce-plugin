/**
 * "Sign in with the member portal" on the My Account login form, for a logged-out shopper.
 *
 * Needs tests/e2e/bin/setup-checkout.php: the store is connected and the cached heartbeat carries the fake portal's
 * branding, including its sign-in page. The fake portal host does not resolve, so the spec lets the store's start
 * handler answer for real, reads its 303 and the state cookie it sets, and shows a stand-in portal page instead.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

const SIGN_IN_URL =
	'https://fake-pixalink.test/l/fake-cafe/store-sign-in/9a3c1f0e-6b1d-4c39-9f7e-0c2b5d6a7e81';

test.describe( 'Sign in with the member portal', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	test( 'the My Account login form offers it and it starts at the portal', async ( {
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
		const location = new URL( starts[ 0 ].headers.location );
		expect( `${ location.origin }${ location.pathname }` ).toBe(
			SIGN_IN_URL
		);
		expect( location.searchParams.get( 'return' ) ).toBe(
			new URL( '/my-account/', page.url() ).toString()
		);
		const state = location.searchParams.get( 'state' ) ?? '';
		expect( state ).toMatch( /^[A-Za-z0-9_-]{43,}$/ );
		const cookie = starts[ 0 ].headers[ 'set-cookie' ] ?? '';
		expect( cookie ).toContain( `pixalink_loyalty_sso_state=${ state }` );
		expect( cookie ).toMatch( /HttpOnly/i );
		expect( cookie ).toMatch( /SameSite=Lax/i );
		expect( starts[ 0 ].headers[ 'cache-control' ] ).toContain(
			'no-store'
		);
	} );
} );
