# Keyword Page Generator

> **v3.0.0** — Create many pages from one template by swapping keywords: one page per city, service or product. Only visible text changes; images, links and layout stay intact.

## How it works

1. Write one template page, e.g. "Plumber in Melbourne".
2. Enter keywords and their replacements: `Melbourne` → Sydney, Brisbane, Perth; `Plumber` → Electrician, Roofer.
3. Choose every combination (6 pages) or each keyword separately, check the live list of titles, preview the first page, and generate.

Each copy gets the template's content, excerpt, featured image, page template, categories/tags, SEO fields (Yoast etc.), custom fields (ACF etc.) and Elementor data, with the keywords swapped.

## Replacement rules

- Only visible text: HTML text, `alt`/`title` attributes, text-like block attributes, and text attributes of builder shortcodes. URLs, file names, classes and IDs are never touched.
- Whole words, with plural/possessive endings (`plumbers` → `electricians`), case kept (`MELBOURNE` → `SYDNEY`).
- One pass for all pairs, so a replacement value is never replaced again.
- Meta is copied with correct slashing, so JSON (Elementor) stays valid.

## Features

- Block editor (including nested Groups/Columns), Classic Editor, Elementor, Divi, WPBakery
- Drafts or publish, preview draft, progress bar with stop, background or scheduled runs (site time zone)
- History with one-click undo (moves a run's pages to Trash)
- CSV import (first row = keywords, rows below = values)
- Optional AI rewriting via Anthropic, OpenAI or Gemini with your own key; rewritten fragments that change tags or links are rejected
- Up to 1,000 pages per run

## Install

Download the release zip, or copy the plugin into `/wp-content/plugins/keyword-page-generator/`, activate, then open **Tools → Page Generator**.

## License

GPL-2.0-or-later
