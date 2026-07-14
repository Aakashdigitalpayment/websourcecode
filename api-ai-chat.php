<?php
/**
 * Public AI Chat API — answers grounded in this site's live DB content.
 * Per-deployment OpenAI or Gemini key from site_settings. POST JSON.
 * Security: honeypot, length limits, IP rate-limit. Never returns API key.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/ai-chat-helpers.php';
require_once __DIR__ . '/includes/ai-chat-context.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$json = json_decode($raw, true);
if (!is_array($json)) {
    // Also accept form-encoded
    $json = $_POST;
}

/* Honeypot */
if (!empty(trim((string)($json['website'] ?? '')))) {
    echo json_encode(['ok' => true, 'answer' => ''], JSON_UNESCAPED_UNICODE);
    exit;
}

$message = trim((string)($json['message'] ?? $json['body'] ?? ''));
if ($message === '' || mb_strlen($message) < 2) {
    echo json_encode(['ok' => false, 'msg' => 'प्रश्न लेख्नुहोस्।'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($message) > 800) {
    echo json_encode(['ok' => false, 'msg' => 'प्रश्न ≤800 अक्षर मात्र।'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!ai_chat_is_enabled()) {
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'msg' => 'AI Chat अहिले उपलब्ध छैन। Live Chat वा FAQ प्रयोग गर्नुहोस्।',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = function_exists('getDB') ? getDB() : null;
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

/* Rate limit via lightweight temp file bucket (10 / 10 min per IP) */
$bucketDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'coop-ai-chat-rl';
if (!is_dir($bucketDir)) {
    @mkdir($bucketDir, 0755, true);
}
$bucketFile = $bucketDir . '/' . hash('sha256', $ip) . '.json';
$now = time();
$hits = [];
if (is_file($bucketFile)) {
    $prev = json_decode((string)@file_get_contents($bucketFile), true);
    if (is_array($prev)) {
        foreach ($prev as $ts) {
            if (is_int($ts) && $ts > $now - 600) {
                $hits[] = $ts;
            }
        }
    }
}
if (count($hits) >= 10) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'msg' => 'धेरै अनुरोध। केही समय पछि पुनः प्रयास गर्नुहोस्।'], JSON_UNESCAPED_UNICODE);
    exit;
}
$hits[] = $now;
@file_put_contents($bucketFile, json_encode($hits), LOCK_EX);

$apiKey = ai_chat_get_api_key();
if ($apiKey === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'msg' => 'AI कन्फिगर छैन।'], JSON_UNESCAPED_UNICODE);
    exit;
}

$english = function_exists('isEnglish') && isEnglish();
$pack = ai_chat_build_context($message, $db instanceof PDO ? $db : null);
$context = $pack['context'];
$sources = array_slice($pack['sources'], 0, 8);

$siteName = (string)getSetting($english ? 'site_name_en' : 'site_name', getSetting('site_name', 'सहकारी'));
$langRule = $english
    ? 'Reply in clear English unless the user wrote Nepali.'
    : 'Reply in clear Nepali (Devanagari) unless the user wrote English.';

$system = <<<SYS
You are the official website assistant for "{$siteName}" (a Nepali cooperative).
{$langRule}

STRICT RULES:
1) Answer ONLY using the SITE CONTEXT below. Do not invent products, interest rates, fees, dates, branch phones, or policies.
2) If the context does not contain the answer, say you do not have that information on the website and suggest Live Chat, Contact page, WhatsApp, or FAQs.
3) Never ask for or discuss personal account balances, passwords, KYC documents, or OTP.
4) Be concise (2–6 short sentences). Use bullet points when listing rates/services.
5) You may briefly greet and stay helpful.

SITE CONTEXT:
{$context}
SYS;

$provider = ai_chat_provider();
$model = ai_chat_model();

try {
    if ($provider === 'openai') {
        $answer = ai_chat_call_openai($apiKey, $model, $system, $message);
    } else {
        $answer = ai_chat_call_gemini($apiKey, $model, $system, $message);
    }
} catch (Throwable $e) {
    error_log('ai-chat provider: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'msg' => 'AI सेवा जवाफ दिन सकेन। केहीबेर पछि वा Live Chat प्रयोग गर्नुहोस्।',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$answer = trim($answer);
if ($answer === '') {
    echo json_encode([
        'ok' => false,
        'msg' => 'खाली जवाफ आयो। Live Chat बाट सम्पर्क गर्नुहोस्।',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'answer' => $answer,
    'sources' => $sources,
], JSON_UNESCAPED_UNICODE);
exit;

/* ── Provider callers ─────────────────────────────────────────── */

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
            'max_tokens' => 700,
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

function ai_chat_call_gemini(string $apiKey, string $model, string $system, string $user): string
{
    $model = trim($model) !== '' ? $model : 'gemini-2.0-flash';
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
                'maxOutputTokens' => 700,
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
