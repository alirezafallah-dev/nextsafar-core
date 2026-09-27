<?php
/**
 * NextSafar Core - Price Converter Service
 * 
 * Handles currency conversion with multiple fallback strategies.
 * 
 * @package NextSafar\Search
 * @since   2.6.0
 */

namespace NextSafar\Search;

use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class PriceConverter {
    
    /**
     * Option key for manual USD rate
     */
    const OPTION_RATE = 'ns_live_usd_rate';
    
    /**
     * Default rate if all else fails (approximate)
     */
    const DEFAULT_RATE = 50000;
    
    /**
     * Cache key for rate
     */
    const CACHE_KEY = 'ns_usd_rate_cached';
    
    /**
     * Cache TTL (1 hour)
     */
    const CACHE_TTL = HOUR_IN_SECONDS;
    
    /**
     * Get current USD to Toman rate
     * 
     * Priority:
     * 1. Manual rate (if set)
     * 2. Exchange plugin rate (if available)
     * 3. Cached rate
     * 4. Default rate
     * 
     * @return float
     */
    public static function get_usd_rate(): float {
        // 1. Check manual override
        $manual_rate = (float) get_option(self::OPTION_RATE, 0);
        if ($manual_rate > 0) {
            return $manual_rate;
        }
        
        // 2. Check cache
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false && $cached > 0) {
            return (float) $cached;
        }
        
        // 3. Try to get from exchange plugin
        $rate = self::fetch_from_exchange_plugin();
        if ($rate > 0) {
            set_transient(self::CACHE_KEY, $rate, self::CACHE_TTL);
            return $rate;
        }
        
        // 4. Fallback to default
        Logger::warning('USD rate fallback to default', [
            'default_rate' => self::DEFAULT_RATE,
        ]);
        
        return self::DEFAULT_RATE;
    }
    
    /**
     * Convert USD to Toman
     * 
     * @param float $usd Amount in USD
     * @return int Amount in Toman (rounded)
     */
    public static function usd_to_toman(float $usd): int {
        if ($usd <= 0) return 0;
        
        $rate = self::get_usd_rate();
        return (int) round($usd * $rate);
    }
    
    /**
     * Convert Toman to USD
     * 
     * @param int $toman Amount in Toman
     * @return float Amount in USD
     */
    public static function toman_to_usd(int $toman): float {
        if ($toman <= 0) return 0.0;
        
        $rate = self::get_usd_rate();
        return round($toman / $rate, 2);
    }
    
    /**
     * Format price for display (Persian)
     * 
     * @param int $toman Amount in Toman
     * @param bool $with_currency Whether to append "تومان"
     * @return string Formatted price
     */
    public static function format_toman(int $toman, bool $with_currency = true): string {
        $formatted = number_format($toman);
        
        if ($with_currency) {
            return $formatted . ' تومان';
        }
        
        return $formatted;
    }
    
    /**
     * Extract numeric value from price string
     * 
     * Examples:
     * - "$1,234" → 1234.0
     * - "1,234 USD" → 1234.0
     * - "1234.56" → 1234.56
     * 
     * @param string $price_str Price string
     * @return float Extracted numeric value
     */
    public static function extract_numeric(string $price_str): float {
        if (is_numeric($price_str)) {
            return (float) $price_str;
        }
        
        // Remove currency symbols and whitespace
        $cleaned = preg_replace('/[^\d.,]/', '', $price_str);
        
        // Handle different decimal separators
        if (strpos($cleaned, ',') !== false && strpos($cleaned, '.') !== false) {
            // Has both comma and dot - assume comma is thousand separator
            $cleaned = str_replace(',', '', $cleaned);
        } elseif (strpos($cleaned, ',') !== false) {
            // Only comma - could be decimal or thousand separator
            if (substr_count($cleaned, ',') > 1) {
                // Multiple commas = thousand separators
                $cleaned = str_replace(',', '', $cleaned);
            } else {
                // Single comma = decimal separator (European style)
                $cleaned = str_replace(',', '.', $cleaned);
            }
        }
        
        return is_numeric($cleaned) ? (float) $cleaned : 0.0;
    }
    
    /**
     * Try to fetch rate from exchange plugin (if installed)
     * 
     * @return float Rate or 0 if not available
     */
    private static function fetch_from_exchange_plugin(): float {
        // Check if our exchange module exists
        if (class_exists('\NextSafar\Admin\Exchange')) {
            try {
                $rate = \NextSafar\Admin\Exchange::get_rate('USD');
                if ($rate > 0) {
                    return $rate;
                }
            } catch (\Throwable $e) {
                Logger::warning('Exchange plugin error', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        return 0.0;
    }
    
    /**
     * Force refresh the cached rate
     * 
     * @return float New rate
     */
    public static function refresh_rate(): float {
        delete_transient(self::CACHE_KEY);
        return self::get_usd_rate();
    }
}