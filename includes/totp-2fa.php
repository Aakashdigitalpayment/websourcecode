<?php
/**
 * Google Authenticator compatible TOTP helpers.
 */

if (!function_exists('twoFaBase32Decode')) {
    function twoFaBase32Decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
        $bits = '';
        $out = '';
        $len = strlen($b32);
        for ($i = 0; $i < $len; $i++) {
            $v = strpos($alphabet, $b32[$i]);
            if ($v === false) continue;
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
            $out .= chr(bindec(substr($bits, $i, 8)));
        }
        return $out;
    }
}

if (!function_exists('twoFaGenerateSecret')) {
    function twoFaGenerateSecret(int $length = 32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }
        return $secret;
    }
}

if (!function_exists('twoFaCurrentTotp')) {
    function twoFaCurrentTotp(string $secret, ?int $slice = null): string
    {
        $key = twoFaBase32Decode($secret);
        if ($key === '') return '';
        $timeSlice = $slice ?? (int)floor(time() / 30);
        $binTime = pack('N*', 0) . pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $binTime, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $tr = substr($hash, $offset, 4);
        $value = unpack('N', $tr)[1] & 0x7FFFFFFF;
        $code = $value % 1000000;
        return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('twoFaVerifyCode')) {
    function twoFaVerifyCode(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/[^0-9]/', '', $code);
        if (strlen($code) !== 6) return false;
        $slice = (int)floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(twoFaCurrentTotp($secret, $slice + $i), $code)) return true;
        }
        return false;
    }
}

if (!function_exists('twoFaProvisioningUri')) {
    function twoFaProvisioningUri(string $issuer, string $label, string $secret): string
    {
        $issuerEnc = rawurlencode($issuer);
        $labelEnc = rawurlencode($issuer . ':' . $label);
        return "otpauth://totp/{$labelEnc}?secret={$secret}&issuer={$issuerEnc}&algorithm=SHA1&digits=6&period=30";
    }
}

if (!function_exists('twoFaQrImgTag')) {
    /**
     * <img> for the Google Authenticator setup QR, rendered client-side by assets/js/totp-qr.js.
     * Never use an external QR image API here: the otpauth:// URI contains the TOTP secret,
     * so sending it to a third party would let them generate valid 2FA codes.
     * $style is the caller's inline style (each portal keeps its own look).
     */
    function twoFaQrImgTag(string $otpauthUri, int $size = 220, string $style = ''): string
    {
        $size = max(120, min(400, $size));
        $base = rtrim(defined('SITE_URL') ? SITE_URL : '/', '/') . '/';
        $ver = static function (string $rel): string {
            return function_exists('coopThemeCssVer') ? coopThemeCssVer($rel) : '1';
        };
        $h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        return '<img hidden data-otpauth="' . $h($otpauthUri) . '" alt="Google Authenticator QR"'
            . ' width="' . $size . '" height="' . $size . '"'
            . ($style !== '' ? ' style="' . $h($style) . '"' : '') . '>'
            . '<script src="' . $h($base . 'assets/vendor/qrcode-generator.js?v=' . $ver('assets/vendor/qrcode-generator.js')) . '"></script>'
            . '<script src="' . $h($base . 'assets/js/totp-qr.js?v=' . $ver('assets/js/totp-qr.js')) . '"></script>';
    }
}

if (!function_exists('twoFaGenerateBackupCodes')) {
    function twoFaGenerateBackupCodes(int $count = 8): array
    {
        $plain = [];
        $hashes = [];
        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $plain[] = $code;
            $hashes[] = password_hash($code, PASSWORD_DEFAULT);
        }
        return ['plain' => $plain, 'hashes' => $hashes];
    }
}

if (!function_exists('twoFaConsumeBackupCode')) {
    function twoFaConsumeBackupCode(string $input, array $hashes): array
    {
        $normalized = strtoupper(trim($input));
        if ($normalized === '') return ['ok' => false, 'hashes' => $hashes];
        foreach ($hashes as $idx => $h) {
            if (is_string($h) && password_verify($normalized, $h)) {
                unset($hashes[$idx]);
                return ['ok' => true, 'hashes' => array_values($hashes)];
            }
        }
        return ['ok' => false, 'hashes' => $hashes];
    }
}
