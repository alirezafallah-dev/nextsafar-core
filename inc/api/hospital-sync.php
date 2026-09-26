<?php

/**
 * HospitalSync — Rewritten using BaseSync
 * Removes duplicate code
 *
 * @version 1.0.0
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

/* ==========================================================================
   Load Base Class
   ========================================================================== */
require_once NEXTSAFAR_PATH . 'inc/api/base-sync.php';

class HospitalSync extends BaseSync {
    protected $post_type = 'hospital';

    /**
     * Search method name in the client
     */
    protected function get_search_method(): string {
        return 'search_hospitals';
    }

    /**
     * Sync hospitals
     */
    public function sync(string $location, array $options = []): array {
        return $this->run_sync($location, $options);
    }

    /**
     * Backward compatibility with old code
     */
    public function sync_hospitals($location, $options = []) {
        return $this->sync($location, $options);
    }

    /**
     * Save a single hospital
     */
    public function save(array $data): string {
        $result = $this->save_post($data);

        if ($result['action'] === 'failed') {
            return 'failed';
        }

        $post_id = $result['post_id'];

        $this->save_metaboxes($post_id, $data);
        $this->save_featured_image($post_id, $data);

        return $result['action'];
    }

    /**
     * Backward compatibility with old code
     */
    public function save_hospital($data) {
        return $this->save($data);
    }

    /* ==========================================================================
       Save Hospital-Specific Metaboxes
       ========================================================================== */
    private function save_metaboxes(int $post_id, array $data): void {
        // ✅ Use shared method
        $this->save_geo_meta($post_id, $data);

        // Save hospital type
        if (!empty($data['type'])) {
            update_post_meta($post_id, '_hospital_type', sanitize_text_field($data['type']));
        }

        // Detect emergency
        $is_emergency = $this->detect_emergency($data);
        update_post_meta($post_id, '_hospital_emergency', $is_emergency ? 'yes' : 'no');

        // ⭐ Use trait to enrich address and working hours
        $this->enrich_address_from_searchapi(
            $post_id,
            $data['name'],
            $data['city'] ?? '',
            $data['country'] ?? '',
            '_hospital_'
        );

        // Re-check emergency based on working hours
        $work_time = get_post_meta($post_id, '_hospital_work_time', true);

        if (is_array($work_time) && $this->is_open_24_7($work_time)) {
            update_post_meta($post_id, '_hospital_emergency', 'yes');
        }
    }

    /* ==========================================================================
       Emergency Detection
       ========================================================================== */

    /**
     * Detect emergency based on name and type
     */
    private function detect_emergency(array $data): bool {
        $name = strtolower($data['name'] ?? '');
        $type = strtolower($data['type'] ?? '');

        $emergency_keywords = [
            'emergency', '24 hour', '24/7', 'trauma',
            'اورژانس', 'شبانه‌روزی',
        ];

        foreach ($emergency_keywords as $keyword) {
            if (strpos($name, $keyword) !== false || strpos($type, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if open 24/7
     */
    private function is_open_24_7($work_time): bool {
        if (empty($work_time) || !is_array($work_time)) return false;

        foreach ($work_time as $day => $slots) {
            if (!is_array($slots)) continue;

            foreach ($slots as $slot) {
                if (!is_array($slot)) continue;

                $from = $slot['from'] ?? '';
                $to = $slot['to'] ?? '';

                if ($from === '24h' || $to === '24h') {
                    return true;
                }

                if (stripos($from . $to, '24') !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}