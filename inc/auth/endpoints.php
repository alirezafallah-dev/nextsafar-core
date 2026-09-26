<?php

namespace NextSafar\Auth\Endpoints;

use NextSafar\Auth\OTP as OTP;
use NextSafar\Auth\Session as Session;
use NextSafar\Auth\Kavenegar as Kavenegar;

add_action('rest_api_init', function () {

    /* ========================================================================
       1. send-otp
       ======================================================================== */
    register_rest_route('nextsafar/v1', '/auth/send-otp', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (\WP_REST_Request $req) {
            $phone = sanitize_text_field($req->get_param('phone'));
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

            if (!preg_match('/^09\d{9}$/', $phone)) {
                return new \WP_REST_Response([
                    'ok' => false,
                    'error' => 'invalid_phone',
                ], 400);
            }

            $recent = OTP\active_count($phone, $ip);

            if ($recent >= 3) {
                return new \WP_REST_Response([
                    'ok' => false,
                    'error' => 'rate_limit',
                    'retry_after' => 600,
                ], 429);
            }

            $code = OTP\generate();
            OTP\store($phone, $code, $ip, 'login');

            $sent = Kavenegar\send_otp($phone, $code);

            if (!$sent['ok']) {
                return new \WP_REST_Response([
                    'ok' => false,
                    'error' => 'sms_failed',
                    'detail' => $sent['error'],
                ], 500);
            }

            return new \WP_REST_Response([
                'ok' => true,
                'expires_in' => OTP\OTP_TTL_MINUTES * 60,
            ], 200);
        },
    ]);

    /* ========================================================================
       2. verify-otp
       ======================================================================== */
    register_rest_route('nextsafar/v1', '/auth/verify-otp', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (\WP_REST_Request $req) {
            $phone = sanitize_text_field($req->get_param('phone'));
            $code = (string) $req->get_param('code');
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

            $check = OTP\verify($phone, $code);

            if (!$check['ok']) {
                return new \WP_REST_Response([
                    'ok' => false,
                    'error' => $check['reason'],
                ], 400);
            }

            $user_id = find_or_create_user_by_phone($phone);

            if (!$user_id) {
                return new \WP_REST_Response([
                    'ok' => false,
                    'error' => 'user_creation_failed',
                ], 500);
            }

            $token = Session\create($user_id, $ip, $_SERVER['HTTP_USER_AGENT'] ?? null);

            return new \WP_REST_Response([
                'ok' => true,
                'session_token' => $token,
                'user' => get_user_payload($user_id),
            ], 200);
        },
    ]);

    /* ========================================================================
       3. me
       ======================================================================== */
    register_rest_route('nextsafar/v1', '/auth/me', [
        'methods' => 'GET',
        'permission_callback' => function ($req) {
            $token = $req->get_header('x-ns-session') ?: '';

            return (bool) Session\validate($token);
        },
        'callback' => function (\WP_REST_Request $req) {
            $token = $req->get_header('x-ns-session');
            $user_id = Session\validate($token);

            if (!$user_id) {
                return new \WP_REST_Response(['ok' => false, 'error' => 'unauthorized'], 401);
            }

            return new \WP_REST_Response([
                'ok' => true,
                'user' => get_user_payload($user_id),
            ], 200);
        },
    ]);

    /* ========================================================================
       4. logout
       ======================================================================== */
    register_rest_route('nextsafar/v1', '/auth/logout', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (\WP_REST_Request $req) {
            $token = $req->get_header('x-ns-session');

            if ($token) {
                Session\destroy($token);
            }

            return new \WP_REST_Response(['ok' => true], 200);
        },
    ]);

    /* ========================================================================
       5. resolve-google-user (only from Next.js with secret)
       ✅ FIX: Now registered inside rest_api_init
       ======================================================================== */
    register_rest_route('nextsafar/v1', '/auth/google/resolve-user', [
        'methods' => 'POST',
        'permission_callback' => function () {
            return \NextSafar\Auth\Google\validate_internal_request();
        },
        'callback' => function (\WP_REST_Request $req) {
            $email  = sanitize_email($req->get_param('email'));
            $name   = sanitize_text_field($req->get_param('name') ?? '');
            $avatar = filter_var($req->get_param('avatar') ?? '', FILTER_VALIDATE_URL) ?: null;
            $ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

            if (!$email || !is_email($email)) {
                return new \WP_REST_Response([
                    'ok' => false,
                    'error' => 'invalid_email'
                ], 400);
            }

            $resolved = \NextSafar\Auth\Google\resolve_user($email, $name, $avatar);

            if (isset($resolved['error'])) {
                return new \WP_REST_Response([
                    'ok' => false,
                    'error' => $resolved['error'],
                    'detail' => $resolved['detail'] ?? null,
                ], 500);
            }

            $token = Session\create(
                $resolved['user_id'],
                $ip,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            );

            return new \WP_REST_Response([
                'ok'            => true,
                'session_token' => $token,
                'is_new'        => $resolved['is_new'],
                'user'          => get_user_payload($resolved['user_id']),
            ], 200);
        },
    ]);
});

/* ========================================================================
   Find or create user by phone number
   ======================================================================== */
function find_or_create_user_by_phone(string $phone): ?int {
    global $wpdb;

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'ns_phone' AND meta_value = %s LIMIT 1",
        $phone
    ));

    if ($existing) {
        return (int) $existing;
    }

    $username = 'u_' . substr(md5($phone), 0, 10);
    $temp_email = $phone . '@phone.local.' . parse_url(home_url(), PHP_URL_HOST);

    if (username_exists($username)) {
        $username .= '_' . wp_generate_password(4, false);
    }

    if (email_exists($temp_email)) {
        $temp_email = $phone . '_' . wp_generate_password(4, false) . '@phone.local.' . parse_url(home_url(), PHP_URL_HOST);
    }

    $user_id = wp_insert_user([
        'user_login' => $username,
        'user_email' => $temp_email,
        'user_pass' => wp_generate_password(24, true, true),
        'display_name' => 'کاربر ' . substr($phone, -4),
        'role' => 'subscriber',
    ]);

    if (is_wp_error($user_id)) return null;

    update_user_meta($user_id, 'ns_phone', $phone);
    update_user_meta($user_id, 'ns_signup_method', 'phone');
    update_user_meta($user_id, 'ns_phone_verified', current_time('mysql'));

    return (int) $user_id;
}

/* ========================================================================
   Get user payload
   ======================================================================== */
function get_user_payload(int $user_id): array {
    $user = get_userdata($user_id);
    $phone = get_user_meta($user_id, 'ns_phone', true);

    $has_real_email = $user->user_email && !str_contains($user->user_email, '@phone.local.');

    /* ✅ Avatar priority: user uploaded → Google → default */
    $avatar = get_user_meta($user_id, 'ns_avatar', true)
        ?: get_user_meta($user_id, 'ns_google_avatar', true);

    return [
        'id' => $user_id,
        'display_name' => $user->display_name,
        'phone' => $phone,
        'email' => $has_real_email ? $user->user_email : null,
        'has_password' => false,
        'avatar' => $avatar ?: get_avatar_url($user_id, ['size' => 96]),
    ];
}