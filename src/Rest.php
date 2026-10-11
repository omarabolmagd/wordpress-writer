<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoints used by the plugin's admin screens.
 */
final class Rest {

	public const NS = 'aaw/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		$use    = static fn() => Plugin::can_use();
		$manage = static fn() => current_user_can( Plugin::CAP_MANAGE );
		$id     = array( 'id' => array( 'validate_callback' => static fn( $v ) => is_numeric( $v ) ) );

		register_rest_route( self::NS, '/overview', array( 'methods' => 'GET', 'callback' => array( self::class, 'overview' ), 'permission_callback' => $use ) );
		register_rest_route( self::NS, '/topics', array( 'methods' => 'POST', 'callback' => array( self::class, 'start_topics' ), 'permission_callback' => $use ) );
		register_rest_route( self::NS, '/articles', array( 'methods' => 'POST', 'callback' => array( self::class, 'start_article' ), 'permission_callback' => $use ) );
		register_rest_route( self::NS, '/jobs/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( self::class, 'job' ), 'permission_callback' => $use, 'args' => $id ) );
		register_rest_route( self::NS, '/jobs/(?P<id>\d+)/approve', array( 'methods' => 'POST', 'callback' => array( self::class, 'approve' ), 'permission_callback' => $use, 'args' => $id ) );
		register_rest_route( self::NS, '/jobs/(?P<id>\d+)/retry', array( 'methods' => 'POST', 'callback' => array( self::class, 'retry' ), 'permission_callback' => $use, 'args' => $id ) );
		register_rest_route( self::NS, '/jobs/(?P<id>\d+)/cancel', array( 'methods' => 'POST', 'callback' => array( self::class, 'cancel' ), 'permission_callback' => $use, 'args' => $id ) );
		register_rest_route( self::NS, '/jobs/(?P<id>\d+)/backlinks/(?P<index>\d+)', array( 'methods' => 'POST', 'callback' => array( self::class, 'backlink' ), 'permission_callback' => $use, 'args' => $id ) );
		register_rest_route( self::NS, '/test-connections', array( 'methods' => 'POST', 'callback' => array( self::class, 'test_connections' ), 'permission_callback' => $manage ) );
	}

	private static function error( string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( 'aaw_error', $message, array( 'status' => $status ) );
	}

	public static function overview(): \WP_REST_Response {
		$authors = array();
		foreach ( get_users( array( 'capability' => 'edit_posts', 'fields' => array( 'ID', 'display_name' ), 'number' => 50 ) ) as $u ) {
			$authors[] = array( 'id' => (int) $u->ID, 'name' => $u->display_name );
		}
		$articles = array();
		foreach ( Jobs::recent( Jobs::TYPE_ARTICLE, 25 ) as $job ) {
			$articles[] = self::summary( $job );
		}
		$topics_jobs = Jobs::recent( Jobs::TYPE_TOPICS, 1 );
		$templates   = array();
		foreach ( Prompts::templates() as $key => $t ) {
			$templates[ $key ] = array( 'label' => $t['label'], 'words' => $t['words'] );
		}
		return rest_ensure_response(
			array(
				'ready'         => Settings::readiness(),
				'month_total'   => Costs::month_total(),
				'cap'           => Costs::cap(),
				'cap_reached'   => Costs::cap_reached(),
				'categories'    => self::assoc_list( Site::categories() ),
				'authors'       => $authors,
				'current_user'  => get_current_user_id(),
				'templates'     => $templates,
				'image_sources' => Images::available_sources(),
				'articles'      => $articles,
				'topics_job'    => $topics_jobs ? self::present( $topics_jobs[0] ) : null,
				'can_manage'    => current_user_can( Plugin::CAP_MANAGE ),
				'settings_url'  => admin_url( 'admin.php?page=' . Settings::PAGE ),
			)
		);
	}

	private static function assoc_list( array $map ): array {
		$out = array();
		foreach ( $map as $id => $name ) {
			$out[] = array( 'id' => (int) $id, 'name' => $name );
		}
		return $out;
	}

	private static function check_budget_and_key(): ?\WP_Error {
		if ( ! Settings::has_key( 'anthropic_key' ) ) {
			return self::error( __( 'Add your Claude API key in Settings first.', 'ana-afdal-writer' ) );
		}
		if ( Costs::cap_reached() ) {
			return self::error( __( 'This month\'s spending cap is reached. Raise it in Settings or wait until next month.', 'ana-afdal-writer' ), 402 );
		}
		return null;
	}

	public static function start_topics( \WP_REST_Request $request ) {
		$blocked = self::check_budget_and_key();
		if ( $blocked ) {
			return $blocked;
		}
		$focus  = sanitize_text_field( (string) $request->get_param( 'focus' ) );
		$job_id = Topics::start( get_current_user_id(), $focus );
		return rest_ensure_response( self::present( Jobs::get( $job_id ) ) );
	}

	public static function start_article( \WP_REST_Request $request ) {
		$blocked = self::check_budget_and_key();
		if ( $blocked ) {
			return $blocked;
		}
		$topic = (array) $request->get_param( 'topic' );
		try {
			$job_id = Article::start( get_current_user_id(), $topic );
		} catch ( \Throwable $e ) {
			return self::error( $e->getMessage() );
		}
		return rest_ensure_response( self::present( Jobs::get( $job_id ) ) );
	}

	public static function job( \WP_REST_Request $request ) {
		$job = Jobs::get( (int) $request['id'] );
		if ( ! $job ) {
			return self::error( __( 'Not found.', 'ana-afdal-writer' ), 404 );
		}
		// The page is watching: it drives this job, so background runners leave it alone.
		if ( in_array( $job['status'], array( 'queued', 'running' ), true ) ) {
			Worker::seen( (int) $job['id'] );
		}
		return rest_ensure_response( self::present( $job ) );
	}

	public static function approve( \WP_REST_Request $request ) {
		try {
			$job = Article::approve( (int) $request['id'], (array) $request->get_param( 'outline' ) );
		} catch ( \Throwable $e ) {
			return self::error( $e->getMessage() );
		}
		return rest_ensure_response( self::present( $job ) );
	}

	public static function retry( \WP_REST_Request $request ) {
		$job = Jobs::get( (int) $request['id'] );
		if ( ! $job || 'failed' !== $job['status'] || empty( $job['failed_stage'] ) ) {
			return self::error( __( 'Only a failed step can be retried.', 'ana-afdal-writer' ) );
		}
		Jobs::schedule( (int) $job['id'], (string) $job['failed_stage'] );
		return rest_ensure_response( self::present( Jobs::get( (int) $job['id'] ) ) );
	}

	public static function cancel( \WP_REST_Request $request ) {
		$job_id = (int) $request['id'];
		$job    = Jobs::get( $job_id );
		if ( ! $job ) {
			return self::error( __( 'Not found.', 'ana-afdal-writer' ), 404 );
		}
		Jobs::update(
			$job_id,
			static function ( array $s ) {
				$s['status'] = 'cancelled';
				$s['log'][]  = array( 't' => time(), 'stage' => $s['stage'], 'm' => 'Cancelled.' );
				return $s;
			}
		);
		return rest_ensure_response( self::present( Jobs::get( $job_id ) ) );
	}

	public static function backlink( \WP_REST_Request $request ) {
		try {
			$job = Backlinks::decide( (int) $request['id'], (int) $request['index'], (bool) $request->get_param( 'approve' ) );
		} catch ( \Throwable $e ) {
			return self::error( $e->getMessage() );
		}
		return rest_ensure_response( self::present( $job ) );
	}

	/** Short form for lists. */
	private static function summary( array $job ): array {
		$post_id = (int) ( $job['data']['post_id'] ?? 0 );
		return array(
			'id'          => (int) $job['id'],
			'title'       => (string) ( $job['title'] ?? ( $job['input']['topic']['title'] ?? '' ) ),
			'status'      => $job['status'],
			'stage'       => $job['stage'],
			'created'     => (int) $job['created'],
			'updated'     => (int) $job['updated'],
			'post_id'     => $post_id,
			'post_status' => $post_id ? get_post_status( $post_id ) : '',
			'cost'        => Costs::job_total( (int) $job['id'] ),
		);
	}

	/** Everything the job screen needs, without internal-only fields. */
	public static function present( ?array $job ): ?array {
		if ( ! $job ) {
			return null;
		}
		$out            = self::summary( $job );
		$out['type']    = $job['type'];
		$out['error']   = (string) $job['error'];
		$out['log']     = array_slice( (array) $job['log'], -25 );
		$out['input']   = $job['input'];
		$out['topics']  = $job['data']['topics'] ?? array();
		$out['outline'] = $job['data']['outline'] ?? null;
		$out['sources'] = array_values(
			array_map(
				static fn( $s ) => array(
					'url'   => $s['url'],
					'title' => $s['title'],
					'cited' => ! empty( $s['cited'] ),
				),
				$job['data']['research']['sources'] ?? array()
			)
		);
		$out['outline_links'] = array();
		foreach ( (array) ( $job['data']['outline']['internal_link_ids'] ?? array() ) as $pid ) {
			$out['outline_links'][] = array(
				'id'    => (int) $pid,
				'title' => html_entity_decode( get_the_title( (int) $pid ), ENT_QUOTES, 'UTF-8' ),
				'url'   => (string) get_permalink( (int) $pid ),
			);
		}
		$out['checklist']   = $job['data']['checklist'] ?? array();
		$out['backlinks']   = $job['data']['backlinks'] ?? array();
		$out['link_report'] = $job['data']['link_report'] ?? null;
		$out['words']       = isset( $job['data']['draft'] ) ? Article::word_count( $job['data']['draft'] ) : 0;
		$out['can_retry']   = 'failed' === $job['status'] && ! empty( $job['failed_stage'] );
		if ( $out['post_id'] ) {
			$out['edit_url']    = get_edit_post_link( $out['post_id'], 'raw' );
			$out['preview_url'] = get_preview_post_link( $out['post_id'] );
			$out['view_url']    = get_permalink( $out['post_id'] );
		}
		$active = in_array( $job['status'], array( 'queued', 'running' ), true );
		$out['active']    = $active;
		$out['now']       = time();
		$out['queued_at'] = (int) ( $job['queued_at'] ?? $job['updated'] );
		$out['attempts']  = (int) ( $job['attempts'] ?? 0 );
		$out['runnable']  = $active && Worker::runnable( $job );
		$out['progress']  = $active ? Progress::get( (int) $job['id'] ) : null;
		if ( $out['progress'] && ( $out['progress']['stage'] ?? '' ) !== $job['stage'] ) {
			$out['progress'] = null;
		}
		return $out;
	}

	public static function test_connections(): \WP_REST_Response {
		$results = array();

		$key = Settings::key( 'anthropic_key' );
		if ( $key ) {
			$r = wp_remote_get(
				'https://api.anthropic.com/v1/models/' . rawurlencode( (string) Settings::get( 'claude_model' ) ),
				array(
					'timeout' => 20,
					'headers' => array(
						'x-api-key'         => $key,
						'anthropic-version' => '2023-06-01',
					),
				)
			);
			$code              = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
			$results['claude'] = self::verdict( $code, is_wp_error( $r ) ? $r->get_error_message() : '' );
		} else {
			$results['claude'] = array( 'ok' => false, 'message' => __( 'No key', 'ana-afdal-writer' ) );
		}

		$key = Settings::key( 'gemini_key' );
		if ( $key ) {
			$r                 = wp_remote_get(
				'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1',
				array(
					'timeout' => 20,
					'headers' => array( 'x-goog-api-key' => $key ),
				)
			);
			$code              = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
			$results['gemini'] = self::verdict( $code, is_wp_error( $r ) ? $r->get_error_message() : '' );
		} else {
			$results['gemini'] = array( 'ok' => false, 'message' => __( 'No key (AI images off)', 'ana-afdal-writer' ) );
		}

		$key = Settings::key( 'pexels_key' );
		if ( $key ) {
			$r                 = wp_remote_get(
				'https://api.pexels.com/v1/search?query=study&per_page=1',
				array(
					'timeout' => 20,
					'headers' => array( 'Authorization' => $key ),
				)
			);
			$code              = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
			$results['pexels'] = self::verdict( $code, is_wp_error( $r ) ? $r->get_error_message() : '' );
		} else {
			$results['pexels'] = array( 'ok' => false, 'message' => __( 'No key (stock photos off)', 'ana-afdal-writer' ) );
		}

		$results['screenshot'] = Settings::has_key( 'screenshot_key' )
			? array( 'ok' => true, 'message' => __( 'Key saved (tested on first use)', 'ana-afdal-writer' ) )
			: array( 'ok' => false, 'message' => __( 'No key (screenshots off)', 'ana-afdal-writer' ) );

		$results['cover'] = Cover::available()
			? array( 'ok' => true, 'message' => __( 'Server can draw covers', 'ana-afdal-writer' ) )
			: array( 'ok' => false, 'message' => __( 'PHP GD with FreeType is missing', 'ana-afdal-writer' ) );

		$results['background'] = Worker::test_background();

		// Long steps (research, writing) can take a few minutes, so the server must allow it.
		$disabled  = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		$can_raise = function_exists( 'set_time_limit' ) && ! in_array( 'set_time_limit', $disabled, true );
		$limit     = (int) ini_get( 'max_execution_time' );
		$ok        = $can_raise || 0 === $limit || $limit >= 240;
		if ( $ok ) {
			/* translators: 1: seconds, 2: yes or no */
			$message = sprintf( __( 'Time limit OK (%1$ds, can raise: %2$s)', 'ana-afdal-writer' ), $limit, $can_raise ? __( 'yes', 'ana-afdal-writer' ) : __( 'no', 'ana-afdal-writer' ) );
		} else {
			/* translators: %d: seconds */
			$message = sprintf( __( 'PHP time limit is %ds and cannot be raised. Ask your host for 300s.', 'ana-afdal-writer' ), $limit );
		}
		$results['time'] = array(
			'ok'      => $ok,
			'message' => $message,
		);

		return rest_ensure_response( $results );
	}

	private static function verdict( int $code, string $error ): array {
		if ( 200 === $code ) {
			return array( 'ok' => true, 'message' => __( 'Connected', 'ana-afdal-writer' ) );
		}
		if ( 401 === $code || 403 === $code ) {
			return array( 'ok' => false, 'message' => __( 'Key rejected', 'ana-afdal-writer' ) );
		}
		if ( 404 === $code ) {
			return array( 'ok' => false, 'message' => __( 'Model or endpoint not found — check the model name', 'ana-afdal-writer' ) );
		}
		/* translators: %s: HTTP status or error */
		return array( 'ok' => false, 'message' => sprintf( __( 'Failed (%s)', 'ana-afdal-writer' ), $code ? 'HTTP ' . $code : $error ) );
	}
}
