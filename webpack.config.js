/**
 * Build configuration: `@wordpress/scripts` defaults with WooCommerce dependency extraction.
 *
 * Each entry emits build/<name>.js plus build/<name>.asset.php (dependencies + version) for wp_register_script().
 * Imports from `@woocommerce/*` and `@wordpress/*` resolve to the globals WooCommerce and WordPress already load.
 */
const path = require( 'path' );
const wordpressConfig = require( '@wordpress/scripts/config/webpack.config' );
const WooCommerceDependencyExtractionWebpackPlugin = require( '@woocommerce/dependency-extraction-webpack-plugin' );

const defaultConfig = Array.isArray( wordpressConfig )
	? wordpressConfig[ 0 ]
	: wordpressConfig;

const entry = ( name ) =>
	path.resolve( __dirname, 'assets/js/src', name, 'index.js' );

module.exports = {
	...defaultConfig,
	entry: {
		blocks: entry( 'blocks' ),
		'classic-checkout': entry( 'classic-checkout' ),
		'classic-phone': entry( 'classic-phone' ),
		account: entry( 'account' ),
		admin: entry( 'admin' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) =>
				plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new WooCommerceDependencyExtractionWebpackPlugin(),
	],
};
