<?php
/**
 * Plugin Name:       Ana Afdal Writer
 * Plugin URI:        https://anaafdal.com/
 * Description:       Researches, writes, illustrates and links new Arabic posts with Claude, then saves them as drafts for review.
 * Version:           1.2.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Omar Abouelmagd
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ana-afdal-writer
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'AAW_VERSION', '1.2.0' );
define( 'AAW_FILE', __FILE__ );
define( 'AAW_DIR', plugin_dir_path( __FILE__ ) );
define( 'AAW_URL', plugin_dir_url( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Ana Afdal Writer needs PHP 8.1 or newer. Ask your host to upgrade PHP.', 'ana-afdal-writer' ) . '</p></div>';
		}
	);
	return;
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'AnaAfdal\\Writer\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = AAW_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'AnaAfdal\\Writer\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AnaAfdal\\Writer\\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'AnaAfdal\\Writer\\Plugin', 'boot' ) );
