<?php
/**
 * NextSafar Core - Hotel Search Service
 * 
 * High-level service for hotel search operations.
 * Orchestrates provider calls, caching, and result merging.
 * 
 * @package NextSafar\Search
 * @since   2.6.0
 */

namespace NextSafar\Search;

use NextSafar\Core\Logger;
use NextSafar\Core\ErrorHandler;

if (!defined('ABSPATH')) exit;

class HotelSearchService {
    
    /**
     * Cache TTL for search results (2 hours)
     */
    const CACHE_TTL = 2 * HOUR_IN_SECONDS;
    
    /**
     * Search hotels with automatic fallback and caching
     * 
     * @param array $args Search arguments
     * @return array|\WP_Error Array of hotels or WP_Error
     */
    public static function search(array $args): array|\WP_Error {
        $city      = $args['q'] ?? '';
        $check_in  = $args['check_in'];
        $check_out = $args['check_out'];
        $adults    = $args['adults'] ?? 2;
        $children  = $args['children'] ?? 0;
        
        // Check cache
        $cache_key = self::get_cache_key($args);
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            Logger::debug('Hotel search cache hit', ['city' => $city]);
            return $cached;
        }
        
        // Get available provider
        $provider = ProviderFactory::get_available();
        
        if ($provider === null) {
            return ErrorHandler::service_unavailable('هیچ ارائه‌دهنده جستجویی پیکربندی نشده است.');
        }
        
        // Execute search with fallback
        $result = self::search_with_fallback($provider, $args);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Cache results
        set_transient($cache_key, $result, self::CACHE_TTL);
        
        Logger::info('Hotel search completed', [
            'city'    => $city,
            'count'   => count($result),
            'provider' => $provider->get_name(),
        ]);
        
        return $result;
    }
    
    /**
     * Get hotel details
     * 
     * @param array $args Details arguments
     * @return array|\WP_Error
     */
    public static function get_details(array $args): array|\WP_Error {
        $token = $args['token'] ?? '';
        
        if ($token === '') {
            return ErrorHandler::missing_params(['token']);
        }
        
        // Check cache
        $cache_key = 'ns_hotel_details_v4_' . md5($token . ($args['check_in'] ?? '') . ($args['check_out'] ?? '') . ($args['adults'] ?? 2));
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $provider = ProviderFactory::get_available();
        
        if ($provider === null) {
            return ErrorHandler::service_unavailable();
        }
        
        try {
            $result = $provider->hotel_details($args);
        } catch (\Throwable $e) {
            Logger::error('Hotel details exception', [
                'token'   => $token,
                'message' => $e->getMessage(),
            ]);
            return ErrorHandler::api_error($provider->get_name(), $e->getMessage());
        }
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        set_transient($cache_key, $result, self::CACHE_TTL);
        
        return $result;
    }
    
    /**
     * Search with automatic provider fallback
     * 
     * @param ProviderInterface $primary Primary provider
     * @param array $args Search arguments
     * @return array|\WP_Error
     */
    private static function search_with_fallback(ProviderInterface $primary, array $args): array|\WP_Error {
        // Try primary provider
        $result = $primary->search_hotels($args);
        
        if (!is_wp_error($result)) {
            return $result;
        }
        
        // Log primary failure
        Logger::warning('Primary provider failed, trying fallback', [
            'provider' => $primary->get_name(),
            'error'    => $result->get_error_message(),
        ]);
        
        // Try fallback providers
        foreach (ProviderFactory::get_all_statuses() as $name => $status) {
            if ($name === $primary->get_name()) continue;
            if (!$status['available']) continue;
            
            $fallback = ProviderFactory::create($name);
            $result = $fallback->search_hotels($args);
            
            if (!is_wp_error($result)) {
                Logger::info('Fallback provider succeeded', [
                    'provider' => $name,
                ]);
                return $result;
            }
            
            Logger::warning('Fallback provider also failed', [
                'provider' => $name,
                'error'    => $result->get_error_message(),
            ]);
        }
        
        // All providers failed
        return ErrorHandler::api_error('all', 'تمام ارائه‌دهندگان جستجو با خطا مواجه شدند');
    }
    
    /**
     * Generate cache key from search arguments
     * 
     * @param array $args Search arguments
     * @return string
     */
    private static function get_cache_key(array $args): string {
        $provider = ProviderFactory::get_active_provider_name();
        
        return "ns_hotel_search_v2_{$provider}_" . md5(
            ($args['q'] ?? '') . '|' .
            ($args['check_in'] ?? '') . '|' .
            ($args['check_out'] ?? '') . '|' .
            ($args['adults'] ?? 2) . '|' .
            ($args['children'] ?? 0)
        );
    }
    
    /**
     * Clear search cache
     * 
     * @return int Number of deleted items
     */
    public static function clear_cache(): int {
        global $wpdb;
        
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '%\_transient\_ns\_hotel\_search\_%',
                '%\_transient\_timeout\_ns\_hotel\_search\_%'
            )
        );
        
        Logger::info('Hotel search cache cleared', ['deleted' => $deleted]);
        
        return (int) $deleted;
    }
}