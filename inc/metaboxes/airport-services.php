<?php

namespace NextSafar\MetaBoxes;

class AirportServices {
    public static function register() {
        add_meta_box(
            'nextsafar_airport_services',
            '🛎️ خدمات فرودگاه',
            [__CLASS__, 'render'],
            'airport',
            'normal',
            'high'
        );
    }

    public static function register_hooks() {
        add_action('save_post_airport', [__CLASS__, 'save'], 10, 2);
    }

    public static function render($post) {
        wp_nonce_field('nextsafar_airport_services', 'nextsafar_airport_services_nonce');

        $sections = self::get_sections();

        require NEXTSAFAR_PATH . 'views/metaboxes/airport-services.php';
    }

    public static function save($post_id, $post) {
        if (!isset($_POST['nextsafar_airport_services_nonce']) ||
            !wp_verify_nonce($_POST['nextsafar_airport_services_nonce'], 'nextsafar_airport_services')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $sections = self::get_sections();
        $index = 0;

        foreach ($sections as $section_title => $fields) {
            foreach ($fields as $key => $label) {
                if (isset($_POST[$key]) && $_POST[$key] === 'yes') {
                    update_post_meta($post_id, '_' . $key, 'yes');
                } else {
                    delete_post_meta($post_id, '_' . $key);
                }
            }

            /* ⭐ Save with new meta_key */
            $section_meta_key = 'svc_custom_' . $index;

            if (isset($_POST[$section_meta_key]) && is_array($_POST[$section_meta_key])) {
                $cleaned = array_filter(array_map('sanitize_text_field', $_POST[$section_meta_key]));
                update_post_meta($post_id, $section_meta_key, array_values($cleaned));
            } else {
                delete_post_meta($post_id, $section_meta_key);
            }

            $index++;
        }
    }

    public static function get_sections() {
        return [
            /* Passenger Services */
            'خدمات مسافری' => [
                'service_flight_info'       => 'اطلاعات پرواز',
                'service_checkin_assist'    => 'کمک در پذیرش بار',
                'service_baggage_services'  => 'تحویل و پیگیری بار',
                'service_lost_found'        => 'اشیای گمشده',
                'service_special_assist'    => 'خدمات ویژه افراد کم‌توان',
                'service_transit'           => 'خدمات ترانزیت',
                'service_vip_cip'           => 'سالن‌های VIP/CIP',
                'service_waiting_lounges'   => 'لانج‌ها و سالن‌های استراحت',
                'service_medical_assist'    => 'خدمات پزشکی و اورژانس',
            ],

            /* Transportation Services */
            'خدمات حمل‌ونقل' => [
                'service_taxi_bus'          => 'تاکسی و اتوبوس فرودگاهی',
                'service_car_rental'        => 'اجاره خودرو',
                'service_shuttle_bus'       => 'اتوبوس شاتل داخلی',
                'service_parking_short'     => 'پارکینگ کوتاه‌مدت',
                'service_parking_long'      => 'پارکینگ بلندمدت',
                'service_valet'             => 'خدمات پارکینگ ولو',
                'service_public_transport'  => 'اتصال به مترو/قطار/اتوبوس',
                'service_ev_charging'       => 'ایستگاه شارژ خودرو برقی',
            ],

            /* Welfare & Shopping Services */
            'خدمات رفاهی و خرید' => [
                'service_shops'             => 'فروشگاه‌ها و مراکز خرید',
                'service_duty_free'         => 'فروشگاه‌های دیوتی‌فری',
                'service_restaurant_cafe'   => 'رستوران‌ها و کافه‌ها',
                'service_prayer_room'       => 'نمازخانه / عبادتگاه',
                'service_mother_child'      => 'اتاق مادر و کودک',
                'service_kids_play_area'    => 'منطقه بازی کودکان',
                'service_airport_hotel'     => 'رزرو هتل فرودگاهی',
                'service_atm_exchange'      => 'خودپرداز و صرافی',
                'service_free_wifi'         => 'وای‌فای رایگان',
                'service_spa'               => 'سالن اسپا و ماساژ',
            ],

            /* Security & Safety Services */
            'خدمات امنیتی و ایمنی' => [
                'service_security_check'    => 'کنترل امنیتی مسافران',
                'service_customs'           => 'خدمات گمرکی',
                'service_passport_control'  => 'کنترل گذرنامه',
                'service_first_aid'         => 'اورژانس و کمک‌های اولیه',
                'service_police_support'    => 'پشتیبانی پلیس فرودگاهی',
            ],

            /* Cargo & Operations Services */
            'خدمات بار و عملیات' => [
                'service_cargo_handling'    => 'باربری و ترخیص کالا',
                'service_ground_handling'   => 'هندلینگ زمینی',
                'service_fuel_service'      => 'سوخت‌رسانی به هواپیما',
                'service_maintenance'       => 'نگهداری و تعمیرات',
                'service_postal_services'   => 'خدمات پستی و بسته‌ها',
            ],
        ];
    }
}