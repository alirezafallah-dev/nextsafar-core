<?php
/**
 * NextSafar Sync Page - News Only
 * Live terminal UI for news sync
 */

namespace NextSafar\Admin;

if (!defined('ABSPATH')) exit;

class SyncPage {

    /* ========================================================================
       Init
       ======================================================================== */
    public static function init() {
        add_action('wp_ajax_nextsafar_news_batch_start', [__CLASS__, 'ajax_news_batch_start']);
        add_action('wp_ajax_nextsafar_news_batch_process', [__CLASS__, 'ajax_news_batch_process']);
        add_action('wp_ajax_nextsafar_reset_sources', [__CLASS__, 'ajax_reset_sources']);
        add_action('wp_ajax_nextsafar_get_sources_stats', [__CLASS__, 'ajax_get_sources_stats']);
    }

    /* ========================================================================
       Render Page
       ======================================================================== */
    public static function render_sync_page() {
        // Ensure tables exist
        if (class_exists('\NextSafar\Database\NewsTables') && method_exists('\NextSafar\Database\NewsTables', 'create_all_tables')) {
            \NextSafar\Database\NewsTables::create_all_tables();
        } elseif (class_exists('\NextSafar\API\NewsSync') && method_exists('\NextSafar\API\NewsSync', 'ensure_tables_exist')) {
            \NextSafar\API\NewsSync::ensure_tables_exist();
        }

        $stats = [
            'total_posts' => 0,
            'today_posts' => 0,
            'total_duplicates_blocked' => 0,
            'ai_total_cost_usd' => 0,
        ];

        if (class_exists('\NextSafar\API\NewsSync') && method_exists('\NextSafar\API\NewsSync', 'get_overview_stats')) {
            $maybe_stats = \NextSafar\API\NewsSync::get_overview_stats();
            if (!is_array($maybe_stats)) $maybe_stats = (array) $maybe_stats;
            $stats = wp_parse_args($maybe_stats, $stats);
        }

        $nonce = wp_create_nonce('nextsafar_sync');
        $news_list_url = admin_url('edit.php?post_type=travelnews');
        ?>
        <style>
            .nextsafar-sync-page .ns-card {
                background: #fff;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
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

            .nextsafar-sync-page .ns-stat-value { font-size: 24px; font-weight: bold; }
            .nextsafar-sync-page .ns-stat-label { color: #666; }
            .nextsafar-sync-page .ns-radio { display: block; margin: 10px 0; }

            /* Terminal Styles */
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
                animation: nsFadeIn 0.3s ease-in;
            }

            @keyframes nsFadeIn {
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
                grid-template-columns: repeat(5, 1fr);
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

            .ns-stat-box-value { font-size: 22px; font-weight: bold; margin-bottom: 4px; }
            .ns-stat-box-label { font-size: 10px; color: #8b949e; }

            .ns-stat-published { color: #3fb950; }
            .ns-stat-drafts { color: #d29922; }
            .ns-stat-failed { color: #f85149; }
            .ns-stat-skipped { color: #8b949e; }
            .ns-stat-ai { color: #bc8cff; }

            .ns-spinner {
                display: inline-block;
                width: 12px;
                height: 12px;
                border: 2px solid #30363d;
                border-top-color: #58a6ff;
                border-radius: 50%;
                animation: nsSpin 0.8s linear infinite;
                margin-left: 5px;
            }

            @keyframes nsSpin { to { transform: rotate(360deg); } }

            .ns-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 600;
            }

            .ns-badge-success { background: rgba(63,185,80,0.2); color: #3fb950; }
            .ns-badge-info { background: rgba(88,166,255,0.2); color: #58a6ff; }
            .ns-badge-error { background: rgba(248,81,73,0.2); color: #f85149; }
            .ns-badge-warn { background: rgba(210,153,34,0.2); color: #d29922; }
        </style>

        <div class="wrap nextsafar-sync-page">
            <h1>اخبار هوشمند</h1>

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

                <div id="news-progress" style="display:none;"></div>
            </div>

            <!-- Sources Status -->
            <div class="ns-card">
                <h2>وضعیت منابع RSS</h2>
                <button type="button" id="reset-sources-btn" class="button button-secondary">بازنشانی منابع پیش‌فرض</button>
                <button type="button" id="refresh-sources-btn" class="button button-primary">بارگذاری مجدد</button>
                <div id="sources-table-container" style="margin-top:15px;"></div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function ($) {
            var nsNonce = '<?php echo esc_js($nonce); ?>';
            var nsNewsListUrl = '<?php echo esc_url($news_list_url); ?>';
            var newsInProgress = false;

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

            function resetNewsButton() {
                $('#start-news-sync').prop('disabled', false).text('شروع دریافت اخبار');
            }

            /* ============================================================
               Load Sources Table
               ============================================================ */
            function loadSourcesTable() {
                $('#sources-table-container').html('<p>در حال بارگذاری...</p>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: { action: 'nextsafar_get_sources_stats', nonce: nsNonce },
                    success: function (response) {
                        if (!response.success) {
                            $('#sources-table-container').html('<p style="color:red;">خطا در بارگذاری منابع</p>');
                            return;
                        }

                        var sources = response.data;
                        if (!Array.isArray(sources)) sources = sources.sources || [];

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
                            html += '<td>' + type + '</td>';
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
                        $('#sources-table-container').html('<p style="color:red;">خطا در ارتباط با سرور</p>');
                    }
                });
            }

            loadSourcesTable();

            $('#refresh-sources-btn').on('click', loadSourcesTable);

            $('#reset-sources-btn').on('click', function () {
                if (!confirm('همه منابع فعلی حذف و پیش‌فرض جایگزین می‌شود. ادامه؟')) return;

                var btn = $(this);
                btn.prop('disabled', true).text('در حال بازنشانی...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: { action: 'nextsafar_reset_sources', nonce: nsNonce },
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
                        alert('خطا در ارتباط با سرور');
                    }
                });
            });

            /* ============================================================
               Start News Sync with Live Terminal
               ============================================================ */
            $('#start-news-sync').on('click', function () {
                if (newsInProgress) {
                    alert('یک همگام‌سازی در حال اجرا است!');
                    return;
                }

                var mode = $('input[name="news_sync_mode"]:checked').val();

                newsInProgress = true;

                var btn = $(this);
                btn.prop('disabled', true).html('در حال شروع... <span class="ns-spinner"></span>');

                // Build terminal UI
                var terminalHtml =
                    '<div class="ns-terminal">' +
                    '<div class="ns-terminal-header">' +
                    '<div class="ns-terminal-dots">' +
                    '<div class="ns-terminal-dot red"></div>' +
                    '<div class="ns-terminal-dot yellow"></div>' +
                    '<div class="ns-terminal-dot green"></div>' +
                    '</div>' +
                    '<div class="ns-terminal-title">NextSafar News Sync Terminal</div>' +
                    '</div>' +
                    '<div class="ns-terminal-body" id="news-terminal-body"></div>' +
                    '</div>' +
                    '<div class="ns-progress-container">' +
                    '<div class="ns-progress-bar-new" id="news-progress-bar"></div>' +
                    '</div>' +
                    '<div class="ns-stats-row" id="news-stats-row" style="display:none;">' +
                    '<div class="ns-stat-box"><div class="ns-stat-box-value ns-stat-published" id="ns-stat-published">0</div><div class="ns-stat-box-label">منتشر شده</div></div>' +
                    '<div class="ns-stat-box"><div class="ns-stat-box-value ns-stat-drafts" id="ns-stat-drafts">0</div><div class="ns-stat-box-label">پیش‌نویس</div></div>' +
                    '<div class="ns-stat-box"><div class="ns-stat-box-value ns-stat-skipped" id="ns-stat-skipped">0</div><div class="ns-stat-box-label">رد شده</div></div>' +
                    '<div class="ns-stat-box"><div class="ns-stat-box-value ns-stat-failed" id="ns-stat-failed">0</div><div class="ns-stat-box-label">ناموفق</div></div>' +
                    '<div class="ns-stat-box"><div class="ns-stat-box-value ns-stat-ai" id="ns-stat-ai">0</div><div class="ns-stat-box-label">AI</div></div>' +
                    '</div>';

                $('#news-progress').html(terminalHtml).show();

                var $terminal = $('#news-terminal-body');

                logToTerminal($terminal, '🚀 شروع همگام‌سازی اخبار', 'info');
                logToTerminal($terminal, '📋 حالت: <strong>' + (mode === 'ai' ? 'با بازنویسی AI' : 'سریع') + '</strong>', 'info');
                logToTerminal($terminal, '─────────────────────────────────────────', 'process');
                logToTerminal($terminal, '📡 در حال دریافت از منابع...', 'process');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'nextsafar_news_batch_start',
                        nonce: nsNonce,
                        mode: mode
                    },
                    success: function (response) {
                        if (!response.success) {
                            logToTerminal($terminal, '❌ ' + escHtml(response.data.message || 'خطای ناشناخته'), 'error');
                            newsInProgress = false;
                            resetNewsButton();
                            return;
                        }

                        var d = response.data || {};

                        // Show fetch stats
                        if (d.stats) {
                            logToTerminal($terminal, '📰 RSS: <strong>' + d.stats.rss + '</strong> خبر', 'info');
                            logToTerminal($terminal, '🌐 API: <strong>' + d.stats.api + '</strong> خبر', 'info');
                            logToTerminal($terminal, '📊 کل دریافتی: <strong>' + d.stats.total_fetched + '</strong>', 'info');

                            if (d.stats.duplicates > 0) {
                                logToTerminal($terminal, '🔄 تکراری حذف شد: <strong>' + d.stats.duplicates + '</strong>', 'warn');
                            }
                            if (d.stats.filtered > 0) {
                                logToTerminal($terminal, '🗑️ فیلتر شد: <strong>' + d.stats.filtered + '</strong>', 'warn');
                            }
                        }

                        if (d.total === 0) {
                            logToTerminal($terminal, '', 'info');
                            logToTerminal($terminal, 'ℹ️ ' + escHtml(d.message || 'هیچ خبر جدیدی یافت نشد'), 'warn');
                            newsInProgress = false;
                            resetNewsButton();
                            return;
                        }

                        logToTerminal($terminal, '─────────────────────────────────────────', 'process');
                        logToTerminal($terminal, '✅ <strong>' + d.total + '</strong> خبر آماده ذخیره', 'success');
                        logToTerminal($terminal, '📦 تقسیم به <strong>' + d.total_batches + '</strong> دسته (هر دسته ' + d.batch_size + ' خبر)', 'info');
                        logToTerminal($terminal, '─────────────────────────────────────────', 'process');

                        // Start batch processing
                        setTimeout(function () {
                            processNextNewsBatch($terminal);
                        }, 500);
                    },
                    error: function (xhr) {
                        logToTerminal($terminal, '❌ خطا در ارتباط با سرور: ' + (xhr.statusText || 'نامشخص'), 'error');
                        newsInProgress = false;
                        resetNewsButton();
                    }
                });
            });

            /* ============================================================
               Process Next News Batch
               ============================================================ */
            function processNextNewsBatch($terminal) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'nextsafar_news_batch_process',
                        nonce: nsNonce
                    },
                    success: function (response) {
                        if (!response.success) {
                            logToTerminal($terminal, '❌ ' + escHtml(response.data.message || 'خطا'), 'error');
                            newsInProgress = false;
                            resetNewsButton();
                            return;
                        }

                        var r = response.data || {};

                        // Update progress bar
                        var percent = r.progress_percent || 0;
                        $('#news-progress-bar').css('width', percent + '%');

                        // Update stats
                        $('#news-stats-row').show();
                        $('#ns-stat-published').text(r.published || 0);
                        $('#ns-stat-drafts').text(r.drafts || 0);
                        $('#ns-stat-skipped').text(r.skipped || 0);
                        $('#ns-stat-failed').text(r.failed || 0);
                        $('#ns-stat-ai').text(r.ai_used || 0);

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
                                var statusText = 'منتشر شد';

                                if (item.status === 'draft') {
                                    icon = '📝';
                                    badgeClass = 'ns-badge-warn';
                                    logType = 'warn';
                                    statusText = 'پیش‌نویس';
                                } else if (item.status === 'skipped') {
                                    icon = '⏭️';
                                    badgeClass = 'ns-badge-info';
                                    logType = 'info';
                                    statusText = 'رد شد';
                                } else if (item.status === 'failed') {
                                    icon = '❌';
                                    badgeClass = 'ns-badge-error';
                                    logType = 'error';
                                    statusText = 'ناموفق';
                                }

                                logToTerminal($terminal,
                                    icon + ' <strong>#' + item.index + '</strong> ' + escHtml(item.name) +
                                    ' <span class="ns-badge ' + badgeClass + '">' + statusText + '</span>',
                                    logType
                                );
                            });
                        }

                        if (r.completed) {
                            showNewsSyncComplete(r, $terminal);
                        } else {
                            setTimeout(function () {
                                processNextNewsBatch($terminal);
                            }, 700);
                        }
                    },
                    error: function (xhr) {
                        logToTerminal($terminal, '❌ خطا در ارتباط: ' + (xhr.statusText || 'نامشخص'), 'error');
                        newsInProgress = false;
                        resetNewsButton();
                    }
                });
            }

            /* ============================================================
               Show News Sync Complete
               ============================================================ */
            function showNewsSyncComplete(r, $terminal) {
                newsInProgress = false;
                resetNewsButton();

                $('#news-progress-bar').css('width', '100%');

                logToTerminal($terminal, '─────────────────────────────────────────', 'process');
                logToTerminal($terminal, '🎉 <strong>همگام‌سازی اخبار با موفقیت کامل شد!</strong>', 'success');
                logToTerminal($terminal, '', 'info');

                var published = r.published || 0;
                var drafts = r.drafts || 0;
                var failed = r.failed || 0;
                var skipped = r.skipped || 0;
                var aiUsed = r.ai_used || 0;

                logToTerminal($terminal, '📊 <strong>خلاصه نهایی:</strong>', 'info');

                if (published > 0) {
                    logToTerminal($terminal, '  • <span class="ns-log-success">✅ منتشر شده: ' + published + '</span>', 'success');
                }
                if (drafts > 0) {
                    logToTerminal($terminal, '  • <span class="ns-log-warn">📝 پیش‌نویس: ' + drafts + '</span>', 'warn');
                }
                if (skipped > 0) {
                    logToTerminal($terminal, '  • <span class="ns-log-info">⏭️ رد شده: ' + skipped + '</span>', 'info');
                }
                if (failed > 0) {
                    logToTerminal($terminal, '  • <span class="ns-log-error">❌ ناموفق: ' + failed + '</span>', 'error');
                }
                if (aiUsed > 0) {
                    logToTerminal($terminal, '  • <span class="ns-log-process">🤖 بازنویسی با AI: ' + aiUsed + '</span>', 'process');
                }

                $('#ns-stat-published').text(published);
                $('#ns-stat-drafts').text(drafts);
                $('#ns-stat-skipped').text(skipped);
                $('#ns-stat-failed').text(failed);
                $('#ns-stat-ai').text(aiUsed);

                var total = r.total || 0;
                var successRate = total > 0 ? Math.round(((published + drafts) / total) * 100) : 0;

                logToTerminal($terminal, '', 'info');
                logToTerminal($terminal, '✨ نرخ موفقیت: <strong>' + successRate + '%</strong>', successRate >= 80 ? 'success' : 'warn');
                logToTerminal($terminal, '', 'info');
                logToTerminal($terminal, '🔗 <a href="' + nsNewsListUrl + '" target="_blank" style="color:#58a6ff;">مشاهده اخبار</a>', 'info');

                loadSourcesTable();
            }
        });
        </script>
        <?php
    }

    /* ========================================================================
       AJAX: Start News Batch Sync
       ======================================================================== */
    public static function ajax_news_batch_start() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        @ini_set('max_execution_time', 0);
        @ini_set('memory_limit', '1024M');

        check_ajax_referer('nextsafar_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز']);
        }

        $mode = sanitize_key($_POST['mode'] ?? 'quick');

        try {
            // Ensure class is loaded
            if (!class_exists('\NextSafar\API\NewsBatchSync')) {
                $file = NEXTSAFAR_PATH . 'inc/api/news-batch-sync.php';
                if (file_exists($file)) {
                    require_once $file;
                }

                if (!class_exists('\NextSafar\API\NewsBatchSync')) {
                    throw new \Exception('کلاس NewsBatchSync در دسترس نیست.');
                }
            }

            do_action('nextsafar_skip_revalidation');

            $result = \NextSafar\API\NewsBatchSync::start_sync($mode);

            wp_send_json_success($result);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /* ========================================================================
       AJAX: Process Next News Batch
       ======================================================================== */
    public static function ajax_news_batch_process() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        check_ajax_referer('nextsafar_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز']);
        }

        try {
            if (!class_exists('\NextSafar\API\NewsBatchSync')) {
                $file = NEXTSAFAR_PATH . 'inc/api/news-batch-sync.php';
                if (file_exists($file)) {
                    require_once $file;
                }
            }

            $result = \NextSafar\API\NewsBatchSync::process_next_batch();

            wp_send_json_success($result);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /* ========================================================================
       AJAX: Reset Sources
       ======================================================================== */
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

    /* ========================================================================
       AJAX: Get Sources Stats
       ======================================================================== */
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
}