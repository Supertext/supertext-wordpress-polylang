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
		// Skip (rather than fail) when the seed post is missing — this test needs a
		// German post titled TITLE to exist on the site.
		test.skip( 0 === ( await row.count() ), 'Seed post "' + TITLE + '" (German) not found on this site.' );
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

test.describe( 'Settings — feature status, toggles & plugin detection', () => {
	test( 'Status overview lists the new features + integrations, and the toggles/detect controls', async ( { page }, testInfo ) => {
		await page.goto( 'wp-admin/admin.php?page=supertext-polylang' );

		await expect( page.getByRole( 'heading', { name: 'Status' } ) ).toBeVisible();

		// New "Features & integrations" section of the status overview.
		await expect( page.getByText( 'Features & integrations', { exact: true } ) ).toBeVisible();
		await expect( page.getByText( 'Page screenshots (VibeBoost)', { exact: true } ) ).toBeVisible();
		await expect( page.getByText( 'Integration: Gravity Forms', { exact: true } ) ).toBeVisible();

		// The two new settings toggles (matched by their option field names, which are unique).
		await expect( page.locator( 'input[name="supertext_polylang_settings[preview_links_enabled]"]' ) ).toBeVisible();
		await expect( page.locator( 'input[name="supertext_polylang_settings[screenshots_enabled]"]' ) ).toBeVisible();

		// Plugin-detection button.
		await expect( page.getByRole( 'button', { name: 'Detect plugins' } ) ).toBeVisible();

		await shot( page, testInfo, 'settings-features' ).catch( () => {} );
	} );
} );

test.describe( 'Secret preview link', () => {
	/** Reads the REST root + nonce (via the block editor, which enqueues wpApiSettings). */
	async function restConfig( page ) {
		await page.goto( 'wp-admin/post-new.php', { waitUntil: 'domcontentloaded' } );
		return page.evaluate( () => ( {
			// eslint-disable-next-line no-undef
			nonce: window.wpApiSettings && window.wpApiSettings.nonce,
			// eslint-disable-next-line no-undef
			root: window.wpApiSettings && window.wpApiSettings.root,
		} ) );
	}

	/** Dismisses the block-editor welcome guide if it pops up. */
	async function dismissGuide( page ) {
		const close = page.getByRole( 'button', { name: 'Close', exact: true } );
		if ( await close.count() ) {
			await close.first().click().catch( () => {} );
		}
	}

	test( 'preview metabox renders on the post editor', async ( { page }, testInfo ) => {
		await page.goto( 'wp-admin/post-new.php', { waitUntil: 'domcontentloaded' } );
		await dismissGuide( page );

		const box = page.locator( '#supertext-preview' );
		await expect( box ).toBeAttached( { timeout: 30000 } );
		await expect( box.locator( 'input[name="supertext_preview_enabled"]' ) ).toBeAttached();
		await expect( box.locator( '#supertext_preview_expires' ) ).toBeAttached();

		await shot( page, testInfo, 'preview-metabox' ).catch( () => {} );
	} );

	// Verifies the part of the flow this plugin actually owns: enabling the preview
	// on a draft generates a secret, tokenised URL for that exact post. Drives the
	// real Gutenberg meta box.
	//
	// Note: the front-end token *gate* (draft renders only with a valid, unexpired
	// token) is covered by unit tests (DraftPreviewTest), NOT here — this demo serves
	// draft posts publicly at ?p=<id> regardless of token, so an E2E check on it can't
	// distinguish the gate. Keep the security assertion in the unit layer.
	test( 'enabling preview on a draft generates a secret token URL', async ( { page }, testInfo ) => {
		test.setTimeout( 180000 );

		const cfg = await restConfig( page );

		// 1. Create a draft via REST.
		const created = await page.request.post( cfg.root + 'wp/v2/posts', {
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
			data: { title: 'Supertext preview E2E', status: 'draft', content: '<p>preview body</p>' },
		} );
		expect( created.ok(), 'draft creation should succeed' ).toBeTruthy();
		const postId = ( await created.json() ).id;
		console.log( 'Created draft post ' + postId );

		try {
			// 2. Open the editor, enable the preview link with a far-future expiry, save.
			const editUrl = 'wp-admin/post.php?post=' + postId + '&action=edit';
			await page.goto( editUrl, { waitUntil: 'domcontentloaded' } );
			await dismissGuide( page );

			// Meta-box changes alone don't mark the block editor dirty, so make a real
			// edit through the data store — otherwise "Save draft" stays disabled and the
			// meta box is never persisted. (Robust across WP versions; no title selector.)
			await page.waitForFunction( () => window.wp && window.wp.data && window.wp.data.select( 'core/editor' ), { timeout: 30000 } );
			await page.evaluate( () => {
				const { dispatch, select } = window.wp.data;
				const t = select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '';
				dispatch( 'core/editor' ).editPost( { title: t + ' e2e' } );
			} );

			const enable = page.locator( '#supertext-preview input[name="supertext_preview_enabled"]' );
			await expect( enable ).toBeAttached( { timeout: 30000 } );
			await enable.check( { force: true } );
			await page.locator( '#supertext_preview_expires' ).fill( '2099-12-31' );

			// Save draft — Gutenberg posts the meta-box form to post.php after the REST save.
			await page.getByRole( 'button', { name: 'Save draft' } ).click( { force: true } );
			await page.waitForResponse(
				( r ) => r.url().includes( 'post.php' ) && 'POST' === r.request().method(),
				{ timeout: 20000 }
			).catch( () => {} );

			// 3. Reload so the (now-enabled) meta box renders the readonly secret URL.
			await page.goto( editUrl, { waitUntil: 'domcontentloaded' } );
			await dismissGuide( page );
			const urlInput = page.locator( '#supertext_preview_url' );
			await expect( urlInput ).toBeVisible( { timeout: 30000 } );

			const secretUrl = await urlInput.inputValue();
			console.log( 'Secret URL: ' + secretUrl );
			await shot( page, testInfo, 'preview-enabled' ).catch( () => {} );

			// The generated link must carry this post id and a UUID token.
			expect( secretUrl ).toMatch( new RegExp( '[?&]p=' + postId + '(&|$)' ) );
			expect( secretUrl ).toMatch( /[?&]st_preview=[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}(&|$)/ );
		} finally {
			// 4. Clean up the throwaway draft.
			await page.request.delete( cfg.root + 'wp/v2/posts/' + postId + '?force=true', {
				headers: { 'X-WP-Nonce': cfg.nonce },
			} ).catch( () => {} );
		}
	} );
} );
