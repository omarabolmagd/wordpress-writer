<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Runs queued stages in the background.
 *
 * Stages are stored in Action Scheduler, so its WP-Cron runner picks them up
 * even if everything else fails. To avoid waiting up to a minute between stages,
 * the plugin also fires a non-blocking request to admin-ajax.php that processes
 * this plugin's due actions immediately.
 */
final class Worker {

	public const ACTION      = 'aaw_run_stage';
	private const AJAX       = 'aaw_worker';
	private const RUNNING    = 'aaw_worker_running';
	private const MAX_SECONDS = 480;

	public static function register(): void {
		add_action( self::ACTION, array( Pipeline::class, 'run' ), 10, 2 );
		add_action( 'wp_ajax_' . self::AJAX, array( self::class, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX, array( self::class, 'handle' ) );
	}

	private static function token( int $ts ): string {
		return hash_hmac( 'sha256', self::AJAX . '|' . $ts, wp_salt( 'auth' ) );
	}

	/** Fires a non-blocking request that processes due stages right away. */
	public static function kick(): void {
		if ( get_transient( self::RUNNING ) ) {
			return;
		}
		$ts = time();
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action' => self::AJAX,
					'ts'     => $ts,
					'token'  => self::token( $ts ),
				),
			)
		);
	}

	public static function handle(): void {
		$ts    = isset( $_POST['ts'] ) ? (int) $_POST['ts'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- HMAC token below.
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( abs( time() - $ts ) > 300 || ! hash_equals( self::token( $ts ), $token ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		self::process_queue();
		wp_die( '', '', array( 'response' => 200 ) );
	}

	/** Processes this plugin's due stages, one after another, within a time budget. */
	public static function process_queue(): int {
		if ( ! class_exists( '\ActionScheduler' ) || get_transient( self::RUNNING ) ) {
			return 0;
		}
		set_transient( self::RUNNING, 1, self::MAX_SECONDS + 120 );
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( self::MAX_SECONDS + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$started   = time();
		$processed = 0;
		try {
			$store  = \ActionScheduler::store();
			$runner = \ActionScheduler::runner();
			while ( time() - $started < self::MAX_SECONDS ) {
				// Filter by hook only: right after install Action Scheduler's hybrid store throws for unknown groups.
				$claim   = $store->stake_claim( 1, null, array( self::ACTION ) );
				$actions = $claim->get_actions();
				if ( ! $actions ) {
					$store->release_claim( $claim );
					break;
				}
				foreach ( $actions as $action_id ) {
					$runner->process_action( $action_id, 'Ana Afdal Writer' );
					++$processed;
				}
				$store->release_claim( $claim );
			}
		} finally {
			delete_transient( self::RUNNING );
		}
		return $processed;
	}

	/** True when this plugin has stages waiting to run. */
	public static function has_pending(): bool {
		return function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::ACTION, null, AAW_GROUP );
	}

	public static function is_running(): bool {
		return (bool) get_transient( self::RUNNING );
	}
}
