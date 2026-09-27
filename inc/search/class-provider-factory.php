<?php
/**
 * NextSafar Core - Provider Factory
 * 
 * Creates provider instances with automatic fallback support.
 * Implements Factory Pattern.
 * 
 * @package NextSafar\Search
 * @since   2.6.0
 */

namespace NextSafar\Search;

use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class ProviderFactory {
    
    /**
     * Option key for active provider
     */
    const OPTION_PROVIDER = 'ns_live_provider';
    
    /**
     * Available providers registry
     */
    private static array $providers = [
        'serpapi'   => SerpApiProvider::class,
        'searchapi' => SearchApiProvider::class,
    ];
    
    /**
     * Get the primary provider based on settings
     * 
     * @return ProviderInterface
     */
    public static function get_primary(): ProviderInterface {
        $provider_name = get_option(self::OPTION_PROVIDER, 'serpapi');
        
        // Fallback to serpapi if invalid
        if (!isset(self::$providers[$provider_name])) {
            $provider_name = 'serpapi';
        }
        
        return self::create($provider_name);
    }
    
    /**
     * Get provider with automatic fallback
     * 
     * Returns primary provider if available, otherwise tries fallbacks.
     * 
     * @return ProviderInterface|null
     */
    public static function get_available(): ?ProviderInterface {
        $primary = self::get_primary();
        
        if ($primary->is_available()) {
            return $primary;
        }
        
        // Try fallbacks
        foreach (self::$providers as $name => $class) {
            if ($name === $primary->get_name()) continue;
            
            $provider = self::create($name);
            if ($provider->is_available()) {
                Logger::info('Provider fallback', [
                    'from' => $primary->get_name(),
                    'to'   => $name,
                ]);
                return $provider;
            }
        }
        
        return null;
    }
    
    /**
     * Create a provider instance
     * 
     * @param string $name Provider name
     * @return ProviderInterface
     */
    public static function create(string $name): ProviderInterface {
        $class = self::$providers[$name] ?? SerpApiProvider::class;
        return new $class();
    }
    
    /**
     * Get all provider statuses
     * 
     * @return array ['provider_name' => ['available' => bool, 'class' => string]]
     */
    public static function get_all_statuses(): array {
        $statuses = [];
        
        foreach (self::$providers as $name => $class) {
            $provider = new $class();
            $statuses[$name] = [
                'available' => $provider->is_available(),
                'class'     => $class,
            ];
        }
        
        return $statuses;
    }
    
    /**
     * Get active provider name
     * 
     * @return string
     */
    public static function get_active_provider_name(): string {
        $provider = self::get_available();
        return $provider ? $provider->get_name() : 'none';
    }
}