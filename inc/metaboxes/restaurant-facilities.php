<?php

namespace NextSafar\MetaBoxes;

class RestaurantFacilities {
    public static function register() {
        add_meta_box(
            'nextsafar_restaurant_facilities',
            '✨ امکانات رستوران',
            [__CLASS__, 'render'],
            'restaurant',
            'normal',
            'default'
        );
    }

    public static function register_hooks() {
        add_action('save_post_restaurant', [__CLASS__, 'save'], 10, 2);
    }

    public static function render($post) {
        wp_nonce_field('nextsafar_restaurant_facilities', 'nextsafar_restaurant_facilities_nonce');

        $sections = self::get_facility_sections();

        require NEXTSAFAR_PATH . 'views/metaboxes/restaurant-facilities.php';
    }

    public static function save($post_id, $post) {
        if (!isset($_POST['nextsafar_restaurant_facilities_nonce']) ||
            !wp_verify_nonce($_POST['nextsafar_restaurant_facilities_nonce'], 'nextsafar_restaurant_facilities')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $sections = self::get_facility_sections();

        foreach ($sections as $section_title => $fields) {
            foreach ($fields as $key => $label) {
                if (isset($_POST[$key]) && $_POST[$key] === 'yes') {
                    update_post_meta($post_id, '_' . $key, 'yes');
                } else {
                    delete_post_meta($post_id, '_' . $key);
                }
            }
        }

        /* Save custom items */
        foreach ($_POST as $key => $val) {
            if (strpos($key, 'rest_fac_custom_') === 0 && is_array($val)) {
                $clean = array_values(array_filter(array_map('sanitize_text_field', $val)));
                update_post_meta($post_id, $key, $clean);
            }
        }
    }

    /**
     * ⭐ Definition of restaurant facility sections and fields
     */
    public static function get_facility_sections() {
        return [
            'خدمات اصلی' => [
                'restaurant_takeout' => 'بیرون‌بر (Takeout)',
                'restaurant_delivery' => 'ارسال (Delivery)',
                'restaurant_dine_in' => 'صرف غذا در محل',
                'restaurant_curbside_pickup' => 'تحویل کنار خیابان',
                'restaurant_drive_through' => 'درایو ترو',
                'restaurant_online_orders' => 'سفارش آنلاین',
            ],
            'فضای نشستن' => [
                'restaurant_outdoor_seating' => 'فضای باز',
                'restaurant_rooftop_seating' => 'پشت بام',
                'restaurant_private_dining' => 'اتاق خصوصی',
                'restaurant_bar_onsite' => 'بار در محل',
                'restaurant_table_service' => 'سرویس میز',
                'restaurant_counter_service' => 'سرویس کانتر',
                'restaurant_seating' => 'صندلی',
            ],
            'رزرو و پرداخت' => [
                'restaurant_reservations' => 'رزرو',
                'restaurant_accepts_reservations' => 'پذیرش رزرو',
                'restaurant_accepts_credit_cards' => 'کارت اعتباری',
                'restaurant_accepts_debit_cards' => 'کارت بانکی',
                'restaurant_nfc_payments' => 'پرداخت موبایلی',
            ],
            'امکانات رفاهی' => [
                'restaurant_wifi' => 'وای‌فای رایگان',
                'restaurant_restroom' => 'سرویس بهداشتی',
                'restaurant_air_conditioning' => 'تهویه مطبوع',
                'restaurant_heating' => 'گرمایش',
                'restaurant_pet_friendly' => 'حیوان خانگی مجاز',
                'restaurant_high_chairs' => 'صندلی کودک',
            ],
            'دسترسی معلولین' => [
                'restaurant_wheelchair_accessible' => 'قابل دسترسی با ویلچر',
                'restaurant_wheelchair_entrance' => 'ورود مناسب ویلچر',
                'restaurant_wheelchair_parking' => 'پارکینگ مناسب ویلچر',
                'restaurant_wheelchair_seating' => 'صندلی مناسب ویلچر',
            ],
            'پارکینگ' => [
                'restaurant_free_parking' => 'پارکینگ رایگان',
                'restaurant_paid_parking' => 'پارکینگ پولی',
                'restaurant_street_parking' => 'پارکینگ خیابانی',
                'restaurant_valet_parking' => 'پارکینگ با خدمه',
                'restaurant_parking' => 'پارکینگ',
            ],
            'غذا و نوشیدنی' => [
                'restaurant_serves_alcohol' => 'سرو الکل',
                'restaurant_serves_beer' => 'سرو آبجو',
                'restaurant_serves_wine' => 'سرو شراب',
                'restaurant_serves_cocktails' => 'سرو کوکتل',
                'restaurant_serves_coffee' => 'سرو قهوه',
                'restaurant_serves_breakfast' => 'صبحانه',
                'restaurant_serves_lunch' => 'ناهار',
                'restaurant_serves_dinner' => 'شام',
                'restaurant_serves_dessert' => 'دسر',
                'restaurant_serves_brunch' => 'برانچ',
                'restaurant_late_night_food' => 'غذای دیروقت',
                'restaurant_halal' => 'غذای حلال',
                'restaurant_vegan' => 'غذای وگان',
                'restaurant_vegetarian' => 'غذای گیاهی',
                'restaurant_organic' => 'غذای ارگانیک',
                'restaurant_small_plates' => 'بشقاب‌های کوچک',
                'restaurant_catering' => 'کیترینگ',
                'restaurant_kids_menu' => 'منوی کودک',
            ],
            'کیفیت و ویژگی‌ها' => [
                'restaurant_great_beer' => 'انتخاب عالی آبجو',
                'restaurant_great_wine' => 'لیست عالی شراب',
                'restaurant_great_cocktails' => 'کوکتل‌های عالی',
                'restaurant_great_coffee' => 'قهوه عالی',
                'restaurant_great_dessert' => 'دسر عالی',
                'restaurant_great_tea' => 'انتخاب عالی چای',
                'restaurant_quick_bite' => 'غذای سریع',
            ],
            'فضا و اتمسفر' => [
                'restaurant_casual' => 'غیررسمی',
                'restaurant_cozy' => 'دنج',
                'restaurant_upscale' => 'لوکس',
                'restaurant_trendy' => 'مدرن',
                'restaurant_romantic' => 'رمانتیک',
                'restaurant_historic' => 'تاریخی',
                'restaurant_quiet' => 'آرام',
                'restaurant_family_friendly' => 'مناسب خانواده',
                'restaurant_good_for_groups' => 'مناسب گروه',
                'restaurant_good_for_solo' => 'مناسب تنها',
                'restaurant_popular_with_tourists' => 'محبوب توریست‌ها',
                'restaurant_popular_for_lunch' => 'محبوب برای ناهار',
                'restaurant_popular_for_dinner' => 'محبوب برای شام',
            ],
        ];
    }
}