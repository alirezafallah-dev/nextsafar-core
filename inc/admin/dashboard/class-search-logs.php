<?php
/**
 * NextSafar Core - Search Logs Viewer
 * 
 * View and filter system logs.
 * 
 * @package NextSafar\Admin\Dashboard
 * @since   2.6.0
 */

namespace NextSafar\Admin\Dashboard;

use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class SearchLogs {
    
    /**
     * Render logs page
     */
    public static function render_page(): void {
        if (!current_user_can('manage_options')) return;
        
        // Handle clear action
        if (isset($_POST['clear_logs']) && wp_verify_nonce($_POST['_wpnonce'], 'ns_logs_action')) {
            Logger::clear_all_logs();
            echo '<div class="notice notice-success"><p>✓ تمام لاگ‌ها پاک شدند</p></div>';
        }
        
        // Get filters
        $page = max(1, intval($_GET['paged'] ?? 1));
        $level = sanitize_text_field($_GET['level'] ?? '');
        $per_page = 50;
        
        $logs_data = Logger::get_logs($page, $per_page, $level);
        $logs = $logs_data['logs'];
        $total = $logs_data['total'];
        $total_pages = ceil($total / $per_page);
        ?>
        <div class="wrap">
            <h1>
                لاگ‌های سیستم
                <form method="post" style="display: inline-block; margin-right: 20px;">
                    <?php wp_nonce_field('ns_logs_action'); ?>
                    <button type="submit" name="clear_logs" class="button"
                            onclick="return confirm('آیا مطمئن هستید؟');">
                        🗑️ پاک کردن همه لاگ‌ها
                    </button>
                </form>
            </h1>
            
            <!-- Filters -->
            <div class="tablenav top">
                <form method="get" class="ns-filters">
                    <input type="hidden" name="page" value="nextsafar-logs">
                    <select name="level">
                        <option value="">همه سطوح</option>
                        <option value="debug" <?php selected($level, 'debug'); ?>>Debug</option>
                        <option value="info" <?php selected($level, 'info'); ?>>Info</option>
                        <option value="warning" <?php selected($level, 'warning'); ?>>Warning</option>
                        <option value="error" <?php selected($level, 'error'); ?>>Error</option>
                        <option value="critical" <?php selected($level, 'critical'); ?>>Critical</option>
                    </select>
                    <button type="submit" class="button">فیلتر</button>
                </form>
                
                <div class="ns-stats-inline">
                    <strong><?php echo number_format($total); ?></strong> لاگ یافت شد
                </div>
            </div>
            
            <!-- Logs Table -->
            <table class="wp-list-table widefat fixed striped ns-logs-table">
                <thead>
                    <tr>
                        <th style="width: 150px;">زمان</th>
                        <th style="width: 100px;">سطح</th>
                        <th>پیام</th>
                        <th style="width: 120px;">IP</th>
                        <th style="width: 100px;">کاربر</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 40px;">
                                لاگی یافت نشد
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo esc_html($log->created_at); ?></td>
                                <td>
                                    <span class="ns-log-badge ns-level-<?php echo esc_attr($log->level); ?>">
                                        <?php echo esc_html(strtoupper($log->level)); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="ns-log-message">
                                        <?php echo esc_html($log->message); ?>
                                    </div>
                                    <?php if (!empty($log->context)): ?>
                                        <details class="ns-log-context">
                                            <summary>مشاهده جزئیات</summary>
                                            <pre><?php echo esc_html(print_r(json_decode($log->context, true), true)); ?></pre>
                                        </details>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo esc_html($log->ip_address ?? '-'); ?></code></td>
                                <td>
                                    <?php if ($log->user_id): ?>
                                        <a href="<?php echo get_edit_user_link($log->user_id); ?>">
                                            <?php echo get_userdata($log->user_id)->display_name ?? 'User #' . $log->user_id; ?>
                                        </a>
                                    <?php else: ?>
                                        Guest
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links([
                            'base'      => add_query_arg('paged', '%#%'),
                            'format'    => '',
                            'current'   => $page,
                            'total'     => $total_pages,
                            'prev_text' => '« قبلی',
                            'next_text' => 'بعدی »',
                        ]);
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <style>
            .ns-filters {
                display: flex;
                gap: 8px;
                align-items: center;
            }
            .ns-stats-inline {
                margin-right: 20px;
                color: #666;
            }
            .ns-logs-table {
                margin-top: 20px;
            }
            .ns-log-badge {
                display: inline-block;
                padding: 3px 10px;
                border-radius: 4px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .ns-level-debug { background: #61afef; color: #fff; }
            .ns-level-info { background: #98c379; color: #fff; }
            .ns-level-warning { background: #e5c07b; color: #000; }
            .ns-level-error { background: #f48771; color: #fff; }
            .ns-level-critical { background: #dc3232; color: #fff; }
            .ns-log-message {
                font-family: 'Courier New', monospace;
                font-size: 12px;
                word-break: break-word;
            }
            .ns-log-context {
                margin-top: 8px;
            }
            .ns-log-context summary {
                cursor: pointer;
                color: #0073aa;
                font-size: 11px;
            }
            .ns-log-context pre {
                background: #f5f5f5;
                padding: 12px;
                border-radius: 4px;
                max-height: 300px;
                overflow: auto;
                font-size: 11px;
                margin-top: 8px;
            }
        </style>
        <?php
    }
}