<?php

namespace NextSafar;

if (!defined('ABSPATH')) exit;

/**
 * Deactivator — Manages plugin deactivation
 *
 * Note: Tables are NOT deleted in this method.
 * Tables are only deleted when the plugin is completely uninstalled,
 * so that if the user temporarily deactivates the plugin, their data is preserved.
 */
class Deactivator {

    /** Option key for storing database version */
    const DB_VERSION_OPTION = 'nextsafar_db_version';

    /**
     * Execute deactivation operations
     */
    public static function deactivate() {
        /* Clear all scheduled cron jobs */
        self::clear_all_cron_jobs();

        /* Flush rewrite rules */
        flush_rewrite_rules();

        /* Clear temporary transients */
        self::clear_temporary_transients();

        /* Record deactivation time (for debugging) */
        update_option('nextsafar_deactivated_at', current_time('mysql'));

        error_log('✅ NextSafar Core: Plugin deactivated successfully');
    }

    /**
     * Clear all scheduled cron jobs
     */
    private static function clear_all_cron_jobs(): void {
        $cron_hooks = [
            /* News system cron */
            'nextsafar_news_cron_hook',

            /* Other crons (if any) */
            'ns_enrich_locations_cron',
            'nextsafar_exchange_update_cron',
            'nextsafar_sync_cron',
        ];

        foreach ($cron_hooks as $hook) {
            wp_clear_scheduled_hook($hook);
            error_log('🗑️ NextSafar: Cron hook cleared: ' . $hook);
        }
    }

    /**
     * Clear temporary transients (not permanent data)
     *
     * Note: Only cache transients are cleared, not important data
     */
    private static function clear_temporary_transients(): void {
        global $wpdb;

        /* List of temporary transient patterns */
        $patterns = [
            'ns_ai_rates_cache',
            'visa_api_rates_cache',
            'nextsafar_news_overview_stats',
            'nextsafar_news_public_stats',
            'ns_ai_consecutive_fails',
            'ns_ai_fail_count',
            'ns_ai_disabled_until',
        ];

        foreach ($patterns as $pattern) {
            delete_transient($pattern);
        }

        /* Clear maps cache with pattern */
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_ns_maps_%'
                OR option_name LIKE '_transient_timeout_ns_maps_%'"
        );

        error_log('🗑️ NextSafar: Temporary transients cleared');
    }
}