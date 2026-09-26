<?php

namespace NextSafar\MetaBoxes;

class AirportFacilities {
    public static function register() {
        add_meta_box(
            'nextsafar_airport_facilities',
            '🏗️ امکانات فرودگاه',
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
        wp_nonce_field('nextsafar_airport_facilities', 'nextsafar_airport_facilities_nonce');

        $sections = self::get_sections();

        $view_data = ['sections' => $sections, 'post' => $post];
        extract($view_data);

        require NEXTSAFAR_PATH . 'views/metaboxes/airport-facilities.php';
    }

    public static function save($post_id, $post) {
        if (!isset($_POST['nextsafar_airport_facilities_nonce']) ||
            !wp_verify_nonce($_POST['nextsafar_airport_facilities_nonce'], 'nextsafar_airport_facilities')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $sections = self::get_sections();
        $index = 0;

        foreach ($sections as $section_title => $fields) {
            /* Save checkboxes */
            foreach ($fields as $key => $label) {
                if (isset($_POST[$key]) && $_POST[$key] === 'yes') {
                    update_post_meta($post_id, '_' . $key, 'yes');
                } else {
                    delete_post_meta($post_id, '_' . $key);
                }
            }

            /* ⭐ Save repeatable fields with new meta_key */
            $section_meta_key = 'fac_custom_' . $index;

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
            /* Flight Infrastructure Facilities */
            'امکانات زیرساخت پرواز' => [
                'airport_runways'        => 'باندهای پرواز',
                'airport_taxiways'       => 'تاکسی‌وی‌ها',
                'airport_hangars'        => 'آشیانه هواپیما',
                'airport_control_tower'  => 'برج مراقبت پرواز',
                'airport_navigation'     => 'سیستم‌های ناوبری و فرود',
                'airport_lighting'       => 'سیستم‌های روشنایی باند',
                'airport_apron'          => 'محل پارک هواپیما (اپرون)',
                'airport_ground_support' => 'تجهیزات پشتیبانی زمینی (GSE)',
            ],

            /* Passenger Terminal Facilities */
            'امکانات ترمینال مسافری' => [
                'airport_terminal_entry_exit' => 'سالن ورودی و خروجی',
                'airport_boarding_gates'      => 'گیت‌های سوار و پیاده شدن',
                'airport_jet_bridges'         => 'پل‌های تلسکوپی',
                'airport_checkin_counters'    => 'کانترهای پذیرش بار',
                'airport_baggage_conveyor'    => 'نوار نقاله بار',
                'airport_baggage_claim'       => 'منطقه تحویل بار',
                'airport_waiting_halls'       => 'سالن انتظار',
                'airport_transit_area'        => 'منطقه ترانزیت بین‌المللی',
                'airport_vip_cip'             => 'سالن VIP/CIP',
                'airport_lounges'             => 'لانج مسافری (بیزینس/فرست کلاس)',
                'airport_crew_room'           => 'اتاق استراحت خدمه',
                'airport_medical_center'      => 'مرکز درمانی / اورژانس',
                'airport_customs'             => 'گمرک و گذرنامه',
            ],

            /* Transportation & Parking Facilities */
            'امکانات حمل‌ونقل و پارکینگ' => [
                'airport_parking_short'      => 'پارکینگ کوتاه‌مدت',
                'airport_parking_long'       => 'پارکینگ بلندمدت',
                'airport_taxi_bus_parking'   => 'پارکینگ تاکسی و اتوبوس',
                'airport_special_taxi_lane'  => 'مسیر تاکسی‌ویژه',
                'airport_public_transport'   => 'ایستگاه مترو/قطار/اتوبوس',
                'airport_ev_charging'        => 'ایستگاه شارژ خودرو برقی',
                'airport_valet_parking'      => 'پارکینگ با خدمات ولو (Valet)',
                'airport_helicopter_pad'     => 'محل فرود بالگرد (هلی‌پد)',
            ],

            /* Support & Operational Facilities */
            'امکانات پشتیبانی و عملیاتی' => [
                'airport_fuel_facilities' => 'تأسیسات سوخت‌رسانی',
                'airport_cargo_handling'  => 'سیستم بارگیری و تخلیه بار',
                'airport_cold_storage'    => 'سردخانه بار',
                'airport_warehouse'       => 'انبار بار و کالا',
                'airport_mail_center'     => 'مرکز پست و بسته‌ها',
                'airport_fire_station'    => 'ایستگاه آتش‌نشانی',
                'airport_power_backup'    => 'ژنراتور و برق اضطراری',
                'airport_hvac'            => 'سیستم تهویه و سرمایش/گرمایش',
                'airport_water_system'    => 'سیستم تأمین آب و فاضلاب',
                'airport_waste_management'=> 'مدیریت پسماند',
            ],

            /* Security Facilities */
            'امکانات امنیتی' => [
                'airport_security_gates'   => 'گیت‌های کنترل امنیتی',
                'airport_cctv'             => 'دوربین‌های مدار بسته',
                'airport_police_station'   => 'ایستگاه پلیس فرودگاهی',
                'airport_quarantine'       => 'اتاق قرنطینه',
                'airport_prohibited_items' => 'انبار وسایل ممنوعه',
                'airport_sniffer_dogs'     => 'سگ‌های مواد یاب / بمب یاب',
            ],

            /* Public Welfare Facilities */
            'امکانات رفاهی عمومی' => [
                'airport_restrooms'       => 'سرویس‌های بهداشتی',
                'airport_prayer_room'     => 'نمازخانه / عبادتگاه',
                'airport_mother_child'    => 'اتاق مادر و کودک',
                'airport_kids_play_area'  => 'زمین بازی کودکان',
                'airport_conference_room' => 'سالن کنفرانس و جلسات',
                'airport_airport_hotel'   => 'هتل فرودگاهی',
                'airport_spa'             => 'اسپا و ماساژ',
                'airport_smoking_room'    => 'اتاق سیگار',
            ],

            /* Technology & Communications Facilities */
            'امکانات فناوری و ارتباطات' => [
                'airport_fids'            => 'سیستم نمایش اطلاعات پرواز (FIDS)',
                'airport_digital_signage' => 'تابلوهای دیجیتال راهنما',
                'airport_wifi'            => 'اینترنت وای‌فای',
                'airport_pa_system'       => 'سیستم اعلام عمومی (PA)',
                'airport_self_checkin'    => 'کیوسک‌های سلف‌چک‌این',
                'airport_mobile_app'      => 'اپلیکیشن فرودگاهی',
                'airport_smart_gates'     => 'گیت‌های هوشمند عبور سریع',
            ],
        ];
    }
}