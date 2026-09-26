<?php

namespace NextSafar\Flights;

use NextSafar\Admin\LiveSearch;

add_action('rest_api_init', function () {
    
    /**
     * Endpoint دیباگ کامل - تمام مراحل را نشان می‌دهد
     */
    register_rest_route('nextsafar/v1', '/flights/debug', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function (\WP_REST_Request $req) {
            $results = [];
            
            // 1. بررسی تنظیمات
            $provider = LiveSearch::get_provider();
            $key = LiveSearch::get_active_key();
            $rate = LiveSearch::get_usd_rate();
            
            $results['step_1_config'] = [
                'provider' => $provider,
                'key_set' => $key !== '',
                'key_preview' => $key ? substr($key, 0, 8) . '...' : '(empty)',
                'usd_rate' => $rate,
            ];
            
            if ($key === '') {
                $results['error'] = 'کلید API تنظیم نشده است. به NextSafar → جستجوی زنده بروید.';
                return new \WP_REST_Response($results, 200);
            }
            
            // 2. پارامترهای تست
            $from = strtoupper($req->get_param('from') ?? 'THR');
            $to = strtoupper($req->get_param('to') ?? 'MHD');
            $date = $req->get_param('date') ?? date('Y-m-d', strtotime('+7 days'));
            
            $results['step_2_params'] = [
                'from' => $from,
                'to' => $to,
                'date' => $date,
            ];
            
            // 3. ساخت پارامترهای SerpApi
            $cabin_map = [
                'economy' => 'Economy',
                'premium_economy' => 'Premium economy',
                'business' => 'Business',
                'first' => 'First',
            ];

            $params = [
                'engine' => 'google_flights',
                'departure_id' => $from,
                'arrival_id' => $to,
                'outbound_date' => $date,
                'type' => 2, // ✅ اضافه کنید
                'travel_class' => 'Economy',
                'adults' => 1,
                'currency' => 'USD',
                'api_key' => $key,
            ];

            $url = 'https://serpapi.com/search.json';
            $full_url = $url . '?' . http_build_query($params);
            
            $results['step_3_request'] = [
                'url' => substr($full_url, 0, 200) . '...',
                'params' => $params,
            ];
            
            // 4. ارسال درخواست به SerpApi
            $start_time = microtime(true);
            
            $response = wp_remote_get($url . '?' . http_build_query($params), [
                'timeout' => 45,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);
            
            $duration_ms = round((microtime(true) - $start_time) * 1000, 2);
            
            $results['step_4_response'] = [
                'duration_ms' => $duration_ms,
            ];
            
            if (is_wp_error($response)) {
                $results['step_4_response']['error'] = 'Network Error: ' . $response->get_error_message();
                return new \WP_REST_Response($results, 200);
            }
            
            $http_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            $results['step_4_response']['http_code'] = $http_code;
            $results['step_4_response']['body_length'] = strlen($body);
            
            if ($http_code !== 200) {
                $results['step_4_response']['error'] = 'HTTP Error';
                $results['step_4_response']['body_preview'] = substr($body, 0, 1000);
                
                $err_data = json_decode($body, true);
                if ($err_data && isset($err_data['error'])) {
                    $results['step_4_response']['api_error'] = $err_data['error'];
                }
                
                return new \WP_REST_Response($results, 200);
            }
            
            // 5. پارس پاسخ
            $data = json_decode($body, true);
            
            if (!$data) {
                $results['step_5_parse']['error'] = 'JSON parse failed';
                $results['step_5_parse']['body_preview'] = substr($body, 0, 1000);
                return new \WP_REST_Response($results, 200);
            }
            
            $results['step_5_parse'] = [
                'success' => true,
                'top_level_keys' => array_keys($data),
            ];
            
            // 6. بررسی ساختار پاسخ
            $results['step_6_structure'] = [];
            
            if (isset($data['search_metadata'])) {
                $results['step_6_structure']['search_metadata'] = [
                    'id' => $data['search_metadata']['id'] ?? null,
                    'status' => $data['search_metadata']['status'] ?? null,
                    'total_time_taken' => $data['search_metadata']['total_time_taken'] ?? null,
                ];
            }
            
            if (isset($data['search_parameters'])) {
                $results['step_6_structure']['search_parameters'] = $data['search_parameters'];
            }
            
            $best_count = count($data['best_flights'] ?? []);
            $other_count = count($data['other_flights'] ?? []);
            
            $results['step_6_structure']['flights_summary'] = [
                'best_flights_count' => $best_count,
                'other_flights_count' => $other_count,
                'total_count' => $best_count + $other_count,
            ];
            
            // 7. بررسی ساختار اولین پرواز
            if ($best_count > 0) {
                $first_flight = $data['best_flights'][0];
                $results['step_7_first_flight'] = [
                    'top_level_keys' => array_keys($first_flight),
                    'price' => $first_flight['price'] ?? null,
                    'total_duration' => $first_flight['total_duration'] ?? null,
                    'flights_count' => count($first_flight['flights'] ?? []),
                ];
                
                if (!empty($first_flight['flights'])) {
                    $first_segment = $first_flight['flights'][0];
                    $results['step_7_first_flight']['first_segment_keys'] = array_keys($first_segment);
                    $results['step_7_first_flight']['departure_airport'] = $first_segment['departure_airport'] ?? null;
                    $results['step_7_first_flight']['arrival_airport'] = $first_segment['arrival_airport'] ?? null;
                }
            }
            
            // 8. تست normalize
            try {
                $flights = LiveSearch::normalize_serpapi_flights($data, [
                    'origin' => $from,
                    'dest' => $to,
                    'date' => $date,
                ]);
                
                $results['step_8_normalize'] = [
                    'success' => true,
                    'flights_count' => count($flights),
                    'first_flight' => !empty($flights) ? $flights[0] : null,
                ];
            } catch (\Exception $e) {
                $results['step_8_normalize'] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
            
            return new \WP_REST_Response($results, 200);
        },
    ]);
    
    /**
     * پاک کردن کش پرواز
     */
    register_rest_route('nextsafar/v1', '/flights/clear-cache', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function () {
            global $wpdb;
            
            // حذف همه transient های مربوط به پرواز
            $deleted = $wpdb->query(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ns_flight_%' OR option_name LIKE '_transient_timeout_ns_flight_%'"
            );
            
            return new \WP_REST_Response([
                'ok' => true,
                'message' => "کش پرواز پاک شد. {$deleted} آیتم حذف شد.",
            ], 200);
        },
    ]);
    
    /**
     * تست مستقیم بدون کش
     */
    register_rest_route('nextsafar/v1', '/flights/test-direct', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function (\WP_REST_Request $req) {
            $from = strtoupper($req->get_param('from') ?? 'THR');
            $to = strtoupper($req->get_param('to') ?? 'MHD');
            $date = $req->get_param('date') ?? date('Y-m-d', strtotime('+7 days'));
            
            // فراخوانی مستقیم بدون کش
            $flights = LiveSearch::search_flights([
                'origin' => $from,
                'dest' => $to,
                'date' => $date,
                'return_date' => '',
                'trip_type' => 'one_way',
                'cabin' => 'economy',
                'adults' => 1,
                'children' => 0,
            ]);
            
            if (is_wp_error($flights)) {
                return new \WP_REST_Response([
                    'ok' => false,
                    'error' => $flights->get_error_code(),
                    'message' => $flights->get_error_message(),
                ], 500);
            }
            
            return new \WP_REST_Response([
                'ok' => true,
                'route' => "$from → $to",
                'date' => $date,
                'count' => count($flights),
                'flights' => $flights,
            ], 200);
        },
    ]);
});