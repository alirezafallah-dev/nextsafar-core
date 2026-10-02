<?php
/**
 * NextSafar Core - Visa Booking Adapter
 * 
 * Implements booking logic specifically for visa bookings.
 * Part of the Adapter Pattern for multi-type booking support.
 * 
 * @package NextSafar\Booking\Adapters
 * @since   2.7.0
 */

namespace NextSafar\Booking\Adapters;

use NextSafar\Booking\BookingTable;
use NextSafar\Booking\BookingPassengerTable;
use NextSafar\Booking\BookingDocumentTable;
use NextSafar\Booking\VisaPriceCalculator;
use NextSafar\Booking\VisaValidator;
use NextSafar\Booking\BookingFileUploader;
use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class VisaBookingAdapter {
    
    /**
     * Booking type identifier
     */
    const TYPE = 'visa';
    
    /**
     * Get booking type
     * 
     * @return string
     */
    public static function get_type(): string {
        return self::TYPE;
    }
    
    /**
     * Get visa item details
     * 
     * @param int $visa_post_id
     * @return array|\WP_Error
     */
    public static function get_item_details(int $visa_post_id): array|\WP_Error {
        $post = get_post($visa_post_id);
        
        if (!$post || $post->post_type !== 'visa' || $post->post_status !== 'publish') {
            return new \WP_Error('not_found', 'ویزای مورد نظر یافت نشد.', ['status' => 404]);
        }
        
        // Get meta data
        $prices = get_post_meta($visa_post_id, '_visa_prices', true) ?: [];
        $issue = get_post_meta($visa_post_id, '_visa_issue', true) ?: '';
        $expiry = get_post_meta($visa_post_id, '_visa_expiry', true) ?: '';
        $flag = get_post_meta($visa_post_id, '_visa_flag_image', true) ?: '';
        $bg = get_post_meta($visa_post_id, '_visa_bg_image', true) ?: '';
        $description = get_post_meta($visa_post_id, '_visa_description', true) ?: '';
        $docs = get_post_meta($visa_post_id, '_visa_docs', true) ?: [];
        
        // Get country from taxonomy
        $countries = get_the_terms($visa_post_id, 'visa_category');
        $country = !is_wp_error($countries) && !empty($countries) ? $countries[0]->name : '';
        
        // Build unique visa types (grouped by type + duration)
        $unique_visas = [];
        $seen = [];
        
        foreach ($prices as $index => $price) {
            $key = ($price['type'] ?? '') . '|' . ($price['duration'] ?? '');
            
            if (!isset($seen[$key]) || ($price['person'] ?? '') === 'بزرگسال') {
                $unique_visas[$index] = $price;
                $seen[$key] = true;
            }
        }
        
        return [
            'id'           => $visa_post_id,
            'title'        => $post->post_title,
            'slug'         => $post->post_name,
            'country'      => $country,
            'issue_time'   => $issue,
            'expiry_time'  => $expiry,
            'flag_image'   => $flag,
            'bg_image'     => $bg,
            'description'  => $description,
            'prices'       => $prices,
            'unique_visas' => array_values($unique_visas),
            'documents'    => self::format_documents($docs),
        ];
    }
    
    /**
     * Create a visa booking
     * 
     * @param array $data Booking data
     * @return array|\WP_Error Booking data or error
     */
    public static function create_booking(array $data): array|\WP_Error {
        // Step 1: Validate all data
        $validation = VisaValidator::validate_booking($data);
        
        if (is_wp_error($validation)) {
            return $validation;
        }
        
        // Step 2: Calculate price
        $price_data = VisaPriceCalculator::calculate([
            'visa_post_id' => (int) ($data['visa_post_id'] ?? $data['post_id']),
            'price_index'  => (int) ($data['visa_index'] ?? $data['visa'] ?? 0),
            'adults'       => (int) ($data['adults'] ?? 0),
            'children'     => (int) ($data['children'] ?? 0),
        ]);
        
        if (is_wp_error($price_data)) {
            return $price_data;
        }
        
        // Step 3: Get or create user
        $user_id = self::get_or_create_user($data);
        
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        
        // Step 4: Generate booking code
        $booking_code = BookingTable::generate_booking_code(self::TYPE);
        
        // Step 5: Insert booking record
        $booking_id = BookingTable::insert([
            'booking_code'     => $booking_code,
            'user_id'          => $user_id,
            'booking_type'     => self::TYPE,
            'item_id'          => (int) $price_data['visa_post_id'],
            'status'           => 'awaiting_documents', 
            'total_price'      => $price_data['total_price_rial'],
            'currency'         => 'IRR',
            'passenger_count'  => $price_data['total_passengers'],
            'booking_data'     => [
                'visa_post_id'   => $price_data['visa_post_id'],
                'price_index'    => $price_data['price_index'],
                'visa_type'      => $price_data['type'],
                'visa_duration'  => $price_data['duration'],
                'price_breakdown' => $price_data,
            ],
            'notes'            => '',
        ]);
        
        if (!$booking_id) {
            return new \WP_Error('db_error', 'خطا در ایجاد رزرو. لطفاً دوباره تلاش کنید.', ['status' => 500]);
        }
        
        // Step 6: Insert passengers
        $passenger_ids = self::insert_passengers($booking_id, $data);
        
        if (is_wp_error($passenger_ids)) {
            // Rollback: delete booking
            BookingTable::delete($booking_id);
            return $passenger_ids;
        }
        
        // Step 7: Handle document uploads
        $doc_result = self::handle_document_uploads($booking_id, $data, $passenger_ids);
        
        Logger::info('Visa booking created', [
            'booking_id'   => $booking_id,
            'booking_code' => $booking_code,
            'user_id'      => $user_id,
            'total_price'  => $price_data['total_price_rial'],
            'adults'       => $price_data['adults'],
            'children'     => $price_data['children'],
        ]);
        
        return [
            'booking_id'   => $booking_id,
            'booking_code' => $booking_code,
            'status'       => 'awaiting_payment',
            'total_price'  => $price_data['total_price_rial'],
            'price_data'   => $price_data,
            'passengers'   => $passenger_ids,
            'documents'    => $doc_result,
        ];
    }
    
    /**
     * Insert passengers for a booking
     * 
     * @param int $booking_id
     * @param array $data
     * @return array|\WP_Error Passenger IDs or error
     */
    private static function insert_passengers(int $booking_id, array $data): array|\WP_Error {
        $adults_data   = $data['adult'] ?? [];
        $children_data = $data['child'] ?? [];
        
        $passengers = [];
        
        // Add adults
        foreach ($adults_data as $index => $adult) {
            $passengers[] = [
                'type'               => 'adult',
                'index_in_booking'   => $index,
                'first_name'         => sanitize_text_field($adult['first_name'] ?? ''),
                'last_name'          => sanitize_text_field($adult['last_name'] ?? ''),
                'national_id'        => sanitize_text_field($adult['national_id'] ?? ''),
                'passport_number'    => sanitize_text_field($adult['passport_number'] ?? ''),
                'phone'              => sanitize_text_field($adult['phone'] ?? ''),
                'email'              => sanitize_email($adult['email'] ?? ''),
                'birth_date'         => sanitize_text_field($adult['birth_date'] ?? ''),
                'travel_date'        => sanitize_text_field($adult['travel_date'] ?? ''),
                'passport_expiry_date' => sanitize_text_field($adult['passport_expiry'] ?? ''),
            ];
        }
        
        // Add children
        $offset = count($adults_data);
        foreach ($children_data as $index => $child) {
            $passengers[] = [
                'type'               => 'child',
                'index_in_booking'   => $offset + $index,
                'first_name'         => sanitize_text_field($child['first_name'] ?? ''),
                'last_name'          => sanitize_text_field($child['last_name'] ?? ''),
                'national_id'        => sanitize_text_field($child['national_id'] ?? ''),
                'passport_number'    => sanitize_text_field($child['passport_number'] ?? ''),
                'phone'              => '',
                'email'              => '',
                'birth_date'         => sanitize_text_field($child['birth_date'] ?? ''),
                'travel_date'        => null,
                'passport_expiry_date' => sanitize_text_field($child['passport_expiry'] ?? ''),
            ];
        }
        
        // Batch insert
        $ids = BookingPassengerTable::insert_batch($booking_id, $passengers);
        
        if (count($ids) !== count($passengers)) {
            return new \WP_Error('db_error', 'خطا در ذخیره اطلاعات مسافران.', ['status' => 500]);
        }
        
        return $ids;
    }
    
    /**
     * Handle document uploads
     * 
     * @param int $booking_id
     * @param array $data
     * @param array $passenger_ids
     * @return array Upload results
     */
    private static function handle_document_uploads(
        int $booking_id,
        array $data,
        array $passenger_ids
    ): array {
        $results = [
            'uploaded' => 0,
            'errors'   => [],
        ];
        
        // Process adult documents
        $adults_data = $data['adult'] ?? [];
        $adult_files = $_FILES['adult'] ?? [];
        
        foreach ($adults_data as $index => $adult) {
            if (!isset($passenger_ids[$index])) continue;
            
            $passenger_id = $passenger_ids[$index];
            
            // Extract files for this passenger
            $files = self::extract_passenger_files($adult_files, $index, 'docs');
            
            if (!empty($files)) {
                $upload_result = BookingFileUploader::upload_documents(
                    $files,
                    $booking_id,
                    $passenger_id
                );
                
                $results['uploaded'] += count($upload_result['success']);
                $results['errors'] = array_merge($results['errors'], $upload_result['errors']);
            }
        }
        
        // Process child documents
        $children_data = $data['child'] ?? [];
        $child_files = $_FILES['child'] ?? [];
        
        foreach ($children_data as $index => $child) {
            $passenger_index = count($adults_data) + $index;
            if (!isset($passenger_ids[$passenger_index])) continue;
            
            $passenger_id = $passenger_ids[$passenger_index];
            
            // Extract files for this passenger
            $files = self::extract_passenger_files($child_files, $index, 'docs');
            
            if (!empty($files)) {
                $upload_result = BookingFileUploader::upload_documents(
                    $files,
                    $booking_id,
                    $passenger_id
                );
                
                $results['uploaded'] += count($upload_result['success']);
                $results['errors'] = array_merge($results['errors'], $upload_result['errors']);
            }
        }
        
        return $results;
    }
    
    /**
     * Extract files for a specific passenger from $_FILES array
     * 
     * @param array $files_array The $_FILES['adult'] or $_FILES['child'] array
     * @param int $index Passenger index
     * @param string $field Field name ('docs')
     * @return array Extracted files
     */
    private static function extract_passenger_files(array $files_array, int $index, string $field): array {
        if (!isset($files_array[$field])) {
            return [];
        }
        
        $passenger_docs = $files_array[$field];
        $extracted = [];
        
        // Handle nested array structure: adult[docs][index][doc_slug][]
        foreach ($passenger_docs as $doc_slug => $doc_data) {
            if (!isset($doc_data[$index])) continue;
            
            $file_data = $doc_data[$index];
            
            // Skip if no file
            if (empty($file_data['name']) || (is_array($file_data['name']) && empty($file_data['name'][0]))) {
                continue;
            }
            
            $extracted[$doc_slug] = $file_data;
        }
        
        return $extracted;
    }
    
    /**
     * Get or create user from booking data
     * 
     * @param array $data
     * @return int|\WP_Error User ID or error
     */
    public static function get_or_create_user(array $data): int|\WP_Error {
        // If user is logged in, use their ID
        if (is_user_logged_in()) {
            return get_current_user_id();
        }
        
        // Otherwise, find or create user by phone
        $phone = sanitize_text_field($data['main_phone'] ?? '');
        
        if (empty($phone)) {
            return new \WP_Error('no_phone', 'شماره تلفن الزامی است.', ['status' => 400]);
        }
        
        // Search for existing user by phone
        $users = get_users([
            'meta_key'   => '_ns_phone',
            'meta_value' => $phone,
            'number'     => 1,
        ]);
        
        if (!empty($users)) {
            return $users[0]->ID;
        }
        
        // Create new user
        $email = sanitize_email($data['main_mail'] ?? '');
        $username = 'ns_' . preg_replace('/[^0-9]/', '', $phone);
        
        $user_id = wp_create_user($username, wp_generate_password(12, false), $email ?: $username . '@nextsafar.local');
        
        if (is_wp_error($user_id)) {
            // If username exists, try to find the user
            $existing_user = get_user_by('login', $username);
            if ($existing_user) {
                return $existing_user->ID;
            }
            
            Logger::error('Failed to create user', [
                'phone' => $phone,
                'error' => $user_id->get_error_message(),
            ]);
            
            return new \WP_Error('user_error', 'خطا در ایجاد حساب کاربری.', ['status' => 500]);
        }
        
        // Save phone number
        update_user_meta($user_id, '_ns_phone', $phone);
        
        Logger::info('New user created for visa booking', [
            'user_id' => $user_id,
            'phone'   => $phone,
        ]);
        
        return (int) $user_id;
    }
    
    /**
     * Process booking after successful payment
     * 
     * @param int $booking_id
     * @return bool|\WP_Error
     */
    public static function process_after_payment(int $booking_id): bool|\WP_Error {
        // Update booking status
        $updated = BookingTable::update_status($booking_id, 'processing');
        
        if (!$updated) {
            return new \WP_Error('db_error', 'خطا در به‌روزرسانی وضعیت رزرو.', ['status' => 500]);
        }
        
        // Get booking data
        $booking = BookingTable::find($booking_id);
        
        if (!$booking) {
            return new \WP_Error('not_found', 'رزرو یافت نشد.', ['status' => 404]);
        }
        
        // Send SMS notification
        self::send_confirmation_sms($booking);
        
        Logger::info('Visa booking processed after payment', [
            'booking_id' => $booking_id,
        ]);
        
        return true;
    }
    

    /**
     * Cancel a booking with full payment check
     */
    public static function cancel_booking(int $booking_id, string $reason = '', bool $by_admin = false): array|\WP_Error {
        $booking = BookingTable::find($booking_id);
        
        if (!$booking) {
            return new \WP_Error('not_found', 'رزرو یافت نشد.', ['status' => 404]);
        }
        
        // ✅ FIX: Complete list of cancellable statuses
        $cancellable_statuses = [
            'pending',            // در انتظار
            'awaiting_documents', // در انتظار مدارک
            'awaiting_payment',   // در انتظار پرداخت
            'paid',               // پرداخت شده (نیاز به بازپرداخت)
        ];
        
        if (!in_array($booking['status'], $cancellable_statuses, true)) {
            return new \WP_Error(
                'cannot_cancel',
                'این رزرو در وضعیت فعلی قابل لغو نیست. فقط رزروهایی که هنوز در حال پردازش نشده‌اند قابل لغو هستند.',
                ['status' => 400]
            );
        }
        
        // ✅ NEW: Check for successful payments
        $payments = \NextSafar\Payment\PaymentTable::find_by_booking($booking_id);
        $successful_payments = array_filter($payments, function ($p) {
            return $p['status'] === 'success';
        });
        
        $needs_refund = !empty($successful_payments);
        $refund_status = 'not_needed';
        $total_refund_amount = 0;
        
        if ($needs_refund) {
            // Calculate total refund amount
            foreach ($successful_payments as $payment) {
                $total_refund_amount += (float) $payment['amount'];
            }
            
            // ✅ Mark payments for refund
            foreach ($successful_payments as $payment) {
                \NextSafar\Payment\PaymentTable::update_status($payment['id'], 'refunded', [
                    'callback_data' => [
                        'refund_reason' => $reason ?: 'لغو رزرو توسط کاربر',
                        'refunded_at'   => current_time('mysql'),
                        'refunded_by'   => $by_admin ? 'admin' : 'user',
                        'booking_code'  => $booking['booking_code'],
                    ],
                ]);
            }
            
            $refund_status = 'initiated';
        }
        
        // ✅ Update booking status
        $updated = BookingTable::update($booking_id, [
            'status' => 'cancelled',
            'notes'  => sprintf(
                "لغو شده در %s | دلیل: %s | توسط: %s | بازپرداخت: %s",
                current_time('mysql'),
                $reason ?: 'ذکر نشده',
                $by_admin ? 'ادمین' : 'کاربر',
                $needs_refund ? number_format($total_refund_amount) . ' ریال' : 'ندارد'
            ),
        ]);
        
        if (!$updated) {
            return new \WP_Error('db_error', 'خطا در لغو رزرو.', ['status' => 500]);
        }
        
        // ✅ NEW: Send SMS notification
        $passenger_info = is_string($booking['passenger_info']) 
            ? json_decode($booking['passenger_info'], true) 
            : $booking['passenger_info'];
        
        $phone = $passenger_info['main_phone'] ?? '';
        
        if (!empty($phone)) {
            BookingSms::send_cancellation_notification(
                $phone, 
                $booking['booking_code'],
                $needs_refund,
                $total_refund_amount
            );
        }
        
        // ✅ NEW: Clean up uploaded documents (optional, only if not paid)
        if (!$needs_refund) {
            BookingFileUploader::delete_booking_documents($booking_id, true);
        }
        
        Logger::info('Visa booking cancelled', [
            'booking_id'      => $booking_id,
            'booking_code'    => $booking['booking_code'],
            'reason'          => $reason,
            'by_admin'        => $by_admin,
            'needs_refund'    => $needs_refund,
            'refund_amount'   => $total_refund_amount,
            'previous_status' => $booking['status'],
        ]);
        
        return [
            'booking_id'      => $booking_id,
            'booking_code'    => $booking['booking_code'],
            'status'          => 'cancelled',
            'needs_refund'    => $needs_refund,
            'refund_status'   => $refund_status,
            'refund_amount'   => $total_refund_amount,
            'message'         => $needs_refund 
                ? 'رزرو لغو شد. مبلغ پرداختی به زودی به حساب شما بازگردانده می‌شود.'
                : 'رزرو با موفقیت لغو شد.',
        ];
    }
    
    /**
     * Send confirmation SMS
     * 
     * @param array $booking
     * @return bool
     */
    private static function send_confirmation_sms(array $booking): bool {
        $phone = $booking['passenger_info']['main_phone'] ?? '';
        
        if (empty($phone)) {
            // Get from first passenger
            $passengers = BookingPassengerTable::get_by_booking($booking['id']);
            if (!empty($passengers)) {
                $phone = $passengers[0]['phone'] ?? '';
            }
        }
        
        if (empty($phone)) {
            return false;
        }
        
        // Format booking code
        $booking_code = $booking['booking_code'];
        
        $message = sprintf(
            'رزرو ویزای شما با موفقیت ثبت شد. شماره رزرو: %s - نکست سفر',
            $booking_code
        );
        
        // TODO: Implement SMS sending (e.g., Payamak Panel)
        // For now, just log
        Logger::info('SMS confirmation queued', [
            'booking_id' => $booking['id'],
            'phone'      => $phone,
        ]);
        
        return true;
    }
    
    /**
     * Format documents for API response
     * 
     * @param array $docs
     * @return array
     */
    private static function format_documents(array $docs): array {
        $formatted = [];
        
        foreach ($docs as $label => $config) {
            if (!empty($config['checked'])) {
                $formatted[] = [
                    'label' => $label,
                    'slug'  => BookingFileUploader::get_doc_label($label),
                    'note'  => $config['text'] ?? '',
                ];
            }
        }
        
        return $formatted;
    }
}