<?php
/**
 * NextSafar Core - Admin Booking List
 * 
 * Displays list of all bookings with filters.
 * 
 * @package NextSafar\Admin\Booking
 * @since   2.7.0
 */

namespace NextSafar\Admin\Booking;

use NextSafar\Booking\BookingTable;

if (!defined('ABSPATH')) exit;

class AdminBookingList {
    
    /**
     * Status labels
     */
    const STATUS_LABELS = [
        'pending'            => 'در انتظار',
        'awaiting_documents' => 'در انتظار مدارک',
        'awaiting_payment'   => 'در انتظار پرداخت',
        'paid'               => 'پرداخت شده',
        'processing'         => 'در حال پردازش',
        'completed'          => 'تکمیل شده',
        'cancelled'          => 'لغو شده',
        'refunded'           => 'بازپرداخت شده',
        'failed'             => 'ناموفق',
    ];
    
    /**
     * Status colors
     */
    const STATUS_COLORS = [
        'pending'            => '#6b7280',
        'awaiting_documents' => '#f59e0b',
        'awaiting_payment'   => '#f97316',
        'paid'               => '#22c55e',
        'processing'         => '#3b82f6',
        'completed'          => '#10b981',
        'cancelled'          => '#ef4444',
        'refunded'           => '#8b5cf6',
        'failed'             => '#dc2626',
    ];
    
    /**
     * Render bookings list page
     */
    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        global $wpdb;
        
        // Get filters
        $status_filter = sanitize_text_field($_GET['status'] ?? '');
        $type_filter   = sanitize_text_field($_GET['type'] ?? '');
        $search        = sanitize_text_field($_GET['s'] ?? '');
        $page          = max(1, intval($_GET['paged'] ?? 1));
        $per_page      = 20;
        $offset        = ($page - 1) * $per_page;
        
        // Build query
        $table = BookingTable::get_table_name();
        $where = ['1=1'];
        $params = [];
        
        if ($status_filter !== '') {
            $where[] = 'status = %s';
            $params[] = $status_filter;
        }
        
        if ($type_filter !== '') {
            $where[] = 'booking_type = %s';
            $params[] = $type_filter;
        }
        
        if ($search !== '') {
            $where[] = '(booking_code LIKE %s OR passenger_info LIKE %s)';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        
        $where_clause = implode(' AND ', $where);
        
        // Get total count
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";
        $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params));
        $total_pages = ceil($total / $per_page);
        
        // Get bookings
        $params[] = $per_page;
        $params[] = $offset;
        
        $bookings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                ...$params
            ),
            ARRAY_A
        );
        
        ?>
        <div class="wrap">
            <h1>مدیریت رزروها</h1>
            
            <!-- Filters -->
            <div class="ns-filters-bar">
                <form method="get">
                    <input type="hidden" name="page" value="nextsafar-bookings">
                    
                    <select name="status">
                        <option value="">همه وضعیت‌ها</option>
                        <?php foreach (self::STATUS_LABELS as $key => $label): ?>
                            <option value="<?php echo esc_attr($key); ?>" 
                                    <?php selected($status_filter, $key); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select name="type">
                        <option value="">همه انواع</option>
                        <option value="visa" <?php selected($type_filter, 'visa'); ?>>ویزا</option>
                        <option value="hotel" <?php selected($type_filter, 'hotel'); ?>>هتل</option>
                        <option value="flight" <?php selected($type_filter, 'flight'); ?>>پرواز</option>
                        <option value="tour" <?php selected($type_filter, 'tour'); ?>>تور</option>
                    </select>
                    
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" 
                           placeholder="جستجو (شماره رزرو، تلفن...)">
                    
                    <button type="submit" class="button">فیلتر</button>
                    
                    <?php if ($status_filter || $type_filter || $search): ?>
                        <a href="<?php echo admin_url('admin.php?page=nextsafar-bookings'); ?>" class="button">پاک کردن فیلترها</a>
                    <?php endif; ?>
                </form>
            </div>
            
            <!-- Stats Summary -->
            <div class="ns-booking-stats-summary">
                <span>تعداد کل: <strong><?php echo number_format($total); ?></strong></span>
            </div>
            
            <!-- Bookings Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>شماره رزرو</th>
                        <th>نوع</th>
                        <th>وضعیت</th>
                        <th>مبلغ (ریال)</th>
                        <th>مسافران</th>
                        <th>تلفن</th>
                        <th>تاریخ ایجاد</th>
                        <th style="width: 150px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bookings)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 40px;">
                                رزروی یافت نشد
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($bookings as $booking): ?>
                            <?php
                            $passenger_info = json_decode($booking['passenger_info'] ?? '{}', true);
                            $main_phone = $passenger_info['main_phone'] ?? '-';
                            $status_color = self::STATUS_COLORS[$booking['status']] ?? '#6b7280';
                            $status_label = self::STATUS_LABELS[$booking['status']] ?? $booking['status'];
                            ?>
                            <tr>
                                <td><?php echo $booking['id']; ?></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=nextsafar-booking-detail&id=' . $booking['id']); ?>">
                                        <strong><?php echo esc_html($booking['booking_code']); ?></strong>
                                    </a>
                                </td>
                                <td>
                                    <?php 
                                    $type_labels = ['visa' => 'ویزا', 'hotel' => 'هتل', 'flight' => 'پرواز', 'tour' => 'تور'];
                                    echo esc_html($type_labels[$booking['booking_type']] ?? $booking['booking_type']);
                                    ?>
                                </td>
                                <td>
                                    <span class="ns-status-badge" style="background: <?php echo $status_color; ?>;">
                                        <?php echo esc_html($status_label); ?>
                                    </span>
                                </td>
                                <td><?php echo number_format((float) $booking['total_price']); ?></td>
                                <td><?php echo (int) $booking['passenger_count']; ?> نفر</td>
                                <td dir="ltr"><?php echo esc_html($main_phone); ?></td>
                                <td><?php echo esc_html($booking['created_at']); ?></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=nextsafar-booking-detail&id=' . $booking['id']); ?>" 
                                       class="button button-small">مشاهده</a>
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
            .ns-filters-bar {
                background: #fff;
                padding: 15px;
                margin: 15px 0;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .ns-filters-bar form {
                display: flex;
                gap: 10px;
                align-items: center;
                flex-wrap: wrap;
            }
            .ns-filters-bar select,
            .ns-filters-bar input[type="search"] {
                min-width: 150px;
            }
            .ns-booking-stats-summary {
                margin-bottom: 15px;
                color: #666;
            }
            .ns-status-badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 12px;
                color: #fff;
                font-size: 12px;
                font-weight: 600;
            }
        </style>
        <?php
    }
}