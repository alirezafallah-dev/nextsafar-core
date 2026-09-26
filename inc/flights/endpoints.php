<?php

namespace NextSafar\Flights;

use NextSafar\Admin\LiveSearch;
use function NextSafar\Search\search\search_flights;
use function NextSafar\Search\search\live_config;

add_action('rest_api_init', function () {

    /* ========================================================================
       1) Flight Search
       ======================================================================== */
    register_rest_route('nextsafar/v1', '/flights/search', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function (\WP_REST_Request $req) {
            $origin_code = strtoupper(sanitize_text_field($req->get_param('originCode') ?? ''));
            $dest_code = strtoupper(sanitize_text_field($req->get_param('destCode') ?? ''));
            $date_raw = sanitize_text_field($req->get_param('date') ?? '');
            $return_raw = sanitize_text_field($req->get_param('returnDate') ?? '');
            $cabin = sanitize_text_field($req->get_param('cabin') ?? 'economy');
            $adults = max(1, min(9, (int) ($req->get_param('adults') ?? 1)));
            $children = max(0, min(8, (int) ($req->get_param('children') ?? 0)));

            if (!$origin_code || !$dest_code || !$date_raw) {
                return new \WP_REST_Response(['ok' => false, 'error' => 'missing_params'], 400);
            }

            if (!preg_match('/^[A-Z]{3}$/', $origin_code) || !preg_match('/^[A-Z]{3}$/', $dest_code)) {
                return new \WP_REST_Response(['ok' => false, 'error' => 'invalid_code'], 400);
            }

            $outbound = LiveSearch::jalali_to_gregorian($date_raw);

            if (!$outbound) {
                return new \WP_REST_Response(['ok' => false, 'error' => 'invalid_date'], 400);
            }

            if (strtotime($outbound) < strtotime(date('Y-m-d'))) {
                return new \WP_REST_Response(['ok' => false, 'error' => 'past_date'], 400);
            }

            $trip_type = 'one_way';
            $return_date = '';

            if ($return_raw !== '') {
                $return_date = LiveSearch::jalali_to_gregorian($return_raw);

                if (!$return_date || strtotime($return_date) < strtotime($outbound)) {
                    return new \WP_REST_Response(['ok' => false, 'error' => 'invalid_return_date'], 400);
                }

                $trip_type = 'round_trip';
            }

            /* Cache with provider name */
            $provider = LiveSearch::get_provider();

            $cache_key = "ns_flight_{$provider}_{$origin_code}_{$dest_code}_{$outbound}"
                . ($return_date ? "_{$return_date}" : '_ow')
                . "_{$cabin}_{$adults}_{$children}";

            $cached = get_transient($cache_key);

            if ($cached !== false) {
                return new \WP_REST_Response([
                    'ok' => true,
                    'items' => $cached,
                    'from_cache' => true,
                    'provider' => $provider,
                ], 200);
            }

            $flights = search_flights([
                'origin' => $origin_code,
                'dest' => $dest_code,
                'date' => $outbound,
                'return_date' => $return_date,
                'trip_type' => $trip_type,
                'cabin' => $cabin,
                'adults' => $adults,
                'children' => $children,
            ]);

            if (is_wp_error($flights)) {
                return new \WP_REST_Response([
                    'ok' => false,
                    'error' => 'api_error',
                    'message' => $flights->get_error_message(),
                ], 500);
            }

            set_transient($cache_key, $flights, 2 * HOUR_IN_SECONDS);

            return new \WP_REST_Response([
                'ok' => true,
                'items' => $flights,
                'from_cache' => false,
                'provider' => $provider,
            ], 200);
        },
    ]);

    /* ========================================================================
       2) Status Test
       ======================================================================== */
    register_rest_route('nextsafar/v1', '/flights/test-api', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            $live_key = LiveSearch::get_active_key();

            return new \WP_REST_Response([
                'ok' => true,
                'live_provider' => LiveSearch::get_provider(),
                'live_key_set' => $live_key !== '',
                'live_key_preview' => $live_key ? substr($live_key, 0, 6) . '...' : null,
            ], 200);
        },
    ]);

    /* ========================================================================
       3) Date Test
       ======================================================================== */
    register_rest_route('nextsafar/v1', '/flights/test-date', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function (\WP_REST_Request $req) {
            $input = sanitize_text_field($req->get_param('date') ?? '1405/07/10');

            return new \WP_REST_Response([
                'ok' => true,
                'input' => $input,
                'output' => LiveSearch::jalali_to_gregorian($input),
            ], 200);
        },
    ]);

    /* ========================================================================
       4) Direct Test
       ======================================================================== */
    register_rest_route('nextsafar/v1', '/flights/test-search', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function (\WP_REST_Request $req) {
            $from = strtoupper(sanitize_text_field($req->get_param('from') ?? 'JFK'));
            $to = strtoupper(sanitize_text_field($req->get_param('to') ?? 'MAD'));
            $date = sanitize_text_field($req->get_param('date') ?? '');

            if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $date = LiveSearch::jalali_to_gregorian($date ?: '1405/08/15')
                    ?? date('Y-m-d', strtotime('+30 days'));
            }

            $flights = search_flights([
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
                    'error' => 'api_error',
                    'message' => $flights->get_error_message(),
                ], 500);
            }

            return new \WP_REST_Response([
                'ok' => true,
                'provider' => LiveSearch::get_provider(),
                'route' => "$from → $to",
                'date' => $date,
                'count' => count($flights),
                'first_three' => array_slice($flights, 0, 3),
            ], 200);
        },
    ]);

    /* ========================================================================
       5) Clear Flight Cache
       ======================================================================== */
    register_rest_route('nextsafar/v1', '/flights/clear-cache', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function () {
            global $wpdb;

            $deleted = $wpdb->query(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_ns_flight_%' OR option_name LIKE '%_transient_timeout_ns_flight_%'"
            );

            return new \WP_REST_Response([
                'ok' => true,
                'message' => "کش پرواز پاک شد. {$deleted} آیتم حذف شد.",
            ], 200);
        },
    ]);
});