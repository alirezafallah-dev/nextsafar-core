<?php

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

use NextSafar\Database\AiTripTable;

/**
 * Endpoint: /nextsafar/v1/ai-trip-planner
 *
 * POST /generate      - Create a new plan (instant)
 * POST /process-now   - Process a plan immediately (internal, non-blocking)
 * GET  /plan/{id}     - Get a plan (with auto-trigger)
 * GET  /history       - User history
 * POST /delete        - Delete a plan
 * POST /chat          - Conversational assistant
 * GET  /plan-entities - Entities of a plan
 */
class AiTripPlannerEndpoint {
    const LOCK_KEY       = 'ns_ai_job_lock';
    const LOCK_TTL       = 150;
    const MAX_ATTEMPTS   = 3;

    /* FIX: Unified quota constants. */
    const GUEST_DAILY_LIMIT = 3;
    const USER_DAILY_LIMIT  = 20;

    /**
     * Load local secrets from inc/secrets.local.php
     */
    private static function secrets(): array {
        static $secrets = null;

        if ($secrets === null) {
            $secrets = [];

            $file = defined('NEXTSAFAR_PATH')
                ? NEXTSAFAR_PATH . 'inc/secrets.local.php'
                : dirname(__DIR__) . '/secrets.local.php';

            if (is_readable($file)) {
                $data = require $file;

                if (is_array($data)) {
                    $secrets = $data;
                }
            }
        }

        return $secrets;
    }

    /**
     * Get internal AI processing secret from secrets.
     */
    private static function process_secret(): string {
        return (string) (self::secrets()['ai_trip_process_secret'] ?? '');
    }

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_filter('cron_schedules', [__CLASS__, 'add_cron_interval']);

        add_action('ns_ai_process_plan', [__CLASS__, 'process_plan']);
        add_action('ns_ai_process_queue', [__CLASS__, 'process_queue']);

        if (wp_get_schedule('ns_ai_process_queue') === 'every_minute') {
            wp_clear_scheduled_hook('ns_ai_process_queue');
        }

        if (!wp_next_scheduled('ns_ai_process_queue')) {
            wp_schedule_event(time() + 300, 'hourly', 'ns_ai_process_queue');
        }
    }

    public static function add_cron_interval($schedules) {
        if (!isset($schedules['every_minute'])) {
            $schedules['every_minute'] = [
                'interval' => 60,
                'display'  => 'Every Minute',
            ];
        }

        return $schedules;
    }

    public static function register_routes() {
        register_rest_route('nextsafar/v1', '/ai-trip-planner/generate', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'generate'],
            'permission_callback' => '__return_true',
            'args'                => self::get_generate_args(),
        ]);

        register_rest_route('nextsafar/v1', '/ai-trip-planner/process-now', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'process_now'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('nextsafar/v1', '/ai-trip-planner/plan/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_plan'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('nextsafar/v1', '/ai-trip-planner/history', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_history'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('nextsafar/v1', '/ai-trip-planner/delete', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'delete_plan'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('nextsafar/v1', '/ai-trip-planner/chat', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'chat'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('nextsafar/v1', '/ai-trip-planner/plan-entities', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_plan_entities'],
            'permission_callback' => '__return_true',
        ]);
    }

    private static function get_generate_args(): array {
        return [
            'destination'  => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'country'      => [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'days'         => [
                'required'          => true,
                'type'              => 'integer',
                'minimum'           => 2,
                'maximum'           => 14,
                'sanitize_callback' => 'absint',
            ],
            'budget_level' => [
                'required' => true,
                'type'     => 'string',
                'enum'     => ['economy', 'medium', 'luxury'],
            ],
            'interests'    => [
                'type'  => 'array',
                'items' => ['type' => 'string'],
            ],
            'travelers'    => [
                'type'    => 'integer',
                'minimum' => 1,
                'maximum' => 20,
                'default' => 2,
            ],
            'start_date'   => [
                'required'          => false,
                'type'              => 'string',
                'validate_callback' => function ($param) {
                    return $param === null || $param === '' || is_string($param);
                },
                'sanitize_callback' => function ($param) {
                    return $param ? sanitize_text_field($param) : null;
                },
            ],
        ];
    }

    /* ========================================================================
       GENERATE
       ======================================================================== */
    public static function generate($request) {
        /* FIX: Check class existence before using it. */
        if (!class_exists('\NextSafar\API\TripProviderChain') || !TripProviderChain::has_any_provider()) {
            return new \WP_Error(
                'no_api_key',
                'کلید API برنامه سفر تنظیم نشده. از منوی «سفر AI» در ادمین تنظیم کنید.',
                ['status' => 503]
            );
        }

        $rate_check = self::check_rate_limit();

        if (!$rate_check['allowed']) {
            return new \WP_Error(
                'rate_limit_exceeded',
                $rate_check['message'],
                ['status' => 429, 'remaining' => $rate_check['remaining']]
            );
        }

        $input = [
            'destination'  => $request->get_param('destination'),
            'country'      => $request->get_param('country') ?: null,
            'days'         => (int) $request->get_param('days'),
            'budget_level' => $request->get_param('budget_level'),
            'interests'    => $request->get_param('interests') ?: [],
            'travelers'    => (int) ($request->get_param('travelers') ?: 2),
            'start_date'   => $request->get_param('start_date') ?: null,
        ];

        /* Smart cache. */
        $cached = self::find_cached_plan($input);

        if ($cached) {
            return rest_ensure_response([
                'success'         => true,
                'plan_id'         => (int) $cached['id'],
                'status'          => 'completed',
                'cached'          => true,
                'plan'            => self::format_db_plan($cached),
                'remaining_today' => self::get_remaining_requests(),
            ]);
        }

        global $wpdb;

        $table = AiTripTable::get_table_name();

        $inserted = $wpdb->insert($table, [
            'user_id'      => get_current_user_id() ?: null,
            'session_id'   => self::get_session_id(),
            'ip_address'   => self::get_client_ip(),
            'destination'  => $input['destination'],
            'country'      => $input['country'],
            'days'         => $input['days'],
            'budget_level' => $input['budget_level'],
            'interests'    => wp_json_encode($input['interests']),
            'travelers'    => $input['travelers'],
            'start_date'   => $input['start_date'],
            'days_plan'    => '[]',
            'status'       => 'pending',
            'attempts'     => 0,
            'created_at'   => current_time('mysql'),
            'expires_at'   => gmdate('Y-m-d H:i:s', strtotime('+1 year')),
        ]);

        if (!$inserted) {
            return new \WP_Error('db_error', 'خطا در ساخت رکورد', ['status' => 500]);
        }

        $plan_id = (int) $wpdb->insert_id;

        self::trigger_background_processing($plan_id);

        wp_schedule_single_event(time() + 60, 'ns_ai_process_plan', [$plan_id]);
        spawn_cron();

        return rest_ensure_response([
            'success'         => true,
            'plan_id'         => $plan_id,
            'status'          => 'pending',
            'message'         => 'برنامه در صف ساخت قرار گرفت',
            'poll_url'        => '/nextsafar/v1/ai-trip-planner/plan/' . $plan_id,
            'remaining_today' => self::get_remaining_requests(),
        ]);
    }

    /* ========================================================================
       GET PLAN ENTITIES
       ======================================================================== */
    public static function get_plan_entities($request) {
        $plan_id = (int) $request->get_param('id');

        global $wpdb;

        $table = AiTripTable::get_table_name();

        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $plan_id
        ), ARRAY_A);

        /* FIX: Check plan existence. */
        if (!$plan) {
            return new \WP_Error('not_found', 'برنامه یافت نشد', ['status' => 404]);
        }

        $days  = json_decode($plan['days_plan'] ?? '[]', true) ?: [];
        $hotel = json_decode($plan['suggested_hotels'] ?? 'null', true);

        $items_by_post_id = [];

        $add = function (string $type_hint, string $slug, string $title, int $day) use (&$items_by_post_id): void {
            if ($slug === '' && $title === '') return;

            $post = self::resolve_entity_post($type_hint, $slug, $title);

            if (!$post) return;

            $pid = (int) $post->ID;

            if (!isset($items_by_post_id[$pid])) {
                $items_by_post_id[$pid] = [
                    'post'      => $post,
                    'type_hint' => $type_hint,
                    'days'      => [],
                ];
            }

            if ($day > 0 && !in_array($day, $items_by_post_id[$pid]['days'], true)) {
                $items_by_post_id[$pid]['days'][] = $day;
            }
        };

        /* FIX: Null check for hotel. */
        if (is_array($hotel) && (!empty($hotel['title']) || !empty($hotel['slug']))) {
            $add('hotel', (string) ($hotel['slug'] ?? ''), (string) ($hotel['title'] ?? ''), 0);
        }

        foreach ($days as $day) {
            $dn = (int) ($day['day_number'] ?? 0);

            foreach ((array) ($day['activities'] ?? []) as $act) {
                $add(
                    (string) ($act['entity_type'] ?? 'custom'),
                    (string) ($act['slug'] ?? ''),
                    (string) ($act['title'] ?? ''),
                    $dn
                );
            }
        }

        $entities = [];

        foreach ($items_by_post_id as $pid => $item) {
            $post = $item['post'];

            [$lat, $lng] = \NextSafar\Sync\GeoSchema::get_latlng((int) $post->ID);

            $type = $post->post_type;

            $entities[] = [
                'type'    => $type,
                'slug'    => $post->post_name,
                'title'   => $post->post_title,
                'url'     => self::entity_url($type) . '/' . $post->post_name,
                'image'   => get_the_post_thumbnail_url($post->ID, 'medium') ?: null,
                'stars'   => (int) get_post_meta($post->ID, '_hotel_stars', true),
                'rating'  => (float) (get_post_meta($post->ID, '_hotel_rating', true)
                    ?: get_post_meta($post->ID, '_destination_rating', true)
                    ?: get_post_meta($post->ID, '_restaurant_rating', true)),
                'lat'     => $lat !== '' ? (float) $lat : null,
                'lng'     => $lng !== '' ? (float) $lng : null,
                'days'    => $item['days'],
                'excerpt' => self::post_excerpt($post),
            ];
        }

        return rest_ensure_response(['entities' => $entities]);
    }

    private static function post_excerpt($post): string {
        $ex = get_the_excerpt($post);

        if ($ex && $ex !== $post->post_title) {
            return wp_trim_words(wp_strip_all_tags($ex), 32, '…');
        }

        $content = wp_strip_all_tags($post->post_content);

        return $content !== '' ? wp_trim_words($content, 32, '…') : '';
    }

    private static function entity_url(string $type): string {
        return [
            'hotel'       => '/hotels',
            'restaurant'  => '/restaurants',
            'destination' => '/destinations',
            'airport'     => '/airports',
            'hospital'    => '/hospitals',
        ][$type] ?? '';
    }

    private static function resolve_entity_post(string $type_hint, string $slug, string $title) {
        $all = ['hotel', 'restaurant', 'destination', 'airport', 'hospital'];

        if ($slug !== '') {
            $types = ($type_hint !== '' && $type_hint !== 'custom' && in_array($type_hint, $all, true))
                ? [$type_hint]
                : $all;

            foreach ($types as $t) {
                $post = get_page_by_path($slug, OBJECT, [$t]);

                if ($post) return $post;
            }
        }

        if ($title === '') return null;

        $pool = ($type_hint !== '' && $type_hint !== 'custom' && in_array($type_hint, $all, true))
            ? [$type_hint]
            : $all;

        $ids = get_posts([
            'post_type'      => $pool,
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'fields'         => 'ids',
        ]);

        $best = null;
        $best_sim = 0.0;

        foreach ($ids as $pid) {
            $sim = self::title_similarity($title, get_the_title($pid));

            if ($sim > $best_sim) {
                $best_sim = $sim;
                $best = $pid;
            }
        }

        return ($best && $best_sim >= 0.5) ? get_post($best) : null;
    }

    private static function title_similarity(string $a, string $b): float {
        $norm = function (string $s): string {
            $s = mb_strtolower($s);
            $s = str_replace(['ي', 'ك', 'ة', 'ؤ', 'إ', 'أ', 'آ'], ['ی', 'ک', 'ه', 'و', 'ا', 'ا', 'ا'], $s);
            $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

            return trim($s);
        };

        $wa = array_filter(explode(' ', $norm($a)));
        $wb = array_filter(explode(' ', $norm($b)));

        if (!$wa || !$wb) return 0.0;

        return count(array_intersect($wa, $wb)) / min(count($wa), count($wb));
    }

    /* ========================================================================
       BACKGROUND TRIGGER
       ======================================================================== */
    private static function trigger_background_processing(int $plan_id): void {
        if (get_transient(self::LOCK_KEY)) return;

        $secret = self::process_secret();

        if ($secret === '') {
            error_log('❌ AI Trip background processing skipped: missing ai_trip_process_secret in inc/secrets.local.php');
            return;
        }

        $url = rest_url('nextsafar/v1/ai-trip-planner/process-now');

        wp_remote_post($url, [
            'blocking'  => false,
            'timeout'   => 0.01,
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => wp_json_encode([
                'plan_id' => $plan_id,
                'secret'  => $secret,
            ]),
            'sslverify' => false,
        ]);
    }

    /* ========================================================================
       PROCESS-NOW
       ======================================================================== */
    public static function process_now($request) {
        $expected = self::process_secret();
        $secret   = (string) $request->get_param('secret');

        if ($expected === '' || !hash_equals($expected, $secret)) {
            return new \WP_Error('forbidden', 'دسترسی غیرمجاز', ['status' => 403]);
        }

        $plan_id = (int) $request->get_param('plan_id');

        if ($plan_id < 1) {
            return new \WP_Error('invalid', 'ID نامعتبر', ['status' => 400]);
        }

        if (function_exists('fastcgi_finish_request')) {
            echo wp_json_encode(['status' => 'processing']);
            fastcgi_finish_request();
        } else {
            @ob_end_clean();

            header('Connection: close');

            ignore_user_abort(true);

            @ob_start();

            echo wp_json_encode(['status' => 'processing']);

            $size = ob_get_length();

            header("Content-Length: {$size}");

            @ob_end_flush();
            @flush();

            if (session_id()) session_write_close();
        }

        self::process_plan($plan_id);

        /* FIX: Exit only in web environment, not CLI. */
        if (php_sapi_name() !== 'cli') {
            exit;
        }
    }

    /* ========================================================================
       PROCESSOR
       ======================================================================== */
    public static function process_plan($plan_id) {
        $plan_id = (int) $plan_id;

        if (get_transient(self::LOCK_KEY)) return;

        set_transient(self::LOCK_KEY, $plan_id, self::LOCK_TTL);

        try {
            global $wpdb;

            $table = AiTripTable::get_table_name();

            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $plan_id
            ), ARRAY_A);

            if (!$row || $row['status'] !== 'pending') return;

            $attempts = (int) $row['attempts'] + 1;

            if ($attempts > self::MAX_ATTEMPTS) {
                $wpdb->update($table,
                    ['status' => 'failed', 'error_message' => 'Max attempts exceeded'],
                    ['id' => $plan_id]
                );

                return;
            }

            $wpdb->update($table, ['attempts' => $attempts], ['id' => $plan_id]);

            $input = [
                'destination'  => $row['destination'],
                'country'      => $row['country'],
                'days'         => (int) $row['days'],
                'budget_level' => $row['budget_level'],
                'interests'    => json_decode($row['interests'] ?? '[]', true) ?: [],
                'travelers'    => (int) $row['travelers'],
                'start_date'   => $row['start_date'],
            ];

            $context = self::collect_site_context($input);
            $prompt  = self::build_prompt($input, $context);

            $gemini = new AiTripGeminiClient(75);

            $result = TripProviderChain::generate_json_with_schema(
                $prompt,
                self::get_response_schema(),
                ['temperature' => 0.8, 'max_tokens' => 16384]
            );

            if ($result['success']) {
                $data = self::sanitize_plan_entities($result['data']);

                /* FIX: Correct indentation - this block is fully inside the if statement. */
                $wpdb->update($table, [
                    'trip_title'            => $data['title'] ?? null,
                    'trip_summary'          => $data['summary'] ?? null,
                    'days_plan'             => wp_json_encode($data['days'] ?? []),
                    'total_budget_min'      => $data['total_budget_min'] ?? null,
                    'total_budget_max'      => $data['total_budget_max'] ?? null,
                    'currency'              => $data['currency'] ?? 'USD',
                    'suggested_hotels'      => wp_json_encode($data['recommended_hotel'] ?? null),
                    'suggested_restaurants' => wp_json_encode($data['tips'] ?? []),
                    'model_used'            => $result['usage']['model'] ?? null,
                    'tokens_used'           => $result['usage']['total_tokens'] ?? 0,
                    'generation_time_ms'    => $result['usage']['duration_ms'] ?? 0,
                    'status'                => 'completed',
                    'error_message'         => null,
                    'last_viewed_at'        => current_time('mysql'),
                ], ['id' => $plan_id]);

                error_log("✅ AI Trip #{$plan_id} completed in " . ($result['usage']['duration_ms'] ?? 0) . "ms");
            } else {
                error_log("❌ AI Trip #{$plan_id} attempt {$attempts} failed: " . $result['error']);

                if ($attempts >= self::MAX_ATTEMPTS) {
                    $wpdb->update($table, [
                        'status'        => 'failed',
                        'error_message' => $result['error'],
                    ], ['id' => $plan_id]);
                } else {
                    $wpdb->update($table, [
                        'error_message' => $result['error'],
                    ], ['id' => $plan_id]);
                }
            }
        } catch (\Throwable $e) {
            error_log('❌ AI Trip processor exception: ' . $e->getMessage());
        } finally {
            delete_transient(self::LOCK_KEY);
        }
    }

    /* ========================================================================
       SANITIZE PLAN ENTITIES
       ======================================================================== */
    private static function sanitize_plan_entities(array $data): array {
        $type_map = [
            'hotel'       => 'hotel',
            'restaurant'  => 'restaurant',
            'destination' => 'destination',
            'tour'        => 'tour',
        ];

        if (!empty($data['days']) && is_array($data['days'])) {
            foreach ($data['days'] as &$day) {
                if (empty($day['activities']) || !is_array($day['activities'])) continue;

                foreach ($day['activities'] as &$act) {
                    $etype = $act['entity_type'] ?? null;
                    $slug  = $act['slug'] ?? null;

                    if ($slug && $etype && isset($type_map[$etype])) {
                        if (!self::slug_exists($slug, $type_map[$etype])) {
                            $act['slug'] = null;
                            $act['entity_type'] = 'custom';
                        }
                    } else {
                        $act['slug'] = null;
                    }
                }

                unset($act);
            }

            unset($day);
        }

        /* FIX: Complete null check for recommended_hotel. */
        if (is_array($data['recommended_hotel'] ?? null) && !empty($data['recommended_hotel']['slug'])) {
            if (!self::slug_exists($data['recommended_hotel']['slug'], 'hotel')) {
                $data['recommended_hotel']['slug'] = null;
            }
        }

        return $data;
    }

    private static function slug_exists(string $slug, string $post_type): bool {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_name = %s AND post_type = %s AND post_status = 'publish'
             LIMIT 1",
            $slug,
            $post_type
        ));
    }

    /* ========================================================================
       GET PLAN
       ======================================================================== */
    public static function get_plan($request) {
        $plan_id = (int) $request->get_param('id');

        global $wpdb;

        $table = AiTripTable::get_table_name();

        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $plan_id
        ), ARRAY_A);

        if (!$plan) {
            return new \WP_Error('not_found', 'برنامه یافت نشد', ['status' => 404]);
        }

        if (!self::can_access_plan($plan)) {
            return new \WP_Error('forbidden', 'دسترسی غیرمجاز', ['status' => 403]);
        }

        if ($plan['status'] === 'pending') {
            $age    = time() - strtotime($plan['created_at']);
            $locked = (bool) get_transient(self::LOCK_KEY);

            if ($age > 5 && $age <= 15 && !$locked) {
                self::trigger_background_processing($plan_id);
            }

            $cooldown = get_transient('ns_ai_inline_' . $plan_id);

            if ($age > 15 && !$locked && !$cooldown && (int) $plan['attempts'] < self::MAX_ATTEMPTS) {
                set_transient('ns_ai_inline_' . $plan_id, 1, 30);

                error_log("🔄 AI Trip #{$plan_id}: inline processing (age {$age}s)");

                @set_time_limit(120);

                self::process_plan($plan_id);

                $plan = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$table} WHERE id = %d",
                    $plan_id
                ), ARRAY_A);
            }

            if ($plan && $plan['status'] === 'pending' && (int) $plan['attempts'] >= self::MAX_ATTEMPTS) {
                $wpdb->update($table, [
                    'status'        => 'failed',
                    'error_message' => 'Max attempts exceeded (pending timeout)',
                ], ['id' => $plan_id]);

                $plan['status'] = 'failed';
            }
        }

        if ($plan['status'] === 'completed') {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET view_count = view_count + 1, last_viewed_at = NOW() WHERE id = %d",
                $plan_id
            ));
        }

        return rest_ensure_response(self::format_db_plan($plan));
    }

    public static function get_history($request) {
        global $wpdb;

        $table = AiTripTable::get_table_name();

        $user_id    = get_current_user_id();
        $session_id = $request->get_param('session_id');

        if ($user_id > 0) {
            $plans = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d AND status = 'completed' ORDER BY created_at DESC LIMIT 50",
                $user_id
            ), ARRAY_A);
        } elseif ($session_id) {
            $plans = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE session_id = %s AND status = 'completed' ORDER BY created_at DESC LIMIT 50",
                sanitize_text_field($session_id)
            ), ARRAY_A);
        } else {
            $plans = [];
        }

        return rest_ensure_response([
            'plans' => array_map([__CLASS__, 'format_db_plan'], $plans),
            'count' => count($plans),
        ]);
    }

    public static function delete_plan($request) {
        $plan_id = (int) $request->get_param('id');

        global $wpdb;

        $table = AiTripTable::get_table_name();

        $plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $plan_id
        ), ARRAY_A);

        if (!$plan) {
            return new \WP_Error('not_found', 'یافت نشد', ['status' => 404]);
        }

        if (!self::can_access_plan($plan)) {
            return new \WP_Error('forbidden', 'دسترسی غیرمجاز', ['status' => 403]);
        }

        $wpdb->delete($table, ['id' => $plan_id], ['%d']);

        return rest_ensure_response(['success' => true]);
    }

    /* ========================================================================
       CHAT
       ======================================================================== */
    public static function chat($request) {
        $message = sanitize_text_field((string) $request->get_param('message'));
        $history = (array) ($request->get_param('history') ?: []);

        if (mb_strlen($message) < 2) {
            return new \WP_Error('empty', 'پیام خالی است', ['status' => 400]);
        }

        $rl = self::check_chat_rate_limit();

        if (!$rl['allowed']) {
            return new \WP_Error('chat_limit', $rl['message'], ['status' => 429]);
        }

        if (!class_exists('\NextSafar\API\TripProviderChain') || !TripProviderChain::has_any_provider()) {
            return new \WP_Error('no_api_key', 'کلید AI تنظیم نشده (منو: سفر AI)', ['status' => 503]);
        }

        $intent = self::quick_intent($message);

        if (!$intent) {
            $intent = self::extract_intent($message, $history);
        }

        if (!$intent) {
            /* FIX: More precise failure cause check. */
            $has_provider = class_exists('\NextSafar\API\TripProviderChain')
                && TripProviderChain::has_any_provider();

            $debug_msg = $has_provider
                ? 'سرویس در دسترس است اما پاسخ نداد. لاگ‌ها را چک کنید.'
                : 'هیچ کلید معتبری برای سرویس پیدا نشد.';

            error_log("❌ AI Chat - Intent extraction failed. has_provider: " . ($has_provider ? 'true' : 'false'));

            return rest_ensure_response([
                'type'        => 'answer',
                'text'        => 'الان سرویس در دسترس نیست. لطفاً ۱-۲ دقیقه بعد دوباره امتحان کن 🙂',
                'suggestions' => self::default_suggestions(),
                /* Enable only in development environment. */
                // 'debug'    => $debug_msg,
            ]);
        }

        $subject = sanitize_text_field($intent['subject'] ?? '');
        $dest    = sanitize_text_field($intent['destination'] ?? '');

        $site_posts = [];

        /* FIX: If subject is empty, extract it from the original message. */
        if ($subject === '' && $message !== '') {
            $subject = self::extract_subject_from_message($message);
        }

        if (in_array($intent['intent'] ?? '', ['recommend', 'question'], true)) {
            if ($subject !== '') {
                $site_posts = self::find_posts_by_title($subject, 4);
            }

            if (!$site_posts && $dest !== '') {
                $kinds = array_values(array_intersect(
                    (array) ($intent['kinds'] ?? []),
                    ['hotel', 'destination', 'restaurant', 'hospital', 'airport']
                ));

                if (!$kinds) $kinds = ['hotel', 'destination'];

                $site_posts = self::find_city_posts($dest, $kinds, 8);
            }
        }

        if (($intent['intent'] ?? '') === 'recommend') {
            return rest_ensure_response(self::handle_recommend($intent, $site_posts));
        }

        if (($intent['intent'] ?? '') === 'plan' && $dest !== '') {
            $input = [
                'destination'  => $dest,
                'country'      => sanitize_text_field($intent['country'] ?? '') ?: null,
                'days'         => min(14, max(2, (int) ($intent['days'] ?? 3))),
                'budget_level' => in_array($intent['budget_level'] ?? '', ['economy', 'medium', 'luxury'], true) ? $intent['budget_level'] : 'medium',
                'interests'    => array_map('sanitize_text_field', (array) ($intent['interests'] ?? [])),
                'travelers'    => min(20, max(1, (int) ($intent['travelers'] ?? 2))),
                'start_date'   => !empty($intent['start_date']) ? sanitize_text_field($intent['start_date']) : null,
            ];

            $cached = self::find_cached_plan($input);

            if ($cached) {
                return rest_ensure_response([
                    'type'    => 'plan',
                    'plan_id' => (int) $cached['id'],
                    'text'    => 'این برنامه رو از قبل آماده داشتم برات 😍',
                    'input'   => $input,
                    'cached'  => true,
                ]);
            }

            $plan_id = self::create_plan_record($input);

            if (is_wp_error($plan_id)) return $plan_id;

            self::trigger_background_processing($plan_id);

            wp_schedule_single_event(time() + 60, 'ns_ai_process_plan', [$plan_id]);

            return rest_ensure_response([
                'type'    => 'plan',
                'plan_id' => $plan_id,
                'text'    => "عالی! دارم برای «{$input['destination']}» یک برنامه {$input['days']} روزه می‌چینم 🗺️ چند لحظه صبر کن...",
                'input'   => $input,
            ]);
        }

        $answer = self::answer_question($message, $intent, $history, $site_posts);

        $out = [
            'type'        => 'answer',
            'text'        => $answer['text'] ?? 'الان نمی‌تونم جواب بدم؛ دوباره تلاش کن.',
            'suggestions' => $answer['suggestions'] ?? [],
        ];

        if ($site_posts) {
            $out['entities']    = array_map(fn($p) => self::build_entity($p), $site_posts);
            $out['panel_title'] = $subject !== '' ? $subject : ('مرتبط با ' . $dest);
        }

        return rest_ensure_response($out);
    }

    /**
     * FIX: Extract subject from message when patterns fail.
     */
    private static function extract_subject_from_message(string $message): string {
        $msg = trim($message);

        /* Common Persian patterns. */
        $patterns = [
            '/درباره\s+(.+?)\s+(بهم|به من|برام)?\s*(بگو|توضیح|معرفی)/u',
            '/^(.+?)\s+(چطوره|چطوریه|چیه|رو بگو|را بگو)/u',
            '/(هتل|رستوران|مقصد|فرودگاه|بیمارستان)\s+(.+)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $msg, $m)) {
                $subject = trim($m[1] ?? $m[2] ?? '');

                return self::clean_subject($subject);
            }
        }

        return '';
    }

    private static function check_chat_rate_limit(): array {
        $user_id = get_current_user_id();

        $max = $user_id > 0 ? 60 : 15;

        $key = 'ns_chat_count_' . ($user_id > 0 ? 'u' . $user_id : 'ip' . md5(self::get_client_ip())) . '_' . gmdate('Ymd');

        $count = (int) get_transient($key);

        if ($count >= $max) {
            return [
                'allowed' => false,
                'message' => 'سهمیه گفتگوی امروزت تمام شد؛ فردا دوباره در خدمتم 🙂',
            ];
        }

        set_transient($key, $count + 1, DAY_IN_SECONDS);

        return [
            'allowed'   => true,
            'remaining' => $max - $count - 1,
        ];
    }

    private static function quick_intent(string $message): ?array {
        $msg = mb_strtolower(trim($message));

        /* FIX: Improved pattern - also recognize "بهم", "به من", "برام". */
        if (preg_match('/درباره\s+(.+?)\s+(بهم|به من|برام|برایم)?\s*(بگو|توضیح\s*بده|چطوره|چطوریه|معرفی\s*کن|چیه|بگو بهم)/u', $msg, $m)) {
            $subject = self::clean_subject(trim($m[1]));

            return [
                'intent'      => 'recommend',
                'subject'     => $subject,
                'destination' => '',
                'kinds'       => [],
            ];
        }

        /* FIX: Alternative pattern - "فلان رو بگو/معرفی کن". */
        if (preg_match('/^(.+?)\s+(رو|را)?\s*(بگو|توضیح بده|معرفی کن|چطوره|چطوریه|بگو بهم)/u', $msg, $m)) {
            $subject = self::clean_subject(trim($m[1]));

            if (mb_strlen($subject) >= 3 && mb_strlen($subject) <= 50) {
                return [
                    'intent'      => 'recommend',
                    'subject'     => $subject,
                    'destination' => '',
                    'kinds'       => [],
                ];
            }
        }

        /* FIX: Pattern for "درباره فلان بگو بهم" - reverse form. */
        if (preg_match('/^(.+?)\s+(چیه|چطوره|چطوریه)\?*\s*$/u', $msg, $m)) {
            $subject = self::clean_subject(trim($m[1]));

            if (mb_strlen($subject) >= 3 && mb_strlen($subject) <= 50) {
                return [
                    'intent'      => 'recommend',
                    'subject'     => $subject,
                    'destination' => '',
                    'kinds'       => [],
                ];
            }
        }

        return null;
    }

    /**
     * FIX: Remove extra words from search subject.
     * "هتل گلدن ایج بهم" → "هتل گلدن ایج"
     */
    private static function clean_subject(string $subject): string {
        /* Extra words that should not be included in search. */
        $stop_words = [
            'بهم', 'به من', 'برام', 'برایم', 'لطفاً', 'لطفا',
            'بگو', 'توضیح بده', 'معرفی کن', 'چطوره', 'چطوریه', 'چیه',
            'را', 'رو', 'یه', 'یک', 'در', 'از', 'برای',
            'بگو بهم', 'بگو به من', 'توضیح بده', 'معرفی کن',
        ];

        $subject = trim($subject);

        /* Remove extra words from end and beginning. */
        $subject = preg_replace('/[\s]*(' . implode('|', array_map(fn($w) => preg_quote($w, '/'), $stop_words)) . ')[\s]*$/u', '', $subject);
        $subject = preg_replace('/^(' . implode('|', array_map(fn($w) => preg_quote($w, '/'), $stop_words)) . ')[\s]*/u', '', $subject);

        /* Remove extra spaces. */
        $subject = preg_replace('/\s+/u', ' ', $subject);

        return trim($subject);
    }

    private static function extract_intent(string $message, array $history): ?array {
        $hist = self::flatten_history($history, 6);

        $prompt = "تو دستیار سفر سایت NextSafar هستی. پیام کاربر و تاریخچه را ببین و نیت را تشخیص بده.
"
            . "قوانین تشخیص نیت:
"
            . "- plan: کاربر برنامه روزبه‌روز/چند روزه می‌خواهد.
"
            . "- recommend: کاربر معرفی/پیشنهاد هتل، مقصد، رستوران یا دیدنی می‌خواهد.
"
            . "- question: سایر سوال‌های سفر.
"
            . ($hist ? "تاریخچه گفتگو:
{$hist}
" : "")
            . "پیام کاربر: {$message}
"
            . "خروجی فقط JSON.";

        $schema = [
            'type'       => 'object',
            'properties' => [
                'intent'       => ['type' => 'string', 'enum' => ['plan', 'recommend', 'question']],
                'subject'      => ['type' => 'string'],
                'destination'  => ['type' => 'string'],
                'country'      => ['type' => 'string'],
                'days'         => ['type' => 'integer'],
                'travelers'    => ['type' => 'integer'],
                'budget_level' => ['type' => 'string', 'enum' => ['economy', 'medium', 'luxury']],
                'interests'    => ['type' => 'array', 'items' => ['type' => 'string']],
                'start_date'   => ['type' => 'string'],
                'question'     => ['type' => 'string'],
                'kinds'        => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['hotel', 'destination', 'restaurant', 'hospital', 'airport']]],
            ],
            'required'   => ['intent'],
        ];

        /* FIX: Precise error log for debugging. */
        try {
            $res = TripProviderChain::generate_json_with_schema($prompt, $schema, ['temperature' => 0.2, 'max_tokens' => 512]);

            if (!$res['success']) {
                error_log("❌ AI Chat - extract_intent failed: " . ($res['error'] ?? 'unknown error'));
                error_log("❌ AI Chat - Full response: " . wp_json_encode($res));

                return null;
            }

            return $res['data'];
        } catch (\Throwable $e) {
            error_log("❌ AI Chat - extract_intent exception: " . $e->getMessage());
            error_log("❌ AI Chat - Stack trace: " . $e->getTraceAsString());

            return null;
        }
    }

    private static function answer_question(string $message, array $intent, array $history, array $site_posts = []): array {
        $q = sanitize_text_field($intent['question'] ?? $message);

        if ($site_posts) {
            $rows = [];

            foreach ($site_posts as $p) {
                $rows[] = '- ' . get_post_type($p) . ': «' . $p->post_title . '»'
                    . ' | ستاره: ' . (get_post_meta($p->ID, '_hotel_stars', true) ?: '-')
                    . ' | امتیاز: ' . (get_post_meta($p->ID, '_hotel_rating', true) ?: get_post_meta($p->ID, '_destination_rating', true) ?: '-')
                    . ' | شهر: ' . (get_post_meta($p->ID, '_geo_city', true) ?: '-')
                    . ' | خلاصه: ' . wp_trim_words(wp_strip_all_tags($p->post_content), 40, '…');
            }

            $context = implode("
", $rows);
        } else {
            $context = self::quick_site_context($q);
        }

        $hist = self::flatten_history($history, 6);

        $prompt = "تو دستیار صمیمی سفر سایت NextSafar هستی. فقط به حوزه سفر پاسخ بده.
"
            . ($context ? "داده‌های واقعی سایت ما:
{$context}
" : "")
            . ($hist ? "تاریخچه گفتگو:
{$hist}
" : "")
            . "سوال کاربر: {$q}
"
            . "پاسخ فارسی صمیمی، حداکثر ۱۲۰ کلمه. در انتها ۲ پیشنهاد پیگیری کوتاه بده.
"
            . "خروجی JSON.";

        $schema = [
            'type'       => 'object',
            'properties' => [
                'text'        => ['type' => 'string'],
                'suggestions' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required'   => ['text'],
        ];

        $res = TripProviderChain::generate_json_with_schema($prompt, $schema, ['temperature' => 0.4, 'max_tokens' => 1024]);

        return $res['success'] ? $res['data'] : ['text' => '', 'suggestions' => []];
    }

    private static function quick_site_context(string $q): string {
        $posts = get_posts([
            'post_type'      => ['hotel', 'destination', 'restaurant', 'visa', 'travelguide'],
            's'              => $q,
            'posts_per_page' => 5,
            'post_status'    => 'publish',
        ]);

        if (!$posts) return '';

        $base = [
            'hotel'       => 'hotels',
            'destination' => 'destinations',
            'restaurant'  => 'restaurants',
            'visa'        => 'visa',
            'travelguide' => 'travelguide',
        ];

        $lines = [];

        foreach ($posts as $p) {
            $t = get_post_type($p);

            $lines[] = '- [' . $t . '] «' . $p->post_title . '» → /' . ($base[$t] ?? '') . '/' . $p->post_name;
        }

        return implode("
", $lines);
    }

    private static function create_plan_record(array $input) {
        global $wpdb;

        $table = AiTripTable::get_table_name();

        $ok = $wpdb->insert($table, [
            'user_id'      => get_current_user_id() ?: null,
            'session_id'   => self::get_session_id(),
            'ip_address'   => self::get_client_ip(),
            'destination'  => $input['destination'],
            'country'      => $input['country'],
            'days'         => $input['days'],
            'budget_level' => $input['budget_level'],
            'interests'    => wp_json_encode($input['interests']),
            'travelers'    => $input['travelers'],
            'start_date'   => $input['start_date'],
            'days_plan'    => '[]',
            'status'       => 'pending',
            'attempts'     => 0,
            'created_at'   => current_time('mysql'),
            'expires_at'   => gmdate('Y-m-d H:i:s', strtotime('+1 year')),
        ]);

        if (!$ok) return new \WP_Error('db_error', 'خطا در ساخت رکورد', ['status' => 500]);

        return (int) $wpdb->insert_id;
    }

    private static function flatten_history(array $history, int $limit): string {
        $history = array_slice($history, -$limit);

        $lines = [];

        foreach ($history as $m) {
            $role = ($m['role'] ?? '') === 'user' ? 'کاربر' : 'دستیار';

            $lines[] = $role . ': ' . mb_substr(sanitize_text_field($m['content'] ?? ''), 0, 300);
        }

        return implode("
", $lines);
    }

    private static function default_suggestions(): array {
        return [
            'برنامه ۳ روزه استانبول',
            'هتل ارزان در دبی',
            'ویزای ترکیه چه مدارکی می‌خواد؟',
        ];
    }

    private static function handle_recommend(array $intent, array $posts): array {
        $dest    = sanitize_text_field($intent['destination'] ?? '');
        $subject = sanitize_text_field($intent['subject'] ?? '');

        $kinds = array_values(array_intersect(
            (array) ($intent['kinds'] ?? []),
            ['hotel', 'destination', 'restaurant', 'hospital', 'airport']
        ));

        if (!$kinds) $kinds = ['hotel', 'destination'];

        /* Mode 1: A specific post was found on the site. */
        if ($subject !== '' && $posts) {
            $entities = array_map(fn($p) => self::build_entity($p), $posts);

            $p = $posts[0];

            /* FIX: Collect complete post information to build a rich description. */
            $rich_data = self::collect_entity_rich_data($p);

            /* FIX: Use AI to write a friendly Persian description. */
            $ai_description = self::generate_entity_description($rich_data, $subject);

            if ($ai_description !== '') {
                $text = $ai_description;
            } else {
                /* Fallback: If AI did not respond, build it from our own data. */
                $text = self::build_manual_description($rich_data, $p);
            }

            $text .= "
📌 جزئیات کامل، تصاویر و موقعیتش روی نقشه رو سمت راست می‌بینی 🗺️";

            return [
                'type'        => 'answer',
                'text'        => $text,
                'suggestions' => [
                    "برنامه ۳ روزه {$dest}",
                    "هتل‌های مشابه {$subject}",
                    "رستوران‌های خوب {$dest}",
                ],
                'entities'    => $entities,
                'panel_title' => $subject,
            ];
        }

        /* Mode 2: The post is not on the site but destination is known. */
        if ($subject !== '' && !$posts) {
            $ans  = self::answer_question("درباره {$subject} توضیح بده", ['question' => "درباره {$subject} توضیح بده"], [], []);

            $text = ($ans['text'] !== '' ? $ans['text'] : "اطلاعات دقیقی درباره «{$subject}» ندارم.")
                . "
(این مکان در سایت ما صفحه اختصاصی ندارد.)";

            return [
                'type'        => 'answer',
                'text'        => $text,
                'suggestions' => $ans['suggestions'] ?: ["هتل‌های خوب {$dest}", "برنامه ۳ روزه {$dest}"],
            ];
        }

        /* Mode 3: Suggestion list based on city. */
        $emoji = [
            'hotel'       => '🏨',
            'destination' => '📍',
            'restaurant'  => '🍽️',
            'hospital'    => '🏥',
            'airport'     => '✈️',
        ];

        $entities = [];
        $lines    = [];

        foreach ($posts as $post) {
            $e = self::build_entity($post);

            $entities[] = $e;

            $meta = [];

            if ($e['stars'] > 0)  $meta[] = $e['stars'] . ' ستاره';
            if ($e['rating'] > 0) $meta[] = 'امتیاز ' . $e['rating'];

            $city = get_post_meta($post->ID, '_geo_city', true);

            if ($city) $meta[] = $city;

            $lines[] = ($emoji[$e['type']] ?? '•') . ' ' . $e['title'] . ($meta ? ' (' . implode('، ', $meta) . ')' : '');
        }

        $extra = [];

        if ($dest !== '' && count($posts) < 4) {
            $extra = self::real_world_suggestions($dest, $kinds, 4 - count($posts));
        }

        if (!$posts && !$extra) {
            return [
                'type'        => 'answer',
                'text'        => "متأسفانه هنوز پستی برای «{$dest}» در سایت نداریم؛ ولی می‌تونم برات برنامه سفر روزبه‌روز بچینم 🙂",
                'suggestions' => ["برنامه ۳ روزه {$dest}", 'ویزای ترکیه چه مدارکی می‌خواد؟'],
            ];
        }

        $text = '';

        if ($lines) {
            $text .= "عالی! این‌ها رو از بین پست‌های خودمون برات جدا کردم:
" . implode("
", $lines);
        }

        if ($extra) {
            $text .= ($text !== '' ? "
" : '') . "🌐 چند پیشنهاد واقعی دیگر:
";

            foreach ($extra as $it) {
                $text .= '• ' . ($it['name'] ?? '')
                    . (($it['area'] ?? '') !== '' ? ' (' . $it['area'] . ')' : '')
                    . (($it['reason'] ?? '') !== '' ? ' — ' . $it['reason'] : '') . "
";
            }
        }

        $text .= "
اگر خواستی برنامه روزبه‌روز کامل هم بچینم، بگو 🗺️";

        return [
            'type'        => 'answer',
            'text'        => $text,
            'suggestions' => ["برنامه ۳ روزه {$dest}", "رستوران‌های خوب {$dest}"],
            'entities'    => $entities,
            'panel_title' => "پیشنهادهای {$dest}",
        ];
    }

    /**
     * FIX: Collect all information of a post to build a rich description.
     */
    private static function collect_entity_rich_data($post): array {
        $type = $post->post_type;

        $data = [
            'type'     => $type,
            'title'    => $post->post_title,
            'content'  => wp_trim_words(wp_strip_all_tags($post->post_content), 150, ''),
            'excerpt'  => get_the_excerpt($post),
            'city'     => get_post_meta($post->ID, '_geo_city', true),
            'country'  => get_post_meta($post->ID, '_geo_country', true),
            'address'  => get_post_meta($post->ID, '_geo_address', true),
            'phone'    => get_post_meta($post->ID, '_geo_phone', true),
            'website'  => get_post_meta($post->ID, '_geo_website', true),
            'lat'      => get_post_meta($post->ID, '_geo_lat', true),
            'lng'      => get_post_meta($post->ID, '_geo_lng', true),
        ];

        /* Hotel-specific fields. */
        if ($type === 'hotel') {
            $data['stars']        = (int) get_post_meta($post->ID, '_hotel_stars', true);
            $data['rating']       = get_post_meta($post->ID, '_hotel_rating', true);
            $data['reviews']      = get_post_meta($post->ID, '_hotel_reviews_count', true);
            $data['checkin']      = get_post_meta($post->ID, '_hotel_checkin', true);
            $data['checkout']     = get_post_meta($post->ID, '_hotel_checkout', true);
            $data['amenities']    = get_post_meta($post->ID, '_hotel_amenities', true);
            $data['instructions'] = get_post_meta($post->ID, '_hotel_instructions', true);
        }

        /* Destination-specific fields. */
        if ($type === 'destination') {
            $data['rating']    = get_post_meta($post->ID, '_destination_rating', true);
            $data['reviews']   = get_post_meta($post->ID, '_destination_reviews_count', true);
            $data['wikipedia'] = get_post_meta($post->ID, '_destination_wikipedia', true);
        }

        /* Restaurant-specific fields. */
        if ($type === 'restaurant') {
            $data['rating']       = get_post_meta($post->ID, '_restaurant_rating', true);
            $data['reviews']      = get_post_meta($post->ID, '_restaurant_reviews_count', true);
            $data['price_level']  = get_post_meta($post->ID, '_restaurant_price_level', true);
            $data['cuisine_type'] = get_post_meta($post->ID, '_restaurant_type', true);
        }

        /* Categories and tags. */
        $taxonomies = get_object_taxonomies($type, 'names');

        $terms_str = [];

        foreach ($taxonomies as $tax) {
            $terms = get_the_terms($post->ID, $tax);

            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $terms_str[] = $term->name;
                }
            }
        }

        $data['categories'] = implode('، ', array_slice($terms_str, 0, 8));

        return $data;
    }

    /**
     * FIX: Use AI to write a rich, friendly Persian description.
     */
    private static function generate_entity_description(array $rich_data, string $subject): string {
        /* Build information text for AI. */
        $info_lines = [];

        $info_lines[] = "نام: {$rich_data['title']}";
        $info_lines[] = "نوع: {$rich_data['type']}";

        if (!empty($rich_data['city']))      $info_lines[] = "شهر: {$rich_data['city']}";
        if (!empty($rich_data['country']))   $info_lines[] = "کشور: {$rich_data['country']}";
        if (!empty($rich_data['address']))   $info_lines[] = "آدرس: {$rich_data['address']}";
        if (!empty($rich_data['stars']))     $info_lines[] = "ستاره: {$rich_data['stars']}";
        if (!empty($rich_data['rating']))    $info_lines[] = "امتیاز: {$rich_data['rating']}";
        if (!empty($rich_data['reviews']))   $info_lines[] = "تعداد نظرات: {$rich_data['reviews']}";
        if (!empty($rich_data['checkin']))   $info_lines[] = "ساعت ورود: {$rich_data['checkin']}";
        if (!empty($rich_data['checkout']))  $info_lines[] = "ساعت خروج: {$rich_data['checkout']}";
        if (!empty($rich_data['amenities'])) $info_lines[] = "امکانات: {$rich_data['amenities']}";
        if (!empty($rich_data['categories'])) $info_lines[] = "دسته‌بندی: {$rich_data['categories']}";
        if (!empty($rich_data['price_level'])) $info_lines[] = "سطح قیمت: {$rich_data['price_level']}";
        if (!empty($rich_data['cuisine_type'])) $info_lines[] = "نوع غذا: {$rich_data['cuisine_type']}";

        if (!empty($rich_data['content'])) {
            $info_lines[] = "توضیحات سایت: {$rich_data['content']}";
        }

        if (!empty($rich_data['excerpt'])) {
            $info_lines[] = "خلاصه: {$rich_data['excerpt']}";
        }

        $info_text = implode("
", $info_lines);

        $prompt = <<<PROMPT
تو یک نویسنده حرفه‌ای سفر برای سایت «سفر بعدی» (NextSafar) هستی.
اطلاعات زیر درباره «{$subject}» را داری:
{$info_text}
## وظیفه تو:
یک توضیح فارسی، صمیمی، جذاب و کاربردی درباره «{$subject}» بنویس.
## قوانین:
1. لحن دوستانه و صمیمی (مثل یک دوست که تجربه سفر دارد)
2. حداکثر ۱۵۰ کلمه
3. اگر اطلاعات محدود است، بر اساس دانش عمومی‌ات درباره این مکان تکمیل کن
4. شامل: معرفی کلی، ویژگی‌های برجسته، مناسب برای چه کسانی، و یک نکته کاربردی
5. اگر ستاره/امتیاز دارد، حتماً ذکر کن
6. اگر امکانات خاصی دارد، چند مورد مهم را نام ببر
7. در انتها یک جمله انگیزشی برای سفر بگو
8. از ایموجی مناسب استفاده کن (حداکثر ۳-۴ تا)
فقط متن توضیح را بنویس. بدون عنوان، بدون علامت نقل‌قول.
PROMPT;

        try {
            $result = TripProviderChain::generate_json_with_schema(
                $prompt,
                [
                    'type'       => 'object',
                    'properties' => [
                        'description' => ['type' => 'string'],
                    ],
                    'required'   => ['description'],
                ],
                ['temperature' => 0.7, 'max_tokens' => 800]
            );

            if ($result['success'] && !empty($result['data']['description'])) {
                return trim($result['data']['description']);
            }

            return '';
        } catch (\Throwable $e) {
            error_log("❌ generate_entity_description error: " . $e->getMessage());

            return '';
        }
    }

    /**
     * FIX: Build manual description when AI is unavailable.
     */
    private static function build_manual_description(array $data, $post): string {
        $type_labels = [
            'hotel'       => 'هتل',
            'destination' => 'جاذبه گردشگری',
            'restaurant'  => 'رستوران',
            'hospital'    => 'بیمارستان',
            'airport'     => 'فرودگاه',
        ];

        $type_label = $type_labels[$data['type']] ?? 'مکان';

        $parts = [];

        /* Introduction. */
        $intro = "🏨 «{$data['title']}»";

        if (!empty($data['city'])) {
            $intro .= " در {$data['city']}";

            if (!empty($data['country'])) $intro .= " ({$data['country']})";
        }

        $parts[] = $intro;

        /* Stars and rating. */
        $meta = [];

        if (!empty($data['stars']))   $meta[] = "⭐ {$data['stars']} ستاره";
        if (!empty($data['rating']))  $meta[] = "📊 امتیاز {$data['rating']}";
        if (!empty($data['reviews'])) $meta[] = "💬 {$data['reviews']} نظر";

        if ($meta) $parts[] = implode(' | ', $meta);

        /* Amenities. */
        if (!empty($data['amenities'])) {
            $parts[] = "🛎️ امکانات: " . $data['amenities'];
        }

        /* Check-in / check-out times. */
        if (!empty($data['checkin']) || !empty($data['checkout'])) {
            $time_info = "🕐";

            if ($data['checkin'])  $time_info .= " ورود: {$data['checkin']}";
            if ($data['checkout']) $time_info .= " | خروج: {$data['checkout']}";

            $parts[] = $time_info;
        }

        /* Content description. */
        if (!empty($data['content'])) {
            $parts[] = "
" . $data['content'];
        } elseif (!empty($data['excerpt'])) {
            $parts[] = "
" . $data['excerpt'];
        }

        /* Address. */
        if (!empty($data['address'])) {
            $parts[] = "📍 آدرس: {$data['address']}";
        }

        /* Final sentence. */
        $parts[] = "
✨ این {$type_label} رو حتماً در لیست سفرت قرار بده!";

        return implode("
", $parts);
    }

    private static function real_world_suggestions(string $dest, array $kinds, int $count): array {
        if ($dest === '' || $count < 1) return [];

        $fa = [
            'hotel'       => 'هتل',
            'destination' => 'جاذبه دیدنی',
            'restaurant'  => 'رستوران',
            'hospital'    => 'بیمارستان',
            'airport'     => 'فرودگاه',
        ];

        $kinds_fa = implode('، ', array_map(fn($k) => $fa[$k] ?? $k, $kinds));

        $prompt = "برای شهر {$dest} دقیقاً {$count} مورد واقعی و مشهور از نوع ({$kinds_fa}) پیشنهاد بده. فقط JSON.";

        $schema = [
            'type'       => 'object',
            'properties' => [
                'items' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'name'   => ['type' => 'string'],
                            'area'   => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                        ],
                        'required'   => ['name'],
                    ],
                ],
            ],
            'required'   => ['items'],
        ];

        $res = TripProviderChain::generate_json_with_schema($prompt, $schema, ['temperature' => 0.3, 'max_tokens' => 512]);

        return $res['success'] ? array_slice($res['data']['items'] ?? [], 0, $count) : [];
    }

    private static function find_posts_by_title(string $subject, int $limit): array {
        $types = ['hotel', 'destination', 'restaurant', 'hospital', 'airport'];

        /* FIX: Direct search. */
        $posts = get_posts([
            'post_type'      => $types,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            's'              => $subject,
        ]);

        if ($posts) return $posts;

        /* FIX: Search in English name and title. */
        global $wpdb;

        $like = '%' . $wpdb->esc_like($subject) . '%';

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_geo_name_en'
             WHERE p.post_status = 'publish'
             AND p.post_type IN ('hotel','destination','restaurant','hospital','airport')
             AND (p.post_title LIKE %s OR pm.meta_value LIKE %s)
             LIMIT %d",
            $like,
            $like,
            $limit
        ));

        if ($ids) {
            $posts = array_filter(array_map('get_post', $ids));

            if ($posts) return $posts;
        }

        /* FIX: Word-by-word search - if full phrase is not found. */
        $words = array_filter(explode(' ', $subject));

        if (count($words) > 1) {
            /* Search each word separately and score. */
            $score_map = [];

            foreach ($words as $word) {
                if (mb_strlen($word) < 2) continue;

                $word_like = '%' . $wpdb->esc_like($word) . '%';

                $word_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT p.ID
                     FROM {$wpdb->posts} p
                     WHERE p.post_status = 'publish'
                     AND p.post_type IN ('hotel','destination','restaurant','hospital','airport')
                     AND p.post_title LIKE %s",
                    $word_like
                ));

                foreach ($word_ids as $pid) {
                    $score_map[$pid] = ($score_map[$pid] ?? 0) + 1;
                }
            }

            /* Sort by score. */
            arsort($score_map);

            $best_ids = array_slice(array_keys($score_map), 0, $limit);

            if ($best_ids) {
                $posts = array_filter(array_map('get_post', $best_ids));

                if ($posts) return $posts;
            }
        }

        return [];
    }

    private static function find_city_posts(string $dest, array $kinds, int $limit): array {
        if ($dest === '') return [];

        $posts = get_posts([
            'post_type'      => $kinds,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => '_geo_city',    'value' => $dest, 'compare' => 'LIKE'],
                ['key' => '_geo_country', 'value' => $dest, 'compare' => 'LIKE'],
            ],
        ]);

        if (!$posts) {
            $posts = get_posts([
                'post_type'      => $kinds,
                'post_status'    => 'publish',
                'posts_per_page' => $limit,
                's'              => $dest,
            ]);
        }

        return $posts ?: [];
    }

    private static function build_entity($post): array {
        [$lat, $lng] = \NextSafar\Sync\GeoSchema::get_latlng((int) $post->ID);

        $type = $post->post_type;

        return [
            'type'    => $type,
            'slug'    => $post->post_name,
            'title'   => $post->post_title,
            'url'     => self::entity_url($type) . '/' . $post->post_name,
            'image'   => get_the_post_thumbnail_url($post->ID, 'medium') ?: null,
            'stars'   => (int) get_post_meta($post->ID, '_hotel_stars', true),
            'rating'  => (float) (
                get_post_meta($post->ID, '_hotel_rating', true) ?:
                get_post_meta($post->ID, '_destination_rating', true) ?:
                get_post_meta($post->ID, '_restaurant_rating', true) ?: 0
            ),
            'lat'     => $lat !== '' ? (float) $lat : null,
            'lng'     => $lng !== '' ? (float) $lng : null,
            'days'    => [],
            'excerpt' => self::post_excerpt($post),
        ];
    }

    public static function process_queue() {
        if (get_transient(self::LOCK_KEY)) return;

        global $wpdb;

        $table = AiTripTable::get_table_name();

        $pending = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE status = 'pending' AND attempts < %d ORDER BY id ASC LIMIT 1",
            self::MAX_ATTEMPTS
        ));

        if ($pending) self::process_plan((int) $pending);
    }

    private static function find_cached_plan(array $input): ?array {
        global $wpdb;

        $table = AiTripTable::get_table_name();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE status = 'completed'
             AND destination = %s
             AND days = %d
             AND budget_level = %s
             AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY created_at DESC LIMIT 1",
            $input['destination'],
            $input['days'],
            $input['budget_level']
        ), ARRAY_A);

        return $row ?: null;
    }

    private static function collect_site_context(array $input): array {
        $context = [
            'hotels'       => [],
            'tours'        => [],
            'restaurants'  => [],
            'destinations' => [],
        ];

        $city = $input['destination'];

        $hotels = self::find_posts_by_city('hotel', $city, 5);

        foreach ($hotels as $h) {
            $stars = (int) get_post_meta($h->ID, '_hotel_stars', true);

            $context['hotels'][] = [
                'id'    => $h->ID,
                'title' => $h->post_title,
                'slug'  => $h->post_name,
                'stars' => $stars ?: 3,
                'url'   => '/hotels/' . $h->post_name,
            ];
        }

        $tours = self::find_posts_by_city('tour', $city, 3);

        foreach ($tours as $t) {
            $context['tours'][] = [
                'id'    => $t->ID,
                'title' => $t->post_title,
                'slug'  => $t->post_name,
                'url'   => '/tours/' . $t->post_name,
            ];
        }

        $restaurants = self::find_posts_by_city('restaurant', $city, 5);

        foreach ($restaurants as $r) {
            $context['restaurants'][] = [
                'id'    => $r->ID,
                'title' => $r->post_title,
                'slug'  => $r->post_name,
                'url'   => '/restaurants/' . $r->post_name,
            ];
        }

        $dests = self::find_posts_by_city('destination', $city, 10);

        foreach ($dests as $d) {
            $context['destinations'][] = [
                'id'    => $d->ID,
                'title' => $d->post_title,
                'slug'  => $d->post_name,
                'url'   => '/destinations/' . $d->post_name,
            ];
        }

        return $context;
    }

    private static function find_posts_by_city(string $post_type, string $city, int $limit): array {
        $term = get_term_by('name', $city, 'tourism');

        if ($term && !is_wp_error($term)) {
            $posts = get_posts([
                'post_type'      => $post_type,
                'posts_per_page' => $limit,
                'post_status'    => 'publish',
                'tax_query'      => [
                    [
                        'taxonomy' => 'tourism',
                        'field'    => 'term_id',
                        'terms'    => $term->term_id,
                    ],
                ],
            ]);

            if (!empty($posts)) return $posts;
        }

        return get_posts([
            'post_type'      => $post_type,
            'posts_per_page' => $limit,
            'post_status'    => 'publish',
            's'              => $city,
        ]);
    }

    private static function build_prompt(array $input, array $context): string {
        $budget_label = [
            'economy' => 'اقتصادی و به‌صرفه',
            'medium'  => 'متوسط و متعادل',
            'luxury'  => 'لوکس و بی‌نظیر',
        ][$input['budget_level']] ?? 'متوسط و متعادل';

        $interests_str = !empty($input['interests'])
            ? 'علاقه‌مندی‌های کاربر: ' . implode('، ', $input['interests'])
            : 'علاقه‌مندی خاصی ذکر نشده، ترکیبی متنوع پیشنهاد بده';

        $country     = !empty($input['country']) ? $input['country'] : 'نامشخص';
        $days        = (int) $input['days'];
        $travelers   = (int) $input['travelers'];
        $destination = $input['destination'];

        $hotels_list = !empty($context['hotels'])
            ? "هتل‌های موجود در سایت ما برای این شهر:
"
            : "هتل خاصی در سایت ما برای این شهر ثبت نشده، خودت پیشنهاد بده.
";

        if (!empty($context['hotels'])) {
            foreach ($context['hotels'] as $h) {
                $hotels_list .= "- «{$h['title']}» ({$h['stars']} ستاره) - slug: {$h['slug']}
";
            }
        }

        $restaurants_list = !empty($context['restaurants'])
            ? "رستوران‌های موجود در سایت ما:
"
            : "رستوران خاصی در سایت ما ثبت نشده، خودت پیشنهاد بده.
";

        if (!empty($context['restaurants'])) {
            foreach ($context['restaurants'] as $r) {
                $restaurants_list .= "- «{$r['title']}» - slug: {$r['slug']}
";
            }
        }

        $dests_list = !empty($context['destinations'])
            ? "جاذبه‌های گردشگری موجود در سایت ما:
"
            : "جاذبه خاصی در سایت ما ثبت نشده، خودت پیشنهاد بده.
";

        if (!empty($context['destinations'])) {
            foreach ($context['destinations'] as $d) {
                $dests_list .= "- «{$d['title']}» - slug: {$d['slug']}
";
            }
        }

        return <<<PROMPT
تو یک برنامه‌ریز سفر حرفه‌ای، صمیمی و با تجربه هستی.
## اطلاعات درخواست کاربر:
- **مقصد:** {$destination}
- **کشور:** {$country}
- **مدت سفر:** {$days} روز
- **بودجه:** {$budget_label}
- **تعداد مسافر:** {$travelers} نفر
- {$interests_str}
## داده‌های واقعی سایت ما (حتماً از این‌ها استفاده کن):
{$hotels_list}
{$restaurants_list}
{$dests_list}
## دستورالعمل‌ها:
1. لحن دوستانه و صمیمی.
2. اولویت با داده‌های سایت.
3. تنوع روزانه.
4. واقع‌بینانه باش.
5. بودجه‌بندی برای هر روز.
6. slug‌ها رو دقیق استفاده کن.
7. عنوان جذاب برای کل سفر.
8. خلاصه دوستانه.
9. فقط برای آیتم‌های لیست بالا slug و entity_type پر کن. برای بقیه slug خالی و entity_type = custom.
10. هتل پیشنهادی فقط از لیست سایت.
حالا یک برنامه سفر کامل برای {$days} روز در {$destination} بساز.
PROMPT;
    }

    private static function get_response_schema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'title'             => ['type' => 'string'],
                'summary'           => ['type' => 'string'],
                'total_budget_min'  => ['type' => 'number'],
                'total_budget_max'  => ['type' => 'number'],
                'currency'          => ['type' => 'string'],
                'tips'              => ['type' => 'array', 'items' => ['type' => 'string']],
                'days'              => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'day_number' => ['type' => 'integer'],
                            'title'      => ['type' => 'string'],
                            'theme'      => ['type' => 'string'],
                            'activities' => [
                                'type'  => 'array',
                                'items' => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'time'        => ['type' => 'string'],
                                        'title'       => ['type' => 'string'],
                                        'description' => ['type' => 'string'],
                                        'type'        => ['type' => 'string', 'enum' => ['visit', 'food', 'hotel', 'transport', 'activity', 'shopping', 'rest']],
                                        'slug'        => ['type' => 'string'],
                                        'entity_type' => ['type' => 'string', 'enum' => ['hotel', 'restaurant', 'destination', 'tour', 'custom']],
                                        'icon'        => ['type' => 'string'],
                                    ],
                                    'required'   => ['time', 'title', 'description', 'type'],
                                ],
                            ],
                            'budget_min' => ['type' => 'number'],
                            'budget_max' => ['type' => 'number'],
                            'tip_of_day' => ['type' => 'string'],
                        ],
                        'required'   => ['day_number', 'title', 'theme', 'activities', 'budget_min', 'budget_max'],
                    ],
                ],
                'recommended_hotel' => [
                    'type'       => 'object',
                    'properties' => [
                        'title'  => ['type' => 'string'],
                        'slug'   => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                    ],
                    'required'   => ['title', 'reason'],
                ],
            ],
            'required'   => ['title', 'summary', 'days', 'tips'],
        ];
    }

    /* FIX: Unified rate limiting. */
    private static function check_rate_limit(): array {
        $user_id = get_current_user_id();

        if ($user_id > 0) {
            return self::check_user_rate_limit($user_id, self::USER_DAILY_LIMIT);
        }

        return self::check_guest_rate_limit(self::get_client_ip(), self::GUEST_DAILY_LIMIT);
    }

    private static function check_user_rate_limit(int $user_id, int $max_per_day): array {
        global $wpdb;

        $table = AiTripTable::get_table_name();

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND DATE(created_at) = CURDATE()",
            $user_id
        ));

        $remaining = max(0, $max_per_day - $count);

        if ($count >= $max_per_day) {
            return [
                'allowed'   => false,
                'message'   => "سهمیه روزانه شما ({$max_per_day} برنامه) تمام شده.",
                'remaining' => 0,
            ];
        }

        return [
            'allowed'   => true,
            'remaining' => $remaining,
        ];
    }

    private static function check_guest_rate_limit(string $ip, int $max_per_day): array {
        global $wpdb;

        $table = AiTripTable::get_table_name();

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE ip_address = %s AND user_id IS NULL AND DATE(created_at) = CURDATE()",
            $ip
        ));

        $remaining = max(0, $max_per_day - $count);

        if ($count >= $max_per_day) {
            return [
                'allowed'   => false,
                'message'   => "سهمیه رایگان امروز شما ({$max_per_day} برنامه) تمام شد.",
                'remaining' => 0,
            ];
        }

        return [
            'allowed'   => true,
            'remaining' => $remaining,
        ];
    }

    /* FIX: Unified with check_rate_limit. */
    private static function get_remaining_requests(): int {
        $user_id = get_current_user_id();

        if ($user_id > 0) {
            return self::check_user_rate_limit($user_id, self::USER_DAILY_LIMIT)['remaining'];
        }

        return self::check_guest_rate_limit(self::get_client_ip(), self::GUEST_DAILY_LIMIT)['remaining'];
    }

    private static function get_session_id(): string {
        if (!empty($_COOKIE['ns_trip_sid'])) {
            return substr(sanitize_text_field($_COOKIE['ns_trip_sid']), 0, 64);
        }

        $sid = bin2hex(random_bytes(16));

        if (!headers_sent()) {
            setcookie('ns_trip_sid', $sid, time() + YEAR_IN_SECONDS, '/', '', false, true);
        }

        return $sid;
    }

    private static function get_client_ip(): string {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = explode(',', $_SERVER[$header])[0];

                return trim($ip);
            }
        }

        return '127.0.0.1';
    }

    private static function can_access_plan(array $plan): bool {
        $user_id = get_current_user_id();

        if ($user_id > 0 && (int) $plan['user_id'] === $user_id) return true;

        if (empty($plan['user_id'])) return ($plan['session_id'] === self::get_session_id());

        return false;
    }

    private static function format_db_plan(array $row): array {
        return [
            'id'                => (int) $row['id'],
            'status'            => $row['status'],
            'attempts'          => (int) ($row['attempts'] ?? 0),
            'title'             => $row['trip_title'],
            'summary'           => $row['trip_summary'],
            'days'              => json_decode($row['days_plan'] ?? '[]', true),
            'tips'              => json_decode($row['suggested_restaurants'] ?? '[]', true),
            'total_budget_min'  => $row['total_budget_min'] ? (float) $row['total_budget_min'] : null,
            'total_budget_max'  => $row['total_budget_max'] ? (float) $row['total_budget_max'] : null,
            'currency'          => $row['currency'] ?? 'USD',
            'recommended_hotel' => json_decode($row['suggested_hotels'] ?? 'null', true),
            'input'             => [
                'destination'  => $row['destination'],
                'country'      => $row['country'],
                'days'         => (int) $row['days'],
                'budget_level' => $row['budget_level'],
                'interests'    => json_decode($row['interests'] ?? '[]', true),
                'travelers'    => (int) $row['travelers'],
                'start_date'   => $row['start_date'],
            ],
            'meta'              => [
                'model_used'         => $row['model_used'],
                'tokens_used'        => (int) $row['tokens_used'],
                'generation_time_ms' => (int) $row['generation_time_ms'],
                'created_at'         => $row['created_at'],
                'view_count'         => (int) $row['view_count'],
                'error_message'      => $row['error_message'] ?? null,
            ],
        ];
    }
}