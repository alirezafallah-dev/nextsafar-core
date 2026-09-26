<?php

namespace NextSafar\Search;

use NextSafar\Admin\LiveSearch;

/**
 * Wrapper functions for endpoint integration.
 *
 * This file acts only as a bridge to the LiveSearch service.
 * All business logic is handled by the LiveSearch class.
 */

/**
 * Get the current live search configuration.
 *
 * @return array {
 *     @type string $provider Active search provider.
 *     @type string $key      Active API key.
 * }
 */
function ns_live_config(): array {
    return [
        'provider' => LiveSearch::get_provider(),
        'key'      => LiveSearch::get_active_key(),
    ];
}

/**
 * Search flights.
 *
 * @param array $args {
 *     @type string $origin      Origin IATA code, e.g. THR.
 *     @type string $dest        Destination IATA code, e.g. MHD.
 *     @type string $date        Departure date, YYYY-MM-DD.
 *     @type string $return_date Return date, YYYY-MM-DD. Optional.
 *     @type string $trip_type   one_way | round_trip.
 *     @type string $cabin       economy | premium_economy | business | first.
 *     @type int    $adults      Number of adults.
 *     @type int    $children    Number of children.
 * }
 *
 * @return array|\WP_Error Flights list or WP_Error on failure.
 */
function ns_search_flights(array $args): array|\WP_Error {
    return LiveSearch::search_flights($args);
}

/**
 * Search hotels.
 *
 * @param array $args {
 *     @type string $q         City name in Persian.
 *     @type string $q_en      City name in English. Optional.
 *     @type string $check_in  Check-in date, YYYY-MM-DD.
 *     @type string $check_out Check-out date, YYYY-MM-DD.
 *     @type int    $adults    Number of adults.
 *     @type int    $children  Number of children.
 * }
 *
 * @return array|\WP_Error Hotels list or WP_Error on failure.
 */
function ns_search_hotels(array $args): array|\WP_Error {
    return LiveSearch::search_hotels($args);
}