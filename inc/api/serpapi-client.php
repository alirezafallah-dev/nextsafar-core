<?php

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

/**
 * SerpApiClient — Client for SerpApi search services
 * Supports hotel, destination, restaurant, hospital search and place details
 *
 * @version 1.0.0
 */
class SerpApiClient extends BaseClient {
    protected $base_url = 'https://serpapi.com/search';

    /* ========================================================================
       Search Hotels
       ======================================================================== */
    public function search_hotels($location, $options = []) {
        if (empty($this->api_key)) {
            $this->last_error = 'کلید API تنظیم نشده است';
            return new \WP_Error('no_api_key', $this->last_error);
        }

        $params = array_merge([
            'engine'         => 'google_hotels',
            'q'              => $location,
            'api_key'        => $this->api_key,
            'hl'             => 'en',
            'gl'             => 'us',
            'currency'       => 'USD',
            'adults'         => 2,
            'check_in_date'  => date('Y-m-d', strtotime('+7 days')),
            'check_out_date' => date('Y-m-d', strtotime('+10 days')),
        ], $options);

        $url = add_query_arg($params, $this->base_url);

        error_log('🔍 SerpApi Hotel Request: ' . $location);

        $response = wp_remote_get($url, [
            'timeout' => $this->timeout,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            $this->last_error = $response->get_error_message();
            error_log('❌ SerpApi Error: ' . $this->last_error);
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $this->last_response = $body;

        if ($status_code !== 200) {
            $this->last_error = "HTTP Error: $status_code";
            return new \WP_Error('api_http_error', $this->last_error);
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error('json_error', 'Invalid JSON');
        }

        /* Check SerpApi error */
        if (isset($data['error'])) {
            $this->last_error = $data['error'];
            return new \WP_Error('api_error', $this->last_error);
        }

        $limit = $options['limit'] ?? 20;

        return $this->normalize_hotels_response($data, $limit);
    }

    /* ========================================================================
       Search Tourist Destinations
       ======================================================================== */
    public function search_destinations($location, $options = []) {
        if (empty($this->api_key)) {
            return new \WP_Error('no_api_key', 'کلید API تنظیم نشده است');
        }

        $params = array_merge([
            'engine' => 'google_maps',
            'q' => 'tourist attractions in ' . $location,
            'api_key' => $this->api_key,
            'hl' => 'en',
            'gl' => 'us',
        ], $options);

        return $this->search_places($params, 'destination', $options['limit'] ?? 20);
    }

    /* ========================================================================
       Search Restaurants
       ======================================================================== */
    public function search_restaurants($location, $options = []) {
        if (empty($this->api_key)) {
            return new \WP_Error('no_api_key', 'کلید API تنظیم نشده است');
        }

        $params = array_merge([
            'engine' => 'google_maps',
            'q' => 'restaurants in ' . $location,
            'api_key' => $this->api_key,
            'hl' => 'en',
            'gl' => 'us',
        ], $options);

        return $this->search_places($params, 'restaurant', $options['limit'] ?? 20);
    }

    /* ========================================================================
       Search Hospitals
       ======================================================================== */
    public function search_hospitals($location, $options = []) {
        if (empty($this->api_key)) {
            return new \WP_Error('no_api_key', 'کلید API تنظیم نشده است');
        }

        $params = array_merge([
            'engine' => 'google_maps',
            'q' => 'hospitals in ' . $location,
            'api_key' => $this->api_key,
            'hl' => 'en',
            'gl' => 'us',
        ], $options);

        return $this->search_places($params, 'hospital', $options['limit'] ?? 20);
    }

    /* ========================================================================
       Get Full Place Details
       ======================================================================== */
    public function get_place_details(string $query): ?array {
        if (empty($this->api_key) || empty($query)) {
            return null;
        }

        $params = [
            'engine' => 'google_maps',
            'q' => $query,
            'api_key' => $this->api_key,
            'hl' => 'en',
            'gl' => 'us',
        ];

        $url = add_query_arg($params, $this->base_url);

        error_log('🔍 SerpApi Place Details: ' . $query);

        $response = wp_remote_get($url, [
            'timeout' => $this->timeout,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            error_log('❌ SerpApi Place Details Error: ' . $response->get_error_message());
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $this->extract_place_details($data);
    }

    /* ========================================================================
       Common Method for Searching Places
       ======================================================================== */
    private function search_places(array $params, string $type, int $limit) {
        $url = add_query_arg($params, $this->base_url);

        error_log('🔍 SerpApi ' . ucfirst($type) . ' Request: ' . ($params['q'] ?? ''));

        $response = wp_remote_get($url, [
            'timeout' => $this->timeout,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            error_log('❌ ' . ucfirst($type) . ' API Error: ' . $response->get_error_message());
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            return new \WP_Error('api_http_error', "HTTP Error: $status_code");
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error('json_error', 'Invalid JSON');
        }

        /* Check SerpApi error */
        if (isset($data['error'])) {
            return new \WP_Error('api_error', $data['error']);
        }

        return $this->normalize_places_response($data, $type, $limit);
    }

    /* ========================================================================
       Extract Place Details from Response
       ======================================================================== */
    private function extract_place_details(array $data): ?array {
        $results = $data['local_results'] ?? ($data['places_results'] ?? []);

        if (empty($results) || !is_array($results)) {
            return null;
        }

        $place = $results[0] ?? null;

        if (empty($place)) {
            return null;
        }

        /* Extract working hours */
        $hours = [];

        if (!empty($place['opening_hours'])) {
            foreach ($place['opening_hours'] as $day => $time) {
                $hours[strtolower($day)] = is_array($time) ? implode(', ', $time) : $time;
            }
        }

        /* Extract amenities from extensions */
        $extensions = [];

        if (!empty($place['extensions'])) {
            $extensions = array_filter(array_map(function($ext) {
                return is_string($ext) ? $ext : ($ext['name'] ?? '');
            }, $place['extensions']));
        }

        /* Extract website */
        $website = $place['website'] ?? '';

        if (is_array($website)) {
            $website = $website[0] ?? ($website['url'] ?? '');
        }

        return [
            'place_id' => is_string($place['place_id'] ?? null) ? $place['place_id'] : '',
            'data_id' => is_string($place['data_id'] ?? null) ? $place['data_id'] : '',
            'name' => is_string($place['title'] ?? null) ? $place['title'] : '',
            'description' => is_string($place['description'] ?? null) ? $place['description'] : '',
            'address' => is_string($place['address'] ?? null) ? $place['address'] : '',
            'phone' => is_string($place['phone'] ?? null) ? $place['phone'] : '',
            'website' => is_string($website) ? $website : '',
            'menu' => '',
            'rating' => floatval($place['rating'] ?? 0),
            'reviews' => intval($place['reviews'] ?? 0),
            'reviews_histogram' => $place['reviews_histogram'] ?? [],
            'reviews_breakdown' => $place['reviews_breakdown'] ?? [],
            'price_level' => is_string($place['price'] ?? null) ? $place['price'] : '',
            'price_description' => '',
            'lat' => floatval($place['gps_coordinates']['latitude'] ?? 0),
            'lng' => floatval($place['gps_coordinates']['longitude'] ?? 0),
            'type' => is_string($place['type'] ?? null) ? $place['type'] : '',
            'types' => is_array($place['types'] ?? null) ? $place['types'] : [],
            'hours' => $hours,
            'open_state' => is_string($place['open_state'] ?? null) ? $place['open_state'] : '',
            'extensions' => $extensions,
            'images' => $place['images'] ?? [],
            'popular_times' => $place['popular_times'] ?? [],
            'questions_and_answers' => [],
        ];
    }

    /* ========================================================================
       Normalize Hotels Response
       ======================================================================== */
    private function normalize_hotels_response(array $data, int $limit): array {
        $hotels = [];

        $properties = $data['properties'] ?? [];
        $properties = array_slice($properties, 0, $limit);

        foreach ($properties as $prop) {
            if (empty($prop['name'])) continue;

            $hotels[] = [
                'external_id'     => $prop['property_token'] ?? uniqid(),
                'data_id'         => $prop['data_id'] ?? '',
                'place_id'        => $prop['place_id'] ?? '',
                'type'            => $prop['type'] ?? 'hotel',
                'name'            => $prop['name'] ?? '',
                'name_en'         => $prop['name'] ?? '',
                'description'     => $prop['description'] ?? '',
                'address'         => $prop['address'] ?? '',
                'city'            => $prop['city'] ?? '',
                'country'         => '',
                'lat'             => $prop['gps_coordinates']['latitude'] ?? 0,
                'lng'             => $prop['gps_coordinates']['longitude'] ?? 0,
                'stars'           => $prop['hotel_class'] ?? 0,
                'rating'          => $prop['overall_rating'] ?? 0,
                'reviews_count'   => $prop['reviews'] ?? 0,
                'price'           => $prop['extracted_price'] ?? 0,
                'currency'        => 'USD',
                'amenities'       => $prop['amenities'] ?? [],
                'images'          => array_filter([$prop['thumbnail'] ?? '']),
                'check_in_time'   => '',
                'check_out_time'  => '',
                'source'          => 'serpapi',
                'raw_data'        => $prop,
            ];
        }

        error_log('✅ SerpApi Normalized Hotels: ' . count($hotels));

        return $hotels;
    }

    /* ========================================================================
       Normalize Places Response (Destination, Restaurant, Hospital)
       ======================================================================== */
    private function normalize_places_response(array $data, string $type = 'destination', int $limit = 20): array {
        $places = [];

        $results = $data['local_results'] ?? ($data['places_results'] ?? []);

        if (empty($results) || !is_array($results)) {
            return $places;
        }

        $results = array_slice($results, 0, $limit);

        foreach ($results as $place) {
            if (empty($place['title'])) continue;

            /* Extract address */
            $address = $place['address'] ?? '';
            $city = '';
            $country = '';

            if (!empty($address)) {
                $parts = explode(',', $address);

                if (count($parts) >= 2) {
                    $city = trim($parts[count($parts) - 2] ?? '');
                    $country = trim($parts[count($parts) - 1] ?? '');
                }
            }

            /* Extract images */
            $images = [];

            if (!empty($place['images'])) {
                foreach ($place['images'] as $img) {
                    if (is_string($img)) {
                        $images[] = $img;
                    } elseif (is_array($img) && !empty($img['original'])) {
                        $images[] = $img['original'];
                    } elseif (is_array($img) && !empty($img['thumbnail'])) {
                        $images[] = $img['thumbnail'];
                    }
                }
            }

            /* Extract amenities from extensions */
            $extensions = [];

            if (!empty($place['extensions'])) {
                foreach ($place['extensions'] as $ext) {
                    if (is_string($ext)) {
                        $extensions[] = $ext;
                    } elseif (is_array($ext) && !empty($ext['name'])) {
                        $extensions[] = $ext['name'];
                    }
                }
            }

            $places[] = [
                'external_id' => $place['place_id'] ?? ($place['data_id'] ?? uniqid()),
                'place_id' => $place['place_id'] ?? '',
                'data_id' => $place['data_id'] ?? '',
                'name' => $place['title'] ?? '',
                'name_en' => $place['title'] ?? '',
                'description' => $place['description'] ?? '',
                'type' => $place['type'] ?? ($place['types'][0] ?? ''),
                'types' => $place['types'] ?? [],
                'address' => $address,
                'city' => $city,
                'country' => $country,
                'lat' => $place['gps_coordinates']['latitude'] ?? 0,
                'lng' => $place['gps_coordinates']['longitude'] ?? 0,
                'phone' => $place['phone'] ?? '',
                'website' => $place['website'] ?? '',
                'rating' => floatval($place['rating'] ?? 0),
                'reviews_count' => intval($place['reviews'] ?? 0),
                'reviews_histogram' => $place['reviews_histogram'] ?? [],
                'price_level' => $place['price'] ?? '',
                'price_description' => '',
                'opening_hours' => $place['opening_hours'] ?? [],
                'open_state' => $place['open_state'] ?? '',
                'extensions' => $extensions,
                'popular_times' => $place['popular_times'] ?? [],
                'reviews_breakdown' => $place['reviews_breakdown'] ?? [],
                'questions_and_answers' => [],
                'menu_link' => '',
                'thumbnail' => $place['thumbnail'] ?? '',
                'images' => $images,
                'source' => 'serpapi',
            ];
        }

        error_log('✅ SerpApi Normalized ' . count($places) . ' ' . $type . ' results');

        return $places;
    }

    /* ========================================================================
       Get Full Hotel Details (For Future Use)
       ======================================================================== */
    public function get_hotel_details($property_token) {
        return [];
    }
}