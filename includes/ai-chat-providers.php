<?php
/**
 * OpenAI / Gemini / DeepSeek HTTP callers for site AI Chat.
 */

if (!function_exists('ai_chat_http_json')) {
    function ai_chat_http_json(string $url, array $headers, array $payload, int $timeout = 45): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new RuntimeException('JSON encode failed');
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 12,
            ]);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($resp === false) {
                throw new RuntimeException('cURL: ' . $err);
            }
        } else {
            if (!ini_get('allow_url_fopen')) {
                throw new RuntimeException('cURL required for AI Chat');
            }
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $headers) . "\r\n",
                    'content' => $body,
                    'timeout' => $timeout,
                    'ignore_errors' => true,
                ],
            ]);
            $resp = @file_get_contents($url, false, $ctx);
            $code = 0;
            $respHeaders = [];
            if (function_exists('http_get_last_response_headers')) {
                $respHeaders = http_get_last_response_headers() ?: [];
            }
            if (isset($respHeaders[0]) && preg_match('/\s(\d{3})\s/', (string)$respHeaders[0], $m)) {
                $code = (int)$m[1];
            }
            if ($resp === false) {
                throw new RuntimeException('HTTP request failed');
            }
        }

        $data = json_decode((string)$resp, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid provider JSON (HTTP ' . $code . ')');
        }
        if ($code >= 400) {
            $msg = (string)($data['error']['message'] ?? $data['error']['status'] ?? ('HTTP ' . $code));
            throw new RuntimeException($msg);
        }
        return $data;
    }
}

if (!function_exists('ai_chat_call_openai')) {
    function ai_chat_call_openai(string $apiKey, string $model, string $system, string $user): string
    {
        $data = ai_chat_http_json(
            'https://api.openai.com/v1/chat/completions',
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            [
                'model' => $model,
                'temperature' => 0.2,
                'max_tokens' => 480,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ]
        );
        $text = (string)($data['choices'][0]['message']['content'] ?? '');
        if ($text === '') {
            throw new RuntimeException('Empty OpenAI content');
        }
        return $text;
    }
}

if (!function_exists('ai_chat_call_gemini')) {
    function ai_chat_call_gemini(string $apiKey, string $model, string $system, string $user): string
    {
        $model = trim($model) !== '' ? $model : 'gemini-2.5-flash';
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($model)
            . ':generateContent?key=' . rawurlencode($apiKey);

        $data = ai_chat_http_json(
            $url,
            ['Content-Type: application/json'],
            [
                'systemInstruction' => [
                    'parts' => [['text' => $system]],
                ],
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [['text' => $user]],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => 480,
                ],
            ]
        );

        $text = (string)($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
        if ($text === '') {
            $block = (string)($data['promptFeedback']['blockReason'] ?? '');
            throw new RuntimeException($block !== '' ? ('Blocked: ' . $block) : 'Empty Gemini content');
        }
        return $text;
    }
}

if (!function_exists('ai_chat_call_deepseek')) {
    /** DeepSeek API is OpenAI-compatible (paid; top-up at platform.deepseek.com). */
    function ai_chat_call_deepseek(string $apiKey, string $model, string $system, string $user): string
    {
        $model = trim($model) !== '' ? $model : 'deepseek-chat';
        $data = ai_chat_http_json(
            'https://api.deepseek.com/v1/chat/completions',
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            [
                'model' => $model,
                'temperature' => 0.2,
                'max_tokens' => 480,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ]
        );
        $text = (string)($data['choices'][0]['message']['content'] ?? '');
        if ($text === '') {
            throw new RuntimeException('Empty DeepSeek content');
        }
        return $text;
    }
}

if (!function_exists('ai_chat_ask_provider')) {
    function ai_chat_ask_provider(string $provider, string $apiKey, string $model, string $system, string $user): string
    {
        if ($provider === 'openai') {
            return ai_chat_call_openai($apiKey, $model, $system, $user);
        }
        if ($provider === 'deepseek') {
            return ai_chat_call_deepseek($apiKey, $model, $system, $user);
        }
        return ai_chat_call_gemini($apiKey, $model, $system, $user);
    }
}
