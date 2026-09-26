<?php

namespace NextSafar\API;

// ⭐ Auto-load trait - before use
if (!trait_exists('\\NextSafar\\Sync\\PlaceEnrichTrait')) {
    require_once NEXTSAFAR_PATH . 'inc/sync/place-enrich-trait.php';
}

use NextSafar\Sync\PlaceEnrichTrait;

class DestinationSync {
    use PlaceEnrichTrait;

    private $client;
    private $debug_info = [];

    private static function get_type_mapping() {
        return [
            'historical' => ['historical landmark', 'monument', 'historic', 'castle', 'ruins', 'archaeological', 'ancient', 'memorial'],
            'cultural' => ['museum', 'art gallery', 'gallery', 'theater', 'theatre', 'opera', 'concert hall', 'cultural center'],
            'natural' => ['natural', 'national park', 'lake', 'mountain', 'forest', 'waterfall', 'island', 'cave'],
            'beach_recreational' => ['beach', 'water park', 'amusement park', 'theme park'],
            'urban' => ['tower', 'skyscraper', 'square', 'plaza', 'shopping mall', 'modern', 'city center', 'bridge'],
            'religious' => ['mosque', 'church', 'cathedral', 'temple', 'synagogue', 'shrine', 'religious', 'monastery', 'basilica'],
            'aquarium_zoo' => ['aquarium', 'zoo', 'zoological', 'marine'],
            'parks_gardens' => ['garden', 'botanical', 'park'],
            'adventure' => ['cable car', 'ski', 'adventure', 'zip line', 'climbing', 'paragliding', 'rafting'],
            'gastronomy' => ['food', 'culinary', 'market'],
            'events_festivals' => ['festival', 'event', 'concert'],
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

    public function sync_destinations($location, $options = []) {
        error_log('🚀 Starting destination sync for: ' . $location);

        $destinations = $this->client->search_destinations($location, $options);

        if (is_wp_error($destinations)) {
            return $destinations;
        }

        $this->debug_info['api_response_count'] = count($destinations);

        $results = [
            'total'   => count($destinations),
            'created' => 0,
            'updated' => 0,
            'failed'  => 0,
            'debug'   => $this->debug_info,
            'errors'  => [],
        ];

        foreach ($destinations as $dest_data) {
            try {
                $result = $this->save_destination($dest_data);

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
                $results['errors'][] = ($dest_data['name'] ?? 'unknown') . ': ' . $e->getMessage();

                error_log('❌ Destination sync error: ' . get_class($e) . ': ' . $e->getMessage());
            }
        }

        return $results;
    }

    public function save_destination($data) {
        $existing = $this->find_by_external_id($data['external_id']);

        if ($existing) {
            $post_id = $existing;
            $action = 'updated';
        } else {
            $post_id = wp_insert_post([
                'post_type'    => 'destination',
                'post_title'   => $data['name'],
                'post_status'  => 'publish',
                'post_content' => $data['description'] ?? '',
            ]);

            if (is_wp_error($post_id)) {
                return 'failed';
            }

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

        $mapped_type = $this->map_place_type($data['type'] ?? '');

        if ($mapped_type) {
            \NextSafar\Sync\GeoSchema::set($post_id, 'type', $mapped_type);
        }

        if (!empty($data['price_level'])) {
            $fee = $this->convert_price_level($data['price_level']);

            if ($fee) {
                update_post_meta($post_id, '_destination_entry_fee', $fee);
            }
        }

        // ⭐ Use trait to enrich address and working hours
        $this->enrich_address_from_searchapi(
            $post_id,
            $data['name'],
            $data['city'] ?? '',
            $data['country'] ?? '',
            '_destination_'
        );

        // Wikipedia + Wikidata
        try {
            $this->enrich_with_wikipedia($post_id, $data['name_en'] ?? $data['name']);
        } catch (\Exception $e) {
            error_log('⚠️ Wikipedia enrichment failed: ' . $e->getMessage());
        }
    }

    private function enrich_with_wikipedia($post_id, $name) {
        if (empty($name)) return;

        $attempted = get_post_meta($post_id, '_wikipedia_attempted', true);

        if ($attempted) return;

        update_post_meta($post_id, '_wikipedia_attempted', '1');

        $wiki_fa = new WikipediaClient('fa');
        $info = $wiki_fa->get_place_info($name);

        if (!$info || empty($info['url'])) {
            $wiki_en = new WikipediaClient('en');
            $info = $wiki_en->get_place_info($name);
        }

        if (!$info) {
            error_log('ℹ️ No Wikipedia data for: ' . $name);

            return;
        }

        if (!empty($info['url'])) {
            \NextSafar\Sync\GeoSchema::set($post_id, 'wikipedia', $info['url']);
            update_post_meta($post_id, '_destination_wikipedia_url', $info['url']);
        }

        $current_post = get_post($post_id);

        if ($current_post && empty($current_post->post_content) && !empty($info['excerpt'])) {
            wp_update_post([
                'ID'           => $post_id,
                'post_content' => substr($info['excerpt'], 0, 2000),
            ]);
        }

        if (!empty($info['wikidata']) && is_array($info['wikidata'])) {
            $this->save_wikidata_structural($post_id, $info['wikidata']);
        }

        $this->extract_structural_info($post_id, $info['excerpt'] ?? '');

        if (!empty($info['thumbnail']) && !has_post_thumbnail($post_id)) {
            ImageManager::set_featured_image($post_id, $info['thumbnail'], 'wikipedia');
        }
    }

    private function save_wikidata_structural($post_id, $data) {
        if (empty($data) || !is_array($data)) return;

        $field_mapping = [
            'opened'            => '_destination_Opened',
            'height'            => '_destination_Height',
            'architect'         => '_destination_Architect',
            'style'             => '_destination_Architectural_styles',
            'owner'             => '_destination_Owner',
            'floors'            => '_destination_Floor_count',
            'former_names'      => '_destination_Former_names',
            'structural_system' => '_destination_Structural_system',
        ];

        foreach ($field_mapping as $key => $meta_key) {
            $value = $data[$key] ?? null;

            if ($value === null || $value === '' || $value === []) continue;

            if (is_array($value)) {
                $value = implode('، ', $value);
            }

            $existing = get_post_meta($post_id, $meta_key, true);

            if (!empty($existing)) continue;

            update_post_meta($post_id, $meta_key, sanitize_text_field($value));
        }
    }

    private function extract_structural_info($post_id, $excerpt) {
        if (empty($excerpt)) return;

        if (!get_post_meta($post_id, '_destination_Opened', true)) {
            if (preg_match('/(?:در سال|سال ساخت|ساخته شده در|built in|built)\s*[«"]?\s*(\d{3,4})/i', $excerpt, $matches)) {
                update_post_meta($post_id, '_destination_Opened', $matches[1]);
            }
        }

        if (!get_post_meta($post_id, '_destination_Height', true)) {
            if (preg_match('/(\d+)\s*(?:متر|متری|meter)/i', $excerpt, $matches)) {
                update_post_meta($post_id, '_destination_Height', $matches[1] . ' متر');
            }
        }
    }

    private function map_place_type($google_type) {
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