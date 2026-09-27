<?php
/**
 * NextSafar Core - Flight REST API Endpoints
 * 
 * Uses the new FlightSearchService for cleaner architecture.
 * 
 * @package NextSafar\Flights
 * @since   2.6.0
 */

namespace NextSafar\Flights;

use NextSafar\Core\RateLimiter;
use NextSafar\Core\ErrorHandler;
use NextSafar\Core\Logger;
use NextSafar\Search\FlightSearchService;
use NextSafar\Search\ProviderFactory;
use NextSafar\Search\DateConverter;
use NextSafar\Search\AirportMapper;
use NextSafar\Core\Validator;

add_action('rest_api_init', function () {

    /* ========================================================================
       Flight Search Endpoint
       Endpoint: GET /nextsafar/v1/flights/search
    ======================================================================== */
    register_rest_route('nextsafar/v1', '/flights/search', [
        'methods'             => 'GET',
        'permission_callback' => RateLimiter::middleware('flight_search'),
        'callback'            => function (\WP_REST_Request $req) {

            // Step 1: Input validation
            $validation = Validator::validate_flight_search($req);
            if (is_wp_error($validation)) {
                return ErrorHandler::to_response($validation);
            }

            $origin      = strtoupper(sanitize_text_field($req->get_param('origin') ?? ''));
            $dest        = strtoupper(sanitize_text_field($req->get_param('dest') ?? ''));
            $date        = sanitize_text_field($req->get_param('date') ?? '');
            $return_date = sanitize_text_field($req->get_param('return_date') ?? '');
            $trip_type   = sanitize_text_field($req->get_param('trip_type') ?? 'one_way');
            $cabin       = sanitize_text_field($req->get_param('cabin') ?? 'economy');
            $adults      = intval($req->get_param('adults') ?? 1);
            $children    = intval($req->get_param('children') ?? 0);

            // Step 2: Convert dates
            $date_greg = DateConverter::jalali_to_gregorian($date) ?? $date;
            $return_greg = $return_date !== '' 
                ? (DateConverter::jalali_to_gregorian($return_date) ?? $return_date) 
                : '';

            // Step 3: Search flights using new Service Layer
            $result = FlightSearchService::search([
                'origin'      => $origin,
                'dest'        => $dest,
                'date'        => $date_greg,
                'return_date' => $return_greg,
                'trip_type'   => $trip_type,
                'cabin'       => $cabin,
                'adults'      => $adults,
                'children'    => $children,
            ]);

            if (is_wp_error($result)) {
                return ErrorHandler::to_response($result);
            }

            $provider_name = ProviderFactory::get_active_provider_name();

            Logger::info('Flight search completed', [
                'origin'   => $origin,
                'dest'     => $dest,
                'count'    => count($result),
                'provider' => $provider_name,
            ]);

            return rest_ensure_response([
                'ok'       => true,
                'flights'  => $result,
                'count'    => count($result),
                'provider' => $provider_name,
                'meta'     => [
                    'origin'      => $origin,
                    'origin_city' => AirportMapper::city_fa($origin),
                    'dest'        => $dest,
                    'dest_city'   => AirportMapper::city_fa($dest),
                    'date'        => $date_greg,
                    'trip_type'   => $trip_type,
                    'cabin'       => $cabin,
                    'adults'      => $adults,
                    'children'    => $children,
                ],
            ]);
        },
    ]);

    /* ========================================================================
       Clear Flight Cache (Admin Only)
       Endpoint: POST /nextsafar/v1/flights/clear-cache
    ======================================================================== */
    register_rest_route('nextsafar/v1', '/flights/clear-cache', [
        'methods'             => 'POST',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
        'callback'            => function () {
            $deleted = FlightSearchService::clear_cache();

            return new \WP_REST_Response([
                'ok'      => true,
                'message' => "کش پرواز پاک شد. {$deleted} آیتم حذف شد.",
            ], 200);
        },
    ]);

    /* ========================================================================
       Test Flight Search (Development Only)
       Endpoint: GET /nextsafar/v1/flights/test-search
    ======================================================================== */
    register_rest_route('nextsafar/v1', '/flights/test-search', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function (\WP_REST_Request $req) {

            if (!defined('WP_DEBUG') || !WP_DEBUG) {
                return ErrorHandler::to_response(
                    ErrorHandler::forbidden('این اندپوینت فقط در حالت توسعه در دسترس است.')
                );
            }

            $origin = strtoupper(sanitize_text_field($req->get_param('origin') ?? 'THR'));
            $dest   = strtoupper(sanitize_text_field($req->get_param('dest') ?? 'MHD'));
            $date   = date('Y-m-d', strtotime('+7 days'));

            $result = FlightSearchService::search([
                'origin'    => $origin,
                'dest'      => $dest,
                'date'      => $date,
                'trip_type' => 'one_way',
                'cabin'     => 'economy',
                'adults'    => 1,
                'children'  => 0,
            ]);

            if (is_wp_error($result)) {
                return ErrorHandler::to_response($result);
            }

            return rest_ensure_response([
                'ok'       => true,
                'origin'   => $origin,
                'dest'     => $dest,
                'date'     => $date,
                'count'    => count($result),
                'flights'  => array_slice($result, 0, 5), // First 5 flights
                'provider' => ProviderFactory::get_active_provider_name(),
            ]);
        },
    ]);

    /* ========================================================================
       Airport List Endpoint (for autocomplete)
       Endpoint: GET /nextsafar/v1/flights/airports
    ======================================================================== */
    register_rest_route('nextsafar/v1', '/flights/airports', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function (\WP_REST_Request $req) {
            $limit = intval($req->get_param('limit') ?? 100);
            $airports = AirportMapper::get_all_airports($limit);

            return rest_ensure_response([
                'ok'       => true,
                'airports' => $airports,
                'count'    => count($airports),
            ]);
        },
    ]);
});