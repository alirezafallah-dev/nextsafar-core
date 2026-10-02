<?php

/**
 * Exchange Rate Settings - NextSafar
 * Version 3.1 - With full logging for debugging.
 */

namespace NextSafar\Admin;

if (!defined('ABSPATH')) exit;

class Exchange {
    const API_KEY_OPTION = 'nextsafar_brs_api_key';
    const LAST_UPDATE_OPTION = 'nextsafar_exchange_last_update';
    const CACHE_KEY = 'nextsafar_exchange_rates_cache';
    const CACHE_DURATION = HOUR_IN_SECONDS;
    const LEGACY_API_KEY = 'visa_brs_api_key';

    /**
     * Persian currency name to code mapping
     * 
     * ✅ NEW: Supports Persian currency names from visa metabox
     */
    const PERSIAN_TO_CODE = [
        'دلار'        => 'USD',
        'دلار آمریکا' => 'USD',
        'یورو'        => 'EUR',
        'پوند'        => 'GBP',
        'پوند انگلیس' => 'GBP',
        'درهم'        => 'AED',
        'درهم امارات' => 'AED',
        'ریال عمان'   => 'OMR',
        'دینار عراق'  => 'IQD',
        'روبل'        => 'RUB',
        'یوان'        => 'CNY',
        'یوان چین'    => 'CNY',
        'لیر'         => 'TRY',
        'لیر ترکیه'   => 'TRY',
        'بات'         => 'THB',
        'بات تایلند'  => 'THB',
        'رینگیت'      => 'MYR',
        'ریال قطر'    => 'QAR',
        'ریال'        => 'IRR',
    ];

    /**
     * Normalize currency input to uppercase code
     * 
     * ✅ NEW METHOD
     * Accepts both codes (USD, aed) and Persian names (دلار, درهم)
     * 
     * @param string $currency Currency code or Persian name
     * @return string Uppercase currency code
     */
    public static function normalize_code(string $currency): string {
        $currency = trim($currency);
        
        // Already a valid code? (3 letters)
        if (preg_match('/^[A-Za-z]{3}$/', $currency)) {
            return strtoupper($currency);
        }
        
        // Try Persian name matching
        foreach (self::PERSIAN_TO_CODE as $persian => $code) {
            if (mb_strpos($currency, $persian) !== false) {
                return $code;
            }
        }
        
        // Return as-is (uppercased)
        return strtoupper($currency);
    }

    /**
     * Get rate for visa system
     * 
     * ✅ NEW METHOD: Bridge between VisaPriceCalculator and Exchange
     * Supports Persian currency names used in visa metabox
     * 
     * @param string $currency Currency code or Persian name (e.g., 'درهم', 'AED')
     * @return float Rate to Rial (0 if not found)
     */
    public static function get_rate_for_visa(string $currency): float {
        $code = self::normalize_code($currency);
        
        // IRR is always 1:1
        if ($code === 'IRR') {
            return 1.0;
        }
        
        $rate = self::get_rate($code);
        
        if ($rate <= 0) {
            error_log('[NEXTSAFAR_EXCHANGE] ⚠️ Rate not found for: ' . $currency . ' (code: ' . $code . ')');
        }
        
        return $rate;
    }

    public static function get_exchange_rate(string $currency): float {
        return \NextSafar\Admin\Exchange::get_rate_for_visa($currency);
    }

    public static function convert_to_rial(float $amount, string $currency_code): float {
        return $amount * self::get_rate($currency_code);
    }

    public static function format_price(float $amount, string $currency = 'IRR'): string {
        $symbol = self::get_currency_symbol($currency);

        return number_format($amount, 0, '.', ',') . ' ' . $symbol;
    }

    public static function get_currency_symbol(string $code): string {
        $symbols = [
            'IRR' => 'ریال',
            'TOMAN' => 'تومان',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'AED' => 'د.إ',
            'TRY' => '₺',
        ];

        return $symbols[strtoupper($code)] ?? $code;
    }

    /**
     * Helper log.
     */
    private static function log(string $message): void {
        error_log('[NEXTSAFAR_EXCHANGE] ' . $message);
    }

    public static function render_page(): void {
        $supported = self::get_supported_currencies();
        $message = '';
        $message_type = '';

        // Process form - without depending on a button.
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['visa_brs_api_key'])) {
            self::log('POST received with visa_brs_api_key');

            // Verify nonce.
            $nonce_ok = isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'visa_api_settings_action');

            self::log('Nonce: ' . ($nonce_ok ? 'PASS' : 'FAIL'));

            if (!$nonce_ok) {
                $message = '⚠️ خطای امنیتی (Nonce). صفحه را رفرش کنید.';
                $message_type = 'error';
            } else {
                $api_key = trim($_POST['visa_brs_api_key']);

                self::log('Saving key, length=' . strlen($api_key));

                if (strlen($api_key) > 0) {
                    // Direct save - without button condition.
                    $r1 = update_option(self::API_KEY_OPTION, $api_key);
                    $r2 = update_option(self::LEGACY_API_KEY, $api_key);

                    delete_transient(self::CACHE_KEY);

                    $verify = get_option(self::API_KEY_OPTION, '');

                    self::log("Save: r1={$r1}, r2={$r2}, verify=" . (empty($verify) ? 'EMPTY' : 'OK'));

                    $message = '✅ کلید API ذخیره شد.';
                    $message_type = 'success';
                } else {
                    delete_option(self::API_KEY_OPTION);
                    delete_option(self::LEGACY_API_KEY);
                    delete_transient(self::CACHE_KEY);

                    $message = '🗑️ کلید حذف شد.';
                    $message_type = 'info';
                }
            }
        }

        // Manual update - with button check.
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refresh_visa_rates'])) {
            delete_transient(self::CACHE_KEY);

            $rates = self::get_exchange_rates();
            $count = count(array_filter($rates));

            $message = $count > 0
                ? "✅ نرخ‌ها بروزرسانی شدند ({$count} ارز)."
                : '⚠️ نری دریافت نشد.';

            $message_type = $count > 0 ? 'success' : 'warning';
        }

        // Get API key.
        $api_key = get_option(self::API_KEY_OPTION);

        if (empty($api_key)) {
            $api_key = get_option(self::LEGACY_API_KEY, '');
        }

        self::log("Render: key=" . (empty($api_key) ? 'EMPTY' : substr($api_key, 0, 8) . '...'));

        $has_api_key = !empty($api_key);
        $rates = self::get_exchange_rates();
        $has_rates = !empty(array_filter($rates));
        $last_update = get_option(self::LAST_UPDATE_OPTION, '');

        if (!$has_api_key) {
            $status_icon = '❌';
            $status_text = 'تنظیم نشده';
            $status_color = '#dc3232';
        } elseif (!$has_rates) {
            $status_icon = '⚠️';
            $status_text = 'کلید هست ولی داده نشد';
            $status_color = '#dba617';
        } else {
            $status_icon = '✅';
            $status_text = 'فعال';
            $status_color = '#00a32a';
        }
        ?>
        <div class="wrap nextsafar-wrap">
            <h1>صرافی - تنظیمات نرخ ارز</h1>

            <?php if ($message): ?>
                <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin:20px 0;">
                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);">
                    <div style="font-size:28px;font-weight:bold;color:#2271b1;"><?php echo count($supported); ?></div>
                    <div style="color:#666;">ارز پشتیبانی شده</div>
                </div>

                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);">
                    <div style="font-size:28px;font-weight:bold;color:<?php echo $status_color; ?>;"><?php echo $status_icon; ?></div>
                    <div style="color:#666;">وضعیت: <strong style="color:<?php echo $status_color; ?>"><?php echo $status_text; ?></strong></div>
                </div>

                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);">
                    <div style="font-size:14px;font-weight:bold;word-break:break-all;">
                        <?php echo $has_api_key ? '' . esc_html(substr($api_key, 0, 10)) . '…' : '—'; ?>
                    </div>
                    <div style="color:#666;">کلید ذخیره‌شده</div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                <div>
                    <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);">
                        <h2>تنظیمات API</h2>

                        <form method="post" action="">
                            <?php wp_nonce_field('visa_api_settings_action'); ?>

                            <table class="form-table">
                                <tr>
                                    <th><label for="visa_brs_api_key">کلید API:</label></th>
                                    <td>
                                        <input type="text" id="visa_brs_api_key" name="visa_brs_api_key"
                                               value="<?php echo esc_attr($api_key); ?>"
                                               class="regular-text"
                                               style="width:320px;direction:ltr;font-family:monospace;">
                                    </td>
                                </tr>
                            </table>

                            <p>
                                <input type="submit" class="button button-primary" value="ذخیره کلید">
                                <input type="submit" name="refresh_visa_rates" class="button button-secondary" value="آپدیت">
                            </p>
                        </form>
                    </div>
                </div>

                <div>
                    <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);">
                        <h2>نرخ‌ها</h2>

                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th>ارز</th>
                                    <th>کد</th>
                                    <th>ریال</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($supported as $code => $name): ?>
                                    <tr>
                                        <td><?php echo esc_html($name); ?></td>
                                        <td><code><?php echo esc_html($code); ?></code></td>
                                        <td><?php echo !empty($rates[$code]) ? number_format($rates[$code]) : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public static function get_supported_currencies(): array {
        return [
            'USD' => 'دلار آمریکا',
            'EUR' => 'یورو',
            'GBP' => 'پوند انگلیس',
            'AED' => 'درهم امارات',
            'OMR' => 'ریال عمان',
            'IQD' => 'دینار عراق',
            'RUB' => 'روبل روسیه',
            'CNY' => 'یوان چین',
            'TRY' => 'لیر ترکیه',
            'THB' => 'بات تایلند',
            'MYR' => 'رینگیت مالزی',
            'QAR' => 'ریال قطر',
        ];
    }

    public static function get_exchange_rates(): array {
        $cached = get_transient(self::CACHE_KEY);

        if ($cached !== false && is_array($cached) && !empty($cached)) {
            return $cached;
        }

        $api_key = get_option(self::API_KEY_OPTION);

        if (empty($api_key)) {
            $api_key = get_option(self::LEGACY_API_KEY, '');
        }

        $rates = [];
        $supported = array_keys(self::get_supported_currencies());

        if (!empty($api_key)) {
            $rates = self::fetch_from_api($api_key, $supported);
        }

        if (empty($rates)) {
            foreach ($supported as $code) {
                $manual_rate = get_option('visa_rate_' . strtolower($code), 0);
                $rates[$code] = is_numeric($manual_rate) ? floatval($manual_rate) : 0;
            }
        } else {
            foreach ($supported as $code) {
                if (isset($rates[$code]) && $rates[$code] > 0) {
                    update_option('visa_rate_' . strtolower($code), $rates[$code]);
                }
            }

            update_option(self::LAST_UPDATE_OPTION, current_time('mysql'));
            set_transient(self::CACHE_KEY, $rates, self::CACHE_DURATION);
        }

        return $rates;
    }

    private static function fetch_from_api(string $api_key, array $supported): array {
        $rates = [];

        $args = [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ],
        ];

        $url = 'https://Api.BrsApi.ir/Market/Gold_Currency.php?key=' . urlencode($api_key);

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            $url = 'https://brsapi.ir/FreeTsetmcBourseApi/Api_Free_Gold_Currency_v2.json';
            $response = wp_remote_get($url, $args);
        }

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($data)) return [];

        $items = [];

        if (isset($data['currency'])) $items = array_merge($items, $data['currency']);
        if (isset($data['gold'])) $items = array_merge($items, $data['gold']);

        foreach ($items as $item) {
            if (!is_array($item)) continue;

            $symbol = isset($item['symbol']) ? strtoupper(trim($item['symbol'])) : '';

            if (empty($symbol) && isset($item['code'])) {
                $symbol = strtoupper(trim($item['code']));
            }

            $price = isset($item['price']) ? floatval(str_replace([',', '،'], '', $item['price'])) : 0;
            $unit = isset($item['unit']) ? strtolower(trim($item['unit'])) : '';

            if ($price > 0 && in_array($symbol, $supported, true)) {
                if ($unit === 'تومان' || $unit === 'toman') {
                    $price *= 10;
                }

                $rates[$symbol] = $price;
            }
        }

        return $rates;
    }

    private static function format_persian_date(string $date): string {
        $timestamp = strtotime($date);

        return $timestamp ? date_i18n('Y/m/d H:i', $timestamp) : $date;
    }
}