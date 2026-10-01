<?php
/**
 * NextSafar Core - Payment Table Manager
 * 
 * Creates and manages the payments database table.
 * 
 * @package NextSafar\Payment
 * @since   2.7.0
 */

namespace NextSafar\Payment;

use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class PaymentTable {
    
    /**
     * Table version
     */
    const TABLE_VERSION = '1.0.0';
    
    /**
     * Option key for storing table version
     */
    const VERSION_OPTION = 'ns_payment_table_version';
    
    /**
     * Get payments table name
     * 
     * @return string
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'ns_payments';
    }
    
    /**
     * Create or upgrade the payments table
     * 
     * @return void
     */
    public static function create_table(): void {
        global $wpdb;
        
        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            gateway VARCHAR(30) NOT NULL DEFAULT 'zarinpal',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(3) NOT NULL DEFAULT 'IRR',
            status ENUM(
                'pending',
                'redirecting',
                'success',
                'failed',
                'cancelled',
                'refunded'
            ) NOT NULL DEFAULT 'pending',
            transaction_id VARCHAR(100) NULL,
            gateway_ref_id VARCHAR(100) NULL,
            gateway_response LONGTEXT NULL,
            callback_data LONGTEXT NULL,
            description TEXT NULL,
            paid_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_transaction_id (transaction_id),
            KEY idx_booking_id (booking_id),
            KEY idx_user_id (user_id),
            KEY idx_status (status),
            KEY idx_gateway (gateway),
            KEY idx_created_at (created_at)
        ) {$charset_collate};";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        update_option(self::VERSION_OPTION, self::TABLE_VERSION);
        
        Logger::info('Payment table created/upgraded', [
            'table'   => $table_name,
            'version' => self::TABLE_VERSION,
        ]);
    }
    
    /**
     * Maybe upgrade table if version changed
     * 
     * @return void
     */
    public static function maybe_upgrade(): void {
        $current_version = get_option(self::VERSION_OPTION, '');
        
        if ($current_version !== self::TABLE_VERSION) {
            self::create_table();
        }
    }
    
    /**
     * Generate unique transaction ID
     * 
     * @return string
     */
    public static function generate_transaction_id(): string {
        return 'NS-PAY-' . date('ymdHis') . '-' . wp_rand(10000, 99999);
    }
    
    /**
     * Find payment by ID
     * 
     * @param int $payment_id
     * @return array|null
     */
    public static function find(int $payment_id): ?array {
        global $wpdb;
        
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::get_table_name() . " WHERE id = %d",
                $payment_id
            ),
            ARRAY_A
        );
        
        return $row ?: null;
    }
    
    /**
     * Find payment by transaction ID
     * 
     * @param string $transaction_id
     * @return array|null
     */
    public static function find_by_transaction(string $transaction_id): ?array {
        global $wpdb;
        
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::get_table_name() . " WHERE transaction_id = %s",
                $transaction_id
            ),
            ARRAY_A
        );
        
        return $row ?: null;
    }
    
    /**
     * Find payments by booking ID
     * 
     * @param int $booking_id
     * @return array
     */
    public static function find_by_booking(int $booking_id): array {
        global $wpdb;
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::get_table_name() . " 
                 WHERE booking_id = %d 
                 ORDER BY created_at DESC",
                $booking_id
            ),
            ARRAY_A
        );
        
        return $results ?: [];
    }
    
    /**
     * Insert a new payment
     * 
     * @param array $data
     * @return int|false Payment ID or false on failure
     */
    public static function insert(array $data) {
        global $wpdb;
        
        $defaults = [
            'booking_id'       => 0,
            'user_id'          => 0,
            'gateway'          => 'zarinpal',
            'amount'           => 0,
            'currency'         => 'IRR',
            'status'           => 'pending',
            'transaction_id'   => '',
            'gateway_ref_id'   => null,
            'gateway_response' => null,
            'callback_data'    => null,
            'description'      => '',
            'paid_at'          => null,
        ];
        
        $data = wp_parse_args($data, $defaults);
        
        // Generate transaction ID if not provided
        if ($data['transaction_id'] === '') {
            $data['transaction_id'] = self::generate_transaction_id();
        }
        
        // Encode JSON fields
        if (is_array($data['gateway_response'])) {
            $data['gateway_response'] = wp_json_encode($data['gateway_response']);
        }
        if (is_array($data['callback_data'])) {
            $data['callback_data'] = wp_json_encode($data['callback_data']);
        }
        
        $result = $wpdb->insert(self::get_table_name(), $data);
        
        if ($result === false) {
            Logger::error('Failed to insert payment', [
                'error' => $wpdb->last_error,
            ]);
            return false;
        }
        
        return (int) $wpdb->insert_id;
    }
    
    /**
     * Update a payment
     * 
     * @param int $payment_id
     * @param array $data
     * @return bool
     */
    public static function update(int $payment_id, array $data): bool {
        global $wpdb;
        
        // Encode JSON fields
        if (isset($data['gateway_response']) && is_array($data['gateway_response'])) {
            $data['gateway_response'] = wp_json_encode($data['gateway_response']);
        }
        if (isset($data['callback_data']) && is_array($data['callback_data'])) {
            $data['callback_data'] = wp_json_encode($data['callback_data']);
        }
        
        $result = $wpdb->update(
            self::get_table_name(),
            $data,
            ['id' => $payment_id]
        );
        
        return $result !== false;
    }
    
    /**
     * Update payment status
     * 
     * @param int $payment_id
     * @param string $status
     * @param array $extra Additional data to update
     * @return bool
     */
    public static function update_status(int $payment_id, string $status, array $extra = []): bool {
        $valid_statuses = ['pending', 'redirecting', 'success', 'failed', 'cancelled', 'refunded'];
        
        if (!in_array($status, $valid_statuses)) {
            return false;
        }
        
        $data = array_merge(['status' => $status], $extra);
        
        // Set paid_at if payment succeeded
        if ($status === 'success' && empty($extra['paid_at'])) {
            $data['paid_at'] = current_time('mysql');
        }
        
        return self::update($payment_id, $data);
    }
    
    /**
     * Get user's payments
     * 
     * @param int $user_id
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function get_user_payments(int $user_id, int $limit = 20, int $offset = 0): array {
        global $wpdb;
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::get_table_name() . " 
                 WHERE user_id = %d 
                 ORDER BY created_at DESC 
                 LIMIT %d OFFSET %d",
                $user_id,
                $limit,
                $offset
            ),
            ARRAY_A
        );
        
        return $results ?: [];
    }
    
    /**
     * Get payment statistics
     * 
     * @param int $days
     * @return array
     */
    public static function get_stats(int $days = 30): array {
        global $wpdb;
        
        $start_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $stats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT 
                    COUNT(*) as total_payments,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END) as total_revenue,
                    AVG(CASE WHEN status = 'success' THEN amount ELSE NULL END) as avg_payment
                 FROM " . self::get_table_name() . "
                 WHERE created_at >= %s",
                $start_date
            ),
            ARRAY_A
        );
        
        return $stats ?: [
            'total_payments' => 0,
            'successful'     => 0,
            'failed'         => 0,
            'total_revenue'  => 0,
            'avg_payment'    => 0,
        ];
    }
    
    /**
     * Get gateway statistics
     * 
     * @param int $days
     * @return array
     */
    public static function get_gateway_stats(int $days = 30): array {
        global $wpdb;
        
        $start_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    gateway,
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
                    SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END) as revenue
                 FROM " . self::get_table_name() . "
                 WHERE created_at >= %s
                 GROUP BY gateway",
                $start_date
            ),
            ARRAY_A
        );
        
        return $results ?: [];
    }
}