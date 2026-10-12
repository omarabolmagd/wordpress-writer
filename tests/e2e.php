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
use AnaAfdal\Writer\Library;
use AnaAfdal\Writer\Links;
use AnaAfdal\Writer\Progress;
use AnaAfdal\Writer\Prompts;
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
$thumb = (int) get_post_thumbnail_id( $post_id );
$check( $thumb && 'ai' === get_post_meta( $thumb, '_aaw_image_source', true ) && 'cover' === get_post_meta( $thumb, '_aaw_image_role', true ), 'the featured image is a Gemini photo, not the title cover' );
$check( 'طالب يتابع كورس ذكاء اصطناعي على حاسوبه' === get_post_meta( $thumb, '_wp_attachment_image_alt', true ), 'the featured image has the planned Arabic alt text' );
$cover_requests = array_values( array_filter( $GLOBALS['aaw_test_gemini'] ?? array(), static fn( $b ) => str_contains( wp_json_encode( $b ), 'online AI course' ) ) );
$cover_input    = (string) ( $cover_requests[0]['input'] ?? '' );
$check( str_contains( $cover_input, 'Photorealistic' ) && str_contains( $cover_input, 'no text' ) && ! str_contains( $cover_input, $a['data']['outline']['title'] ), 'Gemini is asked for a photo of the scene with no text, not the title' );
$check( 1 === (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM ' . Costs::table() . ' WHERE job_id = %d AND item = %s', $job_id, 'ai_cover' ) ), 'the featured photo\'s cost is logged' );
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
$job7     = Topics::start( 1, '', false );
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
$events = array_values( array_filter( $research_progress['events'] ?? array(), static fn( $e ) => 'search' === $e['type'] ) );
$check( $events && 'test' === $events[0]['text'] && 5 === ( $events[0]['results'] ?? 0 ), 'searches and their result counts are reported live' );
$wp = AnaAfdal\Writer\Progress::count_words( '{"intro":["إذا كنت تبحث عن كورسات","الذكاء الاصطناعي"],"sections":[{"heading":"x"}]}' );
$check( 7 === $wp, 'word estimate ignores JSON keys: ' . $wp );

echo "Featured image\n";
$o_first = Jobs::get( $job_id )['data']['outline'];
$check( str_contains( $o_first['cover']['prompt'], 'student' ) && '' !== $o_first['cover']['query'], 'the outline plans a scene for the featured image' );
$check( in_array( 'cover', Prompts::outline_schema()['required'], true ), 'Claude must plan the featured image' );
$plan  = array( 'prompt' => 'Students arriving at a university', 'query' => 'students university', 'alt' => 'طلاب' );
$flags = &$GLOBALS['aaw_test_flags'];
$flags['gemini_fail'] = true;
$c = AnaAfdal\Writer\Images::cover( $plan, 'منحة', 'منح', 0, 'test-cover' );
$check( 'pexels' === $c['source'] && isset( $c['errors']['ai'] ), 'when Gemini fails, a Pexels photo of the scene is used' );
$s2               = Settings::all();
$s2['pexels_key'] = '';
update_option( Settings::OPTION, $s2 );
$c = AnaAfdal\Writer\Images::cover( $plan, 'منحة', 'منح', 0, 'test-cover' );
$check( 'branded' === $c['source'], 'with no photo source working, the title cover is drawn' );
$flags['gemini_fail'] = false;
$s2['pexels_key']  = 'pex-test';
$s2['cover_style'] = 'branded';
update_option( Settings::OPTION, $s2 );
$before = count( $GLOBALS['aaw_test_gemini'] ?? array() );
$c      = AnaAfdal\Writer\Images::cover( $plan, 'منحة', 'منح', 0, 'test-cover' );
$check( 'branded' === $c['source'] && count( $GLOBALS['aaw_test_gemini'] ?? array() ) === $before, 'the title cover style still works, without calling Gemini' );
$s2['cover_style'] = 'pexels';
update_option( Settings::OPTION, $s2 );
$c = AnaAfdal\Writer\Images::cover( $plan, 'منحة', 'منح', 0, 'test-cover' );
$check( 'pexels' === $c['source'], 'the stock photo style uses Pexels' );
$s2['cover_style'] = 'ai';
update_option( Settings::OPTION, $s2 );

// A new featured image for a finished article.
$old_thumb = (int) get_post_thumbnail_id( $post_id );
$res       = Article::new_cover( $job_id, 'A young woman in a job interview, smiling across the table' );
$new_thumb = (int) get_post_thumbnail_id( $post_id );
$last      = end( $GLOBALS['aaw_test_gemini'] );
$check( $new_thumb && $new_thumb !== $old_thumb && 'ai' === $res['source'] && str_contains( (string) ( $last['input'] ?? '' ), 'job interview' ), 'a new featured image can be made from a typed scene' );
$check( str_contains( Jobs::get( $job_id )['data']['outline']['cover']['prompt'], 'job interview' ) && get_post( $old_thumb ), 'the scene is remembered and the old image is kept in the Media Library' );
// An article planned before scenes existed: Claude describes one from the title.
Jobs::update( $job_id, static function ( $s ) { unset( $s['data']['outline']['cover'] ); return $s; } );
$n_calls = count( $calls );
Article::new_cover( $job_id );
$check( in_array( 'cover_scene', array_slice( $calls, $n_calls ), true ) && str_contains( (string) ( end( $GLOBALS['aaw_test_gemini'] )['input'] ?? '' ), 'CV' ), 'without a planned scene, Claude describes one from the title' );
// If Gemini fails, the current image stays and the editor sees why.
$flags['gemini_fail'] = true;
$kept  = (int) get_post_thumbnail_id( $post_id );
$error = '';
try {
	Article::new_cover( $job_id, 'Another scene' );
} catch ( \Throwable $e ) {
	$error = $e->getMessage();
}
$flags['gemini_fail'] = false;
$check( str_contains( $error, 'overloaded' ) && $kept === (int) get_post_thumbnail_id( $post_id ), 'a failed remake keeps the current image and reports the error' );

// A free-tier Gemini key: Google refuses image models, and the editor is told why in plain words.
add_filter( 'aaw_gemini_poll_wait', '__return_zero' );
$flags['gemini_free_tier'] = true;
$error                     = '';
try {
	Article::new_cover( $job_id, 'A scene' );
} catch ( \Throwable $e ) {
	$error = $e->getMessage();
}
$check( str_contains( $error, 'paid tier' ) && str_contains( $error, 'billing' ) && str_contains( $error, 'RESOURCE_EXHAUSTED' ), 'a free-tier key gets a plain explanation plus Google\'s message' );
$c = AnaAfdal\Writer\Images::cover( $plan, 'منحة', 'منح', 0, 'test-cover' );
$check( 'pexels' === $c['source'] && str_contains( $c['errors']['ai'] ?? '', 'billing' ), 'without Gemini billing, articles fall back to a Pexels photo and say why' );
$test = AnaAfdal\Writer\Rest::test_image()->get_data();
$check( false === $test['ok'] && str_contains( $test['message'], 'billing' ), 'the test photo button shows the billing problem' );
$flags['gemini_free_tier'] = false;
$test = AnaAfdal\Writer\Rest::test_image()->get_data();
$check( true === $test['ok'] && str_starts_with( $test['image'], 'data:image/jpeg;base64,' ), 'the test photo button shows a real photo when Gemini works' );
$flags['gemini_async'] = true;
$check( strlen( AnaAfdal\Writer\Images::gemini_bytes( 'x', 0, 'ai_test' ) ) > 1000, 'a photo Google finishes in the background is waited for' );
$flags['gemini_async'] = false;
$flags['gemini_uri']   = true;
$check( strlen( AnaAfdal\Writer\Images::gemini_bytes( 'x', 0, 'ai_test' ) ) > 1000, 'a photo returned as a download link is downloaded' );
$flags['gemini_uri'] = false;
Jobs::update( $job_id, static function ( $s ) { $s['data']['images']['cover']['errors'] = array( 'ai' => 'Gemini error 429: quota' ); return $s; } );
$pc = AnaAfdal\Writer\Rest::present( Jobs::get( $job_id ) )['cover'];
$check( array( 'Gemini error 429: quota' ) === $pc['errors'], 'the article page shows why the planned photo was not used' );

echo "Saved research\n";
global $wpdb;
$lib_table   = Library::table();
$first       = Jobs::get( $job_id );
$lib_first   = Library::get( (int) ( $first['data']['research']['library_id'] ?? 0 ) );
$check( $lib_first && Library::KIND_ARTICLE === $lib_first['kind'] && $job_id === $lib_first['job_id'] && str_contains( $lib_first['notes'], 'AI For Everyone' ) && count( $lib_first['sources'] ) >= 5 && $lib_first['cost'] > 0, 'an article\'s research is saved with its sources and cost' );
$check( 1 === count( $first['data']['research']['reused'] ) && 'ideas' === $first['data']['research']['reused'][0]['kind'], 'the notes of the topic search that suggested it were passed on' );
$fb = $bodies_for( 'Notes from the topic search that suggested this article' );
$check( $fb && 6 === $fb[0]['tools'][0]['max_uses'], 'topic-search notes alone do not cut the number of searches' );
$check( array( 'كورس', 'ذكاء', 'اصطناع', 'مجان', 'شهاد' ) === Library::terms( 'أفضل كورسات الذكاء الاصطناعي المجانية بشهادات في 2026' ), 'Arabic titles are reduced to comparable words' );
$check( Library::focus_key( ' مِنَح  في أوروبا ' ) === Library::focus_key( 'منح في اوروبا' ), 'the same focus typed differently matches' );

$research_body_after = static function ( int $from ) {
	foreach ( array_slice( $GLOBALS['aaw_test_bodies'], $from ) as $b ) {
		if ( ! empty( $b['tools'] ) ) {
			return $b;
		}
	}
	return null;
};
$start_research = static function ( array $topic ) {
	$id = Article::start( 1, $topic );
	Worker::run_pending( 600, null, 1 );
	return $id;
};

// A closely related article starts from the saved research, with fewer searches.
// Give every saved article a cited source the new research won't find again, and one it never cited.
foreach ( $wpdb->get_results( "SELECT id, sources FROM {$lib_table} WHERE kind = 'article'", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$srcs   = json_decode( $row['sources'], true );
	$srcs[] = array( 'url' => 'https://www.example.org/ai-certificates-guide', 'title' => 'AI certificates guide', 'cited' => true );
	$srcs[] = array( 'url' => 'https://www.example.org/unrelated-page', 'title' => 'Unrelated', 'cited' => false );
	$wpdb->update( $lib_table, array( 'sources' => wp_json_encode( $srcs ) ), array( 'id' => $row['id'] ) );
}
$n       = count( $GLOBALS['aaw_test_bodies'] );
$job_rel = $start_research( array( 'title' => 'كورسات الذكاء الاصطناعي المجانية من جوجل', 'focus_keyphrase' => 'كورسات ذكاء اصطناعي مجانية', 'post_type' => 'guide' ) );
$rb      = $research_body_after( $n );
$jr      = Jobs::get( $job_rel );
$check( $rb && 3 === $rb['tools'][0]['max_uses'], 'a close match halves the searches: ' . ( $rb['tools'][0]['max_uses'] ?? '?' ) );
$check( $rb && str_contains( $rb['messages'][0]['content'], 'Research for the article "' ) && str_contains( $rb['messages'][0]['content'], 'free to audit' ) && str_contains( $rb['messages'][0]['content'], 'https://www.deeplearning.ai/courses/' ), 'the earlier notes and sources are in the research request' );
$check( $jr['data']['research']['reused'] && 'article' === $jr['data']['research']['reused'][0]['kind'], 'the job records the research it started from' );
$reuse_events = array_values( array_filter( Progress::get( $job_rel )['events'] ?? array(), static fn( $e ) => 'reuse' === $e['type'] ) );
$check( $reuse_events && $reuse_events[0]['articles'] >= 1, 'the live view says saved notes are used' );
$lib_rel = Library::get( (int) $jr['data']['research']['library_id'] );
$check( $lib_rel && $lib_rel['meta']['reused_from'] && Library::get( (int) $lib_rel['meta']['reused_from'][0] )['reused_count'] >= 1, 'the new research is saved too, and the reuse is counted' );
$rel_urls = wp_list_pluck( $jr['data']['research']['sources'], 'url' );
$check( in_array( 'https://www.example.org/ai-certificates-guide', $rel_urls, true ) && ! in_array( 'https://www.example.org/unrelated-page', $rel_urls, true ), 'cited sources from the saved research stay available to cite; uncited ones are dropped' );
Jobs::update( $job_rel, static fn( $s ) => array_merge( $s, array( 'status' => 'cancelled' ) ) );

// A topic that only shares generic words is researched from scratch.
$n      = count( $GLOBALS['aaw_test_bodies'] );
$job_un = $start_research( array( 'title' => 'أفضل مواقع لتعلم اللغة الإنجليزية مجانا', 'focus_keyphrase' => 'تعلم اللغة الإنجليزية', 'post_type' => 'list' ) );
$rb     = $research_body_after( $n );
$check( $rb && 6 === $rb['tools'][0]['max_uses'] && ! str_contains( $rb['messages'][0]['content'], 'Saved research' ), 'an unrelated topic is researched from scratch' );
Jobs::update( $job_un, static fn( $s ) => array_merge( $s, array( 'status' => 'cancelled' ) ) );

// News only reuses recent notes; guides can use older ones.
$wpdb->query( $wpdb->prepare( "UPDATE {$lib_table} SET created_gmt = %s WHERE kind = 'article'", gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ) );
$n        = count( $GLOBALS['aaw_test_bodies'] );
$job_news = $start_research( array( 'title' => 'كورسات الذكاء الاصطناعي المجانية الجديدة', 'focus_keyphrase' => 'كورسات ذكاء اصطناعي مجانية', 'post_type' => 'news' ) );
$rb       = $research_body_after( $n );
$check( $rb && ! str_contains( $rb['messages'][0]['content'], 'Saved research' ), 'news does not reuse notes older than 7 days' );
Jobs::update( $job_news, static fn( $s ) => array_merge( $s, array( 'status' => 'cancelled' ) ) );
$n         = count( $GLOBALS['aaw_test_bodies'] );
$job_guide = $start_research( array( 'title' => 'كورسات الذكاء الاصطناعي المجانية الجديدة', 'focus_keyphrase' => 'كورسات ذكاء اصطناعي مجانية', 'post_type' => 'guide' ) );
$rb        = $research_body_after( $n );
$check( $rb && str_contains( $rb['messages'][0]['content'], 'Saved research' ), 'a guide reuses notes from 10 days ago' );
Jobs::update( $job_guide, static fn( $s ) => array_merge( $s, array( 'status' => 'cancelled' ) ) );

// Turned off in Settings.
$settings                   = Settings::all();
$settings['reuse_research'] = 0;
update_option( Settings::OPTION, $settings );
$n       = count( $GLOBALS['aaw_test_bodies'] );
$job_off = $start_research( array( 'title' => 'كورسات الذكاء الاصطناعي المجانية من جوجل', 'focus_keyphrase' => 'كورسات ذكاء اصطناعي مجانية', 'post_type' => 'guide' ) );
$rb      = $research_body_after( $n );
$check( $rb && 6 === $rb['tools'][0]['max_uses'] && ! str_contains( $rb['messages'][0]['content'], 'Saved research' ), 'nothing is reused when the setting is off' );
Jobs::update( $job_off, static fn( $s ) => array_merge( $s, array( 'status' => 'cancelled' ) ) );
$settings['reuse_research'] = 1;
update_option( Settings::OPTION, $settings );

// Topic ideas: a second request with the same focus picks new ideas from the saved search.
$searches_before = count( array_filter( $calls, static fn( $c ) => 'topics_research' === $c ) );
$tj1             = Topics::start( 1, 'منح' );
$run_queue();
$n   = count( $GLOBALS['aaw_test_bodies'] );
$tj2 = Topics::start( 1, ' مِنَح ' );
$run_queue();
$t2  = Jobs::get( $tj2 );
$searches_after = count( array_filter( $calls, static fn( $c ) => 'topics_research' === $c ) );
$check( 1 === $searches_after - $searches_before && 'done' === $t2['status'] && ! empty( $t2['data']['reused_ideas'] ), 'the same focus within 3 days reuses the topic search' );
$sb = $bodies_for( 'already suggested to the editor' );
$check( $sb && str_contains( wp_json_encode( end( $sb ), JSON_UNESCAPED_UNICODE ), 'أفضل كورسات الذكاء الاصطناعي المجانية بشهادات في 2026' ), 'ideas already suggested are excluded' );
$check( $t2['data']['topics'] && $t2['data']['topics'][0]['ideas_id'] === (int) Jobs::get( $tj1 )['data']['ideas_id'], 'suggested topics remember their topic search' );
$recent = wp_list_pluck( Library::recent_ideas(), 'focus' );
$check( in_array( 'منح', $recent, true ), 'the topics form can offer the saved search' );
$tj3 = Topics::start( 1, 'منح', false );
$run_queue();
$check( 2 === count( array_filter( $calls, static fn( $c ) => 'topics_research' === $c ) ) - $searches_before, 'unticking Reuse searches the web again' );

// Average costs for the Research page, measured from the cost log.
$avg = Library::average_costs();
$check( $avg['fresh_count'] >= 1 && $avg['reuse_count'] >= 1 && null !== $avg['fresh'] && null !== $avg['reuse'], 'average research cost is measured with and without saved notes' );

// Old notes are deleted.
$old_id = Library::save( array( 'kind' => 'article', 'job_id' => 0, 'title' => 'Old', 'notes' => 'old notes', 'created' => time() - 200 * DAY_IN_SECONDS ) );
Library::cleanup();
$check( $old_id && null === Library::get( $old_id ) && null !== Library::get( (int) $lib_rel['id'] ), 'notes older than 180 days are deleted, newer ones kept' );

// Updating from 1.1 fills the library from earlier jobs.
$wpdb->query( "DELETE FROM {$lib_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
update_option( 'aaw_db_version', '2' );
AnaAfdal\Writer\Installer::maybe_upgrade();
$kinds = $wpdb->get_col( "SELECT kind FROM {$lib_table} WHERE job_id = {$job_id} OR job_id = {$topics_job}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
sort( $kinds );
$check( array( 'article', 'ideas' ) === $kinds && '3' === get_option( 'aaw_db_version' ), 'updating adds the research of earlier jobs to the library' );
$back = $wpdb->get_row( "SELECT searches, cost FROM {$lib_table} WHERE job_id = {$job_id}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$check( $back && (int) $back['searches'] > 0 && (float) $back['cost'] > 0, 'their searches and cost come from the cost log' );
$check( ! $wpdb->get_var( "SELECT COUNT(*) FROM {$lib_table} WHERE job_id = {$tj2}" ), 'a topic run that reused a search is not saved twice' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// WordPress may reactivate the plugin during an update instead of loading it: same result.
$wpdb->query( "DELETE FROM {$lib_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
update_option( 'aaw_db_version', '2' );
AnaAfdal\Writer\Installer::activate();
$check( Library::count() > 0 && '3' === get_option( 'aaw_db_version' ), 'updating by reactivating the plugin fills the library too' );

// The Research page renders.
ob_start();
AnaAfdal\Writer\Admin::render_research();
$page = ob_get_clean();
$check( str_contains( $page, 'AI For Everyone' ) && str_contains( $page, 'aaw_delete_research' ), 'the Saved research page lists notes with a delete button' );

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
$check( (bool) wp_next_scheduled( Worker::CRON_HOOK ) && version_compare( (string) get_option( 'aaw_db_version' ), '2', '>=' ), 'the minute check is scheduled after updating' );
wp_delete_post( $old_running, true );
wp_delete_post( $old_waiting, true );

echo "\nOutput draft content saved to tests/output-content.html\n";
file_put_contents( dirname( __FILE__ ) . '/output-content.html', get_post_field( 'post_content', $post_id ) );

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASSED\n";
