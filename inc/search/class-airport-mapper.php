<?php
/**
 * NextSafar Core - Airport Mapper Service
 * 
 * Maps IATA codes to city names (Persian and English).
 * Uses WordPress airport CPT as primary source with caching.
 * 
 * @package NextSafar\Search
 * @since   2.6.0
 */

namespace NextSafar\Search;

use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class AirportMapper {
    
    /**
     * Cache prefix
     */
    const CACHE_PREFIX = 'ns_airport_city_';
    
    /**
     * Cache TTL (12 hours)
     */
    const CACHE_TTL = 12 * HOUR_IN_SECONDS;
    
    /**
     * Common IATA codes to Persian cities (hardcoded fallback)
     */
    const COMMON_AIRPORTS = [
        // Iran
        'THR' => 'تهران', 'IKA' => 'تهران', 'MHD' => 'مشهد',
        'SYZ' => 'شیراز', 'ISF' => 'اصفهان', 'TBZ' => 'تبریز',
        'KIH' => 'کیش', 'QOM' => 'قم', 'AWZ' => 'اهواز',
        'RAS' => 'رشت', 'ZBR' => 'چابهار', 'GSM' => 'قشم',
        'BND' => 'بندرعباس', 'KER' => 'کرمان', 'HDM' => 'همدان',
        'IFN' => 'اصفهان', 'KSH' => 'کرمانشاه', 'OMH' => 'ارومیه',
        'AZD' => 'یزد', 'ZAH' => 'زاهدان', 'SRY' => 'ساری',
        'GBT' => 'گرگان', 'BDL' => 'بیرجند', 'XRH' => 'خرم‌آباد',
        
        // Turkey
        'IST' => 'استانبول', 'SAW' => 'استانبول', 'ESB' => 'آنکارا',
        'ADB' => 'ازمیر', 'AYT' => 'آنتالیا', 'BJV' => 'بودروم',
        'DLM' => 'دالامان', 'GZT' => 'غازی‌آینتپ',
        
        // UAE
        'DXB' => 'دبی', 'AUH' => 'ابوظبی', 'SHJ' => 'شارجه',
        
        // Qatar
        'DOH' => 'دوحه',
        
        // Others
        'LHR' => 'لندن', 'CDG' => 'پاریس', 'FRA' => 'فرانکفورت',
        'AMS' => 'آمستردام', 'JFK' => 'نیویورک', 'LAX' => 'لس‌آنجلس',
        'SIN' => 'سنگاپور', 'HND' => 'توکیو', 'PEK' => 'پکن',
        'BKK' => 'بانکوک', 'KUL' => 'کوالالامپور',
    ];
    
    /**
     * Get Persian city name from IATA code
     * 
     * @param string $code IATA code (e.g., "THR")
     * @param string $fallback Fallback if not found
     * @return string Persian city name
     */
    public static function city_fa(string $code, string $fallback = ''): string {
        $code = strtoupper(trim($code));
        
        if ($code === '') return $fallback;
        
        // Check cache
        $cache_key = self::CACHE_PREFIX . $code;
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        // Try to find in WordPress airport CPT
        $city = self::find_in_wordpress($code);
        
        if ($city !== '') {
            set_transient($cache_key, $city, self::CACHE_TTL);
            return $city;
        }
        
        // Use hardcoded mapping
        if (isset(self::COMMON_AIRPORTS[$code])) {
            $city = self::COMMON_AIRPORTS[$code];
            set_transient($cache_key, $city, self::CACHE_TTL);
            return $city;
        }
        
        // Return code as fallback
        return $fallback !== '' ? $fallback : $code;
    }
    
    /**
     * Get English city name from IATA code
     * 
     * @param string $code IATA code
     * @return string English city name or code
     */
    public static function city_en(string $code): string {
        $code = strtoupper(trim($code));
        return $code;
    }
    
    /**
     * Find city name in WordPress airport posts
     * 
     * @param string $code IATA code
     * @return string City name or empty string
     */
    private static function find_in_wordpress(string $code): string {
        $posts = get_posts([
            'post_type'      => 'airport',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => '_airport_iata', 'value' => $code],
                ['key' => '_iata_code', 'value' => $code],
                ['key' => 'iata', 'value' => $code],
            ],
        ]);
        
        if (empty($posts)) {
            return '';
        }
        
        $title = get_the_title($posts[0]);
        
        // Clean up title (remove "فرودگاه" prefix)
        $title = trim(preg_replace('/^فرودگاه\s+(بین‌المللی\s+)?/u', '', $title));
        
        return $title;
    }
    
    /**
     * Get all available airports (for autocomplete)
     * 
     * @param int $limit Maximum number of results
     * @return array Array of ['code' => ..., 'city_fa' => ..., 'city_en' => ...]
     */
    public static function get_all_airports(int $limit = 100): array {
        $cache_key = 'ns_all_airports_' . $limit;
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $airports = [];
        
        // Add hardcoded airports
        foreach (self::COMMON_AIRPORTS as $code => $city) {
            $airports[] = [
                'code'    => $code,
                'city_fa' => $city,
                'city_en' => $code,
            ];
        }
        
        // Add WordPress airports
        $posts = get_posts([
            'post_type'      => 'airport',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'fields'         => 'ids',
        ]);
        
        foreach ($posts as $pid) {
            $iata = get_post_meta($pid, '_airport_iata', true) 
                    ?: get_post_meta($pid, '_iata_code', true)
                    ?: get_post_meta($pid, 'iata', true);
            
            if ($iata !== '') {
                $airports[] = [
                    'code'    => strtoupper($iata),
                    'city_fa' => get_the_title($pid),
                    'city_en' => get_post_meta($pid, '_name_en', true) ?: $iata,
                ];
            }
        }
        
        // Remove duplicates
        $unique = [];
        foreach ($airports as $airport) {
            $unique[$airport['code']] = $airport;
        }
        
        $result = array_values($unique);
        
        // Cache for 6 hours
        set_transient($cache_key, $result, 6 * HOUR_IN_SECONDS);
        
        return $result;
    }
    
    /**
     * Clear all airport caches
     * 
     * @return void
     */
    public static function clear_cache(): void {
        global $wpdb;
        
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '\\_transient\\_ns\\_airport\\_city\\_%'
             OR option_name LIKE '\\_transient\\_timeout\\_ns\\_airport\\_city\\_%'
             OR option_name LIKE '\\_transient\\_ns\\_all\\_airports\\_%'
             OR option_name LIKE '\\_transient\\_timeout\\_ns\\_all\\_airports\\_%'"
        );
        
        Logger::info('Airport mapper cache cleared');
    }
}