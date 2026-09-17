<?php
/**
 * Public member-only unlock for Reports + Institutional Profile.
 * Unlock via logged-in approved member OR sadasyata_number match (rate-limited).
 */
declare(strict_types=1);

if (!defined('COOP_PUBLIC_MEMBER_ACCESS_LOADED')) {
    define('COOP_PUBLIC_MEMBER_ACCESS_LOADED', true);
}

const COOP_PMA_SESSION_KEY = 'coop_pma_unlocked';
const COOP_PMA_GUARD_KEY = 'coop_pma_guard';
const COOP_PMA_MAX_FAILS = 8;
const COOP_PMA_BLOCK_SECONDS = 900;

/**
 * Normalize access_level DB/POST value.
 */
function coopAccessLevelNormalize(?string $value): string
{
    $v = strtolower(trim((string) $value));
    return $v === 'member' ? 'member' : 'none';
}

function coopMemberAccessIsAdmin(): bool
{
    return function_exists('isAdminLoggedIn') && isAdminLoggedIn();
}

/**
 * True when visitor may open member-gated actions/files.
 */
function coopMemberAccessUnlocked(): bool
{
    if (coopMemberAccessIsAdmin()) {
        return true;
    }
    if (!empty($_SESSION[COOP_PMA_SESSION_KEY])) {
        return true;
    }
    if (file_exists(__DIR__ . '/member-auth.php')) {
        require_once __DIR__ . '/member-auth.php';
    }
    if (function_exists('memberIsLoggedIn') && memberIsLoggedIn()) {
        $m = function_exists('currentMember') ? currentMember() : null;
        if (is_array($m) && !empty($m['id'])) {
            $_SESSION[COOP_PMA_SESSION_KEY] = [
                'at' => time(),
                'via' => 'login',
                'member_id' => (int) $m['id'],
            ];
            return true;
        }
    }
    return false;
}

/**
 * Whether a row with this access_level may be acted on (view/download/share).
 */
function coopMemberAccessCanOpen(string $accessLevel): bool
{
    if (coopAccessLevelNormalize($accessLevel) !== 'member') {
        return true;
    }
    return coopMemberAccessUnlocked();
}

function coopMemberAccessClientIp(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return preg_replace('/[^0-9a-fA-F:.]/', '', $ip) ?: '0.0.0.0';
}

/**
 * @return array{blocked:bool,retry_after:int}
 */
function coopMemberAccessGuardState(): array
{
    if (!isset($_SESSION[COOP_PMA_GUARD_KEY]) || !is_array($_SESSION[COOP_PMA_GUARD_KEY])) {
        $_SESSION[COOP_PMA_GUARD_KEY] = ['fails' => 0, 'blocked_until' => 0];
    }
    $g = $_SESSION[COOP_PMA_GUARD_KEY];
    $until = (int) ($g['blocked_until'] ?? 0);
    $now = time();
    if ($until > $now) {
        return ['blocked' => true, 'retry_after' => $until - $now];
    }
    return ['blocked' => false, 'retry_after' => 0];
}

function coopMemberAccessNormalizeSadasyata(string $raw): string
{
    $id = trim($raw);
    $id = preg_replace('/\s+/u', '', $id) ?? '';
    /* Allow common membership id characters only */
    $id = preg_replace('/[^\p{L}\p{N}\-\/.]/u', '', $id) ?? '';
    return mb_substr($id, 0, 50);
}

/**
 * Attempt unlock by सदस्यता नम्बर. Generic errors only (no enumeration).
 *
 * @return array{ok:bool,error?:string}
 */
function coopTryUnlockBySadasyata(string $rawId): array
{
    $guard = coopMemberAccessGuardState();
    if ($guard['blocked']) {
        return [
            'ok' => false,
            'error' => function_exists('isEnglish') && isEnglish()
                ? 'Too many attempts. Please try again later.'
                : 'धेरै पटक प्रयास भयो। केही समयपछि फेरि प्रयास गर्नुहोस्।',
        ];
    }

    $sadasyata = coopMemberAccessNormalizeSadasyata($rawId);
    if ($sadasyata === '' || mb_strlen($sadasyata) < 2) {
        coopMemberAccessRegisterFail();
        return [
            'ok' => false,
            'error' => function_exists('isEnglish') && isEnglish()
                ? 'Enter a valid membership number.'
                : 'मान्य सदस्यता नम्बर लेख्नुहोस्।',
        ];
    }

    try {
        $db = function_exists('getDB') ? getDB() : null;
        if (!$db instanceof PDO) {
            return [
                'ok' => false,
                'error' => function_exists('isEnglish') && isEnglish()
                    ? 'Service temporarily unavailable.'
                    : 'सेवा अस्थायी रूपमा उपलब्ध छैन।',
            ];
        }
        $st = $db->prepare(
            "SELECT id FROM members
             WHERE sadasyata_number = ?
               AND is_active = 1
               AND approval_status = 'approved'
             LIMIT 1"
        );
        $st->execute([$sadasyata]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            coopMemberAccessRegisterFail();
            return [
                'ok' => false,
                'error' => function_exists('isEnglish') && isEnglish()
                    ? 'Membership number could not be verified.'
                    : 'सदस्यता नम्बर प्रमाणित हुन सकेन।',
            ];
        }
        $_SESSION[COOP_PMA_SESSION_KEY] = [
            'at' => time(),
            'via' => 'sadasyata',
            'member_id' => (int) ($row['id'] ?? 0),
        ];
        $_SESSION[COOP_PMA_GUARD_KEY] = ['fails' => 0, 'blocked_until' => 0];
        return ['ok' => true];
    } catch (Throwable $e) {
        error_log('[public-member-access] ' . $e->getMessage());
        return [
            'ok' => false,
            'error' => function_exists('isEnglish') && isEnglish()
                ? 'Verification failed. Please try again.'
                : 'प्रमाणीकरण असफल। फेरि प्रयास गर्नुहोस्।',
        ];
    }
}

function coopMemberAccessRegisterFail(): void
{
    if (!isset($_SESSION[COOP_PMA_GUARD_KEY]) || !is_array($_SESSION[COOP_PMA_GUARD_KEY])) {
        $_SESSION[COOP_PMA_GUARD_KEY] = ['fails' => 0, 'blocked_until' => 0];
    }
    $fails = (int) ($_SESSION[COOP_PMA_GUARD_KEY]['fails'] ?? 0) + 1;
    $blockedUntil = 0;
    if ($fails >= COOP_PMA_MAX_FAILS) {
        $blockedUntil = time() + COOP_PMA_BLOCK_SECONDS;
        $fails = 0;
    }
    $_SESSION[COOP_PMA_GUARD_KEY] = [
        'fails' => $fails,
        'blocked_until' => $blockedUntil,
        'ip' => coopMemberAccessClientIp(),
    ];
}

/**
 * Handle unlock POST. Returns flash message keys for the page.
 *
 * @return array{handled:bool,ok:bool,error:string}
 */
function coopMemberAccessHandleUnlockPost(): array
{
    $out = ['handled' => false, 'ok' => false, 'error' => ''];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return $out;
    }
    if ((string) ($_POST['coop_pma_action'] ?? '') !== 'unlock') {
        return $out;
    }
    $out['handled'] = true;

    if (function_exists('verifyCSRFToken') && !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $out['error'] = function_exists('isEnglish') && isEnglish()
            ? 'Security check failed. Please refresh and try again.'
            : 'सुरक्षा जाँच असफल। पेज रिफ्रेस गरी फेरि प्रयास गर्नुहोस्।';
        return $out;
    }

    $result = coopTryUnlockBySadasyata((string) ($_POST['sadasyata_number'] ?? ''));
    if (!empty($result['ok'])) {
        $out['ok'] = true;
        return $out;
    }
    $out['error'] = (string) ($result['error'] ?? '');
    return $out;
}

/**
 * Absolute filesystem path under uploads, or empty.
 */
function coopMemberAccessResolveAbsolutePath(?string $storedPath): string
{
    $rel = function_exists('coop_resolve_stored_upload_rel')
        ? coop_resolve_stored_upload_rel($storedPath)
        : '';
    if ($rel === '') {
        $path = str_replace('\\', '/', trim((string) $storedPath));
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '..')) {
            return '';
        }
        if (!preg_match('#^assets/uploads/#i', $path)) {
            return '';
        }
        $rel = $path;
    }
    $root = defined('ROOT_PATH') ? rtrim((string) ROOT_PATH, '/\\') : dirname(__DIR__);
    $full = $root . '/' . ltrim($rel, '/');
    $real = is_file($full) ? realpath($full) : false;
    $uploads = realpath($root . '/assets/uploads');
    if ($real === false || $uploads === false) {
        return '';
    }
    $uploadsPrefix = $uploads . DIRECTORY_SEPARATOR;
    if (!str_starts_with($real, $uploadsPrefix)) {
        return '';
    }
    return $real;
}

/**
 * Stream a local upload file (inline or attachment).
 */
function coopMemberAccessStreamFile(string $absolutePath, string $downloadName, bool $asDownload): void
{
    $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
    $mimeMap = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ];
    if (!isset($mimeMap[$ext])) {
        http_response_code(415);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Unsupported file type.';
        exit;
    }
    $safeName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $downloadName) ?: 'document';
    if (!str_ends_with(strtolower($safeName), '.' . $ext)) {
        $safeName .= '.' . $ext;
    }

    header('Content-Type: ' . $mimeMap[$ext]);
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    if ($asDownload) {
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
    } else {
        header('Content-Disposition: inline; filename="' . $safeName . '"');
    }
    $size = filesize($absolutePath);
    if ($size !== false) {
        header('Content-Length: ' . (string) $size);
    }
    readfile($absolutePath);
    exit;
}

/**
 * Proxy URL for a gated report/IP file.
 */
function coopMemberAccessFileUrl(string $endpoint, int $id, bool $download = false): string
{
    $base = rtrim((string) (defined('SITE_URL') ? SITE_URL : ''), '/');
    $q = 'id=' . $id . ($download ? '&dl=1' : '');
    return $base . '/' . ltrim($endpoint, '/') . '?' . $q;
}

/**
 * Compact unlock form markup (EN/NP).
 */
function coopMemberAccessUnlockFormHtml(string $returnPath = '', string $extraClass = ''): string
{
    $en = function_exists('isEnglish') && isEnglish();
    $title = $en ? 'Members only' : 'सदस्य मात्र';
    $hint = $en
        ? 'Enter your membership number to view, download, or share.'
        : 'हेर्न, डाउनलोड वा सेयर गर्न आफ्नो सदस्यता नम्बर लेख्नुहोस्।';
    $label = $en ? 'Membership number' : 'सदस्यता नम्बर';
    $btn = $en ? 'Unlock' : 'अनलक गर्नुहोस्';
    $csrf = function_exists('csrfField') ? csrfField() : '';
    $ret = htmlspecialchars($returnPath !== '' ? $returnPath : (string) ($_SERVER['REQUEST_URI'] ?? ''), ENT_QUOTES, 'UTF-8');
    $cls = trim('coop-pma-unlock ' . $extraClass);

    return '<form method="post" class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '" data-testid="coop-pma-unlock-form">'
        . $csrf
        . '<input type="hidden" name="coop_pma_action" value="unlock">'
        . '<input type="hidden" name="coop_pma_return" value="' . $ret . '">'
        . '<div class="coop-pma-unlock-head">'
        . '<i class="lucide-icon" data-lucide="lock" aria-hidden="true"></i>'
        . '<strong>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</strong>'
        . '</div>'
        . '<p class="coop-pma-unlock-hint">' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<label class="coop-pma-unlock-label" for="coop_pma_sadasyata">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label>'
        . '<div class="coop-pma-unlock-row">'
        . '<input type="text" name="sadasyata_number" id="coop_pma_sadasyata" class="form-control coop-pma-unlock-input" '
        . 'autocomplete="username" required maxlength="50" '
        . 'placeholder="' . htmlspecialchars($en ? 'e.g. 1234' : 'उदा. १२३४', ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit" class="btn btn-primary coop-pma-unlock-btn">' . htmlspecialchars($btn, ENT_QUOTES, 'UTF-8') . '</button>'
        . '</div>'
        . '</form>';
}
