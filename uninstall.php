<?php
/**
 * Removes the plugin's settings, cost log and job records when it is deleted.
 * Posts, categories and images it created are kept.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'aaw_settings' );
delete_option( 'aaw_db_version' );
delete_option( 'aaw_pexels_used' );
delete_transient( 'aaw_worker_running' );

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aaw_costs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$job_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'aaw_job' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( $job_ids as $job_id ) {
	wp_delete_post( (int) $job_id, true );
}

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'aaw_run_stage', array(), 'ana-afdal-writer' );
}
