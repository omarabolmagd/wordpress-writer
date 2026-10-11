<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the English slug the plugin chose, even if another plugin or setting
 * rewrites slugs into random ones (like 8313-vytuja) when the post is published.
 *
 * Only automatic-looking slugs are overridden. If an editor deliberately types
 * a different slug, that choice is respected and remembered.
 */
final class SlugGuard {

	public const META = '_aaw_slug';

	public static function register(): void {
		add_filter( 'wp_insert_post_data', array( self::class, 'filter_data' ), PHP_INT_MAX, 2 );
		add_action( 'wp_insert_post', array( self::class, 'after_insert' ), PHP_INT_MAX, 2 );
	}

	/** True for slugs that look machine-made rather than chosen by a person. */
	public static function is_automatic( string $slug, int $post_id, string $title ): bool {
		if ( '' === $slug ) {
			return true;
		}
		if ( preg_match( '/^\d+(?:-[a-z0-9]{3,10})?(?:-\d+)?$/', $slug ) ) {
			return true;
		}
		if ( $post_id && preg_match( '/^' . $post_id . '(?:-|$)/', $slug ) ) {
			return true;
		}
		$from_title = sanitize_title( $title );
		return '' !== $from_title && ( $slug === $from_title || preg_match( '/^' . preg_quote( $from_title, '/' ) . '-\d+$/', $slug ) );
	}

	private static function desired( array $postarr ): string {
		if ( ! empty( $postarr['meta_input'][ self::META ] ) ) {
			return (string) $postarr['meta_input'][ self::META ];
		}
		$id = (int) ( $postarr['ID'] ?? 0 );
		return $id ? (string) get_post_meta( $id, self::META, true ) : '';
	}

	public static function filter_data( array $data, array $postarr ): array {
		if ( 'post' !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}
		$desired = self::desired( $postarr );
		if ( '' === $desired ) {
			return $data;
		}
		$post_id = (int) ( $postarr['ID'] ?? 0 );
		$current = (string) $data['post_name'];
		if ( $current === $desired || preg_match( '/^' . preg_quote( $desired, '/' ) . '-\d+$/', $current ) ) {
			return $data;
		}
		if ( ! self::is_automatic( $current, $post_id, (string) $data['post_title'] ) ) {
			// A person picked another slug on purpose: keep it from now on.
			if ( $post_id ) {
				update_post_meta( $post_id, self::META, $current );
			}
			return $data;
		}
		$data['post_name'] = self::unique( $desired, $post_id, (string) $data['post_status'], (int) $data['post_parent'] );
		return $data;
	}

	/** Catches plugins that rewrite the slug directly in the database after saving. */
	public static function after_insert( int $post_id, \WP_Post $post ): void {
		if ( 'post' !== $post->post_type || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$desired = (string) get_post_meta( $post_id, self::META, true );
		if ( '' === $desired ) {
			return;
		}
		global $wpdb;
		// Read straight from the database: a plugin that wrote there directly may not have cleared the cache.
		$fresh = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_name FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
		if ( $fresh === $desired || preg_match( '/^' . preg_quote( $desired, '/' ) . '-\d+$/', $fresh ) || ! self::is_automatic( $fresh, $post_id, $post->post_title ) ) {
			return;
		}
		$wpdb->update( $wpdb->posts, array( 'post_name' => self::unique( $desired, $post_id, $post->post_status, (int) $post->post_parent ) ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
	}

	private static function unique( string $slug, int $post_id, string $status, int $parent ): string {
		if ( in_array( $status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
			return $slug;
		}
		return wp_unique_post_slug( $slug, $post_id, $status, 'post', $parent );
	}
}
