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
