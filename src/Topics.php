<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Topic suggestions: Claude researches current demand, then proposes articles
 * that don't repeat what the site already has.
 */
final class Topics {

	public static function start( int $user_id, string $focus ): int {
		$job_id = Jobs::create( Jobs::TYPE_TOPICS, $user_id, 'Topic suggestions ' . wp_date( 'Y-m-d H:i' ), array( 'focus' => $focus ) );
		Jobs::schedule( $job_id, 'topics_research' );
		return $job_id;
	}

	public static function research( int $job_id ): string {
		$state = Jobs::get( $job_id );
		list( $system, $prompt ) = Prompts::topics_research( Site::existing_titles(), array_values( Site::categories() ), (string) ( $state['input']['focus'] ?? '' ) );

		Jobs::log( $job_id, 'Searching the web for what readers are looking for…' );
		$result = Claude::research( $system, $prompt, $job_id, 'topics_research' );

		Jobs::update(
			$job_id,
			static function ( array $s ) use ( $result ) {
				$s['data']['notes']   = $result['text'];
				$s['data']['sources'] = array_values( $result['sources'] );
				return $s;
			}
		);
		Jobs::log( $job_id, sprintf( 'Research done (%d searches).', $result['searches'] ) );
		return 'topics_structure';
	}

	public static function structure( int $job_id ): ?string {
		$state      = Jobs::get( $job_id );
		$categories = Site::categories();
		$existing   = Site::existing_titles();

		list( $system, $prompt ) = Prompts::topics_structure( (string) $state['data']['notes'], $categories, $existing );
		$data = Claude::json( $system, $prompt, Prompts::topics_schema(), $job_id, 'topics_structure', 4000 );

		$topics = array();
		foreach ( (array) ( $data['topics'] ?? array() ) as $t ) {
			$topic = self::sanitize_topic( $t, $categories );
			if ( ! $topic ) {
				continue;
			}
			// Second check on our side: flag titles that look like something already published.
			$close = Site::closest_title( $topic['title'], $existing );
			if ( $close && '' === $topic['overlap_note'] ) {
				$topic['overlap_note'] = 'قريب من مقال منشور: ' . $close;
			}
			$topics[] = $topic;
		}

		Jobs::update(
			$job_id,
			static function ( array $s ) use ( $topics ) {
				$s['data']['topics'] = $topics;
				return $s;
			}
		);
		Jobs::log( $job_id, sprintf( '%d topics suggested.', count( $topics ) ) );
		Pipeline::wait( $job_id, 'done', 'done' );
		return null;
	}

	/** Cleans one topic from Claude or from the editor's form. */
	public static function sanitize_topic( array $t, array $categories ): ?array {
		$title = sanitize_text_field( (string) ( $t['title'] ?? '' ) );
		if ( '' === $title ) {
			return null;
		}
		$type = in_array( $t['post_type'] ?? '', array( 'news', 'list', 'guide' ), true ) ? $t['post_type'] : 'guide';

		$cat_id = isset( $t['category_existing_id'] ) && null !== $t['category_existing_id'] ? (int) $t['category_existing_id'] : 0;
		if ( $cat_id && ! isset( $categories[ $cat_id ] ) ) {
			$cat_id = 0;
		}
		$new_cat = $cat_id ? '' : sanitize_text_field( (string) ( $t['category_new_name'] ?? '' ) );
		// Reuse an existing category with the same name instead of creating a duplicate.
		if ( $new_cat ) {
			$match = array_search( $new_cat, $categories, true );
			if ( false !== $match ) {
				$cat_id  = (int) $match;
				$new_cat = '';
			}
		}

		return array(
			'title'                => $title,
			'focus_keyphrase'      => sanitize_text_field( (string) ( $t['focus_keyphrase'] ?? $title ) ),
			'post_type'            => $type,
			'category_existing_id' => $cat_id ? $cat_id : null,
			'category_new_name'    => $new_cat ? $new_cat : null,
			'why_now'              => sanitize_text_field( (string) ( $t['why_now'] ?? '' ) ),
			'overlap_note'         => sanitize_text_field( (string) ( $t['overlap_note'] ?? '' ) ),
			'notes'                => sanitize_textarea_field( (string) ( $t['notes'] ?? '' ) ),
		);
	}
}
