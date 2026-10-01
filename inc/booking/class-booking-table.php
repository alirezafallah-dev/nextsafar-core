<?php
/**
 * NextSafar Core - Booking Table Manager
 * 
 * Creates and manages the bookings database table.
 * Supports all booking types: visa, hotel, flight, tour.
 * 
 * @package NextSafar\Booking
 * @since   2.7.0
 */

namespace NextSafar\Booking;

use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class BookingTable {
    
    /**
     * Table version (for migrations)
     */
    const TABLE_VERSION = '1.0.0';
    
    /**
     * Option key for storing table version
     */
    const VERSION_OPTION = 'ns_booking_table_version';
    
    /**
     * Get bookings table name
     * 
     * @return string
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'ns_bookings';
    }
    
    /**
     * Create or upgrade the bookings table
     * 
     * @return void
     */
    public static function create_table(): void {
        global $wpdb;
        
        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_code VARCHAR(20) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            booking_type ENUM('visa', 'hotel', 'flight', 'tour') NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM(
                'pending',
                'awaiting_payment',
                'paid',
                'processing',
                'completed',
                'cancelled',
                'refunded',
                'failed'
            ) NOT NULL DEFAULT 'pending',
            total_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            currency VARCHAR(3) NOT NULL DEFAULT 'IRR',
            passenger_count INT NOT NULL DEFAULT 1,
            passenger_info LONGTEXT NULL,
            booking_data LONGTEXT NULL,
            payment_id BIGINT UNSIGNED NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_booking_code (booking_code),
            KEY idx_user_id (user_id),
            KEY idx_booking_type (booking_type),
            KEY idx_status (status),
            KEY idx_item_id (item_id),
            KEY idx_created_at (created_at)
        ) {$charset_collate};";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        update_option(self::VERSION_OPTION, self::TABLE_VERSION);
        
        Logger::info('Booking table created/upgraded', [
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
     * Generate unique booking code
     * 
     * Format: NS-{TYPE}-{TIMESTAMP}{RANDOM}
     * Example: NS-V-2609271234, NS-H-2609275678
     * 
     * @param string $type Booking type (visa, hotel, flight, tour)
     * @return string
     */
    public static function generate_booking_code(string $type): string {
        $type_map = [
            'visa'   => 'V',
            'hotel'  => 'H',
            'flight' => 'F',
            'tour'   => 'T',
        ];
        
        $type_letter = $type_map[$type] ?? 'X';
        $timestamp = date('ymd');
        $random = str_pad((string) wp_rand(0, 9999), 4, '0', STR_PAD_LEFT);
        
        return "NS-{$type_letter}-{$timestamp}{$random}";
    }
    
    /**
     * Find booking by ID
     * 
     * @param int $booking_id
     * @return array|null
     */
    public static function find(int $booking_id): ?array {
        global $wpdb;
        
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::get_table_name() . " WHERE id = %d",
                $booking_id
            ),
            ARRAY_A
        );
        
        return $row ?: null;
    }
    
    /**
     * Find booking by booking code
     * 
     * @param string $booking_code
     * @return array|null
     */
    public static function find_by_code(string $booking_code): ?array {
        global $wpdb;
        
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::get_table_name() . " WHERE booking_code = %s",
                $booking_code
            ),
            ARRAY_A
        );
        
        return $row ?: null;
    }
    
    /**
     * Get user's bookings
     * 
     * @param int $user_id
     * @param int $limit
     * @param int $offset
     * @param string $status Filter by status (optional)
     * @return array
     */
    public static function get_user_bookings(
        int $user_id, 
        int $limit = 20, 
        int $offset = 0,
        string $status = ''
    ): array {
        global $wpdb;
        
        $where = "user_id = %d";
        $params = [$user_id];
        
        if ($status !== '') {
            $where .= " AND status = %s";
            $params[] = $status;
        }
        
        $params[] = $limit;
        $params[] = $offset;
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::get_table_name() . " 
                 WHERE {$where} 
                 ORDER BY created_at DESC 
                 LIMIT %d OFFSET %d",
                ...$params
            ),
            ARRAY_A
        );
        
        return $results ?: [];
    }
    
    /**
     * Count user's bookings
     * 
     * @param int $user_id
     * @param string $status
     * @return int
     */
    public static function count_user_bookings(int $user_id, string $status = ''): int {
        global $wpdb;
        
        $where = "user_id = %d";
        $params = [$user_id];
        
        if ($status !== '') {
            $where .= " AND status = %s";
            $params[] = $status;
        }
        
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::get_table_name() . " WHERE {$where}",
                ...$params
            )
        );
    }
    
    /**
     * Insert a new booking
     * 
     * @param array $data Booking data
     * @return int|false Booking ID or false on failure
     */
    public static function insert(array $data) {
        global $wpdb;
        
        $defaults = [
            'booking_code'     => '',
            'user_id'          => 0,
            'booking_type'     => 'visa',
            'item_id'          => 0,
            'status'           => 'pending',
            'total_price'      => 0,
            'currency'         => 'IRR',
            'passenger_count'  => 1,
            'passenger_info'   => '',
            'booking_data'     => '',
            'payment_id'       => null,
            'notes'            => '',
        ];
        
        $data = wp_parse_args($data, $defaults);
        
        // Generate booking code if not provided
        if ($data['booking_code'] === '') {
            $data['booking_code'] = self::generate_booking_code($data['booking_type']);
        }
        
        // Encode JSON fields
        if (is_array($data['passenger_info'])) {
            $data['passenger_info'] = wp_json_encode($data['passenger_info']);
        }
        if (is_array($data['booking_data'])) {
            $data['booking_data'] = wp_json_encode($data['booking_data']);
        }
        
        $result = $wpdb->insert(self::get_table_name(), $data);
        
        if ($result === false) {
            Logger::error('Failed to insert booking', [
                'error' => $wpdb->last_error,
                'data'  => $data,
            ]);
            return false;
        }
        
        return (int) $wpdb->insert_id;
    }
    
    /**
     * Update a booking
     * 
     * @param int $booking_id
     * @param array $data
     * @return bool
     */
    public static function update(int $booking_id, array $data): bool {
        global $wpdb;
        
        // Encode JSON fields
        if (isset($data['passenger_info']) && is_array($data['passenger_info'])) {
            $data['passenger_info'] = wp_json_encode($data['passenger_info']);
        }
        if (isset($data['booking_data']) && is_array($data['booking_data'])) {
            $data['booking_data'] = wp_json_encode($data['booking_data']);
        }
        
        $result = $wpdb->update(
            self::get_table_name(),
            $data,
            ['id' => $booking_id]
        );
        
        return $result !== false;
    }
    
    /**
     * Update booking status
     * 
     * @param int $booking_id
     * @param string $status
     * @return bool
     */
    public static function update_status(int $booking_id, string $status): bool {
        $valid_statuses = [
            'pending', 'awaiting_payment', 'paid', 'processing',
            'completed', 'cancelled', 'refunded', 'failed'
        ];
        
        if (!in_array($status, $valid_statuses)) {
            return false;
        }
        
        return self::update($booking_id, ['status' => $status]);
    }
    
    /**
     * Delete a booking (admin only)
     * 
     * @param int $booking_id
     * @return bool
     */
    public static function delete(int $booking_id): bool {
        global $wpdb;
        
        $result = $wpdb->delete(
            self::get_table_name(),
            ['id' => $booking_id]
        );
        
        return $result !== false;
    }
    
    /**
     * Get booking statistics
     * 
     * @param int $days Number of days to analyze
     * @return array
     */
    public static function get_stats(int $days = 30): array {
        global $wpdb;
        
        $start_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $stats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT 
                    COUNT(*) as total_bookings,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                    SUM(CASE WHEN status = 'paid' THEN total_price ELSE 0 END) as revenue
                 FROM " . self::get_table_name() . "
                 WHERE created_at >= %s",
                $start_date
            ),
            ARRAY_A
        );
        
        return $stats ?: [
            'total_bookings' => 0,
            'completed'      => 0,
            'cancelled'      => 0,
            'revenue'        => 0,
        ];
    }
}