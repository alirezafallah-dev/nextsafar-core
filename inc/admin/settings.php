<?php
/**
 * NextSafar Settings - News Settings
 * Minimal UI with brand colors
 */

namespace NextSafar\Admin;

if (!defined('ABSPATH')) exit;

class Settings {

    /* ========================================================================
       Render Settings Page
       ======================================================================== */
    public static function render_settings_page(): void {
        /* ── Save Settings ── */
        if (isset($_POST['save_news_settings']) && check_admin_referer('nextsafar_news_settings')) {
            update_option('nextsafar_news_auto', isset($_POST['news_auto']) ? '1' : '0');

            $hours = [];
            if (isset($_POST['sync_hours']) && is_array($_POST['sync_hours'])) {
                foreach ($_POST['sync_hours'] as $h) {
                    $h = absint($h);
                    if ($h >= 0 && $h <= 23) {
                        $hours[] = $h;
                    }
                }
            }

            update_option('nextsafar_news_sync_hours', $hours);
            update_option('nextsafar_news_max_publish_per_run', absint($_POST['max_publish_per_run'] ?? 5));
            update_option('nextsafar_news_max_draft_per_run', absint($_POST['max_draft_per_run'] ?? 3));
            update_option('nextsafar_news_max_ai_review_per_run', absint($_POST['max_ai_review_per_run'] ?? 2));
            update_option('nextsafar_news_time_window_hours', absint($_POST['time_window_hours'] ?? 24));

            echo '<div class="notice notice-success is-dismissible"><p>✅ اخبار با موفقیت ذخیره شد.</p></div>';

            $news_auto = isset($_POST['news_auto']) ? '1' : '0';
            $sync_hours = $hours;
            $max_publish = absint($_POST['max_publish_per_run'] ?? 5);
            $max_draft = absint($_POST['max_draft_per_run'] ?? 3);
            $max_ai_review = absint($_POST['max_ai_review_per_run'] ?? 2);
            $time_window = absint($_POST['time_window_hours'] ?? 24);
        } else {
            $news_auto = get_option('nextsafar_news_auto', '0');
            $sync_hours = get_option('nextsafar_news_sync_hours', [8, 12, 18]);
            $max_publish = get_option('nextsafar_news_max_publish_per_run', 5);
            $max_draft = get_option('nextsafar_news_max_draft_per_run', 3);
            $max_ai_review = get_option('nextsafar_news_max_ai_review_per_run', 2);
            $time_window = get_option('nextsafar_news_time_window_hours', 24);
        }

        if (!is_array($sync_hours)) {
            $sync_hours = [8, 12, 18];
        }
        ?>
        <style>
            /* ─── Brand Tokens ─── */
            :root {
                --ns-primary: #43abff;
                --ns-primary-dark: #3384c6;
                --ns-primary-light: #b9e0ff;
                --ns-primary-lightest: #e8f5fe;
                --ns-surface: #f8f8f8;
                --ns-text-strong: #0f172a;
                --ns-text: #1f2937;
                --ns-text-muted: #6b7280;
                --ns-text-subtle: #9ca3af;
                --ns-bg: #ffffff;
                --ns-bg-sec: #f8fafc;
                --ns-border: #e5e7eb;
                --ns-divider: #edf2f7;
                --ns-danger: #ee1500;
                --ns-success: #10b981;
            }

            /* ─── Layout ─── */
            .ns-settings-wrap {
                max-width: 1100px;
                margin: 20px 0;
            }

            .ns-settings-header {
                background: var(--ns-bg);
                padding: 24px 28px;
                border-radius: 12px 12px 0 0;
                border: 1px solid var(--ns-border);
                border-bottom: 2px solid var(--ns-primary);
                display: flex;
                align-items: center;
                gap: 15px;
            }

            .ns-settings-header .icon {
                width: 48px;
                height: 48px;
                border-radius: 10px;
                background: var(--ns-primary-lightest);
                color: var(--ns-primary);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 24px;
                flex-shrink: 0;
            }

            .ns-settings-header h2 {
                color: var(--ns-text-strong);
                margin: 0;
                font-size: 20px;
                font-weight: 600;
            }

            .ns-settings-header p {
                color: var(--ns-text-muted);
                margin: 4px 0 0;
                font-size: 13px;
            }

            .ns-settings-body {
                background: var(--ns-bg);
                padding: 28px;
                border-radius: 0 0 12px 12px;
                border: 1px solid var(--ns-border);
                border-top: none;
            }

            /* ─── Section ─── */
            .ns-section {
                margin-bottom: 28px;
                padding-bottom: 28px;
                border-bottom: 1px solid var(--ns-divider);
            }

            .ns-section:last-of-type {
                border-bottom: none;
                margin-bottom: 0;
                padding-bottom: 0;
            }

            .ns-section-title {
                display: flex;
                align-items: center;
                gap: 10px;
                font-size: 15px;
                font-weight: 600;
                color: var(--ns-text-strong);
                margin: 0 0 16px;
                padding-bottom: 10px;
                border-bottom: 1px solid var(--ns-divider);
            }

            .ns-section-title .emoji {
                font-size: 18px;
            }

            .ns-section-desc {
                color: var(--ns-text-muted);
                font-size: 13px;
                margin: -8px 0 16px 0;
            }

            /* ─── Toggle ─── */
            .ns-toggle-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 16px 20px;
                background: var(--ns-bg-sec);
                border: 1px solid var(--ns-border);
                border-radius: 10px;
                transition: border-color 0.2s;
            }

            .ns-toggle-row:hover {
                border-color: var(--ns-primary-light);
            }

            .ns-toggle-info {
                flex: 1;
            }

            .ns-toggle-info .label {
                font-size: 14px;
                font-weight: 600;
                color: var(--ns-text-strong);
                margin-bottom: 2px;
            }

            .ns-toggle-info .desc {
                font-size: 12px;
                color: var(--ns-text-muted);
                line-height: 1.5;
            }

            .ns-switch {
                position: relative;
                display: inline-block;
                width: 44px;
                height: 24px;
                flex-shrink: 0;
                margin-right: 4px;
            }

            .ns-switch input {
                opacity: 0;
                width: 0;
                height: 0;
            }

            .ns-switch .slider {
                position: absolute;
                cursor: pointer;
                inset: 0;
                background: var(--ns-border);
                border-radius: 24px;
                transition: 0.2s;
            }

            .ns-switch .slider::before {
                content: '';
                position: absolute;
                height: 18px;
                width: 18px;
                left: 3px;
                bottom: 3px;
                background: var(--ns-bg);
                border-radius: 50%;
                transition: 0.2s;
                box-shadow: 0 1px 3px rgba(0,0,0,0.15);
            }

            .ns-switch input:checked + .slider {
                background: var(--ns-primary);
            }

            .ns-switch input:checked + .slider::before {
                transform: translateX(20px);
            }

            /* ─── Hours Container ─── */
            .ns-hours-container {
                background: var(--ns-bg-sec);
                border: 1px solid var(--ns-border);
                border-radius: 10px;
                padding: 20px;
            }

            .ns-hours-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 18px;
                flex-wrap: wrap;
                gap: 10px;
            }

            .ns-hours-title-row {
                display: flex;
                align-items: center;
                gap: 10px;
                font-size: 13px;
                color: var(--ns-text);
                font-weight: 600;
            }

            .ns-hours-badge {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                padding: 3px 10px;
                background: var(--ns-primary-lightest);
                color: var(--ns-primary-dark);
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
            }

            .ns-hours-actions {
                display: flex;
                gap: 8px;
            }

            .ns-hours-actions button {
                padding: 5px 12px;
                font-size: 12px;
                border-radius: 6px;
                border: 1px solid var(--ns-border);
                background: var(--ns-bg);
                color: var(--ns-text-muted);
                cursor: pointer;
                transition: all 0.2s;
                font-weight: 500;
            }

            .ns-hours-actions button:hover {
                background: var(--ns-primary-lightest);
                border-color: var(--ns-primary-light);
                color: var(--ns-primary-dark);
            }

            /* Period Labels */
            .ns-periods {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 10px;
                margin-bottom: 12px;
            }

            .ns-period {
                text-align: center;
                padding: 6px 0;
                font-size: 11px;
                font-weight: 600;
                color: var(--ns-text-subtle);
                letter-spacing: 0.3px;
            }

            /* Hours Grid */
            .ns-hours-grid {
                display: grid;
                grid-template-columns: repeat(12, 1fr);
                gap: 6px;
                margin-bottom: 16px;
            }

            .ns-hour-cell {
                position: relative;
                aspect-ratio: 1;
                border: 1px solid var(--ns-border);
                border-radius: 8px;
                background: var(--ns-bg);
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 13px;
                font-weight: 600;
                color: var(--ns-text-muted);
                transition: all 0.15s;
                user-select: none;
            }

            .ns-hour-cell:hover {
                border-color: var(--ns-primary);
                color: var(--ns-primary-dark);
                background: var(--ns-primary-lightest);
            }

            .ns-hour-cell input {
                position: absolute;
                opacity: 0;
                pointer-events: none;
            }

            .ns-hour-cell.checked {
                background: var(--ns-primary);
                border-color: var(--ns-primary);
                color: var(--ns-bg);
                box-shadow: 0 2px 4px rgba(67, 171, 255, 0.25);
            }

            .ns-hour-cell.checked:hover {
                background: var(--ns-primary-dark);
                border-color: var(--ns-primary-dark);
                color: var(--ns-bg);
            }

            .ns-hour-cell.checked::after {
                content: '✓';
                position: absolute;
                top: 2px;
                right: 3px;
                font-size: 8px;
                color: rgba(255,255,255,0.8);
            }

            /* Selected Summary */
            .ns-selected-summary {
                margin-top: 14px;
                padding: 14px 16px;
                background: var(--ns-bg);
                border: 1px dashed var(--ns-border);
                border-radius: 8px;
                min-height: 50px;
            }

            .ns-selected-summary .label {
                font-size: 11px;
                color: var(--ns-text-subtle);
                margin-bottom: 8px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .ns-selected-tags {
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
            }

            .ns-time-tag {
                display: inline-flex;
                align-items: center;
                padding: 4px 10px;
                background: var(--ns-primary-lightest);
                color: var(--ns-primary-dark);
                border: 1px solid var(--ns-primary-light);
                border-radius: 14px;
                font-size: 12px;
                font-weight: 600;
                direction: ltr;
            }

            .ns-time-tag.empty {
                background: #fef2f2;
                color: var(--ns-danger);
                border-color: #fecaca;
            }

            /* Presets */
            .ns-presets {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin-top: 14px;
                padding-top: 14px;
                border-top: 1px solid var(--ns-divider);
            }

            .ns-presets-label {
                font-size: 11px;
                color: var(--ns-text-subtle);
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                width: 100%;
                margin-bottom: 4px;
            }

            .ns-preset-btn {
                padding: 6px 12px;
                background: var(--ns-bg);
                border: 1px solid var(--ns-border);
                border-radius: 6px;
                font-size: 12px;
                font-weight: 500;
                color: var(--ns-text);
                cursor: pointer;
                transition: all 0.15s;
                display: inline-flex;
                align-items: center;
                gap: 5px;
            }

            .ns-preset-btn:hover {
                background: var(--ns-primary-lightest);
                border-color: var(--ns-primary-light);
                color: var(--ns-primary-dark);
            }

            .ns-preset-btn.active {
                background: var(--ns-primary);
                border-color: var(--ns-primary);
                color: var(--ns-bg);
            }

            /* ─── Number Cards ─── */
            .ns-numbers-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
                gap: 14px;
            }

            .ns-number-card {
                background: var(--ns-bg-sec);
                border: 1px solid var(--ns-border);
                border-radius: 10px;
                padding: 18px;
                transition: all 0.2s;
                display: flex;
                flex-direction: column;
            }

            .ns-number-card:hover {
                border-color: var(--ns-primary-light);
            }

            .ns-number-card .icon-box {
                width: 36px;
                height: 36px;
                border-radius: 8px;
                background: var(--ns-primary-lightest);
                color: var(--ns-primary);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 18px;
                margin-bottom: 12px;
            }

            .ns-number-card .title {
                font-size: 13px;
                font-weight: 600;
                color: var(--ns-text-strong);
                margin-bottom: 4px;
            }

            .ns-number-card .desc {
                font-size: 12px;
                color: var(--ns-text-muted);
                margin-bottom: 12px;
                line-height: 1.5;
                flex: 1;
            }

            .ns-number-input {
                width: 100%;
                padding: 8px 12px;
                border: 1px solid var(--ns-border);
                border-radius: 6px;
                font-size: 15px;
                font-weight: 600;
                color: var(--ns-text-strong);
                background: var(--ns-bg);
                text-align: center;
                transition: all 0.15s;
                font-family: inherit;
            }

            .ns-number-input:focus {
                outline: none;
                border-color: var(--ns-primary);
                box-shadow: 0 0 0 3px rgba(67, 171, 255, 0.15);
            }

            /* ─── Submit ─── */
            .ns-submit-row {
                display: flex;
                justify-content: flex-end;
                align-items: center;
                gap: 12px;
                margin-top: 28px;
                padding-top: 20px;
                border-top: 1px solid var(--ns-divider);
            }

            .ns-btn-primary {
                padding: 10px 24px;
                background: var(--ns-primary);
                color: var(--ns-bg);
                border: none;
                border-radius: 8px;
                font-size: 14px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.15s;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                font-family: inherit;
            }

            .ns-btn-primary:hover {
                background: var(--ns-primary-dark);
            }

            .ns-btn-primary:active {
                transform: translateY(1px);
            }

            /* ─── Responsive ─── */
            @media (max-width: 900px) {
                .ns-hours-grid {
                    grid-template-columns: repeat(8, 1fr);
                }
                .ns-periods {
                    grid-template-columns: repeat(2, 1fr);
                }
            }

            @media (max-width: 600px) {
                .ns-hours-grid {
                    grid-template-columns: repeat(6, 1fr);
                }
                .ns-settings-body {
                    padding: 20px 16px;
                }
            }
        </style>

        <div class="wrap ns-settings-wrap">
            <form method="post" action="">
                <?php wp_nonce_field('nextsafar_news_settings'); ?>

                <div class="ns-settings-header">
                    <div class="icon">⚙️</div>
                    <div>
                        <h2>تنظیمات دریافت اخبار هوشمند</h2>
                        <p>پیکربندی خودکارسازی، زمان‌بندی و پارامترهای دریافت اخبار</p>
                    </div>
                </div>

                <div class="ns-settings-body">

                    <!-- Section 1: Auto Sync Toggle -->
                    <div class="ns-section">
                        <h3 class="ns-section-title">
                            <span class="emoji">🤖</span>
                            دریافت خودکار
                        </h3>

                        <div class="ns-toggle-row">
                            <div class="ns-toggle-info">
                                <div class="label">فعال‌سازی دریافت خودکار اخبار</div>
                                <div class="desc">در ساعات مشخص شده، سیستم به صورت خودکار اخبار جدید را از منابع RSS و API دریافت می‌کند</div>
                            </div>
                            <label class="ns-switch">
                                <input type="checkbox" name="news_auto" value="1" <?php checked($news_auto, '1'); ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>

                    <!-- Section 2: 24-Hour Schedule -->
                    <div class="ns-section">
                        <h3 class="ns-section-title">
                            <span class="emoji">🕐</span>
                            زمان‌بندی دریافت (۲۴ ساعته)
                        </h3>
                        <p class="ns-section-desc">ساعت‌هایی که در آن‌ها دریافت اخبار به صورت خودکار انجام شود را انتخاب کنید</p>

                        <div class="ns-hours-container">
                            <div class="ns-hours-header">
                                <div class="ns-hours-title-row">
                                    <span>ساعت‌های فعال:</span>
                                    <span class="ns-hours-badge">
                                        <span id="ns-hours-count"><?= count($sync_hours); ?></span>
                                        ساعت
                                    </span>
                                </div>
                                <div class="ns-hours-actions">
                                    <button type="button" id="ns-select-all">انتخاب همه</button>
                                    <button type="button" id="ns-clear-all">پاک کردن</button>
                                </div>
                            </div>

                            <div class="ns-periods">
                                <div class="ns-period">🌅 صبح (۰۰-۰۵)</div>
                                <div class="ns-period">☀️ ظهر (۰۶-۱۱)</div>
                                <div class="ns-period">🌆 عصر (۱۲-۱۷)</div>
                                <div class="ns-period">🌙 شب (۱۸-۲۳)</div>
                            </div>

                            <div class="ns-hours-grid" id="ns-hours-grid">
                                <?php for ($i = 0; $i < 24; $i++):
                                    $checked = in_array($i, $sync_hours, true);
                                ?>
                                    <label class="ns-hour-cell <?= $checked ? 'checked' : ''; ?>"
                                           data-hour="<?= $i; ?>"
                                           title="<?= str_pad($i, 2, '0', STR_PAD_LEFT); ?>:00">
                                        <input type="checkbox"
                                               name="sync_hours[]"
                                               value="<?= $i; ?>"
                                               <?= $checked ? 'checked' : ''; ?>>
                                        <span><?= str_pad($i, 2, '0', STR_PAD_LEFT); ?></span>
                                    </label>
                                <?php endfor; ?>
                            </div>

                            <div class="ns-selected-summary">
                                <div class="label">📋 ساعت‌های انتخاب شده</div>
                                <div class="ns-selected-tags" id="ns-selected-tags"></div>
                            </div>

                            <div class="ns-presets">
                                <div class="ns-presets-label">⚡ الگوهای آماده</div>
                                <button type="button" class="ns-preset-btn" data-preset="morning">🌅 صبح‌ها</button>
                                <button type="button" class="ns-preset-btn" data-preset="standard">📰 استاندارد</button>
                                <button type="button" class="ns-preset-btn" data-preset="frequent">🔄 مکرر</button>
                                <button type="button" class="ns-preset-btn" data-preset="peak">🔥 ساعات اوج</button>
                                <button type="button" class="ns-preset-btn" data-preset="business">💼 کاری</button>
                            </div>
                        </div>
                    </div>

                    <!-- Section 3: Limits -->
                    <div class="ns-section">
                        <h3 class="ns-section-title">
                            <span class="emoji">⚡</span>
                            محدودیت‌های هر اجرا
                        </h3>
                        <p class="ns-section-desc">حداکثر تعداد اخباری که در هر بار اجرای سیستم پردازش می‌شود</p>

                        <div class="ns-numbers-grid">
                            <div class="ns-number-card">
                                <div class="icon-box">✅</div>
                                <div class="title">انتشار مستقیم</div>
                                <div class="desc">تعداد اخباری که بلافاصله منتشر می‌شوند</div>
                                <input type="number"
                                       name="max_publish_per_run"
                                       value="<?= esc_attr($max_publish); ?>"
                                       min="1" max="50"
                                       class="ns-number-input">
                            </div>

                            <div class="ns-number-card">
                                <div class="icon-box">📝</div>
                                <div class="title">پیش‌نویس</div>
                                <div class="desc">اخباری که به عنوان پیش‌نویس ذخیره می‌شوند</div>
                                <input type="number"
                                       name="max_draft_per_run"
                                       value="<?= esc_attr($max_draft); ?>"
                                       min="0" max="50"
                                       class="ns-number-input">
                            </div>

                            <div class="ns-number-card">
                                <div class="icon-box">🤖</div>
                                <div class="title">بررسی با AI</div>
                                <div class="desc">تعداد اخباری که توسط هوش مصنوعی بازنویسی می‌شوند</div>
                                <input type="number"
                                       name="max_ai_review_per_run"
                                       value="<?= esc_attr($max_ai_review); ?>"
                                       min="0" max="20"
                                       class="ns-number-input">
                            </div>

                            <div class="ns-number-card">
                                <div class="icon-box">⏱️</div>
                                <div class="title">بازه زمانی</div>
                                <div class="desc">فقط اخبار این تعداد ساعت اخیر دریافت شوند</div>
                                <input type="number"
                                       name="time_window_hours"
                                       value="<?= esc_attr($time_window); ?>"
                                       min="1" max="168"
                                       class="ns-number-input">
                            </div>
                        </div>
                    </div>

                    <!-- Submit -->
                    <div class="ns-submit-row">
                        <button type="submit" name="save_news_settings" class="ns-btn-primary">
                            💾 ذخیره تنظیمات
                        </button>
                    </div>

                </div>
            </form>
        </div>

        <script>
        jQuery(document).ready(function ($) {

            function pad(n) {
                return n < 10 ? '0' + n : '' + n;
            }

            function updateSummary() {
                var selected = [];
                $('#ns-hours-grid input:checked').each(function () {
                    selected.push(parseInt($(this).val()));
                });
                selected.sort(function (a, b) { return a - b; });

                $('#ns-hours-count').text(selected.length);

                var $tags = $('#ns-selected-tags').empty();

                if (selected.length === 0) {
                    $tags.html('<span class="ns-time-tag empty">⚠️ هیچ ساعتی انتخاب نشده</span>');
                    return;
                }

                selected.forEach(function (h) {
                    $tags.append(
                        '<span class="ns-time-tag">' + pad(h) + ':00</span>'
                    );
                });
            }

            function updatePresetHighlight() {
                var selected = [];
                $('#ns-hours-grid input:checked').each(function () {
                    selected.push(parseInt($(this).val()));
                });
                selected.sort(function (a, b) { return a - b; });

                var key = selected.join(',');

                $('.ns-preset-btn').removeClass('active');

                $('.ns-preset-btn').each(function () {
                    var preset = $(this).data('preset');
                    var presetHours = presets[preset] || [];
                    if (presetHours.join(',') === key) {
                        $(this).addClass('active');
                    }
                });
            }

            var presets = {
                'morning': [8, 10],
                'standard': [8, 12, 18],
                'frequent': [0, 4, 8, 12, 16, 20],
                'peak': [9, 13, 17, 21],
                'business': [8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18]
            };

            /* Hour cell click */
            $('#ns-hours-grid').on('click', '.ns-hour-cell', function (e) {
                if ($(e.target).is('input')) return;
                var $input = $(this).find('input');
                $input.prop('checked', !$input.prop('checked'));
                $(this).toggleClass('checked', $input.prop('checked'));
                updateSummary();
                updatePresetHighlight();
            });

            $('#ns-hours-grid').on('change', 'input', function () {
                $(this).closest('.ns-hour-cell').toggleClass('checked', this.checked);
                updateSummary();
                updatePresetHighlight();
            });

            /* Select all / Clear */
            $('#ns-select-all').on('click', function () {
                $('#ns-hours-grid input').prop('checked', true);
                $('#ns-hours-grid .ns-hour-cell').addClass('checked');
                updateSummary();
                updatePresetHighlight();
            });

            $('#ns-clear-all').on('click', function () {
                $('#ns-hours-grid input').prop('checked', false);
                $('#ns-hours-grid .ns-hour-cell').removeClass('checked');
                updateSummary();
                updatePresetHighlight();
            });

            /* Presets */
            $('.ns-preset-btn').on('click', function () {
                var preset = $(this).data('preset');
                var hours = presets[preset] || [];

                $('#ns-hours-grid input').prop('checked', false);
                $('#ns-hours-grid .ns-hour-cell').removeClass('checked');

                hours.forEach(function (h) {
                    var $cell = $('.ns-hour-cell[data-hour="' + h + '"]');
                    $cell.find('input').prop('checked', true);
                    $cell.addClass('checked');
                });

                updateSummary();
                updatePresetHighlight();
            });

            updateSummary();
            updatePresetHighlight();
        });
        </script>
        <?php
    }
}