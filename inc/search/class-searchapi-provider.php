<?php
/**
 * NextSafar Core - SearchApi Provider
 * 
 * Implements ProviderInterface for SearchApi.io
 * Fallback provider when SerpApi fails.
 * 
 * @package NextSafar\Search
 * @since   2.6.0
 */

namespace NextSafar\Search;

use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class SearchApiProvider implements ProviderInterface {
    
    /**
     * Option key for SearchApi API key
     */
    const OPTION_KEY = 'ns_live_searchapi_key';
    
    /**
     * SearchApi base URL
     */
    const BASE_URL = 'https://www.searchapi.io/api/v1/search';
    
    /**
     * Request timeout in seconds
     */
    const TIMEOUT = 60;
    
    /**
     * API key
     */
    private string $api_key;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->api_key = get_option(self::OPTION_KEY, '');
    }
    
    /**
     * Get provider name
     */
    public function get_name(): string {
        return 'searchapi';
    }
    
    /**
     * Check if provider is configured
     */
    public function is_available(): bool {
        return !empty($this->api_key);
    }
    
    /**
     * Search hotels via SearchApi Google Hotels
     */
    public function search_hotels(array $args): array|\WP_Error {
        if (!$this->is_available()) {
            return new \WP_Error('no_api_key', 'کلید سرچ‌ای‌پی تنظیم نشده است');
        }
        
        $params = [
            'engine'     => 'google_hotels',
            'q'          => ($args['q_en'] ?? '') !== '' ? $args['q_en'] : $args['q'],
            'check_in'   => $args['check_in'],
            'check_out'  => $args['check_out'],
            'adults'     => $args['adults'] ?? 2,
            'children'   => $args['children'] ?? 0,
            'currency'   => 'USD',
            'api_key'    => $this->api_key,
        ];
        
        Logger::debug('SearchApi hotel search', [
            'q'        => $params['q'],
            'check_in' => $params['check_in'],
        ]);
        
        $url = self::BASE_URL . '?' . http_build_query($params);
        
        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => ['Accept' => 'application/json'],
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($code !== 200) {
            $err = json_decode($body, true);
            return new \WP_Error('api_error', 'SearchApi: ' . ($err['error'] ?? "HTTP $code"));
        }
        
        $data = json_decode($body, true);
        
        if (!$data) {
            return new \WP_Error('parse_error', 'خطا در تجزیه پاسخ سرچ‌ای‌پی');
        }
        
        $props = $data['properties'] ?? $data['hotels'] ?? [];
        if (empty($props)) return [];
        
        return $this->normalize_hotels_response($props);
    }
    
    /**
     * Get hotel details
     */
    public function hotel_details(array $args): array|\WP_Error {
        // SearchApi doesn't have a dedicated details endpoint
        // Return basic info from the token
        return new \WP_Error('not_supported', 'SearchApi جزئیات هتل را پشتیبانی نمی‌کند');
    }
    
    /**
     * Search flights via SearchApi Google Flights
     */
    public function search_flights(array $args): array|\WP_Error {
        if (!$this->is_available()) {
            return new \WP_Error('no_api_key', 'کلید سرچ‌ای‌پی تنظیم نشده است');
        }
        
        $cabin_map = [
            'economy'         => 'economy',
            'premium_economy' => 'premium_economy',
            'business'        => 'business',
            'first'           => 'first_class',
        ];
        
        $params = [
            'engine'        => 'google_flights',
            'flight_type'   => $args['trip_type'] ?? 'one_way',
            'departure_id'  => $args['origin'],
            'arrival_id'    => $args['dest'],
            'outbound_date' => $args['date'],
            'travel_class'  => $cabin_map[$args['cabin'] ?? 'economy'] ?? 'economy',
            'adults'        => $args['adults'] ?? 1,
            'children'      => $args['children'] ?? 0,
            'currency'      => 'USD',
            'api_key'       => $this->api_key,
        ];
        
        if (($args['trip_type'] ?? 'one_way') === 'round_trip' && !empty($args['return_date'])) {
            $params['return_date'] = $args['return_date'];
        }
        
        $url = self::BASE_URL . '?' . http_build_query($params);
        
        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => ['Accept' => 'application/json'],
        ]);
        
        if (is_wp_error($response)) return $response;
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($code !== 200) {
            $err = json_decode($body, true);
            return new \WP_Error('api_error', 'SearchApi: ' . ($err['error'] ?? "HTTP $code"));
        }
        
        $data = json_decode($body, true);
        if (!$data) {
            return new \WP_Error('parse_error', 'خطا در تجزیه پاسخ پرواز');
        }
        
        return $this->normalize_flights_response($data, $args);
    }
    
    /**
     * Test provider connection
     */
    public function test_connection(): array {
        if (!$this->is_available()) {
            return ['ok' => false, 'message' => 'کلید SearchApi تنظیم نشده است'];
        }
        
        $url = self::BASE_URL . '?' . http_build_query([
            'engine'        => 'google_flights',
            'flight_type'   => 'one_way',
            'departure_id'  => 'JFK',
            'arrival_id'    => 'LAX',
            'outbound_date' => date('Y-m-d', strtotime('+7 days')),
            'adults'        => 1,
            'currency'      => 'USD',
            'api_key'       => $this->api_key,
        ]);
        
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => ['Accept' => 'application/json'],
        ]);
        
        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => 'خطای شبکه: ' . $response->get_error_message()];
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($code !== 200) {
            $err = json_decode($body, true);
            return ['ok' => false, 'message' => 'خطای سرچ‌ای‌پی: ' . ($err['error'] ?? "HTTP $code")];
        }
        
        $data = json_decode($body, true);
        $count = count($data['best_flights'] ?? []) + count($data['other_flights'] ?? []);
        
        return ['ok' => true, 'message' => "اتصال موفق — {$count} پرواز یافت شد"];
    }
    
    /**
     * Normalize hotels response
     */
    private function normalize_hotels_response(array $props): array {
        $rate = PriceConverter::get_usd_rate();
        $out = [];
        
        foreach ($props as $p) {
            $name = $p['name'] ?? '';
            $link = $p['link'] ?? '';
            
            if ($name === '' || $link === '') continue;
            
            $pid = '';
            if (preg_match('/[?&]fid=([^&]+)/', $link, $m)) $pid = $m[1];
            if ($pid === '') $pid = 'u:' . md5($link);
            
            $price_usd = $this->extract_price($p);
            
            $lat = isset($p['latitude']) ? (float) $p['latitude'] : (isset($p['lat']) ? (float) $p['lat'] : null);
            $lng = isset($p['longitude']) ? (float) $p['longitude'] : (isset($p['lng']) ? (float) $p['lng'] : null);
            
            $out[] = [
                'property_id'        => $pid,
                'name'               => $name,
                'link'               => $link,
                'image'              => $p['thumbnail'] ?? ($p['image'] ?? null),
                'rating'             => (float) ($p['rating'] ?? 0),
                'reviews'            => (int) ($p['reviews'] ?? 0),
                'address'            => $p['address'] ?? '',
                'lat'                => $lat,
                'lng'                => $lng,
                'stars'              => (int) ($p['stars'] ?? 0),
                'amenities'          => [],
                'price_usd'          => $price_usd,
                'per_night_toman'    => (int) round($price_usd * $rate),
                'google_property_token' => '',
            ];
        }
        
        return $out;
    }
    
    /**
     * Extract price
     */
    private function extract_price(array $p): float {
        $price_raw = $p['price'] ?? ($p['price_per_night'] ?? '');
        
        if (is_numeric($price_raw)) {
            return (float) $price_raw;
        }
        
        if (preg_match('/(\d[\d,]*(?:\.\d+)?)/', (string) $price_raw, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }
        
        return 0.0;
    }
    
    /**
     * Normalize flights response
     */
    private function normalize_flights_response(array $data, array $args): array {
        $all = array_merge($data['best_flights'] ?? [], $data['other_flights'] ?? []);
        $rate = PriceConverter::get_usd_rate();
        $flights = [];
        
        foreach ($all as $idx => $f) {
            $segments = $f['flights'] ?? [];
            if (empty($segments)) continue;
            
            $first = $segments[0];
            $last = end($segments);
            
            $dep = $first['departure_airport'] ?? [];
            $arr = $last['arrival_airport'] ?? [];
            
            $dep_time = $dep['time'] ?? '';
            $arr_time = $arr['time'] ?? '';
            
            $dep_hm = preg_match('/(\d{2}:\d{2})/', $dep_time, $m) ? $m[1] : '00:00';
            $arr_hm = preg_match('/(\d{2}:\d{2})/', $arr_time, $m) ? $m[1] : '00:00';
            
            $duration_min = (int) ($f['total_duration'] ?? 0);
            if ($duration_min <= 0 && $dep_time && $arr_time) {
                $ts1 = strtotime($dep_time);
                $ts2 = strtotime($arr_time);
                if ($ts1 && $ts2) {
                    $duration_min = (int) (($ts2 - $ts1) / 60);
                    if ($duration_min < 0) $duration_min += 1440;
                }
            }
            
            $dep_date = $dep['date'] ?? '';
            $arr_date = $arr['date'] ?? '';
            $next_day = ($dep_date !== '' && $arr_date !== '') ? ($arr_date > $dep_date) : false;
            
            $flights[] = [
                'id'            => $idx + 1,
                'flight_number' => $first['flight_number'] ?? ('NS-' . ($idx + 1)),
                'airline'       => $first['airline'] ?? 'نامشخص',
                'airline_logo'  => $first['airline_logo'] ?? ($f['airline_logo'] ?? null),
                'origin_code'   => strtoupper($dep['id'] ?? $args['origin']),
                'origin_city'   => AirportMapper::city_fa($dep['id'] ?? '', $dep['name'] ?? ''),
                'dest_code'     => strtoupper($arr['id'] ?? $args['dest']),
                'dest_city'     => AirportMapper::city_fa($arr['id'] ?? '', $arr['name'] ?? ''),
                'date'          => $args['date'],
                'depart_time'   => $dep_hm,
                'arrive_time'   => $arr_hm,
                'next_day'      => $next_day,
                'duration_min'  => $duration_min,
                'stops'         => max(0, count($segments) - 1),
                'price'         => (int) round((float) ($f['price'] ?? 0) * $rate),
                'currency'      => 'TOMAN',
                'cabin'         => strtolower($first['travel_class'] ?? 'Economy'),
                'aircraft'      => $first['airplane'] ?? '',
            ];
        }
        
        usort($flights, fn($x, $y) => strcmp($x['depart_time'], $y['depart_time']));
        
        return $flights;
    }
}