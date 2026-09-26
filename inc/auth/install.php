<?php

namespace NextSafar\Auth;

/* ========================================================================
   Main function to create tables
   This function can be called anytime — safe (CREATE IF NOT EXISTS)
   ======================================================================== */
function ensure_tables(): void {
    global $wpdb;

    static $checked = false;

    if ($checked) return;

    $checked = true;

    $charset = $wpdb->get_charset_collate();

    /* OTP codes table */
    $otp_table = $wpdb->prefix . 'ns_otp_codes';

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$otp_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        phone VARCHAR(20) NOT NULL,
        code_hash CHAR(64) NOT NULL,
        purpose ENUM('login','register') NOT NULL DEFAULT 'login',
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        ip VARCHAR(45) NOT NULL,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY phone_expires (phone, expires_at)
    ) {$charset};");

    /* Sessions table */
    $sess_table = $wpdb->prefix . 'ns_sessions';

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$sess_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        token_hash CHAR(64) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        ip VARCHAR(45) NOT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY token_hash (token_hash),
        KEY user_id (user_id),
        KEY expires_at (expires_at)
    ) {$charset};");

    /* Pending users table */
    $pending_table = $wpdb->prefix . 'ns_pending_users';

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$pending_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        phone VARCHAR(20) NOT NULL UNIQUE,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id)
    ) {$charset};");
}

/* ========================================================================
   Standard activation hook
   ======================================================================== */
add_action('activate_nextsafar-core/nextsafar-core.php', __NAMESPACE__ . '\\ensure_tables');

/* ========================================================================
   ✅ Self-healing mechanism: check on first REST API request
   This hook runs only once per request
   ======================================================================== */
add_action('rest_api_init', __NAMESPACE__ . '\\ensure_tables', 1);

/* ========================================================================
   Daily cleanup
   ======================================================================== */
if (!wp_next_scheduled('ns_cleanup_otp')) {
    wp_schedule_event(time(), 'daily', 'ns_cleanup_otp');
}

add_action('ns_cleanup_otp', function () {
    global $wpdb;

    $now = current_time('mysql');

    /* Check if tables exist */
    $otp_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}ns_otp_codes'");
    $sess_exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}ns_sessions'");

    if ($otp_exists) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ns_otp_codes WHERE expires_at < %s",
            $now
        ));
    }

    if ($sess_exists) {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ns_sessions WHERE expires_at < %s",
            $now
        ));
    }
});

/* ========================================================================
   Add custom columns to users list
   ======================================================================== */
add_filter('manage_users_columns', function ($columns) {
    $columns['ns_phone'] = 'موبایل';
    $columns['ns_fullname'] = 'نام و نام خانوادگی';
    $columns['ns_national_id'] = 'کد ملی';
    $columns['ns_birthdate'] = 'تاریخ تولد';

    return $columns;
});

add_filter('manage_users_custom_column', function ($val, $column, $user_id) {
    switch ($column) {
        case 'ns_phone':
            return esc_html(get_user_meta($user_id, 'ns_phone', true) ?: '—');

        case 'ns_fullname':
            $f = get_user_meta($user_id, 'first_name', true);
            $l = get_user_meta($user_id, 'last_name', true);

            return esc_html(trim($f . ' ' . $l) ?: '—');

        case 'ns_national_id':
            return esc_html(get_user_meta($user_id, 'ns_national_id', true) ?: '—');

        case 'ns_birthdate':
            return esc_html(get_user_meta($user_id, 'ns_birthdate', true) ?: '—');
    }

    return $val;
}, 10, 3);