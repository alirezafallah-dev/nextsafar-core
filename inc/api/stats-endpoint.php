<?php

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

/**
 * Live Stats Endpoint: /nextsafar/v1/stats
 * Counts published posts per post type + 1-hour cache + automatic cache invalidation
 */
class StatsEndpoint {
    const CACHE_KEY      = 'ns_stats_counts';
    const CACHE_DURATION = 3600;

    /* ========================================================================
       Initialization
       ======================================================================== */
    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);

        /* Cache is invalidated on every post save/delete */
        add_action('save_post', [__CLASS__, 'flush_cache']);
        add_action('deleted_post', [__CLASS__, 'flush_cache']);
    }

    /* ========================================================================
       Register Routes
       ======================================================================== */
    public static function register_routes() {
        register_rest_route('nextsafar/v1', '/stats', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_stats'],
            'permission_callback' => '__return_true',
        ]);
    }

    /* ========================================================================
       Get Stats
       ======================================================================== */
    public static function get_stats() {
        $cached = get_transient(self::CACHE_KEY);

        if ($cached !== false) {
            return ['counts' => $cached, 'cached' => true];
        }

        $types = [
            'hotel', 'destination', 'tour', 'visa', 'travelguide',
            'restaurant', 'airport', 'hospital', 'travelnews',
        ];

        $counts = [];

        foreach ($types as $pt) {
            if (!post_type_exists($pt)) {
                $counts[$pt] = 0;
                continue;
            }

            $c = wp_count_posts($pt);
            $counts[$pt] = (int) ($c->publish ?? 0);
        }

        set_transient(self::CACHE_KEY, $counts, self::CACHE_DURATION);

        return ['counts' => $counts];
    }

    /* ========================================================================
       Flush Cache
       ======================================================================== */
    public static function flush_cache() {
        delete_transient(self::CACHE_KEY);
    }
}