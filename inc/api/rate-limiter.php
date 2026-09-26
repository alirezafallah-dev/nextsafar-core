<?php

/**
 * NextSafar Rate Limiter
 * Controls the rate of API requests to prevent being blocked.
 *
 * @version 1.0.0
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

class RateLimiter {
    /**
     * @var array<string, float> Last request time for each service
     */
    private static $last_request = [];

    /**
     * @var array<string, int> Consecutive request counter
     */
    private static $request_count = [];

    /**
     * @var array<string, int> Maximum allowed requests before delay
     */
    private const MAX_BURST = [
        'rss'        => 10,
        'gnews'      => 5,
        'newsdata'   => 5,
        'currents'   => 5,
        'mediastack' => 5,
        'searchapi'  => 3,
        'serpapi'    => 3,
    ];

    /**
     * Wait if needed
     *
     * @param string $service Service name (rss, gnews, ...)
     * @param float  $seconds Minimum interval between requests
     */
    public static function wait_if_needed(string $service, float $seconds = 1.0): void {
        $now     = microtime(true);
        $last    = self::$last_request[$service] ?? 0;
        $count   = self::$request_count[$service] ?? 0;
        $elapsed = $now - $last;

        // Increment counter
        self::$request_count[$service] = $count + 1;

        // If burst limit reached, enforce a longer pause
        $max_burst = self::MAX_BURST[$service] ?? 5;

        if (self::$request_count[$service] >= $max_burst) {
            $seconds = max($seconds, 3.0); // At least 3 seconds pause
            self::$request_count[$service] = 0;
        }

        // If not enough time has passed, wait
        if ($elapsed < $seconds) {
            $sleep_microseconds = ($seconds - $elapsed) * 1000000;
            usleep((int) $sleep_microseconds);
        }

        self::$last_request[$service] = microtime(true);
    }

    /**
     * Reset state for a service
     */
    public static function reset(string $service): void {
        unset(self::$last_request[$service]);
        unset(self::$request_count[$service]);
    }

    /**
     * Reset all services
     */
    public static function reset_all(): void {
        self::$last_request  = [];
        self::$request_count = [];
    }

    /**
     * Get current status (for debugging)
     */
    public static function get_status(): array {
        return [
            'last_request'  => self::$last_request,
            'request_count' => self::$request_count,
        ];
    }
}