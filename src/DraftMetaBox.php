<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the quality checklist under drafts the plugin wrote.
 */
final class DraftMetaBox {

	public static function register(): void {
		add_action( 'add_meta_boxes_post', array( self::class, 'add' ) );
	}

	public static function add( \WP_Post $post ): void {
		if ( ! get_post_meta( $post->ID, '_aaw_job_id', true ) ) {
			return;
		}
		add_meta_box( 'aaw-checklist', __( 'Ana Afdal Writer: review checklist', 'ana-afdal-writer' ), array( self::class, 'render' ), 'post', 'normal', 'high' );
	}

	public static function render( \WP_Post $post ): void {
		$items  = Quality::get_for_post( $post->ID );
		$job_id = (int) get_post_meta( $post->ID, '_aaw_job_id', true );
		$order  = array( 'warn' => 0, 'info' => 1, 'pass' => 2 );
		usort( $items, static fn( $a, $b ) => ( $order[ $a['status'] ] ?? 3 ) <=> ( $order[ $b['status'] ] ?? 3 ) );

		if ( ! $items ) {
			echo '<p>' . esc_html__( 'The checklist appears here once the quality check has run.', 'ana-afdal-writer' ) . '</p>';
		} else {
			echo '<ul class="aaw-checklist">';
			foreach ( $items as $item ) {
				$icon = 'pass' === $item['status'] ? '✓' : ( 'info' === $item['status'] ? 'ℹ' : '!' );
				printf(
					'<li class="aaw-check aaw-check-%1$s"><span class="aaw-check-icon" aria-hidden="true">%2$s</span> <strong>%3$s</strong>%4$s</li>',
					esc_attr( $item['status'] ),
					esc_html( $icon ),
					esc_html( $item['label'] ),
					'' !== (string) $item['note'] ? ' — <span dir="auto">' . esc_html( $item['note'] ) . '</span>' : ''
				);
			}
			echo '</ul>';
		}
		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=' . Admin::PAGE_APP . '#job=' . $job_id ) ),
			esc_html__( 'Open this post in Ana Afdal Writer (sources, backlink suggestions, cost)', 'ana-afdal-writer' )
		);
		?>
		<style>
			.aaw-checklist{margin:0}
			.aaw-check{padding:6px 0;border-bottom:1px solid #f0f0f1}
			.aaw-check-icon{display:inline-block;width:20px;height:20px;line-height:20px;text-align:center;border-radius:50%;font-weight:700;color:#fff;background:#00a32a}
			.aaw-check-warn .aaw-check-icon{background:#dba617}
			.aaw-check-info .aaw-check-icon{background:#2271b1}
		</style>
		<?php
	}
}
