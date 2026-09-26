<?php

namespace NextSafar\Hotels;

use NextSafar\Sync\GeoSchema;
use NextSafar\Database\GeoTable;

/* ========================================================================
   Match Site Hotels with Provider Hotels (Step-by-Step + Persistent)
   ======================================================================== */

/* ========================================================================
   Mapping Table (Self-Healing)
   ======================================================================== */
function ns_hotel_matches_ensure(): void {
    global $wpdb;

    static $done = false;

    if ($done) return;

    $done = true;

    $charset = $wpdb->get_charset_collate();

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ns_hotel_matches (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        site_post_id BIGINT UNSIGNED NULL,
        provider VARCHAR(20) NOT NULL,
        provider_property_id VARCHAR(190) NOT NULL,
        provider_name VARCHAR(255) NULL,
        lat DECIMAL(10,7) NULL,
        lng DECIMAL(10,7) NULL,
        confidence DECIMAL(3,2) NOT NULL DEFAULT 0,
        match_method VARCHAR(20) NOT NULL DEFAULT '',
        last_seen_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_provider_prop (provider, provider_property_id),
        KEY idx_site (site_post_id)
    ) {$charset};");
}

add_action('rest_api_init', __NAMESPACE__ . '\ns_hotel_matches_ensure', 1);

/* ========================================================================
   Site Hotels for a City (Multi-Source - Final Version)
   ======================================================================== */
function ns_hotel_site_list(string $city): array {
    global $wpdb;

    $city_slug  = sanitize_title($city);
    $city_lower = mb_strtolower($city, 'UTF-8');

    error_log("[NS Hotels] Searching site hotels for: '$city' (slug: $city_slug)");

    $post_ids = [];

    /* Step 1: Search from GeoTable (custom table) */
    $geo_table    = GeoTable::get_table_name();
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$geo_table}'") === $geo_table;

    if ($table_exists) {
        $geo_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$geo_table}
             WHERE post_type = 'hotel'
             AND (city LIKE %s OR city LIKE %s OR city LIKE %s)",
            '%' . $wpdb->esc_like($city) . '%',
            '%' . $wpdb->esc_like($city_lower) . '%',
            '%' . $wpdb->esc_like($city_slug) . '%'
        ));

        if (!empty($geo_ids)) {
            $valid_geo_ids = get_posts([
                'post_type'      => 'hotel',
                'post_status'    => 'publish',
                'post__in'       => $geo_ids,
                'posts_per_page' => 300,
                'fields'         => 'ids',
            ]);

            $post_ids = array_merge($post_ids, $valid_geo_ids);

            error_log("[NS Hotels] From GeoTable: " . count($valid_geo_ids) . " hotels");
        }
    }

    /* Step 2: Search from postmeta _geo_city (new GeoSchema) */
    $geo_meta_posts = get_posts([
        'post_type'      => 'hotel',
        'post_status'    => 'publish',
        'posts_per_page' => 300,
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'OR',
            ['key' => '_geo_city', 'value' => $city, 'compare' => 'LIKE'],
            ['key' => '_geo_city', 'value' => $city_lower, 'compare' => 'LIKE'],
            ['key' => '_geo_address', 'value' => $city, 'compare' => 'LIKE'],
        ],
    ]);

    $post_ids = array_merge($post_ids, $geo_meta_posts);

    error_log("[NS Hotels] From _geo_* meta: " . count($geo_meta_posts) . " hotels");

    /* Step 3: Search from old postmeta (Legacy) */
    $legacy_posts = get_posts([
        'post_type'      => 'hotel',
        'post_status'    => 'publish',
        'posts_per_page' => 300,
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'OR',
            ['key' => '_hotel_city', 'value' => $city, 'compare' => 'LIKE'],
            ['key' => '_hotel_address_se', 'value' => $city, 'compare' => 'LIKE'],
            ['key' => '_hotel_address', 'value' => $city, 'compare' => 'LIKE'],
        ],
    ]);

    $post_ids = array_merge($post_ids, $legacy_posts);

    error_log("[NS Hotels] From legacy meta: " . count($legacy_posts) . " hotels");

    /* Step 4: Search from taxonomy hotel_category */
    $term = get_term_by('slug', $city_slug, 'hotel_category');

    if (!$term) {
        $term = get_term_by('name', $city, 'hotel_category');
    }

    if (!$term) {
        /* Search in all terms */
        $terms = get_terms([
            'taxonomy'   => 'hotel_category',
            'hide_empty' => false,
            'name__like' => $city,
        ]);

        if (!empty($terms)) {
            $term = $terms[0];
        }
    }

    if ($term) {
        $term_posts = get_posts([
            'post_type'      => 'hotel',
            'post_status'    => 'publish',
            'posts_per_page' => 300,
            'fields'         => 'ids',
            'tax_query'      => [
                ['taxonomy' => 'hotel_category', 'terms' => $term->term_id],
            ],
        ]);

        $post_ids = array_merge($post_ids, $term_posts);

        error_log("[NS Hotels] From taxonomy hotel_category: " . count($term_posts) . " hotels");
    }

    /* Step 5: Fallback - post title contains city name */
    $title_posts = get_posts([
        'post_type'      => 'hotel',
        'post_status'    => 'publish',
        'posts_per_page' => 100,
        'fields'         => 'ids',
        's'              => $city,
    ]);

    $post_ids = array_merge($post_ids, $title_posts);

    /* Remove duplicates */
    $post_ids = array_unique($post_ids);

    error_log("[NS Hotels] Total unique site hotels: " . count($post_ids));

    if (empty($post_ids)) {
        return [];
    }

    /* Step 6: Build hotels array */
    $out = [];

    foreach ($post_ids as $pid) {
        $post = get_post($pid);

        if (!$post) continue;

        /* Amenities */
        $am        = get_post_meta($pid, '_hotel_amenities', true);
        $amenities = is_array($am)
            ? $am
            : (is_string($am) && $am !== ''
                ? (json_decode($am, true) ?: array_filter(array_map('trim', explode(',', $am))))
                : []);

        /* If amenities is empty, read from separate fields */
        if (empty($amenities)) {
            $amenity_keys = [
                'hotel_wifi', 'hotel_parking', 'hotel_pool', 'hotel_spa',
                'hotel_gym', 'hotel_restaurant', 'hotel_bar', 'hotel_breakfast',
                'hotel_airport_shuttle', 'hotel_pet_friendly', 'hotel_air_conditioning'
            ];

            foreach ($amenity_keys as $key) {
                if (get_post_meta($pid, '_' . $key, true) === 'yes') {
                    $amenities[] = str_replace('hotel_', '', $key);
                }
            }
        }

        /* Coordinates - Priority: GeoSchema → GeoTable → Legacy */
        [$lat, $lng] = GeoSchema::get_latlng($pid);

        if (($lat === '' || $lat === '0') && $table_exists) {
            $geo_row = GeoTable::get_by_post_id($pid);

            if ($geo_row) {
                $lat = $lat ?: $geo_row['lat'];
                $lng = $lng ?: $geo_row['lng'];
            }
        }

        /* Stars */
        $stars = (int) GeoSchema::get($pid, 'stars');

        if (!$stars) {
            $stars = (int) get_post_meta($pid, '_hotel_stars', true);
        }

        /* Rating */
        $rating = (float) GeoSchema::get($pid, 'rating');

        if (!$rating) {
            $rating = (float) get_post_meta($pid, '_hotel_rating', true);
        }

        /* External ID */
        $external_id = GeoSchema::get($pid, 'external_id');

        if (!$external_id) {
            $external_id = get_post_meta($pid, '_hotel_external_id', true);
        }

        /* English name */
        $name_en = GeoSchema::get($pid, 'name_en');

        if (!$name_en) {
            $name_en = get_post_meta($pid, '_hotel_name_en', true);
        }

        /* Address */
        $address = GeoSchema::get($pid, 'address');

        if (!$address) {
            $address = get_post_meta($pid, '_hotel_address', true);

            if (!$address) {
                $address = get_post_meta($pid, '_hotel_address_se', true);
            }
        }

        /* Featured */
        $featured = (bool) get_post_meta($pid, '_hotel_featured', true);

        $out[] = [
            'id'          => $pid,
            'slug'        => $post->post_name,
            'title'       => get_the_title($pid),
            'title_en'    => $name_en ?: '',
            'stars'       => $stars,
            'rating'      => $rating,
            'address'     => $address ?: '',
            'image'       => get_the_post_thumbnail_url($pid, 'medium') ?: null,
            'amenities'   => array_values((array) $amenities),
            'featured'    => $featured,
            'external_id' => (string) $external_id,
            'lat'         => $lat !== '' && $lat !== '0' ? (float) $lat : null,
            'lng'         => $lng !== '' && $lng !== '0' ? (float) $lng : null,
            'url'         => '/hotels/' . $post->post_name,
        ];
    }

    error_log("[NS Hotels] Built " . count($out) . " hotel objects");

    return $out;
}

/* ========================================================================
   Normalize Name for Comparison
   ======================================================================== */
function ns_hotel_name_norm(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? '';

    $stop = [
        'hotel', 'htl', 'grand', 'the', 'of', 'and', 'intl', 'international',
        'resort', 'suites', 'suite', 'apartments', 'apartment', 'boutique',
        'inn', 'motel', 'lodge', 'hostel', 'palace', 'tower', 'plaza',
        'هتل', 'بین‌المللی', 'بین المللی', 'بزرگ', 'مهمانسرا', 'آپارتمان',
        'سوئیت', 'اقامتگاه', 'مسافرخانه', 'پانسیون',
    ];

    $tokens = array_filter(explode(' ', $s), fn($t) => $t !== '' && !in_array($t, $stop, true));

    return implode(' ', $tokens);
}

/* ========================================================================
   Name Similarity (Jaccard + Substring)
   ======================================================================== */
function ns_hotel_name_sim(string $a, string $b): float {
    if ($a === '' || $b === '') return 0.0;
    if ($a === $b) return 1.0;

    $ta = explode(' ', $a);
    $tb = explode(' ', $b);

    $inter = count(array_intersect($ta, $tb));
    $union = count(array_unique(array_merge($ta, $tb)));

    $j = $union > 0 ? $inter / $union : 0.0;

    if (str_contains($a, $b) || str_contains($b, $a)) $j = max($j, 0.7);

    return $j;
}

/* ========================================================================
   Haversine Distance (Meters)
   ======================================================================== */
function ns_hotel_dist_m(?float $a1, ?float $o1, ?float $a2, ?float $o2): ?float {
    if ($a1 === null || $o1 === null || $a2 === null || $o2 === null) return null;

    $r    = 6371000.0;
    $dLat = deg2rad($a2 - $a1);
    $dLng = deg2rad($o2 - $o1);

    $h = sin($dLat / 2) ** 2 + cos(deg2rad($a1)) * cos(deg2rad($a2)) * sin($dLng / 2) ** 2;

    return 2 * $r * asin(sqrt($h));
}

/* ========================================================================
   Match and Merge
   ======================================================================== */
function ns_hotels_merge(array $site, array $online, string $provider): array {
    global $wpdb;

    ns_hotel_matches_ensure();

    /* 1) Build candidates (stricter version) */
    $pairs = [];

    foreach ($online as $oi => $o) {
        /* T1: External ID (best match) */
        foreach ($site as $si => $s) {
            if ($s['external_id'] !== '' && $s['external_id'] === ($o['google_property_token'] ?? $o['property_id'] ?? '')) {
                $pairs[] = [1.0, $si, $oi, 'external_id'];
                continue 2;
            }
        }

        $norm_o = ns_hotel_name_norm($o['name']);

        foreach ($site as $si => $s) {
            $dist = ns_hotel_dist_m($s['lat'], $s['lng'], $o['lat'], $o['lng']);

            /* Match with Persian and English names */
            $sim_fa = ns_hotel_name_sim(ns_hotel_name_norm($s['title']), $norm_o);
            $sim_en = !empty($s['title_en'])
                ? ns_hotel_name_sim(ns_hotel_name_norm($s['title_en']), $norm_o)
                : 0.0;

            $sim = max($sim_fa, $sim_en);

            /* Stricter rules:
               - If name similarity is very high (>=0.7): match even without location
               - If both location is close and name matches: strong match
               - If only location is close and name has low similarity: reject! */
            if ($sim >= 0.80) {
                /* Strong match based only on name */
                $pairs[] = [0.90, $si, $oi, 'name_strong'];
            } elseif ($dist !== null && $dist <= 100 && $sim >= 0.55) {
                /* Close + acceptable name similarity */
                $pairs[] = [0.80, $si, $oi, 'geo+name'];
            } elseif ($dist !== null && $dist <= 50 && $sim >= 0.40) {
                /* Very close + minimum name similarity */
                $pairs[] = [0.70, $si, $oi, 'geo_close+name'];
            } elseif ($sim >= 0.70) {
                /* High name similarity */
                $pairs[] = [0.65, $si, $oi, 'name_good'];
            }
            /* Removed: match based only on location (causes incorrect matches)
               Previously was: elseif ($dist !== null && $dist <= 150) → removed */
        }
    }

    /* 2) Greedy assignment */
    usort($pairs, fn($x, $y) => $y[0] <=> $x[0]);

    $used_site       = [];
    $match_of_online = [];

    foreach ($pairs as [$conf, $si, $oi, $method]) {
        if (isset($used_site[$si]) || isset($match_of_online[$oi])) continue;

        $used_site[$si]       = true;
        $match_of_online[$oi] = [$si, $conf, $method];
    }

    /* 3) Upsert mapping table */
    foreach ($online as $oi => $o) {
        $m       = $match_of_online[$oi] ?? null;
        $site_id = $m ? $site[$m[0]]['id'] : null;
        $conf    = $m ? $m[1] : 0;
        $method  = $m ? $m[2] : '';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ns_hotel_matches WHERE provider=%s AND provider_property_id=%s LIMIT 1",
            $provider,
            $o['property_id']
        ));

        $row = [
            'site_post_id'  => $site_id,
            'provider_name' => mb_substr($o['name'], 0, 250),
            'lat'           => $o['lat'],
            'lng'           => $o['lng'],
            'confidence'    => $conf,
            'match_method'  => $method,
            'last_seen_at'  => current_time('mysql'),
        ];

        if ($exists) {
            $wpdb->update("{$wpdb->prefix}ns_hotel_matches", $row, ['id' => (int) $exists]);
        } else {
            $wpdb->insert("{$wpdb->prefix}ns_hotel_matches", array_merge($row, [
                'provider'             => $provider,
                'provider_property_id' => mb_substr($o['property_id'], 0, 190),
            ]));
        }
    }

    /* 4) Build unified items */
    $site_items = [];

    foreach ($site as $si => $s) {
        $online_ref = null;
        $conf       = null;

        foreach ($match_of_online as $oi => $m) {
            if ($m[0] === $si) {
                $online_ref = $online[$oi];
                $conf       = $m[1];
                break;
            }
        }

        $site_items[] = [
            'source'           => 'site',
            'match_confidence' => $conf,
            'site'             => [
                'id'        => $s['id'],
                'slug'      => $s['slug'],
                'title'     => $s['title'],
                'title_en'  => $s['title_en'] ?? '',
                'stars'     => $s['stars'],
                'rating'    => $s['rating'],
                'address'   => $s['address'],
                'image'     => $s['image'],
                'amenities' => $s['amenities'],
                'featured'  => $s['featured'],
                'url'       => $s['url'],
                'lat'       => $s['lat'],
                'lng'       => $s['lng'],
            ],
            'online' => $online_ref ? [
                'property_id' => $online_ref['property_id'],
                'name'        => $online_ref['name'],
                'image'       => $online_ref['image'],
                'rating'      => $online_ref['rating'],
                'reviews'     => $online_ref['reviews'],
                'address'     => $online_ref['address'],
                'lat'         => $online_ref['lat'] ?? null,
                'lng'         => $online_ref['lng'] ?? null,
                'stars'       => $online_ref['stars'] ?? 0,
                'amenities'   => $online_ref['amenities'] ?? [],
                'link'        => $online_ref['link'] ?? '',
                'token'       => $online_ref['google_property_token'] ?? '',
            ] : null,
            'price' => $online_ref && $online_ref['per_night_toman'] > 0 ? [
                'per_night_toman' => $online_ref['per_night_toman'],
                'usd'             => $online_ref['price_usd'],
            ] : null,
            'badges' => array_merge($s['featured'] ? ['featured'] : [], ['site']),
        ];
    }

    /* Sort site group: featured → stars → rating */
    usort($site_items, fn($a, $b) =>
        [($b['site']['featured'] ? 1 : 0), $b['site']['stars'], $b['site']['rating']]
        <=>
        [($a['site']['featured'] ? 1 : 0), $a['site']['stars'], $a['site']['rating']]
    );

    /* Online group: only without match */
    $online_items = [];

    foreach ($online as $oi => $o) {
        if (isset($match_of_online[$oi])) continue;

        $online_items[] = [
            'source'           => 'online',
            'match_confidence' => null,
            'site'             => null,
            'online'           => [
                'property_id' => $o['property_id'],
                'name'        => $o['name'],
                'image'       => $o['image'],
                'rating'      => $o['rating'],
                'reviews'     => $o['reviews'],
                'address'     => $o['address'],
                'lat'         => $o['lat'] ?? null,
                'lng'         => $o['lng'] ?? null,
                'stars'       => $o['stars'] ?? 0,
                'amenities'   => $o['amenities'] ?? [],
                'link'        => $o['link'] ?? '',
                'token'       => $o['google_property_token'] ?? '',
            ],
            'price' => $o['per_night_toman'] > 0
                ? ['per_night_toman' => $o['per_night_toman'], 'usd' => $o['price_usd']]
                : null,
            'badges' => ['online'],
        ];
    }

    usort($online_items, fn($a, $b) => $b['online']['rating'] <=> $a['online']['rating']);

    return [
        'items'        => array_merge($site_items, $online_items),
        'matched'      => count($match_of_online),
        'site_count'   => count($site),
        'online_count' => count($online),
    ];
}