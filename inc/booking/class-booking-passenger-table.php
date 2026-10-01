<?php
/**
 * NextSafar Core - Booking Passenger Table
 * 
 * Stores passenger information for each booking.
 * Supports all booking types (visa, hotel, flight, tour).
 * 
 * @package NextSafar\Booking
 * @since   2.7.0
 */

namespace NextSafar\Booking;

use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class BookingPassengerTable {
    
    const TABLE_VERSION = '1.0.0';
    const VERSION_OPTION = 'ns_booking_passenger_table_version';
    
    /**
     * Get table name
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'ns_booking_passengers';
    }
    
    /**
     * Create or upgrade the table
     */
    public static function create_table(): void {
        global $wpdb;
        
        $table = self::get_table_name();
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id BIGINT UNSIGNED NOT NULL,
            type ENUM('adult', 'child', 'infant') NOT NULL DEFAULT 'adult',
            index_in_booking INT NOT NULL DEFAULT 0,
            
            /* Basic info */
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            first_name_en VARCHAR(100) NULL,
            last_name_en VARCHAR(100) NULL,
            gender ENUM('male', 'female', 'other') NULL,
            
            /* Identity */
            national_id VARCHAR(20) NULL,
            passport_number VARCHAR(50) NULL,
            nationality VARCHAR(100) NULL,
            
            /* Contact */
            phone VARCHAR(20) NULL,
            email VARCHAR(255) NULL,
            
            /* Dates (stored as DATE type, YYYY-MM-DD) */
            birth_date DATE NULL,
            travel_date DATE NULL,
            passport_issue_date DATE NULL,
            passport_expiry_date DATE NULL,
            
            /* Extra info (JSON for flexible storage) */
            extra_data LONGTEXT NULL,
            
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            PRIMARY KEY (id),
            KEY idx_booking_id (booking_id),
            KEY idx_type (type),
            KEY idx_national_id (national_id),
            KEY idx_passport_number (passport_number),
            CONSTRAINT fk_passenger_booking
                FOREIGN KEY (booking_id) 
                REFERENCES {$wpdb->prefix}ns_bookings(id) 
                ON DELETE CASCADE
        ) {$charset};";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        update_option(self::VERSION_OPTION, self::TABLE_VERSION);
        
        Logger::info('Booking passenger table created/upgraded', [
            'table' => $table,
        ]);
    }
    
    /**
     * Maybe upgrade
     */
    public static function maybe_upgrade(): void {
        if (get_option(self::VERSION_OPTION, '') !== self::TABLE_VERSION) {
            self::create_table();
        }
    }
    
    /**
     * Insert a passenger
     * 
     * @param array $data Passenger data
     * @return int|false Passenger ID or false
     */
    public static function insert(array $data) {
        global $wpdb;
        
        if (isset($data['extra_data']) && is_array($data['extra_data'])) {
            $data['extra_data'] = wp_json_encode($data['extra_data']);
        }
        
        $result = $wpdb->insert(self::get_table_name(), $data);
        
        if ($result === false) {
            Logger::error('Failed to insert passenger', [
                'error' => $wpdb->last_error,
            ]);
            return false;
        }
        
        return (int) $wpdb->insert_id;
    }
    
    /**
     * Insert multiple passengers in batch
     * 
     * @param int $booking_id
     * @param array $passengers Array of passenger data
     * @return array Array of inserted passenger IDs
     */
    public static function insert_batch(int $booking_id, array $passengers): array {
        $ids = [];
        
        foreach ($passengers as $index => $passenger) {
            $passenger['booking_id'] = $booking_id;
            $passenger['index_in_booking'] = $index;
            
            $id = self::insert($passenger);
            if ($id) {
                $ids[] = $id;
            }
        }
        
        return $ids;
    }
    
    /**
     * Get passengers for a booking
     * 
     * @param int $booking_id
     * @param string $type Filter by type (optional)
     * @return array
     */
    public static function get_by_booking(int $booking_id, string $type = ''): array {
        global $wpdb;
        
        $where = 'booking_id = %d';
        $params = [$booking_id];
        
        if ($type !== '') {
            $where .= ' AND type = %s';
            $params[] = $type;
        }
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::get_table_name() . " 
                 WHERE {$where} 
                 ORDER BY index_in_booking ASC",
                ...$params
            ),
            ARRAY_A
        );
        
        // Decode JSON extra_data
        foreach ($results as &$row) {
            if (!empty($row['extra_data'])) {
                $row['extra_data'] = json_decode($row['extra_data'], true);
            }
        }
        
        return $results ?: [];
    }
    
    /**
     * Update a passenger
     * 
     * @param int $passenger_id
     * @param array $data
     * @return bool
     */
    public static function update(int $passenger_id, array $data): bool {
        global $wpdb;
        
        if (isset($data['extra_data']) && is_array($data['extra_data'])) {
            $data['extra_data'] = wp_json_encode($data['extra_data']);
        }
        
        return $wpdb->update(
            self::get_table_name(),
            $data,
            ['id' => $passenger_id]
        ) !== false;
    }
    
    /**
     * Delete all passengers for a booking
     * 
     * @param int $booking_id
     * @return int Number of deleted rows
     */
    public static function delete_by_booking(int $booking_id): int {
        global $wpdb;
        
        return (int) $wpdb->delete(
            self::get_table_name(),
            ['booking_id' => $booking_id]
        );
    }
    
    /**
     * Count passengers by booking
     * 
     * @param int $booking_id
     * @return array ['adults' => N, 'children' => N, 'infants' => N]
     */
    public static function count_by_booking(int $booking_id): array {
        global $wpdb;
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT type, COUNT(*) as count 
                 FROM " . self::get_table_name() . " 
                 WHERE booking_id = %d 
                 GROUP BY type",
                $booking_id
            ),
            ARRAY_A
        );
        
        $counts = ['adult' => 0, 'child' => 0, 'infant' => 0];
        foreach ($results as $row) {
            $counts[$row['type']] = (int) $row['count'];
        }
        
        return $counts;
    }
}