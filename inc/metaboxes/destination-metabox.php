<?php

namespace NextSafar\MetaBoxes;

use NextSafar\Sync\GeoSchema;

class DestinationMetaBox {
    public static function register() {
        add_meta_box('nextsafar_destination_info', '🏝️ اطلاعات مقصد گردشگری', [__CLASS__, 'render'], 'destination', 'normal', 'high');
        add_meta_box('nextsafar_destination_hours', '⏰ ساعات کاری', [__CLASS__, 'render_hours'], 'destination', 'normal', 'default');
    }

    public static function register_hooks() {
        add_action('save_post_destination', [__CLASS__, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function enqueue_assets($hook) {
        global $post_type;

        if (($hook !== 'post.php' && $hook !== 'post-new.php') || $post_type !== 'destination') return;

        wp_enqueue_style('nextsafar-destination-admin', NEXTSAFAR_URL . 'assets/destination.css', [], NEXTSAFAR_VERSION);
        wp_enqueue_script('nextsafar-destination-admin', NEXTSAFAR_URL . 'assets/destination.js', ['jquery'], NEXTSAFAR_VERSION, true);
    }

    public static function render($post) {
        wp_nonce_field('nextsafar_destination_main', 'nextsafar_destination_main_nonce');

        require NEXTSAFAR_PATH . 'views/metaboxes/destination-main.php';
    }

    public static function render_hours($post) {
        wp_nonce_field('nextsafar_destination_hours', 'nextsafar_destination_hours_nonce');

        require NEXTSAFAR_PATH . 'views/metaboxes/destination-hours.php';
    }

    public static function save($post_id, $post) {
        if (isset($_POST['nextsafar_destination_main_nonce']) && wp_verify_nonce($_POST['nextsafar_destination_main_nonce'], 'nextsafar_destination_main')) {
            self::save_main_fields($post_id);
        }

        if (isset($_POST['nextsafar_destination_hours_nonce']) && wp_verify_nonce($_POST['nextsafar_destination_hours_nonce'], 'nextsafar_destination_hours')) {
            self::save_working_hours($post_id);
        }
    }

    private static function save_main_fields($post_id) {
        /* Only custom fields (geo fields are in geo-metabox) */
        $text_fields = [
            'destination_entry_fee', 'destination_Opened', 'destination_Height',
            'destination_Architect', 'destination_Architectural_styles', 'destination_Owner',
            'destination_Floor_count', 'destination_Former_names', 'destination_Structural_system',
            'destination_duration', 'destination_best_season',
        ];

        foreach ($text_fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, '_' . $field, sanitize_text_field($_POST[$field]));
            }
        }

        $textareas = [
            'destination_facilities' => 'لیست امکانات', 'destination_accessibility' => 'دسترسی‌ها',
            'destination_more_desc' => 'توضیحات و توصیه‌ها',
        ];

        foreach ($textareas as $field => $label) {
            if (isset($_POST[$field])) {
                $value = wp_kses_post($_POST[$field]);
                $value = str_replace("\r\n", "\n", $value);

                update_post_meta($post_id, '_' . $field, $value);
            } else {
                delete_post_meta($post_id, '_' . $field);
            }
        }
    }

    private static function save_working_hours($post_id) {
        $week_days = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه'];

        $work_time = [];

        foreach ($week_days as $day) {
            $option = $_POST['destination_work_time_option'][$day] ?? '';

            if ($option === 'off') {
                $work_time[$day] = [['from' => 'off', 'to' => 'off']];
            } elseif ($option === '24h') {
                $work_time[$day] = [['from' => '24h', 'to' => '24h']];
            } else {
                // ⭐ Multiple time slots in one day
                $slots = [];

                $froms = $_POST['destination_work_time'][$day]['from'] ?? [];
                $tos = $_POST['destination_work_time'][$day]['to'] ?? [];

                if (!is_array($froms)) $froms = [$froms];
                if (!is_array($tos)) $tos = [$tos];

                foreach ($froms as $i => $from) {
                    $to = $tos[$i] ?? '';

                    // Only slots that have at least one value
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

        update_post_meta($post_id, '_destination_work_time', $work_time);
    }

    /** 🔄 Sync: legacy key → _geo_* */
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
            '_destination_name_en' => 'name_en', '_destination_country' => 'country', '_destination_city' => 'city',
            '_destination_address' => 'address', '_destination_tell_number' => 'phone', '_destination_website' => 'website',
            '_destination_rating' => 'rating', '_destination_reviews_count' => 'reviews',
            '_destination_wikipedia_url' => 'wikipedia', '_place_type' => 'type',
            '_destination_external_id' => 'external_id', '_destination_data_source' => 'source', '_destination_last_sync' => 'last_sync',
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