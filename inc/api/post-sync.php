<?php

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

use NextSafar\Sync\GeoSync;
use NextSafar\Sync\GeoSchema;

/**
 * PostSync — Sync old posts with API data
 * ✅ The post is not rebuilt; only metas are enriched
 * ✅ Single (metabox button) + bulk (Tools page)
 */
class PostSync {
    const TYPES = ['hotel', 'restaurant', 'destination', 'hospital', 'airport'];

    public static function init(): void {
        add_action('add_meta_boxes', [__CLASS__, 'register_box']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_menu', [__CLASS__, 'add_bulk_page']);
        add_action('wp_ajax_ns_post_sync_candidates', [__CLASS__, 'ajax_candidates']);
        add_action('wp_ajax_ns_post_sync_apply', [__CLASS__, 'ajax_apply']);
        add_action('wp_ajax_ns_bulk_sync_step', [__CLASS__, 'ajax_bulk_step']);
    }

    /* ==========================================================================
       Sync Button Metabox
       ========================================================================== */
    public static function register_box(): void {
        foreach (self::TYPES as $type) {
            add_meta_box('ns_post_sync_box', '🔄 سینک از API', [__CLASS__, 'render_box'], $type, 'side', 'low');
        }
    }

    public static function render_box($post): void {
        $query = esc_attr(self::build_query($post));

        echo '<p><button type="button" class="button" id="ns-sync-open"
            data-post="' . (int) $post->ID . '" data-query="' . $query . '">
            🔄 دریافت اطلاعات از API</button></p>';

        echo '<p class="description">با نام پست در Google Maps (از طریق SearchApi) جستجو می‌کند و اطلاعات این پست را <strong>بدون بازسازی</strong> تکمیل می‌کند.</p>';
    }

    /* ==========================================================================
       Bulk Sync Page
       ========================================================================== */
    public static function add_bulk_page(): void {
        add_submenu_page(
            'tools.php',
            'همگام‌سازی انبوه پست‌ها',
            '🔄 سینک انبوه پست‌ها',
            'manage_options',
            'ns-bulk-sync',
            [__CLASS__, 'render_bulk_page']
        );
    }

    public static function render_bulk_page(): void {
        $count = self::count_unsynced();

        echo '<div class="wrap">';
        echo '<h1>🔄 همگام‌سازی انبوه پست‌های قدیمی</h1>';
        echo '<p>پست‌هایی که هنوز مختصات/شناسه خارجی ندارند: <strong>' . $count . '</strong></p>';
        echo '<p class="description">در هر گام ۵ پست پردازش می‌شود (مصرف اعتبار SearchApi کنترل‌شده). فقط پست‌هایی با شباهت نام کافی اعمال می‌شوند.</p>';
        echo '<button class="button button-primary" id="ns-bulk-start">شروع سینک انبوه</button> <span id="ns-bulk-progress" style="margin-inline-start:10px;font-weight:600;"></span>';
        echo '<div id="ns-bulk-log" style="margin-top:14px;background:#fff;border:1px solid #dcdcde;padding:10px;max-height:420px;overflow-y:auto;font-size:12px;line-height:1.9;"></div>';
        echo '</div>';
    }

    /* ==========================================================================
       Assets
       ========================================================================== */
    public static function assets($hook): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        $is_post_screen = $screen && in_array($screen->post_type ?? '', self::TYPES, true);
        $is_bulk_screen = ($hook === 'tools_page_ns-bulk-sync');

        if (!$is_post_screen && !$is_bulk_screen) return;

        wp_enqueue_script('ns-post-sync', plugins_url('../../assets/post-sync.js', __FILE__), ['jquery'], '1.0.0', true);

        wp_localize_script('ns-post-sync', 'NS_SYNC', [
            'nonce' => wp_create_nonce('ns_post_sync'),
        ]);
    }

    /* ==========================================================================
       AJAX: Candidate List
       ========================================================================== */
    public static function ajax_candidates(): void {
        check_ajax_referer('ns_post_sync', 'nonce');

        if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'دسترسی غیرمجاز']);

        $post_id = (int) ($_POST['post_id'] ?? 0);
        $query   = sanitize_text_field($_POST['query'] ?? '');
        $post    = get_post($post_id);

        if (!$post) wp_send_json_error(['message' => 'پست پیدا نشد']);

        if ($query === '') $query = self::build_query($post);

        $client = self::client();

        if (!$client) wp_send_json_error(['message' => 'کلید SearchApi تنظیم نشده (منو: تنظیمات API)']);

        $places = $client->search_places_by_name($query, 6, 'fa');

        if (is_wp_error($places)) wp_send_json_error(['message' => $places->get_error_message()]);

        if (empty($places)) {
            $places = $client->search_places_by_name($query, 6, 'en');

            if (is_wp_error($places)) wp_send_json_error(['message' => $places->get_error_message()]);
        }

        if (empty($places)) wp_send_json_error(['message' => 'نتیجه‌ای پیدا نشد؛ عبارت جستجو را تغییر دهید', 'query' => $query]);

        $out = [];

        foreach ($places as $p) {
            $out[] = [
                'name'       => $p['name'],
                'address'    => $p['address'] ?? '',
                'rating'     => $p['rating'] ?? 0,
                'reviews'    => $p['reviews_count'] ?? 0,
                'lat'        => $p['lat'] ?? 0,
                'lng'        => $p['lng'] ?? 0,
                'thumbnail'  => $p['thumbnail'] ?? ($p['images'][0] ?? ''),
                'type'       => $p['type'] ?? '',
                'similarity' => round(self::similarity($post->post_title, $p['name'] ?? ''), 2),
                'data'       => $p,
            ];
        }

        wp_send_json_success(['candidates' => $out, 'query' => $query]);
    }

    /* ==========================================================================
       AJAX: Apply Selected Candidate
       ========================================================================== */
    public static function ajax_apply(): void {
        check_ajax_referer('ns_post_sync', 'nonce');

        if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'دسترسی غیرمجاز']);

        $post_id = (int) ($_POST['post_id'] ?? 0);
        $data    = json_decode(wp_unslash($_POST['data'] ?? ''), true);
        $force   = !empty($_POST['force']);
        $post    = get_post($post_id);

        if (!$post || !is_array($data)) wp_send_json_error(['message' => 'داده نامعتبر']);

        $sim = self::similarity($post->post_title, $data['name'] ?? '');

        if ($sim < 0.35 && !$force) {
            wp_send_json_error(['message' => 'شباهت نام کم است (' . round($sim * 100) . '٪). اگر مطمئنی، با force اعمال کن.']);
        }

        $result = self::apply_to_post($post, $data);

        wp_send_json_success($result);
    }

    /**
     * ⭐ Hotel enrichment: the google_maps engine does not provide stars/hours;
     * run a supplementary search with the google_hotels engine and merge the best match.
     */
    private static function enrich_hotel_from_hotels_engine(array $d, string $city): array {
        $client = self::client();

        if (!$client) return $d;

        $q      = trim(($d['name'] ?? '') . ($city !== '' ? ', ' . $city : ''));
        $hotels = $client->search_hotels($q, ['limit' => 5]);

        if (is_wp_error($hotels) || empty($hotels)) return $d;

        $best = null;
        $best_sim = 0.0;

        foreach ($hotels as $h) {
            $s = self::similarity($d['name'] ?? '', $h['name'] ?? '');

            if ($s > $best_sim) {
                $best_sim = $s;
                $best = $h;
            }
        }

        if (!$best || $best_sim < 0.5) return $d;

        foreach (['stars', 'check_in_time', 'check_out_time', 'price', 'price_formatted', 'amenities', 'nearby_places', 'description'] as $k) {
            if (!empty($best[$k]) && empty($d[$k])) $d[$k] = $best[$k];
        }

        if (!empty($best['images']) && count($best['images']) > count($d['images'] ?? [])) {
            $d['images'] = $best['images'];
        }

        return $d;
    }

    /* ==========================================================================
       AJAX: One Bulk Sync Step
       ========================================================================== */
    public static function ajax_bulk_step(): void {
        check_ajax_referer('ns_post_sync', 'nonce');

        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'دسترسی غیرمجاز']);

        $batch  = min(5, max(1, (int) ($_POST['batch'] ?? 5)));
        $offset = max(0, (int) ($_POST['offset'] ?? 0));
        $ids    = self::get_unsynced_ids($batch, $offset);
        $client  = self::client();

        $results = [];

        foreach ($ids as $pid) {
            $post = get_post($pid);

            if (!$post) continue;

            if (!$client) {
                $results[] = ['id' => $pid, 'title' => $post->post_title, 'status' => 'no_key'];
                continue;
            }

            $query  = self::build_query($post);
            $places = $client->search_places_by_name($query, 3, 'fa');

            if (is_wp_error($places) || empty($places)) {
                $places = $client->search_places_by_name($query, 3, 'en');
            }

            if (is_wp_error($places) || empty($places)) {
                $results[] = ['id' => $pid, 'title' => $post->post_title, 'status' => 'no_result'];
                continue;
            }

            $best = $places[0];
            $sim  = round(self::similarity($post->post_title, $best['name'] ?? ''), 2);

            if ($sim < 0.35) {
                $results[] = ['id' => $pid, 'title' => $post->post_title, 'status' => 'low_sim', 'sim' => (int) ($sim * 100)];
                continue;
            }

            self::apply_to_post($post, $best);

            $results[] = ['id' => $pid, 'title' => $post->post_title, 'status' => 'ok', 'sim' => (int) ($sim * 100)];
        }

        wp_send_json_success([
            'results'   => $results,
            'processed' => count($ids),
            'offset'    => $offset + count($ids),
        ]);
    }

    public static function apply_to_post($post, array $d): array {
        $pid     = (int) $post->ID;
        $applied = [];

        /* Hotel enrichment before applying */
        if ($post->post_type === 'hotel') {
            $d = self::enrich_hotel_from_hotels_engine($d, $d['city'] ?? '');
        }

        /* Stars from a type string like "4-star hotel" if still empty */
        if (empty($d['stars']) && !empty($d['type']) && preg_match('/(\d+)\s*[-–]?\s*star/i', $d['type'], $m)) {
            $d['stars'] = (int) $m[1];
        }

        /* name_en only if it is an English name */
        $cand_name = $d['name_en'] ?? ($d['name'] ?? '');
        $name_en   = preg_match('/^[\x20-\x7E]+$/', $cand_name) ? $cand_name : '';

        /* 1) Common data + stars + check-in/out times */
        GeoSync::apply($pid, [
            'external_id'    => $d['place_id'] ?? '',
            'place_id'       => $d['place_id'] ?? '',
            'name_en'        => $name_en,
            'address'        => $d['address'] ?? '',
            'city'           => $d['city'] ?? '',
            'country'        => $d['country'] ?? '',
            'phone'          => $d['phone'] ?? '',
            'website'        => $d['website'] ?? '',
            'rating'         => !empty($d['rating']) ? (string) $d['rating'] : '',
            'reviews_count'  => !empty($d['reviews_count']) ? (string) $d['reviews_count'] : '',
            'price_level'    => $d['price_level'] ?? '',
            'stars'          => !empty($d['stars']) ? (string) $d['stars'] : '',
            'check_in_time'  => $d['check_in_time'] ?? '',
            'check_out_time' => $d['check_out_time'] ?? '',
            'lat'            => !empty($d['lat']) ? (string) $d['lat'] : '',
            'lng'            => !empty($d['lng']) ? (string) $d['lng'] : '',
        ], 'searchapi');

        $applied[] = 'geo+contact+stars+times';

        /* 2) Working hours */
        $hours = $d['opening_hours'] ?? ($d['hours'] ?? null);

        if (!empty($hours) && is_array($hours)) {
            update_post_meta($pid, '_geo_hours', wp_json_encode($hours));

            if ($post->post_type === 'destination') {
                update_post_meta($pid, '_destination_work_time', $hours);
            }

            $applied[] = 'hours';
        }

        /* 3) Menu link (restaurant) */
        if ($post->post_type === 'restaurant' && !empty($d['menu_link'])) {
            update_post_meta($pid, '_restaurant_menu_link', esc_url_raw($d['menu_link']));
            $applied[] = 'menu';
        }

        /* 4) Hotel amenities */
        if ($post->post_type === 'hotel' && !empty($d['amenities']) && is_array($d['amenities'])) {
            update_post_meta($pid, '_hotel_amenities', array_map('sanitize_text_field', $d['amenities']));
            $applied[] = 'amenities(' . count($d['amenities']) . ')';
        }

        /* 5) Hotel nearby places (same structure as old metabox) */
        if ($post->post_type === 'hotel' && !empty($d['nearby_places']) && is_array($d['nearby_places'])) {
            $clean = [];

            foreach ($d['nearby_places'] as $np) {
                if (empty($np['name'])) continue;

                $clean[] = [
                    'name'          => sanitize_text_field($np['name']),
                    'type'          => 'manual',
                    'category'      => sanitize_text_field($np['type'] ?? ''),
                    'coords'        => isset($np['gps_coordinates']['latitude'])
                        ? $np['gps_coordinates']['latitude'] . ',' . $np['gps_coordinates']['longitude'] : '',
                    'distance_km'   => floatval($np['distance'] ?? 0),
                    'distance_unit' => 'km',
                ];
            }

            if ($clean) {
                update_post_meta($pid, '_hotel_near_locations', $clean);
                $applied[] = 'nearby(' . count($clean) . ')';
            }
        }

        /* 6) Images: featured + gallery */
        $images = $d['images'] ?? [];

        if (!empty($images)) {
            if (!has_post_thumbnail($pid)) {
                ImageManager::set_featured_image($pid, $images[0], 'api');
                $applied[] = 'featured_image';
            }

            if (method_exists(ImageManager::class, 'fill_gallery')) {
                $n = ImageManager::fill_gallery($pid, $images, 6);

                if ($n) $applied[] = 'gallery(' . $n . ')';
            }
        }

        /* 7) Content only if empty */
        $desc = trim(wp_strip_all_tags($d['description'] ?? ''));

        if ($desc !== '' && trim($post->post_content) === '') {
            wp_update_post(['ID' => $pid, 'post_content' => sanitize_textarea_field($desc)]);
            $applied[] = 'content';
        }

        return ['applied' => $applied, 'post_id' => $pid];
    }

    /* ==========================================================================
       Helpers
       ========================================================================== */
    private static function client(): ?SearchApiClient {
        $key = get_option('nextsafar_searchapi_key', '');

        return $key ? new SearchApiClient($key) : null;
    }

    private static function build_query($post): string {
        $city    = GeoSchema::get((int) $post->ID, 'city');
        $country = GeoSchema::get((int) $post->ID, 'country');
        $parts   = array_filter([$post->post_title, $city, $country]);

        return implode(', ', $parts);
    }

    private static function similarity(string $a, string $b): float {
        $norm = function (string $s): string {
            $s = mb_strtolower($s);
            $s = str_replace(['ي', 'ك', 'ة', 'ؤ', 'إ', 'أ', 'آ'], ['ی', 'ک', 'ه', 'و', 'ا', 'ا', 'ا'], $s);
            $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

            return trim($s);
        };

        $wa = array_filter(explode(' ', $norm($a)));
        $wb = array_filter(explode(' ', $norm($b)));

        if (!$wa || !$wb) return 0.0;

        $inter = count(array_intersect($wa, $wb));

        return $inter / min(count($wa), count($wb));
    }

    private static function unsynced_meta_query(): array {
        return [
            'relation' => 'AND',
            ['key' => '_geo_external_id', 'compare' => 'NOT EXISTS'],
            ['key' => '_geo_lat', 'compare' => 'NOT EXISTS'],
        ];
    }

    private static function get_unsynced_ids(int $limit, int $offset): array {
        $q = new \WP_Query([
            'post_type'      => self::TYPES,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'fields'         => 'ids',
            'meta_query'     => self::unsynced_meta_query(),
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        return $q->posts;
    }

    public static function count_unsynced(): int {
        $q = new \WP_Query([
            'post_type'      => self::TYPES,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => self::unsynced_meta_query(),
        ]);

        return (int) $q->found_posts;
    }
}