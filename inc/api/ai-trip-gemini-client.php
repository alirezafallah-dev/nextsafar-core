<?php

/**
 * AiTripGeminiClient - Version 1.0.0 (Safe Edition)
 * Dedicated Gemini client for AI Trip Planner.
 *
 * - Completely separate from the news system (GeminiClient remains untouched).
 * - No long sleep → backend does not get locked.
 * - Strict time budget (maximum 75 seconds) → guaranteed completion.
 * - Independent key and model from the "AI Trip" admin menu.
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

class AiTripGeminiClient {
    const VALID_MODELS = [
        'gemini-3.7-flash',
        'gemini-3.6-flash',
        'gemini-3.5-flash',
        'gemini-3.5-pro',
        'gemini-3.0-flash',
        'gemini-3.0-pro',
        'gemini-2.5-flash',
    ];

    const DEFAULT_MODEL = 'gemini-3.7-flash';
    const API_URL       = 'https://generativelanguage.googleapis.com/v1beta/models';

    const OPTION_KEY   = 'nextsafar_trip_planner_key';
    const OPTION_MODEL = 'nextsafar_trip_planner_model';

    /* Safe settings - do not lock the backend. */
    const MAX_HTTP_RETRIES   = 2;   /* Maximum 2 retries (not 4). */
    const MAX_WAIT_PER_RETRY = 5;   /* Maximum 5 seconds sleep per retry. */
    const TOTAL_TIME_BUDGET  = 70;  /* Whole operation maximum 70 seconds. */
    const RETRYABLE_CODES    = [429, 500, 503];

    private $api_key;
    private $timeout;

    private $last_response = null;
    private $last_error = null;

    public function __construct(int $timeout = 75) {
        $this->api_key = trim((string) get_option(self::OPTION_KEY, ''));
        $this->timeout = min($timeout, 80); /* Hard limit. */
    }

    public function has_api_key(): bool {
        return !empty($this->api_key);
    }

    public function get_model(): string {
        $model = get_option(self::OPTION_MODEL, self::DEFAULT_MODEL);

        return in_array($model, self::VALID_MODELS, true) ? $model : self::DEFAULT_MODEL;
    }

    public function get_last_error(): ?string {
        return $this->last_error;
    }

    public function get_last_token_count(): int {
        if (empty($this->last_response)) return 0;

        $data = json_decode($this->last_response, true);

        return (int) ($data['usageMetadata']['totalTokenCount'] ?? 0);
    }

    /**
     * Request with JSON Schema - safe and time-bounded.
     *
     * @return array{success:bool, data:?array, error:?string, usage:array}
     */
    public function generate_json_with_schema(string $prompt, array $schema, array $options = []): array {
        if (!$this->has_api_key()) {
            return [
                'success' => false,
                'data'    => null,
                'error'   => 'کلید API برنامه سفر تنظیم نشده (منو: سفر AI)',
                'usage'   => [],
            ];
        }

        $model       = $options['model'] ?? $this->get_model();
        $temperature = $options['temperature'] ?? 0.8;
        $max_tokens  = max(2048, (int) ($options['max_tokens'] ?? 6144));

        $url = self::API_URL . '/' . $model . ':generateContent?key=' . urlencode($this->api_key);

        $request_body = [
            'contents' => [[
                'role'  => 'user',
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => [
                'temperature'      => $temperature,
                'maxOutputTokens'  => $max_tokens,
                'responseMimeType' => 'application/json',
                'responseSchema'   => $schema,
            ],
            'safetySettings' => [
                ['category' => 'HARM_CATEGORY_HARASSMENT',       'threshold' => 'BLOCK_NONE'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_NONE'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
            ],
        ];

        $body       = json_encode($request_body);
        $attempt    = 0;
        $start_time = microtime(true);

        while (true) {
            /* Strict time budget - if finished, return immediately. */
            $elapsed = microtime(true) - $start_time;

            if ($elapsed > self::TOTAL_TIME_BUDGET) {
                return [
                    'success' => false,
                    'data'    => null,
                    'error'   => 'بودجه زمانی تمام شد (' . self::TOTAL_TIME_BUDGET . 's)',
                    'usage'   => ['duration_ms' => (int) ($elapsed * 1000)],
                ];
            }

            /* No sleep before request (unlike the news version). */
            $response = wp_remote_post($url, [
                'timeout'  => $this->timeout,
                'headers'  => ['Content-Type' => 'application/json'],
                'body'     => $body,
                'blocking' => true,
            ]);

            $duration_ms = (int) ((microtime(true) - $start_time) * 1000);

            /* Network error. */
            if (is_wp_error($response)) {
                if ($attempt < self::MAX_HTTP_RETRIES) {
                    $attempt++;
                    sleep(min(3 * $attempt, self::MAX_WAIT_PER_RETRY));
                    continue;
                }

                return [
                    'success' => false,
                    'data'    => null,
                    'error'   => 'HTTP Error: ' . $response->get_error_message(),
                    'usage'   => ['duration_ms' => $duration_ms],
                ];
            }

            $code = wp_remote_retrieve_response_code($response);
            $raw  = wp_remote_retrieve_body($response);

            $this->last_response = $raw;

            /* Limited retry with short sleep. */
            if (in_array((int) $code, self::RETRYABLE_CODES, true) && $attempt < self::MAX_HTTP_RETRIES) {
                $attempt++;

                $err = json_decode($raw, true);
                $msg = $err['error']['message'] ?? '';

                $wait = 3 * $attempt; /* 3, 6 seconds. */

                if ($code === 429 && preg_match('/retry in (\d+(?:\.\d+)?)s/i', $msg, $m)) {
                    $wait = min((int) ceil((float) $m[1]), self::MAX_WAIT_PER_RETRY);
                }

                error_log("⏳ TripGemini HTTP {$code} — retry {$attempt}/" . self::MAX_HTTP_RETRIES . " (wait {$wait}s)");

                sleep($wait);
                continue;
            }

            /* Non-retryable error. */
            if ($code !== 200) {
                $err = json_decode($raw, true);

                return [
                    'success' => false,
                    'data'    => null,
                    'error'   => "HTTP {$code}: " . ($err['error']['message'] ?? mb_substr($raw, 0, 300)),
                    'usage'   => ['duration_ms' => $duration_ms, 'http_code' => $code],
                ];
            }

            /* Parse successful response. */
            $data = json_decode($raw, true);

            if (empty($data['candidates'][0])) {
                return [
                    'success' => false,
                    'data'    => null,
                    'error'   => 'No candidates. Block: ' . ($data['promptFeedback']['blockReason'] ?? 'Unknown'),
                    'usage'   => ['duration_ms' => $duration_ms],
                ];
            }

            $candidate     = $data['candidates'][0];
            $finish_reason = $candidate['finishReason'] ?? 'UNKNOWN';
            $text          = $candidate['content']['parts'][0]['text'] ?? null;

            /* Empty text. */
            if (empty($text)) {
                if ($finish_reason === 'MAX_TOKENS' && $attempt < self::MAX_HTTP_RETRIES) {
                    $attempt++;

                    $max_tokens = $max_tokens * 2;

                    error_log("⚠️ TripGemini MAX_TOKENS (empty) — retry {$attempt} with {$max_tokens}");

                    $request_body['generationConfig']['maxOutputTokens'] = $max_tokens;
                    $body = json_encode($request_body);

                    continue;
                }

                return [
                    'success' => false,
                    'data'    => null,
                    'error'   => "Empty text. Finish: {$finish_reason}",
                    'usage'   => ['duration_ms' => $duration_ms],
                ];
            }

            $json = $this->extract_json($text);

            /* FIX: Invalid/truncated JSON → retry with double tokens (previously only empty text was retried). */
            if (!is_array($json) && $attempt < self::MAX_HTTP_RETRIES) {
                $attempt++;

                $max_tokens = $max_tokens * 2;

                error_log("⚠️ TripGemini invalid JSON (finish: {$finish_reason}, len: " . mb_strlen($text) . ") — retry {$attempt} with {$max_tokens} tokens");

                $request_body['generationConfig']['maxOutputTokens'] = $max_tokens;
                $body = json_encode($request_body);

                continue;
            }

            /* Last chance - repair truncated JSON. */
            if (!is_array($json)) {
                $json = $this->repair_truncated_json($text);

                if (is_array($json)) {
                    error_log("🔧 TripGemini: truncated JSON repaired successfully");
                }
            }

            if (!is_array($json)) {
                return [
                    'success' => false,
                    'data'    => null,
                    'error'   => "Invalid JSON from model (finish: {$finish_reason})",
                    'usage'   => ['duration_ms' => $duration_ms, 'raw_text' => mb_substr($text, 0, 500)],
                ];
            }

            $usage_meta = $data['usageMetadata'] ?? [];

            return [
                'success' => true,
                'data'    => $json,
                'error'   => null,
                'usage'   => [
                    'duration_ms'   => $duration_ms,
                    'prompt_tokens' => (int) ($usage_meta['promptTokenCount'] ?? 0),
                    'output_tokens' => (int) ($usage_meta['candidatesTokenCount'] ?? 0),
                    'total_tokens'  => (int) ($usage_meta['totalTokenCount'] ?? 0),
                    'model'         => $model,
                    'finish_reason' => $finish_reason,
                ],
            ];
        }
    }

    /**
     * Simple text request (for testing).
     */
    public function send_request(string $prompt, array $options = []): ?string {
        $result = $this->generate_json_with_schema(
            $prompt,
            [
                'type'       => 'object',
                'properties' => ['text' => ['type' => 'string']],
                'required'   => ['text'],
            ],
            $options
        );

        if (!$result['success']) {
            $this->last_error = $result['error'];

            return null;
        }

        return $result['data']['text'] ?? null;
    }

    /**
     * Connection test.
     */
    public function test_connection(): array {
        return $this->generate_json_with_schema(
            'یک جمله کوتاه فارسی بگو که اتصال برقرار است.',
            [
                'type'       => 'object',
                'properties' => [
                    'message' => ['type' => 'string'],
                    'ok'      => ['type' => 'boolean'],
                ],
                'required'   => ['message', 'ok'],
            ],
            ['max_tokens' => 256, 'temperature' => 0.6]
        );
    }

    private function extract_json(string $text): ?array {
        $text = trim($text);

        $text = preg_replace('/^```json\s*/i', '', $text);
        $text = preg_replace('/\s*```\s*$/', '', $text);

        $result = json_decode($text, true);

        if (is_array($result)) return $result;

        if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $result = json_decode($m[0], true);

            if (is_array($result)) return $result;
        }

        return null;
    }

    /**
     * Attempt to repair truncated JSON (by closing open brackets).
     */
    private function repair_truncated_json(string $text): ?array {
        $start = strpos($text, '{');

        if ($start === false) return null;

        $json_str = substr($text, $start);

        /* Track open brackets and strings. */
        $stack = [];
        $in_string = false;
        $escape = false;

        $len = strlen($json_str);

        for ($i = 0; $i < $len; $i++) {
            $ch = $json_str[$i];

            if ($escape) {
                $escape = false;
                continue;
            }

            if ($ch === '\\') {
                $escape = true;
                continue;
            }

            if ($ch === '"') {
                $in_string = !$in_string;
                continue;
            }

            if ($in_string) continue;

            if ($ch === '{' || $ch === '[') {
                $stack[] = $ch;
            } elseif ($ch === '}' || $ch === ']') {
                array_pop($stack);
            }
        }

        /* Close open string. */
        if ($in_string) $json_str .= '"';

        /* Remove incomplete trailing member (key without value). */
        $json_str = preg_replace('/,\s*"[^"]*"?(\s*:\s*[^,\]}]*)?$/', '', rtrim($json_str));
        $json_str = preg_replace('/,\s*$/', '', $json_str);

        /* Close open brackets. */
        while (!empty($stack)) {
            $open = array_pop($stack);

            $json_str .= ($open === '{') ? '}' : ']';
        }

        $result = json_decode($json_str, true);

        return is_array($result) ? $result : null;
    }

    public static function get_models_for_select(): array {
        return [
            'gemini-3.7-flash' => 'Gemini 3.7 Flash — جدیدترین (پیشنهادی)',
            'gemini-3.6-flash' => 'Gemini 3.6 Flash — سریع و دقیق',
            'gemini-3.5-flash' => 'Gemini 3.5 Flash — پایدار',
            'gemini-3.5-pro'   => 'Gemini 3.5 Pro — دقیق‌تر، کندتر',
            'gemini-3.0-flash' => 'Gemini 3.0 Flash',
            'gemini-3.0-pro'   => 'Gemini 3.0 Pro',
            'gemini-2.5-flash' => 'Gemini 2.5 Flash — قدیمی',
        ];
    }
}