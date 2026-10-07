<?php
/**
 * Keyword replacement that only touches visible text.
 *
 * Image URLs, links, CSS classes, IDs and shortcode settings are left alone,
 * so replacing "Melbourne" never turns melbourne-office.jpg into a broken
 * sydney-office.jpg. All pairs are applied in a single pass (a replacement
 * value is never replaced again by a later pair), whole words only, and the
 * case of each match is kept: MELBOURNE → SYDNEY, melbourne → sydney.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KPGen_Replacer {

    /** HTML attributes that hold human-readable text. */
    const TEXT_ATTRIBUTES = array( 'alt', 'title', 'aria-label', 'placeholder' );

    /** Keys whose values are never text (Elementor / block attributes / builder settings). */
    const SKIP_KEYS = '/^(id|url|link|href|src|image|images|_?css_classes|_?element_id|class(name)?|anchor|ref|mediaid|mediatype|slug|_id|module_id|module_class|.*_(url|link|id|src|class))$/i';

    /** @var array lowercase find => replacement */
    private $map = array();

    /** @var string|null */
    private $pattern = null;

    /**
     * @param array $pairs List of [ 'find' => string, 'replace' => string ].
     */
    public function __construct( array $pairs ) {
        $finds = array();
        foreach ( $pairs as $pair ) {
            $find = trim( (string) $pair['find'] );
            if ( '' === $find ) {
                continue;
            }
            $this->map[ mb_strtolower( $find ) ] = (string) $pair['replace'];
            $finds[]                             = $find;
        }
        if ( $finds ) {
            // Longest first so "Melbourne CBD" wins over "Melbourne".
            usort( $finds, function ( $a, $b ) {
                return mb_strlen( $b ) - mb_strlen( $a );
            } );
            $alternation   = implode( '|', array_map( function ( $f ) {
                return preg_quote( $f, '/' );
            }, $finds ) );
            // Whole words, but allow a plural or possessive ending: "plumbers" → "electricians".
            $this->pattern = '/(?<![\p{L}\p{N}])(' . $alternation . ')(?=(?:s|\'s|\x{2019}s)?(?![\p{L}\p{N}]))/iu';
        }
    }

    /**
     * Replace keywords in plain text.
     */
    public function text( $text ) {
        if ( null === $this->pattern || ! is_string( $text ) || '' === $text ) {
            return $text;
        }
        $out = preg_replace_callback( $this->pattern, array( $this, 'match_case' ), $text );
        return null === $out ? $text : $out;
    }

    private function match_case( $m ) {
        $found   = $m[1];
        $replace = $this->map[ mb_strtolower( $found ) ] ?? $found;

        if ( mb_strtoupper( $found ) === $found && mb_strtolower( $found ) !== $found ) {
            return mb_strtoupper( $replace );
        }
        if ( mb_strtolower( $found ) === $found ) {
            return mb_strtolower( $replace );
        }
        return $replace;
    }

    /**
     * Replace keywords in HTML / block markup / shortcode content.
     */
    public function markup( $html ) {
        if ( null === $this->pattern || ! is_string( $html ) || '' === $html ) {
            return $html;
        }

        $tokens = preg_split( '/(<!--.*?-->|<[^>]*>|\[\/?[a-zA-Z_][\w-]*(?:\s[^\]]*)?\])/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
        foreach ( $tokens as $i => $token ) {
            if ( 0 === $i % 2 ) {
                $tokens[ $i ] = $this->text( $token );
            } elseif ( 0 === strpos( $token, '<!--' ) ) {
                $tokens[ $i ] = $this->block_comment( $token );
            } elseif ( '<' === $token[0] ) {
                $tokens[ $i ] = $this->html_tag( $token );
            } else {
                $tokens[ $i ] = $this->shortcode_tag( $token );
            }
        }
        return implode( '', $tokens );
    }

    /**
     * Block delimiters carry attributes as JSON; some of them are visible text
     * (and must match the HTML, or the editor reports the block as invalid).
     */
    private function block_comment( $comment ) {
        if ( ! preg_match( '/^<!--\s+(wp:[^\s]+)\s+(\{.*\})\s+(\/?)-->$/s', $comment, $m ) ) {
            return $comment;
        }
        $attrs = json_decode( $m[2], true );
        if ( ! is_array( $attrs ) ) {
            return $comment;
        }
        $attrs = $this->structure( $attrs );
        return '<!-- ' . $m[1] . ' ' . serialize_block_attributes( $attrs ) . ' ' . $m[3] . '-->';
    }

    private function html_tag( $tag ) {
        if ( preg_match( '/^<\s*\//', $tag ) ) {
            return $tag;
        }
        $attrs = implode( '|', array_map( 'preg_quote', self::TEXT_ATTRIBUTES ) );
        return preg_replace_callback(
            '/(\s(?:' . $attrs . ')\s*=\s*)(["\'])(.*?)\2/is',
            function ( $m ) {
                return $m[1] . $m[2] . $this->text( $m[3] ) . $m[2];
            },
            $tag
        );
    }

    /**
     * Builders like Divi keep some visible text in shortcode attributes
     * (title="…", button_text="…"); URLs, IDs and classes are skipped.
     */
    private function shortcode_tag( $tag ) {
        if ( ! preg_match( '/^\[([a-zA-Z_][\w-]*)/', $tag, $name ) || ! $this->is_shortcode( $name[1] ) ) {
            // Plain bracketed text like "[Melbourne office]".
            return '[' . $this->text( substr( $tag, 1, -1 ) ) . ']';
        }
        return preg_replace_callback(
            '/(\s([\w-]+)\s*=\s*)(["\'])(.*?)\3/s',
            function ( $m ) {
                if ( preg_match( self::SKIP_KEYS, $m[2] ) || $this->looks_like_url( $m[4] ) ) {
                    return $m[0];
                }
                return $m[1] . $m[3] . $this->text( $m[4] ) . $m[3];
            },
            $tag
        );
    }

    private function is_shortcode( $name ) {
        return shortcode_exists( $name ) || preg_match( '/^(et_pb_|vc_|fusion_|av_|cs_)/', $name );
    }

    /**
     * Walk decoded data (Elementor JSON, serialized meta, block attributes),
     * replacing in text values only.
     */
    public function structure( $data, $key = '' ) {
        if ( is_array( $data ) ) {
            foreach ( $data as $k => $v ) {
                $data[ $k ] = $this->structure( $v, is_int( $k ) ? $key : (string) $k );
            }
            return $data;
        }
        if ( is_object( $data ) && ! ( $data instanceof __PHP_Incomplete_Class ) ) {
            foreach ( get_object_vars( $data ) as $k => $v ) {
                $data->$k = $this->structure( $v, (string) $k );
            }
            return $data;
        }
        if ( ! is_string( $data ) || '' === $data || ( '' !== $key && preg_match( self::SKIP_KEYS, $key ) ) || $this->looks_like_url( $data ) ) {
            return $data;
        }
        return ( false !== strpos( $data, '<' ) || false !== strpos( $data, '[' ) ) ? $this->markup( $data ) : $this->text( $data );
    }

    /**
     * Replace keywords in a raw post meta value (serialized, JSON or plain).
     */
    public function meta_value( $key, $raw ) {
        if ( ! is_string( $raw ) || '' === $raw || is_numeric( $raw ) ) {
            return $raw;
        }
        if ( is_serialized( $raw ) ) {
            $data = maybe_unserialize( $raw );
            return $this->structure( $data, $key );
        }
        $first = $raw[0];
        if ( '[' === $first || '{' === $first ) {
            $json = json_decode( $raw, true );
            if ( is_array( $json ) ) {
                return wp_json_encode( $this->structure( $json, $key ) );
            }
        }
        if ( preg_match( self::SKIP_KEYS, ltrim( $key, '_' ) ) || $this->looks_like_url( $raw ) ) {
            return $raw;
        }
        return ( false !== strpos( $raw, '<' ) || false !== strpos( $raw, '[' ) ) ? $this->markup( $raw ) : $this->text( $raw );
    }

    private function looks_like_url( $value ) {
        return (bool) preg_match( '#^(https?:)?//|^/[\w.-]|^\#|^(mailto|tel):|\.(jpe?g|png|gif|webp|avif|svg|pdf|mp4|webm|mp3|css|js)(\?.*)?$#i', trim( $value ) );
    }
}
