<?php
/**
 * NextSafar Core - Admin Menu
 * Separate and independent menus
 * 
 * @package NextSafar\Admin
 * @since   3.1.0
 */

namespace NextSafar\Admin;

if (!defined('ABSPATH')) exit;

class Menu {
    
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_menus'], 10);
        add_action('admin_menu', [__CLASS__, 'remove_old_menus'], 999);
    }
    
    /**
     * Register all menus
     */
    public static function register_menus(): void {
        
        // ═══════════════════════════════════════════════════════════
        // 1. Smart News Settings
        // ═══════════════════════════════════════════════════════════
        add_menu_page(
            'اخبار هوشمند',
            'اخبار هوشمند',
            'manage_options',
            'nextsafar-news',
            [SyncPage::class, 'render_sync_page'],
            'dashicons-megaphone',
            25
        );
        
        add_submenu_page(
            'nextsafar-news',
            'پیشخوان اخبار',
            'پیشخوان',
            'manage_options',
            'nextsafar-news',
            [SyncPage::class, 'render_sync_page']
        );
        
        add_submenu_page(
            'nextsafar-news',
            'فیلتر اخبار گردشگری',
            'فیلتر اخبار',
            'manage_options',
            'nextsafar-news-filter',
            [NewsFilterSettings::class, 'render_page']
        );
        
        add_submenu_page(
            'nextsafar-news',
            'تنظیمات عمومی اخبار',
            'تنظیمات عمومی',
            'manage_options',
            'nextsafar-news-settings',
            [Settings::class, 'render_settings_page']
        );
        
        // ═══════════════════════════════════════════════════════════
        // 2. Smart Post Generator
        // ═══════════════════════════════════════════════════════════
        add_menu_page(
            'پست هوشمند',
            'پست هوشمند',
            'manage_options',
            'nextsafar-post-generator',
            [PostGenerator::class, 'render_page'],
            'dashicons-edit-large',
            26
        );
        
        add_submenu_page(
            'nextsafar-post-generator',
            'همگام‌سازی هتل، رستوران، مقصد و بیمارستان',
            'همگام‌سازی',
            'manage_options',
            'nextsafar-post-generator',
            [PostGenerator::class, 'render_page']
        );
        
        add_submenu_page(
            'nextsafar-post-generator',
            'تنظیمات پست هوشمند',
            'تنظیمات',
            'manage_options',
            'nextsafar-post-generator-settings',
            [PostGenerator::class, 'render_settings_page']
        );
        
        // ═══════════════════════════════════════════════════════════
        // 3. Search Engine
        // ═══════════════════════════════════════════════════════════
        add_menu_page(
            'جستجو',
            'جستجو',
            'manage_options',
            'nextsafar-search',
            [Dashboard\ProviderSettings::class, 'render_page'],
            'dashicons-search',
            27
        );
        
        add_submenu_page(
            'nextsafar-search',
            'ارائه‌دهندگان اتصال جستجو',
            'ارائه‌دهندگان اتصال',
            'manage_options',
            'nextsafar-search',
            [Dashboard\ProviderSettings::class, 'render_page']
        );
        
        add_submenu_page(
            'nextsafar-search',
            'لاگ‌های جستجو',
            'لاگ‌ها',
            'manage_options',
            'nextsafar-logs',
            [Dashboard\SearchLogs::class, 'render_page']
        );
        
        add_submenu_page(
            'nextsafar-search',
            'مدیریت حافظه موقت',
            'حافظه موقت',
            'manage_options',
            'nextsafar-cache',
            [Dashboard\CacheManager::class, 'render_page']
        );
        
        add_submenu_page(
            'nextsafar-search',
            'آمار عملکرد جستجو',
            'آمار عملکرد',
            'manage_options',
            'nextsafar-metrics',
            [Dashboard\PerformanceMetrics::class, 'render_page']
        );
        
        // ═══════════════════════════════════════════════════════════
        // 4. AI Trip Planner
        // ═══════════════════════════════════════════════════════════
        add_menu_page(
            'برنامه سفر هوشمند',
            'برنامه سفر هوشمند',
            'manage_options',
            'nextsafar-ai-trip',
            [AiTripSettings::class, 'render_page'],
            'dashicons-location-alt',
            28
        );
        
        add_submenu_page(
            'nextsafar-ai-trip',
            'تنظیمات برنامه‌ریز سفر هوشمند',
            'تنظیمات',
            'manage_options',
            'nextsafar-ai-trip',
            [AiTripSettings::class, 'render_page']
        );
        
        // ═══════════════════════════════════════════════════════════
        // 5. NextSafar Settings (no dashboard)
        // ═══════════════════════════════════════════════════════════
        add_menu_page(
            'تنظیمات سفر بعدی',
            'تنظیمات سفر بعدی',
            'manage_options',
            'nextsafar-exchange',
            [Exchange::class, 'render_page'],
            'dashicons-admin-generic',
            58
        );
        
        add_submenu_page(
            'nextsafar-settings-main',
            'نرخ ارز',
            'نرخ ارز',
            'manage_options',
            'nextsafar-exchange',
            [Exchange::class, 'render_page']
        );
        
        add_submenu_page(
            'nextsafar-settings-main',
            'درگاه‌های پرداخت',
            'درگاه پرداخت',
            'manage_options',
            'nextsafar-payment',
            [__CLASS__, 'render_payment_page']
        );
        
        add_submenu_page(
            'nextsafar-settings-main',
            'سرویس پیامک',
            'سرویس پیامکی',
            'manage_options',
            'nextsafar-sms',
            [__CLASS__, 'render_sms_page']
        );
        
        // ═══════════════════════════════════════════════════════════
        // 6. Visa Bookings (independent menu)
        // ═══════════════════════════════════════════════════════════
        add_menu_page(
            'رزرو ویزا',
            'رزرو ویزا',
            'manage_options',
            'nextsafar-bookings',
            [Booking\AdminBookingList::class, 'render_page'],
            'dashicons-tickets-alt',
            60
        );
        
        add_submenu_page(
            'nextsafar-bookings',
            'لیست رزروها',
            'لیست رزروها',
            'manage_options',
            'nextsafar-bookings',
            [Booking\AdminBookingList::class, 'render_page']
        );
        
        add_submenu_page(
            'nextsafar-bookings',
            'آمار رزروها',
            'آمار رزروها',
            'manage_options',
            'nextsafar-booking-stats',
            [Booking\AdminBookingStats::class, 'render_page']
        );
        
        add_submenu_page(
            null,
            'جزئیات رزرو',
            'جزئیات رزرو',
            'manage_options',
            'nextsafar-booking-detail',
            [Booking\AdminBookingDetail::class, 'render_page']
        );
    }
    
    /**
     * Remove old and duplicate menus
     */
    public static function remove_old_menus(): void {
        global $menu, $submenu;
        
        $old_slugs = [
            'nextsafar-dashboard',
            'nextsafar-settings-old',
        ];
        
        foreach ($menu as $key => $item) {
            if (isset($item[2]) && in_array($item[2], $old_slugs)) {
                unset($menu[$key]);
            }
        }
        
        foreach (['nextsafar-news', 'nextsafar-search', 'nextsafar-post-generator', 'nextsafar-settings-main', 'nextsafar-bookings'] as $parent) {
            if (isset($submenu[$parent])) {
                $seen_slugs = [];
                foreach ($submenu[$parent] as $key => $item) {
                    $slug = $item[2] ?? '';
                    if (in_array($slug, $seen_slugs)) {
                        unset($submenu[$parent][$key]);
                    } else {
                        $seen_slugs[] = $slug;
                    }
                }
            }
        }
    }
    
    /**
     * Payment gateways page
     */
    public static function render_payment_page(): void {
        if (!current_user_can('manage_options')) return;
        
        $gateway_settings = \NextSafar\Payment\Gateways\ZarinpalGateway::get_settings();
        
        $message = '';
        if (isset($_POST['save_payment']) && wp_verify_nonce($_POST['_wpnonce'], 'ns_payment_save')) {
            \NextSafar\Payment\Gateways\ZarinpalGateway::update_settings(
                sanitize_text_field($_POST['merchant_id'] ?? ''),
                !empty($_POST['is_sandbox'])
            );
            $message = 'تنظیمات درگاه پرداخت ذخیره شد';
            $gateway_settings = \NextSafar\Payment\Gateways\ZarinpalGateway::get_settings();
        }
        
        if (isset($_POST['test_gateway']) && wp_verify_nonce($_POST['_wpnonce'], 'ns_payment_save')) {
            $gateway = new \NextSafar\Payment\Gateways\ZarinpalGateway();
            $test = $gateway->test_connection();
            $message = $test['message'];
        }
        ?>
        <div class="wrap">
            <h1>درگاه‌های پرداخت</h1>
            
            <?php if ($message): ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($message); ?></p></div>
            <?php endif; ?>
            
            <div style="background:#fff;padding:24px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:20px;position:relative;">
                <h2 style="margin-top:0;">زرین‌پال</h2>
                <div style="position:absolute;top:24px;left:24px;padding:6px 12px;border-radius:12px;font-size:12px;font-weight:600;<?php echo !empty($gateway_settings['merchant_id']) ? 'background:#46b450;color:#fff;' : 'background:#dc3232;color:#fff;'; ?>">
                    <?php echo !empty($gateway_settings['merchant_id']) ? 'فعال' : 'غیرفعال'; ?>
                </div>
                
                <form method="post">
                    <?php wp_nonce_field('ns_payment_save'); ?>
                    <table class="form-table">
                        <tr>
                            <th>شناسه پذیرنده:</th>
                            <td>
                                <input type="text" name="merchant_id" value="<?php echo esc_attr($gateway_settings['merchant_id']); ?>" class="regular-text" dir="ltr" style="font-family: monospace;" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                                <p class="description">از پنل زرین‌پال دریافت کنید</p>
                            </td>
                        </tr>
                        <tr>
                            <th>محیط آزمایشی:</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="is_sandbox" value="1" <?php checked($gateway_settings['is_sandbox']); ?>>
                                    استفاده از محیط آزمایشی زرین‌پال
                                </label>
                                <p class="description">در محیط آزمایشی تراکنش واقعی انجام نمی‌شود</p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" name="save_payment" class="button button-primary">ذخیره تنظیمات</button>
                        <button type="submit" name="test_gateway" class="button">تست اتصال</button>
                    </p>
                </form>
            </div>
            
            <div style="background:#fff;padding:24px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);opacity:0.6;margin-bottom:20px;">
                <h2 style="margin-top:0;">بانک ملت - به‌زودی</h2>
                <p style="color:#999;font-style:italic;">به‌زودی اضافه خواهد شد</p>
            </div>
            
            <div style="background:#fff;padding:24px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);opacity:0.6;">
                <h2 style="margin-top:0;">بانک سامان - به‌زودی</h2>
                <p style="color:#999;font-style:italic;">به‌زودی اضافه خواهد شد</p>
            </div>
        </div>
        <?php
    }
    
    /**
     * SMS service page
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
            $message = 'تنظیمات پیامک ذخیره شد';
            $sms_settings = [
                'enabled'  => \NextSafar\Booking\BookingSms::is_enabled(),
                'username' => \NextSafar\Booking\BookingSms::get_username(),
                'password' => \NextSafar\Booking\BookingSms::get_password(),
                'from'     => \NextSafar\Booking\BookingSms::get_from(),
            ];
        }
        
        if (isset($_POST['test_sms']) && wp_verify_nonce($_POST['_wpnonce'], 'ns_sms_save')) {
            $test_phone = sanitize_text_field($_POST['test_phone'] ?? '');
            if (empty($test_phone)) {
                $message = 'شماره تلفن برای تست الزامی است';
            } else {
                $sent = \NextSafar\Booking\BookingSms::send(
                    $test_phone,
                    'تست پیامک نکست سفر - ' . date('Y-m-d H:i:s')
                );
                $message = $sent ? 'پیامک تست ارسال شد' : 'خطا در ارسال پیامک';
            }
        }
        ?>
        <div class="wrap">
            <h1>سرویس پیامکی</h1>
            
            <?php if ($message): ?>
                <div class="notice notice-<?php echo strpos($message, 'ارسال شد') !== false ? 'success' : 'error'; ?> is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>
            
            <div style="background:#fff;padding:24px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:20px;position:relative;">
                <h2 style="margin-top:0;">پنل پیامک</h2>
                <div style="position:absolute;top:24px;left:24px;padding:6px 12px;border-radius:12px;font-size:12px;font-weight:600;<?php echo $sms_settings['enabled'] ? 'background:#46b450;color:#fff;' : 'background:#dc3232;color:#fff;'; ?>">
                    <?php echo $sms_settings['enabled'] ? 'فعال' : 'غیرفعال'; ?>
                </div>
                
                <form method="post">
                    <?php wp_nonce_field('ns_sms_save'); ?>
                    <table class="form-table">
                        <tr>
                            <th>فعال‌سازی:</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enabled" value="1" <?php checked($sms_settings['enabled']); ?>>
                                    ارسال پیامک‌های سیستمی فعال باشد
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>نام کاربری:</th>
                            <td>
                                <input type="text" name="username" value="<?php echo esc_attr($sms_settings['username']); ?>" class="regular-text" dir="ltr">
                            </td>
                        </tr>
                        <tr>
                            <th>رمز عبور:</th>
                            <td>
                                <input type="password" name="password" value="<?php echo esc_attr($sms_settings['password']); ?>" class="regular-text" dir="ltr">
                            </td>
                        </tr>
                        <tr>
                            <th>شماره فرستنده:</th>
                            <td>
                                <input type="text" name="from" value="<?php echo esc_attr($sms_settings['from']); ?>" class="regular-text" dir="ltr">
                                <p class="description">شماره خط اختصاصی شما</p>
                            </td>
                        </tr>
                    </table>
                    
                    <h3>تست ارسال پیامک</h3>
                    <table class="form-table">
                        <tr>
                            <th>شماره مقصد:</th>
                            <td>
                                <input type="tel" name="test_phone" class="regular-text" dir="ltr" placeholder="۰۹۱۲۳۴۵۶۷۸۹">
                                <button type="submit" name="test_sms" class="button">ارسال تست</button>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" name="save_sms" class="button button-primary">ذخیره تنظیمات</button>
                    </p>
                </form>
            </div>
            
            <div style="background:#fff;padding:24px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                <h2 style="margin-top:0;">پیامک‌های ارسالی</h2>
                <table class="form-table">
                    <tr>
                        <td>
                            <strong>تأیید رزرو:</strong> پس از ایجاد رزرو ویزا<br>
                            <strong>تأیید پرداخت:</strong> پس از پرداخت موفق<br>
                            <strong>لغو رزرو:</strong> در صورت لغو رزرو
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }
}

Menu::init();