<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every part of the plugin into WordPress.
 */
final class Plugin {

	/** Capability needed to run the writer (Admins and Editors). */
	public const CAP_USE = 'edit_others_posts';

	/** Capability needed to change settings and API keys (Admins). */
	public const CAP_MANAGE = 'manage_options';

	public static function boot(): void {
		load_plugin_textdomain( 'ana-afdal-writer', false, dirname( plugin_basename( AAW_FILE ) ) . '/languages' );

		Installer::maybe_upgrade();
		Jobs::register();
		Worker::register();
		Settings::register();
		Admin::register();
		Rest::register();
		SlugGuard::register();
		Backlinks::register();
		DraftMetaBox::register();
		Frontend::register();
	}

	public static function can_use( ?int $user_id = null ): bool {
		return $user_id ? user_can( $user_id, self::CAP_USE ) : current_user_can( self::CAP_USE );
	}
}
