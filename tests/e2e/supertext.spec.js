// @ts-check
const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );

/** Rejects if the promise doesn't settle within `ms` (so callers can move on). */
function withTimeout( promise, ms, label ) {
	let timer;
	const timeout = new Promise( ( _, reject ) => {
		timer = setTimeout( () => reject( new Error( label + ' timed out after ' + ms + 'ms' ) ), ms );
	} );
	return Promise.race( [ promise, timeout ] ).finally( () => clearTimeout( timer ) );
}

/**
 * Saves a numbered screenshot for the current test step into test-results/steps/.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').TestInfo} testInfo
 * @param {string} name
 */
async function shot( page, testInfo, name, target ) {
	// @ts-ignore - stash a per-test counter on testInfo.
	testInfo._step = ( testInfo._step || 0 ) + 1;
	const safe = testInfo.title.replace( /[^a-z0-9]+/gi, '-' ).slice( 0, 40 );
	const num  = String( testInfo._step ).padStart( 2, '0' );
	const path = `test-results/steps/${ safe }-${ num }-${ name }.png`;
	// Freeze animations + hide spinner GIFs so the capture can reach a stable frame.
	await page.addStyleTag( {
		content: '*,*::before,*::after{animation:none!important;transition:none!important}.spinner,.spinner.is-active{display:none!important}',
	} ).catch( () => {} );
	// Try the normal (stability-checked) screenshot; if the page never settles, fall
	// back to a plain CDP viewport grab, which returns the current frame instantly
	// (no stability wait) and so can't hang on a perpetually-repainting page.
	try {
		await ( target || page ).screenshot( { path, animations: 'disabled', timeout: 5000 } );
	} catch ( e ) {
		try {
			const client   = await page.context().newCDPSession( page );
			// Bound the CDP call: on a perpetually-repainting page it can stall, so
			// reject after a cap and move on rather than hang the test.
			const { data } = await withTimeout( client.send( 'Page.captureScreenshot', { format: 'png' } ), 12000, 'cdp screenshot' );
			fs.writeFileSync( path, Buffer.from( data, 'base64' ) );
			console.log( 'screenshot "' + name + '" via CDP fallback' );
		} catch ( e2 ) {
			console.log( 'screenshot "' + name + '" skipped: ' + e2.message );
		}
	}
}

test.describe( 'Supertext admin pages', () => {
	test( 'Settings page shows status panel + human credentials', async ( { page }, testInfo ) => {
		// Status is merged into the (top-level) Settings page.
		await page.goto( 'wp-admin/admin.php?page=supertext-polylang' );
		await expect( page.getByRole( 'heading', { name: 'Supertext for Polylang' } ) ).toBeVisible();
		await expect( page.getByText( 'Polylang patched', { exact: false } ) ).toBeVisible();
		await expect( page.getByRole( 'heading', { name: 'Translation Services (human)' } ) ).toBeVisible();
		await expect( page.locator( '#supertext-environment' ) ).toBeVisible();
		await expect( page.locator( '#supertext-email' ) ).toBeVisible();
		await shot( page, testInfo, 'settings' );
	} );

	test( 'Orders page renders with filter', async ( { page }, testInfo ) => {
		await page.goto( 'wp-admin/admin.php?page=supertext-polylang-orders' );
		await expect( page.getByRole( 'heading', { name: 'Supertext Orders' } ) ).toBeVisible();
		await shot( page, testInfo, 'orders' );
	} );

	test( 'Debug page renders', async ( { page }, testInfo ) => {
		await page.goto( 'wp-admin/admin.php?page=supertext-polylang-debug' );
		await expect( page.getByRole( 'heading', { name: 'Order callbacks', exact: false } ) ).toBeVisible();
		await shot( page, testInfo, 'debug' );
	} );
} );

test.describe( 'AI translation via bulk action', () => {
	const TITLE     = 'Playwright AI Test Post';
	const POST_TYPE = 'post';
	const REST_BASE = 'page' === POST_TYPE ? 'pages' : 'posts';

	/** Best-effort LiteSpeed "Purge All" via the admin-bar so object cache is fresh. */
	async function flushCache( page ) {
		await page.goto( 'wp-admin/index.php', { waitUntil: 'domcontentloaded' } );
		const menu = page.locator( '#wp-admin-bar-litespeed-menu' );
		if ( await menu.count() ) {
			await menu.hover().catch( () => {} );
			const purge = menu.locator( 'a', { hasText: 'Purge All' } ).first();
			if ( await purge.count() ) {
				await purge.click().catch( () => {} );
				await page.waitForLoadState( 'domcontentloaded' ).catch( () => {} );
			}
		}
	}

	/** Reads the REST root + nonce (via the block editor, which enqueues it). */
	async function restConfig( page ) {
		await page.goto( 'wp-admin/post-new.php?post_type=' + POST_TYPE, { waitUntil: 'domcontentloaded' } );
		return page.evaluate( () => ( {
			// eslint-disable-next-line no-undef
			nonce: window.wpApiSettings && window.wpApiSettings.nonce,
			// eslint-disable-next-line no-undef
			root: window.wpApiSettings && window.wpApiSettings.root,
		} ) );
	}

	test( '"' + TITLE + '" translates German → English', async ( { page }, testInfo ) => {
		test.setTimeout( 300000 );

		const listUrl = 'wp-admin/edit.php?post_type=' + POST_TYPE + '&s=' + encodeURIComponent( TITLE );

		// The German source row is the title match whose own-language (German) column
		// shows the self flag. (The English post's German column links elsewhere.)
		const sourceRow = ( p ) => p.locator( '#the-list tr', { hasText: TITLE } )
			.filter( { has: p.locator( 'td.column-language_de a.pll_column_flag' ) } ).first();

		// --- 1. Find the German source.
		await page.goto( listUrl, { waitUntil: 'domcontentloaded' } );
		const row = sourceRow( page );
		await expect( row, 'German source "' + TITLE + '" not found' ).toBeVisible();
		await shot( page, testInfo, 'list' );

		// --- 2. Delete an existing English translation for a clean run.
		const enEdit = row.locator( 'td.column-language_en a.pll_icon_edit' );
		if ( await enEdit.count() > 0 ) {
			const href = await enEdit.first().getAttribute( 'href' );
			const m    = href && href.match( /[?&]post=(\d+)/ );
			if ( m ) {
				const cfg = await restConfig( page );
				const res = await page.request.delete( cfg.root + 'wp/v2/' + REST_BASE + '/' + m[ 1 ] + '?force=true', {
					headers: { 'X-WP-Nonce': cfg.nonce },
				} );
				console.log( 'Deleted existing EN translation ' + m[ 1 ] + ' → HTTP ' + res.status() );
			}
		}

		// --- 2b. Flush caches and wait until Polylang no longer reports an EN
		// translation (LiteSpeed object cache can otherwise serve a stale link, which
		// would make the next translation a no-op "already translated").
		await flushCache( page );
		await expect( async () => {
			await page.goto( listUrl, { waitUntil: 'domcontentloaded' } );
			await expect( sourceRow( page ).locator( 'td.column-language_en a.pll_icon_add' ) ).toBeVisible( { timeout: 2000 } );
		} ).toPass( { timeout: 40000, intervals: [ 2000, 3000, 5000 ] } );

		// --- 3. Select the source and choose the AI bulk action → English.
		const src = sourceRow( page );
		await expect( src ).toBeVisible();
		await shot( page, testInfo, 'after-delete' );
		// force: skip Playwright's actionability/stability wait — these admin pages
		// repaint continuously and would otherwise hang the action.
		await src.locator( 'input[type="checkbox"]' ).check( { force: true } );
		await page.selectOption( '#bulk-action-selector-top', 'supertext_ai_translation' );
		const langPicker = page.locator( '#supertext_target_lang' );
		await expect( langPicker ).toBeVisible();
		const enValue = await langPicker.locator( 'option', { hasText: 'English' } ).getAttribute( 'value' );
		await langPicker.selectOption( enValue );
		await shot( page, testInfo, 'before-apply' );

		// --- 4. Apply and verify the result.
		console.log( 'APPLY: clicking at ' + new Date().toISOString() );
		const t0 = Date.now();
		await Promise.all( [
			page.waitForURL( /supertext_(created|errors|error)=/, { timeout: 230000 } ),
			page.click( '#doaction', { force: true } ),
		] );
		console.log( 'APPLY: result page after ' + Math.round( ( Date.now() - t0 ) / 1000 ) + 's' );

		const notices = ( await page.locator( '#wpbody-content .notice' ).allInnerTexts() ).join( ' | ' );
		console.log( 'AI result notices: ' + notices );
		await shot( page, testInfo, 'result' );

		expect( notices.toLowerCase() ).toContain( 'translation' );
	} );
} );
