<?php
/**
 * 🔒 Credential Encryption (AES-256-CBC)
 * ─────────────────────────────────────────────────────────────
 * `config.php` मा यो line थप्नुहोस् (एकपटक मात्र):
 *
 *   define('CRED_MASTER_KEY', 'यहाँ-32-character-को-random-string');
 *
 * Random key generate गर्न:
 *   php -r "echo bin2hex(random_bytes(16));"
 *
 * ⚠️ चेतावनी: एकपटक set गरेपछि key कहिल्यै नबदल्नुहोस् —
 * बदले पुराना saved passwords decrypt हुँदैनन्।
 */

if (!function_exists('cred_encrypt')) {
    function cred_encrypt(string $plain): array {
        if (!defined('CRED_MASTER_KEY') || strlen(CRED_MASTER_KEY) < 16) {
            throw new RuntimeException('CRED_MASTER_KEY config.php मा define गर्नुहोस् (कम्तीमा 16 chars)');
        }
        $key = hash('sha256', CRED_MASTER_KEY, true);
        $iv  = openssl_random_pseudo_bytes(16);
        $enc = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($enc === false) throw new RuntimeException('Encryption failed');
        return [
            'cipher' => base64_encode($enc),
            'iv'     => bin2hex($iv),
        ];
    }
}

if (!function_exists('cred_decrypt')) {
    function cred_decrypt(string $cipherB64, string $ivHex): string {
        if (!defined('CRED_MASTER_KEY')) {
            throw new RuntimeException('CRED_MASTER_KEY missing');
        }
        $key = hash('sha256', CRED_MASTER_KEY, true);
        $iv  = hex2bin($ivHex);
        $dec = openssl_decrypt(base64_decode($cipherB64), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($dec === false) throw new RuntimeException('Decryption failed');
        return $dec;
    }
}

if (!function_exists('cred_log_action')) {
    /**
     * हरेक view/copy/open लाई audit log मा लेख्ने।
     */
    function cred_log_action(int $credentialId, string $action): void {
        try {
            $db = getDB();
            $stmt = $db->prepare(
                "INSERT INTO office_credentials_log
                 (credential_id, admin_id, admin_username, action, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $credentialId,
                (int)($_SESSION['admin_id'] ?? 0),
                $_SESSION['admin_username'] ?? '',
                $action,
                $_SERVER['REMOTE_ADDR'] ?? '',
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250),
            ]);
        } catch (\Throwable $e) {
            error_log('[cred-log] ' . $e->getMessage());
        }
    }
}
