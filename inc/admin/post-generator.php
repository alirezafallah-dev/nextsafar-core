<?php
/**
 * NextSafar Post Generator
 * Smart post generation: Hotels, Restaurants, Destinations, Hospitals
 * 
 * @package NextSafar\Admin
 * @since   3.0.0
 */

namespace NextSafar\Admin;

if (!defined('ABSPATH')) exit;

class PostGenerator {
    
    public static function init(): void {
        add_action('wp_ajax_nextsafar_pg_sync', [__CLASS__, 'ajax_sync']);
        add_action('wp_ajax_nextsafar_pg_status', [__CLASS__, 'ajax_status']);
        add_action('wp_ajax_nextsafar_pg_test_api', [__CLASS__, 'ajax_test_api']);
    }
    
    /**
     * Main sync page
     */
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
            .pg-progress-bar {
                background: #f0f0f1;
                border-radius: 4px;
                height: 20px;
                margin: 15px 0;
                overflow: hidden;
            }
            .pg-progress-fill {
                background: #2271b1;
                height: 100%;
                width: 0%;
                transition: width 0.5s ease;
            }
            .pg-sync-log {
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

        <div class="wrap">
            <h1>تولید پست هوشمند</h1>
            <p>همگام‌سازی و تولید خودکار پست برای هتل، رستوران، مقصد و بیمارستان از منابع خارجی</p>

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

                <div id="pg-progress" style="display:none;">
                    <h3>وضعیت</h3>
                    <div class="pg-progress-bar">
                        <div id="pg-progress-fill" class="pg-progress-fill"></div>
                    </div>
                    <div id="pg-sync-log" class="pg-sync-log"></div>
                </div>

                <div id="pg-results" style="display:none;">
                    <h3>نتیجه</h3>
                    <div id="pg-results-content"></div>
                </div>
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

                function resetButton() {
                    $('#pg-start-sync').prop('disabled', false).text('شروع همگام‌سازی');
                }

                // Test API
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
                                html += '<tr><td><strong>' + escHtml(r.name) + '</strong></td>';
                                html += '<td style="color:' + color + ';">' + status + '</td>';
                                html += '<td>' + escHtml(r.message) + '</td></tr>';
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

                // Start Sync
                $('#pg-start-sync').on('click', function () {
                    if (pgInProgress) {
                        alert('یک همگام‌سازی در حال اجرا است!');
                        return;
                    }

                    var syncType = $('#pg-sync-type').val();
                    var source = $('#pg-sync-source').val();
                    var location = $('#pg-sync-location').val().trim();
                    var limit = parseInt($('#pg-sync-limit').val()) || 20;

                    if (!location) {
                        alert('لطفاً مقصد جستجو را وارد کنید');
                        return;
                    }

                    pgInProgress = true;
                    $(this).prop('disabled', true).text('در حال همگام‌سازی...');
                    $('#pg-progress').show();
                    $('#pg-results').hide();
                    $('#pg-progress-fill').css('width', '0%');
                    $('#pg-sync-log').text('شروع...\n');

                    function pollStatus() {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'nextsafar_pg_status',
                                nonce: pgNonce
                            },
                            success: function (response) {
                                if (response.success && response.data) {
                                    var status = response.data;

                                    if (status.status === 'running') {
                                        var percent = status.percent || 0;
                                        $('#pg-progress-fill').css('width', percent + '%');

                                        var logText = 'در حال پردازش: ' + status.processed + ' از ' + status.total + '\n';
                                        if (status.current_item) {
                                            logText += 'فعلی: ' + status.current_item + '\n';
                                        }
                                        $('#pg-sync-log').text(logText);
                                        setTimeout(pollStatus, 2000);
                                    } else if (status.status === 'complete') {
                                        $('#pg-progress-fill').css('width', '100%');
                                        $('#pg-sync-log').text(
                                            'کامل شد!\n' +
                                            'پردازش شده: ' + status.processed + '\n' +
                                            'موفق: ' + status.success + '\n' +
                                            'خطا: ' + status.failed + '\n'
                                        );
                                        pgInProgress = false;
                                        resetButton();
                                        $('#pg-results').show();
                                        $('#pg-results-content').html(
                                            '<p>موفق: ' + status.success + ' | خطا: ' + status.failed + '</p>'
                                        );
                                    } else if (status.status === 'error') {
                                        $('#pg-sync-log').text('خطا: ' + status.message);
                                        pgInProgress = false;
                                        resetButton();
                                    } else {
                                        setTimeout(pollStatus, 2000);
                                    }
                                } else {
                                    setTimeout(pollStatus, 2000);
                                }
                            },
                            error: function () {
                                setTimeout(pollStatus, 3000);
                            }
                        });
                    }

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'nextsafar_pg_sync',
                            nonce: pgNonce,
                            sync_type: syncType,
                            source: source,
                            location: location,
                            limit: limit
                        },
                        success: function (response) {
                            if (response.success) {
                                $('#pg-sync-log').text('شروع همگام‌سازی...\n');
                                setTimeout(pollStatus, 2000);
                            } else {
                                $('#pg-sync-log').text('❌ ' + (response.data.message || response.data));
                                pgInProgress = false;
                                resetButton();
                            }
                        },
                        error: function () {
                            $('#pg-sync-log').text('خطا در ارتباط با سرور');
                            pgInProgress = false;
                            resetButton();
                        }
                    });
                });
            });
        </script>
        <?php
    }
    
    /**
     * Settings page - API keys only
     */
    public static function render_settings_page(): void {
        if (!current_user_can('manage_options')) return;
        
        $message = '';
        
        // Save API settings
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
            
            <!-- API Keys Section -->
            <div style="background:#fff;padding:24px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:20px;">
                <h2>کلیدهای اتصال</h2>
                <form method="post">
                    <?php wp_nonce_field('nextsafar_pg_api_settings'); ?>
                    <table class="form-table">
                        <tr>
                            <th>کلید سرچ‌ای‌پی‌آی:</th>
                            <td>
                                <input type="password" name="nextsafar_searchapi_key" value="<?php echo esc_attr($searchapi_key); ?>" class="regular-text" dir="ltr" autocomplete="off">
                                <p class="description">از سایت سرچ‌ای‌پی‌آی‌آیو دریافت کنید</p>
                            </td>
                        </tr>
                        <tr>
                            <th>کلید سرپ‌ای‌پی‌آی:</th>
                            <td>
                                <input type="password" name="nextsafar_serpapi_key" value="<?php echo esc_attr($serpapi_key); ?>" class="regular-text" dir="ltr" autocomplete="off">
                                <p class="description">از سایت سرپ‌ای‌پی‌آی‌آیو دریافت کنید</p>
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
    
    /**
     * AJAX: Test API
     */
    public static function ajax_test_api(): void {
        check_ajax_referer('nextsafar_pg', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی غیرمجاز']);
        }
        
        $force_refresh = isset($_POST['force_refresh']) && $_POST['force_refresh'] === '1';
        
        if (class_exists('\NextSafar\API\NewsSync') && method_exists('\NextSafar\API\NewsSync', 'test_all_apis')) {
            try {
                $results = \NextSafar\API\NewsSync::test_all_apis($force_refresh);
                wp_send_json_success($results);
            } catch (\Throwable $e) {
                wp_send_json_error(['message' => $e->getMessage()]);
            }
        }
        
        wp_send_json_error(['message' => 'متد تست در دسترس نیست']);
    }
    
    /**
     * AJAX: Start Sync
     */
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
    
    /**
     * AJAX: Sync Status
     */
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