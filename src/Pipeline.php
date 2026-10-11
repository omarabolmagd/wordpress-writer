<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the current step of a claimed job and queues the next one.
 */
final class Pipeline {

	/** Stage name => handler. Each handler returns the next stage, or null when it stops. */
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

	/** A step interrupted by the server this many times is reported as failed. */
	public const MAX_ATTEMPTS = 3;

	public static function run( int $job_id, string $token ): void {
		$state = Jobs::get( $job_id );
		if ( ! $state || ! in_array( $state['status'], array( 'queued', 'running' ), true ) ) {
			return;
		}
		$stage = (string) $state['stage'];
		if ( ! isset( self::STAGES[ $stage ] ) ) {
			Jobs::fail( $job_id, $stage, 'Unknown step: ' . $stage );
			return;
		}

		$restarted = 'running' === $state['status'];
		$attempts  = (int) ( $state['attempts'] ?? 0 ) + 1;
		if ( $attempts > self::MAX_ATTEMPTS ) {
			Jobs::fail(
				$job_id,
				$stage,
				__( 'The server stopped this step before it finished, several times in a row. Keep the article page open while it works. If it still happens, ask your host to raise the PHP time limit to 300 seconds.', 'ana-afdal-writer' )
			);
			return;
		}

		// Run as the person who started the job, so permissions and content filters match theirs.
		if ( ! empty( $state['user_id'] ) ) {
			wp_set_current_user( (int) $state['user_id'] );
		}
		Jobs::update(
			$job_id,
			static function ( array $s ) use ( $attempts, $restarted ) {
				$s['status']     = 'running';
				$s['attempts']   = $attempts;
				$s['started_at'] = time();
				$s['error']      = '';
				if ( $restarted ) {
					$s['log'][] = array( 't' => time(), 'stage' => $s['stage'], 'm' => 'The server stopped this step before it finished. Starting it again.' );
				}
				return $s;
			}
		);

		Progress::begin( $job_id, $stage, $token );
		$next = null;
		try {
			$next = call_user_func( self::STAGES[ $stage ], $job_id );
		} catch ( Cancelled $e ) {
			Jobs::log( $job_id, 'Stopped.' );
			$next = null;
		} catch ( \Throwable $e ) {
			Jobs::fail( $job_id, $stage, $e->getMessage() );
			$next = null;
		} finally {
			Progress::end();
		}

		if ( $next ) {
			Jobs::schedule( $job_id, $next );
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
