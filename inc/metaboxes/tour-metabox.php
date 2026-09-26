<?php

namespace NextSafar\MetaBoxes;

class TourMetaBox {
    public static function register() {
        add_meta_box(
            'nextsafar_tour_info',
            '💰 قیمت‌گذاری و نوع تور',
            [__CLASS__, 'render_main'],
            'tour',
            'normal',
            'high'
        );

        add_meta_box(
            'nextsafar_tour_accommodation',
            '🏨 مقاصد و اقامت‌ها',
            [__CLASS__, 'render_accommodation'],
            'tour',
            'normal',
            'default'
        );
    }

    public static function register_hooks() {
        add_action('save_post_tour', [__CLASS__, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function enqueue_assets($hook) {
        global $post_type;

        if (($hook !== 'post.php' && $hook !== 'post-new.php') || $post_type !== 'tour') {
            return;
        }

        wp_enqueue_style(
            'nextsafar-tour-admin',
            NEXTSAFAR_URL . 'assets/tour.css',
            [],
            NEXTSAFAR_VERSION
        );

        wp_enqueue_script(
            'nextsafar-tour-admin',
            NEXTSAFAR_URL . 'assets/tour.js',
            ['jquery'],
            NEXTSAFAR_VERSION,
            true
        );
    }

    public static function render_main($post) {
        wp_nonce_field('nextsafar_tour_main', 'nextsafar_tour_main_nonce');

        $price_single = get_post_meta($post->ID, '_tour_price_single', true);
        $price_double = get_post_meta($post->ID, '_tour_price_double', true);
        $price_child  = get_post_meta($post->ID, '_tour_price_child', true);
        $price_infant = get_post_meta($post->ID, '_tour_price_infant', true);
        $discount     = get_post_meta($post->ID, '_tour_discount', true);
        $tour_type    = get_post_meta($post->ID, '_tour_type', true);
        ?>
        <div class="nextsafar-tour-metabox">
            <div class="ns-section">
                <h3 class="ns-section-title">💰 قیمت‌گذاری و نوع تور</h3>

                <p class="description">
                    فیلدهای نام انگلیسی، کشور، شهر، تاریخ رفت، تاریخ برگشت، مدت و واحد ارزی حذف شدند.
                </p>

                <div class="ns-field">
                    <label for="tour_price_single">قیمت انفرادی</label>
                    <input type="text"
                           id="tour_price_single"
                           name="tour_price_single"
                           value="<?= esc_attr($price_single); ?>"
                           class="ns-input ns-price-input">
                </div>

                <div class="ns-field">
                    <label for="tour_price_double">قیمت دونفره</label>
                    <input type="text"
                           id="tour_price_double"
                           name="tour_price_double"
                           value="<?= esc_attr($price_double); ?>"
                           class="ns-input ns-price-input">
                </div>

                <div class="ns-field">
                    <label for="tour_price_child">قیمت کودک</label>
                    <input type="text"
                           id="tour_price_child"
                           name="tour_price_child"
                           value="<?= esc_attr($price_child); ?>"
                           class="ns-input ns-price-input">
                </div>

                <div class="ns-field">
                    <label for="tour_price_infant">قیمت نوزاد</label>
                    <input type="text"
                           id="tour_price_infant"
                           name="tour_price_infant"
                           value="<?= esc_attr($price_infant); ?>"
                           class="ns-input ns-price-input">
                </div>

                <div class="ns-field">
                    <label for="tour_discount">تخفیف (٪)</label>
                    <input type="number"
                           id="tour_discount"
                           name="tour_discount"
                           value="<?= esc_attr($discount); ?>"
                           min="0"
                           max="100"
                           step="1"
                           class="ns-input">
                </div>

                <div class="ns-field">
                    <label for="tour_type">نوع تور</label>
                    <select id="tour_type" name="tour_type" class="ns-input">
                        <?php foreach (self::get_tour_types() as $key => $label): ?>
                            <option value="<?= esc_attr($key); ?>" <?= selected($tour_type, $key); ?>>
                                <?= esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <?php
    }

    public static function render_accommodation($post) {
        wp_nonce_field('nextsafar_tour_accommodation', 'nextsafar_tour_accommodation_nonce');

        $hotels = get_posts([
            'post_type'      => 'hotel',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $selected_hotel_id = get_post_meta($post->ID, '_tour_main_hotel', true);
        $repeat_items = get_post_meta($post->ID, '_tour_stays', true);

        if (!is_array($repeat_items)) $repeat_items = [];
        ?>
        <div class="nextsafar-tour-metabox">
            <div class="ns-section">
                <h3 class="ns-section-title">🏨 هتل اصلی تور</h3>

                <div class="ns-field">
                    <label for="tour_main_hotel">انتخاب هتل اصلی:</label>
                    <select id="tour_main_hotel" name="tour_main_hotel" class="ns-input">
                        <option value="">-- انتخاب کنید --</option>
                        <?php foreach ($hotels as $hotel) : ?>
                            <option value="<?= $hotel->ID; ?>" <?= selected($selected_hotel_id, $hotel->ID, false); ?>>
                                <?= esc_html($hotel->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="ns-section">
                <h3 class="ns-section-title">🌍 اقامت‌ها (تور ترکیبی)</h3>

                <div id="tour-stays-wrapper">
                    <?php foreach ($repeat_items as $index => $item) :
                        $selected_hotel = intval($item['hotel'] ?? 0);
                        $nights = esc_attr($item['nights'] ?? ''); ?>
                        <div class="ns-tour-stay-item" data-index="<?= $index; ?>">
                            <div class="ns-field" style="flex: 2;">
                                <label>هتل:</label>
                                <select name="tour_stays[<?= $index; ?>][hotel]" class="ns-input">
                                    <option value="">-- انتخاب کنید --</option>
                                    <?php foreach ($hotels as $hotel) : ?>
                                        <option value="<?= $hotel->ID; ?>" <?= selected($selected_hotel, $hotel->ID, false); ?>>
                                            <?= esc_html($hotel->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ns-field" style="flex: 1;">
                                <label>تعداد شب:</label>
                                <input type="number" name="tour_stays[<?= $index; ?>][nights]"
                                       value="<?= $nights; ?>" min="1" max="30" class="ns-input">
                            </div>

                            <button type="button" class="ns-remove-stay">✕</button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" id="add-tour-stay" class="ns-btn-add"
                        data-hotels='<?= esc_attr(json_encode(array_map(function($h) {
                            return ['id' => $h->ID, 'title' => $h->post_title];
                        }, $hotels))); ?>'>
                    ➕ افزودن اقامت جدید
                </button>
            </div>
        </div>
        <?php
    }

    public static function save($post_id, $post) {
        if (isset($_POST['nextsafar_tour_main_nonce']) &&
            wp_verify_nonce($_POST['nextsafar_tour_main_nonce'], 'nextsafar_tour_main')) {
            self::save_main_fields($post_id);
        }

        if (isset($_POST['nextsafar_tour_accommodation_nonce']) &&
            wp_verify_nonce($_POST['nextsafar_tour_accommodation_nonce'], 'nextsafar_tour_accommodation')) {
            self::save_accommodation($post_id);
        }
    }

    private static function save_main_fields($post_id) {
        /* ⭐ Remove fields that are no longer used in tour */
        $removed_fields = [
            'tour_name_en',
            'tour_country',
            'tour_city',
            'tour_departure_date',
            'tour_return_date',
            'tour_duration_days',
            'tour_duration_nights',
            'tour_currency',
        ];

        foreach ($removed_fields as $field) {
            delete_post_meta($post_id, '_' . $field);
            delete_post_meta($post_id, '_' . $field . '_source');
        }

        self::mark_manual_fields($post_id);

        /* Prices */
        $price_fields = [
            'tour_price_single',
            'tour_price_double',
            'tour_price_child',
            'tour_price_infant',
        ];

        foreach ($price_fields as $field) {
            if (isset($_POST[$field])) {
                $value = str_replace(',', '', $_POST[$field]);
                $numeric_value = is_numeric($value) ? intval($value) : 0;
                update_post_meta($post_id, '_' . $field, $numeric_value);
            }
        }

        /* Discount */
        if (isset($_POST['tour_discount'])) {
            update_post_meta($post_id, '_tour_discount', intval($_POST['tour_discount']));
        }

        /* Tour type */
        if (isset($_POST['tour_type'])) {
            update_post_meta($post_id, '_tour_type', sanitize_text_field($_POST['tour_type']));
        }
    }

    private static function save_accommodation($post_id) {
        if (isset($_POST['tour_main_hotel'])) {
            update_post_meta($post_id, '_tour_main_hotel', intval($_POST['tour_main_hotel']));
        }

        if (isset($_POST['tour_stays']) && is_array($_POST['tour_stays'])) {
            $sanitized_items = [];

            foreach ($_POST['tour_stays'] as $item) {
                $hotel_id = intval($item['hotel'] ?? 0);
                $nights = intval($item['nights'] ?? 0);

                if ($hotel_id > 0 || $nights > 0) {
                    $sanitized_items[] = [
                        'hotel'  => $hotel_id,
                        'nights' => $nights,
                    ];
                }
            }

            update_post_meta($post_id, '_tour_stays', $sanitized_items);
        } else {
            delete_post_meta($post_id, '_tour_stays');
        }
    }

    private static function mark_manual_fields($post_id) {
        /* Only prices that may be manually changed */
        $lockable = [
            'tour_price_single',
            'tour_price_double',
        ];

        foreach ($lockable as $field) {
            if (!isset($_POST[$field])) {
                continue;
            }

            $new_raw = str_replace(',', '', sanitize_text_field($_POST[$field]));
            $new_value = is_numeric($new_raw) ? (string) intval($new_raw) : '';
            $old_value = (string) (int) get_post_meta($post_id, '_' . $field, true);

            if ($new_value !== '' && $new_value !== $old_value) {
                update_post_meta($post_id, '_' . $field . '_source', 'manual');
            } elseif ($new_value === '') {
                delete_post_meta($post_id, '_' . $field . '_source');
            }
        }
    }

    public static function get_tour_types() {
        return [
            ''           => 'انتخاب کنید',
            'group'      => 'گروهی',
            'individual' => 'انفرادی',
            'private'    => 'خصوصی',
            'combined'   => 'ترکیبی',
        ];
    }

    public static function get_currencies() {
        return [
            ''      => '-- انتخاب کنید --',
            'TOMAN' => 'تومان',
            'IRR'   => 'ریال',
            'AED'   => 'درهم',
            'USD'   => 'دلار',
            'EUR'   => 'یورو',
            'TRY'   => 'لیر',
            'GBP'   => 'پوند',
        ];
    }
}