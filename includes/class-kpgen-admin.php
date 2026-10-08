<?php
/**
 * Admin screen: Generate, History and AI settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KPGen_Admin {

    const SLUG = 'keyword-page-generator';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_filter( 'plugin_action_links_' . KPGEN_BASENAME, array( __CLASS__, 'action_link' ) );
    }

    /**
     * Post types that can be used as templates.
     */
    public static function post_types() {
        $types = get_post_types( array( 'show_ui' => true, 'public' => true ) );
        unset( $types['attachment'] );
        return array_values( $types );
    }

    public static function menu() {
        add_management_page(
            __( 'Keyword Page Generator', 'keyword-page-generator' ),
            __( 'Page Generator', 'keyword-page-generator' ),
            'manage_options',
            self::SLUG,
            array( __CLASS__, 'render' )
        );
    }

    public static function action_link( $links ) {
        array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Generate pages', 'keyword-page-generator' ) . '</a>' );
        return $links;
    }

    public static function assets( $hook ) {
        if ( 'tools_page_' . self::SLUG !== $hook ) {
            return;
        }
        wp_enqueue_style( 'kpgen-admin', KPGEN_URL . 'assets/admin.css', array(), KPGEN_VERSION );
        wp_enqueue_script( 'kpgen-admin', KPGEN_URL . 'assets/admin.js', array( 'jquery' ), KPGEN_VERSION, true );

        $ai = KPGen_AI::settings();
        wp_localize_script( 'kpgen-admin', 'kpgen', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'kpgen' ),
            'aiReady'   => KPGen_AI::is_configured(),
            'providers' => KPGen_AI::PROVIDERS,
            'i18n'      => array(
                /* translators: %d: number of pages */
                'pages'        => __( '%d pages will be created', 'keyword-page-generator' ),
                'page'         => __( '1 page will be created', 'keyword-page-generator' ),
                /* translators: %d: number of further pages */
                'more'         => __( '…and %d more', 'keyword-page-generator' ),
                /* translators: %s: comma-separated keywords */
                'missing'      => __( 'Not found in the template: %s. Those keywords won’t change anything.', 'keyword-page-generator' ),
                /* translators: %d: number of pages */
                'confirmMany'  => __( 'Create %d pages?', 'keyword-page-generator' ),
                /* translators: 1: current page number, 2: total pages */
                'progress'     => __( 'Creating page %1$d of %2$d…', 'keyword-page-generator' ),
                /* translators: %d: number of pages */
                'finished'     => __( 'Done — %d pages created.', 'keyword-page-generator' ),
                /* translators: %d: number of pages */
                'cancelled'    => __( 'Stopped — %d pages created.', 'keyword-page-generator' ),
                /* translators: %s: date and time */
                'scheduled'    => __( 'Scheduled for %s. Progress is shown on the History tab.', 'keyword-page-generator' ),
                'background'   => __( 'Running in the background. Progress is shown on the History tab.', 'keyword-page-generator' ),
                'previewReady' => __( 'Preview draft created:', 'keyword-page-generator' ),
                'open'         => __( 'Open preview', 'keyword-page-generator' ),
                /* translators: %d: number of pages */
                'confirmTrash' => __( 'Move all %d pages from this run to the Trash?', 'keyword-page-generator' ),
                /* translators: %d: number of pages */
                'trashed'      => __( '%d pages moved to the Trash.', 'keyword-page-generator' ),
                'viewHistory'  => __( 'Undo or view in History', 'keyword-page-generator' ),
                'saved'        => __( 'Saved.', 'keyword-page-generator' ),
                'testing'      => __( 'Testing…', 'keyword-page-generator' ),
                'testOk'       => __( 'Connected — the model replied.', 'keyword-page-generator' ),
                'error'        => __( 'Something went wrong. Please try again.', 'keyword-page-generator' ),
                'select'       => __( 'Choose a template…', 'keyword-page-generator' ),
                'csvBad'       => __( 'Couldn’t read that CSV. Put each keyword in the first row and its values in the rows below.', 'keyword-page-generator' ),
                'removePair'   => __( 'Remove', 'keyword-page-generator' ),
                'keyword'      => __( 'Keyword in the template', 'keyword-page-generator' ),
                'values'       => __( 'Replace with (one per line, or comma-separated)', 'keyword-page-generator' ),
            ),
        ) );
    }

    public static function render() {
        $ai        = KPGen_AI::settings();
        $types     = self::post_types();
        $history   = KPGen_Jobs::history();
        $date_fmt  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
        ?>
        <div class="wrap kpgen">
            <h1><?php esc_html_e( 'Keyword Page Generator', 'keyword-page-generator' ); ?></h1>
            <p class="kpgen-intro"><?php esc_html_e( 'Turn one template page into many — for example one page per city or per service — by swapping keywords. Only visible text changes; images, links and layout stay exactly as they are.', 'keyword-page-generator' ); ?></p>

            <nav class="nav-tab-wrapper kpgen-tabs">
                <a href="#generate" class="nav-tab nav-tab-active" data-tab="generate"><?php esc_html_e( 'Generate', 'keyword-page-generator' ); ?></a>
                <a href="#history" class="nav-tab" data-tab="history"><?php esc_html_e( 'History', 'keyword-page-generator' ); ?></a>
                <a href="#ai" class="nav-tab" data-tab="ai"><?php esc_html_e( 'AI rewriting', 'keyword-page-generator' ); ?></a>
            </nav>

            <!-- Generate -->
            <section class="kpgen-panel" data-panel="generate">
                <form id="kpgen-form" class="kpgen-grid" autocomplete="off">
                    <div class="kpgen-main">
                        <div class="kpgen-card">
                            <h2><span class="kpgen-step">1</span><?php esc_html_e( 'Template', 'keyword-page-generator' ); ?></h2>
                            <div class="kpgen-row">
                                <label for="kpgen-type"><?php esc_html_e( 'Type', 'keyword-page-generator' ); ?></label>
                                <select id="kpgen-type">
                                    <?php foreach ( $types as $type ) : $obj = get_post_type_object( $type ); ?>
                                        <option value="<?php echo esc_attr( $type ); ?>" <?php selected( $type, 'page' ); ?>><?php echo esc_html( $obj->labels->singular_name ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="kpgen-row">
                                <label for="kpgen-template"><?php esc_html_e( 'Template', 'keyword-page-generator' ); ?></label>
                                <select id="kpgen-template" name="template" required></select>
                            </div>
                            <p class="description"><?php esc_html_e( 'Write the template for one case, e.g. “Plumber in Melbourne”. The copies get its content, featured image, page template, SEO fields, custom fields and categories.', 'keyword-page-generator' ); ?></p>
                        </div>

                        <div class="kpgen-card">
                            <h2><span class="kpgen-step">2</span><?php esc_html_e( 'Keywords', 'keyword-page-generator' ); ?></h2>
                            <div id="kpgen-pairs"></div>
                            <p class="kpgen-actions-inline">
                                <button type="button" class="button" id="kpgen-add-pair">+ <?php esc_html_e( 'Add keyword', 'keyword-page-generator' ); ?></button>
                                <label class="button kpgen-csv"><?php esc_html_e( 'Import CSV', 'keyword-page-generator' ); ?><input type="file" id="kpgen-csv" accept=".csv,.txt" hidden></label>
                            </p>
                            <fieldset class="kpgen-mode" id="kpgen-mode" hidden>
                                <legend><?php esc_html_e( 'With more than one keyword', 'keyword-page-generator' ); ?></legend>
                                <label><input type="radio" name="mode" value="matrix" checked> <strong><?php esc_html_e( 'Every combination', 'keyword-page-generator' ); ?></strong> — <?php esc_html_e( '3 cities × 4 services = 12 pages', 'keyword-page-generator' ); ?></label>
                                <label><input type="radio" name="mode" value="independent"> <strong><?php esc_html_e( 'Each keyword separately', 'keyword-page-generator' ); ?></strong> — <?php esc_html_e( '3 city pages + 4 service pages = 7 pages', 'keyword-page-generator' ); ?></label>
                            </fieldset>
                        </div>

                        <div class="kpgen-card">
                            <h2><span class="kpgen-step">3</span><?php esc_html_e( 'Options', 'keyword-page-generator' ); ?></h2>
                            <fieldset class="kpgen-inline">
                                <label><input type="radio" name="status" value="draft" checked> <?php esc_html_e( 'Save as drafts', 'keyword-page-generator' ); ?></label>
                                <label><input type="radio" name="status" value="publish"> <?php esc_html_e( 'Publish immediately', 'keyword-page-generator' ); ?></label>
                            </fieldset>
                            <label class="kpgen-check">
                                <input type="checkbox" name="ai" value="1" id="kpgen-ai" <?php disabled( ! KPGen_AI::is_configured() ); ?>>
                                <?php esc_html_e( 'Rewrite the text of each page with AI so the pages aren’t near-duplicates', 'keyword-page-generator' ); ?>
                            </label>
                            <?php if ( ! KPGen_AI::is_configured() ) : ?>
                                <p class="description"><?php esc_html_e( 'Add an API key on the AI rewriting tab to enable this.', 'keyword-page-generator' ); ?></p>
                            <?php endif; ?>
                            <p class="kpgen-note"><?php esc_html_e( 'Search engines treat many near-identical pages as low quality. Give each page something genuinely local or specific — a photo, a review, opening hours — before publishing.', 'keyword-page-generator' ); ?></p>
                        </div>
                    </div>

                    <aside class="kpgen-side">
                        <div class="kpgen-card kpgen-sticky">
                            <h2><?php esc_html_e( 'Pages to create', 'keyword-page-generator' ); ?></h2>
                            <p class="kpgen-count" id="kpgen-count">—</p>
                            <ul class="kpgen-titles" id="kpgen-titles"></ul>
                            <p class="kpgen-warning" id="kpgen-missing" hidden></p>

                            <div class="kpgen-buttons">
                                <button type="button" class="button" id="kpgen-preview"><?php esc_html_e( 'Preview first page', 'keyword-page-generator' ); ?></button>
                                <button type="button" class="button button-primary" id="kpgen-generate"><?php esc_html_e( 'Generate pages', 'keyword-page-generator' ); ?></button>
                            </div>
                            <details class="kpgen-schedule">
                                <summary><?php esc_html_e( 'Run later or in the background', 'keyword-page-generator' ); ?></summary>
                                <label><input type="radio" name="run" value="background" checked> <?php esc_html_e( 'In the background now', 'keyword-page-generator' ); ?></label>
                                <label><input type="radio" name="run" value="timed"> <?php esc_html_e( 'At', 'keyword-page-generator' ); ?> <input type="datetime-local" id="kpgen-when"></label>
                                <p class="description">
                                    <?php
                                    /* translators: %s: site timezone */
                                    echo esc_html( sprintf( __( 'Site time zone: %s. Background runs use WP-Cron, which needs site traffic (or a real cron job) to fire.', 'keyword-page-generator' ), wp_timezone_string() ) );
                                    ?>
                                </p>
                                <button type="button" class="button" id="kpgen-schedule"><?php esc_html_e( 'Schedule', 'keyword-page-generator' ); ?></button>
                            </details>

                            <div id="kpgen-status" class="kpgen-status" hidden>
                                <div class="kpgen-bar"><span id="kpgen-bar"></span></div>
                                <p id="kpgen-status-text"></p>
                                <button type="button" class="button-link" id="kpgen-cancel"><?php esc_html_e( 'Stop', 'keyword-page-generator' ); ?></button>
                                <ul id="kpgen-log" class="kpgen-log"></ul>
                            </div>
                        </div>
                    </aside>
                </form>
            </section>

            <!-- History -->
            <section class="kpgen-panel" data-panel="history" hidden>
                <div class="kpgen-card">
                    <?php if ( ! $history ) : ?>
                        <p><?php esc_html_e( 'No runs yet.', 'keyword-page-generator' ); ?></p>
                    <?php else : ?>
                        <table class="widefat striped kpgen-history">
                            <thead><tr>
                                <th><?php esc_html_e( 'When', 'keyword-page-generator' ); ?></th>
                                <th><?php esc_html_e( 'Template', 'keyword-page-generator' ); ?></th>
                                <th><?php esc_html_e( 'Progress', 'keyword-page-generator' ); ?></th>
                                <th><?php esc_html_e( 'Pages', 'keyword-page-generator' ); ?></th>
                                <th></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ( $history as $row ) : ?>
                                <tr>
                                    <td><?php echo esc_html( wp_date( $date_fmt, $row['run_at'] ) ); ?></td>
                                    <td><?php echo esc_html( $row['template'] ); ?></td>
                                    <td>
                                        <?php
                                        if ( $row['cancelled'] ) {
                                            esc_html_e( 'Stopped', 'keyword-page-generator' );
                                        } elseif ( $row['done'] >= $row['total'] ) {
                                            esc_html_e( 'Finished', 'keyword-page-generator' );
                                        } elseif ( 'now' !== $row['run'] && $row['run_at'] > time() ) {
                                            esc_html_e( 'Scheduled', 'keyword-page-generator' );
                                        } else {
                                            /* translators: 1: done, 2: total */
                                            echo esc_html( sprintf( __( '%1$d of %2$d', 'keyword-page-generator' ), $row['done'], $row['total'] ) );
                                        }
                                        if ( $row['warnings'] || $row['failed'] ) {
                                            echo '<details><summary>' . esc_html( sprintf( /* translators: %d: number of notes */ _n( '%d note', '%d notes', count( $row['warnings'] ) + count( $row['failed'] ), 'keyword-page-generator' ), count( $row['warnings'] ) + count( $row['failed'] ) ) ) . '</summary><ul>';
                                            foreach ( array_merge( $row['failed'], $row['warnings'] ) as $note ) {
                                                echo '<li>' . esc_html( $note ) . '</li>';
                                            }
                                            echo '</ul></details>';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo esc_html( $row['live'] ); ?></td>
                                    <td class="kpgen-history-actions">
                                        <?php if ( $row['live'] ) : ?>
                                            <button type="button" class="button-link kpgen-trash" data-batch="<?php echo esc_attr( $row['id'] ); ?>" data-count="<?php echo esc_attr( $row['live'] ); ?>"><?php esc_html_e( 'Undo (move to Trash)', 'keyword-page-generator' ); ?></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </section>

            <!-- AI -->
            <section class="kpgen-panel" data-panel="ai" hidden>
                <form id="kpgen-ai-form" class="kpgen-card kpgen-narrow" autocomplete="off">
                    <p><?php esc_html_e( 'Optional. When enabled for a run, each generated page’s paragraphs and headings are reworded by the AI model you choose. Tags, links and images are checked and left untouched; if the model changes them, the original text is kept.', 'keyword-page-generator' ); ?></p>

                    <?php if ( KPGen_AI::connectors_exist() ) : ?>
                    <fieldset class="kpgen-source">
                        <label>
                            <input type="radio" name="source" value="wordpress" <?php checked( $ai['source'], 'wordpress' ); ?>>
                            <strong><?php esc_html_e( 'Use WordPress Connectors', 'keyword-page-generator' ); ?></strong> <?php esc_html_e( '(recommended)', 'keyword-page-generator' ); ?>
                            <span class="description">
                                <?php
                                if ( KPGen_AI::connectors_ready() ) {
                                    esc_html_e( 'Ready — uses the AI provider set up in Settings → Connectors.', 'keyword-page-generator' );
                                } else {
                                    printf(
                                        /* translators: %s: link to the Connectors screen */
                                        esc_html__( 'No AI provider is connected yet. Set one up in %s.', 'keyword-page-generator' ),
                                        '<a href="' . esc_url( admin_url( 'options-connectors.php' ) ) . '">' . esc_html__( 'Settings → Connectors', 'keyword-page-generator' ) . '</a>'
                                    );
                                }
                                ?>
                            </span>
                        </label>
                        <label>
                            <input type="radio" name="source" value="key" <?php checked( $ai['source'], 'key' ); ?>>
                            <strong><?php esc_html_e( 'Use my own API key', 'keyword-page-generator' ); ?></strong>
                        </label>
                    </fieldset>
                    <?php else : ?>
                        <input type="hidden" name="source" value="key">
                    <?php endif; ?>

                    <div class="kpgen-key-fields">
                    <div class="kpgen-row">
                        <label for="kpgen-provider"><?php esc_html_e( 'Provider', 'keyword-page-generator' ); ?></label>
                        <select id="kpgen-provider" name="provider">
                            <?php foreach ( KPGen_AI::PROVIDERS as $key => $p ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $ai['provider'], $key ); ?>><?php echo esc_html( $p['label'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="kpgen-row">
                        <label for="kpgen-model"><?php esc_html_e( 'Model', 'keyword-page-generator' ); ?></label>
                        <input type="text" id="kpgen-model" name="model" list="kpgen-models" value="<?php echo esc_attr( $ai['model'] ); ?>" placeholder="<?php esc_attr_e( 'Model name from your provider', 'keyword-page-generator' ); ?>">
                        <datalist id="kpgen-models"></datalist>
                    </div>
                    <div class="kpgen-row">
                        <label for="kpgen-key"><?php esc_html_e( 'API key', 'keyword-page-generator' ); ?></label>
                        <input type="password" id="kpgen-key" name="key" placeholder="<?php echo '' !== $ai['key'] ? esc_attr__( 'Saved — leave empty to keep it', 'keyword-page-generator' ) : esc_attr__( 'Paste your API key', 'keyword-page-generator' ); ?>">
                        <a href="#" id="kpgen-key-link" target="_blank" rel="noopener"><?php esc_html_e( 'Get a key', 'keyword-page-generator' ); ?></a>
                    </div>
                    </div><!-- .kpgen-key-fields -->
                    <div class="kpgen-row">
                        <label for="kpgen-prompt"><?php esc_html_e( 'Extra instructions', 'keyword-page-generator' ); ?></label>
                        <textarea id="kpgen-prompt" name="prompt" rows="3" placeholder="<?php esc_attr_e( 'Optional, e.g. “Use Australian English and a friendly tone.”', 'keyword-page-generator' ); ?>"><?php echo esc_textarea( $ai['prompt'] ); ?></textarea>
                    </div>
                    <p class="description"><?php esc_html_e( 'Your own key is stored encrypted. Page text is sent to the AI provider you use; their terms and pricing apply.', 'keyword-page-generator' ); ?></p>
                    <p>
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'keyword-page-generator' ); ?></button>
                        <button type="button" class="button" id="kpgen-test"><?php esc_html_e( 'Test connection', 'keyword-page-generator' ); ?></button>
                        <span id="kpgen-ai-result" class="kpgen-inline-result"></span>
                    </p>
                </form>
            </section>
        </div>
        <?php
    }
}
