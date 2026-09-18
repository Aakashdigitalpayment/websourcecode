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
 * Session + IP-keyed rate guard (peek only — do not increment here).
 *
 * @return array{blocked:bool,retry_after:int}
 */
function coopMemberAccessGuardState(): array
{
    $ip = coopMemberAccessClientIp();

    /* Peek shared rate_* session key without consuming a slot */
    $rateKey = 'rate_coop_pma_unlock_' . $ip;
    if (isset($_SESSION[$rateKey]) && is_array($_SESSION[$rateKey])) {
        $r = $_SESSION[$rateKey];
        $elapsed = time() - (int) ($r['time'] ?? 0);
        if ($elapsed <= COOP_PMA_BLOCK_SECONDS && (int) ($r['count'] ?? 0) > COOP_PMA_MAX_FAILS) {
            return [
                'blocked' => true,
                'retry_after' => max(1, COOP_PMA_BLOCK_SECONDS - $elapsed),
            ];
        }
    }

    if (!isset($_SESSION[COOP_PMA_GUARD_KEY]) || !is_array($_SESSION[COOP_PMA_GUARD_KEY])) {
        $_SESSION[COOP_PMA_GUARD_KEY] = ['fails' => 0, 'blocked_until' => 0, 'ip' => $ip];
    }
    $g = $_SESSION[COOP_PMA_GUARD_KEY];
    if (($g['ip'] ?? '') !== '' && (string) ($g['ip'] ?? '') !== $ip) {
        $_SESSION[COOP_PMA_GUARD_KEY] = ['fails' => 0, 'blocked_until' => 0, 'ip' => $ip];
        $g = $_SESSION[COOP_PMA_GUARD_KEY];
    }
    $until = (int) ($g['blocked_until'] ?? 0);
    $now = time();
    if ($until > $now) {
        return ['blocked' => true, 'retry_after' => $until - $now];
    }
    return ['blocked' => false, 'retry_after' => 0];
}

function coopMemberAccessNormalizeSadasyata(string $raw): string
{
    if (function_exists('memberSsotNormalizeId')) {
        return memberSsotNormalizeId($raw);
    }
    if (!is_file(__DIR__ . '/member-ssot.php')) {
        $id = trim($raw);
        $id = preg_replace('/\s+/u', '', $id) ?? '';
        return strtoupper(mb_substr($id, 0, 50));
    }
    require_once __DIR__ . '/member-ssot.php';
    if (function_exists('memberSsotNormalizeId')) {
        return memberSsotNormalizeId($raw);
    }
    return strtoupper(trim($raw));
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

        /* SSOT match: Latin + legacy Devanagari-digit Member IDs */
        $variants = function_exists('memberSsotIdLookupVariants')
            ? memberSsotIdLookupVariants($sadasyata)
            : [$sadasyata];
        if ($variants === []) {
            coopMemberAccessRegisterFail();
            return [
                'ok' => false,
                'error' => function_exists('isEnglish') && isEnglish()
                    ? 'Enter a valid membership number.'
                    : 'मान्य सदस्यता नम्बर लेख्नुहोस्।',
            ];
        }
        $ph = implode(',', array_fill(0, count($variants), '?'));
        $st = $db->prepare(
            "SELECT id FROM members
             WHERE (UPPER(TRIM(sadasyata_number)) IN ({$ph}) OR TRIM(sadasyata_number) IN ({$ph}))
               AND is_active = 1
               AND approval_status = 'approved'
             LIMIT 1"
        );
        $st->execute(array_merge($variants, $variants));
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
        $_SESSION[COOP_PMA_GUARD_KEY] = [
            'fails' => 0,
            'blocked_until' => 0,
            'ip' => coopMemberAccessClientIp(),
        ];
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
    $ip = coopMemberAccessClientIp();
    /* IP-keyed counter via shared helper (increments) */
    if (function_exists('checkRateLimit')) {
        checkRateLimit('coop_pma_unlock', COOP_PMA_MAX_FAILS, COOP_PMA_BLOCK_SECONDS);
    }

    if (!isset($_SESSION[COOP_PMA_GUARD_KEY]) || !is_array($_SESSION[COOP_PMA_GUARD_KEY])) {
        $_SESSION[COOP_PMA_GUARD_KEY] = ['fails' => 0, 'blocked_until' => 0, 'ip' => $ip];
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
        'ip' => $ip,
    ];
}

/**
 * Safe same-origin return path for unlock POST (allowlist).
 */
function coopMemberAccessSafeReturnPath(?string $raw): string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '';
    }
    /* Absolute SITE_URL → path+query */
    $site = rtrim((string) (defined('SITE_URL') ? SITE_URL : ''), '/');
    if ($site !== '' && str_starts_with($raw, $site . '/')) {
        $raw = substr($raw, strlen($site));
    }
    if ($raw === '' || $raw[0] !== '/') {
        /* Relative page name */
        if (!preg_match('#^[a-z0-9][a-z0-9._/-]*$#i', $raw)) {
            return '';
        }
        $raw = '/' . ltrim($raw, '/');
    }
    $parts = parse_url($raw);
    if ($parts === false || isset($parts['host']) || isset($parts['scheme'])) {
        return '';
    }
    $path = (string) ($parts['path'] ?? '');
    $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
    if (str_contains($path, '..')) {
        return '';
    }
    $allowed = [
        '/reports.php',
        '/institutional-profile.php',
    ];
    if (!in_array($path, $allowed, true)) {
        return '';
    }
    $query = (string) ($parts['query'] ?? '');
    if ($query === '') {
        return $path;
    }
    parse_str($query, $qs);
    $safeQs = [];
    if ($path === '/reports.php') {
        if (!empty($qs['type']) && preg_match('/^[a-z_]+$/', (string) $qs['type'])) {
            $safeQs['type'] = (string) $qs['type'];
        }
        if (!empty($qs['year']) && preg_match('/^\d{4}\/\d{2}$/', (string) $qs['year'])) {
            $safeQs['year'] = (string) $qs['year'];
        }
        if (!empty($qs['id']) && (int) $qs['id'] > 0) {
            $safeQs['id'] = (int) $qs['id'];
        }
    } elseif ($path === '/institutional-profile.php') {
        if (!empty($qs['id']) && (int) $qs['id'] > 0) {
            $safeQs['id'] = (int) $qs['id'];
        }
    }
    return $safeQs === [] ? $path : ($path . '?' . http_build_query($safeQs));
}

/**
 * Handle unlock POST. Returns flash message keys for the page.
 *
 * @return array{handled:bool,ok:bool,error:string,return?:string}
 */
function coopMemberAccessHandleUnlockPost(): array
{
    $out = ['handled' => false, 'ok' => false, 'error' => '', 'return' => ''];
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

    $ret = coopMemberAccessSafeReturnPath((string) ($_POST['coop_pma_return'] ?? ''));
    $out['return'] = $ret;

    $result = coopTryUnlockBySadasyata((string) ($_POST['sadasyata_number'] ?? ''));
    if (!empty($result['ok'])) {
        $out['ok'] = true;
        return $out;
    }
    $out['error'] = (string) ($result['error'] ?? '');
    return $out;
}

/**
 * True when unlock should respond with JSON (no full-page POST round-trip).
 */
function coopMemberAccessWantsAjax(): bool
{
    if (!empty($_POST['coop_pma_ajax'])) {
        return true;
    }
    $xrw = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    if (strcasecmp($xrw, 'XMLHttpRequest') === 0) {
        return true;
    }
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    return str_contains($accept, 'application/json');
}

/**
 * Exit with JSON unlock result (AJAX).
 *
 * @param array{handled?:bool,ok?:bool,error?:string,return?:string} $pmaUnlock
 */
function coopMemberAccessJsonRespond(array $pmaUnlock): void
{
    $ok = !empty($pmaUnlock['ok']);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    http_response_code($ok ? 200 : 422);
    echo json_encode([
        'ok' => $ok,
        'unlocked' => $ok,
        'error' => (string) ($pmaUnlock['error'] ?? ''),
        'return' => (string) ($pmaUnlock['return'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Process unlock POST and either JSON-exit (AJAX) or return result for classic POST.
 * On classic success, caller should redirect.
 *
 * @return array{handled:bool,ok:bool,error:string,return?:string}
 */
function coopMemberAccessProcessUnlockRequest(): array
{
    $pmaUnlock = coopMemberAccessHandleUnlockPost();
    if (!empty($pmaUnlock['handled']) && coopMemberAccessWantsAjax()) {
        coopMemberAccessJsonRespond($pmaUnlock);
    }
    return $pmaUnlock;
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
 * Facebook / Instagram / Line in-app browsers (not link-preview crawlers).
 */
function coopMemberAccessIsSocialInAppBrowser(?string $ua = null): bool
{
    $ua = $ua ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') {
        return false;
    }
    /* FBAN/FBAV/FB_IAB = Facebook app WebView */
    return (bool) preg_match('/FBAN|FBAV|FB_IAB|Instagram|Line\//i', $ua);
}

/**
 * Social link-preview crawlers that should land on HTML (for OG), not PDF bytes.
 */
function coopMemberAccessIsSocialLinkCrawler(?string $ua = null): bool
{
    $ua = $ua ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') {
        return false;
    }
    return (bool) preg_match('/facebookexternalhit|Facebot|Twitterbot|LinkedInBot|Slackbot|Discordbot|WhatsApp/i', $ua);
}

/**
 * Bounce social in-app browsers + preview crawlers to an HTML page before streaming a PDF/doc.
 * Call early (before DB) so cold Facebook taps never hang, and crawlers get OG HTML.
 */
function coopMemberAccessBounceSocialInAppToPage(string $pageScript, int $id): void
{
    if ($id < 1) {
        return;
    }
    if (!coopMemberAccessIsSocialInAppBrowser() && !coopMemberAccessIsSocialLinkCrawler()) {
        return;
    }
    $page = basename(str_replace('\\', '/', $pageScript));
    $page = preg_replace('/[^a-zA-Z0-9._-]/', '', $page) ?: '';
    if ($page === '' || !str_ends_with(strtolower($page), '.php')) {
        return;
    }
    $to = rtrim((string) (defined('SITE_URL') ? SITE_URL : ''), '/') . '/' . $page . '?id=' . $id;
    header('Location: ' . $to, true, 302);
    exit;
}

/**
 * Compact unlock form markup (EN/NP). Unique input ids when many cards on one page.
 */
function coopMemberAccessUnlockFormHtml(string $returnPath = '', string $extraClass = '', string $idSuffix = ''): string
{
    static $formSeq = 0;
    $formSeq++;
    $suffix = $idSuffix !== '' ? preg_replace('/[^a-zA-Z0-9_-]/', '', $idSuffix) : ('f' . $formSeq);
    if ($suffix === '') {
        $suffix = 'f' . $formSeq;
    }
    $inputId = 'coop_pma_sadasyata_' . $suffix;

    $en = function_exists('isEnglish') && isEnglish();
    $title = $en ? 'Members only' : 'सदस्य मात्र';
    $hint = $en
        ? 'Enter your membership number to view or download.'
        : 'हेर्न वा डाउनलोड गर्न आफ्नो सदस्यता नम्बर लेख्नुहोस्।';
    $label = $en ? 'Membership number' : 'सदस्यता नम्बर';
    $btn = $en ? 'Unlock' : 'अनलक गर्नुहोस्';
    $csrf = function_exists('csrfField') ? csrfField() : '';

    $defaultRet = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $safeRet = coopMemberAccessSafeReturnPath($returnPath !== '' ? $returnPath : $defaultRet);
    if ($safeRet === '') {
        $safeRet = coopMemberAccessSafeReturnPath($defaultRet);
    }
    $ret = htmlspecialchars($safeRet !== '' ? $safeRet : '/reports.php', ENT_QUOTES, 'UTF-8');
    $cls = trim('coop-pma-unlock ' . $extraClass);

    return '<form method="post" class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '" data-testid="coop-pma-unlock-form" data-coop-pma-ajax="1">'
        . $csrf
        . '<input type="hidden" name="coop_pma_action" value="unlock">'
        . '<input type="hidden" name="coop_pma_return" value="' . $ret . '">'
        . '<div class="coop-pma-unlock-head">'
        . '<i class="lucide-icon" data-lucide="lock" aria-hidden="true"></i>'
        . '<strong>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</strong>'
        . '</div>'
        . '<p class="coop-pma-unlock-hint">' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<div class="coop-pma-error" role="alert" hidden data-coop-pma-error></div>'
        . '<label class="coop-pma-unlock-label" for="' . htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label>'
        . '<div class="coop-pma-unlock-row">'
        . '<input type="text" name="sadasyata_number" id="' . htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') . '" '
        . 'class="form-control coop-pma-unlock-input" autocomplete="username" required maxlength="50" '
        . 'placeholder="' . htmlspecialchars($en ? 'e.g. 1234' : 'उदा. १२३४', ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit" class="btn btn-primary coop-pma-unlock-btn">' . htmlspecialchars($btn, ENT_QUOTES, 'UTF-8') . '</button>'
        . '</div>'
        . '</form>'
        . coopMemberAccessUnlockClientScriptOnce();
}

/**
 * One-time AJAX unlock script (no full page reload on check).
 */
function coopMemberAccessUnlockClientScriptOnce(): string
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    $busyLabel = (function_exists('isEnglish') && isEnglish()) ? 'Checking…' : 'जाँच हुँदै…';
    $failGeneric = (function_exists('isEnglish') && isEnglish())
        ? 'Verification failed. Please try again.'
        : 'प्रमाणीकरण असफल। फेरि प्रयास गर्नुहोस्।';

    return '<script>(function(){'
        . 'if(window.__coopPmaAjaxBound)return;window.__coopPmaAjaxBound=1;'
        . 'var BUSY=' . json_encode($busyLabel, JSON_UNESCAPED_UNICODE) . ';'
        . 'var FAIL=' . json_encode($failGeneric, JSON_UNESCAPED_UNICODE) . ';'
        . 'function showErr(form,msg){'
        . 'var box=form.querySelector("[data-coop-pma-error]");'
        . 'if(!box){box=document.createElement("div");box.className="coop-pma-error";box.setAttribute("role","alert");box.setAttribute("data-coop-pma-error","");'
        . 'var hint=form.querySelector(".coop-pma-unlock-hint");'
        . 'if(hint&&hint.parentNode)hint.parentNode.insertBefore(box,hint.nextSibling);else form.insertBefore(box,form.firstChild);}'
        . 'box.hidden=false;box.textContent=msg||FAIL;}'
        . 'function hideErr(form){var box=form.querySelector("[data-coop-pma-error]");if(box){box.hidden=true;box.textContent="";}}'
        . 'function refreshIcons(root){try{if(window.lucide&&typeof window.lucide.createIcons==="function")window.lucide.createIcons({nodes:root?root.querySelectorAll("[data-lucide]"):undefined});}catch(e){}}'
        . 'function softRefresh(){'
        . 'var targets=document.querySelectorAll("[data-coop-pma-refresh]");'
        . 'if(!targets.length){location.reload();return;}'
        . 'var url=window.location.href;'
        . 'fetch(url,{credentials:"same-origin",headers:{"Accept":"text/html","X-Requested-With":"XMLHttpRequest"}})'
        . '.then(function(r){return r.text();})'
        . '.then(function(html){'
        . 'var doc=new DOMParser().parseFromString(html,"text/html");'
        . 'var ok=false;'
        . 'targets.forEach(function(el){'
        . 'var key=el.getAttribute("data-coop-pma-refresh")||"";'
        . 'var sel=key?("[data-coop-pma-refresh=\\""+key+"\\"]"):"[data-coop-pma-refresh]";'
        . 'var neu=doc.querySelector(sel);'
        . 'if(neu){el.replaceWith(neu);ok=true;refreshIcons(neu);}'
        . '});'
        . 'if(!ok)location.reload();'
        . 'else{try{document.dispatchEvent(new CustomEvent("coop:pma-unlocked"));}catch(e2){}}'
        . '})'
        . '.catch(function(){location.reload();});'
        . '}'
        . 'document.addEventListener("submit",function(ev){'
        . 'var form=ev.target&&ev.target.closest?ev.target.closest("form.coop-pma-unlock[data-coop-pma-ajax]"):null;'
        . 'if(!form)return;'
        . 'if(typeof FormData==="undefined"||typeof fetch==="undefined")return;'
        . 'ev.preventDefault();'
        . 'hideErr(form);'
        . 'var btn=form.querySelector(".coop-pma-unlock-btn");'
        . 'var prev=btn?btn.textContent:"";'
        . 'if(btn){btn.disabled=true;btn.textContent=BUSY;}'
        . 'form.classList.add("is-busy");'
        . 'var fd=new FormData(form);'
        . 'fd.set("coop_pma_ajax","1");'
        . 'fetch((window.location.href||"").split("#")[0],{'
        . 'method:"POST",body:fd,credentials:"same-origin",'
        . 'headers:{"X-Requested-With":"XMLHttpRequest","Accept":"application/json"}'
        . '}).then(function(r){return r.json().then(function(j){return{status:r.status,j:j};}).catch(function(){return{status:r.status,j:null};});})'
        . '.then(function(res){'
        . 'var j=res.j||{};'
        . 'if(j.ok||j.unlocked){softRefresh();return;}'
        . 'showErr(form,(j&&j.error)||FAIL);'
        . 'if(btn){btn.disabled=false;btn.textContent=prev;}'
        . 'form.classList.remove("is-busy");'
        . 'var inp=form.querySelector("input[name=sadasyata_number]");'
        . 'if(inp&&inp.focus)inp.focus();'
        . '}).catch(function(){'
        . 'showErr(form,FAIL);'
        . 'if(btn){btn.disabled=false;btn.textContent=prev;}'
        . 'form.classList.remove("is-busy");'
        . '});'
        . '},true);'
        . '})();</script>';
}
