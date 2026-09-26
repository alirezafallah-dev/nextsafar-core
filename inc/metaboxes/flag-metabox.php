<?php

namespace NextSafar\MetaBoxes;

/**
 * Country Flag MetaBox - for airport and visa
 * Online flag from flagcdn.com + manual upload
 */
class FlagMetaBox {
    public static function get_supported_post_types() {
        return apply_filters('nextsafar_flag_post_types', ['airport', 'visa']);
    }

    public static function register() {
        foreach (self::get_supported_post_types() as $pt) {
            add_meta_box(
                'nextsafar_country_flag',
                '🏳️ پرچم کشور',
                [__CLASS__, 'render'],
                $pt,
                'side',
                'default'
            );
        }
    }

    public static function register_hooks() {
        add_action('save_post', [__CLASS__, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function enqueue_assets($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'])) return;

        $screen = get_current_screen();

        if (!$screen || !in_array($screen->post_type, self::get_supported_post_types())) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_script(
            'nextsafar-flag',
            NEXTSAFAR_URL . 'assets/airport.js',
            ['jquery'],
            NEXTSAFAR_VERSION,
            true
        );

        wp_localize_script('nextsafar-flag', 'NextSafarFlag', [
            'country_map' => self::get_country_map(),
        ]);
    }

    public static function render($post) {
        wp_nonce_field('nextsafar_flag', 'nextsafar_flag_nonce');

        require NEXTSAFAR_PATH . 'views/metaboxes/country-flag.php';
    }

    public static function save($post_id, $post) {
        if (!in_array($post->post_type, self::get_supported_post_types())) return;

        if (!isset($_POST['nextsafar_flag_nonce']) ||
            !wp_verify_nonce($_POST['nextsafar_flag_nonce'], 'nextsafar_flag')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $flag_url = isset($_POST['country_flag']) ? esc_url_raw($_POST['country_flag']) : '';
        $iso_code = isset($_POST['country_iso']) ? sanitize_text_field($_POST['country_iso']) : '';
        $source   = isset($_POST['country_flag_source']) ? sanitize_text_field($_POST['country_flag_source']) : 'auto';

        if ($flag_url) {
            update_post_meta($post_id, '_country_flag', $flag_url);
            update_post_meta($post_id, '_country_flag_source', $source);
        } else {
            delete_post_meta($post_id, '_country_flag');
            delete_post_meta($post_id, '_country_flag_source');
        }

        if ($iso_code) {
            update_post_meta($post_id, '_country_iso', $iso_code);
        } else {
            delete_post_meta($post_id, '_country_iso');
        }
    }

    /**
     * Mapping of country names (Persian + English) to ISO code for flag
     */
    public static function get_country_map() {
        return [
            'iran' => ['fa' => 'ایران', 'iso' => 'ir'],
            'united arab emirates' => ['fa' => 'امارات متحده عربی', 'iso' => 'ae'],
            'uae' => ['fa' => 'امارات', 'iso' => 'ae'],
            'turkey' => ['fa' => 'ترکیه', 'iso' => 'tr'],
            'iraq' => ['fa' => 'عراق', 'iso' => 'iq'],
            'saudi arabia' => ['fa' => 'عربستان سعودی', 'iso' => 'sa'],
            'qatar' => ['fa' => 'قطر', 'iso' => 'qa'],
            'kuwait' => ['fa' => 'کویت', 'iso' => 'kw'],
            'oman' => ['fa' => 'عمان', 'iso' => 'om'],
            'bahrain' => ['fa' => 'بحرین', 'iso' => 'bh'],
            'china' => ['fa' => 'چین', 'iso' => 'cn'],
            'japan' => ['fa' => 'ژاپن', 'iso' => 'jp'],
            'india' => ['fa' => 'هند', 'iso' => 'in'],
            'russia' => ['fa' => 'روسیه', 'iso' => 'ru'],
            'germany' => ['fa' => 'آلمان', 'iso' => 'de'],
            'france' => ['fa' => 'فرانسه', 'iso' => 'fr'],
            'italy' => ['fa' => 'ایتالیا', 'iso' => 'it'],
            'spain' => ['fa' => 'اسپانیا', 'iso' => 'es'],
            'united kingdom' => ['fa' => 'بریتانیا', 'iso' => 'gb'],
            'united states' => ['fa' => 'آمریکا', 'iso' => 'us'],
            'canada' => ['fa' => 'کانادا', 'iso' => 'ca'],
            'thailand' => ['fa' => 'تایلند', 'iso' => 'th'],
            'malaysia' => ['fa' => 'مالزی', 'iso' => 'my'],
            'indonesia' => ['fa' => 'اندونزی', 'iso' => 'id'],
            'singapore' => ['fa' => 'سنگاپور', 'iso' => 'sg'],
            'south korea' => ['fa' => 'کره جنوبی', 'iso' => 'kr'],
            'pakistan' => ['fa' => 'پاکستان', 'iso' => 'pk'],
            'afghanistan' => ['fa' => 'افغانستان', 'iso' => 'af'],
            'azerbaijan' => ['fa' => 'آذربایجان', 'iso' => 'az'],
            'armenia' => ['fa' => 'ارمنستان', 'iso' => 'am'],
            'georgia' => ['fa' => 'گرجستان', 'iso' => 'ge'],
            'egypt' => ['fa' => 'مصر', 'iso' => 'eg'],
            'australia' => ['fa' => 'استرالیا', 'iso' => 'au'],
            'brazil' => ['fa' => 'برزیل', 'iso' => 'br'],
            'netherlands' => ['fa' => 'هلند', 'iso' => 'nl'],
            'switzerland' => ['fa' => 'سوئیس', 'iso' => 'ch'],
            'sweden' => ['fa' => 'سوئد', 'iso' => 'se'],
            'norway' => ['fa' => 'نروژ', 'iso' => 'no'],
            'austria' => ['fa' => 'اتریش', 'iso' => 'at'],
            'greece' => ['fa' => 'یونان', 'iso' => 'gr'],
            'ukraine' => ['fa' => 'اوکراین', 'iso' => 'ua'],
            'jordan' => ['fa' => 'اردن', 'iso' => 'jo'],
            'lebanon' => ['fa' => 'لبنان', 'iso' => 'lb'],
            'syria' => ['fa' => 'سوریه', 'iso' => 'sy'],
        ];
    }
}