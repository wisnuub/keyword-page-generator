<?php
/**
 * Optional AI rewriting, so generated pages aren't word-for-word copies.
 *
 * Text is sent as HTML fragments (one per paragraph, heading, list item or
 * Elementor text widget). The model may only change the words: a rewritten
 * fragment whose tags or links differ from the original is rejected and the
 * original kept, so layouts, links and images can't be damaged.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KPGen_AI {

    const OPTION = 'kpgen_ai';

    /** Fragments per request; long pages are split across several calls. */
    const CHUNK = 40;

    /** Fragments shorter than this (in visible characters) aren't worth rewriting. */
    const MIN_LENGTH = 25;

    const PROVIDERS = array(
        'anthropic' => array(
            'label'   => 'Anthropic (Claude)',
            'models'  => array( 'claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-4-5' ),
            'key_url' => 'https://console.anthropic.com/settings/keys',
        ),
        'openai'    => array(
            'label'   => 'OpenAI',
            'models'  => array(),
            'key_url' => 'https://platform.openai.com/api-keys',
        ),
        'gemini'    => array(
            'label'   => 'Google Gemini',
            'models'  => array(),
            'key_url' => 'https://aistudio.google.com/apikey',
        ),
    );

    /** Gutenberg blocks whose own HTML is the text to rewrite. */
    const TEXT_BLOCKS = array( 'core/paragraph', 'core/heading', 'core/list-item', 'core/verse', 'core/preformatted' );

    /** Elementor widget => setting keys holding text. */
    const ELEMENTOR_WIDGETS = array(
        'text-editor'    => array( 'editor' ),
        'heading'        => array( 'title' ),
        'button'         => array( 'text' ),
        'icon-box'       => array( 'title_text', 'description_text' ),
        'image-box'      => array( 'title_text', 'description_text' ),
        'call-to-action' => array( 'title', 'description' ),
        'testimonial'    => array( 'testimonial_content' ),
    );

    /* ------------------------------------------------------------------
     *  Settings
     * ----------------------------------------------------------------*/

    public static function settings() {
        return wp_parse_args( get_option( self::OPTION, array() ), array(
            // "wordpress" = the AI provider configured in Settings → Connectors (WordPress 7+).
            'source'   => self::connectors_exist() ? 'wordpress' : 'key',
            'provider' => 'anthropic',
            'model'    => 'claude-opus-5-5',
            'key'      => '',
            'prompt'   => '',
        ) );
    }

    public static function is_configured() {
        $s = self::settings();
        if ( 'wordpress' === $s['source'] ) {
            return self::connectors_ready();
        }
        return '' !== $s['key'] && '' !== $s['model'];
    }

    /**
     * Whether this WordPress has the AI Client and Connectors (7.0+).
     */
    public static function connectors_exist() {
        return function_exists( 'wp_ai_client_prompt' ) && function_exists( 'wp_supports_ai' );
    }

    /**
     * Whether a connector that can generate text is set up. Cached briefly,
     * since providers may look up their model list to answer.
     */
    public static function connectors_ready() {
        if ( ! self::connectors_exist() || ! wp_supports_ai() ) {
            return false;
        }
        $cached = get_transient( 'kpgen_connectors_ready' );
        if ( false !== $cached ) {
            return 'yes' === $cached;
        }
        $ready = true === wp_ai_client_prompt( 'Test' )->is_supported_for_text_generation();
        set_transient( 'kpgen_connectors_ready', $ready ? 'yes' : 'no', 10 * MINUTE_IN_SECONDS );
        return $ready;
    }

    /**
     * Encrypt the API key at rest (AES-256-CBC, random IV, key from the site's salts).
     */
    public static function encrypt( $plain ) {
        if ( '' === $plain || ! function_exists( 'openssl_encrypt' ) ) {
            return $plain;
        }
        $iv     = random_bytes( 16 );
        $cipher = openssl_encrypt( $plain, 'aes-256-cbc', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, $iv );
        return false === $cipher ? '' : 'enc:' . base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext storage.
    }

    public static function decrypt( $stored ) {
        if ( 0 !== strpos( (string) $stored, 'enc:' ) ) {
            return (string) $stored;
        }
        $raw = base64_decode( substr( $stored, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        if ( false === $raw || strlen( $raw ) < 17 ) {
            return '';
        }
        $plain = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
        return false === $plain ? '' : $plain;
    }

    /* ------------------------------------------------------------------
     *  Content
     * ----------------------------------------------------------------*/

    /**
     * Rewrite post content (blocks, classic HTML, Divi or WPBakery text).
     *
     * @return string|WP_Error Rewritten content, or an error (content unchanged).
     */
    public static function rewrite_markup( $content, array $keywords ) {
        if ( has_blocks( $content ) ) {
            $blocks = parse_blocks( $content );
            $refs   = array();
            self::collect_blocks( $blocks, $refs );
            if ( ! $refs ) {
                return $content;
            }
            $out = self::rewrite_fragments( array_map( function ( $r ) {
                return $r['html'];
            }, $refs ), $keywords );
            if ( is_wp_error( $out ) ) {
                return $out;
            }
            foreach ( $refs as $i => &$ref ) {
                if ( $out[ $i ] !== $ref['html'] ) {
                    $ref['block']['innerHTML']    = $ref['lead'] . $out[ $i ] . $ref['tail'];
                    $ref['block']['innerContent'] = array( $ref['block']['innerHTML'] );
                }
            }
            unset( $ref );
            return serialize_blocks( $blocks );
        }

        // Classic editor / page-builder shortcodes: rewrite the inside of p, h1-h6 and li.
        if ( ! preg_match_all( '#<(p|h[1-6]|li)\b[^>]*>(.*?)</\1>#is', $content, $m, PREG_OFFSET_CAPTURE ) ) {
            return $content;
        }
        $targets = array();
        foreach ( $m[2] as $match ) {
            if ( mb_strlen( trim( wp_strip_all_tags( $match[0] ) ) ) >= self::MIN_LENGTH ) {
                $targets[] = $match;
            }
        }
        if ( ! $targets ) {
            return $content;
        }
        $out = self::rewrite_fragments( wp_list_pluck( $targets, 0 ), $keywords );
        if ( is_wp_error( $out ) ) {
            return $out;
        }
        // Splice from the end so earlier offsets stay valid.
        for ( $i = count( $targets ) - 1; $i >= 0; $i-- ) {
            $content = substr_replace( $content, $out[ $i ], $targets[ $i ][1], strlen( $targets[ $i ][0] ) );
        }
        return $content;
    }

    /**
     * Collect references to rewritable leaf blocks, including those nested in
     * groups, columns and other containers.
     */
    private static function collect_blocks( array &$blocks, array &$refs ) {
        foreach ( $blocks as &$block ) {
            if ( ! empty( $block['innerBlocks'] ) ) {
                self::collect_blocks( $block['innerBlocks'], $refs );
                continue;
            }
            if ( ! in_array( $block['blockName'], self::TEXT_BLOCKS, true ) ) {
                continue;
            }
            $html = (string) $block['innerHTML'];
            if ( mb_strlen( trim( wp_strip_all_tags( $html ) ) ) < self::MIN_LENGTH ) {
                continue;
            }
            // Keep surrounding whitespace out of the fragment so it round-trips exactly.
            preg_match( '/^(\s*)(.*?)(\s*)$/s', $html, $parts );
            $refs[] = array(
                'block' => &$block,
                'lead'  => $parts[1],
                'html'  => $parts[2],
                'tail'  => $parts[3],
            );
        }
        unset( $block );
    }

    /**
     * Rewrite the text widgets of a generated post's Elementor data.
     *
     * @return true|WP_Error
     */
    public static function rewrite_elementor( $post_id, array $keywords ) {
        $data = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
        if ( ! is_array( $data ) ) {
            return true;
        }

        $refs = array();
        self::collect_elementor( $data, $refs );
        if ( ! $refs ) {
            return true;
        }

        $out = self::rewrite_fragments( array_column( $refs, 'value' ), $keywords );
        if ( is_wp_error( $out ) ) {
            return $out;
        }
        foreach ( $refs as $i => &$ref ) {
            $ref['slot'] = $out[ $i ]; // writes through to $data
        }
        unset( $ref );

        update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
        delete_post_meta( $post_id, '_elementor_css' );
        return true;
    }

    private static function collect_elementor( array &$elements, array &$refs ) {
        foreach ( $elements as &$el ) {
            $type = $el['widgetType'] ?? '';
            if ( isset( self::ELEMENTOR_WIDGETS[ $type ] ) ) {
                foreach ( self::ELEMENTOR_WIDGETS[ $type ] as $key ) {
                    if ( isset( $el['settings'][ $key ] ) && is_string( $el['settings'][ $key ] )
                        && mb_strlen( trim( wp_strip_all_tags( $el['settings'][ $key ] ) ) ) >= self::MIN_LENGTH ) {
                        $refs[] = array(
                            'value' => $el['settings'][ $key ],
                            'slot'  => &$el['settings'][ $key ],
                        );
                    }
                }
            }
            if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
                self::collect_elementor( $el['elements'], $refs );
            }
        }
        unset( $el );
    }

    /* ------------------------------------------------------------------
     *  Model calls
     * ----------------------------------------------------------------*/

    /**
     * Rewrite a list of HTML fragments. Returns a same-length list; any
     * fragment whose markup changed is replaced by its original.
     *
     * @return array|WP_Error
     */
    public static function rewrite_fragments( array $fragments, array $keywords ) {
        $fragments = array_values( $fragments );
        $result    = array();

        foreach ( array_chunk( $fragments, self::CHUNK ) as $chunk ) {
            $text = self::call( self::system_prompt(), wp_json_encode( array(
                'page_topic' => implode( ', ', $keywords ),
                'fragments'  => $chunk,
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
            if ( is_wp_error( $text ) ) {
                return $text;
            }

            $rewritten = self::parse_fragments( $text );
            if ( ! is_array( $rewritten ) || count( $rewritten ) !== count( $chunk ) ) {
                return new WP_Error( 'kpgen_ai_format', __( 'The AI response did not match the page structure, so the keyword-replaced text was kept.', 'keyword-page-generator' ) );
            }

            foreach ( $chunk as $i => $original ) {
                $new      = is_string( $rewritten[ $i ] ) ? trim( $rewritten[ $i ] ) : '';
                $result[] = self::same_structure( $original, $new ) ? $new : $original;
            }
        }
        return $result;
    }

    private static function system_prompt() {
        $settings = self::settings();
        $prompt   = "You rewrite website copy so that several similar pages don't read as duplicates.\n"
            . "The user message is JSON with a page_topic and a list of HTML fragments from one page, in order.\n"
            . "Rewrite the words of each fragment so it reads naturally and specifically for the page_topic, keeping the meaning, facts, tone and roughly the same length.\n"
            . "Rules:\n"
            . "- Keep every HTML tag and attribute exactly as it is (same tags, same order, same href/src/class values). Only change the text between tags.\n"
            . "- Keep names, prices, phone numbers, addresses and other facts unchanged. Don't invent new facts.\n"
            . "- Return one rewritten fragment for every input fragment, in the same order.\n"
            . "- Reply with only a JSON object: {\"fragments\": [\"...\", \"...\"]}";

        if ( '' !== trim( $settings['prompt'] ) ) {
            $prompt .= "\n\nAdditional instructions from the site owner:\n" . trim( $settings['prompt'] );
        }
        return $prompt;
    }

    /**
     * A rewrite is accepted only if it keeps the same tags, links and media
     * and doesn't add text before the first or after the last tag.
     */
    private static function same_structure( $original, $new ) {
        if ( '' === $new || self::markup_signature( $new ) !== self::markup_signature( $original ) ) {
            return false;
        }
        $orig = trim( $original );
        return ( '<' === $orig[0] ) === ( '<' === $new[0] )
            && ( '>' === substr( $orig, -1 ) ) === ( '>' === substr( $new, -1 ) );
    }

    /**
     * Tags and link/media targets of a fragment, used to check the model
     * changed only text.
     */
    private static function markup_signature( $html ) {
        preg_match_all( '/<\/?[a-z][a-z0-9]*\b[^>]*>/i', $html, $m );
        return implode( '', array_map( function ( $tag ) {
            preg_match( '/^<\/?([a-z][a-z0-9]*)/i', $tag, $name );
            preg_match_all( '/\s(href|src|srcset|class|id)\s*=\s*("[^"]*"|\'[^\']*\')/i', $tag, $attrs, PREG_SET_ORDER );
            $keep = array_map( function ( $a ) {
                return strtolower( $a[1] ) . '=' . trim( $a[2], '"\'' );
            }, $attrs );
            sort( $keep );
            return '<' . ( '/' === $tag[1] ? '/' : '' ) . strtolower( $name[1] ) . ' ' . implode( ' ', $keep ) . '>';
        }, $m[0] ) );
    }

    private static function parse_fragments( $text ) {
        $start = strpos( $text, '{' );
        $end   = strrpos( $text, '}' );
        if ( false === $start || false === $end ) {
            return null;
        }
        $json = json_decode( substr( $text, $start, $end - $start + 1 ), true );
        return isset( $json['fragments'] ) && is_array( $json['fragments'] ) ? $json['fragments'] : null;
    }

    /**
     * Send one request to the configured provider and return the reply text.
     *
     * @return string|WP_Error
     */
    public static function call( $system, $user, $settings = null ) {
        $settings = $settings ?: self::settings();

        if ( 'wordpress' === $settings['source'] ) {
            return self::call_connectors( $system, $user );
        }

        $key      = self::decrypt( $settings['key'] );
        $model    = trim( $settings['model'] );

        if ( '' === $key ) {
            return new WP_Error( 'kpgen_ai_key', __( 'No AI API key is saved.', 'keyword-page-generator' ) );
        }
        if ( '' === $model ) {
            return new WP_Error( 'kpgen_ai_model', __( 'Choose an AI model first.', 'keyword-page-generator' ) );
        }

        switch ( $settings['provider'] ) {
            case 'openai':
                return self::call_openai( $system, $user, $key, $model );
            case 'gemini':
                return self::call_gemini( $system, $user, $key, $model );
            default:
                return self::call_anthropic( $system, $user, $key, $model );
        }
    }

    /**
     * Use the provider the site owner set up in Settings → Connectors.
     */
    private static function call_connectors( $system, $user ) {
        if ( ! self::connectors_exist() || ! wp_supports_ai() ) {
            return new WP_Error( 'kpgen_ai_connectors', __( 'WordPress Connectors are not available on this site. Choose “My own API key” on the AI rewriting tab.', 'keyword-page-generator' ) );
        }
        $text = wp_ai_client_prompt( $user )
            ->using_system_instruction( $system )
            ->using_max_tokens( 16000 )
            ->generate_text();

        if ( is_wp_error( $text ) ) {
            return $text;
        }
        return '' !== trim( (string) $text ) ? (string) $text : new WP_Error( 'kpgen_ai_empty', __( 'The AI returned an empty response.', 'keyword-page-generator' ) );
    }

    private static function post( $url, array $headers, array $body ) {
        $response = wp_remote_post( $url, array(
            'timeout' => 120,
            'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
            'body'    => wp_json_encode( $body ),
        ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code >= 400 || ! is_array( $data ) ) {
            $message = $data['error']['message'] ?? ( is_string( $data['error'] ?? null ) ? $data['error'] : sprintf( 'HTTP %d', $code ) );
            return new WP_Error( 'kpgen_ai_http', $message );
        }
        return $data;
    }

    private static function call_anthropic( $system, $user, $key, $model ) {
        $headers = array(
            'x-api-key'         => $key,
            'anthropic-version' => '2023-06-01',
        );
        $body    = array(
            'model'      => $model,
            'max_tokens' => 16000,
            'system'     => $system,
            'messages'   => array( array( 'role' => 'user', 'content' => $user ) ),
        );
        // If the model's safety classifiers decline, let Anthropic retry on its recommended fallback model.
        if ( in_array( $model, array( 'claude-opus-5-5', 'claude-sonnet-5-5' ), true ) ) {
            $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
            $body['fallbacks']         = 'default';
        }

        $data = self::post( 'https://api.anthropic.com/v1/messages', $headers, $body );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        if ( 'refusal' === ( $data['stop_reason'] ?? '' ) ) {
            return new WP_Error( 'kpgen_ai_refusal', __( 'The model declined to rewrite this page.', 'keyword-page-generator' ) );
        }
        // Newer models return thinking blocks before the answer; read only text blocks.
        $text = '';
        foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
            if ( 'text' === ( $block['type'] ?? '' ) ) {
                $text .= $block['text'];
            }
        }
        if ( 'max_tokens' === ( $data['stop_reason'] ?? '' ) ) {
            return new WP_Error( 'kpgen_ai_truncated', __( 'The AI response was cut off; the page may be too long for one request.', 'keyword-page-generator' ) );
        }
        return '' !== $text ? $text : new WP_Error( 'kpgen_ai_empty', __( 'The AI returned an empty response.', 'keyword-page-generator' ) );
    }

    private static function call_openai( $system, $user, $key, $model ) {
        $data = self::post( 'https://api.openai.com/v1/chat/completions', array( 'Authorization' => 'Bearer ' . $key ), array(
            'model'           => $model,
            'messages'        => array(
                array( 'role' => 'system', 'content' => $system ),
                array( 'role' => 'user', 'content' => $user ),
            ),
            'response_format' => array( 'type' => 'json_object' ),
        ) );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        $text = $data['choices'][0]['message']['content'] ?? '';
        return '' !== $text ? $text : new WP_Error( 'kpgen_ai_empty', __( 'The AI returned an empty response.', 'keyword-page-generator' ) );
    }

    private static function call_gemini( $system, $user, $key, $model ) {
        $data = self::post(
            'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent',
            array( 'x-goog-api-key' => $key ),
            array(
                'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
                'contents'          => array( array( 'role' => 'user', 'parts' => array( array( 'text' => $user ) ) ) ),
                'generationConfig'  => array( 'responseMimeType' => 'application/json' ),
            )
        );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        $text = '';
        foreach ( (array) ( $data['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
            $text .= $part['text'] ?? '';
        }
        return '' !== $text ? $text : new WP_Error( 'kpgen_ai_empty', __( 'The AI returned an empty response.', 'keyword-page-generator' ) );
    }
}
