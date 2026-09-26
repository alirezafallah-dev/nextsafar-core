<?php

namespace NextSafar\API;

// Auto-load trait
if (!trait_exists('\\NextSafar\\Sync\\PlaceEnrichTrait')) {
    require_once NEXTSAFAR_PATH . 'inc/sync/place-enrich-trait.php';
}

use NextSafar\Sync\PlaceEnrichTrait;

class RestaurantSync {
    use PlaceEnrichTrait;

    private $client;
    private $debug_info = [];

    private static function get_type_mapping() {
        return [
            'restaurant'  => ['restaurant', 'dining', 'eatery'],
            'traditional' => ['traditional', 'local cuisine', 'authentic'],
            'fast_food'   => ['fast food', 'burger', 'pizza', 'sandwich', 'fast casual'],
            'cafe'        => ['cafe', 'tea house', 'coffee shop'],
            'coffee_shop' => ['specialty coffee', 'coffee roaster'],
            'street_food' => ['street food', 'food stall', 'food truck'],
            'bakery'      => ['bakery', 'pastry', 'bread'],
            'bar'         => ['bar', 'lounge', 'pub', 'tavern'],
            'seafood'     => ['seafood', 'fish', 'sushi'],
            'steakhouse'  => ['steakhouse', 'steak', 'grill'],
            'buffet'      => ['buffet', 'smorgasbord'],
            'fine_dining' => ['fine dining', 'gourmet', 'michelin'],
        ];
    }

    /**
     * Mapping restaurant facilities from SearchAPI to WordPress metas
     * This mapping covers more than 60 common restaurant facilities.
     */
    private static function get_facility_mapping() {
        return [
            // Internet & Connectivity
            'restaurant_wifi'            => ['wifi', 'free wifi', 'wireless', 'internet', 'wi-fi'],
            'restaurant_parking'         => ['parking', 'free parking', 'valet parking', 'garage'],
            'restaurant_reservation'     => ['reservations', 'reservation', 'booking', 'takes reservations'],

            // Food Services
            'restaurant_delivery'        => ['delivery', 'food delivery', 'home delivery'],
            'restaurant_takeout'         => ['takeout', 'take away', 'takeaway', 'pickup'],
            'restaurant_outdoor_seating' => ['outdoor seating', 'patio', 'terrace', 'outdoor'],
            'restaurant_live_music'      => ['live music', 'live band', 'music'],

            // Payment
            'restaurant_credit_cards'    => ['credit cards', 'accepts credit cards', 'credit card'],
            'restaurant_cash_only'       => ['cash only'],

            // Family & Kids
            'restaurant_high_chairs'     => ['high chairs', 'highchairs', 'kids seats'],
            'restaurant_kids_menu'       => ['kids menu', 'children menu', 'kid friendly'],
            'restaurant_wheelchair'      => ['wheelchair', 'accessible', 'handicap', 'disability'],

            // Drinks & Bar
            'restaurant_full_bar'        => ['full bar', 'bar', 'alcohol', 'liquor'],
            'restaurant_wine_list'       => ['wine list', 'wine', 'wine bar'],
            'restaurant_beer'            => ['beer', 'craft beer', 'draft beer'],
            'restaurant_cocktails'       => ['cocktails', 'cocktail'],

            // Meal Services
            'restaurant_breakfast'       => ['breakfast', 'brunch'],
            'restaurant_lunch'           => ['lunch'],
            'restaurant_dinner'          => ['dinner'],
            'restaurant_buffet'          => ['buffet'],
            'restaurant_table_service'   => ['table service', 'waiter', 'waitstaff'],
            'restaurant_seating'         => ['seating', 'sit down'],

            // Dietary
            'restaurant_vegetarian'      => ['vegetarian', 'veggie', 'vegetarian options'],
            'restaurant_vegan'           => ['vegan', 'vegan options'],
            'restaurant_gluten_free'     => ['gluten free', 'gluten-free'],
            'restaurant_halal'           => ['halal'],
            'restaurant_kosher'          => ['kosher'],

            // Special Features
            'restaurant_private_dining'  => ['private dining', 'private room', 'event space'],
            'restaurant_tv'              => ['tv', 'television', 'sports bar'],
            'restaurant_sports'          => ['sports', 'sports bar', 'game'],
            'restaurant_dancing'         => ['dancing', 'dance floor'],
            'restaurant_smoking'         => ['smoking', 'smoking area'],

            // Outdoor Space
            'restaurant_garden'          => ['garden', 'garden seating'],
            'restaurant_rooftop'         => ['rooftop', 'roof top', 'terrace'],
            'restaurant_waterfront'      => ['waterfront', 'sea view', 'ocean view'],

            // Entertainment
            'restaurant_live_sport'      => ['live sport', 'sports tv'],
            'restaurant_dj'              => ['dj', 'disc jockey'],
            'restaurant_karaoke'         => ['karaoke'],

            // Parking & Access
            'restaurant_street_parking'  => ['street parking'],
            'restaurant_valet'           => ['valet', 'valet service'],
            'restaurant_validated'       => ['validated parking'],

            // Technology
            'restaurant_digital_menu'    => ['digital menu', 'qr menu', 'qr code'],
            'restaurant_online_order'    => ['online ordering', 'order online'],

            // Pets
            'restaurant_pet_friendly'    => ['pet friendly', 'pets allowed', 'dog friendly'],

            // Business
            'restaurant_business_meeting'=> ['business meetings', 'meeting room'],
            'restaurant_groups'          => ['groups', 'large groups', 'parties'],
        ];
    }

    public function __construct($source = null) {
        $active_source = $source ?: get_option('nextsafar_active_source', 'searchapi');

        $this->debug_info['source'] = $active_source;

        $key = get_option('nextsafar_searchapi_key', '');
        $this->client = new SearchApiClient($key);
        $this->debug_info['has_api_key'] = !empty($key);
    }

    public function get_debug_info() {
        return $this->debug_info;
    }

    public function sync_restaurants($location, $options = []) {
        error_log('🚀 Starting restaurant sync for: ' . $location);

        $restaurants = $this->client->search_restaurants($location, $options);

        if (is_wp_error($restaurants)) {
            return $restaurants;
        }

        $this->debug_info['api_response_count'] = count($restaurants);

        $results = [
            'total'   => count($restaurants),
            'created' => 0,
            'updated' => 0,
            'failed'  => 0,
            'debug'   => $this->debug_info,
            'errors'  => [],
        ];

        foreach ($restaurants as $rest_data) {
            try {
                $result = $this->save_restaurant($rest_data);

                if ($result === 'created') {
                    $results['created']++;
                } elseif ($result === 'updated') {
                    $results['updated']++;
                } else {
                    $results['failed']++;
                }
            } catch (\Throwable $e) {
                /* ✅ Error is also caught, not just Exception */
                $results['failed']++;
                $results['errors'][] = ($rest_data['name'] ?? 'unknown') . ': ' . $e->getMessage();
                error_log('❌ Restaurant sync error: ' . get_class($e) . ': ' . $e->getMessage());
            }
        }

        return $results;
    }

    public function save_restaurant($data) {
        $existing = $this->find_by_external_id($data['external_id']);

        if ($existing) {
            $post_id = $existing;
            $action = 'updated';
        } else {
            $post_id = wp_insert_post([
                'post_type'    => 'restaurant',
                'post_title'   => $data['name'],
                'post_status'  => 'publish',
                'post_content' => $data['description'] ?? '',
            ]);

            if (is_wp_error($post_id)) return 'failed';

            $action = 'created';
        }

        $this->save_metaboxes($post_id, $data);

        if (!empty($data['images']) && !has_post_thumbnail($post_id)) {
            ImageManager::set_featured_image($post_id, $data['images'][0], 'api');
        }

        return $action;
    }

    private function find_by_external_id($external_id) {
        global $wpdb;

        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_geo_external_id' AND meta_value = %s LIMIT 1",
            $external_id
        ));

        return $post_id ? intval($post_id) : null;
    }

    private function save_metaboxes($post_id, $data) {
        \NextSafar\Sync\GeoSync::apply($post_id, $data, $data['source'] ?? 'searchapi');

        // Restaurant type
        $mapped_type = $this->map_restaurant_type($data['type'] ?? '');

        if ($mapped_type) {
            update_post_meta($post_id, '_restaurant_type', $mapped_type);
        }

        // Price level
        if (!empty($data['price_level'])) {
            $price_text = $this->convert_price_level($data['price_level']);

            if ($price_text) {
                update_post_meta($post_id, '_restaurant_average_price', $price_text);
            }
        }

        // Menu link (with array and object handling)
        $this->save_menu_link($post_id, $data['menu_link'] ?? '');

        // Save facilities from extensions
        $this->save_facilities($post_id, $data['extensions'] ?? []);

        // Use trait to enrich address and working hours
        $this->enrich_address_from_searchapi(
            $post_id,
            $data['name'],
            $data['city'] ?? '',
            $data['country'] ?? '',
            '_restaurant_'
        );
    }

    /**
     * Save menu link with array and object handling
     */
    private function save_menu_link($post_id, $menu_data) {
        $menu_url = '';

        if (empty($menu_data)) {
            return;
        }

        // Case 1: Simple string
        if (is_string($menu_data)) {
            $menu_url = $menu_data;
        }
        // Case 2: Array
        elseif (is_array($menu_data)) {
            foreach ($menu_data as $item) {
                if (is_string($item) && !empty($item)) {
                    $menu_url = $item;
                    break;
                }

                // If it is an array of objects
                if (is_array($item) && !empty($item['link'])) {
                    $menu_url = $item['link'];
                    break;
                }

                if (is_array($item) && !empty($item['url'])) {
                    $menu_url = $item['url'];
                    break;
                }
            }
        }
        // Case 3: Object
        elseif (is_object($menu_data)) {
            $menu_url = $menu_data->link ?? $menu_data->url ?? '';
        }

        // Sanitize and save
        if (!empty($menu_url)) {
            $menu_url = esc_url_raw($menu_url);

            if (!empty($menu_url)) {
                update_post_meta($post_id, '_restaurant_menu_link', $menu_url);
                error_log('✅ Menu link saved for restaurant ' . $post_id . ': ' . $menu_url);
            }
        }
    }

    /**
     * Save facilities as checkboxes
     * Uses the defined mapping.
     */
    private function save_facilities($post_id, $extensions) {
        if (empty($extensions) || !is_array($extensions)) {
            return;
        }

        $mapping = self::get_facility_mapping();
        $saved_count = 0;

        // Convert all extensions to lowercase for easier comparison
        $extensions_lower = array_map('strtolower', $extensions);
        $extensions_text = implode(' ', $extensions_lower);

        foreach ($mapping as $meta_key => $keywords) {
            foreach ($keywords as $keyword) {
                $keyword_lower = strtolower($keyword);

                // Check if keyword exists in extensions
                foreach ($extensions_lower as $ext) {
                    if (strpos($ext, $keyword_lower) !== false) {
                        update_post_meta($post_id, '_' . $meta_key, 'yes');
                        $saved_count++;
                        break 2; // Also break out of the outer loop
                    }
                }
            }
        }

        if ($saved_count > 0) {
            error_log("✅ Saved {$saved_count} facilities for restaurant {$post_id}");
        }
    }

    private function map_restaurant_type($google_type) {
        if (empty($google_type)) return '';

        $type_lower = strtolower($google_type);
        $mapping = self::get_type_mapping();

        foreach ($mapping as $our_type => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($type_lower, $keyword) !== false) {
                    return $our_type;
                }
            }
        }

        return '';
    }
}