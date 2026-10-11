<?php
/**
 * Test-only mu-plugin: imitates whatever on anaafdal.com turns slugs into "8313-vytuja"
 * when a post is published, in two different ways, so SlugGuard can be tested.
 */

add_filter(
	'wp_insert_post_data',
	static function ( $data, $postarr ) {
		if ( 'post' === $data['post_type'] && 'publish' === $data['post_status'] && ! empty( $postarr['ID'] ) && ! get_option( 'aaw_test_no_random' ) ) {
			$data['post_name'] = $postarr['ID'] . '-' . substr( str_shuffle( 'abcdefghijklmnopqrstuvwxyz' ), 0, 6 );
		}
		return $data;
	},
	10,
	2
);

add_action(
	'save_post',
	static function ( $post_id, $post ) {
		if ( 'post' === $post->post_type && 'publish' === $post->post_status && get_option( 'aaw_test_db_slug_rewrite' ) ) {
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_name' => $post_id . '-zzzzzz' ), array( 'ID' => $post_id ) );
		}
	},
	10,
	2
);
