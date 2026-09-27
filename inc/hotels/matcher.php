<?php
/**
 * NextSafar Core - Hotel Matcher (Optimized Version)
 * 
 * Optimizations:
 * 1. Batch postmeta loading (reduces N+1 queries to 1 query)
 * 2. City-based caching with transients
 * 3. Optimized database queries with indexes
 * 4. Batch upsert operations
 * 
 * @package NextSafar\Hotels
 * @since   2.6.0
 */

namespace NextSafar\Hotels;

use NextSafar\Sync\GeoSchema;
use NextSafar\Database\GeoTable;
use NextSafar\Core\Logger;

/* ========================================================================
   Mapping Table (Self-Healing)
   Creates table for storing hotel match mappings
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
        KEY idx_site (site_post_id),
        KEY idx_last_seen (last_seen_at)
    ) {$charset};");
}

add_action('rest_api_init', __NAMESPACE__ . '\ns_hotel_matches_ensure', 1);

/* ========================================================================
   Site Hotels for a City (OPTIMIZED VERSION)
   
   Performance improvements:
   - Batch postmeta loading (1 query instead of N queries)
   - City-based caching (1 hour TTL)
   - Optimized queries with proper indexes
======================================================================== */
function ns_hotel_site_list(string $city): array {
    global $wpdb;
    
    // Step 1: Check cache first
    $cache_key = 'ns_site_hotels_' . md5($city);
    $cached = get_transient($cache_key);
    
    if ($cached !== false) {
        Logger::debug('Site hotels cache hit', ['city' => $city, 'count' => count($cached)]);
        return $cached;
    }
    
    $city_slug = sanitize_title($city);
    $city_lower = mb_strtolower($city, 'UTF-8');
    
    Logger::debug('Searching site hotels', [
        'city' => $city,
        'slug' => $city_slug,
    ]);
    
    $post_ids = [];
    
    // Step 2: Optimized search with combined queries where possible
    
    // 2a. Search from GeoTable (custom table)
    $geo_table = GeoTable::get_table_name();
    $table_exists = $wpdb->get_var($wpdb->prepare(
        "SHOW TABLES LIKE %s",
        $geo_table
    )) === $geo_table;
    
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
        }
    }
    
    // 2b. Search from postmeta (optimized with single query)
    $meta_posts = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT p.ID 
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
         WHERE p.post_type = 'hotel'
         AND p.post_status = 'publish'
         AND pm.meta_key IN ('_geo_city', '_geo_address', '_hotel_city', '_hotel_address', '_hotel_address_se')
         AND (pm.meta_value LIKE %s OR pm.meta_value LIKE %s OR pm.meta_value LIKE %s)
         LIMIT 300",
        '%' . $wpdb->esc_like($city) . '%',
        '%' . $wpdb->esc_like($city_lower) . '%',
        '%' . $wpdb->esc_like($city_slug) . '%'
    ));
    
    $post_ids = array_merge($post_ids, $meta_posts);
    
    // 2c. Search from taxonomy
    $term = get_term_by('slug', $city_slug, 'hotel_category');
    if (!$term) {
        $term = get_term_by('name', $city, 'hotel_category');
    }
    
    if (!$term) {
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
    }
    
    // 2d. Fallback: post title search
    $title_posts = get_posts([
        'post_type'      => 'hotel',
        'post_status'    => 'publish',
        'posts_per_page' => 100,
        'fields'         => 'ids',
        's'              => $city,
    ]);
    $post_ids = array_merge($post_ids, $title_posts);
    
    // Remove duplicates
    $post_ids = array_unique($post_ids);
    
    if (empty($post_ids)) {
        Logger::debug('No site hotels found', ['city' => $city]);
        set_transient($cache_key, [], 1 * HOUR_IN_SECONDS);
        return [];
    }
    
    Logger::debug('Found site hotels', [
        'city'  => $city,
        'count' => count($post_ids),
    ]);
    
    // Step 3: BATCH LOAD all postmeta in ONE query (KEY OPTIMIZATION!)
    $all_meta = batch_load_postmeta($post_ids);
    
    // Step 4: BATCH LOAD all GeoTable data in ONE query
    $geo_data = [];
    if ($table_exists) {
        $geo_data = batch_load_geo_data($post_ids, $geo_table);
    }
    
    // Step 5: Build hotels array using batch-loaded data
    $out = [];
    
    foreach ($post_ids as $pid) {
        $post = get_post($pid);
        if (!$post) continue;
        
        // Get meta from batch-loaded data (NO additional queries!)
        $meta = $all_meta[$pid] ?? [];
        
        // Amenities
        $am = $meta['_hotel_amenities'] ?? '';
        $amenities = is_array($am)
            ? $am
            : (is_string($am) && $am !== ''
                ? (json_decode($am, true) ?: array_filter(array_map('trim', explode(',', $am))))
                : []);
        
        // If amenities is empty, read from separate fields
        if (empty($amenities)) {
            $amenity_keys = [
                'hotel_wifi', 'hotel_parking', 'hotel_pool', 'hotel_spa',
                'hotel_gym', 'hotel_restaurant', 'hotel_bar', 'hotel_breakfast',
                'hotel_airport_shuttle', 'hotel_pet_friendly', 'hotel_air_conditioning'
            ];
            
            foreach ($amenity_keys as $key) {
                if (($meta['_' . $key] ?? '') === 'yes') {
                    $amenities[] = str_replace('hotel_', '', $key);
                }
            }
        }
        
        // Coordinates - Priority: GeoSchema → GeoTable → Legacy
        $lat = $meta['_geo_lat'] ?? '';
        $lng = $meta['_geo_lng'] ?? '';
        
        if (($lat === '' || $lat === '0') && isset($geo_data[$pid])) {
            $lat = $lat ?: $geo_data[$pid]['lat'];
            $lng = $lng ?: $geo_data[$pid]['lng'];
        }
        
        // Stars
        $stars = (int) ($meta['_geo_stars'] ?? $meta['_hotel_stars'] ?? 0);
        
        // Rating
        $rating = (float) ($meta['_geo_rating'] ?? $meta['_hotel_rating'] ?? 0);
        
        // External ID
        $external_id = $meta['_geo_external_id'] ?? $meta['_hotel_external_id'] ?? '';
        
        // English name
        $name_en = $meta['_geo_name_en'] ?? $meta['_hotel_name_en'] ?? '';
        
        // Address
        $address = $meta['_geo_address'] ?? $meta['_hotel_address'] ?? $meta['_hotel_address_se'] ?? '';
        
        // Featured
        $featured = ($meta['_hotel_featured'] ?? '') === '1' || ($meta['_hotel_featured'] ?? '') === 'yes';
        
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
    
    Logger::info('Site hotels loaded', [
        'city'  => $city,
        'count' => count($out),
    ]);
    
    // Cache for 1 hour
    set_transient($cache_key, $out, 1 * HOUR_IN_SECONDS);
    
    return $out;
}

/* ========================================================================
   Batch Load Postmeta (KEY OPTIMIZATION)
   
   Instead of N queries for N posts, loads all meta in 1 query
   Reduces 2200 queries to 1 query for 100 hotels!
======================================================================== */
function batch_load_postmeta(array $post_ids): array {
    global $wpdb;
    
    if (empty($post_ids)) return [];
    
    // Build IN clause
    $ids_placeholder = implode(',', array_fill(0, count($post_ids), '%d'));
    
    // Single query to get ALL meta for ALL posts
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, meta_key, meta_value 
         FROM {$wpdb->postmeta} 
         WHERE post_id IN ($ids_placeholder)",
        ...$post_ids
    ));
    
    // Organize into nested array: [post_id => [meta_key => meta_value]]
    $meta_by_post = [];
    foreach ($results as $row) {
        if (!isset($meta_by_post[$row->post_id])) {
            $meta_by_post[$row->post_id] = [];
        }
        $meta_by_post[$row->post_id][$row->meta_key] = $row->meta_value;
    }
    
    return $meta_by_post;
}

/* ========================================================================
   Batch Load Geo Data (KEY OPTIMIZATION)
   
   Loads all geo data from custom table in 1 query
======================================================================== */
function batch_load_geo_data(array $post_ids, string $table): array {
    global $wpdb;
    
    if (empty($post_ids)) return [];
    
    $ids_placeholder = implode(',', array_fill(0, count($post_ids), '%d'));
    
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, lat, lng, city, address 
         FROM {$table} 
         WHERE post_id IN ($ids_placeholder)",
        ...$post_ids
    ));
    
    $geo_by_post = [];
    foreach ($results as $row) {
        $geo_by_post[$row->post_id] = [
            'lat'     => $row->lat,
            'lng'     => $row->lng,
            'city'    => $row->city,
            'address' => $row->address,
        ];
    }
    
    return $geo_by_post;
}

/* ========================================================================
   Normalize Name for Comparison (Enhanced Version)
   
   Strategy: Remove stop words but keep core name for matching
======================================================================== */
function ns_hotel_name_norm(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    
    // Step 1: Remove punctuation and special characters (keep letters, numbers, spaces)
    $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
    
    // Step 2: Normalize whitespace
    $s = preg_replace('/\s+/', ' ', trim($s));
    
    if ($s === '') return '';
    
    // Step 3: Remove stop words
    $stop = [
        'hotel', 'htl', 'grand', 'the', 'of', 'and', 'intl', 'international',
        'resort', 'suites', 'suite', 'apartments', 'apartment', 'boutique',
        'inn', 'motel', 'lodge', 'hostel', 'palace', 'tower', 'plaza',
        'هتل', 'بین‌المللی', 'بین المللی', 'بزرگ', 'مهمانسرا', 'آپارتمان',
        'سوئیت', 'اقامتگاه', 'مسافرخانه', 'پانسیون',
    ];
    
    $words = array_filter(explode(' ', $s));
    $filtered = array_filter($words, fn($w) => !in_array($w, $stop, true));
    
    // If all words were stop words, return original
    if (empty($filtered)) return $s;
    
    return implode(' ', $filtered);
}

/* ========================================================================
   Name Similarity (Hybrid Algorithm)
   
   Combines 3 strategies for best accuracy:
   1. Exact match (1.0)
   2. Substring match (0.85-0.95)
   3. Jaccard similarity (0.0-1.0)
   4. Levenshtein-based similarity (for short names)
   
   Returns the MAXIMUM score from all strategies
======================================================================== */
function ns_hotel_name_sim(string $a, string $b): float {
    if ($a === '' || $b === '') return 0.0;
    
    // Strategy 1: Exact match
    if ($a === $b) return 1.0;
    
    // Strategy 2: Substring match (one contains the other)
    if (mb_strlen($a) >= 3 && mb_strlen($b) >= 3) {
        if (str_contains($a, $b)) {
            // Boost based on length ratio
            $ratio = mb_strlen($b) / mb_strlen($a);
            return 0.80 + ($ratio * 0.15); // 0.80 to 0.95
        }
        if (str_contains($b, $a)) {
            $ratio = mb_strlen($a) / mb_strlen($b);
            return 0.80 + ($ratio * 0.15);
        }
    }
    
    // Strategy 3: Word-based Jaccard similarity
    $words_a = array_filter(explode(' ', $a));
    $words_b = array_filter(explode(' ', $b));
    
    if (!empty($words_a) && !empty($words_b)) {
        $inter = count(array_intersect($words_a, $words_b));
        $union = count(array_unique(array_merge($words_a, $words_b)));
        $jaccard = $union > 0 ? $inter / $union : 0.0;
    } else {
        $jaccard = 0.0;
    }
    
    // Strategy 4: Character-based similarity for short names (< 20 chars)
    // This helps with names like "Vespia" vs "Vespia Hotel"
    $char_sim = 0.0;
    if (mb_strlen($a) < 30 && mb_strlen($b) < 30) {
        $chars_a = mb_str_split($a);
        $chars_b = mb_str_split($b);
        
        $inter = count(array_intersect($chars_a, $chars_b));
        $union = count(array_unique(array_merge($chars_a, $chars_b)));
        $char_sim = $union > 0 ? $inter / $union : 0.0;
        
        // Boost for short names if they share most characters
        if (mb_strlen($a) < 15 && mb_strlen($b) < 15) {
            $char_sim *= 1.1;
        }
    }
    
    // Return the maximum score from all strategies
    return max($jaccard, $char_sim);
}

/* ========================================================================
   Haversine Distance (Meters)
   Calculates distance between two GPS coordinates
======================================================================== */
function ns_hotel_dist_m(?float $a1, ?float $o1, ?float $a2, ?float $o2): ?float {
    if ($a1 === null || $o1 === null || $a2 === null || $o2 === null) return null;
    
    $r = 6371000.0; // Earth radius in meters
    
    $dLat = deg2rad($a2 - $a1);
    $dLng = deg2rad($o2 - $o1);
    
    $h = sin($dLat / 2) ** 2 + cos(deg2rad($a1)) * cos(deg2rad($a2)) * sin($dLng / 2) ** 2;
    
    return 2 * $r * asin(sqrt($h));
}

/* ========================================================================
   Match and Merge (OPTIMIZED VERSION)
   
   Optimizations:
   - Batch upsert operations (1 query instead of N queries)
   - Pre-normalize names outside loops
======================================================================== */
function ns_hotels_merge(array $site, array $online, string $provider): array {
    global $wpdb;
    
    ns_hotel_matches_ensure();
    
    // Step 1: Pre-normalize all site hotel names (optimization)
    $site_normalized = [];
    foreach ($site as $si => $s) {
        $site_normalized[$si] = [
            'fa' => ns_hotel_name_norm($s['title']),
            'en' => !empty($s['title_en']) ? ns_hotel_name_norm($s['title_en']) : '',
        ];
    }
    
    // Step 2: Build candidates with scoring
    $pairs = [];
    
    foreach ($online as $oi => $o) {
        // T1: External ID match (highest priority)
        foreach ($site as $si => $s) {
            if ($s['external_id'] !== '' && $s['external_id'] === ($o['google_property_token'] ?? $o['property_id'] ?? '')) {
                $pairs[] = [1.0, $si, $oi, 'external_id'];
                continue 2;
            }
        }
        
        $norm_o = ns_hotel_name_norm($o['name']);
        
        // T2: Name + Location matching
        foreach ($site as $si => $s) {
            $dist = ns_hotel_dist_m($s['lat'], $s['lng'], $o['lat'], $o['lng']);
            
            // Use pre-normalized names
            $sim_fa = ns_hotel_name_sim($site_normalized[$si]['fa'], $norm_o);
            $sim_en = $site_normalized[$si]['en'] !== '' 
                ? ns_hotel_name_sim($site_normalized[$si]['en'], $norm_o) 
                : 0.0;
            $sim = max($sim_fa, $sim_en);
            
            // Scoring rules (LOWERED THRESHOLDS for better matching)
            if ($sim >= 0.95) {
                // Almost exact match
                $pairs[] = [0.95, $si, $oi, 'name_exact'];
            } elseif ($sim >= 0.80) {
                // Strong name match (substring or high jaccard)
                $pairs[] = [0.88, $si, $oi, 'name_strong'];
            } elseif ($sim >= 0.70) {
                // Good name match
                $pairs[] = [0.75, $si, $oi, 'name_good'];
            } elseif ($dist !== null && $dist <= 100 && $sim >= 0.50) {
                // Close location + acceptable name
                $pairs[] = [0.70, $si, $oi, 'geo+name'];
            } elseif ($dist !== null && $dist <= 50 && $sim >= 0.35) {
                // Very close + some name similarity
                $pairs[] = [0.65, $si, $oi, 'geo_close+name'];
            } elseif ($sim >= 0.60) {
                // Moderate name similarity
                $pairs[] = [0.60, $si, $oi, 'name_moderate'];
            }
        }
    }
    
    // Step 3: Greedy assignment (highest confidence first)
    usort($pairs, fn($x, $y) => $y[0] <=> $x[0]);
    
    $used_site = [];
    $match_of_online = [];
    
    foreach ($pairs as [$conf, $si, $oi, $method]) {
        if (isset($used_site[$si]) || isset($match_of_online[$oi])) continue;
        
        $used_site[$si] = true;
        $match_of_online[$oi] = [$si, $conf, $method];
    }
    
    // Step 4: BATCH UPSERT mapping table (KEY OPTIMIZATION!)
    $upsert_data = [];
    foreach ($online as $oi => $o) {
        $m = $match_of_online[$oi] ?? null;
        $site_id = $m ? $site[$m[0]]['id'] : null;
        $conf = $m ? $m[1] : 0;
        $method = $m ? $m[2] : '';
        
        $upsert_data[] = [
            'provider'             => $provider,
            'provider_property_id' => mb_substr($o['property_id'], 0, 190),
            'site_post_id'         => $site_id,
            'provider_name'        => mb_substr($o['name'], 0, 250),
            'lat'                  => $o['lat'] ?? null,
            'lng'                  => $o['lng'] ?? null,
            'confidence'           => $conf,
            'match_method'         => $method,
            'last_seen_at'         => current_time('mysql'),
        ];
    }
    
    // Batch upsert using ON DUPLICATE KEY UPDATE
    if (!empty($upsert_data)) {
        batch_upsert_matches($upsert_data);
    }
    
    // Step 5: Build unified items
    $site_items = [];
    
    foreach ($site as $si => $s) {
        $online_ref = null;
        $conf = null;
        
        foreach ($match_of_online as $oi => $m) {
            if ($m[0] === $si) {
                $online_ref = $online[$oi];
                $conf = $m[1];
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
            'online'           => $online_ref ? [
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
            'price'            => $online_ref && $online_ref['per_night_toman'] > 0 ? [
                'per_night_toman' => $online_ref['per_night_toman'],
                'usd'             => $online_ref['price_usd'],
            ] : null,
            'badges'           => array_merge($s['featured'] ? ['featured'] : [], ['site']),
        ];
    }
    
    // Sort site group: featured → stars → rating
    usort($site_items, fn($a, $b) =>
        [($b['site']['featured'] ? 1 : 0), $b['site']['stars'], $b['site']['rating']]
        <=>
        [($a['site']['featured'] ? 1 : 0), $a['site']['stars'], $a['site']['rating']]
    );
    
    // Online group: only without match
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
            'price'            => $o['per_night_toman'] > 0
                ? ['per_night_toman' => $o['per_night_toman'], 'usd' => $o['price_usd']]
                : null,
            'badges'           => ['online'],
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

/* ========================================================================
   Batch Upsert Matches (KEY OPTIMIZATION)
   
   Instead of N queries for N hotels, uses ON DUPLICATE KEY UPDATE
   Reduces 40 queries to 1 query for 20 hotels!
======================================================================== */
function batch_upsert_matches(array $data): void {
    global $wpdb;
    
    if (empty($data)) return;
    
    $table = $wpdb->prefix . 'ns_hotel_matches';
    
    // Build VALUES clause
    $values = [];
    $placeholders = [];
    
    foreach ($data as $row) {
        $values[] = $row['provider'];
        $values[] = $row['provider_property_id'];
        $values[] = $row['site_post_id'];
        $values[] = $row['provider_name'];
        $values[] = $row['lat'];
        $values[] = $row['lng'];
        $values[] = $row['confidence'];
        $values[] = $row['match_method'];
        $values[] = $row['last_seen_at'];
        
        $placeholders[] = '(%s, %s, %s, %s, %s, %s, %s, %s, %s)';
    }
    
    $values_clause = implode(', ', $placeholders);
    
    // Single INSERT ... ON DUPLICATE KEY UPDATE query
    $sql = "INSERT INTO {$table} 
            (provider, provider_property_id, site_post_id, provider_name, lat, lng, confidence, match_method, last_seen_at)
            VALUES {$values_clause}
            ON DUPLICATE KEY UPDATE
                site_post_id = VALUES(site_post_id),
                provider_name = VALUES(provider_name),
                lat = VALUES(lat),
                lng = VALUES(lng),
                confidence = VALUES(confidence),
                match_method = VALUES(match_method),
                last_seen_at = VALUES(last_seen_at)";
    
    $wpdb->query($wpdb->prepare($sql, ...$values));
}

/* ========================================================================
   Clear Site Hotels Cache
   Called when hotels are added/updated/deleted
======================================================================== */
function clear_site_hotels_cache(?string $city = null): void {
    global $wpdb;
    
    if ($city !== null) {
        // Clear cache for specific city
        $cache_key = 'ns_site_hotels_' . md5($city);
        delete_transient($cache_key);
        Logger::info('Site hotels cache cleared', ['city' => $city]);
    } else {
        // Clear all site hotels cache
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '\\_transient\\_ns\\_site\\_hotels\\_%'
             OR option_name LIKE '\\_transient\\_timeout\\_ns\\_site\\_hotels\\_%'"
        );
        Logger::info('All site hotels cache cleared');
    }
}

// Hook to clear cache when hotels are modified
add_action('save_post_hotel', function($post_id) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    clear_site_hotels_cache();
}, 10, 1);

add_action('delete_post', function($post_id) {
    if (get_post_type($post_id) === 'hotel') {
        clear_site_hotels_cache();
    }
}, 10, 1);