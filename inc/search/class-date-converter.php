<?php
/**
 * NextSafar Core - Date Converter Service
 * 
 * Handles Jalali (Persian) to Gregorian date conversion and vice versa.
 * 
 * @package NextSafar\Search
 * @since   2.6.0
 */

namespace NextSafar\Search;

if (!defined('ABSPATH')) exit;

class DateConverter {
    
    /**
     * Convert Jalali date string to Gregorian
     * 
     * Supports formats:
     * - "1405/08/05"
     * - "1405-08-05"
     * - "1405/8/5"
     * 
     * @param string $jalali Jalali date string
     * @return string|null Gregorian date (YYYY-MM-DD) or null on failure
     */
    public static function jalali_to_gregorian(string $jalali): ?string {
        // Already Gregorian?
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $jalali)) {
            return $jalali;
        }
        
        // Parse Jalali format
        if (!preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $jalali, $m)) {
            return null;
        }
        
        $jy = (int) $m[1];
        $jm = (int) $m[2];
        $jd = (int) $m[3];
        
        // Basic validation
        if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) {
            return null;
        }
        
        return self::jalali_to_gregorian_parts($jy, $jm, $jd);
    }
    
    /**
     * Convert Jalali parts to Gregorian
     * 
     * @param int $jy Jalali year
     * @param int $jm Jalali month
     * @param int $jd Jalali day
     * @return string|null Gregorian date (YYYY-MM-DD) or null
     */
    public static function jalali_to_gregorian_parts(int $jy, int $jm, int $jd): ?string {
        if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) return null;
        
        $jy -= 979;
        $jm -= 1;
        $jd -= 1;
        
        $j_day_no = 365 * $jy + (int)($jy / 33) * 8 + (int)((($jy % 33) + 3) / 4);
        for ($i = 0; $i < $jm; ++$i) {
            $j_day_no += ($i < 6) ? 31 : 30;
        }
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
    
    /**
     * Convert Gregorian date to Jalali
     * 
     * @param string $gregorian Gregorian date (YYYY-MM-DD)
     * @return string|null Jalali date (YYYY/MM/DD) or null
     */
    public static function gregorian_to_jalali(string $gregorian): ?string {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $gregorian, $m)) {
            return null;
        }
        
        $gy = (int) $m[1];
        $gm = (int) $m[2];
        $gd = (int) $m[3];
        
        return self::gregorian_to_jalali_parts($gy, $gm, $gd);
    }
    
    /**
     * Convert Gregorian parts to Jalali
     * 
     * @param int $gy Gregorian year
     * @param int $gm Gregorian month
     * @param int $gd Gregorian day
     * @return string|null Jalali date (YYYY/MM/DD) or null
     */
    public static function gregorian_to_jalali_parts(int $gy, int $gm, int $gd): ?string {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + ((int)(($gy2 + 3) / 4)) 
                - ((int)(($gy2 + 99) / 100)) + ((int)(($gy2 + 399) / 400)) 
                + $gd + $g_d_m[$gm - 1];
        
        $jy = -1595 + (33 * ((int)($days / 12053)));
        $days %= 12053;
        
        $jy += 4 * ((int)($days / 1461));
        $days %= 1461;
        
        if ($days > 365) {
            $jy += (int)(($days - 1) / 365);
            $days = ($days - 1) % 365;
        }
        
        if ($days < 186) {
            $jm = 1 + (int)($days / 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + (int)(($days - 186) / 30);
            $jd = 1 + (($days - 186) % 30);
        }
        
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }
    
    /**
     * Validate a date string (Jalali or Gregorian)
     * 
     * @param string $date Date string
     * @return bool
     */
    public static function is_valid(string $date): bool {
        // Jalali
        if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $date, $m)) {
            $jy = (int) $m[1];
            $jm = (int) $m[2];
            $jd = (int) $m[3];
            
            if ($jy < 1300 || $jy > 1500) return false;
            if ($jm < 1 || $jm > 12) return false;
            if ($jd < 1 || $jd > 31) return false;
            if ($jm > 6 && $jd > 31) return false;
            if ($jm > 11 && $jd > 30) return false;
            
            return true;
        }
        
        // Gregorian
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
        }
        
        return false;
    }
    
    /**
     * Calculate number of nights between two dates
     * 
     * @param string $check_in Check-in date (Jalali or Gregorian)
     * @param string $check_out Check-out date (Jalali or Gregorian)
     * @return int Number of nights (minimum 1)
     */
    public static function calculate_nights(string $check_in, string $check_out): int {
        $in_greg = self::jalali_to_gregorian($check_in) ?? $check_in;
        $out_greg = self::jalali_to_gregorian($check_out) ?? $check_out;
        
        try {
            $date1 = new \DateTime($in_greg);
            $date2 = new \DateTime($out_greg);
            $diff = $date1->diff($date2);
            return max(1, $diff->days);
        } catch (\Exception $e) {
            return 1;
        }
    }
    
    /**
     * Add days to a date
     * 
     * @param string $date Date string
     * @param int $days Number of days to add
     * @return string|null New date or null on failure
     */
    public static function add_days(string $date, int $days): ?string {
        $greg = self::jalali_to_gregorian($date) ?? $date;
        
        try {
            $dt = new \DateTime($greg);
            $dt->modify("+{$days} days");
            return $dt->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }
}