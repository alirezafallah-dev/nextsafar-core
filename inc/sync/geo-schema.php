<?php

namespace NextSafar\Sync;

if (!defined('ABSPATH')) exit;

/**
 * GeoSchema — Single Source of Truth for Location/Sync Data
 *
 * Keys: shared _geo_* across all 5 post types + type-specific fields.
 */
class GeoSchema {

    const GEO_POST_TYPES = ['hotel', 'airport', 'destination', 'restaurant', 'hospital'];

    /** Common fields for all post types */
    const COMMON_FIELDS = [
        'name_en'     => '_geo_name_en',
        'address'     => '_geo_address',
        'city'        => '_geo_city',
        'country'     => '_geo_country',
        'postal'      => '_geo_postal',
        'lat'         => '_geo_lat',
        'lng'         => '_geo_lng',
        'place_id'    => '_geo_place_id',
        'phone'       => '_geo_phone',
        'website'     => '_geo_website',
        'external_id' => '_geo_external_id',
        'source'      => '_geo_source',
        'last_sync'   => '_geo_last_sync',
    ];

    /** Type-specific fields */
    const TYPE_FIELDS = [
        'hotel' => [
            'parto_id'             => '_parto_hotel_id',
            'property_token'       => '_geo_property_token',
            'stars'                => '_hotel_stars',
            'rating'               => '_hotel_rating',
            'reviews'              => '_hotel_reviews_count',
            'checkin'              => '_hotel_checkin',
            'checkout'             => '_hotel_checkout',
            'instructions'         => '_hotel_instructions',
            'special_instructions' => '_hotel_special_instructions',
            'know_before'          => '_hotel_know_before',
            'mandatory_fees'       => '_hotel_mandatory_fees',
            'optional_fees'        => '_hotel_optional_fees',
            'pet_policy'           => '_hotel_pet_policy',
            'min_age'              => '_hotel_min_age',
        ],
        'airport' => [
            'iata'      => '_airport_iata',
            'icao'      => '_airport_icao',
            'terminals' => '_airport_terminals',
            'type'      => '_airport_type',
        ],
        'restaurant' => [
            'type'        => '_restaurant_type',
            'price_level' => '_restaurant_price_level',
            'rating'      => '_restaurant_rating',
            'reviews'     => '_restaurant_reviews_count',
        ],
        'hospital' => [
            'type'      => '_hospital_type',
            'emergency' => '_hospital_emergency',
            'rating'    => '_hospital_rating',
            'reviews'   => '_hospital_reviews_count',
        ],
        'destination' => [
            'type'      => '_destination_type',
            'rating'    => '_destination_rating',
            'reviews'   => '_destination_reviews_count',
            'wikipedia' => '_destination_wikipedia',
        ],
    ];

    /** Legacy key → standard field (translator for sync/migration) */
    const LEGACY_MAP = [
        'hotel' => [
            '_hotel_name_en'       => 'name_en',
            '_hotel_address_se'    => 'address',
            '_hotel_website'       => 'website',
            '_hotel_phone_number'  => 'phone',
            '_hotel_chickin'       => 'checkin',
            '_hotel_chickout'      => 'checkout',
            '_hotel_stars'         => 'stars',
            '_hotel_rating'        => 'rating',
            '_hotel_reviews_count' => 'reviews',
            '_hotel_instructions'  => 'instructions',
            '_hotel_property_token'=> 'property_token',
            '_hotel_external_id'   => 'external_id',
            '_hotel_data_source'   => 'source',
            '_hotel_last_sync'     => 'last_sync',
        ],
        'airport' => [
            '_airport_name_en'      => 'name_en',
            '_airport_iata'         => 'iata',
            '_airport_country'      => 'country',
            '_airport_city'         => 'city',
            '_airport_address'      => 'address',
            '_airport_tell_number'  => 'phone',
            '_airport_website'      => 'website',
            '_airport_terminals'    => 'terminals',
            '_airport_type'         => 'type',
            '_airport_external_id'  => 'external_id',
            '_airport_data_source'  => 'source',
            '_airport_last_sync'    => 'last_sync',
        ],
        'destination' => [
            '_destination_name_en'       => 'name_en',
            '_destination_country'       => 'country',
            '_destination_city'          => 'city',
            '_destination_address'       => 'address',
            '_destination_tell_number'   => 'phone',
            '_destination_website'       => 'website',
            '_destination_rating'        => 'rating',
            '_destination_reviews_count' => 'reviews',
            '_destination_wikipedia_url' => 'wikipedia',
            '_place_type'                => 'type',
            '_destination_external_id'   => 'external_id',
            '_destination_data_source'   => 'source',
            '_destination_last_sync'     => 'last_sync',
        ],
        'restaurant' => [
            '_restaurant_name_en'       => 'name_en',
            '_restaurant_country'       => 'country',
            '_restaurant_city'          => 'city',
            '_restaurant_address'       => 'address',
            '_restaurant_tell_number'   => 'phone',
            '_restaurant_website'       => 'website',
            '_restaurant_rating'        => 'rating',
            '_restaurant_reviews_count' => 'reviews',
            '_restaurant_type'          => 'type',
            '_restaurant_average_price' => 'price_level',
            '_restaurant_external_id'   => 'external_id',
            '_restaurant_data_source'   => 'source',
            '_restaurant_last_sync'     => 'last_sync',
        ],
        'hospital' => [
            '_hospital_name_en'       => 'name_en',
            '_hospital_country'       => 'country',
            '_hospital_city'          => 'city',
            '_hospital_address'       => 'address',
            '_hospital_tell_number'   => 'phone',
            '_hospital_website'       => 'website',
            '_hospital_rating'        => 'rating',
            '_hospital_reviews_count' => 'reviews',
            '_hospital_type'          => 'type',
            '_hospital_external_id'   => 'external_id',
            '_hospital_data_source'   => 'source',
            '_hospital_last_sync'     => 'last_sync',
        ],
    ];

    /**
     * Get the meta key for a field on a specific post type.
     */
    public static function key(string $type, string $field): ?string {
        if (isset(self::COMMON_FIELDS[$field])) return self::COMMON_FIELDS[$field];

        return self::TYPE_FIELDS[$type][$field] ?? null;
    }

    /**
     * Check if a field exists for a specific post type.
     */
    public static function has(string $type, string $field): bool {
        return self::key($type, $field) !== null;
    }

    /**
     * Get a field value for a post.
     */
    public static function get(int $post_id, string $field): string {
        $key = self::key(get_post_type($post_id), $field);

        if (!$key) return '';

        $v = get_post_meta($post_id, $key, true);

        return ($v === '' || $v === null || $v === false) ? '' : (string) $v;
    }

    /**
     * Set a field value for a post.
     */
    public static function set(int $post_id, string $field, string $value): bool {
        $type = get_post_type($post_id);
        $key  = self::key($type, $field);

        if (!$key) return false;

        $v = sanitize_text_field($value);

        update_post_meta($post_id, $key, $v);

        /* ✅ Dual write: also update the legacy key so old metaboxes stay populated */
        $legacy = array_search($field, self::LEGACY_MAP[$type] ?? [], true);

        if ($legacy) {
            update_post_meta($post_id, $legacy, $v);
        }

        return true;
    }

    /**
     * Get latitude and longitude for a post.
     */
    public static function get_latlng(int $post_id): array {
        $lat = self::get($post_id, 'lat');
        $lng = self::get($post_id, 'lng');

        if ($lat === '' || $lng === '') {
            $raw = get_post_meta($post_id, '_location_coords', true);

            if ($raw) {
                $p = array_map('trim', explode(',', $raw));

                if (count($p) >= 2) return [$p[0], $p[1]];
            }
        }

        return [$lat, $lng];
    }

    /**
     * Set latitude and longitude for a post.
     */
    public static function set_latlng(int $post_id, string $lat, string $lng): bool {
        update_post_meta($post_id, '_geo_lat', sanitize_text_field($lat));
        update_post_meta($post_id, '_geo_lng', sanitize_text_field($lng));

        /* ✅ The combined legacy field always stays in sync */
        update_post_meta($post_id, '_location_coords', $lat . ',' . $lng);

        return true;
    }

    /**
     * Get the standard field name for a legacy key.
     */
    public static function legacy_field(string $type, string $key): ?string {
        return self::LEGACY_MAP[$type][$key] ?? null;
    }

    /**
     * 🔄 Single sync entry point: accepts legacy key or coordinates and writes to _geo_*
     *
     * Usage in sync files: GeoSchema::sync_set($post_id, '_hotel_name_en', $val);
     */
    public static function sync_set(int $post_id, string $key, $value, string $source = 'api'): bool {
        if ($value === '' || $value === null) return false;

        if (in_array($key, ['_location_coords', '_google_map_coords'], true)) {
            $p = array_map('trim', explode(',', (string) $value));

            if (count($p) >= 2 && $p[0] !== '') {
                self::set_latlng($post_id, $p[0], $p[1]);
                self::touch_sync($post_id, $source);

                return true;
            }

            return false;
        }

        $field = self::legacy_field(get_post_type($post_id), $key);

        if ($field) {
            self::set($post_id, $field, (string) $value);
            self::touch_sync($post_id, $source);

            return true;
        }

        update_post_meta($post_id, $key, $value);

        return true;
    }

    /**
     * Update sync metadata.
     */
    private static function touch_sync(int $post_id, string $source): void {
        update_post_meta($post_id, '_geo_source', sanitize_key($source));
        update_post_meta($post_id, '_geo_last_sync', current_time('mysql'));
    }

    /**
     * Full output for map/JSON.
     */
    public static function to_array(int $post_id): array {
        $out = [];

        foreach (array_keys(self::COMMON_FIELDS) as $f) {
            $out[$f] = self::get($post_id, $f);
        }

        [$out['lat'], $out['lng']] = self::get_latlng($post_id);

        return $out;
    }
}