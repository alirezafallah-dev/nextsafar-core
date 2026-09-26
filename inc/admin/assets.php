<?php

/**
 * AdminAssets - Centralized Admin Assets Manager
 * Optimized and organized loading of CSS and JS files.
 *
 * @version 1.0.0
 */

namespace NextSafar\Admin;

if (!defined('ABSPATH')) exit;

class Assets {
    /**
     * Initialize hooks.
     */
    public static function init(): void {
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    /**
     * Load assets only on plugin pages.
     */
    public static function enqueue_assets(string $hook): void {
        // Load only on plugin pages.
        if (!self::is_plugin_page($hook)) {
            return;
        }

        // Main JS.
        wp_enqueue_script(
            'nextsafar-admin',
            NEXTSAFAR_URL . 'assets/nextsafar-admin.js',
            ['jquery'],
            NEXTSAFAR_VERSION,
            true
        );

        // Send data to JavaScript.
        wp_localize_script('nextsafar-admin', 'nextsafarAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('nextsafar_admin'),
            'syncNonce' => wp_create_nonce('nextsafar_sync'),
            'strings' => [
                'confirmReset' => __('آیا مطمئن هستید؟ همه منابع فعلی حذف و منابع پیش‌فرض جایگزین می‌شوند.', 'nextsafar'),
                'loading' => __('در حال بارگذاری...', 'nextsafar'),
                'error' => __('خطایی رخ داد', 'nextsafar'),
                'success' => __('عملیات با موفقیت انجام شد', 'nextsafar'),
                'emptyField' => __('این فیلد نمی‌تواند خالی باشد', 'nextsafar'),
            ],
        ]);

        // Load page-specific files on specific pages.
        self::enqueue_page_specific($hook);
    }

    /**
     * Detect plugin pages.
     */
    private static function is_plugin_page(string $hook): bool {
        $plugin_pages = [
            'toplevel_page_nextsafar-settings',
            'nextsafar_page_nextsafar-sync',
            'nextsafar_page_nextsafar-news-filter',
            'nextsafar_page_nextsafar-exchange',
        ];

        return in_array($hook, $plugin_pages, true);
    }

    /**
     * Load page-specific files.
     */
    private static function enqueue_page_specific(string $hook): void {
        switch ($hook) {
            case 'nextsafar_page_nextsafar-sync':
                wp_enqueue_script(
                    'nextsafar-sync',
                    NEXTSAFAR_URL . 'assets/nextsafar-sync.js',
                    ['jquery', 'nextsafar-admin'],
                    NEXTSAFAR_VERSION,
                    true
                );
                break;

            case 'nextsafar_page_nextsafar-news-filter':
                wp_enqueue_script(
                    'nextsafar-filter',
                    NEXTSAFAR_URL . 'assets/js/nextsafar-filter.js',
                    ['jquery', 'nextsafar-admin'],
                    NEXTSAFAR_VERSION,
                    true
                );
                break;
        }
    }
}