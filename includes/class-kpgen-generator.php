<?php
/**
 * Builds the list of pages to create and creates them one at a time.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KPGen_Generator {

    const BATCH_META   = '_kpgen_batch';
    const PREVIEW_META = '_kpgen_preview';
    const HISTORY      = 'kpgen_history';

    /** Hard ceiling per run, so a typo in matrix mode can't create 50,000 pages. */
    const MAX_PAGES = 1000;

    /** Meta that belongs to one specific post and must not be copied. */
    const SKIP_META = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time', '_elementor_css', '_elementor_element_cache', '_elementor_page_assets', '_yoast_indexable', self::BATCH_META, self::PREVIEW_META );

    /**
     * Split a list of values: one per line, or comma-separated on a single line.
     */
    public static function parse_values( $raw ) {
        $raw   = str_replace( "\r", '', (string) $raw );
        $parts = false !== strpos( $raw, "\n" ) ? explode( "\n", $raw ) : explode( ',', $raw );
        $parts = array_map( 'trim', $parts );
        return array_values( array_unique( array_filter( $parts, 'strlen' ) ) );
    }

    /**
     * Normalise submitted pairs into [ [ 'find' => …, 'values' => [ … ] ], … ].
     */
    public static function clean_pairs( $raw_pairs ) {
        $pairs = array();
        foreach ( (array) $raw_pairs as $pair ) {
            $find   = isset( $pair['find'] ) ? sanitize_text_field( $pair['find'] ) : '';
            $values = self::parse_values( isset( $pair['values'] ) ? sanitize_textarea_field( $pair['values'] ) : '' );
            if ( '' !== $find && $values ) {
                $pairs[] = array( 'find' => $find, 'values' => $values );
            }
        }
        return $pairs;
    }

    /**
     * One entry per page: a list of [ find, replace ] pairs.
     *
     * matrix:      every combination of every pair's values.
     * independent: each pair on its own; other keywords stay as in the template.
     */
    public static function build_specs( array $pairs, $mode ) {
        if ( ! $pairs ) {
            return array();
        }

        if ( 'independent' === $mode || 1 === count( $pairs ) ) {
            $specs = array();
            foreach ( $pairs as $pair ) {
                foreach ( $pair['values'] as $value ) {
                    $specs[] = array( array( 'find' => $pair['find'], 'replace' => $value ) );
                }
            }
            return $specs;
        }

        $specs = array( array() );
        foreach ( $pairs as $pair ) {
            $next = array();
            foreach ( $specs as $partial ) {
                foreach ( $pair['values'] as $value ) {
                    $next[] = array_merge( $partial, array( array( 'find' => $pair['find'], 'replace' => $value ) ) );
                    if ( count( $next ) > self::MAX_PAGES ) {
                        return $next; // caller reports the overflow
                    }
                }
            }
            $specs = $next;
        }
        return $specs;
    }

    /**
     * The title a spec will produce, without creating anything.
     */
    public static function preview_title( WP_Post $template, array $spec ) {
        $replacer = new KPGen_Replacer( $spec );
        return $replacer->text( $template->post_title );
    }

    /**
     * Create one page from the template.
     *
     * @param array $options status (draft|publish), batch, ai (bool), preview (bool).
     * @return array { ok: bool, post_id: int, title: string, url: string, warning: string, error: string }
     */
    public static function create( WP_Post $template, array $spec, array $options ) {
        $replacer = new KPGen_Replacer( $spec );
        $title    = $replacer->text( $template->post_title );
        $content  = $replacer->markup( $template->post_content );
        $excerpt  = $replacer->markup( $template->post_excerpt );
        $warning  = '';

        if ( ! empty( $options['ai'] ) ) {
            $rewritten = KPGen_AI::rewrite_markup( $content, wp_list_pluck( $spec, 'replace' ) );
            if ( is_wp_error( $rewritten ) ) {
                $warning = $rewritten->get_error_message();
            } else {
                $content = $rewritten;
            }
        }

        $post_id = wp_insert_post( wp_slash( array(
            'post_type'      => $template->post_type,
            'post_status'    => ! empty( $options['preview'] ) ? 'draft' : ( 'publish' === $options['status'] ? 'publish' : 'draft' ),
            'post_title'     => $title,
            'post_name'      => self::slug( $template, $title, $spec ),
            'post_content'   => $content,
            'post_excerpt'   => $excerpt,
            'post_parent'    => $template->post_parent,
            'menu_order'     => $template->menu_order,
            'comment_status' => $template->comment_status,
            'ping_status'    => $template->ping_status,
            'post_password'  => $template->post_password,
        ) ), true );

        if ( is_wp_error( $post_id ) ) {
            return array( 'ok' => false, 'post_id' => 0, 'title' => $title, 'url' => '', 'warning' => '', 'error' => $post_id->get_error_message() );
        }

        self::copy_meta( $template->ID, $post_id, $replacer );
        self::copy_terms( $template, $post_id );

        if ( ! empty( $options['ai'] ) && get_post_meta( $post_id, '_elementor_data', true ) ) {
            $ai = KPGen_AI::rewrite_elementor( $post_id, wp_list_pluck( $spec, 'replace' ) );
            if ( is_wp_error( $ai ) && ! $warning ) {
                $warning = $ai->get_error_message();
            }
        }

        if ( ! empty( $options['preview'] ) ) {
            update_post_meta( $post_id, self::PREVIEW_META, 1 );
        } elseif ( ! empty( $options['batch'] ) ) {
            update_post_meta( $post_id, self::BATCH_META, $options['batch'] );
        }

        return array(
            'ok'      => true,
            'post_id' => $post_id,
            'title'   => $title,
            'url'     => ! empty( $options['preview'] ) ? get_preview_post_link( $post_id ) : get_permalink( $post_id ),
            'warning' => $warning ? $title . ': ' . $warning : '',
            'error'   => '',
        );
    }

    /**
     * Swap the keyword inside the template slug ("plumber-melbourne" → "plumber-sydney");
     * if the slug doesn't contain it, build one from the new title. WordPress
     * adds -2, -3… itself if the slug is taken.
     */
    private static function slug( WP_Post $template, $title, array $spec ) {
        $slug    = $template->post_name;
        $changed = false;
        foreach ( $spec as $pair ) {
            $from = sanitize_title( $pair['find'] );
            if ( '' !== $from && preg_match( '/(^|-)' . preg_quote( $from, '/' ) . '(-|$)/', $slug ) ) {
                $slug    = preg_replace( '/(^|-)' . preg_quote( $from, '/' ) . '(-|$)/', '${1}' . sanitize_title( $pair['replace'] ) . '${2}', $slug );
                $changed = true;
            }
        }
        return $changed ? $slug : sanitize_title( $title );
    }

    /**
     * Copy every meta row, with keywords replaced. Values are re-slashed
     * because add_post_meta() unslashes — skipping that corrupts JSON such as
     * Elementor's page data (escaped quotes and newlines get stripped).
     */
    private static function copy_meta( $from_id, $to_id, KPGen_Replacer $replacer ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC", $from_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- need raw rows to keep multi-value keys and original order.

        foreach ( $rows as $row ) {
            if ( in_array( $row->meta_key, self::SKIP_META, true ) || 0 === strpos( $row->meta_key, '_wp_trash_' ) ) {
                continue;
            }
            $value = $replacer->meta_value( $row->meta_key, $row->meta_value );
            add_post_meta( $to_id, $row->meta_key, wp_slash( $value ) );
        }
    }

    private static function copy_terms( WP_Post $template, $post_id ) {
        foreach ( get_object_taxonomies( $template->post_type ) as $taxonomy ) {
            $terms = wp_get_object_terms( $template->ID, $taxonomy, array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $terms ) && $terms ) {
                wp_set_object_terms( $post_id, $terms, $taxonomy );
            }
        }
    }

    /**
     * Remove earlier preview drafts.
     */
    public static function delete_previews() {
        $ids = get_posts( array(
            'post_type'      => 'any',
            'post_status'    => array( 'draft', 'pending', 'private', 'auto-draft' ),
            'posts_per_page' => 100,
            'fields'         => 'ids',
            'meta_key'       => self::PREVIEW_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key -- small, admin-only.
        ) );
        foreach ( $ids as $id ) {
            wp_delete_post( $id, true );
        }
    }

    /* ------------------------------------------------------------------
     *  Batch history (for undo)
     * ----------------------------------------------------------------*/

    public static function record_batch( $batch, $template_title, $total ) {
        $history = get_option( self::HISTORY, array() );
        array_unshift( $history, array(
            'id'       => $batch,
            'time'     => time(),
            'template' => $template_title,
            'total'    => (int) $total,
        ) );
        // Keep the last 30 runs; drop the stored job data of older ones.
        foreach ( array_slice( $history, 30 ) as $old ) {
            delete_option( KPGen_Jobs::PREFIX . $old['id'] );
        }
        update_option( self::HISTORY, array_slice( $history, 0, 30 ), false );
    }

    public static function batch_post_ids( $batch, $status = 'any' ) {
        return get_posts( array(
            'post_type'      => 'any',
            'post_status'    => $status,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => self::BATCH_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
            'meta_value'     => $batch, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
        ) );
    }

    /**
     * Move every page of a batch to the trash (recoverable from Trash).
     */
    public static function trash_batch( $batch ) {
        $count = 0;
        foreach ( self::batch_post_ids( $batch, array( 'publish', 'draft', 'pending', 'private', 'future' ) ) as $id ) {
            if ( current_user_can( 'delete_post', $id ) && wp_trash_post( $id ) ) {
                $count++;
            }
        }
        return $count;
    }
}
