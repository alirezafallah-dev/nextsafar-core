<?php

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

/**
 * Endpoint: /nextsafar/v1/country-posts?id=108
 * Returns: hotels, tours, destinations, travel guides of a country
 */
class CountryPostsEndpoint {
    const CACHE_KEY      = 'ns_country_posts_';
    const CACHE_DURATION = 1800; // 30 minutes
    const TAXONOMY       = 'tourism';
    const TOUR_TAX       = 'tour_category';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);

        add_action('save_post', [__CLASS__, 'flush']);
        add_action('deleted_post', [__CLASS__, 'flush']);
        add_action('created_' . self::TAXONOMY, [__CLASS__, 'flush']);
        add_action('edited_' . self::TAXONOMY, [__CLASS__, 'flush']);
    }

    public static function flush() {
        global $wpdb;

        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_" . self::CACHE_KEY . "%'
                OR option_name LIKE '_transient_timeout_" . self::CACHE_KEY . "%'"
        );
    }

    public static function register_routes() {
        register_rest_route('nextsafar/v1', '/country-posts', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_posts'],
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    public static function get_posts($request) {
        try {
            $country_id = (int) $request->get_param('id');

            if ($country_id <= 0) {
                return new \WP_Error('invalid_id', 'شناسه نامعتبر', ['status' => 400]);
            }

            $cache_key = self::CACHE_KEY . $country_id;
            $cached = get_transient($cache_key);

            if ($cached !== false) {
                return rest_ensure_response($cached);
            }

            /* 1) All cities under this country in tourism */
            $city_terms = get_terms([
                'taxonomy'   => self::TAXONOMY,
                'parent'     => $country_id,
                'hide_empty' => false,
            ]);

            if (is_wp_error($city_terms)) {
                $city_terms = [];
            }

            $city_ids   = [];
            $city_names = [];

            foreach ($city_terms as $t) {
                $city_ids[]   = (int) $t->term_id;
                $city_names[] = $t->name;
            }

            /* 2) Posts by type */
            $hotels       = self::get_posts_by_taxonomy('hotel', self::TAXONOMY, $city_ids, 4);
            $destinations = self::get_posts_by_taxonomy('destination', self::TAXONOMY, $city_ids, 4);
            $guides       = self::get_posts_by_taxonomy('travelguide', self::TAXONOMY, $city_ids, 4);

            /* 3) Tours — from tour categories matching city names */
            $tours = self::get_tour_packages_for_country($city_names, 6);

            /* 4) Visa */
            $visa = self::get_visa_for_country($country_id);

            $out = [
                'country_id'   => $country_id,
                'hotels'       => $hotels,
                'tours'        => $tours,
                'destinations' => $destinations,
                'guides'       => $guides,
                'visa'         => $visa,
            ];

            set_transient($cache_key, $out, self::CACHE_DURATION);

            return rest_ensure_response($out);
        } catch (\Throwable $e) {
            error_log('❌ CountryPostsEndpoint Error: ' . $e->getMessage());

            return new \WP_Error('endpoint_error', $e->getMessage(), ['status' => 500]);
        }
    }

    private static function get_posts_by_taxonomy($post_type, $taxonomy, $term_ids, $limit) {
        if (empty($term_ids)) return [];

        $posts = get_posts([
            'post_type'      => $post_type,
            'posts_per_page' => $limit,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'tax_query'      => [[
                'taxonomy' => $taxonomy,
                'field'    => 'term_id',
                'terms'    => $term_ids,
            ]],
        ]);

        $out = [];

        foreach ($posts as $p) {
            $out[] = self::format_post($p);
        }

        return $out;
    }

    private static function get_tours_for_country($city_names, $limit) {
        if (empty($city_names)) return [];

        global $wpdb;

        $city_tour_cats = [];

        /* ═══ Method 1: Exact name match ═══ */
        $like_clauses = [];
        $params = [self::TOUR_TAX];

        foreach ($city_names as $name) {
            $like_clauses[] = 't.name = %s';
            $params[] = $name;
        }

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT t.term_id
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy = %s
               AND (" . implode(' OR ', $like_clauses) . ")",
            $params
        ));

        $city_tour_cats = array_merge($city_tour_cats, $ids);

        /* ═══ Method 2: Slug match (English) ═══ */
        foreach ($city_names as $name) {
            $slug = sanitize_title($name);

            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT t.term_id
                 FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                 WHERE tt.taxonomy = %s AND t.slug = %s",
                [self::TOUR_TAX, $slug]
            ));

            $city_tour_cats = array_merge($city_tour_cats, $ids);
        }

        /* ═══ Method 3: LIKE for non-exact matches ═══ */
        foreach ($city_names as $name) {
            $like = '%' . $wpdb->esc_like($name) . '%';

            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT t.term_id
                 FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                 WHERE tt.taxonomy = %s AND t.name LIKE %s",
                [self::TOUR_TAX, $like]
            ));

            $city_tour_cats = array_merge($city_tour_cats, $ids);
        }

        /* Remove duplicates */
        $city_tour_cats = array_unique(array_map('intval', $city_tour_cats));

        if (empty($city_tour_cats)) return [];

        /* ═══ Find tour posts that belong to these categories ═══ */
        $tours = get_posts([
            'post_type'      => 'tour',
            'posts_per_page' => $limit,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'tax_query'      => [[
                'taxonomy'         => self::TOUR_TAX,
                'field'            => 'term_id',
                'terms'            => $city_tour_cats,
                'include_children' => true,
            ]],
        ]);

        $out = [];

        foreach ($tours as $p) {
            $out[] = self::format_post($p);
        }

        return $out;
    }

    private static function get_visa_for_country($country_id) {
        $posts = get_posts([
            'post_type'      => 'visa',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'tax_query'      => [[
                'taxonomy' => self::TAXONOMY,
                'field'    => 'term_id',
                'terms'    => $country_id,
            ]],
        ]);

        if (empty($posts)) return null;

        return self::format_post($posts[0]);
    }

    private static function format_post($post) {
        $image_id = get_post_thumbnail_id($post->ID);
        $image    = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : null;

        $prefix_map = [
            'hotel'       => '/hotels/',
            'tour'        => '/tours/',
            'destination' => '/destinations/',
            'travelguide' => '/travel-guides/',
            'visa'        => '/visa/',
        ];

        return [
            'id'    => $post->ID,
            'title' => wp_strip_all_tags($post->post_title),
            'slug'  => $post->post_name,
            'type'  => $post->post_type,
            'image' => $image,
            'url'   => ($prefix_map[$post->post_type] ?? '/') . $post->post_name,
        ];
    }

    /* ==========================================================================
       Tour Packages: Night-level categories
       Name matching strategy:
       1) Exact match with name
       2) Exact match with slug
       3) LIKE containing name ("تور استانبول" contains "استانبول")
       4) LIKE containing slug
       ========================================================================== */
    private static function get_tour_packages_for_country($city_names, $limit) {
        if (empty($city_names)) {
            error_log('🗺️ CountryPosts: No city_names provided');
            return [];
        }

        global $wpdb;

        $city_tour_cat_ids = [];

        /* ═══ 1) Exact match with name ═══ */
        foreach ($city_names as $name) {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT t.term_id FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt
                    ON tt.term_id = t.term_id AND tt.taxonomy = %s
                 WHERE t.name = %s",
                self::TOUR_TAX,
                $name
            ));

            $city_tour_cat_ids = array_merge($city_tour_cat_ids, $ids);
        }

        /* ═══ 2) Exact match with slug ═══ */
        foreach ($city_names as $name) {
            $slug = sanitize_title($name);

            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT t.term_id FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt
                    ON tt.term_id = t.term_id AND tt.taxonomy = %s
                 WHERE t.slug = %s",
                self::TOUR_TAX,
                $slug
            ));

            $city_tour_cat_ids = array_merge($city_tour_cat_ids, $ids);
        }

        /* ═══ 3) LIKE containing name (for "تور استانبول" containing "استانبول") ═══ */
        foreach ($city_names as $name) {
            $like = '%' . $wpdb->esc_like($name) . '%';

            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT t.term_id FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt
                    ON tt.term_id = t.term_id AND tt.taxonomy = %s
                 WHERE t.name LIKE %s",
                self::TOUR_TAX,
                $like
            ));

            $city_tour_cat_ids = array_merge($city_tour_cat_ids, $ids);
        }

        /* ═══ 4) LIKE containing slug ═══ */
        foreach ($city_names as $name) {
            $slug = sanitize_title($name);
            $like = '%' . $wpdb->esc_like($slug) . '%';

            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT t.term_id FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt
                    ON tt.term_id = t.term_id AND tt.taxonomy = %s
                 WHERE t.slug LIKE %s",
                self::TOUR_TAX,
                $like
            ));

            $city_tour_cat_ids = array_merge($city_tour_cat_ids, $ids);
        }

        /* Remove duplicates */
        $city_tour_cat_ids = array_unique(array_map('intval', $city_tour_cat_ids));

        error_log('🗺️ CountryPosts: City tour_category IDs found: ' . implode(',', $city_tour_cat_ids));

        if (empty($city_tour_cat_ids)) return [];

        /* ═══ Get subcategories (night-level packages) ═══ */
        $placeholders = implode(',', array_fill(0, count($city_tour_cat_ids), '%d'));
        $params = array_merge([self::TOUR_TAX], $city_tour_cat_ids);

        $packages = $wpdb->get_results($wpdb->prepare(
            "SELECT t.term_id, t.name, t.slug, tt.parent AS city_cat_id,
                    m_dep.meta_value    AS departure_en,
                    m_dep_fa.meta_value AS departure_fa,
                    m_ret.meta_value    AS return_en,
                    m_dur.meta_value    AS duration,
                    m_tr.meta_value     AS transport,
                    m_al.meta_value     AS airline,
                    m_curr.meta_value   AS currency,
                    m_photo.meta_value  AS photo
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt
                ON tt.term_id = t.term_id AND tt.taxonomy = %s
             LEFT JOIN {$wpdb->termmeta} m_dep
                ON m_dep.term_id = t.term_id AND m_dep.meta_key = 'departure_date_en'
             LEFT JOIN {$wpdb->termmeta} m_dep_fa
                ON m_dep_fa.term_id = t.term_id AND m_dep_fa.meta_key = 'departure_date'
             LEFT JOIN {$wpdb->termmeta} m_ret
                ON m_ret.term_id = t.term_id AND m_ret.meta_key = 'return_date_en'
             LEFT JOIN {$wpdb->termmeta} m_dur
                ON m_dur.term_id = t.term_id AND m_dur.meta_key = 'duration'
             LEFT JOIN {$wpdb->termmeta} m_tr
                ON m_tr.term_id = t.term_id AND m_tr.meta_key = 'transport'
             LEFT JOIN {$wpdb->termmeta} m_al
                ON m_al.term_id = t.term_id AND m_al.meta_key = 'airline'
             LEFT JOIN {$wpdb->termmeta} m_curr
                ON m_curr.term_id = t.term_id AND m_curr.meta_key = 'currency_unit'
             LEFT JOIN {$wpdb->termmeta} m_photo
                ON m_photo.term_id = t.term_id AND m_photo.meta_key = 'term_tour_photo'
             WHERE tt.parent IN ($placeholders)
             ORDER BY
                CASE WHEN m_dep.meta_value IS NULL OR m_dep.meta_value = '' THEN 1 ELSE 0 END,
                STR_TO_DATE(m_dep.meta_value, '%%Y-%%m-%%d') ASC
             LIMIT %d",
            array_merge($params, [$limit * 2])
        ));

        error_log('🗺️ CountryPosts: Packages found: ' . count($packages));

        if (empty($packages)) return [];

        $out = [];

        foreach ($packages as $pkg) {
            $image = null;

            if (!empty($pkg->photo)) {
                if (is_numeric($pkg->photo)) {
                    $image = wp_get_attachment_image_url((int) $pkg->photo, 'medium')
                        ?: wp_get_attachment_url((int) $pkg->photo)
                        ?: null;
                } else {
                    $image = filter_var($pkg->photo, FILTER_VALIDATE_URL) ? $pkg->photo : null;
                }
            }

            $out[] = [
                'id'           => (int) $pkg->term_id,
                'title'        => wp_strip_all_tags($pkg->name),
                'slug'         => $pkg->slug,
                'image'        => $image,
                'departure_en' => $pkg->departure_en ?: null,
                'departure_fa' => $pkg->departure_fa ?: null,
                'return_en'    => $pkg->return_en ?: null,
                'nights'       => !empty($pkg->duration) ? (int) $pkg->duration : null,
                'transport'    => $pkg->transport ?: null,
                'airline'      => $pkg->airline ?: null,
                'currency'     => $pkg->currency ?: null,
                'url'          => '/tours?cat=' . $pkg->slug,
            ];
        }

        return array_slice($out, 0, $limit);
    }
}