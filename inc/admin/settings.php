<?php

/**
 * NextSafar Settings - Version 3.0
 * Note 6: "Automatic review and publish hours" section.
 * Note 7: Publish/draft/AI review caps + time window.
 */

namespace NextSafar\Admin;

if (!defined('ABSPATH')) exit;

class Settings {
    public static function render_settings_page(): void {
        /* ========================================================================
           Save API Settings
           ======================================================================== */
        if (isset($_POST['save_nextsafar_api_settings']) && check_admin_referer('nextsafar_api_settings_action')) {
            update_option('nextsafar_searchapi_key', sanitize_text_field($_POST['nextsafar_searchapi_key'] ?? ''));
            update_option('nextsafar_serpapi_key', sanitize_text_field($_POST['nextsafar_serpapi_key'] ?? ''));
            update_option('nextsafar_active_source', sanitize_text_field($_POST['nextsafar_active_source'] ?? 'searchapi'));

            echo '<div class="updated"><p>تنظیمات API ذخیره شد.</p></div>';
        }

        /* ========================================================================
           Save News Settings
           ======================================================================== */
        if (isset($_POST['save_news_settings']) && check_admin_referer('nextsafar_news_settings')) {
            update_option('nextsafar_news_auto', isset($_POST['news_auto']) ? '1' : '0');

            // Automatic hours (Note 6).
            $hours = array_map('intval', (array) ($_POST['news_schedule_hours'] ?? []));
            $hours = array_values(array_unique(array_filter($hours, function ($h) {
                return $h >= 0 && $h <= 23;
            })));

            if (empty($hours)) $hours = [8, 14, 20];

            update_option('nextsafar_news_schedule_hours', $hours);

            // Time window and caps (Notes 1 and 7).
            update_option('nextsafar_news_fetch_window_hours', max(1, min(72, (int) ($_POST['news_fetch_window'] ?? 12))));
            update_option('nextsafar_news_max_publish', max(0, min(10, (int) ($_POST['news_max_publish'] ?? 2))));
            update_option('nextsafar_news_max_drafts', max(0, min(20, (int) ($_POST['news_max_drafts'] ?? 5))));
            update_option('nextsafar_news_max_ai_checks', max(0, min(100, (int) ($_POST['news_max_ai_checks'] ?? 25))));

            update_option('nextsafar_news_ai_enabled', isset($_POST['news_ai_enabled']) ? '1' : '0');
            update_option('nextsafar_news_ai_provider', sanitize_text_field($_POST['news_ai_provider'] ?? 'gemini'));
            update_option('nextsafar_news_ai_model', sanitize_text_field($_POST['news_ai_model'] ?? ''));

            // NEW: Save main and backup models.
            update_option('nextsafar_news_ai_model_main', sanitize_text_field($_POST['news_ai_model_main'] ?? 'gemini-3.6-flash'));
            update_option('nextsafar_news_ai_model_backup1', sanitize_text_field($_POST['news_ai_model_backup1'] ?? 'gemini-3.7-flash'));
            update_option('nextsafar_news_ai_model_backup2', sanitize_text_field($_POST['news_ai_model_backup2'] ?? 'gemini-3.5-flash'));

            update_option('nextsafar_openai_api_key', sanitize_text_field($_POST['openai_key'] ?? ''));
            update_option('nextsafar_gemini_api_key', sanitize_text_field($_POST['gemini_key'] ?? ''));
            update_option('nextsafar_gnews_api_key', sanitize_text_field($_POST['gnews_key'] ?? ''));
            update_option('nextsafar_newsdata_api_key', sanitize_text_field($_POST['newsdata_key'] ?? ''));
            update_option('nextsafar_currents_api_key', sanitize_text_field($_POST['currents_key'] ?? ''));

            \NextSafar\API\NewsSync::schedule_cron();

            echo '<div class="updated"><p>✅ تنظیمات اخبار ذخیره شد.</p></div>';
        }

        /* ========================================================================
           Reset AI Status
           ======================================================================== */
        if (isset($_POST['reset_ai_status']) && check_admin_referer('nextsafar_news_settings')) {
            \NextSafar\API\AIRewriter::reset_all();

            echo '<div class="updated"><p>✅ وضعیت AI ریست شد.</p></div>';
        }

        $searchapi_key = get_option('nextsafar_searchapi_key', '');
        $serpapi_key = get_option('nextsafar_serpapi_key', '');
        $active_source = get_option('nextsafar_active_source', 'searchapi');

        $news_auto = get_option('nextsafar_news_auto', '1');
        $schedule_hours = array_map('intval', (array) get_option('nextsafar_news_schedule_hours', [8, 14, 20]));
        $fetch_window = (int) get_option('nextsafar_news_fetch_window_hours', 12);
        $max_publish = (int) get_option('nextsafar_news_max_publish', 2);
        $max_drafts = (int) get_option('nextsafar_news_max_drafts', 5);
        $max_ai_checks = (int) get_option('nextsafar_news_max_ai_checks', 25);

        $ai_enabled = get_option('nextsafar_news_ai_enabled', '0');
        $ai_provider = get_option('nextsafar_news_ai_provider', 'gemini');

        $ai_model_main = get_option('nextsafar_news_ai_model_main', 'gemini-3.7-flash');
        $ai_model_back1 = get_option('nextsafar_news_ai_model_backup1', 'gemini-3.6-flash');
        $ai_model_back2 = get_option('nextsafar_news_ai_model_backup2', 'gemini-3.5-flash');

        $openai_key = get_option('nextsafar_openai_api_key', '');
        $gemini_key = get_option('nextsafar_gemini_api_key', '');
        $gnews_key = get_option('nextsafar_gnews_api_key', '');
        $newsdata_key = get_option('nextsafar_newsdata_api_key', '');
        $currents_key = get_option('nextsafar_currents_api_key', '');

        $ai_status = \NextSafar\API\AIRewriter::get_status();

        // Define list of valid models.
        $valid_models = [
            'gemini-3.7-flash' => 'Gemini 3.7 Flash (جدیدترین - توصیه شده)',
            'gemini-3.6-flash' => 'Gemini 3.6 Flash',
            'gemini-3.5-flash' => 'Gemini 3.5 Flash',
            'gemini-3.5-pro' => 'Gemini 3.5 Pro (قدرتمندتر)',
            'gemini-3.0-flash' => 'Gemini 3.0 Flash',
            'gemini-3.0-pro' => 'Gemini 3.0 Pro',
        ];
        ?>
        <div class="wrap">
            <h1>تنظیمات API - NextSafar</h1>

            <div style="max-width: 950px;">
                <form method="post" style="background:#fff;padding:20px;border-radius:8px;margin-bottom:20px;">
                    <?php wp_nonce_field('nextsafar_api_settings_action'); ?>

                    <h2>تنظیمات همگام‌سازی هتل، مقصد و ...</h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="nextsafar_active_source">منبع پیش‌فرض:</label></th>
                            <td>
                                <select name="nextsafar_active_source" id="nextsafar_active_source">
                                    <option value="searchapi" <?= selected($active_source, 'searchapi'); ?>>SearchApi.io</option>
                                    <option value="serpapi" <?= selected($active_source, 'serpapi'); ?>>SerpApi</option>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label>کلید SearchApi.io:</label></th>
                            <td>
                                <input type="password" name="nextsafar_searchapi_key" value="<?= esc_attr($searchapi_key); ?>" class="regular-text">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label>کلید SerpApi:</label></th>
                            <td>
                                <input type="password" name="nextsafar_serpapi_key" value="<?= esc_attr($serpapi_key); ?>" class="regular-text">
                            </td>
                        </tr>
                    </table>

                    <p>
                        <input type="submit" name="save_nextsafar_api_settings" class="button button-primary button-large" value="ذخیره تنظیمات">
                    </p>
                </form>

                <form method="post" style="background:#fff;padding:20px;border-radius:8px;margin-bottom:20px;">
                    <?php wp_nonce_field('nextsafar_news_settings'); ?>

                    <h2>تنظیمات انتشار خودکار اخبار</h2>

                    <h3>ساعت‌های بررسی و انتشار خودکار</h3>
                    <p class="description">
                        سیستم در این ساعت‌ها خودکار اخبار را دریافت، فیلتر، بازنویسی و منتشر می‌کند (منطقه زمانی وردپرس). حداقل یک ساعت انتخاب کنید.
                    </p>

                    <div style="display:grid;grid-template-columns:repeat(8,1fr);gap:6px;max-width:700px;margin:10px 0 20px;">
                        <?php for ($h = 0; $h < 24; $h++): ?>
                            <label style="background:<?= in_array($h, $schedule_hours, true) ? '#d1f0dd' : '#f0f0f1'; ?>;padding:8px 4px;text-align:center;border-radius:4px;cursor:pointer;font-size:12px;">
                                <input type="checkbox" name="news_schedule_hours[]" value="<?= $h; ?>" <?= checked(in_array($h, $schedule_hours, true), true, false); ?> style="display:block;margin:0 auto 3px;">
                                <?= sprintf('%02d:00', $h); ?>
                            </label>
                        <?php endfor; ?>
                    </div>

                    <h3>تنظیمات پایه</h3>

                    <table class="form-table">
                        <tr>
                            <th>انتشار خودکار:</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="news_auto" value="1" <?= checked($news_auto, '1', false); ?>> فعال (WP-Cron)
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th><label>پنجره زمانی دریافت اخبار (ساعت):</label></th>
                            <td>
                                <input type="number" name="news_fetch_window" value="<?= esc_attr($fetch_window); ?>" min="1" max="72" style="width:80px;">
                                <p class="description">فقط اخبار این بازه زمانی دریافت می‌شوند (پیشنهاد: ۱۲)</p>
                            </td>
                        </tr>

                        <tr>
                            <th><label>حداکثر انتشار در هر اجرا:</label></th>
                            <td>
                                <input type="number" name="news_max_publish" value="<?= esc_attr($max_publish); ?>" min="0" max="10" style="width:80px;">
                                <p class="description">پیشنهاد: ۲</p>
                            </td>
                        </tr>

                        <tr>
                            <th><label>حداکثر پیش‌نویس منتظر:</label></th>
                            <td>
                                <input type="number" name="news_max_drafts" value="<?= esc_attr($max_drafts); ?>" min="0" max="20" style="width:80px;">
                                <p class="description">سقف کل پیش‌نویس‌های در انتظار بازنویسی — پیشنهاد: ۵</p>
                            </td>
                        </tr>

                        <tr>
                            <th><label>حداکثر بررسی AI در هر اجرا:</label></th>
                            <td>
                                <input type="number" name="news_max_ai_checks" value="<?= esc_attr($max_ai_checks); ?>" min="0" max="100" style="width:80px;">
                                <p class="description">سقف بررسی ارتباط خبرهای مرزی/ردشده با AI</p>
                            </td>
                        </tr>
                    </table>

                    <h3>تنظیمات بازنویسی با هوش مصنوعی</h3>

                    <table class="form-table">
                        <tr>
                            <th>بازنویسی با AI:</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="news_ai_enabled" value="1" <?= checked($ai_enabled, '1', false); ?>> فعال
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th>مدل اصلی AI:</th>
                            <td>
                                <div style="display:flex;gap:10px;align-items:center;">
                                    <select name="news_ai_model_main" class="ai-model-select" style="flex:1;">
                                        <?php foreach ($valid_models as $model_id => $model_name): ?>
                                            <option value="<?= esc_attr($model_id); ?>" <?= selected($ai_model_main, $model_id); ?>>
                                                <?= esc_html($model_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <button type="button" class="button" id="load-models-btn">🔄 دریافت لیست مدل‌ها</button>
                                </div>

                                <p class="description">مدل اولیه برای ترجمه و بررسی ارتباط.</p>
                            </td>
                        </tr>

                        <tr>
                            <th>مدل پشتیبان ۱:</th>
                            <td>
                                <select name="news_ai_model_backup1" class="ai-model-select" style="width:100%;max-width:400px;">
                                    <?php foreach ($valid_models as $model_id => $model_name): ?>
                                        <option value="<?= esc_attr($model_id); ?>" <?= selected($ai_model_back1, $model_id); ?>>
                                            <?= esc_html($model_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <th>مدل پشتیبان ۲:</th>
                            <td>
                                <select name="news_ai_model_backup2" class="ai-model-select" style="width:100%;max-width:400px;">
                                    <?php foreach ($valid_models as $model_id => $model_name): ?>
                                        <option value="<?= esc_attr($model_id); ?>" <?= selected($ai_model_back2, $model_id); ?>>
                                            <?= esc_html($model_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <th>مدل‌های اضطراری (Fallback):</th>
                            <td>
                                <p class="description" style="color:#666;">
                                    اگر هر ۳ مدل بالا از کار بیفتند، سیستم به صورت خودکار از <strong>gemini-3.5-pro</strong>، <strong>gemini-3.0-flash</strong> و <strong>gemini-3.0-pro</strong> استفاده می‌کند.
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th>وضعیت AI:</th>
                            <td>
                                <?php if ($ai_status['is_disabled']): ?>
                                    <span style="color:#d63638;">در حالت استراحت — <?= esc_html($ai_status['cooldown_remaining']); ?></span>
                                <?php else: ?>
                                    <span style="color:#00a32a;">آماده — شکست‌های متوالی: <?= (int) $ai_status['fail_count']; ?>/<?= (int) $ai_status['max_fails']; ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>

                    <h3>API Keys (اخبار و AI)</h3>

                    <table class="form-table">
                        <tr>
                            <th>Gemini API Key:</th>
                            <td><input type="password" name="gemini_key" value="<?= esc_attr($gemini_key); ?>" class="regular-text"></td>
                        </tr>

                        <tr>
                            <th>OpenAI API Key:</th>
                            <td><input type="password" name="openai_key" value="<?= esc_attr($openai_key); ?>" class="regular-text"></td>
                        </tr>

                        <tr>
                            <th>GNews.io API Key:</th>
                            <td><input type="password" name="gnews_key" value="<?= esc_attr($gnews_key); ?>" class="regular-text"></td>
                        </tr>

                        <tr>
                            <th>NewsData.io API Key:</th>
                            <td><input type="password" name="newsdata_key" value="<?= esc_attr($newsdata_key); ?>" class="regular-text"></td>
                        </tr>

                        <tr>
                            <th>Currents API Key:</th>
                            <td><input type="password" name="currents_key" value="<?= esc_attr($currents_key); ?>" class="regular-text"></td>
                        </tr>
                    </table>

                    <p>
                        <input type="submit" name="save_news_settings" class="button button-primary button-large" value="ذخیره تنظیمات اخبار">
                    </p>
                </form>

                <form method="post" style="background:#fff;padding:20px;border-radius:8px;">
                    <?php wp_nonce_field('nextsafar_news_settings'); ?>

                    <h3>ابزار اضطراری</h3>
                    <p class="description">
                        اگر AI به دلیل خطای موقت (مثل 503) در حالت استراحت است، با این دکمه شمارنده‌ها ریست می‌شوند.
                    </p>

                    <p>
                        <input type="submit" name="reset_ai_status" class="button" value="ریست وضعیت AI">
                    </p>
                </form>
            </div>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                // Fix: use class instead of id to select all dropdowns.
                var allSelects = $('.ai-model-select');

                $('#load-models-btn').on('click', function () {
                    var btn = $(this),
                        status = $('<div id="models-status" style="margin-top:10px;"></div>'),
                        provider = $('#ai-provider').val();

                    btn.prop('disabled', true).text('⏳ در حال دریافت...');

                    // Add status after the button.
                    if ($('#models-status').length === 0) {
                        btn.parent().append(status);
                    } else {
                        status = $('#models-status');
                    }

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'nextsafar_get_ai_models',
                            nonce: '<?= wp_create_nonce('nextsafar_sync'); ?>',
                            provider: provider
                        },
                        success: function (response) {
                            btn.prop('disabled', false).text('🔄 دریافت لیست مدل‌ها');

                            if (!response.success) {
                                status.html('<div style="color:#d63638;">❌ خطا: ' + (response.data?.error || 'نامشخص') + '</div>');
                                return;
                            }

                            var data = response.data || {};
                            var models = data.models || [];

                            if (models.length === 0) {
                                status.html('<div style="color:#dba617;">⚠️ مدلی یافت نشد</div>');
                                return;
                            }

                            // Save current values.
                            var currentValues = {};

                            allSelects.each(function () {
                                currentValues[$(this).attr('name')] = $(this).val();
                            });

                            // Clear and repopulate all dropdowns.
                            allSelects.empty();

                            models.forEach(function (m) {
                                var label = m.display_name || m.name;

                                if (m.is_latest) label += ' ⭐';
                                if (m.is_pro) label += ' (Pro)';

                                allSelects.each(function () {
                                    var select = $(this);
                                    var option = $('<option>').val(m.name).text(label);

                                    select.append(option);
                                });
                            });

                            // Restore previous values.
                            allSelects.each(function () {
                                var select = $(this);
                                var name = select.attr('name');

                                if (currentValues[name]) {
                                    select.val(currentValues[name]);
                                }
                            });

                            status.html('<div style="color:#00a32a;">✅ ' + models.length + ' مدل یافت شد و در تمام دراپ‌داون‌ها به‌روزرسانی شد.</div>');
                        },
                        error: function () {
                            btn.prop('disabled', false).text('🔄 دریافت لیست مدل‌ها');
                            status.html('<div style="color:#d63638;">❌ خطا در ارتباط با سرور</div>');
                        }
                    });
                });
            });
        </script>
        <?php
    }
}