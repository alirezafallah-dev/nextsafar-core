<?php
/**
 * NextSafar Core - Booking SMS Service
 * 
 * Handles SMS notifications for bookings.
 * Uses Payamak Panel API (rest.payamak-panel.com).
 * 
 * @package NextSafar\Booking
 * @since   2.7.0
 */

namespace NextSafar\Booking;

use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class BookingSms {
    
    /**
     * Option keys for SMS settings
     */
    const OPTION_USERNAME = 'ns_sms_username';
    const OPTION_PASSWORD = 'ns_sms_password';
    const OPTION_FROM     = 'ns_sms_from';
    const OPTION_ENABLED  = 'ns_sms_enabled';
    
    /**
     * Payamak Panel API URL
     */
    const API_URL = 'https://rest.payamak-panel.com/api/SendSMS/SendSMS';
    
    /**
     * Request timeout
     */
    const TIMEOUT = 20;
    
    /**
     * Send payment confirmation SMS
     * 
     * @param string $phone Recipient phone number
     * @param string $booking_code Booking code
     * @return bool
     */
    public static function send_payment_confirmation(string $phone, string $booking_code): bool {
        $message = sprintf(
            'پرداخت رزرو ویزای شما با موفقیت انجام شد. شماره رزرو: %s - نکست سفر',
            $booking_code
        );
        
        return self::send($phone, $message);
    }
    
    /**
     * Send booking created SMS
     * 
     * @param string $phone
     * @param string $booking_code
     * @return bool
     */
    public static function send_booking_created(string $phone, string $booking_code): bool {
        $message = sprintf(
            'رزرو ویزای شما ثبت شد. شماره رزرو: %s - لطفاً برای تکمیل، مدارک را بارگذاری و پرداخت را انجام دهید. نکست سفر',
            $booking_code
        );
        
        return self::send($phone, $message);
    }
    
    /**
     * Send booking cancelled SMS
     * 
     * @param string $phone
     * @param string $booking_code
     * @return bool
     */
    public static function send_booking_cancelled(string $phone, string $booking_code): bool {
        $message = sprintf(
            'رزرو ویزای شما به شماره %s لغو شد. نکست سفر',
            $booking_code
        );
        
        return self::send($phone, $message);
    }
    
    /**
     * Send SMS via Payamak Panel
     * 
     * @param string $to Recipient phone
     * @param string $text Message text
     * @return bool
     */
    public static function send(string $to, string $text): bool {
        // Check if SMS is enabled
        if (!self::is_enabled()) {
            Logger::debug('SMS disabled, skipping', [
                'to' => $to,
            ]);
            return false;
        }
        
        // Validate phone
        if (!self::is_valid_phone($to)) {
            Logger::warning('Invalid SMS phone number', [
                'phone' => $to,
            ]);
            return false;
        }
        
        // Get credentials
        $username = self::get_username();
        $password = self::get_password();
        $from     = self::get_from();
        
        if (empty($username) || empty($password)) {
            Logger::warning('SMS credentials not configured');
            return false;
        }
        
        // Prepare request
        $body = wp_json_encode([
            'username' => $username,
            'password' => $password,
            'to'       => $to,
            'from'     => $from,
            'text'     => $text,
            'isflash'  => false,
        ], JSON_UNESCAPED_UNICODE);
        
        Logger::info('Sending SMS', [
            'to'   => $to,
            'text' => mb_substr($text, 0, 50),
        ]);
        
        // Send request
        $response = wp_remote_post(self::API_URL, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body'    => $body,
            'timeout' => self::TIMEOUT,
        ]);
        
        if (is_wp_error($response)) {
            Logger::error('SMS send failed', [
                'to'    => $to,
                'error' => $response->get_error_message(),
            ]);
            return false;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body        = wp_remote_retrieve_body($response);
        
        if ($status_code !== 200) {
            Logger::error('SMS API returned error', [
                'status' => $status_code,
                'body'   => mb_substr($body, 0, 200),
            ]);
            return false;
        }
        
        $result = json_decode($body, true);
        
        // Payamak Panel returns 200 on success with value > 0
        if (isset($result['value']) && $result['value'] > 0) {
            Logger::info('SMS sent successfully', [
                'to' => $to,
            ]);
            return true;
        }
        
        Logger::warning('SMS send returned unexpected result', [
            'to'     => $to,
            'result' => $result,
        ]);
        
        return false;
    }
    
    /**
     * Check if SMS is enabled
     */
    public static function is_enabled(): bool {
        return (bool) get_option(self::OPTION_ENABLED, true);
    }
    
    /**
     * Get SMS username
     */
    public static function get_username(): string {
        return get_option(self::OPTION_USERNAME, '09034368406');
    }
    
    /**
     * Get SMS password
     */
    public static function get_password(): string {
        return get_option(self::OPTION_PASSWORD, 'bd726139-5461-4a19-bd3a-a4e67529e345');
    }
    
    /**
     * Get sender number
     */
    public static function get_from(): string {
        return get_option(self::OPTION_FROM, '50002710068406');
    }
    
    /**
     * Validate phone number
     */
    private static function is_valid_phone(string $phone): bool {
        // Iranian mobile: 09XXXXXXXXX
        return (bool) preg_match('/^09\d{9}$/', $phone);
    }
    
    /**
     * Send cancellation notification SMS
     */
    public static function send_cancellation_notification(
        string $phone, 
        string $booking_code, 
        bool $has_refund = false,
        float $refund_amount = 0
    ): bool {
        if (empty($phone)) {
            return false;
        }
        
        if ($has_refund) {
            $message = sprintf(
                "کاربر گرامی،\nرزرو %s لغو شد.\nمبلغ %s ریال به زودی به حساب شما بازگردانده می‌شود.\nنکست‌سفر",
                $booking_code,
                number_format($refund_amount)
            );
        } else {
            $message = sprintf(
                "کاربر گرامی،\nرزرو %s با موفقیت لغو شد.\nدر صورت نیاز، می‌توانید مجدداً رزرو انجام دهید.\nنکست‌سفر",
                $booking_code
            );
        }
        
        return self::send($phone, $message);
    }

    /**
     * Update SMS settings
     */
    public static function update_settings(array $settings): void {
        if (isset($settings['username'])) {
            update_option(self::OPTION_USERNAME, sanitize_text_field($settings['username']));
        }
        if (isset($settings['password'])) {
            update_option(self::OPTION_PASSWORD, sanitize_text_field($settings['password']));
        }
        if (isset($settings['from'])) {
            update_option(self::OPTION_FROM, sanitize_text_field($settings['from']));
        }
        if (isset($settings['enabled'])) {
            update_option(self::OPTION_ENABLED, (bool) $settings['enabled']);
        }
        
        Logger::info('SMS settings updated');
    }
}