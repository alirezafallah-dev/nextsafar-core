<?php

namespace NextSafar\Database;

if (!defined('ABSPATH')) exit;

/**
 * AiTripTable — Database table management for AI Trip Planner
 *
 * Handles creation, upgrade, cleanup, and uninstallation of the
 * ai_trip_plans table used by the AI Trip Planner feature.
 */
class AiTripTable {
    const TABLE_NAME   = 'ai_trip_plans';
    const DB_VERSION   = '1.1.0';
    const OPTION_KEY   = 'ns_ai_trip_table_version';

    /**
     * Get the full table name with WordPress prefix.
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Initialize hooks and schedule cleanup.
     */
    public static function init() {
        add_action('init', [__CLASS__, 'maybe_upgrade']);
        add_action('ns_cleanup_ai_trips', [__CLASS__, 'cleanup_old_plans']);

        if (!wp_next_scheduled('ns_cleanup_ai_trips')) {
            wp_schedule_event(time(), 'daily', 'ns_cleanup_ai_trips');
        }
    }

    /**
     * Check and upgrade table if version is outdated.
     */
    public static function maybe_upgrade() {
        $current = get_option(self::OPTION_KEY, '0');

        if (version_compare($current, self::DB_VERSION, '>=')) {
            return;
        }

        self::create_table();
        update_option(self::OPTION_KEY, self::DB_VERSION);
    }

    /**
     * Create the ai_trip_plans table.
     */
    public static function create_table() {
        global $wpdb;

        $table   = self::get_table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NULL,
            session_id varchar(64) NULL,
            ip_address varchar(45) NULL,
            destination varchar(100) NOT NULL,
            country varchar(100) NULL,
            days int unsigned NOT NULL DEFAULT 3,
            budget_level enum('economy','medium','luxury') NOT NULL DEFAULT 'medium',
            interests json NULL,
            travelers int unsigned NOT NULL DEFAULT 2,
            start_date date NULL,
            trip_title varchar(255) NULL,
            trip_summary text NULL,
            days_plan json NOT NULL,
            total_budget_min bigint NULL,
            total_budget_max bigint NULL,
            currency varchar(10) DEFAULT 'USD',
            suggested_hotels json NULL,
            suggested_tours json NULL,
            suggested_restaurants json NULL,
            model_used varchar(50) NULL,
            tokens_used int unsigned DEFAULT 0,
            generation_time_ms int unsigned DEFAULT 0,
            status enum('pending','completed','failed','expired') NOT NULL DEFAULT 'pending',
            attempts tinyint unsigned NOT NULL DEFAULT 0,
            error_message text NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime NULL,
            last_viewed_at datetime NULL,
            view_count int unsigned DEFAULT 1,
            PRIMARY KEY  (id),
            KEY idx_user (user_id),
            KEY idx_session (session_id),
            KEY idx_ip (ip_address),
            KEY idx_status (status),
            KEY idx_expires (expires_at),
            KEY idx_created (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Force drop and recreate the table.
     */
    public static function force_recreate() {
        global $wpdb;

        $table = self::get_table_name();

        $wpdb->query("DROP TABLE IF EXISTS {$table}");
        delete_option(self::OPTION_KEY);

        self::create_table();
        update_option(self::OPTION_KEY, self::DB_VERSION);
    }

    /**
     * Clean up old plans.
     * - Guest plans: deleted after 90 days
     * - User plans: deleted after 1 year
     * - Failed plans: deleted after 30 days
     */
    public static function cleanup_old_plans() {
        global $wpdb;

        $table = self::get_table_name();

        $wpdb->query("DELETE FROM {$table} WHERE user_id IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
        $wpdb->query("DELETE FROM {$table} WHERE user_id IS NOT NULL AND created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)");
        $wpdb->query("DELETE FROM {$table} WHERE status = 'failed' AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    }

    /**
     * Uninstall: drop table and clean up.
     */
    public static function uninstall() {
        global $wpdb;

        $table = self::get_table_name();

        $wpdb->query("DROP TABLE IF EXISTS {$table}");
        delete_option(self::OPTION_KEY);
        wp_clear_scheduled_hook('ns_cleanup_ai_trips');
    }
}