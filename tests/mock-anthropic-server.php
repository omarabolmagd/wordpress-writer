<?php
/**
 * A stand-in for the Claude Messages API that streams canned replies slowly, the way the real
 * API does, so the plugin's real curl streaming and the live progress screen can be tested.
 *
 * NO_PROXY=localhost AAW_WP_DIR=/path/to/wordpress php -S localhost:8081 tests/mock-anthropic-server.php
 */

$wp_dir = getenv( 'AAW_WP_DIR' );
if ( ! $wp_dir || ! is_readable( $wp_dir . '/wp-load.php' ) ) {
	http_response_code( 500 );
	echo 'Set AAW_WP_DIR to the WordPress folder.';
	return;
}
define( 'WP_USE_THEMES', false );
require $wp_dir . '/wp-load.php';
if ( ! function_exists( 'aaw_test_claude_reply' ) ) {
	require_once __DIR__ . '/mocks.php';
}

$path = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
$body = json_decode( (string) file_get_contents( 'php://input' ), true );
if ( '/v1/messages' !== $path || ! is_array( $body ) ) {
	http_response_code( 404 );
	header( 'Content-Type: application/json' );
	echo '{"type":"error","error":{"type":"not_found_error","message":"Not found"}}';
	return;
}

try {
	$reply = aaw_test_claude_reply( $body );
} catch ( \AnaAfdal\Writer\ApiError $e ) {
	http_response_code( $e->status ? $e->status : 500 );
	header( 'Content-Type: application/json' );
	echo wp_json_encode( array( 'type' => 'error', 'error' => array( 'type' => 'invalid_request_error', 'message' => $e->getMessage() ) ) );
	return;
}

if ( empty( $body['stream'] ) ) {
	header( 'Content-Type: application/json' );
	echo wp_json_encode( $reply );
	return;
}

header( 'Content-Type: text/event-stream' );
header( 'Cache-Control: no-cache' );
while ( ob_get_level() > 0 ) {
	ob_end_flush();
}
$delay_ms = (int) ( getenv( 'AAW_MOCK_DELAY_MS' ) ?: 35 );
foreach ( aaw_test_sse_events( $reply ) as $event ) {
	echo $event; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	flush();
	if ( str_contains( $event, 'content_block_stop' ) ) {
		usleep( 300000 );
	}
	if ( str_contains( $event, '"type":"server_tool_use"' ) ) {
		usleep( 1000 * (int) ( getenv( 'AAW_MOCK_SEARCH_MS' ) ?: 1800 ) ); // A search takes a moment.
	}
	usleep( $delay_ms * 1000 );
}
