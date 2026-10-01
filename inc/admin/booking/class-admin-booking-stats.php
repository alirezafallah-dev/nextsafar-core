<?php
/**
 * NextSafar Core - Admin Booking Stats
 * 
 * Displays booking statistics and reports.
 * 
 * @package NextSafar\Admin\Booking
 * @since   2.7.0
 */

namespace NextSafar\Admin\Booking;

use NextSafar\Booking\BookingTable;
use NextSafar\Payment\PaymentTable;

if (!defined('ABSPATH')) exit;

class AdminBookingStats {
    
    /**
     * Render stats page
     */
    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get stats
        $booking_stats = BookingTable::get_stats(30);
        $payment_stats = PaymentTable::get_stats(30);
        $gateway_stats = PaymentTable::get_gateway_stats(30);
        
        ?>
        <div class="wrap">
            <h1>آمار رزروها</h1>
            
            <!-- Summary Cards -->
            <div class="ns-stats-grid">
                <div class="ns-stat-card">
                    <div class="ns-stat-value"><?php echo number_format($booking_stats['total_bookings']); ?></div>
                    <div class="ns-stat-label">کل رزروها (30 روز)</div>
                </div>
                
                <div class="ns-stat-card">
                    <div class="ns-stat-value"><?php echo number_format($booking_stats['completed']); ?></div>
                    <div class="ns-stat-label">تکمیل شده</div>
                </div>
                
                <div class="ns-stat-card">
                    <div class="ns-stat-value"><?php echo number_format($booking_stats['cancelled']); ?></div>
                    <div class="ns-stat-label">لغو شده</div>
                </div>
                
                <div class="ns-stat-card">
                    <div class="ns-stat-value"><?php echo number_format($booking_stats['revenue']); ?></div>
                    <div class="ns-stat-label">درآمد (ریال)</div>
                </div>
            </div>
            
            <!-- Payment Stats -->
            <div class="ns-detail-card">
                <h2>آمار پرداخت‌ها</h2>
                <table class="form-table">
                    <tr>
                        <th>کل پرداخت‌ها:</th>
                        <td><?php echo number_format($payment_stats['total_payments']); ?></td>
                    </tr>
                    <tr>
                        <th>پرداخت‌های موفق:</th>
                        <td><?php echo number_format($payment_stats['successful']); ?></td>
                    </tr>
                    <tr>
                        <th>پرداخت‌های ناموفق:</th>
                        <td><?php echo number_format($payment_stats['failed']); ?></td>
                    </tr>
                    <tr>
                        <th>مجموع درآمد:</th>
                        <td><?php echo number_format($payment_stats['total_revenue']); ?> ریال</td>
                    </tr>
                    <tr>
                        <th>میانگین پرداخت:</th>
                        <td><?php echo number_format($payment_stats['avg_payment']); ?> ریال</td>
                    </tr>
                </table>
            </div>
            
            <!-- Gateway Stats -->
            <div class="ns-detail-card">
                <h2>آمار درگاه‌ها</h2>
                
                <?php if (empty($gateway_stats)): ?>
                    <p>هنوز پرداختی ثبت نشده است.</p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>درگاه</th>
                                <th>تعداد تراکنش</th>
                                <th>موفق</th>
                                <th>درآمد</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gateway_stats as $gateway): ?>
                                <tr>
                                    <td><?php echo esc_html($gateway['gateway']); ?></td>
                                    <td><?php echo number_format($gateway['total']); ?></td>
                                    <td><?php echo number_format($gateway['successful']); ?></td>
                                    <td><?php echo number_format($gateway['revenue']); ?> ریال</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
            .ns-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }
            .ns-stat-card {
                background: #fff;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                text-align: center;
            }
            .ns-stat-value {
                font-size: 32px;
                font-weight: 700;
                color: #1f2937;
            }
            .ns-stat-label {
                color: #6b7280;
                font-size: 13px;
                margin-top: 5px;
            }
            .ns-detail-card {
                background: #fff;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                margin-bottom: 20px;
            }
            .ns-detail-card h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 2px solid #f0f0f0;
            }
        </style>
        <?php
    }
}