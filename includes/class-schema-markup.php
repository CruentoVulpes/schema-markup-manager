<?php

namespace SchemaMarkupManager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Schema_Markup {

    private static $already_output = false;

    public static function wp_head_start_buffer() {
        ob_start();
        self::output_schema_markup();
    }

    public static function wp_head_end_buffer_and_dedupe() {
        $html = ob_get_clean();
        if ( $html === false || $html === '' ) {
            return;
        }
        echo self::remove_duplicate_schema_blocks( $html );
    }

    const PLUGIN_BLOCK_MARKER = 'data-schema-source="schema-markup-manager-plugin"';

    private static function remove_duplicate_schema_blocks( $html ) {
        $pattern = '/<script(?=[^>]*type\s*=\s*["\']application\/ld\+json["\'])(?=[^>]*class\s*=\s*["\']schema-markup-(?:manager|page|theme)["\'])[^>]*>.*?<\/script>\s*/is';
        $blocks  = [];
        preg_match_all( $pattern, $html, $blocks );
        if ( empty( $blocks[0] ) ) {
            return $html;
        }
        $keep_index = 0;
        foreach ( $blocks[0] as $i => $block ) {
            if ( strpos( $block, self::PLUGIN_BLOCK_MARKER ) !== false ) {
                $keep_index = $i;
                break;
            }
        }
        $count = 0;
        return preg_replace_callback( $pattern, function ( $m ) use ( $keep_index, &$count ) {
            $cur = $count++;
            return $cur === $keep_index ? $m[0] : '';
        }, $html );
    }

    public static function output_schema_markup() {
        if ( self::$already_output ) {
            return;
        }

        global $post;

        $post_id = $post && isset( $post->ID ) ? (int) $post->ID : 0;
        $per_page_map = get_option( 'schema_markup_per_page', [] );

        if ( $post_id && ! empty( $per_page_map ) && ! empty( $per_page_map[ $post_id ] ) ) {
            $decoded = json_decode( $per_page_map[ $post_id ], true );
            if ( json_last_error() === JSON_ERROR_NONE ) {
                $decoded = self::inject_aggregate_rating( $decoded );
                $formatted = json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                self::$already_output = true;
                echo "\n" . '<script type="application/ld+json" class="schema-markup-page" ' . self::PLUGIN_BLOCK_MARKER . '>' . "\n";
                echo $formatted . "\n";
                echo '</script>' . "\n";
                return;
            }
        }

        $is_front_page = is_front_page() || is_home();
        $allowed_types = [ 'Product', 'Review', 'BreadcrumbList' ];

        $custom_markup = get_option( 'schema_markup_custom', '' );
        if ( ! empty( $custom_markup ) ) {
            $decoded = json_decode( $custom_markup, true );
            if ( json_last_error() === JSON_ERROR_NONE ) {
                $decoded = self::inject_aggregate_rating( $decoded );
                $filtered = self::filter_markup( $decoded, $is_front_page, $allowed_types );
                if ( ! empty( $filtered ) ) {
                    self::$already_output = true;
                    $formatted = json_encode( $filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                    echo "\n" . '<script type="application/ld+json" class="schema-markup-manager" ' . self::PLUGIN_BLOCK_MARKER . '>' . "\n";
                    echo $formatted . "\n";
                    echo '</script>' . "\n";
                }
                return;
            }
        }

        if ( ! get_option( 'schema_markup_disable_old', false ) ) {
            $options = get_option( 'mytheme_options', [] );
            $schema_markup = $options['schema_markup'] ?? '';

            if ( ! empty( $schema_markup ) ) {
                $decoded = json_decode( $schema_markup, true );
                if ( json_last_error() === JSON_ERROR_NONE ) {
                    $decoded = self::inject_aggregate_rating( $decoded );
                    $filtered = self::filter_markup( $decoded, $is_front_page, $allowed_types );
                    if ( ! empty( $filtered ) ) {
                        self::$already_output = true;
                        $formatted = json_encode( $filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                        echo "\n" . '<script type="application/ld+json" class="schema-markup-theme" ' . self::PLUGIN_BLOCK_MARKER . '>' . "\n";
                        echo $formatted . "\n";
                        echo '</script>' . "\n";
                    }
                }
            }
        }

        if ( ! self::$already_output ) {
            $rating = self::get_aggregate_rating_fallback();
            if ( $rating !== null ) {
                $schema = [
                    '@context'        => 'https://schema.org',
                    '@type'           => 'Organization',
                    'name'            => get_bloginfo( 'name' ),
                    'url'             => home_url( '/' ),
                    'aggregateRating' => self::normalize_aggregate_rating( $rating ),
                ];
                self::$already_output = true;
                $formatted = json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                echo "\n" . '<script type="application/ld+json" class="schema-markup-manager" ' . self::PLUGIN_BLOCK_MARKER . '>' . "\n";
                echo $formatted . "\n";
                echo '</script>' . "\n";
            }
        }
    }

    private static function get_aggregate_rating_fallback(): ?array {
        $dynamic_rating = apply_filters( 'auto_reviews_aggregate_rating_schema', null );
        if ( ( $dynamic_rating === null || ! is_array( $dynamic_rating ) ) && class_exists( 'AutoReviews\Plugin' ) ) {
            $plugin = \AutoReviews\Plugin::instance();
            if ( method_exists( $plugin, 'get_aggregate_rating_for_schema' ) ) {
                $dynamic_rating = $plugin->get_aggregate_rating_for_schema();
            }
        }
        if ( $dynamic_rating === null || ! is_array( $dynamic_rating ) ) {
            $dynamic_rating = self::build_aggregate_rating_from_posts();
        }
        return $dynamic_rating;
    }

    const AUTO_REVIEW_CPT    = 'auto_review';
    const AUTO_REVIEW_META   = '_auto_reviews_rating';
    const AUTO_REVIEW_OPTION = 'auto_reviews_settings';

    private static function inject_aggregate_rating( array $data ): array {
        $dynamic_rating = apply_filters( 'auto_reviews_aggregate_rating_schema', null );
        if ( ( $dynamic_rating === null || ! is_array( $dynamic_rating ) ) && class_exists( 'AutoReviews\Plugin' ) ) {
            $plugin = \AutoReviews\Plugin::instance();
            if ( method_exists( $plugin, 'get_aggregate_rating_for_schema' ) ) {
                $dynamic_rating = $plugin->get_aggregate_rating_for_schema();
            }
        }
        if ( $dynamic_rating === null || ! is_array( $dynamic_rating ) ) {
            $dynamic_rating = self::build_aggregate_rating_from_posts();
        }
        if ( $dynamic_rating === null || ! is_array( $dynamic_rating ) ) {
            return $data;
        }
        $dynamic_rating = self::normalize_aggregate_rating( $dynamic_rating );
        return self::replace_aggregate_rating_recursive( $data, $dynamic_rating );
    }

    private static function build_aggregate_rating_from_posts(): ?array {
        $cpt = apply_filters( 'schema_markup_manager_auto_review_cpt', self::AUTO_REVIEW_CPT );
        if ( ! post_type_exists( $cpt ) ) {
            return null;
        }
        $posts = get_posts( [
            'post_type'      => $cpt,
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'fields'         => 'ids',
        ] );
        if ( empty( $posts ) ) {
            return null;
        }
        $ratings = [];
        foreach ( $posts as $post_id ) {
            $rating = (int) get_post_meta( $post_id, self::AUTO_REVIEW_META, true );
            $ratings[] = $rating <= 0 ? 5 : $rating;
        }
        $avg_rating  = array_sum( $ratings ) / count( $ratings );
        $review_count = count( $ratings );
        $settings = get_option( self::AUTO_REVIEW_OPTION, [] );
        if ( ! empty( $settings['external_reviews_offset'] ) ) {
            $review_count += max( 0, (int) $settings['external_reviews_offset'] );
        }
        return [
            '@type'       => 'AggregateRating',
            'ratingValue' => number_format( (float) $avg_rating, 2, '.', '' ),
            'reviewCount' => $review_count,
            'ratingCount' => $review_count,
            'bestRating'  => 5,
            'worstRating' => 1,
        ];
    }

    private static function normalize_aggregate_rating( array $arr ): array {
        if ( empty( $arr['@type'] ) ) {
            $arr['@type'] = 'AggregateRating';
        }
        if ( isset( $arr['reviewCount'] ) && ! isset( $arr['ratingCount'] ) ) {
            $arr['ratingCount'] = $arr['reviewCount'];
        }
        if ( isset( $arr['ratingValue'] ) && is_numeric( $arr['ratingValue'] ) ) {
            $arr['ratingValue'] = number_format( (float) $arr['ratingValue'], 2, '.', '' );
        }
        return $arr;
    }

    private static function replace_aggregate_rating_recursive( array $data, array $replacement ): array {
        foreach ( $data as $key => $value ) {
            if ( $key === 'aggregateRating' ) {
                $data[ $key ] = $replacement;
                continue;
            }
            if ( is_array( $value ) ) {
                $data[ $key ] = self::replace_aggregate_rating_recursive( $value, $replacement );
            }
        }
        return $data;
    }

    private static function filter_markup( $data, $is_front_page, $allowed_types ) {
        if ( $is_front_page ) {
            return $data;
        }

        if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
            $filtered_graph = [];
            foreach ( $data['@graph'] as $item ) {
                if ( isset( $item['@type'] ) && in_array( $item['@type'], $allowed_types, true ) ) {
                    $filtered_graph[] = $item;
                }
            }

            if ( ! empty( $filtered_graph ) ) {
                $result = $data;
                $result['@graph'] = $filtered_graph;
                return $result;
            }
            return null;
        }

        if ( isset( $data['@type'] ) && in_array( $data['@type'], $allowed_types, true ) ) {
            return $data;
        }

        return null;
    }
}
