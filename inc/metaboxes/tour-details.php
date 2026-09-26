<?php

namespace NextSafar\MetaBoxes;

class TourDetails {
    public static function register() {
        add_meta_box(
            'nextsafar_tour_details',
            '🚐 حمل‌ونقل، امکانات، مدارک و برنامه سفر',
            [__CLASS__, 'render'],
            'tour',
            'normal',
            'default'
        );
    }

    public static function register_hooks() {
        add_action('save_post_tour', [__CLASS__, 'save'], 10, 2);
    }

    public static function render($post) {
        wp_nonce_field('nextsafar_tour_details', 'nextsafar_tour_details_nonce');

        require NEXTSAFAR_PATH . 'views/metaboxes/tour-details.php';
    }

    public static function save($post_id, $post) {
        if (!isset($_POST['nextsafar_tour_details_nonce']) ||
            !wp_verify_nonce($_POST['nextsafar_tour_details_nonce'], 'nextsafar_tour_details')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        /* Transport */
        $transport_fields = [
            'tour_transport_type', 'tour_airline', 'tour_train_name',
            'tour_ship_name', 'tour_bus_name',
        ];

        foreach ($transport_fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, '_' . $field, sanitize_text_field($_POST[$field]));
            }
        }

        /* Features (checkboxes) */
        $features = self::get_features();
        $selected_features = [];

        foreach ($features as $key => $label) {
            if (isset($_POST['tour_features'][$key])) {
                $selected_features[$key] = 1;
            }
        }

        update_post_meta($post_id, '_tour_features', $selected_features);

        /* Required documents (checkboxes) */
        $documents = self::get_documents();
        $selected_documents = [];

        foreach ($documents as $key => $label) {
            if (isset($_POST['tour_documents'][$key])) {
                $selected_documents[$key] = 1;
            }
        }

        update_post_meta($post_id, '_tour_documents', $selected_documents);

        /* Itinerary (repeatable) */
        if (isset($_POST['tour_itinerary']) && is_array($_POST['tour_itinerary'])) {
            $itinerary = [];

            foreach ($_POST['tour_itinerary'] as $day => $content) {
                $content = sanitize_textarea_field($content);

                if (!empty(trim($content))) {
                    $itinerary[$day] = $content;
                }
            }

            update_post_meta($post_id, '_tour_itinerary', $itinerary);
        } else {
            delete_post_meta($post_id, '_tour_itinerary');
        }
    }

    public static function get_transport_options() {
        return [
            'iran_airlines' => [
                'ata air'       => 'آتا ایر',
                'iran airtour'  => 'ایران ایرتور',
                'iran air'      => 'ایران ایر',
                'karun air'     => 'کارون ایر',
                'kish air'      => 'کیش ایر',
                'mahan air'     => 'ماهان ایر',
                'meraj air'     => 'معراج ایر',
                'naft air'      => 'نفت ایر',
                'pars air'      => 'پارس ایر',
                'qasem air'     => 'قشم ایر',
                'saha air'      => 'ساها ایر',
                'safiran air'   => 'سفیران',
                'sepehran air'  => 'سپهران ایر',
                'taban air'     => 'تابان ایر',
                'zagros air'    => 'زاگرس ایر',
                'fly persia'    => 'فلای پرشیا',
                'varesh air'    => 'وارش',
            ],
            'foreign_airlines' => [
                'aeroflot'          => 'آئروفلوت',
                'air arabia'        => 'ایرعربیا',
                'atlas global'      => 'اطلس گلوبال',
                'azal'              => 'آزال (آذربایجان)',
                'austrian airlines' => 'آسترین ایرلاینز',
                'bahrain gulf air'  => 'گلف ایر (بحرین)',
                'china southern'    => 'چاینا ساترن',
                'emirates'          => 'امارات',
                'flydubai'          => 'فلای دبی',
                'iraqi airways'     => 'هواپیمایی عراق',
                'kuwait airways'    => 'کویت ایرویز',
                'lufthansa'         => 'لوفت‌هانزا',
                'pegasus airlines'  => 'پگاسوس',
                'qatar airways'     => 'قطر ایرویز',
                'salam air'         => 'سلام ایر',
                'sunexpress'        => 'سان اکسپرس',
                'turkish airlines'  => 'ترکیش ایرلاینز',
                'tailwind air'      => 'تیلویند ایر',
                'ura air'           => 'یواِر‌اِی',
                'wizz air'          => 'ویز ایر',
            ],
            'trains' => [
                'raja'        => 'رجا',
                'fadak'       => 'فدک',
                'saba'        => 'صبا',
                'zayanderood' => 'زاینده‌رود',
                'ghadir'      => 'قدیر',
                'parsian'     => 'پارسیان',
            ],
            'ships' => [
                'kish star'   => 'کیش استار',
                'khalij fars' => 'خلیج فارس',
                'navid'       => 'نوید دریا',
                'sahel'       => 'ساحل ترابر',
            ],
            'buses' => [
                'siro safar'  => 'سیر و سفر',
                'royal safar' => 'رویال سفر',
                'iran peyma'  => 'ایران پیما',
                'safiran'     => 'سفیران',
                'hamsafar'    => 'همسفر',
                'ghazal'      => 'غزال',
            ],
        ];
    }

    public static function get_features() {
        return [
            'flight'           => 'بلیط رفت و برگشت',
            'transfer'         => 'ترانسفر از فرودگاه به هتل و بالعکس',
            'guide'            => 'لیدر فارسی زبان',
            'breakfast'        => 'صبحانه',
            'full_board'       => 'صبحانه ناهار شام',
            'travel_insurance' => 'بیمه مسافرتی',
            'city_lunch'       => 'یک گشت شهری با ناهار',
            'city_tour_hfd'    => 'گشت شهری نیم روزه',
            'hotel_bb'         => 'اقامت در هتل با صبحانه',
            'sim_card_free'    => 'سیمکارت به ازای هر اتاق یک عدد',
            'bb_hf_fb'         => 'خدمات غذایی طبق هتل انتخابی می باشد',
        ];
    }

    public static function get_documents() {
        return [
            'passport'    => 'گذرنامه',
            'photo'       => 'عکس ۳ در ۴',
            'shenasnameh' => 'شناسنامه',
            'meli_card'   => 'کارت ملی',
            'sh_id_card'  => 'اسکن تمام صفحات شناسنامه و اسکن کارت ملی',
            'expass8'     => 'اسکن پاسپورت با ۸ ماه اعتبار',
            'expass7'     => 'اسکن پاسپورت با ۷ ماه اعتبار',
            'expass6'     => 'اسکن پاسپورت با ۶ ماه اعتبار',
        ];
    }
}