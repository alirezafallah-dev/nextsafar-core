<?php

/**
 * NextSafar News REST API Endpoints
 * API for use in Next.js frontend
 *
 * Endpoints:
 *   GET /wp-json/nextsafar/v1/news          - News list
 *   GET /wp-json/nextsafar/v1/news/{slug}   - Single news details
 *   GET /wp-json/nextsafar/v1/news/categories - Categories
 *   GET /wp-json/nextsafar/v1/news/stats    - Statistics
 *
 * @version 1.0.0
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

class NewsEndpoint {
    const NAMESPACE = 'nextsafar/v1';
    const POST_TYPE = 'travelnews';
    const TAXONOMY = 'travelnews_category';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    /* ========================================================================
       Register all endpoints
       ======================================================================== */
    public static function register_routes() {
        // 1. News list
        register_rest_route(self::NAMESPACE, '/news', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_news_list'],
            'permission_callback' => '__return_true',
            'args' => [
                'page' => [
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'default' => 12,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param <= 100;
                    },
                ],
                'category' => [
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'search' => [
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'orderby' => [
                    'default' => 'date',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function($param) {
                        return in_array($param, ['date', 'title', 'score', 'popular']);
                    },
                ],
                'order' => [
                    'default' => 'DESC',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // 2. Single news details (by slug)
        register_rest_route(self::NAMESPACE, '/news/(?P<slug>[a-z0-9-]+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_news_single'],
            'permission_callback' => '__return_true',
            'args' => [
                'slug' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_title',
                ],
            ],
        ]);

        // 3. Categories list
        register_rest_route(self::NAMESPACE, '/news/categories', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_categories'],
            'permission_callback' => '__return_true',
        ]);

        // 4. News statistics
        register_rest_route(self::NAMESPACE, '/news/stats', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_stats'],
            'permission_callback' => '__return_true',
        ]);

        // 5. Related news (Recommendation)
        register_rest_route(self::NAMESPACE, '/news/(?P<slug>[a-z0-9-]+)/related', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_related_news'],
            'permission_callback' => '__return_true',
            'args' => [
                'slug' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_title',
                ],
                'limit' => [
                    'default' => 4,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    /* ========================================================================
       GET /news - News list
       ======================================================================== */
    public static function get_news_list(\WP_REST_Request $request) {
        $page = $request->get_param('page');
        $per_page = min($request->get_param('per_page'), 100);
        $category = $request->get_param('category');
        $search = $request->get_param('search');
        $orderby = $request->get_param('orderby');
        $order = $request->get_param('order');

        // Build query
        $args = [
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => $orderby === 'popular' ? 'meta_value_num' : $orderby,
            'order' => $order,
        ];

        // Sort by popularity
        if ($orderby === 'popular') {
            $args['meta_key'] = '_ns_views';
        } elseif ($orderby === 'score') {
            $args['meta_key'] = '_ns_filter_score';
            $args['orderby'] = 'meta_value_num';
        }

        // Category filter
        if (!empty($category)) {
            $args['tax_query'] = [
                [
                    'taxonomy' => self::TAXONOMY,
                    'field' => 'slug',
                    'terms' => $category,
                ],
            ];
        }

        // Search
        if (!empty($search)) {
            $args['s'] = $search;
        }

        $query = new \WP_Query($args);
        $posts = $query->posts;

        // Convert to API format
        $news = array_map([__CLASS__, 'format_news_item'], $posts);

        // Build pagination
        $total_pages = $query->max_num_pages;
        $total_items = $query->found_posts;

        $response = [
            'success' => true,
            'data' => $news,
            'pagination' => [
                'page' => (int) $page,
                'per_page' => (int) $per_page,
                'total_pages' => (int) $total_pages,
                'total_items' => (int) $total_items,
                'has_next' => $page < $total_pages,
                'has_prev' => $page > 1,
            ],
            'filters' => [
                'category' => $category,
                'search' => $search,
                'orderby' => $orderby,
                'order' => $order,
            ],
        ];

        return new \WP_REST_Response($response, 200);
    }

    /* ========================================================================
       GET /news/{slug} - Single news details
       ======================================================================== */
    public static function get_news_single(\WP_REST_Request $request) {
        $slug = $request->get_param('slug');

        $post = get_page_by_path($slug, OBJECT, self::POST_TYPE);

        if (!$post || $post->post_status !== 'publish') {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'خبر مورد نظر یافت نشد',
            ], 404);
        }

        // Increment views
        $views = (int) get_post_meta($post->ID, '_ns_views', true);
        update_post_meta($post->ID, '_ns_views', $views + 1);

        // Build full data
        $news = self::format_news_item($post, true);

        // Add SEO metadata
        $news['seo'] = [
            'title' => get_post_meta($post->ID, '_yoast_wpseo_title', true) ?: $post->post_title,
            'description' => get_post_meta($post->ID, '_yoast_wpseo_metadesc', true) ?: $post->post_excerpt,
            'canonical' => get_permalink($post->ID),
            'published_time' => get_the_date('c', $post),
            'modified_time' => get_the_modified_date('c', $post),
            'author' => get_the_author_meta('display_name', $post->post_author),
            'type' => 'NewsArticle',
        ];

        // Open Graph
        $news['og'] = [
            'title' => $post->post_title,
            'description' => $post->post_excerpt,
            'image' => get_the_post_thumbnail_url($post->ID, 'large'),
            'url' => get_permalink($post->ID),
            'type' => 'article',
        ];

        return new \WP_REST_Response([
            'success' => true,
            'data' => $news,
        ], 200);
    }

    /* ========================================================================
       GET /news/categories - Categories list
       ======================================================================== */
    public static function get_categories(\WP_REST_Request $request) {
        $categories = get_terms([
            'taxonomy' => self::TAXONOMY,
            'hide_empty' => true,
            'orderby' => 'count',
            'order' => 'DESC',
        ]);

        if (is_wp_error($categories)) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'خطا در دریافت دسته‌بندی‌ها',
            ], 500);
        }

        $data = array_map(function($term) {
            $image_id = get_term_meta($term->term_id, 'term_image', true);

            return [
                'id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'description' => $term->description,
                'count' => $term->count,
                'image' => $image_id ? wp_get_attachment_image_url($image_id, 'medium') : null,
                'link' => get_term_link($term),
            ];
        }, $categories);

        return new \WP_REST_Response([
            'success' => true,
            'data' => $data,
            'total' => count($data),
        ], 200);
    }

    /* ========================================================================
       GET /news/stats - News statistics
       ======================================================================== */
    public static function get_stats(\WP_REST_Request $request) {
        $cache_key = 'nextsafar_news_public_stats';
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return new \WP_REST_Response([
                'success' => true,
                'data' => $cached,
            ], 200);
        }

        $post_count = wp_count_posts(self::POST_TYPE);

        global $wpdb;

        // Calculate total views
        $total_views = (int) $wpdb->get_var(
            "SELECT SUM(meta_value) FROM {$wpdb->postmeta}
             WHERE meta_key = '_ns_views'
             AND post_id IN (
                 SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = '" . self::POST_TYPE . "'
                 AND post_status = 'publish'
             )"
        );

        // Categories
        $categories_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->term_taxonomy}
             WHERE taxonomy = '" . self::TAXONOMY . "'"
        );

        $stats = [
            'total_news' => (int) ($post_count->publish ?? 0),
            'total_views' => $total_views,
            'total_categories' => $categories_count,
            'total_drafts' => (int) ($post_count->draft ?? 0),
        ];

        // Cache for 5 minutes
        set_transient($cache_key, $stats, 5 * MINUTE_IN_SECONDS);

        return new \WP_REST_Response([
            'success' => true,
            'data' => $stats,
        ], 200);
    }

    /* ========================================================================
       GET /news/{slug}/related - Related news
       ======================================================================== */
    public static function get_related_news(\WP_REST_Request $request) {
        $slug = $request->get_param('slug');
        $limit = $request->get_param('limit');

        $post = get_page_by_path($slug, OBJECT, self::POST_TYPE);

        if (!$post) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'خبر یافت نشد',
            ], 404);
        }

        // Get categories of this news
        $categories = wp_get_post_terms($post->ID, self::TAXONOMY, ['fields' => 'ids']);

        // If no categories, use latest news
        if (empty($categories)) {
            $args = [
                'post_type' => self::POST_TYPE,
                'post_status' => 'publish',
                'posts_per_page' => $limit,
                'post__not_in' => [$post->ID],
                'orderby' => 'date',
                'order' => 'DESC',
            ];
        } else {
            $args = [
                'post_type' => self::POST_TYPE,
                'post_status' => 'publish',
                'posts_per_page' => $limit,
                'post__not_in' => [$post->ID],
                'tax_query' => [
                    [
                        'taxonomy' => self::TAXONOMY,
                        'field' => 'term_id',
                        'terms' => $categories,
                    ],
                ],
            ];
        }

        $related = get_posts($args);
        $data = array_map([__CLASS__, 'format_news_item'], $related);

        return new \WP_REST_Response([
            'success' => true,
            'data' => $data,
            'total' => count($data),
        ], 200);
    }

    /* ========================================================================
       Format a news item for API
       ======================================================================== */
    private static function format_news_item($post, bool $full = false): array {
        if (is_numeric($post)) {
            $post = get_post($post);
        }

        $categories = wp_get_post_terms($post->ID, self::TAXONOMY);

        $categories_data = array_map(function($cat) {
            return [
                'id' => $cat->term_id,
                'name' => $cat->name,
                'slug' => $cat->slug,
            ];
        }, $categories);

        // Base data (for list)
        $data = [
            'id' => $post->ID,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'excerpt' => $post->post_excerpt ?: wp_trim_words($post->post_content, 30),
            'date' => get_the_date('c', $post),
            'modified' => get_the_modified_date('c', $post),
            'author' => get_the_author_meta('display_name', $post->post_author),
            'featured_image' => [
                'thumbnail' => get_the_post_thumbnail_url($post->ID, 'thumbnail'),
                'medium' => get_the_post_thumbnail_url($post->ID, 'medium'),
                'large' => get_the_post_thumbnail_url($post->ID, 'large'),
                'full' => get_the_post_thumbnail_url($post->ID, 'full'),
            ],
            'categories' => $categories_data,
            'link' => get_permalink($post->ID),
            'views' => (int) get_post_meta($post->ID, '_ns_views', true),
            'source' => [
                'name' => get_post_meta($post->ID, '_ns_source_name', true),
                'url' => get_post_meta($post->ID, '_ns_source_url', true),
            ],
            'ai_rewritten' => get_post_meta($post->ID, '_ns_ai_used', true) === '1',
        ];

        // Full data (only for details)
        if ($full) {
            $data['content'] = apply_filters('the_content', $post->post_content);
            $data['gallery'] = self::get_gallery($post->ID);
            $data['tags'] = wp_get_post_tags($post->ID, ['fields' => 'names']);
            $data['meta'] = [
                'reading_time' => self::calculate_reading_time($post->post_content),
                'word_count' => str_word_count(strip_tags($post->post_content)),
            ];

            // Admin only - filter data
            if (current_user_can('manage_options')) {
                $data['filter_debug'] = [
                    'score' => (int) get_post_meta($post->ID, '_ns_filter_score', true),
                    'decision' => get_post_meta($post->ID, '_ns_filter_decision', true),
                    'reason' => get_post_meta($post->ID, '_ns_filter_reason', true),
                ];
            }
        }

        return $data;
    }

    /* ========================================================================
       Get image gallery
       ======================================================================== */
    private static function get_gallery(int $post_id): array {
        $gallery = get_post_meta($post_id, '_ns_gallery', true);

        if (empty($gallery)) {
            return [];
        }

        if (is_string($gallery)) {
            $gallery = maybe_unserialize($gallery);
        }

        return array_map(function($img_id) {
            return [
                'id' => $img_id,
                'thumbnail' => wp_get_attachment_image_url($img_id, 'thumbnail'),
                'medium' => wp_get_attachment_image_url($img_id, 'medium'),
                'large' => wp_get_attachment_image_url($img_id, 'large'),
                'full' => wp_get_attachment_image_url($img_id, 'full'),
                'alt' => get_post_meta($img_id, '_wp_attachment_image_alt', true),
            ];
        }, (array) $gallery);
    }

    /* ========================================================================
       Calculate reading time
       ======================================================================== */
    private static function calculate_reading_time(string $content): int {
        $word_count = str_word_count(strip_tags($content));

        return max(1, ceil($word_count / 200)); // 200 words per minute
    }
}