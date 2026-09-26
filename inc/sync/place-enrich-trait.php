<?php

/**
 * PlaceEnrichTrait — Enrich Address and Working Hours from SearchAPI
 */

namespace NextSafar\Sync;

if (!defined('ABSPATH')) exit;

trait PlaceEnrichTrait {

    /**
     * Enrich full address and working hours from SearchAPI.
     */
    protected function enrich_address_from_searchapi(int $post_id, string $name, string $city = '', string $country = '', string $meta_prefix = '_restaurant_'): bool {
        if (!isset($this->client) || !method_exists($this->client, 'get_place_details')) {
            error_log('❌ PlaceEnrichTrait: client or get_place_details not available');

            return false;
        }

        $query_parts = array_filter([$name, $city, $country]);
        $query = implode(', ', $query_parts);

        if (empty($query)) {
            return false;
        }

        /* Strong cache */
        $cache_key = 'ns_maps_' . md5(strtolower($query));
        $cached = get_transient($cache_key);

        if ($cached === 'miss') {
            return false;
        }

        if ($cached) {
            $details = $cached;
        } else {
            $details = $this->client->get_place_details($query);

            if (!$details) {
                set_transient($cache_key, 'miss', 7 * DAY_IN_SECONDS);

                return false;
            }

            set_transient($cache_key, $details, 90 * DAY_IN_SECONDS);
        }

        /* ⭐ Save location fields with safe sanitize (array support) */
        $geo_fields = [
            'address'  => ['meta' => '_geo_address',  'type' => 'text'],
            'phone'    => ['meta' => '_geo_phone',    'type' => 'text'],
            'website'  => ['meta' => '_geo_website',  'type' => 'url'],
            'place_id' => ['meta' => '_geo_place_id', 'type' => 'text'],
        ];

        foreach ($geo_fields as $src_key => $config) {
            if (empty($details[$src_key])) continue;

            $value = $this->safe_sanitize($details[$src_key], $config['type']);

            if ($value !== '') {
                update_post_meta($post_id, $config['meta'], $value);
            }
        }

        /* Standard working hours (multi-slot) */
        if (!empty($details['hours'])) {
            $formatted_hours = $this->format_working_hours_standard($details['hours']);

            update_post_meta($post_id, $meta_prefix . 'work_time', $formatted_hours);

            error_log('✅ Working hours saved for post ' . $post_id);
        }

        /* Menu link (restaurant specific) */
        if (!empty($details['menu']) && $meta_prefix === '_restaurant_') {
            $menu_url = '';

            if (is_string($details['menu'])) {
                $menu_url = $details['menu'];
            } elseif (is_array($details['menu'])) {
                $menu_url = $details['menu'][0] ?? ($details['menu']['url'] ?? '');
            } elseif (is_object($details['menu'])) {
                $menu_url = $details['menu']->url ?? $details['menu']->link ?? '';
            }

            if (!empty($menu_url)) {
                $menu_url = esc_url_raw($menu_url);

                if (!empty($menu_url)) {
                    update_post_meta($post_id, '_restaurant_menu_link', $menu_url);

                    error_log('✅ Menu link enriched for restaurant ' . $post_id);
                }
            }
        }

        /* ⭐ Save facilities from extensions (if restaurant) */
        if (!empty($details['extensions']) && is_array($details['extensions']) && $meta_prefix === '_restaurant_') {
            $this->save_restaurant_facilities($post_id, $details['extensions']);
        }

        /* Sync with geo table */
        \NextSafar\Sync\GeoSync::sync_to_custom_table($post_id);

        error_log('✅ Address enriched for post ' . $post_id . ': ' . ($details['address'] ?? 'N/A'));

        return true;
    }

    /**
     * ⭐ Save restaurant facilities (usable from trait).
     */
    private function save_restaurant_facilities(int $post_id, array $extensions): void {
        $mapping = [
            'restaurant_wifi'            => ['wifi', 'free wifi', 'wireless', 'internet'],
            'restaurant_parking'         => ['parking', 'free parking', 'valet'],
            'restaurant_reservation'     => ['reservations', 'booking'],
            'restaurant_delivery'        => ['delivery', 'food delivery'],
            'restaurant_takeout'         => ['takeout', 'take away', 'pickup'],
            'restaurant_outdoor_seating' => ['outdoor seating', 'patio', 'terrace'],
            'restaurant_live_music'      => ['live music', 'live band'],
            'restaurant_credit_cards'    => ['credit cards', 'accepts credit cards'],
            'restaurant_high_chairs'     => ['high chairs', 'kids seats'],
            'restaurant_kids_menu'       => ['kids menu', 'children menu'],
            'restaurant_wheelchair'      => ['wheelchair', 'accessible'],
            'restaurant_full_bar'        => ['full bar', 'bar', 'alcohol'],
            'restaurant_vegetarian'      => ['vegetarian', 'veggie'],
            'restaurant_vegan'           => ['vegan'],
            'restaurant_gluten_free'     => ['gluten free'],
            'restaurant_halal'           => ['halal'],
            'restaurant_pet_friendly'    => ['pet friendly', 'pets allowed'],
        ];

        $extensions_lower = array_map('strtolower', $extensions);
        $saved = 0;

        foreach ($mapping as $meta_key => $keywords) {
            foreach ($keywords as $keyword) {
                foreach ($extensions_lower as $ext) {
                    if (strpos($ext, strtolower($keyword)) !== false) {
                        update_post_meta($post_id, '_' . $meta_key, 'yes');
                        $saved++;

                        break 2;
                    }
                }
            }
        }

        if ($saved > 0) {
            error_log("✅ Saved {$saved} facilities via trait for restaurant {$post_id}");
        }
    }

    /**
     * ⭐ Safe sanitize method — supports arrays, objects, and strings.
     *
     * @param mixed  $value Input value (may be string, array, or object)
     * @param string $type  Type: 'text' or 'url'
     * @return string
     */
    private function safe_sanitize($value, string $type = 'text'): string {
        /* 1. If null or empty */
        if ($value === null || $value === '' || $value === false) {
            return '';
        }

        /* 2. If array, get the first valid element */
        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $value = $item;

                    break;
                }
            }

            /* If no valid element was found */
            if (is_array($value)) {
                return '';
            }
        }

        /* 3. If still not scalar (e.g., object) */
        if (!is_scalar($value)) {
            /* Try to convert to JSON and extract URL */
            if (is_object($value)) {
                $json = json_encode($value);

                if (preg_match('/"url"\s*:\s*"([^"]+)"/', $json, $matches)) {
                    $value = $matches[1];
                } else {
                    return '';
                }
            } else {
                return '';
            }
        }

        /* 4. Convert to string */
        $value = (string) $value;

        /* 5. Sanitize based on type */
        if ($type === 'url') {
            $sanitized = esc_url_raw($value);

            /* If esc_url_raw returned empty but original value was a valid URL */
            if (empty($sanitized) && preg_match('/^https?:\/\//', $value)) {
                return filter_var($value, FILTER_SANITIZE_URL);
            }

            return $sanitized;
        }

        return sanitize_text_field($value);
    }

    /**
     * ⭐ Standard working hours format (multi-slot).
     */
    protected function format_working_hours_standard($hours): array {
        $days_map = [
            'saturday'  => 'شنبه',
            'sunday'    => 'یکشنبه',
            'monday'    => 'دوشنبه',
            'tuesday'   => 'سه‌شنبه',
            'wednesday' => 'چهارشنبه',
            'thursday'  => 'پنج‌شنبه',
            'friday'    => 'جمعه',
        ];

        $formatted = [];

        /* Case 1: Array of {"day": ..., "time": ...} */
        if (isset($hours[0]) && is_array($hours[0]) && isset($hours[0]['day'])) {
            $hours_by_day = [];

            foreach ($hours as $item) {
                $day = strtolower($item['day'] ?? '');
                $time = $item['time'] ?? '';

                if ($day && $time) {
                    $hours_by_day[$day][] = $time;
                }
            }

            $hours = $hours_by_day;
        }

        /* Case 2: Object {day: time_string} */
        foreach ($days_map as $en_day => $fa_day) {
            if (!isset($hours[$en_day]) || empty($hours[$en_day])) {
                $formatted[$fa_day] = [];

                continue;
            }

            $time_data = $hours[$en_day];

            if (is_string($time_data)) {
                if (stripos($time_data, 'closed') !== false) {
                    $formatted[$fa_day] = [['from' => 'off', 'to' => 'off']];

                    continue;
                }

                if (stripos($time_data, 'open 24') !== false || stripos($time_data, '24 hours') !== false) {
                    $formatted[$fa_day] = [['from' => '24h', 'to' => '24h']];

                    continue;
                }

                $time_strings = preg_split('/[,،
]+/', $time_data);
                $time_data = array_filter(array_map('trim', $time_strings));
            }

            if (is_string($time_data)) {
                $time_data = [$time_data];
            }

            $day_slots = [];

            foreach ($time_data as $time_str) {
                if (!is_string($time_str) || empty($time_str)) continue;

                if (stripos($time_str, 'closed') !== false) continue;

                if (stripos($time_str, 'open 24') !== false || stripos($time_str, '24 hours') !== false) {
                    $day_slots[] = ['from' => '24h', 'to' => '24h'];

                    continue;
                }

                $parts = preg_split('/[–\-−]\s*|\s+to\s+|\s+till\s+/iu', $time_str);

                if (count($parts) >= 2) {
                    $from = $this->parse_time_string(trim($parts[0]));
                    $to = $this->parse_time_string(trim($parts[1]));

                    if ($from && $to) {
                        $day_slots[] = ['from' => $from, 'to' => $to];
                    }
                }
            }

            $formatted[$fa_day] = !empty($day_slots) ? $day_slots : [];
        }

        return $formatted;
    }

    /**
     * Convert "8:30 AM" to "08:30".
     */
    protected function parse_time_string(string $time_str): string {
        $time_str = trim($time_str);

        if (empty($time_str)) return '';

        $time_str = preg_replace('/[^\d:APMapm\s]/', '', $time_str);

        $timestamp = strtotime($time_str);

        if ($timestamp === false) return '';

        return date('H:i', $timestamp);
    }

    /**
     * Convert price level to Persian text.
     */
    protected function convert_price_level($price_level): string {
        if (!is_string($price_level)) {
            $price_level = is_array($price_level) ? implode('', $price_level) : '';
        }

        $levels = [
            'free' => 'رایگان',
            '$'    => 'ارزان',
            '$$'   => 'متوسط',
            '$$$'  => 'گران',
            '$$$$' => 'خیلی گران',
        ];

        return $levels[$price_level] ?? '';
    }
}