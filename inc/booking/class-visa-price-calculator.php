<?php
/**
 * NextSafar Core - Visa Price Calculator
 * 
 * Calculates visa prices with exchange rate conversion.
 * Handles adult/child pricing and multi-currency support.
 * 
 * @package NextSafar\Booking
 * @since   2.7.0
 */

namespace NextSafar\Booking;

use NextSafar\Core\Logger;
use NextSafar\Booking\CurrencyManager;

if (!defined('ABSPATH')) exit;

class VisaPriceCalculator {
    
    /**
     * Currency mapping (Persian → code)
     */
    const CURRENCY_MAP = [
        'درهم'     => 'aed',
        'دلار'     => 'usd',
        'یورو'     => 'eur',
        'ریال'     => 'irr',
        'ریال عمان' => 'omn',
        'AED'      => 'aed',
        'USD'      => 'usd',
        'EUR'      => 'eur',
        'IRR'      => 'irr',
        'OMR'      => 'omn',
    ];
    
    /**
     * Child price ratio (50% of adult price)
     */
    const CHILD_PRICE_RATIO = 0.5;
    
    /**
     * Get visa prices from post meta
     * 
     * @param int $visa_post_id
     * @return array Array of price items
     */
    public static function get_visa_prices(int $visa_post_id): array {
        $prices = get_post_meta($visa_post_id, '_visa_prices', true);
        
        if (!is_array($prices)) {
            return [];
        }
        
        return $prices;
    }
    
    /**
     * Get specific visa price by index
     * 
     * @param int $visa_post_id
     * @param int $price_index Index in the prices array
     * @return array|null Price item or null
     */
    public static function get_price_by_index(int $visa_post_id, int $price_index): ?array {
        $prices = self::get_visa_prices($visa_post_id);
        
        return $prices[$price_index] ?? null;
    }
    
    /**
     * Find visa price by type, duration, and person
     * 
     * @param int $visa_post_id
     * @param string $type Visa type (e.g., 'توریستی')
     * @param string $duration Duration (e.g., '۱۴ روزه')
     * @param string $person Person type ('بزرگسال' or 'کودک')
     * @return array|null Price item or null
     */
    public static function find_price(
        int $visa_post_id, 
        string $type, 
        string $duration, 
        string $person = 'بزرگسال'
    ): ?array {
        $prices = self::get_visa_prices($visa_post_id);
        
        foreach ($prices as $price) {
            if (
                $price['type'] === $type &&
                $price['duration'] === $duration &&
                $price['person'] === $person
            ) {
                return $price;
            }
        }
        
        // If no specific child price, use adult price
        if ($person === 'کودک') {
            $adult_price = self::find_price($visa_post_id, $type, $duration, 'بزرگسال');
            if ($adult_price) {
                return $adult_price; // Will be adjusted in calculation
            }
        }
        
        return null;
    }
    
    /**
     * Get exchange rate for a currency
     */
    public static function get_exchange_rate(string $currency): float {
        // ✅ Delegate to CurrencyManager
        return CurrencyManager::get_rate($currency);
    }
    
    /**
     * Calculate total price in Rials
     * 
     * @param array $args Calculation arguments:
     *   - visa_post_id: int - Visa post ID
     *   - price_index: int - Index in prices array
     *   - adults: int - Number of adults
     *   - children: int - Number of children
     * @return array|\WP_Error Price breakdown or error
     */
    public static function calculate(array $args): array|\WP_Error {
        $visa_post_id = (int) ($args['visa_post_id'] ?? 0);
        $price_index  = (int) ($args['price_index'] ?? 0);
        $adults       = max(0, (int) ($args['adults'] ?? 0));
        $children     = max(0, (int) ($args['children'] ?? 0));
        
        // Validate inputs
        if ($visa_post_id <= 0) {
            return new \WP_Error('invalid_visa', 'ویزای مورد نظر پیدا نشد.', ['status' => 400]);
        }
        
        if ($adults <= 0) {
            return new \WP_Error('no_adults', 'حداقل یک بزرگسال الزامی است.', ['status' => 400]);
        }
        
        // Get visa price
        $visa_price = self::get_price_by_index($visa_post_id, $price_index);
        
        if (!$visa_price) {
            return new \WP_Error('price_not_found', 'قیمت ویزا پیدا نشد.', ['status' => 400]);
        }
        
        // Parse price values
        $adult_price = self::parse_price($visa_price['price'] ?? 0);
        $currency    = $visa_price['currency'] ?? 'درهم';
        $type        = $visa_price['type'] ?? '';
        $duration    = $visa_price['duration'] ?? '';
        
        // ✅ Get exchange rate via centralized Exchange system
        $rate = self::get_exchange_rate($currency);

        // ✅ FIX: Better error message with admin guidance
        if ($rate <= 0 && strtoupper($currency) !== 'IRR' && $currency !== 'ریال') {
            $currency_code = \NextSafar\Admin\Exchange::normalize_code($currency);
            
            Logger::warning('Exchange rate not set', [
                'currency'      => $currency,
                'currency_code' => $currency_code,
                'visa_id'       => $visa_post_id,
            ]);
            
            return new \WP_Error(
                'no_exchange_rate',
                sprintf(
                    'نرخ ارز %s (%s) تنظیم نشده است. لطفاً از بخش «صرافی» در پنل ادمین نرخ را به‌روزرسانی کنید یا با پشتیبانی تماس بگیرید.',
                    $currency,
                    $currency_code
                ),
                ['status' => 503]
            );
        }
        
        // Calculate prices
        $child_price = $adult_price * self::CHILD_PRICE_RATIO;
        
        $total_adult_price = $adult_price * $adults;
        $total_child_price = $child_price * $children;
        $total_price       = $total_adult_price + $total_child_price;
        
        // Convert to Rials
        $total_in_rial = ($rate > 0) ? (int) round($total_price * $rate) : (int) $total_price;
        
        // Calculate per-person prices in Rials
        $adult_price_rial = ($rate > 0) ? (int) round($adult_price * $rate) : (int) $adult_price;
        $child_price_rial = ($rate > 0) ? (int) round($child_price * $rate) : (int) $child_price;
        
        $result = [
            'visa_post_id'      => $visa_post_id,
            'price_index'       => $price_index,
            'type'              => $type,
            'duration'          => $duration,
            'currency'          => $currency,
            'currency_code'     => self::CURRENCY_MAP[$currency] ?? strtolower($currency),
            'exchange_rate'     => $rate,
            
            'adults'            => $adults,
            'children'          => $children,
            'total_passengers'  => $adults + $children,
            
            'adult_price'       => $adult_price,
            'child_price'       => $child_price,
            'total_adult_price' => $total_adult_price,
            'total_child_price' => $total_child_price,
            'total_price'       => $total_price,
            
            'adult_price_rial'  => $adult_price_rial,
            'child_price_rial'  => $child_price_rial,
            'total_price_rial'  => $total_in_rial,
            
            'formatted_total'   => number_format($total_in_rial) . ' ریال',
        ];
        
        Logger::debug('Visa price calculated', [
            'visa_id'   => $visa_post_id,
            'total'     => $total_in_rial,
            'adults'    => $adults,
            'children'  => $children,
        ]);
        
        return $result;
    }
    
    /**
     * Parse price value (handles strings like "1,200" or "1200")
     * 
     * @param mixed $price
     * @return float
     */
    private static function parse_price($price): float {
        if (is_numeric($price)) {
            return (float) $price;
        }
        
        // Remove commas and spaces
        $cleaned = str_replace([',', ' ', '،'], '', (string) $price);
        
        return is_numeric($cleaned) ? (float) $cleaned : 0.0;
    }
    
    /**
     * Format price for display
     * 
     * @param float $amount
     * @param string $currency
     * @return string
     */
    public static function format_price(float $amount, string $currency = 'ریال'): string {
        return number_format($amount) . ' ' . $currency;
    }
    
    /**
     * Get all available currencies with rates
     * 
     * @return array
     */
    public static function get_all_rates(): array {
        $currencies = ['aed', 'usd', 'eur', 'irr', 'omn'];
        $rates = [];
        
        foreach ($currencies as $code) {
            $rates[$code] = [
                'code'   => $code,
                'label'  => self::get_currency_label($code),
                'rate'   => self::get_exchange_rate($code),
                'active' => self::get_exchange_rate($code) > 0,
            ];
        }
        
        return $rates;
    }
    
    /**
     * Get Persian label for currency code
     * 
     * @param string $code
     * @return string
     */
    public static function get_currency_label(string $code): string {
        $labels = [
            'aed' => 'درهم امارات',
            'usd' => 'دلار آمریکا',
            'eur' => 'یورو',
            'irr' => 'ریال ایران',
            'omn' => 'ریال عمان',
        ];
        
        return $labels[strtolower($code)] ?? strtoupper($code);
    }
}