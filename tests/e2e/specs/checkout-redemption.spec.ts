/**
 * Vouchers and points in a real browser, in the Checkout block and the classic checkout: the panel, the chip and
 * review-row labels, the totals, holds placed at submit (BACS), a refused hold and its retry, and the holds in the
 * order meta box.
 *
 * Needs tests/e2e/bin/setup-checkout.php (see the e2e job in .github/workflows/ci.yml). The shopper is the logged-in
 * wp-env admin: a verified Pixalink member (customer 5001, 1,250 points on the fake server) with a saved address.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import type { Page } from '@playwright/test';

type RequestUtils = {
	rest: ( options: Record< string, unknown > ) => Promise< unknown >;
};

type Recorded = {
	method: string;
	path: string;
	status: number;
	body: { redemptions?: Array< Record< string, unknown > > };
};

const REFUSED = 'Your Pixalink points balance changed';

const productId = async ( requestUtils: RequestUtils, sku: string ) => {
	const products = ( await requestUtils.rest( {
		path: '/wc/store/v1/products',
		params: { sku },
	} ) ) as Array< { id: number } >;
	expect( products ).toHaveLength( 1 );
	return products[ 0 ].id;
};

const holds = async ( requestUtils: RequestUtils ) => {
	const recorded = ( await requestUtils.rest( {
		path: '/fake-pixalink/v1/requests',
	} ) ) as { requests: Recorded[] };
	return recorded.requests.filter(
		( request ) =>
			'PUT' === request.method && request.path.startsWith( 'orders/' )
	);
};

const setBalance = ( requestUtils: RequestUtils, points: number ) =>
	requestUtils.rest( {
		method: 'POST',
		path: '/fake-pixalink/v1/balance',
		data: { points },
	} );

/**
 * Forget the shopper's stored quote, empty their cart through the Store API (which also drops our selection) and
 * add one product A.
 *
 * @param page         Page.
 * @param requestUtils REST helpers.
 */
const freshCart = async ( page: Page, requestUtils: RequestUtils ) => {
	await requestUtils.rest( {
		method: 'DELETE',
		path: '/fake-pixalink/v1/requests',
	} );
	// The last test's quote is still fresh for its TTL; start from a shopper with none.
	await requestUtils.rest( {
		method: 'DELETE',
		path: '/pixalink-e2e/v1/shopper',
	} );
	const id = await productId( requestUtils, 'PXL-A' );

	await page.goto( '/' );
	await page.evaluate( async ( product ) => {
		const base = '/?rest_route=/wc/store/v1';
		const cart = await window.fetch( `${ base }/cart`, {
			credentials: 'same-origin',
		} );
		const nonce = cart.headers.get( 'Nonce' ) || '';
		const headers = { 'Content-Type': 'application/json', Nonce: nonce };
		await window.fetch( `${ base }/cart/items`, {
			method: 'DELETE',
			credentials: 'same-origin',
			headers,
		} );
		await window.fetch( `${ base }/cart/add-item`, {
			method: 'POST',
			credentials: 'same-origin',
			headers,
			body: JSON.stringify( { id: product, quantity: 1 } ),
		} );
	}, id );
};

/**
 * The order id from the order-received URL.
 *
 * @param page Page.
 * @return Order id.
 */
const orderIdFromUrl = ( page: Page ) => {
	const match = /order-received\/(\d+)/.exec( page.url() );
	expect( match ).not.toBeNull();
	return Number( match?.[ 1 ] );
};

/**
 * Open the order edit screen (HPOS first, posts storage otherwise) and return the Pixalink meta box.
 *
 * @param page Page.
 * @param id   Order id.
 */
const orderMetaBox = async ( page: Page, id: number ) => {
	await page.goto(
		`/wp-admin/admin.php?page=wc-orders&action=edit&id=${ id }`
	);
	let box = page.locator( '#pixalink-loyalty-order' );
	if ( 0 === ( await box.count() ) ) {
		await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );
		box = page.locator( '#pixalink-loyalty-order' );
	}
	await expect( box ).toBeVisible();
	return box;
};

test.beforeEach( async ( { requestUtils } ) => {
	await setBalance( requestUtils, 1250 );
} );

test.describe( 'Checkout block', () => {
	test( 'applies a voucher and points, relabels the chips and holds both at submit', async ( {
		page,
		requestUtils,
	}, testInfo ) => {
		await freshCart( page, requestUtils );
		await page.goto( '/checkout/' );

		const panel = page.getByTestId( 'pixalink-loyalty-panel' );
		await expect( panel ).toBeVisible();
		await expect(
			panel.getByText( 'You have 1,250 points · 25.00 credits' )
		).toBeVisible( { timeout: 15000 } );

		await panel
			.getByRole( 'button', { name: 'Apply Welcome 10 off' } )
			.click();
		await expect(
			panel.getByRole( 'button', { name: 'Remove Welcome 10 off' } )
		).toBeVisible();

		await panel
			.getByRole( 'spinbutton', { name: 'Points to use' } )
			.fill( '1000' );
		await expect(
			panel.getByText( '1,000 points = $10.00 off' )
		).toBeVisible();
		await panel.getByRole( 'button', { name: 'Use points' } ).click();

		// Chips read "Coupon: <label>"; neither shows an internal pxl- code.
		await expect(
			page
				.getByRole( 'listitem' )
				.filter( { hasText: 'Points (1,000 pts)' } )
		).toBeVisible();
		await expect(
			page
				.getByRole( 'listitem' )
				.filter( { hasText: 'Voucher: Welcome 10 off' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'listitem' ).filter( { hasText: /pxl-/i } )
		).toHaveCount( 0 );
		await expect(
			page.locator( '.wc-block-components-totals-footer-item' )
		).toContainText( '$80.00' );
		await page.screenshot( {
			path: testInfo.outputPath( 'blocks-checkout.png' ),
			fullPage: true,
		} );

		await page.getByRole( 'button', { name: /place order/i } ).click();
		await expect( page ).toHaveURL( /order-received/, { timeout: 30000 } );
		await expect( page.getByText( 'Rewards used' ).first() ).toBeVisible();

		const placed = await holds( requestUtils );
		expect( placed.at( -1 )?.status ).toBe( 200 );
		expect( placed.at( -1 )?.body.redemptions ).toHaveLength( 2 );

		const box = await orderMetaBox( page, orderIdFromUrl( page ) );
		await expect( box ).toContainText( 'Held until' );
		await expect( box ).toContainText( 'Points (1,000 pts)' );
		await expect( box ).toContainText( 'Voucher: Welcome 10 off' );
		await expect( box.locator( 'code', { hasText: /^CH-/ } ) ).toHaveCount(
			2
		);
	} );

	test( 'a refused hold shows our notice, keeps the shopper on checkout and the retry succeeds', async ( {
		page,
		requestUtils,
	}, testInfo ) => {
		await freshCart( page, requestUtils );
		await page.goto( '/checkout/' );

		const panel = page.getByTestId( 'pixalink-loyalty-panel' );
		await expect( panel.getByText( /You have 1,250 points/ ) ).toBeVisible(
			{
				timeout: 15000,
			}
		);
		await panel
			.getByRole( 'spinbutton', { name: 'Points to use' } )
			.fill( '1200' );
		await panel.getByRole( 'button', { name: 'Use points' } ).click();
		await expect(
			page
				.getByRole( 'listitem' )
				.filter( { hasText: 'Points (1,200 pts)' } )
		).toBeVisible();

		// The member spends points elsewhere before submitting.
		await setBalance( requestUtils, 1000 );
		await page.getByRole( 'button', { name: /place order/i } ).click();
		await expect( page.getByText( REFUSED ).first() ).toBeVisible( {
			timeout: 30000,
		} );
		await expect( page ).not.toHaveURL( /order-received/ );
		expect( ( await holds( requestUtils ) ).at( -1 )?.status ).toBe( 409 );
		await expect(
			page
				.getByRole( 'listitem' )
				.filter( { hasText: 'Points (1,000 pts)' } )
		).toBeVisible( { timeout: 15000 } );
		await expect(
			page.locator( '.wc-block-components-totals-footer-item' )
		).toContainText( '$90.00' );
		await page.screenshot( {
			path: testInfo.outputPath( 'blocks-hold-refused.png' ),
			fullPage: true,
		} );

		await page.getByRole( 'button', { name: /place order/i } ).click();
		await expect( page ).toHaveURL( /order-received/, { timeout: 30000 } );
		const placed = await holds( requestUtils );
		expect( placed.at( -1 )?.status ).toBe( 200 );
		expect( placed.at( -1 )?.body.redemptions?.[ 0 ]?.points ).toBe( 1000 );
	} );
} );

test.describe( 'Classic checkout', () => {
	test( 'applies a voucher and points through the Store API and holds both at submit', async ( {
		page,
		requestUtils,
	}, testInfo ) => {
		await freshCart( page, requestUtils );
		await page.goto( '/classic-checkout/' );

		const panel = page.getByTestId( 'pixalink-loyalty-panel' );
		await expect(
			panel.getByText( 'You have 1,250 points · 25.00 credits' )
		).toBeVisible( { timeout: 15000 } );
		const review = page.locator(
			'.woocommerce-checkout-review-order-table'
		);

		await panel
			.getByRole( 'button', { name: 'Apply Welcome 10 off' } )
			.click();
		await expect(
			review.locator( 'tr.cart-discount', {
				hasText: 'Voucher: Welcome 10 off',
			} )
		).toBeVisible( { timeout: 15000 } );

		await page
			.getByTestId( 'pixalink-loyalty-panel' )
			.getByRole( 'spinbutton', { name: 'Points to use' } )
			.fill( '1000' );
		await expect(
			page
				.getByTestId( 'pixalink-loyalty-panel' )
				.getByText( '1,000 points = $10.00 off' )
		).toBeVisible();
		await page
			.getByTestId( 'pixalink-loyalty-panel' )
			.getByRole( 'button', { name: 'Use points' } )
			.click();
		await expect(
			review.locator( 'tr.cart-discount', {
				hasText: 'Points (1,000 pts)',
			} )
		).toBeVisible( { timeout: 15000 } );
		await expect( review.locator( 'tr.order-total' ) ).toContainText(
			'$80.00'
		);
		await expect( review ).not.toContainText( 'pxl-' );
		await page.screenshot( {
			path: testInfo.outputPath( 'classic-checkout.png' ),
			fullPage: true,
		} );

		await page.locator( '#place_order' ).click();
		await expect( page ).toHaveURL( /order-received/, { timeout: 30000 } );

		const placed = await holds( requestUtils );
		expect( placed.at( -1 )?.status ).toBe( 200 );
		expect( placed.at( -1 )?.body.redemptions ).toHaveLength( 2 );

		const box = await orderMetaBox( page, orderIdFromUrl( page ) );
		await expect( box ).toContainText( 'Held until' );
		await expect( box.locator( 'code', { hasText: /^CH-/ } ) ).toHaveCount(
			2
		);
	} );

	test( 'a refused hold aborts with our notice and no order', async ( {
		page,
		requestUtils,
	} ) => {
		await freshCart( page, requestUtils );
		await page.goto( '/classic-checkout/' );
		const panel = page.getByTestId( 'pixalink-loyalty-panel' );
		await expect( panel.getByText( /You have 1,250 points/ ) ).toBeVisible(
			{
				timeout: 15000,
			}
		);
		await panel
			.getByRole( 'spinbutton', { name: 'Points to use' } )
			.fill( '1200' );
		await panel.getByRole( 'button', { name: 'Use points' } ).click();
		await expect(
			page.locator( 'tr.cart-discount', {
				hasText: 'Points (1,200 pts)',
			} )
		).toBeVisible( { timeout: 15000 } );

		await setBalance( requestUtils, 1000 );
		await page.locator( '#place_order' ).click();
		await expect(
			page
				.locator(
					'.woocommerce-NoticeGroup-checkout, .woocommerce-error'
				)
				.first()
		).toContainText( REFUSED, { timeout: 30000 } );
		await expect( page ).not.toHaveURL( /order-received/ );
		expect( ( await holds( requestUtils ) ).at( -1 )?.status ).toBe( 409 );
	} );
} );

test.describe( 'Guests', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	test( 'see the verify prompt instead of rewards', async ( {
		page,
		requestUtils,
	} ) => {
		const id = await productId( requestUtils, 'PXL-A' );
		await page.goto( `/?add-to-cart=${ id }` );
		await page.goto( '/checkout/' );

		const panel = page.getByTestId( 'pixalink-loyalty-panel' );
		await expect(
			panel.getByText( 'Verify your phone to use your rewards.' )
		).toBeVisible( { timeout: 15000 } );
		await expect( panel.getByRole( 'button' ) ).toHaveCount( 0 );
	} );
} );
