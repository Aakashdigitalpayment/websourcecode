<?php
/**
 * ════════════════════════════════════════════════════════════
 * Web Push VAPID Helper — pure PHP, no Composer required
 * Requires: openssl + curl PHP extensions (standard on most hosts)
 *
 * Strategy: VAPID-authenticated push with NO payload encryption.
 * The SW receives the push, shows a branded notification, and
 * members see the full message in the bell when they open the app.
 * ════════════════════════════════════════════════════════════
 */

/* ── VAPID key pair (generated once per install) ──────────────────
   These are the project-specific VAPID credentials.
   To regenerate: run tools/generate-vapid.php on your server.   */
if (!defined('COOP_VAPID_PUBLIC_KEY')) {
    define('COOP_VAPID_PUBLIC_KEY',
        'BGBgAPEKj2nvCF8aAxIn1Vw1rMo_2YQKFsR2W2E-L38e1HDA8QLIzMgtjz9Kvze7-rfVzj8_c6Glrd-KEtgxDUo'
    );
}
if (!defined('COOP_VAPID_PRIVATE_PEM')) {
    define('COOP_VAPID_PRIVATE_PEM',
        "-----BEGIN EC PRIVATE KEY-----\n" .
        "MHcCAQEEIGq4QLbnsW8dGTUchWXlUxaFOT05u45rMoKD5hBIyJbioAoGCCqGSM49\n" .
        "AwEHoUQDQgAEYGAA8QqPae8IXxoDEifVXDWsyj/ZhAoWxHZbYT4vfx7UcMDxAsjM\n" .
        "yC2PP0q/N7v6t9XOPz9zoaWt34oS2DENSg==\n" .
        "-----END EC PRIVATE KEY-----"
    );
}
/* Admin contact for VAPID 'sub' claim — update to your email */
if (!defined('COOP_VAPID_SUBJECT')) {
    define('COOP_VAPID_SUBJECT', function_exists('getSetting')
        ? ('mailto:' . (getSetting('admin_email', '') ?: 'admin@aakashcooperative.com'))
        : 'mailto:admin@aakashcooperative.com'
    );
}

/* ════════════════════════════════════════════════════════════
   DB tables
   ════════════════════════════════════════════════════════════ */

function ensurePushTables(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS member_push_subscriptions (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            member_id  INT UNSIGNED NOT NULL,
            endpoint   TEXT NOT NULL,
            p256dh     VARCHAR(512) NOT NULL,
            auth       VARCHAR(64)  NOT NULL,
            user_agent VARCHAR(255) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_endpoint (endpoint(255)),
            INDEX      idx_member  (member_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS member_push_log (
            id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            notif_id  INT UNSIGNED DEFAULT NULL,
            member_id INT UNSIGNED DEFAULT NULL,
            endpoint  VARCHAR(255) NOT NULL DEFAULT '',
            http_code SMALLINT UNSIGNED DEFAULT 0,
            sent_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notif  (notif_id),
            INDEX idx_member (member_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/* ════════════════════════════════════════════════════════════
   VAPID JWT signing (ECDSA P-256 / ES256)
   ════════════════════════════════════════════════════════════ */

/** URL-safe base64 without padding. */
function vapidB64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Convert DER-encoded ECDSA signature → raw 64-byte r‖s.
 * OpenSSL produces DER; the VAPID JWT needs raw r‖s.
 */
function vapidDerToRaw(string $der): string
{
    $offset = 2;                           /* skip SEQUENCE tag + length */
    if (strlen($der) > 1 && (ord($der[1]) & 0x80)) {
        $offset += ord($der[1]) & 0x7f;   /* long-form length */
    }

    /* r component */
    $offset++;                             /* INTEGER tag */
    $rLen = ord($der[$offset++]);
    if ($rLen > 32 && isset($der[$offset]) && ord($der[$offset]) === 0x00) {
        $offset++; $rLen--;                /* strip positive-sign padding */
    }
    $r = substr($der, $offset, $rLen);
    $offset += $rLen;

    /* s component */
    $offset++;                             /* INTEGER tag */
    $sLen = ord($der[$offset++]);
    if ($sLen > 32 && isset($der[$offset]) && ord($der[$offset]) === 0x00) {
        $offset++; $sLen--;
    }
    $s = substr($der, $offset, $sLen);

    /* Pad each to exactly 32 bytes */
    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

    return $r . $s;
}

/**
 * Build and sign a VAPID JWT for the given push endpoint.
 *
 * @param  string $endpoint  Full push subscription endpoint URL
 * @return string            Signed compact JWT (header.payload.sig)
 * @throws RuntimeException  If private key cannot be loaded
 */
function vapidBuildJwt(string $endpoint): string
{
    $parsed   = parse_url($endpoint);
    $audience = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');

    $header  = vapidB64url((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload = vapidB64url((string) json_encode([
        'aud' => $audience,
        'exp' => time() + 43200,           /* 12 h */
        'sub' => COOP_VAPID_SUBJECT,
    ]));
    $message = $header . '.' . $payload;

    $pk = openssl_pkey_get_private(COOP_VAPID_PRIVATE_PEM);
    if (!$pk) {
        throw new \RuntimeException('VAPID: cannot load private key — ' . openssl_error_string());
    }
    openssl_sign($message, $derSig, $pk, OPENSSL_ALGO_SHA256);

    return $message . '.' . vapidB64url(vapidDerToRaw($derSig));
}

/* ════════════════════════════════════════════════════════════
   Sending a push notification
   ════════════════════════════════════════════════════════════ */

/**
 * Send an empty-body VAPID push to one endpoint.
 * The browser wakes up the SW which shows a branded notification.
 *
 * @param  string $endpoint  Subscription endpoint URL
 * @param  int    $ttl       Seconds the push service should retry (default 24 h)
 * @return array  ['ok' => bool, 'code' => int, 'msg' => string]
 */
function vapidSend(string $endpoint, int $ttl = 86400): array
{
    try {
        $jwt  = vapidBuildJwt($endpoint);
        $auth = 'vapid t=' . $jwt . ',k=' . COOP_VAPID_PUBLIC_KEY;

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: '  . $auth,
                'Content-Type: application/octet-stream',
                'Content-Length: 0',
                'TTL: '            . $ttl,
                'Urgency: normal',
            ],
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = (string) curl_exec($ch);
        $code = (int)    curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = (string) curl_error($ch);
        curl_close($ch);

        $ok = in_array($code, [200, 201, 202], true);
        return ['ok' => $ok, 'code' => $code, 'msg' => $err ?: substr($body, 0, 200)];

    } catch (\Throwable $e) {
        return ['ok' => false, 'code' => 0, 'msg' => $e->getMessage()];
    }
}

/**
 * Broadcast a push to all subscriptions (or one member's subscriptions).
 * Expired subscriptions (HTTP 410) are automatically removed.
 *
 * @param  PDO      $db
 * @param  int|null $memberId   null = all subscribed members
 * @param  int|null $notifId    member_notifications row id (for logging)
 * @return array  ['sent'=>int, 'failed'=>int, 'removed'=>int]
 */
function broadcastWebPush(PDO $db, ?int $memberId = null, ?int $notifId = null): array
{
    ensurePushTables($db);

    if ($memberId !== null) {
        $stmt = $db->prepare(
            'SELECT id, member_id, endpoint FROM member_push_subscriptions WHERE member_id = ?'
        );
        $stmt->execute([$memberId]);
    } else {
        $stmt = $db->query(
            'SELECT id, member_id, endpoint FROM member_push_subscriptions'
        );
    }
    $subs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $logStmt = $db->prepare(
        'INSERT INTO member_push_log (notif_id, member_id, endpoint, http_code, sent_at)
         VALUES (?, ?, ?, ?, NOW())'
    );
    $delStmt = $db->prepare('DELETE FROM member_push_subscriptions WHERE id = ?');

    $sent = $failed = $removed = 0;
    foreach ($subs as $sub) {
        $res = vapidSend((string) $sub['endpoint']);

        if ($res['ok']) {
            $sent++;
        } elseif ($res['code'] === 410) {
            /* 410 Gone — subscription expired or revoked */
            $delStmt->execute([(int) $sub['id']]);
            $removed++;
            continue;
        } else {
            $failed++;
        }

        try {
            $logStmt->execute([
                $notifId,
                (int) $sub['member_id'],
                substr((string) $sub['endpoint'], 0, 255),
                $res['code'],
            ]);
        } catch (\Throwable $ignored) {}
    }

    return ['sent' => $sent, 'failed' => $failed, 'removed' => $removed];
}
