<?php
/**
 * NextSafar Core - Flight Search Service
 * 
 * High-level service for flight search operations.
 * 
 * @package NextSafar\Search
 * @since   2.6.0
 */

namespace NextSafar\Search;

use NextSafar\Core\Logger;
use NextSafar\Core\ErrorHandler;

if (!defined('ABSPATH')) exit;

class FlightSearchService {
    
    /**
     * Cache TTL for flight results (30 minutes - flights change frequently)
     */
    const CACHE_TTL = 30 * MINUTE_IN_SECONDS;
    
    /**
     * Search flights with automatic fallback
     * 
     * @param array $args Search arguments:
     *   - origin: IATA code
     *   - dest: IATA code
     *   - date: Departure date
     *   - return_date: Return date (optional)
     *   - trip_type: 'one_way' or 'round_trip'
     *   - cabin: Cabin class
     *   - adults: Number of adults
     *   - children: Number of children
     * @return array|\WP_Error Array of flights or WP_Error
     */
    public static function search(array $args): array|\WP_Error {
        $origin = strtoupper($args['origin'] ?? '');
        $dest   = strtoupper($args['dest'] ?? '');
        $date   = $args['date'] ?? '';
        
        // Validate required parameters
        if ($origin === '' || $dest === '' || $date === '') {
            return ErrorHandler::missing_params(['origin', 'dest', 'date']);
        }
        
        // Check cache (only for one-way to avoid stale round-trip data)
        $cache_key = self::get_cache_key($args);
        
        if (($args['trip_type'] ?? 'one_way') === 'one_way') {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                Logger::debug('Flight search cache hit', [
                    'origin' => $origin,
                    'dest'   => $dest,
                ]);
                return $cached;
            }
        }
        
        // Get available provider
        $provider = ProviderFactory::get_available();
        
        if ($provider === null) {
            return ErrorHandler::service_unavailable('هیچ ارائه‌دهنده جستجویی پیکربندی نشده است.');
        }
        
        // Execute search
        try {
            $result = $provider->search_flights($args);
        } catch (\Throwable $e) {
            Logger::error('Flight search exception', [
                'origin'  => $origin,
                'dest'    => $dest,
                'message' => $e->getMessage(),
            ]);
            return ErrorHandler::api_error($provider->get_name(), $e->getMessage());
        }
        
        if (is_wp_error($result)) {
            // Try fallback
            return self::search_with_fallback($provider, $args);
        }
        
        // Cache results
        if (($args['trip_type'] ?? 'one_way') === 'one_way') {
            set_transient($cache_key, $result, self::CACHE_TTL);
        }
        
        Logger::info('Flight search completed', [
            'origin'   => $origin,
            'dest'     => $dest,
            'count'    => count($result),
            'provider' => $provider->get_name(),
        ]);
        
        return $result;
    }
    
    /**
     * Search with fallback
     */
    private static function search_with_fallback(ProviderInterface $primary, array $args): array|\WP_Error {
        foreach (ProviderFactory::get_all_statuses() as $name => $status) {
            if ($name === $primary->get_name()) continue;
            if (!$status['available']) continue;
            
            $fallback = ProviderFactory::create($name);
            
            try {
                $result = $fallback->search_flights($args);
            } catch (\Throwable $e) {
                continue;
            }
            
            if (!is_wp_error($result)) {
                Logger::info('Flight search fallback succeeded', [
                    'provider' => $name,
                ]);
                return $result;
            }
        }
        
        return ErrorHandler::api_error('all', 'تمام ارائه‌دهندگان جستجوی پرواز با خطا مواجه شدند');
    }
    
    /**
     * Generate cache key
     */
    private static function get_cache_key(array $args): string {
        $provider = ProviderFactory::get_active_provider_name();
        
        return "ns_flight_search_v1_{$provider}_" . md5(
            strtoupper($args['origin'] ?? '') . '|' .
            strtoupper($args['dest'] ?? '') . '|' .
            ($args['date'] ?? '') . '|' .
            ($args['return_date'] ?? '') . '|' .
            ($args['trip_type'] ?? 'one_way') . '|' .
            ($args['cabin'] ?? 'economy') . '|' .
            ($args['adults'] ?? 1) . '|' .
            ($args['children'] ?? 0)
        );
    }
    
    /**
     * Clear flight search cache
     */
    public static function clear_cache(): int {
        global $wpdb;
        
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '%\_transient\_ns\_flight\_search\_%',
                '%\_transient\_timeout\_ns\_flight\_search\_%'
            )
        );
        
        Logger::info('Flight search cache cleared', ['deleted' => $deleted]);
        
        return (int) $deleted;
    }
}