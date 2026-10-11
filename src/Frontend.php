<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Small styles for the "read also" and Telegram boxes inside posts.
 */
final class Frontend {

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'styles' ) );
	}

	public static function styles(): void {
		if ( ! is_singular( 'post' ) ) {
			return;
		}
		$post = get_post();
		if ( ! $post || ( ! str_contains( $post->post_content, 'aaw-read-more' ) && ! str_contains( $post->post_content, 'aaw-telegram' ) ) ) {
			return;
		}
		$color = sanitize_hex_color( (string) Settings::get( 'brand_color' ) );
		$color = $color ? $color : '#00AEAD';
		wp_register_style( 'aaw-frontend', false, array(), AAW_VERSION );
		wp_enqueue_style( 'aaw-frontend' );
		wp_add_inline_style(
			'aaw-frontend',
			".aaw-read-more,.aaw-telegram{padding:14px 18px;border-radius:8px;margin:1.5em 0}"
			. ".aaw-read-more{background:color-mix(in srgb,{$color} 8%,#fff);border-inline-start:4px solid {$color}}"
			. ".aaw-telegram{background:color-mix(in srgb,{$color} 12%,#fff);font-weight:600}"
			. ".aaw-read-more a,.aaw-telegram a{color:{$color};font-weight:700}"
		);
	}
}
