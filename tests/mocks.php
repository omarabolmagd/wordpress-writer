<?php
/**
 * Mocks for every external API the plugin calls, shared by the end-to-end test
 * and the browser test. Nothing here leaves the machine.
 */

use AnaAfdal\Writer\Claude;
use AnaAfdal\Writer\Http;
use AnaAfdal\Writer\Links;

$GLOBALS['aaw_test_calls'] = $GLOBALS['aaw_test_calls'] ?? array();

function aaw_test_install_mocks(): void {
	$calls = &$GLOBALS['aaw_test_calls'];
	// ------------------------------------------------------------------ mocks
	$jpeg = static function ( int $w = 1200, int $h = 675 ): string {
		$im = imagecreatetruecolor( $w, $h );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 40, 120, 160 ) );
		ob_start();
		imagejpeg( $im, null, 80 );
		return (string) ob_get_clean();
	};

	$published = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) );
	$sources   = array(
		'https://www.coursera.org/learn/ai-for-everyone'    => 'AI For Everyone | Coursera',
		'https://www.deeplearning.ai/courses/'              => 'Courses - DeepLearning.AI',
		'https://grow.google/certificates/'                 => 'Google Career Certificates',
		'https://www.coursera.org/broken-page'              => 'Old page',
		'https://www.linkedin.com/learning/'                => 'LinkedIn Learning',
	);

	$calls = array();
	Claude::$transport = static function ( array $body ) use ( &$calls, $published, $sources ) {
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
		if ( str_contains( $url, '/v1beta/interactions' ) ) {
			$body = json_decode( $args['body'], true );
			if ( empty( $body['response_format']['type'] ) || 'image' !== $body['response_format']['type'] ) {
				return array( 'status' => 400, 'headers' => array(), 'body' => '{}' );
			}
			return array( 'status' => 200, 'headers' => array(), 'body' => wp_json_encode( array( 'id' => 'i1', 'status' => 'completed', 'steps' => array( array( 'type' => 'model_output', 'content' => array( array( 'type' => 'image', 'mime_type' => 'image/jpeg', 'data' => base64_encode( $jpeg( 1376, 768 ) ) ) ) ) ) ) ) );
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
