#!/usr/bin/env node
/**
 * Resolve the integration matrix from the WordPress.org APIs at run time.
 *
 * WordPress: N = latest release, N-1 = latest patch of the previous major (x.y) branch, trunk = nightly build.
 * WooCommerce: L = latest stable, L-1 / L-2 = latest patch of the two previous x.y branches,
 * RC = newest pre-release newer than L (falls back to L when none is open).
 *
 * Usage: node bin/ci/resolve-versions.mjs            -> writes "matrix=<json>" (for $GITHUB_OUTPUT)
 *        node bin/ci/resolve-versions.mjs --pretty   -> human-readable table
 *        node bin/ci/resolve-versions.mjs --from=dir -> read dir/wp.json and dir/wc.json instead of the network
 */
import { readFile } from 'node:fs/promises';

const fromDir = process.argv
	.find( ( arg ) => arg.startsWith( '--from=' ) )
	?.slice( '--from='.length );

const WP_API = 'https://api.wordpress.org/core/version-check/1.7/';
const WC_API =
	'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=woocommerce&request[fields][versions]=1';

const parse = ( version ) => {
	const match = /^(\d+)\.(\d+)(?:\.(\d+))?(?:-([0-9A-Za-z.-]+))?$/.exec(
		version
	);
	if ( ! match ) {
		return null;
	}
	return {
		version,
		major: Number( match[ 1 ] ),
		minor: Number( match[ 2 ] ),
		patch: Number( match[ 3 ] ?? 0 ),
		pre: match[ 4 ] ?? null,
	};
};

const compare = ( a, b ) =>
	a.major - b.major ||
	a.minor - b.minor ||
	a.patch - b.patch ||
	// A stable release sorts after its own pre-releases.
	( a.pre === b.pre
		? 0
		: ( a.pre === null && 1 ) ||
			( b.pre === null && -1 ) ||
			a.pre.localeCompare( b.pre, 'en', { numeric: true } ) );

const branch = ( v ) => `${ v.major }.${ v.minor }`;

/**
 * Latest stable version of each of the newest `count` x.y branches, newest first.
 *
 * @param {Array}  versions Parsed versions.
 * @param {number} count    Number of branches.
 * @return {Array} Versions.
 */
const latestPerBranch = ( versions, count ) => {
	const byBranch = new Map();
	for ( const v of versions.filter( ( candidate ) => ! candidate.pre ) ) {
		const current = byBranch.get( branch( v ) );
		if ( ! current || compare( v, current ) > 0 ) {
			byBranch.set( branch( v ), v );
		}
	}
	return [ ...byBranch.values() ]
		.sort( ( a, b ) => compare( b, a ) )
		.slice( 0, count );
};

const getJson = async ( url ) => {
	if ( fromDir ) {
		const file = url === WP_API ? 'wp.json' : 'wc.json';
		return JSON.parse( await readFile( `${ fromDir }/${ file }`, 'utf8' ) );
	}

	const response = await fetch( url, {
		headers: { 'User-Agent': 'pixalink-loyalty-ci' },
	} );
	if ( ! response.ok ) {
		throw new Error( `${ url } returned HTTP ${ response.status }` );
	}
	return response.json();
};

const wpCore = await getJson( WP_API );
const wpVersions = wpCore.offers
	.map( ( offer ) => parse( offer.version ) )
	.filter( Boolean );
const [ wpN, wpPrevious ] = latestPerBranch( wpVersions, 2 );

const wooInfo = await getJson( WC_API );
const wooVersions = Object.keys( wooInfo.versions )
	.map( parse )
	.filter( Boolean );
const [ wcL, wcL1, wcL2 ] = latestPerBranch( wooVersions, 3 );
const wcRc =
	wooVersions
		.filter( ( v ) => v.pre && compare( v, wcL ) > 0 )
		.sort( ( a, b ) => compare( b, a ) )[ 0 ] ?? wcL;

if ( ! wpN || ! wpPrevious || ! wcL || ! wcL1 || ! wcL2 ) {
	throw new Error( 'Could not resolve WordPress/WooCommerce versions.' );
}

const wpZip = ( v ) => `https://wordpress.org/wordpress-${ v.version }.zip`;
const wcZip = ( v ) =>
	`https://downloads.wordpress.org/plugin/woocommerce.${ v.version }.zip`;

const environment = ( php, wp, wc, experimental = false, wpLabel = null ) => ( {
	name: `PHP ${ php } · WP ${ wpLabel ?? wp.version } · WC ${ wc.version }`,
	php,
	wp: wpLabel ?? wp.version,
	wp_zip: wp
		? wpZip( wp )
		: 'https://wordpress.org/nightly-builds/wordpress-latest.zip',
	wc: wc.version,
	wc_zip: wcZip( wc ),
	experimental,
} );

const matrix = [
	environment( '8.1', wpPrevious, wcL2 ),
	environment( '8.3', wpN, wcL ),
	environment( '8.4', wpN, wcL1 ),
	environment( '8.4', null, wcRc, true, 'trunk' ),
];

if ( process.argv.includes( '--pretty' ) ) {
	// eslint-disable-next-line no-console
	console.table(
		matrix.map( ( { name, experimental } ) => ( { name, experimental } ) )
	);
} else {
	process.stdout.write( `matrix=${ JSON.stringify( matrix ) }\n` );
}
