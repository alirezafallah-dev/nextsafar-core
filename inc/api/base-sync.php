<?php

/**
 * BaseSync - Base class for all Syncs
 * Eliminates duplicated code in Hotel/Restaurant/Hospital/Destination Sync
 *
 * @version 1.0.0
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

// ⭐ Auto-load the trait
if (!trait_exists('\NextSafar\Sync\PlaceEnrichTrait')) {
    require_once NEXTSAFAR_PATH . 'inc/sync/place-enrich-trait.php';
}

use NextSafar\Sync\PlaceEnrichTrait;

abstract class BaseSync {
    use PlaceEnrichTrait;

    /** @var object API client */
    protected $client;

    /** @var array Debug info */
    protected $debug_info = [];

    /** @var string Post type */
    protected $post_type;

    /**
     * Common constructor
     */
    public function __construct($source = null) {
        $active_source = $source ?: get_option('nextsafar_active_source', 'searchapi');

        $this->debug_info['source'] = $active_source;

        $key = get_option('nextsafar_searchapi_key', '');
        $this->client = new SearchApiClient($key);
        $this->debug_info['has_api_key'] = !empty($key);
    }

    /**
     * Get debug info
     */
    public function get_debug_info(): array {
        return $this->debug_info;
    }

    /**
     * Main sync method (must be implemented in child classes)
     */
    abstract public function sync(string $location, array $options = []): array;

    /**
     * Save one item (must be implemented in child classes)
     */
    abstract public function save(array $data): string;

    /**
     * Search method name in client (must be defined in child classes)
     */
    abstract protected function get_search_method(): string;

    /**
     * ✅ Common sync implementation
     */
    protected function run_sync(string $location, array $options): array {
        $search_method = $this->get_search_method();

        if (!method_exists($this->client, $search_method)) {
            return new \WP_Error('invalid_method', "Method {$search_method} does not exist");
        }

        error_log('🚀 Starting ' . $this->post_type . ' sync for: ' . $location);

        $items = $this->client->{$search_method}($location, $options);

        if (is_wp_error($items)) {
            return $items;
        }

        $this->debug_info['api_response_count'] = count($items);

        $results = [
            'total'   => count($items),
            'created' => 0,
            'updated' => 0,
            'failed'  => 0,
            'debug'   => $this->debug_info,
            'errors'  => [],
        ];

        foreach ($items as $item_data) {
            try {
                $result = $this->save($item_data);

                if ($result === 'created') {
                    $results['created']++;
                } elseif ($result === 'updated') {
                    $results['updated']++;
                } else {
                    $results['failed']++;
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = ($item_data['name'] ?? 'Unknown') . ': ' . $e->getMessage();
                error_log('❌ Failed to save: ' . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * ✅ Common save post implementation
     */
    protected function save_post(array $data): array {
        $existing = $this->find_by_external_id($data['external_id']);

        if ($existing) {
            $post_id = $existing;
            $action = 'updated';
        } else {
            $post_id = wp_insert_post([
                'post_type'    => $this->post_type,
                'post_title'   => $data['name'],
                'post_status'  => 'publish',
                'post_content' => $data['description'] ?? '',
            ]);

            if (is_wp_error($post_id)) {
                return ['post_id' => null, 'action' => 'failed'];
            }

            $action = 'created';
        }

        return ['post_id' => $post_id, 'action' => $action];
    }

    /**
     * ✅ Common implementation: find post by external_id
     */
    protected function find_by_external_id(string $external_id): ?int {
        global $wpdb;

        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_geo_external_id' AND meta_value = %s LIMIT 1",
            $external_id
        ));

        return $post_id ? intval($post_id) : null;
    }

    /**
     * ✅ Common implementation: save featured image
     */
    protected function save_featured_image(int $post_id, array $data): void {
        if (!empty($data['images']) && !has_post_thumbnail($post_id)) {
            ImageManager::set_featured_image($post_id, $data['images'][0], 'api');
        }
    }

    /**
     * ✅ Common implementation: save geo meta
     */
    protected function save_geo_meta(int $post_id, array $data): void {
        \NextSafar\Sync\GeoSync::apply($post_id, $data, $data['source'] ?? 'searchapi');
    }
}