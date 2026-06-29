// @ts-check
// Captures clean screenshots of the (static) admin screens for the GitHub docs.
const { test } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

const OUT = path.join( __dirname, '..', '..', 'docs', 'images' );

const SCREENS = [
	// Status is merged into the (top-level) Settings page.
	[ 'settings', 'wp-admin/admin.php?page=supertext-polylang' ],
	[ 'orders', 'wp-admin/admin.php?page=supertext-polylang-orders' ],
	[ 'machine-translation', 'wp-admin/admin.php?page=mlang_settings' ],
];

test( 'capture docs screenshots', async ( { page } ) => {
	test.setTimeout( 120000 );
	fs.mkdirSync( OUT, { recursive: true } );

	for ( const [ name, url ] of SCREENS ) {
		await page.goto( url, { waitUntil: 'domcontentloaded' } );
		await page.addStyleTag( {
			content: '*,*::before,*::after{animation:none!important;transition:none!important}.spinner{display:none!important}',
		} ).catch( () => {} );
		try {
			await page.screenshot( { path: path.join( OUT, name + '.png' ), fullPage: true, animations: 'disabled', timeout: 8000 } );
			console.log( 'captured ' + name );
		} catch ( e ) {
			console.log( 'FAILED ' + name + ': ' + e.message );
		}
	}
} );
