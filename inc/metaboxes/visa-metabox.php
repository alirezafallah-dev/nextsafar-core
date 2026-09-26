<?php

namespace NextSafar\MetaBoxes;

class VisaMetaBox {
    /* ✅ Standard select options */
    const VISA_TYPES = [
        'سینگل', 'لیبل', 'الکترونیک', 'با دعوت‌نامه', 'بدون دعوت‌نامه',
        'یک ماهه', 'فوری', 'مولتی', 'توریستی', 'تجاری', 'عادی',
        'توریستی یکبار ورود', 'خدمات سفارت', 'توریستی الکترونیکی',
    ];

    const VISA_DURATIONS = ['10 روزه', '20 روزه', '1 ماهه', '2 ماهه', '3 ماهه', '58 روزه'];

    const VISA_PERSONS = ['بزرگسال', 'کودک'];

    public static function register() {
        add_meta_box(
            'nextsafar_visa_banner',
            '🏞️ عکس بنر ویزا',
            [__CLASS__, 'render_banner'],
            'visa',
            'side',
            'default'
        );

        add_meta_box(
            'nextsafar_visa_prices',
            '💰 قیمت‌های مختلف ویزا',
            [__CLASS__, 'render_prices'],
            'visa',
            'normal',
            'high'
        );

        add_meta_box(
            'nextsafar_visa_info',
            '📋 اطلاعات ویزا',
            [__CLASS__, 'render_info'],
            'visa',
            'normal',
            'default'
        );

        add_meta_box(
            'nextsafar_visa_documents',
            '📄 مدارک مورد نیاز ویزا',
            [__CLASS__, 'render_documents'],
            'visa',
            'normal',
            'default'
        );

        add_meta_box(
            'nextsafar_visa_description',
            '📝 توضیحات ویزا',
            [__CLASS__, 'render_description'],
            'visa',
            'normal',
            'default'
        );
    }

    public static function register_hooks() {
        add_action('save_post_visa', [__CLASS__, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('rest_api_init', [__CLASS__, 'register_rest_fields']); /* ✅ New: REST field for frontend */
    }

    public static function enqueue_assets($hook) {
        global $post_type;

        if (($hook !== 'post.php' && $hook !== 'post-new.php') || $post_type !== 'visa') {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_style(
            'nextsafar-visa-admin',
            NEXTSAFAR_URL . 'assets/visa.css',
            [],
            NEXTSAFAR_VERSION
        );

        $currencies = \NextSafar\Admin\Exchange::get_supported_currencies();

        wp_enqueue_script(
            'nextsafar-visa-admin',
            NEXTSAFAR_URL . 'assets/visa.js',
            ['jquery'],
            NEXTSAFAR_VERSION,
            true
        );

        wp_localize_script('nextsafar-visa-admin', 'NextSafarCurrencies', $currencies);
    }

    /* ========================================
       Visa Banner Image (No Changes)
       ======================================== */
    public static function render_banner($post) {
        wp_nonce_field('nextsafar_visa_meta', 'visa_meta_nonce');

        $banner = get_post_meta($post->ID, '_visa_banner', true);
        ?>
        <div class="ns-visa-banner-field">
            <input type="hidden" name="visa_banner" id="ns-visa-banner-input" value="<?= esc_attr($banner); ?>">

            <div class="ns-visa-banner-preview" id="ns-visa-banner-preview">
                <?php if ($banner) : ?>
                    <img src="<?= esc_url($banner); ?>" style="max-width:100%; height:auto; border-radius:6px;">
                <?php else : ?>
                    <div class="ns-visa-banner-placeholder">🏞️ بنری انتخاب نشده</div>
                <?php endif; ?>
            </div>

            <div class="ns-visa-banner-buttons" style="margin-top:10px;">
                <button type="button" class="button button-primary ns-visa-banner-upload">📁 انتخاب بنر</button>
                <button type="button" class="button ns-visa-banner-remove" <?= $banner ? '' : 'style="display:none;"'; ?>>✕ حذف</button>
            </div>
        </div>
        <?php
    }

    /* ========================================
       Visa Prices: Type/Duration/Person Are Now Selects
       ======================================== */
    public static function render_prices($post) {
        wp_nonce_field('nextsafar_visa_meta', 'visa_meta_nonce');

        $visa_prices = get_post_meta($post->ID, '_visa_prices', true);

        if (!is_array($visa_prices)) $visa_prices = [];

        $currencies = \NextSafar\Admin\Exchange::get_supported_currencies();
        ?>
        <div class="nextsafar-visa-metabox">
            <div class="ns-visa-prices-wrapper" id="visa-prices-wrapper">
                <?php foreach ($visa_prices as $index => $price_item) : ?>
                    <div class="ns-visa-price-group">
                        <!-- ✅ Visa Type: Select -->
                        <select name="visa_prices[<?= $index; ?>][type]">
                            <?= self::select_options(self::VISA_TYPES, (string) ($price_item['type'] ?? '')); ?>
                        </select>

                        <!-- ✅ Duration: Select -->
                        <select name="visa_prices[<?= $index; ?>][duration]">
                            <?= self::select_options(self::VISA_DURATIONS, (string) ($price_item['duration'] ?? '')); ?>
                        </select>

                        <!-- ✅ Person: Select -->
                        <select name="visa_prices[<?= $index; ?>][person]">
                            <?= self::select_options(self::VISA_PERSONS, (string) ($price_item['person'] ?? '')); ?>
                        </select>

                        <input type="text" name="visa_prices[<?= $index; ?>][price]" placeholder="قیمت">

                        <select name="visa_prices[<?= $index; ?>][currency]">
                            <?= self::currency_options($currencies, (string) ($price_item['currency'] ?? '')); ?>
                        </select>

                        <button type="button" class="ns-remove-visa-price">✕</button>
                    </div>
                <?php endforeach; ?>
            </div>

            <button type="button" class="ns-btn-add" id="add-visa-price">➕ افزودن قیمت جدید</button>
        </div>
        <?php
    }

    /* ========================================
       Visa Info (No Changes)
       ======================================== */
    public static function render_info($post) {
        $visa_issue = get_post_meta($post->ID, '_visa_issue', true);
        $visa_expiry = get_post_meta($post->ID, '_visa_expiry', true);
        ?>
        <div class="nextsafar-visa-metabox">
            <div class="ns-visa-info-grid">
                <div class="ns-field">
                    <label for="visa_issue">⏱️ زمان اخذ ویزا:</label>
                    <input type="text" name="visa_issue" value="<?= esc_attr($visa_issue); ?>"
                           class="ns-input" placeholder="مثلاً ۱۰ روز کاری">
                </div>

                <div class="ns-field">
                    <label for="visa_expiry">📅 اعتبار ویزا پس از صدور:</label>
                    <input type="text" name="visa_expiry" value="<?= esc_attr($visa_expiry); ?>"
                           class="ns-input" placeholder="مثلاً ۹۰ روز">
                </div>
            </div>
        </div>
        <?php
    }

    /* ========================================
       Visa Documents (No Changes)
       ======================================== */
    public static function render_documents($post) {
        $fields = self::get_visa_doc_fields();
        $saved_data = get_post_meta($post->ID, '_visa_docs', true);

        if (!is_array($saved_data)) $saved_data = [];
        ?>
        <div class="nextsafar-visa-metabox">
            <div class="ns-visa-docs-grid">
                <?php foreach ($fields as $field) :
                    $checked = (isset($saved_data[$field]['checked']) && $saved_data[$field]['checked'] === true) ? 'checked' : '';
                    $text = $saved_data[$field]['text'] ?? '';
                    ?>
                    <div class="ns-visa-doc-item">
                        <label class="ns-visa-doc-label">
                            <input type="checkbox" name="visa_docs[<?= esc_attr($field); ?>][checked]"
                                   value="1" <?= $checked; ?>>
                            <span><?= esc_html($field); ?></span>
                        </label>

                        <input type="text" name="visa_docs[<?= esc_attr($field); ?>][text]"
                               value="<?= esc_attr($text); ?>"
                               placeholder="توضیحات..."
                               class="ns-visa-doc-text">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    public static function render_description($post) {
        $content = get_post_meta($post->ID, '_visa_description', true);

        wp_editor($content, 'visa_description', [
            'textarea_name' => 'visa_description',
            'textarea_rows' => 10,
            'media_buttons' => true,
            'tinymce'       => true,
            'quicktags'     => true,
        ]);
    }

    /* ========================================
       Save (No Changes)
       ======================================== */
    public static function save($post_id, $post) {
        if (!isset($_POST['visa_meta_nonce']) || !wp_verify_nonce($_POST['visa_meta_nonce'], 'nextsafar_visa_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['visa_banner'])) {
            if (!empty($_POST['visa_banner'])) {
                update_post_meta($post_id, '_visa_banner', esc_url_raw($_POST['visa_banner']));
            } else {
                delete_post_meta($post_id, '_visa_banner');
            }
        }

        if (isset($_POST['visa_prices']) && is_array($_POST['visa_prices'])) {
            $prices = array_map(function ($item) {
                return [
                    'type'     => sanitize_text_field($item['type'] ?? ''),
                    'duration' => sanitize_text_field($item['duration'] ?? ''),
                    'person'   => sanitize_text_field($item['person'] ?? ''),
                    'price'    => sanitize_text_field($item['price'] ?? ''),
                    'currency' => sanitize_text_field($item['currency'] ?? ''),
                ];
            }, $_POST['visa_prices']);

            update_post_meta($post_id, '_visa_prices', $prices);
        } else {
            delete_post_meta($post_id, '_visa_prices');
        }

        if (isset($_POST['visa_issue'])) {
            update_post_meta($post_id, '_visa_issue', sanitize_text_field($_POST['visa_issue']));
        }

        if (isset($_POST['visa_expiry'])) {
            update_post_meta($post_id, '_visa_expiry', sanitize_text_field($_POST['visa_expiry']));
        }

        if (isset($_POST['visa_description'])) {
            update_post_meta($post_id, '_visa_description', wp_kses_post($_POST['visa_description']));
        }

        $fields = self::get_visa_doc_fields();

        if (isset($_POST['visa_docs'])) {
            $data = [];

            foreach ($fields as $field) {
                $checked = isset($_POST['visa_docs'][$field]['checked']);
                $text = isset($_POST['visa_docs'][$field]['text']) ? sanitize_text_field($_POST['visa_docs'][$field]['text']) : '';

                $data[$field] = ['checked' => $checked, 'text' => $text];
            }

            update_post_meta($post_id, '_visa_docs', $data);
        } else {
            update_post_meta($post_id, '_visa_docs', []);
        }
    }

    public static function get_visa_doc_fields() {
        return [
            'پاسپورت', 'عکس ۳ در ۴', 'شناسنامه', 'کارت ملی', 'بلیط پرواز',
            'ووچر هتل', 'بیمه مسافرتی', 'فرم اطلاعات', 'تمکن مالی', 'برنامه سفر',
            'آدرس میزبان', 'نامه اشتغال به کار', 'اصل و ترجمه سند ملکی', 'انگشت نگاری',
            'ضمانت بازگشت', 'پاسپورت قدیمی', 'گواهی اشتغال به تحصیل', 'دعوت نامه',
            'کارت پایان خدمت', 'واکسن', 'مدارک همسر (خانم)', 'رضایت‌نامه محضری',
        ];
    }

    /* ═══════════════════════════════════════════════════════
       ✅ Helper Functions
       ═══════════════════════════════════════════════════════ */

    /* Build select options - preserved old saved value is also kept */
    private static function select_options(array $options, string $current): string {
        $html = '<option value="">-- انتخاب کنید --</option>';

        if ($current !== '' && !in_array($current, $options, true)) {
            $html .= '<option value="' . esc_attr($current) . '" selected>' . esc_html($current) . ' (فعلی)</option>';
        }

        foreach ($options as $opt) {
            $html .= '<option value="' . esc_attr($opt) . '"' . selected($current, $opt, false) . '>' . esc_html($opt) . '</option>';
        }

        return $html;
    }

    /* Build currency select options */
    private static function currency_options(array $currencies, string $current): string {
        $html = '<option value="">-- واحد پول --</option>';

        foreach ($currencies as $code => $name) {
            $html .= '<option value="' . esc_attr($code) . '"' . selected($current, $code, false) . '>' . esc_html($name) . ' (' . esc_html($code) . ')</option>';
        }

        return $html;
    }

    /* ✅ Minimum price among all rows (single/multi/urgent/...) */
    public static function get_min_price(int $post_id): array {
        $prices = get_post_meta($post_id, '_visa_prices', true);

        if (!is_array($prices)) return ['price_min' => null, 'currency' => ''];

        $min = null;
        $cur = '';

        foreach ($prices as $p) {
            $val = self::to_number((string) ($p['price'] ?? ''));

            if ($val > 0 && ($min === null || $val < $min)) {
                $min = $val;
                $cur = sanitize_text_field($p['currency'] ?? '');
            }
        }

        return ['price_min' => $min, 'currency' => $cur];
    }

    /* ✅ REST field: visa_info - without this, frontend won't see price/duration */
    public static function register_rest_fields() {
        register_rest_field('visa', 'visa_info', [
            'get_callback' => function ($post) {
                $id  = (int) $post['id'];
                $min = self::get_min_price($id);

                return [
                    'price_min' => $min['price_min'],
                    'currency'  => $min['currency'] ?: null,
                    'issue'     => get_post_meta($id, '_visa_issue', true) ?: null,
                    'expiry'    => get_post_meta($id, '_visa_expiry', true) ?: null,
                    'banner'    => get_post_meta($id, '_visa_banner', true) ?: null,
                ];
            },
            'update_callback' => null,
            'schema'          => ['type' => 'object'],
        ]);
    }
}