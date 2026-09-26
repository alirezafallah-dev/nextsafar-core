<?php

namespace NextSafar\MetaBoxes;

use NextSafar\Sync\GeoSchema;

class RestaurantMetaBox {
    public static function register() {
        add_meta_box('nextsafar_restaurant_info', '🍽️ اطلاعات رستوران', [__CLASS__, 'render_main'], 'restaurant', 'normal', 'high');
        add_meta_box('nextsafar_restaurant_hours', '⏰ ساعات کاری', [__CLASS__, 'render_hours'], 'restaurant', 'normal', 'default');
        add_meta_box('nextsafar_restaurant_api', '🔗 داده‌های API', [__CLASS__, 'render_api'], 'restaurant', 'side', 'default');
    }

    public static function register_hooks() {
        add_action('save_post_restaurant', [__CLASS__, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function enqueue_assets($hook) {
        global $post_type;

        if (($hook !== 'post.php' && $hook !== 'post-new.php') || $post_type !== 'restaurant') return;

        wp_enqueue_style('nextsafar-restaurant-admin', NEXTSAFAR_URL . 'assets/restaurant.css', [], NEXTSAFAR_VERSION);
        wp_enqueue_script('nextsafar-restaurant-admin', NEXTSAFAR_URL . 'assets/restaurant.js', ['jquery'], NEXTSAFAR_VERSION, true);
    }

    public static function render_main($post) {
        wp_nonce_field('nextsafar_restaurant_main', 'nextsafar_restaurant_main_nonce');

        require NEXTSAFAR_PATH . 'views/metaboxes/restaurant-main.php';
    }

    public static function render_hours($post) {
        wp_nonce_field('nextsafar_restaurant_hours', 'nextsafar_restaurant_hours_nonce');

        require NEXTSAFAR_PATH . 'views/metaboxes/restaurant-hours.php';
    }

    public static function render_api($post) {
        $external_id = GeoSchema::get($post->ID, 'external_id');
        $source      = GeoSchema::get($post->ID, 'source');
        $last_sync   = GeoSchema::get($post->ID, 'last_sync');
        ?>
        <div class="ns-api-sidebar">
            <p><strong>شناسه خارجی:</strong><br><?= esc_html($external_id ?: '—'); ?></p>
            <p><strong>منبع:</strong><br><?= esc_html($source ?: 'دستی'); ?></p>
            <p><strong>آخرین sync:</strong><br><?= esc_html($last_sync ?: '—'); ?></p>
        </div>
        <?php
    }

    public static function save($post_id, $post) {
        if (isset($_POST['nextsafar_restaurant_main_nonce']) && wp_verify_nonce($_POST['nextsafar_restaurant_main_nonce'], 'nextsafar_restaurant_main')) {
            self::save_main_fields($post_id);
        }

        if (isset($_POST['nextsafar_restaurant_hours_nonce']) && wp_verify_nonce($_POST['nextsafar_restaurant_hours_nonce'], 'nextsafar_restaurant_hours')) {
            self::save_working_hours($post_id);
        }
    }

    private static function save_main_fields($post_id) {
        $text_fields = [
            'restaurant_mail', 'restaurant_menu_link', 'restaurant_entry_fee',
            'restaurant_best_season', 'restaurant_duration', 'restaurant_cuisine_type',
        ];

        foreach ($text_fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, '_' . $field, sanitize_text_field($_POST[$field]));
            }
        }
    }

    /**
     * ⭐ Save working hours - multi-slot format (matching destination)
     */
    private static function save_working_hours($post_id) {
        $week_days = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه'];

        $work_time = [];

        foreach ($week_days as $day) {
            $option = $_POST['restaurant_work_time_option'][$day] ?? '';

            if ($option === 'off') {
                $work_time[$day] = [['from' => 'off', 'to' => 'off']];
            } elseif ($option === '24h') {
                $work_time[$day] = [['from' => '24h', 'to' => '24h']];
            } else {
                /* Multiple time slots */
                $slots = [];

                $froms = $_POST['restaurant_work_time'][$day]['from'] ?? [];
                $tos = $_POST['restaurant_work_time'][$day]['to'] ?? [];

                if (!is_array($froms)) $froms = [$froms];
                if (!is_array($tos)) $tos = [$tos];

                foreach ($froms as $i => $from) {
                    $to = $tos[$i] ?? '';

                    if (!empty($from) || !empty($to)) {
                        $slots[] = [
                            'from' => sanitize_text_field($from),
                            'to' => sanitize_text_field($to),
                        ];
                    }
                }

                $work_time[$day] = !empty($slots) ? $slots : [];
            }
        }

        update_post_meta($post_id, '_restaurant_work_time', $work_time);
    }

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

    public static function get_restaurant_types() {
        return [
            '' => 'انتخاب کنید',
            'restaurant' => 'رستوران',
            'traditional' => 'رستوران سنتی',
            'fast_food' => 'فست فود',
            'cafe' => 'کافه',
            'coffee_shop' => 'کافی شاپ تخصصی',
            'coffe_restaurant' => 'کافه رستوران',
            'street_food' => 'غذای خیابانی',
            'bakery' => 'نان و شیرینی',
            'bar' => 'بار و لانژ',
            'seafood' => 'غذای دریایی',
            'steakhouse' => 'استیک‌هاوس',
            'buffet' => 'بوفه',
            'fine_dining' => 'رستوران لوکس',
        ];
    }
}