<?php
/**
 * NextSafar Core - Currency Manager
 * 
 * مدیریت مرکزی نرخ ارز با قابلیت:
 * - ذخیره در جدول اختصاصی
 * - تاریخ انقضا
 * - کش برای عملکرد سریع
 * - دریافت خودکار از منابع
 * - لاگ تغییرات
 * 
 * @package NextSafar\Booking
 * @since   2.7.1
 */

namespace NextSafar\Booking;

use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class CurrencyManager {
    
    /**
     * Table version
     */
    const TABLE_VERSION = '1.0.0';
    const VERSION_OPTION = 'ns_currency_table_version';
    
    /**
     * Cache duration (1 hour)
     */
    const CACHE_DURATION = HOUR_IN_SECONDS;
    
    /**
     * Rate validity (24 hours)
     */
    const RATE_VALIDITY = DAY_IN_SECONDS;
    
    /**
     * Default rates (fallback when API fails)
     */
    const DEFAULT_RATES = [
        'usd' => 1050000, // دلار آمریکا → ریال
        'aed' => 285000,  // درهم امارات → ریال
        'eur' => 1150000, // یورو → ریال
        'try' => 30000,   // لیر ترکیه → ریال
        'omr' => 2730000, // ریال عمان → ریال
        'gbp' => 1330000, // پوند انگلیس → ریال
        'cny' => 145000,  // یوان چین → ریال
        'inr' => 12600,   // روپیه هند → ریال
        'thb' => 29000,   // بات تایلند → ریال
        'myr' => 222000,  // رینگیت مالزی → ریال
    ];
    
    /**
     * Persian name to code mapping
     */
    const PERSIAN_TO_CODE = [
        'دلار'       => 'usd',
        'دلار آمریکا' => 'usd',
        'درهم'       => 'aed',
        'درهم امارات' => 'aed',
        'یورو'       => 'eur',
        'لیر'        => 'try',
        'لیر ترکیه'  => 'try',
        'ریال عمان'  => 'omr',
        'پوند'       => 'gbp',
        'یوان'       => 'cny',
        'روپیه'      => 'inr',
        'بات'        => 'thb',
        'رینگیت'     => 'myr',
        'ریال'       => 'irr',
    ];
    
    /**
     * Initialize
     */
    public static function init(): void {
        add_action('init', [__CLASS__, 'maybe_upgrade']);
        
        // Hourly check for expired rates
        add_action('nextsafar_currency_hourly', [__CLASS__, 'check_expired_rates']);
        
        if (!wp_next_scheduled('nextsafar_currency_hourly')) {
            wp_schedule_event(time(), 'hourly', 'nextsafar_currency_hourly');
        }
    }
    
    /* ================================================================
       TABLE MANAGEMENT
       ================================================================ */
    
    /**
     * Get table name
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'ns_exchange_rates';
    }
    
    /**
     * Create or upgrade table
     */
    public static function create_table(): void {
        global $wpdb;
        
        $table = self::get_table_name();
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            currency_code VARCHAR(10) NOT NULL,
            currency_name_fa VARCHAR(100) NOT NULL DEFAULT '',
            rate_to_rial DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            source ENUM('manual', 'auto', 'default') NOT NULL DEFAULT 'manual',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            valid_until DATETIME NULL,
            notes TEXT NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_currency (currency_code),
            KEY idx_active (is_active),
            KEY idx_valid_until (valid_until)
        ) {$charset};";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        update_option(self::VERSION_OPTION, self::TABLE_VERSION);
        
        // Seed default rates if empty
        self::seed_default_rates();
        
        Logger::info('Currency table created/upgraded', [
            'table' => $table,
        ]);
    }
    
    /**
     * Maybe upgrade table
     */
    public static function maybe_upgrade(): void {
        $current = get_option(self::VERSION_OPTION, '');
        
        if ($current !== self::TABLE_VERSION) {
            self::create_table();
        }
    }
    
    /**
     * Seed default rates
     */
    private static function seed_default_rates(): void {
        global $wpdb;
        
        $table = self::get_table_name();
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        
        if ($count > 0) return;
        
        $names = [
            'usd' => 'دلار آمریکا',
            'aed' => 'درهم امارات',
            'eur' => 'یورو',
            'try' => 'لیر ترکیه',
            'omr' => 'ریال عمان',
            'gbp' => 'پوند انگلیس',
            'cny' => 'یوان چین',
            'inr' => 'روپیه هند',
            'thb' => 'بات تایلند',
            'myr' => 'رینگیت مالزی',
        ];
        
        foreach (self::DEFAULT_RATES as $code => $rate) {
            $wpdb->insert($table, [
                'currency_code'    => $code,
                'currency_name_fa' => $names[$code] ?? strtoupper($code),
                'rate_to_rial'     => $rate,
                'source'           => 'default',
                'is_active'        => 1,
                'valid_until'      => null,
                'notes'            => 'نرخ پیش‌فرض اولیه',
            ]);
        }
        
        Logger::info('Default currency rates seeded', [
            'count' => count(self::DEFAULT_RATES),
        ]);
    }
    
    /* ================================================================
       RATE RETRIEVAL
       ================================================================ */
    
    /**
     * Get exchange rate for a currency
     * 
     * Priority:
     * 1. Cache (1 hour)
     * 2. Database (if not expired)
     * 3. Default rate (fallback)
     * 
     * @param string $currency Currency code or Persian name
     * @return float Rate to Rial
     */
    public static function get_rate(string $currency): float {
        $code = self::normalize_currency_code($currency);
        
        // IRR/Rial is always 1:1
        if ($code === 'irr' || $code === 'rial') {
            return 1.0;
        }
        
        // Check cache first
        $cache_key = 'ns_currency_rate_' . $code;
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return (float) $cached;
        }
        
        // Get from database
        $rate = self::get_rate_from_db($code);
        
        if ($rate > 0) {
            set_transient($cache_key, $rate, self::CACHE_DURATION);
            return $rate;
        }
        
        // Fallback to default rate
        $default = self::DEFAULT_RATES[$code] ?? 0;
        
        if ($default > 0) {
            Logger::warning('Using default currency rate', [
                'currency' => $code,
                'rate'     => $default,
            ]);
            
            set_transient($cache_key, $default, self::CACHE_DURATION);
            return (float) $default;
        }
        
        Logger::error('No exchange rate available', [
            'currency' => $code,
        ]);
        
        return 0.0;
    }
    
    /**
     * Get rate from database
     * 
     * @param string $code Currency code
     * @return float Rate or 0 if not found/expired
     */
    private static function get_rate_from_db(string $code): float {
        global $wpdb;
        
        $table = self::get_table_name();
        
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT rate_to_rial, valid_until, is_active 
             FROM {$table} 
             WHERE currency_code = %s AND is_active = 1",
            $code
        ), ARRAY_A);
        
        if (!$row) return 0.0;
        
        // Check if rate is expired
        if (!empty($row['valid_until'])) {
            $valid_until = strtotime($row['valid_until']);
            
            if ($valid_until < time()) {
                Logger::info('Currency rate expired', [
                    'currency'    => $code,
                    'valid_until' => $row['valid_until'],
                ]);
                
                // Still return it but mark as expired
                // Admin should update it
            }
        }
        
        return (float) $row['rate_to_rial'];
    }
    
    /**
     * Normalize currency code
     * Accepts both codes (usd, aed) and Persian names (دلار, درهم)
     * 
     * @param string $currency
     * @return string Lowercase currency code
     */
    public static function normalize_currency_code(string $currency): string {
        $currency = trim(mb_strtolower($currency));
        
        // Already a code?
        if (preg_match('/^[a-z]{3}$/', $currency)) {
            return $currency;
        }
        
        // Persian name?
        foreach (self::PERSIAN_TO_CODE as $persian => $code) {
            if (mb_strpos($currency, mb_strtolower($persian)) !== false) {
                return $code;
            }
        }
        
        return $currency;
    }
    
    /* ================================================================
       RATE MANAGEMENT
       ================================================================ */
    
    /**
     * Set exchange rate
     * 
     * @param string $code Currency code
     * @param float $rate Rate to Rial
     * @param string $source Source: manual, auto, default
     * @param int|null $validity_seconds Validity period (null = no expiry)
     * @return bool
     */
    public static function set_rate(
        string $code, 
        float $rate, 
        string $source = 'manual',
        ?int $validity_seconds = null
    ): bool {
        global $wpdb;
        
        $code = self::normalize_currency_code($code);
        
        if ($rate <= 0) {
            return false;
        }
        
        $table = self::get_table_name();
        
        // Check if exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE currency_code = %s",
            $code
        ));
        
        $data = [
            'rate_to_rial' => $rate,
            'source'       => $source,
            'is_active'    => 1,
            'valid_until'  => $validity_seconds 
                ? gmdate('Y-m-d H:i:s', time() + $validity_seconds)
                : null,
            'updated_by'   => get_current_user_id() ?: null,
        ];
        
        if ($existing) {
            $result = $wpdb->update($table, $data, ['currency_code' => $code]);
        } else {
            $data['currency_code'] = $code;
            $data['currency_name_fa'] = self::get_currency_name($code);
            $result = $wpdb->insert($table, $data);
        }
        
        if ($result !== false) {
            // Clear cache
            delete_transient('ns_currency_rate_' . $code);
            
            Logger::info('Currency rate updated', [
                'currency' => $code,
                'rate'     => $rate,
                'source'   => $source,
            ]);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Get all rates
     * 
     * @return array
     */
    public static function get_all_rates(): array {
        global $wpdb;
        
        $table = self::get_table_name();
        
        $rates = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY currency_code ASC",
            ARRAY_A
        );
        
        return $rates ?: [];
    }
    
    /**
     * Get currency display name
     */
    public static function get_currency_name(string $code): string {
        $names = [
            'usd' => 'دلار آمریکا',
            'aed' => 'درهم امارات',
            'eur' => 'یورو',
            'try' => 'لیر ترکیه',
            'omr' => 'ریال عمان',
            'gbp' => 'پوند انگلیس',
            'cny' => 'یوان چین',
            'inr' => 'روپیه هند',
            'thb' => 'بات تایلند',
            'myr' => 'رینگیت مالزی',
            'irr' => 'ریال ایران',
        ];
        
        return $names[strtolower($code)] ?? strtoupper($code);
    }
    
    /* ================================================================
       AUTO UPDATE (Optional - can be extended)
       ================================================================ */
    
    /**
     * Check expired rates (hourly cron)
     */
    public static function check_expired_rates(): void {
        global $wpdb;
        
        $table = self::get_table_name();
        
        $expired = $wpdb->get_results($wpdb->prepare(
            "SELECT currency_code, valid_until 
             FROM {$table} 
             WHERE valid_until IS NOT NULL 
             AND valid_until < %s 
             AND is_active = 1",
            current_time('mysql')
        ));
        
        if (empty($expired)) return;
        
        foreach ($expired as $row) {
            Logger::warning('Currency rate expired - needs update', [
                'currency'    => $row->currency_code,
                'valid_until' => $row->valid_until,
            ]);
            
            /**
             * Action hook for external rate providers
             * Other plugins can hook here to auto-update rates
             */
            do_action('nextsafar_currency_rate_expired', $row->currency_code);
        }
    }
    
    /**
     * Try to fetch rate from external API
     * 
     * @param string $code Currency code
     * @return float|false New rate or false on failure
     */
    public static function fetch_rate_from_api(string $code) {
        /**
         * Filter hook for external rate providers
         * Return a float to use as the new rate
         */
        $api_rate = apply_filters('nextsafar_fetch_currency_rate', false, $code);
        
        if ($api_rate !== false && $api_rate > 0) {
            self::set_rate($code, (float) $api_rate, 'auto', self::RATE_VALIDITY);
            return (float) $api_rate;
        }
        
        return false;
    }
}