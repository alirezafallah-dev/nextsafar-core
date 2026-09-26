<?php

/**
 * SchemaManager — Database version management and upgrade
 *
 * This class is responsible for creating, upgrading, and maintaining
 * all custom database tables used by the NextSafar plugin.
 */

namespace NextSafar\Database;

use NextSafar\Activator;

if (!defined('ABSPATH')) exit;

class SchemaManager {

    /** Option key for storing the database version */
    const DB_VERSION_OPTION = 'nextsafar_db_version';

    /** Current database schema version */
    const CURRENT_VERSION = '1.1.0';

    /**
     * Initialize hooks.
     */
    public static function init(): void {
        add_action('admin_init', [__CLASS__, 'maybe_upgrade']);
    }

    /**
     * Check and auto-upgrade if needed.
     */
    public static function maybe_upgrade(): void {
        $installed = get_option(self::DB_VERSION_OPTION, '0.0.0');

        if (version_compare($installed, self::CURRENT_VERSION, '<')) {
            self::upgrade();
        }
    }

    /**
     * Perform the full database upgrade.
     */
    public static function upgrade(): void {
        global $wpdb;

        error_log('🔄 NextSafar: Starting database upgrade to version ' . self::CURRENT_VERSION);

        /* Create/upgrade all tables */
        GeoTable::create_table();
        self::upgrade_sync_log_table();
        self::upgrade_hotel_prices_table();
        self::upgrade_hotel_availability_table();
        self::upgrade_price_history_table();

        /* ✅ New: News system tables */
        \NextSafar\Database\NewsTables::create_sources_table();
        \NextSafar\Database\NewsTables::create_duplicates_table();
        \NextSafar\Database\NewsTables::create_ai_log_table();
        \NextSafar\Database\NewsTables::create_keywords_table();
        \NextSafar\Activator::create_filter_log_table();

        /* Save new version */
        update_option(self::DB_VERSION_OPTION, self::CURRENT_VERSION);

        error_log('✅ NextSafar: Database upgrade completed');
    }

    /**
     * Rebuild empty tables (only when they have no data).
     * Suitable for development/local environments.
     *
     * @return array Results per table.
     */
    public static function rebuild_empty_tables(): array {
        global $wpdb;

        $results = [];

        $tables = [
            'api_sync_log',
            'hotel_prices',
            'hotel_availability',
            'price_history',
        ];

        foreach ($tables as $table) {
            $full_name = $wpdb->prefix . $table;

            /* Check if table exists */
            if ($wpdb->get_var("SHOW TABLES LIKE '$full_name'") !== $full_name) {
                $results[$table] = 'Table did not exist';
                continue;
            }

            /* Check if empty */
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$full_name}");

            if ($count === 0) {
                /* Drop and prepare for rebuild */
                $wpdb->query("DROP TABLE {$full_name}");
                $results[$table] = 'Dropped and ready for rebuild';
            } else {
                $results[$table] = "Has {$count} records - not dropped";
            }
        }

        /* Rebuild all tables */
        self::upgrade_sync_log_table();
        self::upgrade_hotel_availability_table();
        self::upgrade_hotel_prices_table();
        self::upgrade_price_history_table();

        /* Update version */
        update_option(self::DB_VERSION_OPTION, self::CURRENT_VERSION);

        return $results;
    }

    /**
     * Sync log table.
     */
    private static function upgrade_sync_log_table(): void {
        Activator::create_sync_log_table();
    }

    /**
     * Upgrade hotel prices table with support for old and new structure.
     */
    private static function upgrade_hotel_prices_table(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . 'hotel_prices';

        /* If table does not exist, create it */
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            Activator::create_hotel_prices_table();
            return;
        }

        /* Check columns */
        $columns     = wp_list_pluck($wpdb->get_results("SHOW COLUMNS FROM {$table_name}"), 'Field');
        $has_old     = in_array('hotel_id', $columns);
        $has_post_id = in_array('hotel_post_id', $columns);
        $has_ext_id  = in_array('hotel_external_id', $columns);

        /* Case 1: Old structure (only hotel_id) → convert to new */
        if ($has_old && !$has_post_id) {
            $wpdb->query("ALTER TABLE {$table_name}
                CHANGE COLUMN hotel_id hotel_post_id BIGINT UNSIGNED DEFAULT NULL,
                ADD COLUMN hotel_external_id VARCHAR(255) DEFAULT NULL AFTER hotel_post_id,
                ADD KEY hotel_external_id (hotel_external_id)");

            error_log('✅ Hotel prices table upgraded from old structure');
            return;
        }

        /* Case 2: Missing external ID column → add it */
        if ($has_post_id && !$has_ext_id) {
            $wpdb->query("ALTER TABLE {$table_name}
                ADD COLUMN hotel_external_id VARCHAR(255) DEFAULT NULL AFTER hotel_post_id,
                ADD KEY hotel_external_id (hotel_external_id)");

            error_log('✅ hotel_external_id column added to prices table');
        }
    }

    /**
     * Upgrade hotel availability table.
     */
    private static function upgrade_hotel_availability_table(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . 'hotel_availability';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            Activator::create_hotel_availability_table();
            return;
        }

        $columns     = wp_list_pluck($wpdb->get_results("SHOW COLUMNS FROM {$table_name}"), 'Field');
        $has_old     = in_array('hotel_id', $columns);
        $has_post_id = in_array('hotel_post_id', $columns);
        $has_ext_id  = in_array('hotel_external_id', $columns);

        if ($has_old && !$has_post_id) {
            $wpdb->query("ALTER TABLE {$table_name}
                CHANGE COLUMN hotel_id hotel_post_id BIGINT UNSIGNED DEFAULT NULL,
                ADD COLUMN hotel_external_id VARCHAR(255) DEFAULT NULL AFTER hotel_post_id,
                ADD KEY hotel_external_id (hotel_external_id)");

            error_log('✅ Hotel availability table upgraded from old structure');
            return;
        }

        if ($has_post_id && !$has_ext_id) {
            $wpdb->query("ALTER TABLE {$table_name}
                ADD COLUMN hotel_external_id VARCHAR(255) DEFAULT NULL AFTER hotel_post_id,
                ADD KEY hotel_external_id (hotel_external_id)");
        }
    }

    /**
     * Upgrade price history table.
     */
    private static function upgrade_price_history_table(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . 'price_history';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            Activator::create_price_history_table();
            return;
        }

        $columns     = wp_list_pluck($wpdb->get_results("SHOW COLUMNS FROM {$table_name}"), 'Field');
        $has_old     = in_array('hotel_id', $columns);
        $has_post_id = in_array('hotel_post_id', $columns);
        $has_ext_id  = in_array('hotel_external_id', $columns);

        if ($has_old && !$has_post_id) {
            $wpdb->query("ALTER TABLE {$table_name}
                CHANGE COLUMN hotel_id hotel_post_id BIGINT UNSIGNED DEFAULT NULL,
                ADD COLUMN hotel_external_id VARCHAR(255) DEFAULT NULL AFTER hotel_post_id,
                ADD KEY hotel_external_id (hotel_external_id)");

            error_log('✅ Price history table upgraded from old structure');
            return;
        }

        if ($has_post_id && !$has_ext_id) {
            $wpdb->query("ALTER TABLE {$table_name}
                ADD COLUMN hotel_external_id VARCHAR(255) DEFAULT NULL AFTER hotel_post_id,
                ADD KEY hotel_external_id (hotel_external_id)");
        }
    }

    /**
     * Get the currently installed database version.
     *
     * @return string The installed version string.
     */
    public static function get_installed_version(): string {
        return get_option(self::DB_VERSION_OPTION, '0.0.0');
    }
}