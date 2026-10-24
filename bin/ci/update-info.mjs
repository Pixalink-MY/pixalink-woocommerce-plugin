#!/usr/bin/env node
/**
 * Write the plugin-update-checker metadata (info.json) for a self-hosted release.
 *
 * Usage: node bin/ci/update-info.mjs <selfhosted zip> <download base URL>  > info.json
 *
 * Reads the version and requirement headers from the main plugin file and readme.txt, and renders the
 * readme Description and Changelog sections as simple HTML for the WordPress "View details" modal.
 */
import { createHash } from 'node:crypto';
import { readFile, stat } from 'node:fs/promises';
import { basename, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const [ zipPath, downloadBase ] = process.argv.slice( 2 );
if ( ! zipPath || ! downloadBase ) {
	process.stderr.write(
		'Usage: node bin/ci/update-info.mjs <zip> <download base URL>\n'
	);
	process.exit( 2 );
}

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..', '..' );
const main = await readFile(
	join( root, 'pixalink-loyalty-for-woocommerce.php' ),
	'utf8'
);
const readme = await readFile( join( root, 'readme.txt' ), 'utf8' );

const header = ( source, name ) => {
	const match = new RegExp( `^[ \\t/*#@]*${ name }:\\s*(.+)$`, 'mi' ).exec(
		source
	);
	return match ? match[ 1 ].trim() : '';
};

const section = ( name ) => {
	const match = new RegExp(
		`^== ${ name } ==\\s*$([\\s\\S]*?)(?=^== |(?![\\s\\S]))`,
		'm'
	).exec( readme );
	return match ? match[ 1 ].trim() : '';
};

const escapeHtml = ( text ) =>
	text
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' );

const toHtml = ( text ) => {
	const html = [];
	let list = false;
	for ( const raw of text.split( '\n' ) ) {
		const line = raw.trim();
		const item = /^\*\s+(.*)$/.exec( line );
		const heading = /^=\s*(.+?)\s*=$/.exec( line );
		if ( item ) {
			if ( ! list ) {
				html.push( '<ul>' );
				list = true;
			}
			html.push(
				`<li>${ escapeHtml( item[ 1 ] ).replace(
					/\*\*(.+?)\*\*/g,
					'<strong>$1</strong>'
				) }</li>`
			);
			continue;
		}
		if ( list ) {
			html.push( '</ul>' );
			list = false;
		}
		if ( heading ) {
			html.push( `<h4>${ escapeHtml( heading[ 1 ] ) }</h4>` );
		} else if ( line ) {
			html.push(
				`<p>${ escapeHtml( line ).replace(
					/\*\*(.+?)\*\*/g,
					'<strong>$1</strong>'
				) }</p>`
			);
		}
	}
	if ( list ) {
		html.push( '</ul>' );
	}
	return html.join( '\n' );
};

const zip = await readFile( zipPath );
const { mtime } = await stat( zipPath );

const info = {
	name: header( main, 'Plugin Name' ),
	slug: 'pixalink-loyalty-for-woocommerce',
	version: header( main, 'Version' ),
	download_url: `${ downloadBase.replace( /\/$/, '' ) }/${ basename(
		zipPath
	) }`,
	homepage: header( main, 'Plugin URI' ) || header( main, 'Author URI' ),
	author: header( main, 'Author' ),
	requires: header( main, 'Requires at least' ),
	tested: header( readme, 'Tested up to' ),
	requires_php: header( main, 'Requires PHP' ),
	last_updated: mtime.toISOString().replace( 'T', ' ' ).slice( 0, 19 ),
	sha256: createHash( 'sha256' ).update( zip ).digest( 'hex' ),
	sections: {
		description: toHtml( section( 'Description' ) ),
		installation: toHtml( section( 'Installation' ) ),
		changelog: toHtml( section( 'Changelog' ) ),
	},
};

process.stdout.write( `${ JSON.stringify( info, null, 2 ) }\n` );
