<?php
/**
 * NextSafar Core - Logger
 * سیستم لاگینگ حرفه‌ای با دسته‌بندی و ذخیره در دیتابیس
 *
 * @package NextSafar\Core
 * @since   2.6.0
 */

namespace NextSafar\Core;

if (!defined('ABSPATH')) exit;

class Logger {

    /**
     * سطوح لاگ
     */
    const LEVEL_DEBUG   = 'debug';
    const LEVEL_INFO    = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR   = 'error';
    const LEVEL_CRITICAL = 'critical';

    /**
     * نام جدول سفارشی لاگ‌ها
     */
    private static $table_name = 'ns_api_logs';

    /**
     * آیا لاگ در دیتابیس ذخیره شود؟
     */
    private static $db_logging_enabled = true;

    /**
     * آیا لاگ در فایل ذخیره شود؟
     */
    private static $file_logging_enabled = true;

    /**
     * حداکثر تعداد لاگ‌ها در دیتابیس (برای جلوگیری از رشد بی‌رویه)
     */
    private static $max_log_entries = 10000;

    /**
     * لاگ کردن پیام
     *
     * @param string $level   سطح لاگ
     * @param string $message متن پیام
     * @param array  $context داده‌های اضافی
     * @return void
     */
    public static function log(string $level, string $message, array $context = []): void {
        // لاگ در فایل
        if (self::$file_logging_enabled) {
            self::log_to_file($level, $message, $context);
        }

        // لاگ در دیتابیس (فقط برای warning و بالاتر)
        if (self::$db_logging_enabled && in_array($level, [self::LEVEL_WARNING, self::LEVEL_ERROR, self::LEVEL_CRITICAL])) {
            self::log_to_database($level, $message, $context);
        }

        // لاگ در error_log وردپرس (فقط برای خطاها)
        if (in_array($level, [self::LEVEL_ERROR, self::LEVEL_CRITICAL])) {
            error_log("[NextSafar][{$level}] {$message} | " . wp_json_encode($context));
        }
    }

    /**
     * لاگ Debug
     */
    public static function debug(string $message, array $context = []): void {
        self::log(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * لاگ Info
     */
    public static function info(string $message, array $context = []): void {
        self::log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * لاگ Warning
     */
    public static function warning(string $message, array $context = []): void {
        self::log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * لاگ Error
     */
    public static function error(string $message, array $context = []): void {
        self::log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * لاگ Critical
     */
    public static function critical(string $message, array $context = []): void {
        self::log(self::LEVEL_CRITICAL, $message, $context);
    }

    /**
     * لاگ در فایل
     */
    private static function log_to_file(string $level, string $message, array $context = []): void {
        $upload_dir = wp_upload_dir();
        $log_dir    = $upload_dir['basedir'] . '/nextsafar-logs/';

        // ساخت پوشه لاگ‌ها
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            // محافظت از دسترسی مستقیم
            file_put_contents($log_dir . '.htaccess', 'Deny from all');
            file_put_contents($log_dir . 'index.php', '<?php // Silence is golden');
        }

        $log_file = $log_dir . 'nextsafar-' . gmdate('Y-m-d') . '.log';
        $timestamp = current_time('Y-m-d H:i:s');
        $context_str = !empty($context) ? ' | ' . wp_json_encode($context) : '';

        $log_line = "[{$timestamp}] [{$level}] {$message}{$context_str}" . PHP_EOL;

        file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);
    }

    /**
     * لاگ در دیتابیس
     */
    private static function log_to_database(string $level, string $message, array $context = []): void {
        global $wpdb;

        $table = $wpdb->prefix . self::$table_name;

        // بررسی وجود جدول
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            self::create_log_table();
        }

        $wpdb->insert($table, [
            'level'      => $level,
            'message'    => mb_substr($message, 0, 1000),
            'context'    => wp_json_encode($context),
            'user_id'    => get_current_user_id() ?: null,
            'ip_address' => self::get_client_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '',
            'created_at' => current_time('mysql'),
        ], ['%s', '%s', '%s', '%d', '%s', '%s', '%s']);

        // پاکسازی لاگ‌های قدیمی هر 100 لاگ یکبار
        static $cleanup_counter = 0;
        $cleanup_counter++;
        if ($cleanup_counter >= 100) {
            self::cleanup_old_logs();
            $cleanup_counter = 0;
        }
    }

    /**
     * ساخت جدول لاگ‌ها
     */
    public static function create_log_table(): void {
        global $wpdb;

        $table   = $wpdb->prefix . self::$table_name;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            level VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            context LONGTEXT NULL,
            user_id BIGINT UNSIGNED NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_level (level),
            KEY idx_created_at (created_at),
            KEY idx_user_id (user_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * پاکسازی لاگ‌های قدیمی
     */
    public static function cleanup_old_logs(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::$table_name;

        // حذف لاگ‌های قدیمی‌تر از 30 روز
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        ));

        // اگر هنوز بیشتر از حد مجاز است، قدیمی‌ترین‌ها را حذف کن
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE id NOT IN (
                SELECT id FROM (
                    SELECT id FROM {$table} ORDER BY created_at DESC LIMIT %d
                ) as t
            )",
            self::$max_log_entries
        ));
    }

    /**
     * دریافت لاگ‌ها (برای نمایش در ادمین)
     *
     * @param int    $page     شماره صفحه
     * @param int    $per_page تعداد در هر صفحه
     * @param string $level    فیلتر بر اساس سطح
     * @return array
     */
    public static function get_logs(int $page = 1, int $per_page = 50, string $level = ''): array {
        global $wpdb;
        $table = $wpdb->prefix . self::$table_name;

        $where = '1=1';
        $params = [];

        if ($level !== '') {
            $where .= ' AND level = %s';
            $params[] = $level;
        }

        $offset = ($page - 1) * $per_page;

        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        $results = $wpdb->get_results($wpdb->prepare($sql, $params));

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $count_params = array_slice($params, 0, count($params) - 2);
        $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $count_params));

        return [
            'logs'     => $results,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * دریافت IP کاربر
     */
    public static function get_client_ip(): string {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * پاک کردن تمام لاگ‌ها
     */
    public static function clear_all_logs(): int {
        global $wpdb;
        $table = $wpdb->prefix . self::$table_name;
        return (int) $wpdb->query("TRUNCATE TABLE {$table}");
    }
}