<?php

namespace NextSafar\MetaBoxes;

if (!defined('ABSPATH')) exit;

use NextSafar\Sync\GeoSchema;
use NextSafar\Sync\GeoSync;

/**
 * GeoCoordsField — Unified coordinates field + map picker
 * One input: "lat,lng" + OpenStreetMap modal (no API key required)
 * Added as a separate metabox for all 5 location types
 */
class GeoCoordsField {
    const TYPES = ['hotel', 'restaurant', 'destination', 'hospital', 'airport'];

    public static function init(): void {
        add_action('add_meta_boxes', [__CLASS__, 'register_boxes']);
        add_action('save_post', [__CLASS__, 'save'], 20, 1);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    /* ═══ Register metabox for 5 types ═══ */
    public static function register_boxes(): void {
        foreach (self::TYPES as $type) {
            add_meta_box('ns_geo_coords_box', '📍 مختصات و نقشه', [__CLASS__, 'render_box'], $type, 'side', 'high');
        }
    }

    public static function render_box($post): void {
        [$lat, $lng] = GeoSchema::get_latlng((int) $post->ID);

        $value = ($lat !== '' && $lng !== '') ? $lat . ',' . $lng : '';

        include NEXTSAFAR_PATH . 'views/metaboxes/geo-coords.php';
    }

    /* ═══ Assets only on location screens ═══ */
    public static function assets(): void {
        if (!function_exists('get_current_screen')) return;

        $screen = get_current_screen();

        if (!$screen || !in_array($screen->post_type ?? '', self::TYPES, true)) return;

        wp_enqueue_style('ns-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
        wp_enqueue_script('ns-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
        wp_enqueue_style('ns-geo-picker', plugins_url('../../assets/geo-picker.css', __FILE__), [], '1.0.0');
        wp_enqueue_script('ns-geo-picker', plugins_url('../../assets/geo-picker.js', __FILE__), ['ns-leaflet'], '1.0.0', true);
    }

    /* ═══ Save: one string → two metas + combined field ═══ */
    public static function save($post_id): void {
        if (!isset($_POST['ns_geo_coords_nonce'])) return;
        if (!wp_verify_nonce($_POST['ns_geo_coords_nonce'], 'ns_geo_coords_' . $post_id)) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (!in_array(get_post_type($post_id), self::TYPES, true)) return;

        $raw = sanitize_text_field($_POST['ns_geo_coords'] ?? '');

        /* Empty → clear coordinates */
        if ($raw === '') {
            delete_post_meta($post_id, '_geo_lat');
            delete_post_meta($post_id, '_geo_lng');
            delete_post_meta($post_id, '_location_coords');

            return;
        }

        $parts = array_map('trim', explode(',', $raw));

        if (count($parts) < 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) return;

        $lat = (float) $parts[0];
        $lng = (float) $parts[1];

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return;

        GeoSchema::set_latlng($post_id, (string) $lat, (string) $lng);

        update_post_meta($post_id, '_location_coords', $lat . ',' . $lng); /* Legacy compatibility */

        if (class_exists(GeoSync::class)) {
            GeoSync::sync_to_custom_table($post_id);
        }
    }
}