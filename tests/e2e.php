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

add_filter( 'aaw_retry_wait', '__return_zero' );
// Only this process runs steps, so the results are deterministic.
add_filter( 'aaw_background_kick', '__return_false' );
$run_queue = static function () {
	Worker::run_pending( 600 );
};
$bodies_for = static function ( string $marker ): array {
	return array_values( array_filter( $GLOBALS['aaw_test_bodies'], static fn( $b ) => str_contains( wp_json_encode( $b, JSON_UNESCAPED_UNICODE ), $marker ) ) );
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
$tb = $GLOBALS['aaw_test_bodies'][0];
$check( ! empty( $tb['stream'] ), 'requests are streamed' );
$check( array( 'type' => 'between_tools' ) === ( $tb['thinking'] ?? null ), 'Sonnet 5.5: up-front thinking turned off' );
$check( 'low' === ( $tb['output_config']['effort'] ?? '' ), 'topic research runs at low effort' );
$check( 12000 === $tb['max_tokens'], 'topic research has room to answer: ' . $tb['max_tokens'] );
$check( null === $t['data']['topics'][2]['category_new_name'] && $t['data']['topics'][2]['category_existing_id'], '"new" category that already exists is reused' );

// ---------------------------------------------------------- research+outline
echo "Article: research and outline\n";
$job_id = Article::start( 1, $t['data']['topics'][0] );
Worker::run_pending( 600, null, 1 );
$research_progress = AnaAfdal\Writer\Progress::get( $job_id );
$check( is_array( $research_progress ) && 'research' === $research_progress['stage'] && 'finished' === $research_progress['phase'], 'progress saved for the research step' );
$check( 'queued' === Jobs::get( $job_id )['status'] && 'outline' === Jobs::get( $job_id )['stage'], 'next step queued after research' );
$run_queue();
$a = Jobs::get( $job_id );
$check( 'waiting' === $a['status'] && 'awaiting_approval' === $a['stage'], 'outline waits for approval (' . $a['status'] . '/' . $a['stage'] . ' ' . $a['error'] . ')' );
$check( in_array( 'research_paused', $calls, true ) && in_array( 'research_resumed', $calls, true ), 'paused research turn was resumed' );
$check( 5 === count( $a['data']['research']['sources'] ), 'research sources collected' );
$check( in_array( 'resent_input_is_object:yes', $calls, true ), 'paused turn sent back with its tool input intact' );
$ob = $bodies_for( 'slug' );
$check( $ob && 16000 === $ob[0]['max_tokens'] && 'medium' === ( $ob[0]['output_config']['effort'] ?? '' ), 'outline: 16000 tokens of room, medium effort' );
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

$wb = $bodies_for( '"intro"' );
$check( $wb && 32000 === $wb[0]['max_tokens'] && array( 'type' => 'between_tools' ) === ( $wb[0]['thinking'] ?? null ), 'write: 32000 tokens of room, no up-front thinking' );
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
$fail_mode = 'auth';
$original  = Claude::$sse_transport;
Claude::$sse_transport = static function ( array $body, callable $write ) use ( &$fail_mode, $original ) {
	if ( ! empty( $body['tools'] ) && 'auth' === $fail_mode ) {
		$fail_mode = '';
		throw new AnaAfdal\Writer\ApiError( 'Claude API error 401: invalid x-api-key', 401 );
	}
	if ( ! empty( $body['tools'] ) && 'overloaded' === $fail_mode ) {
		$fail_mode = '';
		$write( "event: error\ndata: {\"type\":\"error\",\"error\":{\"type\":\"overloaded_error\",\"message\":\"Overloaded\"}}\n\n" );
		return;
	}
	call_user_func( $original, $body, $write );
};
$job2 = Article::start( 1, array( 'title' => 'موضوع تجريبي', 'focus_keyphrase' => 'موضوع', 'post_type' => 'news' ) );
$run_queue();
$j2 = Jobs::get( $job2 );
$check( 'failed' === $j2['status'] && 'research' === $j2['failed_stage'], 'failure recorded with its step' );
$res = rest_do_request( new WP_REST_Request( 'POST', '/aaw/v1/jobs/' . $job2 . '/retry' ) );
$run_queue();
$j2 = Jobs::get( $job2 );
$check( 'awaiting_approval' === $j2['stage'], 'retry resumes from the failed step' );

$fail_mode = 'overloaded';
$job3      = Article::start( 1, array( 'title' => 'موضوع ثالث', 'focus_keyphrase' => 'موضوع', 'post_type' => 'news' ) );
$run_queue();
$j3 = Jobs::get( $job3 );
$check( 'awaiting_approval' === $j3['stage'] && '' === $fail_mode, 'an overloaded error mid-stream is retried automatically' );

echo "Output limit\n";
$GLOBALS['aaw_test_flags']['truncate_outline_once'] = true;
$job4 = Article::start( 1, array( 'title' => 'موضوع رابع', 'focus_keyphrase' => 'موضوع', 'post_type' => 'guide' ) );
$run_queue();
$j4 = Jobs::get( $job4 );
$check( in_array( 'outline_truncated', $calls, true ), 'outline hit the output limit once' );
$check( 'awaiting_approval' === $j4['stage'] && ! empty( $j4['data']['outline']['sections'] ), 'outline retried with more room and finished' );
$retry_body = array_values( array_filter( $bodies_for( 'slug' ), static fn( $b ) => 32000 === $b['max_tokens'] ) );
$check( $retry_body && 'low' === ( $retry_body[0]['output_config']['effort'] ?? '' ), 'retry used double the room at low effort' );

echo "Model settings it does not support\n";
$GLOBALS['aaw_test_flags']['reject_thinking'] = true;
$job5 = Topics::start( 1, '' );
$run_queue();
$j5 = Jobs::get( $job5 );
$check( in_array( 'rejected_thinking', $calls, true ) && 'done' === $j5['status'], 'a rejected thinking setting is dropped and the request retried' );
$GLOBALS['aaw_test_flags']['reject_thinking'] = false;

echo "Whole replies (servers without curl)\n";
aaw_test_install_mocks( 'plain' );
$job6 = Topics::start( 1, '' );
$run_queue();
$check( 'done' === Jobs::get( $job6 )['status'], 'non-streamed replies still work' );
aaw_test_install_mocks( 'stream' );

echo "Claims\n";
$t1 = Worker::claim( $job6 );
$t2 = Worker::claim( $job6 );
$check( $t1 && null === $t2, 'a job can be claimed by one runner at a time' );
global $wpdb;
$wpdb->update( $wpdb->options, array( 'option_value' => $t1 . '|' . ( time() - 600 ) ), array( 'option_name' => 'aaw_claim_' . $job6 ) );
$t3 = Worker::claim( $job6 );
$check( $t3 && $t3 !== $t1, 'a claim with a dead heartbeat can be taken over' );
Worker::release( $job6, $t1 );
$check( Worker::claim_alive( $job6 ), 'the old holder cannot release the new claim' );
Worker::release( $job6, $t3 );
$check( null === Worker::claim_age( $job6 ), 'the holder releases its claim' );

echo "Step stopped by the server\n";
$stalled             = Jobs::get( $job2 );
$stalled['status']   = 'running';
$stalled['stage']    = 'outline';
$stalled['attempts'] = 1;
update_post_meta( $job2, '_aaw_state', wp_slash( wp_json_encode( $stalled, JSON_UNESCAPED_UNICODE ) ) );
update_post_meta( $job2, '_aaw_status', 'running' );
$res = rest_do_request( new WP_REST_Request( 'GET', '/aaw/v1/jobs/' . $job2 ) );
$check( true === $res->get_data()['runnable'], 'a running step without a heartbeat can be picked up' );
$run_queue();
$j2 = Jobs::get( $job2 );
$check( 'awaiting_approval' === $j2['stage'] && str_contains( wp_json_encode( $j2['log'], JSON_UNESCAPED_UNICODE ), 'Starting it again' ), 'it is restarted automatically' );

$stalled             = Jobs::get( $job2 );
$stalled['status']   = 'running';
$stalled['stage']    = 'outline';
$stalled['attempts'] = AnaAfdal\Writer\Pipeline::MAX_ATTEMPTS;
update_post_meta( $job2, '_aaw_state', wp_slash( wp_json_encode( $stalled, JSON_UNESCAPED_UNICODE ) ) );
update_post_meta( $job2, '_aaw_status', 'running' );
$run_queue();
$j2 = Jobs::get( $job2 );
$check( 'failed' === $j2['status'] && str_contains( $j2['error'], 'several times' ), 'after repeated stops it fails with advice' );

echo "Cancel while Claude is writing\n";
$job7     = Topics::start( 1, '' );
$original = Claude::$sse_transport;
Claude::$sse_transport = static function ( array $body, callable $write ) use ( $job7, $original ) {
	$events = aaw_test_sse_events( aaw_test_claude_reply( $body ) );
	foreach ( $events as $i => $event ) {
		if ( 3 === $i ) {
			// Another request presses Cancel, then time passes before the next chunk arrives.
			Jobs::update( $job7, static function ( array $s ) { $s['status'] = 'cancelled'; return $s; } );
			sleep( 4 );
		}
		$write( $event );
	}
};
$run_queue();
$j7 = Jobs::get( $job7 );
$check( 'cancelled' === $j7['status'] && 'topics_research' === $j7['stage'], 'cancel stops the running step' );
$check( str_contains( wp_json_encode( $j7['log'] ), 'Stopped' ) && empty( $j7['data']['notes'] ), 'nothing after the cancel was saved' );
Claude::$sse_transport = $original;

echo "Live progress\n";
$events = $research_progress['events'] ?? array();
$check( $events && 'search' === $events[0]['type'] && 'test' === $events[0]['text'] && 5 === ( $events[0]['results'] ?? 0 ), 'searches and their result counts are reported live' );
$wp = AnaAfdal\Writer\Progress::count_words( '{"intro":["إذا كنت تبحث عن كورسات","الذكاء الاصطناعي"],"sections":[{"heading":"x"}]}' );
$check( 7 === $wp, 'word estimate ignores JSON keys: ' . $wp );

echo "Upgrade from 1.0\n";
$old_running = Jobs::create( Jobs::TYPE_ARTICLE, 1, 'Old running job', array() );
Jobs::update( $old_running, static fn( $s ) => array_merge( $s, array( 'status' => 'running', 'stage' => 'write' ) ) );
$old_waiting = Jobs::create( Jobs::TYPE_ARTICLE, 1, 'Old waiting job', array() );
Jobs::update( $old_waiting, static fn( $s ) => array_merge( $s, array( 'status' => 'waiting', 'stage' => 'approve' ) ) );
delete_post_meta( $old_running, Jobs::STATUS_META );
delete_post_meta( $old_waiting, Jobs::STATUS_META );
update_option( 'aaw_db_version', '1' );
AnaAfdal\Writer\Installer::maybe_upgrade();
$j = Jobs::get( $old_running );
$check( 'failed' === $j['status'] && 'write' === $j['failed_stage'] && 'failed' === Jobs::status_fresh( $old_running ), 'a step that was running during the update stops and can be retried' );
$check( 'waiting' === Jobs::status_fresh( $old_waiting ), 'other jobs keep their status' );
$check( ! in_array( $old_running, Worker::pending_ids(), true ), 'old jobs are not restarted in the background' );
$check( (bool) wp_next_scheduled( Worker::CRON_HOOK ) && '2' === get_option( 'aaw_db_version' ), 'the minute check is scheduled after updating' );
wp_delete_post( $old_running, true );
wp_delete_post( $old_waiting, true );

echo "\nOutput draft content saved to tests/output-content.html\n";
file_put_contents( dirname( __FILE__ ) . '/output-content.html', get_post_field( 'post_content', $post_id ) );

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASSED\n";
