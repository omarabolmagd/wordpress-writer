<?php
namespace AnaAfdal\Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Every instruction the plugin gives Claude, plus the JSON schemas for structured replies.
 */
final class Prompts {

	/** The three post templates chosen at the outline step. */
	public static function templates(): array {
		return array(
			'news'  => array(
				'label'    => 'تحديث إخباري',
				'words'    => array( 600, 900 ),
				'sections' => '3 to 4',
				'faq'      => '3',
				'rules'    => 'A timely update: what changed, who it affects, what the reader should do now. No comparison table unless the news itself is a comparison.',
			),
			'list'  => array(
				'label'    => 'قائمة "أفضل X"',
				'words'    => array( 1200, 1800 ),
				'sections' => '5 to 9 (one per item, plus how to choose)',
				'faq'      => '4 to 5',
				'rules'    => 'A ranked or grouped list. Every item gets: what it is, who it suits, price or free status, and one honest limitation. Include exactly one comparison table that summarises all items.',
			),
			'guide' => array(
				'label'    => 'دليل شامل',
				'words'    => array( 1800, 2500 ),
				'sections' => '5 to 8',
				'faq'      => '5 to 6',
				'rules'    => 'A complete how-to. Use numbered steps where order matters, give concrete examples, and warn about common mistakes. A table is welcome where it helps compare options.',
			),
		);
	}

	public static function today(): string {
		return wp_date( 'F Y' );
	}

	private static function site_context(): string {
		return 'You work for "انا افضل" (anaafdal.com), an Arabic website that helps students, job seekers and early-career readers across the Arab world. '
			. 'It publishes practical guides about learning English, online courses, useful websites, useful software and AI tools, and scholarships and jobs. '
			. 'Readers come mostly from Egypt, the Gulf, the Levant and North Africa, usually from Google search on their phones. Today is ' . self::today() . '.';
	}

	/** Shared Arabic writing rules for anything the reader will see. */
	public static function voice_rules(): string {
		return <<<'TXT'
Arabic writing rules (follow all of them):
- Write in simple Modern Standard Arabic (فصحى مبسطة) that any Arab reader understands. Address the reader directly (أنت).
- Never use dialect words such as: ده، دي، عايز، إزاي، كده، مش، هتعمل، بتاع، اللي، فين، ليه، شنو، شلون، هيك. Use their MSA equivalents.
- Short paragraphs of 2 to 4 sentences. Prefer short, clear sentences. No filler openings like "في عالم يتسارع فيه التطور".
- Keep product and brand names in Latin script (ChatGPT, Coursera, Google Gemini). Explain technical terms briefly the first time.
- Be specific and practical: steps, examples, prices, requirements, who it suits. No vague praise.
- Accuracy: use only facts that appear in the research notes. Never invent statistics, prices, dates, version numbers, quotes or URLs. When a fact can change (prices, versions, deadlines), say it is correct as of the current month and year.
- Do not use emojis. Do not use exclamation marks more than once in the whole article.
- Use the focus keyphrase naturally: in the title, the first paragraph, one H2 heading and the meta description. Elsewhere use natural variations. Never stuff it.
TXT;
	}

	/* ------------------------------------------------------------------ topics */

	public static function topics_research( array $existing_titles, array $categories, string $focus ): array {
		$system = self::site_context() . "\nYou are the site's content strategist. Use web search to find what Arabic-speaking readers are searching for right now in the site's topics.";

		$prompt  = "Research topic ideas for new articles.\n\n";
		$prompt .= "Site categories:\n- " . implode( "\n- ", $categories ) . "\n\n";
		if ( '' !== trim( $focus ) ) {
			$prompt .= "The editor asked to focus on: {$focus}\n\n";
		}
		$prompt .= "Articles already published (avoid repeating these angles):\n- " . implode( "\n- ", array_slice( $existing_titles, 0, 150 ) ) . "\n\n";
		$prompt .= "Search for recent, specific demand: new courses and certificates (Coursera, Google, edX, Udemy free offers), newly opened scholarships and deadlines, AI tools and updates people are asking about, English-learning resources, job-search platforms and remote work in the Arab world, and useful software or websites. Prefer things that changed in the last few months, plus evergreen questions with high search interest that the site has not covered.\n\n";
		$prompt .= 'Write concise research notes: for each promising idea give the topic, why it matters now, the likely Arabic search phrase people would type, the best category, and which existing article (if any) it overlaps with. Aim for 10 to 12 ideas.';

		return array( $system, $prompt );
	}

	public static function topics_structure( string $notes, array $categories_with_ids, array $existing_titles ): array {
		$system = self::site_context() . "\nYou turn research notes into a clean list of article suggestions.";

		$cats = array();
		foreach ( $categories_with_ids as $id => $name ) {
			$cats[] = "{$id}: {$name}";
		}

		$prompt  = "Research notes:\n{$notes}\n\n";
		$prompt .= "Existing categories (id: name):\n" . implode( "\n", $cats ) . "\n\n";
		$prompt .= "Already published titles:\n- " . implode( "\n- ", array_slice( $existing_titles, 0, 150 ) ) . "\n\n";
		$prompt .= "Pick the 6 strongest article ideas. Drop any idea that substantially repeats a published title. For each:\n";
		$prompt .= "- title: an Arabic article title, specific and click-worthy, no clickbait, no emojis.\n";
		$prompt .= "- focus_keyphrase: the Arabic phrase readers would search (2 to 5 words).\n";
		$prompt .= "- post_type: news (timely update), list (\"best X\" list) or guide (complete how-to).\n";
		$prompt .= "- category_existing_id: the id of the existing category that truly fits the content, or null if none fits.\n";
		$prompt .= "- category_new_name: only when no existing category fits, a short Arabic category name (for example \"ذكاء اصطناعي\"); otherwise null.\n";
		$prompt .= "- why_now: one Arabic sentence on why this is worth writing now.\n";
		$prompt .= "- overlap_note: if a published title is close, name it and explain the different angle in Arabic; otherwise an empty string.\n";

		return array( $system, $prompt );
	}

	public static function topics_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'topics' ),
			'properties'           => array(
				'topics' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'title', 'focus_keyphrase', 'post_type', 'category_existing_id', 'category_new_name', 'why_now', 'overlap_note' ),
						'properties'           => array(
							'title'                => array( 'type' => 'string' ),
							'focus_keyphrase'      => array( 'type' => 'string' ),
							'post_type'            => array( 'type' => 'string', 'enum' => array( 'news', 'list', 'guide' ) ),
							'category_existing_id' => array( 'anyOf' => array( array( 'type' => 'integer' ), array( 'type' => 'null' ) ) ),
							'category_new_name'    => array( 'anyOf' => array( array( 'type' => 'string' ), array( 'type' => 'null' ) ) ),
							'why_now'              => array( 'type' => 'string' ),
							'overlap_note'         => array( 'type' => 'string' ),
						),
					),
				),
			),
		);
	}

	/* --------------------------------------------------------------- research */

	public static function article_research( array $topic ): array {
		$system = self::site_context() . "\nYou are a careful researcher preparing notes for an Arabic writer. Use web search. Prefer official sources (the product's own site, the university or scholarship page, official documentation, reputable news). Record exact facts with dates.";

		$prompt  = "Research this article so the writer has accurate, current facts.\n\n";
		$prompt .= 'Working title: ' . $topic['title'] . "\n";
		$prompt .= 'Focus keyphrase: ' . $topic['focus_keyphrase'] . "\n";
		$prompt .= 'Article type: ' . $topic['post_type'] . "\n";
		if ( ! empty( $topic['notes'] ) ) {
			$prompt .= 'Editor notes: ' . $topic['notes'] . "\n";
		}
		$prompt .= "\nWrite research notes in English with:\n";
		$prompt .= "1. Key facts the article needs (names, what each thing does, prices or free status, requirements, deadlines, steps), each with its source.\n";
		$prompt .= "2. Anything that changed recently, with dates, and anything widely believed that is now outdated.\n";
		$prompt .= "3. For each tool, website or service the article will cover, its official URL.\n";
		$prompt .= "4. Questions readers commonly ask about this topic.\n";
		$prompt .= 'Do not write the article.';

		return array( $system, $prompt );
	}

	/* ---------------------------------------------------------------- outline */

	public static function outline( array $topic, string $notes, array $sources, array $categories_with_ids, array $link_index, array $image_sources ): array {
		$templates = self::templates();
		$system    = self::site_context() . "\nYou are the site's editor. You plan articles that rank on Google and genuinely help the reader.\n\n" . self::voice_rules();

		$cats = array();
		foreach ( $categories_with_ids as $id => $name ) {
			$cats[] = "{$id}: {$name}";
		}
		$posts = array();
		foreach ( $link_index as $p ) {
			$posts[] = $p['id'] . ': ' . $p['title'] . ' [' . implode( '، ', $p['categories'] ) . ']';
		}
		$src = array();
		foreach ( $sources as $s ) {
			$src[] = $s['url'] . ' — ' . $s['title'];
		}

		$prompt  = "Plan an article.\n\n";
		$prompt .= 'Working title: ' . $topic['title'] . "\nFocus keyphrase: " . $topic['focus_keyphrase'] . "\nSuggested type: " . $topic['post_type'] . "\n";
		if ( ! empty( $topic['category_existing_id'] ) && isset( $categories_with_ids[ (int) $topic['category_existing_id'] ] ) ) {
			$prompt .= 'Suggested category: ' . (int) $topic['category_existing_id'] . ' (' . $categories_with_ids[ (int) $topic['category_existing_id'] ] . ")\n";
		} elseif ( ! empty( $topic['category_new_name'] ) ) {
			$prompt .= 'Suggested new category: ' . $topic['category_new_name'] . "\n";
		}
		if ( ! empty( $topic['notes'] ) ) {
			$prompt .= 'Editor notes: ' . $topic['notes'] . "\n";
		}
		$prompt .= "\nResearch notes:\n{$notes}\n\n";
		$prompt .= "Sources found during research (url — title):\n" . implode( "\n", $src ) . "\n\n";
		$prompt .= "Templates:\n";
		foreach ( $templates as $key => $t ) {
			$prompt .= "- {$key}: {$t['words'][0]}–{$t['words'][1]} words, {$t['sections']} H2 sections, {$t['faq']} FAQ questions. {$t['rules']}\n";
		}
		$prompt .= "\nExisting categories (id: name):\n" . implode( "\n", $cats ) . "\n\n";
		$prompt .= "Published posts you can link to (id: title [categories]):\n" . implode( "\n", $posts ) . "\n\n";
		$prompt .= "Image sources available: " . implode( ', ', $image_sources ) . ".\n\n";
		$prompt .= "Return the plan:\n";
		$prompt .= "- title: final Arabic H1 title containing the focus keyphrase, under 70 characters if possible. Add the year only if the content is time-sensitive.\n";
		$prompt .= "- seo_title: Arabic title for Google, under 60 characters, may differ slightly from the H1.\n";
		$prompt .= "- meta_description: Arabic, 120 to 155 characters, includes the keyphrase, tells the reader what they get.\n";
		$prompt .= "- focus_keyphrase: final Arabic keyphrase.\n";
		$prompt .= "- slug: short English URL slug, lowercase words joined by hyphens, 3 to 6 words, no year unless essential (for example free-ai-courses-coursera).\n";
		$prompt .= "- post_type: news, list or guide.\n";
		$prompt .= "- category_existing_id: the existing category that truly matches the article's subject, or null if none fits. category_new_name: a short Arabic name only when none fits, otherwise null. Do not force AI or software topics into the courses category.\n";
		$prompt .= "- angle: one Arabic sentence on what the reader gets that other articles do not give.\n";
		$prompt .= "- sections: the H2 sections in order (do not include the introduction, FAQ or conclusion; those are added automatically). For each: heading (Arabic), key_points (3 to 6 short notes in English for the writer), and an image plan.\n";
		$prompt .= "  Image plan rules: plan an image for about every second section and never more than 5 images in total. Use source \"screenshot\" when the section reviews one specific website or app (put its official URL in url). Use \"ai\" for abstract ideas with no good stock photo (put an English prompt describing a clean editorial illustration in prompt; never ask for text, letters or logos in the image). Use \"pexels\" for real-world scenes (put a 2 to 4 word English search query in query). Use \"none\" for no image. Only use sources from the available list. alt is a short Arabic description of what the image will show. Leave unused fields as empty strings.\n";
		$prompt .= "- faq_questions: Arabic questions readers really ask, in the number the template needs.\n";
		$prompt .= "- internal_link_ids: 3 to 5 ids of published posts that are genuinely related and will be linked in the text. Only ids from the list above.\n";
		$prompt .= "- external_sources: 2 to 4 URLs from the sources list above that the article will cite: official sites first. Only URLs from that list.\n";

		return array( $system, $prompt );
	}

	public static function outline_schema(): array {
		$nullable_int    = array( 'anyOf' => array( array( 'type' => 'integer' ), array( 'type' => 'null' ) ) );
		$nullable_string = array( 'anyOf' => array( array( 'type' => 'string' ), array( 'type' => 'null' ) ) );
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'title', 'seo_title', 'meta_description', 'focus_keyphrase', 'slug', 'post_type', 'category_existing_id', 'category_new_name', 'angle', 'sections', 'faq_questions', 'internal_link_ids', 'external_sources' ),
			'properties'           => array(
				'title'                => array( 'type' => 'string' ),
				'seo_title'            => array( 'type' => 'string' ),
				'meta_description'     => array( 'type' => 'string' ),
				'focus_keyphrase'      => array( 'type' => 'string' ),
				'slug'                 => array( 'type' => 'string' ),
				'post_type'            => array( 'type' => 'string', 'enum' => array( 'news', 'list', 'guide' ) ),
				'category_existing_id' => $nullable_int,
				'category_new_name'    => $nullable_string,
				'angle'                => array( 'type' => 'string' ),
				'sections'             => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'heading', 'key_points', 'image' ),
						'properties'           => array(
							'heading'    => array( 'type' => 'string' ),
							'key_points' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
							'image'      => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'required'             => array( 'source', 'query', 'prompt', 'url', 'alt' ),
								'properties'           => array(
									'source' => array( 'type' => 'string', 'enum' => array( 'pexels', 'ai', 'screenshot', 'none' ) ),
									'query'  => array( 'type' => 'string' ),
									'prompt' => array( 'type' => 'string' ),
									'url'    => array( 'type' => 'string' ),
									'alt'    => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'faq_questions'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'internal_link_ids'    => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'external_sources'     => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			),
		);
	}

	/* ------------------------------------------------------------------ draft */

	public static function draft( array $outline, string $notes, array $link_posts, array $external_sources ): array {
		$t      = self::templates()[ $outline['post_type'] ] ?? self::templates()['guide'];
		$system = self::site_context() . "\nYou are the site's senior Arabic writer.\n\n" . self::voice_rules() . "\n\n" . <<<'TXT'
Formatting rules for your JSON reply:
- All reader-facing text is Arabic.
- Inside any text you may use **bold** for a few key phrases, and links written as [anchor text](target).
- Internal links: target is post:ID using only the ids provided. Example: [دليل كتابة السيرة الذاتية](post:8089).
- External links: target is a full URL copied exactly from the allowed sources list. Never invent or change a URL.
- Anchor text must describe the destination (for example "منصة Coursera" or "الموقع الرسمي للمنحة"). Never use "اضغط هنا" or "هنا" or a bare URL as anchor text.
- Spread the links naturally through the body; at most one link per paragraph. Do not put links in headings or in FAQ answers.
- Do not add a call to action, newsletter or Telegram invitation; it is added automatically.
TXT;

		$posts = array();
		foreach ( $link_posts as $p ) {
			$posts[] = 'post:' . $p['id'] . ' — ' . $p['title'] . ' — ' . $p['excerpt'];
		}

		$sections = array();
		foreach ( $outline['sections'] as $i => $section ) {
			$sections[] = ( $i + 1 ) . '. ' . $section['heading'] . "\n   notes: " . implode( '; ', $section['key_points'] );
		}

		$prompt  = 'Write the full article from this approved outline.' . "\n\n";
		$prompt .= 'Title (H1): ' . $outline['title'] . "\nFocus keyphrase: " . $outline['focus_keyphrase'] . "\nAngle: " . $outline['angle'] . "\n";
		$prompt .= "Template: {$outline['post_type']} — {$t['words'][0]} to {$t['words'][1]} words in total. {$t['rules']}\n\n";
		$prompt .= "Sections, in this exact order and with these exact headings:\n" . implode( "\n", $sections ) . "\n\n";
		$prompt .= "FAQ questions to answer (2 to 4 sentences each, self-contained):\n- " . implode( "\n- ", $outline['faq_questions'] ) . "\n\n";
		$prompt .= "Research notes (the only source of facts):\n{$notes}\n\n";
		$prompt .= "Internal posts to link (include 3 to 5 of these inside the body text):\n" . implode( "\n", $posts ) . "\n\n";
		$prompt .= "Allowed external sources (cite 2 to 4 of these inside the body text):\n" . implode( "\n", $external_sources ) . "\n\n";
		$prompt .= "Return:\n";
		$prompt .= "- intro: 2 or 3 paragraphs. The first paragraph contains the focus keyphrase and tells the reader what they will get.\n";
		$prompt .= "- sections: one entry per outline section, same order, heading copied exactly. blocks is the section body: paragraphs, optional subheadings (H3), bulleted or numbered lists, tables (headers and rows of plain text), and at most one read_more block per article pointing to a related post id from the list.\n";
		$prompt .= "- faq: the questions above with their answers.\n";
		$prompt .= '- summary: 1 or 2 short paragraphs that wrap up the article and tell the reader what to do next.';

		return array( $system, $prompt );
	}

	public static function draft_schema(): array {
		$text_block = static function ( string $kind ): array {
			return array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'kind', 'text' ),
				'properties'           => array(
					'kind' => array( 'type' => 'string', 'enum' => array( $kind ) ),
					'text' => array( 'type' => 'string' ),
				),
			);
		};
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'intro', 'sections', 'faq', 'summary' ),
			'properties'           => array(
				'intro'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'sections' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'heading', 'blocks' ),
						'properties'           => array(
							'heading' => array( 'type' => 'string' ),
							'blocks'  => array(
								'type'  => 'array',
								'items' => array(
									'anyOf' => array(
										$text_block( 'paragraph' ),
										$text_block( 'subheading' ),
										array(
											'type'                 => 'object',
											'additionalProperties' => false,
											'required'             => array( 'kind', 'ordered', 'items' ),
											'properties'           => array(
												'kind'    => array( 'type' => 'string', 'enum' => array( 'list' ) ),
												'ordered' => array( 'type' => 'boolean' ),
												'items'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
											),
										),
										array(
											'type'                 => 'object',
											'additionalProperties' => false,
											'required'             => array( 'kind', 'headers', 'rows' ),
											'properties'           => array(
												'kind'    => array( 'type' => 'string', 'enum' => array( 'table' ) ),
												'headers' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
												'rows'    => array(
													'type'  => 'array',
													'items' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
												),
											),
										),
										array(
											'type'                 => 'object',
											'additionalProperties' => false,
											'required'             => array( 'kind', 'post_id' ),
											'properties'           => array(
												'kind'    => array( 'type' => 'string', 'enum' => array( 'read_more' ) ),
												'post_id' => array( 'type' => 'integer' ),
											),
										),
									),
								),
							),
						),
					),
				),
				'faq'      => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'question', 'answer' ),
						'properties'           => array(
							'question' => array( 'type' => 'string' ),
							'answer'   => array( 'type' => 'string' ),
						),
					),
				),
				'summary'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			),
		);
	}

	/* ---------------------------------------------------------------- quality */

	public static function quality( string $article_text, array $outline, string $notes ): array {
		$system = self::site_context() . "\nYou are a strict Arabic copy editor and fact checker. You review drafts before a human editor publishes them.";

		$prompt  = "Review this draft. Today is " . self::today() . ".\n\n";
		$prompt .= 'Focus keyphrase: ' . $outline['focus_keyphrase'] . "\n\n";
		$prompt .= "Research notes the writer was given (the only trusted facts):\n{$notes}\n\n";
		$prompt .= "Draft (links shown as [anchor](url)):\n{$article_text}\n\n";
		$prompt .= "Return one item for each check below. status is pass or warn. note is in Arabic, short and specific: quote the problem sentence or phrase and say what to change. For pass, note can be empty.\n";
		$prompt .= "- outdated_facts: claims that are out of date as of today (old model or product versions, past deadlines, old prices).\n";
		$prompt .= "- unsupported_claims: facts, numbers or prices not backed by the research notes.\n";
		$prompt .= "- dialect: any dialect words or phrases instead of Modern Standard Arabic.\n";
		$prompt .= "- keyword_stuffing: the keyphrase repeated unnaturally.\n";
		$prompt .= "- off_topic_links: links whose anchor text or destination does not match the sentence.\n";
		$prompt .= "- title_match: the title promises something the article does not deliver.\n";
		$prompt .= "- repetition: repeated ideas or padding.\n";
		$prompt .= '- formatting: broken lists, empty sections, headings that do not match their content.';

		return array( $system, $prompt );
	}

	public static function quality_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'items' ),
			'properties'           => array(
				'items' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'check', 'status', 'note' ),
						'properties'           => array(
							'check'  => array( 'type' => 'string', 'enum' => array( 'outdated_facts', 'unsupported_claims', 'dialect', 'keyword_stuffing', 'off_topic_links', 'title_match', 'repetition', 'formatting' ) ),
							'status' => array( 'type' => 'string', 'enum' => array( 'pass', 'warn' ) ),
							'note'   => array( 'type' => 'string' ),
						),
					),
				),
			),
		);
	}

	/* -------------------------------------------------------------- backlinks */

	public static function backlinks( array $new_post, array $candidates ): array {
		$system = self::site_context() . "\nYou are an SEO editor. You choose where older articles should link to a new article.";

		$prompt  = "New article:\nTitle: {$new_post['title']}\nFocus keyphrase: {$new_post['keyphrase']}\nSummary: {$new_post['summary']}\n\n";
		$prompt .= "Older articles and their paragraphs (post id, then numbered paragraphs):\n";
		foreach ( $candidates as $c ) {
			$prompt .= "\n=== post {$c['id']}: {$c['title']}\n";
			foreach ( $c['paragraphs'] as $i => $p ) {
				$prompt .= "[{$i}] {$p}\n";
			}
		}
		$prompt .= "\nChoose up to 3 places, each in a different older article, where a link to the new article genuinely helps the reader. For each give post_id, paragraph_index, anchor (an exact phrase of 2 to 7 words copied character for character from that paragraph, describing the new article's subject) and reason (one short Arabic sentence). Return fewer or none if no place fits naturally.";

		return array( $system, $prompt );
	}

	public static function backlinks_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'suggestions' ),
			'properties'           => array(
				'suggestions' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'post_id', 'paragraph_index', 'anchor', 'reason' ),
						'properties'           => array(
							'post_id'         => array( 'type' => 'integer' ),
							'paragraph_index' => array( 'type' => 'integer' ),
							'anchor'          => array( 'type' => 'string' ),
							'reason'          => array( 'type' => 'string' ),
						),
					),
				),
			),
		);
	}

	/* ------------------------------------------------------------------ images */

	public static function ai_image( string $prompt ): string {
		$color = Settings::get( 'brand_color' );
		return trim( $prompt ) . '. Style: clean modern editorial illustration for an educational website, soft natural lighting, simple composition with one clear subject, subtle accents in teal (' . $color . '). Absolutely no text, letters, numbers, words, logos or watermarks anywhere in the image. Landscape 16:9.';
	}
}
