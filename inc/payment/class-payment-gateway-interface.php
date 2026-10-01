<?php
/**
 * NextSafar Core - Payment Gateway Interface
 * 
 * Common interface for all payment gateways.
 * Enables easy addition of new gateways without changing core logic.
 * 
 * @package NextSafar\Payment
 * @since   2.7.0
 */

namespace NextSafar\Payment;

if (!defined('ABSPATH')) exit;

interface PaymentGatewayInterface {
    
    /**
     * Get gateway unique identifier
     * 
     * @return string e.g., 'zarinpal', 'mellat', 'saman'
     */
    public function get_name(): string;
    
    /**
     * Get gateway display name
     * 
     * @return string e.g., 'زرین‌پال', 'ملت'
     */
    public function get_display_name(): string;
    
    /**
     * Check if gateway is configured and ready
     * 
     * @return bool
     */
    public function is_available(): bool;
    
    /**
     * Create a new payment transaction
     * 
     * @param array $args Transaction arguments:
     *   - amount: int - Amount in Rials
     *   - callback_url: string - Callback URL
     *   - description: string - Transaction description
     *   - mobile: string - Customer phone (optional)
     *   - email: string - Customer email (optional)
     *   - order_id: string - Internal order ID
     * @return array|\WP_Error Array with 'authority' and 'redirect_url' or WP_Error
     */
    public function create_transaction(array $args): array|\WP_Error;
    
    /**
     * Verify a transaction after callback
     * 
     * @param array $args Verification arguments:
     *   - authority: string - Gateway authority/token
     *   - amount: int - Expected amount in Rials
     * @return array|\WP_Error Array with 'verified' => bool, 'ref_id' => string
     */
    public function verify_transaction(array $args): array|\WP_Error;
    
    /**
     * Get the payment redirect URL
     * 
     * @param string $authority Gateway authority/token
     * @return string Full redirect URL
     */
    public function get_redirect_url(string $authority): string;
    
    /**
     * Test gateway connection
     * 
     * @return array ['ok' => bool, 'message' => string]
     */
    public function test_connection(): array;
}