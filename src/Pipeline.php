<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Runs one background stage of a job and queues the next one.
 */
final class Pipeline {

	/** Stage name => [handler class, method]. Each handler returns the next stage, or null when it stops. */
	private const STAGES = array(
		'topics_research'  => array( Topics::class, 'research' ),
		'topics_structure' => array( Topics::class, 'structure' ),
		'research'         => array( Article::class, 'research' ),
		'outline'          => array( Article::class, 'outline' ),
		'write'            => array( Article::class, 'write' ),
		'images'           => array( Article::class, 'images' ),
		'assemble'         => array( Article::class, 'assemble' ),
		'quality'          => array( Quality::class, 'run' ),
		'backlinks'        => array( Backlinks::class, 'suggest' ),
	);

	public static function run( $job_id, $stage ): void {
		$job_id = (int) $job_id;
		$stage  = (string) $stage;
		if ( ! isset( self::STAGES[ $stage ] ) ) {
			return;
		}

		$lock = 'aaw_lock_' . $job_id;
		if ( get_transient( $lock ) ) {
			// The same job is already running somewhere else; try again shortly.
			as_schedule_single_action( time() + 30, Worker::ACTION, array( $job_id, $stage ), AAW_GROUP );
			return;
		}
		set_transient( $lock, 1, 15 * MINUTE_IN_SECONDS );

		try {
			$state = Jobs::get( $job_id );
			if ( ! $state || 'cancelled' === $state['status'] ) {
				return;
			}
			// Run as the person who started the job, so permissions and content filters match theirs.
			if ( ! empty( $state['user_id'] ) ) {
				wp_set_current_user( (int) $state['user_id'] );
			}
			Jobs::update(
				$job_id,
				static function ( array $s ) use ( $stage ) {
					$s['status'] = 'running';
					$s['stage']  = $stage;
					$s['error']  = '';
					return $s;
				}
			);

			$next = call_user_func( self::STAGES[ $stage ], $job_id );

			if ( $next ) {
				Jobs::schedule( $job_id, $next );
			}
		} catch ( \Throwable $e ) {
			Jobs::fail( $job_id, $stage, $e->getMessage() );
		} finally {
			delete_transient( $lock );
		}
	}

	/** Marks a job finished at a stage that waits for a person (or the end). */
	public static function wait( int $job_id, string $stage, string $status = 'waiting' ): void {
		Jobs::update(
			$job_id,
			static function ( array $s ) use ( $stage, $status ) {
				$s['status'] = $status;
				$s['stage']  = $stage;
				return $s;
			}
		);
	}
}
