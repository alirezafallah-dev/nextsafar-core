<?php
/**
 * NextSafar Core - Zarinpal Payment Gateway
 * 
 * Implements PaymentGatewayInterface for Zarinpal.
 * Based on the working implementation from visa-payment.php.
 * 
 * @package NextSafar\Payment\Gateways
 * @since   2.7.0
 */

namespace NextSafar\Payment\Gateways;

use NextSafar\Payment\PaymentGatewayInterface;
use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class ZarinpalGateway implements PaymentGatewayInterface {
    
    /**
     * Option keys for settings
     */
    const OPTION_MERCHANT_ID = 'ns_zarinpal_merchant_id';
    const OPTION_IS_SANDBOX  = 'ns_zarinpal_is_sandbox';
    
    /**
     * Zarinpal API URLs
     */
    const PRODUCTION_REQUEST_URL = 'https://payment.zarinpal.com/pg/v4/payment/request.json';
    const PRODUCTION_VERIFY_URL  = 'https://payment.zarinpal.com/pg/v4/payment/verify.json';
    const PRODUCTION_START_PAY   = 'https://payment.zarinpal.com/pg/StartPay/';
    
    const SANDBOX_REQUEST_URL = 'https://sandbox.zarinpal.com/pg/v4/payment/request.json';
    const SANDBOX_VERIFY_URL  = 'https://sandbox.zarinpal.com/pg/v4/payment/verify.json';
    const SANDBOX_START_PAY   = 'https://sandbox.zarinpal.com/pg/StartPay/';
    
    /**
     * Success code from Zarinpal
     */
    const SUCCESS_CODE = 100;
    
    /**
     * Request timeout in seconds
     */
    const TIMEOUT = 30;
    
    /**
     * Merchant ID
     */
    private string $merchant_id;
    
    /**
     * Whether sandbox mode is enabled
     */
    private bool $is_sandbox;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->merchant_id = get_option(self::OPTION_MERCHANT_ID, '2a19a31c-1ce0-40ac-ba17-7dc4d57272c2');
        $this->is_sandbox  = (bool) get_option(self::OPTION_IS_SANDBOX, false);
    }
    
    /**
     * Get gateway name
     */
    public function get_name(): string {
        return 'zarinpal';
    }
    
    /**
     * Get display name
     */
    public function get_display_name(): string {
        return 'زرین‌پال';
    }
    
    /**
     * Check if gateway is available
     */
    public function is_available(): bool {
        return !empty($this->merchant_id);
    }
    
    /**
     * Create a new payment transaction
     * 
     * This is based on the working code from visa-payment.php
     */
    public function create_transaction(array $args): array|\WP_Error {
        // Validate required parameters
        if (empty($args['amount']) || $args['amount'] <= 0) {
            return new \WP_Error('invalid_amount', 'مبلغ پرداخت نامعتبر است.', ['status' => 400]);
        }
        
        if (empty($args['callback_url'])) {
            return new \WP_Error('missing_callback', 'آدرس بازگشت مشخص نشده است.', ['status' => 400]);
        }
        
        // Prepare request data
        $data = [
            'merchant_id'  => $this->merchant_id,
            'amount'       => (int) $args['amount'],
            'callback_url' => $args['callback_url'],
            'description'  => $args['description'] ?? 'پرداخت رزرو',
        ];
        
        // Add optional metadata
        $metadata = [];
        if (!empty($args['mobile'])) {
            $metadata['mobile'] = $args['mobile'];
        }
        if (!empty($args['email'])) {
            $metadata['email'] = $args['email'];
        }
        if (!empty($args['order_id'])) {
            $metadata['order_id'] = $args['order_id'];
        }
        
        if (!empty($metadata)) {
            $data['metadata'] = $metadata;
        }
        
        Logger::info('Zarinpal create transaction', [
            'amount'     => $args['amount'],
            'order_id'   => $args['order_id'] ?? '',
        ]);
        
        // Send request to Zarinpal
        $response = $this->send_request($this->get_request_url(), $data);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Check response
        if (isset($response['data']['code']) && $response['data']['code'] === self::SUCCESS_CODE) {
            $authority = $response['data']['authority'];
            
            Logger::info('Zarinpal transaction created', [
                'authority'  => $authority,
                'order_id'   => $args['order_id'] ?? '',
            ]);
            
            return [
                'success'      => true,
                'authority'    => $authority,
                'redirect_url' => $this->get_redirect_url($authority),
            ];
        }
        
        // Handle errors
        $error_code    = $response['errors']['code'] ?? 'unknown';
        $error_message = $response['errors']['message'] ?? 'خطای نامشخص در درگاه پرداخت';
        
        Logger::error('Zarinpal create transaction failed', [
            'code'     => $error_code,
            'message'  => $error_message,
            'order_id' => $args['order_id'] ?? '',
        ]);
        
        return new \WP_Error(
            'gateway_error',
            sprintf('خطا در ایجاد تراکنش (کد %s): %s', $error_code, $error_message),
            ['status' => 502]
        );
    }
    
    /**
     * Verify a transaction
     * 
     * This is based on the working code from visa-payment-callback.php
     */
    public function verify_transaction(array $args): array|\WP_Error {
        // Validate required parameters
        if (empty($args['authority'])) {
            return new \WP_Error('missing_authority', 'Authority ارسال نشده است.', ['status' => 400]);
        }
        
        if (empty($args['amount']) || $args['amount'] <= 0) {
            return new \WP_Error('invalid_amount', 'مبلغ پرداخت نامعتبر است.', ['status' => 400]);
        }
        
        // Prepare verify data
        $data = [
            'merchant_id' => $this->merchant_id,
            'amount'      => (int) $args['amount'],
            'authority'   => $args['authority'],
        ];
        
        Logger::info('Zarinpal verify transaction', [
            'authority' => $args['authority'],
            'amount'    => $args['amount'],
        ]);
        
        // Send verify request
        $response = $this->send_request($this->get_verify_url(), $data);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Check verification result
        if (isset($response['data']['code']) && $response['data']['code'] === self::SUCCESS_CODE) {
            $ref_id = $response['data']['ref_id'] ?? '';
            
            Logger::info('Zarinpal transaction verified', [
                'authority' => $args['authority'],
                'ref_id'    => $ref_id,
            ]);
            
            return [
                'verified' => true,
                'ref_id'   => $ref_id,
                'code'     => self::SUCCESS_CODE,
            ];
        }
        
        // Handle verification failure
        $error_code    = $response['errors']['code'] ?? ($response['data']['code'] ?? 'unknown');
        $error_message = $response['errors']['message'] ?? 'پرداخت ناموفق بود';
        
        Logger::warning('Zarinpal verification failed', [
            'authority' => $args['authority'],
            'code'      => $error_code,
            'message'   => $error_message,
        ]);
        
        return [
            'verified' => false,
            'ref_id'   => '',
            'code'     => $error_code,
            'message'  => $error_message,
        ];
    }
    
    /**
     * Get payment redirect URL
     */
    public function get_redirect_url(string $authority): string {
        $base_url = $this->is_sandbox ? self::SANDBOX_START_PAY : self::PRODUCTION_START_PAY;
        return $base_url . $authority;
    }
    
    /**
     * Test gateway connection
     */
    public function test_connection(): array {
        if (!$this->is_available()) {
            return [
                'ok'      => false,
                'message' => 'Merchant ID تنظیم نشده است.',
            ];
        }
        
        // Try a small test request (this will fail with amount error, but tests connectivity)
        $data = [
            'merchant_id'  => $this->merchant_id,
            'amount'       => 1000,
            'callback_url' => home_url('/test-callback'),
            'description'  => 'Test connection',
        ];
        
        $response = $this->send_request($this->get_request_url(), $data);
        
        if (is_wp_error($response)) {
            return [
                'ok'      => false,
                'message' => 'خطا در ارتباط با زرین‌پال: ' . $response->get_error_message(),
            ];
        }
        
        // If we got a response, connection is working
        // Code 100 = success, other codes = merchant error but connection works
        if (isset($response['data']['code'])) {
            return [
                'ok'      => true,
                'message' => 'اتصال به زرین‌پال برقرار است.',
            ];
        }
        
        return [
            'ok'      => false,
            'message' => 'پاسخ نامعتبر از زرین‌پال دریافت شد.',
        ];
    }
    
    /**
     * Get request URL based on sandbox mode
     */
    private function get_request_url(): string {
        return $this->is_sandbox ? self::SANDBOX_REQUEST_URL : self::PRODUCTION_REQUEST_URL;
    }
    
    /**
     * Get verify URL based on sandbox mode
     */
    private function get_verify_url(): string {
        return $this->is_sandbox ? self::SANDBOX_VERIFY_URL : self::PRODUCTION_VERIFY_URL;
    }
    
    /**
     * Send HTTP request to Zarinpal
     * 
     * @param string $url Request URL
     * @param array $data Request data
     * @return array|\WP_Error
     */
    private function send_request(string $url, array $data): array|\WP_Error {
        $response = wp_remote_post($url, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body'    => wp_json_encode($data),
            'sslverify' => true,
        ]);
        
        if (is_wp_error($response)) {
            Logger::error('Zarinpal request failed', [
                'url'   => $url,
                'error' => $response->get_error_message(),
            ]);
            
            return new \WP_Error(
                'connection_error',
                'خطا در ارتباط با درگاه زرین‌پال. لطفاً دوباره تلاش کنید.',
                ['status' => 503]
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body        = wp_remote_retrieve_body($response);
        
        $decoded = json_decode($body, true);
        
        if ($decoded === null) {
            return new \WP_Error(
                'invalid_response',
                'پاسخ نامعتبر از درگاه دریافت شد.',
                ['status' => 502]
            );
        }
        
        return $decoded;
    }
    
    /**
     * Get gateway settings for admin
     */
    public static function get_settings(): array {
        return [
            'merchant_id' => get_option(self::OPTION_MERCHANT_ID, ''),
            'is_sandbox'  => (bool) get_option(self::OPTION_IS_SANDBOX, false),
        ];
    }
    
    /**
     * Update gateway settings
     */
    public static function update_settings(string $merchant_id, bool $is_sandbox = false): void {
        update_option(self::OPTION_MERCHANT_ID, sanitize_text_field($merchant_id));
        update_option(self::OPTION_IS_SANDBOX, $is_sandbox);
        
        Logger::info('Zarinpal settings updated', [
            'is_sandbox' => $is_sandbox,
        ]);
    }
}