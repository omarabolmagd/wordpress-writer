<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a saved draft before a person reviews it: automatic checks on our side,
 * plus an editorial review by Claude. Results show as a checklist on the draft.
 */
final class Quality {

	public const META = '_aaw_checklist';

	public static function run( int $job_id ): string {
		$state   = Jobs::get( $job_id );
		$outline = $state['data']['outline'];
		$draft   = $state['data']['draft'];
		$post_id = (int) $state['data']['post_id'];

		$items = self::local_checks( $state );

		try {
			list( $system, $prompt ) = Prompts::quality( self::plain_article( $outline, $draft ), $outline, (string) $state['data']['research']['notes'] );
			$review                  = Claude::json( $system, $prompt, Prompts::quality_schema(), $job_id, 'quality' );
			$labels                  = self::review_labels();
			foreach ( (array) ( $review['items'] ?? array() ) as $r ) {
				$check = (string) ( $r['check'] ?? '' );
				if ( ! isset( $labels[ $check ] ) ) {
					continue;
				}
				$items[] = array(
					'key'    => 'review_' . $check,
					'status' => 'warn' === ( $r['status'] ?? '' ) ? 'warn' : 'pass',
					'label'  => $labels[ $check ],
					'note'   => sanitize_text_field( (string) ( $r['note'] ?? '' ) ),
				);
			}
		} catch ( \Throwable $e ) {
			$items[] = array(
				'key'    => 'review_failed',
				'status' => 'warn',
				'label'  => __( 'Editorial review', 'ana-afdal-writer' ),
				'note'   => __( 'The automatic review could not run. Read the draft carefully.', 'ana-afdal-writer' ) . ' (' . $e->getMessage() . ')',
			);
		}

		update_post_meta( $post_id, self::META, wp_slash( wp_json_encode( $items, JSON_UNESCAPED_UNICODE ) ) );
		Jobs::update(
			$job_id,
			static function ( array $s ) use ( $items ) {
				$s['data']['checklist'] = $items;
				return $s;
			}
		);
		$warnings = count( array_filter( $items, static fn( $i ) => 'warn' === $i['status'] ) );
		Jobs::log( $job_id, sprintf( 'Quality check done: %d item(s) to review.', $warnings ) );
		return 'backlinks';
	}

	public static function get_for_post( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META, true );
		$val = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $val ) ? $val : array();
	}

	private static function review_labels(): array {
		return array(
			'outdated_facts'     => __( 'Outdated facts', 'ana-afdal-writer' ),
			'unsupported_claims' => __( 'Claims not backed by research', 'ana-afdal-writer' ),
			'dialect'            => __( 'Dialect instead of MSA', 'ana-afdal-writer' ),
			'keyword_stuffing'   => __( 'Keyword stuffing', 'ana-afdal-writer' ),
			'off_topic_links'    => __( 'Off-topic links', 'ana-afdal-writer' ),
			'title_match'        => __( 'Title matches content', 'ana-afdal-writer' ),
			'repetition'         => __( 'Repetition or padding', 'ana-afdal-writer' ),
			'formatting'         => __( 'Formatting', 'ana-afdal-writer' ),
		);
	}

	/** The article as plain text with [anchor](url) links, for the reviewer. */
	public static function plain_article( array $outline, array $draft ): string {
		$lines = array( '# ' . $outline['title'], '' );
		foreach ( (array) $draft['intro'] as $p ) {
			$lines[] = $p;
		}
		foreach ( (array) $draft['sections'] as $section ) {
			$lines[] = '';
			$lines[] = '## ' . $section['heading'];
			foreach ( (array) $section['blocks'] as $b ) {
				switch ( $b['kind'] ?? '' ) {
					case 'paragraph':
						$lines[] = $b['text'];
						break;
					case 'subheading':
						$lines[] = '### ' . $b['text'];
						break;
					case 'list':
						foreach ( (array) $b['items'] as $n => $item ) {
							$lines[] = ( ! empty( $b['ordered'] ) ? ( $n + 1 ) . '. ' : '- ' ) . $item;
						}
						break;
					case 'table':
						$lines[] = '| ' . implode( ' | ', (array) $b['headers'] ) . ' |';
						foreach ( (array) $b['rows'] as $row ) {
							$lines[] = '| ' . implode( ' | ', (array) $row ) . ' |';
						}
						break;
					case 'read_more':
						$lines[] = '(Read also: post ' . (int) $b['post_id'] . ')';
						break;
				}
			}
		}
		$lines[] = '';
		$lines[] = '## الأسئلة الشائعة';
		foreach ( (array) $draft['faq'] as $qa ) {
			$lines[] = 'س: ' . $qa['question'];
			$lines[] = 'ج: ' . $qa['answer'];
		}
		$lines[] = '';
		$lines[] = '## الخلاصة';
		foreach ( (array) $draft['summary'] as $p ) {
			$lines[] = $p;
		}
		return implode( "\n", $lines );
	}

	private static function item( string $key, bool $ok, string $label, string $note = '', string $fail = 'warn' ): array {
		return array(
			'key'    => $key,
			'status' => $ok ? 'pass' : $fail,
			'label'  => $label,
			'note'   => $note,
		);
	}

	/** Checks that don't need Claude. */
	public static function local_checks( array $state ): array {
		$outline  = $state['data']['outline'];
		$draft    = $state['data']['draft'];
		$report   = $state['data']['link_report'] ?? array();
		$images   = $state['data']['images'] ?? array();
		$post_id  = (int) ( $state['data']['post_id'] ?? 0 );
		$template = Prompts::templates()[ $outline['post_type'] ] ?? Prompts::templates()['guide'];
		$items    = array();

		$words         = Article::word_count( $draft );
		list( $lo, $hi ) = $template['words'];
		$items[]       = self::item(
			'length',
			$words >= (int) ( $lo * 0.85 ) && $words <= (int) ( $hi * 1.2 ),
			__( 'Length fits the template', 'ana-afdal-writer' ),
			/* translators: 1: word count, 2: minimum, 3: maximum */
			sprintf( __( 'About %1$d words (target %2$d–%3$d).', 'ana-afdal-writer' ), $words, $lo, $hi )
		);

		$internal = count( $report['internal'] ?? array() );
		$items[]  = self::item(
			'internal_links',
			$internal >= 3 && $internal <= 6,
			__( 'Internal links', 'ana-afdal-writer' ),
			/* translators: %d: number of links */
			sprintf( __( '%d links to other posts (target 3–5).', 'ana-afdal-writer' ), $internal )
		);

		$external = count( $report['external'] ?? array() );
		$items[]  = self::item(
			'external_links',
			$external >= 2 && $external <= 4,
			__( 'External sources', 'ana-afdal-writer' ),
			/* translators: %d: number of links */
			sprintf( __( '%d links to sources, each checked live (target 2–4).', 'ana-afdal-writer' ), $external )
		);

		$removed = $report['removed'] ?? array();
		$items[] = self::item(
			'removed_links',
			! $removed,
			__( 'Links removed during checks', 'ana-afdal-writer' ),
			$removed ? implode( ' · ', array_map( static fn( $r ) => $r['target'] . ' — ' . $r['reason'], $removed ) ) : __( 'None.', 'ana-afdal-writer' )
		);

		$unverified = $report['unverified'] ?? array();
		if ( $unverified ) {
			$items[] = self::item(
				'unverified_links',
				false,
				__( 'Sources to open yourself', 'ana-afdal-writer' ),
				__( 'These sites refuse automated checks, so open them once to confirm they work:', 'ana-afdal-writer' ) . ' ' . implode( ' · ', wp_list_pluck( $unverified, 'target' ) )
			);
		}

		$failed_images = array();
		$missing_alt   = 0;
		foreach ( $outline['sections'] as $i => $section ) {
			if ( 'none' === $section['image']['source'] ) {
				continue;
			}
			$img = $images['sections'][ $i ] ?? array();
			if ( empty( $img['id'] ) ) {
				$failed_images[] = sprintf( '%s (%s): %s', $section['heading'], $section['image']['source'], $img['error'] ?? 'not created' );
			} elseif ( '' === trim( (string) get_post_meta( (int) $img['id'], '_wp_attachment_image_alt', true ) ) ) {
				++$missing_alt;
			}
		}
		$items[] = self::item(
			'images',
			! $failed_images,
			__( 'Planned images added', 'ana-afdal-writer' ),
			$failed_images ? implode( ' · ', $failed_images ) : __( 'All planned images are in the post with Arabic alt text.', 'ana-afdal-writer' )
		);
		if ( $missing_alt ) {
			/* translators: %d: number of images */
			$items[] = self::item( 'alt_text', false, __( 'Image alt text', 'ana-afdal-writer' ), sprintf( __( '%d image(s) have no alt text.', 'ana-afdal-writer' ), $missing_alt ) );
		}

		$cover    = (array) ( $images['cover'] ?? array() );
		$fallback = ! empty( $cover['errors'] );
		$items[]  = self::item(
			'featured_image',
			$post_id && has_post_thumbnail( $post_id ) && ! $fallback,
			__( 'Featured image', 'ana-afdal-writer' ),
			! empty( $cover['error'] )
				? $cover['error']
				/* translators: %s: why the first choice of image failed */
				: ( $fallback ? sprintf( __( 'The first choice failed, so another image was used. You can make a new one on the article page. (%s)', 'ana-afdal-writer' ), implode( ' ', (array) $cover['errors'] ) ) : '' )
		);

		$len     = mb_strlen( $outline['meta_description'] );
		$items[] = self::item(
			'meta_description',
			$len >= 110 && $len <= 160,
			__( 'Meta description length', 'ana-afdal-writer' ),
			/* translators: %d: number of characters */
			sprintf( __( '%d characters (target 120–155).', 'ana-afdal-writer' ), $len )
		);

		$key        = mb_strtolower( $outline['focus_keyphrase'] );
		$first_para = mb_strtolower( Links::strip( (string) ( $draft['intro'][0] ?? '' ) ) );
		$missing    = array();
		if ( ! str_contains( mb_strtolower( $outline['title'] ), $key ) ) {
			$missing[] = __( 'title', 'ana-afdal-writer' );
		}
		if ( ! str_contains( $first_para, $key ) ) {
			$missing[] = __( 'first paragraph', 'ana-afdal-writer' );
		}
		if ( ! str_contains( mb_strtolower( $outline['meta_description'] ), $key ) ) {
			$missing[] = __( 'meta description', 'ana-afdal-writer' );
		}
		$items[] = self::item(
			'keyphrase',
			! $missing,
			__( 'Focus keyphrase placement', 'ana-afdal-writer' ),
			$missing ? __( 'Exact keyphrase not found in:', 'ana-afdal-writer' ) . ' ' . implode( '، ', $missing ) : ''
		);

		$current_slug = $post_id ? (string) get_post_field( 'post_name', $post_id ) : '';
		$items[]      = self::item(
			'slug',
			$current_slug === $outline['slug'],
			__( 'English URL slug', 'ana-afdal-writer' ),
			'/' . $outline['slug'] . '/'
		);

		$faq     = count( (array) $draft['faq'] );
		$items[] = self::item(
			'faq',
			$faq >= 3,
			__( 'FAQ with schema', 'ana-afdal-writer' ),
			/* translators: %d: number of questions */
			sprintf( __( '%d questions in the Yoast FAQ block.', 'ana-afdal-writer' ), $faq )
		);

		if ( $outline['category_new_name'] ) {
			$items[] = array(
				'key'    => 'new_category',
				'status' => 'info',
				'label'  => __( 'New category created', 'ana-afdal-writer' ),
				'note'   => $outline['category_new_name'],
			);
		}
		return $items;
	}
}
