<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Admin menu, screens and assets.
 */
final class Admin {

	public const PAGE_APP      = 'aaw-writer';
	public const PAGE_COSTS    = 'aaw-costs';
	public const PAGE_RESEARCH = 'aaw-research';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AAW_FILE ), array( self::class, 'action_links' ) );
		add_action( 'admin_post_aaw_delete_research', array( self::class, 'delete_research' ) );
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
		add_submenu_page( self::PAGE_APP, __( 'Saved research', 'ana-afdal-writer' ), __( 'Saved research', 'ana-afdal-writer' ), Plugin::CAP_USE, self::PAGE_RESEARCH, array( self::class, 'render_research' ) );
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
		return str_contains( $hook, self::PAGE_APP ) || str_contains( $hook, self::PAGE_COSTS ) || str_contains( $hook, self::PAGE_RESEARCH ) || str_contains( $hook, Settings::PAGE );
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

	public static function render_research(): void {
		if ( ! Plugin::can_use() ) {
			wp_die( esc_html__( 'You do not have permission to use the writer.', 'ana-afdal-writer' ) );
		}
		$entries = Library::all( 200 );
		$avg     = Library::average_costs();
		$by_id   = array();
		foreach ( $entries as $e ) {
			$by_id[ $e['id'] ] = $e;
		}
		$deleted = isset( $_GET['deleted'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap aaw-wrap">
			<h1><?php esc_html_e( 'Saved research', 'ana-afdal-writer' ); ?></h1>
			<?php if ( $deleted ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Deleted.', 'ana-afdal-writer' ); ?></p></div>
			<?php endif; ?>
			<div class="aaw-card">
				<p><?php esc_html_e( 'Claude\'s notes and sources from every research step are kept here. When you write about a related topic, Claude starts from these notes and searches only for what is missing or may have changed, which uses fewer searches and tokens.', 'ana-afdal-writer' ); ?></p>
				<p class="description"><?php esc_html_e( 'Claude\'s raw search results come back encrypted and can only be read inside the request that made them, so the notes Claude wrote from them are what is saved.', 'ana-afdal-writer' ); ?></p>
				<?php if ( ! Library::enabled() ) : ?>
					<p><strong><?php esc_html_e( 'Reusing saved research is turned off in Settings.', 'ana-afdal-writer' ); ?></strong></p>
				<?php endif; ?>
				<?php if ( null !== $avg['fresh'] || null !== $avg['reuse'] ) : ?>
					<p class="aaw-research-avg">
						<?php
						echo esc_html__( 'Average research cost per article:', 'ana-afdal-writer' ) . ' ';
						echo esc_html(
							null !== $avg['fresh']
								/* translators: 1: cost, 2: number of articles */
								? sprintf( __( '%1$s from scratch (articles: %2$d)', 'ana-afdal-writer' ), Costs::format( $avg['fresh'] ), $avg['fresh_count'] )
								: __( 'no article researched from scratch yet', 'ana-afdal-writer' )
						);
						echo ' · ';
						echo esc_html(
							null !== $avg['reuse']
								/* translators: 1: cost, 2: number of articles */
								? sprintf( __( '%1$s with saved notes (articles: %2$d)', 'ana-afdal-writer' ), Costs::format( $avg['reuse'] ), $avg['reuse_count'] )
								: __( 'no article has used saved notes yet', 'ana-afdal-writer' )
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<table class="widefat striped aaw-research-table">
				<thead><tr>
					<th><?php esc_html_e( 'Date', 'ana-afdal-writer' ); ?></th>
					<th><?php esc_html_e( 'Research', 'ana-afdal-writer' ); ?></th>
					<th><?php esc_html_e( 'Searches', 'ana-afdal-writer' ); ?></th>
					<th><?php esc_html_e( 'Cost', 'ana-afdal-writer' ); ?></th>
					<th><?php esc_html_e( 'Times reused', 'ana-afdal-writer' ); ?></th>
					<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'ana-afdal-writer' ); ?></span></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $entries ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Nothing saved yet. The research of your next article or topic search will appear here.', 'ana-afdal-writer' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $entries as $e ) : ?>
					<?php
					$is_ideas = Library::KIND_IDEAS === $e['kind'];
					$label    = $is_ideas
						? ( '' !== $e['title'] ? $e['title'] : __( 'Topic search without a focus', 'ana-afdal-writer' ) )
						: $e['title'];
					$job_url  = ! $is_ideas && $e['job_id'] && Jobs::get( $e['job_id'] ) ? admin_url( 'admin.php?page=' . self::PAGE_APP . '#job=' . $e['job_id'] ) : '';
					$built_on = array_filter( array_map( static fn( $id ) => $by_id[ (int) $id ]['title'] ?? '', (array) ( $e['meta']['reused_from'] ?? array() ) ) );
					?>
					<tr>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ), $e['created'] ) ); ?></td>
						<td>
							<span class="aaw-pill"><?php echo esc_html( $is_ideas ? __( 'Topic search', 'ana-afdal-writer' ) : __( 'Article research', 'ana-afdal-writer' ) ); ?></span>
							<?php if ( $job_url ) : ?>
								<a href="<?php echo esc_url( $job_url ); ?>" dir="auto"><strong><?php echo esc_html( $label ); ?></strong></a>
							<?php else : ?>
								<strong dir="auto"><?php echo esc_html( $label ); ?></strong>
							<?php endif; ?>
							<?php if ( ! empty( $e['meta']['reused_from'] ) ) : ?>
								<br><span class="description">
									<?php
									/* translators: %s: titles of earlier articles */
									echo esc_html( $built_on ? sprintf( __( 'Started from: %s', 'ana-afdal-writer' ), implode( '، ', $built_on ) ) : __( 'Started from saved notes', 'ana-afdal-writer' ) );
									?>
								</span>
							<?php endif; ?>
							<details class="aaw-research-notes">
								<summary>
									<?php
									/* translators: %d: number of sources */
									echo esc_html( sprintf( __( 'Notes and sources (%d)', 'ana-afdal-writer' ), count( $e['sources'] ) ) );
									?>
								</summary>
								<div class="aaw-notes-text" dir="auto"><?php echo esc_html( $e['notes'] ); ?></div>
								<?php if ( $e['sources'] ) : ?>
									<ul class="aaw-sources">
										<?php foreach ( $e['sources'] as $src ) : ?>
											<li dir="auto"><a href="<?php echo esc_url( $src['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( '' !== (string) ( $src['title'] ?? '' ) ? $src['title'] : $src['url'] ); ?></a></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</details>
						</td>
						<td><?php echo esc_html( number_format_i18n( $e['searches'] ) ); ?></td>
						<td><?php echo esc_html( $e['cost'] > 0 ? Costs::format( $e['cost'] ) : '—' ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $e['reused_count'] ) ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="aaw_delete_research">
								<input type="hidden" name="id" value="<?php echo esc_attr( (string) $e['id'] ); ?>">
								<?php wp_nonce_field( 'aaw_delete_research_' . $e['id'] ); ?>
								<button type="submit" class="button-link aaw-danger"><?php esc_html_e( 'Delete', 'ana-afdal-writer' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function delete_research(): void {
		$id = absint( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked below.
		if ( ! Plugin::can_use() ) {
			wp_die( esc_html__( 'You do not have permission to use the writer.', 'ana-afdal-writer' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'aaw_delete_research_' . $id );
		Library::delete( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_RESEARCH . '&deleted=1' ) );
		exit;
	}
}
