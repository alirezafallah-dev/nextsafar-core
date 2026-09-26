<?php

namespace NextSafar\Taxonomies;

class TourCategoryMeta {

    /* ========================================================================
       Initialization
       ======================================================================== */
    public static function init() {
        add_action('tour_category_add_form_fields', [__CLASS__, 'add_form_fields']);
        add_action('tour_category_edit_form_fields', [__CLASS__, 'edit_form_fields']);
        add_action('created_tour_category', [__CLASS__, 'save']);
        add_action('edited_tour_category', [__CLASS__, 'save']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        /* ⭐ Image column in admin table */
        add_filter('manage_edit-tour_category_columns', [__CLASS__, 'add_columns']);
        add_filter('manage_tour_category_custom_column', [__CLASS__, 'render_columns'], 10, 3);

        /* ⭐ REST API output for tour image and info */
        add_action('rest_api_init', [__CLASS__, 'register_rest_fields']);
    }

    /* ═══════════════════════════════════════════════════════════
       REST Fields: Category Image + Tour Info
       Output in /wp/v2/tour_category:
       {
         "ns_term_image": "https://.../photo.jpg",
         "ns_tour_info": { duration, departure, return, transport, currency }
       }
       ═══════════════════════════════════════════════════════════ */
    public static function register_rest_fields() {
        /* ⭐ Category image — both cases (Attachment ID and legacy URL) */
        register_rest_field('tour_category', 'ns_term_image', [
            'get_callback' => function ($term_arr) {
                $photo = get_term_meta($term_arr['id'], 'term_tour_photo', true);

                if (empty($photo)) return null;

                /* New case: Attachment ID */
                if (is_numeric($photo)) {
                    $url = wp_get_attachment_image_url((int) $photo, 'large');

                    if (!$url) {
                        $url = wp_get_attachment_url((int) $photo);
                    }

                    return $url ?: null;
                }

                /* Legacy case: direct URL */
                return esc_url_raw($photo);
            },
            'schema' => ['type' => 'string'],
        ]);

        /* ⭐ Tour info (duration, dates, transport, currency) */
        register_rest_field('tour_category', 'ns_tour_info', [
            'get_callback' => function ($term_arr) {
                $id = $term_arr['id'];

                return [
                    'duration'  => get_term_meta($id, 'duration', true),
                    'departure' => get_term_meta($id, 'departure_date', true),
                    'return'    => get_term_meta($id, 'return_date', true),
                    'transport' => get_term_meta($id, 'transport', true),
                    'airline'   => get_term_meta($id, 'airline', true),
                    'currency'  => get_term_meta($id, 'currency_unit', true),
                ];
            },
            'schema' => ['type' => 'object'],
        ]);
    }

    /* ========================================================================
       Image Column in Admin Table
       ======================================================================== */
    public static function add_columns($columns) {
        $new = [];

        foreach ($columns as $key => $val) {
            /* ✅ Image column, right before the category name */
            if ($key === 'name') {
                $new['tour_image'] = 'تصویر تور';
            }

            $new[$key] = $val;
        }

        unset($new['posts']); // Remove default column

        return $new;
    }

    public static function render_columns($content, $column_name, $term_id) {
        if ($column_name === 'tour_image') {
            $photo = get_term_meta($term_id, 'term_tour_photo', true);

            if (empty($photo)) return '—';

            $img_url = '';

            /* ✅ New case: stored value is Attachment ID */
            if (is_numeric($photo)) {
                $img_url = wp_get_attachment_image_url((int) $photo, 'thumbnail');
            }
            /* ✅ Legacy case: stored value is direct URL */
            elseif (filter_var($photo, FILTER_VALIDATE_URL)) {
                $img_url = $photo;
            }

            if ($img_url) {
                return '<img src="' . esc_url($img_url) . '" style="width:50px;height:50px;object-fit:cover;border-radius:4px;">';
            }

            return '—';
        }

        return $content;
    }

    /* ========================================================================
       Enqueue Assets
       ======================================================================== */
    public static function enqueue_assets($hook) {
        $screen = get_current_screen();

        if ($screen && $screen->taxonomy === 'tour_category') {
            wp_enqueue_media();

            // Persian date library
            wp_enqueue_script(
                'persian-date',
                'https://cdn.jsdelivr.net/npm/persian-date@1.1.0/dist/persian-date.min.js',
                ['jquery'],
                '1.1.0',
                true
            );

            // Styles
            wp_enqueue_style(
                'nextsafar-tour-category',
                NEXTSAFAR_URL . 'assets/tour-category.css',
                [],
                NEXTSAFAR_VERSION
            );

            // Current calendar — do not touch this
            wp_enqueue_script(
                'nextsafar-tour-category',
                NEXTSAFAR_URL . 'assets/tour-category.js',
                ['jquery', 'persian-date'],
                NEXTSAFAR_VERSION,
                true
            );

            // Fix noConflict issue
            wp_add_inline_script('persian-date', '
                if (typeof $ === "undefined" && typeof jQuery !== "undefined") {
                    var $ = jQuery;
                }
            ', 'before');
        }
    }

    /* ========================================================================
       Add Category Form
       ======================================================================== */
    public static function add_form_fields() {
        wp_nonce_field('nextsafar_tour_category', 'tour_category_nonce');
        ?>
        <!-- Category Image -->
        <div class="form-field term-image-wrap">
            <label>تصویر دسته‌بندی</label>
            <div>
                <button type="button" class="button ns-upload-image">📁 آپلود/انتخاب تصویر</button>
                <input type="hidden" name="term_tour_photo" value="" />
                <div class="ns-image-preview" style="margin-top:10px;"></div>
            </div>
        </div>

        <!-- ⭐ Departure and Return Dates with Custom Calendar -->
        <div class="form-field ns-date-range-field">
            <label>بازه زمانی تور</label>
            <div class="ns-date-range-container">
                <div class="ns-date-input-group">
                    <label for="tour_departure_date">تاریخ رفت:</label>
                    <input type="text" name="departure_date" id="tour_departure_date"
                        class="ns-persian-date" readonly placeholder="کلیک کنید برای انتخاب">
                    <input type="hidden" name="departure_date_en" id="tour_departure_date_en">
                </div>
                <div class="ns-date-input-group">
                    <label for="tour_return_date">تاریخ برگشت:</label>
                    <input type="text" name="return_date" id="tour_return_date"
                        class="ns-persian-date" readonly placeholder="کلیک کنید برای انتخاب">
                    <input type="hidden" name="return_date_en" id="tour_return_date_en">
                </div>
            </div>
            <div id="ns-range-status" class="ns-range-status"></div>
        </div>

        <!-- Duration -->
        <div class="form-field">
            <label for="duration">مدت اقامت <span id="ns-nights-display"></span></label>
            <input type="number" name="duration" id="duration"
                class="ns-duration-select"
                min="1" max="365"
                value="<?= isset($duration) ? esc_attr($duration) : ''; ?>"
                placeholder="تعداد شب">
            <p class="ns-duration-hint">با انتخاب بازه تاریخ، تعداد شب به صورت خودکار محاسبه می‌شود.</p>
        </div>

        <!-- Transport -->
        <div class="form-field">
            <label for="transport">حمل و نقل</label>
            <select name="transport" id="transport" class="ns-transport-select">
                <option value="">انتخاب کنید</option>
                <option value="هواپیما">هواپیما</option>
                <option value="قطار">قطار</option>
                <option value="کشتی">کشتی</option>
                <option value="اتوبوس">اتوبوس</option>
            </select>
        </div>

        <?php self::render_transport_fields(); ?>

        <!-- Currency Unit -->
        <div class="form-field">
            <label for="currency_unit">واحد ارزی</label>
            <select name="currency_unit" id="currency_unit">
                <option value="">-- انتخاب کنید --</option>
                <option value="TOMAN">تومان</option>
                <option value="IRR">ریال</option>
                <option value="AED">درهم</option>
                <option value="USD">دلار</option>
                <option value="EUR">یورو</option>
                <option value="TRY">لیر</option>
                <option value="GBP">پوند</option>
            </select>
        </div>

        <!-- Tour Services -->
        <div class="form-field">
            <label for="tour_services">خدمات تور - قیمت پرواز</label>
            <input type="text" name="tour_services" id="tour_services" value="شامل حمل و نقل، اقامت و خدمات تور">
        </div>

        <!-- Features -->
        <div class="form-field">
            <label>امکانات</label>
            <div class="ns-checkbox-group">
                <?php foreach (self::get_features() as $key => $label): ?>
                    <label style="display: block; margin: 5px 0;">
                        <input type="checkbox" name="features[<?= $key; ?>]" value="1"> <?= esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Other Services -->
        <div class="form-field">
            <label>دیگر خدمات تور</label>
            <div class="ns-repeatable-group" id="otherfeatures-fields">
                <input type="text" name="otherfeatures[]" style="width:100%; margin-bottom: 5px;">
            </div>
            <button type="button" class="button ns-add-otherfeatures">➕ افزودن خدمات جدید</button>
        </div>

        <!-- Required Documents -->
        <div class="form-field">
            <label>مدارک لازم</label>
            <div class="ns-checkbox-group">
                <?php foreach (self::get_documents() as $key => $label): ?>
                    <label style="display: block; margin: 5px 0;">
                        <input type="checkbox" name="documents[<?= $key; ?>]" value="1"> <?= esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Other Required Documents -->
        <div class="form-field">
            <label>دیگر مدارک موردنیاز</label>
            <div class="ns-repeatable-group" id="otherdocs-fields">
                <input type="text" name="otherdocs[]" style="width:100%; margin-bottom: 5px;">
            </div>
            <button type="button" class="button ns-add-otherdocs">➕ افزودن مدرک جدید</button>
        </div>

        <!-- Itinerary -->
        <div class="form-field">
            <label>برنامه سفر</label>
            <div class="ns-repeatable-group" id="itinerary-fields">
                <textarea name="itinerary[]" rows="3" style="width:100%; margin-bottom: 5px;"></textarea>
            </div>
            <button type="button" class="button ns-add-itinerary">➕ افزودن روز</button>
        </div>

        <!-- Tour Description -->
        <div class="form-field">
            <label for="tour_description">توضیحات و توصیه‌ها</label>
            <textarea name="tour_description" id="tour_description" rows="6" style="width:100%;"></textarea>
        </div>
        <?php
    }

    /* ========================================================================
       Edit Category Form
       ======================================================================== */
    public static function edit_form_fields($term) {
        $meta = get_term_meta($term->term_id);

        $term_photo = $meta['term_tour_photo'][0] ?? '';
        $photo_url = '';

        if (!empty($term_photo)) {
            // If stored value is Attachment ID
            if (is_numeric($term_photo)) {
                $photo_url = wp_get_attachment_image_url((int) $term_photo, 'thumbnail');
            }
            // If legacy value is URL
            else {
                $photo_url = $term_photo;
            }
        }

        $departure_date = $meta['departure_date'][0] ?? '';
        $departure_date_en = $meta['departure_date_en'][0] ?? '';
        $return_date = $meta['return_date'][0] ?? '';
        $return_date_en = $meta['return_date_en'][0] ?? '';
        $duration = $meta['duration'][0] ?? '';

        wp_nonce_field('nextsafar_tour_category', 'tour_category_nonce');
        ?>
        <!-- Category Image -->
        <tr class="form-field term-image-wrap">
            <th scope="row"><label>تصویر دسته‌بندی</label></th>
            <td>
                <button type="button" class="button ns-upload-image" <?= !empty($photo_url) ? 'style="display:none;"' : ''; ?>>
                    📁 آپلود/انتخاب تصویر
                </button>
                <input type="hidden" name="term_tour_photo" value="<?= !empty($photo_url) ? esc_attr($term_photo) : ''; ?>" />
                <div class="ns-image-preview" style="margin-top:10px;">
                    <?php if ($photo_url): ?>
                        <img src="<?= esc_url($photo_url); ?>" style="max-width:100px;">
                        <button type="button" class="button ns-remove-image" style="display:block; margin-top:5px;">✕</button>
                    <?php endif; ?>
                </div>
            </td>
        </tr>

        <!-- ⭐ Departure and Return Dates with Custom Calendar -->
        <tr class="form-field ns-date-range-field">
            <th scope="row"><label>بازه زمانی تور</label></th>
            <td>
                <div class="ns-date-range-container">
                    <div class="ns-date-input-group">
                        <label for="tour_departure_date">تاریخ رفت:</label>
                        <input type="text" name="departure_date" id="tour_departure_date"
                            class="ns-persian-date" readonly value="<?= esc_attr($departure_date); ?>" placeholder="کلیک کنید برای انتخاب">
                        <input type="hidden" name="departure_date_en" id="tour_departure_date_en" value="<?= esc_attr($departure_date_en); ?>">
                    </div>
                    <div class="ns-date-input-group">
                        <label for="tour_return_date">تاریخ برگشت:</label>
                        <input type="text" name="return_date" id="tour_return_date"
                            class="ns-persian-date" readonly value="<?= esc_attr($return_date); ?>" placeholder="کلیک کنید برای انتخاب">
                        <input type="hidden" name="return_date_en" id="tour_return_date_en" value="<?= esc_attr($return_date_en); ?>">
                    </div>
                </div>
                <div id="ns-range-status" class="ns-range-status"></div>
            </td>
        </tr>

        <!-- Duration -->
        <tr class="form-field">
            <th scope="row"><label for="duration">مدت اقامت <span id="ns-nights-display"></span></label></th>
            <td>
                <input type="number" name="duration" id="duration"
                    class="ns-duration-select"
                    min="1" max="365"
                    value="<?= isset($duration) ? esc_attr($duration) : ''; ?>"
                    placeholder="تعداد شب">
                <p class="ns-duration-hint">با انتخاب بازه تاریخ، تعداد شب به صورت خودکار محاسبه می‌شود.</p>
            </td>
        </tr>

        <!-- Transport -->
        <tr class="form-field">
            <th scope="row"><label for="transport">حمل و نقل</label></th>
            <td>
                <select name="transport" id="transport" class="ns-transport-select">
                    <option value="">انتخاب کنید</option>
                    <?php foreach (['هواپیما', 'قطار', 'کشتی', 'اتوبوس'] as $t): ?>
                        <option value="<?= $t; ?>" <?= selected($meta['transport'][0] ?? '', $t); ?>><?= $t; ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>

        <?php self::render_transport_fields_edit($term->term_id); ?>

        <!-- Currency Unit -->
        <tr class="form-field">
            <th scope="row"><label for="currency_unit">واحد ارزی</label></th>
            <td>
                <select name="currency_unit" id="currency_unit">
                    <option value="">-- انتخاب --</option>
                    <?php foreach (['TOMAN' => 'تومان', 'IRR' => 'ریال', 'AED' => 'درهم', 'USD' => 'دلار', 'EUR' => 'یورو', 'TRY' => 'لیر', 'GBP' => 'پوند'] as $code => $label): ?>
                        <option value="<?= $code; ?>" <?= selected($meta['currency_unit'][0] ?? '', $code); ?>><?= $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>

        <!-- Tour Services -->
        <tr class="form-field">
            <th scope="row"><label for="tour_services">خدمات تور - قیمت پرواز</label></th>
            <td><input type="text" name="tour_services" id="tour_services" value="<?= esc_attr($meta['tour_services'][0] ?? ''); ?>"></td>
        </tr>

        <!-- Features -->
        <tr class="form-field">
            <th scope="row"><label>امکانات</label></th>
            <td>
                <?php
                $features = maybe_unserialize($meta['features'][0] ?? []);
                foreach (self::get_features() as $key => $label): ?>
                    <label style="display: block; margin: 5px 0;">
                        <input type="checkbox" name="features[<?= $key; ?>]" value="1" <?= checked(isset($features[$key])); ?>> <?= esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
            </td>
        </tr>

        <!-- Other Services -->
        <tr class="form-field">
            <th scope="row"><label>دیگر خدمات تور</label></th>
            <td>
                <?php
                $otherfeatures = maybe_unserialize($meta['otherfeatures'][0] ?? []);
                if (empty($otherfeatures)) $otherfeatures = [''];
                ?>
                <div class="ns-repeatable-group" id="otherfeatures-fields">
                    <?php foreach ($otherfeatures as $item): ?>
                        <input type="text" name="otherfeatures[]" value="<?= esc_attr($item); ?>" style="width:100%; margin-bottom: 5px;">
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button ns-add-otherfeatures">➕ افزودن خدمات جدید</button>
            </td>
        </tr>

        <!-- Required Documents -->
        <tr class="form-field">
            <th scope="row"><label>مدارک لازم</label></th>
            <td>
                <?php
                $documents = maybe_unserialize($meta['documents'][0] ?? []);
                foreach (self::get_documents() as $key => $label): ?>
                    <label style="display: block; margin: 5px 0;">
                        <input type="checkbox" name="documents[<?= $key; ?>]" value="1" <?= checked(isset($documents[$key])); ?>> <?= esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
            </td>
        </tr>

        <!-- Other Required Documents -->
        <tr class="form-field">
            <th scope="row"><label>دیگر مدارک موردنیاز</label></th>
            <td>
                <?php
                $otherdocs = maybe_unserialize($meta['otherdocs'][0] ?? []);
                if (empty($otherdocs)) $otherdocs = [''];
                ?>
                <div class="ns-repeatable-group" id="otherdocs-fields">
                    <?php foreach ($otherdocs as $item): ?>
                        <input type="text" name="otherdocs[]" value="<?= esc_attr($item); ?>" style="width:100%; margin-bottom: 5px;">
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button ns-add-otherdocs">➕ افزودن مدرک جدید</button>
            </td>
        </tr>

        <!-- Itinerary -->
        <tr class="form-field">
            <th scope="row"><label>برنامه سفر</label></th>
            <td>
                <?php
                $itinerary = maybe_unserialize($meta['itinerary'][0] ?? []);
                if (empty($itinerary)) $itinerary = [''];
                ?>
                <div class="ns-repeatable-group" id="itinerary-fields">
                    <?php foreach ($itinerary as $day): ?>
                        <textarea name="itinerary[]" rows="3" style="width:100%; margin-bottom: 5px;"><?= esc_textarea($day); ?></textarea>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button ns-add-itinerary">➕ افزودن روز</button>
            </td>
        </tr>

        <!-- Tour Description -->
        <tr class="form-field">
            <th scope="row"><label for="tour_description">توضیحات و توصیه‌ها</label></th>
            <td><textarea name="tour_description" id="tour_description" rows="6" style="width:100%;"><?= esc_textarea($meta['tour_description'][0] ?? ''); ?></textarea></td>
        </tr>
        <?php
    }

    /* ========================================================================
       Transport Fields
       ======================================================================== */
    private static function render_transport_fields($term_id = 0) {
        $options = self::get_transport_options();

        $selected_transport = $term_id ? get_term_meta($term_id, 'transport', true) : '';
        $selected_airline = $term_id ? get_term_meta($term_id, 'airline', true) : '';
        $selected_train = $term_id ? get_term_meta($term_id, 'train_name', true) : '';
        $selected_ship = $term_id ? get_term_meta($term_id, 'ship_name', true) : '';
        $selected_bus = $term_id ? get_term_meta($term_id, 'bus_name', true) : '';
        ?>
        <!-- Airline -->
        <div class="form-field ns-transport-field" id="airline-field" style="display:<?= $selected_transport === 'هواپیما' ? 'block' : 'none'; ?>;">
            <label for="airline">ایرلاین</label>
            <select name="airline" id="airline">
                <option value="">-- انتخاب ایرلاین --</option>
                <optgroup label="ایرلاین‌های ایرانی">
                    <?php foreach ($options['iran_airlines'] as $key => $label): ?>
                        <option value="<?= esc_attr($key); ?>" <?= selected($selected_airline, $key); ?>><?= esc_html($label); ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="ایرلاین‌های خارجی">
                    <?php foreach ($options['foreign_airlines'] as $key => $label): ?>
                        <option value="<?= esc_attr($key); ?>" <?= selected($selected_airline, $key); ?>><?= esc_html($label); ?></option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
        </div>

        <!-- Train -->
        <div class="form-field ns-transport-field" id="train-field" style="display:<?= $selected_transport === 'قطار' ? 'block' : 'none'; ?>;">
            <label for="train_name">نام قطار</label>
            <select name="train_name" id="train_name">
                <option value="">-- انتخاب --</option>
                <?php foreach ($options['trains'] as $key => $label): ?>
                    <option value="<?= esc_attr($key); ?>" <?= selected($selected_train, $key); ?>><?= esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Ship -->
        <div class="form-field ns-transport-field" id="ship-field" style="display:<?= $selected_transport === 'کشتی' ? 'block' : 'none'; ?>;">
            <label for="ship_name">نام کشتی</label>
            <select name="ship_name" id="ship_name">
                <option value="">-- انتخاب --</option>
                <?php foreach ($options['ships'] as $key => $label): ?>
                    <option value="<?= esc_attr($key); ?>" <?= selected($selected_ship, $key); ?>><?= esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Bus -->
        <div class="form-field ns-transport-field" id="bus-field" style="display:<?= $selected_transport === 'اتوبوس' ? 'block' : 'none'; ?>;">
            <label for="bus_name">نام اتوبوس</label>
            <select name="bus_name" id="bus_name">
                <option value="">-- انتخاب --</option>
                <?php foreach ($options['buses'] as $key => $label): ?>
                    <option value="<?= esc_attr($key); ?>" <?= selected($selected_bus, $key); ?>><?= esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
    }

    /* ========================================================================
       Transport Fields for Edit Form
       ======================================================================== */
    private static function render_transport_fields_edit($term_id) {
        $options = self::get_transport_options();

        $selected_transport = get_term_meta($term_id, 'transport', true);
        $selected_airline   = get_term_meta($term_id, 'airline', true);
        $selected_train     = get_term_meta($term_id, 'train_name', true);
        $selected_ship      = get_term_meta($term_id, 'ship_name', true);
        $selected_bus       = get_term_meta($term_id, 'bus_name', true);
        ?>
        <!-- Airline -->
        <tr class="form-field ns-transport-field" id="airline-field" style="display:<?= $selected_transport === 'هواپیما' ? 'table-row' : 'none'; ?>;">
            <th scope="row"><label for="airline">ایرلاین</label></th>
            <td>
                <select name="airline" id="airline">
                    <option value="">-- انتخاب ایرلاین --</option>
                    <optgroup label="ایرلاین‌های ایرانی">
                        <?php foreach ($options['iran_airlines'] as $key => $label): ?>
                            <option value="<?= esc_attr($key); ?>" <?= selected($selected_airline, $key); ?>><?= esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="ایرلاین‌های خارجی">
                        <?php foreach ($options['foreign_airlines'] as $key => $label): ?>
                            <option value="<?= esc_attr($key); ?>" <?= selected($selected_airline, $key); ?>><?= esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
            </td>
        </tr>

        <!-- Train -->
        <tr class="form-field ns-transport-field" id="train-field" style="display:<?= $selected_transport === 'قطار' ? 'table-row' : 'none'; ?>;">
            <th scope="row"><label for="train_name">نام قطار</label></th>
            <td>
                <select name="train_name" id="train_name">
                    <option value="">-- انتخاب --</option>
                    <?php foreach ($options['trains'] as $key => $label): ?>
                        <option value="<?= esc_attr($key); ?>" <?= selected($selected_train, $key); ?>><?= esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>

        <!-- Ship -->
        <tr class="form-field ns-transport-field" id="ship-field" style="display:<?= $selected_transport === 'کشتی' ? 'table-row' : 'none'; ?>;">
            <th scope="row"><label for="ship_name">نام کشتی</label></th>
            <td>
                <select name="ship_name" id="ship_name">
                    <option value="">-- انتخاب --</option>
                    <?php foreach ($options['ships'] as $key => $label): ?>
                        <option value="<?= esc_attr($key); ?>" <?= selected($selected_ship, $key); ?>><?= esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>

        <!-- Bus -->
        <tr class="form-field ns-transport-field" id="bus-field" style="display:<?= $selected_transport === 'اتوبوس' ? 'table-row' : 'none'; ?>;">
            <th scope="row"><label for="bus_name">نام اتوبوس</label></th>
            <td>
                <select name="bus_name" id="bus_name">
                    <option value="">-- انتخاب --</option>
                    <?php foreach ($options['buses'] as $key => $label): ?>
                        <option value="<?= esc_attr($key); ?>" <?= selected($selected_bus, $key); ?>><?= esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <?php
    }

    /* ========================================================================
       Save
       ======================================================================== */
    public static function save($term_id) {
        if (!isset($_POST['tour_category_nonce']) ||
            !wp_verify_nonce($_POST['tour_category_nonce'], 'nextsafar_tour_category')) {
            return;
        }

        // Text fields
        $text_fields = ['departure_date', 'departure_date_en', 'return_date', 'return_date_en', 'duration', 'transport', 'airline', 'train_name', 'ship_name', 'bus_name', 'tour_services', 'currency_unit', 'term_tour_photo'];

        foreach ($text_fields as $field) {
            if (isset($_POST[$field])) {
                update_term_meta($term_id, $field, sanitize_text_field($_POST[$field]));
            } else {
                delete_term_meta($term_id, $field);
            }
        }

        // Features
        if (isset($_POST['features']) && is_array($_POST['features'])) {
            $features = array_fill_keys(array_map('sanitize_text_field', array_keys($_POST['features'])), 1);
            update_term_meta($term_id, 'features', $features);
        } else {
            delete_term_meta($term_id, 'features');
        }

        // Required Documents
        if (isset($_POST['documents']) && is_array($_POST['documents'])) {
            $documents = array_fill_keys(array_map('sanitize_text_field', array_keys($_POST['documents'])), 1);
            update_term_meta($term_id, 'documents', $documents);
        } else {
            delete_term_meta($term_id, 'documents');
        }

        // Other Services
        if (!empty($_POST['otherfeatures']) && is_array($_POST['otherfeatures'])) {
            $items = array_filter(array_map('sanitize_text_field', $_POST['otherfeatures']), function($item) {
                return !empty(trim($item));
            });
            update_term_meta($term_id, 'otherfeatures', $items ?: []);
        } else {
            delete_term_meta($term_id, 'otherfeatures');
        }

        // Other Documents
        if (!empty($_POST['otherdocs']) && is_array($_POST['otherdocs'])) {
            $items = array_filter(array_map('sanitize_text_field', $_POST['otherdocs']), function($item) {
                return !empty(trim($item));
            });
            update_term_meta($term_id, 'otherdocs', $items ?: []);
        } else {
            delete_term_meta($term_id, 'otherdocs');
        }

        // Itinerary
        if (!empty($_POST['itinerary']) && is_array($_POST['itinerary'])) {
            $items = array_filter(array_map('sanitize_textarea_field', $_POST['itinerary']), function($item) {
                return !empty(trim($item));
            });
            update_term_meta($term_id, 'itinerary', $items ?: []);
        } else {
            delete_term_meta($term_id, 'itinerary');
        }

        // Tour Description
        if (isset($_POST['tour_description'])) {
            update_term_meta($term_id, 'tour_description', sanitize_textarea_field($_POST['tour_description']));
        } else {
            delete_term_meta($term_id, 'tour_description');
        }
    }

    /* ========================================================================
       Data Lists
       ======================================================================== */
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

    public static function get_transport_options() {
        return [
            'iran_airlines' => [
                'ata air' => 'آتا ایر', 'iran airtour' => 'ایران ایرتور', 'iran air' => 'ایران ایر',
                'karun air' => 'کارون ایر', 'kish air' => 'کیش ایر', 'mahan air' => 'ماهان ایر',
                'meraj air' => 'معراج ایر', 'naft air' => 'نفت ایر', 'pars air' => 'پارس ایر',
                'qasem air' => 'قشم ایر', 'saha air' => 'ساها ایر', 'safiran air' => 'سفیران',
                'sepehran air' => 'سپهران ایر', 'taban air' => 'تابان ایر', 'zagros air' => 'زاگرس ایر',
                'fly persia' => 'فلای پرشیا', 'varesh air' => 'وارش',
            ],
            'foreign_airlines' => [
                'aeroflot' => 'آئروفلوت', 'air arabia' => 'ایرعربیا', 'atlas global' => 'اطلس گلوبال',
                'azal' => 'آزال (آذربایجان)', 'austrian airlines' => 'آسترین ایرلاینز',
                'bahrain gulf air' => 'گلف ایر (بحرین)', 'china southern' => 'چاینا ساترن',
                'emirates' => 'امارات', 'flydubai' => 'فلای دبی', 'iraqi airways' => 'هواپیمایی عراق',
                'kuwait airways' => 'کویت ایرویز', 'lufthansa' => 'لوفت‌هانزا',
                'pegasus airlines' => 'پگاسوس', 'qatar airways' => 'قطر ایرویز',
                'salam air' => 'سلام ایر', 'sunexpress' => 'سان اکسپرس',
                'turkish airlines' => 'ترکیش ایرلاینز', 'tailwind air' => 'تیلویند ایر',
                'ura air' => 'یواِر‌اِی', 'wizz air' => 'ویز ایر',
            ],
            'trains' => [
                'raja' => 'رجا', 'fadak' => 'فدک', 'saba' => 'صبا',
                'zayanderood' => 'زاینده‌رود', 'ghadir' => 'قدیر', 'parsian' => 'پارسیان',
            ],
            'ships' => [
                'kish star' => 'کیش استار', 'khalij fars' => 'خلیج فارس',
                'navid' => 'نوید دریا', 'sahel' => 'ساحل ترابر',
            ],
            'buses' => [
                'siro safar' => 'سیر و سفر', 'royal safar' => 'رویال سفر',
                'iran peyma' => 'ایران پیما', 'safiran' => 'سفیران',
                'hamsafar' => 'همسفر', 'ghazal' => 'غزال',
            ],
        ];
    }
}