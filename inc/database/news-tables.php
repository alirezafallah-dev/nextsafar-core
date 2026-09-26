<?php

/**
 * NextSafar News Tables — Version 3.1
 *
 * Handles creation and management of all news-related database tables:
 * - ns_news_sources: RSS/API feed sources
 * - ns_news_duplicates: Duplicate detection records
 * - ns_news_ai_log: AI processing log
 * - ns_news_keywords: Filter keywords
 *
 * ✅ Fix: Added url_hash for compatibility with DuplicateChecker
 */

namespace NextSafar\Database;

if (!defined('ABSPATH')) exit;

class NewsTables {

    /* ========================================================================
       Sources Table
       ======================================================================== */
    public static function create_sources_table(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_sources';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            url TEXT NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'rss',
            group_name VARCHAR(50) NOT NULL DEFAULT 'general',
            priority INT NOT NULL DEFAULT 5,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_fetch DATETIME NULL DEFAULT NULL,
            last_success DATETIME NULL DEFAULT NULL,
            total_fetched BIGINT(20) NOT NULL DEFAULT 0,
            total_duplicates BIGINT(20) NOT NULL DEFAULT 0,
            error_message TEXT NULL,
            PRIMARY KEY (id), KEY type (type), KEY is_active (is_active)
        ) {$wpdb->get_charset_collate()};");
    }

    /* ========================================================================
       Duplicates Table
       ======================================================================== */
    public static function create_duplicates_table(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_duplicates';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        /* ✅ Added url_hash + fetched_at for compatibility with DuplicateChecker */
        dbDelta("CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            url_hash CHAR(32) NOT NULL DEFAULT '',
            guid TEXT NULL,
            guid_hash CHAR(32) NOT NULL DEFAULT '',
            title_hash CHAR(32) NOT NULL DEFAULT '',
            fuzzy_hash VARCHAR(191) NOT NULL DEFAULT '',
            source_name VARCHAR(191) NOT NULL DEFAULT '',
            post_id BIGINT(20) NOT NULL DEFAULT 0,
            fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY url_hash (url_hash),
            KEY guid_hash (guid_hash),
            KEY title_hash (title_hash),
            KEY fuzzy_hash (fuzzy_hash)
        ) {$wpdb->get_charset_collate()};");
    }

    /* ========================================================================
       AI Log Table
       ======================================================================== */
    public static function create_ai_log_table(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_ai_log';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT(20) NOT NULL DEFAULT 0,
            provider VARCHAR(50) NOT NULL DEFAULT '',
            model VARCHAR(100) NOT NULL DEFAULT '',
            tokens_used INT NOT NULL DEFAULT 0,
            cost_usd DECIMAL(10,6) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'success',
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY post_id (post_id)
        ) {$wpdb->get_charset_collate()};");
    }

    /* ========================================================================
       Keywords Table
       ======================================================================== */
    public static function create_keywords_table(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_keywords';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword VARCHAR(191) NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'positive',
            weight INT NOT NULL DEFAULT 10,
            category VARCHAR(50) NOT NULL DEFAULT 'general',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id), KEY type (type), KEY is_active (is_active)
        ) {$wpdb->get_charset_collate()};");
    }

    /* ========================================================================
       Seed Default Keywords
       ======================================================================== */
    public static function seed_default_keywords(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_keywords';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) self::create_keywords_table();

        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") > 0) return;

        foreach (\NextSafar\API\NewsFilter::get_default_keyword_rows() as $row) {
            $wpdb->insert($table, $row);
        }

        error_log('✅ NextSafar: Default keywords seeded');
    }

    /* ========================================================================
       Seed Default Sources
       ======================================================================== */
    public static function seed_default_sources(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_sources';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) self::create_sources_table();

        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") > 0) return;

        $sources = [
            /* API sources */
            ['name' => 'GNews.io',      'url' => 'https://gnews.io/api/v4/search',             'type' => 'api', 'group_name' => 'api',      'priority' => 5],
            ['name' => 'NewsData.io',   'url' => 'https://newsdata.io/api/1/latest',           'type' => 'api', 'group_name' => 'api',      'priority' => 5],
            ['name' => 'Currents API',  'url' => 'https://api.currentsapi.services/v1/search', 'type' => 'api', 'group_name' => 'api',      'priority' => 5],

            /* ✅ Verified and tested RSS feeds */
            ['name' => 'Skift',                'url' => 'https://skift.com/feed/',                   'type' => 'rss', 'group_name' => 'industry', 'priority' => 10],
            ['name' => 'Travel and Tour World', 'url' => 'https://www.travelandtourworld.com/feed/', 'type' => 'rss', 'group_name' => 'industry', 'priority' => 8],
        ];

        foreach ($sources as $s) $wpdb->insert($table, $s + ['is_active' => 1]);

        error_log('✅ NextSafar: Default sources seeded');
    }

    /* ========================================================================
       Migration: Add url_hash Column If Missing
       ======================================================================== */
    public static function migrate_duplicates_table(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_duplicates';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return;

        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}");

        if (!in_array('url_hash', $cols)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN url_hash CHAR(32) NOT NULL DEFAULT '' AFTER id");
            $wpdb->query("ALTER TABLE {$table} ADD INDEX url_hash (url_hash)");
            error_log('✅ NextSafar: url_hash column added to duplicates table');
        }

        if (!in_array('fetched_at', $cols)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
            error_log('✅ NextSafar: fetched_at column added to duplicates table');
        }
    }

    /* ========================================================================
       Drop All Tables (Uninstall)
       ======================================================================== */
    public static function drop_tables(): void {
        global $wpdb;

        foreach ([
            $wpdb->prefix . 'ns_news_sources',
            $wpdb->prefix . 'ns_news_duplicates',
            $wpdb->prefix . 'ns_news_ai_log',
            $wpdb->prefix . 'ns_news_keywords',
            $wpdb->prefix . 'ns_news_filter_log',
        ] as $t) $wpdb->query("DROP TABLE IF EXISTS {$t}");
    }
}