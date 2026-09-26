<?php

/**
 * NextSafar Admin Menu Management
 * Version 2.0 - Fixed duplicate menu bug.
 */

namespace NextSafar\Admin;

if (!defined('ABSPATH')) exit;

class Menu {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menus']);
    }

    public static function register_menus() {
        /* ========================================================================
           Main Menu
           ======================================================================== */
        add_menu_page(
            __('تنظیمات سفر بعدی', 'nextsafar'),
            __('تنظیمات سفر بعدی', 'nextsafar'),
            'manage_options',
            'nextsafar-settings',
            [Settings::class, 'render_settings_page'],
            'dashicons-globe',
            30
        );

        /* ========================================================================
           Submenu: Settings (Main Page)
           ======================================================================== */
        add_submenu_page(
            'nextsafar-settings',
            __('تنظیمات API', 'nextsafar'),
            __('تنظیمات', 'nextsafar'),
            'manage_options',
            'nextsafar-settings',
            [Settings::class, 'render_settings_page']
        );

        /* ========================================================================
           Submenu: Sync
           ======================================================================== */
        add_submenu_page(
            'nextsafar-settings',
            __('همگام‌سازی از API', 'nextsafar'),
            __('همگام‌سازی', 'nextsafar'),
            'manage_options',
            'nextsafar-sync',
            [SyncPage::class, 'render_sync_page']
        );

        /* ========================================================================
           Submenu: News Filter
           ======================================================================== */
        add_submenu_page(
            'nextsafar-settings',
            __('فیلتر اخبار گردشگری', 'nextsafar'),
            __('فیلتر اخبار', 'nextsafar'),
            'manage_options',
            'nextsafar-news-filter',
            [NewsFilterSettings::class, 'render_page']
        );

        /* ========================================================================
           Submenu: Exchange Rate
           ======================================================================== */
        add_submenu_page(
            'nextsafar-settings',
            __('نرخ ارز', 'nextsafar'),
            __('نرخ ارز', 'nextsafar'),
            'manage_options',
            'nextsafar-exchange',
            [Exchange::class, 'render_page']
        );

        /* ========================================================================
           Submenu: Live Search (After Exchange Rate)
           ======================================================================== */
        add_submenu_page(
            'nextsafar-settings',
            __('جستجوی زنده (پرواز / هتل / تور)', 'nextsafar'),
            __('جستجوی زنده', 'nextsafar'),
            'manage_options',
            'nextsafar-live-search',
            [LiveSearch::class, 'render_page']
        );
    }
}