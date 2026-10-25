/**
 * Logs in as the wp-env admin once and stores the session for every spec.
 */
import { request, type FullConfig } from '@playwright/test';
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

export default async function globalSetup(
	config: FullConfig
): Promise< void > {
	const { baseURL, storageState } = config.projects[ 0 ].use;
	const storageStatePath =
		typeof storageState === 'string' ? storageState : undefined;

	const requestContext = await request.newContext( { baseURL } );
	const requestUtils = new RequestUtils( requestContext, {
		storageStatePath,
	} );

	await requestUtils.setupRest();
	await requestContext.dispose();
}
