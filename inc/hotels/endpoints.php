<?php

namespace NextSafar\Hotels;

use NextSafar\Admin\LiveSearch;
use function NextSafar\Search\search_hotels;
use function NextSafar\Search\live_config;

add_action('rest_api_init', function () {

    /* ========================================================================
       Hotel Search with Smart Matching (Uses matcher.php)
       ======================================================================== */
    register_rest_route('nextsafar/v1', '/hotels/search', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function (\WP_REST_Request $req) {
            $city      = sanitize_text_field($req->get_param('city') ?? '');
            $q_en      = sanitize_text_field($req->get_param('q_en') ?? '');
            $check_in  = sanitize_text_field($req->get_param('check_in') ?? '');
            $check_out = sanitize_text_field($req->get_param('check_out') ?? '');
            $adults    = intval($req->get_param('adults') ?? 2);
            $children  = intval($req->get_param('children') ?? 0);

            if (empty($city) || empty($check_in) || empty($check_out)) {
                return new \WP_Error('missing_params', 'شهر و تاریخ‌ها الزامی هستند', ['status' => 400]);
            }

            /* Convert Jalali to Gregorian */
            $check_in_greg  = LiveSearch::jalali_to_gregorian($check_in) ?? $check_in;
            $check_out_greg = LiveSearch::jalali_to_gregorian($check_out) ?? $check_out;

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $check_in_greg) ||
                !preg_match('/^\d{4}-\d{2}-\d{2}$/', $check_out_greg)) {
                return new \WP_Error('invalid_date', 'تاریخ نامعتبر است', ['status' => 400]);
            }

            $date1  = new \DateTime($check_in_greg);
            $date2  = new \DateTime($check_out_greg);
            $nights = max(1, $date1->diff($date2)->days);

            $cfg = ns_live_config();

            /* Cache */
            $cache_key = "ns_hotel_merged_v5_{$cfg['provider']}_" .
                md5("{$city}|{$check_in_greg}|{$check_out_greg}|{$adults}|{$children}");

            $cached = get_transient($cache_key);

            if ($cached !== false) {
                return rest_ensure_response(array_merge($cached, ['from_cache' => true]));
            }

            /* Site hotels */
            $site_hotels = ns_hotel_site_list($city);
            error_log("[NS Hotels] Site hotels for '$city': " . count($site_hotels));

            /* Online search */
            $online_hotels = [];
            $is_stale      = false;

            try {
                $hotels_result = ns_search_hotels([
                    'q'         => $city,
                    'q_en'      => $q_en ?: $city,
                    'check_in'  => $check_in_greg,
                    'check_out' => $check_out_greg,
                    'adults'    => $adults,
                    'children'  => $children,
                ]);

                if (is_wp_error($hotels_result)) {
                    error_log('[NS Hotels] Search failed: ' . $hotels_result->get_error_message());
                    $is_stale = true;
                } else {
                    $online_hotels = $hotels_result;
                }
            } catch (\Throwable $e) {
                error_log('[NS Hotels] Exception: ' . $e->getMessage());
                $is_stale = true;
            }

            error_log('[NS Hotels] Online hotels: ' . count($online_hotels));

            /* Match and merge */
            try {
                $merged = ns_hotels_merge($site_hotels, $online_hotels, $cfg['provider']);
            } catch (\Throwable $e) {
                error_log('[NS Hotels] Merge error: ' . $e->getMessage());
                return new \WP_Error('merge_error', $e->getMessage(), ['status' => 500]);
            }

            error_log("[NS Hotels] Merged: site={$merged['site_count']}, online={$merged['online_count']}, matched={$merged['matched']}");

            /* Response */
            $response_data = [
                'ok'       => true,
                'items'    => $merged['items'],
                'provider' => $cfg['provider'],
                'meta'     => [
                    'nights'       => $nights,
                    'site_count'   => $merged['site_count'],
                    'online_count' => $merged['online_count'],
                    'matched'      => $merged['matched'],
                    'stale'        => $is_stale,
                ],
            ];

            set_transient($cache_key, $response_data, 2 * HOUR_IN_SECONDS);

            return rest_ensure_response(array_merge($response_data, ['from_cache' => false]));
        },
    ]);

    /* ========================================================================
       Online Hotel Details (For Dedicated Page)
       ======================================================================== */
    register_rest_route('nextsafar/v1', '/hotels/details', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function (\WP_REST_Request $req) {
            $token     = sanitize_text_field($req->get_param('token') ?? '');
            $check_in  = sanitize_text_field($req->get_param('check_in') ?? '');
            $check_out = sanitize_text_field($req->get_param('check_out') ?? '');
            $adults    = intval($req->get_param('adults') ?? 2);
            $name      = sanitize_text_field($req->get_param('name') ?? 'Hotels');

            if ($token === '') {
                return new \WP_Error('missing_token', 'توکن هتل الزامی است', ['status' => 400]);
            }

            if ($check_in === '')  $check_in  = date('Y-m-d', strtotime('+30 days'));
            if ($check_out === '') $check_out = date('Y-m-d', strtotime('+31 days'));

            $ci = LiveSearch::jalali_to_gregorian($check_in)  ?? $check_in;
            $co = LiveSearch::jalali_to_gregorian($check_out) ?? $check_out;

            $cache_key = 'ns_hotel_details_' . md5($token . $ci . $co . $adults);
            $cached    = get_transient($cache_key);

            if ($cached !== false) {
                return rest_ensure_response(['ok' => true, 'hotel' => $cached, 'from_cache' => true]);
            }

            $details = LiveSearch::hotel_details([
                'token'      => $token,
                'hotel_name' => $name,
                'check_in'   => $ci,
                'check_out'  => $co,
                'adults'     => $adults,
            ]);

            if (is_wp_error($details)) {
                return new \WP_REST_Response([
                    'ok'    => false,
                    'error' => $details->get_error_message(),
                ], 500);
            }

            set_transient($cache_key, $details, 2 * HOUR_IN_SECONDS);

            return rest_ensure_response(['ok' => true, 'hotel' => $details, 'from_cache' => false]);
        },
    ]);

    /* ========================================================================
       Clear Cache
       ======================================================================== */
    register_rest_route('nextsafar/v1', '/hotels/clear-cache', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            global $wpdb;

            $deleted = $wpdb->query(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_ns_hotel_%' OR option_name LIKE '%_transient_timeout_ns_hotel_%'"
            );

            return new \WP_REST_Response([
                'ok'      => true,
                'message' => "کش هتل پاک شد. {$deleted} آیتم حذف شد.",
            ], 200);
        },
    ]);

    /* ========================================================================
       Test
       ======================================================================== */
    register_rest_route('nextsafar/v1', '/hotels/test-search', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function (\WP_REST_Request $req) {
            $city      = sanitize_text_field($req->get_param('city') ?? 'Istanbul');
            $check_in  = date('Y-m-d', strtotime('+30 days'));
            $check_out = date('Y-m-d', strtotime('+32 days'));
            $cfg       = ns_live_config();

            $site_hotels = ns_hotel_site_list($city);

            $online_hotels = ns_search_hotels([
                'q'         => $city,
                'q_en'      => $city,
                'check_in'  => $check_in,
                'check_out' => $check_out,
                'adults'    => 2,
                'children'  => 0,
            ]);

            if (is_wp_error($online_hotels)) $online_hotels = [];

            $merged = ns_hotels_merge($site_hotels, $online_hotels, $cfg['provider']);

            return rest_ensure_response([
                'ok'           => true,
                'city'         => $city,
                'site_count'   => $merged['site_count'],
                'online_count' => $merged['online_count'],
                'matched'      => $merged['matched'],
                'first_hotel'  => $merged['items'][0] ?? null,
                'provider'     => $cfg['provider'],
            ]);
        },
    ]);
});