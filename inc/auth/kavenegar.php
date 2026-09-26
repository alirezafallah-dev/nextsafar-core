<?php

namespace NextSafar\Auth\Kavenegar;

/* ========================================================================
   Send OTP via Kavenegar — sms/send method (no template required)
   ======================================================================== */
function send_otp(string $phone, string $code): array {
    $apikey = defined('NS_KAVENEGAR_API_KEY') ? NS_KAVENEGAR_API_KEY : '';

    if (!$apikey) {
        return ['ok' => false, 'error' => 'api_key_missing', 'message' => 'کلید API کاوه‌نگار تنظیم نشده'];
    }

    /* Build SMS message with OTP code */
    $message = "کد تایید شما در سفر بعدی:
" . $code . "
این کد را در اختیار کسی قرار ندهید.
nextsafar.com";

    /* sms/send endpoint for regular SMS */
    $url = "https://api.kavenegar.com/v1/{$apikey}/sms/send.json";

    $response = wp_remote_post($url, [
        'timeout' => 15,
        'body' => [
            'receptor' => $phone,
            'message'  => $message,
            'sender'   => '10004346', // Kavenegar sender number (default)
        ],
    ]);

    /* Full log for debugging */
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("[Kavenegar] Sending OTP to {$phone}");
    }

    if (is_wp_error($response)) {
        $error_msg = $response->get_error_message();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[Kavenegar] Network error: {$error_msg}");
        }

        return ['ok' => false, 'error' => 'network_error', 'message' => $error_msg];
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("[Kavenegar] Response HTTP {$http_code}: " . wp_remote_retrieve_body($response));
    }

    /* Check success */
    if ($http_code !== 200) {
        return [
            'ok' => false,
            'error' => 'kavenegar_http_error',
            'status' => $http_code,
            'message' => $body['return']['message'] ?? "خطای HTTP {$http_code}",
            'raw' => $body,
        ];
    }

    if (!isset($body['return']['status']) || $body['return']['status'] !== 200) {
        return [
            'ok' => false,
            'error' => 'kavenegar_error',
            'status' => $body['return']['status'] ?? null,
            'message' => $body['return']['message'] ?? 'ارسال پیامک ناموفق بود',
            'raw' => $body,
        ];
    }

    return [
        'ok' => true,
        'message_id' => $body['entries'][0]['messageid'] ?? null,
    ];
}