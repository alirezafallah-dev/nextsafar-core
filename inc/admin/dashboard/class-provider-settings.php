<?php
/**
 * NextSafar Core - Provider Settings Page
 * 
 * Manages API keys and provider configuration.
 * 
 * @package NextSafar\Admin\Dashboard
 * @since   2.6.0
 */

namespace NextSafar\Admin\Dashboard;

use NextSafar\Search\ProviderFactory;
use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class ProviderSettings {
    
    /**
     * Render provider settings page
     */
    public static function render_page(): void {
        if (!current_user_can('manage_options')) return;
        
        $message = '';
        $message_type = '';
        
        // Handle form submission
        if (isset($_POST['save_providers']) && wp_verify_nonce($_POST['_wpnonce'], 'ns_providers_save')) {
            update_option('ns_live_provider', sanitize_text_field($_POST['primary_provider'] ?? 'serpapi'));
            update_option('ns_live_serpapi_key', sanitize_text_field($_POST['serpapi_key'] ?? ''));
            update_option('ns_live_searchapi_key', sanitize_text_field($_POST['searchapi_key'] ?? ''));
            update_option('ns_live_usd_rate', floatval($_POST['usd_rate'] ?? 0));
            
            $message = '✓ تنظیمات با موفقیت ذخیره شد';
            $message_type = 'success';
            
            Logger::info('Provider settings updated', [
                'primary' => $_POST['primary_provider'] ?? 'serpapi',
            ]);
        }
        
        // Test connections
        if (isset($_POST['test_serpapi']) && wp_verify_nonce($_POST['_wpnonce'], 'ns_providers_save')) {
            $provider = ProviderFactory::create('serpapi');
            $test = $provider->test_connection();
            $message = $test['message'];
            $message_type = $test['ok'] ? 'success' : 'error';
        }
        
        if (isset($_POST['test_searchapi']) && wp_verify_nonce($_POST['_wpnonce'], 'ns_providers_save')) {
            $provider = ProviderFactory::create('searchapi');
            $test = $provider->test_connection();
            $message = $test['message'];
            $message_type = $test['ok'] ? 'success' : 'error';
        }
        
        $primary = get_option('ns_live_provider', 'serpapi');
        $serpapi_key = get_option('ns_live_serpapi_key', '');
        $searchapi_key = get_option('ns_live_searchapi_key', '');
        $usd_rate = get_option('ns_live_usd_rate', 0);
        $statuses = ProviderFactory::get_all_statuses();
        ?>
        <div class="wrap">
            <h1>تنظیمات Provider‌ها</h1>
            
            <?php if ($message): ?>
                <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>
            
            <div class="ns-settings-card">
                <h2>Provider اصلی</h2>
                <form method="post">
                    <?php wp_nonce_field('ns_providers_save'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th>Provider پیش‌فرض:</th>
                            <td>
                                <select name="primary_provider">
                                    <option value="serpapi" <?php selected($primary, 'serpapi'); ?>>
                                        SerpApi (توصیه می‌شود)
                                    </option>
                                    <option value="searchapi" <?php selected($primary, 'searchapi'); ?>>
                                        SearchApi (Fallback)
                                    </option>
                                </select>
                                <p class="description">
                                    اگر Provider اصلی با خطا مواجه شود، سیستم به صورت خودکار به Fallback می‌رود.
                                </p>
                            </td>
                        </tr>
                    </table>
            </div>
            
            <div class="ns-settings-card">
                <h2>SerpApi (Primary)</h2>
                <div class="ns-provider-status <?php echo $statuses['serpapi']['available'] ? 'active' : 'inactive'; ?>">
                    <?php echo $statuses['serpapi']['available'] ? '✓ فعال' : '✗ غیرفعال'; ?>
                </div>
                <table class="form-table">
                    <tr>
                        <th>API Key:</th>
                        <td>
                            <input type="password" 
                                   name="serpapi_key" 
                                   value="<?php echo esc_attr($serpapi_key); ?>" 
                                   class="regular-text"
                                   dir="ltr"
                                   style="font-family: monospace;">
                            <p class="description">
                                از <a href="https://serpapi.com" target="_blank">serpapi.com</a> دریافت کنید
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th></th>
                        <td>
                            <button type="submit" name="test_serpapi" class="button">
                                🧪 تست اتصال
                            </button>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="ns-settings-card">
                <h2>SearchApi (Fallback)</h2>
                <div class="ns-provider-status <?php echo $statuses['searchapi']['available'] ? 'active' : 'inactive'; ?>">
                    <?php echo $statuses['searchapi']['available'] ? '✓ فعال' : '✗ غیرفعال'; ?>
                </div>
                <table class="form-table">
                    <tr>
                        <th>API Key:</th>
                        <td>
                            <input type="password" 
                                   name="searchapi_key" 
                                   value="<?php echo esc_attr($searchapi_key); ?>" 
                                   class="regular-text"
                                   dir="ltr"
                                   style="font-family: monospace;">
                            <p class="description">
                                از <a href="https://searchapi.io" target="_blank">searchapi.io</a> دریافت کنید
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th></th>
                        <td>
                            <button type="submit" name="test_searchapi" class="button">
                                🧪 تست اتصال
                            </button>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="ns-settings-card">
                <h2>تنظیمات ارزی</h2>
                <table class="form-table">
                    <tr>
                        <th>نرخ USD (دستی):</th>
                        <td>
                            <input type="number" 
                                   name="usd_rate" 
                                   value="<?php echo esc_attr($usd_rate); ?>" 
                                   class="small-text"
                                   dir="ltr">
                            <p class="description">
                                اگر 0 باشد، از نرخ زنده استفاده می‌شود. عدد بگذارید تا ثابت شود.
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
            
            <p class="submit">
                <button type="submit" name="save_providers" class="button button-primary button-large">
                    💾 ذخیره تنظیمات
                </button>
            </p>
                </form>
        </div>
        
        <style>
            .ns-settings-card {
                background: #fff;
                padding: 24px;
                margin: 20px 0;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
                position: relative;
            }
            .ns-settings-card h2 {
                margin-top: 0;
                padding-bottom: 12px;
                border-bottom: 2px solid #f0f0f0;
            }
            .ns-provider-status {
                position: absolute;
                top: 24px;
                left: 24px;
                padding: 6px 12px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 600;
            }
            .ns-provider-status.active {
                background: #46b450;
                color: #fff;
            }
            .ns-provider-status.inactive {
                background: #dc3232;
                color: #fff;
            }
        </style>
        <?php
    }
}