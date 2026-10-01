<?php
/**
 * Plugin Name: NextSafar Core
 * Plugin URI: https://github.com/alirezafallah-dev/nextsafar-core
 * Description: هسته مرکزی و بک‌اند هدلس (Headless) پلتفرم نکست‌سفر. ارائه‌دهنده REST API برای هتل، پرواز، مقاصد، همگام‌سازی با APIهای خارجی و برنامه‌ریزی سفر با هوش مصنوعی.
 * Version: 2.5.0
 * Author: Alireza Fallah
 * Author URI: https://github.com/alirezafallah-dev
 * Text Domain: nextsafar
 * Domain Path: /languages
 * Requires PHP: 8.1
 * Requires at least: 6.0
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 */

if (!defined('ABSPATH')) exit;

// ═══════════════════════════════════════════════════════════
// Plugin Constants
// ═══════════════════════════════════════════════════════════
define('NEXTSAFAR_VERSION', '2.5.0');
define('NEXTSAFAR_PATH', plugin_dir_path(__FILE__));
define('NEXTSAFAR_URL', plugin_dir_url(__FILE__));

// ═══════════════════════════════════════════════════════════
// 1. AUTOLOADER - Smart class loading
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/autoloader.php';

// ═══════════════════════════════════════════════════════════
// 2. CORE HOOKS - Activation and deactivation
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/activator.php';
require_once NEXTSAFAR_PATH . 'inc/deactivator.php';
require_once NEXTSAFAR_PATH . 'inc/media.php';

// ═══════════════════════════════════════════════════════════
// 3. DATABASE LAYER - Tables and Schema (must load first)
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/database/geo-table.php';
require_once NEXTSAFAR_PATH . 'inc/database/schema-manager.php';
require_once NEXTSAFAR_PATH . 'inc/database/news-tables.php';
require_once NEXTSAFAR_PATH . 'inc/database/ai-trip-table.php';

// ═══════════════════════════════════════════════════════════
// 4. GEO SYSTEM - Spatial data foundation (before Sync)
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/sync/geo-schema.php';
require_once NEXTSAFAR_PATH . 'inc/sync/geo-sync.php';
require_once NEXTSAFAR_PATH . 'inc/sync/place-enrich-trait.php';
require_once NEXTSAFAR_PATH . 'inc/sync/migrate-geo-meta.php';

// ═══════════════════════════════════════════════════════════
// 5. API CLIENTS - External service integrations
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/api/base-client.php';
require_once NEXTSAFAR_PATH . 'inc/api/searchapi-client.php';
require_once NEXTSAFAR_PATH . 'inc/api/serpapi-client.php';
require_once NEXTSAFAR_PATH . 'inc/api/image-manager.php';
require_once NEXTSAFAR_PATH . 'inc/api/wikipedia-client.php';
require_once NEXTSAFAR_PATH . 'inc/api/rate-limiter.php';
require_once NEXTSAFAR_PATH . 'inc/api/gemini-client.php';
require_once NEXTSAFAR_PATH . 'inc/api/revalidation-webhook.php';

// ═══════════════════════════════════════════════════════════
// 6. SYNC BASE - Base sync classes
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/api/base-sync.php';
require_once NEXTSAFAR_PATH . 'inc/api/batch-sync.php';

// ═══════════════════════════════════════════════════════════
// 7. SYNC CLASSES - Entity synchronization services
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/api/hotel-sync.php';
require_once NEXTSAFAR_PATH . 'inc/api/destination-sync.php';
require_once NEXTSAFAR_PATH . 'inc/api/restaurant-sync.php';
require_once NEXTSAFAR_PATH . 'inc/api/hospital-sync.php';

// ═══════════════════════════════════════════════════════════
// 8. NEWS AGGREGATOR SYSTEM - News fetching and processing
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/api/rss-fetcher.php';
require_once NEXTSAFAR_PATH . 'inc/api/newsapi-fetcher.php';
require_once NEXTSAFAR_PATH . 'inc/api/duplicate-checker.php';
require_once NEXTSAFAR_PATH . 'inc/api/ai-rewriter.php';
require_once NEXTSAFAR_PATH . 'inc/api/news-filter.php';
require_once NEXTSAFAR_PATH . 'inc/api/news-sync.php';

// ═══════════════════════════════════════════════════════════
// 9. POST TYPES - Custom content types
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/posttypes/hotel.php';
require_once NEXTSAFAR_PATH . 'inc/posttypes/airport.php';
require_once NEXTSAFAR_PATH . 'inc/posttypes/destination.php';
require_once NEXTSAFAR_PATH . 'inc/posttypes/restaurant.php';
require_once NEXTSAFAR_PATH . 'inc/posttypes/hospital.php';
require_once NEXTSAFAR_PATH . 'inc/posttypes/tour.php';
require_once NEXTSAFAR_PATH . 'inc/posttypes/visa.php';
require_once NEXTSAFAR_PATH . 'inc/posttypes/travelguide.php';
require_once NEXTSAFAR_PATH . 'inc/posttypes/travelnews.php';

// ═══════════════════════════════════════════════════════════
// 10. TAXONOMIES - Categories and tags
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/taxonomies/hotel-category.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/hotel-tag.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/airport-category.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/airport-tag.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/destination-category.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/destination-tag.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/restaurant-category.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/restaurant-tag.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/hospital-category.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/hospital-tag.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/tour-category.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/tour-tag.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/tour-category-meta.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/visa-category.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/visa-tag.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/travelguide-category.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/travelguide-tag.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/travelnews-category.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/travelnews-tag.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/term-images.php';

// ═══ Tourism System ═══
require_once NEXTSAFAR_PATH . 'inc/taxonomies/tourism.php';
require_once NEXTSAFAR_PATH . 'inc/taxonomies/tourism-meta.php';

// ═══════════════════════════════════════════════════════════
// 11. METABOXES - Custom fields in editor
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/metaboxes/hotel-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/hotel-featured-meta.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/airport-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/airport-facilities.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/airport-services.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/airport-airlines.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/flag-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/destination-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/gallery-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/restaurant-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/restaurant-facilities.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/hospital-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/tour-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/visa-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/travelguide-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/travelnews-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/geo-metabox.php';
require_once NEXTSAFAR_PATH . 'inc/metaboxes/geo-coords-field.php';

// ═══════════════════════════════════════════════════════════
// 12. ADMIN - Backend interface
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/admin/assets.php';
require_once NEXTSAFAR_PATH . 'inc/admin/settings.php';
require_once NEXTSAFAR_PATH . 'inc/admin/exchange.php';
require_once NEXTSAFAR_PATH . 'inc/admin/live-search.php';
require_once NEXTSAFAR_PATH . 'inc/admin/sync-page.php';
require_once NEXTSAFAR_PATH . 'inc/admin/news-filter-settings.php';
require_once NEXTSAFAR_PATH . 'inc/admin/menu.php';
include_once NEXTSAFAR_PATH . 'inc/admin/icon-menu.php';

// ═══════════════════════════════════════════════════════════
// 13. REST API ENDPOINTS - API endpoints
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/api/search-endpoint.php';
require_once NEXTSAFAR_PATH . 'inc/api/news-endpoint.php';
require_once NEXTSAFAR_PATH . 'inc/api/menu-endpoint.php';
require_once NEXTSAFAR_PATH . 'inc/api/stats-endpoint.php';
require_once NEXTSAFAR_PATH . 'inc/api/world-endpoint.php';
require_once NEXTSAFAR_PATH . 'inc/api/country-posts-endpoint.php';
require_once NEXTSAFAR_PATH . 'inc/api/featured-hotels-endpoint.php';

// ═══ AI Trip Planner ═══
require_once NEXTSAFAR_PATH . 'inc/api/ai-trip-gemini-client.php';
require_once NEXTSAFAR_PATH . 'inc/api/ai-trip-planner-endpoint.php';
require_once NEXTSAFAR_PATH . 'inc/admin/ai-trip-settings.php';
require_once NEXTSAFAR_PATH . 'inc/api/map-endpoint.php';
require_once NEXTSAFAR_PATH . 'inc/api/ai-providers.php';
require_once NEXTSAFAR_PATH . 'inc/api/post-sync.php';

// ═══════════════════════════════════════════════════════════
// 13.5 ADMIN DASHBOARD - Management Interface
// ═══════════════════════════════════════════════════════════
if (is_admin()) {
    require_once NEXTSAFAR_PATH . 'inc/admin/dashboard/class-dashboard-page.php';
    require_once NEXTSAFAR_PATH . 'inc/admin/dashboard/class-provider-settings.php';
    require_once NEXTSAFAR_PATH . 'inc/admin/dashboard/class-search-logs.php';
    require_once NEXTSAFAR_PATH . 'inc/admin/dashboard/class-cache-manager.php';
    require_once NEXTSAFAR_PATH . 'inc/admin/dashboard/class-performance-metrics.php';
}

// ═══════════════════════════════════════════════════════════
// 14. CORE - Main plugin core (load last)
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/core.php';

// ═══════════════════════════════════════════════════════════
// 15. AUTHENTICATION SYSTEM
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/auth/install.php';
require_once NEXTSAFAR_PATH . 'inc/auth/kavenegar.php';
require_once NEXTSAFAR_PATH . 'inc/auth/otp.php';
require_once NEXTSAFAR_PATH . 'inc/auth/session.php';
require_once NEXTSAFAR_PATH . 'inc/auth/google.php';
require_once NEXTSAFAR_PATH . 'inc/auth/endpoints.php';

// ═══════════════════════════════════════════════════════════
// 16. ACCOUNT SYSTEM
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/account/endpoints.php';
require_once NEXTSAFAR_PATH . 'inc/account/admin-fields.php';
require_once NEXTSAFAR_PATH . 'inc/account/favorites-endpoint.php';

// ═══════════════════════════════════════════════════════════
// 17. SEARCH & LIVE SEARCH
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/search/providers.php';
require_once NEXTSAFAR_PATH . 'inc/flights/endpoints.php';
require_once NEXTSAFAR_PATH . 'inc/hotels/endpoints.php';
require_once NEXTSAFAR_PATH . 'inc/hotels/matcher.php';

// ═══════════════════════════════════════════════════════════
// 2.5 CORE INFRASTRUCTURE - Logger, RateLimiter, Validator, ErrorHandler
// (باید قبل از بقیه فایل‌ها لود شود)
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/core/class-ns-logger.php';
require_once NEXTSAFAR_PATH . 'inc/core/class-ns-rate-limiter.php';
require_once NEXTSAFAR_PATH . 'inc/core/class-ns-validator.php';
require_once NEXTSAFAR_PATH . 'inc/core/class-ns-error-handler.php';

// ═══════════════════════════════════════════════════════════
// 2.6 SEARCH SERVICES - Provider Pattern + Service Layer
// Must load AFTER core infrastructure
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/search/class-provider-interface.php';
require_once NEXTSAFAR_PATH . 'inc/search/class-price-converter.php';
require_once NEXTSAFAR_PATH . 'inc/search/class-date-converter.php';
require_once NEXTSAFAR_PATH . 'inc/search/class-airport-mapper.php';
require_once NEXTSAFAR_PATH . 'inc/search/class-serpapi-provider.php';
require_once NEXTSAFAR_PATH . 'inc/search/class-searchapi-provider.php';
require_once NEXTSAFAR_PATH . 'inc/search/class-provider-factory.php';
require_once NEXTSAFAR_PATH . 'inc/search/class-hotel-search-service.php';
require_once NEXTSAFAR_PATH . 'inc/search/class-flight-search-service.php';

// ═══════════════════════════════════════════════════════════
// 2.7 BOOKING & PAYMENT ENGINE
// Must load AFTER core infrastructure
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/booking/class-booking-table.php';
require_once NEXTSAFAR_PATH . 'inc/payment/class-payment-table.php';

// Create tables on plugin load (safe: CREATE IF NOT EXISTS)
add_action('init', function () {
    \NextSafar\Booking\BookingTable::maybe_upgrade();
    \NextSafar\Payment\PaymentTable::maybe_upgrade();
}, 1);

// ═══════════════════════════════════════════════════════════
// 2.8 BOOKING PASSENGERS & DOCUMENTS
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/booking/class-booking-passenger-table.php';
require_once NEXTSAFAR_PATH . 'inc/booking/class-booking-document-table.php';

// Upgrade on init
add_action('init', function () {
    \NextSafar\Booking\BookingPassengerTable::maybe_upgrade();
    \NextSafar\Booking\BookingDocumentTable::maybe_upgrade();
}, 2);

// ═══════════════════════════════════════════════════════════
// 2.9 VISA BOOKING LOGIC
// ═══════════════════════════════════════════════════════════
require_once NEXTSAFAR_PATH . 'inc/booking/class-visa-price-calculator.php';
require_once NEXTSAFAR_PATH . 'inc/booking/class-visa-validator.php';
require_once NEXTSAFAR_PATH . 'inc/booking/class-booking-file-uploader.php';
require_once NEXTSAFAR_PATH . 'inc/booking/adapters/class-visa-booking-adapter.php';

// ═══════════════════════════════════════════════════════════
// ACTIVATION / DEACTIVATION HOOKS
// ═══════════════════════════════════════════════════════════
register_activation_hook(__FILE__, ['NextSafar\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['NextSafar\Deactivator', 'deactivate']);

// ═══════════════════════════════════════════════════════════
// BOOTSTRAP - Plugin initialization
// ═══════════════════════════════════════════════════════════
add_action('plugins_loaded', function () {
    NextSafar\Core::init();
    \NextSafar\Database\SchemaManager::init();
    \NextSafar\API\NewsSync::init();
    \NextSafar\Admin\Assets::init();
    \NextSafar\Admin\Menu::init();
    \NextSafar\API\MapEndpoint::init();
    \NextSafar\MetaBoxes\GeoCoordsField::init();
    \NextSafar\API\PostSync::init();
}, 10);

// ═══════════════════════════════════════════════════════════
// SETTINGS - WordPress settings registration
// ═══════════════════════════════════════════════════════════
add_action('init', function () {
    register_setting('nextsafar_settings', 'nextsafar_google_places_key', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    register_setting('nextsafar_settings', 'nextsafar_searchapi_key', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    register_setting('nextsafar_settings', 'nextsafar_serpapi_key', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);
});

// ═══════════════════════════════════════════════════════════
// MENUS - WordPress navigation menus
// ═══════════════════════════════════════════════════════════
add_action('after_setup_theme', function () {
    register_nav_menus([
        'mainmenu'   => __('Main Menu', 'nextsafar'),
        'secmenu'    => __('Category Menu', 'nextsafar'),
        'mobilemenu' => __('Mobile Menu (Optional)', 'nextsafar'),
    ]);
});

// ═══════════════════════════════════════════════════════════
// LAZY INIT - Ensure tables exist on every load
// ═══════════════════════════════════════════════════════════
add_action('admin_init', [\NextSafar\API\NewsSync::class, 'ensure_tables_exist']);
add_action('rest_api_init', [\NextSafar\API\NewsSync::class, 'ensure_tables_exist']);

/**
 * Cleanup hooks when plugin is deactivated
 */
add_action('deactivate_' . plugin_basename(__FILE__), function () {
    // Clear news sync cron hooks
    wp_clear_scheduled_hook('nextsafar_news_hourly_tick');
    wp_clear_scheduled_hook('nextsafar_news_retry_hook');
    delete_transient('ns_news_sync_lock');
    delete_transient('ns_news_last_cron_run');
    error_log('NextSafar: All cron hooks cleared on plugin deactivation');
});