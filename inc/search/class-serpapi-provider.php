<?php
/**
 * NextSafar Core - SerpApi Provider
 * 
 * Implements ProviderInterface for SerpApi.com
 * Primary provider for hotels and flights search.
 * 
 * @package NextSafar\Search
 * @since   2.6.0
 */

namespace NextSafar\Search;

use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class SerpApiProvider implements ProviderInterface {
    
    /**
     * Option key for SerpApi API key
     */
    const OPTION_KEY = 'ns_live_serpapi_key';
    
    /**
     * SerpApi base URL
     */
    const BASE_URL = 'https://serpapi.com/search.json';
    
    /**
     * Request timeout in seconds
     */
    const TIMEOUT = 45;
    
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
        return 'serpapi';
    }
    
    /**
     * Check if provider is configured
     */
    public function is_available(): bool {
        return !empty($this->api_key);
    }
    
    /**
     * Search hotels via SerpApi Google Hotels
     */
    public function search_hotels(array $args): array|\WP_Error {
        if (!$this->is_available()) {
            return new \WP_Error('no_api_key', 'کلید سرپ‌ای‌پی تنظیم نشده است');
        }
        
        $params = [
            'engine'        => 'google_hotels',
            'q'             => $args['q_en'] ?? $args['q'],
            'check_in_date' => $args['check_in'],
            'check_out_date' => $args['check_out'],
            'adults'        => $args['adults'] ?? 2,
            'hl'            => 'en',
            'gl'            => 'us',
            'currency'      => 'USD',
            'api_key'       => $this->api_key,
        ];
        
        if (!empty($args['children'])) {
            $params['children'] = $args['children'];
        }
        
        Logger::debug('SerpApi hotel search', [
            'q'        => $params['q'],
            'check_in' => $params['check_in_date'],
        ]);
        
        $url = self::BASE_URL . '?' . http_build_query($params);
        
        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => ['Accept' => 'application/json'],
        ]);
        
        if (is_wp_error($response)) {
            Logger::error('SerpApi request failed', [
                'error' => $response->get_error_message(),
            ]);
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($code !== 200) {
            $error_data = json_decode($body, true);
            $error_msg = $error_data['error'] ?? "HTTP $code";
            
            Logger::error('SerpApi API error', [
                'code' => $code,
                'error' => $error_msg,
            ]);
            
            return new \WP_Error('api_error', "SerpApi: $error_msg");
        }
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error('parse_error', 'خطا در تجزیه پاسخ سرپ‌ای‌پی');
        }
        
        // Check for SerpApi error
        if (isset($data['error'])) {
            return new \WP_Error('api_error', 'SerpApi: ' . $data['error']);
        }
        
        return $this->normalize_hotels_response($data);
    }
    
    /**
     * Get hotel details from SerpApi
     */
    public function hotel_details(array $args): array|\WP_Error {
        if (!$this->is_available()) {
            return new \WP_Error('no_api_key', 'کلید سرپ‌ای‌پی تنظیم نشده است');
        }
        
        $params = [
            'engine'        => 'google_hotels_property_details',
            'property_token' => $args['token'],
            'check_in_date' => $args['check_in'],
            'check_out_date' => $args['check_out'],
            'adults'        => $args['adults'] ?? 2,
            'currency'      => 'USD',
            'api_key'       => $this->api_key,
        ];
        
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
            return new \WP_Error('api_error', "SerpApi Details: HTTP $code");
        }
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || isset($data['error'])) {
            return new \WP_Error('parse_error', 'خطا در دریافت جزئیات هتل');
        }
        
        return $this->normalize_hotel_details($data);
    }
    
    /**
     * Search flights via SerpApi Google Flights
     */
    public function search_flights(array $args): array|\WP_Error {
        if (!$this->is_available()) {
            return new \WP_Error('no_api_key', 'کلید سرپ‌ای‌پی تنظیم نشده است');
        }
        
        $trip_type = $args['trip_type'] ?? 'one_way';
        $cabin_map = [
            'economy'          => 1,
            'premium_economy'  => 2,
            'business'         => 3,
            'first'            => 4,
        ];
        
        $params = [
            'engine'        => 'google_flights',
            'departure_id'  => $args['origin'],
            'arrival_id'    => $args['dest'],
            'outbound_date' => $args['date'],
            'adults'        => $args['adults'] ?? 1,
            'children'      => $args['children'] ?? 0,
            'travel_class'  => $cabin_map[$args['cabin'] ?? 'economy'] ?? 1,
            'currency'      => 'USD',
            'api_key'       => $this->api_key,
        ];
        
        // Trip type: 1 = round trip, 2 = one way
        $params['type'] = ($trip_type === 'round_trip') ? 1 : 2;
        
        if ($trip_type === 'round_trip' && !empty($args['return_date'])) {
            $params['return_date'] = $args['return_date'];
        }
        
        Logger::debug('SerpApi flight search', [
            'origin' => $args['origin'],
            'dest'   => $args['dest'],
            'date'   => $args['date'],
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
            return new \WP_Error('api_error', "SerpApi Flights: HTTP $code");
        }
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error('parse_error', 'خطا در تجزیه پاسخ پرواز');
        }
        
        return $this->normalize_flights_response($data, $args);
    }
    
    /**
     * Test provider connection
     */
    public function test_connection(): array {
        if (!$this->is_available()) {
            return ['ok' => false, 'message' => 'کلید SerpApi تنظیم نشده است'];
        }
        
        $params = [
            'engine'        => 'google_flights',
            'departure_id'  => 'JFK',
            'arrival_id'    => 'LAX',
            'outbound_date' => date('Y-m-d', strtotime('+7 days')),
            'type'          => 2,
            'travel_class'  => 1,
            'adults'        => 1,
            'currency'      => 'USD',
            'api_key'       => $this->api_key,
        ];
        
        $url = self::BASE_URL . '?' . http_build_query($params);
        
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
            return ['ok' => false, 'message' => 'خطای سرپ‌ای‌پی: ' . ($err['error'] ?? "HTTP $code")];
        }
        
        $data = json_decode($body, true);
        $count = count($data['best_flights'] ?? []) + count($data['other_flights'] ?? []);
        
        return ['ok' => true, 'message' => "اتصال موفق — {$count} پرواز یافت شد"];
    }
    
    /**
     * Normalize hotels response from SerpApi
     */
    private function normalize_hotels_response(array $data): array {
        $props = $data['properties'] ?? [];
        $rate = PriceConverter::get_usd_rate();
        $out = [];
        
        foreach ($props as $p) {
            $name = $p['name'] ?? '';
            $link = $p['link'] ?? '';
            
            if ($name === '') continue;
            
            // Extract property ID
            $pid = '';
            if (preg_match('/[?&]fid=([^&]+)/', $link, $m)) {
                $pid = $m[1];
            }
            if ($pid === '') {
                $pid = 'u:' . md5($link ?: $name);
            }
            
            // Extract price
            $price_usd = $this->extract_price($p);
            
            // Extract coordinates
            $lat = isset($p['gps_coordinates']['latitude']) 
                ? (float) $p['gps_coordinates']['latitude'] 
                : (isset($p['latitude']) ? (float) $p['latitude'] : null);
            $lng = isset($p['gps_coordinates']['longitude']) 
                ? (float) $p['gps_coordinates']['longitude'] 
                : (isset($p['longitude']) ? (float) $p['longitude'] : null);
            
            // Extract amenities
            $amenities = [];
            if (!empty($p['amenities']) && is_array($p['amenities'])) {
                $amenities = array_map(function($a) {
                    return is_string($a) ? strtolower(str_replace(' ', '_', $a)) : '';
                }, array_column($p['amenities'], 'name'));
                $amenities = array_filter($amenities);
            }
            
            $out[] = [
                'property_id'        => $pid,
                'name'               => $name,
                'link'               => $link,
                'image'              => $p['images'][0]['thumbnail'] ?? ($p['image'] ?? null),
                'rating'             => (float) ($p['overall_rating'] ?? 0),
                'reviews'            => (int) ($p['reviews'] ?? 0),
                'address'            => $p['location'] ?? '',
                'lat'                => $lat,
                'lng'                => $lng,
                'stars'              => (int) ($p['hotel_class'] ?? $p['extracted_hotel_class'] ?? 0),
                'amenities'          => array_values($amenities),
                'price_usd'          => $price_usd,
                'per_night_toman'    => $price_usd > 0 ? (int) round($price_usd * $rate) : 0,
                'google_property_token' => $p['property_token'] ?? '',
            ];
        }
        
        return $out;
    }
    
    /**
     * Extract price from hotel data
     */
    private function extract_price(array $p): float {
        // Try rate_per_night first
        if (isset($p['rate_per_night']['extracted_lowest'])) {
            return (float) $p['rate_per_night']['extracted_lowest'];
        }
        
        // Try total_rate
        if (isset($p['total_rate']['extracted_lowest'])) {
            return (float) $p['total_rate']['extracted_lowest'];
        }
        
        // Try prices array
        if (isset($p['prices']) && is_array($p['prices'])) {
            foreach ($p['prices'] as $price) {
                if (isset($price['rate_per_night']['extracted_lowest'])) {
                    return (float) $price['rate_per_night']['extracted_lowest'];
                }
            }
        }
        
        // Try lowest_price string
        if (isset($p['lowest_price'])) {
            return PriceConverter::extract_numeric((string) $p['lowest_price']);
        }
        
        return 0.0;
    }
    
    /**
     * Normalize hotel details response
     */
    private function normalize_hotel_details(array $data): array {
        $rate = PriceConverter::get_usd_rate();
        
        $price_usd = $this->extract_price($data);
        
        return [
            'name'            => $data['name'] ?? '',
            'description'     => $data['description'] ?? '',
            'rating'          => (float) ($data['overall_rating'] ?? 0),
            'reviews'         => (int) ($data['reviews'] ?? 0),
            'stars'           => (int) ($data['hotel_class'] ?? 0),
            'address'         => $data['location'] ?? '',
            'check_in_time'   => $data['check_in_time'] ?? '',
            'check_out_time'  => $data['check_out_time'] ?? '',
            'price_usd'       => $price_usd,
            'per_night_toman' => $price_usd > 0 ? (int) round($price_usd * $rate) : 0,
            'rooms'           => $data['rooms'] ?? [],
            'images'          => $this->extract_images($data['images'] ?? []),
            'reviews_list'    => $data['reviews_breakdown'] ?? [],
            'nearby_places'   => $data['nearby_places'] ?? [],
            'amenities'       => $this->extract_amenities($data['amenities'] ?? []),
        ];
    }
    
    /**
     * Extract images array
     */
    private function extract_images(array $images): array {
        $out = [];
        foreach ($images as $img) {
            if (isset($img['thumbnail'])) {
                $out[] = $img['thumbnail'];
            }
        }
        return $out;
    }
    
    /**
     * Extract amenities array
     */
    private function extract_amenities(array $amenities): array {
        $out = [];
        foreach ($amenities as $a) {
            if (is_string($a)) {
                $out[] = strtolower(str_replace(' ', '_', $a));
            } elseif (isset($a['name'])) {
                $out[] = strtolower(str_replace(' ', '_', $a['name']));
            }
        }
        return array_values(array_unique($out));
    }
    
    /**
     * Normalize flights response from SerpApi
     */
    private function normalize_flights_response(array $data, array $args): array {
        $all_flights = array_merge(
            $data['best_flights'] ?? [],
            $data['other_flights'] ?? []
        );
        
        $rate = PriceConverter::get_usd_rate();
        $flights = [];
        
        foreach ($all_flights as $idx => $f) {
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
            
            // Calculate duration
            $duration_min = (int) ($f['total_duration'] ?? 0);
            if ($duration_min <= 0 && $dep_time && $arr_time) {
                $ts1 = strtotime($dep_time);
                $ts2 = strtotime($arr_time);
                if ($ts1 && $ts2) {
                    $duration_min = (int) (($ts2 - $ts1) / 60);
                    if ($duration_min < 0) $duration_min += 1440;
                }
            }
            
            // Check if arrival is next day
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
        
        // Sort by departure time
        usort($flights, fn($x, $y) => strcmp($x['depart_time'], $y['depart_time']));
        
        return $flights;
    }
}