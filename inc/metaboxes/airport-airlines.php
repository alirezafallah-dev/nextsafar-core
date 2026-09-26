<?php

namespace NextSafar\MetaBoxes;

class AirportAirlines {
    public static function register() {
        add_meta_box(
            'nextsafar_airport_airlines',
            '🛩️ خطوط هوایی (ایرلاین‌ها)',
            [__CLASS__, 'render'],
            'airport',
            'normal',
            'default'
        );
    }

    public static function register_hooks() {
        add_action('save_post_airport', [__CLASS__, 'save'], 10, 2);
    }

    public static function render($post) {
        wp_nonce_field('nextsafar_airlines', 'nextsafar_airlines_nonce');

        require NEXTSAFAR_PATH . 'views/metaboxes/airport-airlines.php';
    }

    public static function save($post_id, $post) {
        if (!isset($_POST['nextsafar_airlines_nonce']) ||
            !wp_verify_nonce($_POST['nextsafar_airlines_nonce'], 'nextsafar_airlines')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        /* Main airline websites */
        if (isset($_POST['airline_main_airline_site'])) {
            update_post_meta($post_id, '_airline_main_airline_site', esc_url_raw($_POST['airline_main_airline_site']));
        }

        /* Airlines list */
        if (isset($_POST['airlines']) && is_array($_POST['airlines'])) {
            $airlines = array_values(array_filter($_POST['airlines'], function($item) {
                return !empty($item['name']) || !empty($item['link']);
            }));

            $clean_airlines = [];

            foreach ($airlines as $airline) {
                $clean_airlines[] = [
                    'name' => sanitize_text_field($airline['name'] ?? ''),
                    'link' => esc_url_raw($airline['link'] ?? ''),
                ];
            }

            update_post_meta($post_id, '_airlines', $clean_airlines);
        } else {
            delete_post_meta($post_id, '_airlines');
        }
    }
}