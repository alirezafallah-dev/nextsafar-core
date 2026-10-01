<?php
/**
 * NextSafar Core - Admin Dashboard
 * 
 * Main dashboard page showing system overview, metrics, and quick actions.
 * 
 * @package NextSafar\Admin\Dashboard
 * @since   2.6.0
 */

namespace NextSafar\Admin\Dashboard;

use NextSafar\Search\ProviderFactory;
use NextSafar\Search\PriceConverter;
use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class DashboardPage {
    
    /**
     * Initialize dashboard
     */
    public static function init(): void {
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_ns_dashboard_stats', [__CLASS__, 'ajax_get_stats']);
        add_action('wp_ajax_ns_clear_all_cache', [__CLASS__, 'ajax_clear_cache']);
    }
    
    /**
     * Enqueue assets
     */
    public static function enqueue_assets(string $hook): void {
        if (strpos($hook, 'nextsafar-') === false) return;
        
        wp_enqueue_style(
            'nextsafar-admin',
            NEXTSAFAR_URL . 'assets/admin/dashboard.css',
            [],
            NEXTSAFAR_VERSION
        );
        
        wp_enqueue_script(
            'nextsafar-admin',
            NEXTSAFAR_URL . 'assets/admin/dashboard.js',
            ['jquery'],
            NEXTSAFAR_VERSION,
            true
        );
        
        wp_localize_script('nextsafar-admin', 'nsDashboard', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('ns_dashboard_nonce'),
        ]);
    }
    
    /**
     * Render dashboard page
     */
    public static function render_page(): void {
        if (!current_user_can('manage_options')) return;
        
        $stats = self::get_dashboard_stats();
        ?>
        <div class="wrap nextsafar-dashboard">
            <h1>
                <span class="dashicons dashicons-airplane" style="font-size: 30px; margin-left: 10px;"></span>
                NextSafar Dashboard
            </h1>
            
            <!-- Stats Cards -->
            <div class="ns-stats-grid">
                <div class="ns-stat-card ns-stat-primary">
                    <div class="ns-stat-icon">🔍</div>
                    <div class="ns-stat-content">
                        <div class="ns-stat-label">جستجوهای امروز</div>
                        <div class="ns-stat-value"><?php echo number_format($stats['today_searches']); ?></div>
                    </div>
                </div>
                
                <div class="ns-stat-card ns-stat-success">
                    <div class="ns-stat-icon">✅</div>
                    <div class="ns-stat-content">
                        <div class="ns-stat-label">Cache Hit Rate</div>
                        <div class="ns-stat-value"><?php echo $stats['cache_hit_rate']; ?>%</div>
                    </div>
                </div>
                
                <div class="ns-stat-card ns-stat-info">
                    <div class="ns-stat-icon">🏨</div>
                    <div class="ns-stat-content">
                        <div class="ns-stat-label">هتل‌های سایت</div>
                        <div class="ns-stat-value"><?php echo number_format($stats['total_hotels']); ?></div>
                    </div>
                </div>
                
                <div class="ns-stat-card ns-stat-warning">
                    <div class="ns-stat-icon">⚠️</div>
                    <div class="ns-stat-content">
                        <div class="ns-stat-label">خطاهای امروز</div>
                        <div class="ns-stat-value"><?php echo number_format($stats['today_errors']); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Provider Status -->
            <div class="ns-section">
                <h2>وضعیت Provider‌ها</h2>
                <div class="ns-provider-grid">
                    <?php foreach ($stats['providers'] as $name => $provider): ?>
                        <div class="ns-provider-card <?php echo $provider['available'] ? 'active' : 'inactive'; ?>">
                            <div class="ns-provider-header">
                                <h3><?php echo esc_html(ucfirst($name)); ?></h3>
                                <span class="ns-status-badge <?php echo $provider['available'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $provider['available'] ? '✓ فعال' : '✗ غیرفعال'; ?>
                                </span>
                            </div>
                            <div class="ns-provider-details">
                                <?php if ($name === $stats['active_provider']): ?>
                                    <div class="ns-active-badge">🎯 Active</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- System Info -->
            <div class="ns-section">
                <h2>اطلاعات سیستم</h2>
                <table class="widefat ns-info-table">
                    <tr>
                        <th>نرخ دلار (USD → Rial):</th>
                        <td>
                            <strong><?php echo number_format($stats['usd_rate']); ?></strong> ریال
                            <?php if ($stats['rate_source'] === 'manual'): ?>
                                <span class="ns-badge ns-badge-info">دستی</span>
                            <?php else: ?>
                                <span class="ns-badge ns-badge-success">زنده</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Provider فعال:</th>
                        <td><strong><?php echo esc_html(ucfirst($stats['active_provider'])); ?></strong></td>
                    </tr>
                    <tr>
                        <th>تعداد هتل‌های Matched:</th>
                        <td><?php echo number_format($stats['matched_hotels']); ?></td>
                    </tr>
                    <tr>
                        <th>نسخه پلاگین:</th>
                        <td><?php echo NEXTSAFAR_VERSION; ?></td>
                    </tr>
                </table>
            </div>
            
            <!-- Quick Actions -->
            <div class="ns-section">
                <h2>اقدامات سریع</h2>
                <div class="ns-actions">
                    <button class="button button-primary" id="ns-clear-cache">
                        <span class="dashicons dashicons-trash"></span>
                        پاک کردن تمام Cache‌ها
                    </button>
                    
                    <button class="button" id="ns-refresh-rate">
                        <span class="dashicons dashicons-update"></span>
                        به‌روزرسانی نرخ ارز
                    </button>
                    
                    <a href="<?php echo admin_url('admin.php?page=nextsafar-logs'); ?>" class="button">
                        <span class="dashicons dashicons-list-view"></span>
                        مشاهده لاگ‌ها
                    </a>
                    
                    <a href="<?php echo admin_url('admin.php?page=nextsafar-providers'); ?>" class="button">
                        <span class="dashicons dashicons-admin-generic"></span>
                        تنظیمات Provider
                    </a>
                </div>
            </div>
            
            <!-- Recent Logs -->
            <div class="ns-section">
                <h2>آخرین لاگ‌ها</h2>
                <div id="ns-recent-logs" class="ns-logs-container">
                    <div class="ns-loading">در حال بارگذاری...</div>
                </div>
            </div>
        </div>
        
        <style>
            .nextsafar-dashboard {
                max-width: 1400px;
            }
            .ns-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }
            .ns-stat-card {
                background: #fff;
                border-radius: 12px;
                padding: 24px;
                display: flex;
                align-items: center;
                gap: 16px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.08);
                border-left: 4px solid #0073aa;
                transition: transform 0.2s;
            }
            .ns-stat-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0,0,0,0.12);
            }
            .ns-stat-primary { border-left-color: #0073aa; }
            .ns-stat-success { border-left-color: #46b450; }
            .ns-stat-info { border-left-color: #00a0d2; }
            .ns-stat-warning { border-left-color: #ffb900; }
            .ns-stat-icon {
                font-size: 32px;
            }
            .ns-stat-label {
                color: #666;
                font-size: 13px;
                margin-bottom: 4px;
            }
            .ns-stat-value {
                font-size: 28px;
                font-weight: 700;
                color: #23282d;
            }
            .ns-section {
                background: #fff;
                padding: 24px;
                margin: 20px 0;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            }
            .ns-section h2 {
                margin-top: 0;
                padding-bottom: 12px;
                border-bottom: 2px solid #f0f0f0;
            }
            .ns-provider-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 16px;
            }
            .ns-provider-card {
                background: #f9f9f9;
                border: 2px solid #e0e0e0;
                border-radius: 8px;
                padding: 16px;
                transition: all 0.2s;
            }
            .ns-provider-card.active {
                border-color: #46b450;
                background: #f0f9f0;
            }
            .ns-provider-card.inactive {
                border-color: #dc3232;
                background: #fdf0f0;
            }
            .ns-provider-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 12px;
            }
            .ns-provider-header h3 {
                margin: 0;
                font-size: 16px;
            }
            .ns-status-badge {
                padding: 4px 12px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 600;
            }
            .ns-status-badge.active {
                background: #46b450;
                color: #fff;
            }
            .ns-status-badge.inactive {
                background: #dc3232;
                color: #fff;
            }
            .ns-active-badge {
                background: #0073aa;
                color: #fff;
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 11px;
                display: inline-block;
            }
            .ns-info-table {
                border: none;
            }
            .ns-info-table th {
                width: 200px;
                padding: 12px;
                background: #f9f9f9;
                font-weight: 600;
            }
            .ns-info-table td {
                padding: 12px;
            }
            .ns-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 4px;
                font-size: 11px;
                margin-right: 8px;
            }
            .ns-badge-success {
                background: #46b450;
                color: #fff;
            }
            .ns-badge-info {
                background: #00a0d2;
                color: #fff;
            }
            .ns-actions {
                display: flex;
                gap: 12px;
                flex-wrap: wrap;
            }
            .ns-actions .button {
                display: flex;
                align-items: center;
                gap: 6px;
                padding: 8px 16px;
                height: auto;
            }
            .ns-logs-container {
                max-height: 300px;
                overflow-y: auto;
                background: #1e1e1e;
                color: #d4d4d4;
                padding: 16px;
                border-radius: 6px;
                font-family: 'Courier New', monospace;
                font-size: 12px;
                line-height: 1.6;
            }
            .ns-log-entry {
                padding: 4px 0;
                border-bottom: 1px solid #333;
            }
            .ns-log-entry:last-child {
                border-bottom: none;
            }
            .ns-log-level {
                font-weight: 700;
                margin-left: 8px;
            }
            .ns-log-level.error { color: #f48771; }
            .ns-log-level.warning { color: #e5c07b; }
            .ns-log-level.info { color: #98c379; }
            .ns-log-level.debug { color: #61afef; }
            .ns-loading {
                text-align: center;
                color: #888;
                padding: 20px;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Load recent logs
            function loadRecentLogs() {
                $.post(nsDashboard.ajaxUrl, {
                    action: 'ns_dashboard_stats',
                    nonce: nsDashboard.nonce,
                    type: 'logs'
                }, function(response) {
                    if (response.success && response.data.logs) {
                        var html = '';
                        response.data.logs.forEach(function(log) {
                            html += '<div class="ns-log-entry">';
                            html += '<span class="ns-log-level ' + log.level + '">[' + log.level.toUpperCase() + ']</span>';
                            html += '<span class="ns-log-time">' + log.time + '</span> ';
                            html += '<span class="ns-log-message">' + log.message + '</span>';
                            html += '</div>';
                        });
                        $('#ns-recent-logs').html(html || '<div class="ns-loading">لاگی وجود ندارد</div>');
                    }
                });
            }
            
            loadRecentLogs();
            setInterval(loadRecentLogs, 10000); // Refresh every 10 seconds
            
            // Clear cache button
            $('#ns-clear-cache').on('click', function(e) {
                e.preventDefault();
                if (!confirm('آیا مطمئن هستید که می‌خواهید تمام Cache‌ها را پاک کنید؟')) return;
                
                var btn = $(this);
                btn.prop('disabled', true).text('در حال پاک کردن...');
                
                $.post(nsDashboard.ajaxUrl, {
                    action: 'ns_clear_all_cache',
                    nonce: nsDashboard.nonce
                }, function(response) {
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> پاک کردن تمام Cache‌ها');
                    if (response.success) {
                        alert('✓ ' + response.data.message);
                    } else {
                        alert('✗ ' + response.data.message);
                    }
                });
            });
            
            // Refresh rate button
            $('#ns-refresh-rate').on('click', function(e) {
                e.preventDefault();
                var btn = $(this);
                btn.prop('disabled', true).text('در حال به‌روزرسانی...');
                
                $.post(nsDashboard.ajaxUrl, {
                    action: 'ns_refresh_rate',
                    nonce: nsDashboard.nonce
                }, function(response) {
                    btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> به‌روزرسانی نرخ ارز');
                    if (response.success) {
                        alert('✓ نرخ ارز به‌روزرسانی شد');
                        location.reload();
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Get dashboard statistics
     */
    private static function get_dashboard_stats(): array {
        global $wpdb;
        
        // Today's searches (from logs)
        $today = date('Y-m-d');
        $today_searches = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ns_api_logs 
             WHERE DATE(created_at) = %s 
             AND message LIKE '%%search%%'",
            $today
        ));
        
        // Today's errors
        $today_errors = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ns_api_logs 
             WHERE DATE(created_at) = %s 
             AND level IN ('error', 'critical')",
            $today
        ));
        
        // Total hotels
        $total_hotels = (int) wp_count_posts('hotel')->publish;
        
        // Matched hotels
        $matched_hotels = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ns_hotel_matches 
             WHERE site_post_id IS NOT NULL AND confidence > 0.5"
        );
        
        // Cache hit rate (from last 100 searches)
        $recent_logs = Logger::get_logs(1, 100);
        $cache_hits = 0;
        $total_searches = 0;
        foreach ($recent_logs['logs'] as $log) {
            if (strpos($log->message, 'cache hit') !== false) {
                $cache_hits++;
            }
            if (strpos($log->message, 'search') !== false) {
                $total_searches++;
            }
        }
        $cache_hit_rate = $total_searches > 0 
            ? round(($cache_hits / $total_searches) * 100, 1) 
            : 0;
        
        // USD rate
        $usd_rate = PriceConverter::get_usd_rate();
        $manual_rate = (float) get_option(PriceConverter::OPTION_RATE, 0);
        $rate_source = $manual_rate > 0 ? 'manual' : 'auto';
        
        return [
            'today_searches'  => $today_searches,
            'today_errors'    => $today_errors,
            'total_hotels'    => $total_hotels,
            'matched_hotels'  => $matched_hotels,
            'cache_hit_rate'  => $cache_hit_rate,
            'usd_rate'        => $usd_rate,
            'rate_source'     => $rate_source,
            'providers'       => ProviderFactory::get_all_statuses(),
            'active_provider' => ProviderFactory::get_active_provider_name(),
        ];
    }
    
    /**
     * AJAX: Get stats
     */
    public static function ajax_get_stats(): void {
        check_ajax_referer('ns_dashboard_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'عدم دسترسی']);
        }
        
        $type = sanitize_text_field($_POST['type'] ?? 'stats');
        
        if ($type === 'logs') {
            $logs = Logger::get_logs(1, 20);
            $formatted = [];
            foreach ($logs['logs'] as $log) {
                $formatted[] = [
                    'level'   => $log->level,
                    'time'    => $log->created_at,
                    'message' => $log->message,
                ];
            }
            wp_send_json_success(['logs' => $formatted]);
        }
        
        wp_send_json_success(self::get_dashboard_stats());
    }
    
    /**
     * AJAX: Clear all cache
     */
    public static function ajax_clear_cache(): void {
        check_ajax_referer('ns_dashboard_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'عدم دسترسی']);
        }
        
        global $wpdb;
        
        // Clear search cache
        $search_deleted = \NextSafar\Search\HotelSearchService::clear_cache();
        $flight_deleted = \NextSafar\Search\FlightSearchService::clear_cache();
        
        // Clear site hotels cache
        \NextSafar\Hotels\clear_site_hotels_cache();
        
        // Clear merged cache
        $merged_deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '\\_transient\\_ns\\_hotel\\_merged\\_%'
             OR option_name LIKE '\\_transient\\_timeout\\_ns\\_hotel\\_merged\\_%'"
        );
        
        // Clear airport cache
        \NextSafar\Search\AirportMapper::clear_cache();
        
        $total = $search_deleted + $flight_deleted + (int) $merged_deleted;
        
        Logger::info('Admin cleared all cache', ['total_deleted' => $total]);
        
        wp_send_json_success([
            'message' => "تمام Cache‌ها پاک شدند. {$total} آیتم حذف شد.",
        ]);
    }
}

// Initialize
DashboardPage::init();