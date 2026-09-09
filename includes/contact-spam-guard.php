<?php
/**
 * Shared soft-safe anti-bot for ALL public forms + contact/live-chat.
 * One implementation: math, honeypot, spam filter, optional Turnstile, optional rate.
 */
declare(strict_types=1);

if (!function_exists('coop_contact_rate_ok')) {
    /** ~5 / hour / IP (session). */
    function coop_contact_rate_ok(string $bucket = 'contact_form'): bool
    {
        if (!function_exists('checkRateLimit')) {
            return true;
        }
        return checkRateLimit($bucket, 5, 3600);
    }
}

if (!function_exists('coop_contact_honeypot_tripped')) {
    /** @param array<string,mixed> $src */
    function coop_contact_honeypot_tripped(array $src): bool
    {
        foreach (['ct_hp', 'acp_hp', 'company_url', 'fax_number'] as $key) {
            if (trim((string) ($src[$key] ?? '')) !== '') {
                return true;
            }
        }
        return trim((string) ($src['website'] ?? '')) !== '';
    }
}

if (!function_exists('coop_math_challenge_issue')) {
    /**
     * Soft math captcha. Same bucket reuses one challenge until verified (multi-form pages share it).
     *
     * @return array{a:int,b:int,prompt_np:string,prompt_en:string}
     */
    function coop_math_challenge_issue(string $bucket = 'contact', bool $forceNew = false): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $key = 'coop_math_' . $bucket;
        if (!$forceNew && isset($_SESSION[$key]['a'], $_SESSION[$key]['b'], $_SESSION[$key]['sum'])) {
            $a = (int) $_SESSION[$key]['a'];
            $b = (int) $_SESSION[$key]['b'];
            return [
                'a' => $a,
                'b' => $b,
                'prompt_np' => "के हो {$a} + {$b} ?",
                'prompt_en' => "What is {$a} + {$b}?",
            ];
        }
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        $_SESSION[$key] = [
            'a'   => $a,
            'b'   => $b,
            'sum' => $a + $b,
            'ts'  => time(),
        ];
        return [
            'a' => $a,
            'b' => $b,
            'prompt_np' => "के हो {$a} + {$b} ?",
            'prompt_en' => "What is {$a} + {$b}?",
        ];
    }
}

if (!function_exists('coop_math_challenge_verify')) {
    function coop_math_challenge_verify(string $bucket, $answer): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $key = 'coop_math_' . $bucket;
        $row = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        if (!is_array($row) || !isset($row['sum'])) {
            return false;
        }
        if (!empty($row['ts']) && (time() - (int) $row['ts']) > 3600) {
            return false;
        }
        $given = is_numeric($answer) ? (int) $answer : -9999;
        return $given === (int) $row['sum'];
    }
}

if (!function_exists('coop_contact_looks_like_spam')) {
    function coop_contact_looks_like_spam(string ...$parts): bool
    {
        $text = mb_strtolower(implode("\n", $parts), 'UTF-8');
        if ($text === '') {
            return false;
        }
        /* Emails often contain .com — strip so normal contact forms are not blocked. */
        $scan = preg_replace('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/iu', ' ', $text) ?? $text;
        if (preg_match('#https?://#iu', $scan)) {
            return true;
        }
        if (preg_match('#\bwww\.#iu', $scan)) {
            return true;
        }
        if (preg_match('#\b(telegra\.ph|t\.me/|bit\.ly|tinyurl\.com|goo\.gl|ow\.ly|rebrand\.ly)\b#iu', $scan)) {
            return true;
        }
        if (preg_match('#\$\s?\d[\d,]{2,}#u', $scan)) {
            return true;
        }
        if (preg_match('#\b(jackpot|lottery|crypto\s?airdrop|forex\s?signal|prize\s?winner|you\s+won|claim\s+now|promo\s?code|double\s+your\s+money)\b#iu', $scan)) {
            return true;
        }
        /* Bare host + promo verb (not email hosts). */
        if (
            preg_match('#(?<![@\w])(?:[a-z0-9\-]+\.)+(?:com|net|org|xyz|ru|cn|top|click|shop)\b#iu', $scan)
            && preg_match('#\b(click|visit|register|invest|earn|whatsapp|telegram)\b#iu', $scan)
        ) {
            return true;
        }
        return false;
    }
}

if (!function_exists('coop_turnstile_configured')) {
    function coop_turnstile_configured(): bool
    {
        if (!function_exists('getSetting')) {
            return false;
        }
        return trim((string) getSetting('turnstile_site_key', '')) !== ''
            && trim((string) getSetting('turnstile_secret_key', '')) !== '';
    }
}

if (!function_exists('coop_turnstile_site_key')) {
    function coop_turnstile_site_key(): string
    {
        return function_exists('getSetting') ? trim((string) getSetting('turnstile_site_key', '')) : '';
    }
}

if (!function_exists('coop_turnstile_verify')) {
    function coop_turnstile_verify(?string $token): bool
    {
        if (!coop_turnstile_configured()) {
            return true;
        }
        $token = trim((string) $token);
        if ($token === '') {
            return false;
        }
        $secret = trim((string) getSetting('turnstile_secret_key', ''));
        $ip = function_exists('coop_client_ip') ? coop_client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $payload = http_build_query([
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ]);
        $ok = false;
        try {
            if (function_exists('curl_init')) {
                $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
                if ($ch !== false) {
                    curl_setopt_array($ch, [
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => $payload,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 5,
                        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
                    ]);
                    $raw = curl_exec($ch);
                    curl_close($ch);
                    $data = is_string($raw) ? json_decode($raw, true) : null;
                    $ok = is_array($data) && !empty($data['success']);
                }
            } else {
                $ctx = stream_context_create([
                    'http' => [
                        'method'  => 'POST',
                        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                        'content' => $payload,
                        'timeout' => 5,
                    ],
                ]);
                $raw = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);
                $data = is_string($raw) ? json_decode($raw, true) : null;
                $ok = is_array($data) && !empty($data['success']);
            }
        } catch (Throwable $e) {
            error_log('turnstile verify: ' . $e->getMessage());
            $ok = false;
        }
        return $ok;
    }
}

if (!function_exists('coop_public_form_spam_parts')) {
    /**
     * Build spam-scan strings from POST keys (shared cleaner).
     *
     * @param array<string,mixed> $src
     * @param list<string> $keys
     * @return list<string>
     */
    function coop_public_form_spam_parts(array $src, array $keys, int $maxLen = 8000): array
    {
        $out = [];
        foreach ($keys as $key) {
            $raw = (string) ($src[$key] ?? '');
            $out[] = function_exists('clean_text') ? clean_text($raw, $maxLen) : mb_substr(trim($raw), 0, $maxLen);
        }
        return $out;
    }
}

if (!function_exists('coop_public_form_bot_block')) {
    /**
     * Core shared check (no rate — forms keep their own rate limits).
     * Returns null = OK, or honeypot|math|spam|turnstile.
     *
     * @param array<string,mixed> $src
     * @param list<string> $spamParts
     */
    function coop_public_form_bot_block(
        array $src,
        string $mathBucket,
        array $spamParts = [],
        bool $checkSpam = true
    ): ?string {
        if (coop_contact_honeypot_tripped($src)) {
            return 'honeypot';
        }
        if (!coop_math_challenge_verify($mathBucket, $src['math_answer'] ?? $src['ct_math'] ?? null)) {
            return 'math';
        }
        if ($checkSpam && $spamParts !== [] && coop_contact_looks_like_spam(...$spamParts)) {
            return 'spam';
        }
        $cfToken = (string) ($src['cf-turnstile-response'] ?? $src['turnstile_token'] ?? '');
        if (!coop_turnstile_verify($cfToken)) {
            return 'turnstile';
        }
        return null;
    }
}

if (!function_exists('coop_contact_guard_block_reason')) {
    /**
     * Contact / live-chat: bot checks + shared rate bucket.
     *
     * @param array<string,mixed> $src
     * @param list<string> $textParts
     */
    function coop_contact_guard_block_reason(
        array $src,
        array $textParts,
        string $mathBucket = 'contact',
        string $rateBucket = 'contact_form',
        bool $requireMath = true
    ): ?string {
        if (!$requireMath) {
            if (coop_contact_honeypot_tripped($src)) {
                return 'honeypot';
            }
            if (coop_contact_looks_like_spam(...$textParts)) {
                return 'spam';
            }
            $cfToken = (string) ($src['cf-turnstile-response'] ?? $src['turnstile_token'] ?? '');
            if (!coop_turnstile_verify($cfToken)) {
                return 'turnstile';
            }
        } else {
            $block = coop_public_form_bot_block($src, $mathBucket, $textParts, true);
            if ($block !== null) {
                return $block;
            }
        }
        if (!coop_contact_rate_ok($rateBucket)) {
            return 'rate';
        }
        return null;
    }
}

if (!function_exists('coop_public_form_guard_message')) {
    function coop_public_form_guard_message(?string $block, bool $en = false): string
    {
        switch ($block) {
            case 'honeypot':
                return '';
            case 'math':
                return $en
                    ? 'Please solve the simple math check correctly.'
                    : 'कृपया साधारण गणित जाँच सही मिलाउनुहोस्।';
            case 'spam':
                return $en
                    ? 'Links, promo or spam-like text cannot be sent. Write a normal message without URLs.'
                    : 'लिङ्क, प्रोमो वा स्प्याम जस्तो सन्देश पठाउन मिल्दैन। URL बिना सामान्य सन्देश लेख्नुहोस्।';
            case 'turnstile':
                return $en
                    ? 'Human verification failed. Please try again.'
                    : 'मानव प्रमाणीकरण असफल। कृपया पुनः प्रयास गर्नुहोस्।';
            case 'rate':
                return $en
                    ? 'Too many requests. Please try again later.'
                    : 'धेरै अनुरोधहरू। कृपया पछि प्रयास गर्नुहोस्।';
            default:
                return $en ? 'Could not submit. Please try again.' : 'पेश गर्न सकिएन। कृपया पुनः प्रयास गर्नुहोस्।';
        }
    }
}

if (!function_exists('coop_public_form_gate')) {
    /**
     * Uniform gate for every public form.
     * @return array{ok:bool,silent?:bool,error?:string,block?:string}
     *
     * @param array<string,mixed> $src
     * @param list<string> $spamParts
     */
    function coop_public_form_gate(
        array $src,
        string $mathBucket,
        array $spamParts = [],
        bool $checkSpam = true,
        bool $en = false
    ): array {
        $block = coop_public_form_bot_block($src, $mathBucket, $spamParts, $checkSpam);
        if ($block === null) {
            return ['ok' => true];
        }
        if ($block === 'honeypot') {
            return ['ok' => false, 'silent' => true, 'block' => 'honeypot'];
        }
        return [
            'ok' => false,
            'block' => $block,
            'error' => coop_public_form_guard_message($block, $en),
        ];
    }
}

if (!function_exists('coop_public_form_math_payload')) {
    /** Fresh math for JSON APIs after verify. */
    function coop_public_form_math_payload(string $bucket, bool $en = false): array
    {
        $m = coop_math_challenge_issue($bucket, true);
        return [
            'a' => $m['a'],
            'b' => $m['b'],
            'prompt' => $en ? $m['prompt_en'] : $m['prompt_np'],
        ];
    }
}

if (!function_exists('coop_public_form_anti_bot_html')) {
    /**
     * Single shared UI for math + honeypot + optional Turnstile.
     * Same mathBucket → same challenge on one page (no duplicate issue).
     *
     * @param array{a?:int,b?:int,prompt_np?:string,prompt_en?:string}|null $math
     */
    function coop_public_form_anti_bot_html(
        string $mathBucket,
        string $idPrefix = 'pf',
        bool $en = false,
        string $wrapperClass = 'col-12 col-md-6',
        ?array $math = null
    ): string {
        $math = $math ?? coop_math_challenge_issue($mathBucket);
        $prompt = htmlspecialchars($en ? (string) ($math['prompt_en'] ?? '') : (string) ($math['prompt_np'] ?? ''), ENT_QUOTES, 'UTF-8');
        $ph = htmlspecialchars($en ? 'Answer' : 'जवाफ', ENT_QUOTES, 'UTF-8');
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $idPrefix) ?: 'pf';
        $wrap = htmlspecialchars($wrapperClass, ENT_QUOTES, 'UTF-8');
        $html = '<div class="' . $wrap . ' coop-anti-bot-math">'
            . '<label for="' . $id . '_math" class="form-label">' . $prompt . ' <span class="text-danger">*</span></label>'
            . '<input type="number" name="math_answer" id="' . $id . '_math" class="form-control coop-anti-bot-math-input" required'
            . ' inputmode="numeric" autocomplete="off" min="0" max="99" placeholder="' . $ph . '"'
            . ' style="max-width:8rem">'
            . '</div>';
        $html .= '<div class="coop-hp-wrap" aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;">'
            . '<label for="' . $id . '_hp">Company URL</label>'
            . '<input type="text" name="ct_hp" id="' . $id . '_hp" value="" tabindex="-1" autocomplete="off">'
            . '</div>';
        if (coop_turnstile_configured()) {
            $site = htmlspecialchars(coop_turnstile_site_key(), ENT_QUOTES, 'UTF-8');
            $html .= '<div class="col-12 coop-anti-bot-turnstile"><div class="cf-turnstile" data-sitekey="'
                . $site . '" data-theme="light"></div></div>';
            static $tsScriptPrinted = false;
            if (!$tsScriptPrinted) {
                $tsScriptPrinted = true;
                $html .= '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
            }
        }
        return $html;
    }
}
