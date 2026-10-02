<?php

/**
 * AI Trip Planner Settings Page
 * Standalone menu: AI Trip
 */

namespace NextSafar\Admin;

if (!defined('ABSPATH')) exit;

use NextSafar\API\AiTripGeminiClient;
use NextSafar\Database\AiTripTable;

class AiTripSettings {
    const PAGE_SLUG = 'nextsafar-ai-trip';

    public static function init() {
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('wp_ajax_ns_test_trip_key', [__CLASS__, 'ajax_test_key']);
    }

    // Menu registration moved to menu.php

    public static function register_settings() {
        register_setting('ns_ai_trip_group', AiTripGeminiClient::OPTION_KEY, [
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        register_setting('ns_ai_trip_group', AiTripGeminiClient::OPTION_MODEL, [
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        register_setting('ns_ai_trip_group', 'ns_ai_trip_groq_key', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ns_ai_trip_group', 'ns_ai_trip_groq_model', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ns_ai_trip_group', 'ns_ai_trip_qwen_key', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ns_ai_trip_group', 'ns_ai_trip_qwen_model', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ns_ai_trip_group', 'ns_ai_trip_provider_order', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ns_ai_trip_group', 'ns_ai_trip_jawg_key', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('ns_ai_trip_group', 'ns_map_tile_theme', ['sanitize_callback' => 'sanitize_key']);
    }

    /* ========================================================================
       AJAX Key Test
       ======================================================================== */
    public static function ajax_test_key() {
        check_ajax_referer('ns_test_trip_key', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز']);
        }

        $client = new AiTripGeminiClient(60);

        if (!$client->has_api_key()) {
            wp_send_json_error(['message' => 'کلید API ذخیره نشده. اول ذخیره کن، بعد تست کن.']);
        }

        $result = $client->test_connection();

        if ($result['success']) {
            wp_send_json_success([
                'message' => '✅ اتصال برقرار شد! مدل فعال: ' . $client->get_model()
                . ' | توکن: ' . ($result['usage']['total_tokens'] ?? 0)
                . ' | زمان: ' . round(($result['usage']['duration_ms'] ?? 0) / 1000, 1) . ' ثانیه',
            ]);
        }

        wp_send_json_error(['message' => '❌ ' . $result['error']]);
    }

    /* ========================================================================
       Usage Statistics
       ======================================================================== */
    private static function get_stats(): array {
        global $wpdb;

        $stats = [
            'table_exists' => false,
            'today'        => 0,
            'total'        => 0,
            'tokens'       => 0,
            'avg_ms'       => 0,
            'recent'       => [],
        ];

        $table = AiTripTable::get_table_name();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        if (!$exists) return $stats;

        $stats['table_exists'] = true;
        $stats['today']  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE DATE(created_at) = CURDATE()");
        $stats['total']  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $stats['tokens'] = (int) $wpdb->get_var("SELECT COALESCE(SUM(tokens_used),0) FROM {$table}");
        $stats['avg_ms'] = (int) $wpdb->get_var("SELECT COALESCE(AVG(generation_time_ms),0) FROM {$table} WHERE generation_time_ms > 0");

        $stats['recent'] = $wpdb->get_results(
            "SELECT id, trip_title, destination, days, tokens_used, created_at
             FROM {$table} ORDER BY id DESC LIMIT 5"
        );

        return $stats;
    }

    /* ========================================================================
       Render Page
       ======================================================================== */
    public static function render_page() {
        $client = new AiTripGeminiClient();
        $key    = get_option(AiTripGeminiClient::OPTION_KEY, '');
        $model  = $client->get_model();
        $stats  = self::get_stats();
        ?>
        <div class="wrap">
            <h1>🗺️ تنظیمات برنامه‌ریز سفر AI</h1>
            <p>سیستم کاملاً مستقل از اخبار — کلید API و مدل مخصوص خودش</p>

            <!-- Connection Status -->
            <div class="notice notice-<?php echo $client->has_api_key() ? 'success' : 'warning'; ?> inline" style="margin:15px 0;">
                <p>
                    <?php if ($client->has_api_key()): ?>
                        ✅ کلید API تنظیم شده — مدل فعال: <strong><?php echo esc_html($model); ?></strong>
                    <?php else: ?>
                        ⚠️ کلید API تنظیم نشده — برنامه سفر کار نمی‌کنه تا کلید رو وارد کنی
                    <?php endif; ?>
                </p>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 380px; gap:20px; align-items:start;">
                <div>
                    <!-- Settings Form -->
                    <form method="post" action="options.php">
                        <?php settings_fields('ns_ai_trip_group'); ?>

                        <div class="postbox" style="padding:15px;">
                            <h2>🔑 کلید API گوگل (مخصوص برنامه سفر)</h2>

                            <table class="form-table">
                                <tr>
                                    <th scope="row">API Key</th>
                                    <td>
                                        <div style="display:flex; gap:8px; align-items:center;">
                                            <input type="password" id="trip_key_input"
                                                   name="<?php echo AiTripGeminiClient::OPTION_KEY; ?>"
                                                   value="<?php echo esc_attr($key); ?>"
                                                   class="regular-text" autocomplete="off" />
                                            <button type="button" class="button" id="trip_key_toggle">👁️ نمایش</button>
                                        </div>
                                        <p class="description">
                                            این کلید فقط برای برنامه‌ریز سفر استفاده می‌شه — جدا از کلید اخبار
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">مدل هوش مصنوعی</th>
                                    <td>
                                        <select name="<?php echo AiTripGeminiClient::OPTION_MODEL; ?>">
                                            <?php foreach (AiTripGeminiClient::get_models_for_select() as $val => $label): ?>
                                                <option value="<?php echo esc_attr($val); ?>" <?php selected($model, $val); ?>>
                                                    <?php echo esc_html($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="description">مدل‌های زیر 2.5 پشتیبانی نمی‌شن (تست شده)</p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">ترتیب زنجیره AI</th>
                                    <td>
                                        <input type="text" name="ns_ai_trip_provider_order"
                                               value="<?php echo esc_attr(get_option('ns_ai_trip_provider_order', 'gemini,groq,qwen')); ?>" class="regular-text" />
                                        <p class="description">با کاما جدا کن: gemini,groq,qwen — هر کدام کلید نداشته باشد رد می‌شود</p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">کلید Groq (+ مدل)</th>
                                    <td>
                                        <input type="password" name="ns_ai_trip_groq_key" value="<?php echo esc_attr(get_option('ns_ai_trip_groq_key', '')); ?>" class="regular-text" autocomplete="off" />
                                        <input type="text" name="ns_ai_trip_groq_model" value="<?php echo esc_attr(get_option('ns_ai_trip_groq_model', 'llama-3.3-70b-versatile')); ?>" style="margin-top:6px;" class="regular-text" />
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">کلید Qwen (+ مدل)</th>
                                    <td>
                                        <input type="password" name="ns_ai_trip_qwen_key" value="<?php echo esc_attr(get_option('ns_ai_trip_qwen_key', '')); ?>" class="regular-text" autocomplete="off" />
                                        <input type="text" name="ns_ai_trip_qwen_model" value="<?php echo esc_attr(get_option('ns_ai_trip_qwen_model', 'qwen-plus')); ?>" style="margin-top:6px;" class="regular-text" />
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">کلید Jawg Maps (کاشی‌های نقشه)</th>
                                    <td>
                                        <input type="text" name="ns_ai_trip_jawg_key"
                                               value="<?php echo esc_attr(get_option('ns_ai_trip_jawg_key', '')); ?>"
                                               class="regular-text" placeholder="توکن Jawg" />
                                        <p class="description">
                                            اگر خالی بماند، نقشه روی کاشی‌های رایگان OpenStreetMap می‌افتد.
                                            این کلید توسط endpoint عمومی <code>/map/config</code> به فرانت داده می‌شود.
                                        </p>
                                    </td>
                                </tr>

                                <tr>
                                    <th scope="row">تم نقشه</th>
                                    <td>
                                        <select name="ns_map_tile_theme">
                                            <?php
                                            $current = get_option('ns_map_tile_theme', 'carto-voyager');

                                            $options = [
                                                'ofm-liberty'  => 'OpenFreeMap Liberty — مطابق دیزاین مرجع (پیشنهادی، بدون کلید)',
                                                'ofm-bright'   => 'OpenFreeMap Bright — رنگی‌تر (بدون کلید)',
                                                'ofm-positron' => 'OpenFreeMap Positron — خاکستری مینیمال (بدون کلید)',
                                                'osm-standard' => 'OpenStreetMap استاندارد (بدون کلید)',
                                                'jawg-sunny'   => 'Jawg Sunny — کرم گرم (نیازمند کلید)',
                                                'jawg-light'   => 'Jawg Light (نیازمند کلید)',
                                                'jawg-streets' => 'Jawg Streets (نیازمند کلید)',
                                            ];

                                            foreach ($options as $val => $label) {
                                                echo '<option value="' . esc_attr($val) . '" ' . selected($current, $val, false) . '>' . esc_html($label) . '</option>';
                                            }
                                            ?>
                                        </select>
                                        <p class="description">تغییر تم بدون دست‌زدن به کد؛ فقط ذخیره کن.</p>
                                    </td>
                                </tr>
                            </table>

                            <?php submit_button('💾 ذخیره تنظیمات'); ?>
                        </div>
                    </form>

                    <!-- Connection Test -->
                    <div class="postbox" style="padding:15px;">
                        <h2>🧪 تست اتصال</h2>

                        <button type="button" class="button button-primary" id="trip_test_btn">
                            🧪 تست کلید و مدل
                        </button>

                        <div id="trip_test_result" style="margin-top:12px; display:none; padding:12px; border-radius:6px;"></div>
                    </div>

                    <!-- Recent Trips -->
                    <?php if ($stats['table_exists'] && !empty($stats['recent'])): ?>
                        <div class="postbox" style="padding:15px;">
                            <h2>📚 آخرین برنامه‌های ساخته‌شده</h2>

                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>عنوان</th>
                                        <th>مقصد</th>
                                        <th>روز</th>
                                        <th>توکن</th>
                                        <th>تاریخ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stats['recent'] as $row): ?>
                                        <tr>
                                            <td><?php echo (int) $row->id; ?></td>
                                            <td><?php echo esc_html(mb_substr($row->trip_title ?? '—', 0, 40)); ?></td>
                                            <td><?php echo esc_html($row->destination); ?></td>
                                            <td><?php echo (int) $row->days; ?></td>
                                            <td><?php echo number_format((int) $row->tokens_used); ?></td>
                                            <td><?php echo esc_html($row->created_at); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Sidebar: Stats + Guide -->
                <div>
                    <div class="postbox" style="padding:15px;">
                        <h2>📊 آمار استفاده</h2>

                        <?php if ($stats['table_exists']): ?>
                            <table class="widefat">
                                <tr><td>برنامه‌های امروز</td><td><strong><?php echo $stats['today']; ?></strong></td></tr>
                                <tr><td>کل برنامه‌ها</td><td><strong><?php echo $stats['total']; ?></strong></td></tr>
                                <tr><td>مصرف توکن</td><td><strong><?php echo number_format($stats['tokens']); ?></strong></td></tr>
                                <tr><td>میانگین زمان تولید</td><td><strong><?php echo round($stats['avg_ms'] / 1000, 1); ?> ثانیه</strong></td></tr>
                            </table>

                            <p class="description" style="margin-top:10px;">
                                سهمیه رایگان: مهمان ۳ بار در روز | کاربر لاگین ۲۰ بار در روز
                            </p>
                        <?php else: ?>
                            <p>جدول دیتابیس هنوز ساخته نشده. یک بار صفحه اصلی سایت رو باز کن تا ساخته بشه.</p>
                        <?php endif; ?>
                    </div>

                    <div class="postbox" style="padding:15px; background:#f0f6fc;">
                        <h2>🔑 راهنمای گرفتن کلید</h2>

                        <ol style="margin:0; padding-inline-start:18px; line-height:2;">
                            <li>برو به <a href="https://aistudio.google.com/apikey" target="_blank">aistudio.google.com/apikey</a></li>
                            <li>یک کلید <strong>جدید و جدا</strong> بساز</li>
                            <li>کپی کن و بالا در فیلد بذار</li>
                            <li>ذخیره → تست</li>
                        </ol>

                        <p><strong>💡 نکته:</strong> هر کلید ۱۵۰۰ درخواست رایگان در روز داره — با کلید جدا، اخبار و سفر هیچ تداخلی ندارن</p>
                    </div>
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                /* Show / Hide key */
                $('#trip_key_toggle').on('click', function() {
                    var input = $('#trip_key_input');

                    if (input.attr('type') === 'password') {
                        input.attr('type', 'text');
                        $(this).text('🙈 مخفی');
                    } else {
                        input.attr('type', 'password');
                        $(this).text('👁️ نمایش');
                    }
                });

                /* Connection test */
                $('#trip_test_btn').on('click', function() {
                    var btn = $(this);
                    var box = $('#trip_test_result');

                    btn.prop('disabled', true).text('⏳ در حال تست...');
                    box.hide();

                    $.post(ajaxurl, {
                        action: 'ns_test_trip_key',
                        nonce: '<?php echo wp_create_nonce('ns_test_trip_key'); ?>'
                    }, function(res) {
                        btn.prop('disabled', false).text('🧪 تست کلید و مدل');
                        box.show();

                        if (res.success) {
                            box.css({'background':'#edfaef','border':'1px solid #68de7c','color':'#1e7e34'})
                               .html(res.data.message);
                        } else {
                            box.css({'background':'#fcf0f1','border':'1px solid #fc97a0','color':'#a0222f'})
                               .html(res.data.message);
                        }
                    }).fail(function() {
                        btn.prop('disabled', false).text('🧪 تست کلید و مدل');
                        box.show().css({'background':'#fcf0f1','border':'1px solid #fc97a0','color':'#a0222f'})
                           .html('❌ خطای ارتباط با سرور');
                    });
                });
            });
        </script>
        <?php
    }
}