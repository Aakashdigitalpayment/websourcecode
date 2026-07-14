<?php
/**
 * AI Chat — settings helpers (per-deployment Gemini / OpenAI / DeepSeek key)
 * Used by admin UI, api-ai-chat.php, and footer visibility.
 */

if (!function_exists('ai_chat_require_crypto')) {
    function ai_chat_require_crypto(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $path = __DIR__ . '/credentials-crypto.php';
        if (is_file($path)) {
            require_once $path;
        }
        $done = true;
    }
}

if (!function_exists('ai_chat_can_encrypt')) {
    function ai_chat_can_encrypt(): bool
    {
        ai_chat_require_crypto();
        return defined('CRED_MASTER_KEY')
            && is_string(CRED_MASTER_KEY)
            && strlen(CRED_MASTER_KEY) >= 16
            && function_exists('cred_encrypt')
            && function_exists('cred_decrypt');
    }
}

if (!function_exists('ai_chat_store_api_key')) {
    /** Persist API key (encrypt when CRED_MASTER_KEY available). Blank input = no-op. */
    function ai_chat_store_api_key(string $plain): void
    {
        $plain = trim($plain);
        if ($plain === '') {
            return;
        }
        if (ai_chat_can_encrypt()) {
            try {
                $enc = cred_encrypt($plain);
                $stored = 'enc:v1:' . $enc['iv'] . ':' . $enc['cipher'];
                updateSetting('ai_api_key', $stored);
                return;
            } catch (Throwable $e) {
                error_log('ai_chat_store_api_key encrypt: ' . $e->getMessage());
            }
        }
        updateSetting('ai_api_key', $plain);
    }
}

if (!function_exists('ai_chat_get_api_key')) {
    function ai_chat_get_api_key(): string
    {
        $raw = trim((string)getSetting('ai_api_key', ''));
        if ($raw === '') {
            return '';
        }
        if (str_starts_with($raw, 'enc:v1:')) {
            $parts = explode(':', $raw, 4);
            if (count($parts) === 4 && ai_chat_can_encrypt()) {
                try {
                    return cred_decrypt($parts[3], $parts[2]);
                } catch (Throwable $e) {
                    error_log('ai_chat_get_api_key decrypt: ' . $e->getMessage());
                    return '';
                }
            }
            return '';
        }
        return $raw;
    }
}

if (!function_exists('ai_chat_has_api_key')) {
    /** True only when a usable (decryptable) key is available. */
    function ai_chat_has_api_key(): bool
    {
        return ai_chat_get_api_key() !== '';
    }
}

if (!function_exists('ai_chat_is_enabled')) {
    function ai_chat_is_enabled(): bool
    {
        return getSetting('ai_chat_enabled', '0') === '1' && ai_chat_has_api_key();
    }
}

if (!function_exists('ai_chat_provider')) {
    function ai_chat_provider(): string
    {
        $p = strtolower(trim((string)getSetting('ai_provider', 'gemini')));
        return in_array($p, ['openai', 'gemini', 'deepseek'], true) ? $p : 'gemini';
    }
}

if (!function_exists('ai_chat_default_model')) {
    function ai_chat_default_model(?string $provider = null): string
    {
        $provider = $provider ?? ai_chat_provider();
        return match ($provider) {
            'openai' => 'gpt-4o-mini',
            'deepseek' => 'deepseek-chat',
            default => 'gemini-2.5-flash',
        };
    }
}

if (!function_exists('ai_chat_model')) {
    function ai_chat_model(): string
    {
        $m = trim((string)getSetting('ai_model', ''));
        if ($m === '') {
            $m = ai_chat_default_model(ai_chat_provider());
        }

        /* Gemini 2.0 Flash family shut down (June 2026) — remap saved admin values. */
        static $legacy = [
            'gemini-2.0-flash' => 'gemini-2.5-flash',
            'gemini-2.0-flash-001' => 'gemini-2.5-flash',
            'gemini-2.0-flash-exp' => 'gemini-2.5-flash',
            'gemini-2.0-flash-lite' => 'gemini-2.5-flash-lite',
            'gemini-2.0-flash-lite-001' => 'gemini-2.5-flash-lite',
            'gemini-1.5-flash' => 'gemini-2.5-flash',
            'gemini-1.5-flash-latest' => 'gemini-2.5-flash',
            'gemini-1.5-pro' => 'gemini-2.5-flash',
        ];
        if (isset($legacy[$m])) {
            return $legacy[$m];
        }

        return $m;
    }
}

if (!function_exists('ai_chat_welcome')) {
    function ai_chat_welcome(bool $english = false): string
    {
        if ($english) {
            $w = trim((string)getSetting('ai_welcome_en', ''));
            if ($w !== '') {
                return $w;
            }
            $name = (string)getSetting('site_name_en', getSetting('site_name', 'Cooperative'));
            return 'Hello! Ask about ' . $name . ' services, rates, notices, or branches. Answers use this website’s published data.';
        }
        $w = trim((string)getSetting('ai_welcome_np', ''));
        if ($w !== '') {
            return $w;
        }
        $name = (string)getSetting('site_name', 'सहकारी');
        return 'नमस्कार! ' . $name . ' का सेवा, ब्याजदर, सूचना वा शाखाबारे सोध्नुहोस्। जवाफ यस वेबसाइटको प्रकाशित डाटाबाट दिइन्छ।';
    }
}

if (!function_exists('ai_chat_is_sensitive_question')) {
    /**
     * Block passwords, PINs, balances, jailbreaks, and private member/admin data requests.
     */
    function ai_chat_is_sensitive_question(string $message): bool
    {
        $q = mb_strtolower(trim(strip_tags($message)));

        if (ai_chat_is_jailbreak_attempt($q)) {
            return true;
        }

        return (bool)preg_match(
            '/\b(password|passwd|pass\s*word|pin\s*code|pin\s*number|otp|one[\s-]?time|username|user\s*name|user\s*id|'
            . 'login\s*credential|admin\s*password|secret\s*key|api\s*key|private\s*key|token|'
            . 'account\s*balance|balance\s*of|my\s*balance|account\s*number|member\s*number|share\s*number|'
            . 'kyc\s*document|citizenship\s*number|national\s*id|bank\s*account\s*number|card\s*number|'
            . 'database\s*dump|sql\s*query|member\s*portal\s*password|'
            . 'पासवर्ड|पास्वर्ड|पिन|पिनकोड|ओटिपी|गोप्य|गुप्त|खाता\s*ब्यालेन्स|मेरो\s*ब्यालेन्स|'
            . 'सदस्य\s*नम्बर|खाता\s*नम्बर|शेयर\s*नम्बर|लगइन|लग\s*इन|गोप्य\s*सूचना|गुप्त\s*सूचना|'
            . 'गोप्य\s*डाटा|गुप्त\s*डाटा|निजी\s*डाटा|निजी\s*सूचना|'
            . 'सदस्य\s*पोर्टल|एडमिन\s*पासवर्ड|डाटाबेस)\b/ui',
            $q
        ) || (bool)preg_match(
            '/गोप्य\s*डाटा|गुप्त\s*डाटा|निजी\s*डाटा|मेरो\s*खाता|member\s*data|private\s*data|sensitive\s*data/ui',
            $q
        );
    }
}

if (!function_exists('ai_chat_is_jailbreak_attempt')) {
    function ai_chat_is_jailbreak_attempt(string $q): bool
    {
        return (bool)preg_match(
            '/ignore\s+(all\s+)?(previous|prior|above)\s+instructions|'
            . 'pretend\s+you\s+are|act\s+as\s+if|bypass\s+(security|rules)|'
            . 'reveal\s+(hidden|secret)|show\s+me\s+(the\s+)?(password|database)|'
            . 'नियम\s*बेवास्ता|गोप्य\s*देखाउ|पासवर्ड\s*देखाउ|डाटाबेस\s*देखाउ/ui',
            $q
        );
    }
}

if (!function_exists('ai_chat_sensitive_refusal')) {
    function ai_chat_sensitive_refusal(bool $english): string
    {
        $b = rtrim(defined('SITE_URL') ? (string)SITE_URL : '/', '/') . '/';
        $member = $b . 'member/login.php';
        $contact = $b . 'contact.php';

        if ($english) {
            $msg = 'I can only share information published on this public website. '
                . 'Passwords, PINs, OTPs, usernames, account balances, KYC details, and other private member data cannot be provided here.';
            if ($member !== '') {
                $msg .= ' For your own account, use the member portal: ' . $member;
            }
            if ($contact !== '') {
                $msg .= ' Or contact staff: ' . $contact;
            }
            return $msg;
        }

        $msg = 'म यहाँ सार्वजनिक वेबसाइटमा रहेको जानकारी मात्र दिन्छु। '
            . 'पासवर्ड, पिन, OTP, प्रयोगकर्ता नाम, खाता ब्यालेन्स, KYC वा अन्य गोप्य सदस्य डाटा यहाँबाट दिइँदैन।';
        if ($member !== '') {
            $msg .= ' आफ्नो खातासम्बन्धी कुरा सदस्य पोर्टलबाट: ' . $member;
        }
        if ($contact !== '') {
            $msg .= ' वा कर्मचारीसँग सम्पर्क: ' . $contact;
        }
        return $msg;
    }
}

if (!function_exists('ai_chat_answer_has_sensitive_leak')) {
    /** Post-check LLM output — block accidental private data in replies. */
    function ai_chat_answer_has_sensitive_leak(string $answer): bool
    {
        $a = mb_strtolower($answer);
        if (preg_match('/\b(sk-[A-Za-z0-9]{10,}|AIza[A-Za-z0-9_\-]{20,}|enc:v1:)\b/', $answer)) {
            return true;
        }
        if (preg_match('/\b(password|passwd|pin)\s*(is|:)\s*\S+/i', $answer)) {
            return true;
        }
        if (preg_match('/\b(your\s+(account|member)\s+balance|खाताको\s+ब्यालेन्स|मेरो\s+ब्यालेन्स\s*[:=])/ui', $a)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('ai_chat_answer_looks_like_prompt_leak')) {
    /** Detect when the model echoes system instructions instead of answering. */
    function ai_chat_answer_looks_like_prompt_leak(string $answer): bool
    {
        if ($answer === '') {
            return false;
        }
        return (bool)preg_match(
            '/\b(STRICT RULES|SITE CONTEXT|PUBLIC PAGES|system instruction|never invent)\b/ui',
            $answer
        ) || (bool)preg_match(
            '/PAGES below|plain text only\s*\(no markdown|Rule\s*\d+\s*:/ui',
            $answer
        );
    }
}

if (!function_exists('ai_chat_redact_secrets')) {
    /** Strip accidental secrets from text before/after LLM (never send to model or user). */
    function ai_chat_redact_secrets(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/\b(sk-[A-Za-z0-9]{10,}|AIza[A-Za-z0-9_\-]{20,}|enc:v1:[^\s]+)\b/u', '[redacted]', $text) ?? $text;
        $text = preg_replace('/\b(password|passwd|pin|otp)\s*[:=]\s*\S+/iu', '$1: [redacted]', $text) ?? $text;
        return $text;
    }
}

if (!function_exists('ai_chat_public_setting_keys')) {
    /** Whitelist of site_settings safe for public AI context (no SMTP/API secrets). */
    function ai_chat_public_setting_keys(): array
    {
        return [
            'site_name', 'site_name_en', 'phone', 'contact_phone', 'email', 'contact_email',
            'address', 'address_en', 'whatsapp', 'whatsapp_number', 'facebook', 'twitter',
            'instagram', 'youtube', 'office_hours', 'office_hours_np',
            'membership_info_np', 'membership_info_en', 'registration_steps_np',
            'chairman_name', 'ceo_name', 'ceo_designation_np', 'ceo_designation_en',
        ];
    }
}
