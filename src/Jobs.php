<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Stores each topic-suggestion run and each article as a private "job" post,
 * with its full state as JSON in post meta.
 */
final class Jobs {

	public const POST_TYPE = 'aaw_job';
	private const META        = '_aaw_state';
	public const STATUS_META  = '_aaw_status';

	public const TYPE_TOPICS  = 'topics';
	public const TYPE_ARTICLE = 'article';

	public static function register(): void {
		add_action( 'init', array( self::class, 'register_post_type' ) );
	}

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title', 'author' ),
				'labels'              => array( 'name' => 'Writer jobs' ),
			)
		);
	}

	public static function create( string $type, int $user_id, string $title, array $input ): int {
		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				'post_title'  => wp_strip_all_tags( $title ),
				'post_author' => $user_id,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( $id->get_error_message() );
		}
		$now   = time();
		$state = array(
			'id'      => (int) $id,
			'type'    => $type,
			'status'  => 'queued',
			'stage'   => '',
			'user_id' => $user_id,
			'created' => $now,
			'updated' => $now,
			'error'   => '',
			'log'     => array(),
			'input'   => $input,
			'data'    => array(),
		);
		self::save( (int) $id, $state );
		return (int) $id;
	}

	public static function get( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		// Another request (a runner, or Cancel) may have changed the job since this request cached it.
		wp_cache_delete( $id, 'post_meta' );
		$raw   = get_post_meta( $id, self::META, true );
		$state = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $state ) ? $state : null;
	}

	/** The job's status straight from the database, for checks inside a long-running step. */
	public static function status_fresh( int $id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $id, self::STATUS_META ) );
	}

	public static function save( int $id, array $state ): void {
		$state['updated'] = time();
		// wp_slash keeps backslashes in the JSON intact through update_post_meta's unslashing.
		update_post_meta( $id, self::META, wp_slash( wp_json_encode( $state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
		update_post_meta( $id, self::STATUS_META, (string) $state['status'] );
		if ( ! empty( $state['title'] ) ) {
			$post = get_post( $id );
			if ( $post && $post->post_title !== $state['title'] ) {
				wp_update_post( array( 'ID' => $id, 'post_title' => wp_strip_all_tags( $state['title'] ) ) );
			}
		}
	}

	/** Loads, changes and saves a job in one step. Returns the new state. */
	public static function update( int $id, callable $change ): array {
		$state = self::get( $id );
		if ( ! $state ) {
			throw new \RuntimeException( 'Job not found: ' . $id );
		}
		$was_cancelled = 'cancelled' === $state['status'];
		$state         = $change( $state );
		if ( $was_cancelled ) {
			// Once cancelled, a job stays cancelled whatever a still-running step tries to write.
			$state['status'] = 'cancelled';
		}
		self::save( $id, $state );
		return $state;
	}

	public static function log( int $id, string $message ): void {
		self::update(
			$id,
			static function ( array $s ) use ( $message ) {
				$s['log'][] = array( 't' => time(), 'stage' => $s['stage'], 'm' => $message );
				$s['log']   = array_slice( $s['log'], -60 );
				return $s;
			}
		);
	}

	/** Queues a stage. The open admin page runs it; otherwise a background runner does. */
	public static function schedule( int $id, string $stage ): void {
		$state = self::update(
			$id,
			static function ( array $s ) use ( $stage ) {
				if ( 'cancelled' === $s['status'] ) {
					return $s;
				}
				$s['status']    = 'queued';
				$s['stage']     = $stage;
				$s['error']     = '';
				$s['attempts']  = 0;
				$s['queued_at'] = time();
				return $s;
			}
		);
		if ( 'queued' === $state['status'] ) {
			Worker::nudge( $id );
		}
	}

	public static function fail( int $id, string $stage, string $message ): void {
		self::update(
			$id,
			static function ( array $s ) use ( $stage, $message ) {
				$s['status']       = 'failed';
				$s['stage']        = $stage;
				$s['failed_stage'] = $stage;
				$s['error']        = $message;
				$s['log'][]        = array( 't' => time(), 'stage' => $stage, 'm' => 'ERROR: ' . $message );
				return $s;
			}
		);
	}

	/** Recent jobs of a type, newest first. */
	public static function recent( string $type, int $limit = 20 ): array {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'posts_per_page' => $limit * 2,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);
		$out = array();
		foreach ( $posts as $pid ) {
			$state = self::get( (int) $pid );
			if ( $state && $state['type'] === $type ) {
				$out[] = $state;
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
		}
		return $out;
	}

	/** Finds the article job that produced a post. */
	public static function for_post( int $post_id ): ?array {
		$job_id = (int) get_post_meta( $post_id, '_aaw_job_id', true );
		return $job_id ? self::get( $job_id ) : null;
	}
}
