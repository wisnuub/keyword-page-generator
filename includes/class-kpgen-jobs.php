<?php
/**
 * Generation jobs: created from the form, then processed one page per AJAX
 * request (with a progress bar) or by WP-Cron in the background.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KPGen_Jobs {

    const PREFIX    = 'kpgen_job_';
    const CRON_HOOK = 'kpgen_run_job';

    public static function init() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );
        foreach ( array( 'count', 'preview', 'start', 'step', 'cancel', 'templates', 'save_ai', 'test_ai', 'trash_batch' ) as $action ) {
            add_action( 'wp_ajax_kpgen_' . $action, array( __CLASS__, 'ajax_' . $action ) );
        }
    }

    private static function authorize() {
        check_ajax_referer( 'kpgen', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'You do not have permission to do this.', 'keyword-page-generator' ), 403 );
        }
    }

    /**
     * Read and validate the generator form from $_POST.
     *
     * @return array|WP_Error
     */
    private static function read_form() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in authorize().
        $template = get_post( isset( $_POST['template'] ) ? absint( $_POST['template'] ) : 0 );
        $pairs    = KPGen_Generator::clean_pairs( isset( $_POST['pairs'] ) ? wp_unslash( (array) $_POST['pairs'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field in clean_pairs().
        $mode     = isset( $_POST['mode'] ) && 'independent' === $_POST['mode'] ? 'independent' : 'matrix';
        $status   = isset( $_POST['status'] ) && 'publish' === $_POST['status'] ? 'publish' : 'draft';
        $ai       = ! empty( $_POST['ai'] ) && KPGen_AI::is_configured();
        // phpcs:enable

        if ( ! $template || 'trash' === $template->post_status || ! current_user_can( 'edit_post', $template->ID ) ) {
            return new WP_Error( 'kpgen_template', __( 'Choose a template page.', 'keyword-page-generator' ) );
        }
        if ( ! $pairs ) {
            return new WP_Error( 'kpgen_pairs', __( 'Add at least one keyword and one replacement value.', 'keyword-page-generator' ) );
        }

        $specs = KPGen_Generator::build_specs( $pairs, $mode );
        if ( count( $specs ) > KPGen_Generator::MAX_PAGES ) {
            /* translators: %d: maximum number of pages */
            return new WP_Error( 'kpgen_too_many', sprintf( __( 'That would create more than %d pages. Split it into smaller runs.', 'keyword-page-generator' ), KPGen_Generator::MAX_PAGES ) );
        }

        // Keywords missing from the template would produce identical copies — warn about them.
        $haystack = $template->post_title . ' ' . $template->post_content . ' ' . $template->post_excerpt;
        $missing  = array();
        foreach ( $pairs as $pair ) {
            if ( false === mb_stripos( $haystack, $pair['find'] ) && false === mb_stripos( (string) get_post_meta( $template->ID, '_elementor_data', true ), $pair['find'] ) ) {
                $missing[] = $pair['find'];
            }
        }

        return compact( 'template', 'pairs', 'mode', 'status', 'ai', 'specs', 'missing' );
    }

    /* ------------------------------------------------------------------
     *  AJAX
     * ----------------------------------------------------------------*/

    /**
     * Live page count and the titles that would be created.
     */
    public static function ajax_count() {
        self::authorize();
        $form = self::read_form();
        if ( is_wp_error( $form ) ) {
            wp_send_json_success( array( 'total' => 0, 'titles' => array(), 'message' => $form->get_error_message() ) );
        }
        wp_send_json_success( array(
            'total'   => count( $form['specs'] ),
            'titles'  => array_map( function ( $spec ) use ( $form ) {
                return KPGen_Generator::preview_title( $form['template'], $spec );
            }, array_slice( $form['specs'], 0, 50 ) ),
            'missing' => $form['missing'],
        ) );
    }

    /**
     * Create the first page as a draft preview (replacing older previews).
     */
    public static function ajax_preview() {
        self::authorize();
        $form = self::read_form();
        if ( is_wp_error( $form ) ) {
            wp_send_json_error( $form->get_error_message() );
        }
        KPGen_Generator::delete_previews();
        $result = KPGen_Generator::create( $form['template'], $form['specs'][0], array(
            'preview' => true,
            'status'  => 'draft',
            'ai'      => $form['ai'],
        ) );
        $result['ok'] ? wp_send_json_success( $result ) : wp_send_json_error( $result['error'] );
    }

    /**
     * Create a job. mode=now returns the job for the browser to step through;
     * background/timed hand it to WP-Cron.
     */
    public static function ajax_start() {
        self::authorize();
        $form = self::read_form();
        if ( is_wp_error( $form ) ) {
            wp_send_json_error( $form->get_error_message() );
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $run  = isset( $_POST['run'] ) ? sanitize_key( wp_unslash( $_POST['run'] ) ) : 'now';
        $when = isset( $_POST['when'] ) ? sanitize_text_field( wp_unslash( $_POST['when'] ) ) : '';
        // phpcs:enable

        $timestamp = time();
        if ( 'timed' === $run ) {
            try {
                // datetime-local has no zone: read it in the site's timezone.
                $timestamp = ( new DateTimeImmutable( $when, wp_timezone() ) )->getTimestamp();
            } catch ( Exception $e ) {
                $timestamp = 0;
            }
            if ( $timestamp <= time() ) {
                wp_send_json_error( __( 'Choose a date and time in the future.', 'keyword-page-generator' ) );
            }
        }

        KPGen_Generator::delete_previews();
        $id  = wp_generate_password( 12, false );
        $job = array(
            'id'        => $id,
            'template'  => $form['template']->ID,
            'specs'     => $form['specs'],
            'status'    => $form['status'],
            'ai'        => $form['ai'],
            'total'     => count( $form['specs'] ),
            'done'      => 0,
            'created'   => 0,
            'failed'    => array(),
            'warnings'  => array(),
            'cancelled' => false,
            'run'       => $run,
            'run_at'    => $timestamp,
            'user'      => get_current_user_id(),
        );
        update_option( self::PREFIX . $id, $job, false );
        KPGen_Generator::record_batch( $id, $form['template']->post_title, $job['total'] );

        if ( 'now' !== $run ) {
            wp_schedule_single_event( $timestamp, self::CRON_HOOK, array( $id ) );
        }

        wp_send_json_success( array(
            'job'     => $id,
            'total'   => $job['total'],
            'run'     => $run,
            'run_at'  => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ),
            'missing' => $form['missing'],
        ) );
    }

    /**
     * Create the next page of a job.
     */
    public static function ajax_step() {
        self::authorize();
        $id  = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $job = get_option( self::PREFIX . $id );
        if ( ! is_array( $job ) ) {
            wp_send_json_error( __( 'This job no longer exists.', 'keyword-page-generator' ) );
        }
        $last = self::process( $job, 1 );
        wp_send_json_success( array(
            'done'    => $job['done'],
            'total'   => $job['total'],
            'created' => $job['created'],
            'last'    => $last,
            'finished'=> $job['done'] >= $job['total'] || $job['cancelled'],
        ) );
    }

    public static function ajax_cancel() {
        self::authorize();
        $id  = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $job = get_option( self::PREFIX . $id );
        if ( is_array( $job ) ) {
            $job['cancelled'] = true;
            update_option( self::PREFIX . $id, $job, false );
            wp_clear_scheduled_hook( self::CRON_HOOK, array( $id ) );
        }
        wp_send_json_success();
    }

    public static function ajax_templates() {
        self::authorize();
        $type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'page'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! in_array( $type, KPGen_Admin::post_types(), true ) ) {
            $type = 'page';
        }
        $posts = get_posts( array(
            'post_type'      => $type,
            'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
            'posts_per_page' => 500,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'meta_query'     => array( array( 'key' => KPGen_Generator::BATCH_META, 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query -- hide generated pages from the template list.
        ) );
        wp_send_json_success( array_map( function ( $p ) {
            return array(
                'id'    => $p->ID,
                'title' => ( $p->post_title ?: '#' . $p->ID ) . ( 'publish' !== $p->post_status ? ' (' . $p->post_status . ')' : '' ),
            );
        }, $posts ) );
    }

    public static function ajax_save_ai() {
        self::authorize();
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $current  = KPGen_AI::settings();
        $provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : 'anthropic';
        $key      = isset( $_POST['key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['key'] ) ) ) : '';
        $source   = isset( $_POST['source'] ) && 'wordpress' === $_POST['source'] && KPGen_AI::connectors_exist() ? 'wordpress' : 'key';
        $settings = array(
            'source'   => $source,
            'provider' => isset( KPGen_AI::PROVIDERS[ $provider ] ) ? $provider : 'anthropic',
            'model'    => isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '',
            // An empty field keeps the saved key; "-" clears it.
            'key'      => '' === $key ? $current['key'] : ( '-' === $key ? '' : KPGen_AI::encrypt( $key ) ),
            'prompt'   => isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '',
        );
        // phpcs:enable
        update_option( KPGen_AI::OPTION, $settings, false );
        delete_transient( 'kpgen_connectors_ready' );
        wp_send_json_success( array( 'ready' => KPGen_AI::is_configured() ) );
    }

    public static function ajax_test_ai() {
        self::authorize();
        $result = KPGen_AI::call( 'Reply with the single word OK.', 'Test' );
        is_wp_error( $result ) ? wp_send_json_error( $result->get_error_message() ) : wp_send_json_success( trim( wp_strip_all_tags( $result ) ) );
    }

    public static function ajax_trash_batch() {
        self::authorize();
        $batch = isset( $_POST['batch'] ) ? sanitize_key( wp_unslash( $_POST['batch'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $job   = get_option( self::PREFIX . $batch );
        if ( is_array( $job ) && $job['done'] < $job['total'] ) {
            $job['cancelled'] = true;
            update_option( self::PREFIX . $batch, $job, false );
            wp_clear_scheduled_hook( self::CRON_HOOK, array( $batch ) );
        }
        wp_send_json_success( array( 'trashed' => KPGen_Generator::trash_batch( $batch ) ) );
    }

    /* ------------------------------------------------------------------
     *  Processing
     * ----------------------------------------------------------------*/

    /**
     * Create up to $limit pages, saving progress after each one.
     *
     * @return array|null Result of the last page created.
     */
    private static function process( array &$job, $limit, $deadline = 0 ) {
        $template = get_post( $job['template'] );
        $last     = null;

        if ( ! $template ) {
            $job['cancelled'] = true;
            update_option( self::PREFIX . $job['id'], $job, false );
            return array( 'ok' => false, 'title' => '', 'error' => __( 'The template page was deleted.', 'keyword-page-generator' ) );
        }

        for ( $n = 0; $n < $limit && ! $job['cancelled'] && $job['done'] < $job['total']; $n++ ) {
            if ( $deadline && microtime( true ) > $deadline ) {
                break;
            }
            $last = KPGen_Generator::create( $template, $job['specs'][ $job['done'] ], array(
                'status' => $job['status'],
                'ai'     => $job['ai'],
                'batch'  => $job['id'],
            ) );
            $job['done']++;
            if ( $last['ok'] ) {
                $job['created']++;
            } else {
                $job['failed'][] = $last['title'] . ': ' . $last['error'];
            }
            if ( $last['warning'] ) {
                $job['warnings'][] = $last['warning'];
            }
            update_option( self::PREFIX . $job['id'], $job, false );
        }
        return $last;
    }

    /**
     * WP-Cron: work for ~20 seconds, then reschedule if pages remain.
     */
    public static function run_cron( $id ) {
        $job = get_option( self::PREFIX . sanitize_key( $id ) );
        if ( ! is_array( $job ) || $job['cancelled'] ) {
            return;
        }
        if ( $job['user'] ) {
            wp_set_current_user( $job['user'] ); // so created pages get the right author
        }
        self::process( $job, PHP_INT_MAX, microtime( true ) + 20 );
        if ( ! $job['cancelled'] && $job['done'] < $job['total'] ) {
            wp_schedule_single_event( time() + 5, self::CRON_HOOK, array( $job['id'] ) );
        }
    }

    /**
     * Jobs for the History tab, newest first.
     */
    public static function history() {
        $rows = array();
        foreach ( get_option( KPGen_Generator::HISTORY, array() ) as $entry ) {
            $job = get_option( self::PREFIX . $entry['id'] );
            $rows[] = array_merge( $entry, array(
                'done'      => is_array( $job ) ? $job['done'] : $entry['total'],
                'created'   => is_array( $job ) ? $job['created'] : 0,
                'cancelled' => is_array( $job ) && $job['cancelled'],
                'run'       => is_array( $job ) ? $job['run'] : 'now',
                'run_at'    => is_array( $job ) ? $job['run_at'] : $entry['time'],
                'warnings'  => is_array( $job ) ? $job['warnings'] : array(),
                'failed'    => is_array( $job ) ? $job['failed'] : array(),
                'live'      => count( KPGen_Generator::batch_post_ids( $entry['id'], array( 'publish', 'draft', 'pending', 'private', 'future' ) ) ),
            ) );
        }
        return $rows;
    }
}
