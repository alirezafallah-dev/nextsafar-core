<?php
/**
 * NextSafar Core - Admin Booking Menu
 * 
 * Registers admin menu and pages for booking management.
 * 
 * @package NextSafar\Admin\Booking
 * @since   2.7.0
 */

namespace NextSafar\Admin\Booking;

if (!defined('ABSPATH')) exit;

class AdminBookingMenu {
    
    /**
     * Initialize
     */
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'add_menu'], 25);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        
        // AJAX handlers
        add_action('wp_ajax_ns_update_booking_status', [__CLASS__, 'ajax_update_status']);
        add_action('wp_ajax_ns_verify_document', [__CLASS__, 'ajax_verify_document']);
        add_action('wp_ajax_ns_delete_booking', [__CLASS__, 'ajax_delete_booking']);
    }
    
    /**
     * Add menu items
     */
    public static function add_menu(): void {
        // Bookings list page
        add_submenu_page(
            'nextsafar-dashboard',
            'مدیریت رزروها',
            'رزروها',
            'manage_options',
            'nextsafar-bookings',
            [AdminBookingList::class, 'render_page']
        );
        
        // Booking detail page (hidden from menu)
        add_submenu_page(
            null, // Hidden
            'جزئیات رزرو',
            'جزئیات رزرو',
            'manage_options',
            'nextsafar-booking-detail',
            [AdminBookingDetail::class, 'render_page']
        );
        
        // Booking stats page
        add_submenu_page(
            'nextsafar-dashboard',
            'آمار رزروها',
            'آمار رزروها',
            'manage_options',
            'nextsafar-booking-stats',
            [AdminBookingStats::class, 'render_page']
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public static function enqueue_assets(string $hook): void {
        if (strpos($hook, 'nextsafar-booking') === false) {
            return;
        }
        
        wp_enqueue_style(
            'ns-admin-booking',
            NEXTSAFAR_URL . 'assets/admin/booking.css',
            [],
            NEXTSAFAR_VERSION
        );
        
        wp_enqueue_script(
            'ns-admin-booking',
            NEXTSAFAR_URL . 'assets/admin/booking.js',
            ['jquery'],
            NEXTSAFAR_VERSION,
            true
        );
        
        wp_localize_script('ns-admin-booking', 'nsBooking', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('ns_booking_admin'),
        ]);
    }
    
    /**
     * AJAX: Update booking status
     */
    public static function ajax_update_status(): void {
        check_ajax_referer('ns_booking_admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی مجاز نیست']);
        }
        
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $status     = sanitize_text_field($_POST['status'] ?? '');
        
        if ($booking_id <= 0 || empty($status)) {
            wp_send_json_error(['message' => 'پارامترهای نامعتبر']);
        }
        
        $valid_statuses = [
            'pending', 'awaiting_documents', 'awaiting_payment',
            'paid', 'processing', 'completed', 'cancelled', 'refunded', 'failed'
        ];
        
        if (!in_array($status, $valid_statuses)) {
            wp_send_json_error(['message' => 'وضعیت نامعتبر']);
        }
        
        $updated = \NextSafar\Booking\BookingTable::update_status($booking_id, $status);
        
        if ($updated) {
            \NextSafar\Core\Logger::info('Booking status updated by admin', [
                'booking_id' => $booking_id,
                'new_status' => $status,
            ]);
            wp_send_json_success(['message' => 'وضعیت رزرو به‌روزرسانی شد']);
        }
        
        wp_send_json_error(['message' => 'خطا در به‌روزرسانی']);
    }
    
    /**
     * AJAX: Verify document
     */
    public static function ajax_verify_document(): void {
        check_ajax_referer('ns_booking_admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی مجاز نیست']);
        }
        
        $document_id = intval($_POST['document_id'] ?? 0);
        
        if ($document_id <= 0) {
            wp_send_json_error(['message' => 'پارامترهای نامعتبر']);
        }
        
        $verified = \NextSafar\Booking\BookingDocumentTable::mark_verified(
            $document_id,
            get_current_user_id()
        );
        
        if ($verified) {
            wp_send_json_success(['message' => 'مدرک تأیید شد']);
        }
        
        wp_send_json_error(['message' => 'خطا در تأیید مدرک']);
    }
    
    /**
     * AJAX: Delete booking
     */
    public static function ajax_delete_booking(): void {
        check_ajax_referer('ns_booking_admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'دسترسی مجاز نیست']);
        }
        
        $booking_id = intval($_POST['booking_id'] ?? 0);
        
        if ($booking_id <= 0) {
            wp_send_json_error(['message' => 'پارامترهای نامعتبر']);
        }
        
        // Delete documents first
        \NextSafar\Booking\BookingFileUploader::delete_booking_documents($booking_id);
        
        // Delete passengers
        \NextSafar\Booking\BookingPassengerTable::delete_by_booking($booking_id);
        
        // Delete booking
        $deleted = \NextSafar\Booking\BookingTable::delete($booking_id);
        
        if ($deleted) {
            \NextSafar\Core\Logger::info('Booking deleted by admin', [
                'booking_id' => $booking_id,
            ]);
            wp_send_json_success(['message' => 'رزرو حذف شد']);
        }
        
        wp_send_json_error(['message' => 'خطا در حذف رزرو']);
    }
}

// Initialize
AdminBookingMenu::init();