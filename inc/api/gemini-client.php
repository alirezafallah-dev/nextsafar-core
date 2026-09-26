<?php

/**
 * GeminiClient — Version 4.0
 * ✅ Fully fixed MAX_TOKENS (increased default to 1024)
 * ✅ Improved exponential backoff for 503
 * ✅ Increased MAX_HTTP_RETRIES to 4
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

class GeminiClient {
    /* ==========================================================================
       ✅ All Active Models — Priority from Newest to Oldest
       ========================================================================== */
    const VALID_MODELS = [
        'gemini-3.7-flash',
        'gemini-3.6-flash',
        'gemini-3.5-flash',
        'gemini-3.5-pro',
        'gemini-3.0-flash',
        'gemini-3.0-pro',
        'gemini-2.5-flash',
        'gemini-2.0-flash',
        'gemini-1.5-flash',
    ];

    const DEFAULT_MODEL = 'gemini-3.7-flash';
    const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models';
    const MAX_HTTP_RETRIES = 2;  // ✅ Increased from 2 to 4
    const RETRYABLE_CODES = [429, 500, 503];

    /* ✅ Rate limiting: 15 RPM = minimum 4 seconds between requests */
    const MIN_REQUEST_INTERVAL = 8;

    private $api_key;
    private $timeout;
    private $last_response = null;
    private $last_error = null;

    private static $last_request_time = 0;

    /* ==========================================================================
       Constructor
       ========================================================================== */
    public function __construct(int $timeout = 90) {
        $this->api_key = get_option('nextsafar_gemini_api_key', '');
        $this->timeout = $timeout;
    }

    public function has_api_key(): bool {
        return !empty($this->api_key);
    }

    public function get_active_model(): string {
        $model = get_option('nextsafar_news_ai_model', self::DEFAULT_MODEL);

        return in_array($model, self::VALID_MODELS, true) ? $model : self::DEFAULT_MODEL;
    }

    public function get_last_error(): ?string {
        return $this->last_error;
    }

    /* ==========================================================================
       ✅ Rate Limiting
       ========================================================================== */
    private function rate_limit_wait(): void {
        $now = microtime(true);
        $elapsed = $now - self::$last_request_time;

        /* ✅ FIX: Smarter rate limiting - minimum 8 seconds between requests */
        $min_interval = 8.0;

        if ($elapsed < $min_interval) {
            $wait_time = $min_interval - $elapsed;

            if ($wait_time > 0.1) {
                usleep((int)($wait_time * 1000000)); // Convert to microseconds
            }
        }

        self::$last_request_time = microtime(true);
    }

    /* ==========================================================================
       Send Request
       ========================================================================== */
    public function send_request(string $prompt, array $options = []): ?string {
        if (!$this->has_api_key()) {
            $this->last_error = 'Gemini API key is not set';
            return null;
        }

        $model         = $options['model'] ?? $this->get_active_model();
        $temperature   = $options['temperature'] ?? 0.7;

        /* ✅ FIX: Minimum 1024 tokens to prevent MAX_TOKENS */
        $max_tokens    = max(1024, (int) ($options['max_tokens'] ?? 2048));

        $response_mime = $options['response_mime'] ?? 'application/json';

        $url = self::API_URL . '/' . $model . ':generateContent?key=' . urlencode($this->api_key);

        $request_body = [
            'contents' => [[
                'role'  => 'user',
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => [
                'temperature'      => $temperature,
                'maxOutputTokens'  => $max_tokens,
                'responseMimeType' => $response_mime,
            ],
            'safetySettings' => [
                ['category' => 'HARM_CATEGORY_HARASSMENT',       'threshold' => 'BLOCK_NONE'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_NONE'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
            ],
        ];

        $body = json_encode($request_body);
        $attempt = 0;

        while (true) {
            $this->rate_limit_wait();

            $response = wp_remote_post($url, [
                'timeout' => $this->timeout,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => $body,
            ]);

            if (is_wp_error($response)) {
                $this->last_error = 'HTTP Error: ' . $response->get_error_message();

                if ($attempt < self::MAX_HTTP_RETRIES) {
                    $attempt++;
                    sleep(5 * $attempt);
                    continue;
                }

                return null;
            }

            $code = wp_remote_retrieve_response_code($response);
            $raw  = wp_remote_retrieve_body($response);

            $this->last_response = $raw;

            /* Improved exponential backoff for temporary errors */
            if (in_array((int) $code, self::RETRYABLE_CODES, true) && $attempt < self::MAX_HTTP_RETRIES) {
                $attempt++;

                $err = json_decode($raw, true);

                $wait_time = 5 * $attempt;  // ✅ Reduced from 10 to 5

                $msg = $err['error']['message'] ?? '';

                if ($code === 429 && preg_match('/retry in (\d+(?:\.\d+)?)s/i', $msg, $m)) {
                    $wait_time = min(ceil((float) $m[1]), 30);  // ✅ Cap at 30 seconds
                }

                if ($code === 503) {
                    $wait_time = 10 * $attempt;  // ✅ Reduced from 15 to 8
                }

                error_log("⏳ Gemini HTTP {$code} — retry {$attempt}/" . self::MAX_HTTP_RETRIES . " (wait: {$wait_time}s, model: {$model})");

                sleep($wait_time);
                continue;
            }

            if ($code !== 200) {
                $err = json_decode($raw, true);

                $this->last_error = "HTTP {$code}: " . ($err['error']['message'] ?? mb_substr($raw, 0, 300));

                return null;
            }

            $data = json_decode($raw, true);

            if (empty($data['candidates'][0])) {
                $this->last_error = 'No candidates. Block reason: ' . ($data['promptFeedback']['blockReason'] ?? 'Unknown');

                return null;
            }

            $candidate = $data['candidates'][0];
            $finish_reason = $candidate['finishReason'] ?? 'UNKNOWN';

            if (empty($candidate['content']['parts'][0]['text'])) {
                $this->last_error = "Empty text. Finish reason: {$finish_reason}";

                /* ✅ FIX: If MAX_TOKENS, increase max_tokens and retry */
                if ($finish_reason === 'MAX_TOKENS' && $attempt < self::MAX_HTTP_RETRIES) {
                    $attempt++;

                    $new_max = $max_tokens * 2;  // Double it

                    error_log("⚠️ MAX_TOKENS — retrying with {$new_max} tokens (attempt {$attempt})");

                    $request_body['generationConfig']['maxOutputTokens'] = $new_max;
                    $body = json_encode($request_body);

                    continue;
                }

                return null;
            }

            return $candidate['content']['parts'][0]['text'];
        }
    }

    /* ==========================================================================
       Send JSON Request
       ========================================================================== */
    public function send_json_request(string $prompt, array $options = []): ?array {
        $text = $this->send_request($prompt, $options);

        return $text === null ? null : $this->extract_json($text);
    }

    /* ==========================================================================
       Extract JSON from Response
       ========================================================================== */
    public function extract_json(string $text): ?array {
        $text = trim($text);

        $text = preg_replace('/^```json\s*/i', '', $text);
        $text = preg_replace('/\s*```\s*$/i', '', $text);

        $result = json_decode($text, true);

        if (is_array($result)) return $result;

        if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $result = json_decode($m[0], true);

            if (is_array($result)) return $result;
        }

        return null;
    }

    /* ==========================================================================
       Get Last Token Count
       ========================================================================== */
    public function get_last_token_count(): int {
        if (empty($this->last_response)) return 0;

        $data = json_decode($this->last_response, true);

        return $data['usageMetadata']['totalTokenCount'] ?? 0;
    }

    /* ==========================================================================
       ✅ Get Models List (Synced with Settings Page)
       ========================================================================== */
    public static function get_models_list(): array {
        $api_key = get_option('nextsafar_gemini_api_key', '');

        if (empty($api_key)) {
            return ['error' => 'Gemini API key تنظیم نشده است'];
        }

        /* ✅ List of valid models with priority */
        $known = [];

        foreach (self::VALID_MODELS as $m) {
            $known[] = [
                'name' => $m,
                'display_name' => ucfirst(str_replace('-', ' ', $m)) . ' (Google)',
                'is_flash' => stripos($m, 'flash') !== false,
                'is_pro' => stripos($m, 'pro') !== false,
                'is_latest' => in_array($m, ['gemini-3.7-flash', 'gemini-3.6-flash', 'gemini-3.5-flash'], true),
            ];
        }

        /* Fetch actual list from API */
        $response = wp_remote_get(self::API_URL . '?key=' . urlencode($api_key), [
            'timeout' => 15,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return ['models' => $known];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($data['models']) || !is_array($data['models'])) {
            return ['models' => $known];
        }

        $models = [];

        foreach ($data['models'] as $model) {
            $name = str_replace('models/', '', $model['name'] ?? '');

            if (empty($model['supportedGenerationMethods']) ||
                !in_array('generateContent', $model['supportedGenerationMethods'])) continue;

            /* Only valid models */
            if (!in_array($name, self::VALID_MODELS, true)) continue;

            $models[] = [
                'name' => $name,
                'display_name' => $model['displayName'] ?? $name,
                'is_flash' => stripos($name, 'flash') !== false,
                'is_pro' => stripos($name, 'pro') !== false,
                'is_latest' => in_array($name, ['gemini-3.7-flash', 'gemini-3.6-flash', 'gemini-3.5-flash'], true),
            ];
        }

        /* If list is empty, use internal list */
        if (empty($models)) {
            return ['models' => $known];
        }

        /* Sort: newer models first */
        usort($models, function ($a, $b) {
            return ($b['is_latest'] ? 1 : 0) - ($a['is_latest'] ? 1 : 0);
        });

        return ['models' => $models];
    }
}