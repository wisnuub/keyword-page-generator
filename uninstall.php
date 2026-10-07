<?php
/**
 * Keyword Page Generator — remove settings and run history.
 * Generated pages are normal pages and are kept.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

function kpgen_uninstall() {
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- removing this plugin's own options.
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'kpgen\\_%'" );

    // Leftovers from versions before 3.0.
    foreach ( array( 'kpg_ai_settings', 'kpg_cron_job', 'kpg_elementor_meta_normalizer_done' ) as $option ) {
        delete_option( $option );
    }

    delete_post_meta_by_key( '_kpgen_batch' );
    delete_post_meta_by_key( '_kpgen_preview' );
    delete_post_meta_by_key( '_kpg_preview_flag' );
    wp_unschedule_hook( 'kpgen_run_job' );
    wp_unschedule_hook( 'kpg_cron_process_job' );
}

kpgen_uninstall();
