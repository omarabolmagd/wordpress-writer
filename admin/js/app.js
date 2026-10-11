/* Ana Afdal Writer — admin screens. Plain JavaScript, no build step. */
( function () {
	'use strict';

	const { __, sprintf } = wp.i18n;
	const apiFetch = wp.apiFetch;
	const cfg = window.aawConfig || {};
	const NS = '/' + ( cfg.ns || 'aaw/v1' );

	/* ---------------------------------------------------------- helpers */

	const esc = ( value ) =>
		String( value === undefined || value === null ? '' : value ).replace( /[&<>"']/g, ( c ) => ( {
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#39;',
		}[ c ] ) );

	const money = ( n ) => {
		const v = Number( n ) || 0;
		return '$' + v.toFixed( v > 0 && v < 0.1 ? 3 : 2 );
	};

	const when = ( ts ) => {
		if ( ! ts ) {
			return '';
		}
		try {
			return new Date( ts * 1000 ).toLocaleString( ( cfg.locale || 'en' ).replace( '_', '-' ), { dateStyle: 'medium', timeStyle: 'short' } );
		} catch ( e ) {
			return new Date( ts * 1000 ).toLocaleString();
		}
	};

	const api = ( path, options = {} ) => apiFetch( { path: NS + path, ...options } );

	const errorText = ( err ) => ( err && err.message ) || __( 'Something went wrong. Try again.', 'ana-afdal-writer' );

	const TYPE_LABELS = () => ( {
		news: __( 'News update', 'ana-afdal-writer' ),
		list: __( 'Best-of list', 'ana-afdal-writer' ),
		guide: __( 'Full guide', 'ana-afdal-writer' ),
	} );

	const SOURCE_LABELS = () => ( {
		pexels: __( 'Pexels photo', 'ana-afdal-writer' ),
		ai: __( 'AI image (Gemini)', 'ana-afdal-writer' ),
		screenshot: __( 'Website screenshot', 'ana-afdal-writer' ),
		none: __( 'No image', 'ana-afdal-writer' ),
	} );

	const STEPS = () => [
		[ 'research', __( 'Research', 'ana-afdal-writer' ) ],
		[ 'outline', __( 'Outline', 'ana-afdal-writer' ) ],
		[ 'awaiting_approval', __( 'Your approval', 'ana-afdal-writer' ) ],
		[ 'write', __( 'Writing', 'ana-afdal-writer' ) ],
		[ 'images', __( 'Images', 'ana-afdal-writer' ) ],
		[ 'assemble', __( 'Links & draft', 'ana-afdal-writer' ) ],
		[ 'quality', __( 'Quality check', 'ana-afdal-writer' ) ],
		[ 'backlinks', __( 'Backlinks', 'ana-afdal-writer' ) ],
		[ 'done', __( 'Done', 'ana-afdal-writer' ) ],
	];

	const statusLabel = ( job ) => {
		if ( job.status === 'failed' ) {
			return __( 'Failed', 'ana-afdal-writer' );
		}
		if ( job.status === 'cancelled' ) {
			return __( 'Cancelled', 'ana-afdal-writer' );
		}
		if ( job.stage === 'awaiting_approval' ) {
			return __( 'Waiting for your approval', 'ana-afdal-writer' );
		}
		if ( job.status === 'done' ) {
			return job.post_status === 'publish' ? __( 'Published', 'ana-afdal-writer' ) : __( 'Draft ready', 'ana-afdal-writer' );
		}
		const step = STEPS().find( ( s ) => s[ 0 ] === job.stage );
		return step ? step[ 1 ] + '…' : __( 'Queued', 'ana-afdal-writer' );
	};

	const statusClass = ( job ) => {
		if ( job.status === 'failed' ) {
			return 'bad';
		}
		if ( job.status === 'cancelled' ) {
			return 'muted';
		}
		if ( job.stage === 'awaiting_approval' ) {
			return 'attention';
		}
		if ( job.status === 'done' ) {
			return 'good';
		}
		return 'busy';
	};

	const badge = ( job ) => `<span class="aaw-badge aaw-badge-${ statusClass( job ) }">${ esc( statusLabel( job ) ) }</span>`;

	const isActive = ( job ) => job && ( job.status === 'queued' || job.status === 'running' );

	/* ----------------------------------------------------- settings page */

	const testButton = document.getElementById( 'aaw-test-connections' );
	if ( testButton ) {
		const out = document.getElementById( 'aaw-test-results' );
		testButton.addEventListener( 'click', () => {
			testButton.disabled = true;
			out.textContent = __( 'Testing…', 'ana-afdal-writer' );
			api( '/test-connections', { method: 'POST' } )
				.then( ( res ) => {
					const names = { claude: 'Claude', gemini: 'Gemini', pexels: 'Pexels', screenshot: __( 'Screenshots', 'ana-afdal-writer' ), cover: __( 'Covers', 'ana-afdal-writer' ), time: __( 'Server time limit', 'ana-afdal-writer' ) };
					out.innerHTML = Object.keys( res )
						.map( ( k ) => `<span class="aaw-test ${ res[ k ].ok ? 'ok' : 'no' }">${ esc( names[ k ] || k ) }: ${ esc( res[ k ].message ) }</span>` )
						.join( ' ' );
				} )
				.catch( ( err ) => ( out.textContent = errorText( err ) ) )
				.finally( () => ( testButton.disabled = false ) );
		} );
	}

	const root = document.getElementById( 'aaw-app' );
	if ( ! root ) {
		return;
	}

	/* ------------------------------------------------------------- state */

	let overview = null;
	let pollTimer = null;
	let topicsJob = null;
	let currentJob = null;

	const stopPolling = () => {
		if ( pollTimer ) {
			clearTimeout( pollTimer );
			pollTimer = null;
		}
	};

	const notice = ( message, type = 'error' ) =>
		`<div class="notice notice-${ type === 'error' ? 'error' : 'success' } inline aaw-notice"><p>${ esc( message ) }</p></div>`;

	const flash = ( container, message, type ) => {
		const box = document.createElement( 'div' );
		box.innerHTML = notice( message, type );
		container.prepend( box.firstElementChild );
		setTimeout( () => {
			const n = container.querySelector( '.aaw-notice' );
			if ( n ) {
				n.remove();
			}
		}, 8000 );
	};

	/* --------------------------------------------------------- home view */

	const budgetHtml = () => {
		const o = overview;
		const warnings = [];
		if ( ! o.ready.anthropic ) {
			warnings.push(
				o.can_manage
					? sprintf( __( 'Add your Claude API key in %s to start writing.', 'ana-afdal-writer' ), `<a href="${ esc( o.settings_url ) }">${ esc( __( 'Settings', 'ana-afdal-writer' ) ) }</a>` )
					: esc( __( 'An administrator needs to add the Claude API key before you can write.', 'ana-afdal-writer' ) )
			);
		}
		const off = [];
		if ( ! o.ready.pexels ) {
			off.push( __( 'stock photos', 'ana-afdal-writer' ) );
		}
		if ( ! o.ready.gemini ) {
			off.push( __( 'AI images', 'ana-afdal-writer' ) );
		}
		if ( ! o.ready.screenshot ) {
			off.push( __( 'screenshots', 'ana-afdal-writer' ) );
		}
		if ( off.length && o.ready.anthropic ) {
			warnings.push( esc( sprintf( __( 'Off until their keys are added: %s.', 'ana-afdal-writer' ), off.join( '، ' ) ) ) );
		}
		const pct = o.cap > 0 ? Math.min( 100, Math.round( ( o.month_total / o.cap ) * 100 ) ) : 0;
		return `
			<div class="aaw-card aaw-budget">
				<p class="aaw-budget-figure"><strong>${ esc( money( o.month_total ) ) }</strong>
				${ o.cap > 0 ? esc( sprintf( __( 'of %s spent this month', 'ana-afdal-writer' ), money( o.cap ) ) ) : esc( __( 'spent this month (no cap)', 'ana-afdal-writer' ) ) }</p>
				${ o.cap > 0 ? `<div class="aaw-meter ${ o.cap_reached ? 'full' : '' }"><span style="width:${ pct }%"></span></div>` : '' }
				${ o.cap_reached ? `<p class="aaw-warn">${ esc( __( 'The monthly cap is reached, so new posts are paused. Posts already in progress can finish.', 'ana-afdal-writer' ) ) }</p>` : '' }
				${ warnings.map( ( w ) => `<p class="aaw-warn">${ w }</p>` ).join( '' ) }
			</div>`;
	};

	const categoryText = ( t ) => {
		if ( t.category_new_name ) {
			return sprintf( __( 'New category: %s', 'ana-afdal-writer' ), t.category_new_name );
		}
		const c = overview.categories.find( ( x ) => x.id === Number( t.category_existing_id ) );
		return c ? c.name : '';
	};

	const topicsHtml = () => {
		const job = topicsJob;
		let body = '';
		if ( isActive( job ) ) {
			body = `<p class="aaw-progress"><span class="spinner is-active"></span> ${ esc( __( 'Searching the web for what readers want right now. This takes about a minute.', 'ana-afdal-writer' ) ) }</p>`;
		} else if ( job && job.status === 'failed' ) {
			body = notice( job.error );
		} else if ( job && job.topics && job.topics.length ) {
			body = `<p class="description">${ esc( sprintf( __( 'Suggested %s', 'ana-afdal-writer' ), when( job.updated ) ) ) }</p><div class="aaw-topics">` +
				job.topics
					.map(
						( t, i ) => `
					<article class="aaw-topic">
						<h3 dir="auto">${ esc( t.title ) }</h3>
						<p class="aaw-meta">
							<span class="aaw-pill">${ esc( TYPE_LABELS()[ t.post_type ] || t.post_type ) }</span>
							<span class="aaw-pill ${ t.category_new_name ? 'aaw-pill-new' : '' }"><bdi>${ esc( categoryText( t ) ) }</bdi></span>
							<span class="aaw-keyphrase">${ esc( __( 'Keyphrase:', 'ana-afdal-writer' ) ) } <bdi>${ esc( t.focus_keyphrase ) }</bdi></span>
						</p>
						<p dir="auto">${ esc( t.why_now ) }</p>
						${ t.overlap_note ? `<p class="aaw-overlap" dir="auto">⚠ ${ esc( t.overlap_note ) }</p>` : '' }
						<button type="button" class="button button-primary" data-action="write-topic" data-index="${ i }">${ esc( __( 'Write this', 'ana-afdal-writer' ) ) }</button>
					</article>`
					)
					.join( '' ) +
				'</div>';
		}
		return `
			<section class="aaw-card">
				<h2>${ esc( __( '1. Get topic ideas', 'ana-afdal-writer' ) ) }</h2>
				<form class="aaw-inline-form" data-form="topics">
					<label class="screen-reader-text" for="aaw-focus">${ esc( __( 'Focus (optional)', 'ana-afdal-writer' ) ) }</label>
					<input type="text" id="aaw-focus" name="focus" class="regular-text" dir="auto" placeholder="${ esc( __( 'Optional focus, e.g. scholarships in Europe or AI tools for students', 'ana-afdal-writer' ) ) }">
					<button type="submit" class="button button-primary" ${ isActive( job ) || ! overview.ready.anthropic || overview.cap_reached ? 'disabled' : '' }>${ esc( __( 'Suggest topics', 'ana-afdal-writer' ) ) }</button>
				</form>
				<div data-region="topics">${ body }</div>
			</section>`;
	};

	const categorySelect = ( name, selectedId, newName, allowAuto ) => {
		const opts = overview.categories
			.map( ( c ) => `<option value="${ c.id }" ${ Number( selectedId ) === c.id && ! newName ? 'selected' : '' }>${ esc( c.name ) }</option>` )
			.join( '' );
		return `
			<select name="${ name }" data-category-select>
				${ allowAuto ? `<option value="">${ esc( __( 'Let the writer choose', 'ana-afdal-writer' ) ) }</option>` : '' }
				${ opts }
				<option value="__new" ${ newName ? 'selected' : '' }>${ esc( __( 'New category…', 'ana-afdal-writer' ) ) }</option>
			</select>
			<input type="text" name="${ name }_new" class="regular-text aaw-new-category" dir="auto" value="${ esc( newName || '' ) }" placeholder="${ esc( __( 'New category name', 'ana-afdal-writer' ) ) }" ${ newName ? '' : 'hidden' }>`;
	};

	const ownTopicHtml = () => `
		<section class="aaw-card">
			<h2>${ esc( __( '2. Or write about your own topic', 'ana-afdal-writer' ) ) }</h2>
			<form class="aaw-form" data-form="own-topic">
				<p><label>${ esc( __( 'Working title', 'ana-afdal-writer' ) ) }<br><input type="text" name="title" class="large-text" dir="auto" required></label></p>
				<p><label>${ esc( __( 'Focus keyphrase (what readers search)', 'ana-afdal-writer' ) ) }<br><input type="text" name="focus_keyphrase" class="large-text" dir="auto"></label></p>
				<p class="aaw-row">
					<label>${ esc( __( 'Post type', 'ana-afdal-writer' ) ) }<br>
						<select name="post_type">${ Object.entries( TYPE_LABELS() ).map( ( [ k, v ] ) => `<option value="${ k }" ${ k === 'guide' ? 'selected' : '' }>${ esc( v ) }</option>` ).join( '' ) }</select>
					</label>
					<label>${ esc( __( 'Category', 'ana-afdal-writer' ) ) }<br>${ categorySelect( 'category', '', '', true ) }</label>
				</p>
				<p><label>${ esc( __( 'Notes for the writer (optional)', 'ana-afdal-writer' ) ) }<br><textarea name="notes" rows="3" class="large-text" dir="auto"></textarea></label></p>
				<p><button type="submit" class="button button-primary" ${ ! overview.ready.anthropic || overview.cap_reached ? 'disabled' : '' }>${ esc( __( 'Start research', 'ana-afdal-writer' ) ) }</button></p>
			</form>
		</section>`;

	const articlesHtml = () => {
		const rows = overview.articles
			.map(
				( a ) => `
			<tr>
				<td><a href="#job=${ a.id }" dir="auto">${ esc( a.title || __( '(untitled)', 'ana-afdal-writer' ) ) }</a></td>
				<td>${ badge( a ) }</td>
				<td>${ esc( money( a.cost ) ) }</td>
				<td>${ esc( when( a.updated ) ) }</td>
			</tr>`
			)
			.join( '' );
		return `
			<section class="aaw-card">
				<h2>${ esc( __( 'Articles', 'ana-afdal-writer' ) ) }</h2>
				<table class="widefat striped aaw-table">
					<thead><tr><th>${ esc( __( 'Title', 'ana-afdal-writer' ) ) }</th><th>${ esc( __( 'Status', 'ana-afdal-writer' ) ) }</th><th>${ esc( __( 'Cost', 'ana-afdal-writer' ) ) }</th><th>${ esc( __( 'Updated', 'ana-afdal-writer' ) ) }</th></tr></thead>
					<tbody>${ rows || `<tr><td colspan="4">${ esc( __( 'No articles yet. Pick a topic above to start.', 'ana-afdal-writer' ) ) }</td></tr>` }</tbody>
				</table>
			</section>`;
	};

	const renderHome = () => {
		root.innerHTML = budgetHtml() + `<div class="aaw-grid">${ topicsHtml() }${ ownTopicHtml() }</div>` + articlesHtml();
		bindCategorySelects( root );
	};

	const pollTopics = () => {
		stopPolling();
		if ( ! isActive( topicsJob ) ) {
			return;
		}
		pollTimer = setTimeout( () => {
			api( '/jobs/' + topicsJob.id )
				.then( ( job ) => {
					topicsJob = job;
					if ( ! currentRoute().job ) {
						const region = root.querySelector( '[data-region="topics"]' );
						if ( isActive( job ) ) {
							pollTopics();
						} else if ( region ) {
							loadHome();
						}
					}
				} )
				.catch( () => pollTopics() );
		}, 5000 );
	};

	const loadHome = () => {
		stopPolling();
		return api( '/overview' )
			.then( ( o ) => {
				overview = o;
				topicsJob = o.topics_job;
				renderHome();
				pollTopics();
			} )
			.catch( ( err ) => ( root.innerHTML = notice( errorText( err ) ) ) );
	};

	const bindCategorySelects = ( scope ) => {
		scope.querySelectorAll( '[data-category-select]' ).forEach( ( select ) => {
			const input = select.parentElement.querySelector( '.aaw-new-category' );
			select.addEventListener( 'change', () => {
				if ( input ) {
					input.hidden = select.value !== '__new';
					if ( ! input.hidden ) {
						input.focus();
					}
				}
			} );
		} );
	};

	const startArticle = ( topic, button ) => {
		if ( button ) {
			button.disabled = true;
			button.textContent = __( 'Starting…', 'ana-afdal-writer' );
		}
		return api( '/articles', { method: 'POST', data: { topic } } )
			.then( ( job ) => {
				window.location.hash = 'job=' + job.id;
			} )
			.catch( ( err ) => {
				if ( button ) {
					button.disabled = false;
					button.textContent = __( 'Write this', 'ana-afdal-writer' );
				}
				flash( root, errorText( err ) );
			} );
	};

	/* ---------------------------------------------------------- job view */

	const stepsHtml = ( job ) => {
		const steps = STEPS();
		const current = steps.findIndex( ( s ) => s[ 0 ] === job.stage );
		return `<ol class="aaw-steps">${ steps
			.map( ( [ key, label ], i ) => {
				let cls = '';
				if ( job.status === 'done' || i < current ) {
					cls = 'done';
				} else if ( i === current ) {
					cls = job.status === 'failed' ? 'failed' : 'current';
				}
				return `<li class="${ cls }" data-step="${ key }">${ esc( label ) }</li>`;
			} )
			.join( '' ) }</ol>`;
	};

	const sourceField = ( section, i ) => {
		const src = section.image.source;
		const avail = overview.image_sources;
		const options = [ 'pexels', 'ai', 'screenshot', 'none' ]
			.filter( ( s ) => avail.includes( s ) || s === src )
			.map( ( s ) => `<option value="${ s }" ${ s === src ? 'selected' : '' } ${ ! avail.includes( s ) ? 'disabled' : '' }>${ esc( SOURCE_LABELS()[ s ] ) }</option>` )
			.join( '' );
		return `
			<div class="aaw-image-plan" data-image="${ i }">
				<label>${ esc( __( 'Image', 'ana-afdal-writer' ) ) }
					<select name="image_source">${ options }</select>
				</label>
				<label data-show="pexels" ${ src === 'pexels' ? '' : 'hidden' }>${ esc( __( 'Search words (English)', 'ana-afdal-writer' ) ) }
					<input type="text" name="image_query" value="${ esc( section.image.query ) }" dir="ltr"></label>
				<label data-show="ai" ${ src === 'ai' ? '' : 'hidden' }>${ esc( __( 'What to draw (English)', 'ana-afdal-writer' ) ) }
					<textarea name="image_prompt" rows="2" dir="ltr">${ esc( section.image.prompt ) }</textarea></label>
				<label data-show="screenshot" ${ src === 'screenshot' ? '' : 'hidden' }>${ esc( __( 'Page to capture', 'ana-afdal-writer' ) ) }
					<input type="url" name="image_url" value="${ esc( section.image.url ) }" dir="ltr"></label>
				<label data-hide="none" ${ src === 'none' ? 'hidden' : '' }>${ esc( __( 'Alt text (Arabic)', 'ana-afdal-writer' ) ) }
					<input type="text" name="image_alt" value="${ esc( section.image.alt ) }" dir="auto"></label>
			</div>`;
	};

	const sectionHtml = ( section, i ) => `
		<li class="aaw-section" data-section="${ i }">
			<div class="aaw-section-head">
				<span class="aaw-section-num">${ i + 1 }</span>
				<input type="text" name="heading" value="${ esc( section.heading ) }" class="large-text" dir="auto" aria-label="${ esc( __( 'Section heading', 'ana-afdal-writer' ) ) }">
				<button type="button" class="button-link" data-action="up" aria-label="${ esc( __( 'Move up', 'ana-afdal-writer' ) ) }">▲</button>
				<button type="button" class="button-link" data-action="down" aria-label="${ esc( __( 'Move down', 'ana-afdal-writer' ) ) }">▼</button>
				<button type="button" class="button-link aaw-danger" data-action="remove">${ esc( __( 'Remove', 'ana-afdal-writer' ) ) }</button>
			</div>
			<label class="aaw-points">${ esc( __( 'Notes for the writer, one per line', 'ana-afdal-writer' ) ) }
				<textarea name="key_points" rows="3" dir="auto">${ esc( ( section.key_points || [] ).join( '\n' ) ) }</textarea></label>
			${ sourceField( section, i ) }
		</li>`;

	const outlineHtml = ( job ) => {
		const o = job.outline;
		const typeOptions = Object.entries( overview.templates )
			.map( ( [ k, t ] ) => `<option value="${ k }" ${ k === o.post_type ? 'selected' : '' }>${ esc( TYPE_LABELS()[ k ] || t.label ) } (${ t.words[ 0 ] }–${ t.words[ 1 ] })</option>` )
			.join( '' );
		const authorOptions = overview.authors.map( ( a ) => `<option value="${ a.id }" ${ a.id === Number( o.author_id ) ? 'selected' : '' }>${ esc( a.name ) }</option>` ).join( '' );
		const sources = job.sources
			.map(
				( s ) => `<li><label><input type="checkbox" name="external" value="${ esc( s.url ) }" ${ o.external_sources.includes( s.url ) ? 'checked' : '' }>
				<a href="${ esc( s.url ) }" target="_blank" rel="noopener noreferrer">${ esc( s.title || s.url ) }</a> ${ s.cited ? `<span class="aaw-pill">${ esc( __( 'cited', 'ana-afdal-writer' ) ) }</span>` : '' }</label></li>`
			)
			.join( '' );
		const links = job.outline_links
			.map( ( l ) => `<li><label><input type="checkbox" name="internal" value="${ l.id }" checked> <a href="${ esc( l.url ) }" target="_blank" rel="noopener noreferrer">${ esc( l.title ) }</a></label></li>` )
			.join( '' );
		const descLen = ( o.meta_description || '' ).length;

		return `
			<form class="aaw-card aaw-outline" data-form="outline">
				<h2>${ esc( __( 'Review the outline', 'ana-afdal-writer' ) ) }</h2>
				<p class="description">${ esc( __( 'Change anything you like. Nothing is written or paid for beyond this point until you approve.', 'ana-afdal-writer' ) ) }</p>

				<div class="aaw-fields">
					<label>${ esc( __( 'Title (H1)', 'ana-afdal-writer' ) ) }<input type="text" name="title" value="${ esc( o.title ) }" class="large-text" dir="auto" required></label>
					<label>${ esc( __( 'SEO title', 'ana-afdal-writer' ) ) }<input type="text" name="seo_title" value="${ esc( o.seo_title ) }" class="large-text" dir="auto"></label>
					<label>${ esc( __( 'Meta description', 'ana-afdal-writer' ) ) } <span class="aaw-counter" data-counter>${ descLen }</span>
						<textarea name="meta_description" rows="2" class="large-text" dir="auto">${ esc( o.meta_description ) }</textarea></label>
					<label>${ esc( __( 'Focus keyphrase', 'ana-afdal-writer' ) ) }<input type="text" name="focus_keyphrase" value="${ esc( o.focus_keyphrase ) }" class="large-text" dir="auto"></label>
					<label>${ esc( __( 'URL slug (English)', 'ana-afdal-writer' ) ) }<input type="text" name="slug" value="${ esc( o.slug ) }" class="large-text" dir="ltr" pattern="[a-z0-9\\-]+"></label>
					<div class="aaw-row">
						<label>${ esc( __( 'Post type', 'ana-afdal-writer' ) ) }<select name="post_type">${ typeOptions }</select></label>
						<label>${ esc( __( 'Category', 'ana-afdal-writer' ) ) }${ categorySelect( 'category', o.category_existing_id, o.category_new_name, false ) }</label>
						<label>${ esc( __( 'Author', 'ana-afdal-writer' ) ) }<select name="author_id">${ authorOptions }</select></label>
					</div>
				</div>

				<h3>${ esc( __( 'Sections', 'ana-afdal-writer' ) ) }</h3>
				<p class="description">${ esc( __( 'The introduction, FAQ, summary and Telegram invitation are added automatically.', 'ana-afdal-writer' ) ) }</p>
				<ol class="aaw-sections">${ o.sections.map( sectionHtml ).join( '' ) }</ol>
				<p><button type="button" class="button" data-action="add-section">${ esc( __( 'Add section', 'ana-afdal-writer' ) ) }</button></p>

				<h3>${ esc( __( 'FAQ questions', 'ana-afdal-writer' ) ) }</h3>
				<textarea name="faq_questions" rows="5" class="large-text" dir="auto">${ esc( o.faq_questions.join( '\n' ) ) }</textarea>

				<div class="aaw-columns">
					<div>
						<h3>${ esc( __( 'Internal links', 'ana-afdal-writer' ) ) }</h3>
						<ul class="aaw-checks">${ links || `<li>${ esc( __( 'None suggested.', 'ana-afdal-writer' ) ) }</li>` }</ul>
					</div>
					<div>
						<h3>${ esc( __( 'Sources to cite', 'ana-afdal-writer' ) ) }</h3>
						<p class="description">${ esc( __( 'Ticked sources are offered to the writer; each link is checked live before the draft is saved.', 'ana-afdal-writer' ) ) }</p>
						<ul class="aaw-checks aaw-sources">${ sources || `<li>${ esc( __( 'No sources found.', 'ana-afdal-writer' ) ) }</li>` }</ul>
					</div>
				</div>

				<p class="aaw-actions">
					<button type="submit" class="button button-primary button-hero">${ esc( __( 'Approve and write', 'ana-afdal-writer' ) ) }</button>
					<span class="description">${ esc( __( 'Writing, images and checks take about 3–6 minutes. You can leave this page.', 'ana-afdal-writer' ) ) }</span>
				</p>
			</form>`;
	};

	const collectOutline = ( form, job ) => {
		const val = ( name ) => ( form.querySelector( `[name="${ name }"]` ) || {} ).value || '';
		const catValue = val( 'category' );
		const sections = [ ...form.querySelectorAll( '.aaw-section' ) ].map( ( li ) => {
			const q = ( n ) => ( li.querySelector( `[name="${ n }"]` ) || {} ).value || '';
			return {
				heading: q( 'heading' ).trim(),
				key_points: q( 'key_points' ).split( '\n' ).map( ( s ) => s.trim() ).filter( Boolean ),
				image: { source: q( 'image_source' ) || 'none', query: q( 'image_query' ), prompt: q( 'image_prompt' ), url: q( 'image_url' ), alt: q( 'image_alt' ) },
			};
		} );
		return {
			title: val( 'title' ),
			seo_title: val( 'seo_title' ),
			meta_description: val( 'meta_description' ),
			focus_keyphrase: val( 'focus_keyphrase' ),
			slug: val( 'slug' ),
			post_type: val( 'post_type' ),
			author_id: Number( val( 'author_id' ) ),
			angle: job.outline.angle,
			category_existing_id: catValue && catValue !== '__new' ? Number( catValue ) : null,
			category_new_name: catValue === '__new' ? val( 'category_new' ).trim() : null,
			sections: sections.filter( ( s ) => s.heading ),
			faq_questions: val( 'faq_questions' ).split( '\n' ).map( ( s ) => s.trim() ).filter( Boolean ),
			internal_link_ids: [ ...form.querySelectorAll( '[name="internal"]:checked' ) ].map( ( c ) => Number( c.value ) ),
			external_sources: [ ...form.querySelectorAll( '[name="external"]:checked' ) ].map( ( c ) => c.value ),
		};
	};

	const bindOutline = ( form, job ) => {
		bindCategorySelects( form );
		const list = form.querySelector( '.aaw-sections' );
		const renumber = () => list.querySelectorAll( '.aaw-section-num' ).forEach( ( n, i ) => ( n.textContent = i + 1 ) );

		form.addEventListener( 'input', ( e ) => {
			if ( e.target.name === 'meta_description' ) {
				const c = form.querySelector( '[data-counter]' );
				const n = e.target.value.length;
				c.textContent = n;
				c.classList.toggle( 'off', n < 120 || n > 155 );
			}
		} );
		form.addEventListener( 'change', ( e ) => {
			if ( e.target.name === 'image_source' ) {
				const plan = e.target.closest( '.aaw-image-plan' );
				plan.querySelectorAll( '[data-show]' ).forEach( ( el ) => ( el.hidden = el.dataset.show !== e.target.value ) );
				plan.querySelectorAll( '[data-hide]' ).forEach( ( el ) => ( el.hidden = el.dataset.hide === e.target.value ) );
			}
		} );
		form.addEventListener( 'click', ( e ) => {
			const btn = e.target.closest( '[data-action]' );
			if ( ! btn ) {
				return;
			}
			const li = btn.closest( '.aaw-section' );
			switch ( btn.dataset.action ) {
				case 'up':
					if ( li.previousElementSibling ) {
						list.insertBefore( li, li.previousElementSibling );
					}
					break;
				case 'down':
					if ( li.nextElementSibling ) {
						list.insertBefore( li.nextElementSibling, li );
					}
					break;
				case 'remove':
					li.remove();
					break;
				case 'add-section': {
					const tmp = document.createElement( 'ol' );
					tmp.innerHTML = sectionHtml( { heading: '', key_points: [], image: { source: 'none', query: '', prompt: '', url: '', alt: '' } }, list.children.length );
					list.appendChild( tmp.firstElementChild );
					list.lastElementChild.querySelector( '[name="heading"]' ).focus();
					break;
				}
				default:
					return;
			}
			renumber();
		} );
		form.addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			const outline = collectOutline( form, job );
			if ( ! outline.sections.length ) {
				flash( form, __( 'Add at least one section.', 'ana-afdal-writer' ) );
				return;
			}
			if ( outline.category_existing_id === null && ! outline.category_new_name ) {
				flash( form, __( 'Name the new category, or pick an existing one.', 'ana-afdal-writer' ) );
				return;
			}
			const submit = form.querySelector( '[type="submit"]' );
			submit.disabled = true;
			submit.textContent = __( 'Starting…', 'ana-afdal-writer' );
			api( `/jobs/${ job.id }/approve`, { method: 'POST', data: { outline } } )
				.then( ( updated ) => {
					currentJob = updated;
					renderJob();
					pollJob();
				} )
				.catch( ( err ) => {
					submit.disabled = false;
					submit.textContent = __( 'Approve and write', 'ana-afdal-writer' );
					flash( form, errorText( err ) );
				} );
		} );
	};

	const checklistHtml = ( items ) => {
		if ( ! items.length ) {
			return '';
		}
		const order = { warn: 0, info: 1, pass: 2 };
		const sorted = [ ...items ].sort( ( a, b ) => ( order[ a.status ] ?? 3 ) - ( order[ b.status ] ?? 3 ) );
		const warns = items.filter( ( i ) => i.status === 'warn' ).length;
		return `
			<section class="aaw-card">
				<h2>${ esc( __( 'Review checklist', 'ana-afdal-writer' ) ) }</h2>
				<p>${ esc( warns ? sprintf( __( '%d item(s) need a look before you publish.', 'ana-afdal-writer' ), warns ) : __( 'Everything passed. Give it a final read and publish.', 'ana-afdal-writer' ) ) }</p>
				<ul class="aaw-checklist">${ sorted
					.map( ( i ) => `<li class="aaw-check aaw-check-${ esc( i.status ) }"><span class="aaw-check-icon" aria-hidden="true">${ i.status === 'pass' ? '✓' : i.status === 'info' ? 'ℹ' : '!' }</span> <strong>${ esc( i.label ) }</strong>${ i.note ? ` — <span dir="auto">${ esc( i.note ) }</span>` : '' }</li>` )
					.join( '' ) }</ul>
			</section>`;
	};

	const backlinksHtml = ( job ) => {
		if ( job.status !== 'done' ) {
			return '';
		}
		const published = job.post_status === 'publish';
		const rows = job.backlinks
			.map( ( b, i ) => {
				const highlighted = esc( b.paragraph ).replace( esc( b.anchor ), () => `<mark>${ esc( b.anchor ) }</mark>` );
				let actions = '';
				if ( b.status === 'suggested' ) {
					actions = `<button type="button" class="button button-primary" data-backlink="${ i }" data-approve="1">${ esc( __( 'Approve', 'ana-afdal-writer' ) ) }</button>
						<button type="button" class="button" data-backlink="${ i }" data-approve="0">${ esc( __( 'Dismiss', 'ana-afdal-writer' ) ) }</button>`;
				} else {
					const labels = {
						approved: published ? __( 'Approved', 'ana-afdal-writer' ) : __( 'Approved — added when you publish', 'ana-afdal-writer' ),
						applied: __( 'Link added', 'ana-afdal-writer' ),
						dismissed: __( 'Dismissed', 'ana-afdal-writer' ),
						failed: __( 'Could not add', 'ana-afdal-writer' ),
					};
					actions = `<span class="aaw-badge aaw-badge-${ b.status === 'applied' ? 'good' : b.status === 'failed' ? 'bad' : 'muted' }">${ esc( labels[ b.status ] || b.status ) }</span> ${ b.note ? esc( b.note ) : '' }`;
				}
				return `
					<li class="aaw-backlink">
						<p><strong>${ esc( b.title ) }</strong></p>
						<blockquote dir="auto">…${ highlighted }…</blockquote>
						<p class="description" dir="auto">${ esc( b.reason ) }</p>
						<p>${ actions }</p>
					</li>`;
			} )
			.join( '' );
		return `
			<section class="aaw-card">
				<h2>${ esc( __( 'Links from older posts', 'ana-afdal-writer' ) ) }</h2>
				<p class="description">${ esc( __( 'Approved links are added to the older posts once this post is published, so no reader lands on a draft.', 'ana-afdal-writer' ) ) }</p>
				${ rows ? `<ul class="aaw-backlinks">${ rows }</ul>` : `<p>${ esc( __( 'No good spots found in older posts this time.', 'ana-afdal-writer' ) ) }</p>` }
			</section>`;
	};

	const sourcesHtml = ( job ) => {
		if ( ! job.sources.length ) {
			return '';
		}
		const used = ( job.link_report && job.link_report.external ) || [];
		return `
			<details class="aaw-card">
				<summary><strong>${ esc( sprintf( __( 'Research sources (%d)', 'ana-afdal-writer' ), job.sources.length ) ) }</strong></summary>
				<ul class="aaw-sources">${ job.sources
					.map( ( s ) => `<li><a href="${ esc( s.url ) }" target="_blank" rel="noopener noreferrer">${ esc( s.title || s.url ) }</a> ${ used.includes( s.url ) ? `<span class="aaw-pill">${ esc( __( 'linked in post', 'ana-afdal-writer' ) ) }</span>` : '' }</li>` )
					.join( '' ) }</ul>
			</details>`;
	};

	const logHtml = ( job ) => `
		<details class="aaw-card">
			<summary><strong>${ esc( __( 'Activity', 'ana-afdal-writer' ) ) }</strong></summary>
			<ul class="aaw-log">${ job.log
				.slice()
				.reverse()
				.map( ( l ) => `<li><time>${ esc( when( l.t ) ) }</time> ${ esc( l.m ) }</li>` )
				.join( '' ) }</ul>
		</details>`;

	const renderJob = () => {
		const job = currentJob;
		const busy = isActive( job );
		let main = '';

		if ( job.status === 'failed' ) {
			main += `<div class="notice notice-error inline"><p><strong>${ esc( __( 'This step failed:', 'ana-afdal-writer' ) ) }</strong> <span dir="auto">${ esc( job.error ) }</span></p>
				${ job.can_retry ? `<p><button type="button" class="button button-primary" data-job-action="retry">${ esc( __( 'Try this step again', 'ana-afdal-writer' ) ) }</button></p>` : '' }</div>`;
		}
		if ( busy ) {
			const hint = job.stage === 'research' || job.stage === 'outline' ? __( 'Researching and planning. The outline is usually ready in 1–3 minutes.', 'ana-afdal-writer' ) : __( 'Working on your draft. You can leave this page and come back.', 'ana-afdal-writer' );
			main += `<p class="aaw-progress"><span class="spinner is-active"></span> ${ esc( hint ) }</p>`;
		}
		if ( job.post_id ) {
			main += `
				<section class="aaw-card aaw-result">
					<h2>${ esc( job.post_status === 'publish' ? __( 'Published post', 'ana-afdal-writer' ) : __( 'Your draft', 'ana-afdal-writer' ) ) }</h2>
					<p>${ esc( sprintf( __( 'About %d words.', 'ana-afdal-writer' ), job.words ) ) } ${ job.link_report ? esc( sprintf( __( '%1$d internal and %2$d external links.', 'ana-afdal-writer' ), job.link_report.internal.length, job.link_report.external.length ) ) : '' }</p>
					<p>
						<a class="button button-primary" href="${ esc( job.edit_url ) }">${ esc( __( 'Open in the editor', 'ana-afdal-writer' ) ) }</a>
						<a class="button" href="${ esc( job.post_status === 'publish' ? job.view_url : job.preview_url ) }" target="_blank" rel="noopener">${ esc( job.post_status === 'publish' ? __( 'View', 'ana-afdal-writer' ) : __( 'Preview', 'ana-afdal-writer' ) ) }</a>
					</p>
				</section>`;
		}
		if ( job.stage === 'awaiting_approval' && job.status === 'waiting' && job.outline ) {
			main += outlineHtml( job );
		}
		main += checklistHtml( job.checklist ) + backlinksHtml( job ) + sourcesHtml( job ) + logHtml( job );

		const canCancel = busy || ( job.status === 'waiting' );
		root.innerHTML = `
			<p><a href="#" data-nav="home">${ cfg.isRtl ? '→' : '←' } ${ esc( __( 'All articles', 'ana-afdal-writer' ) ) }</a></p>
			<header class="aaw-job-head">
				<h2 dir="auto">${ esc( job.title || __( '(untitled)', 'ana-afdal-writer' ) ) }</h2>
				<p>${ badge( job ) } <span class="aaw-cost">${ esc( sprintf( __( 'Cost so far: %s', 'ana-afdal-writer' ), money( job.cost ) ) ) }</span>
				${ canCancel ? `<button type="button" class="button-link aaw-danger" data-job-action="cancel">${ esc( __( 'Cancel', 'ana-afdal-writer' ) ) }</button>` : '' }</p>
			</header>
			${ stepsHtml( job ) }
			${ main }`;

		const form = root.querySelector( '[data-form="outline"]' );
		if ( form ) {
			bindOutline( form, job );
		}
	};

	const pollJob = () => {
		stopPolling();
		if ( ! isActive( currentJob ) ) {
			return;
		}
		pollTimer = setTimeout( () => {
			const id = currentJob.id;
			api( '/jobs/' + id )
				.then( ( job ) => {
					if ( currentRoute().job !== id ) {
						return;
					}
					const changed = job.stage !== currentJob.stage || job.status !== currentJob.status || job.log.length !== currentJob.log.length;
					currentJob = job;
					if ( changed ) {
						renderJob();
					}
					pollJob();
				} )
				.catch( () => pollJob() );
		}, 4000 );
	};

	const loadJob = ( id ) => {
		stopPolling();
		const ready = overview ? Promise.resolve() : api( '/overview' ).then( ( o ) => ( overview = o ) );
		return ready
			.then( () => api( '/jobs/' + id ) )
			.then( ( job ) => {
				currentJob = job;
				renderJob();
				pollJob();
			} )
			.catch( ( err ) => ( root.innerHTML = `<p><a href="#" data-nav="home">${ cfg.isRtl ? '→' : '←' } ${ esc( __( 'All articles', 'ana-afdal-writer' ) ) }</a></p>` + notice( errorText( err ) ) ) );
	};

	/* ------------------------------------------------------------ events */

	root.addEventListener( 'submit', ( e ) => {
		const form = e.target;
		if ( form.dataset.form === 'topics' ) {
			e.preventDefault();
			const button = form.querySelector( '[type="submit"]' );
			button.disabled = true;
			api( '/topics', { method: 'POST', data: { focus: form.focus.value } } )
				.then( ( job ) => {
					topicsJob = job;
					renderHome();
					pollTopics();
				} )
				.catch( ( err ) => {
					button.disabled = false;
					flash( root, errorText( err ) );
				} );
		}
		if ( form.dataset.form === 'own-topic' ) {
			e.preventDefault();
			const cat = form.category.value;
			startArticle(
				{
					title: form.title.value,
					focus_keyphrase: form.focus_keyphrase.value || form.title.value,
					post_type: form.post_type.value,
					category_existing_id: cat && cat !== '__new' ? Number( cat ) : null,
					category_new_name: cat === '__new' ? form.category_new.value : null,
					notes: form.notes.value,
				},
				form.querySelector( '[type="submit"]' )
			);
		}
	} );

	root.addEventListener( 'click', ( e ) => {
		const nav = e.target.closest( '[data-nav="home"]' );
		if ( nav ) {
			e.preventDefault();
			window.location.hash = '';
			return;
		}
		const write = e.target.closest( '[data-action="write-topic"]' );
		if ( write && topicsJob ) {
			startArticle( topicsJob.topics[ Number( write.dataset.index ) ], write );
			return;
		}
		const jobAction = e.target.closest( '[data-job-action]' );
		if ( jobAction && currentJob ) {
			const action = jobAction.dataset.jobAction;
			if ( action === 'cancel' && ! window.confirm( __( 'Stop working on this article? Anything already saved stays.', 'ana-afdal-writer' ) ) ) {
				return;
			}
			jobAction.disabled = true;
			api( `/jobs/${ currentJob.id }/${ action }`, { method: 'POST' } )
				.then( ( job ) => {
					currentJob = job;
					renderJob();
					pollJob();
				} )
				.catch( ( err ) => flash( root, errorText( err ) ) );
			return;
		}
		const bl = e.target.closest( '[data-backlink]' );
		if ( bl && currentJob ) {
			bl.disabled = true;
			api( `/jobs/${ currentJob.id }/backlinks/${ bl.dataset.backlink }`, { method: 'POST', data: { approve: bl.dataset.approve === '1' } } )
				.then( ( job ) => {
					currentJob = job;
					renderJob();
				} )
				.catch( ( err ) => flash( root, errorText( err ) ) );
		}
	} );

	/* ------------------------------------------------------------ router */

	const currentRoute = () => {
		const m = window.location.hash.match( /job=(\d+)/ );
		return { job: m ? Number( m[ 1 ] ) : 0 };
	};

	const route = () => {
		const r = currentRoute();
		if ( r.job ) {
			loadJob( r.job );
		} else {
			loadHome();
		}
	};

	window.addEventListener( 'hashchange', route );
	route();
} )();
