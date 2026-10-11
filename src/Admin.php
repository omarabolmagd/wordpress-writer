<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Admin menu, screens and assets.
 */
final class Admin {

	public const PAGE_APP   = 'aaw-writer';
	public const PAGE_COSTS = 'aaw-costs';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AAW_FILE ), array( self::class, 'action_links' ) );
	}

	public static function menu(): void {
		add_menu_page(
			__( 'Ana Afdal Writer', 'ana-afdal-writer' ),
			__( 'Ana Afdal Writer', 'ana-afdal-writer' ),
			Plugin::CAP_USE,
			self::PAGE_APP,
			array( self::class, 'render_app' ),
			'dashicons-edit-large',
			6
		);
		add_submenu_page( self::PAGE_APP, __( 'New post', 'ana-afdal-writer' ), __( 'New post', 'ana-afdal-writer' ), Plugin::CAP_USE, self::PAGE_APP, array( self::class, 'render_app' ) );
		add_submenu_page( self::PAGE_APP, __( 'Costs', 'ana-afdal-writer' ), __( 'Costs', 'ana-afdal-writer' ), Plugin::CAP_USE, self::PAGE_COSTS, array( self::class, 'render_costs' ) );
		add_submenu_page( self::PAGE_APP, __( 'Settings', 'ana-afdal-writer' ), __( 'Settings', 'ana-afdal-writer' ), Plugin::CAP_MANAGE, Settings::PAGE, array( Settings::class, 'render_page' ) );
	}

	public static function action_links( array $links ): array {
		if ( current_user_can( Plugin::CAP_MANAGE ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . Settings::PAGE ) ) . '">' . esc_html__( 'Settings', 'ana-afdal-writer' ) . '</a>' );
		}
		return $links;
	}

	private static function is_our_screen( string $hook ): bool {
		return str_contains( $hook, self::PAGE_APP ) || str_contains( $hook, self::PAGE_COSTS ) || str_contains( $hook, Settings::PAGE );
	}

	public static function assets( string $hook ): void {
		if ( ! self::is_our_screen( $hook ) ) {
			return;
		}
		wp_enqueue_style( 'aaw-admin', AAW_URL . 'admin/css/admin.css', array(), AAW_VERSION );
		wp_enqueue_script( 'aaw-admin', AAW_URL . 'admin/js/app.js', array( 'wp-api-fetch', 'wp-i18n' ), AAW_VERSION, true );
		wp_set_script_translations( 'aaw-admin', 'ana-afdal-writer', AAW_DIR . 'languages' );
		wp_add_inline_script(
			'aaw-admin',
			'window.aawConfig = ' . wp_json_encode(
				array(
					'ns'       => Rest::NS,
					'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
					'runNonce' => wp_create_nonce( Worker::AJAX_RUN ),
					'appUrl'   => admin_url( 'admin.php?page=' . self::PAGE_APP ),
					'locale'   => get_user_locale(),
					'isRtl'    => is_rtl(),
				)
			) . ';',
			'before'
		);
	}

	public static function notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! current_user_can( Plugin::CAP_MANAGE ) || Settings::has_key( 'anthropic_key' ) ) {
			return;
		}
		if ( 'plugins' === $screen->id || str_contains( (string) $screen->id, self::PAGE_APP ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Ana Afdal Writer needs a Claude API key before it can write.', 'ana-afdal-writer' ),
				esc_url( admin_url( 'admin.php?page=' . Settings::PAGE ) ),
				esc_html__( 'Add it in Settings', 'ana-afdal-writer' )
			);
		}
	}

	public static function render_app(): void {
		if ( ! Plugin::can_use() ) {
			wp_die( esc_html__( 'You do not have permission to use the writer.', 'ana-afdal-writer' ) );
		}
		?>
		<div class="wrap aaw-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Ana Afdal Writer', 'ana-afdal-writer' ); ?></h1>
			<div id="aaw-app" class="aaw-app" aria-live="polite">
				<p class="aaw-loading"><span class="spinner is-active"></span> <?php esc_html_e( 'Loading…', 'ana-afdal-writer' ); ?></p>
			</div>
			<noscript><p><?php esc_html_e( 'This screen needs JavaScript.', 'ana-afdal-writer' ); ?></p></noscript>
		</div>
		<?php
	}

	public static function render_costs(): void {
		if ( ! Plugin::can_use() ) {
			wp_die( esc_html__( 'You do not have permission to view costs.', 'ana-afdal-writer' ) );
		}
		$total  = Costs::month_total();
		$cap    = Costs::cap();
		$by_job = Costs::month_by_job();
		$recent = Costs::recent( 40 );
		?>
		<div class="wrap aaw-wrap">
			<h1><?php esc_html_e( 'Costs', 'ana-afdal-writer' ); ?></h1>
			<div class="aaw-card aaw-budget">
				<p class="aaw-budget-figure">
					<strong><?php echo esc_html( Costs::format( $total ) ); ?></strong>
					<?php
					if ( $cap > 0 ) {
						/* translators: %s: monthly cap in dollars */
						printf( esc_html__( 'of %s this month', 'ana-afdal-writer' ), esc_html( Costs::format( $cap ) ) );
					} else {
						esc_html_e( 'this month (no cap set)', 'ana-afdal-writer' );
					}
					?>
				</p>
				<?php if ( $cap > 0 ) : ?>
					<div class="aaw-meter"><span style="width:<?php echo esc_attr( (string) min( 100, round( $total / $cap * 100 ) ) ); ?>%"></span></div>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Costs are estimates from token counts and the prices in Settings. Your Anthropic and Google consoles show the exact bill.', 'ana-afdal-writer' ); ?></p>
			</div>

			<h2><?php esc_html_e( 'This month by job', 'ana-afdal-writer' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Job', 'ana-afdal-writer' ); ?></th><th><?php esc_html_e( 'Cost', 'ana-afdal-writer' ); ?></th><th><?php esc_html_e( 'Last activity', 'ana-afdal-writer' ); ?></th></tr></thead>
				<tbody>
				<?php if ( ! $by_job ) : ?>
					<tr><td colspan="3"><?php esc_html_e( 'Nothing spent yet this month.', 'ana-afdal-writer' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $by_job as $row ) : ?>
					<?php
					$job   = Jobs::get( (int) $row['job_id'] );
					$title = $job ? ( $job['title'] ?? get_the_title( (int) $row['job_id'] ) ) : '#' . (int) $row['job_id'];
					$url   = $job && Jobs::TYPE_ARTICLE === $job['type'] ? admin_url( 'admin.php?page=' . self::PAGE_APP . '#job=' . (int) $row['job_id'] ) : '';
					?>
					<tr>
						<td><?php echo $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ); ?></td>
						<td><?php echo esc_html( Costs::format( (float) $row['total'] ) ); ?></td>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $row['last_gmt'] . ' UTC' ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Latest API calls', 'ana-afdal-writer' ); ?></h2>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'When', 'ana-afdal-writer' ); ?></th><th><?php esc_html_e( 'Service', 'ana-afdal-writer' ); ?></th><th><?php esc_html_e( 'Step', 'ana-afdal-writer' ); ?></th><th><?php esc_html_e( 'Cost', 'ana-afdal-writer' ); ?></th><th><?php esc_html_e( 'Details', 'ana-afdal-writer' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $recent as $row ) : ?>
					<?php $d = json_decode( (string) $row['details'], true ); ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'Y-m-d H:i', strtotime( $row['created_gmt'] . ' UTC' ) ) ); ?></td>
						<td><?php echo esc_html( $row['provider'] ); ?></td>
						<td><?php echo esc_html( $row['item'] ); ?></td>
						<td><?php echo esc_html( Costs::format( (float) $row['amount_usd'] ) ); ?></td>
						<td><code><?php echo esc_html( is_array( $d ) ? implode( ', ', array_map( static fn( $k, $v ) => $k . '=' . ( is_scalar( $v ) ? $v : '…' ), array_keys( $d ), $d ) ) : '' ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
