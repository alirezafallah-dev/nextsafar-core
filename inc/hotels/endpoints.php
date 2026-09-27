<?php
/**
 * NextSafar Core - Hotel REST API Endpoints
 * 
 * Uses the new Service Layer architecture:
 * - HotelSearchService for search orchestration
 * - ProviderFactory for automatic fallback
 * - RateLimiter for abuse prevention
 * - Validator for input validation
 * 
 * @package NextSafar\Hotels
 * @since   2.6.0
 */

namespace NextSafar\Hotels;

use NextSafar\Admin\LiveSearch;
use NextSafar\Core\RateLimiter;
use NextSafar\Core\Validator;
use NextSafar\Core\ErrorHandler;
use NextSafar\Core\Logger;
use NextSafar\Search\HotelSearchService;
use NextSafar\Search\FlightSearchService;
use NextSafar\Search\ProviderFactory;
use NextSafar\Search\DateConverter;
use NextSafar\Search\PriceConverter;

add_action('rest_api_init', function () {

    /* ========================================================================
       Hotel Search with Smart Matching + Rate Limiting + Validation
       Endpoint: GET /nextsafar/v1/hotels/search
       
       Uses the new Service Layer for cleaner architecture.
    ======================================================================== */
    register_rest_route('nextsafar/v1', '/hotels/search', [
        'methods'             => 'GET',
        'permission_callback' => RateLimiter::middleware('hotel_search'),
        'callback'            => function (\WP_REST_Request $req) {

            // Step 1: Input validation
            $validation = Validator::validate_hotel_search($req);
            if (is_wp_error($validation)) {
                return ErrorHandler::to_response($validation);
            }

            $city       = sanitize_text_field($req->get_param('city') ?? '');
            $q_en       = sanitize_text_field($req->get_param('q_en') ?? '');
            $check_in   = sanitize_text_field($req->get_param('check_in') ?? '');
            $check_out  = sanitize_text_field($req->get_param('check_out') ?? '');
            $adults     = intval($req->get_param('adults') ?? 2);
            $children   = intval($req->get_param('children') ?? 0);

            // Step 2: Convert Jalali to Gregorian dates
            $check_in_greg  = DateConverter::jalali_to_gregorian($check_in) ?? $check_in;
            $check_out_greg = DateConverter::jalali_to_gregorian($check_out) ?? $check_out;

            // Step 3: Validate date format after conversion
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $check_in_greg) ||
                !preg_match('/^\d{4}-\d{2}-\d{2}$/', $check_out_greg)) {
                return ErrorHandler::to_response(ErrorHandler::invalid_date($check_in));
            }

            // Step 4: Calculate nights
            $nights = DateConverter::calculate_nights($check_in_greg, $check_out_greg);

            // Step 5: Get active provider name
            $provider_name = ProviderFactory::get_active_provider_name();

            // Step 6: Check cache
            $cache_key = "ns_hotel_merged_v8_{$provider_name}_" .
                md5("{$city}|{$check_in_greg}|{$check_out_greg}|{$adults}|{$children}");

            $cached = get_transient($cache_key);
            if ($cached !== false) {
                Logger::debug('Hotel search cache hit', [
                    'city'      => $city,
                    'cache_key' => $cache_key,
                ]);
                return rest_ensure_response(array_merge($cached, ['from_cache' => true]));
            }

            // Step 7: Get hotels from local WordPress database
            $site_hotels = ns_hotel_site_list($city);
            Logger::debug('Site hotels fetched', [
                'city'  => $city,
                'count' => count($site_hotels),
            ]);

            // Step 8: Online search using new Service Layer
            $online_hotels = [];
            $is_stale      = false;

            $search_result = HotelSearchService::search([
                'q'         => $city,
                'q_en'      => $q_en ?: $city,
                'check_in'  => $check_in_greg,
                'check_out' => $check_out_greg,
                'adults'    => $adults,
                'children'  => $children,
            ]);

            if (is_wp_error($search_result)) {
                Logger::warning('Hotel search failed', [
                    'city'  => $city,
                    'error' => $search_result->get_error_message(),
                ]);
                $is_stale = true;
            } else {
                $online_hotels = $search_result;
            }

            // Step 9: Match and merge local + online hotels
            try {
                $merged = ns_hotels_merge($site_hotels, $online_hotels, $provider_name);
            } catch (\Throwable $e) {
                Logger::critical('Hotel merge failed', [
                    'city'    => $city,
                    'message' => $e->getMessage(),
                ]);
                return ErrorHandler::to_response(
                    ErrorHandler::server_error('خطا در ادغام نتایج جستجو.')
                );
            }

            Logger::info('Hotel search completed', [
                'city'         => $city,
                'site_count'   => $merged['site_count'],
                'online_count' => $merged['online_count'],
                'matched'      => $merged['matched'],
                'stale'        => $is_stale,
            ]);

            // Step 10: Build final response
            $response_data = [
                'ok'       => true,
                'items'    => $merged['items'],
                'provider' => $provider_name,
                'meta'     => [
                    'nights'       => $nights,
                    'site_count'   => $merged['site_count'],
                    'online_count' => $merged['online_count'],
                    'matched'      => $merged['matched'],
                    'stale'        => $is_stale,
                ],
            ];

            // Step 11: Cache the response for 2 hours
            set_transient($cache_key, $response_data, 2 * HOUR_IN_SECONDS);

            $response = rest_ensure_response(array_merge($response_data, ['from_cache' => false]));
            return RateLimiter::add_headers($response, 'hotel_search');
        },
    ]);

    /* ========================================================================
       Hotel Details Endpoint
       Endpoint: GET /nextsafar/v1/hotels/details
    ======================================================================== */
    register_rest_route('nextsafar/v1', '/hotels/details', [
        'methods'             => 'GET',
        'permission_callback' => RateLimiter::middleware('hotel_details'),
        'callback'            => function (\WP_REST_Request $req) {

            // Input validation
            $validation = Validator::validate_hotel_details($req);
            if (is_wp_error($validation)) {
                return ErrorHandler::to_response($validation);
            }

            $token     = sanitize_text_field($req->get_param('token') ?? '');
            $check_in  = sanitize_text_field($req->get_param('check_in') ?? '');
            $check_out = sanitize_text_field($req->get_param('check_out') ?? '');
            $adults    = intval($req->get_param('adults') ?? 2);
            $name      = sanitize_text_field($req->get_param('name') ?? 'Hotels');

            // Default dates if not provided
            if ($check_in === '') $check_in = date('Y-m-d', strtotime('+30 days'));
            if ($check_out === '') $check_out = date('Y-m-d', strtotime('+31 days'));

            $ci = DateConverter::jalali_to_gregorian($check_in) ?? $check_in;
            $co = DateConverter::jalali_to_gregorian($check_out) ?? $check_out;

            // Use new Service Layer for details
            $details = HotelSearchService::get_details([
                'token'      => $token,
                'hotel_name' => $name,
                'check_in'   => $ci,
                'check_out'  => $co,
                'adults'     => $adults,
            ]);

            if (is_wp_error($details)) {
                return ErrorHandler::to_response($details);
            }

            return rest_ensure_response([
                'ok'         => true,
                'hotel'      => $details,
                'from_cache' => false,
            ]);
        },
    ]);

    /* ========================================================================
       Clear Hotel Cache (Admin Only)
       Endpoint: POST /nextsafar/v1/hotels/clear-cache
    ======================================================================== */
    register_rest_route('nextsafar/v1', '/hotels/clear-cache', [
        'methods'             => 'POST',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
        'callback'            => function () {
            // Clear search cache using new service
            $search_deleted = HotelSearchService::clear_cache();
            
            // Clear site hotels cache
            clear_site_hotels_cache();
            
            // Clear merged results cache
            global $wpdb;
            $merged_deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                    '%\_transient\_ns\_hotel\_merged\_%',
                    '%\_transient\_timeout\_ns\_hotel\_merged\_%'
                )
            );

            $total = $search_deleted + (int) $merged_deleted;

            Logger::info('Hotel cache cleared', [
                'search_deleted' => $search_deleted,
                'merged_deleted' => $merged_deleted,
            ]);

            return new \WP_REST_Response([
                'ok'      => true,
                'message' => "کش هتل پاک شد. {$total} آیتم حذف شد.",
            ], 200);
        },
    ]);

    /* ========================================================================
       Test Search Endpoint (Development Only)
       Endpoint: GET /nextsafar/v1/hotels/test-search
    ======================================================================== */
    register_rest_route('nextsafar/v1', '/hotels/test-search', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function (\WP_REST_Request $req) {

            // Security: Only allow in development mode
            if (!defined('WP_DEBUG') || !WP_DEBUG) {
                return ErrorHandler::to_response(
                    ErrorHandler::forbidden('این اندپوینت فقط در حالت توسعه در دسترس است.')
                );
            }

            $city      = sanitize_text_field($req->get_param('city') ?? 'Istanbul');
            $check_in  = date('Y-m-d', strtotime('+30 days'));
            $check_out = date('Y-m-d', strtotime('+32 days'));

            $provider_name = ProviderFactory::get_active_provider_name();

            try {
                // Local hotels
                $site_hotels = ns_hotel_site_list($city);

                // Online hotels using new Service Layer
                $search_result = HotelSearchService::search([
                    'q'         => $city,
                    'q_en'      => $city,
                    'check_in'  => $check_in,
                    'check_out' => $check_out,
                    'adults'    => 2,
                    'children'  => 0,
                ]);

                $online_hotels = is_wp_error($search_result) ? [] : $search_result;

                // Merge
                $merged = ns_hotels_merge($site_hotels, $online_hotels, $provider_name);

                return rest_ensure_response([
                    'ok'           => true,
                    'city'         => $city,
                    'site_count'   => $merged['site_count'],
                    'online_count' => $merged['online_count'],
                    'matched'      => $merged['matched'],
                    'first_hotel'  => $merged['items'][0] ?? null,
                    'provider'     => $provider_name,
                ]);
            } catch (\Throwable $e) {
                return ErrorHandler::to_response(
                    ErrorHandler::handle_exception($e, 'test-search')
                );
            }
        },
    ]);

    /* ========================================================================
       Provider Status Endpoint (Admin Only)
       Endpoint: GET /nextsafar/v1/hotels/provider-status
       
       Shows which providers are configured and available.
    ======================================================================== */
    register_rest_route('nextsafar/v1', '/hotels/provider-status', [
        'methods'             => 'GET',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
        'callback'            => function () {
            $statuses = ProviderFactory::get_all_statuses();
            $active = ProviderFactory::get_active_provider_name();
            
            return rest_ensure_response([
                'ok'            => true,
                'active'        => $active,
                'providers'     => $statuses,
                'usd_rate'      => PriceConverter::get_usd_rate(),
            ]);
        },
    ]);
});