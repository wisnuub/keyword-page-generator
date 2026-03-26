<?php
/**
 * Plugin Name: Suburb Page Generator
 * Description: Generates suburb-specific pages by replacing placeholders in content and metadata. Supports Divi, Elementor and Yoast SEO.
 * Version: 1.0
 * Author: By Steven Chun (modified by Wisnu)
 * Author URI: https://wisnuub.github.io/
 */

defined('ABSPATH') || exit;

define('SPG_SUBURB_LIMIT', 20);
add_action('admin_menu', 'spg_add_admin_menu');
add_action('admin_init', 'spg_elementor_meta_normalizer_run_once'); // one-time fixer
add_action('admin_enqueue_scripts', 'spg_enqueue_admin_assets');

function spg_add_admin_menu() {
    add_menu_page('Suburb Page Generator', 'Suburb Pages', 'manage_options', 'suburb-page-generator', 'spg_admin_page', 'dashicons-location', 25);
}

function spg_enqueue_admin_assets() {
    $current_screen = get_current_screen();
    
    if (!$current_screen || $current_screen->id !== 'toplevel_page_suburb-page-generator') {
        return;
    }
    
    wp_enqueue_style(
        'spg-admin-styles',
        plugins_url('assets/spg-admin-styles.css', __FILE__),
        array(),
        '1.0',
        'all'
    );
    
    wp_enqueue_script(
        'spg-admin-script',
        plugins_url('assets/spg-admin-script.js', __FILE__),
        array('jquery'),
        '1.0',
        true
    );
    
    wp_localize_script('spg-admin-script', 'spgData', array(
        'nonce' => wp_create_nonce('spg_admin_nonce'),
        'i18n' => array(
            'loading' => __('Loading...', 'suburb-page-generator'),
            'error' => __('An error occurred. Please try again.', 'suburb-page-generator'),
        )
    ));
}

function spg_admin_page() {
    spg_delete_old_previews();
    if (!current_user_can('manage_options')) wp_die(__('Unauthorized user'));

    spg_enqueue_admin_assets();

    $preview_results = [];
    $has_error = false;

    if (isset($_POST['spg_preview'])) {
        $preview_results = spg_generate_preview();
    } elseif (isset($_POST['spg_submit'])) {
        spg_process_form();
    }

    $pages = get_pages();
    ?>
    <div class="spg-admin-wrapper">
        <!-- Header Section -->
        <div class="spg-admin-header">
            <div class="spg-header-content">
                <h1 class="spg-page-title">
                    <span class="spg-icon">🏘️</span>
                    Suburb Page Generator
                </h1>
                <p class="spg-page-subtitle">Create multiple pages for different suburbs in seconds</p>
            </div>
        </div>

        <div class="spg-admin-container">
            <!-- Main Form Card -->
            <div class="spg-form-card spg-card">
                <div class="spg-card-header">
                    <h2 class="spg-card-title">Generate Pages</h2>
                    <p class="spg-card-description">Fill in the details below to create suburb-specific pages from your base template</p>
                </div>

                <form method="post" class="spg-form">
                    <!-- Step 1: Suburb List -->
                    <div class="spg-form-section">
                        <div class="spg-section-header">
                            <h3 class="spg-section-title">
                                <span class="spg-step-number">1</span>
                                Suburb List
                            </h3>
                        </div>
                        
                        <div class="spg-form-group">
                            <?php spg_form_field('Suburb List', 'suburbs_list', true, 'Enter suburbs separated by commas. Example: Sydney, Melbourne, Brisbane'); ?>
                        </div>
                    </div>

                    <!-- Step 2: Replace Keyword -->
                    <div class="spg-form-section">
                        <div class="spg-section-header">
                            <h3 class="spg-section-title">
                                <span class="spg-step-number">2</span>
                                Current Suburb Name
                            </h3>
                        </div>

                        <div class="spg-form-group">
                            <?php spg_form_field('Old Suburb Name', 'old_keyword', false, 'The suburb name in your template that will be replaced'); ?>
                        </div>
                    </div>

                    <!-- Step 3: Base Page Selection -->
                    <div class="spg-form-section">
                        <div class="spg-section-header">
                            <h3 class="spg-section-title">
                                <span class="spg-step-number">3</span>
                                Base Page Template
                            </h3>
                        </div>

                        <div class="spg-form-group">
                            <?php spg_form_page_select('Select the page to use as template', $pages); ?>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="spg-form-actions">
                        <button type="submit" name="spg_preview" class="button spg-btn spg-btn-secondary" aria-label="Preview the page generation">
                            <span class="spg-btn-icon">👁️</span>
                            Preview Page
                        </button>
                        <button type="submit" name="spg_submit" class="button spg-btn spg-btn-primary" aria-label="Generate all suburb pages">
                            <span class="spg-btn-icon">✨</span>
                            Generate Pages
                        </button>
                    </div>
                </form>
            </div>

            <!-- Preview Results Card -->
            <?php if (!empty($preview_results)) : ?>
                <div class="spg-preview-card spg-card">
                    <div class="spg-card-header">
                        <h2 class="spg-card-title">Preview</h2>
                        <p class="spg-card-description">Review the template preview for one suburb</p>
                    </div>

                    <div class="spg-preview-content">
                        <?php foreach ($preview_results as $result) : ?>
                            <div class="spg-preview-item">
                                <!-- Preview Header -->
                                <div class="spg-preview-header">
                                    <div class="spg-preview-title">
                                        <h3><?php echo esc_html($result['title']); ?></h3>
                                        <code class="spg-preview-slug"><?php echo esc_html($result['slug']); ?></code>
                                    </div>
                                    <a href="<?php echo esc_url($result['preview_url']); ?>" class="button spg-btn spg-btn-outline" target="_blank" rel="noopener noreferrer" aria-label="View draft page in new window">
                                        <span class="spg-btn-icon">🔍</span>
                                        View Draft
                                    </a>
                                </div>

                                <!-- Preview Content -->
                                <div class="spg-preview-details">
                                    <div class="spg-detail-group">
                                        <label class="spg-detail-label">Content Preview</label>
                                        <p class="spg-detail-content"><?php echo esc_html(wp_trim_words($result['content'], 40)); ?></p>
                                    </div>

                                    <div class="spg-detail-group">
                                        <label class="spg-detail-label">Suburbs to Generate</label>
                                        <ul class="spg-suburb-list" role="list">
                                            <?php foreach ($result['suburbs_list'] as $suburb_name) : ?>
                                                <li role="listitem" class="spg-suburb-item"><?php echo esc_html($suburb_name); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Help & Info Card -->
            <div class="spg-info-card spg-card">
                <div class="spg-card-header">
                    <h2 class="spg-card-title">💡 How It Works</h2>
                </div>
                <div class="spg-info-content">
                    <ol class="spg-info-list">
                        <li>
                            <strong>Select your base page:</strong> Choose the page that contains your suburb-specific content template with placeholders.
                        </li>
                        <li>
                            <strong>Specify the current suburb:</strong> Enter the suburb name that appears in your template that should be replaced.
                        </li>
                        <li>
                            <strong>List your suburbs:</strong> Enter all the suburbs you want to generate pages for, separated by commas.
                        </li>
                        <li>
                            <strong>Preview & Generate:</strong> Use the preview button to verify the first page, then click Generate Pages to create all variations.
                        </li>
                    </ol>
                    <div class="spg-info-tip">
                        <strong>💫 Tip:</strong> The generator supports Divi, Elementor, and Yoast SEO. All metadata and content variations are automatically handled.
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function spg_form_field($label, $name, $is_textarea = false, $placeholder = '') {
    $value = esc_attr($_POST[$name] ?? '');
    $field_id = 'spg_field_' . $name;
    
    echo '<div class="spg-form-field">';
    echo '<label for="' . esc_attr($field_id) . '" class="spg-label">';
    echo esc_html($label);
    echo '</label>';
    
    if ($is_textarea) {
        echo '<textarea
                id="' . esc_attr($field_id) . '"
                name="' . esc_attr($name) . '"
                class="spg-input spg-textarea"
                rows="5"
                required
                aria-required="true"
                aria-describedby="' . esc_attr($field_id) . '-help"
                placeholder="' . esc_attr($placeholder) . '"
                tabindex="0">' . esc_textarea($_POST[$name] ?? '') . '</textarea>';
    } else {
        echo '<input
                type="text"
                id="' . esc_attr($field_id) . '"
                name="' . esc_attr($name) . '"
                class="spg-input"
                required
                aria-required="true"
                aria-describedby="' . esc_attr($field_id) . '-help"
                placeholder="' . esc_attr($placeholder) . '"
                value="' . $value . '"
                tabindex="0">';
    }
    
    if (!empty($placeholder)) {
        echo '<p id="' . esc_attr($field_id) . '-help" class="spg-field-help">';
        echo esc_html($placeholder);
        echo '</p>';
    }
    
    echo '</div>';
}

function spg_form_page_select($label, $pages) {
    $selected_id = $_POST['base_page'] ?? '';
    $field_id = 'spg_field_base_page';
    
    echo '<div class="spg-form-field">';
    echo '<label for="' . esc_attr($field_id) . '" class="spg-label">';
    echo esc_html($label);
    echo '</label>';
    echo '<select
            id="' . esc_attr($field_id) . '"
            name="base_page"
            class="spg-select"
            required
            aria-required="true"
            aria-describedby="base_page-help"
            tabindex="0">';
    
    echo '<option value="">' . esc_html(__('Select a page...', 'suburb-page-generator')) . '</option>';
    
    foreach ($pages as $page) {
        $selected = selected($selected_id, $page->ID, false);
        echo '<option value="' . esc_attr($page->ID) . '" ' . $selected . '>';
        echo esc_html($page->post_title);
        echo '</option>';
    }
    
    echo '</select>';
    echo '<p id="base_page-help" class="spg-field-help">';
    echo esc_html(__('Select the page template containing your suburb placeholders and content', 'suburb-page-generator'));
    echo '</p>';
    echo '</div>';
}

function spg_generate_preview() {
    $suburbs_list       = sanitize_text_field($_POST['suburbs_list']);
    $old_suburb_raw     = sanitize_text_field($_POST['old_keyword']);
    $old_suburb_slug    = sanitize_title_with_dashes($old_suburb_raw);
    $base_page_id       = intval($_POST['base_page']);
    $suburbs            = spg_get_suburb_list($suburbs_list);
    $base_page          = get_post($base_page_id);

    if (!$base_page) {
        echo '<div class="notice notice-error"><p>⚠️ Base page not found (ID: ' . esc_html($base_page_id) . ')</p></div>';
        return [];
    }
    if (empty($suburbs)) {
        echo '<div class="notice notice-warning"><p>⚠️ No valid suburbs found in the list</p></div>';
        return [];
    }

    spg_delete_old_previews();
    $first_suburb = $suburbs;
    $original_slug    = $base_page->post_name;
    $original_title   = $base_page->post_title;
    $original_content = $base_page->post_content;

    $suburb_slug  = sanitize_title_with_dashes($first_suburb);
    // Handle Divi/Elementor content replacement
    $new_content  = spg_replace_content($original_content, $old_suburb_raw, $first_suburb);
    $new_title    = str_ireplace([$old_suburb_raw, '{suburb}', '{keyword}'], $first_suburb, $original_title);
    $new_slug     = str_ireplace($old_suburb_slug, $suburb_slug, $original_slug);

    if (get_page_by_path($new_slug)) {
        $new_slug .= '-' . wp_generate_password(4, false);
    }

    $preview_id = wp_insert_post([
        'post_title'   => $new_title,
        'post_name'    => $new_slug,
        'post_content' => $new_content,
        'post_status'  => 'draft',
        'post_type'    => 'page',
        'post_parent'  => $base_page->post_parent
    ]);

    if ($preview_id && !is_wp_error($preview_id)) {
        // Safely copy postmeta (handles Elementor/Divi/Yoast replacements)
        spg_copy_post_meta($base_page_id, $preview_id, $old_suburb_raw, $first_suburb);
        update_post_meta($preview_id, '_spg_preview_flag', '1');
    }

    return [[
        'title'        => $new_title,
        'slug'         => $new_slug,
        'content'      => $new_content,
        'preview_url'  => get_preview_post_link($preview_id),
        'suburbs_list' => $suburbs
    ]];
}

function spg_process_form() {
    $suburbs_list       = sanitize_text_field($_POST['suburbs_list']);
    $old_suburb_raw     = sanitize_text_field($_POST['old_keyword']);
    $old_suburb_slug    = sanitize_title_with_dashes($old_suburb_raw);
    $base_page_id       = intval($_POST['base_page']);
    $suburbs            = spg_get_suburb_list($suburbs_list);
    $base_page          = get_post($base_page_id);

    if (!$base_page || empty($suburbs)) {
        echo '<div class="notice notice-error"><p>⚠️ Unable to process. Base page or suburb list is missing.</p></div>';
        return;
    }

    $original_slug    = $base_page->post_name;
    $original_title   = $base_page->post_title;
    $original_content = $base_page->post_content;

    foreach ($suburbs as $suburb) {
        $suburb_slug  = sanitize_title_with_dashes($suburb);
        $new_title    = str_ireplace([$old_suburb_raw, '{suburb}', '{keyword}'], $suburb, $original_title);
        $new_slug     = str_ireplace($old_suburb_slug, $suburb_slug, $original_slug);
        $new_content  = spg_replace_content($original_content, $old_suburb_raw, $suburb);

        if (get_page_by_path($new_slug)) {
            $new_slug .= '-' . wp_generate_password(4, false);
        }

        // insert the new page first
        $new_page_id = wp_insert_post([
            'post_title'   => $new_title,
            'post_name'    => $new_slug,
            'post_content' => $new_content,
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_parent'  => $base_page->post_parent
        ]);

        if ($new_page_id && !is_wp_error($new_page_id)) {
            // copy all relevant postmeta (Elementor/Divi/Yoast) from base page, doing replacement where appropriate
            spg_copy_post_meta($base_page_id, $new_page_id, $old_suburb_raw, $suburb);

            // SEO meta handled inside spg_copy_post_meta already, but keep compatibility function:
            spg_update_seo_meta($base_page_id, $new_page_id, $old_suburb_raw, $suburb);
        }
    }

    echo '<div class="notice notice-success"><p>Pages published successfully.</p></div>';
}

function spg_get_suburb_list($suburbs_input) {
    if (empty($suburbs_input)) {
        return [];
    }
    
    $suburbs = array_map('trim', explode(',', $suburbs_input));
    $suburbs = array_filter($suburbs, fn($s) => !empty($s));
    
    // Validate each suburb contains only letters, spaces, and hyphens
    $valid_suburbs = [];
    foreach ($suburbs as $suburb) {
        if (preg_match('/^[a-zA-Z\s-]+$/', $suburb)) {
            $valid_suburbs[] = $suburb;
        }
    }
    
    return $valid_suburbs;
}

function spg_replace_content($content, $old, $new) {
    // Replace in regular content (case-insensitive)
    $content = str_ireplace([$old, '{suburb}', '{keyword}'], $new, $content);
    
    // Handle Divi shortcodes - process each match individually
    if (preg_match_all('/\[et_pb_text.*?\](.*?)\[\/et_pb_text\]/is', $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $replaced = str_ireplace($old, $new, $match);
            $content = str_replace($match, $replaced, $content);
        }
    }
    
    return $content;
}

/**
 * Copy all post meta from $from_post to $to_post, performing safe replacements.
 * Use get_post_meta(..., true) to preserve types and avoid corrupting serialized data.
 * Skips meta keys which should not be copied.
 */
function spg_copy_post_meta($from_post_id, $to_post_id, $old, $new_suburb) {
    // Get all meta keys for the source post
    $all_meta = get_post_meta($from_post_id); // returns meta_key => array(values)
    $meta_keys = is_array($all_meta) ? array_keys($all_meta) : [];

    // List of meta keys to skip copying (edit locks, last editor, preview flags, features that are ID-specific)
    $skip_keys = [
        '_wp_old_slug', '_edit_lock', '_edit_last', '_spg_preview_flag',
        '_yoast_indexable', // often regenerated
    ];

    foreach ($meta_keys as $meta_key) {
        if (in_array($meta_key, $skip_keys, true)) {
            continue;
        }

        // Read single value (true) to preserve arrays/objects as PHP types (WP will unserialize them)
        $orig_value = get_post_meta($from_post_id, $meta_key, true);

        // Do a safe recursive replacement (handles arrays/objects/strings)
        $new_value = spg_recursive_replace_maybe_serialized($orig_value, $old, $new_suburb);

        // Write it to the new post
        update_post_meta($to_post_id, $meta_key, $new_value);
    }

    // Ensure certain builder flags exist for Elementor / Divi so editor recognizes the page builder
    // Elementor: ensure edit mode and version keys are present if present in source
    $elementor_edit_mode = get_post_meta($from_post_id, '_elementor_edit_mode', true);
    if ($elementor_edit_mode) {
        update_post_meta($to_post_id, '_elementor_edit_mode', $elementor_edit_mode);
    } else {
        // set a safe default if the source didn't have it but elementor data exists
        if (get_post_meta($to_post_id, '_elementor_data', true) && !get_post_meta($to_post_id, '_elementor_edit_mode', true)) {
            update_post_meta($to_post_id, '_elementor_edit_mode', 'builder');
        }
    }

    $elementor_ver = get_post_meta($from_post_id, '_elementor_version', true);
    if ($elementor_ver) {
        update_post_meta($to_post_id, '_elementor_version', $elementor_ver);
    }

    // Copy Divi custom CSS if present
    $divi_custom_css = get_post_meta($from_post_id, '_et_pb_post_custom_css', true);
    if ($divi_custom_css) {
        update_post_meta($to_post_id, '_et_pb_post_custom_css', spg_recursive_replace_maybe_serialized($divi_custom_css, $old, $new_suburb));
    }
}

/**
 * Update Yoast SEO fields (kept as a safety wrapper).
 */
function spg_update_seo_meta($base_page_id, $new_page_id, $old, $new) {
    $meta_fields = [
        '_yoast_wpseo_title',
        '_yoast_wpseo_metadesc',
        '_yoast_wpseo_focuskw'
    ];
    
    foreach ($meta_fields as $field) {
        $value = get_post_meta($base_page_id, $field, true);
        if ($value) {
            update_post_meta(
                $new_page_id,
                $field,
                str_ireplace($old, $new, $value)
            );
        }
    }
}

/**
 * Recursively replace $old with $new inside $value.
 * If $value is serialized string, maybe_unserialize will already have returned the structured value
 * when using get_post_meta(..., true), but we still handle strings/arrays/objects generically.
 */
function spg_recursive_replace_maybe_serialized($value, $old, $new) {
    // If it's a string that *looks* serialized, maybe_unserialize will change it to structured
    if (is_string($value)) {
        $maybe = maybe_unserialize($value);
        if ($maybe !== $value) {
            // was serialized
            return spg_recursive_replace($maybe, $old, $new);
        }
    }

    return spg_recursive_replace($value, $old, $new);
}

/**
 * Recursively replace $old with $new in arrays/objects/strings.
 */
function spg_recursive_replace($data, $old, $new) {
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            $data[$k] = spg_recursive_replace($v, $old, $new);
        }
        return $data;
    } elseif (is_object($data)) {
        // operate on object properties
        foreach ($data as $prop => $val) {
            $data->$prop = spg_recursive_replace($val, $old, $new);
        }
        return $data;
    } elseif (is_string($data)) {
        // perform case-insensitive replacements and placeholders
        return str_ireplace([$old, '{suburb}', '{keyword}'], $new, $data);
    } else {
        return $data; // int, bool, null left untouched
    }
}

function spg_delete_old_previews() {
    $args = [
        'post_type'      => 'page',
        'post_status'    => ['draft', 'pending', 'private'],
        'posts_per_page' => -1,
        'meta_key'       => '_spg_preview_flag',
        'meta_value'     => '1',
        'fields'         => 'ids'
    ];

    $previews = get_posts($args);
    foreach ($previews as $post_id) {
        wp_delete_post($post_id, true);
    }
}

/**
 * One-time Elementor meta normalizer for recovery.
 * Runs once (admin-only) to try to fix corrupted Elementor meta (serialized vs JSON vs slashed).
 */
function spg_elementor_meta_normalizer_run_once() {
    if (get_option('spg_elementor_meta_normalizer_done')) {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    // meta keys to check
    $meta_keys_to_check = [
        '_elementor_page_settings',
        '_elementor_data',
        '_elementor_css',
    ];

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

        $did_fix_for_post = false;

        foreach ($meta_keys_to_check as $meta_key) {
            $checked_count++;
            $raw = get_post_meta($post_id, $meta_key, true);

            // already structured
            if (is_array($raw) || is_object($raw)) {
                continue;
            }

            if ($raw === '' || $raw === null || !is_string($raw)) {
                continue;
            }

            $fixed_value = null;

            // try PHP serialized
            $maybe = maybe_unserialize($raw);
            if ($maybe !== $raw && (is_array($maybe) || is_object($maybe))) {
                $fixed_value = $maybe;
            }

            // try JSON
            if ($fixed_value === null) {
                $json = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE && (is_array($json) || is_object($json))) {
                    $fixed_value = $json;
                }
            }

            // strip slashes and retry
            if ($fixed_value === null) {
                $raw_stripped = wp_unslash($raw);
                $maybe2 = maybe_unserialize($raw_stripped);
                if ($maybe2 !== $raw_stripped && (is_array($maybe2) || is_object($maybe2))) {
                    $fixed_value = $maybe2;
                } else {
                    $json2 = json_decode($raw_stripped, true);
                    if (json_last_error() === JSON_ERROR_NONE && (is_array($json2) || is_object($json2))) {
                        $fixed_value = $json2;
                    }
                }
            }

            if ($fixed_value !== null) {
                update_post_meta($post_id, $meta_key, $fixed_value);
                $did_fix_for_post = true;
            }
        }

        if ($did_fix_for_post) {
            $fixed_count++;
        }
    }

    update_option('spg_elementor_meta_normalizer_done', 1);

    add_action('admin_notices', function() use ($fixed_count, $checked_count) {
        $class = $fixed_count > 0 ? 'updated' : 'notice-warning';
        printf(
            '<div class="%1$s"><p><strong>SPG Element or meta normalizer:</strong> Checked %2$d meta entries; fixed %3$d pages. Please view affected pages and regenerate Elementor CSS if needed.</p></div>',
            esc_attr($class),
            intval($checked_count),
            intval($fixed_count)
        );
    });
}