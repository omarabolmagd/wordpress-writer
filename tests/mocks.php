<?php
/**
 * Mocks for every external API the plugin calls, shared by the end-to-end test,
 * the browser test and the mock Claude server. Nothing here leaves the machine.
 */

use AnaAfdal\Writer\ApiError;
use AnaAfdal\Writer\Claude;
use AnaAfdal\Writer\Http;
use AnaAfdal\Writer\Links;

$GLOBALS['aaw_test_calls']  = $GLOBALS['aaw_test_calls'] ?? array();
$GLOBALS['aaw_test_bodies'] = $GLOBALS['aaw_test_bodies'] ?? array();
$GLOBALS['aaw_test_flags']  = $GLOBALS['aaw_test_flags'] ?? array();

/** A canned Claude reply for a request, chosen by what the request asks for. */
function aaw_test_claude_reply( array $body ): array {
	$calls = &$GLOBALS['aaw_test_calls'];
	$flags = &$GLOBALS['aaw_test_flags'];
	$GLOBALS['aaw_test_bodies'][] = $body;

	if ( ! empty( $flags['reject_thinking'] ) && isset( $body['thinking'] ) ) {
		$calls[] = 'rejected_thinking';
		throw new ApiError( 'Claude API error 400: thinking.type: this model does not support this setting', 400 );
	}

	$published = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) );
	$sources   = array(
		'https://www.coursera.org/learn/ai-for-everyone' => 'AI For Everyone | Coursera',
		'https://www.deeplearning.ai/courses/'           => 'Courses - DeepLearning.AI',
		'https://grow.google/certificates/'              => 'Google Career Certificates',
		'https://www.coursera.org/broken-page'           => 'Old page',
		'https://www.linkedin.com/learning/'             => 'LinkedIn Learning',
	);
	$system = (string) $body['system'];
	$prompt = (string) $body['messages'][0]['content'];
	$schema = $body['output_config']['format']['schema'] ?? null;
	$usage  = array( 'input_tokens' => 12000, 'output_tokens' => 3000, 'server_tool_use' => array( 'web_search_requests' => 0 ) );
	$text   = static fn( string $t ) => array( 'type' => 'text', 'text' => $t );
	$json   = static fn( array $d ) => array( 'type' => 'text', 'text' => wp_json_encode( $d, JSON_UNESCAPED_UNICODE ) );

	$results = array();
	foreach ( $sources as $url => $title ) {
		$results[] = array( 'type' => 'web_search_result', 'url' => $url, 'title' => $title, 'encrypted_content' => 'x', 'page_age' => 'October 2026' );
	}
	$search_blocks = array(
		array( 'type' => 'server_tool_use', 'id' => 'srv1', 'name' => 'web_search', 'input' => array( 'query' => 'test' ) ),
		array( 'type' => 'web_search_tool_result', 'tool_use_id' => 'srv1', 'content' => $results ),
		array( 'type' => 'server_tool_use', 'id' => 'srv2', 'name' => 'web_search', 'input' => array( 'query' => 'كورسات ذكاء اصطناعي مجانية بشهادة 2026' ) ),
		array( 'type' => 'web_search_tool_result', 'tool_use_id' => 'srv2', 'content' => array_slice( $results, 0, 3 ) ),
	);

	if ( ! empty( $body['tools'] ) ) {
		$usage['server_tool_use']['web_search_requests'] = 4;
		if ( str_contains( $system, 'content strategist' ) ) {
			$calls[] = 'topics_research';
			return array( 'content' => array_merge( $search_blocks, array( $text( 'Idea notes: free AI courses with certificates are trending; scholarships deadlines; English learning with AI.' ) ) ), 'stop_reason' => 'end_turn', 'usage' => $usage );
		}
		// Article research: first reply pauses, second finishes (tests pause_turn handling).
		$paused = count( $body['messages'] ) === 1;
		$calls[] = $paused ? 'research_paused' : 'research_resumed';
		if ( ! $paused ) {
			$calls[] = 'resent_input_is_object:' . ( wp_json_encode( $body['messages'][1]['content'][0]['input'] ?? null ) === '{"query":"test"}' ? 'yes' : 'no' );
		}
		if ( $paused ) {
			return array( 'content' => $search_blocks, 'stop_reason' => 'pause_turn', 'usage' => $usage );
		}
		return array(
			'content'     => array(
				$text( "Key facts: Coursera's AI For Everyone is free to audit; certificate requires payment or financial aid (as of October 2026). " ),
				array(
					'type'      => 'text',
					'text'      => 'DeepLearning.AI offers short courses.',
					'citations' => array( array( 'type' => 'web_search_result_location', 'url' => 'https://www.deeplearning.ai/courses/', 'title' => 'Courses - DeepLearning.AI', 'cited_text' => 'Short courses', 'encrypted_index' => 'x' ) ),
				),
			),
			'stop_reason' => 'end_turn',
			'usage'       => $usage,
		);
	}

	$props = array_keys( $schema['properties'] ?? array() );
	if ( array( 'prompt', 'query', 'alt' ) === $props ) {
		$calls[] = 'cover_scene';
		return array( 'content' => array( $json( array( 'prompt' => 'A young woman reviewing her CV on a laptop in a bright cafe', 'query' => 'woman cv laptop', 'alt' => 'شابة تراجع سيرتها الذاتية على حاسوبها' ) ) ), 'stop_reason' => 'end_turn', 'usage' => $usage );
	}
	if ( in_array( 'topics', $props, true ) ) {
		$calls[] = 'topics_structure';
		return array( 'content' => array( $json( array( 'topics' => array(
			array( 'title' => 'أفضل كورسات الذكاء الاصطناعي المجانية بشهادات في 2026', 'focus_keyphrase' => 'كورسات الذكاء الاصطناعي المجانية', 'post_type' => 'list', 'category_existing_id' => null, 'category_new_name' => 'ذكاء اصطناعي', 'why_now' => 'الطلب مرتفع على شهادات الذكاء الاصطناعي.', 'overlap_note' => '' ),
			array( 'title' => 'أخطاء يجب تجنبها عند كتابة السيرة الذاتية', 'focus_keyphrase' => 'أخطاء السيرة الذاتية', 'post_type' => 'guide', 'category_existing_id' => 999, 'category_new_name' => null, 'why_now' => 'موسم التوظيف.', 'overlap_note' => '' ),
			array( 'title' => 'منحة جديدة', 'focus_keyphrase' => 'منحة', 'post_type' => 'weird', 'category_existing_id' => null, 'category_new_name' => 'منح و وظائف', 'why_now' => 'x', 'overlap_note' => '' ),
		) ) ) ), 'stop_reason' => 'end_turn', 'usage' => $usage );
	}
	if ( in_array( 'slug', $props, true ) ) {
		$calls[] = 'outline';
		if ( ! empty( $flags['truncate_outline_once'] ) ) {
			$flags['truncate_outline_once'] = false;
			$calls[]                        = 'outline_truncated';
			return array( 'content' => array( $text( '{"title": "أفضل كورسات' ) ), 'stop_reason' => 'max_tokens', 'usage' => $usage );
		}
		$section = static fn( string $h, string $src, array $img = array() ) => array(
			'heading'    => $h,
			'key_points' => array( 'point one', 'point two' ),
			'image'      => array_merge( array( 'source' => $src, 'query' => '', 'prompt' => '', 'url' => '', 'alt' => '' ), $img ),
		);
		return array( 'content' => array( $json( array(
			'title'                => 'أفضل كورسات الذكاء الاصطناعي المجانية بشهادات معتمدة في 2026',
			'seo_title'            => 'كورسات الذكاء الاصطناعي المجانية بشهادات 2026',
			'meta_description'     => 'تعرف على أفضل كورسات الذكاء الاصطناعي المجانية بشهادات معتمدة في 2026، ومن يناسبه كل كورس، وكيف تحصل على الشهادة مجانًا أو بمساعدة مالية.',
			'focus_keyphrase'      => 'كورسات الذكاء الاصطناعي المجانية',
			'slug'                 => 'Free AI Courses with Certificates 2026!!',
			'post_type'            => 'list',
			'category_existing_id' => null,
			'category_new_name'    => 'ذكاء اصطناعي',
			'angle'                => 'مقارنة عملية بين الكورسات حسب مستواك.',
			'sections'             => array(
				$section( 'كورس AI For Everyone من Coursera', 'screenshot', array( 'url' => 'https://www.coursera.org/learn/ai-for-everyone', 'alt' => 'صفحة كورس AI For Everyone' ) ),
				$section( 'دورات DeepLearning.AI القصيرة', 'pexels', array( 'query' => 'student laptop study', 'alt' => 'طالب يدرس على حاسوبه' ) ),
				$section( 'شهادات Google المهنية', 'none' ),
				$section( 'كيف تختار الكورس المناسب لك؟', 'ai', array( 'prompt' => 'a student choosing between learning paths', 'alt' => 'طالب يختار بين مسارات تعلم' ) ),
				$section( 'مقارنة سريعة بين الكورسات', 'pexels', array( 'query' => '', 'alt' => 'x' ) ),
			),
			'cover'                => array(
				'prompt' => 'A young Arab student following an online AI course on a laptop at a tidy desk with notebooks',
				'query'  => 'student laptop online course',
				'alt'    => 'طالب يتابع كورس ذكاء اصطناعي على حاسوبه',
			),
			'faq_questions'        => array( 'هل الشهادات مجانية فعلًا؟', 'هل أحتاج خبرة سابقة؟', 'كم يستغرق الكورس؟', 'هل الشهادة معترف بها؟' ),
			'internal_link_ids'    => array( $published[0], $published[1], $published[3], $published[10], 999999 ),
			'external_sources'     => array( 'https://www.coursera.org/learn/ai-for-everyone', 'https://www.deeplearning.ai/courses/', 'https://not-in-research.example.com/' ),
		) ) ), 'stop_reason' => 'end_turn', 'usage' => $usage );
	}
	if ( in_array( 'intro', $props, true ) ) {
		$calls[] = 'write';
		$p = static fn( string $t ) => array( 'kind' => 'paragraph', 'text' => $t );
		return array( 'content' => array( $json( array(
			'intro'    => array(
				'إذا كنت تبحث عن **كورسات الذكاء الاصطناعي المجانية** بشهادات معتمدة، فهذا الدليل يجمع لك أفضل الخيارات المتاحة في 2026 مع مقارنة واضحة.',
				'ستعرف في هذا المقال من يناسبه كل كورس، وكم يستغرق، وكيف تحصل على الشهادة. وإذا كنت مبتدئًا تمامًا فابدأ بقراءة [دليل كورسات كورسيرا](post:' . $published[0] . ').',
			),
			'sections' => array(
				array( 'heading' => 'IGNORED HEADING', 'blocks' => array(
					$p( 'يقدم [كورس AI For Everyone على منصة Coursera](https://www.coursera.org/learn/ai-for-everyone) مقدمة مبسطة للذكاء الاصطناعي لغير المتخصصين.' ),
					array( 'kind' => 'list', 'ordered' => false, 'items' => array( 'مدة الكورس: نحو 6 ساعات', 'المستوى: مبتدئ', 'الشهادة: مدفوعة مع إمكانية [المساعدة المالية](https://www.coursera.org/broken-page)' ) ),
				) ),
				array( 'heading' => 'x', 'blocks' => array(
					$p( 'توفر [منصة DeepLearning.AI](https://www.deeplearning.ai/courses/) دورات قصيرة عملية في ساعة أو ساعتين.' ),
					array( 'kind' => 'subheading', 'text' => 'لمن تناسب هذه الدورات؟' ),
					$p( 'تناسب من يريد مهارة محددة بسرعة. راجع أيضًا [أفضل أدوات الذكاء الاصطناعي](post:' . $published[5] . ') و[رابط وهمي](post:999999).' ),
				) ),
				array( 'heading' => 'x', 'blocks' => array(
					$p( 'تتوفر [شهادات Google المهنية](https://grow.google/certificates/) في مجالات مثل تحليل البيانات والدعم التقني، ويمكنك مراجعة [دليل تحليل البيانات](post:' . $published[6] . ').' ),
					array( 'kind' => 'read_more', 'post_id' => $published[1] ),
				) ),
				array( 'heading' => 'x', 'blocks' => array(
					array( 'kind' => 'list', 'ordered' => true, 'items' => array( 'حدد هدفك', 'اختر المستوى', 'خصص وقتًا ثابتًا' ) ),
					$p( 'لا تنس أن تحسن لغتك عبر [دليل تعلم الإنجليزية بالذكاء الاصطناعي](post:' . $published[10] . ') وأن تقرأ [مقالًا من موقع غير موثوق](https://evil.example.com/page).' ),
				) ),
				array( 'heading' => 'x', 'blocks' => array(
					array( 'kind' => 'table', 'headers' => array( 'الكورس', 'المدة', 'الشهادة' ), 'rows' => array( array( 'AI For Everyone', '6 ساعات', 'مدفوعة' ), array( 'DeepLearning.AI', 'ساعة', 'لا يوجد' ) ) ),
					$p( 'اختر ما يناسب وقتك وميزانيتك. صفحة [LinkedIn Learning](https://www.linkedin.com/learning/) تحجب الفحص الآلي.' ),
				) ),
			),
			'faq'      => array(
				array( 'question' => 'هل الشهادات مجانية فعلًا؟', 'answer' => 'بعض الكورسات تمنح شهادة مجانية، وبعضها يتطلب دفع رسوم أو التقديم على [المساعدة المالية](https://www.coursera.org/learn/ai-for-everyone).' ),
				array( 'question' => 'هل أحتاج خبرة سابقة؟', 'answer' => 'لا، معظم الكورسات المذكورة مناسبة للمبتدئين.' ),
				array( 'question' => 'كم يستغرق الكورس؟', 'answer' => 'من ساعة واحدة إلى عدة أسابيع حسب الكورس.' ),
				array( 'question' => 'هل الشهادة معترف بها؟ <script>alert(1)</script>', 'answer' => 'تعترف بها شركات كثيرة كإثبات للمهارة.' ),
			),
			'summary'  => array( 'ابدأ بكورس واحد يناسب مستواك، والتزم به حتى النهاية قبل الانتقال إلى غيره.' ),
		) ) ), 'stop_reason' => 'end_turn', 'usage' => $usage );
	}
	if ( in_array( 'items', $props, true ) ) {
		$calls[] = 'quality';
		return array( 'content' => array( $json( array( 'items' => array(
			array( 'check' => 'outdated_facts', 'status' => 'pass', 'note' => '' ),
			array( 'check' => 'dialect', 'status' => 'warn', 'note' => 'عبارة "مش" في الفقرة الثانية.' ),
			array( 'check' => 'bogus', 'status' => 'warn', 'note' => 'should be ignored' ),
		) ) ) ), 'stop_reason' => 'end_turn', 'usage' => $usage );
	}
	if ( in_array( 'suggestions', $props, true ) ) {
		$calls[] = 'backlinks';
		preg_match_all( '/=== post (\d+):.*?\n\[0\] (.+?)\n/u', $prompt, $m, PREG_SET_ORDER );
		$out = array();
		foreach ( array_slice( $m, 0, 3 ) as $row ) {
			$words = preg_split( '/\s+/u', $row[2] );
			$out[] = array( 'post_id' => (int) $row[1], 'paragraph_index' => 0, 'anchor' => implode( ' ', array_slice( $words, 2, 3 ) ), 'reason' => 'سياق مناسب.' );
		}
		$out[] = array( 'post_id' => (int) ( $m[0][1] ?? 0 ), 'paragraph_index' => 0, 'anchor' => 'عبارة غير موجودة إطلاقًا', 'reason' => 'x' );
		return array( 'content' => array( $json( array( 'suggestions' => $out ) ) ), 'stop_reason' => 'end_turn', 'usage' => $usage );
	}
	throw new RuntimeException( 'Unexpected Claude call: ' . substr( $system, 0, 80 ) );
}

/**
 * The server-sent events Claude would stream for a whole message, so tests run the
 * real streaming parser. Text is split into small pieces like the real API does.
 */
function aaw_test_sse_events( array $msg ): array {
	$events = array();
	$add    = static function ( string $type, array $data ) use ( &$events ) {
		$data['type'] = $type;
		$events[]     = 'event: ' . $type . "\n" . 'data: ' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) . "\n\n";
	};
	$usage = (array) ( $msg['usage'] ?? array() );
	$start = array(
		'id'      => 'msg_test',
		'type'    => 'message',
		'role'    => 'assistant',
		'model'   => 'claude-test',
		'content' => array(),
		'usage'   => array( 'input_tokens' => (int) ( $usage['input_tokens'] ?? 0 ), 'output_tokens' => 1 ),
	);
	$add( 'message_start', array( 'message' => $start ) );
	$add( 'ping', array() );
	foreach ( array_values( $msg['content'] ?? array() ) as $i => $block ) {
		switch ( $block['type'] ) {
			case 'text':
				$add( 'content_block_start', array( 'index' => $i, 'content_block' => array( 'type' => 'text', 'text' => '' ) ) );
				foreach ( mb_str_split( (string) $block['text'], 12 ) as $piece ) {
					$add( 'content_block_delta', array( 'index' => $i, 'delta' => array( 'type' => 'text_delta', 'text' => $piece ) ) );
				}
				foreach ( $block['citations'] ?? array() as $citation ) {
					$add( 'content_block_delta', array( 'index' => $i, 'delta' => array( 'type' => 'citations_delta', 'citation' => $citation ) ) );
				}
				break;
			case 'server_tool_use':
				$add( 'content_block_start', array( 'index' => $i, 'content_block' => array( 'type' => 'server_tool_use', 'id' => $block['id'], 'name' => $block['name'], 'input' => new stdClass() ) ) );
				foreach ( str_split( wp_json_encode( $block['input'], JSON_UNESCAPED_UNICODE ), 5 ) as $piece ) {
					$add( 'content_block_delta', array( 'index' => $i, 'delta' => array( 'type' => 'input_json_delta', 'partial_json' => $piece ) ) );
				}
				break;
			case 'thinking':
				$add( 'content_block_start', array( 'index' => $i, 'content_block' => array( 'type' => 'thinking', 'thinking' => '', 'signature' => '' ) ) );
				$add( 'content_block_delta', array( 'index' => $i, 'delta' => array( 'type' => 'signature_delta', 'signature' => (string) ( $block['signature'] ?? 'sig' ) ) ) );
				break;
			default:
				$add( 'content_block_start', array( 'index' => $i, 'content_block' => $block ) );
		}
		$add( 'content_block_stop', array( 'index' => $i ) );
	}
	$add( 'message_delta', array( 'delta' => array( 'stop_reason' => $msg['stop_reason'] ?? 'end_turn', 'stop_sequence' => null ), 'usage' => $usage ) );
	$add( 'message_stop', array() );
	return $events;
}

/** Writes the events to the parser in uneven chunks, to test events split across reads. */
function aaw_test_stream_reply( array $body, callable $write ): void {
	$text   = implode( '', aaw_test_sse_events( aaw_test_claude_reply( $body ) ) );
	$offset = 0;
	$sizes  = array( 7, 64, 1, 300, 33, 4096 );
	$n      = 0;
	while ( $offset < strlen( $text ) ) {
		$size = $sizes[ $n++ % count( $sizes ) ];
		$write( substr( $text, $offset, $size ) );
		$offset += $size;
	}
}

/**
 * @param string $claude "stream" (default): streamed through the real parser,
 *                       "plain": whole replies, "none": leave Claude alone (mock server).
 */
function aaw_test_install_mocks( string $claude = 'stream' ): void {
	if ( 'stream' === $claude ) {
		Claude::$transport     = null;
		Claude::$sse_transport = 'aaw_test_stream_reply';
	} elseif ( 'plain' === $claude ) {
		Claude::$sse_transport = null;
		Claude::$transport     = 'aaw_test_claude_reply';
	}

	$jpeg = static function ( int $w = 1200, int $h = 675 ): string {
		$im = imagecreatetruecolor( $w, $h );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 40, 120, 160 ) );
		ob_start();
		imagejpeg( $im, null, 80 );
		return (string) ob_get_clean();
	};

	$http_log = array();
	Http::$mock = static function ( string $method, string $url, array $args ) use ( $jpeg, &$http_log ) {
		$http_log[] = $method . ' ' . preg_replace( '/\?.*/', '', $url );
		if ( str_starts_with( $url, 'https://api.pexels.com/v1/search' ) ) {
			return array( 'status' => 200, 'headers' => array(), 'body' => wp_json_encode( array( 'photos' => array(
				array( 'id' => 111, 'photographer' => 'Sara Ahmed', 'url' => 'https://www.pexels.com/photo/111/', 'src' => array( 'large2x' => 'https://images.pexels.com/photos/111/a.jpeg' ) ),
				array( 'id' => 222, 'photographer' => 'Omar Ali', 'url' => 'https://www.pexels.com/photo/222/', 'src' => array( 'large2x' => 'https://images.pexels.com/photos/222/b.jpeg' ) ),
			) ) ) );
		}
		if ( str_starts_with( $url, 'https://images.pexels.com/' ) || str_starts_with( $url, 'https://api.screenshotone.com/take' ) ) {
			return array( 'status' => 200, 'headers' => array(), 'body' => $jpeg() );
		}
		if ( str_contains( $url, 'generativelanguage.googleapis.com' ) ) {
			$flags   = $GLOBALS['aaw_test_flags'] ?? array();
			$image   = array( 'type' => 'image', 'mime_type' => 'image/jpeg', 'data' => base64_encode( $jpeg( 1376, 768 ) ) );
			$done    = static fn( array $content ) => array( 'status' => 200, 'headers' => array(), 'body' => wp_json_encode( array( 'id' => 'i1', 'object' => 'interaction', 'status' => 'completed', 'steps' => array( array( 'type' => 'model_output', 'content' => $content ) ) ) ) );
			if ( 'GET' === $method && str_contains( $url, '/v1beta/interactions/' ) ) {
				// Polling a background interaction: finished now.
				return $done( array( $image ) );
			}
			if ( 'GET' === $method && str_contains( $url, '/files/' ) ) {
				return array( 'status' => 200, 'headers' => array(), 'body' => $jpeg( 1376, 768 ) );
			}
			$GLOBALS['aaw_test_gemini'][] = json_decode( (string) ( $args['body'] ?? '' ), true );
			if ( ! empty( $flags['gemini_fail'] ) ) {
				return array( 'status' => 503, 'headers' => array(), 'body' => '{"error":{"code":503,"message":"The model is overloaded.","status":"UNAVAILABLE"}}' );
			}
			if ( ! empty( $flags['gemini_free_tier'] ) ) {
				return array( 'status' => 429, 'headers' => array(), 'body' => '{"error":{"code":429,"message":"You exceeded your current quota, please check your plan and billing details. Quota exceeded for metric: generativelanguage.googleapis.com/generate_content_free_tier_requests, limit: 0, model: gemini-nano-banana-2.1","status":"RESOURCE_EXHAUSTED"}}' );
			}
			if ( str_contains( $url, '/v1beta/interactions' ) ) {
				$body = json_decode( $args['body'], true );
				if ( empty( $body['response_format']['type'] ) || 'image' !== $body['response_format']['type'] ) {
					return array( 'status' => 400, 'headers' => array(), 'body' => '{}' );
				}
				if ( ! empty( $flags['gemini_async'] ) ) {
					return array( 'status' => 200, 'headers' => array(), 'body' => wp_json_encode( array( 'id' => 'i-bg', 'object' => 'interaction', 'status' => 'in_progress', 'steps' => array() ) ) );
				}
				if ( ! empty( $flags['gemini_uri'] ) ) {
					return $done( array( array( 'type' => 'image', 'mime_type' => 'image/jpeg', 'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/img-1:download' ) ) );
				}
				return $done( array( $image ) );
			}
		}
		return array( 'status' => 404, 'headers' => array(), 'body' => 'not mocked' );
	};

	Links::$checker = static function ( string $url ): array {
		if ( str_contains( $url, 'broken-page' ) ) {
			return array( 'ok' => false, 'blocked' => false, 'status' => 404 );
		}
		if ( str_contains( $url, 'linkedin.com' ) ) {
			return array( 'ok' => false, 'blocked' => true, 'status' => 999 );
		}
		return array( 'ok' => true, 'blocked' => false, 'status' => 200 );
	};

}
