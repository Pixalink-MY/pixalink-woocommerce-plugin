/**
 * SPIKE (P02, throwaway): virtual coupons + commit-at-submit holds in a real browser, Blocks and classic.
 *
 * Needs PIXALINK_LOYALTY_SPIKE=true and tests/e2e/spike/setup.php (see the spike-e2e job in .github/workflows/ci.yml).
 * The shopper is the logged-in wp-env admin with a saved US billing address.
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

const REFUSED = 'You no longer have enough Pixalink points';

const productId = async ( requestUtils: RequestUtils, sku: string ) => {
	const products = ( await requestUtils.rest( {
		path: '/wc/store/v1/products',
		params: { sku },
	} ) ) as Array< { id: number } >;
	expect( products ).toHaveLength( 1 );
	return products[ 0 ].id;
};

const puts = async ( requestUtils: RequestUtils ) => {
	const recorded = ( await requestUtils.rest( {
		path: '/fake-pixalink/v1/requests',
	} ) ) as { requests: Recorded[] };
	return recorded.requests.filter(
		( request ) =>
			'PUT' === request.method && request.path.startsWith( 'orders/' )
	);
};

const freshCart = async ( page: Page, requestUtils: RequestUtils ) => {
	await requestUtils.rest( {
		method: 'DELETE',
		path: '/fake-pixalink/v1/requests',
	} );
	const id = await productId( requestUtils, 'PXL-A' );
	await page.goto( `/?pixalink_spike_reset=1&add-to-cart=${ id }` );
};

test.describe( 'P02 spike: Blocks checkout', () => {
	test( 'slot fill mounts, points chip is relabelled, holds are placed at submit', async ( {
		page,
		requestUtils,
	}, testInfo ) => {
		await freshCart( page, requestUtils );
		await page.goto( '/checkout/' );

		const exported = await page.evaluate( () =>
			Object.keys(
				( window as unknown as { wc: { blocksCheckout: object } } ).wc
					.blocksCheckout
			).sort()
		);
		testInfo.annotations.push( {
			type: 'wc.blocksCheckout exports',
			description: exported.join( ', ' ),
		} );
		expect( exported ).toEqual(
			expect.arrayContaining( [
				'ExperimentalDiscountsMeta',
				'extensionCartUpdate',
				'registerCheckoutFilters',
				'registerCheckoutBlock',
			] )
		);

		const panel = page.getByTestId( 'pixalink-loyalty-spike' );
		await expect( panel ).toBeVisible();
		await panel
			.getByRole( 'button', { name: 'Link Pixalink (spike)' } )
			.click();
		await expect( panel.getByText( 'Balance: 2,000 pts' ) ).toBeVisible();

		await panel.getByLabel( 'Points to redeem' ).fill( '1000' );
		await panel.getByRole( 'button', { name: 'Apply points' } ).click();
		// The coupon chip renders "Coupon: <label>" inside the order summary's coupon list.
		await expect(
			page
				.getByRole( 'listitem' )
				.filter( { hasText: 'Points (1,000 pts)' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'listitem' ).filter( { hasText: 'pxl-points' } )
		).toHaveCount( 0 );

		await panel.getByRole( 'button', { name: 'Welcome 10 off' } ).click();
		await expect(
			panel.getByRole( 'button', { name: 'Remove Welcome 10 off' } )
		).toBeVisible();
		await expect(
			page.locator( '.wc-block-components-totals-footer-item' )
		).toContainText( '$80.00' );
		await page.screenshot( {
			path: testInfo.outputPath( 'blocks-checkout.png' ),
			fullPage: true,
		} );

		await page.getByRole( 'button', { name: /place order/i } ).click();
		await expect( page ).toHaveURL( /order-received/, { timeout: 30000 } );

		const placed = await puts( requestUtils );
		expect( placed.at( -1 )?.status ).toBe( 200 );
		expect( placed.at( -1 )?.body.redemptions ).toHaveLength( 2 );
		await page.screenshot( {
			path: testInfo.outputPath( 'blocks-order-received.png' ),
			fullPage: true,
		} );
	} );

	test( 'a refused hold shows our notice and keeps the shopper on checkout', async ( {
		page,
		requestUtils,
	}, testInfo ) => {
		await freshCart( page, requestUtils );
		await page.goto( '/checkout/' );

		const panel = page.getByTestId( 'pixalink-loyalty-spike' );
		await panel
			.getByRole( 'button', { name: 'Link Pixalink (spike)' } )
			.click();
		await panel.getByLabel( 'Points to redeem' ).fill( '1500' );
		await panel.getByRole( 'button', { name: 'Apply points' } ).click();
		await expect(
			page
				.getByRole( 'listitem' )
				.filter( { hasText: 'Points (1,500 pts)' } )
		).toBeVisible();

		await page.getByRole( 'button', { name: /place order/i } ).click();
		await expect( page.getByText( REFUSED ).first() ).toBeVisible( {
			timeout: 30000,
		} );
		await expect( page ).not.toHaveURL( /order-received/ );
		expect( ( await puts( requestUtils ) ).at( -1 )?.status ).toBe( 409 );
		await page.screenshot( {
			path: testInfo.outputPath( 'blocks-hold-refused.png' ),
			fullPage: true,
		} );
	} );
} );

test.describe( 'P02 spike: classic checkout', () => {
	const linkAndApply = async ( page: Page, points: string ) => {
		await page.goto( '/classic-checkout/' );
		const panel = page.getByTestId( 'pixalink-loyalty-spike-classic' );
		await expect( panel ).toBeVisible();
		await Promise.all( [
			page.waitForEvent( 'load' ),
			panel
				.getByRole( 'button', { name: 'Link Pixalink (spike)' } )
				.click(),
		] );
		await expect(
			page
				.getByTestId( 'pixalink-loyalty-spike-classic' )
				.getByText( 'Balance: 2,000 pts' )
		).toBeVisible();
		await page.getByLabel( 'Points to redeem' ).fill( points );
		await page.getByRole( 'button', { name: 'Apply points' } ).click();
	};

	test( 'Store API fetch + update_checkout applies points, label and holds', async ( {
		page,
		requestUtils,
	}, testInfo ) => {
		await freshCart( page, requestUtils );
		await linkAndApply( page, '1000' );

		const review = page.locator(
			'.woocommerce-checkout-review-order-table'
		);
		await expect(
			review.locator( 'tr.cart-discount', {
				hasText: 'Points (1,000 pts)',
			} )
		).toBeVisible( { timeout: 15000 } );

		await page.getByRole( 'button', { name: 'Welcome 10 off' } ).click();
		await expect(
			review.locator( 'tr.cart-discount', {
				hasText: 'Voucher: Welcome 10 off',
			} )
		).toBeVisible( { timeout: 15000 } );
		await expect( review.locator( 'tr.order-total' ) ).toContainText(
			'$80.00'
		);
		await page.screenshot( {
			path: testInfo.outputPath( 'classic-checkout.png' ),
			fullPage: true,
		} );

		await page.locator( '#place_order' ).click();
		await expect( page ).toHaveURL( /order-received/, { timeout: 30000 } );

		const placed = await puts( requestUtils );
		expect( placed.at( -1 )?.status ).toBe( 200 );
		expect( placed.at( -1 )?.body.redemptions ).toHaveLength( 2 );
	} );

	test( 'a refused hold aborts with our notice and no order', async ( {
		page,
		requestUtils,
	}, testInfo ) => {
		await freshCart( page, requestUtils );
		await linkAndApply( page, '1500' );
		await expect(
			page.locator( 'tr.cart-discount', {
				hasText: 'Points (1,500 pts)',
			} )
		).toBeVisible( { timeout: 15000 } );

		await page.locator( '#place_order' ).click();
		await expect(
			page
				.locator(
					'.woocommerce-NoticeGroup-checkout, .woocommerce-error'
				)
				.first()
		).toContainText( REFUSED, { timeout: 30000 } );
		await expect( page ).not.toHaveURL( /order-received/ );
		expect( ( await puts( requestUtils ) ).at( -1 )?.status ).toBe( 409 );
		await page.screenshot( {
			path: testInfo.outputPath( 'classic-hold-refused.png' ),
			fullPage: true,
		} );
	} );

	test( 'the Store API Nonce header alone keeps the logged-in shopper session (no X-WP-Nonce)', async ( {
		page,
		requestUtils,
	} ) => {
		await freshCart( page, requestUtils );
		await linkAndApply( page, '1000' );
		await expect(
			page.locator( 'tr.cart-discount', {
				hasText: 'Points (1,000 pts)',
			} )
		).toBeVisible( { timeout: 15000 } );

		const bare = await page.evaluate( async () => {
			const config = (
				window as unknown as {
					pixalinkLoyaltySpike: {
						endpoint: string;
						nonce: string;
						namespace: string;
					};
				}
			 ).pixalinkLoyaltySpike;
			const response = await window.fetch( config.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					Nonce: config.nonce,
				},
				body: JSON.stringify( {
					namespace: config.namespace,
					data: { action: 'set_points', points: 500 },
				} ),
			} );
			return { status: response.status, body: await response.json() };
		} );

		// Store API Authentication returns true on rest_authentication_errors (priority 10), so WordPress's
		// cookie check (priority 100, which would demote a nonce-less request to user 0) never runs.
		expect( bare.status ).toBe( 200 );
		await page.goto( '/classic-checkout/' );
		await expect(
			page.locator( 'tr.cart-discount', { hasText: 'Points (500 pts)' } )
		).toBeVisible( { timeout: 15000 } );
	} );
} );
