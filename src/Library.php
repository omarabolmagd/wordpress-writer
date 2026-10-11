<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * The research library: Claude's notes and sources from every research step, kept so
 * later articles and topic runs can start from them instead of searching again.
 *
 * Claude's raw search results come back encrypted and can only be read inside the request
 * that made them, so what is kept is what Claude wrote from them: the notes (facts, dates,
 * official links) and the list of sources.
 */
final class Library {

	public const KIND_ARTICLE = 'article';
	public const KIND_IDEAS   = 'ideas';

	/** News changes fast, so news articles never reuse notes older than this. */
	public const NEWS_DAYS = 7;
	/** Topic ideas are about what is new right now; reuse a topic search for this long. */
	public const IDEAS_DAYS = 3;
	/** Notes older than this are deleted. */
	private const KEEP_DAYS = 180;
	/** At most this many earlier articles are given to Claude. */
	private const MAX_RELATED = 2;
	/** How close an earlier article's topic must be to be given to Claude (0–1, weighted word overlap). */
	private const MIN_SCORE = 0.4;
	/** From this closeness on, the earlier research covers most of the new article, so fewer searches are allowed. */
	public const STRONG_SCORE = 0.55;

	private const STOP_WORDS = array(
		// Arabic, after normalisation (see terms()).
		'في', 'من', 'علي', 'الي', 'عن', 'مع', 'او', 'ام', 'ثم', 'ما', 'ماذا', 'كيف', 'هل', 'لماذا', 'متي', 'اين',
		'هذا', 'هذه', 'ذلك', 'تلك', 'التي', 'الذي', 'الذين', 'كل', 'بين', 'عند', 'بعد', 'قبل', 'حتي', 'لك', 'لكل',
		'به', 'بها', 'له', 'لها', 'انت', 'نحن', 'هو', 'هي', 'افضل', 'اهم', 'دليل', 'شامل', 'كامل', 'جديد', 'عام',
		// English.
		'the', 'a', 'an', 'of', 'for', 'to', 'in', 'on', 'and', 'or', 'with', 'how', 'what', 'why', 'best', 'top', 'guide', 'new',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aaw_research';
	}

	public static function schema( string $charset ): string {
		$table = self::table();
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kind varchar(16) NOT NULL DEFAULT '',
			job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			title text NOT NULL,
			keyphrase varchar(255) NOT NULL DEFAULT '',
			post_type varchar(16) NOT NULL DEFAULT '',
			category_id bigint(20) unsigned NOT NULL DEFAULT 0,
			focus varchar(255) NOT NULL DEFAULT '',
			terms text NOT NULL,
			notes longtext NOT NULL,
			sources longtext NOT NULL,
			meta longtext NULL,
			searches smallint(5) unsigned NOT NULL DEFAULT 0,
			cost decimal(12,6) NOT NULL DEFAULT 0,
			reused_count int(10) unsigned NOT NULL DEFAULT 0,
			last_used_gmt datetime NULL,
			PRIMARY KEY  (id),
			KEY kind_created (kind,created_gmt),
			KEY job_id (job_id)
		) {$charset};";
	}

	public static function enabled(): bool {
		return (bool) Settings::get( 'reuse_research' );
	}

	/** How old an earlier article's notes may be for a new article of this type. */
	public static function max_age_days( string $post_type ): int {
		$days = max( 1, (int) Settings::get( 'reuse_days' ) );
		return 'news' === $post_type ? min( self::NEWS_DAYS, $days ) : $days;
	}

	/* --------------------------------------------------------------- saving */

	/**
	 * Saves one research result. A step that runs again for the same job replaces its entry.
	 *
	 * @param array $e kind, job_id, title, keyphrase, post_type, category_id, focus, notes,
	 *                 sources (list), searches, cost, meta (array), created (unix time, optional).
	 */
	public static function save( array $e ): int {
		global $wpdb;
		$kind   = self::KIND_IDEAS === ( $e['kind'] ?? '' ) ? self::KIND_IDEAS : self::KIND_ARTICLE;
		$job_id = (int) ( $e['job_id'] ?? 0 );
		$notes  = trim( (string) ( $e['notes'] ?? '' ) );
		if ( '' === $notes ) {
			return 0;
		}
		if ( $job_id ) {
			$wpdb->delete( self::table(), array( 'kind' => $kind, 'job_id' => $job_id ), array( '%s', '%d' ) );
		}
		$title     = (string) ( $e['title'] ?? '' );
		$keyphrase = (string) ( $e['keyphrase'] ?? '' );
		$focus     = (string) ( $e['focus'] ?? '' );
		$sources   = array();
		foreach ( (array) ( $e['sources'] ?? array() ) as $s ) {
			if ( empty( $s['url'] ) ) {
				continue;
			}
			$sources[] = array(
				'url'      => esc_url_raw( (string) $s['url'] ),
				'title'    => (string) ( $s['title'] ?? '' ),
				'page_age' => (string) ( $s['page_age'] ?? '' ),
				'cited'    => ! empty( $s['cited'] ),
				'quote'    => (string) ( $s['quote'] ?? '' ),
			);
		}
		$wpdb->insert(
			self::table(),
			array(
				'kind'        => $kind,
				'job_id'      => $job_id,
				'created_gmt' => gmdate( 'Y-m-d H:i:s', (int) ( $e['created'] ?? time() ) ),
				'title'       => $title,
				'keyphrase'   => mb_substr( $keyphrase, 0, 255 ),
				'post_type'   => substr( (string) ( $e['post_type'] ?? '' ), 0, 16 ),
				'category_id' => (int) ( $e['category_id'] ?? 0 ),
				'focus'       => mb_substr( self::focus_key( $focus ), 0, 255 ),
				'terms'       => implode( ' ', self::terms( $kind === self::KIND_IDEAS ? $focus : $title . ' ' . $keyphrase ) ),
				'notes'       => $notes,
				'sources'     => (string) wp_json_encode( $sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'meta'        => (string) wp_json_encode( (array) ( $e['meta'] ?? array() ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'searches'    => max( 0, (int) ( $e['searches'] ?? 0 ) ),
				'cost'        => round( max( 0, (float) ( $e['cost'] ?? 0 ) ), 6 ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%f' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::decode( $row ) : null;
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/** Newest first, for the Research screen. */
	public static function all( int $limit = 200 ): array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_gmt DESC, id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( self::class, 'decode' ), (array) $rows );
	}

	public static function count(): int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Counts a reuse, so the Research screen shows which notes earn their keep. */
	public static function mark_used( array $ids ): void {
		global $wpdb;
		$table = self::table();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET reused_count = reused_count + 1, last_used_gmt = %s WHERE id = %d", gmdate( 'Y-m-d H:i:s' ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/** Remembers which ideas from a topic search were already suggested, so a reuse offers new ones. */
	public static function add_suggested( int $id, array $titles ): void {
		global $wpdb;
		$entry = self::get( $id );
		if ( ! $entry ) {
			return;
		}
		$meta              = $entry['meta'];
		$meta['suggested'] = array_values( array_unique( array_merge( (array) ( $meta['suggested'] ?? array() ), array_map( 'strval', $titles ) ) ) );
		$wpdb->update( self::table(), array( 'meta' => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	public static function cleanup(): int {
		global $wpdb;
		$table = self::table();
		$days  = max( 7, (int) apply_filters( 'aaw_library_keep_days', self::KEEP_DAYS ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_gmt < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function decode( array $row ): array {
		$row['id']           = (int) $row['id'];
		$row['job_id']       = (int) $row['job_id'];
		$row['category_id']  = (int) $row['category_id'];
		$row['searches']     = (int) $row['searches'];
		$row['reused_count'] = (int) $row['reused_count'];
		$row['cost']         = (float) $row['cost'];
		$row['created']      = (int) strtotime( $row['created_gmt'] . ' UTC' );
		$sources             = json_decode( (string) $row['sources'], true );
		$meta                = json_decode( (string) ( $row['meta'] ?? '' ), true );
		$row['sources']      = is_array( $sources ) ? $sources : array();
		$row['meta']         = is_array( $meta ) ? $meta : array();
		return $row;
	}

	/* ------------------------------------------------------------- matching */

	/**
	 * Earlier article research close enough to this topic to reuse, best match first.
	 *
	 * Closeness is the overlap of the words in the two titles and keyphrases, weighted so
	 * words that appear in many of the site's titles ("كورسات", "مجانية") count for little
	 * and specific ones ("الذكاء الاصطناعي", "ألمانيا") count for a lot.
	 */
	public static function related( array $topic, int $exclude_job = 0 ): array {
		if ( ! self::enabled() ) {
			return array();
		}
		global $wpdb;
		$table  = self::table();
		$since  = gmdate( 'Y-m-d H:i:s', time() - self::max_age_days( (string) ( $topic['post_type'] ?? '' ) ) * DAY_IN_SECONDS );
		$rows   = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, title, keyphrase, category_id, terms FROM {$table} WHERE kind = %s AND created_gmt >= %s AND job_id <> %d ORDER BY created_gmt DESC LIMIT 300", self::KIND_ARTICLE, $since, $exclude_job ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		if ( ! $rows ) {
			return array();
		}
		$mine = self::terms( (string) ( $topic['title'] ?? '' ) . ' ' . (string) ( $topic['focus_keyphrase'] ?? '' ) );
		if ( ! $mine ) {
			return array();
		}

		// Word weights come from the site's titles and the saved research, each distinct title
		// counted once, so several articles on one topic don't drown out its words.
		$docs = array();
		foreach ( array_merge( array_column( $rows, 'terms' ), array_map( static fn( $t ) => implode( ' ', self::terms( $t ) ), Site::existing_titles() ) ) as $terms ) {
			$words = array_filter( explode( ' ', (string) $terms ) );
			sort( $words );
			$docs[ implode( ' ', $words ) ] = $words;
		}
		$idf = self::idf( array_values( $docs ) );

		$scored = array();
		foreach ( $rows as $r ) {
			$score = self::score( $mine, array_filter( explode( ' ', (string) $r['terms'] ) ), $idf );
			$cat   = (int) ( $topic['category_existing_id'] ?? 0 );
			if ( $cat && $cat === (int) $r['category_id'] ) {
				$score += 0.05;
			}
			if ( $score >= self::MIN_SCORE ) {
				$scored[ (int) $r['id'] ] = $score;
			}
		}
		arsort( $scored );
		$out = array();
		foreach ( array_slice( array_keys( $scored ), 0, self::MAX_RELATED ) as $id ) {
			$entry = self::get( $id );
			if ( $entry ) {
				$entry['score'] = round( $scored[ $id ], 2 );
				$out[]          = $entry;
			}
		}
		return $out;
	}

	/** The newest topic search for the same focus, if it is recent enough to reuse. */
	public static function ideas_for( string $focus ): ?array {
		if ( ! self::enabled() ) {
			return null;
		}
		global $wpdb;
		$table = self::table();
		$id    = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE kind = %s AND focus = %s AND created_gmt >= %s ORDER BY created_gmt DESC, id DESC LIMIT 1", self::KIND_IDEAS, self::focus_key( $focus ), gmdate( 'Y-m-d H:i:s', time() - self::IDEAS_DAYS * DAY_IN_SECONDS ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return $id ? self::get( $id ) : null;
	}

	/** Recent topic searches that can be reused, newest per focus, for the topics form. */
	public static function recent_ideas(): array {
		if ( ! self::enabled() ) {
			return array();
		}
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, focus, created_gmt FROM {$table} WHERE kind = %s AND created_gmt >= %s ORDER BY created_gmt DESC, id DESC", self::KIND_IDEAS, gmdate( 'Y-m-d H:i:s', time() - self::IDEAS_DAYS * DAY_IN_SECONDS ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			if ( ! isset( $out[ $r['focus'] ] ) ) {
				$out[ $r['focus'] ] = array(
					'id'      => (int) $r['id'],
					'focus'   => (string) $r['focus'],
					'created' => (int) strtotime( $r['created_gmt'] . ' UTC' ),
				);
			}
		}
		return array_values( $out );
	}

	/** The same focus typed differently ("AI tools", "ai  tools ") is the same search. */
	public static function focus_key( string $focus ): string {
		$focus = mb_strtolower( trim( preg_replace( '/\s+/u', ' ', $focus ) ), 'UTF-8' );
		return self::normalize( $focus );
	}

	/**
	 * Distinct, comparable words of a title: lower case, Arabic letters unified, the article
	 * "ال" and common plural endings removed, numbers and filler words dropped.
	 */
	public static function terms( string $text ): array {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', self::normalize( mb_strtolower( $text, 'UTF-8' ) ), -1, PREG_SPLIT_NO_EMPTY );
		$stop  = array_flip( self::STOP_WORDS );
		$out   = array();
		foreach ( (array) $words as $w ) {
			if ( preg_match( '/^\p{N}+$/u', $w ) ) {
				continue;
			}
			if ( preg_match( '/^\p{Arabic}+$/u', $w ) && ! isset( $stop[ $w ] ) ) {
				$w = self::stem( $w );
			}
			if ( mb_strlen( $w ) < 2 || isset( $stop[ $w ] ) ) {
				continue;
			}
			$out[ $w ] = true;
		}
		return array_keys( $out );
	}

	/**
	 * A light Arabic stemmer: removes the article and attached prepositions ("ال", "بال",
	 * "لل", or a leading و/ب/ل/ف/ك on longer words) and common endings ("ات", "ون", "ين",
	 * "ة", the "ي" of adjectives, the "ا" of adverbs). It is not grammatical, but it maps
	 * "بشهادات" and "شهادة", or "المجانية" and "مجانا", to the same word, which is what
	 * matching titles needs.
	 */
	private static function stem( string $w ): string {
		$stripped = false;
		foreach ( array( 'وال', 'بال', 'كال', 'فال', 'لل', 'ال' ) as $prefix ) {
			if ( str_starts_with( $w, $prefix ) && mb_strlen( $w ) - mb_strlen( $prefix ) >= 3 ) {
				$w        = mb_substr( $w, mb_strlen( $prefix ) );
				$stripped = true;
				break;
			}
		}
		for ( $pass = 0; $pass < 2; $pass++ ) {
			$changed = false;
			foreach ( array( 'ات', 'ون', 'ين', 'ه', 'ي', 'ا' ) as $suffix ) {
				if ( str_ends_with( $w, $suffix ) && mb_strlen( $w ) - mb_strlen( $suffix ) >= 3 ) {
					$w       = mb_substr( $w, 0, -mb_strlen( $suffix ) );
					$changed = true;
					break;
				}
			}
			if ( ! $changed ) {
				break;
			}
		}
		// After the endings, so "كورسات" (كورس) keeps its ك but "بشهادات" (بشهاد) loses its ب.
		if ( ! $stripped && mb_strlen( $w ) >= 5 && in_array( mb_substr( $w, 0, 1 ), array( 'و', 'ب', 'ل', 'ف', 'ك' ), true ) ) {
			$w = mb_substr( $w, 1 );
		}
		return $w;
	}

	private static function normalize( string $text ): string {
		$text = (string) preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text );
		return strtr(
			$text,
			array(
				'أ' => 'ا',
				'إ' => 'ا',
				'آ' => 'ا',
				'ٱ' => 'ا',
				'ة' => 'ه',
				'ى' => 'ي',
				'ؤ' => 'و',
				'ئ' => 'ي',
			)
		);
	}

	/** Inverse document frequency of each word across a set of word lists. */
	public static function idf( array $docs ): array {
		$df = array();
		foreach ( $docs as $words ) {
			foreach ( array_unique( $words ) as $w ) {
				$df[ $w ] = ( $df[ $w ] ?? 0 ) + 1;
			}
		}
		$n   = count( $docs );
		$idf = array();
		foreach ( $df as $w => $count ) {
			$idf[ $w ] = log( ( $n + 1 ) / ( $count + 1 ) ) + 1;
		}
		$idf[''] = log( $n + 1 ) + 1; // Weight for words seen nowhere else.
		return $idf;
	}

	/** Weighted overlap of two word lists, from 0 (nothing shared) to 1 (the same words). */
	public static function score( array $a, array $b, array $idf ): float {
		$a     = array_unique( $a );
		$b     = array_unique( $b );
		$union = array_unique( array_merge( $a, $b ) );
		$w     = static fn( $t ) => $idf[ $t ] ?? $idf[''] ?? 1.0;
		$total = array_sum( array_map( $w, $union ) );
		if ( $total <= 0 ) {
			return 0.0;
		}
		return array_sum( array_map( $w, array_intersect( $a, $b ) ) ) / $total;
	}

	/* ---------------------------------------------------------------- costs */

	/**
	 * Average cost of an article's research step, from scratch and with saved notes, measured
	 * from the cost log. Null when there is nothing to compare yet.
	 */
	public static function average_costs(): array {
		$fresh = array();
		$reuse = array();
		foreach ( self::all( 500 ) as $e ) {
			if ( self::KIND_ARTICLE !== $e['kind'] || $e['cost'] <= 0 ) {
				continue;
			}
			if ( empty( $e['meta']['reused_from'] ) ) {
				$fresh[] = $e['cost'];
			} else {
				$reuse[] = $e['cost'];
			}
		}
		$avg = static fn( array $v ) => $v ? array_sum( $v ) / count( $v ) : null;
		return array(
			'fresh'       => $avg( $fresh ),
			'fresh_count' => count( $fresh ),
			'reuse'       => $avg( $reuse ),
			'reuse_count' => count( $reuse ),
		);
	}

	/* -------------------------------------------------------------- upgrade */

	/** Adds the research of jobs from before the library existed. */
	public static function backfill(): int {
		global $wpdb;
		$added = 0;
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID ASC", Jobs::POST_TYPE ) );
		foreach ( $ids as $id ) {
			$job = Jobs::get( (int) $id );
			if ( ! $job ) {
				continue;
			}
			if ( Jobs::TYPE_ARTICLE === $job['type'] && ! empty( $job['data']['research']['notes'] ) ) {
				$topic  = (array) ( $job['input']['topic'] ?? array() );
				$added += self::save(
					array(
						'kind'        => self::KIND_ARTICLE,
						'job_id'      => (int) $id,
						'title'       => (string) ( $topic['title'] ?? $job['title'] ?? '' ),
						'keyphrase'   => (string) ( $topic['focus_keyphrase'] ?? '' ),
						'post_type'   => (string) ( $topic['post_type'] ?? '' ),
						'category_id' => (int) ( $topic['category_existing_id'] ?? 0 ),
						'notes'       => (string) $job['data']['research']['notes'],
						'sources'     => (array) ( $job['data']['research']['sources'] ?? array() ),
						'searches'    => Costs::job_item_searches( (int) $id, 'research' ),
						'cost'        => Costs::job_item_total( (int) $id, 'research' ),
						'created'     => (int) ( $job['created'] ?? time() ),
					)
				) ? 1 : 0;
			} elseif ( Jobs::TYPE_TOPICS === $job['type'] && ! empty( $job['data']['notes'] ) && empty( $job['data']['reused_ideas'] ) ) {
				$added += self::save(
					array(
						'kind'     => self::KIND_IDEAS,
						'job_id'   => (int) $id,
						'title'    => (string) ( $job['input']['focus'] ?? '' ),
						'focus'    => (string) ( $job['input']['focus'] ?? '' ),
						'notes'    => (string) $job['data']['notes'],
						'sources'  => (array) ( $job['data']['sources'] ?? array() ),
						'searches' => Costs::job_item_searches( (int) $id, 'topics_research' ),
						'cost'     => Costs::job_item_total( (int) $id, 'topics_research' ),
						'meta'     => array( 'suggested' => wp_list_pluck( (array) ( $job['data']['topics'] ?? array() ), 'title' ) ),
						'created'  => (int) ( $job['created'] ?? time() ),
					)
				) ? 1 : 0;
			}
		}
		return $added;
	}
}
