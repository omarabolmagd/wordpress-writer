<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * One article from topic to saved draft: research → outline (waits for approval)
 * → write → images → assemble. Quality checks and backlink suggestions follow.
 */
final class Article {

	private const MAX_IMAGES = 5;

	/* ----------------------------------------------------------------- start */

	public static function start( int $user_id, array $topic ): int {
		$topic = Topics::sanitize_topic( $topic, Site::categories() );
		if ( ! $topic ) {
			throw new \InvalidArgumentException( __( 'The topic needs a title.', 'ana-afdal-writer' ) );
		}
		$job_id = Jobs::create( Jobs::TYPE_ARTICLE, $user_id, $topic['title'], array( 'topic' => $topic ) );
		Jobs::update(
			$job_id,
			static function ( array $s ) use ( $topic ) {
				$s['title'] = $topic['title'];
				return $s;
			}
		);
		Jobs::schedule( $job_id, 'research' );
		return $job_id;
	}

	/* -------------------------------------------------------------- research */

	public static function research( int $job_id ): string {
		$state = Jobs::get( $job_id );
		$topic = $state['input']['topic'];

		// Start from saved research when earlier articles covered a related topic.
		$related = Library::related( $topic, $job_id );
		$earlier = $related;
		$ideas   = ! empty( $topic['ideas_id'] ) && Library::enabled() ? Library::get( (int) $topic['ideas_id'] ) : null;
		if ( $ideas && Library::KIND_IDEAS === $ideas['kind'] && $ideas['created'] >= time() - Library::max_age_days( (string) $topic['post_type'] ) * DAY_IN_SECONDS ) {
			$earlier[] = $ideas;
		} else {
			$ideas = null;
		}
		// Claude is told to search only for what the saved notes miss. When an earlier article was
		// on nearly the same topic, the search limit is also halved, so the saving is certain.
		// Notes from the topic search are a head start, not research for this article.
		$max      = (int) Settings::get( 'max_searches' );
		$strong   = array_filter( $related, static fn( $e ) => $e['score'] >= Library::STRONG_SCORE );
		$searches = $strong ? min( $max, max( 2, (int) ceil( $max / 2 ) ) ) : 0;
		$reused   = array_map(
			static fn( $e ) => array(
				'id'      => (int) $e['id'],
				'kind'    => (string) $e['kind'],
				'title'   => (string) $e['title'],
				'created' => (int) $e['created'],
			),
			$earlier
		);
		if ( $earlier ) {
			Progress::event( 'reuse', '', array( 'articles' => count( $related ), 'ideas' => (bool) $ideas ) );
			Jobs::log(
				$job_id,
				$related
					? sprintf( 'Starting from saved research of %d related article(s); up to %d new searches.', count( $related ), $searches ? $searches : $max )
					: 'Starting from the notes of the topic search that suggested this article.'
			);
		}

		list( $system, $prompt ) = Prompts::article_research( $topic, $earlier );

		Jobs::log( $job_id, 'Researching the topic on the web…' );
		$result = Claude::research( $system, $prompt, $job_id, 'research', $searches );
		if ( '' === $result['text'] ) {
			throw new \RuntimeException( 'Research came back empty. Try again.' );
		}

		// Keep the earlier sources the new notes rely on, so the article can still cite them.
		// Each is checked live again before the draft is saved.
		$sources = $result['sources'];
		foreach ( $earlier as $e ) {
			foreach ( $e['sources'] as $src ) {
				$url = (string) ( $src['url'] ?? '' );
				if ( '' === $url || isset( $sources[ $url ] ) ) {
					continue;
				}
				$mentioned = str_contains( $result['text'], untrailingslashit( $url ) );
				if ( $mentioned || ( Library::KIND_ARTICLE === $e['kind'] && ! empty( $src['cited'] ) ) ) {
					$sources[ $url ] = array_merge( $src, array( 'reused' => true ) );
				}
			}
		}

		$library_id = Library::save(
			array(
				'kind'        => Library::KIND_ARTICLE,
				'job_id'      => $job_id,
				'title'       => (string) $topic['title'],
				'keyphrase'   => (string) ( $topic['focus_keyphrase'] ?? '' ),
				'post_type'   => (string) ( $topic['post_type'] ?? '' ),
				'category_id' => (int) ( $topic['category_existing_id'] ?? 0 ),
				'notes'       => $result['text'],
				'sources'     => array_values( $sources ),
				'searches'    => max( $result['searches'], Costs::job_item_searches( $job_id, 'research' ) ),
				'cost'        => Costs::job_item_total( $job_id, 'research' ),
				'meta'        => array(
					'reused_from' => array_map( static fn( $e ) => (int) $e['id'], $related ),
					'ideas_id'    => $ideas ? (int) $ideas['id'] : 0,
				),
			)
		);
		Library::mark_used( wp_list_pluck( $earlier, 'id' ) );

		Jobs::update(
			$job_id,
			static function ( array $s ) use ( $result, $sources, $reused, $library_id ) {
				$s['data']['research'] = array(
					'notes'      => $result['text'],
					'sources'    => array_values( $sources ),
					'reused'     => $reused,
					'library_id' => $library_id,
				);
				return $s;
			}
		);
		Jobs::log( $job_id, sprintf( 'Research done: %d searches, %d sources.', $result['searches'], count( $sources ) ) );
		return 'outline';
	}

	/* --------------------------------------------------------------- outline */

	public static function outline( int $job_id ): ?string {
		$state      = Jobs::get( $job_id );
		$topic      = $state['input']['topic'];
		$research   = $state['data']['research'];
		$categories = Site::categories();
		$index      = Site::published_posts( 200 );

		list( $system, $prompt ) = Prompts::outline( $topic, $research['notes'], $research['sources'], $categories, $index, Images::available_sources() );
		$outline = Claude::json( $system, $prompt, Prompts::outline_schema(), $job_id, 'outline' );

		$outline['author_id'] = (int) $state['user_id'];
		$outline              = self::sanitize_outline( $outline, $research['sources'] );

		Jobs::update(
			$job_id,
			static function ( array $s ) use ( $outline ) {
				$s['data']['outline'] = $outline;
				$s['title']           = $outline['title'];
				return $s;
			}
		);
		Jobs::log( $job_id, 'Outline ready for your approval.' );
		Pipeline::wait( $job_id, 'awaiting_approval' );
		return null;
	}

	/**
	 * Validates an outline from Claude or from the approval form.
	 */
	public static function sanitize_outline( array $o, array $research_sources ): array {
		$categories = Site::categories();
		$published  = wp_list_pluck( Site::published_posts( 400 ), 'id' );
		$available  = Images::available_sources();
		$templates  = Prompts::templates();

		$out = array(
			'title'            => sanitize_text_field( (string) ( $o['title'] ?? '' ) ),
			'seo_title'        => sanitize_text_field( (string) ( $o['seo_title'] ?? '' ) ),
			'meta_description' => sanitize_text_field( (string) ( $o['meta_description'] ?? '' ) ),
			'focus_keyphrase'  => sanitize_text_field( (string) ( $o['focus_keyphrase'] ?? '' ) ),
			'slug'             => self::english_slug( (string) ( $o['slug'] ?? '' ) ),
			'post_type'        => isset( $templates[ $o['post_type'] ?? '' ] ) ? $o['post_type'] : 'guide',
			'angle'            => sanitize_text_field( (string) ( $o['angle'] ?? '' ) ),
			'author_id'        => (int) ( $o['author_id'] ?? 0 ),
			'sections'         => array(),
			'cover'            => self::sanitize_cover( (array) ( $o['cover'] ?? array() ) ),
			'faq_questions'    => array(),
			'internal_link_ids' => array(),
			'external_sources' => array(),
		);
		if ( '' === $out['title'] ) {
			throw new \InvalidArgumentException( __( 'The outline needs a title.', 'ana-afdal-writer' ) );
		}
		if ( '' === $out['seo_title'] ) {
			$out['seo_title'] = $out['title'];
		}
		if ( '' === $out['focus_keyphrase'] ) {
			$out['focus_keyphrase'] = $out['title'];
		}
		if ( '' === $out['slug'] ) {
			$out['slug'] = 'post-' . wp_date( 'Ymd-His' );
		}
		if ( ! $out['author_id'] || ! user_can( $out['author_id'], 'edit_posts' ) ) {
			$out['author_id'] = get_current_user_id();
		}

		// Category: an existing id, or a new name that is created when the draft is saved.
		$cat_id  = isset( $o['category_existing_id'] ) && null !== $o['category_existing_id'] ? (int) $o['category_existing_id'] : 0;
		$new_cat = sanitize_text_field( (string) ( $o['category_new_name'] ?? '' ) );
		if ( $cat_id && ! isset( $categories[ $cat_id ] ) ) {
			$cat_id = 0;
		}
		if ( ! $cat_id && $new_cat ) {
			$match = array_search( $new_cat, $categories, true );
			if ( false !== $match ) {
				$cat_id = (int) $match;
			}
		}
		if ( $cat_id ) {
			$new_cat = '';
		}
		if ( ! $cat_id && ! $new_cat ) {
			$cat_id = (int) get_option( 'default_category' );
		}
		$out['category_existing_id'] = $cat_id ? $cat_id : null;
		$out['category_new_name']    = $new_cat ? $new_cat : null;

		$images = 0;
		foreach ( (array) ( $o['sections'] ?? array() ) as $section ) {
			$heading = sanitize_text_field( (string) ( $section['heading'] ?? '' ) );
			if ( '' === $heading ) {
				continue;
			}
			$points = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $section['key_points'] ?? array() ) ) ) );
			$img    = (array) ( $section['image'] ?? array() );
			$plan   = array(
				'source' => in_array( $img['source'] ?? 'none', array( 'pexels', 'ai', 'screenshot', 'none' ), true ) ? $img['source'] : 'none',
				'query'  => sanitize_text_field( (string) ( $img['query'] ?? '' ) ),
				'prompt' => sanitize_textarea_field( (string) ( $img['prompt'] ?? '' ) ),
				'url'    => esc_url_raw( (string) ( $img['url'] ?? '' ), array( 'http', 'https' ) ),
				'alt'    => sanitize_text_field( (string) ( $img['alt'] ?? '' ) ),
			);
			// Drop slots that can't work: missing key, missing query/prompt/url, or over the image limit.
			$needs = array( 'pexels' => 'query', 'ai' => 'prompt', 'screenshot' => 'url' );
			if ( 'none' !== $plan['source'] ) {
				$field = $needs[ $plan['source'] ];
				if ( ! in_array( $plan['source'], $available, true ) || '' === $plan[ $field ] || $images >= self::MAX_IMAGES ) {
					$plan['source'] = 'none';
				} else {
					++$images;
					if ( '' === $plan['alt'] ) {
						$plan['alt'] = $heading;
					}
				}
			}
			$out['sections'][] = array(
				'heading'    => $heading,
				'key_points' => $points,
				'image'      => $plan,
			);
		}
		if ( ! $out['sections'] ) {
			throw new \InvalidArgumentException( __( 'The outline needs at least one section.', 'ana-afdal-writer' ) );
		}

		$out['faq_questions'] = array_slice( array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $o['faq_questions'] ?? array() ) ) ) ), 0, 8 );

		foreach ( (array) ( $o['internal_link_ids'] ?? array() ) as $id ) {
			if ( in_array( (int) $id, $published, true ) ) {
				$out['internal_link_ids'][] = (int) $id;
			}
		}
		$out['internal_link_ids'] = array_slice( array_values( array_unique( $out['internal_link_ids'] ) ), 0, 6 );

		$source_urls = wp_list_pluck( $research_sources, 'url' );
		foreach ( (array) ( $o['external_sources'] ?? array() ) as $url ) {
			if ( Links::is_allowed( (string) $url, $source_urls ) ) {
				$out['external_sources'][] = esc_url_raw( (string) $url );
			}
		}
		$out['external_sources'] = array_slice( array_values( array_unique( $out['external_sources'] ) ), 0, 6 );

		return $out;
	}

	/** The featured-image plan: a scene for Gemini, words for a stock photo and Arabic alt text. */
	public static function sanitize_cover( array $c ): array {
		return array(
			'prompt' => sanitize_textarea_field( (string) ( $c['prompt'] ?? '' ) ),
			'query'  => sanitize_text_field( (string) ( $c['query'] ?? '' ) ),
			'alt'    => sanitize_text_field( (string) ( $c['alt'] ?? '' ) ),
		);
	}

	public static function english_slug( string $slug ): string {
		$slug = remove_accents( strtolower( $slug ) );
		$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug );
		$slug = trim( preg_replace( '/-+/', '-', $slug ), '-' );
		// Keep at most 6 words and 60 characters.
		$words = array_slice( explode( '-', $slug ), 0, 6 );
		$slug  = implode( '-', $words );
		return trim( substr( $slug, 0, 60 ), '-' );
	}

	/** Called from the approval form: saves the edited outline and starts writing. */
	public static function approve( int $job_id, array $edited ): array {
		$state = Jobs::get( $job_id );
		if ( ! $state || Jobs::TYPE_ARTICLE !== $state['type'] || 'awaiting_approval' !== $state['stage'] ) {
			throw new \RuntimeException( __( 'This outline is not waiting for approval.', 'ana-afdal-writer' ) );
		}
		$outline = self::sanitize_outline( $edited, $state['data']['research']['sources'] ?? array() );
		$state   = Jobs::update(
			$job_id,
			static function ( array $s ) use ( $outline ) {
				$s['data']['outline'] = $outline;
				$s['title']           = $outline['title'];
				$s['log'][]           = array( 't' => time(), 'stage' => 'awaiting_approval', 'm' => 'Outline approved.' );
				return $s;
			}
		);
		Jobs::schedule( $job_id, 'write' );
		return Jobs::get( $job_id );
	}

	/* ----------------------------------------------------------------- write */

	public static function write( int $job_id ): string {
		$state    = Jobs::get( $job_id );
		$outline  = $state['data']['outline'];
		$research = $state['data']['research'];

		$link_posts = array();
		foreach ( Site::published_posts( 400 ) as $p ) {
			if ( in_array( $p['id'], $outline['internal_link_ids'], true ) ) {
				$link_posts[] = $p;
			}
		}
		$external = $outline['external_sources'];
		if ( count( $external ) < 2 ) {
			// Offer cited sources the outline didn't pick, so the writer can still cite 2.
			foreach ( $research['sources'] as $s ) {
				if ( ! empty( $s['cited'] ) && ! in_array( $s['url'], $external, true ) ) {
					$external[] = $s['url'];
				}
			}
			$external = array_slice( $external, 0, 6 );
		}

		list( $system, $prompt ) = Prompts::draft( $outline, $research['notes'], $link_posts, $external );
		Jobs::log( $job_id, 'Writing the article…' );
		$template = Prompts::templates()[ $outline['post_type'] ] ?? Prompts::templates()['guide'];
		Progress::set(
			array(
				'target'         => $template['words'],
				'sections_total' => count( $outline['sections'] ),
			)
		);
		$draft = Claude::json( $system, $prompt, Prompts::draft_schema(), $job_id, 'write' );

		// Keep the approved headings and order even if the reply drifted.
		$sections = array();
		foreach ( $outline['sections'] as $i => $planned ) {
			$written    = $draft['sections'][ $i ] ?? array( 'blocks' => array() );
			$sections[] = array(
				'heading' => $planned['heading'],
				'blocks'  => is_array( $written['blocks'] ?? null ) ? $written['blocks'] : array(),
			);
		}
		$draft['sections'] = $sections;

		Jobs::update(
			$job_id,
			static function ( array $s ) use ( $draft, $external ) {
				$s['data']['draft']             = $draft;
				$s['data']['allowed_external'] = $external;
				return $s;
			}
		);
		Jobs::log( $job_id, sprintf( 'Draft written: about %d words.', self::word_count( $draft ) ) );
		return 'images';
	}

	public static function word_count( array $draft ): int {
		$text = implode( ' ', self::draft_texts( $draft ) );
		return count( preg_split( '/\s+/u', trim( Links::strip( $text ) ), -1, PREG_SPLIT_NO_EMPTY ) );
	}

	/** Every reader-facing string in a draft, in reading order. */
	public static function draft_texts( array $draft ): array {
		$texts = array_merge( array(), (array) ( $draft['intro'] ?? array() ) );
		foreach ( (array) ( $draft['sections'] ?? array() ) as $section ) {
			$texts[] = (string) ( $section['heading'] ?? '' );
			foreach ( (array) ( $section['blocks'] ?? array() ) as $block ) {
				switch ( $block['kind'] ?? '' ) {
					case 'paragraph':
					case 'subheading':
						$texts[] = (string) $block['text'];
						break;
					case 'list':
						$texts = array_merge( $texts, array_map( 'strval', (array) $block['items'] ) );
						break;
					case 'table':
						$texts = array_merge( $texts, array_map( 'strval', (array) $block['headers'] ) );
						foreach ( (array) $block['rows'] as $row ) {
							$texts = array_merge( $texts, array_map( 'strval', (array) $row ) );
						}
						break;
				}
			}
		}
		foreach ( (array) ( $draft['faq'] ?? array() ) as $qa ) {
			$texts[] = (string) ( $qa['question'] ?? '' );
			$texts[] = (string) ( $qa['answer'] ?? '' );
		}
		return array_merge( $texts, (array) ( $draft['summary'] ?? array() ) );
	}

	/* ---------------------------------------------------------------- images */

	public static function images( int $job_id ): string {
		$state   = Jobs::get( $job_id );
		$outline = $state['data']['outline'];
		$done    = $state['data']['images'] ?? array( 'cover' => null, 'sections' => array() );
		$started = time();

		$record = static function ( array $done ) use ( $job_id ) {
			Jobs::update(
				$job_id,
				static function ( array $s ) use ( $done ) {
					$s['data']['images'] = $done;
					return $s;
				}
			);
		};

		$planned = array_keys( array_filter( $outline['sections'], static fn( $s ) => 'none' !== $s['image']['source'] ) );
		$total   = count( $planned ) + 1;

		if ( null === $done['cover'] ) {
			Progress::phase( 'images', 'cover', array( 'current' => 1, 'total' => $total ) );
			Progress::hold( 240 );
			$done['cover'] = self::make_cover( $job_id, $outline, (array) ( $outline['cover'] ?? array() ) );
			$record( $done );
			Progress::tick( true );
		}

		foreach ( $outline['sections'] as $i => $section ) {
			$plan = $section['image'];
			if ( 'none' === $plan['source'] || isset( $done['sections'][ $i ] ) ) {
				continue;
			}
			if ( time() - $started > 200 ) {
				// Continue in a fresh request so a slow image never hits the host's time limit.
				return 'images';
			}
			$position = (int) array_search( $i, $planned, true ) + 2;
			Progress::phase( 'images', $plan['source'], array( 'current' => $position, 'total' => $total ) );
			// Image services can take a while and don't report progress: reserve time so no other runner takes over.
			Progress::hold( 240 );
			try {
				$id                     = Images::fetch( $plan, $job_id, $outline['slug'] );
				$done['sections'][ $i ] = array( 'id' => $id, 'source' => $plan['source'] );
				Jobs::log( $job_id, sprintf( 'Image %d (%s) saved.', $i + 1, $plan['source'] ) );
			} catch ( \Throwable $e ) {
				$done['sections'][ $i ] = array( 'id' => 0, 'source' => $plan['source'], 'error' => $e->getMessage() );
				Jobs::log( $job_id, sprintf( 'Image %d (%s) failed: %s', $i + 1, $plan['source'], $e->getMessage() ) );
			}
			$record( $done );
			Progress::tick( true );
		}
		return 'assemble';
	}

	/**
	 * Makes the featured image for a job and logs how it went.
	 *
	 * @return array{id:int, source?:string, error?:string, prompt?:string}
	 */
	private static function make_cover( int $job_id, array $outline, array $plan, ?array $order = null ): array {
		$names = array(
			'ai'      => 'Gemini photo',
			'pexels'  => 'Pexels photo',
			'branded' => 'title cover',
		);
		try {
			$cover = Images::cover( $plan, $outline['title'], self::category_label( $outline ), $job_id, $outline['slug'], $order );
			$note  = '';
			foreach ( $cover['errors'] as $source => $error ) {
				$note .= sprintf( ' (%s failed: %s)', $names[ $source ] ?? $source, $error );
			}
			Jobs::log( $job_id, 'Featured image: ' . ( $names[ $cover['source'] ] ?? $cover['source'] ) . '.' . $note );
			return array(
				'id'     => $cover['id'],
				'source' => $cover['source'],
				'prompt' => 'ai' === $cover['source'] ? (string) ( $plan['prompt'] ?? '' ) : '',
				'errors' => $cover['errors'],
			);
		} catch ( \Throwable $e ) {
			Jobs::log( $job_id, 'Featured image failed: ' . $e->getMessage() );
			return array( 'id' => 0, 'error' => $e->getMessage() );
		}
	}

	/**
	 * Replaces the featured image of a finished article with a new one, from the scene the editor
	 * typed, the outline's scene, or (for articles planned before scenes existed) one Claude describes.
	 */
	public static function new_cover( int $job_id, string $scene = '' ): array {
		$state   = Jobs::get( $job_id );
		$outline = $state['data']['outline'] ?? null;
		$post_id = (int) ( $state['data']['post_id'] ?? 0 );
		if ( ! $outline || ! $post_id || ! get_post( $post_id ) ) {
			throw new \InvalidArgumentException( __( 'This article has no saved draft yet.', 'ana-afdal-writer' ) );
		}
		$plan  = (array) ( $outline['cover'] ?? array() );
		$scene = trim( $scene );
		if ( '' !== $scene ) {
			$plan['prompt'] = sanitize_textarea_field( $scene );
		} elseif ( '' === trim( (string) ( $plan['prompt'] ?? '' ) ) && Settings::has_key( 'anthropic_key' ) ) {
			list( $system, $prompt ) = Prompts::cover_scene( $outline['title'], $outline['focus_keyphrase'], (string) ( $outline['angle'] ?? '' ), self::category_label( $outline ) );
			$plan                    = self::sanitize_cover( Claude::json( $system, $prompt, Prompts::cover_schema(), $job_id, 'cover_scene' ) );
		}

		// Asked for by the editor: a Gemini photo or a clear error, never a quiet fallback.
		$cover = self::make_cover( $job_id, $outline, $plan, array( 'ai' ) );
		if ( empty( $cover['id'] ) ) {
			throw new \RuntimeException( (string) ( $cover['error'] ?? 'The featured image could not be made.' ) );
		}
		set_post_thumbnail( $post_id, (int) $cover['id'] );
		Media::attach_to_post( array( (int) $cover['id'] ), $post_id );

		Jobs::update(
			$job_id,
			static function ( array $s ) use ( $cover, $plan ) {
				$s['data']['images']['cover']   = $cover;
				$s['data']['outline']['cover'] = array_merge( (array) ( $s['data']['outline']['cover'] ?? array() ), $plan );
				foreach ( (array) ( $s['data']['checklist'] ?? array() ) as $i => $item ) {
					if ( 'featured_image' === ( $item['key'] ?? '' ) ) {
						$s['data']['checklist'][ $i ]['status'] = empty( $cover['errors'] ) ? 'pass' : 'warn';
						$s['data']['checklist'][ $i ]['note']   = empty( $cover['errors'] ) ? '' : implode( ' ', (array) $cover['errors'] );
					}
				}
				return $s;
			}
		);
		$checklist = Jobs::get( $job_id )['data']['checklist'] ?? null;
		if ( is_array( $checklist ) ) {
			update_post_meta( $post_id, Quality::META, wp_slash( wp_json_encode( $checklist, JSON_UNESCAPED_UNICODE ) ) );
		}
		return $cover;
	}

	private static function category_label( array $outline ): string {
		if ( $outline['category_new_name'] ) {
			return $outline['category_new_name'];
		}
		$cats = Site::categories();
		return $cats[ (int) $outline['category_existing_id'] ] ?? '';
	}

	/* -------------------------------------------------------------- assemble */

	public static function assemble( int $job_id ): string {
		$state    = Jobs::get( $job_id );
		$outline  = $state['data']['outline'];
		$draft    = $state['data']['draft'];
		$images   = $state['data']['images'] ?? array( 'cover' => null, 'sections' => array() );
		$allowed  = array_merge( $state['data']['allowed_external'] ?? array(), wp_list_pluck( $state['data']['research']['sources'] ?? array(), 'url' ) );
		$existing = (int) ( $state['data']['post_id'] ?? 0 );

		$published = array();
		foreach ( Site::published_posts( 400 ) as $p ) {
			$published[ $p['id'] ] = $p;
		}

		// 1. Check every external link once, live.
		$report   = array( 'internal' => array(), 'external' => array(), 'removed' => array(), 'unverified' => array() );
		$external = array();
		foreach ( self::draft_texts( $draft ) as $text ) {
			foreach ( Links::extract( $text ) as $link ) {
				if ( preg_match( '#^https?://#i', $link['target'] ) ) {
					$external[ $link['target'] ] = true;
				}
			}
		}
		$checked  = array();
		$to_check = array();
		foreach ( array_keys( $external ) as $url ) {
			if ( Links::is_allowed( $url, $allowed ) ) {
				$to_check[] = $url;
			} else {
				$checked[ $url ] = array( 'ok' => false, 'reason' => __( 'not one of the researched sources', 'ana-afdal-writer' ) );
			}
		}
		Progress::phase( 'links', '', array( 'total' => count( $to_check ) ) );
		Progress::hold( 120 );
		foreach ( Links::check_many( $to_check ) as $url => $result ) {
			$checked[ $url ] = array(
				'ok'      => $result['ok'] || $result['blocked'],
				'blocked' => $result['blocked'],
				/* translators: %d: HTTP status code */
				'reason'  => $result['ok'] ? '' : ( $result['blocked'] ? sprintf( __( 'site blocks automated checks (HTTP %d)', 'ana-afdal-writer' ), $result['status'] ) : ( $result['status'] ? sprintf( __( 'broken link (HTTP %d)', 'ana-afdal-writer' ), $result['status'] ) : __( 'site did not respond', 'ana-afdal-writer' ) ) ),
			);
		}
		Jobs::log( $job_id, sprintf( 'Checked %d external links.', count( $checked ) ) );
		Progress::phase( 'saving' );

		// 2. Resolver used while rendering: decides what stays a link.
		$resolve = static function ( string $target ) use ( &$report, $published, $checked ) {
			if ( preg_match( '/^post:(\d+)$/', $target, $m ) ) {
				$id = (int) $m[1];
				if ( isset( $published[ $id ] ) ) {
					$report['internal'][] = $id;
					return array( 'href' => $published[ $id ]['url'], 'external' => false );
				}
				$report['removed'][] = array( 'target' => $target, 'reason' => __( 'not a published post', 'ana-afdal-writer' ) );
				return null;
			}
			if ( isset( $checked[ $target ] ) ) {
				if ( $checked[ $target ]['ok'] ) {
					$report['external'][] = $target;
					if ( ! empty( $checked[ $target ]['blocked'] ) ) {
						$report['unverified'][] = array( 'target' => $target, 'reason' => $checked[ $target ]['reason'] );
					}
					return array( 'href' => $target, 'external' => true );
				}
				$report['removed'][] = array( 'target' => $target, 'reason' => $checked[ $target ]['reason'] );
				return null;
			}
			$report['removed'][] = array( 'target' => $target, 'reason' => __( 'invalid link', 'ana-afdal-writer' ) );
			return null;
		};
		$render = static fn( string $text ): string => Links::render( $text, $resolve );

		// 3. Build the blocks.
		$blocks = array();
		foreach ( (array) $draft['intro'] as $p ) {
			$blocks[] = Blocks::paragraph( $render( (string) $p ) );
		}
		$read_more = 0;
		foreach ( $draft['sections'] as $i => $section ) {
			$blocks[]     = Blocks::heading( Links::inline( $section['heading'] ), 2 );
			$image_id     = (int) ( $images['sections'][ $i ]['id'] ?? 0 );
			$image_block  = $image_id ? Blocks::image( $image_id, (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ), wp_kses_post( (string) get_post_field( 'post_excerpt', $image_id ) ) ) : '';
			$image_placed = '' === $image_block;
			foreach ( (array) $section['blocks'] as $block ) {
				switch ( $block['kind'] ?? '' ) {
					case 'paragraph':
						$blocks[] = Blocks::paragraph( $render( (string) $block['text'] ) );
						if ( ! $image_placed ) {
							$blocks[]     = $image_block;
							$image_placed = true;
						}
						break;
					case 'subheading':
						$blocks[] = Blocks::heading( Links::inline( Links::strip( (string) $block['text'] ) ), 3 );
						break;
					case 'list':
						$items = array_map( $render, array_map( 'strval', (array) $block['items'] ) );
						if ( $items ) {
							$blocks[] = Blocks::list( $items, ! empty( $block['ordered'] ) );
						}
						break;
					case 'table':
						$headers = array_map( $render, array_map( 'strval', (array) $block['headers'] ) );
						$rows    = array();
						foreach ( (array) $block['rows'] as $row ) {
							$rows[] = array_map( $render, array_map( 'strval', (array) $row ) );
						}
						if ( $rows ) {
							$blocks[] = Blocks::table( $headers, $rows );
						}
						break;
					case 'read_more':
						$id = (int) ( $block['post_id'] ?? 0 );
						if ( $read_more < 2 && isset( $published[ $id ] ) ) {
							$report['internal'][] = $id;
							$blocks[]             = Blocks::paragraph( '<strong>اقرأ أيضًا:</strong> <a href="' . esc_url( $published[ $id ]['url'] ) . '">' . esc_html( $published[ $id ]['title'] ) . '</a>', 'aaw-read-more' );
							++$read_more;
						}
						break;
				}
			}
			if ( ! $image_placed ) {
				$blocks[] = $image_block;
			}
		}

		$faq = array();
		foreach ( (array) $draft['faq'] as $qa ) {
			$q = trim( (string) ( $qa['question'] ?? '' ) );
			$a = trim( (string) ( $qa['answer'] ?? '' ) );
			if ( '' !== $q && '' !== $a ) {
				$faq[] = array( Links::inline( Links::strip( $q ) ), Links::inline( Links::strip( $a ) ) );
			}
		}
		if ( $faq ) {
			$blocks[] = Blocks::heading( 'الأسئلة الشائعة', 2 );
			$blocks[] = Blocks::faq( $faq );
		}

		$blocks[] = Blocks::heading( 'الخلاصة', 2 );
		foreach ( (array) $draft['summary'] as $p ) {
			$blocks[] = Blocks::paragraph( $render( (string) $p ) );
		}

		$telegram = (string) Settings::get( 'telegram_url' );
		if ( $telegram ) {
			$blocks[] = Blocks::paragraph( esc_html( (string) Settings::get( 'telegram_text' ) ) . ' <a href="' . esc_url( $telegram ) . '" target="_blank" rel="noreferrer noopener">اشترك في قناة تيليجرام</a>', 'aaw-telegram' );
		}

		$content = Blocks::join( $blocks );

		// 4. Category (create it now if the outline asked for a new one).
		$cat_id = (int) $outline['category_existing_id'];
		if ( ! $cat_id && $outline['category_new_name'] ) {
			$term = term_exists( $outline['category_new_name'], 'category' );
			if ( ! $term ) {
				$term = wp_insert_term( $outline['category_new_name'], 'category' );
			}
			if ( is_wp_error( $term ) ) {
				throw new \RuntimeException( 'Could not create the category: ' . $term->get_error_message() );
			}
			$cat_id = (int) ( is_array( $term ) ? $term['term_id'] : $term );
			Jobs::log( $job_id, 'Created category: ' . $outline['category_new_name'] );
		}

		// 5. Save the draft.
		$postarr = array(
			'post_type'     => 'post',
			'post_status'   => 'draft',
			'post_title'    => $outline['title'],
			'post_content'  => $content,
			'post_excerpt'  => $outline['meta_description'],
			'post_name'     => $outline['slug'],
			'post_author'   => (int) $outline['author_id'],
			'post_category' => $cat_id ? array( $cat_id ) : array(),
			'meta_input'    => array(
				'_aaw_job_id'                    => $job_id,
				'_aaw_slug'                      => $outline['slug'],
				'_yoast_wpseo_focuskw'           => $outline['focus_keyphrase'],
				'_yoast_wpseo_title'             => $outline['seo_title'] . ' %%sep%% %%sitename%%',
				'_yoast_wpseo_metadesc'          => $outline['meta_description'],
				'_yoast_wpseo_primary_category'  => $cat_id ? $cat_id : '',
			),
		);
		if ( $existing && get_post( $existing ) ) {
			$postarr['ID'] = $existing;
		}
		$post_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( 'Could not save the draft: ' . $post_id->get_error_message() );
		}

		$attached = array_filter( array_map( static fn( $img ) => (int) ( $img['id'] ?? 0 ), $images['sections'] ?? array() ) );
		$cover_id = (int) ( $images['cover']['id'] ?? 0 );
		if ( $cover_id ) {
			set_post_thumbnail( $post_id, $cover_id );
			$attached[] = $cover_id;
		}
		Media::attach_to_post( $attached, (int) $post_id );

		$report['internal'] = array_values( array_unique( $report['internal'] ) );
		$report['external'] = array_values( array_unique( $report['external'] ) );

		Jobs::update(
			$job_id,
			static function ( array $s ) use ( $post_id, $report, $cat_id ) {
				$s['data']['post_id']     = (int) $post_id;
				$s['data']['link_report'] = $report;
				$s['data']['category_id'] = $cat_id;
				return $s;
			}
		);
		Jobs::log( $job_id, sprintf( 'Draft saved (post %d): %d internal and %d external links.', $post_id, count( $report['internal'] ), count( $report['external'] ) ) );
		return 'quality';
	}
}
