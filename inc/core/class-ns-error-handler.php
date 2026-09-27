<?php
/**
 * NextSafar Core - Error Handler
 * مدیریت خطاهای حرفه‌ای با پاسخ‌های استاندارد
 *
 * @package NextSafar\Core
 * @since   2.6.0
 */

namespace NextSafar\Core;

if (!defined('ABSPATH')) exit;

class ErrorHandler {

    /**
     * کدهای خطای سفارشی
     */
    const ERR_MISSING_PARAMS     = 'missing_params';
    const ERR_INVALID_DATE       = 'invalid_date';
    const ERR_API_ERROR          = 'api_error';
    const ERR_RATE_LIMIT         = 'rate_limit_exceeded';
    const ERR_VALIDATION         = 'validation_failed';
    const ERR_UNAUTHORIZED       = 'unauthorized';
    const ERR_FORBIDDEN          = 'forbidden';
    const ERR_NOT_FOUND          = 'not_found';
    const ERR_SERVER_ERROR       = 'server_error';
    const ERR_SERVICE_UNAVAILABLE = 'service_unavailable';

    /**
     * ساخت پاسخ خطای استاندارد
     *
     * @param string $code     کد خطا
     * @param string $message  پیام فارسی
     * @param int    $status   کد وضعیت HTTP
     * @param array  $data     داده‌های اضافی
     * @return \WP_Error
     */
    public static function error(string $code, string $message, int $status = 400, array $data = []): \WP_Error {
        Logger::error($message, array_merge(['code' => $code], $data));

        return new \WP_Error($code, $message, array_merge(['status' => $status], $data));
    }

    /**
     * خطای پارامترهای گم‌شده
     */
    public static function missing_params(array $missing = []): \WP_Error {
        $message = 'پارامترهای الزامی وارد نشده است.';
        if (!empty($missing)) {
            $message .= ' پارامترهای گم‌شده: ' . implode('، ', $missing);
        }
        return self::error(self::ERR_MISSING_PARAMS, $message, 400, ['missing' => $missing]);
    }

    /**
     * خطای تاریخ نامعتبر
     */
    public static function invalid_date(string $date = ''): \WP_Error {
        $message = 'تاریخ وارد شده نامعتبر است.';
        if ($date !== '') {
            $message .= " (تاریخ: {$date})";
        }
        return self::error(self::ERR_INVALID_DATE, $message, 400);
    }

    /**
     * خطای API خارجی
     */
    public static function api_error(string $provider, string $message): \WP_Error {
        Logger::error("External API error: {$provider}", [
            'provider' => $provider,
            'message'  => $message,
        ]);

        return self::error(self::ERR_API_ERROR, 'خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.', 502, [
            'provider' => $provider,
        ]);
    }

    /**
     * خطای عدم دسترسی
     */
    public static function unauthorized(string $message = 'ابتدا وارد حساب کاربری خود شوید.'): \WP_Error {
        return self::error(self::ERR_UNAUTHORIZED, $message, 401);
    }

    /**
     * خطای دسترسی ممنوع
     */
    public static function forbidden(string $message = 'شما به این بخش دسترسی ندارید.'): \WP_Error {
        return self::error(self::ERR_FORBIDDEN, $message, 403);
    }

    /**
     * خطای پیدا نشد
     */
    public static function not_found(string $message = 'مورد درخواستی پیدا نشد.'): \WP_Error {
        return self::error(self::ERR_NOT_FOUND, $message, 404);
    }

    /**
     * خطای سرور
     */
    public static function server_error(string $message = 'خطای داخلی سرور. لطفاً دوباره تلاش کنید.'): \WP_Error {
        return self::error(self::ERR_SERVER_ERROR, $message, 500);
    }

    /**
     * خطای در دسترس نبودن سرویس
     */
    public static function service_unavailable(string $message = 'سرویس موقتاً در دسترس نیست.'): \WP_Error {
        return self::error(self::ERR_SERVICE_UNAVAILABLE, $message, 503);
    }

    /**
     * مدیریت استثناها
     *
     * @param \Throwable $e        استثنا
     * @param string     $context  زمینه خطا
     * @return \WP_Error
     */
    public static function handle_exception(\Throwable $e, string $context = ''): \WP_Error {
        Logger::critical('Unhandled exception', [
            'context'  => $context,
            'message'  => $e->getMessage(),
            'file'     => $e->getFile(),
            'line'     => $e->getLine(),
            'trace'    => array_slice(explode("\n", $e->getTraceAsString()), 0, 5),
        ]);

        // در حالت development، جزئیات بیشتر نمایش داده شود
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return self::error(
                self::ERR_SERVER_ERROR,
                'خطای سرور: ' . $e->getMessage(),
                500,
                ['file' => $e->getFile(), 'line' => $e->getLine()]
            );
        }

        return self::server_error();
    }

    /**
     * تبدیل WP_Error به پاسخ استاندارد
     */
    public static function to_response(\WP_Error $error): \WP_REST_Response {
        $error_data = $error->get_error_data();
        $status = is_array($error_data) ? ($error_data['status'] ?? 400) : 400;

        $response = [
            'ok'      => false,
            'error'   => [
                'code'    => $error->get_error_code(),
                'message' => $error->get_error_message(),
            ],
        ];

        // افزودن داده‌های اضافی
        if (is_array($error_data)) {
            $extra = array_diff_key($error_data, ['status' => true]);
            if (!empty($extra)) {
                $response['error']['data'] = $extra;
            }
        }

        return new \WP_REST_Response($response, $status);
    }
}