<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Topic suggestions: Claude researches current demand, then proposes articles
 * that don't repeat what the site already has.
 */
final class Topics {

	/**
	 * @param bool $reuse Use a recent topic search for the same focus instead of searching again.
	 */
	public static function start( int $user_id, string $focus, bool $reuse = true ): int {
		$saved  = $reuse ? Library::ideas_for( $focus ) : null;
		$job_id = Jobs::create( Jobs::TYPE_TOPICS, $user_id, 'Topic suggestions ' . wp_date( 'Y-m-d H:i' ), array( 'focus' => $focus ) );
		if ( ! $saved ) {
			Jobs::schedule( $job_id, 'topics_research' );
			return $job_id;
		}
		Jobs::update(
			$job_id,
			static function ( array $s ) use ( $saved ) {
				$s['data']['notes']        = $saved['notes'];
				$s['data']['sources']      = $saved['sources'];
				$s['data']['ideas_id']     = (int) $saved['id'];
				$s['data']['reused_ideas'] = array(
					'id'      => (int) $saved['id'],
					'created' => (int) $saved['created'],
				);
				return $s;
			}
		);
		Library::mark_used( array( (int) $saved['id'] ) );
		Jobs::log( $job_id, 'Reusing the topic search from ' . gmdate( 'Y-m-d H:i', (int) $saved['created'] ) . ' UTC, so no new searches are needed.' );
		Jobs::schedule( $job_id, 'topics_structure' );
		return $job_id;
	}

	public static function research( int $job_id ): string {
		$state = Jobs::get( $job_id );
		list( $system, $prompt ) = Prompts::topics_research( Site::existing_titles(), array_values( Site::categories() ), (string) ( $state['input']['focus'] ?? '' ) );

		Jobs::log( $job_id, 'Searching the web for what readers are looking for…' );
		$result = Claude::research( $system, $prompt, $job_id, 'topics_research' );

		$focus    = (string) ( $state['input']['focus'] ?? '' );
		$ideas_id = Library::save(
			array(
				'kind'     => Library::KIND_IDEAS,
				'job_id'   => $job_id,
				'title'    => $focus,
				'focus'    => $focus,
				'notes'    => $result['text'],
				'sources'  => array_values( $result['sources'] ),
				'searches' => max( $result['searches'], Costs::job_item_searches( $job_id, 'topics_research' ) ),
				'cost'     => Costs::job_item_total( $job_id, 'topics_research' ),
			)
		);

		Jobs::update(
			$job_id,
			static function ( array $s ) use ( $result, $ideas_id ) {
				$s['data']['notes']    = $result['text'];
				$s['data']['sources']  = array_values( $result['sources'] );
				$s['data']['ideas_id'] = $ideas_id;
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

		$ideas_id = (int) ( $state['data']['ideas_id'] ?? 0 );
		$already  = array();
		if ( ! empty( $state['data']['reused_ideas'] ) ) {
			$saved   = Library::get( $ideas_id );
			$already = $saved ? (array) ( $saved['meta']['suggested'] ?? array() ) : array();
		}

		list( $system, $prompt ) = Prompts::topics_structure( (string) $state['data']['notes'], $categories, $existing, $already );
		$data = Claude::json( $system, $prompt, Prompts::topics_schema(), $job_id, 'topics_structure' );

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
			$topic['ideas_id'] = $ideas_id;
			$topics[]          = $topic;
		}
		if ( $ideas_id && $topics ) {
			Library::add_suggested( $ideas_id, wp_list_pluck( $topics, 'title' ) );
		}

		Jobs::update(
			$job_id,
			static function ( array $s ) use ( $topics ) {
				$s['data']['topics'] = $topics;
				return $s;
			}
		);
		Jobs::log(
			$job_id,
			$topics || ! $already
				? sprintf( '%d topics suggested.', count( $topics ) )
				: 'No new ideas were left in the saved topic search. Untick "Reuse" to search the web again.'
		);
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
			// The topic search this came from, so its notes can help the article's research.
			'ideas_id'             => absint( $t['ideas_id'] ?? 0 ),
		);
	}
}
