<?php
/**
 * NextSafar Core - Admin Booking Detail
 * 
 * Displays detailed view of a single booking.
 * 
 * @package NextSafar\Admin\Booking
 * @since   2.7.0
 */

namespace NextSafar\Admin\Booking;

use NextSafar\Booking\BookingTable;
use NextSafar\Booking\BookingPassengerTable;
use NextSafar\Booking\BookingDocumentTable;
use NextSafar\Payment\PaymentTable;

if (!defined('ABSPATH')) exit;

class AdminBookingDetail {
    
    /**
     * Render booking detail page
     */
    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $booking_id = intval($_GET['id'] ?? 0);
        
        if ($booking_id <= 0) {
            echo '<div class="wrap"><p>رزرو مشخص نشده است.</p></div>';
            return;
        }
        
        // Get booking
        $booking = BookingTable::find($booking_id);
        
        if (!$booking) {
            echo '<div class="wrap"><p>رزرو یافت نشد.</p></div>';
            return;
        }
        
        // Get related data
        $passengers = BookingPassengerTable::get_by_booking($booking_id);
        $documents = BookingDocumentTable::get_grouped_by_passenger($booking_id);
        $payments = PaymentTable::find_by_booking($booking_id);
        
        // Decode JSON
        $booking_data = json_decode($booking['booking_data'] ?? '{}', true);
        $passenger_info = json_decode($booking['passenger_info'] ?? '{}', true);
        
        // Get visa info if applicable
        $visa_info = null;
        if ($booking['booking_type'] === 'visa' && $booking['item_id']) {
            $visa_post = get_post($booking['item_id']);
            if ($visa_post) {
                $visa_info = [
                    'title' => $visa_post->post_title,
                    'issue' => get_post_meta($booking['item_id'], '_visa_issue', true),
                    'expiry' => get_post_meta($booking['item_id'], '_visa_expiry', true),
                ];
            }
        }
        
        $status_color = AdminBookingList::STATUS_COLORS[$booking['status']] ?? '#6b7280';
        $status_label = AdminBookingList::STATUS_LABELS[$booking['status']] ?? $booking['status'];
        
        ?>
        <div class="wrap">
            <h1>
                جزئیات رزرو: <?php echo esc_html($booking['booking_code']); ?>
                <a href="<?php echo admin_url('admin.php?page=nextsafar-bookings'); ?>" class="page-title-action">بازگشت به لیست</a>
            </h1>
            
            <div class="ns-booking-detail-grid">
                <!-- Main Info -->
                <div class="ns-detail-card">
                    <h2>اطلاعات کلی رزرو</h2>
                    <table class="form-table">
                        <tr>
                            <th>شماره رزرو:</th>
                            <td><strong><?php echo esc_html($booking['booking_code']); ?></strong></td>
                        </tr>
                        <tr>
                            <th>نوع رزرو:</th>
                            <td><?php echo $booking['booking_type'] === 'visa' ? 'ویزا' : $booking['booking_type']; ?></td>
                        </tr>
                        <tr>
                            <th>وضعیت:</th>
                            <td>
                                <span class="ns-status-badge" style="background: <?php echo $status_color; ?>;">
                                    <?php echo esc_html($status_label); ?>
                                </span>
                                
                                <!-- Status Change Dropdown -->
                                <select id="ns-status-select" data-booking-id="<?php echo $booking_id; ?>">
                                    <?php foreach (AdminBookingList::STATUS_LABELS as $key => $label): ?>
                                        <option value="<?php echo esc_attr($key); ?>" 
                                                <?php selected($booking['status'], $key); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" id="ns-update-status" class="button button-small">تغییر وضعیت</button>
                            </td>
                        </tr>
                        <tr>
                            <th>مبلغ کل:</th>
                            <td><strong><?php echo number_format((float) $booking['total_price']); ?></strong> ریال</td>
                        </tr>
                        <tr>
                            <th>تعداد مسافران:</th>
                            <td><?php echo (int) $booking['passenger_count']; ?> نفر</td>
                        </tr>
                        <tr>
                            <th>تاریخ ایجاد:</th>
                            <td><?php echo esc_html($booking['created_at']); ?></td>
                        </tr>
                        <tr>
                            <th>تلفن رزروکننده:</th>
                            <td dir="ltr"><?php echo esc_html($passenger_info['main_phone'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>ایمیل رزروکننده:</th>
                            <td><?php echo esc_html($passenger_info['main_mail'] ?? '-'); ?></td>
                        </tr>
                    </table>
                </div>
                
                <!-- Visa Info -->
                <?php if ($visa_info): ?>
                <div class="ns-detail-card">
                    <h2>اطلاعات ویزا</h2>
                    <table class="form-table">
                        <tr>
                            <th>عنوان ویزا:</th>
                            <td><?php echo esc_html($visa_info['title']); ?></td>
                        </tr>
                        <tr>
                            <th>نوع ویزا:</th>
                            <td><?php echo esc_html($booking_data['visa_type'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>مدت اقامت:</th>
                            <td><?php echo esc_html($booking_data['visa_duration'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>زمان اخذ:</th>
                            <td><?php echo esc_html($visa_info['issue'] ?: 'نامشخص'); ?></td>
                        </tr>
                        <tr>
                            <th>اعتبار ویزا:</th>
                            <td><?php echo esc_html($visa_info['expiry'] ?: 'نامشخص'); ?></td>
                        </tr>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Passengers -->
            <div class="ns-detail-card">
                <h2>مسافران (<?php echo count($passengers); ?> نفر)</h2>
                
                <?php if (empty($passengers)): ?>
                    <p>مسافری ثبت نشده است.</p>
                <?php else: ?>
                    <?php foreach ($passengers as $index => $passenger): ?>
                        <div class="ns-passenger-card">
                            <h3>
                                <?php echo $passenger['type'] === 'adult' ? 'بزرگسال' : 'کودک'; ?>
                                <?php echo ($passenger['type'] === 'adult' ? $index + 1 : ''); ?>
                            </h3>
                            
                            <div class="ns-passenger-info">
                                <div><strong>نام:</strong> <?php echo esc_html($passenger['first_name']); ?></div>
                                <div><strong>نام خانوادگی:</strong> <?php echo esc_html($passenger['last_name']); ?></div>
                                <div><strong>کد ملی:</strong> <?php echo esc_html($passenger['national_id']); ?></div>
                                <div><strong>پاسپورت:</strong> <?php echo esc_html($passenger['passport_number'] ?: '-'); ?></div>
                                <div><strong>تلفن:</strong> <?php echo esc_html($passenger['phone'] ?: '-'); ?></div>
                                <div><strong>تاریخ تولد:</strong> <?php echo esc_html($passenger['birth_date']); ?></div>
                                <div><strong>تاریخ سفر:</strong> <?php echo esc_html($passenger['travel_date'] ?: '-'); ?></div>
                                <div><strong>انقضای پاسپورت:</strong> <?php echo esc_html($passenger['passport_expiry_date'] ?: '-'); ?></div>
                            </div>
                            
                            <!-- Documents -->
                            <div class="ns-passenger-docs">
                                <h4>مدارک:</h4>
                                <?php 
                                $passenger_docs = $documents[$passenger['id']] ?? [];
                                if (empty($passenger_docs)): 
                                ?>
                                    <p class="ns-no-docs">مدرکی بارگذاری نشده است.</p>
                                <?php else: ?>
                                    <div class="ns-docs-grid">
                                        <?php foreach ($passenger_docs as $doc): ?>
                                            <div class="ns-doc-item <?php echo $doc['verified'] ? 'verified' : ''; ?>">
                                                <a href="<?php echo esc_url($doc['file_url']); ?>" target="_blank">
                                                    <?php 
                                                    $is_image = strpos($doc['mime_type'], 'image/') === 0;
                                                    if ($is_image): 
                                                    ?>
                                                        <img src="<?php echo esc_url($doc['file_url']); ?>" 
                                                             alt="<?php echo esc_attr($doc['document_label']); ?>">
                                                    <?php else: ?>
                                                        <span class="ns-doc-pdf">PDF</span>
                                                    <?php endif; ?>
                                                </a>
                                                <div class="ns-doc-info">
                                                    <span class="ns-doc-label"><?php echo esc_html($doc['document_label']); ?></span>
                                                    <?php if ($doc['verified']): ?>
                                                        <span class="ns-doc-verified">✓ تأیید شده</span>
                                                    <?php else: ?>
                                                        <button type="button" 
                                                                class="button button-small ns-verify-doc"
                                                                data-doc-id="<?php echo $doc['id']; ?>">
                                                            تأیید مدرک
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Payments -->
            <div class="ns-detail-card">
                <h2>پرداخت‌ها</h2>
                
                <?php if (empty($payments)): ?>
                    <p>پرداختی ثبت نشده است.</p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>درگاه</th>
                                <th>مبلغ</th>
                                <th>وضعیت</th>
                                <th>شماره تراکنش</th>
                                <th>تاریخ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <?php
                                $payment_status_colors = [
                                    'pending' => '#f59e0b',
                                    'redirecting' => '#3b82f6',
                                    'success' => '#22c55e',
                                    'failed' => '#ef4444',
                                    'cancelled' => '#6b7280',
                                    'refunded' => '#8b5cf6',
                                ];
                                $p_color = $payment_status_colors[$payment['status']] ?? '#6b7280';
                                ?>
                                <tr>
                                    <td><?php echo $payment['id']; ?></td>
                                    <td><?php echo esc_html($payment['gateway']); ?></td>
                                    <td><?php echo number_format((float) $payment['amount']); ?> ریال</td>
                                    <td>
                                        <span class="ns-status-badge" style="background: <?php echo $p_color; ?>;">
                                            <?php echo esc_html($payment['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html($payment['gateway_ref_id'] ?: '-'); ?></td>
                                    <td><?php echo esc_html($payment['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <!-- Actions -->
            <div class="ns-detail-card">
                <h2>عملیات</h2>
                <div class="ns-actions">
                    <button type="button" class="button button-link-delete" id="ns-delete-booking" 
                            data-booking-id="<?php echo $booking_id; ?>">
                        🗑️ حذف رزرو
                    </button>
                </div>
            </div>
        </div>
        
        <style>
            .ns-booking-detail-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin: 20px 0;
            }
            @media (max-width: 992px) {
                .ns-booking-detail-grid {
                    grid-template-columns: 1fr;
                }
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
            .ns-status-badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 12px;
                color: #fff;
                font-size: 12px;
                font-weight: 600;
            }
            .ns-passenger-card {
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                padding: 15px;
                margin-bottom: 15px;
            }
            .ns-passenger-card h3 {
                margin-top: 0;
                color: #1f2937;
            }
            .ns-passenger-info {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: 10px;
                margin-bottom: 15px;
            }
            .ns-passenger-docs {
                border-top: 1px dashed #e5e7eb;
                padding-top: 15px;
            }
            .ns-docs-grid {
                display: flex;
                flex-wrap: wrap;
                gap: 15px;
            }
            .ns-doc-item {
                width: 120px;
                text-align: center;
                border: 2px solid #e5e7eb;
                border-radius: 8px;
                padding: 10px;
            }
            .ns-doc-item.verified {
                border-color: #22c55e;
            }
            .ns-doc-item img {
                max-width: 100%;
                max-height: 80px;
                border-radius: 4px;
            }
            .ns-doc-pdf {
                display: block;
                padding: 20px;
                background: #fee2e2;
                color: #dc2626;
                font-weight: bold;
                border-radius: 4px;
            }
            .ns-doc-info {
                margin-top: 8px;
            }
            .ns-doc-label {
                display: block;
                font-size: 11px;
                color: #6b7280;
            }
            .ns-doc-verified {
                color: #22c55e;
                font-size: 12px;
            }
            .ns-no-docs {
                color: #9ca3af;
                font-style: italic;
            }
            .ns-actions {
                display: flex;
                gap: 10px;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Update status
            $('#ns-update-status').on('click', function() {
                var bookingId = $('#ns-status-select').data('booking-id');
                var status = $('#ns-status-select').val();
                
                if (!confirm('آیا مطمئن هستید؟')) return;
                
                $.post(nsBooking.ajaxUrl, {
                    action: 'ns_update_booking_status',
                    nonce: nsBooking.nonce,
                    booking_id: bookingId,
                    status: status
                }, function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message);
                    }
                });
            });
            
            // Verify document
            $('.ns-verify-doc').on('click', function() {
                var docId = $(this).data('doc-id');
                
                $.post(nsBooking.ajaxUrl, {
                    action: 'ns_verify_document',
                    nonce: nsBooking.nonce,
                    document_id: docId
                }, function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        location.reload();
                    } else {
                        alert(response.data.message);
                    }
                });
            });
            
            // Delete booking
            $('#ns-delete-booking').on('click', function() {
                var bookingId = $(this).data('booking-id');
                
                if (!confirm('آیا مطمئن هستید که می‌خواهید این رزرو را حذف کنید؟ این عمل قابل بازگشت نیست.')) return;
                
                $.post(nsBooking.ajaxUrl, {
                    action: 'ns_delete_booking',
                    nonce: nsBooking.nonce,
                    booking_id: bookingId
                }, function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        window.location.href = '<?php echo admin_url('admin.php?page=nextsafar-bookings'); ?>';
                    } else {
                        alert(response.data.message);
                    }
                });
            });
        });
        </script>
        <?php
    }
}