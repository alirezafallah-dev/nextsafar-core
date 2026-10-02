<?php
/**
 * NextSafar Post Generator
 * Smart post generation: Hotels, Restaurants, Destinations, Hospitals
 */

namespace NextSafar\Admin;

if (!defined('ABSPATH')) exit;

class PostGenerator {

    /* ========================================================================
       Init
       ======================================================================== */
    public static function init(): void {
        add_action('wp_ajax_nextsafar_pg_sync', [__CLASS__, 'ajax_sync']);
        add_action('wp_ajax_nextsafar_pg_batch', [__CLASS__, 'ajax_batch']);
        add_action('wp_ajax_nextsafar_pg_status', [__CLASS__, 'ajax_status']);
        add_action('wp_ajax_nextsafar_pg_test_api', [__CLASS__, 'ajax_test_api']);
    }

    /* ========================================================================
       Render Page
       ======================================================================== */
    public static function render_page(): void {
        if (!current_user_can('manage_options')) return;

        $nonce = wp_create_nonce('nextsafar_pg');
        ?>
        <style>
            .pg-card {
                background: #fff;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
                margin-bottom: 20px;
            }

            .ns-terminal {
                background: #0d1117;
                border-radius: 8px;
                border: 1px solid #30363d;
                overflow: hidden;
                font-family: 'SF Mono', 'Fira Code', 'Courier New', monospace;
                margin-top: 15px;
                box-shadow: 0 4px 6px rgba(0,0,0,0.3);
            }

            .ns-terminal-header {
                background: #161b22;
                padding: 10px 15px;
                border-bottom: 1px solid #30363d;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .ns-terminal-dots { display: flex; gap: 6px; }

            .ns-terminal-dot { width: 12px; height: 12px; border-radius: 50%; }
            .ns-terminal-dot.red { background: #ff5f56; }
            .ns-terminal-dot.yellow { background: #ffbd2e; }
            .ns-terminal-dot.green { background: #27c93f; }

            .ns-terminal-title {
                color: #8b949e;
                font-size: 13px;
                margin-left: 10px;
                flex: 1;
            }

            .ns-terminal-body {
                padding: 15px;
                max-height: 400px;
                overflow-y: auto;
                color: #c9d1d9;
                font-size: 13px;
                line-height: 1.6;
            }

            .ns-terminal-body::-webkit-scrollbar { width: 8px; }
            .ns-terminal-body::-webkit-scrollbar-track { background: #0d1117; }
            .ns-terminal-body::-webkit-scrollbar-thumb { background: #30363d; border-radius: 4px; }

            .ns-log-line {
                margin-bottom: 4px;
                padding: 2px 0;
                animation: pgFadeIn 0.3s ease-in;
            }

            @keyframes pgFadeIn {
                from { opacity: 0; transform: translateX(-10px); }
                to { opacity: 1; transform: translateX(0); }
            }

            .ns-log-success { color: #3fb950; }
            .ns-log-error { color: #f85149; }
            .ns-log-info { color: #58a6ff; }
            .ns-log-warn { color: #d29922; }
            .ns-log-process { color: #bc8cff; }

            .ns-log-time {
                color: #484f58;
                margin-right: 8px;
                font-size: 11px;
            }

            .ns-progress-container {
                background: #21262d;
                border-radius: 4px;
                height: 8px;
                margin: 15px 0;
                overflow: hidden;
            }

            .ns-progress-bar-new {
                background: linear-gradient(90deg, #238636, #2ea043);
                height: 100%;
                width: 0%;
                transition: width 0.5s ease;
                border-radius: 4px;
            }

            .ns-stats-row {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 10px;
                margin-top: 15px;
            }

            .ns-stat-box {
                background: #161b22;
                border: 1px solid #30363d;
                border-radius: 6px;
                padding: 12px;
                text-align: center;
            }

            .ns-stat-box-value { font-size: 24px; font-weight: bold; margin-bottom: 4px; }
            .ns-stat-box-label { font-size: 11px; color: #8b949e; }

            .ns-stat-created { color: #3fb950; }
            .ns-stat-updated { color: #58a6ff; }
            .ns-stat-failed { color: #f85149; }
            .ns-stat-total { color: #bc8cff; }

            .ns-spinner {
                display: inline-block;
                width: 12px;
                height: 12px;
                border: 2px solid #30363d;
                border-top-color: #58a6ff;
                border-radius: 50%;
                animation: pgSpin 0.8s linear infinite;
                margin-left: 5px;
            }

            @keyframes pgSpin { to { transform: rotate(360deg); } }

            .ns-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 600;
            }

            .ns-badge-success { background: rgba(63, 185, 80, 0.2); color: #3fb950; }
            .ns-badge-info { background: rgba(88, 166, 255, 0.2); color: #58a6ff; }
            .ns-badge-error { background: rgba(248, 81, 73, 0.2); color: #f85149; }
        </style>

        <div class="wrap">
            <h1>پست هوشمند</h1>
            <p>همگام‌سازی و خودکار پست برای هتل، رستوران، مقصد و بیمارستان از منابع خارجی</p>

            <!-- API Test -->
            <div class="pg-card">
                <h2>تست اتصال منابع</h2>
                <p>قبل از همگام‌سازی، وضعیت منابع را بررسی کنید:</p>
                <button id="pg-test-api-btn" class="button button-primary">تست همه منابع</button>
                <div id="pg-api-test-results" style="margin-top:15px;"></div>
            </div>

            <!-- Sync Form -->
            <div class="pg-card">
                <h2>همگام‌سازی</h2>

                <table class="form-table">
                    <tr>
                        <th>نوع محتوا:</th>
                        <td>
                            <select id="pg-sync-type">
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
                            <select id="pg-sync-source">
                                <option value="searchapi">سرچ‌ای‌پی‌آی</option>
                                <option value="serpapi">سرپ‌ای‌پی‌آی</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>مقصد جستجو:</th>
                        <td>
                            <input type="text" id="pg-sync-location" placeholder="مثال: استانبول، ترکیه" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th>تعداد نتایج:</th>
                        <td>
                            <select id="pg-sync-limit">
                                <option value="1">۱ مورد (تست)</option>
                                <option value="5">۵ مورد</option>
                                <option value="10">۱۰ مورد</option>
                                <option value="20" selected>۲۰ مورد</option>
                                <option value="50">۵۰ مورد</option>
                                <option value="100">۱۰۰ مورد</option>
                            </select>
                        </td>
                    </tr>
                </table>

                <p>
                    <button id="pg-start-sync" class="button button-primary button-large">
                        شروع همگام‌سازی
                    </button>
                </p>

                <div id="pg-progress" style="display:none;"></div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                var pgNonce = '<?php echo esc_js($nonce); ?>';
                var pgInProgress = false;

                function escHtml(str) {
                    if (!str) return '';
                    return str.toString()
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;');
                }

                function getCurrentTime() {
                    return new Date().toLocaleTimeString('fa-IR', { hour12: false });
                }

                function logToTerminal(container, message, type) {
                    var timeSpan = '<span class="ns-log-time">' + getCurrentTime() + '</span>';
                    var typeClass = 'ns-log-' + (type || 'info');
                    var line = '<div class="ns-log-line ' + typeClass + '">' + timeSpan + message + '</div>';
                    container.append(line);
                    container.scrollTop(container[0].scrollHeight);
                }

                function resetButton() {
                    $('#pg-start-sync').prop('disabled', false).text('شروع همگام‌سازی');
                }

                /* ============================================================
                   Test API
                   ============================================================ */
                $('#pg-test-api-btn').on('click', function () {
                    var btn = $(this);

                    btn.prop('disabled', true).text('در حال تست...');
                    $('#pg-api-test-results').html('<p>در حال بررسی...</p>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'nextsafar_pg_test_api',
                            nonce: pgNonce,
                            force_refresh: '1'
                        },
                        success: function (response) {
                            btn.prop('disabled', false).text('تست همه منابع');

                            if (!response.success) {
                                $('#pg-api-test-results').html('<p style="color:red;">خطا: ' + (response.data.message || response.data) + '</p>');
                                return;
                            }

                            var results = Array.isArray(response.data) ? response.data : [];
                            var html = '<table class="widefat"><thead><tr><th>منبع</th><th>وضعیت</th><th>جزئیات</th></tr></thead><tbody>';

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
                            $('#pg-api-test-results').html(html);
                        },
                        error: function () {
                            btn.prop('disabled', false).text('تست همه منابع');
                            $('#pg-api-test-results').html('<p style="color:red;">خطا در ارتباط با سرور</p>');
                        }
                    });
                });

                /* ============================================================
                   Start Sync
                   ============================================================ */
                $('#pg-start-sync').on('click', function () {
                    if (pgInProgress) {
                        alert('یک همگام‌سازی در حال اجرا است!');
                        return;
                    }

                    var location = $('#pg-sync-location').val().trim();

                    if (!location) {
                        alert('لطفاً مقصد جستجو را وارد کنید');
                        return;
                    }

                    pgInProgress = true;

                    var btn = $(this);
                    btn.prop('disabled', true).html('در حال همگام‌سازی <span class="ns-spinner"></span>');

                    // Build terminal UI
                    var terminalHtml =
                        '<div class="ns-terminal">' +
                        '<div class="ns-terminal-header">' +
                        '<div class="ns-terminal-dots">' +
                        '<div class="ns-terminal-dot red"></div>' +
                        '<div class="ns-terminal-dot yellow"></div>' +
                        '<div class="ns-terminal-dot green"></div>' +
                        '</div>' +
                        '<div class="ns-terminal-title">NextSafar Sync Terminal</div>' +
                        '</div>' +
                        '<div class="ns-terminal-body" id="pg-terminal-body"></div>' +
                        '</div>' +
                        '<div class="ns-progress-container">' +
                        '<div class="ns-progress-bar-new" id="pg-progress-bar"></div>' +
                        '</div>' +
                        '<div class="ns-stats-row" id="pg-stats-row" style="display:none;">' +
                        '<div class="ns-stat-box"><div class="ns-stat-box-value ns-stat-total" id="pg-stat-total">0</div><div class="ns-stat-box-label">کل</div></div>' +
                        '<div class="ns-stat-box"><div class="ns-stat-box-value ns-stat-created" id="pg-stat-created">0</div><div class="ns-stat-box-label">ایجاد شده</div></div>' +
                        '<div class="ns-stat-box"><div class="ns-stat-box-value ns-stat-updated" id="pg-stat-updated">0</div><div class="ns-stat-box-label">به‌روزرسانی</div></div>' +
                        '<div class="ns-stat-box"><div class="ns-stat-box-value ns-stat-failed" id="pg-stat-failed">0</div><div class="ns-stat-box-label">ناموفق</div></div>' +
                        '</div>';

                    $('#pg-progress').html(terminalHtml).show();

                    var $terminal = $('#pg-terminal-body');

                    logToTerminal($terminal, '🚀 شروع همگام‌سازی <span class="ns-badge ns-badge-info">' + escHtml($('#pg-sync-type option:selected').text()) + '</span>', 'info');
                    logToTerminal($terminal, '📍 مقصد: <strong>' + escHtml(location) + '</strong>', 'info');
                    logToTerminal($terminal, '🔌 منبع: <strong>' + escHtml($('#pg-sync-source option:selected').text()) + '</strong>', 'info');
                    logToTerminal($terminal, '─────────────────────────────────────────', 'process');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'nextsafar_pg_sync',
                            nonce: pgNonce,
                            sync_type: $('#pg-sync-type').val(),
                            source: $('#pg-sync-source').val(),
                            location: location,
                            limit: $('#pg-sync-limit').val()
                        },
                        success: function (response) {
                            if (!response.success) {
                                logToTerminal($terminal, '❌ ' + escHtml(response.data.message || response.data), 'error');
                                pgInProgress = false;
                                resetButton();
                                return;
                            }

                            var d = response.data || {};

                            if (d.total) {
                                logToTerminal($terminal, '✅ <strong>' + d.total + '</strong> مورد یافت شد', 'success');
                                logToTerminal($terminal, '📦 تقسیم به <strong>' + d.total_batches + '</strong> دسته (هر دسته ' + d.batch_size + ' مورد)', 'info');
                                logToTerminal($terminal, '─────────────────────────────────────────', 'process');
                            }

                            // Start batch processing
                            setTimeout(function () {
                                processNextBatch($terminal);
                            }, 500);
                        },
                        error: function (xhr) {
                            logToTerminal($terminal, '❌ خطا در ارتباط با سرور: ' + (xhr.statusText || 'نامشخص'), 'error');
                            pgInProgress = false;
                            resetButton();
                        }
                    });
                });

                /* ============================================================
                   Process Next Batch
                   ============================================================ */
                function processNextBatch($terminal) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'nextsafar_pg_batch',
                            nonce: pgNonce
                        },
                        success: function (response) {
                            if (!response.success) {
                                logToTerminal($terminal, '❌ ' + escHtml(response.data.message || 'خطا'), 'error');
                                pgInProgress = false;
                                resetButton();
                                return;
                            }

                            var r = response.data || {};

                            // Update progress bar
                            var percent = r.progress_percent || 0;
                            $('#pg-progress-bar').css('width', percent + '%');

                            // Update stats
                            $('#pg-stats-row').show();
                            $('#pg-stat-total').text(r.processed || 0);
                            $('#pg-stat-created').text(r.created || 0);
                            $('#pg-stat-updated').text(r.updated || 0);
                            $('#pg-stat-failed').text(r.failed || 0);

                            // Log batch header
                            logToTerminal($terminal,
                                '📦 دسته <strong>' + (r.current_batch || '?') + '</strong> از <strong>' + (r.total_batches || '?') + '</strong> ' +
                                '<span class="ns-badge ns-badge-info">' + percent + '%</span>',
                                'process'
                            );

                            // Log each item
                            if (r.batch_details && Array.isArray(r.batch_details)) {
                                r.batch_details.forEach(function (item) {
                                    var icon = '✅';
                                    var badgeClass = 'ns-badge-success';
                                    var logType = 'success';

                                    if (item.status === 'updated') {
                                        icon = '🔄';
                                        badgeClass = 'ns-badge-info';
                                        logType = 'info';
                                    } else if (item.status === 'failed') {
                                        icon = '❌';
                                        badgeClass = 'ns-badge-error';
                                        logType = 'error';
                                    }

                                    logToTerminal($terminal,
                                        icon + ' <strong>#' + item.index + '</strong> ' + escHtml(item.name) +
                                        ' <span class="ns-badge ' + badgeClass + '">' + item.status + '</span>',
                                        logType
                                    );
                                });
                            }

                            if (r.completed) {
                                showSyncComplete(r, $terminal);
                            } else {
                                setTimeout(function () {
                                    processNextBatch($terminal);
                                }, 700);
                            }
                        },
                        error: function (xhr) {
                            logToTerminal($terminal, '❌ خطا در ارتباط: ' + (xhr.statusText || 'نامشخص'), 'error');
                            pgInProgress = false;
                            resetButton();
                        }
                    });
                }

                /* ============================================================
                   Show Complete
                   ============================================================ */
                function showSyncComplete(r, $terminal) {
                    pgInProgress = false;
                    resetButton();

                    $('#pg-progress-bar').css('width', '100%');

                    logToTerminal($terminal, '─────────────────────────────────────────', 'process');
                    logToTerminal($terminal, '🎉 <strong>همگام‌سازی با موفقیت کامل شد!</strong>', 'success');
                    logToTerminal($terminal, '', 'info');

                    var total = r.total || 0;
                    var created = r.created || 0;
                    var updated = r.updated || 0;
                    var failed = r.failed || 0;

                    logToTerminal($terminal, '📊 <strong>خلاصه نهایی:</strong>', 'info');
                    logToTerminal($terminal, '  • کل موارد: <strong>' + total + '</strong>', 'info');

                    if (created > 0) {
                        logToTerminal($terminal, '  • <span class="ns-log-success">✅ ایجاد شده: ' + created + '</span>', 'success');
                    }
                    if (updated > 0) {
                        logToTerminal($terminal, '  • <span class="ns-log-info">🔄 به‌روزرسانی: ' + updated + '</span>', 'info');
                    }
                    if (failed > 0) {
                        logToTerminal($terminal, '  • <span class="ns-log-error">❌ ناموفق: ' + failed + '</span>', 'error');
                    }

                    $('#pg-stat-total').text(total);
                    $('#pg-stat-created').text(created);
                    $('#pg-stat-updated').text(updated);
                    $('#pg-stat-failed').text(failed);

                    var successRate = total > 0 ? Math.round(((created + updated) / total) * 100) : 0;
                    logToTerminal($terminal, '', 'info');
                    logToTerminal($terminal, '✨ نرخ موفقیت: <strong>' + successRate + '%</strong>', successRate >= 80 ? 'success' : 'warn');
                }
            });
        </script>
        <?php
    }

    /* ========================================================================
       Settings Page
       ======================================================================== */
    public static function render_settings_page(): void {
        if (!current_user_can('manage_options')) return;

        $message = '';

        if (isset($_POST['save_pg_api_settings']) && check_admin_referer('nextsafar_pg_api_settings')) {
            update_option('nextsafar_searchapi_key', sanitize_text_field($_POST['nextsafar_searchapi_key'] ?? ''));
            update_option('nextsafar_serpapi_key', sanitize_text_field($_POST['nextsafar_serpapi_key'] ?? ''));
            update_option('nextsafar_active_source', sanitize_text_field($_POST['nextsafar_active_source'] ?? 'searchapi'));
            $message = 'کلیدهای اتصال ذخیره شد';
        }

        $searchapi_key = get_option('nextsafar_searchapi_key', '');
        $serpapi_key = get_option('nextsafar_serpapi_key', '');
        $active_source = get_option('nextsafar_active_source', 'searchapi');
        ?>
        <div class="wrap">
            <h1>تنظیمات تولید پست هوشمند</h1>

            <?php if ($message): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($message); ?></p></div>
            <?php endif; ?>

            <div style="background:#fff;padding:24px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:20px;">
                <h2>کلیدهای اتصال</h2>
                <form method="post">
                    <?php wp_nonce_field('nextsafar_pg_api_settings'); ?>
                    <table class="form-table">
                        <tr>
                            <th>کلید سرچ‌ای‌پی‌آی:</th>
                            <td>
                                <input type="password" name="nextsafar_searchapi_key" value="<?php echo esc_attr($searchapi_key); ?>" class="regular-text" dir="ltr" autocomplete="off">
                                <p class="description">از سایت searchapi.io دریافت کنید</p>
                            </td>
                        </tr>
                        <tr>
                            <th>کلید سرپ‌ای‌پی‌آی:</th>
                            <td>
                                <input type="password" name="nextsafar_serpapi_key" value="<?php echo esc_attr($serpapi_key); ?>" class="regular-text" dir="ltr" autocomplete="off">
                                <p class="description">از سایت serpapi.com دریافت کنید</p>
                            </td>
                        </tr>
                        <tr>
                            <th>منبع فعال:</th>
                            <td>
                                <select name="nextsafar_active_source">
                                    <option value="searchapi" <?php selected($active_source, 'searchapi'); ?>>سرچ‌ای‌پی‌آی</option>
                                    <option value="serpapi" <?php selected($active_source, 'serpapi'); ?>>سرپ‌ای‌پی‌آی</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" name="save_pg_api_settings" class="button button-primary">ذخیره کلیدهای اتصال</button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    /* ========================================================================
       AJAX: Test API
       ======================================================================== */
    public static function ajax_test_api(): void {
        check_ajax_referer('nextsafar_pg', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز']);
        }

        $force_refresh = isset($_POST['force_refresh']) && $_POST['force_refresh'] === '1';
        $cache_key = 'nextsafar_pg_api_test_results';

        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                wp_send_json_success($cached);
                return;
            }
        }

        $results = [];

        // Test SearchApi
        $searchapi_key = get_option('nextsafar_searchapi_key', '');

        if (!empty($searchapi_key)) {
            $url = 'https://www.searchapi.io/api/v1/search?engine=google_hotels&q=Dubai&check_in_date='
                . date('Y-m-d', strtotime('+30 days')) . '&check_out_date='
                . date('Y-m-d', strtotime('+31 days')) . '&adults=2&api_key=' . urlencode($searchapi_key);

            $response = wp_remote_get($url, ['timeout' => 20]);

            if (is_wp_error($response)) {
                $results[] = ['name' => 'SearchApi.io', 'success' => false, 'message' => $response->get_error_message()];
            } else {
                $code = wp_remote_retrieve_response_code($response);
                $body = json_decode(wp_remote_retrieve_body($response), true);

                if ($code === 200 && !empty($body['properties'])) {
                    $results[] = ['name' => 'SearchApi.io', 'success' => true, 'message' => '✅ متصل (' . count($body['properties']) . ' هتل)'];
                } else {
                    $results[] = ['name' => 'SearchApi.io', 'success' => false, 'message' => $body['error'] ?? "HTTP {$code}"];
                }
            }
        } else {
            $results[] = ['name' => 'SearchApi.io', 'success' => false, 'message' => 'کلید تنظیم نشده'];
        }

        // Test SerpApi
        $serpapi_key = get_option('nextsafar_serpapi_key', '');

        if (!empty($serpapi_key)) {
            $url = 'https://serpapi.com/search.json?engine=google_hotels&q=Dubai&check_in_date='
                . date('Y-m-d', strtotime('+30 days')) . '&check_out_date='
                . date('Y-m-d', strtotime('+31 days')) . '&adults=2&api_key=' . urlencode($serpapi_key);

            $response = wp_remote_get($url, ['timeout' => 20]);

            if (is_wp_error($response)) {
                $results[] = ['name' => 'SerpApi', 'success' => false, 'message' => $response->get_error_message()];
            } else {
                $code = wp_remote_retrieve_response_code($response);
                $body = json_decode(wp_remote_retrieve_body($response), true);

                if ($code === 200 && !empty($body['properties'])) {
                    $results[] = ['name' => 'SerpApi', 'success' => true, 'message' => '✅ متصل (' . count($body['properties']) . ' هتل)'];
                } else {
                    $results[] = ['name' => 'SerpApi', 'success' => false, 'message' => $body['error'] ?? "HTTP {$code}"];
                }
            }
        } else {
            $results[] = ['name' => 'SerpApi', 'success' => false, 'message' => 'کلید تنظیم نشده'];
        }

        // Test Gemini with fallback models
        $gemini_key = get_option('nextsafar_gemini_api_key', '');

        if (!empty($gemini_key)) {
            $models = ['gemini-2.5-flash', 'gemini-2.0-flash', 'gemini-2.0-flash-lite', 'gemini-1.5-flash'];
            $success = false;
            $working_model = '';
            $last_error = '';

            foreach ($models as $model) {
                $response = wp_remote_post(
                    "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$gemini_key}",
                    [
                        'timeout' => 15,
                        'headers' => ['Content-Type' => 'application/json'],
                        'body'    => json_encode([
                            'contents' => [['parts' => [['text' => 'Say OK']]]],
                            'generationConfig' => ['maxOutputTokens' => 16],
                        ]),
                    ]
                );

                if (is_wp_error($response)) {
                    $last_error = $response->get_error_message();
                    continue;
                }

                $code = wp_remote_retrieve_response_code($response);

                if ($code === 200) {
                    $success = true;
                    $working_model = $model;
                    break;
                }

                $body = json_decode(wp_remote_retrieve_body($response), true);
                $last_error = $body['error']['message'] ?? "HTTP {$code}";

                // Skip to next model on 404
                if ($code !== 404) break;
            }

            if ($success) {
                $results[] = ['name' => 'Gemini AI', 'success' => true, 'message' => '✅ متصل (مدل: ' . $working_model . ')'];
            } else {
                $results[] = ['name' => 'Gemini AI', 'success' => false, 'message' => '⚠️ ' . $last_error];
            }
        } else {
            $results[] = ['name' => 'Gemini AI', 'success' => false, 'message' => 'کلید تنظیم نشده'];
        }

        set_transient($cache_key, $results, 120);

        wp_send_json_success($results);
    }

    /* ========================================================================
       AJAX: Start Sync
       ======================================================================== */
    public static function ajax_sync(): void {
        check_ajax_referer('nextsafar_pg', 'nonce');

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

        $limit = max(1, min(100, $limit));

        // Check API key
        if ($source === 'serpapi') {
            $key = get_option('nextsafar_serpapi_key', '');
            if (empty($key)) {
                wp_send_json_error(['message' => 'کلید سرپ‌ای‌پی‌آی تنظیم نشده!']);
            }
        } else {
            $key = get_option('nextsafar_searchapi_key', '');
            if (empty($key)) {
                wp_send_json_error(['message' => 'کلید سرچ‌ای‌پی‌آی تنظیم نشده!']);
            }
        }

        if (!class_exists('\NextSafar\API\BatchSync') || !method_exists('\NextSafar\API\BatchSync', 'start_sync')) {
            wp_send_json_error(['message' => 'کلاس همگام‌سازی در دسترس نیست']);
        }

        try {
            $result = \NextSafar\API\BatchSync::start_sync($sync_type, $location, $source, $limit);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        if (empty($result['success'])) {
            wp_send_json_error(['message' => $result['message'] ?? 'خطا در شروع همگام‌سازی']);
        }

        wp_send_json_success($result);
    }

    /* ========================================================================
       AJAX: Process Next Batch
       ======================================================================== */
    public static function ajax_batch(): void {
        check_ajax_referer('nextsafar_pg', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز']);
        }

        if (!class_exists('\NextSafar\API\BatchSync') || !method_exists('\NextSafar\API\BatchSync', 'process_next_batch')) {
            wp_send_json_error(['message' => 'کلاس همگام‌سازی در دسترس نیست']);
        }

        try {
            $result = \NextSafar\API\BatchSync::process_next_batch();
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        wp_send_json_success($result);
    }

    /* ========================================================================
       AJAX: Sync Status
       ======================================================================== */
    public static function ajax_status(): void {
        check_ajax_referer('nextsafar_pg', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز']);
        }

        if (!class_exists('\NextSafar\API\BatchSync') || !method_exists('\NextSafar\API\BatchSync', 'get_status')) {
            wp_send_json_success(['status' => 'unsupported', 'message' => 'متد وضعیت در دسترس نیست']);
        }

        try {
            $result = \NextSafar\API\BatchSync::get_status();
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        wp_send_json_success($result);
    }
}

PostGenerator::init();