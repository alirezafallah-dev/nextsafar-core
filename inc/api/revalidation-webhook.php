<?php

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

/**
 * Webhook: Notify Next.js when data changes
 */
class RevalidationWebhook {

    public static function init() {
        /* Hotels */
        add_action('save_post_hotel', [__CLASS__, 'on_hotel_change'], 20, 2);
        add_action('delete_post', [__CLASS__, 'on_hotel_change'], 20, 2);

        /* Other posts */
        add_action('save_post_destination', [__CLASS__, 'on_content_change'], 20, 2);
        add_action('save_post_tour', [__CLASS__, 'on_content_change'], 20, 2);
        add_action('save_post_travelguide', [__CLASS__, 'on_content_change'], 20, 2);

        /* Tourism taxonomy */
        add_action('edited_tourism', [__CLASS__, 'on_tourism_change']);
        add_action('created_tourism', [__CLASS__, 'on_tourism_change']);
    }

    /**
     * Load local secrets from inc/secrets.local.php
     */
    private static function secrets(): array {
        static $secrets = null;

        if ($secrets === null) {
            $secrets = [];

            $file = defined('NEXTSAFAR_PATH')
                ? NEXTSAFAR_PATH . 'inc/secrets.local.php'
                : dirname(__DIR__) . '/secrets.local.php';

            if (is_readable($file)) {
                $data = require $file;

                if (is_array($data)) {
                    $secrets = $data;
                }
            }
        }

        return $secrets;
    }

    /**
     * Get Next.js frontend URL from secrets.
     */
    private static function nextjs_url(): string {
        $url = (string) (self::secrets()['nextjs_url'] ?? 'http://localhost:3000');

        return rtrim($url, '/');
    }

    /**
     * Get Next.js revalidation secret from secrets.
     */
    private static function revalidation_secret(): string {
        return (string) (self::secrets()['nextjs_revalidation_secret'] ?? '');
    }

    /**
     * Hotel change → revalidate home page + hotel list
     */
    public static function on_hotel_change($post_id, $post = null) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!$post || $post->post_status !== 'publish') return;

        self::trigger_revalidation([
            '/',
            '/hotels',
            '/hotels/' . $post->post_name,
        ]);
    }

    /**
     * Generic content change
     */
    public static function on_content_change($post_id, $post = null) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!$post || $post->post_status !== 'publish') return;

        self::trigger_revalidation(['/']);
    }

    /**
     * Tourism change
     */
    public static function on_tourism_change($term_id) {
        self::trigger_revalidation(['/']);
    }

    /**
     * Call Next.js API
     */
    private static function trigger_revalidation(array $paths) {
        $secret = self::revalidation_secret();

        if ($secret === '') {
            error_log('❌ Revalidation skipped: missing nextjs_revalidation_secret in inc/secrets.local.php');
            return;
        }

        $url = self::nextjs_url() . '/api/revalidate';

        $response = wp_remote_post($url, [
            'timeout' => 5,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'secret' => $secret,
                'paths'  => $paths,
            ]),
        ]);

        if (is_wp_error($response)) {
            error_log('❌ Revalidation failed: ' . $response->get_error_message());
            return;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            error_log('❌ Revalidation error: HTTP ' . $code);
            return;
        }

        error_log('✅ Revalidated: ' . implode(', ', $paths));
    }
}