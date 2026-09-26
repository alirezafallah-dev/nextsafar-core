<?php

namespace NextSafar\MetaBoxes;

use NextSafar\Sync\GeoSchema;

if (!defined('ABSPATH')) exit;

class GeoMetaBox {
    const TEXTAREA_FIELDS = [
        'instructions', 'special_instructions', 'know_before',
        'mandatory_fees', 'optional_fees', 'pet_policy',
    ];

    const OPTION_FIELDS = [
        'hotel' => [
            'stars'    => ['' => 'انتخاب', '1' => '۱ ستاره', '2' => '۲ ستاره', '3' => '۳ ستاره', '4' => '۴ ستاره', '5' => '۵ ستاره'],
            'checkin'  => ['' => 'انتخاب', '12:00' => '12:00', '13:00' => '13:00', '14:00' => '14:00', '15:00' => '15:00', '16:00' => '16:00'],
            'checkout' => ['' => 'انتخاب', '10:00' => '10:00', '11:00' => '11:00', '12:00' => '12:00', '14:00' => '14:00'],
        ],
        'airport' => [
            'type' => ['' => 'انتخاب کنید', '1' => 'بین‌المللی', '2' => 'داخلی', '3' => 'نظامی', '4' => 'غیره'],
        ],
        'hospital' => [
            'type' => ['' => 'انتخاب کنید', '1' => 'دولتی', '2' => 'تخصصی', '3' => 'عمومی', '4' => 'خصوصی', '5' => 'غیره'],
        ],
        'restaurant' => [
            'type' => [
                '' => 'انتخاب کنید', 'restaurant' => 'رستوران', 'traditional' => 'رستوران سنتی',
                'fast_food' => 'فست فود', 'cafe' => 'کافه', 'coffee_shop' => 'کافی شاپ تخصصی',
                'coffe_restaurant' => 'کافه رستوران', 'street_food' => 'غذای خیابانی', 'bakery' => 'نان و شیرینی',
                'bar' => 'بار و لانژ', 'seafood' => 'غذای دریایی', 'steakhouse' => 'استیک‌هاوس',
                'buffet' => 'بوفه', 'fine_dining' => 'رستوران لوکس',
            ],
        ],
        'destination' => [
            'type' => [
                '' => 'انتخاب کنید', 'historical' => 'مکان‌های تاریخی و آثار باستانی',
                'cultural' => 'مکان‌های فرهنگی و هنری', 'natural' => 'جاذبه‌های طبیعی',
                'beach_recreational' => 'تفریحی و ساحلی', 'urban' => 'مکان‌های شهری و مدرن',
                'religious' => 'مکان‌های مذهبی', 'aquarium_zoo' => 'آکواریوم و باغ‌وحش',
                'parks_gardens' => 'پارک‌ها و باغ‌ها', 'adventure' => 'تفریحات ماجراجویانه',
                'gastronomy' => 'گردشگری غذایی', 'events_festivals' => 'رویدادها و جشنواره‌ها',
            ],
        ],
    ];

    const NUMBER_FIELDS = ['rating', 'reviews', 'terminals', 'min_age', 'price_level', 'icao'];

    const LABELS = [
        'name_en' => 'نام انگلیسی (کلید سینک)', 'address' => 'آدرس', 'city' => 'شهر (EN)',
        'country' => 'کشور (EN)', 'postal' => 'کد پستی', 'place_id' => 'Google Place ID',
        'phone' => 'تلفن', 'website' => 'وب‌سایت', 'lat' => 'عرض جغرافیایی (lat)', 'lng' => 'طول جغرافیایی (lng)',
        'parto_id' => '🔗 Parto Hotel ID', 'property_token' => 'Property Token',
        'stars' => 'ستاره', 'rating' => 'امتیاز (0-5)', 'reviews' => 'تعداد نظرات',
        'checkin' => 'ساعت ورود', 'checkout' => 'ساعت خروج',
        'instructions' => 'قوانین (Instructions)', 'special_instructions' => 'دستورالعمل ویژه',
        'know_before' => 'قبل از سفر بدانید', 'mandatory_fees' => 'هزینه‌های اجباری',
        'optional_fees' => 'هزینه‌های اختیاری', 'pet_policy' => 'قوانین حیوانات', 'min_age' => 'حداقل سن',
        'iata' => 'کد IATA', 'icao' => 'کد ICAO', 'terminals' => 'ترمینال‌ها', 'type' => 'نوع',
        'price_level' => 'سطح قیمت', 'emergency' => 'تلفن اورژانس ۲۴ساعته', 'wikipedia' => 'لینک ویکی‌پدیا',
    ];

    /* Dedicated fields per post type */
    const TYPE_SECTIONS = [
        'hotel'       => ['stars', 'rating', 'reviews', 'checkin', 'checkout', 'min_age'],
        'airport'     => ['iata', 'icao', 'terminals', 'type'],
        'restaurant'  => ['type', 'price_level', 'rating', 'reviews'],
        'hospital'    => ['type', 'emergency', 'rating', 'reviews'],
        'destination' => ['type', 'rating', 'reviews', 'wikipedia'],
    ];

    public static function register() {
        foreach (GeoSchema::GEO_POST_TYPES as $pt) {
            add_meta_box('ns_geo_sync', '📍 اطلاعات مکانی و سینک', [__CLASS__, 'render'], $pt, 'normal', 'high');
        }
    }

    public static function register_hooks() {
        add_action('add_meta_boxes', [__CLASS__, 'register']);
        add_action('save_post', [__CLASS__, 'save'], 20);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function enqueue_assets($hook) {
        global $post_type;

        if (($hook !== 'post.php' && $hook !== 'post-new.php') || !in_array($post_type, GeoSchema::GEO_POST_TYPES, true)) return;

        wp_enqueue_style('nextsafar-geo-admin', NEXTSAFAR_URL . 'assets/geo.css', [], NEXTSAFAR_VERSION);
    }

    /* ─── Render helpers ─── */
    private static function label($f) {
        return self::LABELS[$f] ?? $f;
    }

    private static function field_text($post_id, $f, $full = false) {
        echo '<div class="ns-field' . ($full ? ' ns-field-full' : '') . '"><label>' . esc_html(self::label($f)) . '</label>'
            . '<input class="ns-input" type="text" name="ns_geo[' . esc_attr($f) . ']" value="' . esc_attr(GeoSchema::get($post_id, $f)) . '" dir="ltr"></div>';
    }

    private static function field_number($post_id, $f) {
        echo '<div class="ns-field"><label>' . esc_html(self::label($f)) . '</label>'
            . '<input class="ns-input" type="number" step="0.1" min="0" name="ns_geo[' . esc_attr($f) . ']" value="' . esc_attr(GeoSchema::get($post_id, $f)) . '" dir="ltr"></div>';
    }

    private static function field_select($post_id, $type, $f) {
        $val = GeoSchema::get($post_id, $f);

        echo '<div class="ns-field"><label>' . esc_html(self::label($f)) . '</label><select class="ns-input" name="ns_geo[' . esc_attr($f) . ']">';

        foreach (self::OPTION_FIELDS[$type][$f] as $k => $lbl) {
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($val, $k, false), esc_html($lbl));
        }

        echo '</select></div>';
    }

    private static function field_textarea($post_id, $f) {
        echo '<div class="ns-field ns-field-full"><label>' . esc_html(self::label($f)) . '</label>'
            . '<textarea class="ns-textarea" rows="3" name="ns_geo[' . esc_attr($f) . ']" dir="ltr">' . esc_textarea(GeoSchema::get($post_id, $f)) . '</textarea></div>';
    }

    public static function render($post) {
        wp_nonce_field('ns_geo_save', 'ns_geo_nonce');

        $type = get_post_type($post);

        [$lat, $lng] = GeoSchema::get_latlng($post->ID);
        ?>
        <div class="nextsafar-geo-metabox">
            <!-- ═══ 🌍 Geographic Information ═══ -->
            <div class="ns-section">
                <h3 class="ns-section-title">🌍 اطلاعات مکانی</h3>
                <div class="ns-grid ns-grid-2">
                    <?php
                    self::field_text($post->ID, 'name_en');
                    self::field_text($post->ID, 'place_id');
                    self::field_text($post->ID, 'city');
                    self::field_text($post->ID, 'country');
                    self::field_text($post->ID, 'postal');

                    echo '<div class="ns-field"><label>مختصات (lat , lng)</label><div class="ns-coords-row">';
                    echo '<input class="ns-input" type="text" name="ns_geo[lat]" value="' . esc_attr($lat) . '" placeholder="lat" dir="ltr">';
                    echo '<input class="ns-input" type="text" name="ns_geo[lng]" value="' . esc_attr($lng) . '" placeholder="lng" dir="ltr">';
                    echo '</div></div>';

                    self::field_text($post->ID, 'address', true);
                    ?>
                </div>
            </div>

            <!-- ═══ 📞 Contact and Web ═══ -->
            <div class="ns-section">
                <h3 class="ns-section-title">📞 تماس و وب</h3>
                <div class="ns-grid ns-grid-2">
                    <?php
                    self::field_text($post->ID, 'phone');
                    self::field_text($post->ID, 'website');
                    ?>
                </div>
            </div>

            <!-- ═══ ⚙️ Type-Specific Information ═══ -->
            <div class="ns-section">
                <h3 class="ns-section-title">⚙️ اطلاعات اختصاصی</h3>
                <div class="ns-grid ns-grid-3">
                    <?php
                    foreach (self::TYPE_SECTIONS[$type] ?? [] as $f) {
                        if (isset(self::OPTION_FIELDS[$type][$f])) {
                            self::field_select($post->ID, $type, $f);
                        } elseif (in_array($f, self::NUMBER_FIELDS, true)) {
                            self::field_number($post->ID, $f);
                        } else {
                            self::field_text($post->ID, $f);
                        }
                    }
                    ?>
                </div>

                <?php if ('hotel' === $type) : ?>
                    <div class="ns-grid ns-grid-2" style="margin-top:15px;">
                        <?php foreach (self::TEXTAREA_FIELDS as $f) self::field_textarea($post->ID, $f); ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ═══ 🔗 Sync and Data Source ═══ -->
            <div class="ns-section ns-sync-section">
                <h3 class="ns-section-title">🔗 سینک و منبع داده</h3>
                <div class="ns-grid ns-grid-3">
                    <?php if ('hotel' === $type) : ?>
                        <?php self::field_text($post->ID, 'parto_id'); ?>
                        <?php self::field_text($post->ID, 'property_token'); ?>
                    <?php endif; ?>

                    <div class="ns-field">
                        <label>منبع داده</label>
                        <select class="ns-input" name="ns_geo[source]">
                            <?php
                            $source = GeoSchema::get($post->ID, 'source');

                            foreach (['manual' => 'دستی', 'searchapi' => 'SearchApi', 'google' => 'Google Places', 'parto' => 'Parto CRS'] as $k => $l) {
                                printf('<option value="%s" %s>%s</option>', $k, selected($source, $k, false), esc_html($l));
                            }
                            ?>
                        </select>
                    </div>

                    <div class="ns-field">
                        <label>شناسه خارجی (External ID)</label>
                        <input class="ns-input ns-readonly" type="text" value="<?php echo esc_attr(GeoSchema::get($post->ID, 'external_id') ?: '—'); ?>" readonly dir="ltr">
                    </div>

                    <div class="ns-field">
                        <label>آخرین سینک</label>
                        <input class="ns-input ns-readonly" type="text" value="<?php echo esc_attr(GeoSchema::get($post->ID, 'last_sync') ?: '—'); ?>" readonly dir="ltr">
                    </div>
                </div>

                <p class="ns-sync-hint">ℹ️ این بخش فقط توسط سیستم سینک (Parto / Google Places) یا به‌صورت دستی پر می‌شود؛ فیلدهای خاکستری قابل ویرایش نیستند.</p>
            </div>
        </div>
        <?php
    }

    public static function save($post_id) {
        if (!isset($_POST['ns_geo_nonce']) || !wp_verify_nonce($_POST['ns_geo_nonce'], 'ns_geo_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (empty($_POST['ns_geo']) || !is_array($_POST['ns_geo'])) return;

        $data = wp_unslash($_POST['ns_geo']);

        foreach ($data as $f => $v) {
            if (in_array($f, ['lat', 'lng', 'source'], true)) continue;

            $clean = in_array($f, self::TEXTAREA_FIELDS, true) ? wp_kses_post($v) : sanitize_text_field($v);

            GeoSchema::set($post_id, sanitize_key($f), $clean);
        }

        if (isset($data['lat']) || isset($data['lng'])) {
            [$ol, $og] = GeoSchema::get_latlng($post_id);

            GeoSchema::set_latlng($post_id, $data['lat'] ?? $ol, $data['lng'] ?? $og);
        }

        if (isset($data['source'])) {
            GeoSchema::set($post_id, 'source', sanitize_key($data['source']));
            GeoSchema::set($post_id, 'last_sync', current_time('mysql'));
        }
    }
}