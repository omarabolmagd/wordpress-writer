<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's database table and default options.
 */
final class Installer {

	private const DB_VERSION        = '3';
	private const DB_VERSION_OPTION = 'aaw_db_version';

	/**
	 * Runs on activation and whenever the stored database version is older than this code's,
	 * so an update works the same whether WordPress reactivates the plugin or not.
	 */
	public static function activate(): void {
		$from = get_option( self::DB_VERSION_OPTION );
		self::create_tables();
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		if ( ! wp_next_scheduled( Worker::CRON_HOOK ) ) {
			add_filter( 'cron_schedules', array( Worker::class, 'cron_schedules' ) );
			wp_schedule_event( time() + 60, 'aaw_minute', Worker::CRON_HOOK );
		}
		if ( $from && version_compare( (string) $from, '2', '<' ) ) {
			self::upgrade_from_1();
		}
		if ( $from && version_compare( (string) $from, '3', '<' ) ) {
			// 1.2: start the research library with the research of earlier jobs.
			Library::backfill();
		}
	}

	public static function deactivate(): void {
		// Queued steps stay in their jobs and continue if the plugin is activated again.
		wp_clear_scheduled_hook( Worker::CRON_HOOK );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::activate();
		}
	}

	/**
	 * 1.0 ran steps through Action Scheduler and kept the status only inside the job's JSON.
	 * Copies the status to its own meta, which the new queue reads, and stops jobs that were
	 * mid-step during the update, so an old, abandoned job is never restarted (and billed) by surprise.
	 */
	private static function upgrade_from_1(): void {
		global $wpdb;
		// Remove 1.0's queued steps. Another plugin may still load Action Scheduler, so leave its tables.
		$as_table = $wpdb->prefix . 'actionscheduler_actions';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $as_table ) ) ) === $as_table ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$as_table} WHERE hook = %s AND status = %s", 'aaw_run_stage', 'pending' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		delete_option( 'aaw_worker_running' );
		// This runs before post types and statuses are registered, so query the table directly.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", Jobs::POST_TYPE ) );
		foreach ( $ids as $id ) {
			$job = Jobs::get( (int) $id );
			if ( ! $job ) {
				continue;
			}
			if ( in_array( $job['status'], array( 'queued', 'running' ), true ) ) {
				Jobs::fail( (int) $id, (string) $job['stage'], __( 'The plugin was updated while this step was waiting or running. Click Try this step again to continue.', 'ana-afdal-writer' ) );
			} else {
				update_post_meta( (int) $id, Jobs::STATUS_META, (string) $job['status'] );
			}
		}
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = Costs::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				job_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				provider varchar(32) NOT NULL DEFAULT '',
				item varchar(64) NOT NULL DEFAULT '',
				amount_usd decimal(12,6) NOT NULL DEFAULT 0,
				details text NULL,
				PRIMARY KEY  (id),
				KEY job_id (job_id),
				KEY created_gmt (created_gmt)
			) {$charset};"
		);
		dbDelta( Library::schema( $charset ) );
	}
}
