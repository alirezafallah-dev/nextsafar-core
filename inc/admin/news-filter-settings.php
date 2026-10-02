<?php

/**
 * Travel News Filter Settings
 * @version 3.0.0 - Full AJAX bug fix + display all decisions.
 */

namespace NextSafar\Admin;

if (!defined('ABSPATH')) exit;

class NewsFilterSettings {
    const PAGE_SLUG = 'nextsafar-news-filter';
    const NONCE_ACTION = 'nextsafar_news_filter_save';
    const NONCE_NAME = 'nextsafar_news_filter_nonce';

    private static $registered = false;

    public static function init() {
        // FIX: all AJAX actions + real handler.
        add_action('wp_ajax_nextsafar_get_filter_history', [__CLASS__, 'ajax_get_history']);
        add_action('wp_ajax_nextsafar_get_rejected_news', [__CLASS__, 'ajax_get_rejected']);
    }

    // Page registration moved to menu.php

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('شما اجازه دسترسی به این صفحه را ندارید.'));
        }

        $message = '';
        $message_type = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce($_POST[self::NONCE_NAME], self::NONCE_ACTION)) {
                wp_die('Security check failed');
            }

            if (isset($_POST['action'])) {
                switch ($_POST['action']) {
                    case 'save_keywords':
                        $result = self::save_keywords();
                        $message = $result['message'];
                        $message_type = $result['type'];
                        break;

                    case 'save_thresholds':
                        $result = self::save_thresholds();
                        $message = $result['message'];
                        $message_type = $result['type'];
                        break;

                    case 'reset_keywords':
                        $result = self::reset_keywords();
                        $message = $result['message'];
                        $message_type = $result['type'];
                        break;
                }
            }
        }

        $keywords = \NextSafar\API\NewsFilter::get_keywords();
        $stats = self::get_filter_stats();

        $thresholds = [
            'publish' => get_option('nextsafar_news_filter_publish_threshold', 20),
            'delete' => get_option('nextsafar_news_filter_delete_threshold', -10),
        ];
        ?>
        <div class="wrap">
            <h1>تنظیمات فیلتر اخبار</h1>

            <?php if ($message): ?>
                <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin:20px 0;">
                <div style="background:#fff;padding:20px;border-radius:8px;border-left:4px solid #00a32a;">
                    <div style="font-size:24px;font-weight:bold;"><?= number_format($stats['total_published'] ?? 0); ?></div>
                    <div style="color:#666;">منتشر شده</div>
                </div>

                <div style="background:#fff;padding:20px;border-radius:8px;border-left:4px solid #dba617;">
                    <div style="font-size:24px;font-weight:bold;"><?= number_format($stats['total_draft'] ?? 0); ?></div>
                    <div style="color:#666;">پیش‌نویس</div>
                </div>

                <div style="background:#fff;padding:20px;border-radius:8px;border-left:4px solid #d63638;">
                    <div style="font-size:24px;font-weight:bold;"><?= number_format($stats['total_deleted'] ?? 0); ?></div>
                    <div style="color:#666;">حذف شده</div>
                </div>

                <div style="background:#fff;padding:20px;border-radius:8px;border-left:4px solid #2271b1;">
                    <div style="font-size:24px;font-weight:bold;"><?= number_format($stats['ai_checks'] ?? 0); ?></div>
                    <div style="color:#666;">بررسی با AI</div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                <div>
                    <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:20px;">
                        <h2>کلمات کلیدی مثبت (<?= count($keywords['positive'] ?? []); ?> کلمه)</h2>

                        <form method="post">
                            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                            <input type="hidden" name="action" value="save_keywords">
                            <input type="hidden" name="save_positive_only" value="1">

                            <p class="description">هر خط یک کلمه. این کلمات امتیاز مثبت به خبر می‌دهند.</p>

                            <textarea name="positive_keywords" rows="10" class="large-text" style="font-family:monospace;"><?= esc_textarea(implode("\n", $keywords['positive'] ?? [])); ?></textarea>

                            <p>
                                <button type="submit" class="button button-primary">ذخیره کلمات مثبت</button>
                            </p>
                        </form>
                    </div>

                    <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:20px;">
                        <h2>کلمات کلیدی منفی (<?= count($keywords['negative'] ?? []); ?> کلمه)</h2>

                        <form method="post">
                            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                            <input type="hidden" name="action" value="save_keywords">
                            <input type="hidden" name="save_negative_only" value="1">

                            <p class="description">هر خط یک کلمه. این کلمات امتیاز منفی به خبر می‌دهند.</p>

                            <textarea name="negative_keywords" rows="10" class="large-text" style="font-family:monospace;"><?= esc_textarea(implode("\n", $keywords['negative'] ?? [])); ?></textarea>

                            <p>
                                <button type="submit" class="button button-secondary" style="background:#d63638;color:#fff;border-color:#d63638;">ذخیره کلمات منفی</button>
                            </p>
                        </form>
                    </div>

                    <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                        <h2>بازنشانی</h2>
                        <p>کلمات کلیدی را به حالت پیش‌فرض بازنشانی کنید.</p>

                        <form method="post" onsubmit="return confirm('آیا مطمئن هستید؟ کلمات فعلی حذف می‌شوند.');">
                            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                            <input type="hidden" name="action" value="reset_keywords">

                            <button type="submit" class="button">بازنشانی به پیش‌فرض</button>
                        </form>
                    </div>
                </div>

                <div>
                    <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:20px;">
                        <h2>تنظیمات آستانه</h2>

                        <form method="post">
                            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                            <input type="hidden" name="action" value="save_thresholds">

                            <table class="form-table">
                                <tr>
                                    <th><label for="publish_threshold">حداقل امتیاز انتشار:</label></th>
                                    <td>
                                        <input type="number" id="publish_threshold" name="publish_threshold" value="<?= esc_attr($thresholds['publish']); ?>" min="0" max="100" style="width:80px;">
                                        <p class="description">اگر امتیاز ≥ این عدد → انتشار مستقیم</p>
                                    </td>
                                </tr>

                                <tr>
                                    <th><label for="delete_threshold">حداکثر امتیاز حذف:</label></th>
                                    <td>
                                        <input type="number" id="delete_threshold" name="delete_threshold" value="<?= esc_attr($thresholds['delete']); ?>" min="-100" max="0" style="width:80px;">
                                        <p class="description">اگر امتیاز < این عدد → حذف</p>
                                    </td>
                                </tr>
                            </table>

                            <p>
                                <button type="submit" class="button button-primary">ذخیره آستانه‌ها</button>
                            </p>
                        </form>
                    </div>

                    <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:20px;">
                        <h2>راهنمای فیلترینگ</h2>

                        <ol style="line-height:2;">
                            <li>بررسی کلمات کلیدی و محاسبه امتیاز</li>
                            <li>امتیاز بالا → انتشار مستقیم</li>
                            <li>امتیاز پایین → حذف</li>
                            <li>امتیاز متوسط → بررسی با AI</li>
                        </ol>
                    </div>
                </div>
            </div>

            <!-- New Section: Latest Rejected News -->
            <div style="margin-top:30px;">
                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-right:4px solid #d63638;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
                        <h2 style="margin:0;">🗑️ آخرین اخبار رد شده (حذف شده)</h2>

                        <div style="display:flex;gap:10px;align-items:center;">
                            <select id="ns-rejected-limit" style="padding:4px 8px;">
                                <option value="20">۲۰ خبر اخیر</option>
                                <option value="30" selected>۳۰ خبر اخیر</option>
                                <option value="50">۵۰ خبر اخیر</option>
                            </select>

                            <button type="button" class="button" id="ns-refresh-rejected">🔄 بارگذاری مجدد</button>
                        </div>
                    </div>

                    <p class="description" style="margin-bottom:15px;">
                        این اخبار توسط فیلتر کلمات کلیدی حذف شدند و منتشر نشدند.
                    </p>

                    <div id="ns-rejected-container" style="max-height:500px;overflow-y:auto;">
                        <p style="color:#999;text-align:center;padding:20px;">در حال بارگذاری...</p>
                    </div>
                </div>
            </div>

            <!-- New Section: Latest Filter Decisions -->
            <div style="margin-top:20px;">
                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-right:4px solid #00a32a;">
                    <h2 style="margin:0 0 15px 0;">✅ آخرین تصمیم‌های فیلتر</h2>

                    <div id="ns-all-decisions-container" style="max-height:400px;overflow-y:auto;">
                        <p style="color:#999;text-align:center;padding:20px;">در حال بارگذاری...</p>
                    </div>
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                function escHtml(str) {
                    return str ? str.toString().replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;') : '';
                }

                /* ========================================================================
                   Load Rejected News
                   ======================================================================== */
                function loadRejectedNews() {
                    var $c = $('#ns-rejected-container');
                    var limit = $('#ns-rejected-limit').val();

                    $c.html('<p style="color:#999;text-align:center;padding:20px;">در حال بارگذاری...</p>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'nextsafar_get_rejected_news',
                            nonce: '<?php echo wp_create_nonce('nextsafar_sync'); ?>',
                            limit: limit
                        },
                        success: function (response) {
                            if (!response.success || !response.data || !response.data.items || response.data.items.length === 0) {
                                $c.html('<p style="color:#999;text-align:center;padding:20px;">' +
                                    (response.data && response.data.message ? response.data.message : 'هیچ خبر رد شده‌ای یافت نشد') + '</p>');
                                return;
                            }

                            var html = '<table class="widefat" style="font-size:12px;"><thead><tr>' +
                                '<th style="width:30px;">#</th>' +
                                '<th>عنوان خبر</th>' +
                                '<th style="width:100px;">منبع</th>' +
                                '<th style="width:60px;">امتیاز</th>' +
                                '<th style="width:200px;">دلیل حذف</th>' +
                                '<th style="width:120px;">کلمات منفی</th>' +
                                '<th style="width:120px;">تاریخ</th>' +
                                '</tr></thead><tbody>';

                            response.data.items.forEach(function (item, i) {
                                var negWords = item.negative_matches && item.negative_matches.length > 0
                                    ? item.negative_matches.map(function (w) {
                                        return '<span style="background:#fce4e4;color:#d63638;padding:1px 6px;border-radius:3px;font-size:11px;margin:1px;">' + escHtml(w) + '</span>';
                                    }).join(' ')
                                    : '<span style="color:#999;">—</span>';

                                html += '<tr style="' + (i % 2 === 0 ? 'background:#fafafa;' : '') + '">' +
                                    '<td style="color:#999;">' + (i + 1) + '</td>' +
                                    '<td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escHtml(item.news_title) + '">' + escHtml(item.news_title) + '</td>' +
                                    '<td>' + escHtml(item.source_name || '—') + '</td>' +
                                    '<td style="font-weight:bold;color:#d63638;">' + item.score + '</td>' +
                                    '<td style="font-size:11px;color:#666;">' + escHtml(item.reason) + '</td>' +
                                    '<td>' + negWords + '</td>' +
                                    '<td style="font-size:11px;color:#999;">' + escHtml(item.created_at) + '</td>' +
                                    '</tr>';
                            });

                            html += '</tbody></table>';
                            html += '<p style="margin-top:10px;color:#666;font-size:12px;">نمایش ' + response.data.count + ' خبر رد شده</p>';

                            $c.html(html);
                        },
                        error: function (xhr) {
                            console.error('AJAX Error:', xhr);
                            $c.html('<p style="color:#d63638;text-align:center;padding:20px;">خطا در بارگذاری (کد: ' + xhr.status + ')</p>');
                        }
                    });
                }

                /* ========================================================================
                   Load All Decisions
                   ======================================================================== */
                function loadAllDecisions() {
                    var $c = $('#ns-all-decisions-container');

                    $c.html('<p style="color:#999;text-align:center;padding:20px;">در حال بارگذاری...</p>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'nextsafar_get_filter_history',
                            nonce: '<?php echo wp_create_nonce('nextsafar_sync'); ?>'
                        },
                        success: function (response) {
                            if (!response.success || !response.data || response.data.length === 0) {
                                $c.html('<p style="color:#999;text-align:center;padding:20px;">رکوردی یافت نشد (هنوز خبری پردازش نشده)</p>');
                                return;
                            }

                            var html = '<table class="widefat" style="font-size:12px;"><thead><tr>' +
                                '<th>عنوان</th>' +
                                '<th style="width:60px;">امتیاز</th>' +
                                '<th style="width:80px;">تصمیم</th>' +
                                '<th>دلیل</th>' +
                                '<th style="width:100px;">تاریخ</th>' +
                                '</tr></thead><tbody>';

                            response.data.forEach(function (item) {
                                var badge = item.decision === 'publish'
                                    ? '<span style="background:#edfaef;color:#00a32a;padding:2px 8px;border-radius:3px;font-weight:bold;">✅ انتشار</span>'
                                    : (item.decision === 'delete'
                                        ? '<span style="background:#fce4e4;color:#d63638;padding:2px 8px;border-radius:3px;font-weight:bold;">🗑️ حذف</span>'
                                        : '<span style="background:#fcf8e3;color:#dba617;padding:2px 8px;border-radius:3px;font-weight:bold;">🤖 بررسی</span>');

                                html += '<tr>' +
                                    '<td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escHtml(item.news_title) + '">' + escHtml(item.news_title) + '</td>' +
                                    '<td style="font-weight:bold;">' + item.score + '</td>' +
                                    '<td>' + badge + '</td>' +
                                    '<td style="font-size:11px;color:#666;">' + escHtml(item.reason) + '</td>' +
                                    '<td style="font-size:11px;color:#999;">' + escHtml(item.created_at) + '</td>' +
                                    '</tr>';
                            });

                            html += '</tbody></table>';

                            $c.html(html);
                        },
                        error: function (xhr) {
                            console.error('AJAX Error:', xhr);
                            $c.html('<p style="color:#d63638;text-align:center;padding:20px;">خطا در بارگذاری (کد: ' + xhr.status + ')</p>');
                        }
                    });
                }

                /* ========================================================================
                   Event Handlers
                   ======================================================================== */
                $('#ns-refresh-rejected').on('click', loadRejectedNews);
                $('#ns-rejected-limit').on('change', loadRejectedNews);

                // Initial load.
                loadRejectedNews();
                loadAllDecisions();

                // Refresh every 30 seconds.
                setInterval(function () {
                    loadRejectedNews();
                    loadAllDecisions();
                }, 30000);
            });
        </script>
        <?php
    }

    /* ========================================================================
       FIX: ajax_get_history method that previously did not exist.
       ======================================================================== */
    public static function ajax_get_history() {
        check_ajax_referer('nextsafar_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز');
        }

        $results = \NextSafar\API\NewsFilter::get_filter_history(30);

        wp_send_json_success($results);
    }

    /* ========================================================================
       AJAX: Get Latest Rejected News (decision=delete)
       ======================================================================== */
    public static function ajax_get_rejected() {
        check_ajax_referer('nextsafar_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز');
        }

        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_filter_log';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            wp_send_json_success(['items' => [], 'message' => 'جدول لاگ وجود ندارد']);
        }

        $limit = min(50, max(10, (int) ($_POST['limit'] ?? 30)));

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT news_title, source_name, score, decision, reason,
                    positive_matches, negative_matches, ai_checked, created_at
             FROM {$table}
             WHERE decision = 'delete'
             ORDER BY created_at DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);

        if (empty($items)) {
            wp_send_json_success(['items' => [], 'message' => 'هیچ خبر رد شده‌ای یافت نشد']);
        }

        foreach ($items as &$row) {
            $row['positive_matches'] = maybe_unserialize($row['positive_matches']) ?: [];
            $row['negative_matches'] = maybe_unserialize($row['negative_matches']) ?: [];
            $row['ai_checked'] = (bool) $row['ai_checked'];
            $row['score'] = (int) $row['score'];
        }

        wp_send_json_success(['items' => $items, 'count' => count($items)]);
    }

    private static function get_filter_stats(): array {
        global $wpdb;

        $stats = [
            'total_published' => 0,
            'total_draft' => 0,
            'total_deleted' => 0,
            'ai_checks' => 0,
        ];

        $stats['total_published'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'travelnews' AND post_status = 'publish'");
        $stats['total_draft'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'travelnews' AND post_status = 'draft'");

        $filter_table = $wpdb->prefix . 'ns_news_filter_log';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$filter_table}'") === $filter_table) {
            $stats['total_deleted'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$filter_table} WHERE decision = 'delete'");
            $stats['ai_checks'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$filter_table} WHERE ai_checked = 1");
        }

        return $stats;
    }

    private static function save_keywords(): array {
        $positive_text = sanitize_textarea_field(wp_unslash($_POST['positive_keywords'] ?? ''));
        $negative_text = sanitize_textarea_field(wp_unslash($_POST['negative_keywords'] ?? ''));

        $save_positive_only = isset($_POST['save_positive_only']) && $_POST['save_positive_only'] == '1';
        $save_negative_only = isset($_POST['save_negative_only']) && $_POST['save_negative_only'] == '1';

        $current_keywords = \NextSafar\API\NewsFilter::get_keywords();

        $new_positive = $save_positive_only ? array_filter(array_map('trim', explode("\n", $positive_text))) : $current_keywords['positive'];

        $new_negative = $save_negative_only ? array_filter(array_map('trim', explode("\n", $negative_text))) : $current_keywords['negative'];

        if (!$save_positive_only && !$save_negative_only) {
            $new_positive = array_filter(array_map('trim', explode("\n", $positive_text)));

            $new_negative = array_filter(array_map('trim', explode("\n", $negative_text)));
        }

        \NextSafar\API\NewsFilter::save_keywords(array_unique($new_positive), array_unique($new_negative));

        return [
            'success' => true,
            'type' => 'success',
            'message' => sprintf('کلمات کلیدی ذخیره شد (%d مثبت، %d منفی)', count($new_positive), count($new_negative)),
        ];
    }

    private static function save_thresholds(): array {
        $publish = (int) ($_POST['publish_threshold'] ?? 20);
        $delete = (int) ($_POST['delete_threshold'] ?? -10);

        if ($delete >= $publish) {
            return [
                'success' => false,
                'type' => 'error',
                'message' => 'حداکثر امتیاز حذف باید کمتر از حداقل امتیاز انتشار باشد.',
            ];
        }

        update_option('nextsafar_news_filter_publish_threshold', $publish);
        update_option('nextsafar_news_filter_delete_threshold', $delete);

        return [
            'success' => true,
            'type' => 'success',
            'message' => 'تنظیمات آستانه ذخیره شد.',
        ];
    }

    private static function reset_keywords(): array {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_keywords';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $wpdb->query("TRUNCATE TABLE {$table}");
        }

        \NextSafar\Database\NewsTables::seed_default_keywords();

        return [
            'success' => true,
            'type' => 'success',
            'message' => 'کلمات کلیدی به حالت پیش‌فرض بازنشانی شد.',
        ];
    }
}