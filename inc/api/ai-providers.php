<?php

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

/**
 * Trip AI provider chain.
 * Default order: gemini → groq → qwen.
 * Any provider without a key is automatically skipped.
 */
class TripProviderChain {
    public static function order(): array {
        $order = get_option('ns_ai_trip_provider_order', 'gemini,groq,qwen');

        return array_values(array_filter(array_map('trim', explode(',', $order))));
    }

    public static function has_any_provider(): bool {
        foreach (self::order() as $p) {
            if ($p === 'gemini' && (new AiTripGeminiClient())->has_api_key()) return true;
            if ($p === 'groq' && (string) get_option('ns_ai_trip_groq_key', '') !== '') return true;
            if ($p === 'qwen' && (string) get_option('ns_ai_trip_qwen_key', '') !== '') return true;
        }

        return false;
    }

    /**
     * Generate JSON with schema - the first successful provider wins.
     */
    public static function generate_json_with_schema(string $prompt, array $schema, array $options = []): array {
        $last = [
            'success' => false,
            'data'    => null,
            'error'   => 'هیچ ارائه‌دهنده AI فعال نیست',
            'usage'   => [],
        ];

        foreach (self::order() as $provider) {
            if ($provider === 'gemini') {
                $client = new AiTripGeminiClient($options['timeout'] ?? 75);

                if (!$client->has_api_key()) continue;

                $res = $client->generate_json_with_schema($prompt, $schema, $options);
            } elseif ($provider === 'groq') {
                $key = (string) get_option('ns_ai_trip_groq_key', '');

                if ($key === '') continue;

                $res = self::openai_compat(
                    $key,
                    'https://api.groq.com/openai/v1/chat/completions',
                    (string) get_option('ns_ai_trip_groq_model', 'llama-3.3-70b-versatile'),
                    $prompt,
                    $schema,
                    $options
                );
            } elseif ($provider === 'qwen') {
                $key = (string) get_option('ns_ai_trip_qwen_key', '');

                if ($key === '') continue;

                $res = self::openai_compat(
                    $key,
                    'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions',
                    (string) get_option('ns_ai_trip_qwen_model', 'qwen-plus'),
                    $prompt,
                    $schema,
                    $options
                );
            } else {
                continue;
            }

            if ($res['success']) return $res;

            $last = $res;

            error_log("⚠️ TripProvider [{$provider}] failed: " . ($res['error'] ?? ''));
        }

        return $last;
    }

    /* ========================================================================
       OpenAI-Compatible Protocol (Groq / Qwen)
       ======================================================================== */
    private static function openai_compat(string $key, string $url, string $model, string $prompt, array $schema, array $options): array {
        $start = microtime(true);

        /* These providers do not accept schema → add structure to prompt. */
        $full_prompt = $prompt
            . "
خروجی باید فقط یک JSON معتبر با این ساختار باشد:
"
            . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $response = wp_remote_post($url, [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'model'           => $model,
                'messages'        => [['role' => 'user', 'content' => $full_prompt]],
                'temperature'     => $options['temperature'] ?? 0.7,
                'max_tokens'      => $options['max_tokens'] ?? 4096,
                'response_format' => ['type' => 'json_object'],
            ]),
        ]);

        $duration_ms = (int) ((microtime(true) - $start) * 1000);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'data'    => null,
                'error'   => $response->get_error_message(),
                'usage'   => ['duration_ms' => $duration_ms],
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            return [
                'success' => false,
                'data'    => null,
                'error'   => "HTTP {$code}: " . mb_substr($raw, 0, 200),
                'usage'   => ['duration_ms' => $duration_ms],
            ];
        }

        $data = json_decode($raw, true);

        $text = $data['choices'][0]['message']['content'] ?? null;

        if (!$text) {
            return [
                'success' => false,
                'data'    => null,
                'error'   => 'Empty response',
                'usage'   => ['duration_ms' => $duration_ms],
            ];
        }

        $json = json_decode($text, true);

        if (!is_array($json) && preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $json = json_decode($m[0], true);
        }

        if (!is_array($json)) {
            return [
                'success' => false,
                'data'    => null,
                'error'   => 'Invalid JSON',
                'usage'   => ['duration_ms' => $duration_ms],
            ];
        }

        return [
            'success' => true,
            'data'    => $json,
            'error'   => null,
            'usage'   => [
                'duration_ms'  => $duration_ms,
                'total_tokens' => (int) ($data['usage']['total_tokens'] ?? 0),
                'model'        => $model,
            ],
        ];
    }
}