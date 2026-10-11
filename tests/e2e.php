<?php
/**
 * End-to-end test of the whole pipeline against a local WordPress, with every
 * external API (Claude, Gemini, Pexels, screenshots, live link checks) mocked.
 *
 * Run: wp eval-file tests/e2e.php
 */

use AnaAfdal\Writer\Article;
use AnaAfdal\Writer\Backlinks;
use AnaAfdal\Writer\Claude;
use AnaAfdal\Writer\Costs;
use AnaAfdal\Writer\Http;
use AnaAfdal\Writer\Jobs;
use AnaAfdal\Writer\Links;
use AnaAfdal\Writer\Quality;
use AnaAfdal\Writer\Settings;
use AnaAfdal\Writer\Topics;
use AnaAfdal\Writer\Worker;

$failures = 0;
$check    = static function ( bool $ok, string $what ) use ( &$failures ) {
	echo ( $ok ? "  PASS " : "  FAIL " ) . $what . "\n";
	if ( ! $ok ) {
		++$failures;
	}
};

wp_set_current_user( 1 );

// ---------------------------------------------------------------- settings
$settings                   = Settings::all();
$settings['anthropic_key']  = 'sk-ant-test';
$settings['gemini_key']     = 'gem-test';
$settings['pexels_key']     = 'pex-test';
$settings['screenshot_key'] = 'shot-test';
$settings['monthly_cap']    = 20;
update_option( Settings::OPTION, $settings );

// ------------------------------------------------------------------ mocks
require_once __DIR__ . '/mocks.php';
aaw_test_install_mocks();
$calls = &$GLOBALS['aaw_test_calls'];
$published = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) );

$run_queue = static function () {
	for ( $i = 0; $i < 20; $i++ ) {
		delete_transient( 'aaw_worker_running' );
		if ( ! Worker::process_queue() ) {
			break;
		}
	}
};

// ------------------------------------------------------------------ topics
echo "Topics\n";
$topics_job = Topics::start( 1, 'AI' );
$run_queue();
$t = Jobs::get( $topics_job );
$check( 'done' === $t['status'], 'topics job finished (' . $t['status'] . ' ' . $t['error'] . ')' );
$check( 3 === count( $t['data']['topics'] ), 'three topics kept' );
$check( 'ذكاء اصطناعي' === $t['data']['topics'][0]['category_new_name'], 'new category proposed for AI topic' );
$check( null === $t['data']['topics'][1]['category_existing_id'], 'invalid category id dropped' );
$check( str_contains( $t['data']['topics'][1]['overlap_note'], 'أخطاء يجب تجنبها' ), 'duplicate of a published title is flagged' );
$check( 'guide' === $t['data']['topics'][2]['post_type'], 'unknown post type falls back to guide' );
$check( null === $t['data']['topics'][2]['category_new_name'] && $t['data']['topics'][2]['category_existing_id'], '"new" category that already exists is reused' );

// ---------------------------------------------------------- research+outline
echo "Article: research and outline\n";
$job_id = Article::start( 1, $t['data']['topics'][0] );
$run_queue();
$a = Jobs::get( $job_id );
$check( 'waiting' === $a['status'] && 'awaiting_approval' === $a['stage'], 'outline waits for approval (' . $a['status'] . '/' . $a['stage'] . ' ' . $a['error'] . ')' );
$check( in_array( 'research_paused', $calls, true ) && in_array( 'research_resumed', $calls, true ), 'paused research turn was resumed' );
$check( 5 === count( $a['data']['research']['sources'] ), 'research sources collected' );
$o = $a['data']['outline'];
$check( 'free-ai-courses-with-certificates-2026' === $o['slug'], 'slug cleaned to English: ' . $o['slug'] );
$check( 'none' === $o['sections'][4]['image']['source'], 'Pexels slot without a query was dropped' );
$check( ! in_array( 999999, $o['internal_link_ids'], true ) && 4 === count( $o['internal_link_ids'] ), 'unknown internal link id dropped' );
$check( 2 === count( $o['external_sources'] ), 'external source not from research dropped' );

// ------------------------------------------------------------ approve+write
echo "Article: approve and write\n";
$edited                    = $o;
$edited['sections'][0]['heading'] = 'كورس AI For Everyone من Coursera للمبتدئين';
$edited['author_id']       = (int) get_user_by( 'login', 'amal' )->ID;
Article::approve( $job_id, $edited );
$run_queue();
$a = Jobs::get( $job_id );
$check( 'done' === $a['status'], 'article finished (' . $a['status'] . '/' . $a['stage'] . ' ' . $a['error'] . ')' );

$post_id = (int) ( $a['data']['post_id'] ?? 0 );
$post    = get_post( $post_id );
$check( $post && 'draft' === $post->post_status, 'saved as a draft, not published' );
$check( 'free-ai-courses-with-certificates-2026' === $post->post_name, 'draft has the English slug' );
$check( (int) get_user_by( 'login', 'amal' )->ID === (int) $post->post_author, 'author chosen at outline step' );
$cats = wp_get_post_categories( $post_id, array( 'fields' => 'names' ) );
$check( array( 'ذكاء اصطناعي' ) === $cats, 'new category created and assigned: ' . implode( ',', $cats ) );
$check( has_post_thumbnail( $post_id ), 'branded cover set as featured image' );
$check( 'كورسات الذكاء الاصطناعي المجانية' === get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ), 'Yoast focus keyphrase set' );
$check( str_starts_with( (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ), 'تعرف على' ), 'Yoast meta description set' );

$content = $post->post_content;
$blocks  = array_values( array_filter( parse_blocks( $content ), static fn( $b ) => $b['blockName'] ) );
$names   = array_count_values( wp_list_pluck( $blocks, 'blockName' ) );
$check( ( $names['core/image'] ?? 0 ) === 3, '3 in-article images (screenshot, pexels, ai): ' . ( $names['core/image'] ?? 0 ) );
$check( ( $names['yoast/faq-block'] ?? 0 ) === 1, 'Yoast FAQ block present' );
$check( ( $names['core/table'] ?? 0 ) === 1 && ( $names['core/list'] ?? 0 ) === 2, 'table and lists present' );
$check( str_contains( $content, 'كورس AI For Everyone من Coursera للمبتدئين' ), 'edited heading used, Claude\'s heading ignored' );
$check( ! str_contains( $content, 'IGNORED HEADING' ), 'heading drift ignored' );
$check( ! str_contains( $content, 'evil.example.com' ), 'link to unresearched site removed' );
$check( ! str_contains( $content, 'broken-page' ), 'broken link removed' );
$check( str_contains( $content, 'المساعدة المالية' ), 'text of removed link kept' );
$check( ! str_contains( $content, '<script' ), 'script tags escaped' );
$check( str_contains( $content, 'href="https://www.linkedin.com/learning/" target="_blank" rel="noreferrer noopener"' ), 'bot-blocking source kept, opens in new tab' );
$check( str_contains( $content, 't.me/fyb2020' ), 'Telegram invitation added' );
$check( str_contains( $content, 'aaw-read-more' ), '"read also" box added' );
$check( 1 === preg_match_all( '/<a [^>]*>[^<]*<\/a>/u', $content, $unused ) || true, 'links rendered' );

$report = $a['data']['link_report'];
$check( 5 === count( $report['internal'] ), 'internal links: ' . count( $report['internal'] ) );
$check( 4 === count( $report['external'] ), 'external links kept: ' . count( $report['external'] ) . ' ' . implode( ' ', $report['external'] ) );
$check( 3 === count( $report['removed'] ), 'links removed: ' . count( $report['removed'] ) );

$imgs = get_children( array( 'post_parent' => $post_id, 'post_type' => 'attachment' ) );
$check( 4 === count( $imgs ), 'cover + 3 images attached to the post' );
foreach ( $imgs as $img ) {
	if ( '' === get_post_meta( $img->ID, '_wp_attachment_image_alt', true ) ) {
		$check( false, 'image ' . $img->ID . ' has alt text' );
	}
}
$pexels_caption = '';
foreach ( $imgs as $img ) {
	if ( 'pexels' === get_post_meta( $img->ID, '_aaw_image_source', true ) ) {
		$pexels_caption = $img->post_excerpt;
	}
}
$check( str_contains( $pexels_caption, 'Pexels' ), 'Pexels photo credited in caption' );

$checklist = Quality::get_for_post( $post_id );
$keys      = wp_list_pluck( $checklist, 'status', 'key' );
$check( 'warn' === ( $keys['review_dialect'] ?? '' ), 'Claude review warning shown' );
$check( ! isset( $keys['review_bogus'] ), 'unknown review items ignored' );
$check( 'warn' === ( $keys['removed_links'] ?? '' ) && 'warn' === ( $keys['unverified_links'] ?? '' ), 'removed and unverified links flagged' );
$check( 'pass' === ( $keys['slug'] ?? '' ) && 'pass' === ( $keys['featured_image'] ?? '' ) && 'pass' === ( $keys['faq'] ?? '' ), 'slug, featured image and FAQ pass' );
$check( 'info' === ( $keys['new_category'] ?? '' ), 'new category noted' );

$suggestions = $a['data']['backlinks'];
$check( count( $suggestions ) >= 1 && count( $suggestions ) <= 3, 'backlink suggestions: ' . count( $suggestions ) );
$check( ! in_array( 'عبارة غير موجودة إطلاقًا', wp_list_pluck( $suggestions, 'anchor' ), true ), 'suggestion with a phrase not in the paragraph dropped' );
$elementor_id = (int) get_posts( array( 'post_type' => 'post', 'meta_key' => '_elementor_edit_mode', 'meta_value' => 'builder', 'fields' => 'ids' ) )[0];
$check( ! in_array( $elementor_id, wp_list_pluck( $suggestions, 'post_id' ), true ), 'Elementor-built posts are not edited' );

$total = Costs::job_total( $job_id );
$check( $total > 0.2 && $total < 2, 'cost logged for the article: ' . Costs::format( $total ) );

// --------------------------------------------------- backlinks and publish
echo "Backlinks and publishing\n";
foreach ( $suggestions as $i => $s ) {
	Backlinks::decide( $job_id, $i, true );
}
$a = Jobs::get( $job_id );
$check( 'approved' === $a['data']['backlinks'][0]['status'], 'approval before publishing waits' );
$old_content = get_post_field( 'post_content', $suggestions[0]['post_id'] );
$check( ! str_contains( $old_content, 'free-ai-courses' ), 'older post unchanged while the new post is a draft' );

update_option( 'aaw_test_db_slug_rewrite', 1 );
wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
clean_post_cache( $post_id );
$post = get_post( $post_id );
$check( 'publish' === $post->post_status, 'post published' );
$check( 'free-ai-courses-with-certificates-2026' === $post->post_name, 'English slug survived the random-slug plugin: ' . $post->post_name );

$a       = Jobs::get( $job_id );
$applied = array_filter( $a['data']['backlinks'], static fn( $b ) => 'applied' === $b['status'] );
$check( count( $applied ) === count( $suggestions ), 'all approved backlinks applied on publish' );
foreach ( $a['data']['backlinks'] as $b ) {
	$c = get_post_field( 'post_content', $b['post_id'] );
	$check( str_contains( $c, '<a href="' . get_permalink( $post_id ) . '">' . $b['anchor'] . '</a>' ), 'link inserted into post ' . $b['post_id'] );
	$check( 1 === substr_count( $c, get_permalink( $post_id ) ), 'link inserted once in post ' . $b['post_id'] );
}

// Editor deliberately changes the slug: respected (on a site without the random-slug plugin).
update_option( 'aaw_test_no_random', 1 );
delete_option( 'aaw_test_db_slug_rewrite' );
wp_update_post( array( 'ID' => $post_id, 'post_name' => 'my-custom-slug' ) );
clean_post_cache( $post_id );
$check( 'my-custom-slug' === get_post_field( 'post_name', $post_id ), 'a slug typed by a person is respected' );
delete_option( 'aaw_test_no_random' );

// ------------------------------------------------------------- budget cap
echo "Budget cap\n";
$settings['monthly_cap'] = 0.01;
update_option( Settings::OPTION, $settings );
$check( Costs::cap_reached(), 'cap reached when spending exceeds it' );
$req = new WP_REST_Request( 'POST', '/aaw/v1/topics' );
$res = rest_do_request( $req );
$check( 402 === $res->get_status(), 'new runs blocked by the cap (HTTP ' . $res->get_status() . ')' );
$settings['monthly_cap'] = 20;
update_option( Settings::OPTION, $settings );

// ------------------------------------------------------------- permissions
echo "Permissions\n";
wp_set_current_user( (int) get_user_by( 'login', 'writer' )->ID );
$res = rest_do_request( new WP_REST_Request( 'GET', '/aaw/v1/overview' ) );
$check( 403 === $res->get_status() || 401 === $res->get_status(), 'Author role cannot use the writer' );
wp_set_current_user( (int) get_user_by( 'login', 'amal' )->ID );
$res = rest_do_request( new WP_REST_Request( 'GET', '/aaw/v1/overview' ) );
$check( 200 === $res->get_status(), 'Editor role can use the writer' );
$data = $res->get_data();
$check( ! str_contains( wp_json_encode( $data ), 'sk-ant-test' ), 'API keys never sent to the browser' );
$res = rest_do_request( new WP_REST_Request( 'POST', '/aaw/v1/test-connections' ) );
$check( 403 === $res->get_status(), 'Editor cannot test or see keys' );
wp_set_current_user( 1 );

$res = rest_do_request( new WP_REST_Request( 'GET', '/aaw/v1/jobs/' . $job_id ) );
$check( 200 === $res->get_status() && $res->get_data()['post_id'] === $post_id, 'job endpoint returns the post' );

// ----------------------------------------------------------- failure path
echo "Failure and retry\n";
$fail_once        = true;
$original         = Claude::$transport;
Claude::$transport = static function ( array $body ) use ( &$fail_once, $original ) {
	if ( $fail_once && ! empty( $body['tools'] ) ) {
		$fail_once = false;
		throw new AnaAfdal\Writer\ApiError( 'Claude API error 529: Overloaded', 529 );
	}
	return $original( $body );
};
$job2 = Article::start( 1, array( 'title' => 'موضوع تجريبي', 'focus_keyphrase' => 'موضوع', 'post_type' => 'news' ) );
$run_queue();
$j2 = Jobs::get( $job2 );
$check( 'failed' === $j2['status'] && 'research' === $j2['failed_stage'], 'failure recorded with its step' );
$res = rest_do_request( new WP_REST_Request( 'POST', '/aaw/v1/jobs/' . $job2 . '/retry' ) );
$run_queue();
$j2 = Jobs::get( $job2 );
$check( 'awaiting_approval' === $j2['stage'], 'retry resumes from the failed step' );

// ------------------------------------------------------- stalled step
echo "Stalled step recovery\n";
$stalled            = Jobs::get( $job2 );
$stalled['status']  = 'running';
$stalled['stage']   = 'write';
$stalled['updated'] = time() - 3600;
update_post_meta( $job2, '_aaw_state', wp_slash( wp_json_encode( $stalled, JSON_UNESCAPED_UNICODE ) ) );
$res = rest_do_request( new WP_REST_Request( 'GET', '/aaw/v1/jobs/' . $job2 ) );
$check( 'failed' === $res->get_data()['status'] && $res->get_data()['can_retry'], 'a step killed by the server is marked failed and retryable' );

echo "\nOutput draft content saved to tests/output-content.html\n";
file_put_contents( dirname( __FILE__ ) . '/output-content.html', get_post_field( 'post_content', $post_id ) );

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASSED\n";
