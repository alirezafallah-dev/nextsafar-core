<?php
/**
 * NextSafar Core - Booking Document Table
 * 
 * Stores uploaded documents for each passenger.
 * Uses WordPress Media Library (wp_posts) as storage,
 * but tracks the relationship to passengers here.
 * 
 * @package NextSafar\Booking
 * @since   2.7.0
 */

namespace NextSafar\Booking;

use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class BookingDocumentTable {
    
    const TABLE_VERSION = '1.0.0';
    const VERSION_OPTION = 'ns_booking_document_table_version';
    
    /**
     * Get table name
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'ns_booking_documents';
    }
    
    /**
     * Create or upgrade table
     */
    public static function create_table(): void {
        global $wpdb;
        
        $table = self::get_table_name();
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id BIGINT UNSIGNED NOT NULL,
            passenger_id BIGINT UNSIGNED NOT NULL,
            document_type VARCHAR(100) NOT NULL,
            document_label VARCHAR(255) NOT NULL,
            attachment_id BIGINT UNSIGNED NOT NULL,
            file_url TEXT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            mime_type VARCHAR(100) NOT NULL,
            notes TEXT NULL,
            verified TINYINT(1) NOT NULL DEFAULT 0,
            verified_at DATETIME NULL,
            verified_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            
            PRIMARY KEY (id),
            KEY idx_booking_id (booking_id),
            KEY idx_passenger_id (passenger_id),
            KEY idx_document_type (document_type),
            KEY idx_attachment_id (attachment_id),
            KEY idx_verified (verified),
            CONSTRAINT fk_doc_booking
                FOREIGN KEY (booking_id) 
                REFERENCES {$wpdb->prefix}ns_bookings(id) 
                ON DELETE CASCADE,
            CONSTRAINT fk_doc_passenger
                FOREIGN KEY (passenger_id) 
                REFERENCES {$wpdb->prefix}ns_booking_passengers(id) 
                ON DELETE CASCADE
        ) {$charset};";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        update_option(self::VERSION_OPTION, self::TABLE_VERSION);
        
        Logger::info('Booking document table created/upgraded', [
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
     * Insert a document record
     */
    public static function insert(array $data) {
        global $wpdb;
        
        $result = $wpdb->insert(self::get_table_name(), $data);
        
        if ($result === false) {
            Logger::error('Failed to insert document', [
                'error' => $wpdb->last_error,
            ]);
            return false;
        }
        
        return (int) $wpdb->insert_id;
    }
    
    /**
     * Get documents for a passenger
     * 
     * @param int $passenger_id
     * @return array
     */
    public static function get_by_passenger(int $passenger_id): array {
        global $wpdb;
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::get_table_name() . " 
                 WHERE passenger_id = %d 
                 ORDER BY created_at ASC",
                $passenger_id
            ),
            ARRAY_A
        );
        
        return $results ?: [];
    }
    
    /**
     * Get documents for a booking
     * 
     * @param int $booking_id
     * @return array
     */
    public static function get_by_booking(int $booking_id): array {
        global $wpdb;
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::get_table_name() . " 
                 WHERE booking_id = %d 
                 ORDER BY created_at ASC",
                $booking_id
            ),
            ARRAY_A
        );
        
        return $results ?: [];
    }
    
    /**
     * Get documents grouped by passenger
     * 
     * @param int $booking_id
     * @return array ['passenger_id' => [...documents]]
     */
    public static function get_grouped_by_passenger(int $booking_id): array {
        $documents = self::get_by_booking($booking_id);
        $grouped = [];
        
        foreach ($documents as $doc) {
            $pid = $doc['passenger_id'];
            if (!isset($grouped[$pid])) {
                $grouped[$pid] = [];
            }
            $grouped[$pid][] = $doc;
        }
        
        return $grouped;
    }
    
    /**
     * Mark a document as verified
     * 
     * @param int $document_id
     * @param int $verified_by User ID who verified
     * @return bool
     */
    public static function mark_verified(int $document_id, int $verified_by): bool {
        global $wpdb;
        
        return $wpdb->update(
            self::get_table_name(),
            [
                'verified'    => 1,
                'verified_at' => current_time('mysql'),
                'verified_by' => $verified_by,
            ],
            ['id' => $document_id]
        ) !== false;
    }
    
    /**
     * Delete a document (and its attachment)
     * 
     * @param int $document_id
     * @param bool $delete_attachment Whether to delete the file
     * @return bool
     */
    public static function delete(int $document_id, bool $delete_attachment = true): bool {
        global $wpdb;
        
        $doc = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT attachment_id FROM " . self::get_table_name() . " WHERE id = %d",
                $document_id
            )
        );
        
        if (!$doc) return false;
        
        // Delete the record
        $wpdb->delete(self::get_table_name(), ['id' => $document_id]);
        
        // Delete the actual file if requested
        if ($delete_attachment && $doc->attachment_id) {
            wp_delete_attachment($doc->attachment_id, true);
        }
        
        return true;
    }
    
    /**
     * Count documents by verification status
     * 
     * @param int $booking_id
     * @return array ['total' => N, 'verified' => N, 'pending' => N]
     */
    public static function count_by_verification(int $booking_id): array {
        global $wpdb;
        
        $stats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN verified = 1 THEN 1 ELSE 0 END) as verified
                 FROM " . self::get_table_name() . "
                 WHERE booking_id = %d",
                $booking_id
            ),
            ARRAY_A
        );
        
        $total = (int) ($stats['total'] ?? 0);
        $verified = (int) ($stats['verified'] ?? 0);
        
        return [
            'total'    => $total,
            'verified' => $verified,
            'pending'  => $total - $verified,
        ];
    }
}