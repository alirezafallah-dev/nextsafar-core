<?php

/**
 * NextSafar Sync Page - Integrated Version
 *
 * Combines the new file + hotel/restaurant/destination/hospital sync section from the old file.
 *
 * @version 2.5.0
 */

namespace NextSafar\Admin;

if (!defined('ABSPATH')) exit;

class SyncPage {
    public static function init() {
        add_action('wp_ajax_nextsafar_sync_hotels', [__CLASS__, 'ajax_sync_hotels']);
        add_action('wp_ajax_nextsafar_sync_news', [__CLASS__, 'ajax_sync_news']);
        add_action('wp_ajax_nextsafar_sync_batch', [__CLASS__, 'ajax_sync_batch']);
        add_action('wp_ajax_nextsafar_sync_status', [__CLASS__, 'ajax_sync_status']);
        add_action('wp_ajax_nextsafar_get_ai_models', [__CLASS__, 'ajax_get_ai_models']);
        add_action('wp_ajax_nextsafar_reset_sources', [__CLASS__, 'ajax_reset_sources']);
        add_action('wp_ajax_nextsafar_get_sources_stats', [__CLASS__, 'ajax_get_sources_stats']);
        add_action('wp_ajax_nextsafar_get_filter_history', [__CLASS__, 'ajax_get_filter_history']);
        add_action('wp_ajax_nextsafar_test_api', [__CLASS__, 'ajax_test_api']);
    }

    public static function add_sync_page() {
        add_submenu_page(
            'nextsafar-settings',
            __('همگام‌سازی از API', 'nextsafar'),
            __('همگام‌سازی', 'nextsafar'),
            'manage_options',
            'nextsafar-sync',
            [__CLASS__, 'render_sync_page']
        );
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

            .nextsafar-sync-page .ns-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
            }

            @media (max-width: 960px) {
                .nextsafar-sync-page .ns-grid {
                    grid-template-columns: 1fr;
                }
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

            .nextsafar-sync-page .form-table th {
                width: 180px;
            }
        </style>

        <div class="wrap nextsafar-sync-page">
            <h1>همگام‌سازی از API</h1>

            <div class="notice notice-info" style="margin: 15px 0;">
                <p>
                    برای تنظیمات AI، API Keys و زمان‌بندی، به
                    <a href="admin.php?page=nextsafar-settings">صفحه تنظیمات</a>
                    مراجعه کنید.
                </p>
            </div>

            <!-- General Stats -->
            <div class="ns-card">
                <h2>آمار کلی</h2>

                <div class="ns-stats-grid">
                    <div class="ns-stat">
                        <div class="ns-stat-value" style="color:#2271b1;">
                            <?= number_format($stats['total_posts'] ?? 0); ?>
                        </div>
                        <div class="ns-stat-label">کل اخبار</div>
                    </div>

                    <div class="ns-stat">
                        <div class="ns-stat-value" style="color:#00a32a;">
                            <?= number_format($stats['today_posts'] ?? 0); ?>
                        </div>
                        <div class="ns-stat-label">امروز</div>
                    </div>

                    <div class="ns-stat">
                        <div class="ns-stat-value" style="color:#d63638;">
                            <?= number_format($stats['total_duplicates_blocked'] ?? 0); ?>
                        </div>
                        <div class="ns-stat-label">تکراری مسدود</div>
                    </div>

                    <div class="ns-stat">
                        <div class="ns-stat-value" style="color:#dba617;">
                            $<?= number_format($stats['ai_total_cost_usd'] ?? 0, 2); ?>
                        </div>
                        <div class="ns-stat-label">هزینه AI</div>
                    </div>
                </div>
            </div>

            <div class="ns-grid">
                <!-- API Test -->
                <div class="ns-card">
                    <h2>تست اتصال API ها</h2>
                    <p>قبل از sync، وضعیت API ها را تست کنید:</p>

                    <button id="test-api-btn" class="button button-primary">
                        تست همه API ها
                    </button>

                    <div id="api-test-results" style="margin-top:15px;"></div>
                </div>

                <!-- News Sync -->
                <div class="ns-card">
                    <h2>همگام‌سازی اخبار</h2>
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
            </div>

            <!-- Section added from the old file: Hotel, Restaurant, Destination, Hospital Sync -->
            <div class="ns-card">
                <h2>همگام‌سازی هتل، رستوران، مقصد و بیمارستان</h2>

                <p>
                    از این بخش می‌توانید هتل‌ها، رستوران‌ها، مقاصد گردشگری و بیمارستان‌ها را از APIهای خارجی دریافت و در وردپرس ذخیره کنید.
                </p>

                <table class="form-table">
                    <tr>
                        <th>نوع محتوا:</th>
                        <td>
                            <select id="sync-type">
                                <option value="hotel">هتل</option>
                                <option value="destination">مقصد گردشگری</option>
                                <option value="restaurant">رستوران</option>
                                <option value="hospital">بیمارستان</option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th>منبع داده:</th>
                        <td>
                            <select id="sync-source">
                                <option value="searchapi">SearchApi.io</option>
                                <option value="serpapi">SerpApi</option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th>مقصد جستجو:</th>
                        <td>
                            <input type="text" id="sync-location" placeholder="مثال: Istanbul, Turkey" class="regular-text" dir="ltr">
                        </td>
                    </tr>

                    <tr>
                        <th>تعداد نتایج:</th>
                        <td>
                            <select id="sync-limit">
                                <option value="1">1 مورد (تست)</option>
                                <option value="5">5 مورد</option>
                                <option value="10">10 مورد</option>
                                <option value="20" selected>20 مورد</option>
                                <option value="50">50 مورد</option>
                                <option value="100">100 مورد</option>
                            </select>
                        </td>
                    </tr>
                </table>

                <p>
                    <button id="start-sync" class="button button-primary button-large">
                        شروع همگام‌سازی
                    </button>
                </p>

                <div id="general-progress" style="display:none;">
                    <h3>وضعیت</h3>

                    <div class="ns-progress-bar">
                        <div id="general-progress-fill" class="ns-progress-fill"></div>
                    </div>

                    <div id="sync-log" class="ns-sync-log"></div>
                </div>

                <div id="general-results" style="display:none;">
                    <h3>نتیجه</h3>
                    <div id="sync-results-content"></div>
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
                var nsNewsListUrl = '<?php echo esc_url($news_list_url); ?>';

                function escHtml(str) {
                    if (!str) return '';

                    return str.toString()
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#039;');
                }

                function resetGeneralSyncButton() {
                    $('#start-sync').prop('disabled', false).text('شروع همگام‌سازی');
                }

                /* ========================================================================
                   Load Sources Table
                   ======================================================================== */
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

                            html += '<thead><tr>';
                            html += '<th>منبع</th>';
                            html += '<th>نوع</th>';
                            html += '<th>گروه</th>';
                            html += '<th>آخرین دریافت</th>';
                            html += '<th>دریافتی</th>';
                            html += '<th>تکراری</th>';
                            html += '<th>وضعیت</th>';
                            html += '</tr></thead><tbody>';

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
                    if (!confirm('همه منابع فعلی حذف و پیش‌فرض جایگزین می‌شود. ادامه؟')) {
                        return;
                    }

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

                /* ========================================================================
                   Test APIs
                   ======================================================================== */
                $('#test-api-btn').on('click', function () {
                    var btn = $(this);

                    btn.prop('disabled', true).text('⏳ در حال تست...');
                    $('#api-test-results').html('<p>در حال بررسی...</p>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'nextsafar_test_api',
                            nonce: nsNonce,
                            force_refresh: '1'
                        },
                        success: function (response) {
                            btn.prop('disabled', false).text('تست همه API ها');

                            if (!response.success) {
                                $('#api-test-results').html('<p style="color:red;">خطا: ' + (response.data.message || response.data) + '</p>');
                                return;
                            }

                            var results = Array.isArray(response.data) ? response.data : [];

                            var html = '<table class="widefat">';

                            html += '<thead><tr><th>API</th><th>وضعیت</th><th>جزئیات</th></tr></thead><tbody>';

                            results.forEach(function (r) {
                                var status = r.success ? '✅' : '⚠️';
                                var color = r.success ? '#00a32a' : '#dba617';

                                html += '<tr>';
                                html += '<td><strong>' + escHtml(r.name) + '</strong></td>';
                                html += '<td style="color:' + color + ';">' + status + '</td>';
                                html += '<td>' + escHtml(r.message) + '</td>';
                                html += '</tr>';
                            });

                            html += '</tbody></table>';
                            html += '<p style="margin-top:10px;color:#666;font-size:12px;">نتیجه برای 2 دقیقه کش می‌شود. برای تست مجدد دکمه را دوباره بزنید.</p>';

                            $('#api-test-results').html(html);
                        },
                        error: function () {
                            btn.prop('disabled', false).text('🧪 تست همه API ها');
                            $('#api-test-results').html('<p style="color:red;">❌ خطا در ارتباط با سرور</p>');
                        }
                    });
                });

                /* ========================================================================
                   News Sync
                   ======================================================================== */
                var newsSyncInProgress = false;

                $('#start-news-sync').on('click', function () {
                    if (newsSyncInProgress) {
                        alert('یک همگام‌سازی در حال اجرا است!');
                        return;
                    }

                    var mode = $('input[name="news_sync_mode"]:checked').val();

                    newsSyncInProgress = true;

                    var btn = $(this);

                    btn.prop('disabled', true).text('⏳ در حال اجرا...');

                    $('#news-progress').show();
                    $('#news-results').hide();
                    $('#news-progress-fill').css('width', '0%');
                    $('#news-sync-log').html('شروع دریافت اخبار...\n');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'nextsafar_sync_news',
                            nonce: nsNonce,
                            mode: mode
                        },
                        success: function (response) {
                            newsSyncInProgress = false;

                            btn.prop('disabled', false).text('شروع دریافت اخبار');

                            if (!response.success) {
                                $('#news-sync-log').append('❌ ' + (response.data.message || response.data) + '\n');
                                return;
                            }

                            var d = response.data || {};

                            $('#news-progress-fill').css('width', '100%');

                            $('#news-sync-log').append('RSS: ' + Number(d.fetched_rss || 0).toLocaleString() + '\n');
                            $('#news-sync-log').append('API: ' + Number(d.fetched_api || 0).toLocaleString() + '\n');
                            $('#news-sync-log').append('ایجاد: ' + Number(d.created || 0).toLocaleString() + '\n');
                            $('#news-sync-log').append('تکراری: ' + Number(d.duplicates || 0).toLocaleString() + '\n');

                            if (d.filtered_out) {
                                $('#news-sync-log').append('فیلتر: ' + Number(d.filtered_out || 0).toLocaleString() + '\n');
                            }

                            if (d.ai_used) {
                                $('#news-sync-log').append('بازنویسی با AI: ' + Number(d.ai_used || 0).toLocaleString() + '\n');
                            }

                            if (d.failed) {
                                $('#news-sync-log').append('❌ ناموفق: ' + Number(d.failed || 0).toLocaleString() + '\n');
                            }

                            if (d.errors && d.errors.length > 0) {
                                $('#news-sync-log').append('\n⚠️ خطاها:\n');

                                d.errors.slice(0, 5).forEach(function (e) {
                                    $('#news-sync-log').append('  - ' + e + '\n');
                                });

                                if (d.errors.length > 5) {
                                    $('#news-sync-log').append('  ... و ' + (d.errors.length - 5) + ' خطای دیگر\n');
                                }
                            }

                            $('#news-sync-log').append('\nکامل!\n');

                            var html = '<table class="widefat">';

                            html += '<tr><th>مورد</th><th>تعداد</th></tr>';
                            html += '<tr><td>ایجاد</td><td style="color:#00a32a;">' + Number(d.created || 0).toLocaleString() + '</td></tr>';
                            html += '<tr><td>تکراری</td><td>' + Number(d.duplicates || 0).toLocaleString() + '</td></tr>';
                            html += '<tr><td>فیلتر شده</td><td>' + Number(d.filtered_out || 0).toLocaleString() + '</td></tr>';
                            html += '<tr><td>بازنویسی با AI</td><td>' + Number(d.ai_used || 0).toLocaleString() + '</td></tr>';
                            html += '<tr><td>ناموفق</td><td>' + Number(d.failed || 0).toLocaleString() + '</td></tr>';
                            html += '</table>';
                            html += '<p><a href="' + nsNewsListUrl + '" target="_blank">مشاهده اخبار</a></p>';

                            $('#news-results-content').html(html);
                            $('#news-results').show();

                            loadSourcesTable();
                        },
                        error: function (xhr) {
                            newsSyncInProgress = false;

                            btn.prop('disabled', false).text('شروع دریافت اخبار');
                            $('#news-sync-log').append('❌ خطا در ارتباط: ' + (xhr.statusText || 'نامشخص') + '\n');
                        }
                    });
                });

                /* ========================================================================
                   Hotel / Restaurant / Destination / Hospital Sync
                   This section was added from the old file.
                   ======================================================================== */
                var syncInProgress = false;

                $('#start-sync').on('click', function () {
                    if (syncInProgress) {
                        alert('یک همگام‌سازی در حال اجرا است!');
                        return;
                    }

                    var location = $.trim($('#sync-location').val());

                    if (!location) {
                        alert('مقصد جستجو را وارد کنید');
                        return;
                    }

                    syncInProgress = true;

                    var btn = $(this);

                    btn.prop('disabled', true).text('⏳ در حال شروع...');

                    $('#general-progress').show();
                    $('#general-results').hide();
                    $('#general-progress-fill').css('width', '0%');
                    $('#sync-log').html('شروع...\n');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'nextsafar_sync_hotels',
                            nonce: nsNonce,
                            sync_type: $('#sync-type').val(),
                            source: $('#sync-source').val(),
                            location: location,
                            limit: $('#sync-limit').val()
                        },
                        success: function (response) {
                            if (!response.success) {
                                syncInProgress = false;
                                resetGeneralSyncButton();
                                $('#sync-log').append('❌ ' + (response.data.message || response.data) + '\n');
                                return;
                            }

                            var d = response.data || {};

                            if (d.message) {
                                $('#sync-log').append('ℹ️ ' + d.message + '\n');
                            }

                            var batchResult = d.batch_result || d;

                            if (batchResult && batchResult.completed) {
                                syncInProgress = false;
                                resetGeneralSyncButton();
                                $('#general-progress-fill').css('width', '100%');
                                showGeneralResults(batchResult);
                                return;
                            }

                            if (d.total_batches) {
                                $('#sync-log').append('دسته‌ها: ' + d.total_batches + '\n');
                            }

                            processNextBatch();
                        },
                        error: function (xhr) {
                            syncInProgress = false;
                            resetGeneralSyncButton();
                            $('#sync-log').append('❌ ' + (xhr.statusText || 'خطا در ارتباط با سرور') + '\n');
                        }
                    });
                });

                function processNextBatch() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'nextsafar_sync_batch',
                            nonce: nsNonce
                        },
                        success: function (response) {
                            if (!response.success) {
                                syncInProgress = false;
                                resetGeneralSyncButton();
                                $('#sync-log').append('❌ ' + (response.data.message || response.data) + '\n');
                                return;
                            }

                            var r = response.data || {};

                            $('#general-progress-fill').css('width', (r.progress_percent || 0) + '%');

                            $('#sync-log').append(
                                '📦 دسته ' + (r.current_batch || '?') + '/' + (r.total_batches || '?') +
                                ' | ✅ ' + (r.created || 0) +
                                ' | 🔄 ' + (r.updated || 0) +
                                ' | ❌ ' + (r.failed || 0) + '\n'
                            );

                            if (r.completed) {
                                syncInProgress = false;
                                resetGeneralSyncButton();
                                $('#general-progress-fill').css('width', '100%');
                                showGeneralResults(r);
                            } else {
                                setTimeout(processNextBatch, 700);
                            }
                        },
                        error: function (xhr) {
                            syncInProgress = false;
                            resetGeneralSyncButton();
                            $('#sync-log').append('❌ خطا در ارتباط: ' + (xhr.statusText || 'نامشخص') + '\n');
                        }
                    });
                }

                function showGeneralResults(r) {
                    var html = '<table class="widefat">';

                    html += '<tr><th>مورد</th><th>تعداد</th></tr>';
                    html += '<tr><td>کل</td><td>' + Number(r.total || 0).toLocaleString() + '</td></tr>';
                    html += '<tr><td>ایجاد</td><td style="color:#00a32a;">' + Number(r.created || 0).toLocaleString() + '</td></tr>';
                    html += '<tr><td>به‌روزرسانی</td><td>' + Number(r.updated || 0).toLocaleString() + '</td></tr>';
                    html += '<tr><td>ناموفق</td><td style="color:#d63638;">' + Number(r.failed || 0).toLocaleString() + '</td></tr>';
                    html += '</table>';

                    if (r.message) {
                        html += '<p>' + escHtml(r.message) + '</p>';
                    }

                    $('#sync-results-content').html(html);
                    $('#general-results').show();
                }
            });
        </script>
        <?php
    }

    /**
     * AJAX: Test APIs.
     */
    public static function ajax_test_api() {
        check_ajax_referer('nextsafar_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز']);
        }

        $force_refresh = isset($_POST['force_refresh']) && $_POST['force_refresh'] === '1';
        $cache_key = 'nextsafar_api_test_results';

        if (!$force_refresh) {
            $cached = get_transient($cache_key);

            if ($cached !== false) {
                wp_send_json_success($cached);
                return;
            }
        }

        $results = [];

        // Test GNews.
        $gnews_key = get_option('nextsafar_gnews_api_key', '');

        if (!empty($gnews_key)) {
            $url = 'https://gnews.io/api/v4/top-headlines?lang=en&max=1&apikey=' . urlencode($gnews_key);
            $response = wp_remote_get($url, ['timeout' => 15]);

            if (is_wp_error($response)) {
                $results[] = ['name' => 'GNews.io', 'success' => false, 'message' => $response->get_error_message()];
            } else {
                $code = wp_remote_retrieve_response_code($response);
                $body = json_decode(wp_remote_retrieve_body($response), true);

                if ($code === 200 && !empty($body['articles'])) {
                    $results[] = ['name' => 'GNews.io', 'success' => true, 'message' => '✅ متصل (' . count($body['articles']) . ' خبر)'];
                } else {
                    $error = $body['errors'][0]['message'] ?? ($body['message'] ?? "HTTP {$code}");
                    $results[] = ['name' => 'GNews.io', 'success' => false, 'message' => $error];
                }
            }
        } else {
            $results[] = ['name' => 'GNews.io', 'success' => false, 'message' => 'کلید تنظیم نشده'];
        }

        // Test NewsData.
        $newsdata_key = get_option('nextsafar_newsdata_api_key', '');

        if (!empty($newsdata_key)) {
            $url = 'https://newsdata.io/api/1/latest?apikey=' . urlencode($newsdata_key) . '&language=en&size=1';
            $response = wp_remote_get($url, ['timeout' => 15]);

            if (is_wp_error($response)) {
                $results[] = ['name' => 'NewsData.io', 'success' => false, 'message' => $response->get_error_message()];
            } else {
                $code = wp_remote_retrieve_response_code($response);
                $body = json_decode(wp_remote_retrieve_body($response), true);

                if ($code === 200 && ($body['status'] ?? '') === 'success') {
                    $results[] = ['name' => 'NewsData.io', 'success' => true, 'message' => '✅ متصل (' . ($body['totalResults'] ?? 0) . ' نتیجه)'];
                } else {
                    $results[] = ['name' => 'NewsData.io', 'success' => false, 'message' => $body['results']['message'] ?? "HTTP {$code}"];
                }
            }
        } else {
            $results[] = ['name' => 'NewsData.io', 'success' => false, 'message' => 'کلید تنظیم نشده'];
        }

        // Test Currents.
        $currents_key = get_option('nextsafar_currents_api_key', '');

        if (!empty($currents_key)) {
            $response = wp_remote_get('https://api.currentsapi.services/v1/latest-news?language=en', [
                'timeout' => 15,
                'headers' => ['Authorization' => $currents_key],
            ]);

            if (is_wp_error($response)) {
                $results[] = ['name' => 'Currents API', 'success' => false, 'message' => $response->get_error_message()];
            } else {
                $code = wp_remote_retrieve_response_code($response);
                $body = json_decode(wp_remote_retrieve_body($response), true);

                if ($code === 200 && !empty($body['news'])) {
                    $results[] = ['name' => 'Currents API', 'success' => true, 'message' => '✅ متصل (' . count($body['news']) . ' خبر)'];
                } else {
                    $results[] = ['name' => 'Currents API', 'success' => false, 'message' => $body['message'] ?? "HTTP {$code}"];
                }
            }
        } else {
            $results[] = ['name' => 'Currents API', 'success' => false, 'message' => 'کلید تنظیم نشده'];
        }

        // SearchApi and SerpApi.
        $searchapi_key = get_option('nextsafar_searchapi_key', '');

        $results[] = [
            'name' => 'SearchApi.io',
            'success' => !empty($searchapi_key),
            'message' => !empty($searchapi_key) ? '✅ کلید تنظیم شده' : 'کلید تنظیم نشده',
        ];

        $serpapi_key = get_option('nextsafar_serpapi_key', '');

        $results[] = [
            'name' => 'SerpApi',
            'success' => !empty($serpapi_key),
            'message' => !empty($serpapi_key) ? '✅ کلید تنظیم شده' : 'کلید تنظیم نشده',
        ];

        // FIX: Test Gemini with updated models.
        $gemini_key = get_option('nextsafar_gemini_api_key', '');

        if (!empty($gemini_key)) {
            // Use updated models.
            $valid_models = ['gemini-3.7-flash', 'gemini-3.6-flash', 'gemini-3.5-flash', 'gemini-3.0-flash'];
            $selected_model = get_option('nextsafar_news_ai_model', 'gemini-3.7-flash');

            if (!in_array($selected_model, $valid_models, true)) {
                $selected_model = $valid_models[0];
            }

            $models_to_try = array_unique(array_merge([$selected_model], $valid_models));

            $success = false;
            $last_error = '';
            $last_error_code = 0;
            $working_model = '';
            $attempted_models = [];

            foreach ($models_to_try as $model) {
                $attempted_models[] = $model;

                $response = self::call_gemini($gemini_key, $model);

                // Improve temporary error handling.
                if (
                    $response['code'] === 429 ||
                    $response['code'] === 503 ||
                    stripos($response['error'], 'high demand') !== false ||
                    stripos($response['error'], 'overloaded') !== false
                ) {
                    sleep(3);
                    $response = self::call_gemini($gemini_key, $model);
                }

                if ($response['code'] === 200) {
                    $success = true;
                    $working_model = $model;
                    break;
                }

                $last_error = $response['error'];
                $last_error_code = $response['code'];

                // Continue to the next model for specific errors.
                if (
                    $response['code'] === 404 ||
                    stripos($response['error'], 'no longer available') !== false
                ) {
                    continue;
                }

                // Quota error = continue to the next model.
                if (stripos($response['error'], 'quota') !== false) {
                    continue;
                }

                break;
            }

            if ($success) {
                if ($working_model !== $selected_model) {
                    $message = sprintf(
                        '✅ متصل با fallback (مدل: %s) - مدل انتخابی (%s) در دسترس نبود',
                        $working_model,
                        $selected_model
                    );
                } else {
                    $message = "✅ متصل (مدل: {$working_model})";
                }

                $results[] = ['name' => 'Gemini AI', 'success' => true, 'message' => $message];
            } else {
                $error_type = 'نامشخص';

                if (stripos($last_error, 'quota') !== false) {
                    $error_type = 'محدودیت رایگان (Quota Exceeded) - کلید API پولی نیاز است';
                } elseif (stripos($last_error, 'API key') !== false || stripos($last_error, 'invalid') !== false) {
                    $error_type = 'کلید API نامعتبر';
                } elseif ($last_error_code === 429) {
                    $error_type = 'محدودیت نرخ (Rate Limit)';
                }

                $message = sprintf(
                    '⚠️ %s. مدل‌های تست شده: %s',
                    $error_type,
                    implode('، ', $attempted_models)
                );

                $results[] = ['name' => 'Gemini AI', 'success' => false, 'message' => $message];
            }
        } else {
            $results[] = ['name' => 'Gemini AI', 'success' => false, 'message' => 'کلید تنظیم نشده'];
        }

        set_transient($cache_key, $results, 120);

        wp_send_json_success($results);
    }

    /**
     * FIX: Removed AI disabling in Quick mode.
     */
    public static function ajax_sync_news() {
        // FIX 1: Release session lock (main reason for the white screen).
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        @ini_set('max_execution_time', 0);
        @ini_set('memory_limit', '1024M');

        check_ajax_referer('nextsafar_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز']);
        }

        $mode = sanitize_key($_POST['mode'] ?? 'full'); // Change default to 'full'.

        try {
            if (!class_exists('\NextSafar\API\NewsSync')) {
                throw new \Exception('کلاس NewsSync در دسترس نیست.');
            }

            $sync = new \NextSafar\API\NewsSync();
            $result = $sync->sync_news();

            wp_send_json_success($result);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * FIX: The get_gemini_models method is now implemented in AIRewriter.
     */
    public static function ajax_get_ai_models() {
        check_ajax_referer('nextsafar_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'دسترسی غیرمجاز']);
        }

        $provider = sanitize_key($_POST['provider'] ?? 'gemini');

        if (!class_exists('\NextSafar\API\AIRewriter')) {
            wp_send_json_error(['error' => 'کلاس AIRewriter در دسترس نیست.']);
        }

        try {
            if ($provider === 'openai') {
                $result = \NextSafar\API\AIRewriter::get_openai_models();
            } else {
                $result = \NextSafar\API\AIRewriter::get_gemini_models();
            }
        } catch (\Throwable $e) {
            wp_send_json_error(['error' => $e->getMessage()]);
        }

        if (isset($result['error'])) {
            wp_send_json_error(['error' => $result['error']]);
        }

        wp_send_json_success($result);
    }

    /**
     * Helper function to call Gemini.
     */
    private static function call_gemini(string $api_key, string $model): array {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";

        $response = wp_remote_post($url, [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'contents' => [
                    [
                        'parts' => [['text' => 'Say OK']],
                    ],
                ],
                'generationConfig' => [
                    'maxOutputTokens' => 32,
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            return ['code' => 0, 'error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200) {
            return ['code' => 200, 'error' => ''];
        }

        $error = $body['error']['message'] ?? "HTTP {$code}";

        return ['code' => $code, 'error' => $error];
    }

    /**
     * AJAX: Start hotel / restaurant / destination / hospital sync.
     */
    public static function ajax_sync_hotels() {
        check_ajax_referer('nextsafar_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز']);
        }

        $sync_type = sanitize_key($_POST['sync_type'] ?? 'hotel');
        $source    = sanitize_key($_POST['source'] ?? 'searchapi');
        $location  = sanitize_text_field(wp_unslash($_POST['location'] ?? ''));
        $limit     = isset($_POST['limit']) ? absint($_POST['limit']) : 20;

        $allowed_types = ['hotel', 'destination', 'restaurant', 'hospital'];

        if (!in_array($sync_type, $allowed_types, true)) {
            wp_send_json_error(['message' => 'نوع محتوا معتبر نیست']);
        }

        $allowed_sources = ['searchapi', 'serpapi'];

        if (!in_array($source, $allowed_sources, true)) {
            $source = 'searchapi';
        }

        if ($location === '') {
            wp_send_json_error(['message' => 'مقصد جستجو خالی است']);
        }

        if ($limit < 1) {
            $limit = 1;
        }

        if ($limit > 100) {
            $limit = 100;
        }

        // Check API key.
        if ($source === 'serpapi') {
            $key = get_option('nextsafar_serpapi_key', '');

            if (empty($key)) {
                wp_send_json_error(['message' => 'کلید SerpApi تنظیم نشده!']);
            }
        } else {
            $key = get_option('nextsafar_searchapi_key', '');

            if (empty($key)) {
                wp_send_json_error(['message' => 'کلید SearchApi تنظیم نشده!']);
            }
        }

        if (!class_exists('\NextSafar\API\BatchSync') || !method_exists('\NextSafar\API\BatchSync', 'start_sync')) {
            wp_send_json_error(['message' => 'کلاس BatchSync برای همگام‌سازی در دسترس نیست.']);
        }

        try {
            $result = \NextSafar\API\BatchSync::start_sync($sync_type, $location, $source, $limit);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        if (empty($result['success'])) {
            wp_send_json_error([
                'message' => $result['message'] ?? 'خطا در شروع همگام‌سازی',
            ]);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Process next batch.
     */
    public static function ajax_sync_batch() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        check_ajax_referer('nextsafar_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز']);
        }

        if (!class_exists('\NextSafar\API\BatchSync') || !method_exists('\NextSafar\API\BatchSync', 'process_next_batch')) {
            wp_send_json_error(['message' => 'کلاس BatchSync برای پردازش دسته‌ها در دسترس نیست.']);
        }

        try {
            $result = \NextSafar\API\BatchSync::process_next_batch();
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Sync status.
     */
    public static function ajax_sync_status() {
        check_ajax_referer('nextsafar_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز']);
        }

        if (!class_exists('\NextSafar\API\BatchSync') || !method_exists('\NextSafar\API\BatchSync', 'get_status')) {
            wp_send_json_success([
                'status'  => 'unsupported',
                'message' => 'متد get_status در BatchSync وجود ندارد.',
            ]);
        }

        try {
            $result = \NextSafar\API\BatchSync::get_status();
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Reset sources.
     */
    public static function ajax_reset_sources() {
        check_ajax_referer('nextsafar_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز']);
        }

        if (!class_exists('\NextSafar\API\NewsSync') || !method_exists('\NextSafar\API\NewsSync', 'reset_sources')) {
            wp_send_json_error(['message' => 'متد reset_sources در NewsSync وجود ندارد.']);
        }

        try {
            $result = \NextSafar\API\NewsSync::reset_sources();
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Get sources stats.
     */
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

    /**
     * AJAX: Get news filter history.
     */
    public static function ajax_get_filter_history() {
        check_ajax_referer('nextsafar_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'دسترسی غیرمجاز']);
        }

        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 20;

        if ($limit < 1) {
            $limit = 20;
        }

        if ($limit > 100) {
            $limit = 100;
        }

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