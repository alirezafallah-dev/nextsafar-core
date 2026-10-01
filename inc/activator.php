<?php

namespace NextSafar;

use NextSafar\Database\GeoTable;

/**
 * Activator — Manages plugin activation
 *
 * This class is responsible for:
 * - Creating custom database tables
 * - Registering post types and taxonomies
 * - Creating custom user roles
 * - Scheduling cron jobs
 * - Flushing rewrite rules
 */
class Activator {

    /**
     * Main activation method
     */
    public static function activate() {
        /* Create custom tables */
        self::create_sync_log_table();
        self::create_hotel_prices_table();
        self::create_hotel_availability_table();
        self::create_price_history_table();
        self::create_filter_log_table();

        /* Create geo table */
        GeoTable::create_table();

        // Create Booking and Payment tables
        \NextSafar\Booking\BookingTable::create_table();
        \NextSafar\Payment\PaymentTable::create_table();

        // Booking passengers table
        \NextSafar\Booking\BookingPassengerTable::create_table();
        // Booking documents table
        \NextSafar\Booking\BookingDocumentTable::create_table();

        /* Register post types and taxonomies */
        Core::register_post_types();
        Core::register_taxonomies();

        /* Create custom user roles */
        self::create_custom_roles();

        /* Schedule cron jobs */
        self::schedule_cron_jobs();

        /* Flush rewrite rules */
        flush_rewrite_rules();

        /* Record plugin version */
        update_option('nextsafar_version', NEXTSAFAR_VERSION);
        update_option('nextsafar_activated_at', current_time('mysql'));

        error_log('✅ NextSafar Core: Plugin activated successfully');
    }

    /**
     * Create sync log table
     */
    public static function create_sync_log_table(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . 'api_sync_log';
        $charset_collate = $wpdb->get_charset_collate();

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) return;

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source VARCHAR(50) NOT NULL DEFAULT 'searchapi',
            entity_type VARCHAR(50) NOT NULL,
            status ENUM('running','completed','failed','partial') DEFAULT 'running',
            records_fetched INT DEFAULT 0,
            records_created INT DEFAULT 0,
            records_updated INT DEFAULT 0,
            records_failed INT DEFAULT 0,
            error_message TEXT,
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY source (source),
            KEY entity_type (entity_type),
            KEY status (status),
            KEY started_at (started_at)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Create hotel prices table (Future-proof)
     * Supports two modes:
     * - Hotel with post: hotel_post_id is filled
     * - Online hotel (like future with Parto): hotel_external_id is filled
     */
    public static function create_hotel_prices_table(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . 'hotel_prices';
        $charset_collate = $wpdb->get_charset_collate();

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) return;

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hotel_post_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'WordPress post ID (if hotel is in our database)',
            hotel_external_id VARCHAR(255) DEFAULT NULL COMMENT 'External ID (for online hotels like Parto)',
            check_in DATE NOT NULL,
            check_out DATE NOT NULL,
            room_type VARCHAR(100) DEFAULT 'standard',
            price DECIMAL(10,2) NOT NULL,
            currency VARCHAR(10) DEFAULT 'USD',
            source VARCHAR(50) DEFAULT 'searchapi',
            expires_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY hotel_post_id (hotel_post_id),
            KEY hotel_external_id (hotel_external_id),
            KEY check_in (check_in),
            KEY check_out (check_out),
            KEY expires_at (expires_at),
            INDEX idx_post_dates (hotel_post_id, check_in, check_out),
            INDEX idx_ext_dates (hotel_external_id, check_in, check_out)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Create hotel availability table (Future-proof)
     */
    public static function create_hotel_availability_table(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . 'hotel_availability';
        $charset_collate = $wpdb->get_charset_collate();

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) return;

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hotel_post_id BIGINT UNSIGNED DEFAULT NULL,
            hotel_external_id VARCHAR(255) DEFAULT NULL,
            check_in DATE NOT NULL,
            check_out DATE NOT NULL,
            room_type VARCHAR(100) DEFAULT 'standard',
            available TINYINT(1) DEFAULT 1,
            rooms_left INT DEFAULT 0,
            source VARCHAR(50) DEFAULT 'searchapi',
            expires_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY hotel_post_id (hotel_post_id),
            KEY hotel_external_id (hotel_external_id),
            KEY check_in (check_in),
            KEY check_out (check_out),
            KEY expires_at (expires_at),
            INDEX idx_post_dates (hotel_post_id, check_in, check_out),
            INDEX idx_ext_dates (hotel_external_id, check_in, check_out)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Create price history table
     */
    public static function create_price_history_table(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . 'price_history';
        $charset_collate = $wpdb->get_charset_collate();

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) return;

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hotel_post_id BIGINT UNSIGNED DEFAULT NULL,
            hotel_external_id VARCHAR(255) DEFAULT NULL,
            check_in DATE NOT NULL,
            check_out DATE NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            currency VARCHAR(10) DEFAULT 'USD',
            source VARCHAR(50) DEFAULT 'searchapi',
            recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY hotel_post_id (hotel_post_id),
            KEY hotel_external_id (hotel_external_id),
            KEY check_in (check_in),
            KEY recorded_at (recorded_at),
            INDEX idx_post_date (hotel_post_id, check_in)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Create news filter log table
     */
    public static function create_filter_log_table(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . 'ns_news_filter_log';
        $charset_collate = $wpdb->get_charset_collate();

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) return;

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            news_title VARCHAR(500) NOT NULL,
            source_name VARCHAR(100),
            score INT DEFAULT 0,
            decision ENUM('publish', 'draft', 'delete') DEFAULT 'publish',
            reason TEXT,
            positive_matches TEXT,
            negative_matches TEXT,
            ai_checked TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_decision (decision),
            INDEX idx_created (created_at),
            INDEX idx_score (score)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Create custom user roles
     */
    private static function create_custom_roles(): void {
        /* Remove old roles if they exist */
        remove_role('nextsafar_editor');
        remove_role('nextsafar_author');

        /* Create editor role */
        add_role('nextsafar_editor', 'NextSafar Editor', [
            'read' => true,
            'edit_posts' => true,
            'edit_published_posts' => true,
            'publish_posts' => true,
            'upload_files' => true,
            'edit_pages' => true,
            'edit_others_posts' => true,
            'edit_pages' => true,
            'edit_published_pages' => true,
            'publish_pages' => true,
            'edit_others_pages' => true,
        ]);

        /* Create author role */
        add_role('nextsafar_author', 'NextSafar Author', [
            'read' => true,
            'edit_posts' => true,
            'edit_published_posts' => true,
            'upload_files' => true,
        ]);
    }

    /**
     * Schedule cron jobs
     */
    private static function schedule_cron_jobs(): void {
        /* News sync cron - every 15 minutes */
        if (!wp_next_scheduled('nextsafar_news_cron_hook')) {
            wp_schedule_event(time(), 'nextsafar_15min', 'nextsafar_news_cron_hook');
        }

        /* Exchange rate update cron - daily */
        if (!wp_next_scheduled('nextsafar_exchange_update_cron')) {
            wp_schedule_event(time(), 'daily', 'nextsafar_exchange_update_cron');
        }

        /* Location enrichment cron - hourly */
        if (!wp_next_scheduled('ns_enrich_locations_cron')) {
            wp_schedule_event(time(), 'hourly', 'ns_enrich_locations_cron');
        }

        /* Register custom interval */
        add_filter('cron_schedules', function($schedules) {
            if (!isset($schedules['nextsafar_15min'])) {
                $schedules['nextsafar_15min'] = [
                    'interval' => 900,
                    'display' => __('Every 15 Minutes (NextSafar)'),
                ];
            }

            return $schedules;
        });
    }

    /**
     * Plugin deactivation cleanup
     */
    public static function deactivate(): void {
        /* Clear all scheduled events */
        wp_clear_scheduled_hook('nextsafar_news_cron_hook');
        wp_clear_scheduled_hook('nextsafar_exchange_update_cron');
        wp_clear_scheduled_hook('ns_enrich_locations_cron');

        /* Flush rewrite rules */
        flush_rewrite_rules();

        error_log('🗑️ NextSafar Core: Plugin deactivated, cron jobs cleared');
    }
}