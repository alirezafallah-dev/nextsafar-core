<?php
/**
 * NextSafar Core - Booking File Uploader
 * 
 * Handles document file uploads for bookings.
 * Uses WordPress Media Library for storage.
 * 
 * @package NextSafar\Booking
 * @since   2.7.0
 */

namespace NextSafar\Booking;

use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class BookingFileUploader {
    
    /**
     * Maximum file size (5MB)
     */
    const MAX_FILE_SIZE = 5 * 1024 * 1024;
    
    /**
     * Allowed MIME types
     */
    const ALLOWED_TYPES = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];
    
    /**
     * Upload directory (inside wp-content/uploads)
     */
    const UPLOAD_DIR = 'nextsafar/booking-documents';
    
    /**
     * Upload documents for a passenger
     * 
     * @param array $files Files from $_FILES
     * @param int $booking_id Booking ID
     * @param int $passenger_id Passenger ID
     * @return array ['success' => [...], 'errors' => [...]]
     */
    public static function upload_documents(
        array $files,
        int $booking_id,
        int $passenger_id
    ): array {
        $results = [
            'success' => [],
            'errors'  => [],
        ];
        
        if (empty($files)) {
            return $results;
        }
        
        // Ensure upload directory exists
        self::ensure_upload_dir();
        
        foreach ($files as $doc_slug => $file_data) {
            // Skip if no file
            if (empty($file_data['name']) || $file_data['name'][0] === '') {
                continue;
            }
            
            // Handle single file upload
            if (is_string($file_data['name'])) {
                $upload_result = self::upload_single_file(
                    $file_data,
                    $booking_id,
                    $passenger_id,
                    $doc_slug
                );
                
                if ($upload_result['success']) {
                    $results['success'][] = $upload_result;
                } else {
                    $results['errors'][] = $upload_result;
                }
                
                continue;
            }
            
            // Handle multiple files (array format from $_FILES)
            $file_count = count($file_data['name']);
            
            for ($i = 0; $i < $file_count; $i++) {
                // Skip empty entries
                if (empty($file_data['name'][$i])) {
                    continue;
                }
                
                // Reconstruct single file array
                $single_file = [
                    'name'     => $file_data['name'][$i],
                    'type'     => $file_data['type'][$i],
                    'tmp_name' => $file_data['tmp_name'][$i],
                    'error'    => $file_data['error'][$i],
                    'size'     => $file_data['size'][$i],
                ];
                
                $upload_result = self::upload_single_file(
                    $single_file,
                    $booking_id,
                    $passenger_id,
                    $doc_slug
                );
                
                if ($upload_result['success']) {
                    $results['success'][] = $upload_result;
                } else {
                    $results['errors'][] = $upload_result;
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Upload a single file
     * 
     * @param array $file File data from $_FILES
     * @param int $booking_id
     * @param int $passenger_id
     * @param string $doc_slug Document type slug
     * @return array Upload result
     */
    private static function upload_single_file(
        array $file,
        int $booking_id,
        int $passenger_id,
        string $doc_slug
    ): array {
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [
                'success'  => false,
                'doc_slug' => $doc_slug,
                'error'    => self::get_upload_error_message($file['error']),
            ];
        }
        
        // Validate file size
        if ($file['size'] > self::MAX_FILE_SIZE) {
            return [
                'success'  => false,
                'doc_slug' => $doc_slug,
                'error'    => sprintf(
                    'حجم فایل "%s" بیشتر از ۵ مگابایت است.',
                    $file['name']
                ),
            ];
        }
        
        // Validate file type
        $file_type = wp_check_filetype($file['name']);
        
        if (!in_array($file_type['type'], array_keys(self::ALLOWED_TYPES))) {
            return [
                'success'  => false,
                'doc_slug' => $doc_slug,
                'error'    => sprintf(
                    'فرمت فایل "%s" مجاز نیست. فقط فایل‌های PDF و تصاویر (JPG, PNG) مجاز هستند.',
                    $file['name']
                ),
            ];
        }
        
        // Upload to WordPress Media Library
        $attachment_id = self::upload_to_media_library($file, $booking_id, $passenger_id, $doc_slug);
        
        if (!$attachment_id) {
            return [
                'success'  => false,
                'doc_slug' => $doc_slug,
                'error'    => 'خطا در بارگذاری فایل. لطفاً دوباره تلاش کنید.',
            ];
        }
        
        // Get file info
        $file_url = wp_get_attachment_url($attachment_id);
        
        // Record in database
        $document_id = BookingDocumentTable::insert([
            'booking_id'     => $booking_id,
            'passenger_id'   => $passenger_id,
            'document_type'  => $doc_slug,
            'document_label' => self::get_doc_label($doc_slug),
            'attachment_id'  => $attachment_id,
            'file_url'       => $file_url,
            'file_name'      => $file['name'],
            'file_size'      => $file['size'],
            'mime_type'      => $file_type['type'],
        ]);
        
        if (!$document_id) {
            // Rollback: delete attachment
            wp_delete_attachment($attachment_id, true);
            
            return [
                'success'  => false,
                'doc_slug' => $doc_slug,
                'error'    => 'خطا در ذخیره اطلاعات فایل.',
            ];
        }
        
        Logger::info('Document uploaded', [
            'booking_id'   => $booking_id,
            'passenger_id' => $passenger_id,
            'doc_slug'     => $doc_slug,
            'file_name'    => $file['name'],
        ]);
        
        return [
            'success'       => true,
            'doc_slug'      => $doc_slug,
            'document_id'   => $document_id,
            'attachment_id' => $attachment_id,
            'file_url'      => $file_url,
            'file_name'     => $file['name'],
        ];
    }
    
    /**
     * Upload file to WordPress Media Library
     * 
     * @param array $file
     * @param int $booking_id
     * @param int $passenger_id
     * @param string $doc_slug
     * @return int|false Attachment ID or false
     */
    private static function upload_to_media_library(
        array $file,
        int $booking_id,
        int $passenger_id,
        string $doc_slug
    ) {
        // Include required files
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        
        // Create unique filename
        $filename = sprintf(
            'ns-booking-%d-p%d-%s-%d.%s',
            $booking_id,
            $passenger_id,
            $doc_slug,
            time(),
            pathinfo($file['name'], PATHINFO_EXTENSION)
        );
        
        // Move file to temp location
        $temp_file = trailingslashit(get_temp_dir()) . wp_unique_filename(get_temp_dir(), $filename);
        
        if (!move_uploaded_file($file['tmp_name'], $temp_file)) {
            Logger::error('Failed to move uploaded file', [
                'tmp_name' => $file['tmp_name'],
                'temp_file' => $temp_file,
            ]);
            return false;
        }
        
        // Prepare file array for media_handle_sideload
        $file_array = [
            'name'     => $filename,
            'tmp_name' => $temp_file,
        ];
        
        // Upload to media library
        $attachment_id = media_handle_sideload($file_array, 0, 'Booking Document');
        
        // Clean up temp file if it still exists
        if (file_exists($temp_file)) {
            @unlink($temp_file);
        }
        
        if (is_wp_error($attachment_id)) {
            Logger::error('Media sideload failed', [
                'error' => $attachment_id->get_error_message(),
            ]);
            return false;
        }
        
        // Add post meta for tracking
        update_post_meta($attachment_id, '_ns_booking_id', $booking_id);
        update_post_meta($attachment_id, '_ns_passenger_id', $passenger_id);
        update_post_meta($attachment_id, '_ns_doc_type', $doc_slug);
        
        // Protect the file (not publicly accessible without auth)
        // Note: For production, consider using a protected upload directory
        
        return (int) $attachment_id;
    }
    
    /**
     * Delete all documents for a booking
     * 
     * @param int $booking_id
     * @param bool $delete_files Whether to delete actual files
     * @return int Number of deleted documents
     */
    public static function delete_booking_documents(int $booking_id, bool $delete_files = true): int {
        global $wpdb;
        
        $table = BookingDocumentTable::get_table_name();
        
        // Get all documents for this booking
        $documents = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, attachment_id FROM {$table} WHERE booking_id = %d",
                $booking_id
            )
        );
        
        $count = 0;
        
        foreach ($documents as $doc) {
            // Delete attachment
            if ($delete_files && $doc->attachment_id) {
                wp_delete_attachment($doc->attachment_id, true);
            }
            
            // Delete record
            BookingDocumentTable::delete($doc->id, false);
            $count++;
        }
        
        Logger::info('Booking documents deleted', [
            'booking_id' => $booking_id,
            'count'      => $count,
        ]);
        
        return $count;
    }
    
    /**
     * Get document label by slug
     * 
     * @param string $slug
     * @return string
     */
    public static function get_doc_label(string $slug): string {
        $labels = [
            'پاسپورت'          => 'passport',
            'عکس پرسنلی'       => 'personal_photo',
            'شناسنامه'         => 'birth_certificate',
            'کارت ملی'         => 'national_card',
            'بلیط پرواز'       => 'flight_ticket',
            'ووچر هتل'         => 'hotel_voucher',
            'بیمه مسافرتی'     => 'travel_insurance',
            'تمکن مالی'        => 'financial_proof',
            'دعوت نامه'        => 'invitation_letter',
            'فرم اطلاعات'      => 'info_form',
            'برنامه سفر'       => 'travel_plan',
            'نامه اشتغال به کار' => 'employment_letter',
            'گواهی اشتغال به تحصیل' => 'student_certificate',
            'کارت پایان خدمت'  => 'military_service_card',
            'رضایت‌نامه محضری'  => 'consent_letter',
        ];
        
        // Reverse lookup
        foreach ($labels as $persian => $english) {
            if ($english === $slug || $persian === $slug) {
                return $persian;
            }
        }
        
        return $slug;
    }
    
    /**
     * Get upload error message
     * 
     * @param int $error_code
     * @return string
     */
    private static function get_upload_error_message(int $error_code): string {
        $messages = [
            UPLOAD_ERR_INI_SIZE   => 'حجم فایل بیشتر از حد مجاز سرور است.',
            UPLOAD_ERR_FORM_SIZE  => 'حجم فایل بیشتر از حد مجاز فرم است.',
            UPLOAD_ERR_PARTIAL    => 'فایل به صورت ناقص بارگذاری شده است.',
            UPLOAD_ERR_NO_FILE    => 'هیچ فایلی بارگذاری نشده است.',
            UPLOAD_ERR_NO_TMP_DIR => 'پوشه موقت سرور موجود نیست.',
            UPLOAD_ERR_CANT_WRITE => 'امکان نوشتن فایل روی دیسک وجود ندارد.',
            UPLOAD_ERR_EXTENSION  => 'بارگذاری فایل توسط یک افزونه متوقف شده است.',
        ];
        
        return $messages[$error_code] ?? 'خطای نامشخص در بارگذاری فایل.';
    }
    
    /**
     * Ensure upload directory exists
     * 
     * @return void
     */
    private static function ensure_upload_dir(): void {
        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/' . self::UPLOAD_DIR;
        
        if (!file_exists($target_dir)) {
            wp_mkdir_p($target_dir);
            
            // Add .htaccess to protect files
            $htaccess = $target_dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Options -Indexes\n");
            }
            
            // Add index.php to prevent directory listing
            $index = $target_dir . '/index.php';
            if (!file_exists($index)) {
                file_put_contents($index, '<?php // Silence is golden');
            }
        }
    }
    
    /**
     * Get formatted file size
     * 
     * @param int $bytes
     * @return string
     */
    public static function format_file_size(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}