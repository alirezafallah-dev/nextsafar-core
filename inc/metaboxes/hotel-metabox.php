<?php

namespace NextSafar\MetaBoxes;

use NextSafar\Sync\GeoSchema;

class HotelMetaBox {
    public static function register() {
        add_meta_box('hotel_info_metabox', 'اطلاعات هتل', [__CLASS__, 'render_main'], 'hotel', 'normal', 'high');
        add_meta_box('hotel_api_metabox', 'داده‌های API', [__CLASS__, 'render_api'], 'hotel', 'side', 'default');
    }

    public static function register_hooks() {
        add_action('save_post_hotel', [__CLASS__, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function enqueue_assets($hook) {
        global $post_type;

        if (($hook !== 'post.php' && $hook !== 'post-new.php') || $post_type !== 'hotel') return;

        wp_enqueue_style('nextsafar-hotel-admin', NEXTSAFAR_URL . 'assets/hotel.css', [], NEXTSAFAR_VERSION);
        wp_enqueue_script('nextsafar-hotel-admin', NEXTSAFAR_URL . 'assets/hotel.js', ['jquery'], NEXTSAFAR_VERSION, true);

        wp_localize_script('nextsafar-hotel-admin', 'NextSafarHotel', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('nextsafar_hotel'),
        ]);
    }

    public static function render_main($post) {
        wp_nonce_field('hotel_metabox', 'hotel_metabox_nonce');

        require NEXTSAFAR_PATH . 'views/metaboxes/hotel-main.php';
        require NEXTSAFAR_PATH . 'views/metaboxes/working-hours-modal.php';
    }

    public static function render_api($post) {
        require NEXTSAFAR_PATH . 'views/metaboxes/hotel-api.php';
    }

    public static function save($post_id, $post) {
        if (!isset($_POST['hotel_metabox_nonce']) || !wp_verify_nonce($_POST['hotel_metabox_nonce'], 'hotel_metabox')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        /* 1) Only custom fields (the rest are in geo-metabox) */
        $simple_fields = ['hotel_type'];

        foreach ($simple_fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, '_' . $field, sanitize_text_field($_POST[$field]));
            }
        }

        /* 2) Ratings */
        $points = ['hotel_staff_point', 'hotel_facilities_point', 'hotel_cleanliness', 'hotel_comfort_point', 'hotel_services_point', 'hotel_location_point'];

        foreach ($points as $point) {
            if (isset($_POST[$point]) && is_numeric($_POST[$point])) {
                update_post_meta($post_id, '_' . $point, max(0, min(10, floatval($_POST[$point]))));
            }
        }

        /* 3) Nearby locations */
        self::save_near_locations($post_id);

        /* 4) Amenities */
        self::save_amenities($post_id);

        /* 5) Long descriptions (hotel_instructions moved to geo) */
        $textareas = ['hotel_dis_checkin', 'hotel_dis_checkout', 'hotel_dis_expenses', 'hotel_no_age_restriction', 'hotel_Pets_rouls'];

        foreach ($textareas as $field) {
            if (isset($_POST[$field])) {
                $value = wp_kses_post($_POST[$field]);
                $value = str_replace("\r
", "
", $value);

                update_post_meta($post_id, '_' . $field, $value);
            } else {
                delete_post_meta($post_id, '_' . $field);
            }
        }

        /* 6) Custom sections */
        self::save_custom_sections($post_id);

        /* 7) Calculate rating (on standard key _hotel_rating) */
        self::calculate_and_save_rating($post_id);
    }

    private static function save_near_locations($post_id) {
        if (!isset($_POST['hotel_near_locations']) || !is_array($_POST['hotel_near_locations'])) {
            delete_post_meta($post_id, '_hotel_near_locations');
            return;
        }

        $clean = [];

        foreach ($_POST['hotel_near_locations'] as $loc) {
            if (empty($loc['name']) && empty($loc['post_id'])) continue;

            $clean[] = [
                'type'          => sanitize_text_field($loc['type'] ?? ''),
                'category'      => sanitize_text_field($loc['category'] ?? ''),
                'post_id'       => intval($loc['post_id'] ?? 0),
                'name'          => sanitize_text_field($loc['name'] ?? ''),
                'coords'        => sanitize_text_field($loc['coords'] ?? ''),
                'distance_km'   => sanitize_text_field($loc['distance_km'] ?? ''),
                'distance_unit' => sanitize_text_field($loc['distance_unit'] ?? 'km'),
                'distance_hr'   => sanitize_text_field($loc['distance_hr'] ?? ''),
                'walking_hr'    => sanitize_text_field($loc['walking_hr'] ?? ''),
            ];
        }

        update_post_meta($post_id, '_hotel_near_locations', $clean);
    }

    private static function save_amenities($post_id) {
        foreach (self::get_all_amenity_keys() as $key) {
            if (isset($_POST[$key]) && $_POST[$key] === 'yes') {
                update_post_meta($post_id, '_' . $key, 'yes');
            } else {
                delete_post_meta($post_id, '_' . $key);
            }

            $extra = $key . '_extra';

            if (isset($_POST[$extra]) && $_POST[$extra] === 'yes') {
                update_post_meta($post_id, '_' . $extra, 'yes');
            } else {
                delete_post_meta($post_id, '_' . $extra);
            }

            $hours_key = 'working_hours_' . $key;

            if (isset($_POST[$hours_key])) {
                $hours = json_decode(stripslashes($_POST[$hours_key]), true);

                if (is_array($hours)) {
                    foreach ($hours as $day => $times) {
                        if (empty($times['start']) && empty($times['end'])) {
                            unset($hours[$day]);
                        }
                    }

                    if (!empty($hours)) {
                        update_post_meta($post_id, $hours_key, $hours);
                    } else {
                        delete_post_meta($post_id, $hours_key);
                    }
                }
            }
        }

        foreach ($_POST as $key => $val) {
            if (strpos($key, '_custom_items') !== false && is_array($val)) {
                update_post_meta($post_id, $key, array_values(array_filter(array_map('sanitize_text_field', $val))));
            }
        }
    }

    private static function save_custom_sections($post_id) {
        if (!isset($_POST['hotel_custom_sections']) || !is_array($_POST['hotel_custom_sections'])) {
            delete_post_meta($post_id, '_hotel_custom_sections');
            return;
        }

        $selects = $_POST['hotel_custom_sections']['select'] ?? [];
        $texts   = $_POST['hotel_custom_sections']['textarea'] ?? [];

        $clean = [];

        foreach ($selects as $i => $sel) {
            $txt = isset($texts[$i]) ? wp_kses_post($texts[$i]) : '';

            if (empty($sel) && empty($txt)) continue;

            $clean[] = ['select' => sanitize_text_field($sel), 'textarea' => $txt];
        }

        update_post_meta($post_id, '_hotel_custom_sections', $clean);
    }

    private static function calculate_and_save_rating($post_id) {
        $keys = ['hotel_staff_point', 'hotel_facilities_point', 'hotel_cleanliness', 'hotel_comfort_point', 'hotel_services_point', 'hotel_location_point'];

        $total = 0;
        $count = 0;

        foreach ($keys as $k) {
            $v = get_post_meta($post_id, '_' . $k, true);

            if (is_numeric($v) && $v > 0) {
                $total += floatval($v);
                $count++;
            }
        }

        if ($count > 0) {
            update_post_meta($post_id, '_hotel_rating', round(max(0, min(5, ($total / $count) / 2)), 1));
        }
    }

    /**
     * 🔄 Sync helper: old Parto/SearchApi keys → standard _geo_* keys
     * (In hotel-sync.php, change the call from $this->smart_update_meta to
     *  \NextSafar\MetaBoxes\HotelMetaBox::smart_update_meta)
     */
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
            '_hotel_name_en'       => 'name_en',
            '_hotel_address_se'    => 'address',
            '_hotel_website'       => 'website',
            '_hotel_phone_number'  => 'phone',
            '_hotel_chickin'       => 'checkin',
            '_hotel_chickout'      => 'checkout',
            '_hotel_stars'         => 'stars',
            '_hotel_rating'        => 'rating',
            '_hotel_reviews_count' => 'reviews',
            '_hotel_instructions'  => 'instructions',
            '_hotel_property_token'=> 'property_token',
            '_hotel_external_id'   => 'external_id',
            '_hotel_data_source'   => 'source',
            '_hotel_last_sync'     => 'last_sync',
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

    public static function get_all_amenity_keys() {
        $keys = [];

        foreach (self::get_amenity_sections() as $fields) {
            $keys = array_merge($keys, array_keys($fields));
        }

        return $keys;
    }

    public static function get_amenity_sections() {
        return [
            'حمام' => [
                'hotel_Toilet_paper' => 'دستمال توالت',
                'hotel_Towels' => 'حوله',
                'hotel_shower' => 'دوش',
                'hotel_Slippers' => 'دمپایی',
                'hotel_private_bathroom' => 'حمام اختصاصی',
                'hotel_Toilet' => 'توالت',
                'hotel_Free_toiletries' => 'لوازم بهداشتی رایگان',
                'hotel_Hairdryer' => 'سشوار',
                'hotel_Bathrobe' => 'حمام',
                'hotel_Bathtub' => 'وان حمام',
            ],
            'امکانات اتاق' => [
                'hotel_wardrobe_closet' => 'کمد دیواری',
                'hotel_Clothes_rack' => 'قفسه لباس',
                'hotel_Linens' => 'ملحفه',
                'hotel_Socket_near_bed' => 'پریز نزدیک تخت',
                'hotel_room_servies' => 'سرویس اتاق',
                'hotel_safe_box' => 'صندوق امانات',
                'hotel_room_sofa' => 'مبل',
                'hotel_Drying_rack_for_clothing' => 'قفسه خشک‌کن',
                'hotel_Fold_up_bed' => 'تخت تاشو',
            ],
            'فضای باز' => [
                'hotel_Outdoor_dining_area' => 'فضای غذاخوری بیرونی',
                'hotel_coffee_house_site' => 'قهوه‌خانه',
                'hotel_sun_loungers_beach_chairs' => 'صندیل ساحلی',
                'hotel_outdoor_furniture' => 'مبلمان بیرونی',
                'hotel_Beachfront' => 'کنار ساحل',
                'hotel_Private_beach_area' => 'ساحل خصوصی',
                'hotel_terrace' => 'تراس',
                'hotel_Sun_terrace' => 'تراس آفتابگیر',
                'hotel_garden' => 'باغ',
            ],
            'آشپزخانه' => [
                'hotel_Electric_kettle' => 'کتری برقی',
                'hotel_coffee_tea_maker' => 'چای‌ساز',
                'hotel_Refrigerator' => 'یخچال',
            ],
            'فعالیت‌ها' => [
                'hotel_Tour_about_local_culture' => 'تور فرهنگی',
                'hotel_bicycle_rental' => 'اجاره دوچرخه',
                'hotel_aerobics' => 'ایروبیک',
                'hotel_water_park' => 'پارک آبی',
                'hotel_kids_club' => 'باشگاه کودکان',
                'hotel_nightclub' => 'کلوپ شبانه',
                'hotel_snorkeling' => 'غواصی',
                'hotel_bowling' => 'بولینگ',
                'hotel_pingpong' => 'پینگ‌پنگ',
                'hotel_pool_table' => 'میز بیلیارد',
                'hotel_canoeing' => 'قایق‌رانی',
                'hotel_playground' => 'زمین بازی',
                'hotel_game_room' => 'اتاق بازی',
                'hotel_tennis_court' => 'زمین تنیس',
                'hotel_golf_course' => 'زمین گلف',
                'hotel_live_music' => 'موسیقی زنده',
                'hotel_cultural_events' => 'برنامه‌های فرهنگی',
                'hotel_cooking_classes' => 'کلاس آشپزی',
                'hotel_horseback_riding' => 'اسب‌سواری',
                'hotel_Cycling' => 'دوچرخه‌سواری',
                'hotel_casino' => 'کازینو',
            ],
            'رسانه و فناوری' => [
                'hotel_wifi' => 'وای‌فای رایگان',
                'hotel_flatscreen_tv' => 'تلویزیون تخت',
                'hotel_room_telephone' => 'تلفن',
                'hotel_Satellite_channels' => 'کانال ماهواره‌ای',
                'hotel_Cable_channels' => 'کانال کابلی',
                'hotel_radio' => 'رادیو',
            ],
            'امکانات رفاهی' => [
                'hotel_fitness_center' => 'سالن بدنسازی',
                'hotel_accessible_facilities' => 'امکانات معلولین',
                'hotel_party_hall' => 'تالار مهمانی',
                'hotel_gift_shop' => 'فروشگاه هدیه',
                'hotel_water_purification' => 'تصفیه آب',
                'hotel_dance_hall' => 'سالن رقص',
                'hotel_free_newspaper' => 'روزنامه رایگان',
                'hotel_atm_banking' => 'دستگاه خودپرداز',
                'hotel_tour_ticket_assistant' => 'دستیار تور',
                'hotel_multilingual_staff' => 'کارکنان چندزبانه',
                'hotel_wedding_services' => 'خدمات عروسی',
                'hotel_convenience_store' => 'فروشگاه رفاه',
            ],
            'غذا و نوشیدنی' => [
                'hotel_bar' => 'بار',
                'hotel_minibar' => 'مینی‌بار',
                'hotel_restaurant' => 'رستوران',
                'hotel_Fruit' => 'میوه',
                'hotel_wine_champagne' => 'شراب و شامپاین',
                'hotel_snack_bar' => 'اسنک بار',
                'hotel_special_diet_meals' => 'غذای رژیمی',
                'hotel_Breakfast_in_the_room' => 'صبحانه در اتاق',
                'hotel_Kid_friendly_buffet' => 'بوفه کودکان',
                'hotel_Kids_meals' => 'غذای کودکان',
                'hotel_free_breakfast' => 'صبحانه رایگان',
            ],
            'خدمات پذیرش' => [
                'hotel_luggage_storage' => 'انبار چمدان',
                'hotel_concierge_service' => 'خدمات دربان',
                'hotel_early_checkin' => 'چک‌این زودهنگام',
                'hotel_late_checkout' => 'چک‌اوت دیرهنگام',
                'hotel_24_hour_reception' => 'پذیرش ۲۴ ساعته',
                'hotel_Currency_exchange' => 'صرافی',
                'hotel_entrance_guard' => 'نگهبان ورودی',
                'hotel_Private_check_in_out' => 'ورود/خروج خصوصی',
            ],
            'خدمات نظافتی' => [
                'hotel_cleaning_daily_service' => 'نظافت روزانه',
                'hotel_laundry' => 'رختشویخانه',
                'hotel_Ironing_service' => 'خدمات اتو',
                'hotel_Dry_cleaning' => 'خشک‌شویی',
                'hotel_Suit_press' => 'پرس کت‌وشلوار',
            ],
            'حیوانات خانگی' => [
                'hotel_pet_friendly' => 'امکان همراه داشتن حیوان',
                'hotel_pet_charges_may_apply' => 'با هزینه',
            ],
            'امکانات تجاری' => [
                'hotel_Fax_photocopying' => 'فکس/فتوکپی',
                'hotel_business_center' => 'مرکز تجاری',
                'hotel_Meeting_Banquet_facilities' => 'امکانات جلسه',
            ],
            'ایمنی و امنیت' => [
                'hotel_Fire_extinguishers' => 'کپسول آتش‌نشانی',
                'hotel_CCTV_outside_property' => 'دوربین بیرونی',
                'hotel_CCTV_in_common_areas' => 'دوربین فضاهای مشترک',
                'hotel_Smoke_alarms' => 'آلارم دود',
                'hotel_Security_alarm' => 'زنگ امنیتی',
                'hotel_24_hour_security' => 'امنیت ۲۴ ساعته',
                'hotel_safety_deposit_box' => 'صندوق امانات',
                'hotel_Key_card_access' => 'دسترسی با کارت',
            ],
            'استخر' => [
                'hotel_Indoor_pool' => 'استخر سرپوشیده',
                'hotel_outdoor_pool' => 'استخر روباز',
                'hotel_pool_onlt_adult' => 'فقط بزرگسالان',
                'hotel_heated_pool' => 'آب گرم',
                'hotel_Open_all_year' => 'تمام سال',
                'hotel_Pool_with_view' => 'استخر با منظره',
                'hotel_Pool_bar' => 'بار کنار استخر',
                'hotel_Beach_chairs_Loungers' => 'صندلی ساحلی',
                'hotel_Beach_umbrellas' => 'چتر ساحلی',
                'hotel_Pool_beach_towels' => 'حوله استخری',
                'hotel_sun_umbrellas' => 'چتر آفتابی',
            ],
            'پارکینگ' => [
                'hotel_valet_parking_staff' => 'پارکینگ با خدمه',
                'hotel_free_parking' => 'پارکینگ رایگان',
                'hotel_valet_parking' => 'پارکینگ اختصاصی',
                'hotel_Electric_charging_station' => 'شارژ خودرو برقی',
            ],
            'حمل و نقل' => [
                'hotel_transport_tickets' => 'بلیط حمل‌ونقل عمومی',
                'hotel_airport_shuttle_service' => 'سرویس فرودگاه',
                'hotel_shuttle_service' => 'سرویس رفت‌وبرگشت',
                'hotel_car_rental' => 'اجاره خودرو',
            ],
            'عمومی' => [
                'hotel_air_conditioning' => 'تهویه مطبوع',
                'hotel_heating' => 'گرمایش',
                'hotel_elevator' => 'آسانسور',
                'hotel_soundproof_rooms' => 'اتاق عایق صدا',
                'hotel_Hardwood_parquet_floors' => 'کف چوبی',
                'hotel_Tile_marble_floor' => 'کف کاشی/مرمر',
                'hotel_Fan' => 'فن',
                'hotel_designated_smoking_area' => 'منطقه سیگار',
                'hotel_Shared_lounge_TV_area' => 'سالن مشترک',
                'hotel_Carbon_monoxide_detector' => 'آشکارساز مونوکسید',
            ],
            'خدمات اسپا' => [
                'hotel_spa' => 'اسپا و ماساژ',
                'hotel_fitness' => 'تناسب اندام',
                'hotel_Spa_Relaxation_area' => 'منطقه آرامش',
                'hotel_Steam_room' => 'اتاق بخار',
                'hotel_Turkish_Steam_Bath' => 'حمام ترکی',
                'hotel_sauna' => 'سونا',
                'hotel_Hot_tub' => 'وان آب گرم',
                'hotel_jacuzzi' => 'جکوزی',
                'hotel_personal_trainer' => 'مربی شخصی',
                'hotel_yoga_classes' => 'کلاس یوگا',
                'hotel_body_treatments' => 'درمان‌های بدنی',
                'hotel_Facial_treatments' => 'درمان صورت',
                'hotel_Massage_chair' => 'صندلی ماساژ',
            ],
            'انواع اتاق' => [
                'hotel_city_view' => 'دید شهر',
                'hotel_pool_view' => 'دید استخر',
                'hotel_suites' => 'سوئیت',
                'hotel_non_smoking_rooms' => 'اتاق غیرسیگاری',
                'hotel_family_rooms' => 'اتاق خانوادگی',
            ],
            'زبان کارکنان' => [
                'hotel_english_staff' => 'انگلیسی',
                'hotel_arabic_staff' => 'عربی',
                'hotel_persian_staff' => 'فارسی',
                'hotel_turkish_staff' => 'ترکی',
                'hotel_russian_staff' => 'روسی',
                'hotel_france_staff' => 'فرانسوی',
                'hotel_spanish_staff' => 'اسپانیایی',
                'hotel_germani_staff' => 'آلمانی',
                'hotel_italian_staff' => 'ایتالیایی',
                'hotel_Chinese_staff' => 'چینی',
            ],
            'دسترسی' => [
                'hotel_Wheelchair_accessible' => 'قابل دسترسی با ویلچر',
                'hotel_Toilet_with_grab_rails' => 'توالت با دستگیره',
                'hotel_Raised_toilet' => 'توالت مرتفع',
                'hotel_Upper_floors_elevator' => 'طبقات بالا با آسانسور',
                'hotel_Fac_for_disabled_guests' => 'امکانات معلولین',
            ],
        ];
    }
}