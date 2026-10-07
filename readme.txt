=== Keyword Page Generator ===
Contributors: wisnuub
Tags: page generator, bulk pages, location pages, duplicate page, programmatic seo
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create many pages from one template by swapping keywords — one page per city, service or product. Images, links and layout stay intact.

== Description ==

Write one template page — say, "Plumber in Melbourne" — then list the cities and services you need. Keyword Page Generator creates a copy for each one, with the keywords swapped in the title, URL, content, SEO fields and custom fields.

= Safe find-and-replace =

Only visible text is changed. Image file names, links, CSS classes and builder settings are left alone, so replacing "Melbourne" never turns `melbourne-office.jpg` into a missing `sydney-office.jpg`.

* Whole words only — "Melbourne" doesn't match inside "Melbournian" — with plurals handled: "plumbers" becomes "electricians".
* Capitalisation is kept: MELBOURNE → SYDNEY, melbourne → sydney.
* All keywords are swapped in one pass, so a new value is never replaced again by another keyword.

= What gets copied =

Content and excerpt, featured image, page template, categories and tags, Yoast and other SEO fields, custom fields (including ACF), and Elementor page data.

= Features =

* **Every combination** (3 cities × 4 services = 12 pages) or **each keyword separately**
* Live list of the page titles that will be created, and a warning if a keyword isn't in the template
* **Preview** the first page as a draft before generating
* Save pages as **drafts** or publish immediately
* Progress bar with a stop button; long runs can go to the **background** or be **scheduled** (in your site's time zone)
* **Undo**: move every page from a run to the Trash in one click
* CSV import for long keyword lists
* Works with the block editor (including Groups and Columns), Classic Editor, Elementor, Divi and WPBakery
* Optional **AI rewriting** so pages aren't near-duplicates (bring your own API key)

= A note on SEO =

Search engines treat large numbers of near-identical pages as low quality. Use this to save typing, then give each page something genuinely specific — a local photo, a review, opening hours, a map — before you publish.

== External services ==

AI rewriting is off by default and only runs when you add an API key and tick "Rewrite the text of each page with AI" for a run. When it runs, the text of each generated page (paragraphs, headings, list items and Elementor text widgets) and the page's keywords are sent to the provider you chose, once per generated page. Nothing is sent otherwise.

* **Anthropic (Claude)** — https://api.anthropic.com — [Terms](https://www.anthropic.com/legal/commercial-terms), [Privacy policy](https://www.anthropic.com/legal/privacy)
* **OpenAI** — https://api.openai.com — [Terms](https://openai.com/policies/terms-of-use), [Privacy policy](https://openai.com/policies/privacy-policy)
* **Google Gemini** — https://generativelanguage.googleapis.com — [Terms](https://ai.google.dev/gemini-api/terms), [Privacy policy](https://policies.google.com/privacy)

Usage is billed by the provider to your own account.

== Installation ==

1. Install from **Plugins → Add New**, or upload the plugin to `/wp-content/plugins/`.
2. Activate it.
3. Go to **Tools → Page Generator**.

== Frequently Asked Questions ==

= How do I write the template? =

Write it as a normal page for one case, using the exact words you want swapped — for example "Melbourne" and "Plumber". Then enter "Melbourne" as a keyword and the other cities as its values.

= Can I undo a run? =

Yes. On the History tab, click "Undo — move to Trash". The pages can still be restored from the Trash.

= Is there a limit? =

Up to 1,000 pages per run, to protect you from accidental huge combinations. Split bigger jobs into several runs.

= What does AI rewriting change? =

Only the wording of each paragraph, heading and list item. Tags, links and images are checked after the rewrite; if the model changed any of them, that paragraph keeps the original text. Facts like names, prices and phone numbers are kept.

= Where is my API key stored? =

In your WordPress database, encrypted with your site's security keys.

== Screenshots ==

1. Template, keywords and the live list of pages to create
2. Progress while generating
3. History with one-click undo
4. AI rewriting settings

== Changelog ==

= 3.0.0 =
* Rewritten from the ground up.
* Keyword replacement now changes only visible text: image URLs, links and classes are no longer altered.
* Whole-word matching with plurals, case preservation, and single-pass replacement.
* Accented and non-Latin keyword values are no longer silently dropped.
* Elementor pages are copied without corrupting their data; the old one-time "Elementor meta normalizer" that modified every Elementor page on activation has been removed.
* AI rewriting works inside Groups and Columns, checks that tags and links are unchanged, and supports current Claude, OpenAI and Gemini models.
* New: drafts, live title list, missing-keyword warning, History with undo, CSV import, any public post type.
* Scheduled runs use the site's time zone.
* Moved to Tools → Page Generator.

= 2.3 =
* Tabs, Gemini support, AI rewrite scope.
