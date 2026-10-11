<?php
/**
 * Test-only mu-plugin for the browser test: replaces external APIs with mocks
 * and can switch the admin to Arabic/RTL. Never install this on a real site.
 */

if ( get_option( 'aaw_test_mocks' ) ) {
	add_action(
		'plugins_loaded',
		static function () {
			$mocks = WP_PLUGIN_DIR . '/ana-afdal-writer/tests/mocks.php';
			// When testing the built zip (which has no tests folder), use a copy next to this file.
			require_once is_readable( $mocks ) ? $mocks : __DIR__ . '/aaw-mocks.php';
			aaw_test_install_mocks();
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
