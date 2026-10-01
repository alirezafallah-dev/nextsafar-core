<?php
/**
 * NextSafar Core - Booking REST API Endpoints
 * 
 * Registers all REST API endpoints for the booking system.
 * Supports visa bookings (with extensibility for hotel, flight, tour).
 * 
 * Architecture: Two-step booking process
 *   Step 1: Create booking with passenger info (JSON)
 *   Step 2: Upload documents (multipart/form-data)
 *   Step 3: Initialize payment (Phase 4)
 * 
 * @package NextSafar\Booking
 * @since   2.7.0
 */

namespace NextSafar\Booking;

use NextSafar\Booking\Adapters\VisaBookingAdapter;
use NextSafar\Core\RateLimiter;
use NextSafar\Core\ErrorHandler;
use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class BookingEndpoints {
    
    /**
     * REST API namespace
     */
    const NAMESPACE = 'nextsafar/v1';
    
    /**
     * Initialize endpoints
     */
    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        
        // Allow CORS for Next.js frontend
        add_filter('rest_pre_serve_request', [__CLASS__, 'add_cors_headers'], 10, 4);
    }
    
    /**
     * Add CORS headers for cross-origin requests
     */
    public static function add_cors_headers($served, $result, $request, $server) {
        // Only for our namespace
        if (strpos($request->get_route(), '/nextsafar/') !== 0) {
            return $served;
        }
        
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        
        return $served;
    }
    
    /**
     * Register all booking routes
     */
    public static function register_routes(): void {
        
        // ─────────────────────────────────────────────────────────
        // 1. PRICE CALCULATION ENDPOINT
        // GET /nextsafar/v1/booking/visa/price
        // ─────────────────────────────────────────────────────────
        register_rest_route(self::NAMESPACE, '/booking/visa/price', [
            'methods'             => ['GET', 'POST'],
            'permission_callback' => RateLimiter::middleware('general'),
            'callback'            => [__CLASS__, 'handle_price_calculation'],
        ]);
        
        // ─────────────────────────────────────────────────────────
        // 2. CREATE BOOKING ENDPOINT
        // POST /nextsafar/v1/booking/visa/create
        // ─────────────────────────────────────────────────────────
        register_rest_route(self::NAMESPACE, '/booking/visa/create', [
            'methods'             => 'POST',
            'permission_callback' => RateLimiter::middleware('booking_create'),
            'callback'            => [__CLASS__, 'handle_create_booking'],
        ]);
        
        // ─────────────────────────────────────────────────────────
        // 3. UPLOAD DOCUMENTS ENDPOINT
        // POST /nextsafar/v1/booking/visa/{code}/upload
        // ─────────────────────────────────────────────────────────
        register_rest_route(self::NAMESPACE, '/booking/visa/(?P<code>[A-Z0-9\-]+)/upload', [
            'methods'             => 'POST',
            'permission_callback' => RateLimiter::middleware('booking_upload'),
            'callback'            => [__CLASS__, 'handle_upload_documents'],
            'args'                => [
                'code' => [
                    'validate_callback' => function ($param) {
                        return preg_match('/^NS-V-[0-9]+$/', $param) === 1;
                    },
                ],
            ],
        ]);
        
        // ─────────────────────────────────────────────────────────
        // 4. GET BOOKING DETAILS ENDPOINT
        // GET /nextsafar/v1/booking/visa/{code}
        // ─────────────────────────────────────────────────────────
        register_rest_route(self::NAMESPACE, '/booking/visa/(?P<code>[A-Z0-9\-]+)', [
            'methods'             => 'GET',
            'permission_callback' => RateLimiter::middleware('general'),
            'callback'            => [__CLASS__, 'handle_get_booking'],
        ]);
        
        // ─────────────────────────────────────────────────────────
        // 5. LIST USER BOOKINGS ENDPOINT
        // GET /nextsafar/v1/booking/visa/list
        // ─────────────────────────────────────────────────────────
        register_rest_route(self::NAMESPACE, '/booking/visa/list', [
            'methods'             => 'GET',
            'permission_callback' => function () {
                return is_user_logged_in();
            },
            'callback'            => [__CLASS__, 'handle_list_bookings'],
        ]);
        
        // ─────────────────────────────────────────────────────────
        // 6. CANCEL BOOKING ENDPOINT
        // POST /nextsafar/v1/booking/visa/{code}/cancel
        // ─────────────────────────────────────────────────────────
        register_rest_route(self::NAMESPACE, '/booking/visa/(?P<code>[A-Z0-9\-]+)/cancel', [
            'methods'             => 'POST',
            'permission_callback' => RateLimiter::middleware('general'),
            'callback'            => [__CLASS__, 'handle_cancel_booking'],
        ]);
        
        // ─────────────────────────────────────────────────────────
        // 7. PAYMENT INIT ENDPOINT (Prepared for Phase 4)
        // POST /nextsafar/v1/booking/visa/{code}/payment-init
        // ─────────────────────────────────────────────────────────
        register_rest_route(self::NAMESPACE, '/booking/visa/(?P<code>[A-Z0-9\-]+)/payment-init', [
            'methods'             => 'POST',
            'permission_callback' => RateLimiter::middleware('general'),
            'callback'            => [__CLASS__, 'handle_payment_init'],
        ]);
        
        // ─────────────────────────────────────────────────────────
        // 8. PAYMENT CALLBACK ENDPOINT (Prepared for Phase 4)
        // GET /nextsafar/v1/booking/visa/payment-callback
        // ─────────────────────────────────────────────────────────
        register_rest_route(self::NAMESPACE, '/booking/visa/payment-callback', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [__CLASS__, 'handle_payment_callback'],
        ]);
        
        // ─────────────────────────────────────────────────────────
        // 9. VERIFY PAYMENT ENDPOINT (Prepared for Phase 4)
        // GET /nextsafar/v1/booking/visa/verify
        // ─────────────────────────────────────────────────────────
        register_rest_route(self::NAMESPACE, '/booking/visa/verify', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [__CLASS__, 'handle_verify_payment'],
        ]);
        
        // ─────────────────────────────────────────────────────────
        // 10. GET VISA INFO ENDPOINT
        // GET /nextsafar/v1/booking/visa/info/{post_id}
        // ─────────────────────────────────────────────────────────
        register_rest_route(self::NAMESPACE, '/booking/visa/info/(?P<post_id>\d+)', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [__CLASS__, 'handle_get_visa_info'],
        ]);
    }
    
    // ═══════════════════════════════════════════════════════════
    // HANDLER: Price Calculation
    // ═══════════════════════════════════════════════════════════
    
    public static function handle_price_calculation(\WP_REST_Request $request) {
        try {
            // Get parameters
            $visa_post_id = (int) ($request->get_param('post_id') ?? $request->get_param('visa_post_id') ?? 0);
            $price_index  = (int) ($request->get_param('visa') ?? $request->get_param('price_index') ?? 0);
            $adults       = (int) ($request->get_param('adults') ?? 0);
            $children     = (int) ($request->get_param('children') ?? 0);
            
            // Validate required parameters
            if ($visa_post_id <= 0) {
                return ErrorHandler::to_response(
                    ErrorHandler::missing_params(['post_id'])
                );
            }
            
            if ($adults < 1) {
                return ErrorHandler::to_response(
                    ErrorHandler::error('invalid_adults', 'حداقل یک بزرگسال الزامی است.', 400)
                );
            }
            
            // Calculate price
            $price_data = VisaPriceCalculator::calculate([
                'visa_post_id' => $visa_post_id,
                'price_index'  => $price_index,
                'adults'       => $adults,
                'children'     => $children,
            ]);
            
            if (is_wp_error($price_data)) {
                return ErrorHandler::to_response($price_data);
            }
            
            // Get visa info for response
            $visa_info = self::get_visa_summary($visa_post_id);
            
            return rest_ensure_response([
                'ok'       => true,
                'price'    => $price_data,
                'visa'     => $visa_info,
            ]);
            
        } catch (\Throwable $e) {
            return ErrorHandler::to_response(
                ErrorHandler::handle_exception($e, 'price-calculation')
            );
        }
    }
    
    // ═══════════════════════════════════════════════════════════
    // HANDLER: Create Booking
    // ═══════════════════════════════════════════════════════════
    
    public static function handle_create_booking(\WP_REST_Request $request) {
        try {
            // Get JSON body
            $body = $request->get_json_params();
            
            if (empty($body)) {
                // Try form data
                $body = $request->get_params();
            }
            
            if (empty($body)) {
                return ErrorHandler::to_response(
                    ErrorHandler::error('empty_body', 'اطلاعات رزرو ارسال نشده است.', 400)
                );
            }
            
            Logger::info('Booking creation request received', [
                'has_body' => !empty($body),
            ]);
            
            // Validate visa info
            $visa_post_id = (int) ($body['post_id'] ?? $body['visa_post_id'] ?? 0);
            
            if ($visa_post_id <= 0) {
                return ErrorHandler::to_response(
                    ErrorHandler::missing_params(['post_id'])
                );
            }
            
            // Step 1: Validate booking data
            $validation = VisaValidator::validate_booking($body);
            
            if (is_wp_error($validation)) {
                return ErrorHandler::to_response($validation);
            }
            
            // Step 2: Calculate price
            $price_data = VisaPriceCalculator::calculate([
                'visa_post_id' => $visa_post_id,
                'price_index'  => (int) ($body['visa_index'] ?? $body['visa'] ?? 0),
                'adults'       => (int) ($body['adults'] ?? 0),
                'children'     => (int) ($body['children'] ?? 0),
            ]);
            
            if (is_wp_error($price_data)) {
                return ErrorHandler::to_response($price_data);
            }
            
            // Step 3: Verify total price matches (security check)
            $client_total = (int) ($body['total_price_rial'] ?? 0);
            if ($client_total > 0 && $client_total !== $price_data['total_price_rial']) {
                Logger::warning('Price mismatch detected', [
                    'client_price' => $client_total,
                    'server_price' => $price_data['total_price_rial'],
                ]);
                
                return ErrorHandler::to_response(
                    ErrorHandler::error(
                        'price_mismatch',
                        'مبلغ پرداختی با قیمت محاسبه شده مطابقت ندارد. لطفاً صفحه را رفرش کنید.',
                        400
                    )
                );
            }
            
            // Step 4: Create booking using adapter
            $booking_data = [
                'visa_post_id' => $visa_post_id,
                'visa_index'   => (int) ($body['visa_index'] ?? $body['visa'] ?? 0),
                'adults'       => (int) ($body['adults'] ?? 0),
                'children'     => (int) ($body['children'] ?? 0),
                'main_phone'   => sanitize_text_field($body['main_phone'] ?? ''),
                'main_mail'    => sanitize_email($body['main_mail'] ?? ''),
                'adult'        => $body['adult'] ?? [],
                'child'        => $body['child'] ?? [],
            ];
            
            $result = self::create_booking_without_files($booking_data, $price_data);
            
            if (is_wp_error($result)) {
                return ErrorHandler::to_response($result);
            }
            
            // Step 5: Get required documents for response
            $required_docs = VisaValidator::get_required_documents($visa_post_id);
            
            return rest_ensure_response([
                'ok'              => true,
                'booking'         => $result,
                'required_docs'   => $required_docs,
                'next_step'       => 'upload_documents',
                'upload_endpoint' => rest_url(self::NAMESPACE . '/booking/visa/' . $result['booking_code'] . '/upload'),
            ]);
            
        } catch (\Throwable $e) {
            return ErrorHandler::to_response(
                ErrorHandler::handle_exception($e, 'create-booking')
            );
        }
    }
    
    /**
     * Create booking without file uploads
     * 
     * @param array $data Booking data
     * @param array $price_data Price calculation result
     * @return array|\WP_Error
     */
    private static function create_booking_without_files(array $data, array $price_data): array|\WP_Error {
        // Get or create user
        $user_id = VisaBookingAdapter::get_or_create_user($data);
        
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        
        // Generate booking code
        $booking_code = BookingTable::generate_booking_code('visa');
        
        // Insert booking record
        $booking_id = BookingTable::insert([
            'booking_code'     => $booking_code,
            'user_id'          => $user_id,
            'booking_type'     => 'visa',
            'item_id'          => $price_data['visa_post_id'],
            'status'           => 'awaiting_documents',
            'total_price'      => $price_data['total_price_rial'],
            'currency'         => 'IRR',
            'passenger_count'  => $price_data['total_passengers'],
            'passenger_info'   => [
                'main_phone' => $data['main_phone'] ?? '',
                'main_mail'  => $data['main_mail'] ?? '',
            ],
            'booking_data'     => [
                'visa_post_id'    => $price_data['visa_post_id'],
                'price_index'     => $price_data['price_index'],
                'visa_type'       => $price_data['type'],
                'visa_duration'   => $price_data['duration'],
                'adults'          => $price_data['adults'],
                'children'        => $price_data['children'],
                'price_breakdown' => $price_data,
            ],
        ]);
        
        if (!$booking_id) {
            return ErrorHandler::server_error('خطا در ایجاد رزرو. لطفاً دوباره تلاش کنید.');
        }
        
        // Insert passengers
        $passenger_ids = self::insert_passengers($booking_id, $data);
        
        if (is_wp_error($passenger_ids)) {
            // Rollback
            BookingTable::delete($booking_id);
            return $passenger_ids;
        }
        
        Logger::info('Visa booking created (no files)', [
            'booking_id'   => $booking_id,
            'booking_code' => $booking_code,
            'user_id'      => $user_id,
            'total_price'  => $price_data['total_price_rial'],
        ]);
        
        return [
            'booking_id'     => $booking_id,
            'booking_code'   => $booking_code,
            'status'         => 'awaiting_documents',
            'total_price'    => $price_data['total_price_rial'],
            'passengers'     => $passenger_ids,
        ];
    }
    
    /**
     * Insert passengers for a booking
     */
    private static function insert_passengers(int $booking_id, array $data): array|\WP_Error {
        $adults_data   = $data['adult'] ?? [];
        $children_data = $data['child'] ?? [];
        
        $passengers = [];
        
        // Add adults
        foreach ($adults_data as $index => $adult) {
            $passengers[] = [
                'type'                 => 'adult',
                'index_in_booking'     => $index,
                'first_name'           => sanitize_text_field($adult['first_name'] ?? ''),
                'last_name'            => sanitize_text_field($adult['last_name'] ?? ''),
                'national_id'          => sanitize_text_field($adult['national_id'] ?? ''),
                'passport_number'      => sanitize_text_field($adult['passport_number'] ?? ''),
                'phone'                => sanitize_text_field($adult['phone'] ?? ''),
                'email'                => sanitize_email($adult['email'] ?? ''),
                'birth_date'           => sanitize_text_field($adult['birth_date'] ?? ''),
                'travel_date'          => sanitize_text_field($adult['travel_date'] ?? ''),
                'passport_expiry_date' => sanitize_text_field($adult['passport_expiry'] ?? ''),
            ];
        }
        
        // Add children
        $offset = count($adults_data);
        foreach ($children_data as $index => $child) {
            $passengers[] = [
                'type'                 => 'child',
                'index_in_booking'     => $offset + $index,
                'first_name'           => sanitize_text_field($child['first_name'] ?? ''),
                'last_name'            => sanitize_text_field($child['last_name'] ?? ''),
                'national_id'          => sanitize_text_field($child['national_id'] ?? ''),
                'passport_number'      => sanitize_text_field($child['passport_number'] ?? ''),
                'phone'                => '',
                'email'                => '',
                'birth_date'           => sanitize_text_field($child['birth_date'] ?? ''),
                'travel_date'          => null,
                'passport_expiry_date' => sanitize_text_field($child['passport_expiry'] ?? ''),
            ];
        }
        
        // Batch insert
        $ids = BookingPassengerTable::insert_batch($booking_id, $passengers);
        
        if (count($ids) !== count($passengers)) {
            return ErrorHandler::server_error('خطا در ذخیره اطلاعات مسافران.');
        }
        
        return $ids;
    }
    
    // ═══════════════════════════════════════════════════════════
    // HANDLER: Upload Documents
    // ═══════════════════════════════════════════════════════════
    
    public static function handle_upload_documents(\WP_REST_Request $request) {
        try {
            $booking_code = sanitize_text_field($request->get_param('code'));
            
            // Find booking
            $booking = BookingTable::find_by_code($booking_code);
            
            if (!$booking) {
                return ErrorHandler::to_response(
                    ErrorHandler::not_found('رزرو مورد نظر یافت نشد.')
                );
            }
            
            // Check booking status
            $allowed_statuses = ['awaiting_documents', 'awaiting_payment'];
            if (!in_array($booking['status'], $allowed_statuses)) {
                return ErrorHandler::to_response(
                    ErrorHandler::error(
                        'invalid_status',
                        'در وضعیت فعلی امکان بارگذاری مدارک وجود ندارد.',
                        400
                    )
                );
            }
            
            // Get booking data
            $booking_data = is_string($booking['booking_data']) 
                ? json_decode($booking['booking_data'], true) 
                : $booking['booking_data'];
            
            $visa_post_id = (int) ($booking_data['visa_post_id'] ?? $booking['item_id']);
            
            // Get required documents
            $required_docs = VisaValidator::get_required_documents($visa_post_id);
            
            if (empty($required_docs)) {
                return ErrorHandler::to_response(
                    ErrorHandler::error('no_docs_required', 'این ویزا نیازی به مدارک ندارد.', 400)
                );
            }
            
            // Get passenger_id parameter
            $passenger_id = (int) ($request->get_param('passenger_id') ?? 0);
            
            if ($passenger_id <= 0) {
                return ErrorHandler::to_response(
                    ErrorHandler::missing_params(['passenger_id'])
                );
            }
            
            // Verify passenger belongs to this booking
            $passengers = BookingPassengerTable::get_by_booking($booking['id']);
            $passenger_exists = false;
            $passenger_type = '';
            
            foreach ($passengers as $p) {
                if ((int) $p['id'] === $passenger_id) {
                    $passenger_exists = true;
                    $passenger_type = $p['type'];
                    break;
                }
            }
            
            if (!$passenger_exists) {
                return ErrorHandler::to_response(
                    ErrorHandler::not_found('مسافر مورد نظر در این رزرو یافت نشد.')
                );
            }
            
            // Get uploaded files
            $files = $request->get_file_params();
            
            if (empty($files)) {
                return ErrorHandler::to_response(
                    ErrorHandler::error('no_files', 'هیچ فایلی بارگذاری نشده است.', 400)
                );
            }
            
            // Upload documents
            $upload_result = BookingFileUploader::upload_documents(
                $files,
                $booking['id'],
                $passenger_id
            );
            
            // Check if all required documents are now uploaded
            $all_docs_uploaded = self::check_all_docs_uploaded(
                $booking['id'],
                $visa_post_id
            );
            
            // Update booking status if all documents uploaded
            if ($all_docs_uploaded) {
                BookingTable::update_status($booking['id'], 'awaiting_payment');
                $booking['status'] = 'awaiting_payment';
            }
            
            Logger::info('Documents uploaded for booking', [
                'booking_id'   => $booking['id'],
                'passenger_id' => $passenger_id,
                'uploaded'     => count($upload_result['success']),
                'errors'       => count($upload_result['errors']),
            ]);
            
            return rest_ensure_response([
                'ok'                => true,
                'uploaded'          => count($upload_result['success']),
                'errors'            => $upload_result['errors'],
                'documents'         => $upload_result['success'],
                'all_docs_uploaded' => $all_docs_uploaded,
                'booking_status'    => $booking['status'],
                'next_step'         => $all_docs_uploaded ? 'payment' : 'upload_more',
            ]);
            
        } catch (\Throwable $e) {
            return ErrorHandler::to_response(
                ErrorHandler::handle_exception($e, 'upload-documents')
            );
        }
    }
    
    /**
     * Check if all required documents are uploaded for a booking
     */
    private static function check_all_docs_uploaded(int $booking_id, int $visa_post_id): bool {
        $required_docs = VisaValidator::get_required_documents($visa_post_id);
        
        if (empty($required_docs)) {
            return true;
        }
        
        $passengers = BookingPassengerTable::get_by_booking($booking_id);
        
        foreach ($passengers as $passenger) {
            $docs = BookingDocumentTable::get_by_passenger($passenger['id']);
            $uploaded_types = array_column($docs, 'document_type');
            
            // Check if all required docs are uploaded for this passenger
            foreach ($required_docs as $slug => $doc_info) {
                if (!in_array($slug, $uploaded_types)) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    // ═══════════════════════════════════════════════════════════
    // HANDLER: Get Booking Details
    // ═══════════════════════════════════════════════════════════
    
    public static function handle_get_booking(\WP_REST_Request $request) {
        try {
            $booking_code = sanitize_text_field($request->get_param('code'));
            
            $booking = BookingTable::find_by_code($booking_code);
            
            if (!$booking) {
                return ErrorHandler::to_response(
                    ErrorHandler::not_found('رزرو مورد نظر یافت نشد.')
                );
            }
            
            // Get passengers
            $passengers = BookingPassengerTable::get_by_booking($booking['id']);
            
            // Get documents grouped by passenger
            $documents = BookingDocumentTable::get_grouped_by_passenger($booking['id']);
            
            // Decode JSON fields
            $booking_data = is_string($booking['booking_data']) 
                ? json_decode($booking['booking_data'], true) 
                : $booking['booking_data'];
            
            $passenger_info = is_string($booking['passenger_info']) 
                ? json_decode($booking['passenger_info'], true) 
                : $booking['passenger_info'];
            
            // Format passengers with their documents
            $formatted_passengers = [];
            foreach ($passengers as $p) {
                $p['documents'] = $documents[$p['id']] ?? [];
                $formatted_passengers[] = $p;
            }
            
            return rest_ensure_response([
                'ok'         => true,
                'booking'    => [
                    'id'             => $booking['id'],
                    'booking_code'   => $booking['booking_code'],
                    'type'           => $booking['booking_type'],
                    'status'         => $booking['status'],
                    'status_label'   => self::get_status_label($booking['status']),
                    'total_price'    => (float) $booking['total_price'],
                    'currency'       => $booking['currency'],
                    'passenger_count' => (int) $booking['passenger_count'],
                    'created_at'     => $booking['created_at'],
                    'booking_data'   => $booking_data,
                    'passenger_info' => $passenger_info,
                ],
                'passengers' => $formatted_passengers,
            ]);
            
        } catch (\Throwable $e) {
            return ErrorHandler::to_response(
                ErrorHandler::handle_exception($e, 'get-booking')
            );
        }
    }
    
    /**
     * Get Persian label for booking status
     */
    private static function get_status_label(string $status): string {
        $labels = [
            'pending'           => 'در انتظار',
            'awaiting_documents' => 'در انتظار مدارک',
            'awaiting_payment'  => 'در انتظار پرداخت',
            'paid'              => 'پرداخت شده',
            'processing'        => 'در حال پردازش',
            'completed'         => 'تکمیل شده',
            'cancelled'         => 'لغو شده',
            'refunded'          => 'بازپرداخت شده',
            'failed'            => 'ناموفق',
        ];
        
        return $labels[$status] ?? $status;
    }
    
    // ═══════════════════════════════════════════════════════════
    // HANDLER: List User Bookings
    // ═══════════════════════════════════════════════════════════
    
    public static function handle_list_bookings(\WP_REST_Request $request) {
        try {
            $user_id = get_current_user_id();
            
            if (!$user_id) {
                return ErrorHandler::to_response(
                    ErrorHandler::unauthorized('ابتدا وارد حساب کاربری خود شوید.')
                );
            }
            
            $page   = max(1, (int) ($request->get_param('page') ?? 1));
            $limit  = min(50, max(1, (int) ($request->get_param('limit') ?? 10)));
            $status = sanitize_text_field($request->get_param('status') ?? '');
            $offset = ($page - 1) * $limit;
            
            $bookings = BookingTable::get_user_bookings($user_id, $limit, $offset, $status);
            $total    = BookingTable::count_user_bookings($user_id, $status);
            
            // Format bookings
            $formatted = [];
            foreach ($bookings as $b) {
                $formatted[] = [
                    'id'            => $b['id'],
                    'booking_code'  => $b['booking_code'],
                    'type'          => $b['booking_type'],
                    'status'        => $b['status'],
                    'status_label'  => self::get_status_label($b['status']),
                    'total_price'   => (float) $b['total_price'],
                    'currency'      => $b['currency'],
                    'created_at'    => $b['created_at'],
                ];
            }
            
            return rest_ensure_response([
                'ok'         => true,
                'bookings'   => $formatted,
                'total'      => $total,
                'page'       => $page,
                'limit'      => $limit,
                'total_pages' => ceil($total / $limit),
            ]);
            
        } catch (\Throwable $e) {
            return ErrorHandler::to_response(
                ErrorHandler::handle_exception($e, 'list-bookings')
            );
        }
    }
    
    // ═══════════════════════════════════════════════════════════
    // HANDLER: Cancel Booking
    // ═══════════════════════════════════════════════════════════
    
    public static function handle_cancel_booking(\WP_REST_Request $request) {
        try {
            $booking_code = sanitize_text_field($request->get_param('code'));
            
            $booking = BookingTable::find_by_code($booking_code);
            
            if (!$booking) {
                return ErrorHandler::to_response(
                    ErrorHandler::not_found('رزرو مورد نظر یافت نشد.')
                );
            }
            
            // Cancel the booking
            $result = VisaBookingAdapter::cancel_booking($booking['id']);
            
            if (is_wp_error($result)) {
                return ErrorHandler::to_response($result);
            }
            
            return rest_ensure_response([
                'ok'           => true,
                'booking_code' => $booking_code,
                'status'       => 'cancelled',
                'message'      => 'رزرو شما با موفقیت لغو شد.',
            ]);
            
        } catch (\Throwable $e) {
            return ErrorHandler::to_response(
                ErrorHandler::handle_exception($e, 'cancel-booking')
            );
        }
    }
    
    // ═══════════════════════════════════════════════════════════
    // HANDLER: Payment Init
    // ═══════════════════════════════════════════════════════════
    
    public static function handle_payment_init(\WP_REST_Request $request) {
        try {
            $booking_code = sanitize_text_field($request->get_param('code'));
            
            // Find booking
            $booking = BookingTable::find_by_code($booking_code);
            
            if (!$booking) {
                return ErrorHandler::to_response(
                    ErrorHandler::not_found('رزرو مورد نظر یافت نشد.')
                );
            }
            
            // Initialize payment
            $result = \NextSafar\Payment\PaymentService::init_payment($booking['id']);
            
            if (is_wp_error($result)) {
                return ErrorHandler::to_response($result);
            }
            
            return rest_ensure_response([
                'ok'           => true,
                'payment_id'   => $result['payment_id'],
                'redirect_url' => $result['redirect_url'],
                'message'      => 'در حال انتقال به درگاه پرداخت...',
            ]);
            
        } catch (\Throwable $e) {
            return ErrorHandler::to_response(
                ErrorHandler::handle_exception($e, 'payment-init')
            );
        }
    }
    
    // ═══════════════════════════════════════════════════════════
    // HANDLER: Payment Callback
    // ═══════════════════════════════════════════════════════════
    
    public static function handle_payment_callback(\WP_REST_Request $request) {
        try {
            // Get callback parameters
            $params = [
                'booking_id' => (int) ($request->get_param('booking_id') ?? 0),
                'payment_id' => (int) ($request->get_param('payment_id') ?? 0),
                'Status'     => sanitize_text_field($request->get_param('Status') ?? ''),
                'Authority'  => sanitize_text_field($request->get_param('Authority') ?? ''),
            ];
            
            // Handle callback
            $result = \NextSafar\Payment\PaymentService::handle_callback($params);
            
            if (is_wp_error($result)) {
                // Return error page or redirect
                return rest_ensure_response([
                    'ok'      => false,
                    'message' => $result->get_error_message(),
                ]);
            }
            
            // For web browsers, redirect to appropriate page
            // For API clients, return JSON
            $accept = $request->get_header('accept');
            
            if (strpos($accept, 'text/html') !== false) {
                // Browser request - redirect to frontend page
                if ($result['success']) {
                    wp_redirect(home_url('/booking/success?code=' . $result['booking_code']));
                    exit;
                } else {
                    wp_redirect(home_url('/booking/failed?code=' . $result['booking_code'] . '&reason=' . urlencode($result['message'])));
                    exit;
                }
            }
            
            // API request - return JSON
            return rest_ensure_response([
                'ok'           => $result['success'],
                'cancelled'    => $result['cancelled'] ?? false,
                'booking_code' => $result['booking_code'],
                'message'      => $result['message'],
            ]);
            
        } catch (\Throwable $e) {
            return ErrorHandler::to_response(
                ErrorHandler::handle_exception($e, 'payment-callback')
            );
        }
    }
    
    // ═══════════════════════════════════════════════════════════
    // HANDLER: Verify Payment Status
    // ═══════════════════════════════════════════════════════════
    
    public static function handle_verify_payment(\WP_REST_Request $request) {
        try {
            $payment_id = (int) ($request->get_param('payment_id') ?? 0);
            $booking_code = sanitize_text_field($request->get_param('code') ?? '');
            
            // Get payment by ID or booking code
            if ($payment_id > 0) {
                $result = \NextSafar\Payment\PaymentService::get_payment_status($payment_id);
            } elseif (!empty($booking_code)) {
                // Find booking and get latest payment
                $booking = BookingTable::find_by_code($booking_code);
                
                if (!$booking) {
                    return ErrorHandler::to_response(
                        ErrorHandler::not_found('رزرو مورد نظر یافت نشد.')
                    );
                }
                
                $payments = \NextSafar\Payment\PaymentTable::find_by_booking($booking['id']);
                
                if (empty($payments)) {
                    return rest_ensure_response([
                        'ok'      => true,
                        'status'  => 'no_payment',
                        'message' => 'هنوز پرداختی برای این رزرو ثبت نشده است.',
                    ]);
                }
                
                $latest_payment = $payments[0];
                $result = \NextSafar\Payment\PaymentService::get_payment_status($latest_payment['id']);
            } else {
                return ErrorHandler::to_response(
                    ErrorHandler::missing_params(['payment_id', 'code'])
                );
            }
            
            if (is_wp_error($result)) {
                return ErrorHandler::to_response($result);
            }
            
            return rest_ensure_response([
                'ok'      => true,
                'payment' => $result,
            ]);
            
        } catch (\Throwable $e) {
            return ErrorHandler::to_response(
                ErrorHandler::handle_exception($e, 'verify-payment')
            );
        }
    }
    
    // ═══════════════════════════════════════════════════════════
    // HANDLER: Get Visa Info
    // ═══════════════════════════════════════════════════════════
    
    public static function handle_get_visa_info(\WP_REST_Request $request) {
        try {
            $post_id = (int) $request->get_param('post_id');
            
            $visa_info = VisaBookingAdapter::get_item_details($post_id);
            
            if (is_wp_error($visa_info)) {
                return ErrorHandler::to_response($visa_info);
            }
            
            return rest_ensure_response([
                'ok'   => true,
                'visa' => $visa_info,
            ]);
            
        } catch (\Throwable $e) {
            return ErrorHandler::to_response(
                ErrorHandler::handle_exception($e, 'get-visa-info')
            );
        }
    }
    
    // ═══════════════════════════════════════════════════════════
    // HELPER: Get visa summary
    // ═══════════════════════════════════════════════════════════
    
    private static function get_visa_summary(int $post_id): array {
        $post = get_post($post_id);
        
        if (!$post) {
            return [];
        }
        
        return [
            'id'    => $post_id,
            'title' => $post->post_title,
            'slug'  => $post->post_name,
            'url'   => get_permalink($post_id),
        ];
    }
}

// Initialize endpoints
BookingEndpoints::init();