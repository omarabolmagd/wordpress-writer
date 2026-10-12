<?php
/**
 * Test-only mu-plugin for the browser test: replaces external APIs with mocks
 * and can switch the admin to Arabic/RTL. Never install this on a real site.
 */

// "server": Claude requests go over real HTTP to tests/mock-anthropic-server.php on port 8081.
if ( 'server' === get_option( 'aaw_test_claude' ) && ! defined( 'AAW_ANTHROPIC_ENDPOINT' ) ) {
	define( 'AAW_ANTHROPIC_ENDPOINT', 'http://localhost:8081/v1/messages' );
}

if ( get_option( 'aaw_test_mocks' ) ) {
	add_action(
		'plugins_loaded',
		static function () {
			if ( ! function_exists( 'aaw_test_install_mocks' ) ) {
				$mocks = WP_PLUGIN_DIR . '/ana-afdal-writer/tests/mocks.php';
				// When testing the built zip (which has no tests folder), use a copy next to this file.
				require_once is_readable( $mocks ) ? $mocks : __DIR__ . '/aaw-mocks.php';
			}
			aaw_test_install_mocks( 'server' === get_option( 'aaw_test_claude' ) ? 'none' : 'stream' );
			// Simulated API problems, e.g. wp option update aaw_test_flags '{"gemini_free_tier":true}' --format=json
			$GLOBALS['aaw_test_flags'] = array_merge( $GLOBALS['aaw_test_flags'] ?? array(), (array) get_option( 'aaw_test_flags', array() ) );
		},
		20
	);
}

if ( get_option( 'aaw_test_rtl' ) ) {
	add_filter( 'locale', static fn() => 'ar' );
	add_action(
		'init',
		static function () {
			$GLOBALS['wp_locale']->text_direction = 'rtl';
		},
		0
	);
}
