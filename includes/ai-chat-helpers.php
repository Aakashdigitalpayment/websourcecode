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
