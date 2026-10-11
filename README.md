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
   - a branded cover image, drawn by the plugin (Arabic title on the site's teal)
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
- PHP 8.1 or later, with GD and FreeType for the cover image.
- PHP `max_execution_time` of at least 240 seconds, or a host that lets plugins raise it. **Settings → Test connections** shows whether yours does.
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

They are all editable in Settings. A rough estimate, not yet measured on real articles, is $0.30–$0.60 per article for research, writing and checks, plus about $0.03 per AI image. The Costs page shows the real figures after your first few posts.

## The slug guard

Posts on anaafdal.com get random slugs such as `8313-vytuja`. The plugin keeps its English slug even if another plugin rewrites slugs on publish. It only overrides slugs that look machine-made. If an editor types a different slug on purpose, that slug is kept.

## How it runs

Each step runs in the background through [Action Scheduler](https://actionscheduler.org/), which is bundled. A non-blocking request starts the next step immediately, and WP-Cron is the fallback. A step that dies, for example because the host killed it, is shown as failed after 15 minutes with a **Try again** button.

## Development

```
src/            PHP classes (namespace AnaAfdal\Writer)
admin/          admin screen JavaScript and CSS (no build step)
assets/fonts/   Tajawal Bold (SIL OFL) for covers
languages/      Arabic translation (.po/.mo/.json)
lib/            Action Scheduler 4.2.0 (GPL)
tests/          end-to-end, block-validation and browser tests
bin/            build and translation scripts
```

### Tests

All external APIs are mocked, so tests cost nothing and need no keys.

- **`tests/run.sh <wordpress-dir> <wp-cli.phar>`** rebuilds a local WordPress on SQLite, seeds it like anaafdal.com, and runs `tests/e2e.php`. That test covers the full pipeline, link validation, the slug guard, backlinks, the budget cap, permissions and failure recovery.
- **`node tests/validate-blocks.cjs <wordpress-dir> tests/output-content.html`** loads WordPress's own block-editor scripts and checks that every generated block is valid and matches what the editor would save. It needs `jsdom`.
- **`node tests/browser.cjs http://localhost:8080 screens [rtl]`** clicks through the whole flow in Chromium with Playwright and saves screenshots. Serve WordPress with `PHP_CLI_SERVER_WORKERS=4 php -S localhost:8080 -t <wordpress-dir> tests/router.php`, and enable `tests/mu-test-mocks.php` with the `aaw_test_mocks` option.

### Translations

Run `python3 bin/translations-ar.py`, then `wp i18n make-mo languages` and `wp i18n make-json languages --no-purge`.

## License

GPL-2.0-or-later. Tajawal is licensed under the SIL Open Font License 1.1.
