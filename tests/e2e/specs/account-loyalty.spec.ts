/**
 * My Account › Loyalty in a real browser: the member card in the portal's colours, and "View all rewards" opening the
 * member portal signed in through the store's handler (the sign-in link is minted at the click, never printed).
 *
 * Needs tests/e2e/bin/setup-checkout.php: the logged-in wp-env admin is a verified Pixalink member (customer 5001) and
 * the cached heartbeat carries the fake portal's branding. The fake portal host does not resolve, so the spec lets the
 * store's handler answer for real, reads its 303 (where it sends the browser, and the headers it sends with it), and
 * shows a stand-in portal page instead of following the redirect.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

type Recorded = {
	method: string;
	path: string;
	status: number;
	body: { wp_user_id?: number; destination?: string };
};

const PORTAL = 'https://fake-pixalink.test/l/fake-cafe';

test.describe( 'My Account › Loyalty', () => {
	test( 'shows the member card and opens the portal signed in from "View all rewards"', async ( {
		page,
		context,
		requestUtils,
	} ) => {
		await requestUtils.rest( {
			method: 'DELETE',
			path: '/fake-pixalink/v1/requests',
		} );
		const handoffs: Array< {
			status: number;
			headers: Record< string, string >;
		} > = [];
		await context.route(
			'**/wp-admin/admin-post.php?**',
			async ( route ) => {
				const response = await route.fetch( { maxRedirects: 0 } );
				handoffs.push( {
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
		await page
			.getByRole( 'navigation' )
			.getByRole( 'link', { name: 'Loyalty' } )
			.click();

		const card = page.getByRole( 'region', { name: 'Your membership' } );
		await expect( card ).toBeVisible();
		await expect( card ).toContainText( '1,250' );
		await expect( card ).toContainText( '15.00 credits' );
		await expect( card ).toContainText( 'Silver' );
		await expect( card.getByRole( 'progressbar' ) ).toHaveAttribute(
			'aria-valuenow',
			'70'
		);
		await expect( page.locator( '.pixalink-loyalty-account' ) ).toHaveClass(
			/is-branded/
		);
		// The portal's colour reaches the card through the scoped custom property.
		await expect( card ).toHaveCSS( 'background-color', 'rgb(15, 92, 86)' );

		// The member code stays behind its toggle until asked for.
		const toggle = card.getByRole( 'button', { name: 'Show member code' } );
		await expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		await expect(
			page.locator( '#pixalink-loyalty-member-code' )
		).toBeHidden();
		await toggle.click();
		await expect(
			card.getByRole( 'button', { name: 'Hide member code' } )
		).toHaveAttribute( 'aria-expanded', 'true' );
		await expect(
			page.locator( '#pixalink-loyalty-member-code svg' )
		).toBeVisible();

		const rewards = page.getByRole( 'link', { name: 'View all rewards' } );
		await expect( rewards ).toHaveAttribute( 'target', '_blank' );
		await expect( rewards ).toHaveAttribute(
			'href',
			/admin-post\.php\?action=pixalink_loyalty_open_portal&destination=rewards&_wpnonce=/
		);
		expect( await page.content() ).not.toContain( '/handoff/' );

		const [ portal ] = await Promise.all( [
			context.waitForEvent( 'page' ),
			rewards.click(),
		] );
		await expect( portal.getByRole( 'heading' ) ).toHaveText(
			'Fake portal'
		);

		expect( handoffs ).toHaveLength( 1 );
		expect( handoffs[ 0 ].status ).toBe( 303 );
		expect( handoffs[ 0 ].headers.location ).toMatch(
			new RegExp( `^${ PORTAL }/handoff/[0-9a-f]+$` )
		);
		expect( handoffs[ 0 ].headers[ 'referrer-policy' ] ).toBe(
			'no-referrer'
		);
		expect( handoffs[ 0 ].headers[ 'cache-control' ] ).toContain(
			'no-store'
		);

		const recorded = ( await requestUtils.rest( {
			path: '/fake-pixalink/v1/requests',
		} ) ) as { requests: Recorded[] };
		const sessions = recorded.requests.filter( ( request ) =>
			request.path.endsWith( '/portal-session' )
		);
		expect( sessions ).toHaveLength( 1 );
		expect( sessions[ 0 ].status ).toBe( 201 );
		expect( sessions[ 0 ].body.destination ).toBe( 'rewards' );
	} );
} );
