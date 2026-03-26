# Keyword Page Generator

> **v2.1** — Generate multiple page/post variations by replacing keywords across your content, with optional AI-powered unique rewriting.

A free WordPress plugin that duplicates a base page or post by replacing one or more keywords with a list of alternatives — perfect for location pages, service variations, or any repeatable content pattern. Supports matrix (cross-product) generation and AI content rewriting via OpenAI or Anthropic.

## How It Works

Instead of manually duplicating pages and find-replacing text, the plugin:

1. Takes a **base template** page or post and one or more **keyword pairs** (find keyword + comma-separated replacements).
2. Generates all variations automatically — either as a **matrix** (every combination) or **independently** (one set per pair).
3. Replaces keywords in titles, slugs, content, and all post meta (including serialized data from page builders).
4. Optionally **rewrites content with AI** so each generated page is unique — avoiding duplicate content penalties.

## Features

- Multi-keyword replacement with unlimited pairs (up to 5 axes)
- Matrix mode: cross-product of all keyword pairs (e.g., 3 locations x 4 services = 12 pages)
- Independent mode: generate pages for each pair separately
- Supports both Pages and Blog Posts
- AI content rewriting via OpenAI or Anthropic APIs
- Builder auto-detection: Elementor, Divi, WPBakery, Gutenberg, Classic Editor
- Builder-aware AI extraction — preserves page builder layouts and structure
- Encrypted API key storage (AES-256-CBC)
- Live page counter with limit warnings
- Preview toggle to review the first page before bulk generating
- Recursive replacement in serialized post meta (ACF, Yoast, page builder data)
- Longest-match-first replacement to avoid partial matches
- Slug sanitization with collision detection
- Clean admin UI under **Page Generator**
- Settings page for AI provider configuration with connection test
- Detected builders displayed on settings page

## Requirements

- WordPress 5.8+
- PHP 7.4+
- OpenAI or Anthropic API key (optional, for AI rewriting)

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
4. Choose **Matrix** mode — generates 4 pages (2 locations x 2 services)

### AI Rewriting

1. Go to **Page Generator > Settings**
2. Enter your OpenAI or Anthropic API key
3. When generating, toggle **Enable AI content rewriting**
4. Each page gets unique, rewritten content while preserving structure

## Changelog

### v2.1
- AJAX batch processing with live progress bar (no more page hanging during generation)
- Cancel button to stop mid-batch
- CSV import for keyword lists (bulk import with headers as find keywords, or per-pair import)
- Scheduled generation via WP-Cron (background queue or timed publishing)
- Job status panel showing active/completed scheduled jobs
- Cron lock to prevent duplicate processing
- Plugin deactivation cleanup (clears cron hooks, transients, and options)
- Refactored page creation into reusable `kpg_create_single_page()` function

### v2.0
- Rebranded from Suburb Page Generator to Keyword Page Generator
- Multi-keyword pair support with matrix and independent generation modes
- AI content rewriting via OpenAI and Anthropic APIs
- Builder auto-detection (Elementor, Divi, WPBakery, Gutenberg, Classic)
- Builder-aware text extraction for AI rewriting
- AI settings page with encrypted API key storage and connection test
- Content type selector (pages and blog posts)
- Preview toggle with usage limit warning
- Live page counter with matrix/independent calculation
- Dynamic keyword pair UI (add/remove up to 5 pairs)
- Nonce verification and CSRF protection
- Improved input validation and slug collision handling

### v1.0
- Initial release by Steven Chun (Suburb Page Generator)
- Single keyword (suburb) replacement
- Basic page duplication with meta copying

## Roadmap

- [x] Multi-keyword pair replacement
- [x] Matrix (cross-product) generation
- [x] AI content rewriting (OpenAI + Anthropic)
- [x] Builder auto-detection and builder-aware AI extraction
- [x] Blog post support
- [x] Preview toggle with limit warning
- [x] Batch processing with progress bar for large generation runs
- [x] Scheduled generation via WP-Cron
- [x] CSV import for keyword lists
- [ ] Template library (save/load keyword pair configurations)
- [ ] WP-CLI support for headless generation

## Credits

Based on [Suburb Page Generator](https://github.com/flavor-developer/suburb-page-generator) by Steven Chun.

## License

GPL-2.0+
