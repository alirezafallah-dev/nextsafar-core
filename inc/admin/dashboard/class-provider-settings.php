<?php
/**
 * Provider Settings Page
 * API connection management for search engine
 * 
 * @package NextSafar\Admin\Dashboard
 * @since   3.1.0
 */

namespace NextSafar\Admin\Dashboard;

if (!defined('ABSPATH')) exit;

class ProviderSettings {
    
    public static function render_page(): void {
        if (!current_user_can('manage_options')) return;
        
        $message = '';
        
        // Save settings
        if (isset($_POST['save_provider_settings']) && check_admin_referer('nextsafar_provider_settings')) {
            update_option('nextsafar_searchapi_key', sanitize_text_field($_POST['searchapi_key'] ?? ''));
            update_option('nextsafar_serpapi_key', sanitize_text_field($_POST['serpapi_key'] ?? ''));
            update_option('nextsafar_active_source', sanitize_text_field($_POST['active_source'] ?? 'searchapi'));
            update_option('nextsafar_provider_timeout', absint($_POST['timeout'] ?? 30));
            update_option('nextsafar_provider_retries', absint($_POST['retries'] ?? 2));
            $message = 'تنظیمات ذخیره شد';
        }
        
        $searchapi_key = get_option('nextsafar_searchapi_key', '');
        $serpapi_key = get_option('nextsafar_serpapi_key', '');
        $active_source = get_option('nextsafar_active_source', 'searchapi');
        $timeout = get_option('nextsafar_provider_timeout', 30);
        $retries = get_option('nextsafar_provider_retries', 2);
        ?>
        <div class="wrap">
            <h1>ارائه‌دهندگان اتصال جستجو</h1>
            
            <?php if ($message): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($message); ?></p></div>
            <?php endif; ?>
            
            <!-- Connection Status -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
                <div style="background:#fff;padding:24px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-right:4px solid <?php echo !empty($searchapi_key) ? '#00a32a' : '#d63638'; ?>;">
                    <h2 style="margin-top:0;">سرچ‌ای‌پی‌آی</h2>
                    <p style="font-size:24px;font-weight:bold;color:<?php echo !empty($searchapi_key) ? '#00a32a' : '#d63638'; ?>;">
                        <?php echo !empty($searchapi_key) ? '✅ متصل' : '❌ قطع'; ?>
                    </p>
                    <?php if (!empty($searchapi_key)): ?>
                        <p style="color:#666;font-size:12px;">کلید: <?php echo substr($searchapi_key, 0, 8); ?>...</p>
                    <?php endif; ?>
                </div>
                
                <div style="background:#fff;padding:24px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);border-right:4px solid <?php echo !empty($serpapi_key) ? '#00a32a' : '#d63638'; ?>;">
                    <h2 style="margin-top:0;">سرپ‌ای‌پی‌آی</h2>
                    <p style="font-size:24px;font-weight:bold;color:<?php echo !empty($serpapi_key) ? '#00a32a' : '#d63638'; ?>;">
                        <?php echo !empty($serpapi_key) ? '✅ متصل' : '❌ قطع'; ?>
                    </p>
                    <?php if (!empty($serpapi_key)): ?>
                        <p style="color:#666;font-size:12px;">کلید: <?php echo substr($serpapi_key, 0, 8); ?>...</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Settings Form -->
            <div style="background:#fff;padding:24px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                <h2 style="margin-top:0;">تنظیمات اتصال</h2>
                <form method="post">
                    <?php wp_nonce_field('nextsafar_provider_settings'); ?>
                    <table class="form-table">
                        <tr>
                            <th>کلید سرچ‌ای‌پی‌آی:</th>
                            <td>
                                <input type="password" name="searchapi_key" value="<?php echo esc_attr($searchapi_key); ?>" class="regular-text" dir="ltr" autocomplete="off">
                                <p class="description">از سایت سرچ‌ای‌پی‌آی‌آیو دریافت کنید</p>
                            </td>
                        </tr>
                        <tr>
                            <th>کلید سرپ‌ای‌پی‌آی:</th>
                            <td>
                                <input type="password" name="serpapi_key" value="<?php echo esc_attr($serpapi_key); ?>" class="regular-text" dir="ltr" autocomplete="off">
                                <p class="description">از سایت سرپ‌ای‌پی‌آی‌آیو دریافت کنید</p>
                            </td>
                        </tr>
                        <tr>
                            <th>منبع فعال:</th>
                            <td>
                                <select name="active_source">
                                    <option value="searchapi" <?php selected($active_source, 'searchapi'); ?>>سرچ‌ای‌پی‌آی</option>
                                    <option value="serpapi" <?php selected($active_source, 'serpapi'); ?>>سرپ‌ای‌پی‌آی</option>
                                </select>
                                <p class="description">منبع اصلی برای جستجو</p>
                            </td>
                        </tr>
                        <tr>
                            <th>زمان انتظار (ثانیه):</th>
                            <td>
                                <input type="number" name="timeout" value="<?php echo esc_attr($timeout); ?>" min="5" max="120" class="small-text">
                            </td>
                        </tr>
                        <tr>
                            <th>تعداد تلاش مجدد:</th>
                            <td>
                                <input type="number" name="retries" value="<?php echo esc_attr($retries); ?>" min="0" max="5" class="small-text">
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" name="save_provider_settings" class="button button-primary">ذخیره تنظیمات</button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
}