<?php
/**
 * NextSafar Core - Unified Admin Menu
 * 
 * Consolidates ALL admin menus into ONE organized menu structure.
 * 
 * Menu Structure:
 * NextSafar
 * ├── Overview
 * ├── Bookings
 * │   ├── All Bookings
 * │   └── Statistics
 * ├── Providers & APIs
 * │   ├── API Providers
 * │   ├── Exchange Rates
 * │   ├── Payment Gateways
 * │   └── SMS Service
 * ├── Synchronization
 * │   ├── Data Sync
 * │   └── News Filter
 * ├── Analytics
 * │   ├── System Logs
 * │   ├── Cache Manager
 * │   └── Performance
 * └── Advanced
 *     ├── AI Trip Planner
 *     ├── Live Search (Test)
 *     └── API Settings
 * 
 * @package NextSafar\Admin
 * @since   2.7.0
 */

namespace NextSafar\Admin;

if (!defined('ABSPATH')) exit;

class Menu {
    
    /**
     * Initialize menu system
     */
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_menus'], 100);
        
        // Remove duplicate/old menus that we're consolidating
        add_action('admin_menu', [__CLASS__, 'remove_old_menus'], 999);
    }
    
    /**
     * Register unified menu structure
     */
    public static function register_menus(): void {
        
        // ═══════════════════════════════════════════════════════════
        // 1. MAIN MENU: NextSafar
        // ═══════════════════════════════════════════════════════════
        add_menu_page(
            'NextSafar',
            'NextSafar',
            'manage_options',
            'nextsafar-dashboard',
            [Dashboard\DashboardPage::class, 'render_page'],
            'dashicons-airplane',
            3
        );
        
        // ═══════════════════════════════════════════════════════════
        // 2. Overview (same as main page)
        // ═══════════════════════════════════════════════════════════
        add_submenu_page(
            'nextsafar-dashboard',
            'Overview',
            '📊 Overview',
            'manage_options',
            'nextsafar-dashboard',
            [Dashboard\DashboardPage::class, 'render_page']
        );
        
        // ═══════════════════════════════════════════════════════════
        // 3. BOOKINGS Section
        // ═══════════════════════════════════════════════════════════
        
        // 3.1 All Bookings
        add_submenu_page(
            'nextsafar-dashboard',
            'مدیریت رزروها',
            '📋 رزروها',
            'manage_options',
            'nextsafar-bookings',
            [Booking\AdminBookingList::class, 'render_page']
        );
        
        // 3.2 Booking Statistics (hidden from menu - accessed via booking list)
        add_submenu_page(
            null,
            'آمار رزروها',
            'آمار رزروها',
            'manage_options',
            'nextsafar-booking-stats',
            [Booking\AdminBookingStats::class, 'render_page']
        );
        
        // 3.3 Booking Detail (hidden)
        add_submenu_page(
            null,
            'جزئیات رزرو',
            'جزئیات رزرو',
            'manage_options',
            'nextsafar-booking-detail',
            [Booking\AdminBookingDetail::class, 'render_page']
        );
        
        // ═══════════════════════════════════════════════════════════
        // 4. PROVIDERS & APIs Section
        // ═══════════════════════════════════════════════════════════
        
        // 4.1 API Providers (SerpApi, SearchApi, etc.)
        add_submenu_page(
            'nextsafar-dashboard',
            'API Providers',
            '🌐 API Providers',
            'manage_options',
            'nextsafar-providers',
            [Dashboard\ProviderSettings::class, 'render_page']
        );
        
        // 4.2 Exchange Rates
        add_submenu_page(
            'nextsafar-dashboard',
            'نرخ ارز',
            '💱 نرخ ارز',
            'manage_options',
            'nextsafar-exchange',
            [Exchange::class, 'render_page']
        );
        
        // 4.3 Payment Gateways (placeholder for future)
        add_submenu_page(
            'nextsafar-dashboard',
            'درگاه‌های پرداخت',
            '💳 درگاه پرداخت',
            'manage_options',
            'nextsafar-payment',
            [__CLASS__, 'render_payment_page']
        );
        
        // 4.4 SMS Service (placeholder for future)
        add_submenu_page(
            'nextsafar-dashboard',
            'سرویس پیامک',
            '📱 سرویس پیامک',
            'manage_options',
            'nextsafar-sms',
            [__CLASS__, 'render_sms_page']
        );
        
        // ═══════════════════════════════════════════════════════════
        // 5. SYNCHRONIZATION Section
        // ═══════════════════════════════════════════════════════════
        
        // 5.1 Data Sync
        add_submenu_page(
            'nextsafar-dashboard',
            'همگام‌سازی داده‌ها',
            '🔄 همگام‌سازی',
            'manage_options',
            'nextsafar-sync',
            [SyncPage::class, 'render_sync_page']
        );
        
        // 5.2 News Filter
        add_submenu_page(
            'nextsafar-dashboard',
            'فیلتر اخبار گردشگری',
            '📰 فیلتر اخبار',
            'manage_options',
            'nextsafar-news-filter',
            [NewsFilterSettings::class, 'render_page']
        );
        
        // ═══════════════════════════════════════════════════════════
        // 6. ANALYTICS Section
        // ═══════════════════════════════════════════════════════════
        
        // 6.1 System Logs
        add_submenu_page(
            'nextsafar-dashboard',
            'لاگ‌های سیستم',
            '📝 لاگ‌ها',
            'manage_options',
            'nextsafar-logs',
            [Dashboard\SearchLogs::class, 'render_page']
        );
        
        // 6.2 Cache Manager
        add_submenu_page(
            'nextsafar-dashboard',
            'مدیریت Cache',
            '💾 Cache',
            'manage_options',
            'nextsafar-cache',
            [Dashboard\CacheManager::class, 'render_page']
        );
        
        // 6.3 Performance Metrics
        add_submenu_page(
            'nextsafar-dashboard',
            'آمار عملکرد',
            '📈 Performance',
            'manage_options',
            'nextsafar-metrics',
            [Dashboard\PerformanceMetrics::class, 'render_page']
        );
        
        // ═══════════════════════════════════════════════════════════
        // 7. ADVANCED Section
        // ═══════════════════════════════════════════════════════════
        
        // 7.1 AI Trip Planner Settings
        add_submenu_page(
            'nextsafar-dashboard',
            'تنظیمات AI Trip Planner',
            '🤖 AI Trip Planner',
            'manage_options',
            'nextsafar-ai-trip',
            [AiTripSettings::class, 'render_page']
        );
        
        // 7.2 Live Search Test
        add_submenu_page(
            'nextsafar-dashboard',
            'جستجوی زنده (تست)',
            '🔍 جستجوی زنده',
            'manage_options',
            'nextsafar-live-search',
            [LiveSearch::class, 'render_page']
        );
        
        // 7.3 API Settings (old settings page)
        add_submenu_page(
            'nextsafar-dashboard',
            'تنظیمات API',
            '⚙️ تنظیمات API',
            'manage_options',
            'nextsafar-settings',
            [Settings::class, 'render_settings_page']
        );
    }
    
    /**
     * Remove old/duplicate menus to avoid confusion
     */
    public static function remove_old_menus(): void {
        global $submenu;
        
        // Remove old "تنظیمات سفر بعدی" menu (we consolidated everything)
        remove_menu_page('nextsafar-settings-old');
        
        // Clean up any leftover submenu items that shouldn't be there
        // This helps prevent duplicate entries
    }
    
    /**
     * Render Payment Gateways page
     */
    public static function render_payment_page(): void {
        if (!current_user_can('manage_options')) return;
        
        $gateway_settings = \NextSafar\Payment\Gateways\ZarinpalGateway::get_settings();
        
        // Handle save
        $message = '';
        if (isset($_POST['save_payment']) && wp_verify_nonce($_POST['_wpnonce'], 'ns_payment_save')) {
            \NextSafar\Payment\Gateways\ZarinpalGateway::update_settings(
                sanitize_text_field($_POST['merchant_id'] ?? ''),
                !empty($_POST['is_sandbox'])
            );
            $message = '✓ تنظیمات درگاه پرداخت ذخیره شد';
            $gateway_settings = \NextSafar\Payment\Gateways\ZarinpalGateway::get_settings();
        }
        
        // Test connection
        if (isset($_POST['test_gateway']) && wp_verify_nonce($_POST['_wpnonce'], 'ns_payment_save')) {
            $gateway = new \NextSafar\Payment\Gateways\ZarinpalGateway();
            $test = $gateway->test_connection();
            $message = $test['message'];
        }
        ?>
        <div class="wrap">
            <h1>💳 درگاه‌های پرداخت</h1>
            
            <?php if ($message): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($message); ?></p></div>
            <?php endif; ?>
            
            <div class="ns-gateway-card">
                <h2>زرین‌پال (Zarinpal)</h2>
                <div class="ns-gateway-status <?php echo !empty($gateway_settings['merchant_id']) ? 'active' : 'inactive'; ?>">
                    <?php echo !empty($gateway_settings['merchant_id']) ? '✓ فعال' : '✗ غیرفعال'; ?>
                </div>
                
                <form method="post">
                    <?php wp_nonce_field('ns_payment_save'); ?>
                    <table class="form-table">
                        <tr>
                            <th>Merchant ID:</th>
                            <td>
                                <input type="text" 
                                       name="merchant_id" 
                                       value="<?php echo esc_attr($gateway_settings['merchant_id']); ?>" 
                                       class="regular-text"
                                       dir="ltr"
                                       style="font-family: monospace;"
                                       placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                                <p class="description">
                                    از پنل <a href="https://zarinpal.com" target="_blank">زرین‌پال</a> دریافت کنید
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th>محیط تست (Sandbox):</th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           name="is_sandbox" 
                                           value="1"
                                           <?php checked($gateway_settings['is_sandbox']); ?>>
                                    استفاده از محیط آزمایشی زرین‌پال
                                </label>
                                <p class="description">
                                    در محیط Sandbox تراکنش واقعی انجام نمی‌شود
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" name="save_payment" class="button button-primary">
                            💾 ذخیره تنظیمات
                        </button>
                        <button type="submit" name="test_gateway" class="button">
                            🧪 تست اتصال
                        </button>
                    </p>
                </form>
            </div>
            
            <div class="ns-gateway-card ns-gateway-coming-soon">
                <h2>بانک ملت 🚧</h2>
                <p class="ns-coming-soon">به‌زودی اضافه خواهد شد</p>
            </div>
            
            <div class="ns-gateway-card ns-gateway-coming-soon">
                <h2>بانک سامان 🚧</h2>
                <p class="ns-coming-soon">به‌زودی اضافه خواهد شد</p>
            </div>
        </div>
        
        <style>
            .ns-gateway-card {
                background: #fff;
                padding: 24px;
                margin: 20px 0;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
                position: relative;
            }
            .ns-gateway-card h2 {
                margin-top: 0;
                padding-bottom: 12px;
                border-bottom: 2px solid #f0f0f0;
            }
            .ns-gateway-status {
                position: absolute;
                top: 24px;
                left: 24px;
                padding: 6px 12px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 600;
            }
            .ns-gateway-status.active {
                background: #46b450;
                color: #fff;
            }
            .ns-gateway-status.inactive {
                background: #dc3232;
                color: #fff;
            }
            .ns-gateway-coming-soon {
                opacity: 0.6;
            }
            .ns-coming-soon {
                color: #999;
                font-style: italic;
            }
        </style>
        <?php
    }
    
    /**
     * Render SMS Service page
     */
    public static function render_sms_page(): void {
        if (!current_user_can('manage_options')) return;
        
        $sms_settings = [
            'enabled'  => \NextSafar\Booking\BookingSms::is_enabled(),
            'username' => \NextSafar\Booking\BookingSms::get_username(),
            'password' => \NextSafar\Booking\BookingSms::get_password(),
            'from'     => \NextSafar\Booking\BookingSms::get_from(),
        ];
        
        $message = '';
        if (isset($_POST['save_sms']) && wp_verify_nonce($_POST['_wpnonce'], 'ns_sms_save')) {
            \NextSafar\Booking\BookingSms::update_settings([
                'username' => sanitize_text_field($_POST['username'] ?? ''),
                'password' => sanitize_text_field($_POST['password'] ?? ''),
                'from'     => sanitize_text_field($_POST['from'] ?? ''),
                'enabled'  => !empty($_POST['enabled']),
            ]);
            $message = '✓ تنظیمات پیامک ذخیره شد';
            $sms_settings = [
                'enabled'  => \NextSafar\Booking\BookingSms::is_enabled(),
                'username' => \NextSafar\Booking\BookingSms::get_username(),
                'password' => \NextSafar\Booking\BookingSms::get_password(),
                'from'     => \NextSafar\Booking\BookingSms::get_from(),
            ];
        }
        
        // Test SMS
        if (isset($_POST['test_sms']) && wp_verify_nonce($_POST['_wpnonce'], 'ns_sms_save')) {
            $test_phone = sanitize_text_field($_POST['test_phone'] ?? '');
            if (empty($test_phone)) {
                $message = '✗ شماره تلفن برای تست الزامی است';
            } else {
                $sent = \NextSafar\Booking\BookingSms::send(
                    $test_phone,
                    'تست پیامک نکست سفر - ' . date('Y-m-d H:i:s')
                );
                $message = $sent ? '✓ پیامک تست ارسال شد' : '✗ خطا در ارسال پیامک';
            }
        }
        ?>
        <div class="wrap">
            <h1>📱 سرویس پیامک</h1>
            
            <?php if ($message): ?>
                <div class="notice notice-<?php echo strpos($message, '✓') !== false ? 'success' : 'error'; ?> is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>
            
            <div class="ns-gateway-card">
                <h2>پنل پیامک (Payamak Panel)</h2>
                <div class="ns-gateway-status <?php echo $sms_settings['enabled'] ? 'active' : 'inactive'; ?>">
                    <?php echo $sms_settings['enabled'] ? '✓ فعال' : '✗ غیرفعال'; ?>
                </div>
                
                <form method="post">
                    <?php wp_nonce_field('ns_sms_save'); ?>
                    <table class="form-table">
                        <tr>
                            <th>فعال‌سازی:</th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           name="enabled" 
                                           value="1"
                                           <?php checked($sms_settings['enabled']); ?>>
                                    ارسال پیامک‌های سیستمی فعال باشد
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>نام کاربری:</th>
                            <td>
                                <input type="text" 
                                       name="username" 
                                       value="<?php echo esc_attr($sms_settings['username']); ?>" 
                                       class="regular-text"
                                       dir="ltr">
                            </td>
                        </tr>
                        <tr>
                            <th>رمز عبور:</th>
                            <td>
                                <input type="password" 
                                       name="password" 
                                       value="<?php echo esc_attr($sms_settings['password']); ?>" 
                                       class="regular-text"
                                       dir="ltr">
                            </td>
                        </tr>
                        <tr>
                            <th>شماره فرستنده:</th>
                            <td>
                                <input type="text" 
                                       name="from" 
                                       value="<?php echo esc_attr($sms_settings['from']); ?>" 
                                       class="regular-text"
                                       dir="ltr">
                                <p class="description">
                                    شماره خط اختصاصی شما
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <h3>تست ارسال پیامک</h3>
                    <table class="form-table">
                        <tr>
                            <th>شماره مقصد:</th>
                            <td>
                                <input type="tel" 
                                       name="test_phone" 
                                       class="regular-text"
                                       dir="ltr"
                                       placeholder="09123456789">
                                <button type="submit" name="test_sms" class="button">
                                    📤 ارسال تست
                                </button>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" name="save_sms" class="button button-primary">
                            💾 ذخیره تنظیمات
                        </button>
                    </p>
                </form>
            </div>
            
            <div class="ns-gateway-card">
                <h2>پیامک‌های ارسالی</h2>
                <table class="form-table">
                    <tr>
                        <td>
                            <strong>📩 تأیید رزرو:</strong> پس از ایجاد رزرو ویزا<br>
                            <strong>💳 تأیید پرداخت:</strong> پس از پرداخت موفق<br>
                            <strong>❌ لغو رزرو:</strong> در صورت لغو رزرو
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }
}

// Initialize
Menu::init();