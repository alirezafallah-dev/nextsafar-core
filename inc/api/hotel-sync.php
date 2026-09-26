<?php

/**
 * HotelSync — Hotel synchronization from API sources
 *
 * @version 1.0.0
 */

namespace NextSafar\API;

use NextSafar\Sync\GeoSchema;
use NextSafar\Sync\GeoSync;
use NextSafar\MetaBoxes\HotelMetaBox;

if (!defined('ABSPATH')) exit;

class HotelSync {
    use \NextSafar\Sync\PlaceEnrichTrait;

    private $client;
    private $debug_info = [];

    /* ==========================================================================
       Constructor
       ========================================================================== */
    public function __construct($source = null) {
        $active_source = $source ?: get_option('nextsafar_active_source', 'searchapi');

        $this->debug_info['source'] = $active_source;

        if ($active_source === 'serpapi') {
            $key = get_option('nextsafar_serpapi_key', '');
            $this->client = new SerpApiClient($key);
        } else {
            $key = get_option('nextsafar_searchapi_key', '');
            $this->client = new SearchApiClient($key);
        }

        $this->debug_info['has_api_key'] = !empty($key);
    }

    public function get_debug_info() {
        return $this->debug_info;
    }

    /* ==========================================================================
       Sync Hotels from Data Source
       ========================================================================== */
    public function sync_hotels($location, $options = []) {
        $options['limit'] = max(1, min(50, (int) ($options['limit'] ?? 20)));

        error_log('🔍 Hotel sync limit: ' . $options['limit']);

        $details_limit = intval($options['details_limit'] ?? 20);

        /* ═══ Stage 1: Fetch List ═══ */
        $hotels = $this->client->search_hotels($location, $options);

        if (is_wp_error($hotels)) {
            return [
                'success' => false,
                'message' => $hotels->get_error_message(),
            ];
        }

        $results = [
            'success' => true,
            'total' => count($hotels),
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'details_fetched' => 0,
            'images_added' => 0,
            'errors' => [],
        ];

        $saved_ids = [];

        foreach ($hotels as $hotel_data) {
            $hotel_data['search_location'] = $location;

            try {
                $res = $this->save_hotel($hotel_data);

                if ($res === 'failed') {
                    $results['failed']++;
                    continue;
                }

                $results[$res]++;

                $post_id = $this->find_by_external_id($hotel_data['external_id']);

                if ($post_id) {
                    $saved_ids[] = $post_id;
                }
            } catch (\Throwable $e) {
                $results['failed']++;
                $results['errors'][] = ($hotel_data['name'] ?? 'unknown') . ': ' . $e->getMessage();

                error_log('❌ Hotel sync error: ' . get_class($e) . ': ' . $e->getMessage());
            }
        }

        /* ═══ Stage 2: Fetch Details ═══ */
        if (method_exists($this->client, 'get_hotel_details')) {
            $i = 0;

            foreach ($saved_ids as $post_id) {
                if (!$post_id || $i >= $details_limit) break;

                if (!$this->needs_details($post_id)) continue;

                $i++;

                $token = get_post_meta($post_id, '_geo_property_token', true)
                    ?: get_post_meta($post_id, '_hotel_property_token', true);

                if (!$token) continue;

                $details = $this->client->get_hotel_details($token);

                if (empty($details)) continue;

                $results['details_fetched']++;

                $this->apply_details($post_id, $details);
            }
        }

        $results['debug'] = $this->debug_info;

        error_log('✅ Hotel sync completed: ' . json_encode([
            'created' => $results['created'],
            'updated' => $results['updated'],
            'failed' => $results['failed'],
        ]));

        return $results;
    }

    /* ==========================================================================
       ✅ Save a Hotel Using Unified Architecture
       ========================================================================== */
    public function save_hotel($data) {
        // Check external ID to prevent duplicates
        $existing = $this->find_by_external_id($data['external_id']);

        if ($existing) {
            $post_id = $existing;
            $action = 'updated';
        } else {
            $post_id = wp_insert_post([
                'post_type' => 'hotel',
                'post_title' => $data['name'],
                'post_status' => 'publish',
                'post_content' => $data['description'] ?? '',
            ]);

            if (is_wp_error($post_id)) {
                error_log('❌ wp_insert_post failed: ' . $post_id->get_error_message());
                return 'failed';
            }

            $action = 'created';
        }

        // ✅ Save base fields
        $this->save_hotel_fields($post_id, $data);

        // ✅ Save featured image
        if (!empty($data['images']) && !has_post_thumbnail($post_id)) {
            ImageManager::set_featured_image($post_id, $data['images'][0], 'api');
        }

        // ✅ Save image gallery
        if (!empty($data['images'])) {
            ImageManager::fill_gallery($post_id, $data['images'], 'api');
        }

        return $action;
    }

    /* ==========================================================================
       ✅ Save Hotel Fields with Unified Architecture
       ========================================================================== */
    private function save_hotel_fields($post_id, $data) {
        /* ═══ 1. Geo Data via GeoSync (Standard) ═══ */
        GeoSync::apply($post_id, $data, $data['source'] ?? 'searchapi');

        // ✅ Fix missing Google Plus: get place_id from Google Maps
        $this->enrich_address_from_searchapi(
            $post_id,
            $data['name'],
            $data['city'] ?? '',
            $data['country'] ?? '',
            '_hotel_'
        );

        /* ═══ 2. Hotel-Specific Fields via GeoSchema ═══ */

        // Stars
        if (!empty($data['stars'])) {
            GeoSchema::set($post_id, 'stars', $data['stars']);
        }

        // Rating
        if (!empty($data['rating'])) {
            GeoSchema::set($post_id, 'rating', $data['rating']);
        }

        // Reviews count
        if (!empty($data['reviews_count'])) {
            GeoSchema::set($post_id, 'reviews', $data['reviews_count']);
        }

        // Check-in time
        if (!empty($data['check_in_time'])) {
            GeoSchema::set($post_id, 'checkin', $data['check_in_time']);
        }

        // Check-out time
        if (!empty($data['check_out_time'])) {
            GeoSchema::set($post_id, 'checkout', $data['check_out_time']);
        }

        // Property Token
        if (!empty($data['external_id'])) {
            GeoSchema::set($post_id, 'property_token', $data['external_id']);
        }

        /* ═══ 3. Hotel-Specific Fields (Outside GeoSchema) ═══ */

        // Country code
        if (!empty($data['country_code'])) {
            update_post_meta($post_id, '_geo_country_code', sanitize_text_field($data['country_code']));
            update_post_meta($post_id, '_hotel_country_code', sanitize_text_field($data['country_code']));
        }

        // Booking link
        if (!empty($data['link'])) {
            update_post_meta($post_id, '_hotel_booking_link', esc_url_raw($data['link']));
        }

        // Prices
        $this->save_price_data($post_id, $data);

        // Detailed ratings
        $this->save_rating_details($post_id, $data);

        // Amenities
        if (!empty($data['amenities'])) {
            $this->save_amenities($post_id, $data['amenities']);
        }

        // Nearby places
        if (!empty($data['nearby_places'])) {
            $this->save_nearby_places($post_id, $data['nearby_places']);
        }

        // ✅ Final sync with custom table
        GeoSync::sync_to_custom_table($post_id);
    }

    /* ==========================================================================
       ✅ Save Price Data
       ========================================================================== */
    private function save_price_data($post_id, $data) {
        if (!empty($data['price'])) {
            update_post_meta($post_id, '_hotel_price', floatval($data['price']));
            update_post_meta($post_id, '_hotel_price_formatted', sanitize_text_field($data['price_formatted'] ?? ''));
        }

        if (!empty($data['price_before_taxes'])) {
            update_post_meta($post_id, '_hotel_price_before_taxes', floatval($data['price_before_taxes']));
        }

        if (!empty($data['total_price'])) {
            update_post_meta($post_id, '_hotel_total_price', floatval($data['total_price']));
        }

        if (!empty($data['currency'])) {
            update_post_meta($post_id, '_hotel_currency', sanitize_text_field($data['currency']));
        }
    }

    /* ==========================================================================
       ✅ Save Detailed Ratings
       ========================================================================== */
    private function save_rating_details($post_id, $data) {
        if (!empty($data['reviews_histogram'])) {
            update_post_meta($post_id, '_hotel_reviews_histogram', $data['reviews_histogram']);
        }

        if (!empty($data['reviews_breakdown'])) {
            update_post_meta($post_id, '_hotel_reviews_breakdown', $data['reviews_breakdown']);
        }

        if (!empty($data['location_rating'])) {
            update_post_meta($post_id, '_hotel_location_rating', floatval($data['location_rating']));
        }

        if (!empty($data['proximity_to_transit_rating'])) {
            update_post_meta($post_id, '_hotel_proximity_transit_rating', floatval($data['proximity_to_transit_rating']));
        }

        if (!empty($data['airport_access_rating'])) {
            update_post_meta($post_id, '_hotel_airport_access_rating', floatval($data['airport_access_rating']));
        }
    }

    /* ==========================================================================
       ✅ Save Hotel Amenities with Smart Mapping
       ========================================================================== */
    private function save_amenities($post_id, $amenities) {
        if (empty($amenities) || !is_array($amenities)) return;

        $mapping = self::get_amenity_mapping();
        $saved_count = 0;

        foreach ($amenities as $amenity) {
            $a = strtolower(trim($amenity));

            foreach ($mapping as $meta_key => $synonyms) {
                foreach ($synonyms as $syn) {
                    if (strpos($a, $syn) !== false) {
                        update_post_meta($post_id, '_' . $meta_key, 'yes');
                        $saved_count++;
                        break 2;
                    }
                }
            }
        }

        if ($saved_count > 0) {
            // ✅ Update amenities counter (Bug #15)
            update_post_meta($post_id, '_hotel_amenities_count', $saved_count);

            error_log("✅ Saved {$saved_count} amenities for hotel {$post_id}");
        }
    }

    /* ==========================================================================
       ✅ Save Nearby Places
       ========================================================================== */
    private function save_nearby_places($post_id, $nearby_places) {
        if (empty($nearby_places) || !is_array($nearby_places)) return;

        $clean_places = [];

        foreach ($nearby_places as $place) {
            if (empty($place['name'])) continue;

            $clean_places[] = [
                'name' => sanitize_text_field($place['name']),
                'type' => sanitize_text_field($place['type'] ?? ''),
                'distance_km' => floatval($place['distance'] ?? 0),
                'coords' => isset($place['gps_coordinates'])
                    ? ($place['gps_coordinates']['latitude'] ?? 0) . ',' . ($place['gps_coordinates']['longitude'] ?? 0)
                    : '',
            ];
        }

        if (!empty($clean_places)) {
            update_post_meta($post_id, '_hotel_near_locations', $clean_places);
        }
    }

    /* ==========================================================================
       Does This Hotel Need Details?
       ========================================================================== */
    private function needs_details($post_id) {
        $gallery = get_post_meta($post_id, '_entity_gallery', true);
        $gallery_count = is_array($gallery) ? count($gallery) : 0;
        $gallery_source = get_post_meta($post_id, '_entity_gallery_source', true);

        if ($gallery_source === 'manual' && $gallery_count > 0) return false;

        $amenities_count = (int) get_post_meta($post_id, '_hotel_amenities_count', true);

        return ($gallery_count === 0) || ($amenities_count < 10);
    }

    /* ==========================================================================
       Apply Fetched Details to Post
       ========================================================================== */
    private function apply_details($post_id, $details) {
        if (!empty($details['images'])) {
            ImageManager::fill_gallery($post_id, $details['images'], 'api');
        }

        if (!empty($details['amenities'])) {
            $this->save_amenities($post_id, $details['amenities']);
        }
    }

    /* ==========================================================================
       Find Post by External ID
       ========================================================================== */
    private function find_by_external_id($external_id) {
        if (empty($external_id)) return null;

        global $wpdb;

        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_geo_external_id' AND meta_value = %s LIMIT 1",
            $external_id
        ));

        return $post_id ? intval($post_id) : null;
    }

    /* ==========================================================================
       Amenity Mapping
       ========================================================================== */
    private static function get_amenity_mapping() {
        return [
            'hotel_Indoor_pool' => ['indoor pool'],
            'hotel_outdoor_pool' => ['outdoor pool', 'swimming pool', 'pool'],
            'hotel_heated_pool' => ['heated pool'],
            'hotel_Pool_bar' => ['pool bar'],
            'hotel_Kids_pool' => ['kids pool', 'children pool'],
            'hotel_wifi' => ['free wi-fi', 'wi-fi', 'wifi', 'internet'],
            'hotel_flatscreen_tv' => ['flat-screen tv', 'flatscreen tv'],
            'hotel_restaurant' => ['restaurant'],
            'hotel_free_breakfast' => ['free breakfast', 'breakfast included'],
            'hotel_fitness_center' => ['fitness center', 'fitness', 'gym'],
            'hotel_spa' => ['spa'],
            'hotel_sauna' => ['sauna'],
            'hotel_free_parking' => ['free parking', 'parking'],
            'hotel_airport_shuttle_service' => ['airport shuttle'],
            'hotel_air_conditioning' => ['air conditioning'],
            'hotel_elevator' => ['elevator', 'lift'],
            'hotel_non_smoking_rooms' => ['non-smoking'],
            'hotel_family_rooms' => ['family rooms', 'kid-friendly'],
            'hotel_pet_friendly' => ['pet-friendly', 'pets allowed'],
            'hotel_Beachfront' => ['beach access', 'beachfront'],
            'hotel_terrace' => ['terrace'],
            'hotel_garden' => ['garden'],
            'hotel_24_hour_reception' => ['24-hour front desk', '24/7'],
            'hotel_laundry' => ['laundry'],
            'hotel_business_center' => ['business center'],
        ];
    }
}