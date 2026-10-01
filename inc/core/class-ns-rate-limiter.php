<?php
/**
 * NextSafar Core - Rate Limiter
 * سیستم محدودیت درخواست با پشتیبانی از IP و User
 *
 * @package NextSafar\Core
 * @since   2.6.0
 */

namespace NextSafar\Core;

if (!defined('ABSPATH')) exit;

class RateLimiter {

    /**
     * پیشوند کلیدهای ذخیره‌سازی
     */
    const PREFIX = 'ns_rate_limit:';

    /**
     * Default rate limits for each endpoint
     */
    private static $default_limits = [
        // Hotel search: 30 requests per 10 minutes
        'hotel_search' => [
            'max_requests' => 30,
            'window_seconds' => 600,
            'message' => 'تعداد جستجوهای شما بیش از حد مجاز است. لطفاً چند دقیقه صبر کنید.',
        ],
        // Flight search: 30 requests per 10 minutes
        'flight_search' => [
            'max_requests' => 30,
            'window_seconds' => 600,
            'message' => 'تعداد جستجوهای شما بیش از حد مجاز است. لطفاً چند دقیقه صبر کنید.',
        ],
        // Hotel details: 60 requests per 10 minutes
        'hotel_details' => [
            'max_requests' => 60,
            'window_seconds' => 600,
            'message' => 'تعداد درخواست‌های شما بیش از حد مجاز است.',
        ],
        // General: 100 requests per hour
        'general' => [
            'max_requests' => 100,
            'window_seconds' => 3600,
            'message' => 'تعداد درخواست‌های شما بیش از حد مجاز است. لطفاً بعداً تلاش کنید.',
        ],
        // Login: 5 attempts per 15 minutes
        'login' => [
            'max_requests' => 5,
            'window_seconds' => 900,
            'message' => 'تعداد تلاش‌های ورود بیش از حد مجاز است. لطفاً 15 دقیقه صبر کنید.',
        ],
        
        // ─────────────────────────────────────────────────────────
        // BOOKING ENDPOINTS (New in Phase 3)
        // ─────────────────────────────────────────────────────────
        
        // Booking creation: 10 per hour (prevent spam)
        'booking_create' => [
            'max_requests' => 10,
            'window_seconds' => 3600,
            'message' => 'تعداد رزروهای شما بیش از حد مجاز است. لطفاً بعداً تلاش کنید.',
        ],
        
        // Document upload: 50 per hour
        'booking_upload' => [
            'max_requests' => 50,
            'window_seconds' => 3600,
            'message' => 'تعداد بارگذاری‌های شما بیش از حد مجاز است.',
        ],
    ];

    /**
     * بررسی محدودیت درخواست
     *
     * @param string $endpoint نام اندپوینت
     * @return array [
     *     'allowed'     => bool,      آیا مجاز است؟
     *     'remaining'   => int,       تعداد باقیمانده
     *     'retry_after' => int,       زمان انتظار به ثانیه
     *     'message'     => string,    پیام خطا (در صورت عدم مجاز بودن)
     * ]
     */
    public static function check(string $endpoint = 'general'): array {
        $config = self::$default_limits[$endpoint] ?? self::$default_limits['general'];
        $identifier = self::get_identifier();
        $cache_key = self::PREFIX . $endpoint . ':' . $identifier;

        // دریافت داده‌های فعلی
        $data = get_transient($cache_key);

        if ($data === false) {
            // اولین درخواست
            $data = [
                'count'      => 1,
                'started_at' => time(),
            ];
            set_transient($cache_key, $data, $config['window_seconds']);

            return [
                'allowed'     => true,
                'remaining'   => $config['max_requests'] - 1,
                'retry_after' => 0,
                'message'     => '',
            ];
        }

        $elapsed = time() - $data['started_at'];

        // آیا پنجره زمانی تمام شده؟
        if ($elapsed >= $config['window_seconds']) {
            // شروع پنجره جدید
            $data = [
                'count'      => 1,
                'started_at' => time(),
            ];
            set_transient($cache_key, $data, $config['window_seconds']);

            return [
                'allowed'     => true,
                'remaining'   => $config['max_requests'] - 1,
                'retry_after' => 0,
                'message'     => '',
            ];
        }

        // آیا محدودیت رد شده؟
        if ($data['count'] >= $config['max_requests']) {
            $retry_after = $config['window_seconds'] - $elapsed;

            Logger::warning('Rate limit exceeded', [
                'endpoint'   => $endpoint,
                'identifier' => $identifier,
                'count'      => $data['count'],
            ]);

            return [
                'allowed'     => false,
                'remaining'   => 0,
                'retry_after' => $retry_after,
                'message'     => $config['message'],
            ];
        }

        // افزایش شمارنده
        $data['count']++;
        set_transient($cache_key, $data, $config['window_seconds'] - $elapsed);

        return [
            'allowed'     => true,
            'remaining'   => $config['max_requests'] - $data['count'],
            'retry_after' => 0,
            'message'     => '',
        ];
    }

    /**
     * دریافت شناسه یکتا (IP یا User ID)
     */
    private static function get_identifier(): string {
        $user_id = get_current_user_id();

        if ($user_id > 0) {
            return 'user_' . $user_id;
        }

        return 'ip_' . Logger::get_client_ip();
    }

    /**
     * Middleware برای REST API
     * این تابع را در permission_callback اندپوینت‌ها استفاده کنید
     *
     * @param string $endpoint نام اندپوینت
     * @return callable
     */
    public static function middleware(string $endpoint = 'general'): callable {
        return function ($request) use ($endpoint) {
            $result = self::check($endpoint);

            if (!$result['allowed']) {
                return new \WP_Error(
                    'rate_limit_exceeded',
                    $result['message'],
                    [
                        'status'      => 429,
                        'retry_after' => $result['retry_after'],
                    ]
                );
            }

            return true;
        };
    }

    /**
     * افزودن هدرهای Rate Limit به پاسخ
     *
     * @param \WP_REST_Response $response   پاسخ
     * @param string            $endpoint   نام اندپوینت
     * @return \WP_REST_Response
     */
    public static function add_headers(\WP_REST_Response $response, string $endpoint = 'general'): \WP_REST_Response {
        $config = self::$default_limits[$endpoint] ?? self::$default_limits['general'];

        $response->header('X-RateLimit-Limit', $config['max_requests']);
        $response->header('X-RateLimit-Window', $config['window_seconds']);

        return $response;
    }

    /**
     * ریست کردن محدودیت برای یک کاربر (برای ادمین)
     */
    public static function reset(string $endpoint = 'general', ?int $user_id = null): void {
        if ($user_id !== null) {
            $identifier = 'user_' . $user_id;
        } else {
            $identifier = self::get_identifier();
        }

        $cache_key = self::PREFIX . $endpoint . ':' . $identifier;
        delete_transient($cache_key);
    }

    /**
     * پاک کردن تمام محدودیت‌ها (برای ادمین)
     */
    public static function reset_all(): int {
        global $wpdb;
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '%\_transient\_' . self::PREFIX . '%',
                '%\_transient\_timeout\_' . self::PREFIX . '%'
            )
        );

        Logger::info('All rate limits reset', ['deleted' => $deleted]);
        return (int) $deleted;
    }
}