<?php

/**
 * GeoTable — Custom table management for geographic data
 *
 * Uses Raw SQL for spatial column (POINT) since $wpdb->insert
 * cannot handle spatial functions directly.
 */

namespace NextSafar\Database;

if (!defined('ABSPATH')) exit;

class GeoTable {
    const TABLE_NAME    = 'nextsafar_geo';
    const TABLE_VERSION = '1.0.1';

    /**
     * Get the full table name with WordPress prefix.
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Create the geographic data table.
     */
    public static function create_table(): void {
        global $wpdb;

        $table_name      = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        /* If table already exists, only update the version */
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
            update_option('nextsafar_geo_table_version', self::TABLE_VERSION);
            return;
        }

        $sql = "CREATE TABLE {$table_name} (
            post_id BIGINT UNSIGNED NOT NULL,
            post_type VARCHAR(50) NOT NULL,
            lat DECIMAL(10, 8) NOT NULL DEFAULT 0,
            lng DECIMAL(11, 8) NOT NULL DEFAULT 0,
            place_id VARCHAR(255) DEFAULT NULL,
            city VARCHAR(255) DEFAULT NULL,
            country VARCHAR(255) DEFAULT NULL,
            address TEXT,
            phone VARCHAR(100) DEFAULT NULL,
            website VARCHAR(500) DEFAULT NULL,
            external_id VARCHAR(255) DEFAULT NULL,
            source VARCHAR(50) DEFAULT 'manual',
            last_sync DATETIME DEFAULT NULL,
            location_point POINT NOT NULL SRID 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (post_id),
            SPATIAL KEY location_point (location_point),
            KEY post_type (post_type),
            KEY city (city),
            KEY country (country),
            KEY external_id (external_id)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        update_option('nextsafar_geo_table_version', self::TABLE_VERSION);
    }

    /**
     * Upgrade the table if needed.
     */
    public static function maybe_upgrade(): void {
        $installed_version = get_option('nextsafar_geo_table_version', '0.0.0');

        if (version_compare($installed_version, self::TABLE_VERSION, '<')) {
            self::create_table();
        }
    }

    /**
     * Insert or update geographic data.
     *
     * Uses Raw SQL because $wpdb->insert cannot send spatial functions.
     *
     * @param int   $post_id The WordPress post ID.
     * @param array $data    Geographic data array.
     * @return bool True on success, false on failure.
     */
    public static function upsert(int $post_id, array $data): bool {
        global $wpdb;

        $post_type = get_post_type($post_id);

        if (!$post_type) {
            error_log('❌ GeoTable::upsert - Post not found: ' . $post_id);
            return false;
        }

        /* Validate coordinates */
        $lat = isset($data['lat']) && $data['lat'] !== '' ? (float) $data['lat'] : null;
        $lng = isset($data['lng']) && $data['lng'] !== '' ? (float) $data['lng'] : null;

        if ($lat === null || $lng === null || ($lat == 0 && $lng == 0)) {
            error_log('❌ GeoTable::upsert - No coordinates for post: ' . $post_id);
            return false;
        }

        $table = self::get_table_name();

        /* Build spatial point (order: lng lat) */
        $point_wkt = "POINT({$lng} {$lat})";

        /* Check if record exists */
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$table} WHERE post_id = %d",
            $post_id
        ));

        if ($exists) {
            /* Update existing record */
            $sql = "UPDATE {$table} SET
                post_type = %s,
                lat = %f,
                lng = %f,
                place_id = %s,
                city = %s,
                country = %s,
                address = %s,
                phone = %s,
                website = %s,
                external_id = %s,
                source = %s,
                last_sync = %s,
                location_point = ST_GeomFromText(%s)
                WHERE post_id = %d";

            $result = $wpdb->query($wpdb->prepare($sql,
                $post_type,
                $lat,
                $lng,
                $data['place_id'] ?? '',
                $data['city'] ?? '',
                $data['country'] ?? '',
                $data['address'] ?? '',
                $data['phone'] ?? '',
                $data['website'] ?? '',
                $data['external_id'] ?? '',
                $data['source'] ?? 'api',
                current_time('mysql'),
                $point_wkt,
                $post_id
            ));
        } else {
            /* Insert new record */
            $sql = "INSERT INTO {$table}
                (post_id, post_type, lat, lng, place_id, city, country, address, phone, website, external_id, source, last_sync, location_point)
                VALUES
                (%d, %s, %f, %f, %s, %s, %s, %s, %s, %s, %s, %s, %s, ST_GeomFromText(%s))";

            $result = $wpdb->query($wpdb->prepare($sql,
                $post_id,
                $post_type,
                $lat,
                $lng,
                $data['place_id'] ?? '',
                $data['city'] ?? '',
                $data['country'] ?? '',
                $data['address'] ?? '',
                $data['phone'] ?? '',
                $data['website'] ?? '',
                $data['external_id'] ?? '',
                $data['source'] ?? 'api',
                current_time('mysql'),
                $point_wkt
            ));
        }

        if ($result === false) {
            error_log('❌ GeoTable::upsert error: ' . $wpdb->last_error);
            return false;
        }

        return true;
    }

    /**
     * Find nearby locations using Haversine formula.
     *
     * Uses lat/lng columns directly (no dependency on spatial column).
     *
     * @param float       $lat       Center latitude.
     * @param float       $lng       Center longitude.
     * @param float       $radius_km Search radius in kilometers.
     * @param string|null $post_type Optional post type filter.
     * @param int         $limit     Maximum results to return.
     * @return array Array of nearby locations.
     */
    public static function find_nearby(
        float $lat,
        float $lng,
        float $radius_km = 5,
        ?string $post_type = null,
        int $limit = 20
    ): array {
        global $wpdb;

        $table = self::get_table_name();

        /* Bounding box for speed (pre-filter) */
        $lat_delta = $radius_km / 111.0;
        $lng_delta = $radius_km / (111.0 * max(0.1, cos(deg2rad($lat))));

        $min_lat = $lat - $lat_delta;
        $max_lat = $lat + $lat_delta;
        $min_lng = $lng - $lng_delta;
        $max_lng = $lng + $lng_delta;

        /* Parameters in order of SQL placeholders */
        $params = [$lat, $lng, $lat, $min_lat, $max_lat, $min_lng, $max_lng];

        $where_post_type = '';

        if ($post_type) {
            $where_post_type = "AND post_type = %s";
            $params[] = $post_type;
        }

        $params[] = $radius_km;
        $params[] = $limit;

        $sql = "
            SELECT
                post_id,
                post_type,
                lat,
                lng,
                place_id,
                city,
                country,
                address,
                phone,
                website,
                (6371 * ACOS(
                    COS(RADIANS(%f)) * COS(RADIANS(lat)) *
                    COS(RADIANS(lng) - RADIANS(%f)) +
                    SIN(RADIANS(%f)) * SIN(RADIANS(lat))
                )) AS distance_km
            FROM {$table}
            WHERE lat BETWEEN %f AND %f
            AND lng BETWEEN %f AND %f
            {$where_post_type}
            HAVING distance_km <= %f
            ORDER BY distance_km ASC
            LIMIT %d
        ";

        $results = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        return $results ?: [];
    }

    /**
     * Get geographic data for a specific post.
     *
     * @param int $post_id The WordPress post ID.
     * @return array|null Geographic data or null if not found.
     */
    public static function get_by_post_id(int $post_id): ?array {
        global $wpdb;

        $table = self::get_table_name();

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE post_id = %d",
            $post_id
        ), ARRAY_A);

        return $result ?: null;
    }

    /**
     * Delete geographic data for a specific post.
     *
     * @param int $post_id The WordPress post ID.
     * @return bool True on success.
     */
    public static function delete(int $post_id): bool {
        global $wpdb;

        $table = self::get_table_name();

        return $wpdb->delete($table, ['post_id' => $post_id], ['%d']) !== false;
    }

    /**
     * Sync geographic data from post meta to the custom table.
     *
     * @param int $batch_size Number of posts to process per batch.
     * @return int Number of records synced.
     */
    public static function sync_from_postmeta(int $batch_size = 100): int {
        global $wpdb;

        $post_types = ['hotel', 'airport', 'destination', 'restaurant', 'hospital'];
        $synced = 0;
        $table = self::get_table_name();

        foreach ($post_types as $post_type) {
            $posts = get_posts([
                'post_type'   => $post_type,
                'post_status' => 'publish',
                'numberposts' => $batch_size,
                'fields'      => 'ids',
                'meta_query'  => [
                    ['key' => '_geo_lat', 'compare' => 'EXISTS']
                ]
            ]);

            foreach ($posts as $pid) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM {$table} WHERE post_id = %d",
                    $pid
                ));

                if (!$exists) {
                    $data = self::collect_geo_data($pid);

                    if (!empty($data['lat']) && !empty($data['lng'])) {
                        if (self::upsert($pid, $data)) {
                            $synced++;
                        }
                    }
                }
            }
        }

        return $synced;
    }

    /**
     * Collect geographic data from post meta fields.
     *
     * @param int $post_id The WordPress post ID.
     * @return array Collected geographic data.
     */
    private static function collect_geo_data(int $post_id): array {
        return [
            'lat'         => get_post_meta($post_id, '_geo_lat', true),
            'lng'         => get_post_meta($post_id, '_geo_lng', true),
            'place_id'    => get_post_meta($post_id, '_geo_place_id', true),
            'city'        => get_post_meta($post_id, '_geo_city', true),
            'country'     => get_post_meta($post_id, '_geo_country', true),
            'address'     => get_post_meta($post_id, '_geo_address', true),
            'phone'       => get_post_meta($post_id, '_geo_phone', true),
            'website'     => get_post_meta($post_id, '_geo_website', true),
            'external_id' => get_post_meta($post_id, '_geo_external_id', true),
            'source'      => get_post_meta($post_id, '_geo_source', true) ?: 'manual',
        ];
    }
}