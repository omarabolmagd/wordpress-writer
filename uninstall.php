<?php
/**
 * Removes the plugin's settings, cost log, research library and job records when it is deleted.
 * Posts, categories and images it created are kept.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'aaw_settings' );
delete_option( 'aaw_db_version' );
delete_option( 'aaw_pexels_used' );
delete_option( 'aaw_ping' );
delete_option( 'aaw_library_cleaned' );
wp_clear_scheduled_hook( 'aaw_tick' );

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aaw_costs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aaw_research" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", 'aaw_claim_%', '_transient_aaw_%', '_transient_timeout_aaw_%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$job_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'aaw_job' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( $job_ids as $job_id ) {
	wp_delete_post( (int) $job_id, true );
}
