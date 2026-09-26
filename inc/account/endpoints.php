<?php

namespace NextSafar\Account;

use NextSafar\Auth\Session as Session;

/* ==========================================================================
   Create Tables (Self-Healing)
   ========================================================================== */
function ensure_tables(): void {
    global $wpdb;

    static $checked = false;

    if ($checked) return;

    $checked = true;

    $charset = $wpdb->get_charset_collate();

    /* Unique key on ref_id so duplicate webhooks do not charge twice. */
    $has_uq = $wpdb->get_var("SHOW INDEX FROM {$wpdb->prefix}ns_transactions WHERE Key_name = 'uq_ref'");

    if (!$has_uq) {
        $wpdb->query("ALTER TABLE {$wpdb->prefix}ns_transactions ADD UNIQUE KEY uq_ref (ref_id)");
    }

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ns_favorites (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
user_id BIGINT UNSIGNED NOT NULL,
type VARCHAR(20) NOT NULL,
object_id BIGINT UNSIGNED NOT NULL,
created_at DATETIME NOT NULL,
PRIMARY KEY (id),
UNIQUE KEY user_type_obj (user_id, type, object_id)
) {$charset};");

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ns_bookings (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
user_id BIGINT UNSIGNED NOT NULL,
type VARCHAR(20) NOT NULL,
object_id BIGINT UNSIGNED NULL,
title VARCHAR(255) NOT NULL,
status VARCHAR(20) NOT NULL DEFAULT 'pending',
amount DECIMAL(12,2) NULL,
currency VARCHAR(10) DEFAULT 'IRR',
meta LONGTEXT NULL,
created_at DATETIME NOT NULL,
updated_at DATETIME NOT NULL,
PRIMARY KEY (id),
KEY user_status (user_id, status)
) {$charset};");

    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ns_transactions (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
user_id BIGINT UNSIGNED NOT NULL,
type VARCHAR(20) NOT NULL,
amount DECIMAL(12,2) NOT NULL,
balance_after DECIMAL(12,2) NULL,
status VARCHAR(20) NOT NULL DEFAULT 'pending',
ref_id BIGINT UNSIGNED NULL,
description VARCHAR(255) NULL,
created_at DATETIME NOT NULL,
PRIMARY KEY (id),
KEY user_id (user_id)
) {$charset};");
}

add_action('rest_api_init', __NAMESPACE__ . '\\ensure_tables', 1);

/* ==========================================================================
   National ID Validation (Checksum Algorithm)
   ========================================================================== */
function ns_valid_national_id(string $code): bool {
    if (!preg_match('/^\d{10}$/', $code)) return false;
    if (preg_match('/^(\d)\1{9}$/', $code)) return false;

    $sum = 0;

    for ($i = 0; $i < 9; $i++) {
        $sum += ((int) $code[$i]) * (10 - $i);
    }

    $r = $sum % 11;
    $check = (int) $code[9];

    return ($r < 2 && $check === $r) || ($r >= 2 && $check === 11 - $r);
}

/* ==========================================================================
   Jalali Birthdate Validation
   ========================================================================== */
function ns_valid_jalali(string $d): bool {
    return (bool) preg_match('/^1[34]\d{2}\/(0[1-9]|1[0-2])\/(0[1-9]|[12]\d|3[01])$/', $d);
}

/* ==========================================================================
   Current User From Session Header
   ========================================================================== */
function uid(\WP_REST_Request $req): ?int {
    $token = $req->get_header('x-ns-session') ?: '';

    if (!$token) return null;

    $u = Session::validate($token);

    return $u ? (int) $u : null;
}

function err(string $code, int $status = 400): \WP_REST_Response {
    return new \WP_REST_Response(['ok' => false, 'error' => $code], $status);
}

/* ==========================================================================
   Wallet Engine - Contract: amount is always an absolute value.
   The sign comes from type: deposit/refund = +, payment = -.
   ========================================================================== */

/* Unified balance formula across the whole system. */
function ns_wallet_delta_fragment(): string {
    return "CASE WHEN type IN ('deposit','refund') THEN amount
WHEN type = 'payment' THEN -amount
ELSE amount END";
}

/* Current user balance (only successful transactions). */
function ns_wallet_balance(int $uid): float {
    global $wpdb;

    $frag = ns_wallet_delta_fragment();

    return (float) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM($frag), 0)
FROM {$wpdb->prefix}ns_transactions
WHERE user_id = %d AND status = 'success'",
        $uid
    ));
}

/* ==========================================================================
   Secure Transaction Insert
   Lock + live balance + idempotency.
   ========================================================================== */
function ns_wallet_apply(
    int    $uid,
    string $type,              // deposit | payment | refund
    float  $amount,            // Absolute value (positive)
    string $description,
    ?int   $ref_id = null,     // Booking/webhook ID for uniqueness
    string $status = 'success' // success | pending
): array {
    global $wpdb;

    if ($amount <= 0) return ['ok' => false, 'error' => 'invalid_amount'];

    if (!in_array($type, ['deposit', 'payment', 'refund'], true)) {
        return ['ok' => false, 'error' => 'invalid_type'];
    }

    /* Idempotency: duplicate ref_id means re-insert is not allowed. */
    if ($ref_id) {
        $dup = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ns_transactions WHERE ref_id = %d LIMIT 1",
            $ref_id
        ));

        if ($dup) return ['ok' => false, 'error' => 'duplicate', 'id' => (int) $dup];
    }

    $wpdb->query('START TRANSACTION');

    /* Lock the user row to serialize concurrent operations for this user. */
    $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->users} WHERE ID = %d FOR UPDATE",
        $uid
    ));

    $current = ns_wallet_balance($uid);
    $is_plus = in_array($type, ['deposit', 'refund'], true);

    /* Check whether there is enough balance for deduction. */
    if ($status === 'success' && !$is_plus && $current < $amount) {
        $wpdb->query('ROLLBACK');
        return ['ok' => false, 'error' => 'insufficient_balance'];
    }

    $balance_after = $status === 'success'
        ? $current + ($is_plus ? $amount : -$amount)
        : null;

    $wpdb->insert("{$wpdb->prefix}ns_transactions", [
        'user_id'       => $uid,
        'type'          => $type,
        'amount'        => $amount,
        'balance_after' => $balance_after,
        'status'        => $status,
        'ref_id'        => $ref_id,
        'description'   => $description,
        'created_at'    => current_time('mysql'),
    ]);

    $id = (int) $wpdb->insert_id;

    $wpdb->query('COMMIT');

    return ['ok' => true, 'id' => $id, 'balance' => $balance_after];
}

/* ==========================================================================
   Settle Pending Transaction
   Called after payment gateway webhook confirmation.
   ========================================================================== */
function ns_wallet_settle(int $txn_id, bool $success): array {
    global $wpdb;

    $wpdb->query('START TRANSACTION');

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ns_transactions WHERE id = %d FOR UPDATE",
        $txn_id
    ), ARRAY_A);

    if (!$row) {
        $wpdb->query('ROLLBACK');
        return ['ok' => false, 'error' => 'not_found'];
    }

    if ($row['status'] !== 'pending') {
        $wpdb->query('ROLLBACK');
        return ['ok' => false, 'error' => 'already_settled'];
    }

    $uid = (int) $row['user_id'];

    $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->users} WHERE ID = %d FOR UPDATE",
        $uid
    ));

    $balance_after = null;

    if ($success) {
        $current = ns_wallet_balance($uid);
        $amount  = (float) $row['amount'];
        $is_plus = in_array($row['type'], ['deposit', 'refund'], true);

        if (!$is_plus && $current < $amount) {
            $wpdb->query('ROLLBACK');
            return ['ok' => false, 'error' => 'insufficient_balance'];
        }

        $balance_after = $current + ($is_plus ? $amount : -$amount);
    }

    $wpdb->update(
        "{$wpdb->prefix}ns_transactions",
        [
            'status'        => $success ? 'success' : 'failed',
            'balance_after' => $balance_after,
        ],
        ['id' => $txn_id]
    );

    $wpdb->query('COMMIT');

    return ['ok' => true, 'balance' => $balance_after];
}

add_action('rest_api_init', function () {

    /* ======================================================================
       1) Overview
       ====================================================================== */
    register_rest_route('nextsafar/v1', '/account/overview', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function (\WP_REST_Request $req) {
            $uid = uid($req);

            if (!$uid) return err('unauthorized', 401);

            global $wpdb;

            $bookings = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}ns_bookings WHERE user_id=%d", $uid));
            $active   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}ns_bookings WHERE user_id=%d AND status IN ('pending','confirmed')", $uid));
            $favs     = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}ns_favorites WHERE user_id=%d", $uid));
            $balance  = ns_wallet_balance($uid);
            $recent   = $wpdb->get_results($wpdb->prepare("SELECT id,type,title,status,amount,currency,created_at FROM {$wpdb->prefix}ns_bookings WHERE user_id=%d ORDER BY id DESC LIMIT 5", $uid), ARRAY_A);

            return new \WP_REST_Response([
                'ok' => true,
                'bookings' => $bookings,
                'active' => $active,
                'favorites' => $favs,
                'balance' => $balance,
                'recent' => $recent ?: [],
            ], 200);
        },
    ]);

    /* ======================================================================
       2) Bookings
       ====================================================================== */
    register_rest_route('nextsafar/v1', '/account/bookings', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function (\WP_REST_Request $req) {
            $uid = uid($req);

            if (!$uid) return err('unauthorized', 401);

            global $wpdb;

            $type = sanitize_text_field($req->get_param('type') ?? '');

            $sql = "SELECT * FROM {$wpdb->prefix}ns_bookings WHERE user_id=%d";
            $params = [$uid];

            if ($type) {
                $sql .= " AND type=%s";
                $params[] = $type;
            }

            $sql .= " ORDER BY id DESC LIMIT 100";

            $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

            foreach ($rows as &$r) {
                $r['meta'] = $r['meta'] ? json_decode($r['meta'], true) : [];
            }

            return new \WP_REST_Response(['ok' => true, 'items' => $rows ?: []], 200);
        },
    ]);

    /* ======================================================================
       3) Favorites (GET / POST / DELETE)
       ====================================================================== */
    register_rest_route('nextsafar/v1', '/account/favorites', [
        [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => function (\WP_REST_Request $req) {
                $uid = uid($req);

                if (!$uid) return err('unauthorized', 401);

                global $wpdb;

                $type = sanitize_text_field($req->get_param('type') ?? '');

                $sql = "SELECT * FROM {$wpdb->prefix}ns_favorites WHERE user_id=%d";
                $params = [$uid];

                if ($type) {
                    $sql .= " AND type=%s";
                    $params[] = $type;
                }

                $sql .= " ORDER BY id DESC";

                $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
                $items = [];

                foreach ($rows as $r) {
                    $post = get_post((int) $r['object_id']);

                    if (!$post) continue;

                    $items[] = [
                        'id' => (int) $r['id'],
                        'type' => $r['type'],
                        'object_id' => (int) $r['object_id'],
                        'title' => get_the_title($post),
                        'url' => get_permalink($post),
                        'image' => get_the_post_thumbnail_url($post, 'medium') ?: null,
                        'created_at' => $r['created_at'],
                    ];
                }

                return new \WP_REST_Response(['ok' => true, 'items' => $items], 200);
            },
        ],
        [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => function (\WP_REST_Request $req) {
                $uid = uid($req);

                if (!$uid) return err('unauthorized', 401);

                $type = sanitize_text_field($req->get_param('type') ?? '');
                $obj  = (int) $req->get_param('object_id');

                $allowed = ['hotel', 'tour', 'visa', 'destination', 'travelguide', 'travelnews'];

                if (!in_array($type, $allowed, true) || !$obj || !get_post($obj)) {
                    return err('invalid_item');
                }

                global $wpdb;

                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->prefix}ns_favorites (user_id,type,object_id,created_at) VALUES (%d,%s,%d,%s)",
                    $uid,
                    $type,
                    $obj,
                    current_time('mysql')
                ));

                return new \WP_REST_Response(['ok' => true], 200);
            },
        ],
        [
            'methods' => 'DELETE',
            'permission_callback' => '__return_true',
            'callback' => function (\WP_REST_Request $req) {
                $uid = uid($req);

                if (!$uid) return err('unauthorized', 401);

                $type = sanitize_text_field($req->get_param('type') ?? '');
                $obj  = (int) $req->get_param('object_id');

                global $wpdb;

                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}ns_favorites WHERE user_id=%d AND type=%s AND object_id=%d",
                    $uid,
                    $type,
                    $obj
                ));

                return new \WP_REST_Response(['ok' => true], 200);
            },
        ],
    ]);

    /* ======================================================================
       4) Transactions
       ====================================================================== */
    register_rest_route('nextsafar/v1', '/account/transactions', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function (\WP_REST_Request $req) {
            $uid = uid($req);

            if (!$uid) return err('unauthorized', 401);

            global $wpdb;

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ns_transactions WHERE user_id=%d ORDER BY id DESC LIMIT 100",
                $uid
            ), ARRAY_A);

            return new \WP_REST_Response(['ok' => true, 'items' => $rows ?: []], 200);
        },
    ]);

    /* ======================================================================
       5) Profile (GET / POST)
       ====================================================================== */
    register_rest_route('nextsafar/v1', '/account/profile', [
        [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => function (\WP_REST_Request $req) {
                $uid = uid($req);

                if (!$uid) return err('unauthorized', 401);

                return new \WP_REST_Response(['ok' => true, 'profile' => ns_account_profile($uid)], 200);
            },
        ],
        [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => function (\WP_REST_Request $req) {
                $uid = uid($req);

                if (!$uid) return err('unauthorized', 401);

                $name      = sanitize_text_field($req->get_param('display_name') ?? '');
                $email     = sanitize_email($req->get_param('email') ?? '');
                $first     = sanitize_text_field($req->get_param('first_name') ?? '');
                $last      = sanitize_text_field($req->get_param('last_name') ?? '');
                $national  = sanitize_text_field($req->get_param('national_id') ?? '');
                $birthdate = sanitize_text_field($req->get_param('birthdate') ?? '');

                /* Validate national ID and birthdate. */
                if ($national && !ns_valid_national_id($national)) return err('invalid_national_id');
                if ($birthdate && !ns_valid_jalali($birthdate)) return err('invalid_birthdate');

                /* Minimum age: 18 years. */
                if ($birthdate && !ns_birthdate_min_age($birthdate, 18)) return err('underage');

                $data = [];

                if ($name)  $data['display_name'] = $name;
                if ($first) $data['first_name'] = $first; // Native WordPress field.
                if ($last)  $data['last_name'] = $last;   // Native WordPress field.

                if ($email) {
                    $cur = get_userdata($uid);

                    if ($email !== $cur->user_email) {
                        if (email_exists($email)) return err('email_taken');

                        $data['user_email'] = $email;

                        update_user_meta($uid, 'ns_email_verified', '');
                    }
                }

                if ($data) {
                    $data['ID'] = $uid;

                    $res = wp_update_user($data);

                    if (is_wp_error($res)) return err('update_failed', 500);
                }

                /* Custom meta fields. */
                update_user_meta($uid, 'ns_national_id', $national);
                update_user_meta($uid, 'ns_birthdate', $birthdate);

                return new \WP_REST_Response(['ok' => true, 'profile' => ns_account_profile($uid)], 200);
            },
        ],
    ]);

    /* ======================================================================
       6) Avatar Upload
       ====================================================================== */
    register_rest_route('nextsafar/v1', '/account/avatar', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (\WP_REST_Request $req) {
            $uid = uid($req);

            if (!$uid) return err('unauthorized', 401);

            $files = $req->get_file_params();
            $file = $files['avatar'] ?? null;

            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                return err('no_file');
            }

            /* Limit: 2MB + jpg/png/webp only. */
            if ($file['size'] > 2 * 1024 * 1024) return err('file_too_large');

            $check = wp_check_filetype($file['name']);

            if (!in_array($check['ext'] ?? '', ['jpg', 'jpeg', 'png', 'webp'], true)) {
                return err('invalid_type');
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $uploaded = wp_handle_upload($file, ['test_form' => false]);

            if (!empty($uploaded['error'])) return err('upload_failed', 500);

            $attach_id = wp_insert_attachment([
                'post_mime_type' => $uploaded['type'],
                'post_title'     => 'avatar-' . $uid,
                'post_status'    => 'inherit',
            ], $uploaded['file']);

            if (is_wp_error($attach_id)) return err('upload_failed', 500);

            wp_update_attachment_metadata(
                $attach_id,
                wp_generate_attachment_metadata($attach_id, $uploaded['file'])
            );

            /* Delete previous avatar. */
            $old = (int) get_user_meta($uid, 'ns_avatar_id', true);

            if ($old) wp_delete_attachment($old, true);

            $url = wp_get_attachment_url($attach_id);

            update_user_meta($uid, 'ns_avatar_id', $attach_id);
            update_user_meta($uid, 'ns_avatar', $url);

            return new \WP_REST_Response(['ok' => true, 'avatar' => $url], 200);
        },
    ]);

    /* ======================================================================
       7) Sessions (GET + Revoke)
       ====================================================================== */
    register_rest_route('nextsafar/v1', '/account/sessions', [
        [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => function (\WP_REST_Request $req) {
                $uid = uid($req);

                if (!$uid) return err('unauthorized', 401);

                global $wpdb;

                $current = hash('sha256', $req->get_header('x-ns-session') ?: '');

                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, ip, user_agent, created_at, last_seen, token_hash
FROM {$wpdb->prefix}ns_sessions WHERE user_id=%d
ORDER BY last_seen DESC LIMIT 20",
                    $uid
                ), ARRAY_A);

                $items = [];

                foreach ($rows as $r) {
                    $ua = $r['user_agent'] ?? '';

                    $device = stripos($ua, 'Mobile') !== false ? 'موبایل' : 'دسکتاپ';
                    $browser = preg_match('/(Chrome|Firefox|Safari|Edge)/i', $ua, $m) ? $m[1] : 'مرورگر';

                    $items[] = [
                        'id'         => (int) $r['id'],
                        'ip'         => $r['ip'],
                        'device'     => $device . ' — ' . $browser,
                        'created_at' => $r['created_at'],
                        'last_seen'  => $r['last_seen'],
                        'current'    => hash_equals($current, $r['token_hash']),
                    ];
                }

                return new \WP_REST_Response(['ok' => true, 'items' => $items], 200);
            },
        ],
        [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => function (\WP_REST_Request $req) {
                $uid = uid($req);

                if (!$uid) return err('unauthorized', 401);

                global $wpdb;

                $all = (bool) $req->get_param('all');
                $current = hash('sha256', $req->get_header('x-ns-session') ?: '');

                if ($all) {
                    /* Remove all sessions except the current one. */
                    $wpdb->query($wpdb->prepare(
                        "DELETE FROM {$wpdb->prefix}ns_sessions WHERE user_id=%d AND token_hash != %s",
                        $uid,
                        $current
                    ));
                } else {
                    $id = (int) $req->get_param('id');

                    $wpdb->query($wpdb->prepare(
                        "DELETE FROM {$wpdb->prefix}ns_sessions WHERE id=%d AND user_id=%d AND token_hash != %s",
                        $id,
                        $uid,
                        $current
                    ));
                }

                return new \WP_REST_Response(['ok' => true], 200);
            },
        ],
    ]);

    /* ======================================================================
       8) Link Phone Number To Account (With OTP Verification)
       ====================================================================== */
    register_rest_route('nextsafar/v1', '/account/phone', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (\WP_REST_Request $req) {
            $uid = uid($req);

            if (!$uid) return err('unauthorized', 401);

            $phone = sanitize_text_field($req->get_param('phone') ?? '');
            $code  = (string) $req->get_param('code');

            if (!preg_match('/^09\d{9}$/', $phone)) return err('invalid_phone');

            $check = \NextSafar\Auth\OTP\verify($phone, $code);

            if (!$check['ok']) {
                return err($check['reason'] === 'max_attempts' ? 'max_attempts' : 'invalid_code', 400);
            }

            global $wpdb;

            $other = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='ns_phone' AND meta_value=%s AND user_id != %d LIMIT 1",
                $phone,
                $uid
            ));

            if ($other) return err('phone_taken');

            update_user_meta($uid, 'ns_phone', $phone);
            update_user_meta($uid, 'ns_phone_verified', current_time('mysql'));

            return new \WP_REST_Response(['ok' => true, 'profile' => ns_account_profile($uid)], 200);
        },
    ]);
});

/* ==========================================================================
   Extended Profile
   ========================================================================== */
function ns_account_profile(int $uid): array {
    $user = get_userdata($uid);

    $phone = get_user_meta($uid, 'ns_phone', true);

    $has_real_email = $user->user_email && !str_contains($user->user_email, '@phone.local.');

    $avatar = get_user_meta($uid, 'ns_avatar', true)
        ?: get_user_meta($uid, 'ns_google_avatar', true);

    return [
        'id' => $uid,
        'display_name' => $user->display_name,
        'first_name' => get_user_meta($uid, 'first_name', true) ?: '',
        'last_name' => get_user_meta($uid, 'last_name', true) ?: '',
        'national_id' => get_user_meta($uid, 'ns_national_id', true) ?: '',
        'birthdate' => get_user_meta($uid, 'ns_birthdate', true) ?: '',
        'phone' => $phone ?: null,
        'phone_verified' => (bool) get_user_meta($uid, 'ns_phone_verified', true),
        'email' => $has_real_email ? $user->user_email : null,
        'email_verified' => (bool) get_user_meta($uid, 'ns_email_verified', true),
        'signup_method' => get_user_meta($uid, 'ns_signup_method', true) ?: 'unknown',
        'google_linked' => get_user_meta($uid, 'ns_google_linked', true) ?: null,
        'avatar' => $avatar ?: get_avatar_url($uid, ['size' => 96]),
    ];
}

/* ==========================================================================
   Today's Jalali Date
   ========================================================================== */
function ns_today_jalali(): array {
    if (class_exists('IntlCalendar')) {
        $cal = \IntlCalendar::createInstance(null, 'fa_IR@calendar=persian');

        return [
            (int) $cal->get(\IntlCalendar::FIELD_YEAR),
            (int) $cal->get(\IntlCalendar::FIELD_MONTH) + 1,
            (int) $cal->get(\IntlCalendar::FIELD_DAY_OF_MONTH),
        ];
    }

    /* Fallback without intl: count days from anchor 1404-01-01 = 2025-03-21. */
    $leap = function (int $y): bool {
        return in_array((($y + 12) % 33), [1, 5, 9, 13, 17, 22, 26, 30], true);
    };

    $yearLen = fn(int $y): int => $leap($y) ? 366 : 365;

    $mlen = function (int $y, int $m): int {
        if ($m <= 6) return 31;
        if ($m <= 11) return 30;
        return $leap($y) ? 30 : 29;
    };

    $anchor = gmmktime(0, 0, 0, 3, 21, 2025);
    $days = (int) floor((time() - $anchor) / 86400);

    $jy = 1404;

    while ($days >= $yearLen($jy)) {
        $days -= $yearLen($jy);
        $jy++;
    }

    while ($days < 0) {
        $jy--;
        $days += $yearLen($jy);
    }

    $jm = 1;

    while ($jm <= 12 && $days >= $mlen($jy, $jm)) {
        $days -= $mlen($jy, $jm);
        $jm++;
    }

    return [$jy, $jm, $days + 1];
}

/* ==========================================================================
   Minimum Age Check - Direct Jalali Comparison (Without Gregorian Conversion)
   ========================================================================== */
function ns_birthdate_min_age(string $d, int $min): bool {
    [$jy, $jm, $jd] = array_map('intval', explode('/', $d));
    [$ty, $tm, $td] = ns_today_jalali();

    $cy = $ty - $min;

    if ($jy !== $cy) return $jy < $cy;
    if ($jm !== $tm) return $jm < $tm;

    return $jd <= $td;
}