<?php
/**
 * NextSafar Core - Visa Booking Validator
 * 
 * Validates all visa booking data including passenger info,
 * dates, documents, and contact information.
 * 
 * @package NextSafar\Booking
 * @since   2.7.0
 */

namespace NextSafar\Booking;

use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class VisaValidator {
    
    /**
     * Minimum age for adults (years)
     */
    const MIN_ADULT_AGE = 18;
    
    /**
     * Minimum days from today for travel date
     */
    const MIN_TRAVEL_DAYS = 2;
    
    /**
     * Minimum days for passport expiry from today
     */
    const MIN_PASSPORT_EXPIRY_DAYS = 180;
    
    /**
     * Validate complete visa booking data
     * 
     * @param array $data Booking data from request
     * @return true|\WP_Error True if valid, WP_Error otherwise
     */
    public static function validate_booking(array $data): true|\WP_Error {
        $errors = [];
        
        // 1. Validate visa info
        $visa_errors = self::validate_visa_info($data);
        if (!empty($visa_errors)) {
            $errors = array_merge($errors, $visa_errors);
        }
        
        // 2. Validate main booker info
        $booker_errors = self::validate_main_booker($data);
        if (!empty($booker_errors)) {
            $errors = array_merge($errors, $booker_errors);
        }
        
        // 3. Validate passengers
        $passenger_errors = self::validate_passengers($data);
        if (!empty($passenger_errors)) {
            $errors = array_merge($errors, $passenger_errors);
        }
        
        // 4. Validate documents
        $doc_errors = self::validate_documents($data);
        if (!empty($doc_errors)) {
            $errors = array_merge($errors, $doc_errors);
        }
        
        if (!empty($errors)) {
            return new \WP_Error('validation_failed', implode(' ', $errors), [
                'status' => 400,
                'errors' => $errors,
            ]);
        }
        
        return true;
    }
    
    /**
     * Validate visa information
     * 
     * @param array $data
     * @return array Errors
     */
    public static function validate_visa_info(array $data): array {
        $errors = [];
        
        $visa_post_id = (int) ($data['visa_post_id'] ?? $data['post_id'] ?? 0);
        
        if ($visa_post_id <= 0) {
            $errors[] = 'ویزای مورد نظر مشخص نشده است.';
            return $errors;
        }
        
        // Check if visa post exists and is published
        $post = get_post($visa_post_id);
        if (!$post || $post->post_type !== 'visa' || $post->post_status !== 'publish') {
            $errors[] = 'ویزای مورد نظر یافت نشد یا در دسترس نیست.';
            return $errors;
        }
        
        // Validate price index
        $price_index = (int) ($data['visa_index'] ?? $data['visa'] ?? 0);
        $prices = VisaPriceCalculator::get_visa_prices($visa_post_id);
        
        if (!isset($prices[$price_index])) {
            $errors[] = 'نوع ویزای انتخاب شده معتبر نیست.';
        }
        
        // Validate passenger counts
        $adults   = (int) ($data['adults'] ?? 0);
        $children = (int) ($data['children'] ?? 0);
        
        if ($adults < 1) {
            $errors[] = 'حداقل یک بزرگسال الزامی است.';
        }
        
        if ($adults > 20) {
            $errors[] = 'حداکثر ۲۰ بزرگسال می‌تواند در یک رزرو باشد.';
        }
        
        if ($children > 20) {
            $errors[] = 'حداکثر ۲۰ کودک می‌تواند در یک رزرو باشد.';
        }
        
        if (($adults + $children) > 30) {
            $errors[] = 'مجموع مسافران نمی‌تواند بیشتر از ۳۰ نفر باشد.';
        }
        
        return $errors;
    }
    
    /**
     * Validate main booker information
     * 
     * @param array $data
     * @return array Errors
     */
    public static function validate_main_booker(array $data): array {
        $errors = [];
        
        // Phone (required)
        $phone = sanitize_text_field($data['main_phone'] ?? '');
        
        if (empty($phone)) {
            $errors[] = 'شماره تلفن رزروکننده الزامی است.';
        } elseif (!self::is_valid_phone($phone)) {
            $errors[] = 'شماره تلفن رزروکننده معتبر نیست. لطفاً یک شماره موبایل ایرانی وارد کنید.';
        }
        
        // Email (optional but validate if provided)
        $email = sanitize_email($data['main_mail'] ?? $data['main_email'] ?? '');
        
        if (!empty($email) && !is_email($email)) {
            $errors[] = 'ایمیل وارد شده معتبر نیست.';
        }
        
        // National ID (optional for main booker)
        $national_id = sanitize_text_field($data['main_national_id'] ?? '');
        
        if (!empty($national_id) && !self::is_valid_national_id($national_id)) {
            $errors[] = 'کد ملی رزروکننده معتبر نیست. کد ملی باید ۱۰ رقم باشد.';
        }
        
        return $errors;
    }
    
    /**
     * Validate all passengers
     * 
     * @param array $data
     * @return array Errors
     */
    public static function validate_passengers(array $data): array {
        $errors = [];
        
        $adults_data   = $data['adult'] ?? $data['adults_data'] ?? [];
        $children_data = $data['child'] ?? $data['children_data'] ?? [];
        
        $expected_adults   = (int) ($data['adults'] ?? count($adults_data));
        $expected_children = (int) ($data['children'] ?? count($children_data));
        
        // Validate adult count
        if (count($adults_data) !== $expected_adults) {
            $errors[] = sprintf(
                'تعداد اطلاعات بزرگسالان (%d) با تعداد انتخاب شده (%d) مطابقت ندارد.',
                count($adults_data),
                $expected_adults
            );
        }
        
        // Validate child count
        if (count($children_data) !== $expected_children) {
            $errors[] = sprintf(
                'تعداد اطلاعات کودکان (%d) با تعداد انتخاب شده (%d) مطابقت ندارد.',
                count($children_data),
                $expected_children
            );
        }
        
        // Validate each adult
        foreach ($adults_data as $index => $adult) {
            $adult_errors = self::validate_adult($adult, $index);
            $errors = array_merge($errors, $adult_errors);
        }
        
        // Validate each child
        foreach ($children_data as $index => $child) {
            $child_errors = self::validate_child($child, $index);
            $errors = array_merge($errors, $child_errors);
        }
        
        return $errors;
    }
    
    /**
     * Validate a single adult passenger
     * 
     * @param array $adult Adult data
     * @param int $index Passenger index (for error messages)
     * @return array Errors
     */
    public static function validate_adult(array $adult, int $index): array {
        $errors = [];
        $num = $index + 1;
        $prefix = "بزرگسال {$num}: ";
        
        // First name (required, Persian)
        $first_name = sanitize_text_field($adult['first_name'] ?? '');
        if (empty($first_name)) {
            $errors[] = $prefix . 'نام الزامی است.';
        } elseif (!self::is_persian_text($first_name)) {
            $errors[] = $prefix . 'نام باید به فارسی وارد شود.';
        }
        
        // Last name (required, Persian)
        $last_name = sanitize_text_field($adult['last_name'] ?? '');
        if (empty($last_name)) {
            $errors[] = $prefix . 'نام خانوادگی الزامی است.';
        } elseif (!self::is_persian_text($last_name)) {
            $errors[] = $prefix . 'نام خانوادگی باید به فارسی وارد شود.';
        }
        
        // National ID (required)
        $national_id = sanitize_text_field($adult['national_id'] ?? '');
        if (empty($national_id)) {
            $errors[] = $prefix . 'کد ملی الزامی است.';
        } elseif (!self::is_valid_national_id($national_id)) {
            $errors[] = $prefix . 'کد ملی معتبر نیست. کد ملی باید ۱۰ رقم باشد.';
        }
        
        // Phone (required for first adult, optional for others)
        $phone = sanitize_text_field($adult['phone'] ?? '');
        if ($index === 0) {
            if (empty($phone)) {
                $errors[] = $prefix . 'شماره تلفن الزامی است.';
            } elseif (!self::is_valid_phone($phone)) {
                $errors[] = $prefix . 'شماره تلفن معتبر نیست.';
            }
        } elseif (!empty($phone) && !self::is_valid_phone($phone)) {
            $errors[] = $prefix . 'شماره تلفن معتبر نیست.';
        }
        
        // Passport number (optional)
        $passport = sanitize_text_field($adult['passport_number'] ?? '');
        if (!empty($passport) && !self::is_valid_passport($passport)) {
            $errors[] = $prefix . 'شماره پاسپورت معتبر نیست.';
        }
        
        // Birth date (required, must be 18+ years old)
        $birth_date = sanitize_text_field($adult['birth_date'] ?? '');
        if (empty($birth_date)) {
            $errors[] = $prefix . 'تاریخ تولد الزامی است.';
        } else {
            $age = self::calculate_age($birth_date);
            if ($age === null) {
                $errors[] = $prefix . 'تاریخ تولد معتبر نیست.';
            } elseif ($age < self::MIN_ADULT_AGE) {
                $errors[] = $prefix . sprintf('سن بزرگسال باید حداقل %d سال باشد.', self::MIN_ADULT_AGE);
            }
        }
        
        // Travel date (required for first adult, optional for others)
        $travel_date = sanitize_text_field($adult['travel_date'] ?? '');
        if ($index === 0) {
            if (empty($travel_date)) {
                $errors[] = $prefix . 'تاریخ سفر الزامی است.';
            } else {
                $travel_error = self::validate_travel_date($travel_date);
                if ($travel_error) {
                    $errors[] = $prefix . $travel_error;
                }
            }
        } elseif (!empty($travel_date)) {
            $travel_error = self::validate_travel_date($travel_date);
            if ($travel_error) {
                $errors[] = $prefix . $travel_error;
            }
        }
        
        // Passport expiry (optional, but if provided must be 180+ days from now)
        $passport_expiry = sanitize_text_field($adult['passport_expiry'] ?? '');
        if (!empty($passport_expiry)) {
            $expiry_error = self::validate_passport_expiry($passport_expiry);
            if ($expiry_error) {
                $errors[] = $prefix . $expiry_error;
            }
        }
        
        return $errors;
    }
    
    /**
     * Validate a single child passenger
     * 
     * @param array $child Child data
     * @param int $index Passenger index
     * @return array Errors
     */
    public static function validate_child(array $child, int $index): array {
        $errors = [];
        $num = $index + 1;
        $prefix = "کودک {$num}: ";
        
        // First name (required, Persian)
        $first_name = sanitize_text_field($child['first_name'] ?? '');
        if (empty($first_name)) {
            $errors[] = $prefix . 'نام الزامی است.';
        } elseif (!self::is_persian_text($first_name)) {
            $errors[] = $prefix . 'نام باید به فارسی وارد شود.';
        }
        
        // Last name (required, Persian)
        $last_name = sanitize_text_field($child['last_name'] ?? '');
        if (empty($last_name)) {
            $errors[] = $prefix . 'نام خانوادگی الزامی است.';
        } elseif (!self::is_persian_text($last_name)) {
            $errors[] = $prefix . 'نام خانوادگی باید به فارسی وارد شود.';
        }
        
        // National ID (required)
        $national_id = sanitize_text_field($child['national_id'] ?? '');
        if (empty($national_id)) {
            $errors[] = $prefix . 'کد ملی الزامی است.';
        } elseif (!self::is_valid_national_id($national_id)) {
            $errors[] = $prefix . 'کد ملی معتبر نیست. کد ملی باید ۱۰ رقم باشد.';
        }
        
        // Passport number (optional)
        $passport = sanitize_text_field($child['passport_number'] ?? '');
        if (!empty($passport) && !self::is_valid_passport($passport)) {
            $errors[] = $prefix . 'شماره پاسپورت معتبر نیست.';
        }
        
        // Birth date (required)
        $birth_date = sanitize_text_field($child['birth_date'] ?? '');
        if (empty($birth_date)) {
            $errors[] = $prefix . 'تاریخ تولد الزامی است.';
        } else {
            $age = self::calculate_age($birth_date);
            if ($age === null) {
                $errors[] = $prefix . 'تاریخ تولد معتبر نیست.';
            } elseif ($age >= self::MIN_ADULT_AGE) {
                $errors[] = $prefix . 'سن کودک باید کمتر از ۱۸ سال باشد. لطفاً به عنوان بزرگسال ثبت کنید.';
            }
        }
        
        // Passport expiry (optional)
        $passport_expiry = sanitize_text_field($child['passport_expiry'] ?? '');
        if (!empty($passport_expiry)) {
            $expiry_error = self::validate_passport_expiry($passport_expiry);
            if ($expiry_error) {
                $errors[] = $prefix . $expiry_error;
            }
        }
        
        return $errors;
    }
    
    /**
     * Validate documents
     * 
     * @param array $data
     * @return array Errors
     */
    public static function validate_documents(array $data): array {
        $errors = [];
        
        $visa_post_id = (int) ($data['visa_post_id'] ?? $data['post_id'] ?? 0);
        $adults_data  = $data['adult'] ?? [];
        $children_data = $data['child'] ?? [];
        
        // Get required documents for this visa
        $required_docs = self::get_required_documents($visa_post_id);
        
        if (empty($required_docs)) {
            return $errors; // No documents required
        }
        
        // Check each adult's documents
        foreach ($adults_data as $index => $adult) {
            $doc_errors = self::validate_passenger_docs(
                $adult['docs'] ?? [],
                $required_docs,
                "بزرگسال " . ($index + 1)
            );
            $errors = array_merge($errors, $doc_errors);
        }
        
        // Check each child's documents
        foreach ($children_data as $index => $child) {
            $doc_errors = self::validate_passenger_docs(
                $child['docs'] ?? [],
                $required_docs,
                "کودک " . ($index + 1)
            );
            $errors = array_merge($errors, $doc_errors);
        }
        
        return $errors;
    }
    
    /**
     * Get required documents for a visa
     */
    public static function get_required_documents(int $visa_post_id): array {
        // Get documents from visa post meta
        $visa_docs = get_post_meta($visa_post_id, '_visa_docs', true);
        
        if (!is_array($visa_docs)) {
            $visa_docs = [];
        }
        
        // Default documents structure with passenger type filtering
        $default_docs = [
            'passport' => [
                'label' => 'تصویر صفحه اول پاسپورت',
                'required' => true,
                'applies_to' => ['adult', 'child'],
            ],
            'photo' => [
                'label' => 'عکس پرسنلی ۳×۴',
                'required' => true,
                'applies_to' => ['adult', 'child'],
            ],
            'national_id_card' => [
                'label' => 'تصویر کارت ملی',
                'required' => true,
                'applies_to' => ['adult'],
            ],
            'birth_certificate' => [
                'label' => 'تصویر شناسنامه',
                'required' => true,
                'applies_to' => ['child'],
            ],
            'job_letter' => [
                'label' => 'گواهی اشتغال به کار',
                'required' => false,
                'applies_to' => ['adult'],
            ],
            'bank_statement' => [
                'label' => 'پرینت حساب بانکی',
                'required' => false,
                'applies_to' => ['adult'],
            ],
        ];
        
        $required_docs = [];
        
        foreach ($default_docs as $slug => $info) {
            // Check if this document is enabled in visa settings
            $doc_setting = $visa_docs[$slug] ?? null;
            
            if ($doc_setting === null) {
                // Not configured, use default only if required
                if ($info['required']) {
                    $required_docs[$slug] = $info;
                }
                continue;
            }
            
            $is_checked = (bool) ($doc_setting['checked'] ?? false);
            
            if ($is_checked) {
                $required_docs[$slug] = [
                    'label' => !empty($doc_setting['text']) 
                        ? $doc_setting['text'] 
                        : $info['label'],
                    'required' => $info['required'],
                    'applies_to' => $info['applies_to'],
                ];
            }
        }
        
        /**
         * Filter hook for other plugins to modify required documents
         */
        return apply_filters('nextsafar_visa_required_docs', $required_docs, $visa_post_id);
    }
    
    /**
     * Generate slug for document label
     * 
     * @param string $label
     * @return string
     */
    public static function doc_slug(string $label): string {
        $slug = sanitize_title($label);
        
        if (!$slug) {
            $slug = 'doc_' . substr(md5($label), 0, 8);
        }
        
        return $slug;
    }
    
    /**
     * Validate a passenger's documents
     * 
     * @param array $uploaded_docs Uploaded documents
     * @param array $required_docs Required documents
     * @param string $passenger_label Passenger label for error messages
     * @return array Errors
     */
    private static function validate_passenger_docs(
        array $uploaded_docs, 
        array $required_docs,
        string $passenger_label
    ): array {
        $errors = [];
        
        foreach ($required_docs as $slug => $doc_info) {
            // Check if this document was uploaded
            $doc_files = $uploaded_docs[$slug] ?? [];
            
            // Handle file upload array
            if (is_array($doc_files) && isset($doc_files['name'])) {
                // It's a $_FILES style array
                $has_file = !empty($doc_files['name']) && $doc_files['name'][0] !== '';
            } else {
                $has_file = !empty($doc_files);
            }
            
            if (!$has_file) {
                $errors[] = sprintf(
                    '%s: مدرک "%s" الزامی است و بارگذاری نشده است.',
                    $passenger_label,
                    $doc_info['label']
                );
            }
        }
        
        return $errors;
    }
    
    // ─────────────────────────────────────────────────────────────
    // Helper Validation Methods
    // ─────────────────────────────────────────────────────────────
    
    /**
     * Validate phone number (Iranian mobile)
     * 
     * @param string $phone
     * @return bool
     */
    public static function is_valid_phone(string $phone): bool {
        // Remove spaces and dashes
        $phone = preg_replace('/[\s\-]/', '', $phone);
        
        // Iranian mobile: 09XXXXXXXXX or 989XXXXXXXXX
        return (bool) preg_match('/^(09|989|\+989)\d{9}$/', $phone);
    }
    
    /**
     * Validate national ID (10 digits)
     * 
     * @param string $national_id
     * @return bool
     */
    public static function is_valid_national_id(string $national_id): bool {
        return (bool) preg_match('/^\d{10}$/', $national_id);
    }
    
    /**
     * Validate passport number
     * 
     * @param string $passport
     * @return bool
     */
    public static function is_valid_passport(string $passport): bool {
        // Passport: 1 letter + 7-8 digits OR 8-9 digits
        return (bool) preg_match('/^[A-Z]?\d{7,9}$/i', $passport);
    }
    
    /**
     * Check if text is Persian
     * 
     * @param string $text
     * @return bool
     */
    public static function is_persian_text(string $text): bool {
        // Allow Persian letters, spaces, and zero-width non-joiner
        return (bool) preg_match('/^[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}\s‌]+$/u', $text);
    }
    
    /**
     * Calculate age from birth date
     * 
     * @param string $birth_date YYYY-MM-DD
     * @return int|null Age or null if invalid
     */
    public static function calculate_age(string $birth_date): ?int {
        try {
            $birth = new \DateTime($birth_date);
            $today = new \DateTime('today');
            
            if ($birth > $today) {
                return null; // Future date
            }
            
            return $birth->diff($today)->y;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Validate travel date
     * 
     * @param string $date YYYY-MM-DD
     * @return string|null Error message or null if valid
     */
    public static function validate_travel_date(string $date): ?string {
        try {
            $travel = new \DateTime($date);
            $today = new \DateTime('today');
            
            if ($travel <= $today) {
                return 'تاریخ سفر باید در آینده باشد.';
            }
            
            $min_date = (clone $today)->modify('+' . self::MIN_TRAVEL_DAYS . ' days');
            
            if ($travel < $min_date) {
                return sprintf('تاریخ سفر باید حداقل %d روز بعد از امروز باشد.', self::MIN_TRAVEL_DAYS);
            }
            
            // Check if date is too far (max 1 year)
            $max_date = (clone $today)->modify('+1 year');
            
            if ($travel > $max_date) {
                return 'تاریخ سفر نمی‌تواند بیشتر از یک سال آینده باشد.';
            }
            
            return null;
        } catch (\Exception $e) {
            return 'تاریخ سفر معتبر نیست.';
        }
    }
    
    /**
     * Validate passport expiry date
     * 
     * @param string $date YYYY-MM-DD
     * @return string|null Error message or null if valid
     */
    public static function validate_passport_expiry(string $date): ?string {
        try {
            $expiry = new \DateTime($date);
            $today = new \DateTime('today');
            
            if ($expiry <= $today) {
                return 'تاریخ انقضای پاسپورت باید در آینده باشد.';
            }
            
            $min_date = (clone $today)->modify('+' . self::MIN_PASSPORT_EXPIRY_DAYS . ' days');
            
            if ($expiry < $min_date) {
                return sprintf(
                    'اعتبار پاسپورت باید حداقل %d روز دیگر باشد.',
                    self::MIN_PASSPORT_EXPIRY_DAYS
                );
            }
            
            return null;
        } catch (\Exception $e) {
            return 'تاریخ انقضای پاسپورت معتبر نیست.';
        }
    }
}