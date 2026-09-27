<?php
/**
 * NextSafar Core - Validator
 * سیستم اعتبارسنجی ورودی‌ها
 *
 * @package NextSafar\Core
 * @since   2.6.0
 */

namespace NextSafar\Core;

if (!defined('ABSPATH')) exit;

class Validator {

    /**
     * اعتبارسنجی جستجوی هتل
     *
     * @param \WP_REST_Request $request
     * @return true|\WP_Error
     */
    public static function validate_hotel_search(\WP_REST_Request $request) {
        $errors = [];

        // شهر
        $city = sanitize_text_field($request->get_param('city') ?? '');
        if (empty($city)) {
            $errors[] = 'شهر مقصد الزامی است.';
        } elseif (mb_strlen($city) < 2) {
            $errors[] = 'نام شهر باید حداقل ۲ حرف باشد.';
        } elseif (mb_strlen($city) > 100) {
            $errors[] = 'نام شهر نباید بیشتر از ۱۰۰ حرف باشد.';
        }

        // تاریخ ورود
        $check_in = sanitize_text_field($request->get_param('check_in') ?? '');
        if (empty($check_in)) {
            $errors[] = 'تاریخ ورود الزامی است.';
        }

        // تاریخ خروج
        $check_out = sanitize_text_field($request->get_param('check_out') ?? '');
        if (empty($check_out)) {
            $errors[] = 'تاریخ خروج الزامی است.';
        }

        // اعتبارسنجی تاریخ‌ها
        if (empty($errors)) {
            $date_error = self::validate_date_range($check_in, $check_out);
            if ($date_error !== null) {
                $errors[] = $date_error;
            }
        }

        // تعداد بزرگسالان
        $adults = (int) ($request->get_param('adults') ?? 2);
        if ($adults < 1 || $adults > 10) {
            $errors[] = 'تعداد بزرگسالان باید بین ۱ تا ۱۰ باشد.';
        }

        // تعداد کودکان
        $children = (int) ($request->get_param('children') ?? 0);
        if ($children < 0 || $children > 10) {
            $errors[] = 'تعداد کودکان باید بین ۰ تا ۱۰ باشد.';
        }

        // مجموع مسافران
        if (($adults + $children) > 15) {
            $errors[] = 'مجموع مسافران نباید بیشتر از ۱۵ نفر باشد.';
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
     * اعتبارسنجی جستجوی پرواز
     *
     * @param \WP_REST_Request $request
     * @return true|\WP_Error
     */
    public static function validate_flight_search(\WP_REST_Request $request) {
        $errors = [];

        // مبدا
        $origin = sanitize_text_field($request->get_param('origin') ?? '');
        if (empty($origin)) {
            $errors[] = 'فرودگاه مبدا الزامی است.';
        } elseif (!preg_match('/^[A-Z]{3}$/i', $origin)) {
            $errors[] = 'کد فرودگاه مبدا باید ۳ حرف انگلیسی باشد (مثال: THR).';
        }

        // مقصد
        $dest = sanitize_text_field($request->get_param('dest') ?? '');
        if (empty($dest)) {
            $errors[] = 'فرودگاه مقصد الزامی است.';
        } elseif (!preg_match('/^[A-Z]{3}$/i', $dest)) {
            $errors[] = 'کد فرودگاه مقصد باید ۳ حرف انگلیسی باشد (مثال: MHD).';
        }

        // مبدا و مقصد نباید یکی باشند
        if (strtoupper($origin) === strtoupper($dest)) {
            $errors[] = 'فرودگاه مبدا و مقصد نمی‌توانند یکسان باشند.';
        }

        // تاریخ رفت
        $date = sanitize_text_field($request->get_param('date') ?? '');
        if (empty($date)) {
            $errors[] = 'تاریخ رفت الزامی است.';
        } else {
            $greg_date = self::convert_to_gregorian($date);
            if ($greg_date === null) {
                $errors[] = 'تاریخ رفت نامعتبر است.';
            } elseif (strtotime($greg_date) < strtotime('today')) {
                $errors[] = 'تاریخ رفت نمی‌تواند در گذشته باشد.';
            } elseif (strtotime($greg_date) > strtotime('+11 months')) {
                $errors[] = 'تاریخ رفت نمی‌تواند بیشتر از ۱۱ ماه آینده باشد.';
            }
        }

        // نوع سفر
        $trip_type = sanitize_text_field($request->get_param('trip_type') ?? 'one_way');
        if (!in_array($trip_type, ['one_way', 'round_trip'])) {
            $errors[] = 'نوع سفر نامعتبر است.';
        }

        // تاریخ برگشت (برای رفت و برگشت)
        if ($trip_type === 'round_trip') {
            $return_date = sanitize_text_field($request->get_param('return_date') ?? '');
            if (empty($return_date)) {
                $errors[] = 'تاریخ برگشت الزامی است.';
            } else {
                $greg_return = self::convert_to_gregorian($return_date);
                $greg_depart = self::convert_to_gregorian($date);

                if ($greg_return === null) {
                    $errors[] = 'تاریخ برگشت نامعتبر است.';
                } elseif ($greg_depart !== null && $greg_return < $greg_depart) {
                    $errors[] = 'تاریخ برگشت نمی‌تواند قبل از تاریخ رفت باشد.';
                }
            }
        }

        // کلاس پروازی
        $cabin = sanitize_text_field($request->get_param('cabin') ?? 'economy');
        $valid_cabins = ['economy', 'premium_economy', 'business', 'first'];
        if (!in_array($cabin, $valid_cabins)) {
            $errors[] = 'کلاس پروازی نامعتبر است.';
        }

        // تعداد بزرگسالان
        $adults = (int) ($request->get_param('adults') ?? 1);
        if ($adults < 1 || $adults > 9) {
            $errors[] = 'تعداد بزرگسالان باید بین ۱ تا ۹ باشد.';
        }

        // تعداد کودکان
        $children = (int) ($request->get_param('children') ?? 0);
        if ($children < 0 || $children > 9) {
            $errors[] = 'تعداد کودکان باید بین ۰ تا ۹ باشد.';
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
     * اعتبارسنجی جزئیات هتل
     *
     * @param \WP_REST_Request $request
     * @return true|\WP_Error
     */
    public static function validate_hotel_details(\WP_REST_Request $request) {
        $errors = [];

        $token = sanitize_text_field($request->get_param('token') ?? '');
        if (empty($token)) {
            $errors[] = 'توکن هتل الزامی است.';
        }

        // تعداد بزرگسالان
        $adults = (int) ($request->get_param('adults') ?? 2);
        if ($adults < 1 || $adults > 10) {
            $errors[] = 'تعداد بزرگسالان باید بین ۱ تا ۱۰ باشد.';
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
     * اعتبارسنجی بازه تاریخی
     */
    private static function validate_date_range(string $check_in, string $check_out): ?string {
        $in_greg = self::convert_to_gregorian($check_in);
        $out_greg = self::convert_to_gregorian($check_out);

        if ($in_greg === null || $out_greg === null) {
            return 'تاریخ وارد شده نامعتبر است.';
        }

        $in_ts = strtotime($in_greg);
        $out_ts = strtotime($out_greg);

        if ($in_ts === false || $out_ts === false) {
            return 'تاریخ وارد شده نامعتبر است.';
        }

        // تاریخ ورود نباید در گذشته باشد
        if ($in_ts < strtotime('today')) {
            return 'تاریخ ورود نمی‌تواند در گذشته باشد.';
        }

        // تاریخ خروج باید بعد از ورود باشد
        if ($out_ts <= $in_ts) {
            return 'تاریخ خروج باید بعد از تاریخ ورود باشد.';
        }

        // حداکثر 30 شب اقامت
        $nights = ($out_ts - $in_ts) / 86400;
        if ($nights > 30) {
            return 'حداکثر مدت اقامت ۳۰ شب است.';
        }

        // حداکثر 11 ماه آینده
        if ($in_ts > strtotime('+11 months')) {
            return 'تاریخ ورود نمی‌تواند بیشتر از ۱۱ ماه آینده باشد.';
        }

        return null;
    }

    /**
     * تبدیل تاریخ شمسی به میلادی
     */
    private static function convert_to_gregorian(string $date): ?string {
        // اگر میلادی باشد
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        // اگر شمسی باشد
        if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $date, $m)) {
            return self::jalali_to_gregorian((int)$m[1], (int)$m[2], (int)$m[3]);
        }

        return null;
    }

    /**
     * تبدیل تاریخ شمسی به میلادی (الگوریتم)
     */
    private static function jalali_to_gregorian(int $jy, int $jm, int $jd): ?string {
        if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) return null;
        if ($jm > 6 && $jd > 31) return null;

        $jy -= 979;
        $jm -= 1;
        $jd -= 1;

        $j_day_no = 365 * $jy + (int)($jy / 33) * 8 + (int)((($jy % 33) + 3) / 4);
        for ($i = 0; $i < $jm; ++$i) $j_day_no += ($i < 6) ? 31 : 30;
        $j_day_no += $jd;

        $g_day_no = $j_day_no + 79;
        $gy = 1600 + 400 * (int)($g_day_no / 146097);
        $g_day_no %= 146097;

        $leap = true;
        if ($g_day_no >= 36525) {
            $g_day_no--;
            $gy += 100 * (int)($g_day_no / 36524);
            $g_day_no %= 36524;
            if ($g_day_no >= 365) $g_day_no++;
            else $leap = false;
        }

        $gy += 4 * (int)($g_day_no / 1461);
        $g_day_no %= 1461;

        if ($g_day_no >= 366) {
            $leap = false;
            $g_day_no--;
            $gy += (int)($g_day_no / 365);
            $g_day_no %= 365;
        }

        $g_days = [31, ($leap ? 29 : 28), 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm = 0;

        for ($gm = 0; $gm < 12; $gm++) {
            if ($g_day_no < $g_days[$gm]) break;
            $g_day_no -= $g_days[$gm];
        }

        return sprintf('%04d-%02d-%02d', $gy, $gm + 1, $g_day_no + 1);
    }
}