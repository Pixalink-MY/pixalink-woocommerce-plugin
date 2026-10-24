/**
 * Playwright configuration for end-to-end tests against the wp-env site (http://localhost:8888).
 *
 * The site's Pixalink API is the fake server mu-plugin (tests/e2e/mu-plugins/fake-pixalink.php), so specs can
 * script responses and assert on the requests the plugin made via /wp-json/fake-pixalink/v1/*.
 */
import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.WP_BASE_URL ?? 'http://localhost:8888';

export default defineConfig( {
	testDir: './tests/e2e/specs',
	outputDir: './artifacts/test-results',
	globalSetup: require.resolve( './tests/e2e/global-setup.ts' ),
	fullyParallel: false,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	forbidOnly: !! process.env.CI,
	reporter: process.env.CI ? [ [ 'github' ], [ 'list' ] ] : 'list',
	use: {
		baseURL,
		storageState:
			process.env.STORAGE_STATE_PATH ??
			'artifacts/storage-states/admin.json',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
