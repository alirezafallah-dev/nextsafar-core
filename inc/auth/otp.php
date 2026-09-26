<?php

namespace NextSafar\Auth\OTP;

const OTP_LENGTH = 6;
const OTP_TTL_MINUTES = 5;
const OTP_MAX_ATTEMPTS = 5;

/* ========================================================================
   Generate 6-digit code
   ======================================================================== */
function generate(): string {
    return str_pad((string) mt_rand(0, 999999), OTP_LENGTH, '0', STR_PAD_LEFT);
}

/* ========================================================================
   Store (hashed)
   ======================================================================== */
function store(string $phone, string $code, string $ip, string $purpose = 'login'): void {
    global $wpdb;

    $table = $wpdb->prefix . 'ns_otp_codes';

    $wpdb->insert($table, [
        'phone'      => $phone,
        'code_hash'  => hash('sha256', $code),
        'purpose'    => $purpose,
        'attempts'   => 0,
        'ip'         => $ip,
        'created_at' => current_time('mysql'),
        'expires_at' => date('Y-m-d H:i:s', strtotime('+' . OTP_TTL_MINUTES . ' minutes')),
    ], ['%s','%s','%s','%d','%s','%s','%s']);
}

/* ========================================================================
   Verify code
   ======================================================================== */
function verify(string $phone, string $code): array {
    global $wpdb;

    $table = $wpdb->prefix . 'ns_otp_codes';
    $now = current_time('mysql');
    $hash = hash('sha256', $code);

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE phone = %s AND code_hash = %s AND expires_at > %s ORDER BY id DESC LIMIT 1",
        $phone, $hash, $now
    ));

    if (!$row) {
        return ['ok' => false, 'reason' => 'invalid_or_expired'];
    }

    if ((int) $row->attempts >= OTP_MAX_ATTEMPTS) {
        $wpdb->delete($table, ['id' => $row->id]);

        return ['ok' => false, 'reason' => 'max_attempts'];
    }

    /* Increment attempts */
    $wpdb->update($table, ['attempts' => $row->attempts + 1], ['id' => $row->id]);

    /* Delete after success */
    $wpdb->delete($table, ['id' => $row->id]);

    return [
        'ok'      => true,
        'purpose' => $row->purpose,
        'phone'   => $row->phone,
    ];
}

/* ========================================================================
   Count active codes for a phone number (for rate limiting)
   ======================================================================== */
function active_count(string $phone, string $ip): int {
    global $wpdb;

    $table = $wpdb->prefix . 'ns_otp_codes';
    $ten_min_ago = date('Y-m-d H:i:s', strtotime('-10 minutes'));

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE (phone = %s OR ip = %s) AND created_at > %s",
        $phone, $ip, $ten_min_ago
    ));
}