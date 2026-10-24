/**
 * Smoke test: the plugin is active and the fake Pixalink server answers signed requests.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Pixalink Loyalty smoke', () => {
	test( 'plugin is active', async ( { requestUtils } ) => {
		const plugins = ( await requestUtils.rest( {
			path: '/wp/v2/plugins',
		} ) ) as Array< { plugin: string; status: string } >;

		const plugin = plugins.find(
			( candidate ) =>
				candidate.plugin ===
				'pixalink-loyalty-for-woocommerce/pixalink-loyalty-for-woocommerce'
		);

		expect( plugin?.status ).toBe( 'active' );
	} );

	test( 'fake Pixalink server records requests', async ( {
		requestUtils,
	} ) => {
		await requestUtils.rest( {
			method: 'DELETE',
			path: '/fake-pixalink/v1/requests',
		} );

		const recorded = ( await requestUtils.rest( {
			path: '/fake-pixalink/v1/requests',
		} ) ) as { requests: unknown[] };

		expect( recorded.requests ).toEqual( [] );
	} );
} );
