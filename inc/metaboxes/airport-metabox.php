<?php

namespace NextSafar\MetaBoxes;

use NextSafar\Sync\GeoSchema;

class AirportMetaBox {
    public static function register() {
        add_meta_box(
            'nextsafar_airport_info',
            '✈️ اطلاعات فرودگاه',
            [__CLASS__, 'render'],
            'airport',
            'normal',
            'high'
        );
    }

    public static function register_hooks() {
        add_action('save_post_airport', [__CLASS__, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function enqueue_assets($hook) {
        global $post_type;

        if (($hook !== 'post.php' && $hook !== 'post-new.php') || $post_type !== 'airport') return;

        wp_enqueue_style('nextsafar-airport-admin', NEXTSAFAR_URL . 'assets/airport.css', [], NEXTSAFAR_VERSION);
        wp_enqueue_script('nextsafar-airport-admin', NEXTSAFAR_URL . 'assets/airport.js', ['jquery', 'jquery-ui-sortable'], NEXTSAFAR_VERSION, true);

        wp_localize_script('nextsafar-airport-admin', 'NextSafarAirport', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('nextsafar_airport'),
            'i18n'     => ['confirm_remove' => 'آیا مطمئن هستید؟'],
        ]);
    }

    public static function render($post) {
        wp_nonce_field('nextsafar_airport_main', 'nextsafar_airport_main_nonce');

        require NEXTSAFAR_PATH . 'views/metaboxes/airport-main.php';
    }

    public static function save($post_id, $post) {
        if (!isset($_POST['nextsafar_airport_main_nonce']) || !wp_verify_nonce($_POST['nextsafar_airport_main_nonce'], 'nextsafar_airport_main')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        /* Custom fields only */
        $text_fields = ['airport_opened', 'airport_whatsapp'];

        foreach ($text_fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, '_' . $field, sanitize_text_field($_POST[$field]));
            }
        }

        if (isset($_POST['airport_runways_count'])) {
            update_post_meta($post_id, '_airport_runways_count', intval($_POST['airport_runways_count']));
        }
    }

    /** 🔄 Sync: old key → _geo_* */
    public static function smart_update_meta($post_id, $key, $value, $source = 'api') {
        if ($value === '' || $value === null) return false;

        if (in_array($key, ['_location_coords', '_google_map_coords'], true)) {
            $p = array_map('trim', explode(',', (string) $value));

            if (count($p) >= 2 && $p[0] !== '') {
                GeoSchema::set_latlng($post_id, $p[0], $p[1]);
                GeoSchema::set($post_id, 'source', $source);
                GeoSchema::set($post_id, 'last_sync', current_time('mysql'));

                return true;
            }

            return false;
        }

        $map = [
            '_airport_name_en'     => 'name_en',
            '_airport_iata'        => 'iata',
            '_airport_country'     => 'country',
            '_airport_city'        => 'city',
            '_airport_address'     => 'address',
            '_airport_tell_number' => 'phone',
            '_airport_website'     => 'website',
            '_airport_terminals'   => 'terminals',
            '_airport_type'        => 'type',
            '_airport_external_id' => 'external_id',
            '_airport_data_source' => 'source',
            '_airport_last_sync'   => 'last_sync',
        ];

        if (isset($map[$key])) {
            GeoSchema::set($post_id, $map[$key], (string) $value);
            GeoSchema::set($post_id, 'source', $source);
            GeoSchema::set($post_id, 'last_sync', current_time('mysql'));

            return true;
        }

        update_post_meta($post_id, $key, $value);

        return true;
    }
}