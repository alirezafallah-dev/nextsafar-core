<?php

/**
 * NextSafar Sync Page - فقط اخبار
 * بخش هتل/رستوران/مقصد/بیمارستان به فایل post-generator.php منتقل شد
 *
 * @version 3.0.0
 */

namespace NextSafar\Admin;

if (!defined('ABSPATH')) exit;

class SyncPage {
    public static function init() {
        add_action('wp_ajax_nextsafar_sync_news', [__CLASS__, 'ajax_sync_news']);
        add_action('wp_ajax_nextsafar_reset_sources', [__CLASS__, 'ajax_reset_sources']);
        add_action('wp_ajax_nextsafar_get_sources_stats', [__CLASS__, 'ajax_get_sources_stats']);
        add_action('wp_ajax_nextsafar_get_filter_history', [__CLASS__, 'ajax_get_filter_history']);
    }

    public static function render_sync_page() {
        // Ensure tables exist.
        if (class_exists('\NextSafar\Database\NewsTables') && method_exists('\NextSafar\Database\NewsTables', 'create_all_tables')) {
            \NextSafar\Database\NewsTables::create_all_tables();
        } elseif (class_exists('\NextSafar\API\NewsSync') && method_exists('\NextSafar\API\NewsSync', 'ensure_tables_exist')) {
            \NextSafar\API\NewsSync::ensure_tables_exist();
        }

        $stats = [
            'total_posts'              => 0,
            'today_posts'              => 0,
            'total_duplicates_blocked' => 0,
            'ai_total_cost_usd'        => 0,
        ];

        if (class_exists('\NextSafar\API\NewsSync') && method_exists('\NextSafar\API\NewsSync', 'get_overview_stats')) {
            $maybe_stats = \NextSafar\API\NewsSync::get_overview_stats();

            if (!is_array($maybe_stats)) {
                $maybe_stats = (array) $maybe_stats;
            }

            $stats = wp_parse_args($maybe_stats, $stats);
        }

        $nonce         = wp_create_nonce('nextsafar_sync');
        $news_list_url = admin_url('edit.php?post_type=travelnews');
        ?>
        <style>
            .nextsafar-sync-page .ns-card {
                background: #fff;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
                margin-bottom: 20px;
            }
            .nextsafar-sync-page .ns-stats-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 15px;
            }
            @media (max-width: 960px) {
                .nextsafar-sync-page .ns-stats-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
            }
            .nextsafar-sync-page .ns-stat {
                text-align: center;
                padding: 15px;
                background: #f0f0f1;
                border-radius: 8px;
            }
            .nextsafar-sync-page .ns-stat-value {
                font-size: 24px;
                font-weight: bold;
            }
            .nextsafar-sync-page .ns-stat-label {
                color: #666;
            }
            .nextsafar-sync-page .ns-radio {
                display: block;
                margin: 10px 0;
            }
            .nextsafar-sync-page .ns-progress-bar {
                background: #f0f0f1;
                border-radius: 4px;
                height: 20px;
                margin: 15px 0;
                overflow: hidden;
            }
            .nextsafar-sync-page .ns-progress-fill {
                background: #2271b1;
                height: 100%;
                width: 0%;
                transition: width 0.5s ease;
            }
            .nextsafar-sync-page .ns-sync-log {
                font-family: monospace;
                background: #1d2327;
                color: #00ff00;
                padding: 15px;
                border-radius: 4px;
                min-height: 100px;
                white-space: pre-wrap;
                font-size: 12px;
            }
        </style>

        <div class="wrap nextsafar-sync-page">
            <h1>تنظیمات اخبار هوشمند</h1>

            <!-- General Stats -->
            <div class="ns-card">
                <h2>آمار کلی</h2>
                <div class="ns-stats-grid">
                    <div class="ns-stat">
                        <div class="ns-stat-value" style="color:#2271b1;"><?= number_format($stats['total_posts'] ?? 0); ?></div>
                        <div class="ns-stat-label">کل اخبار</div>
                    </div>
                    <div class="ns-stat">
                        <div class="ns-stat-value" style="color:#00a32a;"><?= number_format($stats['today_posts'] ?? 0); ?></div>
                        <div class="ns-stat-label">امروز</div>
                    </div>
                    <div class="ns-stat">
                        <div class="ns-stat-value" style="color:#d63638;"><?= number_format($stats['total_duplicates_blocked'] ?? 0); ?></div>
                        <div class="ns-stat-label">تکراری مسدود</div>
                    </div>
                    <div class="ns-stat">
                        <div class="ns-stat-value" style="color:#dba617;">$<?= number_format($stats['ai_total_cost_usd'] ?? 0, 2); ?></div>
                        <div class="ns-stat-label">هزینه AI</div>
                    </div>
                </div>
            </div>

            <!-- News Sync -->
            <div class="ns-card">
                <h2>📰 همگام‌سازی اخبار</h2>
                <p>دریافت اخبار از همه منابع RSS و API</p>

                <label class="ns-radio">
                    <input type="radio" name="news_sync_mode" value="quick" checked>
                    سریع (بدون AI بازنویسی)
                </label>

                <label class="ns-radio">
                    <input type="radio" name="news_sync_mode" value="ai">
                    با بازنویسی AI
                </label>

                <p style="margin-top:15px;">
                    <button id="start-news-sync" class="button button-primary button-large" style="background:#00a32a;border-color:#00a32a;">
                        شروع دریافت اخبار
                    </button>
                </p>

                <div id="news-progress" style="display:none;">
                    <div class="ns-progress-bar">
                        <div id="news-progress-fill" class="ns-progress-fill"></div>
                    </div>
                    <div id="news-sync-log" class="ns-sync-log"></div>
                </div>

                <div id="news-results" style="display:none;">
                    <h3>نتیجه</h3>
                    <div id="news-results-content"></div>
                </div>
            </div>

            <!-- Sources Status -->
            <div class="ns-card">
                <h2>وضعیت منابع RSS</h2>

                <button type="button" id="reset-sources-btn" class="button button-secondary">
                    بازنشانی منابع پیش‌فرض
                </button>

                <button type="button" id="refresh-sources-btn" class="button button-primary">
                    بارگذاری مجدد
                </button>

                <div id="sources-table-container" style="margin-top:15px;"></div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                var nsNonce = '<?php echo esc_js($nonce); ?>';

                function escHtml(str) {
                    if (!str) return '';
                    return str.toString()
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#039;');
                }

                function loadSourcesTable() {
                    $('#sources-table-container').html('<p>در حال بارگذاری...</p>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'nextsafar_get_sources_stats',
                            nonce: nsNonce
                        },
                        success: function (response) {
                            if (!response.success) {
                                $('#sources-table-container').html('<p class="error">❌ خطا در بارگذاری منابع</p>');
                                return;
                            }

                            var sources = response.data;
                            if (!Array.isArray(sources)) {
                                sources = sources.sources || [];
                            }

                            if (!sources || sources.length === 0) {
                                $('#sources-table-container').html('<p>منبعی ثبت نشده</p>');
                                return;
                            }

                            var html = '<table class="wp-list-table widefat striped">';
                            html += '<thead><tr><th>منبع</th><th>نوع</th><th>گروه</th><th>آخرین دریافت</th><th>دریافتی</th><th>تکراری</th><th>وضعیت</th></tr></thead><tbody>';

                            sources.forEach(function (s) {
                                var type = (s.type || 'rss').toString().toUpperCase();
                                html += '<tr>';
                                html += '<td><strong>' + escHtml(s.name) + '</strong></td>';
                                html += '<td>' + escHtml(type) + '</td>';
                                html += '<td>' + escHtml(s.group_name || '-') + '</td>';
                                html += '<td>' + (s.last_fetch ? escHtml(s.last_fetch) : '<em>هرگز</em>') + '</td>';
                                html += '<td>' + Number(s.total_fetched || 0).toLocaleString() + '</td>';
                                html += '<td>' + Number(s.total_duplicates || 0).toLocaleString() + '</td>';
                                html += '<td>';

                                if (s.is_active == 1 || s.is_active === true) {
                                    html += '<span style="color:#00a32a;">✅ فعال</span>';
                                } else {
                                    html += '<span style="color:#d63638;">❌ غیرفعال</span>';
                                }

                                if (s.error_message) {
                                    html += '<br><small style="color:#d63638;">' + escHtml(s.error_message) + '</small>';
                                }

                                html += '</td></tr>';
                            });

                            html += '</tbody></table>';
                            $('#sources-table-container').html(html);
                        },
                        error: function () {
                            $('#sources-table-container').html('<p class="error">❌ خطا در ارتباط با سرور</p>');
                        }
                    });
                }

                loadSourcesTable();
                $('#refresh-sources-btn').on('click', loadSourcesTable);

                $('#reset-sources-btn').on('click', function () {
                    if (!confirm('همه منابع فعلی حذف و پیش‌فرض جایگزین می‌شود. ادامه؟')) return;

                    var btn = $(this);
                    btn.prop('disabled', true).text('⏳ در حال بازنشانی...');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'nextsafar_reset_sources',
                            nonce: nsNonce
                        },
                        success: function (response) {
                            btn.prop('disabled', false).text('بازنشانی منابع پیش‌فرض');
                            if (response.success) {
                                alert('✅ ' + (response.data.message || response.data));
                                loadSourcesTable();
                            } else {
                                alert('❌ خطا: ' + (response.data.message || response.data));
                            }
                        },
                        error: function () {
                            btn.prop('disabled', false).text('بازنشانی منابع پیش‌فرض');
                            alert('❌ خطا در ارتباط با سرور');
                        }
                    });
                });

                var newsSyncInProgress = false;

                $('#start-news-sync').on('click', function () {
                    if (newsSyncInProgress) {
                        alert('یک همگام‌سازی در حال اجرا است!');
                        return;
                    }

                    var mode = $('input[name="news_sync_mode"]:checked').val();
                    var btn = $(this);

                    newsSyncInProgress = true;
                    btn.prop('disabled', true).text('⏳ در حال دریافت...');
                    $('#news-progress').show();
                    $('#news-results').hide();
                    $('#news-sync-log').text('');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'nextsafar_sync_news',
                            nonce: nsNonce,
                            mode: mode
                        },
                        success: function (response) {
                            if (response.success) {
                                $('#news-sync-log').text('✅ ' + (response.data.message || response.data));
                                if (response.data.redirect_url) {
                                    setTimeout(function () {
                                        window.location.href = response.data.redirect_url;
                                    }, 2000);
                                }
                            } else {
                                $('#news-sync-log').text('❌ ' + (response.data.message || response.data));
                            }
                        },
                        error: function () {
                            $('#news-sync-log').text('❌ خطا در ارتباط با سرور');
                        },
                        complete: function () {
                            btn.prop('disabled', false).text('شروع دریافت اخبار');
                            newsSyncInProgress = false;
                        }
                    });
                });
            });
        </script>
        <?php
    }

    /* ========================================================================
       AJAX Handlers
       ======================================================================== */

    public static function ajax_sync_news() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        check_ajax_referer('nextsafar_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز']);
        }

        $mode = sanitize_key($_POST['mode'] ?? 'quick');

        if (!class_exists('\NextSafar\API\NewsSync') || !method_exists('\NextSafar\API\NewsSync', 'run_sync')) {
            wp_send_json_error(['message' => 'کلاس NewsSync در دسترس نیست']);
        }

        try {
            $result = \NextSafar\API\NewsSync::run_sync($mode);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        if (!empty($result['success'])) {
            $news_list_url = admin_url('edit.php?post_type=travelnews');
            wp_send_json_success([
                'message'      => $result['message'] ?? 'همگام‌سازی کامل شد',
                'redirect_url' => $news_list_url,
            ]);
        }

        wp_send_json_error(['message' => $result['message'] ?? 'خطا در همگام‌سازی']);
    }

    public static function ajax_reset_sources() {
        check_ajax_referer('nextsafar_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز']);
        }

        if (!class_exists('\NextSafar\API\NewsSync') || !method_exists('\NextSafar\API\NewsSync', 'reset_sources')) {
            wp_send_json_error(['message' => 'متد reset_sources در دسترس نیست']);
        }

        try {
            $result = \NextSafar\API\NewsSync::reset_sources();
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        wp_send_json_success($result);
    }

    public static function ajax_get_sources_stats() {
        check_ajax_referer('nextsafar_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز']);
        }

        if (!class_exists('\NextSafar\API\NewsSync') || !method_exists('\NextSafar\API\NewsSync', 'get_sources_stats')) {
            wp_send_json_success([]);
        }

        try {
            $result = \NextSafar\API\NewsSync::get_sources_stats();
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        wp_send_json_success($result);
    }

    public static function ajax_get_filter_history() {
        check_ajax_referer('nextsafar_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'دسترسی غیرمجاز']);
        }

        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 20;
        $limit = max(1, min(100, $limit));

        if (!class_exists('\NextSafar\API\NewsFilter') || !method_exists('\NextSafar\API\NewsFilter', 'get_filter_history')) {
            wp_send_json_success([]);
        }

        try {
            $history = \NextSafar\API\NewsFilter::get_filter_history($limit);
        } catch (\Throwable $e) {
            wp_send_json_error(['error' => $e->getMessage()]);
        }

        wp_send_json_success($history);
    }
}