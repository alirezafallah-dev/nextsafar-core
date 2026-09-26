<?php

namespace NextSafar\Sync;

use NextSafar\Database\GeoTable;

if (!defined('ABSPATH')) exit;

/**
 * GeoSync — Unified Layer for Applying API Data to _geo_* and Custom Table
 *
 * All syncs (hotel/restaurant/destination/hospital/airport) go through here.
 */
class GeoSync {

    const FIELD_MAP = [
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

    /**
     * Apply normalized data to a post.
     */
    public static function apply(int $post_id, array $data, string $source = 'searchapi'): void {
        foreach (self::FIELD_MAP as $src_key => $meta_key) {
            if (isset($data[$src_key]) && $data[$src_key] !== '' && $data[$src_key] !== null) {
                update_post_meta($post_id, $meta_key, sanitize_text_field($data[$src_key]));
            }
        }

        /* Coordinates */
        if (isset($data['lat']) && isset($data['lng'])) {
            update_post_meta($post_id, '_geo_lat', sanitize_text_field($data['lat']));
            update_post_meta($post_id, '_geo_lng', sanitize_text_field($data['lng']));

            /* ✅ The combined legacy field always stays in sync */
            update_post_meta($post_id, '_location_coords', $data['lat'] . ',' . $data['lng']);
        }

        /* Sync metadata */
        update_post_meta($post_id, '_geo_source', sanitize_key($source));
        update_post_meta($post_id, '_geo_last_sync', current_time('mysql'));

        /* ✅ Sync to custom geo table */
        self::sync_to_custom_table($post_id);
    }

    /**
     * ⭐ Sync location data to custom geo table.
     */
    public static function sync_to_custom_table(int $post_id): bool {
        if (!class_exists('NextSafar\Database\GeoTable')) {
            error_log('❌ GeoTable class not found');

            return false;
        }

        $data = [
            'lat'         => get_post_meta($post_id, '_geo_lat', true),
            'lng'         => get_post_meta($post_id, '_geo_lng', true),
            'place_id'    => get_post_meta($post_id, '_geo_place_id', true),
            'city'        => get_post_meta($post_id, '_geo_city', true),
            'country'     => get_post_meta($post_id, '_geo_country', true),
            'address'     => get_post_meta($post_id, '_geo_address', true),
            'phone'       => get_post_meta($post_id, '_geo_phone', true),
            'website'     => get_post_meta($post_id, '_geo_website', true),
            'external_id' => get_post_meta($post_id, '_geo_external_id', true),
            'source'      => get_post_meta($post_id, '_geo_source', true),
        ];

        /* Only save if we have coordinates */
        if (empty($data['lat']) || empty($data['lng']) || $data['lat'] === '0' || $data['lng'] === '0') {
            return false;
        }

        return GeoTable::upsert($post_id, $data);
    }
}