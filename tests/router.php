<?php
/**
 * Router for PHP's built-in server so a local WordPress can be tested in a browser:
 * PHP_CLI_SERVER_WORKERS=4 php -S localhost:8080 -t /path/to/wordpress tests/router.php
 */
$root = $_SERVER['DOCUMENT_ROOT'];
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$file = $root . $path;
if ( '/' !== $path && is_file( $file ) ) {
	return false;
}
if ( is_dir( $file ) && is_file( rtrim( $file, '/' ) . '/index.php' ) ) {
	chdir( rtrim( $file, '/' ) );
	require rtrim( $file, '/' ) . '/index.php';
	return;
}
chdir( $root );
require $root . '/index.php';
