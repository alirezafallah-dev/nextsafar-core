<?php

namespace NextSafar\MetaBoxes;

class TravelGuideMetaBox {
    public static function register() {
        add_meta_box(
            'nextsafar_travelguide_info',
            '📖 اطلاعات کامل راهنمای سفر',
            [__CLASS__, 'render'],
            'travelguide',
            'normal',
            'high'
        );
    }

    public static function register_hooks() {
        add_action('save_post_travelguide', [__CLASS__, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function enqueue_assets($hook) {
        global $post_type;

        if (($hook !== 'post.php' && $hook !== 'post-new.php') || $post_type !== 'travelguide') {
            return;
        }

        wp_enqueue_style(
            'nextsafar-travelguide-admin',
            NEXTSAFAR_URL . 'assets/travelguide.css',
            [],
            NEXTSAFAR_VERSION
        );
    }

    public static function render($post) {
        wp_nonce_field('nextsafar_travelguide_meta', 'travelguide_meta_nonce');

        require NEXTSAFAR_PATH . 'views/metaboxes/travelguide-main.php';
    }

    public static function save($post_id, $post) {
        if (!isset($_POST['travelguide_meta_nonce']) || !wp_verify_nonce($_POST['travelguide_meta_nonce'], 'nextsafar_travelguide_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        /* Text fields */
        $text_fields = [
            'travelguide_country', 'travelguide_city', 'travelguide_language',
            'travelguide_currency', 'travelguide_country_code', 'travelguide_emergency',
            'location_coords', 'google_map_coords', 'travelguide_daily_budget',
            'travelguide_neighbours',
        ];

        foreach ($text_fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, '_' . $field, sanitize_text_field($_POST[$field]));
            } else {
                delete_post_meta($post_id, '_' . $field);
            }
        }

        /* Select fields */
        $select_fields = ['travelguide_visa_required', 'travelguide_safety_level', 'travelguide_best_season'];

        foreach ($select_fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, '_' . $field, sanitize_text_field($_POST[$field]));
            } else {
                delete_post_meta($post_id, '_' . $field);
            }
        }
    }

    public static function get_visa_options() {
        return [
            ''           => 'انتخاب کنید',
            'yes'        => 'بله',
            'no'         => 'خیر',
            'on_arrival' => 'فرودگاهی',
        ];
    }

    public static function get_safety_options() {
        return [
            ''          => 'انتخاب کنید',
            'safe'      => 'ایمن',
            'moderate'  => 'متوسط',
            'risky'     => 'پرخطر',
            'dangerous' => 'خطرناک',
        ];
    }

    public static function get_season_options() {
        return [
            ''         => 'انتخاب کنید',
            'spring'   => 'بهار',
            'summer'   => 'تابستان',
            'autumn'   => 'پاییز',
            'winter'   => 'زمستان',
            'all_year' => 'تمام سال',
        ];
    }
}