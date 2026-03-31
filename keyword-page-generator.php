<?php
/**
 * Plugin Name: Keyword Page Generator
 * Description: Generates keyword-specific pages by replacing multiple placeholders in content and metadata. Supports matrix generation, AI rewriting, Divi, Elementor, WPBakery, Gutenberg and Yoast SEO.
 * Version: 2.3
 * Author: Wisnu
 * Author URI: https://wisnuub.github.io/
 * Based on: Suburb Page Generator by Steven Chun
 */

defined('ABSPATH') || exit;

define('KPG_PAGE_LIMIT', 20);
define('KPG_MAX_PAIRS', 5);

add_action('admin_menu', 'kpg_add_admin_menu');
add_action('admin_init', 'kpg_register_settings');
add_action('admin_init', 'kpg_elementor_meta_normalizer_run_once');
add_action('admin_enqueue_scripts', 'kpg_enqueue_admin_assets');

// AJAX batch processing
add_action('wp_ajax_kpg_start_batch', 'kpg_ajax_start_batch');
add_action('wp_ajax_kpg_process_step', 'kpg_ajax_process_step');
add_action('wp_ajax_kpg_cancel_batch', 'kpg_ajax_cancel_batch');
add_action('wp_ajax_kpg_get_templates', 'kpg_ajax_get_templates');

// WP-Cron scheduled generation
add_action('kpg_cron_process_job', 'kpg_cron_process_single_step');
add_filter('cron_schedules', 'kpg_add_cron_interval');
add_action('wp_ajax_kpg_schedule_job', 'kpg_ajax_schedule_job');
add_action('wp_ajax_kpg_clear_job', 'kpg_ajax_clear_job');

// Cleanup on deactivation
register_deactivation_hook(__FILE__, 'kpg_deactivation');

// ============================================================
// Admin Menu & Assets
// ============================================================

function kpg_add_admin_menu() {
    add_menu_page(
        'Keyword Page Generator',
        'Page Generator',
        'manage_options',
        'keyword-page-generator',
        'kpg_admin_page',
        'dashicons-admin-page',
        25
    );
    // Keep submenu entry so the menu item label matches (WP adds it automatically otherwise)
    add_submenu_page(
        'keyword-page-generator',
        'Keyword Page Generator',
        'Page Generator',
        'manage_options',
        'keyword-page-generator',
        'kpg_admin_page'
    );
}

function kpg_enqueue_admin_assets() {
    $current_screen = get_current_screen();
    if (!$current_screen) return;

    $allowed_screens = [
        'toplevel_page_keyword-page-generator',
        'page-generator_page_keyword-page-generator',
    ];

    if (!in_array($current_screen->id, $allowed_screens, true)) return;

    wp_enqueue_style('kpg-admin-styles', plugins_url('assets/kpg-admin-styles.css', __FILE__), [], '2.3');
    wp_enqueue_script('kpg-admin-script', plugins_url('assets/kpg-admin-script.js', __FILE__), ['jquery'], '2.3', true);

    $ai_settings = kpg_get_ai_settings();
    wp_localize_script('kpg-admin-script', 'kpgData', [
        'ajaxUrl'      => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce('kpg_admin_nonce'),
        'maxPairs'     => KPG_MAX_PAIRS,
        'pageLimit'    => KPG_PAGE_LIMIT,
        'aiConfigured' => !empty($ai_settings['api_key']),
        'i18n'         => [
            'loading' => __('Loading...', 'keyword-page-generator'),
            'error'   => __('An error occurred. Please try again.', 'keyword-page-generator'),
        ],
    ]);
}

// ============================================================
// AI Settings Registration & Page
// ============================================================

function kpg_register_settings() {
    register_setting('kpg_ai_settings_group', 'kpg_ai_settings', [
        'type'              => 'array',
        'sanitize_callback' => 'kpg_sanitize_ai_settings',
    ]);
}

function kpg_sanitize_ai_settings($input) {
    $clean = [];
    $clean['provider']      = in_array($input['provider'] ?? '', ['openai', 'anthropic', 'gemini'], true) ? $input['provider'] : 'openai';
    $clean['model']         = sanitize_text_field($input['model'] ?? '');
    $clean['custom_prompt'] = sanitize_textarea_field($input['custom_prompt'] ?? '');

    // Encrypt API key if changed (not the masked placeholder)
    $raw_key = $input['api_key'] ?? '';
    if (!empty($raw_key) && strpos($raw_key, '••••') === false) {
        $clean['api_key'] = kpg_encrypt($raw_key);
    } else {
        $existing = get_option('kpg_ai_settings', []);
        $clean['api_key'] = $existing['api_key'] ?? '';
    }

    return $clean;
}

function kpg_get_ai_settings() {
    $defaults = [
        'provider'      => 'openai',
        'api_key'       => '',
        'model'         => '',
        'custom_prompt' => '',
    ];
    return wp_parse_args(get_option('kpg_ai_settings', []), $defaults);
}

function kpg_encrypt($value) {
    $key = wp_salt('auth');
    $iv  = substr(hash('sha256', wp_salt('secure_auth')), 0, 16);
    $encrypted = openssl_encrypt($value, 'AES-256-CBC', $key, 0, $iv);
    return $encrypted !== false ? $encrypted : '';
}

function kpg_decrypt($value) {
    if (empty($value)) return '';
    $key = wp_salt('auth');
    $iv  = substr(hash('sha256', wp_salt('secure_auth')), 0, 16);
    $decrypted = openssl_decrypt($value, 'AES-256-CBC', $key, 0, $iv);
    return $decrypted !== false ? $decrypted : '';
}

// kpg_ai_settings_page kept for backwards-compat in case any bookmark links to the old URL
// It simply redirects to the main page with the AI tab active

// ============================================================
// Builder Detection
// ============================================================

function kpg_detect_active_builders() {
    $cached = get_transient('kpg_active_builders');
    if (is_array($cached)) return $cached;

    if (!function_exists('is_plugin_active')) {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $builders = [];

    if (defined('ELEMENTOR_VERSION') || is_plugin_active('elementor/elementor.php')) {
        $builders[] = 'elementor';
    }

    $theme = wp_get_theme();
    if ($theme->get('Name') === 'Divi' || $theme->get('Template') === 'Divi' || defined('ET_BUILDER_VERSION') || is_plugin_active('divi-builder/divi-builder.php')) {
        $builders[] = 'divi';
    }

    if (defined('WPB_VC_VERSION') || is_plugin_active('js_composer/js_composer.php')) {
        $builders[] = 'wpbakery';
    }

    if (function_exists('parse_blocks')) {
        $builders[] = 'gutenberg';
    }

    $builders[] = 'classic';

    set_transient('kpg_active_builders', $builders, HOUR_IN_SECONDS);
    return $builders;
}

function kpg_detect_page_builder($post_id) {
    if (get_post_meta($post_id, '_elementor_data', true)) return 'elementor';
    $content = get_post($post_id)->post_content ?? '';
    if (strpos($content, '[et_pb_') !== false) return 'divi';
    if (strpos($content, '[vc_') !== false) return 'wpbakery';
    if (strpos($content, '<!-- wp:') !== false) return 'gutenberg';
    return 'classic';
}

// ============================================================
// Main Admin Page
// ============================================================

function kpg_admin_page() {
    if (!current_user_can('manage_options')) wp_die(__('Unauthorized user'));

    kpg_enqueue_admin_assets();

    $preview_results  = [];
    $ai_settings      = kpg_get_ai_settings();
    $ai_configured    = !empty($ai_settings['api_key']);
    $builders         = kpg_detect_active_builders();
    $has_key          = !empty($ai_settings['api_key']);
    $masked_key       = $has_key ? '••••••••' . substr(kpg_decrypt($ai_settings['api_key']), -4) : '';
    $default_models   = [
        'openai'    => ['gpt-4o-mini' => 'GPT-4o Mini (fast)', 'gpt-4o' => 'GPT-4o (best)'],
        'anthropic' => ['claude-sonnet-4-6' => 'Claude Sonnet 4.6 (fast)', 'claude-opus-4-6' => 'Claude Opus 4.6 (best)'],
        'gemini'    => ['gemini-2.0-flash' => 'Gemini 2.0 Flash (fast)', 'gemini-2.5-pro-preview-03-25' => 'Gemini 2.5 Pro (best)'],
    ];
    $api_key_urls = [
        'openai'    => 'https://platform.openai.com/api-keys',
        'anthropic' => 'https://console.anthropic.com/settings/keys',
        'gemini'    => 'https://aistudio.google.com/apikey',
    ];
    $all_builders     = ['elementor' => 'Elementor', 'divi' => 'Divi', 'wpbakery' => 'WPBakery', 'gutenberg' => 'Gutenberg', 'classic' => 'Classic Editor'];

    // Handle AI settings save (posted to this page via AJAX-style inline form)
    if (isset($_POST['kpg_save_ai'])) {
        check_admin_referer('kpg_ai_save', 'kpg_ai_nonce');
        $input = $_POST['kpg_ai_settings'] ?? [];
        update_option('kpg_ai_settings', kpg_sanitize_ai_settings($input));
        $ai_settings   = kpg_get_ai_settings();
        $ai_configured = !empty($ai_settings['api_key']);
        $has_key       = $ai_configured;
        $masked_key    = $has_key ? '••••••••' . substr(kpg_decrypt($ai_settings['api_key']), -4) : '';
    }

    if (isset($_POST['kpg_preview']) || isset($_POST['kpg_submit'])) {
        check_admin_referer('kpg_generate_action', 'kpg_nonce');

        if (isset($_POST['kpg_preview'])) {
            $preview_results = kpg_generate_preview();
        } else {
            kpg_process_form();
        }
    }

    $selected_type = sanitize_text_field($_POST['post_type'] ?? 'page');
    $templates = get_posts([
        'post_type'      => $selected_type,
        'post_status'    => 'publish',
        'posts_per_page' => 200,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);
    ?>
    <div class="kpg-admin-wrapper">
        <div class="kpg-admin-header-wrap">
        <div class="kpg-admin-header">
            <div class="kpg-header-content">
                <div class="kpg-header-brand">
                    <span class="kpg-header-icon">&#128196;</span>
                    <div>
                        <h1 class="kpg-page-title">Keyword Page Generator</h1>
                        <p class="kpg-page-subtitle">Generate multiple pages or posts by replacing keywords in your templates</p>
                    </div>
                </div>
                <span class="kpg-version-badge">v2.2</span>
            </div>
        </div>
        </div><!-- /.kpg-admin-header-wrap -->

        <div class="kpg-admin-outer">

            <!-- Tab navigation -->
            <nav class="kpg-tabs-nav" role="tablist">
                <button class="kpg-tab-btn" data-tab="generate" role="tab" aria-selected="true">
                    &#9889; Generate
                </button>
                <button class="kpg-tab-btn" data-tab="ai" role="tab" aria-selected="false">
                    &#129302; AI Settings<?php if (!$ai_configured) echo ' <span class="kpg-tab-badge">!</span>'; ?>
                </button>
                <button class="kpg-tab-btn" data-tab="builders" role="tab" aria-selected="false">
                    &#128268; Page Builders
                </button>
            </nav>

            <!-- ===================== TAB: Generate ===================== -->
            <div class="kpg-tab-panel" id="kpg-panel-generate">
            <?php
            $cron_job = get_option('kpg_cron_job');
            if ($cron_job) :
                $job_total     = $cron_job['total'] ?? 0;
                $job_completed = $cron_job['completed'] ?? 0;
                $job_status    = $cron_job['status'] ?? 'pending';
                $job_results   = $cron_job['results'] ?? [];
                $created_count = count(array_filter($job_results, fn($r) => $r['status'] === 'created'));
                $skipped_count = count(array_filter($job_results, fn($r) => $r['status'] === 'skipped'));
                $schedule_type = $cron_job['schedule_type'] ?? 'background';
            ?>
            <div class="kpg-job-status-card kpg-card">
                <div class="kpg-card-header">
                    <h2 class="kpg-card-title">&#128339; Scheduled Job</h2>
                    <p class="kpg-card-description">
                        <?php if ($job_status === 'completed') : ?>
                            Job completed — <?php echo esc_html($created_count); ?> page(s) created<?php echo $skipped_count ? ', ' . esc_html($skipped_count) . ' skipped' : ''; ?>
                        <?php elseif ($job_status === 'pending') : ?>
                            Waiting to start (<?php echo esc_html($schedule_type === 'timed' ? 'scheduled for ' . ($cron_job['scheduled_time'] ?? '?') : 'background queue'); ?>)
                        <?php else : ?>
                            In progress: <?php echo esc_html($job_completed); ?> of <?php echo esc_html($job_total); ?> pages
                        <?php endif; ?>
                    </p>
                </div>
                <div class="kpg-job-status-body" style="padding: 1rem 1.5rem;">
                    <?php if ($job_status !== 'completed' && $job_total > 0) : ?>
                        <div class="kpg-progress-bar">
                            <div class="kpg-progress-fill" style="width: <?php echo esc_attr(round($job_completed / $job_total * 100)); ?>%;"></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($job_status === 'completed' && !empty($job_results)) : ?>
                        <ul class="kpg-job-results-list">
                            <?php foreach ($job_results as $r) : ?>
                                <li class="kpg-job-result-<?php echo esc_attr($r['status']); ?>">
                                    <?php echo $r['status'] === 'created' ? '&#9989;' : '&#10060;'; ?>
                                    <?php echo esc_html($r['title']); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <button type="button" id="kpg-clear-job-btn" class="kpg-btn kpg-btn-outline" style="margin-top: 0.75rem;">
                        <?php echo $job_status === 'completed' ? 'Clear Results' : 'Cancel Job'; ?>
                    </button>
                    <p class="kpg-cron-notice">&#9432; WP-Cron runs on page visits. For reliable scheduling, configure a system cron job pointing to <code>wp-cron.php</code>.</p>
                </div>
            </div>
            <?php endif; ?>

            <div class="kpg-admin-container">
            <div class="kpg-main-col">

            <div class="kpg-form-card kpg-card">
                <div class="kpg-card-header">
                    <h2 class="kpg-card-title">Generate Pages</h2>
                    <p class="kpg-card-description">Set up keyword replacement pairs to create page or post variations from your template</p>
                </div>

                <form method="post" class="kpg-form" id="kpg-main-form">
                    <?php wp_nonce_field('kpg_generate_action', 'kpg_nonce'); ?>

                    <!-- Step 1: Base Template -->
                    <div class="kpg-form-section">
                        <div class="kpg-section-header">
                            <h3 class="kpg-section-title">
                                <span class="kpg-step-number">1</span>
                                Base Template
                            </h3>
                        </div>
                        <div class="kpg-form-group">
                            <div class="kpg-form-field">
                                <label for="kpg_post_type" class="kpg-label">Content Type</label>
                                <select id="kpg_post_type" name="post_type" class="kpg-select">
                                    <option value="page" <?php selected($selected_type, 'page'); ?>>Pages</option>
                                    <option value="post" <?php selected($selected_type, 'post'); ?>>Blog Posts</option>
                                </select>
                                <p class="kpg-field-help">Choose whether to generate pages or blog posts</p>
                            </div>
                            <?php kpg_form_page_select('Select the template to duplicate', $templates); ?>
                            <p class="kpg-builder-detect" id="kpg-builder-detect" style="display:none;"></p>
                        </div>
                    </div>

                    <!-- Step 2: Keyword Pairs -->
                    <div class="kpg-form-section">
                        <div class="kpg-section-header">
                            <h3 class="kpg-section-title">
                                <span class="kpg-step-number">2</span>
                                Keyword Replacement Pairs
                            </h3>
                            <div class="kpg-csv-import-section">
                                <label class="kpg-btn kpg-btn-outline kpg-btn-sm" for="kpg-csv-bulk-import">
                                    &#128196; Import CSV
                                </label>
                                <input type="file" id="kpg-csv-bulk-import" accept=".csv,.txt" style="display:none;">
                                <span class="kpg-field-help">Headers = find keywords, rows = replacement values</span>
                            </div>
                        </div>

                        <div id="kpg-pairs-container">
                            <?php
                            $posted_pairs = $_POST['pairs'] ?? [['find' => '', 'replace' => '']];
                            foreach ($posted_pairs as $i => $pair) :
                            ?>
                            <div class="kpg-pair-group" data-pair-index="<?php echo (int)$i; ?>">
                                <div class="kpg-pair-header">
                                    <span class="kpg-pair-label">Keyword Pair <?php echo (int)$i + 1; ?></span>
                                    <?php if ($i > 0) : ?>
                                        <button type="button" class="kpg-remove-pair-btn" aria-label="Remove this keyword pair">&times;</button>
                                    <?php endif; ?>
                                </div>
                                <div class="kpg-pair-fields">
                                    <div class="kpg-form-field">
                                        <label class="kpg-label">Find keyword</label>
                                        <input type="text" name="pairs[<?php echo (int)$i; ?>][find]" class="kpg-input kpg-pair-find"
                                               value="<?php echo esc_attr($pair['find'] ?? ''); ?>"
                                               placeholder="e.g. Melbourne CBD" required>
                                    </div>
                                    <div class="kpg-form-field">
                                        <label class="kpg-label">Replace with</label>
                                        <textarea name="pairs[<?php echo (int)$i; ?>][replace]" class="kpg-input kpg-textarea kpg-pair-replace"
                                                  rows="3" placeholder="Comma-separated values, e.g. Sydney, Brisbane, Perth" required><?php echo esc_textarea($pair['replace'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <button type="button" id="kpg-add-pair-btn" class="kpg-btn kpg-btn-outline kpg-add-pair-btn">
                            + Add another keyword pair
                        </button>
                    </div>

                    <!-- Step 3: Generation Mode (shown when 2+ pairs) -->
                    <div class="kpg-form-section kpg-mode-section" id="kpg-mode-section" style="display:none;">
                        <div class="kpg-section-header">
                            <h3 class="kpg-section-title">
                                <span class="kpg-step-number">3</span>
                                Generation Mode
                            </h3>
                        </div>
                        <div class="kpg-mode-options">
                            <label class="kpg-mode-option">
                                <input type="radio" name="generation_mode" value="matrix" checked>
                                <span class="kpg-mode-label">
                                    <strong>Matrix (Cross-product)</strong>
                                    <span class="kpg-mode-desc">Combine all pairs: every keyword 1 value x every keyword 2 value</span>
                                </span>
                            </label>
                            <label class="kpg-mode-option">
                                <input type="radio" name="generation_mode" value="independent"
                                    <?php checked(($_POST['generation_mode'] ?? ''), 'independent'); ?>>
                                <span class="kpg-mode-label">
                                    <strong>Independent</strong>
                                    <span class="kpg-mode-desc">Each pair generates its own pages separately</span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Step 4: AI Content Rewriting -->
                    <div class="kpg-form-section" id="kpg-ai-section">
                        <div class="kpg-section-header">
                            <h3 class="kpg-section-title">
                                <span class="kpg-step-number kpg-step-ai">AI</span>
                                AI Content Rewriting
                            </h3>
                        </div>
                        <?php if (!$ai_configured) : ?>
                        <div class="kpg-ai-notice">
                            &#128272; No API key configured. <a href="#" class="kpg-tab-link" data-tab="ai">Set up AI Settings</a> to enable unique content rewriting per page.
                        </div>
                        <?php else : ?>
                        <label class="kpg-toggle-label">
                            <input type="checkbox" name="ai_rewrite" value="1" id="kpg-ai-toggle"
                                <?php checked(!empty($_POST['ai_rewrite'])); ?>>
                            <span>Enable AI content rewriting for unique pages</span>
                        </label>
                        <div class="kpg-ai-options" id="kpg-ai-options" style="display:none;">
                            <div class="kpg-form-field">
                                <label class="kpg-label">Rewrite Scope</label>
                                <div class="kpg-mode-options kpg-mode-options--compact">
                                    <label class="kpg-mode-option">
                                        <input type="radio" name="ai_scope" value="widget" <?php checked(($_POST['ai_scope'] ?? 'widget'), 'widget'); ?>>
                                        <span class="kpg-mode-label">
                                            <strong>Widget / Block</strong>
                                            <span class="kpg-mode-desc">Each text element rewritten separately</span>
                                        </span>
                                    </label>
                                    <label class="kpg-mode-option">
                                        <input type="radio" name="ai_scope" value="section" <?php checked(($_POST['ai_scope'] ?? 'widget'), 'section'); ?>>
                                        <span class="kpg-mode-label">
                                            <strong>Section / Row</strong>
                                            <span class="kpg-mode-desc">All text in a section rewritten together (Elementor)</span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                            <div class="kpg-form-field">
                                <label class="kpg-label">Custom AI Prompt (optional)</label>
                                <textarea name="ai_prompt" class="kpg-input kpg-textarea" rows="3"
                                          placeholder="Leave empty for default. Use {keywords} for the replacement values."><?php echo esc_textarea($_POST['ai_prompt'] ?? ''); ?></textarea>
                            </div>
                            <div class="kpg-info-tip">
                                AI will rewrite text content to be unique for each page while preserving your page builder layout.
                                Using: <strong><?php echo esc_html(ucfirst($ai_settings['provider'])); ?></strong>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Page Counter & Actions -->
                    <div class="kpg-form-actions">
                        <div class="kpg-page-counter" id="kpg-page-counter">
                            This will generate <strong id="kpg-page-count">0</strong> page(s)
                        </div>
                        <div class="kpg-preview-toggle-wrapper">
                            <label class="kpg-toggle-label" for="kpg-preview-toggle">
                                <input type="checkbox" id="kpg-preview-toggle" name="kpg_preview_enabled" value="1">
                                Preview first page before generating
                            </label>
                            <p class="kpg-preview-warning" id="kpg-preview-warning" style="display:none;">
                                &#9888; Preview creates a draft page and counts toward your generation limit.
                            </p>
                        </div>
                        <div class="kpg-action-buttons">
                            <button type="submit" name="kpg_preview" id="kpg-preview-btn" class="button kpg-btn kpg-btn-secondary" style="display:none;">
                                <span class="kpg-btn-icon">&#128065;</span> Preview First Page
                            </button>
                            <button type="submit" name="kpg_submit" id="kpg-generate-btn" class="button kpg-btn kpg-btn-primary">
                                <span class="kpg-btn-icon">&#10024;</span> Generate All Pages
                            </button>
                            <button type="button" id="kpg-schedule-btn" class="kpg-btn kpg-btn-outline">
                                <span class="kpg-btn-icon">&#128339;</span> Schedule
                            </button>
                        </div>

                        <!-- Schedule Panel -->
                        <div id="kpg-schedule-panel" class="kpg-schedule-panel" style="display:none;">
                            <div class="kpg-form-field">
                                <label class="kpg-label">Schedule Mode</label>
                                <select id="kpg-schedule-mode" class="kpg-select">
                                    <option value="background">Background queue (process now via cron)</option>
                                    <option value="timed">Schedule for a specific date &amp; time</option>
                                </select>
                            </div>
                            <div class="kpg-form-field" id="kpg-schedule-datetime-field" style="display:none;">
                                <label class="kpg-label">Date &amp; Time</label>
                                <input type="datetime-local" id="kpg-schedule-datetime" class="kpg-input">
                            </div>
                            <button type="button" id="kpg-schedule-confirm" class="kpg-btn kpg-btn-primary kpg-btn-sm">
                                &#10003; Confirm Schedule
                            </button>
                        </div>
                    </div>

                    <!-- Progress Bar (shown during AJAX batch) -->
                    <div id="kpg-progress-container" class="kpg-progress-container" style="display:none;">
                        <div class="kpg-progress-header">
                            <span class="kpg-progress-text" id="kpg-progress-text">Preparing...</span>
                            <button type="button" id="kpg-progress-cancel" class="kpg-btn kpg-btn-outline kpg-btn-sm kpg-progress-cancel">Cancel</button>
                        </div>
                        <div class="kpg-progress-bar">
                            <div class="kpg-progress-fill" id="kpg-progress-fill" style="width: 0%;"></div>
                        </div>
                        <div class="kpg-progress-log" id="kpg-progress-log"></div>
                    </div>

                    <!-- Batch Summary (shown after AJAX batch completes) -->
                    <div id="kpg-batch-summary" class="kpg-batch-summary" style="display:none;"></div>
                </form>
            </div>

            <!-- Preview Results -->
            <?php if (!empty($preview_results)) : ?>
            <div class="kpg-preview-card kpg-card">
                <div class="kpg-card-header">
                    <h2 class="kpg-card-title">Preview</h2>
                    <p class="kpg-card-description">Review the first page that will be generated</p>
                </div>
                <div class="kpg-preview-content">
                    <?php foreach ($preview_results as $result) : ?>
                    <div class="kpg-preview-item">
                        <div class="kpg-preview-header">
                            <div class="kpg-preview-title">
                                <h3><?php echo esc_html($result['title']); ?></h3>
                                <code class="kpg-preview-slug"><?php echo esc_html($result['slug']); ?></code>
                            </div>
                            <a href="<?php echo esc_url($result['preview_url']); ?>" class="button kpg-btn kpg-btn-outline" target="_blank" rel="noopener noreferrer">
                                <span class="kpg-btn-icon">&#128269;</span> View Draft
                            </a>
                        </div>
                        <div class="kpg-preview-details">
                            <div class="kpg-detail-group">
                                <label class="kpg-detail-label">Content Preview</label>
                                <p class="kpg-detail-content"><?php echo esc_html(wp_trim_words($result['content'], 40)); ?></p>
                            </div>
                            <div class="kpg-detail-group">
                                <label class="kpg-detail-label">Pages to Generate (<?php echo count($result['pages_list']); ?>)</label>
                                <ul class="kpg-suburb-list" role="list">
                                    <?php foreach ($result['pages_list'] as $page_title) : ?>
                                        <li role="listitem" class="kpg-suburb-item"><?php echo esc_html($page_title); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            </div><!-- /.kpg-main-col -->

            <!-- Sidebar -->
            <div class="kpg-sidebar-col">
                <!-- Detected Page Builders -->
                <div class="kpg-card" style="margin-bottom:16px;">
                    <div class="kpg-card-header">
                        <h2 class="kpg-card-title">Page Builders</h2>
                    </div>
                    <div class="kpg-sidebar-builders">
                        <?php
                        $all_builders = ['elementor' => 'Elementor', 'divi' => 'Divi', 'wpbakery' => 'WPBakery', 'gutenberg' => 'Gutenberg', 'classic' => 'Classic Editor'];
                        foreach ($all_builders as $key => $name) :
                            $active = in_array($key, $builders, true);
                        ?>
                        <div class="kpg-sidebar-builder-row">
                            <span class="kpg-sidebar-builder-name"><?php echo esc_html($name); ?></span>
                            <span class="kpg-builder-pill <?php echo $active ? 'kpg-pill-active' : 'kpg-pill-inactive'; ?>">
                                <?php echo $active ? '&#10003; Active' : 'None'; ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- How It Works -->
                <div class="kpg-info-card kpg-card">
                    <div class="kpg-card-header">
                        <h2 class="kpg-card-title">How It Works</h2>
                    </div>
                    <div class="kpg-info-content">
                        <ol class="kpg-info-list">
                            <li><strong>Select your base page</strong> — the template to duplicate.</li>
                            <li><strong>Add keyword pairs</strong> — find keyword + comma-separated replacements.</li>
                            <li><strong>Choose mode</strong> — Matrix (every combination) or Independent (per pair).</li>
                            <li><strong>AI rewrite (optional)</strong> — makes each page unique.</li>
                            <li><strong>Preview then Generate</strong> — review one page first.</li>
                        </ol>
                        <div class="kpg-info-tip">
                            <strong>Example:</strong> "Melbourne CBD" &rarr; "Sydney, Brisbane" &times; "Corporate Venue" &rarr; "Private Dining, Birthday Party" = 4 pages in Matrix mode.
                        </div>
                    </div>
                </div>
            </div><!-- /.kpg-sidebar-col -->
            </div><!-- /.kpg-admin-container -->
            </div><!-- /.kpg-tab-panel#generate -->

            <!-- ===================== TAB: AI Settings ===================== -->
            <div class="kpg-tab-panel" id="kpg-panel-ai" style="display:none;">
                <div class="kpg-admin-container--settings">
                    <?php if (isset($_POST['kpg_save_ai'])) : ?>
                    <div class="notice notice-success" style="margin-bottom:16px;"><p>AI settings saved.</p></div>
                    <?php endif; ?>
                    <div class="kpg-card">
                        <div class="kpg-card-header">
                            <h2 class="kpg-card-title">AI Provider Configuration</h2>
                            <p class="kpg-card-description">Configure the AI model used for unique content rewriting</p>
                        </div>
                        <form method="post" class="kpg-form">
                            <?php wp_nonce_field('kpg_ai_save', 'kpg_ai_nonce'); ?>
                            <input type="hidden" name="kpg_save_ai" value="1">

                            <div class="kpg-form-section">
                                <div class="kpg-form-field">
                                    <label for="kpg_provider" class="kpg-label">AI Provider</label>
                                    <select id="kpg_provider" name="kpg_ai_settings[provider]" class="kpg-select">
                                        <option value="openai"    <?php selected($ai_settings['provider'], 'openai'); ?>>OpenAI</option>
                                        <option value="anthropic" <?php selected($ai_settings['provider'], 'anthropic'); ?>>Anthropic (Claude)</option>
                                        <option value="gemini"    <?php selected($ai_settings['provider'], 'gemini'); ?>>Google Gemini</option>
                                    </select>
                                </div>

                                <div class="kpg-form-field">
                                    <label for="kpg_api_key" class="kpg-label">
                                        API Key
                                        <?php foreach ($api_key_urls as $provider => $url) : ?>
                                        <a href="<?php echo esc_url($url); ?>"
                                           class="kpg-api-key-link kpg-api-key-link--<?php echo esc_attr($provider); ?>"
                                           target="_blank" rel="noopener noreferrer"
                                           data-provider="<?php echo esc_attr($provider); ?>"
                                           <?php echo $ai_settings['provider'] !== $provider ? 'style="display:none;"' : ''; ?>>
                                            Get <?php echo esc_html(['openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'gemini' => 'Gemini'][$provider]); ?> key &rarr;
                                        </a>
                                        <?php endforeach; ?>
                                    </label>
                                    <input type="password" id="kpg_api_key" name="kpg_ai_settings[api_key]"
                                           class="kpg-input" value="<?php echo esc_attr($masked_key); ?>"
                                           placeholder="Enter your API key" autocomplete="off">
                                    <p class="kpg-field-help">Stored encrypted. Leave unchanged to keep existing key.</p>
                                </div>

                                <div class="kpg-form-field">
                                    <label for="kpg_model" class="kpg-label">Model</label>
                                    <select id="kpg_model" name="kpg_ai_settings[model]" class="kpg-select">
                                        <?php foreach ($default_models as $provider => $models) :
                                            foreach ($models as $model_id => $model_name) : ?>
                                                <option value="<?php echo esc_attr($model_id); ?>"
                                                        data-provider="<?php echo esc_attr($provider); ?>"
                                                        <?php selected($ai_settings['model'], $model_id); ?>>
                                                    <?php echo esc_html($model_name); ?>
                                                </option>
                                            <?php endforeach;
                                        endforeach; ?>
                                    </select>
                                </div>

                                <div class="kpg-form-field">
                                    <label for="kpg_custom_prompt" class="kpg-label">Default Prompt (optional)</label>
                                    <textarea id="kpg_custom_prompt" name="kpg_ai_settings[custom_prompt]"
                                              class="kpg-input kpg-textarea" rows="4"
                                              placeholder="Leave empty for built-in prompt. Use {keywords} and {blocks} as placeholders."><?php echo esc_textarea($ai_settings['custom_prompt']); ?></textarea>
                                    <p class="kpg-field-help">This is the default prompt for all generations. You can override it per-generation in the Generate tab.</p>
                                </div>
                            </div>

                            <button type="submit" class="kpg-btn kpg-btn-primary">Save AI Settings</button>
                        </form>
                    </div>
                </div>
            </div><!-- /.kpg-tab-panel#ai -->

            <!-- ===================== TAB: Page Builders ===================== -->
            <div class="kpg-tab-panel" id="kpg-panel-builders" style="display:none;">
                <div class="kpg-admin-container--settings">
                    <div class="kpg-card">
                        <div class="kpg-card-header">
                            <h2 class="kpg-card-title">Detected Page Builders</h2>
                            <p class="kpg-card-description">Page builders detected on your WordPress installation. The plugin automatically copies the correct metadata for each builder.</p>
                        </div>
                        <div class="kpg-form">
                            <ul class="kpg-builder-list kpg-builder-list--full">
                                <?php foreach ($all_builders as $key => $name) :
                                    $active = in_array($key, $builders, true);
                                    $desc = [
                                        'elementor' => 'Copies _elementor_data JSON with keyword replacements. AI rewrites at widget or section level.',
                                        'divi'      => 'Replaces text inside [et_pb_text] and [et_pb_blurb] shortcodes.',
                                        'wpbakery'  => 'Replaces text inside [vc_column_text] shortcodes.',
                                        'gutenberg' => 'Parses and replaces core/paragraph, core/heading, core/list, core/quote blocks.',
                                        'classic'   => 'Standard HTML content replacement.',
                                    ];
                                ?>
                                <li class="kpg-builder-item kpg-builder-item--full <?php echo $active ? 'kpg-builder-active' : 'kpg-builder-inactive'; ?>">
                                    <div class="kpg-builder-status">
                                        <span class="kpg-builder-badge"><?php echo $active ? '&#10003;' : '&#10007;'; ?></span>
                                    </div>
                                    <div class="kpg-builder-info">
                                        <strong><?php echo esc_html($name); ?></strong>
                                        <span class="kpg-builder-desc"><?php echo esc_html($desc[$key] ?? ''); ?></span>
                                    </div>
                                    <span class="kpg-builder-pill <?php echo $active ? 'kpg-pill-active' : 'kpg-pill-inactive'; ?>">
                                        <?php echo $active ? 'Active' : 'Not detected'; ?>
                                    </span>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div><!-- /.kpg-tab-panel#builders -->

        </div><!-- /.kpg-admin-outer -->
    </div><!-- /.kpg-admin-wrapper -->
    <?php
}

// ============================================================
// Form Helpers
// ============================================================

function kpg_form_page_select($label, $pages) {
    $selected_id = $_POST['base_page'] ?? '';
    $field_id = 'kpg_field_base_page';

    echo '<div class="kpg-form-field">';
    echo '<label for="' . esc_attr($field_id) . '" class="kpg-label">' . esc_html($label) . '</label>';
    echo '<select id="' . esc_attr($field_id) . '" name="base_page" class="kpg-select" required aria-required="true">';
    echo '<option value="">' . esc_html__('Select a page...', 'keyword-page-generator') . '</option>';

    foreach ($pages as $page) {
        echo '<option value="' . esc_attr($page->ID) . '" ' . selected($selected_id, $page->ID, false) . '>';
        echo esc_html($page->post_title);
        echo '</option>';
    }

    echo '</select>';
    echo '<p class="kpg-field-help">Choose the page template that will be duplicated with keyword replacements</p>';
    echo '</div>';
}

// ============================================================
// Input Parsing & Validation
// ============================================================

function kpg_parse_keyword_list($input) {
    if (empty($input)) return [];
    $items = array_map('trim', explode(',', $input));
    $items = array_filter($items, fn($s) => !empty($s));
    return array_values(array_filter($items, fn($s) => preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\s\'\-&.]*$/', $s)));
}

function kpg_parse_pairs_from_post() {
    $raw_pairs = $_POST['pairs'] ?? [];
    $pairs = [];

    foreach ($raw_pairs as $pair) {
        $find    = sanitize_text_field($pair['find'] ?? '');
        $replace = sanitize_text_field($pair['replace'] ?? '');
        $values  = kpg_parse_keyword_list($replace);

        if (!empty($find) && !empty($values)) {
            $pairs[] = ['find' => $find, 'values' => $values];
        }
    }

    return $pairs;
}

function kpg_build_page_specs($pairs, $mode) {
    if (empty($pairs)) return [];

    if (count($pairs) === 1 || $mode === 'independent') {
        // Independent: each pair generates its own pages
        $specs = [];
        foreach ($pairs as $pair) {
            foreach ($pair['values'] as $value) {
                $replacements = [['old' => $pair['find'], 'new' => $value]];
                // Keep other pairs at their original (find) value — no replacement needed since the template already has those
                $specs[] = $replacements;
            }
        }
        return $specs;
    }

    // Matrix: cartesian product
    $arrays = array_map(fn($p) => $p['values'], $pairs);
    $combos = kpg_cartesian_product($arrays);

    $specs = [];
    foreach ($combos as $combo) {
        $replacements = [];
        foreach ($combo as $i => $value) {
            $replacements[] = ['old' => $pairs[$i]['find'], 'new' => $value];
        }
        $specs[] = $replacements;
    }
    return $specs;
}

function kpg_cartesian_product($arrays) {
    $result = [[]];
    foreach ($arrays as $key => $values) {
        $new_result = [];
        foreach ($result as $combo) {
            foreach ($values as $value) {
                $combo[$key] = $value;
                $new_result[] = $combo;
            }
        }
        $result = $new_result;
    }
    return $result;
}

// ============================================================
// Content Replacement (multi-pair)
// ============================================================

function kpg_replace_content($content, $replacements) {
    // Sort by length descending to avoid partial match issues
    usort($replacements, fn($a, $b) => strlen($b['old']) - strlen($a['old']));
    foreach ($replacements as $pair) {
        $content = str_ireplace($pair['old'], $pair['new'], $content);
    }
    return $content;
}

function kpg_replace_slug($original_slug, $replacements) {
    $slug = $original_slug;
    usort($replacements, fn($a, $b) => strlen($b['old']) - strlen($a['old']));
    foreach ($replacements as $pair) {
        $slug = str_ireplace(sanitize_title_with_dashes($pair['old']), sanitize_title_with_dashes($pair['new']), $slug);
    }
    return $slug;
}

function kpg_replace_title($original_title, $replacements) {
    usort($replacements, fn($a, $b) => strlen($b['old']) - strlen($a['old']));
    foreach ($replacements as $pair) {
        $original_title = str_ireplace($pair['old'], $pair['new'], $original_title);
    }
    return $original_title;
}

function kpg_copy_post_meta($from_post_id, $to_post_id, $replacements) {
    $all_meta = get_post_meta($from_post_id);
    $meta_keys = is_array($all_meta) ? array_keys($all_meta) : [];

    $skip_keys = [
        '_wp_old_slug', '_edit_lock', '_edit_last', '_kpg_preview_flag',
        '_yoast_indexable',
        // Elementor generates CSS per post-ID; copying it causes broken styles on the new page
        '_elementor_css',
    ];

    foreach ($meta_keys as $meta_key) {
        if (in_array($meta_key, $skip_keys, true)) continue;

        $orig_value = get_post_meta($from_post_id, $meta_key, true);
        $new_value = kpg_recursive_replace_maybe_serialized($orig_value, $replacements);
        update_post_meta($to_post_id, $meta_key, $new_value);
    }

    // Elementor builder flags
    $elementor_edit_mode = get_post_meta($from_post_id, '_elementor_edit_mode', true);
    if ($elementor_edit_mode) {
        update_post_meta($to_post_id, '_elementor_edit_mode', $elementor_edit_mode);
    } elseif (get_post_meta($to_post_id, '_elementor_data', true) && !get_post_meta($to_post_id, '_elementor_edit_mode', true)) {
        update_post_meta($to_post_id, '_elementor_edit_mode', 'builder');
    }

    $elementor_ver = get_post_meta($from_post_id, '_elementor_version', true);
    if ($elementor_ver) {
        update_post_meta($to_post_id, '_elementor_version', $elementor_ver);
    }

    // Divi custom CSS
    $divi_css = get_post_meta($from_post_id, '_et_pb_post_custom_css', true);
    if ($divi_css) {
        update_post_meta($to_post_id, '_et_pb_post_custom_css', kpg_recursive_replace_maybe_serialized($divi_css, $replacements));
    }
}

function kpg_recursive_replace_maybe_serialized($value, $replacements) {
    if (is_string($value)) {
        $maybe = maybe_unserialize($value);
        if ($maybe !== $value) {
            return kpg_recursive_replace($maybe, $replacements);
        }
    }
    return kpg_recursive_replace($value, $replacements);
}

function kpg_recursive_replace($data, $replacements) {
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            $data[$k] = kpg_recursive_replace($v, $replacements);
        }
        return $data;
    } elseif (is_object($data)) {
        foreach ($data as $prop => $val) {
            $data->$prop = kpg_recursive_replace($val, $replacements);
        }
        return $data;
    } elseif (is_string($data)) {
        // Sort by length descending to avoid partial matches
        $sorted = $replacements;
        usort($sorted, fn($a, $b) => strlen($b['old']) - strlen($a['old']));
        foreach ($sorted as $pair) {
            $data = str_ireplace($pair['old'], $pair['new'], $data);
        }
        return $data;
    }
    return $data;
}

// ============================================================
// Preview & Generate
// ============================================================

function kpg_generate_preview() {
    if (!current_user_can('manage_options')) wp_die(__('Unauthorized'));

    $base_page_id = intval($_POST['base_page'] ?? 0);
    $base_page    = get_post($base_page_id);
    $pairs        = kpg_parse_pairs_from_post();
    $mode         = sanitize_text_field($_POST['generation_mode'] ?? 'matrix');
    $post_type    = in_array($_POST['post_type'] ?? 'page', ['page', 'post'], true) ? $_POST['post_type'] : 'page';

    if (!$base_page) {
        echo '<div class="notice notice-error"><p>Base template not found. Please select a valid page or post.</p></div>';
        return [];
    }
    if (empty($pairs)) {
        echo '<div class="notice notice-warning"><p>No valid keyword pairs found.</p></div>';
        return [];
    }

    $page_specs = kpg_build_page_specs($pairs, $mode);
    if (empty($page_specs)) return [];

    kpg_delete_old_previews();

    // Preview the first combination
    $first_spec = $page_specs[0];
    $new_content = kpg_replace_content($base_page->post_content, $first_spec);
    $new_title   = kpg_replace_title($base_page->post_title, $first_spec);
    $new_slug    = kpg_replace_slug($base_page->post_name, $first_spec);

    if (get_page_by_path($new_slug)) {
        $new_slug .= '-' . wp_generate_password(6, false);
    }

    $preview_id = wp_insert_post([
        'post_title'   => $new_title,
        'post_name'    => $new_slug,
        'post_content' => $new_content,
        'post_status'  => 'draft',
        'post_type'    => $post_type,
        'post_parent'  => $post_type === 'page' ? $base_page->post_parent : 0,
    ]);

    if ($preview_id && !is_wp_error($preview_id)) {
        kpg_copy_post_meta($base_page_id, $preview_id, $first_spec);
        update_post_meta($preview_id, '_kpg_preview_flag', '1');
    }

    // Build list of all page titles that will be generated
    $pages_list = [];
    foreach ($page_specs as $spec) {
        $pages_list[] = kpg_replace_title($base_page->post_title, $spec);
    }

    return [[
        'title'       => $new_title,
        'slug'        => $new_slug,
        'content'     => $new_content,
        'preview_url' => get_preview_post_link($preview_id),
        'pages_list'  => $pages_list,
    ]];
}

/**
 * Create a single page from a replacement spec.
 * Used by both synchronous form processing and AJAX batch processing.
 */
function kpg_create_single_page($base_page, $spec, $post_type, $builder, $ai_enabled, $ai_settings, $ai_prompt, $ai_scope = 'widget') {
    $new_title   = kpg_replace_title($base_page->post_title, $spec);
    $new_slug    = kpg_replace_slug($base_page->post_name, $spec);
    $new_content = kpg_replace_content($base_page->post_content, $spec);
    $warning     = '';

    // AI rewrite if enabled
    if ($ai_enabled && $ai_settings && !empty($ai_settings['api_key'])) {
        $ai_result = kpg_ai_rewrite($new_content, $base_page->ID, $spec, $ai_settings, $ai_prompt);
        if ($ai_result['success']) {
            $new_content = $ai_result['content'];
        } else {
            $warning = $new_title . ': ' . $ai_result['error'];
        }
        sleep(1); // Rate limiting
    }

    if (get_page_by_path($new_slug)) {
        $new_slug .= '-' . wp_generate_password(6, false);
    }

    $new_page_id = wp_insert_post([
        'post_title'   => $new_title,
        'post_name'    => $new_slug,
        'post_content' => $new_content,
        'post_status'  => 'publish',
        'post_type'    => $post_type,
        'post_parent'  => $post_type === 'page' ? $base_page->post_parent : 0,
    ]);

    if ($new_page_id && !is_wp_error($new_page_id)) {
        kpg_copy_post_meta($base_page->ID, $new_page_id, $spec);

        // AI rewrite for Elementor meta if applicable
        if ($ai_enabled && $builder === 'elementor' && $ai_settings && !empty($ai_settings['api_key'])) {
            kpg_ai_rewrite_elementor_meta($new_page_id, $spec, $ai_settings, $ai_prompt, $ai_scope);
        }

        return ['status' => 'created', 'title' => $new_title, 'post_id' => $new_page_id, 'warning' => $warning];
    }

    return ['status' => 'skipped', 'title' => $new_title, 'post_id' => 0, 'warning' => $warning];
}

function kpg_process_form() {
    if (!current_user_can('manage_options')) wp_die(__('Unauthorized'));

    kpg_delete_old_previews();

    $base_page_id = intval($_POST['base_page'] ?? 0);
    $base_page    = get_post($base_page_id);
    $pairs        = kpg_parse_pairs_from_post();
    $mode         = sanitize_text_field($_POST['generation_mode'] ?? 'matrix');
    $post_type    = in_array($_POST['post_type'] ?? 'page', ['page', 'post'], true) ? $_POST['post_type'] : 'page';
    $ai_enabled   = !empty($_POST['ai_rewrite']);
    $ai_prompt    = sanitize_textarea_field($_POST['ai_prompt'] ?? '');

    if (!$base_page || empty($pairs)) {
        echo '<div class="notice notice-error"><p>Unable to process. Check your base template and keyword pairs.</p></div>';
        return;
    }

    $page_specs = kpg_build_page_specs($pairs, $mode);

    if (count($page_specs) > KPG_PAGE_LIMIT) {
        echo '<div class="notice notice-error"><p>Too many items. Maximum is ' . esc_html(KPG_PAGE_LIMIT) . ' per batch. You requested ' . esc_html(count($page_specs)) . '.</p></div>';
        return;
    }

    $builder          = kpg_detect_page_builder($base_page_id);
    $ai_settings      = $ai_enabled ? kpg_get_ai_settings() : null;
    $created_pages    = [];
    $skipped_pages    = [];
    $ai_warnings      = [];

    foreach ($page_specs as $spec) {
        $result = kpg_create_single_page($base_page, $spec, $post_type, $builder, $ai_enabled, $ai_settings, $ai_prompt);

        if ($result['status'] === 'created') {
            $created_pages[] = $result['title'];
        } else {
            $skipped_pages[] = $result['title'];
        }
        if (!empty($result['warning'])) {
            $ai_warnings[] = $result['warning'];
        }
    }

    if (!empty($created_pages)) {
        echo '<div class="notice notice-success"><p>' . esc_html(count($created_pages)) . ' page(s) published successfully.</p></div>';
    }
    if (!empty($skipped_pages)) {
        echo '<div class="notice notice-warning"><p>Failed to create: ' . esc_html(implode(', ', $skipped_pages)) . '</p></div>';
    }
    if (!empty($ai_warnings)) {
        echo '<div class="notice notice-warning"><p>AI rewrite warnings (used keyword replacement instead):<br>' . esc_html(implode('<br>', $ai_warnings)) . '</p></div>';
    }
}

// ============================================================
// AI Content Rewriting
// ============================================================

function kpg_ai_rewrite($content, $post_id, $replacements, $ai_settings, $custom_prompt = '') {
    $builder = kpg_detect_page_builder($post_id);
    $blocks  = kpg_extract_text_blocks($content, $builder);

    if (empty($blocks)) {
        return ['success' => true, 'content' => $content];
    }

    $keywords = array_map(fn($r) => $r['new'], $replacements);
    $prompt   = kpg_build_ai_prompt($blocks, $keywords, $custom_prompt ?: ($ai_settings['custom_prompt'] ?? ''));

    $response = kpg_call_ai_api($prompt, $ai_settings);

    if (!$response['success']) {
        return ['success' => false, 'error' => $response['error'], 'content' => $content];
    }

    $rewritten_blocks = array_map('trim', explode('---BLOCK---', $response['text']));
    $content = kpg_replace_text_blocks($content, $blocks, $rewritten_blocks, $builder);

    return ['success' => true, 'content' => $content];
}

function kpg_extract_text_blocks($content, $builder) {
    $blocks = [];

    switch ($builder) {
        case 'gutenberg':
            if (function_exists('parse_blocks')) {
                $parsed = parse_blocks($content);
                foreach ($parsed as $block) {
                    if (in_array($block['blockName'], ['core/paragraph', 'core/heading', 'core/list', 'core/quote'], true)) {
                        $text = strip_tags($block['innerHTML']);
                        if (!empty(trim($text))) {
                            $blocks[] = ['text' => trim($text), 'raw' => $block['innerHTML']];
                        }
                    }
                }
            }
            break;

        case 'divi':
            if (preg_match_all('/\[et_pb_text[^\]]*\](.*?)\[\/et_pb_text\]/is', $content, $matches)) {
                foreach ($matches[1] as $i => $inner) {
                    $text = strip_tags($inner);
                    if (!empty(trim($text))) {
                        $blocks[] = ['text' => trim($text), 'raw' => $inner, 'full_match' => $matches[0][$i]];
                    }
                }
            }
            if (preg_match_all('/\[et_pb_blurb[^\]]*\](.*?)\[\/et_pb_blurb\]/is', $content, $matches)) {
                foreach ($matches[1] as $i => $inner) {
                    $text = strip_tags($inner);
                    if (!empty(trim($text))) {
                        $blocks[] = ['text' => trim($text), 'raw' => $inner, 'full_match' => $matches[0][$i]];
                    }
                }
            }
            break;

        case 'wpbakery':
            if (preg_match_all('/\[vc_column_text[^\]]*\](.*?)\[\/vc_column_text\]/is', $content, $matches)) {
                foreach ($matches[1] as $i => $inner) {
                    $text = strip_tags($inner);
                    if (!empty(trim($text))) {
                        $blocks[] = ['text' => trim($text), 'raw' => $inner, 'full_match' => $matches[0][$i]];
                    }
                }
            }
            break;

        case 'elementor':
        case 'classic':
        default:
            // For classic and elementor (post_content), split by paragraphs
            $paragraphs = preg_split('/(<\/?(?:p|h[1-6]|div|li)[^>]*>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
            $current_text = '';
            foreach ($paragraphs as $part) {
                $stripped = strip_tags($part);
                if (!empty(trim($stripped))) {
                    $blocks[] = ['text' => trim($stripped), 'raw' => $part];
                }
            }
            // Filter out very short blocks (likely just tags)
            $blocks = array_values(array_filter($blocks, fn($b) => strlen($b['text']) > 20));
            break;
    }

    return $blocks;
}

function kpg_build_ai_prompt($blocks, $keywords, $custom_prompt = '') {
    $keywords_str = implode(', ', $keywords);
    $blocks_str = implode("\n---BLOCK---\n", array_map(fn($b) => $b['text'], $blocks));

    if (!empty($custom_prompt)) {
        $prompt = str_replace(['{keywords}', '{blocks}'], [$keywords_str, $blocks_str], $custom_prompt);
        return $prompt;
    }

    return "Rewrite the following text content to be unique and natural for a page about \"{$keywords_str}\".
Keep the same meaning, structure, and approximate length for each block.
Do not add new sections or remove existing ones.
Maintain a professional, engaging tone suitable for a business website.
Return ONLY the rewritten text blocks in the same order, separated by \"---BLOCK---\".
Do not include any other text, labels, or explanations.

Text blocks to rewrite:
{$blocks_str}";
}

function kpg_replace_text_blocks($content, $original_blocks, $rewritten_blocks, $builder) {
    foreach ($original_blocks as $i => $block) {
        if (!isset($rewritten_blocks[$i]) || empty(trim($rewritten_blocks[$i]))) continue;

        $new_text = trim($rewritten_blocks[$i]);

        // Replace the raw text in content while preserving HTML structure
        if (isset($block['full_match'])) {
            // Divi/WPBakery: replace inner content of shortcode
            $new_inner = str_replace($block['text'], $new_text, $block['raw']);
            $new_full = str_replace($block['raw'], $new_inner, $block['full_match']);
            $content = str_replace($block['full_match'], $new_full, $content);
        } else {
            // Generic: replace the raw HTML portion
            $new_raw = str_replace($block['text'], $new_text, $block['raw']);
            $content = str_replace($block['raw'], $new_raw, $content);
        }
    }
    return $content;
}

function kpg_call_ai_api($prompt, $settings) {
    $api_key = kpg_decrypt($settings['api_key'] ?? '');
    if (empty($api_key)) {
        return ['success' => false, 'error' => 'No API key configured'];
    }

    $model = $settings['model'] ?? '';
    $provider = $settings['provider'] ?? 'openai';

    if ($provider === 'anthropic') {
        return kpg_call_anthropic($prompt, $api_key, $model ?: 'claude-sonnet-4-6');
    }
    if ($provider === 'gemini') {
        return kpg_call_gemini($prompt, $api_key, $model ?: 'gemini-2.0-flash');
    }
    return kpg_call_openai($prompt, $api_key, $model ?: 'gpt-4o-mini');
}

function kpg_call_openai($prompt, $api_key, $model) {
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'timeout' => 60,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode([
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a professional content rewriter. Follow instructions exactly.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.7,
            'max_tokens'  => 4000,
        ]),
    ]);

    if (is_wp_error($response)) {
        return ['success' => false, 'error' => $response->get_error_message()];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body['choices'][0]['message']['content'])) {
        return ['success' => true, 'text' => $body['choices'][0]['message']['content']];
    }

    $error = $body['error']['message'] ?? 'Unknown OpenAI error';
    return ['success' => false, 'error' => $error];
}

function kpg_call_anthropic($prompt, $api_key, $model) {
    $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'timeout' => 60,
        'headers' => [
            'x-api-key'         => $api_key,
            'anthropic-version'  => '2023-06-01',
            'Content-Type'       => 'application/json',
        ],
        'body' => wp_json_encode([
            'model'      => $model,
            'max_tokens' => 4000,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]),
    ]);

    if (is_wp_error($response)) {
        return ['success' => false, 'error' => $response->get_error_message()];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body['content'][0]['text'])) {
        return ['success' => true, 'text' => $body['content'][0]['text']];
    }

    $error = $body['error']['message'] ?? 'Unknown Anthropic error';
    return ['success' => false, 'error' => $error];
}

function kpg_call_gemini($prompt, $api_key, $model) {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model) . ':generateContent?key=' . urlencode($api_key);
    $response = wp_remote_post($url, [
        'timeout' => 60,
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode([
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
        ]),
    ]);

    if (is_wp_error($response)) {
        return ['success' => false, 'error' => $response->get_error_message()];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body['candidates'][0]['content']['parts'][0]['text'])) {
        return ['success' => true, 'text' => $body['candidates'][0]['content']['parts'][0]['text']];
    }

    $error = $body['error']['message'] ?? 'Unknown Gemini error';
    return ['success' => false, 'error' => $error];
}

function kpg_ai_rewrite_elementor_meta($post_id, $replacements, $ai_settings, $custom_prompt = '', $scope = 'widget') {
    $data = get_post_meta($post_id, '_elementor_data', true);
    if (empty($data)) return;

    $decoded = is_string($data) ? json_decode($data, true) : $data;
    if (!is_array($decoded)) return;

    $keywords = array_map(fn($r) => $r['new'], $replacements);
    $prompt_template = $custom_prompt ?: ($ai_settings['custom_prompt'] ?? '');

    if ($scope === 'section') {
        // Section scope: one API call per top-level section, all widgets in section together
        foreach ($decoded as &$section) {
            if (($section['elType'] ?? '') !== 'section') continue;

            $texts = [];
            $idx   = 0;
            kpg_elementor_walk_widgets($section['elements'] ?? [], $texts, 'extract', $idx);
            if (empty($texts)) continue;

            $prompt = kpg_build_ai_prompt(
                array_map(fn($t) => ['text' => $t], $texts),
                $keywords,
                $prompt_template
            );

            $response = kpg_call_ai_api($prompt, $ai_settings);
            if (!$response['success']) continue;

            $rewritten = array_map('trim', explode('---BLOCK---', $response['text']));
            $idx = 0;
            kpg_elementor_walk_widgets($section['elements'] ?? [], $rewritten, 'replace', $idx);
            sleep(1); // Rate limiting between sections
        }
    } else {
        // Widget scope (default): all widgets across the page in one call
        $texts = [];
        $idx   = 0;
        kpg_elementor_walk_widgets($decoded, $texts, 'extract', $idx);
        if (empty($texts)) return;

        $prompt = kpg_build_ai_prompt(
            array_map(fn($t) => ['text' => $t], $texts),
            $keywords,
            $prompt_template
        );

        $response = kpg_call_ai_api($prompt, $ai_settings);
        if (!$response['success']) return;

        $rewritten = array_map('trim', explode('---BLOCK---', $response['text']));
        $idx = 0;
        kpg_elementor_walk_widgets($decoded, $rewritten, 'replace', $idx);
    }

    update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($decoded)));
}

function kpg_elementor_walk_widgets(&$elements, &$texts, $mode = 'extract', &$index = 0) {
    foreach ($elements as &$element) {
        if (isset($element['widgetType']) && in_array($element['widgetType'], ['text-editor', 'heading', 'text-path'], true)) {
            $settings = &$element['settings'];
            $text_keys = ['editor', 'title', 'text'];
            foreach ($text_keys as $key) {
                if (!empty($settings[$key]) && strlen(strip_tags($settings[$key])) > 20) {
                    if ($mode === 'extract') {
                        $texts[] = strip_tags($settings[$key]);
                    } elseif ($mode === 'replace' && isset($texts[$index])) {
                        // Preserve HTML structure, replace text content
                        $old_text = strip_tags($settings[$key]);
                        $settings[$key] = str_replace($old_text, $texts[$index], $settings[$key]);
                        $index++;
                    }
                }
            }
        }

        if (!empty($element['elements'])) {
            kpg_elementor_walk_widgets($element['elements'], $texts, $mode, $index);
        }
    }
}

// ============================================================
// Utility
// ============================================================

function kpg_delete_old_previews() {
    $previews = get_posts([
        'post_type'      => ['page', 'post'],
        'post_status'    => ['draft', 'pending', 'private'],
        'posts_per_page' => 50,
        'meta_key'       => '_kpg_preview_flag',
        'meta_value'     => '1',
        'fields'         => 'ids',
    ]);
    foreach ($previews as $post_id) {
        wp_delete_post($post_id, true);
    }
}

/**
 * One-time Elementor meta normalizer.
 */
function kpg_elementor_meta_normalizer_run_once() {
    if (get_option('kpg_elementor_meta_normalizer_done')) return;
    if (!current_user_can('manage_options')) return;

    $meta_keys_to_check = ['_elementor_page_settings', '_elementor_data', '_elementor_css'];

    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($meta_keys_to_check), '%s'));
    $sql = "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders)";
    $prepared = $wpdb->prepare($sql, $meta_keys_to_check);
    $post_ids = $wpdb->get_col($prepared);

    $fixed_count = 0;
    $checked_count = 0;

    foreach ($post_ids as $post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'page') continue;

        $did_fix = false;
        foreach ($meta_keys_to_check as $meta_key) {
            $checked_count++;
            $raw = get_post_meta($post_id, $meta_key, true);

            if (is_array($raw) || is_object($raw) || $raw === '' || $raw === null || !is_string($raw)) continue;

            $fixed_value = null;

            $maybe = maybe_unserialize($raw);
            if ($maybe !== $raw && (is_array($maybe) || is_object($maybe))) {
                $fixed_value = $maybe;
            }

            if ($fixed_value === null) {
                $json = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                    $fixed_value = $json;
                }
            }

            if ($fixed_value === null) {
                $stripped = wp_unslash($raw);
                $maybe2 = maybe_unserialize($stripped);
                if ($maybe2 !== $stripped && (is_array($maybe2) || is_object($maybe2))) {
                    $fixed_value = $maybe2;
                } else {
                    $json2 = json_decode($stripped, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($json2)) {
                        $fixed_value = $json2;
                    }
                }
            }

            if ($fixed_value !== null) {
                update_post_meta($post_id, $meta_key, $fixed_value);
                $did_fix = true;
            }
        }
        if ($did_fix) $fixed_count++;
    }

    update_option('kpg_elementor_meta_normalizer_done', 1);

    add_action('admin_notices', function() use ($fixed_count, $checked_count) {
        $class = $fixed_count > 0 ? 'updated' : 'notice-warning';
        printf(
            '<div class="%1$s"><p><strong>KPG Elementor meta normalizer:</strong> Checked %2$d entries; fixed %3$d pages.</p></div>',
            esc_attr($class), intval($checked_count), intval($fixed_count)
        );
    });
}

// ============================================================
// AJAX: Get Templates by Post Type
// ============================================================

function kpg_ajax_get_templates() {
    check_ajax_referer('kpg_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $post_type = in_array($_POST['post_type'] ?? 'page', ['page', 'post'], true) ? $_POST['post_type'] : 'page';
    $posts = get_posts([
        'post_type'      => $post_type,
        'post_status'    => 'publish',
        'posts_per_page' => 200,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);

    $options = '<option value="">Select a template...</option>';
    foreach ($posts as $p) {
        $options .= '<option value="' . esc_attr($p->ID) . '">' . esc_html($p->post_title) . '</option>';
    }

    wp_send_json_success(['options' => $options]);
}

// ============================================================
// AJAX Batch Processing
// ============================================================

function kpg_ajax_start_batch() {
    check_ajax_referer('kpg_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $base_page_id = intval($_POST['base_page'] ?? 0);
    $base_page    = get_post($base_page_id);
    $pairs        = kpg_parse_pairs_from_post();
    $mode         = sanitize_text_field($_POST['generation_mode'] ?? 'matrix');
    $post_type    = in_array($_POST['post_type'] ?? 'page', ['page', 'post'], true) ? $_POST['post_type'] : 'page';
    $ai_enabled   = !empty($_POST['ai_rewrite']);
    $ai_prompt    = sanitize_textarea_field($_POST['ai_prompt'] ?? '');
    $ai_scope     = in_array($_POST['ai_scope'] ?? 'widget', ['widget', 'section'], true) ? $_POST['ai_scope'] : 'widget';

    if (!$base_page || empty($pairs)) {
        wp_send_json_error('Invalid base page or keyword pairs.');
    }

    $page_specs = kpg_build_page_specs($pairs, $mode);

    if (count($page_specs) > KPG_PAGE_LIMIT) {
        wp_send_json_error('Too many pages. Maximum is ' . KPG_PAGE_LIMIT . '. You requested ' . count($page_specs) . '.');
    }

    kpg_delete_old_previews();

    $builder     = kpg_detect_page_builder($base_page_id);
    $ai_settings = $ai_enabled ? kpg_get_ai_settings() : null;
    $job_id      = wp_generate_password(16, false);

    set_transient('kpg_batch_' . $job_id, [
        'base_page_id' => $base_page_id,
        'page_specs'   => $page_specs,
        'post_type'    => $post_type,
        'builder'      => $builder,
        'ai_enabled'   => $ai_enabled,
        'ai_settings'  => $ai_settings,
        'ai_prompt'    => $ai_prompt,
        'ai_scope'     => $ai_scope,
        'total'        => count($page_specs),
        'completed'    => 0,
        'results'      => [],
        'cancelled'    => false,
    ], 2 * HOUR_IN_SECONDS);

    wp_send_json_success(['job_id' => $job_id, 'total' => count($page_specs)]);
}

function kpg_ajax_process_step() {
    check_ajax_referer('kpg_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $job_id = sanitize_text_field($_POST['job_id'] ?? '');
    $step   = intval($_POST['step'] ?? 0);
    $job    = get_transient('kpg_batch_' . $job_id);

    if (!$job) {
        wp_send_json_error('Job not found or expired.');
    }

    if ($job['cancelled']) {
        wp_send_json_error('Job was cancelled.');
    }

    if ($step >= $job['total']) {
        wp_send_json_error('All steps already processed.');
    }

    $base_page = get_post($job['base_page_id']);
    if (!$base_page) {
        wp_send_json_error('Base page no longer exists.');
    }

    $spec   = $job['page_specs'][$step];
    $result = kpg_create_single_page(
        $base_page, $spec, $job['post_type'], $job['builder'],
        $job['ai_enabled'], $job['ai_settings'], $job['ai_prompt'], $job['ai_scope'] ?? 'widget'
    );

    $job['results'][]  = $result;
    $job['completed']  = $step + 1;
    set_transient('kpg_batch_' . $job_id, $job, 2 * HOUR_IN_SECONDS);

    wp_send_json_success([
        'step'      => $step,
        'result'    => $result,
        'remaining' => $job['total'] - $job['completed'],
    ]);
}

function kpg_ajax_cancel_batch() {
    check_ajax_referer('kpg_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $job_id = sanitize_text_field($_POST['job_id'] ?? '');
    $job    = get_transient('kpg_batch_' . $job_id);

    if (!$job) {
        wp_send_json_error('Job not found.');
    }

    $job['cancelled'] = true;
    set_transient('kpg_batch_' . $job_id, $job, 2 * HOUR_IN_SECONDS);

    $created = count(array_filter($job['results'], fn($r) => $r['status'] === 'created'));
    wp_send_json_success([
        'message'   => 'Job cancelled.',
        'completed' => $job['completed'],
        'created'   => $created,
    ]);
}

// ============================================================
// WP-Cron Scheduled Generation
// ============================================================

function kpg_add_cron_interval($schedules) {
    $schedules['kpg_every_minute'] = [
        'interval' => 60,
        'display'  => __('Every Minute (KPG)', 'keyword-page-generator'),
    ];
    return $schedules;
}

function kpg_ajax_schedule_job() {
    check_ajax_referer('kpg_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    // Check for existing job
    $existing = get_option('kpg_cron_job');
    if ($existing && ($existing['status'] ?? '') === 'in_progress') {
        wp_send_json_error('A scheduled job is already in progress. Clear it first.');
    }

    $base_page_id = intval($_POST['base_page'] ?? 0);
    $base_page    = get_post($base_page_id);
    $pairs        = kpg_parse_pairs_from_post();
    $mode         = sanitize_text_field($_POST['generation_mode'] ?? 'matrix');
    $post_type    = in_array($_POST['post_type'] ?? 'page', ['page', 'post'], true) ? $_POST['post_type'] : 'page';
    $ai_enabled   = !empty($_POST['ai_rewrite']);
    $ai_prompt    = sanitize_textarea_field($_POST['ai_prompt'] ?? '');
    $schedule_mode = sanitize_text_field($_POST['schedule_mode'] ?? 'background');
    $schedule_time = sanitize_text_field($_POST['schedule_time'] ?? '');

    if (!$base_page || empty($pairs)) {
        wp_send_json_error('Invalid base page or keyword pairs.');
    }

    $page_specs = kpg_build_page_specs($pairs, $mode);

    if (count($page_specs) > KPG_PAGE_LIMIT) {
        wp_send_json_error('Too many pages. Maximum is ' . KPG_PAGE_LIMIT . '.');
    }

    $builder     = kpg_detect_page_builder($base_page_id);
    $ai_settings = $ai_enabled ? kpg_get_ai_settings() : null;

    $job_data = [
        'base_page_id'  => $base_page_id,
        'page_specs'    => $page_specs,
        'post_type'     => $post_type,
        'builder'       => $builder,
        'ai_enabled'    => $ai_enabled,
        'ai_settings'   => $ai_settings,
        'ai_prompt'     => $ai_prompt,
        'total'         => count($page_specs),
        'completed'     => 0,
        'results'       => [],
        'status'        => 'pending',
        'schedule_type' => $schedule_mode,
        'scheduled_time' => '',
    ];

    // Clear any existing scheduled hook
    wp_clear_scheduled_hook('kpg_cron_process_job');

    if ($schedule_mode === 'timed' && !empty($schedule_time)) {
        $timestamp = strtotime($schedule_time);
        if (!$timestamp || $timestamp < time()) {
            wp_send_json_error('Invalid or past date/time.');
        }
        $job_data['scheduled_time'] = $schedule_time;
        wp_schedule_single_event($timestamp, 'kpg_cron_process_job');
    } else {
        // Background: start immediately
        $job_data['status'] = 'in_progress';
        wp_schedule_event(time(), 'kpg_every_minute', 'kpg_cron_process_job');
    }

    update_option('kpg_cron_job', $job_data);

    wp_send_json_success([
        'message' => $schedule_mode === 'timed'
            ? 'Job scheduled for ' . $schedule_time
            : 'Background job started. Pages will be created via WP-Cron.',
        'total' => count($page_specs),
    ]);
}

function kpg_cron_process_single_step() {
    $job = get_option('kpg_cron_job');
    if (!$job || ($job['status'] ?? '') === 'completed') {
        wp_clear_scheduled_hook('kpg_cron_process_job');
        return;
    }

    // Acquire lock to prevent double-processing
    if (get_transient('kpg_cron_lock')) return;
    set_transient('kpg_cron_lock', true, 60);

    // If this is a timed job that was pending, start it now and schedule recurring
    if (($job['status'] ?? '') === 'pending') {
        $job['status'] = 'in_progress';
        wp_clear_scheduled_hook('kpg_cron_process_job');
        wp_schedule_event(time(), 'kpg_every_minute', 'kpg_cron_process_job');
    }

    $step = $job['completed'];
    if ($step >= $job['total']) {
        $job['status'] = 'completed';
        update_option('kpg_cron_job', $job);
        wp_clear_scheduled_hook('kpg_cron_process_job');
        delete_transient('kpg_cron_lock');
        return;
    }

    $base_page = get_post($job['base_page_id']);
    if (!$base_page) {
        $job['status'] = 'completed';
        update_option('kpg_cron_job', $job);
        wp_clear_scheduled_hook('kpg_cron_process_job');
        delete_transient('kpg_cron_lock');
        return;
    }

    $spec   = $job['page_specs'][$step];
    $result = kpg_create_single_page(
        $base_page, $spec, $job['post_type'], $job['builder'],
        $job['ai_enabled'], $job['ai_settings'], $job['ai_prompt'], $job['ai_scope'] ?? 'widget'
    );

    $job['results'][] = $result;
    $job['completed'] = $step + 1;

    if ($job['completed'] >= $job['total']) {
        $job['status'] = 'completed';
        wp_clear_scheduled_hook('kpg_cron_process_job');
    }

    update_option('kpg_cron_job', $job);
    delete_transient('kpg_cron_lock');
}

function kpg_ajax_clear_job() {
    check_ajax_referer('kpg_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    wp_clear_scheduled_hook('kpg_cron_process_job');
    delete_option('kpg_cron_job');
    delete_transient('kpg_cron_lock');

    wp_send_json_success(['message' => 'Job cleared.']);
}

function kpg_deactivation() {
    wp_clear_scheduled_hook('kpg_cron_process_job');
    delete_option('kpg_cron_job');
    delete_transient('kpg_cron_lock');

    // Clean up batch transients
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_kpg_batch_%' OR option_name LIKE '_transient_timeout_kpg_batch_%'");
}
