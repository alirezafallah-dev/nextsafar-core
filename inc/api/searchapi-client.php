<?php

namespace NextSafar\API;

class SearchApiClient extends BaseClient {
    protected $base_url = 'https://www.searchapi.io/api/v1/search';

    /* ========================================================================
       Search Hotels (Unchanged - Hotel Specific)
       ======================================================================== */
    public function search_hotels($location, $options = []) {
        if (empty($this->api_key)) {
            $this->last_error = 'کلید API تنظیم نشده است';
            return new \WP_Error('no_api_key', $this->last_error);
        }

        $params = array_merge([
            'engine' => 'google_hotels',
            'q' => $location,
            'api_key' => $this->api_key,
            'hl' => 'en',
            'gl' => 'us',
            'currency' => 'USD',
            'adults' => 2,
            'check_in_date' => date('Y-m-d', strtotime('+7 days')),
            'check_out_date' => date('Y-m-d', strtotime('+10 days')),
        ], $options);

        $url = add_query_arg($params, $this->base_url);

        error_log('🔍 SearchApi Hotel Request: ' . $location);

        $response = wp_remote_get($url, [
            'timeout' => $this->timeout,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            $this->last_error = $response->get_error_message();
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $this->last_response = $body;

        if ($status_code !== 200) {
            return new \WP_Error('api_http_error', "HTTP Error: $status_code");
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error('json_error', 'Invalid JSON');
        }

        $limit = $options['limit'] ?? 20;

        return $this->normalize_hotels_response($data, $limit);
    }

    /* ========================================================================
       Search Destinations
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
       Common Method for Searching Places
       ======================================================================== */
    private function search_places(array $params, string $type, int $limit) {
        $url = add_query_arg($params, $this->base_url);

        error_log('🔍 SearchApi ' . ucfirst($type) . ' Request: ' . ($params['q'] ?? ''));

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

        return $this->normalize_places_response($data, $type, $limit);
    }

    /* ========================================================================
       Get Full Place Details from SearchAPI (Google Maps)
       This method replaces GooglePlacesClient
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

        error_log('🔍 SearchApi Place Details: ' . $query);

        $response = wp_remote_get($url, [
            'timeout' => $this->timeout,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            error_log('❌ Place Details Error: ' . $response->get_error_message());
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
       General Search by Name (For Syncing Old Posts)
       $hl = 'fa' → Returns Persian name (for matching with Persian titles)
       ======================================================================== */
    public function search_places_by_name(string $query, int $limit = 6, string $hl = 'fa') {
        if (empty($this->api_key)) {
            return new \WP_Error('no_api_key', 'کلید SearchApi تنظیم نشده');
        }

        $params = [
            'engine'  => 'google_maps',
            'q'       => $query,
            'api_key' => $this->api_key,
            'hl'      => $hl,
            'gl'      => 'us',
        ];

        return $this->search_places($params, 'place', $limit);
    }

    /* ========================================================================
       Extract Place Details from google_maps Response
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

        if (!empty($place['open_hours'])) {
            foreach ($place['open_hours'] as $day => $time) {
                $hours[strtolower($day)] = is_array($time) ? implode(', ', $time) : $time;
            }
        }

        /* Extract amenities from extensions */
        $extensions = [];

        if (!empty($place['extensions'])) {
            foreach ($place['extensions'] as $ext) {
                if (!empty($ext['items'])) {
                    foreach ($ext['items'] as $item) {
                        if (!empty($item['value'])) {
                            $extensions[] = $item['value'];
                        }
                    }
                }
            }
        }

        /* Extract website with array handling */
        $website = $place['website'] ?? '';

        if (is_array($website)) {
            $website = $website[0] ?? ($website['url'] ?? '');
        }

        /* Extract menu with all possible formats (string, array, object) */
        $menu = $this->extract_menu_link($place['menu'] ?? null);

        return [
            'place_id' => is_string($place['place_id'] ?? null) ? $place['place_id'] : '',
            'data_id' => is_string($place['data_id'] ?? null) ? $place['data_id'] : '',
            'name' => is_string($place['title'] ?? null) ? $place['title'] : '',
            'description' => is_string($place['description'] ?? null) ? $place['description'] : '',
            'address' => is_string($place['address'] ?? null) ? $place['address'] : '',
            'phone' => is_string($place['phone'] ?? null) ? $place['phone'] : '',
            'website' => is_string($website) ? $website : '',
            'menu' => $menu,
            'rating' => floatval($place['rating'] ?? 0),
            'reviews' => intval($place['reviews'] ?? 0),
            'reviews_histogram' => $place['reviews_histogram'] ?? [],
            'reviews_breakdown' => $place['reviews_breakdown'] ?? [],
            'price_level' => is_string($place['price'] ?? null) ? $place['price'] : '',
            'price_description' => is_string($place['price_description'] ?? null) ? $place['price_description'] : '',
            'lat' => floatval($place['gps_coordinates']['latitude'] ?? 0),
            'lng' => floatval($place['gps_coordinates']['longitude'] ?? 0),
            'type' => is_string($place['type'] ?? null) ? $place['type'] : '',
            'types' => is_array($place['types'] ?? null) ? $place['types'] : [],
            'hours' => $hours,
            'open_state' => is_string($place['open_state'] ?? null) ? $place['open_state'] : '',
            'extensions' => $extensions,
            'images' => $place['images'] ?? [],
            'popular_times' => $place['popular_times'] ?? [],
            'questions_and_answers' => $place['questions_and_answers'] ?? [],
        ];
    }

    /* ========================================================================
       Extract Main City Name (Remove District like Istanbul/Fatih)
       If string contains / or -, only the first part (main city) is returned
       ======================================================================== */
    private function extract_main_city(string $city_string): string {
        $city_string = trim($city_string);

        if (empty($city_string)) return '';

        /* If contains / (like Istanbul/Fatih) */
        if (strpos($city_string, '/') !== false) {
            $parts = explode('/', $city_string);
            $city_string = trim($parts[0]);
        }

        /* If contains - (like Istanbul-Fatih) */
        if (strpos($city_string, '-') !== false) {
            $parts = explode('-', $city_string);
            $city_string = trim($parts[0]);
        }

        return $city_string;
    }

    /* ========================================================================
       Extract Menu Link from All Possible Formats
       ======================================================================== */
    private function extract_menu_link($menu_data): string {
        if (empty($menu_data)) {
            return '';
        }

        /* Case 1: Simple string */
        if (is_string($menu_data)) {
            return $menu_data;
        }

        /* Case 2: Array */
        if (is_array($menu_data)) {
            /* Check first element */
            $first = reset($menu_data);

            if (is_string($first)) {
                return $first;
            }

            /* If it's an internal object or array */
            if (is_array($first) || is_object($first)) {
                $link = $first['link'] ?? $first['url'] ?? $first['href'] ?? '';

                if (!empty($link)) return $link;
            }
        }

        /* Case 3: Object */
        if (is_object($menu_data)) {
            return $menu_data->link ?? $menu_data->url ?? $menu_data->href ?? '';
        }

        /* Case 4: If it was JSON, try to parse */
        if (is_string($menu_data)) {
            $decoded = json_decode($menu_data, true);

            if (is_array($decoded)) {
                return $decoded['link'] ?? $decoded['url'] ?? '';
            }
        }

        return '';
    }

    /* ========================================================================
       Normalize Response for Destinations, Restaurants and Hospitals
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
                    $city = $this->extract_main_city(trim($parts[count($parts) - 2] ?? ''));
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
                    if (!empty($ext['items'])) {
                        foreach ($ext['items'] as $item) {
                            if (!empty($item['value'])) {
                                $extensions[] = $item['value'];
                            }
                        }
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
                'price_description' => $place['price_description'] ?? '',
                'opening_hours' => $place['open_hours'] ?? [],
                'open_state' => $place['open_state'] ?? '',
                'extensions' => $extensions,
                'popular_times' => $place['popular_times'] ?? [],
                'reviews_breakdown' => $place['reviews_breakdown'] ?? [],
                'questions_and_answers' => $place['questions_and_answers'] ?? [],
                'menu_link' => $this->extract_menu_link($place['menu'] ?? null),
                'thumbnail' => $place['thumbnail'] ?? '',
                'images' => $images,
                'source' => 'searchapi',
            ];
        }

        error_log('✅ Normalized ' . count($places) . ' ' . $type . ' results');

        return $places;
    }

    /* ========================================================================
       Search News
       ======================================================================== */
    public function search_news($query, $options = []) {
        if (empty($this->api_key)) {
            return new \WP_Error('no_api_key', 'کلید API تنظیم نشده است');
        }

        $params = array_merge([
            'engine' => 'google_news',
            'q' => $query,
            'api_key' => $this->api_key,
            'hl' => 'fa',
            'gl' => 'ir',
        ], $options);

        $url = add_query_arg($params, $this->base_url);

        error_log('🔍 SearchApi News Request: ' . $query);

        $response = wp_remote_get($url, [
            'timeout' => $this->timeout,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            return new \WP_Error('api_http_error', "HTTP Error: $status_code");
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error('json_error', 'Invalid JSON');
        }

        return $this->normalize_news_response($data);
    }

    /* ========================================================================
       Normalize News
       ======================================================================== */
    private function normalize_news_response(array $data): array {
        $news = [];

        $results = $data['news_results'] ?? [];

        foreach ($results as $item) {
            if (empty($item['title'])) continue;

            $news[] = [
                'title' => $item['title'] ?? '',
                'snippet' => $item['snippet'] ?? '',
                'link' => $item['link'] ?? '',
                'source' => $item['source']['name'] ?? '',
                'date' => $item['date'] ?? '',
                'thumbnail' => $item['thumbnail'] ?? '',
            ];
        }

        return $news;
    }

    /* ========================================================================
       Normalize Hotels Response
       ======================================================================== */
    private function normalize_hotels_response(array $data, int $limit): array {
        $hotels = [];

        /* SearchApi may return properties, places or results */
        $properties = $data['properties'] ?? ($data['places'] ?? ($data['places_results'] ?? ($data['local_results'] ?? [])));

        /* FIX 1: Precise log of response structure */
        error_log('🔍 Hotels API response keys: ' . implode(', ', array_keys($data)));
        error_log('🔍 Hotels count in API response: ' . count($properties));

        if (empty($properties)) {
            error_log('❌ No properties found in SearchApi response');
            return $hotels;
        }

        $properties = array_slice($properties, 0, $limit);

        /* FIX 2: Track used external_ids */
        $seen_external_ids = [];
        $duplicates_skipped = 0;

        foreach ($properties as $prop) {
            if (empty($prop['name'])) continue;

            $country_code = strtoupper(trim($prop['country'] ?? ''));
            $country_name = $this->country_code_to_name($country_code);
            $city = $this->extract_main_city($prop['city'] ?? '');

            $address = '';

            if (!empty($prop['address'])) {
                $address = $prop['address'];
            } elseif (!empty($prop['full_address'])) {
                $address = $prop['full_address'];
            }

            if (empty($address)) {
                $parts = array_filter([$city, $country_name]);
                $address = implode(', ', $parts);
            }

            $price_per_night = $prop['price_per_night'] ?? [];
            $total_price = $prop['total_price'] ?? [];

            /* ================================================================
               FIX 3: Guaranteed unique external_id
               Priority:
               1. property_token (if available and unique)
               2. data_id
               3. place_id
               4. Real uniqid with more_entropy + random bytes
               ================================================================= */
            $external_id = '';
            $id_source = 'generated';

            if (!empty($prop['property_token']) && !isset($seen_external_ids[$prop['property_token']])) {
                $external_id = $prop['property_token'];
                $id_source = 'property_token';
            } elseif (!empty($prop['data_id']) && !isset($seen_external_ids[$prop['data_id']])) {
                $external_id = $prop['data_id'];
                $id_source = 'data_id';
            } elseif (!empty($prop['place_id']) && !isset($seen_external_ids[$prop['place_id']])) {
                $external_id = $prop['place_id'];
                $id_source = 'place_id';
            }

            /* If none was available or was duplicate, generate a real uniqid */
            if (empty($external_id)) {
                $external_id = 'hotel_' . uniqid('', true) . '_' . bin2hex(random_bytes(4));
                $id_source = 'generated';
            }

            /* Mark as used */
            $seen_external_ids[$external_id] = true;

            error_log("✅ Hotel [{$id_source}]: \"{$prop['name']}\" → ID: {$external_id}");

            $hotels[] = [
                'external_id' => $external_id,
                'data_id' => $prop['data_id'] ?? '',
                'place_id' => $prop['place_id'] ?? '',
                'property_token' => $prop['property_token'] ?? '',
                'type' => $prop['type'] ?? 'hotel',
                'name' => $prop['name'] ?? '',
                'name_en' => $prop['name'] ?? '',
                'description' => $prop['description'] ?? '',
                'link' => $prop['link'] ?? '',
                'address' => $address,
                'city' => $city,
                'country' => $country_name,
                'country_code' => $country_code,
                'lat' => floatval($prop['gps_coordinates']['latitude'] ?? 0),
                'lng' => floatval($prop['gps_coordinates']['longitude'] ?? 0),
                'check_in_time' => $this->normalize_time($prop['check_in_time'] ?? ''),
                'check_out_time' => $this->normalize_time($prop['check_out_time'] ?? ''),
                'stars' => $prop['extracted_hotel_class'] ?? ($prop['hotel_class'] ?? 0),
                'rating' => $prop['rating'] ?? 0,
                'reviews_count' => $prop['reviews'] ?? 0,
                'reviews_histogram' => $prop['reviews_histogram'] ?? [],
                'reviews_breakdown' => $prop['reviews_breakdown'] ?? [],
                'location_rating' => $prop['location_rating'] ?? 0,
                'proximity_to_things_to_do_rating' => $prop['proximity_to_things_to_do_rating'] ?? 0,
                'proximity_to_transit_rating' => $prop['proximity_to_transit_rating'] ?? 0,
                'airport_access_rating' => $prop['airport_access_rating'] ?? 0,
                'price' => $price_per_night['extracted_price'] ?? 0,
                'price_formatted' => $price_per_night['price'] ?? '',
                'price_before_taxes' => $price_per_night['extracted_price_before_taxes'] ?? 0,
                'price_before_taxes_formatted' => $price_per_night['price_before_taxes'] ?? '',
                'total_price' => $total_price['extracted_price'] ?? 0,
                'total_price_formatted' => $total_price['price'] ?? '',
                'total_price_before_taxes' => $total_price['extracted_price_before_taxes'] ?? 0,
                'total_price_before_taxes_formatted' => $total_price['price_before_taxes'] ?? '',
                'currency' => $data['search_parameters']['currency'] ?? 'USD',
                'deal' => $prop['deal'] ?? '',
                'deal_description' => $prop['deal_description'] ?? '',
                'amenities' => $prop['amenities'] ?? [],
                'excluded_amenities' => $prop['excluded_amenities'] ?? [],
                'nearby_places' => $prop['nearby_places'] ?? [],
                'essential_info' => $prop['essential_info'] ?? [],
                'images' => $this->extract_images($prop['images'] ?? []),
                'source' => 'searchapi',
                'raw_data' => $prop,
            ];
        }

        error_log('✅ Total hotels normalized: ' . count($hotels));

        return $hotels;
    }

    /* ========================================================================
       Convert Country Code to Full Name
       ======================================================================== */
    private function country_code_to_name(string $code): string {
        $code = strtoupper(trim($code));

        if (empty($code)) return '';

        $countries = [
            'TR' => 'Turkey', 'IR' => 'Iran', 'AE' => 'United Arab Emirates',
            'US' => 'United States', 'GB' => 'United Kingdom', 'FR' => 'France',
            'DE' => 'Germany', 'IT' => 'Italy', 'ES' => 'Spain', 'GR' => 'Greece',
            'TH' => 'Thailand', 'MY' => 'Malaysia', 'ID' => 'Indonesia',
            'IN' => 'India', 'CN' => 'China', 'JP' => 'Japan', 'KR' => 'South Korea',
            'EG' => 'Egypt', 'MA' => 'Morocco', 'TN' => 'Tunisia', 'JO' => 'Jordan',
            'LB' => 'Lebanon', 'IQ' => 'Iraq', 'SA' => 'Saudi Arabia', 'QA' => 'Qatar',
            'KW' => 'Kuwait', 'BH' => 'Bahrain', 'OM' => 'Oman', 'SY' => 'Syria',
            'AM' => 'Armenia', 'AZ' => 'Azerbaijan', 'GE' => 'Georgia',
            'RU' => 'Russia', 'UA' => 'Ukraine', 'NL' => 'Netherlands',
            'BE' => 'Belgium', 'CH' => 'Switzerland', 'AT' => 'Austria',
            'PT' => 'Portugal', 'SE' => 'Sweden', 'NO' => 'Norway', 'DK' => 'Denmark',
            'FI' => 'Finland', 'PL' => 'Poland', 'CZ' => 'Czech Republic',
            'HU' => 'Hungary', 'RO' => 'Romania', 'BG' => 'Bulgaria',
            'HR' => 'Croatia', 'RS' => 'Serbia', 'BR' => 'Brazil', 'AR' => 'Argentina',
            'MX' => 'Mexico', 'CA' => 'Canada', 'AU' => 'Australia', 'NZ' => 'New Zealand',
            'ZA' => 'South Africa', 'KE' => 'Kenya', 'VN' => 'Vietnam',
            'PH' => 'Philippines', 'SG' => 'Singapore', 'PK' => 'Pakistan',
            'BD' => 'Bangladesh', 'LK' => 'Sri Lanka', 'NP' => 'Nepal',
            'MV' => 'Maldives', 'IL' => 'Israel', 'CY' => 'Cyprus', 'MT' => 'Malta',
        ];

        return $countries[$code] ?? $code;
    }

    /* ========================================================================
       Normalize Time Format
       ======================================================================== */
    private function normalize_time(string $time_str): string {
        if (empty($time_str)) return '';

        $time_str = trim($time_str);

        $timestamp = strtotime($time_str);

        if ($timestamp !== false) {
            return date('H:i', $timestamp);
        }

        return $time_str;
    }

    /* ========================================================================
       Extract Images
       ======================================================================== */
    private function extract_images(array $images): array {
        $result = [];

        foreach ($images as $img) {
            if (is_string($img)) {
                $result[] = $img;
            } elseif (is_array($img)) {
                if (!empty($img['original'])) {
                    $result[] = $img['original'];
                } elseif (!empty($img['thumbnail'])) {
                    $result[] = $img['thumbnail'];
                }
            }
        }

        return array_filter($result);
    }

    /* ========================================================================
       Get Full Hotel Details (For Future)
       ======================================================================== */
    public function get_hotel_details($property_token) {
        return [];
    }
}