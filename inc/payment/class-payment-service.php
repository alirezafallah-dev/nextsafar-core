<?php
/**
 * NextSafar Core - Payment Service
 * 
 * Orchestrates the payment process:
 * 1. Initialize payment (create transaction)
 * 2. Handle callback (verify payment)
 * 3. Update booking status
 * 4. Send notifications (SMS)
 * 
 * @package NextSafar\Payment
 * @since   2.7.0
 */

namespace NextSafar\Payment;

use NextSafar\Payment\Gateways\ZarinpalGateway;
use NextSafar\Booking\BookingTable;
use NextSafar\Booking\BookingSms;
use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class PaymentService {
    
    /**
     * Available gateways
     */
    private static array $gateways = [];
    
    /**
     * Get default gateway
     * 
     * @return PaymentGatewayInterface
     */
    public static function get_gateway(): PaymentGatewayInterface {
        // Currently only Zarinpal is active
        // In the future, this will be configurable
        return new ZarinpalGateway();
    }
    
    /**
     * Initialize payment for a booking
     * 
     * @param int $booking_id Booking ID
     * @return array|\WP_Error Array with redirect_url or WP_Error
     */
    public static function init_payment(int $booking_id): array|\WP_Error {
        // Step 1: Get booking
        $booking = BookingTable::find($booking_id);
        
        if (!$booking) {
            return new \WP_Error('booking_not_found', 'رزرو مورد نظر یافت نشد.', ['status' => 404]);
        }
        
        // Step 2: Check booking status
        if ($booking['status'] !== 'awaiting_payment') {
            return new \WP_Error(
                'invalid_status',
                'این رزرو در وضعیت فعلی آماده پرداخت نیست.',
                ['status' => 400]
            );
        }
        
        // Step 3: Check amount
        $amount = (int) $booking['total_price'];
        
        if ($amount <= 0) {
            return new \WP_Error('invalid_amount', 'مبلغ رزرو نامعتبر است.', ['status' => 400]);
        }
        
        // Step 4: Get gateway
        $gateway = self::get_gateway();
        
        if (!$gateway->is_available()) {
            return new \WP_Error(
                'gateway_unavailable',
                'درگاه پرداخت در دسترس نیست. لطفاً با پشتیبانی تماس بگیرید.',
                ['status' => 503]
            );
        }
        
        // Step 5: Create payment record
        $payment_id = PaymentTable::insert([
            'booking_id'     => $booking_id,
            'user_id'        => $booking['user_id'],
            'gateway'        => $gateway->get_name(),
            'amount'         => $amount,
            'currency'       => 'IRR',
            'status'         => 'pending',
            'description'    => sprintf('پرداخت رزرو ویزا شماره %s', $booking['booking_code']),
        ]);
        
        if (!$payment_id) {
            return new \WP_Error('db_error', 'خطا در ایجاد رکورد پرداخت.', ['status' => 500]);
        }
        
        // Step 6: Build callback URL
        $callback_url = rest_url('nextsafar/v1/booking/visa/payment-callback') 
            . '?booking_id=' . $booking_id 
            . '&payment_id=' . $payment_id;
        
        // Step 7: Get passenger info for metadata
        $passenger_info = is_string($booking['passenger_info']) 
            ? json_decode($booking['passenger_info'], true) 
            : $booking['passenger_info'];
        
        // Step 8: Create transaction via gateway
        $transaction = $gateway->create_transaction([
            'amount'       => $amount,
            'callback_url' => $callback_url,
            'description'  => sprintf('پرداخت رزرو ویزا %s', $booking['booking_code']),
            'mobile'       => $passenger_info['main_phone'] ?? '',
            'email'        => $passenger_info['main_mail'] ?? '',
            'order_id'     => (string) $booking_id,
        ]);
        
        if (is_wp_error($transaction)) {
            // Update payment status to failed
            PaymentTable::update_status($payment_id, 'failed', [
                'gateway_response' => [
                    'error' => $transaction->get_error_message(),
                ],
            ]);
            
            return $transaction;
        }
        
        // Step 9: Update payment record with gateway reference
        PaymentTable::update($payment_id, [
            'status'         => 'redirecting',
            'gateway_ref_id' => $transaction['authority'],
        ]);
        
        Logger::info('Payment initialized', [
            'booking_id' => $booking_id,
            'payment_id' => $payment_id,
            'gateway'    => $gateway->get_name(),
            'amount'     => $amount,
        ]);
        
        return [
            'payment_id'   => $payment_id,
            'authority'    => $transaction['authority'],
            'redirect_url' => $transaction['redirect_url'],
        ];
    }
    
    /**
     * Handle payment callback from gateway
     * 
     * @param array $params Callback parameters from gateway
     * @return array|\WP_Error Result array or WP_Error
     */
    public static function handle_callback(array $params): array|\WP_Error {
        $booking_id = (int) ($params['booking_id'] ?? 0);
        $payment_id = (int) ($params['payment_id'] ?? 0);
        $status     = sanitize_text_field($params['Status'] ?? '');
        $authority  = sanitize_text_field($params['Authority'] ?? '');
        
        // Step 1: Validate parameters
        if ($booking_id <= 0 || $payment_id <= 0) {
            return new \WP_Error('invalid_params', 'پارامترهای بازگشت نامعتبر است.', ['status' => 400]);
        }
        
        // Step 2: Get booking and payment
        $booking = BookingTable::find($booking_id);
        $payment = PaymentTable::find($payment_id);
        
        if (!$booking || !$payment) {
            return new \WP_Error('not_found', 'رزرو یا پرداخت مورد نظر یافت نشد.', ['status' => 404]);
        }
        
        // Step 3: Check if user cancelled
        if ($status !== 'OK') {
            PaymentTable::update_status($payment_id, 'cancelled', [
                'callback_data' => $params,
            ]);
            
            Logger::info('Payment cancelled by user', [
                'booking_id' => $booking_id,
                'payment_id' => $payment_id,
            ]);
            
            return [
                'success'      => false,
                'cancelled'    => true,
                'booking_code' => $booking['booking_code'],
                'message'      => 'پرداخت توسط کاربر لغو شد.',
            ];
        }
        
        // Step 4: Get gateway and verify
        $gateway = self::get_gateway();
        
        $verification = $gateway->verify_transaction([
            'authority' => $authority,
            'amount'    => (int) $payment['amount'],
        ]);
        
        if (is_wp_error($verification)) {
            PaymentTable::update_status($payment_id, 'failed', [
                'callback_data' => $params,
                'gateway_response' => [
                    'error' => $verification->get_error_message(),
                ],
            ]);
            
            return $verification;
        }
        
        // Step 5: Check verification result
        if ($verification['verified']) {
            // Payment successful!
            PaymentTable::update_status($payment_id, 'success', [
                'callback_data'    => $params,
                'gateway_ref_id'   => $verification['ref_id'],
                'gateway_response' => $verification,
            ]);
            
            // Update booking status
            BookingTable::update($booking_id, [
                'status'     => 'paid',
                'payment_id' => $payment_id,
            ]);
            
            // Process booking after payment (e.g., send SMS)
            self::process_successful_payment($booking_id, $payment_id);
            
            Logger::info('Payment successful', [
                'booking_id' => $booking_id,
                'payment_id' => $payment_id,
                'ref_id'     => $verification['ref_id'],
            ]);
            
            return [
                'success'      => true,
                'booking_code' => $booking['booking_code'],
                'ref_id'       => $verification['ref_id'],
                'message'      => 'پرداخت با موفقیت انجام شد.',
            ];
        }
        
        // Payment failed
        PaymentTable::update_status($payment_id, 'failed', [
            'callback_data'    => $params,
            'gateway_response' => $verification,
        ]);
        
        Logger::warning('Payment verification failed', [
            'booking_id' => $booking_id,
            'payment_id' => $payment_id,
            'code'       => $verification['code'],
            'message'    => $verification['message'],
        ]);
        
        return [
            'success'      => false,
            'cancelled'    => false,
            'booking_code' => $booking['booking_code'],
            'message'      => $verification['message'] ?? 'پرداخت ناموفق بود.',
        ];
    }
    
    /**
     * Process successful payment (post-payment actions)
     * 
     * @param int $booking_id
     * @param int $payment_id
     * @return void
     */
    private static function process_successful_payment(int $booking_id, int $payment_id): void {
        $booking = BookingTable::find($booking_id);
        
        if (!$booking) {
            return;
        }
        
        // Step 1: Send confirmation SMS
        $passenger_info = is_string($booking['passenger_info']) 
            ? json_decode($booking['passenger_info'], true) 
            : $booking['passenger_info'];
        
        $phone = $passenger_info['main_phone'] ?? '';
        
        if (!empty($phone)) {
            BookingSms::send_payment_confirmation($phone, $booking['booking_code']);
        }
        
        // Step 2: Update booking status to processing
        BookingTable::update_status($booking_id, 'processing');
        
        // Step 3: Fire action hook for other plugins
        do_action('nextsafar_payment_completed', $booking_id, $payment_id);
        
        Logger::info('Post-payment processing completed', [
            'booking_id' => $booking_id,
        ]);
    }
    
    /**
     * Get payment status
     * 
     * @param int $payment_id
     * @return array|\WP_Error
     */
    public static function get_payment_status(int $payment_id): array|\WP_Error {
        $payment = PaymentTable::find($payment_id);
        
        if (!$payment) {
            return new \WP_Error('not_found', 'پرداخت مورد نظر یافت نشد.', ['status' => 404]);
        }
        
        return [
            'payment_id'   => $payment['id'],
            'booking_id'   => $payment['booking_id'],
            'status'       => $payment['status'],
            'gateway'      => $payment['gateway'],
            'amount'       => (float) $payment['amount'],
            'ref_id'       => $payment['gateway_ref_id'],
            'created_at'   => $payment['created_at'],
            'paid_at'      => $payment['paid_at'],
        ];
    }
    
    /**
     * Refund a payment (for admin)
     * 
     * @param int $payment_id
     * @param string $reason
     * @return bool|\WP_Error
     */
    public static function refund_payment(int $payment_id, string $reason = ''): bool|\WP_Error {
        $payment = PaymentTable::find($payment_id);
        
        if (!$payment) {
            return new \WP_Error('not_found', 'پرداخت مورد نظر یافت نشد.', ['status' => 404]);
        }
        
        if ($payment['status'] !== 'success') {
            return new \WP_Error('invalid_status', 'فقط پرداخت‌های موفق قابل بازپرداخت هستند.', ['status' => 400]);
        }
        
        // Note: Actual refund requires manual action in Zarinpal panel
        // This just updates the status
        
        PaymentTable::update_status($payment_id, 'refunded', [
            'callback_data' => [
                'refund_reason' => $reason,
                'refunded_at'   => current_time('mysql'),
            ],
        ]);
        
        // Update booking status
        BookingTable::update_status($payment['booking_id'], 'refunded');
        
        Logger::info('Payment refunded', [
            'payment_id' => $payment_id,
            'booking_id' => $payment['booking_id'],
            'reason'     => $reason,
        ]);
        
        return true;
    }
}