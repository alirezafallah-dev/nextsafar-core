<?php
/**
 * NextSafar Core - Provider Interface
 * 
 * Common interface for all search providers (SerpApi, SearchApi, etc.)
 * Enables Strategy Pattern for easy provider switching.
 *
 * @package NextSafar\Search
 * @since   2.6.0
 */

namespace NextSafar\Search;

if (!defined('ABSPATH')) exit;

interface ProviderInterface {
    
    /**
     * Get provider name
     * 
     * @return string
     */
    public function get_name(): string;
    
    /**
     * Search hotels in a location
     * 
     * @param array $args Search arguments:
     *   - q: City name
     *   - q_en: English city name
     *   - check_in: Check-in date (YYYY-MM-DD)
     *   - check_out: Check-out date (YYYY-MM-DD)
     *   - adults: Number of adults
     *   - children: Number of children
     * 
     * @return array|\WP_Error Array of hotels or WP_Error on failure
     */
    public function search_hotels(array $args): array|\WP_Error;
    
    /**
     * Get hotel details by token
     * 
     * @param array $args Arguments:
     *   - token: Property token
     *   - hotel_name: Hotel name (fallback)
     *   - check_in: Check-in date
     *   - check_out: Check-out date
     *   - adults: Number of adults
     * 
     * @return array|\WP_Error Hotel details or WP_Error
     */
    public function hotel_details(array $args): array|\WP_Error;
    
    /**
     * Search flights
     * 
     * @param array $args Arguments:
     *   - origin: Origin IATA code
     *   - dest: Destination IATA code
     *   - date: Departure date (YYYY-MM-DD)
     *   - return_date: Return date (optional)
     *   - trip_type: 'one_way' or 'round_trip'
     *   - cabin: 'economy', 'premium_economy', 'business', 'first'
     *   - adults: Number of adults
     *   - children: Number of children
     * 
     * @return array|\WP_Error Array of flights or WP_Error
     */
    public function search_flights(array $args): array|\WP_Error;
    
    /**
     * Check if provider is configured and ready
     * 
     * @return bool
     */
    public function is_available(): bool;
    
    /**
     * Test provider connection
     * 
     * @return array ['ok' => bool, 'message' => string]
     */
    public function test_connection(): array;
}