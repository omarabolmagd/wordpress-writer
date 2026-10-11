/**
 * Validates generated post content with the block editor's own code, exactly as
 * WordPress loads it, so drafts open without "This block contains unexpected or
 * invalid content".
 *
 * Usage: node tests/validate-blocks.cjs /path/to/wordpress content.html
 * Needs: npm install jsdom
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );

const wpDir = process.argv[ 2 ];
const contentFile = process.argv[ 3 ];
if ( ! wpDir || ! contentFile ) {
	console.error( 'Usage: node validate-blocks.cjs /path/to/wordpress content.html' );
	process.exit( 2 );
}

const dist = path.join( wpDir, 'wp-includes/js/dist' );
const deps = JSON.parse(
	execFileSync( 'php', [ '-r', `echo json_encode( array_map( fn( $x ) => $x['dependencies'], include '${ path.join( wpDir, 'wp-includes/assets/script-loader-packages.php' ) }' ) );` ] ).toString()
);

const vendor = { react: 'vendor/react.js', 'react-dom': 'vendor/react-dom.js', 'react-jsx-runtime': 'vendor/react-jsx-runtime.js', lodash: 'vendor/lodash.js', moment: 'vendor/moment.js' };
const fileFor = ( handle ) => {
	if ( vendor[ handle ] ) {
		return vendor[ handle ];
	}
	if ( handle.startsWith( 'wp-' ) && deps[ handle.slice( 3 ) + '.js' ] ) {
		return handle.slice( 3 ) + '.js';
	}
	return null;
};

const order = [];
const seen = new Set();
const visit = ( handle ) => {
	if ( seen.has( handle ) ) {
		return;
	}
	seen.add( handle );
	const file = fileFor( handle );
	if ( ! file ) {
		return;
	}
	for ( const d of deps[ file ] || [] ) {
		visit( d );
	}
	order.push( file );
};
visit( 'wp-block-library' );

const virtualConsole = new VirtualConsole();
const dom = new JSDOM( '<!doctype html><html><head></head><body></body></html>', { runScripts: 'dangerously', pretendToBeVisual: true, virtualConsole, url: 'http://localhost/' } );
const { window } = dom;
window.matchMedia = window.matchMedia || ( () => ( { matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} } ) );
window.ResizeObserver = window.ResizeObserver || class { observe() {} unobserve() {} disconnect() {} };
window.IntersectionObserver = window.IntersectionObserver || class { observe() {} unobserve() {} disconnect() {} };
window.wpApiSettings = { root: 'http://localhost/wp-json/', nonce: 'x' };
window.TextEncoder = require( 'util' ).TextEncoder;
window.TextDecoder = require( 'util' ).TextDecoder;
const errors = [];
window.addEventListener( 'error', ( e ) => errors.push( e.message ) );

for ( const file of order ) {
	// Inject as classic <script> elements so globals behave exactly as in the browser.
	const script = window.document.createElement( 'script' );
	script.textContent = fs.readFileSync( path.join( dist, file ), 'utf8' );
	window.document.head.appendChild( script );
	if ( errors.length ) {
		console.error( 'While loading ' + file + ': ' + errors.splice( 0 ).join( '; ' ) );
	}
}

const wp = window.wp;
wp.blockLibrary.registerCoreBlocks();

// Yoast FAQ block, replicating Yoast's save() (packages/js/src/structured-data-blocks/faq).
const el = wp.element.createElement;
const RichText = wp.blockEditor.RichText;
const withSpace = ( node ) => el( wp.element.Fragment, null, node, ' ' );
wp.blocks.registerBlockType( 'yoast/faq-block', {
	apiVersion: 3,
	title: 'Yoast FAQ',
	category: 'widgets',
	attributes: { questions: { type: 'array' }, additionalListCssClasses: { type: 'string' } },
	save: ( { attributes } ) => {
		const blockProps = wp.blockEditor.useBlockProps.save( attributes );
		const classNames = [ 'schema-faq', blockProps.className ].filter( Boolean ).join( ' ' );
		return el(
			'div',
			{ className: classNames },
			( attributes.questions || [] ).map( ( q, i ) =>
				el(
					wp.element.Fragment,
					{ key: i },
					el(
						'div',
						{ className: 'schema-faq-section', id: q.id, key: q.id },
						withSpace( el( RichText.Content, { tagName: 'strong', className: 'schema-faq-question', value: q.question } ) ),
						withSpace( el( RichText.Content, { tagName: 'p', className: 'schema-faq-answer', value: q.answer } ) )
					),
					' '
				)
			)
		);
	},
} );

const content = fs.readFileSync( contentFile, 'utf8' );
const blocks = wp.blocks.parse( content );
let invalid = 0;
let total = 0;
const walk = ( list, depth ) => {
	for ( const b of list ) {
		if ( ! b.name ) {
			continue;
		}
		total++;
		if ( ! b.isValid ) {
			invalid++;
			console.log( 'INVALID ' + b.name );
			const issues = ( b.validationIssues || [] ).map( ( i ) => i.args && i.args.join( ' | ' ) ).join( '\n' );
			console.log( issues.slice( 0, 1500 ) );
		}
		walk( b.innerBlocks || [], depth + 1 );
	}
};
walk( blocks, 0 );
// Stricter: blocks the editor would silently rewrite (matched only an older, deprecated format).
let migrated = 0;
const norm = ( h ) => h.replace( /\s+/g, ' ' ).replace( /> </g, '><' ).trim();
for ( const b of blocks.filter( ( x ) => x.name ) ) {
	// Blocks with inner blocks store their children separately, so compare only leaf blocks.
	if ( ! b.innerBlocks.length && b.originalContent && norm( wp.blocks.getSaveContent( b.name, b.attributes, b.innerBlocks ) ) !== norm( b.originalContent ) ) {
		migrated++;
		console.log( 'REWRITTEN ON OPEN ' + b.name + '\n  saved:    ' + norm( b.originalContent ).slice( 0, 300 ) + '\n  expected: ' + norm( wp.blocks.getSaveContent( b.name, b.attributes, b.innerBlocks ) ).slice( 0, 300 ) );
	}
}
console.log( `${ total } blocks checked, ${ invalid } invalid, ${ migrated } would be rewritten by the editor` );
process.exit( invalid || migrated ? 1 : 0 );
