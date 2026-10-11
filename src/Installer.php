<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's database table and default options.
 */
final class Installer {

	private const DB_VERSION        = '1';
	private const DB_VERSION_OPTION = 'aaw_db_version';

	public static function activate(): void {
		self::create_tables();
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	public static function deactivate(): void {
		// Scheduled steps stay in the queue and resume if the plugin is re-activated.
	}

	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::activate();
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
	}
}
