<?php
/**
 * NextSafar Core - Cache Manager
 * 
 * View and manage all caches in the system.
 * 
 * @package NextSafar\Admin\Dashboard
 * @since   2.6.0
 */

namespace NextSafar\Admin\Dashboard;

use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class CacheManager {
    
    /**
     * Render cache manager page
     */
    public static function render_page(): void {
        if (!current_user_can('manage_options')) return;
        
        global $wpdb;
        
        $message = '';
        
        // Handle actions
        if (isset($_POST['action_type']) && wp_verify_nonce($_POST['_wpnonce'], 'ns_cache_action')) {
            $action = sanitize_text_field($_POST['action_type']);
            
            switch ($action) {
                case 'clear_search':
                    $count = \NextSafar\Search\HotelSearchService::clear_cache();
                    $message = "✓ کش جستجوی هتل پاک شد ({$count} آیتم)";
                    break;
                    
                case 'clear_flights':
                    $count = \NextSafar\Search\FlightSearchService::clear_cache();
                    $message = "✓ کش جستجوی پرواز پاک شد ({$count} آیتم)";
                    break;
                    
                case 'clear_site_hotels':
                    \NextSafar\Hotels\clear_site_hotels_cache();
                    $message = "✓ کش هتل‌های سایت پاک شد";
                    break;
                    
                case 'clear_airports':
                    \NextSafar\Search\AirportMapper::clear_cache();
                    $message = "✓ کش فرودگاه‌ها پاک شد";
                    break;
                    
                case 'clear_all':
                    $wpdb->query(
                        "DELETE FROM {$wpdb->options} 
                         WHERE option_name LIKE '\\_transient\\_ns\\_%'
                         OR option_name LIKE '\\_transient\\_timeout\\_ns\\_%'"
                    );
                    $message = "✓ تمام Cache‌های NextSafar پاک شدند";
                    break;
            }
            
            Logger::info('Cache cleared', ['action' => $action]);
        }
        
        // Get cache statistics
        $stats = self::get_cache_stats();
        ?>
        <div class="wrap">
            <h1>مدیریت Cache</h1>
            
            <?php if ($message): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>
            
            <!-- Cache Stats -->
            <div class="ns-cache-stats">
                <div class="ns-cache-stat">
                    <div class="ns-cache-stat-value"><?php echo number_format($stats['total_entries']); ?></div>
                    <div class="ns-cache-stat-label">کل ورودی‌ها</div>
                </div>
                <div class="ns-cache-stat">
                    <div class="ns-cache-stat-value"><?php echo size_format($stats['total_size']); ?></div>
                    <div class="ns-cache-stat-label">حجم کل</div>
                </div>
                <div class="ns-cache-stat">
                    <div class="ns-cache-stat-value"><?php echo number_format($stats['search_entries']); ?></div>
                    <div class="ns-cache-stat-label">جستجوی هتل</div>
                </div>
                <div class="ns-cache-stat">
                    <div class="ns-cache-stat-value"><?php echo number_format($stats['flight_entries']); ?></div>
                    <div class="ns-cache-stat-label">جستجوی پرواز</div>
                </div>
            </div>
            
            <!-- Cache Categories -->
            <div class="ns-cache-categories">
                <?php foreach ($stats['categories'] as $category): ?>
                    <div class="ns-cache-card">
                        <div class="ns-cache-card-header">
                            <h3><?php echo esc_html($category['label']); ?></h3>
                            <div class="ns-cache-card-stats">
                                <span class="ns-count"><?php echo number_format($category['count']); ?> آیتم</span>
                                <span class="ns-size"><?php echo size_format($category['size']); ?></span>
                            </div>
                        </div>
                        <div class="ns-cache-card-description">
                            <?php echo esc_html($category['description']); ?>
                        </div>
                        <form method="post" class="ns-cache-card-action">
                            <?php wp_nonce_field('ns_cache_action'); ?>
                            <input type="hidden" name="action_type" value="<?php echo esc_attr($category['action']); ?>">
                            <button type="submit" class="button"
                                    onclick="return confirm('آیا مطمئن هستید که می‌خواهید این Cache را پاک کنید؟');">
                                🗑️ پاک کردن
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Danger Zone -->
            <div class="ns-danger-zone">
                <h2>⚠️ منطقه خطر</h2>
                <p>این اقدام تمام Cache‌های NextSafar را پاک می‌کند. فقط در صورت نیاز استفاده کنید.</p>
                <form method="post">
                    <?php wp_nonce_field('ns_cache_action'); ?>
                    <input type="hidden" name="action_type" value="clear_all">
                    <button type="submit" class="button button-link-delete"
                            onclick="return confirm('آیا واقعاً مطمئن هستید؟ تمام Cache‌ها پاک می‌شوند!');">
                        🗑️ پاک کردن تمام Cache‌های NextSafar
                    </button>
                </form>
            </div>
        </div>
        
        <style>
            .ns-cache-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 16px;
                margin: 20px 0;
            }
            .ns-cache-stat {
                background: #fff;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
                text-align: center;
            }
            .ns-cache-stat-value {
                font-size: 28px;
                font-weight: 700;
                color: #0073aa;
                margin-bottom: 4px;
            }
            .ns-cache-stat-label {
                color: #666;
                font-size: 13px;
            }
            .ns-cache-categories {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 16px;
                margin: 20px 0;
            }
            .ns-cache-card {
                background: #fff;
                border-radius: 8px;
                padding: 20px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            }
            .ns-cache-card-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 12px;
                padding-bottom: 12px;
                border-bottom: 1px solid #f0f0f0;
            }
            .ns-cache-card-header h3 {
                margin: 0;
                font-size: 16px;
            }
            .ns-cache-card-stats {
                text-align: left;
                font-size: 12px;
                color: #666;
            }
            .ns-cache-card-stats .ns-count {
                display: block;
                font-weight: 600;
                color: #23282d;
            }
            .ns-cache-card-description {
                color: #666;
                font-size: 13px;
                margin-bottom: 16px;
                line-height: 1.5;
            }
            .ns-cache-card-action {
                margin: 0;
            }
            .ns-danger-zone {
                background: #fdf0f0;
                border: 2px solid #dc3232;
                border-radius: 8px;
                padding: 20px;
                margin: 30px 0;
            }
            .ns-danger-zone h2 {
                margin-top: 0;
                color: #dc3232;
            }
        </style>
        <?php
    }
    
    /**
     * Get cache statistics
     */
    private static function get_cache_stats(): array {
        global $wpdb;
        
        // Get all NextSafar transients
        $transients = $wpdb->get_results(
            "SELECT option_name, LENGTH(option_value) as size 
             FROM {$wpdb->options} 
             WHERE option_name LIKE '\\_transient\\_ns\\_%'
             AND option_name NOT LIKE '\\_transient\\_timeout\\_%'"
        );
        
        $categories = [
            'search' => [
                'label'       => 'جستجوی هتل',
                'description' => 'نتایج جستجوی هتل‌ها از API‌های خارجی',
                'action'      => 'clear_search',
                'pattern'     => 'ns_hotel_search',
                'count'       => 0,
                'size'        => 0,
            ],
            'flights' => [
                'label'       => 'جستجوی پرواز',
                'description' => 'نتایج جستجوی پروازها',
                'action'      => 'clear_flights',
                'pattern'     => 'ns_flight_search',
                'count'       => 0,
                'size'        => 0,
            ],
            'site_hotels' => [
                'label'       => 'هتل‌های سایت',
                'description' => 'لیست هتل‌های محلی بر اساس شهر',
                'action'      => 'clear_site_hotels',
                'pattern'     => 'ns_site_hotels',
                'count'       => 0,
                'size'        => 0,
            ],
            'airports' => [
                'label'       => 'فرودگاه‌ها',
                'description' => 'کش اطلاعات فرودگاه‌ها و شهرها',
                'action'      => 'clear_airports',
                'pattern'     => 'ns_airport',
                'count'       => 0,
                'size'        => 0,
            ],
            'other' => [
                'label'       => 'سایر',
                'description' => 'Cache‌های متفرقه',
                'action'      => 'clear_all',
                'pattern'     => 'ns_',
                'count'       => 0,
                'size'        => 0,
            ],
        ];
        
        $total_size = 0;
        $total_entries = count($transients);
        
        foreach ($transients as $t) {
            $matched = false;
            foreach ($categories as $key => $cat) {
                if (strpos($t->option_name, $cat['pattern']) !== false) {
                    $categories[$key]['count']++;
                    $categories[$key]['size'] += $t->size;
                    $total_size += $t->size;
                    $matched = true;
                    break;
                }
            }
            
            if (!$matched) {
                $categories['other']['count']++;
                $categories['other']['size'] += $t->size;
                $total_size += $t->size;
            }
        }
        
        // Remove empty categories
        $categories = array_filter($categories, fn($c) => $c['count'] > 0);
        
        return [
            'total_entries'   => $total_entries,
            'total_size'      => $total_size,
            'search_entries'  => $categories['search']['count'] ?? 0,
            'flight_entries'  => $categories['flights']['count'] ?? 0,
            'categories'      => $categories,
        ];
    }
}