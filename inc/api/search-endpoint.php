<?php

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

class SearchEndpoint {
    const EN_NAME_KEYS = [
        '_geo_name_en',
        '_name_en', 'name_en', '_en_name', '_english_name',
        '_title_en', 'title_en', '_hotel_name_en', '_city_name_en',
    ];

    /* Search cache - 5 minutes */
    const CACHE_DURATION = 300;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    /* ========================================================================
       Register Routes
       ======================================================================== */
    public static function register_routes() {
        register_rest_route('nextsafar/v1', '/search', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'search'],
            'permission_callback' => '__return_true',
            'args' => [
                'q' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'per_type' => ['default' => 3, 'sanitize_callback' => 'absint'],
                'types' => ['default' => '', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route('nextsafar/v1', '/hotels', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'hotels_by_city'],
            'permission_callback' => '__return_true',
            'args' => [
                'city' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'limit' => ['default' => 12, 'sanitize_callback' => 'absint'],
            ],
        ]);
    }

    /* ========================================================================
       Optimized Search with One Query Per Post Type
       ======================================================================== */
    public static function search($request) {
        $q = trim($request->get_param('q'));
        $per_type = min(max(1, (int) $request->get_param('per_type')), 6);
        $types_filter = $request->get_param('types');

        if (mb_strlen($q) < 2) {
            return ['results' => []];
        }

        /* Check cache */
        $cache_key = 'ns_search_v2_' . md5($q . '_' . $per_type . '_' . $types_filter);
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return ['results' => $cached, 'cached' => true];
        }

        $routes = [
            'hotel' => '/hotels',
            'destination' => '/destinations',
            'restaurant' => '/restaurants',
            'airport' => '/airports',
            'visa' => '/visa',
            'tour' => '/tours',
            'travelguide' => '/travelguide',
            'travelnews' => '/news',
            'hospital' => '/hospitals',
        ];

        /* Filter post types if needed */
        if (!empty($types_filter)) {
            $allowed_types = array_map('trim', explode(',', $types_filter));
            $routes = array_intersect_key($routes, array_flip($allowed_types));
        }

        $results = [];
        $like_q = '%' . $q . '%';

        foreach ($routes as $pt => $base) {
            if (!post_type_exists($pt)) continue;

            $found = self::search_post_type_optimized($pt, $q, $like_q, $per_type);

            foreach ($found as $p) {
                $results[] = [
                    'id' => $p->ID,
                    'type' => $pt,
                    'title' => wp_strip_all_tags($p->post_title),
                    'url' => $base . '/' . $p->post_name,
                    'image' => get_the_post_thumbnail_url($p->ID, 'thumbnail') ?: null,
                    'address' => self::short_address($p->ID),
                ];
            }
        }

        /* Save to cache */
        set_transient($cache_key, $results, self::CACHE_DURATION);

        return ['results' => $results];
    }

    /* ========================================================================
       Optimized Search for a Single Post Type (Direct Query - No Filter Hack)
       Bug fix: The previous version used posts_where which added an OR
       that bypassed the post_type constraint and caused duplicate results.
       ======================================================================== */
    private static function search_post_type_optimized(string $post_type, string $q, string $like_q, int $limit): array {
        global $wpdb;

        /* OR conditions for English names in postmeta */
        $meta_clauses = [];

        foreach (self::EN_NAME_KEYS as $k) {
            $meta_clauses[] = $wpdb->prepare(
                '(pm.meta_key = %s AND pm.meta_value LIKE %s)',
                $k,
                $like_q
            );
        }

        $meta_sql = implode(' OR ', $meta_clauses);

        $sql = "SELECT DISTINCT p.ID, p.post_title, p.post_name
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                WHERE p.post_type = %s
                AND p.post_status = 'publish'
                AND (
                    p.post_title LIKE %s
                    OR p.post_content LIKE %s
                    OR {$meta_sql}
                )
                ORDER BY p.post_date DESC
                LIMIT %d";

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, $post_type, $like_q, $like_q, $limit)
        );

        if (empty($rows)) return [];

        /* Convert to format compatible with the caller code */
        return array_map(function ($row) {
            $p = new \stdClass();
            $p->ID         = (int) $row->ID;
            $p->post_title = $row->post_title;
            $p->post_name  = $row->post_name;
            return $p;
        }, $rows);
    }

    /* ========================================================================
       Short Address: Smart Scan of All Meta Boxes
       ======================================================================== */
    private static function short_address($post_id) {
        $all_meta = get_post_meta($post_id);

        $city = null;
        $country = null;
        $address = null;

        foreach ($all_meta as $key => $values) {
            $val = is_array($values) ? ($values[0] ?? '') : $values;

            if (!is_string($val) || trim($val) === '' || strlen($val) > 200) continue;
            if (strpos($val, 'a:') === 0 || strpos($val, '{') === 0) continue;

            $lk = strtolower($key);
            $val = trim($val);

            if ($city === null && (strpos($lk, 'city') !== false || strpos($lk, 'shahr') !== false)) {
                $city = $val;
            }

            if ($country === null && (strpos($lk, 'country') !== false || strpos($lk, 'keshvar') !== false)) {
                $country = $val;
            }

            if ($address === null && (strpos($lk, 'address') !== false || strpos($lk, 'location') !== false || strpos($lk, 'region') !== false)) {
                $address = $val;
            }
        }

        if ($city || $country) {
            return trim(($city ?: '') . ($city && $country ? ', ' : '') . ($country ?: ''));
        }

        if ($address) {
            $parts = array_map('trim', explode(',', $address));
            return count($parts) > 2 ? implode(', ', array_slice($parts, -2)) : $address;
        }

        return null;
    }

    /* ========================================================================
       Optimized Hotels by City Search
       ======================================================================== */
    public static function hotels_by_city($request) {
        $city = trim($request->get_param('city'));
        $limit = min(max(1, (int) $request->get_param('limit')), 50);
        $needle = mb_strtolower($city);

        if (mb_strlen($needle) < 2) {
            return ['results' => []];
        }

        /* Cache */
        $cache_key = 'ns_hotels_city_' . md5($needle . '_' . $limit);
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return ['results' => $cached, 'cached' => true];
        }

        /* Direct query with meta instead of loading all hotels */
        $q = new \WP_Query([
            'post_type' => 'hotel',
            'post_status' => 'publish',
            'posts_per_page' => 100,
            'no_found_rows' => true,
            'meta_query' => [
                'relation' => 'OR',
                ['key' => '_geo_city', 'value' => $needle, 'compare' => 'LIKE'],
                ['key' => '_hotel_city', 'value' => $needle, 'compare' => 'LIKE'],
                ['key' => '_geo_country', 'value' => $needle, 'compare' => 'LIKE'],
                ['key' => '_hotel_country', 'value' => $needle, 'compare' => 'LIKE'],
            ],
        ]);

        $results = [];

        foreach ($q->posts as $p) {
            $geo = \NextSafar\Sync\GeoSchema::to_array($p->ID);

            $results[] = [
                'id' => $p->ID,
                'title' => wp_strip_all_tags($p->post_title),
                'url' => '/hotels/' . $p->post_name,
                'image' => get_the_post_thumbnail_url($p->ID, 'medium') ?: null,
                'address' => self::short_address($p->ID),
                'lat' => $geo['lat'] ?: null,
                'lng' => $geo['lng'] ?: null,
                'place_id' => $geo['place_id'] ?: null,
            ];

            if (count($results) >= $limit) break;
        }

        /* Save cache */
        set_transient($cache_key, $results, self::CACHE_DURATION);

        return ['results' => $results];
    }
}