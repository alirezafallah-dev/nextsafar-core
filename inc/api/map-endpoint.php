<?php

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

/**
 * Map Endpoint — Version 1.1 (Fixed and Unified)
 *
 * GET /nextsafar/v1/map/nearby    → Nearby places (two modes: with lat/lng or with post_type+slug)
 * GET /nextsafar/v1/map/coords    → Coordinates of a post by slug
 * GET /nextsafar/v1/map/entities  → Plan entities (legacy)
 * GET /nextsafar/v1/map/config    → Map tile config (from backend to frontend)
 */
class MapEndpoint {
    const TYPES = ['hotel', 'destination', 'restaurant', 'airport', 'hospital', 'travelguide'];

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    /* ========================================================================
       Register Routes
       ======================================================================== */
    public static function register_routes(): void {
        register_rest_route('nextsafar/v1', '/map/nearby', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_nearby'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('nextsafar/v1', '/map/coords', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_coords'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('nextsafar/v1', '/map/entities', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_entities'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('nextsafar/v1', '/map/config', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_map_config'],
            'permission_callback' => '__return_true',
        ]);
    }

    /* ========================================================================
       General Map Config (Key from backend, not frontend env)
       ======================================================================== */
    public static function get_map_config($request) {
        $key   = trim((string) get_option('ns_ai_trip_jawg_key', ''));
        $theme = sanitize_key((string) get_option('ns_map_tile_theme', 'ofm-liberty'));

        $osm = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';

        $themes = [
            /* ✅ No key required — Liberty is closest to reference design */
            'ofm-liberty'   => 'https://tiles.openfreemap.org/styles/liberty/{z}/{x}/{y}.png',
            'ofm-bright'    => 'https://tiles.openfreemap.org/styles/bright/{z}/{x}/{y}.png',
            'ofm-positron'  => 'https://tiles.openfreemap.org/styles/positron/{z}/{x}/{y}.png',
            'osm-standard'  => $osm,

            /* Jawg themes — require key only */
            'jawg-sunny'    => $key !== '' ? 'https://tile.jawg.io/jawg-sunny/{z}/{x}/{y}.png?access-token=' . $key : '',
            'jawg-light'    => $key !== '' ? 'https://tile.jawg.io/jawg-light/{z}/{x}/{y}.png?access-token=' . $key : '',
            'jawg-streets'  => $key !== '' ? 'https://tile.jawg.io/jawg-streets/{z}/{x}/{y}.png?access-token=' . $key : '',
        ];

        $tile_url = $themes[$theme] ?? '';

        if ($tile_url === '') $tile_url = $themes['ofm-liberty'];

        $provider = strpos($tile_url, 'jawg') !== false ? 'jawg'
            : (strpos($tile_url, 'openfreemap') !== false ? 'ofm' : 'osm');

        return rest_ensure_response([
            'provider'     => $provider,
            'theme'        => $theme,
            'tile_url'     => $tile_url,
            'fallback_url' => $osm,
            'attribution'  => $provider === 'ofm'
                ? '© OpenStreetMap contributors © OpenFreeMap'
                : '© OpenStreetMap contributors',
            'max_zoom'     => 19,
        ]);
    }

    /* ========================================================================
       Find Post Coordinates (Multiple Meta Sources) — With Priority
       ======================================================================== */
    public static function get_post_coords(int $post_id): ?array {
        /* 1) Old combined meta: "lat,lng" */
        $raw = get_post_meta($post_id, '_location_coords', true);

        if ($raw && strpos((string) $raw, ',') !== false) {
            $parts = array_map('trim', explode(',', (string) $raw));

            if (count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                return [(float) $parts[0], (float) $parts[1]];
            }
        }

        /* 2) Priority: _geo_lat / _geo_lng (current architecture) */
        $geo_lat = get_post_meta($post_id, '_geo_lat', true);
        $geo_lng = get_post_meta($post_id, '_geo_lng', true);

        if (is_numeric($geo_lat) && is_numeric($geo_lng)) {
            return [(float) $geo_lat, (float) $geo_lng];
        }

        /* 3) Old metas with different prefixes */
        foreach (['_hotel_', '_destination_', '_restaurant_', '_airport_', '_hospital_', '_'] as $p) {
            $lat = get_post_meta($post_id, $p . 'lat', true);
            $lng = get_post_meta($post_id, $p . 'lng', true);

            if (is_numeric($lat) && is_numeric($lng)) return [(float) $lat, (float) $lng];
        }

        /* 4) Custom table nextsafar_geo */
        global $wpdb;

        $geo_table = $wpdb->prefix . 'nextsafar_geo';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$geo_table}'") === $geo_table) {
            $col = $wpdb->get_var("SHOW COLUMNS FROM {$geo_table} LIKE 'post_id'")
                ? 'post_id'
                : ($wpdb->get_var("SHOW COLUMNS FROM {$geo_table} LIKE 'post_ID'") ? 'post_ID' : null);

            if ($col) {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT lat, lng FROM {$geo_table} WHERE {$col} = %d LIMIT 1",
                    $post_id
                ));

                if ($row && is_numeric($row->lat) && is_numeric($row->lng)) {
                    return [(float) $row->lat, (float) $row->lng];
                }
            }
        }

        return null;
    }

    /* ========================================================================
       Nearby Places (Two modes: with lat/lng or with post_type+slug)
       ======================================================================== */
    public static function get_nearby($request) {
        $lat_raw = $request->get_param('lat');
        $lng_raw = $request->get_param('lng');

        /* ✅ FIX: Single-request mode — check before cast */
        if ($lat_raw === null || $lat_raw === '' || $lng_raw === null || $lng_raw === '') {
            $post_type = sanitize_key((string) $request->get_param('post_type'));
            $post_slug = sanitize_title((string) $request->get_param('slug'));

            if (!$post_type || !$post_slug) {
                return new \WP_Error('invalid', 'lat/lng یا post_type+slug لازم است', ['status' => 400]);
            }

            $post = \get_page_by_path($post_slug, OBJECT, [$post_type]);

            if (!$post) {
                return new \WP_Error('not_found', 'پست یافت نشد', ['status' => 404]);
            }

            $coords = self::get_post_coords((int) $post->ID);

            if (!$coords) {
                return rest_ensure_response([
                    'has_center' => false,
                    'center'     => null,
                    'radius_km'  => 0,
                    'places'     => [],
                ]);
            }

            $lat     = $coords[0];
            $lng     = $coords[1];
            $exclude = (int) $post->ID;
        } else {
            $lat     = (float) $lat_raw;
            $lng     = (float) $lng_raw;
            $exclude = (int) $request->get_param('exclude');
        }

        $radius = min(50, max(1, (int) ($request->get_param('radius') ?: 5)));
        $limit  = min(30, max(1, (int) ($request->get_param('limit') ?: 12)));

        $types  = array_filter(explode(',', (string) $request->get_param('types')));
        $types  = array_values(array_intersect($types ?: self::TYPES, self::TYPES));

        if (!$lat || !$lng) {
            return new \WP_Error('invalid', 'مختصات نامعتبر', ['status' => 400]);
        }

        /* Cache for 1 hour based on parameters */
        $cache_key = 'ns_map_nearby_' . md5("{$lat}|{$lng}|{$radius}|" . implode('-', $types) . "|{$exclude}");
        $cached = \get_transient($cache_key);

        if ($cached !== false) return rest_ensure_response($cached);

        $places = [];

        foreach ($types as $type) {
            $ids = \get_posts([
                'post_type'      => $type,
                'post_status'    => 'publish',
                'posts_per_page' => 200,
                'fields'         => 'ids',
                'meta_query'     => [
                    'relation' => 'OR',
                    ['key' => '_location_coords', 'compare' => 'EXISTS'],
                    ['key' => '_geo_lat',         'compare' => 'EXISTS'],
                    ['key' => '_hotel_lat',       'compare' => 'EXISTS'],
                    ['key' => '_lat',             'compare' => 'EXISTS'],
                ],
            ]);

            foreach ($ids as $pid) {
                if ((int) $pid === $exclude) continue;

                $coords = self::get_post_coords((int) $pid);

                if (!$coords) continue;

                $dist = self::haversine($lat, $lng, $coords[0], $coords[1]);

                if ($dist > $radius) continue;

                $places[] = self::format_place((int) $pid, $coords, $dist);
            }
        }

        usort($places, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
        $places = array_slice($places, 0, $limit);

        $out = [
            'has_center' => true,
            'center'     => ['lat' => $lat, 'lng' => $lng],
            'radius_km'  => $radius,
            'places'     => $places,
        ];

        \set_transient($cache_key, $out, HOUR_IN_SECONDS);

        return rest_ensure_response($out);
    }

    /* ========================================================================
       Coordinates of a Post by Slug
       ======================================================================== */
    public static function get_coords($request) {
        $slug = sanitize_title((string) $request->get_param('slug'));
        $type = sanitize_key((string) $request->get_param('post_type'));

        $post = \get_page_by_path($slug, OBJECT, [$type]);

        if (!$post) return new \WP_Error('not_found', 'یافت نشد', ['status' => 404]);

        $coords = self::get_post_coords($post->ID);

        if (!$coords) return rest_ensure_response(['has_coords' => false]);

        return rest_ensure_response([
            'has_coords' => true,
            'lat'        => $coords[0],
            'lng'        => $coords[1],
            'post_id'    => (int) $post->ID,
        ]);
    }

    /* ========================================================================
       Plan Entities (Legacy — From Slug)
       ======================================================================== */
    public static function get_entities($request) {
        $refs_raw = (string) $request->get_param('refs');
        $refs     = array_filter(explode(',', $refs_raw));
        $out      = [];

        foreach (array_slice($refs, 0, 40) as $ref) {
            [$type, $slug] = array_pad(explode(':', $ref, 2), 2, '');

            $type = sanitize_key($type);
            $slug = sanitize_title($slug);

            if (!$type || !$slug) continue;
            if (!in_array($type, ['hotel', 'restaurant', 'destination', 'tour'], true)) continue;

            $post = \get_page_by_path($slug, OBJECT, [$type]);

            if (!$post) continue;

            $coords = self::get_post_coords((int) $post->ID);

            $out[] = [
                'type'   => $type,
                'slug'   => $slug,
                'title'  => \get_the_title($post),
                'url'    => self::type_url($type) . '/' . $slug,
                'image'  => \get_the_post_thumbnail_url($post->ID, 'medium') ?: null,
                'stars'  => (int) (\get_post_meta($post->ID, '_hotel_stars', true) ?: 0),
                'rating' => (float) (
                    \get_post_meta($post->ID, '_hotel_rating', true) ?:
                    \get_post_meta($post->ID, '_destination_rating', true) ?: 0
                ),
                'lat'    => $coords ? $coords[0] : null,
                'lng'    => $coords ? $coords[1] : null,
            ];
        }

        return rest_ensure_response(['entities' => $out]);
    }

    /* ========================================================================
       Helpers
       ======================================================================== */
    private static function type_url(string $type): string {
        return [
            'hotel'        => '/hotels',
            'destination'  => '/destinations',
            'restaurant'   => '/restaurants',
            'airport'      => '/airports',
            'hospital'     => '/hospitals',
            'travelguide'  => '/travelguide',
        ][$type] ?? '';
    }

    private static function format_place(int $pid, array $coords, float $dist): array {
        $post = \get_post($pid);

        if (!$post) return [];

        /* ✅ location_type for specialized icons (destination and restaurant) */
        $location_type = '';

        if ($post->post_type === 'destination') {
            $location_type = (string) (\get_post_meta($pid, '_place_type', true) ?: '');
        } elseif ($post->post_type === 'restaurant') {
            $location_type = (string) (\get_post_meta($pid, '_restaurant_type', true) ?: '');
        }

        return [
            'id'            => $pid,
            'type'          => $post->post_type,
            'title'         => $post->post_title,
            'slug'          => $post->post_name,
            'url'           => self::type_url($post->post_type) . '/' . $post->post_name,
            'lat'           => $coords[0],
            'lng'           => $coords[1],
            'image'         => \get_the_post_thumbnail_url($pid, 'medium') ?: null,
            'stars'         => (int) (\get_post_meta($pid, '_hotel_stars', true) ?: 0),
            'rating'        => (float) (
                \get_post_meta($pid, '_hotel_rating', true) ?:
                \get_post_meta($pid, '_destination_rating', true) ?: 0
            ),
            'location_type' => $location_type,   /* ✅ New */
            'distance_km'   => round($dist, 1),
            'walking_min'   => (int) round(($dist / 5) * 60),
            'driving_min'   => (int) round(($dist / 30) * 60),
        ];
    }

    private static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $earth = 6371;

        $dLat  = deg2rad($lat2 - $lat1);
        $dLng  = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}