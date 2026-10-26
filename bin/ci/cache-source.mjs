#!/usr/bin/env node
/**
 * Keep a runner-local copy of a WordPress or WooCommerce zip, so wp-env does not download it in every job.
 *
 * Versioned zips are kept by file name (woocommerce.11.0.1.zip, wordpress-7.0.7.zip). Moving targets
 * (woocommerce.latest-stable.zip, the trunk nightly) are kept per UTC day. A hit refreshes the file's mtime;
 * files untouched for PRUNE_DAYS are deleted. Downloads go to a temporary file renamed into place, so jobs
 * sharing the directory never read a half-written zip.
 *
 * Usage: node bin/ci/cache-source.mjs <zip url> <cache dir>   -> prints the cached zip's path
 */
import {
	mkdir,
	open,
	readdir,
	rename,
	rm,
	stat,
	utimes,
	writeFile,
} from 'node:fs/promises';
import { join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';

const PRUNE_DAYS = 21;
const ATTEMPTS = 3;

const [ url, cacheDir ] = process.argv.slice( 2 );
if ( ! url || ! cacheDir ) {
	throw new Error( 'Usage: cache-source.mjs <zip url> <cache dir>' );
}

const log = ( message ) => process.stderr.write( `${ message }\n` );

const baseName = new URL( url ).pathname.split( '/' ).pop();
const isMovingTarget = /latest|nightly/.test( url );
const fileName = isMovingTarget
	? baseName.replace(
			/\.zip$/,
			`-${ new Date().toISOString().slice( 0, 10 ) }.zip`
		)
	: baseName;
const target = join( cacheDir, fileName );

/**
 * Whether the file is a complete-looking zip: present, not tiny, and starting with the PK signature.
 *
 * @param {string} path File path.
 * @return {Promise<boolean>} True when it looks usable.
 */
const isZip = async ( path ) => {
	try {
		const handle = await open( path, 'r' );
		const { buffer } = await handle.read( Buffer.alloc( 2 ), 0, 2, 0 );
		const { size } = await handle.stat();
		await handle.close();
		return size > 100_000 && buffer.toString() === 'PK';
	} catch {
		return false;
	}
};

await mkdir( cacheDir, { recursive: true } );

if ( await isZip( target ) ) {
	const now = new Date();
	await utimes( target, now, now );
	log( `Cache hit: ${ fileName }` );
} else {
	const temporary = `${ target }.${ process.pid }.part`;
	for ( let attempt = 1; ; attempt++ ) {
		try {
			log( `Downloading ${ url } (attempt ${ attempt })` );
			const response = await fetch( url, {
				headers: { 'User-Agent': 'pixalink-loyalty-ci' },
			} );
			if ( ! response.ok ) {
				throw new Error( `HTTP ${ response.status }` );
			}
			// Buffered, not streamed: piping the body to a file pauses undici's parser under backpressure, and
			// Node 22's undici then dies on assert( ! this.paused ) when the socket closes, which no catch sees.
			const body = Buffer.from( await response.arrayBuffer() );
			const expected = Number( response.headers.get( 'content-length' ) );
			if ( expected && body.length !== expected ) {
				throw new Error(
					`got ${ body.length } of ${ expected } bytes`
				);
			}
			await writeFile( temporary, body );
			if ( ! ( await isZip( temporary ) ) ) {
				throw new Error( 'not a zip' );
			}
			await rename( temporary, target );
			break;
		} catch ( error ) {
			await rm( temporary, { force: true } );
			if ( attempt === ATTEMPTS ) {
				throw new Error(
					`Could not download ${ url }: ${ error.message }`
				);
			}
			log( `Download failed (${ error.message }); retrying` );
			await sleep( 10_000 );
		}
	}
}

// Prune what no job has used for a while (another job's in-flight .part files included, once stale).
const cutoff = Date.now() - PRUNE_DAYS * 24 * 60 * 60 * 1000;
for ( const name of await readdir( cacheDir ) ) {
	const path = join( cacheDir, name );
	try {
		if ( ( await stat( path ) ).mtimeMs < cutoff ) {
			await rm( path, { force: true } );
			log( `Pruned ${ name }` );
		}
	} catch {
		// Removed by a concurrent job.
	}
}

process.stdout.write( `${ target }\n` );
