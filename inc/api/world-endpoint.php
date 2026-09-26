<?php

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

/**
 * World Data Map (Version 2 — tourism-based)
 *
 * Logic:
 *  - tourism terms with parent=0 = Country (has image/banner/flag)
 *  - tourism terms with parent>0 = City (has image/banner)
 *  - Post counts from term_relationships
 *  - Nearest tour from tour_category with same name as city (level 2, its children are level 3)
 *  - Visa from visa posts connected to country term
 */
class WorldEndpoint {
    const CACHE_KEY      = 'ns_geo_world_v2';
    const CACHE_DURATION = 3600;
    const TAXONOMY       = 'tourism';
    const TOUR_TAX       = 'tour_category';

    /* ========================================================================
       Initialization
       ======================================================================== */
    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);

        /* Cache invalidation on any data change */
        add_action('save_post', [__CLASS__, 'flush']);
        add_action('deleted_post', [__CLASS__, 'flush']);
        add_action('created_' . self::TAXONOMY, [__CLASS__, 'flush']);
        add_action('edited_'  . self::TAXONOMY, [__CLASS__, 'flush']);
        add_action('delete_'  . self::TAXONOMY, [__CLASS__, 'flush']);
        add_action('created_' . self::TOUR_TAX, [__CLASS__, 'flush']);
        add_action('edited_'  . self::TOUR_TAX, [__CLASS__, 'flush']);
        add_action('delete_'  . self::TOUR_TAX, [__CLASS__, 'flush']);
    }

    /* ========================================================================
       Flush Cache
       ======================================================================== */
    public static function flush() {
        delete_transient(self::CACHE_KEY);
    }

    /* ========================================================================
       Register Routes
       ======================================================================== */
    public static function register_routes() {
        register_rest_route('nextsafar/v1', '/geo/world', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_world'],
            'permission_callback' => '__return_true',
        ]);
    }

    /* ========================================================================
       Get World Data
       ======================================================================== */
    public static function get_world() {
        $cached = get_transient(self::CACHE_KEY);

        if ($cached !== false) {
            return ['countries' => $cached, 'cached' => true];
        }

        /* 1) All tourism terms */
        $terms = get_terms([
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (is_wp_error($terms)) {
            return ['countries' => []];
        }

        /* 2) Categorize by country/city */
        $countries        = [];
        $cities_by_parent = [];
        $city_term_ids    = [];   /* NEW: List of city IDs */

        foreach ($terms as $t) {
            if ((int) $t->parent === 0) {
                $countries[$t->term_id] = self::build_country($t);
            } else {
                $cities_by_parent[(int) $t->parent][] = $t;
                $city_term_ids[] = (int) $t->term_id;   /* City ID itself */
            }
        }

        /* 3) Count all posts in one query — with city IDs ✅ */
        $counts = self::count_all_posts($city_term_ids);

        /* 4) Cache all visas (based on connection to country) */
        $visa = self::get_visa_by_country(array_keys($countries));

        /* 5) Complete each country */
        $output = [];

        foreach ($countries as $country_id => $country) {
            $country_cities = $cities_by_parent[$country_id] ?? [];

            if (empty($country_cities)) continue; /* Country without city is not displayed */

            $cities_data  = [];
            $totals       = ['dest' => 0, 'hotel' => 0, 'tour' => 0, 'other' => 0];

            foreach ($country_cities as $city_term) {
                $city_counts = $counts[$city_term->term_id] ?? [];
                $next_tour   = self::find_next_tour_for_city($city_term);

                $city = [
                    'id'        => $city_term->term_id,
                    'name'      => $city_term->name,
                    'slug'      => $city_term->slug,
                    'image'     => self::term_image_url($city_term->term_id, 'tourism_image', 'medium'),
                    'banner'    => self::term_image_url($city_term->term_id, 'tourism_banner', 'medium'),
                    'counts'    => $city_counts,
                    'next_tour' => $next_tour,
                ];

                $cities_data[] = $city;

                /* Aggregate for country totals */
                $totals['dest']  += (int) ($city_counts['destination'] ?? 0);
                $totals['hotel'] += (int) ($city_counts['hotel'] ?? 0);
                $totals['tour']  += (int) ($city_counts['tour'] ?? 0);
                $totals['other'] += (int) ($city_counts['restaurant'] ?? 0)
                    + (int) ($city_counts['hospital'] ?? 0)
                    + (int) ($city_counts['airport'] ?? 0)
                    + (int) ($city_counts['travelguide'] ?? 0);
            }

            /* Sort cities by total posts (hottest first) */
            usort($cities_data, function ($a, $b) {
                $sumA = array_sum($a['counts']);
                $sumB = array_sum($b['counts']);
                return $sumB <=> $sumA;
            });

            $score = $totals['dest'] + ($totals['hotel'] * 2) + ($totals['tour'] * 2) + $totals['other'];

            $country['cities']  = $cities_data;
            $country['totals']  = $totals;
            $country['score']   = $score;
            $country['visa']    = $visa[$country_id] ?? null;

            $output[] = $country;
        }

        /* Sort countries by score */
        usort($output, fn($a, $b) => $b['score'] <=> $a['score']);

        set_transient(self::CACHE_KEY, $output, self::CACHE_DURATION);

        return ['countries' => $output];
    }

    /* ========================================================================
       Build Base Country Data (Without cities)
       ======================================================================== */
    private static function build_country(\WP_Term $t): array {
        return [
            'id'     => $t->term_id,
            'name'   => $t->name,
            'slug'   => $t->slug,
            'image'  => self::term_image_url($t->term_id, 'tourism_image', 'large'),
            'banner' => self::term_image_url($t->term_id, 'tourism_banner', 'large'),
            'flag'   => self::term_image_url($t->term_id, 'tourism_flag', 'medium'),
        ];
    }

    /* ========================================================================
       Term Image URL (Both attachment ID and old URL)
       ======================================================================== */
    private static function term_image_url(int $term_id, string $meta_key, string $size = 'medium'): ?string {
        $value = get_term_meta($term_id, '_' . $meta_key, true);

        if (empty($value)) return null;

        if (is_numeric($value)) {
            return wp_get_attachment_image_url((int) $value, $size)
                ?: wp_get_attachment_url((int) $value)
                ?: null;
        }

        return filter_var($value, FILTER_VALIDATE_URL) ? esc_url_raw($value) : null;
    }

    /* ========================================================================
       Count All Posts Connected to City Terms in One Query
       Output: [term_id => [post_type => count]]
       ======================================================================== */
    private static function count_all_posts(array $city_term_ids): array {
        if (empty($city_term_ids)) return [];

        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($city_term_ids), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tt.term_id, p.post_type, COUNT(*) AS cnt
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt
                ON tt.term_taxonomy_id = tr.term_taxonomy_id
                AND tt.taxonomy = %s
                AND tt.term_id IN ($placeholders)
             INNER JOIN {$wpdb->posts} p
                ON p.ID = tr.object_id
                AND p.post_status = 'publish'
                AND p.post_type IN ('hotel','destination','tour','restaurant','hospital','airport','travelguide')
             GROUP BY tt.term_id, p.post_type",
            array_merge([self::TAXONOMY], $city_term_ids)
        ));

        $out = [];

        foreach ((array) $rows as $r) {
            $out[(int) $r->term_id][$r->post_type] = (int) $r->cnt;
        }

        return $out;
    }

    /* ========================================================================
       Cache Visas: visa post connected to country term in tourism
       Output: [country_term_id => {title, slug}]
       ======================================================================== */
    private static function get_visa_by_country(array $country_term_ids): array {
        if (empty($country_term_ids)) return [];

        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($country_term_ids), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tt.term_id, p.post_title, p.post_name
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr
                ON tr.object_id = p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt
                ON tt.term_taxonomy_id = tr.term_taxonomy_id
                AND tt.taxonomy = %s
                AND tt.term_id IN ($placeholders)
             WHERE p.post_type = 'visa'
             AND p.post_status = 'publish'
             GROUP BY tt.term_id
             ORDER BY p.post_date DESC",
            array_merge([self::TAXONOMY], $country_term_ids)
        ));

        $out = [];

        foreach ((array) $rows as $r) {
            $out[(int) $r->term_id] = [
                'title' => wp_strip_all_tags($r->post_title),
                'slug'  => $r->post_name,
            ];
        }

        return $out;
    }

    /* ========================================================================
       Nearest Tour for a City
       Logic:
       1) Find tour_category with slug or name same as city
       2) Get its children (level 3 = actual tours)
       3) Each child has departure_date_en → select nearest future one
       ======================================================================== */
    private static function find_next_tour_for_city(\WP_Term $city_term): ?array {
        global $wpdb;

        /* 1) Find city-level tour_category:
           First exact match, then contains match (like "تور استانبول") */
        $city_tour_cat = $wpdb->get_row($wpdb->prepare(
            "SELECT t.term_id
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt
                ON tt.term_id = t.term_id AND tt.taxonomy = %s
             WHERE t.slug = %s OR t.name = %s
             LIMIT 1",
            self::TOUR_TAX,
            $city_term->slug,
            $city_term->name
        ));

        /* Fallback: Contains name ("تور استانبول" contains "استانبول") */
        if (!$city_tour_cat) {
            $like = '%' . $wpdb->esc_like($city_term->name) . '%';

            $city_tour_cat = $wpdb->get_row($wpdb->prepare(
                "SELECT t.term_id
                 FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt
                    ON tt.term_id = t.term_id AND tt.taxonomy = %s
                 WHERE t.name LIKE %s
                 LIMIT 1",
                self::TOUR_TAX,
                $like
            ));
        }

        if (!$city_tour_cat) return null;

        /* 2) Level 3 children (actual tours) with future departure date */
        $tours = $wpdb->get_results($wpdb->prepare(
            "SELECT t.term_id, t.name, t.slug,
                    m_dep.meta_value AS departure_en,
                    m_dur.meta_value AS duration,
                    m_tr.meta_value  AS transport,
                    m_al.meta_value  AS airline
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt
                ON tt.term_id = t.term_id
                AND tt.taxonomy = %s
                AND tt.parent = %d
             LEFT JOIN {$wpdb->termmeta} m_dep
                ON m_dep.term_id = t.term_id AND m_dep.meta_key = 'departure_date_en'
             LEFT JOIN {$wpdb->termmeta} m_dur
                ON m_dur.term_id = t.term_id AND m_dur.meta_key = 'duration'
             LEFT JOIN {$wpdb->termmeta} m_tr
                ON m_tr.term_id = t.term_id AND m_tr.meta_key = 'transport'
             LEFT JOIN {$wpdb->termmeta} m_al
                ON m_al.term_id = t.term_id AND m_al.meta_key = 'airline'
             WHERE m_dep.meta_value IS NOT NULL
             AND m_dep.meta_value <> ''
             AND STR_TO_DATE(m_dep.meta_value, '%%Y-%%m-%%d') >= CURDATE()
             ORDER BY STR_TO_DATE(m_dep.meta_value, '%%Y-%%m-%%d') ASC
             LIMIT 5",
            self::TOUR_TAX,
            (int) $city_tour_cat->term_id
        ));

        if (empty($tours)) return null;

        $t = $tours[0];

        return [
            'title'        => wp_strip_all_tags($t->name),
            'slug'         => $t->slug,
            'departure_en' => $t->departure_en,
            'departure_fa' => self::gregorian_to_jalali_str($t->departure_en),
            'nights'       => (int) $t->duration ?: null,
            'transport'    => $t->transport ?: null,
            'airline'      => $t->airline ?: null,
        ];
    }

    /* ========================================================================
       Convert Gregorian to Jalali (Format YYYY/MM/DD)
       ======================================================================== */
    private static function gregorian_to_jalali_str(string $gregorian): string {
        $ts = strtotime($gregorian);

        if (!$ts) return $gregorian;

        $j = \IntlDateFormatter::createFromPattern('y/M/d', 'fa_IR@calendar=persian')
            ?->format($ts);

        if (!$j) {
            /* Fallback: Only use Persian digits */
            $j = date('Y/m/d', $ts);
        }

        return $j;
    }
}