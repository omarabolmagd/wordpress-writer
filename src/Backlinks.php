<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Suggests places in older posts that should link to the new post.
 * Nothing changes until a person approves a suggestion, and approved links are
 * only added once the new post is published (so no one lands on a draft URL).
 */
final class Backlinks {

	private const MAX_CANDIDATES = 8;

	public static function register(): void {
		add_action( 'transition_post_status', array( self::class, 'on_publish' ), 20, 3 );
	}

	public static function suggest( int $job_id ): ?string {
		$state   = Jobs::get( $job_id );
		$outline = $state['data']['outline'];
		$post_id = (int) $state['data']['post_id'];

		$candidates = self::candidates( $post_id, $outline, (int) ( $state['data']['category_id'] ?? 0 ) );
		$suggestions = array();

		if ( $candidates ) {
			$new_post = array(
				'title'     => $outline['title'],
				'keyphrase' => $outline['focus_keyphrase'],
				'summary'   => $outline['meta_description'],
			);
			list( $system, $prompt ) = Prompts::backlinks( $new_post, $candidates );
			$data                    = Claude::json( $system, $prompt, Prompts::backlinks_schema(), $job_id, 'backlinks' );

			$by_id = array();
			foreach ( $candidates as $c ) {
				$by_id[ $c['id'] ] = $c;
			}
			$seen = array();
			foreach ( (array) ( $data['suggestions'] ?? array() ) as $s ) {
				$pid    = (int) ( $s['post_id'] ?? 0 );
				$pidx   = (int) ( $s['paragraph_index'] ?? -1 );
				$anchor = trim( (string) ( $s['anchor'] ?? '' ) );
				if ( ! isset( $by_id[ $pid ] ) || isset( $seen[ $pid ] ) || ! isset( $by_id[ $pid ]['paragraphs'][ $pidx ] ) ) {
					continue;
				}
				$paragraph = $by_id[ $pid ]['paragraphs'][ $pidx ];
				$words     = count( preg_split( '/\s+/u', $anchor, -1, PREG_SPLIT_NO_EMPTY ) );
				if ( '' === $anchor || $words < 2 || $words > 8 || ! str_contains( $paragraph, $anchor ) ) {
					continue;
				}
				$seen[ $pid ]  = true;
				$suggestions[] = array(
					'post_id'   => $pid,
					'title'     => $by_id[ $pid ]['title'],
					'paragraph' => $paragraph,
					'anchor'    => $anchor,
					'reason'    => sanitize_text_field( (string) ( $s['reason'] ?? '' ) ),
					'status'    => 'suggested',
					'note'      => '',
				);
				if ( count( $suggestions ) >= 3 ) {
					break;
				}
			}
		}

		Jobs::update(
			$job_id,
			static function ( array $s ) use ( $suggestions ) {
				$s['data']['backlinks'] = $suggestions;
				return $s;
			}
		);
		Jobs::log( $job_id, sprintf( '%d backlink suggestion(s) waiting for your approval.', count( $suggestions ) ) );
		Pipeline::wait( $job_id, 'done', 'done' );
		return null;
	}

	/** Older posts most related to the new one, with their linkable paragraphs. */
	private static function candidates( int $new_post_id, array $outline, int $category_id ): array {
		$target_tokens = Site::tokens( $outline['title'] . ' ' . $outline['focus_keyphrase'] );
		$target_url    = (string) get_permalink( $new_post_id );
		$scored        = array();

		foreach ( Site::published_posts( 300, array( $new_post_id ) ) as $p ) {
			// Posts built with Elementor keep their content elsewhere; editing post_content wouldn't show.
			if ( 'builder' === get_post_meta( $p['id'], '_elementor_edit_mode', true ) ) {
				continue;
			}
			$score = count( array_intersect( $target_tokens, Site::tokens( $p['title'] . ' ' . $p['excerpt'] ) ) );
			if ( $category_id && in_array( $category_id, wp_get_post_categories( $p['id'] ), true ) ) {
				$score += 2;
			}
			if ( $score > 0 ) {
				$scored[] = array( $score, $p );
			}
		}
		usort( $scored, static fn( $a, $b ) => $b[0] <=> $a[0] );

		$out = array();
		foreach ( array_slice( $scored, 0, self::MAX_CANDIDATES ) as $pair ) {
			$p          = $pair[1];
			$paragraphs = array();
			foreach ( self::paragraphs( (string) get_post_field( 'post_content', $p['id'] ) ) as $para ) {
				if ( mb_strlen( $para['text'] ) >= 60 && ! str_contains( $para['inner'], $target_url ) ) {
					$paragraphs[] = $para['text'];
				}
			}
			if ( $paragraphs ) {
				$out[] = array(
					'id'         => $p['id'],
					'title'      => $p['title'],
					'paragraphs' => array_slice( $paragraphs, 0, 25 ),
				);
			}
		}
		return $out;
	}

	/**
	 * Paragraphs of post content: inner HTML, plain text, and where they sit.
	 *
	 * @return array<int,array{inner:string,text:string,start:int,length:int}>
	 */
	public static function paragraphs( string $content ): array {
		$out = array();
		if ( preg_match_all( '/<p(?:\s[^>]*)?>(.*?)<\/p>/su', $content, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$out[] = array(
					'inner'  => $match[1][0],
					'text'   => self::text_of( $match[1][0] ),
					'start'  => $match[1][1],
					'length' => strlen( $match[1][0] ),
				);
			}
			return $out;
		}
		// Classic-editor content without <p> tags: paragraphs are separated by blank lines.
		$offset = 0;
		foreach ( preg_split( '/(\R\s*\R)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE ) as $piece ) {
			$is_separator = (bool) preg_match( '/^\R\s*\R$/u', $piece );
			if ( ! $is_separator && '' !== trim( $piece ) && ! preg_match( '/^\s*</', $piece ) ) {
				$out[] = array(
					'inner'  => $piece,
					'text'   => self::text_of( $piece ),
					'start'  => $offset,
					'length' => strlen( $piece ),
				);
			}
			$offset += strlen( $piece );
		}
		return $out;
	}

	private static function text_of( string $html ): string {
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/** Called from the job screen. */
	public static function decide( int $job_id, int $index, bool $approve ): array {
		$state = Jobs::get( $job_id );
		if ( ! isset( $state['data']['backlinks'][ $index ] ) ) {
			throw new \RuntimeException( __( 'Suggestion not found.', 'ana-afdal-writer' ) );
		}
		$post_id = (int) $state['data']['post_id'];
		$status  = $approve ? 'approved' : 'dismissed';
		Jobs::update(
			$job_id,
			static function ( array $s ) use ( $index, $status ) {
				$s['data']['backlinks'][ $index ]['status'] = $status;
				return $s;
			}
		);
		if ( $approve && 'publish' === get_post_status( $post_id ) ) {
			self::apply( $job_id, $index );
		}
		return Jobs::get( $job_id );
	}

	public static function on_publish( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status || 'post' !== $post->post_type ) {
			return;
		}
		$job = Jobs::for_post( $post->ID );
		if ( ! $job ) {
			return;
		}
		foreach ( (array) ( $job['data']['backlinks'] ?? array() ) as $i => $b ) {
			if ( 'approved' === $b['status'] ) {
				self::apply( (int) $job['id'], (int) $i );
			}
		}
	}

	/** Adds one approved link to the older post. */
	public static function apply( int $job_id, int $index ): bool {
		$state = Jobs::get( $job_id );
		$b     = $state['data']['backlinks'][ $index ] ?? null;
		if ( ! $b ) {
			return false;
		}
		$target_id = (int) $state['data']['post_id'];
		$url       = (string) get_permalink( $target_id );
		$content   = (string) get_post_field( 'post_content', (int) $b['post_id'] );
		$result    = self::insert_link( $content, (string) $b['paragraph'], (string) $b['anchor'], $url );

		$note   = '';
		$status = 'applied';
		if ( null === $result ) {
			$status = 'failed';
			$note   = __( 'The paragraph or phrase changed since the suggestion was made.', 'ana-afdal-writer' );
		} else {
			$saved = wp_update_post(
				array(
					'ID'           => (int) $b['post_id'],
					'post_content' => wp_slash( $result ),
				),
				true
			);
			if ( is_wp_error( $saved ) ) {
				$status = 'failed';
				$note   = $saved->get_error_message();
			}
		}

		Jobs::update(
			$job_id,
			static function ( array $s ) use ( $index, $status, $note ) {
				$s['data']['backlinks'][ $index ]['status'] = $status;
				$s['data']['backlinks'][ $index ]['note']   = $note;
				return $s;
			}
		);
		return 'applied' === $status;
	}

	/**
	 * Links the first occurrence of $anchor (outside existing links) in the paragraph whose text is $paragraph_text.
	 * Returns the new content, or null if the paragraph or phrase can't be found.
	 */
	public static function insert_link( string $content, string $paragraph_text, string $anchor, string $url ): ?string {
		foreach ( self::paragraphs( $content ) as $para ) {
			if ( $para['text'] !== $paragraph_text ) {
				continue;
			}
			$parts   = preg_split( '/(<a\b[^>]*>.*?<\/a>|<[^>]+>)/su', $para['inner'], -1, PREG_SPLIT_DELIM_CAPTURE );
			$needle  = esc_html( $anchor );
			$done    = false;
			foreach ( $parts as $i => $part ) {
				if ( $done || '' === $part || '<' === $part[0] ) {
					continue;
				}
				foreach ( array_unique( array( $anchor, $needle ) ) as $candidate ) {
					$pos = strpos( $part, $candidate );
					if ( false !== $pos ) {
						$parts[ $i ] = substr( $part, 0, $pos ) . '<a href="' . esc_url( $url ) . '">' . $candidate . '</a>' . substr( $part, $pos + strlen( $candidate ) );
						$done        = true;
						break;
					}
				}
			}
			if ( ! $done ) {
				return null;
			}
			return substr( $content, 0, $para['start'] ) . implode( '', $parts ) . substr( $content, $para['start'] + $para['length'] );
		}
		return null;
	}
}
