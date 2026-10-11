<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Runs queued steps.
 *
 * Three runners share one queue (the job's own state):
 * 1. The open admin page: it asks the server to run the next step and waits for it,
 *    so nothing waits for cron and LiteSpeed doesn't stop the work.
 * 2. A background request fired right after a step is queued, for when the page is closed.
 * 3. A WP-Cron event every minute, as the last fallback.
 *
 * A runner must claim a job before running it. The claim carries a heartbeat; when a
 * server kills a step mid-way, the heartbeat stops and another runner restarts the step.
 */
final class Worker {

	public const AJAX_BACKGROUND = 'aaw_worker';
	public const AJAX_RUN        = 'aaw_run';
	public const AJAX_PING       = 'aaw_ping';
	public const CRON_HOOK       = 'aaw_tick';

	/** Seconds without a heartbeat before a running step counts as dead. */
	public const STALE = 150;

	/** Seconds after the last page poll during which the page drives the job, not background runners. */
	private const PRESENCE = 20;

	public static function register(): void {
		add_action( 'wp_ajax_' . self::AJAX_BACKGROUND, array( self::class, 'handle_background' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_BACKGROUND, array( self::class, 'handle_background' ) );
		add_action( 'wp_ajax_' . self::AJAX_RUN, array( self::class, 'handle_run' ) );
		add_action( 'wp_ajax_' . self::AJAX_PING, array( self::class, 'handle_ping' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_PING, array( self::class, 'handle_ping' ) );
		add_action( self::CRON_HOOK, array( self::class, 'cron' ) );
		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) );
		add_action( 'admin_init', array( self::class, 'ensure_cron' ) );
	}

	public static function cron_schedules( array $schedules ): array {
		$schedules['aaw_minute'] = array(
			'interval' => 60,
			'display'  => 'Every minute (Ana Afdal Writer)',
		);
		return $schedules;
	}

	public static function ensure_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'aaw_minute', self::CRON_HOOK );
		}
	}

	/* ------------------------------------------------------------ claims */

	private static function claim_name( int $job_id ): string {
		return 'aaw_claim_' . $job_id;
	}

	/** Claim value, read straight from the database so caches never hide another runner's claim. */
	private static function claim_raw( int $job_id ): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::claim_name( $job_id ) ) );
		return null === $value ? null : (string) $value;
	}

	/** Seconds since the claim's last heartbeat (negative while a long call is on hold), or null when unclaimed. */
	public static function claim_age( int $job_id ): ?int {
		$raw = self::claim_raw( $job_id );
		if ( null === $raw ) {
			return null;
		}
		$parts = explode( '|', $raw );
		return time() - (int) ( $parts[1] ?? 0 );
	}

	public static function claim_alive( int $job_id ): bool {
		$age = self::claim_age( $job_id );
		return null !== $age && $age <= self::STALE;
	}

	/** Atomically claims a job. Returns a token, or null if another live runner holds it. */
	public static function claim( int $job_id ): ?string {
		global $wpdb;
		$name  = self::claim_name( $job_id );
		$token = wp_generate_password( 12, false );
		$value = $token . '|' . time();

		for ( $try = 0; $try < 2; $try++ ) {
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) );
			if ( $inserted ) {
				return $token;
			}
			$current = self::claim_raw( $job_id );
			if ( null === $current ) {
				continue;
			}
			$parts = explode( '|', $current );
			if ( time() - (int) ( $parts[1] ?? 0 ) <= self::STALE ) {
				return null;
			}
			// The holder stopped sending heartbeats: take over, but only if nobody else did first.
			$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $current ) );
			return $updated ? $token : null;
		}
		return null;
	}

	/** Refreshes the claim's heartbeat. $until lets a runner reserve time for a long call. */
	public static function heartbeat( int $job_id, string $token, ?int $until = null ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s",
				$token . '|' . ( $until ?? time() ),
				self::claim_name( $job_id ),
				$wpdb->esc_like( $token . '|' ) . '%'
			)
		);
	}

	public static function release( int $job_id, string $token ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::claim_name( $job_id ), $wpdb->esc_like( $token . '|' ) . '%' ) );
	}

	/* ---------------------------------------------------------- presence */

	/** Remembers that an admin page is watching this job right now. */
	public static function seen( int $job_id ): void {
		set_transient( 'aaw_seen_' . $job_id, time(), 60 );
	}

	public static function watched( int $job_id ): bool {
		$t = (int) get_transient( 'aaw_seen_' . $job_id );
		return $t && time() - $t <= self::PRESENCE;
	}

	/* --------------------------------------------------------------- queue */

	/** A job a runner may pick up now: queued, or running with a dead heartbeat. */
	public static function runnable( ?array $job ): bool {
		if ( ! $job ) {
			return false;
		}
		if ( 'queued' === $job['status'] ) {
			return ! self::claim_alive( (int) $job['id'] );
		}
		return 'running' === $job['status'] && ! self::claim_alive( (int) $job['id'] );
	}

	/** Job ids that are queued or running, oldest first. */
	public static function pending_ids(): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Jobs::POST_TYPE,
					'post_status'    => 'private',
					'posts_per_page' => 20,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'fields'         => 'ids',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => '_aaw_status',
							'value'   => array( 'queued', 'running' ),
							'compare' => 'IN',
						),
					),
				)
			)
		);
	}

	/**
	 * Runs steps until the time budget runs out or nothing is left.
	 *
	 * @param int      $budget    Seconds this runner may keep starting new steps.
	 * @param int|null $only_job  Run only this job (the open admin page).
	 * @param int      $max_steps Stop after this many steps (0 = no limit).
	 * @param bool     $background Skip jobs an open page is driving.
	 * @return array{ran:int, busy:bool}
	 */
	public static function run_pending( int $budget, ?int $only_job = null, int $max_steps = 0, bool $background = false ): array {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $budget + 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		ignore_user_abort( true );

		$started = time();
		$ran     = 0;
		$busy    = false;
		$skip    = array();

		while ( time() - $started < $budget ) {
			$job_id = 0;
			foreach ( $only_job ? array( $only_job ) : self::pending_ids() as $candidate ) {
				if ( in_array( $candidate, $skip, true ) ) {
					continue;
				}
				if ( $background && self::watched( $candidate ) ) {
					continue;
				}
				if ( self::runnable( Jobs::get( $candidate ) ) ) {
					$job_id = $candidate;
					break;
				}
			}
			if ( ! $job_id ) {
				break;
			}
			$token = self::claim( $job_id );
			if ( ! $token ) {
				$busy   = true;
				$skip[] = $job_id;
				if ( $only_job ) {
					break;
				}
				continue;
			}
			try {
				Pipeline::run( $job_id, $token );
			} finally {
				self::release( $job_id, $token );
			}
			++$ran;
			if ( $max_steps && $ran >= $max_steps ) {
				break;
			}
		}
		return array( 'ran' => $ran, 'busy' => $busy );
	}

	/* ------------------------------------------------------------ triggers */

	private static function token( int $ts, string $purpose ): string {
		return hash_hmac( 'sha256', $purpose . '|' . $ts, wp_salt( 'auth' ) );
	}

	private static function fire( string $action ): void {
		$ts = time();
		wp_remote_post(
			add_query_arg( 'action', $action, admin_url( 'admin-ajax.php' ) ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'ts'    => $ts,
					'token' => self::token( $ts, $action ),
				),
			)
		);
	}

	/** True while handling a request from the admin page, which will run the step itself. */
	private static function from_page(): bool {
		return ( defined( 'REST_REQUEST' ) && REST_REQUEST && Plugin::can_use() ) || doing_action( 'wp_ajax_' . self::AJAX_RUN );
	}

	/** After a step is queued: start a background runner unless an open page is driving the job. */
	public static function nudge( int $job_id ): void {
		self::ensure_cron();
		if ( self::from_page() ) {
			self::seen( $job_id );
			return;
		}
		if ( ! self::watched( $job_id ) && apply_filters( 'aaw_background_kick', true ) ) {
			self::fire( self::AJAX_BACKGROUND );
		}
	}

	private static function verify_signed( string $action ): void {
		$ts    = isset( $_POST['ts'] ) ? (int) $_POST['ts'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- signed with an HMAC instead.
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( abs( time() - $ts ) > 300 || ! hash_equals( self::token( $ts, $action ), $token ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
	}

	public static function handle_background(): void {
		self::verify_signed( self::AJAX_BACKGROUND );
		self::run_pending( 300, null, 0, true );
		wp_die( '', '', array( 'response' => 200 ) );
	}

	public static function cron(): void {
		if ( (int) get_option( 'aaw_library_cleaned', 0 ) < time() - DAY_IN_SECONDS ) {
			update_option( 'aaw_library_cleaned', time(), false );
			Library::cleanup();
		}
		self::run_pending( 240, null, 0, true );
	}

	/**
	 * The open admin page runs the next step of its job here and waits for the answer.
	 * While the step runs, a space is sent every few seconds: that keeps proxies and
	 * LiteSpeed from closing a connection that looks idle. JSON allows leading whitespace.
	 */
	public static function handle_run(): void {
		check_ajax_referer( self::AJAX_RUN );
		if ( ! Plugin::can_use() ) {
			wp_send_json_error( null, 403 );
		}
		$job_id = isset( $_POST['job'] ) ? absint( $_POST['job'] ) : 0;
		$job    = Jobs::get( $job_id );
		if ( ! $job ) {
			wp_send_json_error( null, 404 );
		}
		self::seen( $job_id );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Accel-Buffering: no' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		@ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		echo str_repeat( ' ', 512 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		flush();

		Progress::set_keepalive(
			static function () use ( $job_id ) {
				echo ' ';
				flush();
				self::seen( $job_id );
			}
		);
		$result = self::run_pending( 600, $job_id, 1 );
		Progress::set_keepalive( null );

		echo wp_json_encode(
			array(
				'ran'  => $result['ran'],
				'busy' => $result['busy'],
				'job'  => Rest::present( Jobs::get( $job_id ) ),
			)
		);
		exit;
	}

	/* ---------------------------------------------------- background test */

	public static function handle_ping(): void {
		self::verify_signed( self::AJAX_PING );
		ignore_user_abort( true );
		sleep( 3 );
		update_option( 'aaw_ping', time(), false );
		wp_die( '', '', array( 'response' => 200 ) );
	}

	/**
	 * Checks whether this server keeps a background request running after the request that
	 * started it disconnects. LiteSpeed, for example, stops it unless "noabort" is set.
	 */
	public static function test_background(): array {
		global $wpdb;
		delete_option( 'aaw_ping' );
		$start = time();
		self::fire( self::AJAX_PING );
		for ( $i = 0; $i < 10; $i++ ) {
			sleep( 1 );
			$value = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'aaw_ping' ) );
			if ( $value >= $start ) {
				return array(
					'ok'      => true,
					'message' => __( 'Background work keeps running after you leave the page', 'ana-afdal-writer' ),
				);
			}
		}
		return array(
			'ok'      => false,
			'message' => __( 'The server stops background work. Keep the article page open while it works, or add the LiteSpeed rule shown below.', 'ana-afdal-writer' ),
		);
	}

	public static function is_litespeed(): bool {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		return false !== stripos( $software, 'litespeed' ) || isset( $_SERVER['LSWS_EDITION'] ) || isset( $_SERVER['X-LSCACHE'] );
	}

	/** The .htaccess rule that lets this plugin's background requests finish on LiteSpeed. */
	public static function litespeed_rule(): string {
		return "# BEGIN Ana Afdal Writer\n<IfModule Litespeed>\nRewriteEngine On\nRewriteRule ^wp-cron\\.php$ - [E=noabort:1,E=noconntimeout:1]\nRewriteCond %{QUERY_STRING} action=aaw_(worker|ping)\nRewriteRule admin-ajax\\.php$ - [E=noabort:1,E=noconntimeout:1]\n</IfModule>\n# END Ana Afdal Writer";
	}
}
