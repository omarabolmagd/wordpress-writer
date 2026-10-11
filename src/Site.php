<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only facts about the site that prompts and checks need.
 */
final class Site {

	/** @return array<int,string> Category id => name. */
	public static function categories(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$out = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$out[ (int) $term->term_id ] = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
			}
		}
		return $out;
	}

	/** Titles of published posts, newest first. */
	public static function existing_titles( int $limit = 300 ): array {
		return array_map( static fn( array $p ) => $p['title'], self::published_posts( $limit ) );
	}

	/**
	 * Published posts with what prompts need to link to them.
	 *
	 * @return array<int,array{id:int,title:string,url:string,categories:array,excerpt:string}>
	 */
	public static function published_posts( int $limit = 300, array $exclude = array() ): array {
		$ids = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'posts_per_page'   => $limit,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'post__not_in'     => $exclude,
				'suppress_filters' => true,
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$id    = (int) $id;
			$cats  = array_map( static fn( $t ) => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ), get_the_category( $id ) );
			$out[] = array(
				'id'         => $id,
				'title'      => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'url'        => (string) get_permalink( $id ),
				'categories' => $cats,
				'excerpt'    => self::excerpt( $id ),
			);
		}
		return $out;
	}

	public static function excerpt( int $post_id, int $words = 30 ): string {
		$desc = (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
		if ( '' !== trim( $desc ) && ! str_contains( $desc, '%%' ) ) {
			return trim( $desc );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$text = $post->post_excerpt ? $post->post_excerpt : self::plain_text( $post->post_content );
		return wp_trim_words( $text, $words, '…' );
	}

	/** Plain text of post content without blocks, tags or shortcodes. */
	public static function plain_text( string $content ): string {
		$content = strip_shortcodes( $content );
		$content = preg_replace( '/<!--.*?-->/s', '', $content );
		$content = wp_strip_all_tags( $content );
		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $content ) );
	}

	/** Splits text into rough word tokens for overlap checks (works for Arabic). */
	public static function tokens( string $text ): array {
		$text  = mb_strtolower( $text );
		$text  = preg_replace( '/[\p{P}\p{S}]+/u', ' ', $text );
		$parts = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$stop  = array( 'في', 'من', 'على', 'إلى', 'الى', 'عن', 'مع', 'و', 'أو', 'او', 'ما', 'هو', 'هي', 'كل', 'أفضل', 'افضل', 'دليل', 'شامل', 'دليلك', 'الشامل', 'لعام', 'عام', 'كيف', 'the', 'and', 'for', 'of', 'to', 'a', 'in' );
		$out   = array();
		foreach ( $parts as $p ) {
			$p = preg_replace( '/^(ال|وال|بال|لل|فال|كال)/u', '', $p );
			if ( mb_strlen( $p ) > 1 && ! in_array( $p, $stop, true ) && ! preg_match( '/^\d{4}$/', $p ) ) {
				$out[ $p ] = true;
			}
		}
		return array_keys( $out );
	}

	/** Jaccard similarity between two titles, 0..1. */
	public static function similarity( string $a, string $b ): float {
		$ta = self::tokens( $a );
		$tb = self::tokens( $b );
		if ( ! $ta || ! $tb ) {
			return 0.0;
		}
		$inter = count( array_intersect( $ta, $tb ) );
		$union = count( array_unique( array_merge( $ta, $tb ) ) );
		return $union ? $inter / $union : 0.0;
	}

	/** The closest published title to a candidate, if similar enough to matter. */
	public static function closest_title( string $title, array $existing, float $threshold = 0.45 ): ?string {
		$best       = null;
		$best_score = $threshold;
		foreach ( $existing as $t ) {
			$score = self::similarity( $title, $t );
			if ( $score >= $best_score ) {
				$best       = $t;
				$best_score = $score;
			}
		}
		return $best;
	}
}
