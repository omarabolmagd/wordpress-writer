/**
 * Clicks through the whole flow in a real browser against a local WordPress
 * (with mocked APIs) and saves screenshots.
 *
 * Usage: node tests/browser.cjs http://localhost:8080 ./screens [rtl]
 */
const { chromium } = require( 'playwright' );
const fs = require( 'fs' );

const base = process.argv[ 2 ] || 'http://localhost:8080';
const out = process.argv[ 3 ] || 'screens';
const suffix = process.argv[ 4 ] ? '-' + process.argv[ 4 ] : '';
fs.mkdirSync( out, { recursive: true } );

( async () => {
	const browser = await chromium.launch();
	const page = await browser.newPage( { viewport: { width: 1400, height: 1000 } } );
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( 'pageerror: ' + e.message ) );
	page.on( 'console', ( m ) => {
		if ( m.type() === 'error' && ! /favicon|Failed to load resource/.test( m.text() ) ) {
			errors.push( 'console: ' + m.text() );
		}
	} );
	const shot = ( name ) => page.screenshot( { path: `${ out }/${ name }${ suffix }.png`, fullPage: true } );

	await page.goto( base + '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'admin' );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

	// Home screen.
	await page.goto( base + '/wp-admin/admin.php?page=aaw-writer' );
	await page.waitForSelector( '[data-form="topics"]' );
	await shot( '1-home' );

	// Topic suggestions.
	await page.fill( '#aaw-focus', 'AI' );
	// Wait for the new run to be accepted, then for its suggestions (mocked APIs can finish instantly).
	await Promise.all( [
		page.waitForResponse( ( r ) => r.url().includes( '/aaw/v1/topics' ) && r.request().method() === 'POST' ),
		page.click( '[data-form="topics"] [type="submit"]' ),
	] );
	await page.waitForSelector( '[data-region="topics"] .aaw-topic', { timeout: 120000 } );
	await shot( '2-topics' );

	// Start an article from the first topic and wait for the outline.
	await page.click( '[data-action="write-topic"][data-index="0"]' );
	await page.waitForSelector( '[data-form="outline"]', { timeout: 180000 } );
	await shot( '3-outline' );

	// Edit the outline a little, then approve.
	await page.fill( '[data-form="outline"] [name="seo_title"]', 'كورسات الذكاء الاصطناعي المجانية 2026' );
	await page.click( '[data-action="add-section"]' );
	await page.fill( '.aaw-section:last-child [name="heading"]', 'نصائح للحصول على الشهادة مجانًا' );
	await page.click( '[data-form="outline"] [type="submit"]' );
	await page.waitForSelector( '.aaw-checklist', { timeout: 300000 } );
	await page.waitForSelector( '.aaw-backlinks, .aaw-result', { timeout: 60000 } );
	await shot( '4-done' );

	// Approve one backlink.
	const approve = await page.$( '[data-backlink][data-approve="1"]' );
	if ( approve ) {
		await approve.click();
		await page.waitForTimeout( 1500 );
	}

	// Open the draft in the block editor and look for invalid-block warnings.
	const editHref = await page.getAttribute( '.aaw-result a.button-primary', 'href' );
	const previewHref = await page.getAttribute( '.aaw-result a.button:not(.button-primary)', 'href' );
	await page.goto( editHref );
	await page.waitForSelector( '.editor-styles-wrapper, iframe[name="editor-canvas"]', { timeout: 60000 } );
	await page.waitForTimeout( 4000 );
	const frame = page.frame( { name: 'editor-canvas' } ) || page.mainFrame();
	const warnings = await frame.$$eval( '.block-editor-warning', ( els ) => els.map( ( e ) => e.textContent.slice( 0, 120 ) ) ).catch( () => [] );
	const blockCount = await frame.$$eval( '[data-block]', ( els ) => els.length ).catch( () => 0 );
	// Close the welcome guide if it shows.
	const guide = await page.$( '.components-guide .components-modal__header button, .edit-post-welcome-guide button[aria-label="Close"]' );
	if ( guide ) {
		await guide.click().catch( () => {} );
	}
	await page.waitForTimeout( 800 );
	await shot( '5-editor' );
	console.log( `editor: ${ blockCount } blocks, ${ warnings.length } invalid-block warnings` );
	warnings.forEach( ( w ) => console.log( '  WARNING: ' + w ) );

	await page.evaluate( () => window.scrollTo( 0, document.body.scrollHeight ) );
	await page.waitForTimeout( 500 );
	await page.screenshot( { path: `${ out }/6-editor-checklist${ suffix }.png` } );

	// Settings and costs.
	await page.goto( base + '/wp-admin/admin.php?page=aaw-settings' );
	await page.click( '#aaw-test-connections' );
	await page.waitForSelector( '.aaw-test', { timeout: 60000 } );
	await shot( '7-settings' );
	await page.goto( base + '/wp-admin/admin.php?page=aaw-costs' );
	await shot( '8-costs' );

	// Preview the post on the front end.
	await page.goto( previewHref );
	await page.waitForTimeout( 1000 );
	await shot( '9-preview' );

	console.log( errors.length ? 'JS errors:\n' + errors.join( '\n' ) : 'No JS errors.' );
	await browser.close();
	process.exit( warnings.length || errors.length ? 1 : 0 );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
