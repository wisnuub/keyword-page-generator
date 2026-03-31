# Keyword Page Generator

> **v2.3** — Generate multiple page/post variations by replacing keywords across your content, with optional AI-powered unique rewriting.

A free WordPress plugin that duplicates a base page or post by replacing one or more keywords with a list of alternatives — perfect for location pages, service variations, or any repeatable content pattern.

## Features

- Multi-keyword replacement with up to 5 pairs per generation
- **Matrix mode** — cross-product of all keyword pairs (e.g., 3 locations × 4 services = 12 pages)
- **Independent mode** — generate pages for each pair separately
- Supports both Pages and Blog Posts
- AI content rewriting via **OpenAI**, **Anthropic (Claude)**, or **Google Gemini**
- **Rewrite scope** — Widget/Block level or Section/Row level for Elementor
- Builder auto-detection: Elementor, Divi, WPBakery, Gutenberg, Classic Editor
- Builder-aware AI extraction — preserves page builder layouts and structure
- Encrypted API key storage (AES-256-CBC)
- Live page counter with limit warnings
- Preview first page before bulk generating
- Recursive replacement in serialized post meta (ACF, Yoast, page builder data)
- Longest-match-first replacement to avoid partial matches
- CSV import for keyword lists (bulk or per-pair)
- AJAX batch processing with live progress bar and cancel button
- Scheduled generation via WP-Cron (background queue or timed)
- Slug sanitization with collision detection

## UI Overview

The plugin uses a single admin page with three tabs:

| Tab | Description |
|-----|-------------|
| **⚡ Generate** | Main generation form — base template, keyword pairs, mode, AI rewrite, preview & generate |
| **🤖 AI Settings** | Configure provider (OpenAI / Anthropic / Gemini), API key, model, and default prompt |
| **🔌 Page Builders** | Shows which page builders are detected with per-builder handling notes |

The sidebar on the Generate tab shows detected page builders at a glance, and a How It Works quick reference.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- OpenAI, Anthropic, or Google Gemini API key (optional, for AI rewriting)

## Installation

1. Clone or download this repo
2. Upload the folder to `/wp-content/plugins/`
3. Activate in **Plugins**
4. Go to **Page Generator** in the admin menu

## Usage

### Basic (Single Keyword)

1. Select a base page (e.g., "Plumber Melbourne CBD")
2. Add a keyword pair: Find `Melbourne CBD`, Replace with `Sydney, Brisbane, Perth`
3. Click **Generate All Pages** — creates 3 new pages

### Matrix (Multiple Keywords)

1. Select a base page (e.g., "Corporate Function Venue Melbourne CBD")
2. Pair 1: Find `Melbourne CBD`, Replace with `Sydney, Brisbane`
3. Pair 2: Find `Corporate Function Venue`, Replace with `Private Dining, Birthday Party`
4. Choose **Matrix** mode — generates 4 pages (2 locations × 2 services)

### AI Rewriting

1. Go to the **AI Settings** tab
2. Select your provider (OpenAI, Anthropic, or Google Gemini) and enter your API key
   - OpenAI: [platform.openai.com/api-keys](https://platform.openai.com/api-keys)
   - Anthropic: [console.anthropic.com/settings/keys](https://console.anthropic.com/settings/keys)
   - Gemini: [aistudio.google.com/apikey](https://aistudio.google.com/apikey)
3. Save settings, then go back to the **Generate** tab
4. Toggle **Enable AI content rewriting** and choose a rewrite scope:
   - **Widget/Block** — rewrites each text element individually (faster, more targeted)
   - **Section/Row** — rewrites all text in each Elementor section together (better context)

## Changelog

### v2.3
- Tab-based UI: Generate, AI Settings, Page Builders — all on one page (no separate submenu)
- Added Google Gemini support (gemini-2.0-flash, gemini-2.5-pro)
- Added AI rewrite scope: Widget/Block vs Section/Row for Elementor
- Post type dropdown now live-refreshes template list via AJAX when switching Pages ↔ Blog Posts
- AI rewrite section always visible — shows setup link if no API key is configured
- Page Builders detection card moved to sidebar on Generate tab
- AI Settings tab shows clickable "Get key →" links for each provider
- Fixed "Invalid base page or keyword pairs" error caused by form serializing after disabling
- Fixed Elementor preview broken page (_elementor_css now excluded from meta copy)
- Redesigned UI: pink accent (#FF2462), dark gradient banner, two-column layout, 980px max-width

### v2.1
- AJAX batch processing with live progress bar and cancel button
- CSV import for keyword lists (bulk import with headers, or per-pair)
- Scheduled generation via WP-Cron (background queue or timed publishing)
- Job status panel showing active/completed scheduled jobs

### v2.0
- Rebranded from Suburb Page Generator to Keyword Page Generator
- Multi-keyword pair support with matrix and independent generation modes
- AI content rewriting via OpenAI and Anthropic APIs
- Builder auto-detection (Elementor, Divi, WPBakery, Gutenberg, Classic)
- Content type selector (pages and blog posts)
- Preview toggle with usage limit warning
- Live page counter with matrix/independent calculation

### v1.0
- Initial release by Steven Chun (Suburb Page Generator)
- Single keyword (suburb) replacement
- Basic page duplication with meta copying

## Roadmap

- [x] Multi-keyword pair replacement
- [x] Matrix (cross-product) generation
- [x] AI content rewriting (OpenAI + Anthropic + Gemini)
- [x] Builder auto-detection and builder-aware AI extraction
- [x] Blog post support
- [x] Batch processing with progress bar
- [x] Scheduled generation via WP-Cron
- [x] CSV import for keyword lists
- [x] Tab-based single-page UI
- [ ] Template library (save/load keyword pair configurations)
- [ ] WP-CLI support for headless generation

## Credits

Based on [Suburb Page Generator](https://github.com/flavor-developer/suburb-page-generator) by Steven Chun.

## License

GPL-2.0+
