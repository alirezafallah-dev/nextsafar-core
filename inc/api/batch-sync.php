<?php
/**
 * NextSafar BatchSync
 * Batch processing sync to prevent timeout
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

class BatchSync {
    const BATCH_SIZE = 10;
    const SYNC_STATE_KEY = 'nextsafar_sync_state';

    /* ========================================================================
       Start Sync
       ======================================================================== */
    public static function start_sync(string $sync_type, string $location, string $source = 'searchapi', int $total_limit = 20): array {
        do_action('nextsafar_skip_revalidation');

        $lock_key = 'ns_sync_lock_' . $sync_type;

        // Handle stale locks
        $lock_time = get_transient($lock_key);

        if ($lock_time !== false) {
            $age = time() - (int) $lock_time;

            if ((int) $lock_time > 0 && $age > 900) {
                error_log("Stale sync lock cleared: {$lock_key} (age {$age}s)");
                delete_transient($lock_key);
            } else {
                return ['success' => false, 'message' => 'یک سینک از این نوع در حال اجرا است. لطفاً صبر کنید.'];
            }
        }

        // Hard TTL: 30 minutes
        set_transient($lock_key, time(), 1800);

        // Release lock on fatal error
        register_shutdown_function(function () use ($lock_key) {
            $err = error_get_last();

            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                delete_transient($lock_key);
                error_log('Sync lock released after fatal: ' . $lock_key);
            }
        });

        $client = self::get_client($sync_type, $source);

        if (!$client) {
            delete_transient($lock_key);
            return ['success' => false, 'message' => 'کلاینت معتبر نیست'];
        }

        try {
            $all_items = self::fetch_all_items($sync_type, $client, $location, $total_limit);
        } catch (\Exception $e) {
            delete_transient($lock_key);
            return ['success' => false, 'message' => 'خطا در دریافت لیست: ' . $e->getMessage()];
        }

        if (is_wp_error($all_items)) {
            delete_transient($lock_key);
            return ['success' => false, 'message' => $all_items->get_error_message()];
        }

        if (empty($all_items)) {
            delete_transient($lock_key);
            return ['success' => false, 'message' => 'هیچ آیتمی یافت نشد'];
        }

        $state = [
            'sync_type'     => $sync_type,
            'source'        => $source,
            'location'      => $location,
            'all_items'     => $all_items,
            'total'         => count($all_items),
            'processed'     => 0,
            'created'       => 0,
            'updated'       => 0,
            'failed'        => 0,
            'current_batch' => 0,
            'status'        => 'running',
            'started_at'    => current_time('mysql'),
            'lock_key'      => $lock_key,
        ];

        update_option(self::SYNC_STATE_KEY, $state, false);

        self::log_sync_start($sync_type, $source, count($all_items));

        // Note: batches are processed by client via ajax_sync_batch

        return [
            'success'       => true,
            'total'         => count($all_items),
            'batch_size'    => self::BATCH_SIZE,
            'total_batches' => ceil(count($all_items) / self::BATCH_SIZE),
        ];
    }

    /* ========================================================================
       Process Next Batch
       ======================================================================== */
    public static function process_next_batch(): array {
        do_action('nextsafar_skip_revalidation');

        $state = get_option(self::SYNC_STATE_KEY);

        if (!$state || $state['status'] !== 'running') {
            if (!empty($state['lock_key'])) {
                delete_transient($state['lock_key']);
            }

            return ['completed' => true, 'message' => 'سینک فعالی وجود ندارد'];
        }

        $total = $state['total'];
        $current_batch = $state['current_batch'];
        $batch_start = $current_batch * self::BATCH_SIZE;
        $batch_end = min($batch_start + self::BATCH_SIZE, $total);

        $items_to_process = array_slice($state['all_items'], $batch_start, self::BATCH_SIZE);

        if (empty($items_to_process)) {
            return self::finish_sync($state);
        }

        $sync_instance = self::get_sync_instance($state['sync_type'], $state['source']);

        // Track each item's result
        $batch_details = [];

        foreach ($items_to_process as $index => $item) {
            $item_name = $item['name'] ?? $item['title'] ?? 'Item ' . ($batch_start + $index + 1);

            $detail = [
                'index'  => $batch_start + $index + 1,
                'name'   => mb_substr($item_name, 0, 40),
                'status' => 'processing',
            ];

            $result = self::save_item($sync_instance, $state['sync_type'], $item, $state['location']);

            if ($result === 'created') {
                $state['created']++;
                $detail['status'] = 'created';
            } elseif ($result === 'updated') {
                $state['updated']++;
                $detail['status'] = 'updated';
            } else {
                $state['failed']++;
                $detail['status'] = 'failed';
            }

            $batch_details[] = $detail;
        }

        $state['processed'] = $batch_end;
        $state['current_batch']++;

        update_option(self::SYNC_STATE_KEY, $state, false);

        $is_completed = $batch_end >= $total;

        if ($is_completed) {
            self::finish_sync($state);
        }

        return [
            'completed'        => $is_completed,
            'processed'        => $batch_end,
            'total'            => $total,
            'progress_percent' => $total > 0 ? round(($batch_end / $total) * 100) : 0,
            'created'          => $state['created'],
            'updated'          => $state['updated'],
            'failed'           => $state['failed'],
            'current_batch'    => $state['current_batch'],
            'total_batches'    => ceil($total / self::BATCH_SIZE),
            'batch_details'    => $batch_details,
            'sync_type'        => $state['sync_type'],
        ];
    }

    /* ========================================================================
       Finish Sync
       ======================================================================== */
    private static function finish_sync(array $state): array {
        $state['status'] = 'completed';
        $state['completed_at'] = current_time('mysql');

        update_option(self::SYNC_STATE_KEY, $state, false);

        self::log_sync_complete($state);

        if (!empty($state['lock_key'])) {
            delete_transient($state['lock_key']);
        }

        do_action('nextsafar_enable_revalidation');

        return [
            'completed'        => true,
            'processed'        => $state['total'],
            'total'            => $state['total'],
            'progress_percent' => 100,
            'created'          => $state['created'],
            'updated'          => $state['updated'],
            'failed'           => $state['failed'],
            'current_batch'    => $state['current_batch'],
            'total_batches'    => ceil($state['total'] / self::BATCH_SIZE),
            'sync_type'        => $state['sync_type'],
        ];
    }

    /* ========================================================================
       Get Status
       ======================================================================== */
    public static function get_status(): array {
        $state = get_option(self::SYNC_STATE_KEY);

        if (!$state) return ['status' => 'idle'];

        return [
            'status'           => $state['status'],
            'sync_type'        => $state['sync_type'],
            'source'           => $state['source'],
            'total'            => $state['total'],
            'processed'        => $state['processed'],
            'created'          => $state['created'],
            'updated'          => $state['updated'],
            'failed'           => $state['failed'],
            'progress_percent' => $state['total'] > 0 ? round(($state['processed'] / $state['total']) * 100) : 0,
            'started_at'       => $state['started_at'],
        ];
    }

    /* ========================================================================
       Cancel Sync
       ======================================================================== */
    public static function cancel_sync(): bool {
        $state = get_option(self::SYNC_STATE_KEY);

        if ($state) {
            $state['status'] = 'cancelled';

            update_option(self::SYNC_STATE_KEY, $state, false);

            if (!empty($state['lock_key'])) delete_transient($state['lock_key']);
        }

        return true;
    }

    /* ========================================================================
       Helpers
       ======================================================================== */
    private static function get_client(string $sync_type, string $source) {
        if ($source === 'serpapi') {
            $key = get_option('nextsafar_serpapi_key', '');
            return new SerpApiClient($key);
        }

        $key = get_option('nextsafar_searchapi_key', '');
        return new SearchApiClient($key);
    }

    private static function get_sync_instance(string $sync_type, string $source) {
        switch ($sync_type) {
            case 'destination': return new DestinationSync($source);
            case 'restaurant':  return new RestaurantSync($source);
            case 'hospital':    return new HospitalSync($source);
            case 'hotel':
            default:            return new HotelSync($source);
        }
    }

    private static function fetch_all_items(string $sync_type, $client, string $location, int $limit) {
        $options = ['limit' => $limit];

        switch ($sync_type) {
            case 'hotel':       return $client->search_hotels($location, $options);
            case 'destination': return $client->search_destinations($location, $options);
            case 'restaurant':  return $client->search_restaurants($location, $options);
            case 'hospital':    return $client->search_hospitals($location, $options);
            default:            return new \WP_Error('invalid_type', 'نوع سینک معتبر نیست: ' . $sync_type);
        }
    }

    private static function save_item($sync_instance, string $sync_type, array $item, string $location) {
        $item['search_location'] = $location;

        try {
            $method_map = [
                'hotel'       => 'save_hotel',
                'destination' => 'save_destination',
                'restaurant'  => 'save_restaurant',
                'hospital'    => 'save_hospital',
            ];

            $method = $method_map[$sync_type] ?? null;

            if (!$method) return 'failed';

            $reflection = new \ReflectionMethod($sync_instance, $method);
            $reflection->setAccessible(true);

            return $reflection->invoke($sync_instance, $item);
        } catch (\Exception $e) {
            error_log('save_item exception: ' . $e->getMessage());
            return 'failed';
        }
    }

    /* ========================================================================
       Logging
       ======================================================================== */
    private static function log_sync_start(string $sync_type, string $source, int $total): void {
        global $wpdb;

        $table = $wpdb->prefix . 'api_sync_log';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            \NextSafar\Activator::create_sync_log_table();
        }

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            error_log('api_sync_log table does not exist');
            return;
        }

        $wpdb->insert($table, [
            'source'          => $source,
            'entity_type'     => $sync_type,
            'status'          => 'running',
            'records_fetched' => $total,
            'started_at'      => current_time('mysql'),
        ]);
    }

    private static function log_sync_complete(array $state): void {
        global $wpdb;

        $table = $wpdb->prefix . 'api_sync_log';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return;

        $wpdb->update(
            $table,
            [
                'status'          => $state['failed'] > 0 ? 'partial' : 'completed',
                'records_created' => $state['created'],
                'records_updated' => $state['updated'],
                'records_failed'  => $state['failed'],
                'completed_at'    => current_time('mysql'),
            ],
            [
                'source'      => $state['source'],
                'entity_type' => $state['sync_type'],
                'started_at'  => $state['started_at'],
            ]
        );
    }
}