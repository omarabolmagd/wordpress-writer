# Ana Afdal Writer

A WordPress plugin for [anaafdal.com](https://anaafdal.com) that researches, writes, illustrates and links new Arabic posts with Claude, then saves them as **drafts** for a person to review and publish.

## What it does

1. **Suggests topics.** Claude searches the web for what Arabic learners and job seekers are looking for now, across the site's categories. Each suggestion comes with a keyphrase, a post type and a category, and is checked against published posts so the site doesn't repeat itself.
2. **Plans the article.** Claude researches the chosen topic with web search and proposes an outline. You approve or edit:
   - title, SEO title, meta description and keyphrase
   - the English slug
   - the post type: news update, "best of" list or full guide
   - the category: an existing one, or a new one created only when none fits
   - the author
   - the sections and the image plan for each
   - the FAQ questions
   - the internal links and the sources to cite
3. **Writes the post** in simple Modern Standard Arabic from the research notes only, then adds:
   - a featured image: a Gemini photo of a scene about the topic, with no text on it (for example, someone reviewing their CV for a jobs article). The scene is planned in the outline, and you can change it before approving.
   - in-article images from Pexels, Gemini (Nano Banana 2.1) or website screenshots, saved to the Media Library with Arabic alt text
   - 3–5 internal links with descriptive anchors, plus up to 2 "اقرأ أيضًا" boxes
   - 2–4 external links. Each must come from the research and is checked live; broken or unknown links are removed.
   - a Yoast FAQ block, which gives Google FAQPage schema
   - a summary and an invitation to the Telegram channel
   - Yoast focus keyphrase, SEO title, meta description and primary category
4. **Checks the draft.** Automatic checks run on length, link counts, images, alt text, meta description, keyphrase placement, slug and FAQ. Claude then reviews the draft for outdated facts, unsupported claims, dialect, keyword stuffing, off-topic links and formatting. The results show as a checklist on the draft and in the plugin.
5. **Suggests links from older posts.** It proposes up to 3 sentences in older posts that should link to the new one. Nothing changes until you approve, and approved links are only added once the new post is published.

It never publishes by itself.

## Requirements

- WordPress 6.6 or later. Built and tested on 7.1.3, the version anaafdal.com runs.
- PHP 8.1 or later, with GD and FreeType for the title cover, which is the last fallback for featured images.
- PHP `max_execution_time` of 300 seconds is best, or a host that lets plugins raise it. **Settings → Test connections** shows whether yours does.
- Yoast SEO, for the FAQ schema and SEO fields. The plugin still works without it.

## Install

1. Download `ana-afdal-writer.zip` from the latest release, or build it with `bin/build-zip.sh`.
2. In wp-admin, go to **Plugins → Add New → Upload Plugin**, choose the zip, then **Install** and **Activate**.
3. Open **Ana Afdal Writer → Settings** and add your API keys:
   - **Claude (Anthropic)**, required. Create it at [platform.claude.com](https://platform.claude.com).
   - **Google Gemini**, optional, for AI images. Create it at [aistudio.google.com](https://aistudio.google.com).
   - **Pexels**, optional and free, for stock photos. Create it at [pexels.com/api](https://www.pexels.com/api/).
   - **ScreenshotOne**, optional, for website screenshots. A free plan is available at [screenshotone.com](https://screenshotone.com).
4. Click **Test connections**, set the monthly spending cap, and check the Telegram link.

To keep keys out of the database, define them in `wp-config.php` instead:

```php
define( 'AAW_ANTHROPIC_API_KEY', '...' );
define( 'AAW_GEMINI_API_KEY', '...' );
define( 'AAW_PEXELS_API_KEY', '...' );
define( 'AAW_SCREENSHOT_API_KEY', '...' );
```

## Who can use it

- **Admins and Editors** can create posts with it.
- **Only Admins** can see settings or API keys. Keys are never sent to the browser.

## Costs

Every API call is logged with an estimated cost under **Ana Afdal Writer → Costs**. When the month's total reaches the cap, new topic suggestions and new posts stop; posts already in progress can finish.

Default prices come from the official pricing pages as of October 2026:
- Claude Sonnet 5.5: $2 input and $10 output per million tokens.
- Web search: $0.01 per search.
- Gemini Nano Banana 2.1: about $0.034 per image.

They are all editable in Settings. A rough estimate, not yet measured on real articles, is $0.30–$0.60 per article for research, writing and checks, plus about $0.03 per AI image, including the featured photo. The Costs page shows the real figures after your first few posts.

## Featured images

**Settings → Featured image** has three styles:

- **AI photo of the topic, made with Gemini** (the default). Claude describes a scene in the outline, such as a student following an online course or someone in a job interview. Gemini then makes a realistic photo of it, with no text, logos or readable screens, and with the subject near the centre so home-page crops still work.
- **Stock photo of the topic, from Pexels.**
- **Title written on the brand colour.** This is the style used before 1.3.0.

If the chosen source fails, the other photo source is tried, then the title cover. When that happens, the article page shows Google's reason above the featured image, and the quality checklist flags it.

**Gemini photos need a paid Gemini API plan.** Google doesn't offer its image models (Nano Banana 2.1, 2 and 2 Lite) on the free tier. With a free-tier key, every photo fails and the post gets the fallback image. To fix it, set up billing in Google AI Studio for the project the key belongs to. **Settings → Images → Make a test photo** makes one real photo and shows it, or shows the exact error. **Test connections** only checks that the key works, not that it can make images.

On a finished article, **Featured image → Make a new featured image** replaces the image with a new Gemini photo. You can type a different scene first. For articles planned before 1.3.0, Claude describes a scene from the title. The old image stays in the Media Library.

## Saved research

Every research step is saved under **Ana Afdal Writer → Saved research**: the topic searches and each article's research. Claude's raw search results come back encrypted and can only be read inside the request that made them, so the plugin saves what Claude wrote from them: the research notes (facts, dates, official links) and the list of sources.

- **Related articles start from saved notes.** When a new article's title and keyphrase are close to an earlier article's, Claude gets that research (up to 2 earlier articles). It then searches only for what the notes miss and for facts that may have changed, such as deadlines, prices and dates. For a near-identical topic, the search limit is also halved.
- **Closeness is measured on the words of the titles and keyphrases.** Arabic words are reduced to a common form, so "بشهادات" and "شهادة" match. Words that appear in many of the site's titles, such as "كورسات" or "مجانية", count for little; specific words such as "الذكاء الاصطناعي" or "ألمانيا" count for a lot.
- **Freshness.** News articles only reuse notes up to 7 days old. Guides and lists reuse notes up to 30 days old, which you can change in Settings. Notes are deleted after 180 days.
- **Topic ideas.** Asking for topics with the same focus within 3 days reuses that topic search with no new web searches, and suggests ideas that were not shown before. Untick **Reuse** to search again. Your choice is remembered in your browser. An article started from a suggestion also gets the notes of the topic search that suggested it.
- **Measured savings.** The Saved research page shows your average research cost per article, from scratch and with saved notes, from your own cost log.
- **Settings.** You can turn reuse off under **Settings → Reuse saved research**.

## The slug guard

Posts on anaafdal.com get random slugs such as `8313-vytuja`. The plugin keeps its English slug even if another plugin rewrites slugs on publish. It only overrides slugs that look machine-made. If an editor types a different slug on purpose, that slug is kept.

## Speed

**Settings → Speed** has two modes:

- **Fast** (the default). Claude answers without thinking silently first, at a lower effort level, so each step is several times quicker.
- **Thorough.** Claude thinks before research, planning and the quality check. It's slower and costs more.

Each step has its own output limit, sized for what it writes. If an answer still runs out of room, the plugin retries once with double the space.

## Live progress

Claude's answers are streamed, so the article screen shows what is happening as it happens:

- the searches Claude runs, with the number of results and the sites found
- a running word count and the current section while it writes
- which image is being made, the link checks and the save
- a note when Claude was busy and the plugin retried, or when the server stopped a step and it was restarted

**Cancel** stops the current call to Claude straight away, so nothing more is spent.

## How it runs

The plugin runs its own small queue. No other plugin is needed.

- **While the article page is open,** the page asks the server to run each step and waits for it. This is the fastest way, and it works on any host.
- **After you close the page,** steps continue in the background through a request the site sends to itself, with WP-Cron every minute as a fallback.
- **Every running step sends a heartbeat.** If the server kills a step, for example at its time limit, the step is restarted after about 2½ minutes. After 3 failed attempts the job stops and shows what to do.
- **Only one copy of a step runs at a time,** even when the page, the background request and WP-Cron all try to start it.

### LiteSpeed hosting

LiteSpeed stops PHP as soon as the request that started it disconnects, so background steps die when you close the page. **Test connections** reports this. On LiteSpeed, **Settings → Background work** shows these lines to add at the very top of `.htaccess`, in the WordPress folder:

```apache
# BEGIN Ana Afdal Writer
<IfModule Litespeed>
RewriteEngine On
RewriteRule ^wp-cron\.php$ - [E=noabort:1,E=noconntimeout:1]
RewriteCond %{QUERY_STRING} action=aaw_(worker|ping)
RewriteRule admin-ajax\.php$ - [E=noabort:1,E=noconntimeout:1]
</IfModule>
# END Ana Afdal Writer
```

Without them, everything still works while the article page stays open.

## Development

```
src/            PHP classes (namespace AnaAfdal\Writer)
admin/          admin screen JavaScript and CSS (no build step)
assets/fonts/   Tajawal Bold (SIL OFL) for covers
languages/      Arabic translation (.po/.mo/.json)
tests/          end-to-end, block-validation and browser tests, and the mocks they use
bin/            build and translation scripts
```

### Tests

All external APIs are mocked, so tests cost nothing and need no keys.

- **`tests/run.sh <wordpress-dir> <wp-cli.phar>`** rebuilds a local WordPress on SQLite, seeds it like anaafdal.com, and runs `tests/e2e.php`. That test covers:
  - the full pipeline, link validation, the slug guard, backlinks, the budget cap and permissions
  - the request sent to Claude for each step (effort, thinking, output limits)
  - streaming, live progress and word counts
  - retries when Claude is busy, an answer runs out of room or thinking settings are rejected
  - restarting a step the server killed, stopping after 3 attempts, and cancelling mid-stream
  - saved research:
    - which topics count as related
    - news freshness and the off switch
    - topic-search reuse
    - cleanup
    - filling the library from earlier jobs when updating
- **`node tests/validate-blocks.cjs <wordpress-dir> tests/output-content.html`** loads WordPress's own block-editor scripts and checks that every generated block is valid and matches what the editor would save. It needs `jsdom`.
- **`node tests/browser.cjs http://localhost:8080 screens [rtl]`** clicks through the whole flow in Chromium with Playwright and saves screenshots, including the live progress screens. To set it up:
  1. Copy `tests/mu-test-mocks.php` into `wp-content/mu-plugins/`.
  2. Set the `aaw_test_mocks` option to `1`. Also set `aaw_test_claude` to `server` to use the streaming mock, and `aaw_test_rtl` to `1` for Arabic.
  3. Run `tests/serve.sh <wordpress-dir>`. It starts WordPress on port 8080 and `tests/mock-anthropic-server.php` on port 8081. The mock streams answers slowly, the way the real API does; `AAW_MOCK_SEARCH_MS` and `AAW_MOCK_DELAY_MS` set how slowly.

### Translations

Run `python3 bin/translations-ar.py`, then `wp i18n make-mo languages` and `wp i18n make-json languages --no-purge`.

## Changelog

**1.3.2**
- The topics form keeps your choice. Unticking **Reuse** now stays unticked while the search runs and afterwards, and it is remembered next time. The focus you typed is kept too. Before, the form redrew with the box ticked again, even though the search really was a fresh one.

**1.3.1**
- The article page now shows why a featured photo wasn't made, in plain words, with Google's own message. The most common cause is a free-tier Gemini key, which can't make images.
- New **Make a test photo** button in Settings makes one real Gemini photo, or shows the exact error.
- Gemini errors from the main endpoint are no longer hidden behind the fallback endpoint's error.
- Photos that Google finishes in the background, or returns as a download link, are now handled.
- Cancel now works during image steps too.

**1.3.0**
- Featured images are now Gemini photos of a scene about the topic, with no text, instead of the title on a template. The scene is planned in the outline and can be edited before approving.
- New **Featured image** setting: Gemini photo (default), Pexels photo, or the old title cover. If a source fails, the plugin falls back and flags it in the checklist.
- New **Make a new featured image** button on finished articles, including ones written before this version.

**1.2.0**
- New **Saved research** library. Research notes and sources from every topic search and article are kept.
- Articles on related topics start from the saved notes and search only for what is missing or may have changed. A near-identical topic gets half the searches.
- Topic suggestions with the same focus within 3 days reuse the saved search with no new searches, and suggest ideas that were not shown before.
- The Saved research page lists the notes and lets you delete them. It also compares average research cost with and without saved notes.
- Updating adds the research of articles you have already written to the library.

**1.1.0**
- Steps are several times faster. Claude no longer thinks silently before every answer, and each step uses the effort it needs. The old behaviour is available as **Speed → Thorough**.
- Fixed "Claude ran out of output space" in planning. Output limits are larger, thinking no longer uses them up, and a cut-off answer is retried once with more room.
- Live progress on every step: searches, word count, sections, images and link checks.
- Steps run from the open page, with heartbeats and automatic restarts, instead of Action Scheduler. Background work on LiteSpeed is detected, with the `.htaccess` fix shown in Settings.
- Cancel stops the current call to Claude straight away.
- Source links are checked in parallel.

**1.0.0**
- First release.

## License

GPL-2.0-or-later. Tajawal is licensed under the SIL Open Font License 1.1.
