<?php
/**
 * Member Portal Authentication & Helpers
 * आकाश सहकारी — Member Portal v2.0
 * Changes: sadasyata_number mandatory, approval_status, unique phone,
 *          KYC phone validation, password reset requests, ID card
 */

/* v10.3 SECURITY FIX (Issue #2 — auto-login on other computers):
 * Previously this file called session_start() with NO cookie params and NO
 * use_strict_mode. If it ran BEFORE includes/config.php's session block (which
 * sets strict_mode + secure cookie params), the session became vulnerable to
 * session-fixation: anyone visiting with a known/guessed coop_session cookie
 * would silently inherit a logged-in session.
 *
 * Fix: configure strict_mode + cookie params FIRST, then start session.
 * If config.php has already started a session, this is a no-op.
 */
if (session_status() === PHP_SESSION_NONE) {
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');
    if (function_exists('session_set_cookie_params')) {
        session_set_cookie_params([
            'lifetime' => defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 7200,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_name(SESSION_NAME);
    session_start();
}

/* ─── Ensure member tables exist ─── */
function ensureMemberTables() {
    /* v2: एकपटक मात्र चल्ने guard — register/login बीचमा बारम्बार call हुँदा overhead हटाउन */
    static $done = false;
    if ($done) return;
    $done = true;

    global $db;
    if (!$db) {
        try { $db = getDB(); } catch (\Throwable $e) { return; }
    }
    if (!$db) return;

    /* Core members table */
    $db->exec("CREATE TABLE IF NOT EXISTS members (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        name                VARCHAR(255) NOT NULL,
        email               VARCHAR(255) UNIQUE,
        phone               VARCHAR(20),
        sadasyata_number    VARCHAR(50) NOT NULL DEFAULT '',
        password_hash       VARCHAR(255),
        google_id           VARCHAR(255),
        facebook_id         VARCHAR(255),
        avatar_url          VARCHAR(500),
        member_card_no      VARCHAR(50),
        kyc_application_id  INT NULL,
        address             TEXT,
        dob                 DATE,
        gender              VARCHAR(20),
        approval_status     VARCHAR(20) DEFAULT 'pending',
        approved_at         TIMESTAMP NULL DEFAULT NULL,
        approved_by         INT NULL DEFAULT NULL,
        rejection_reason    TEXT,
        id_card_generated   TINYINT DEFAULT 0,
        id_card_generated_at TIMESTAMP NULL DEFAULT NULL,
        is_verified         TINYINT DEFAULT 0,
        is_active           TINYINT DEFAULT 1,
        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_login          TIMESTAMP NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* Add new columns to existing tables (silently ignore if exists) */
    $newCols = [
        "ALTER TABLE members ADD COLUMN sadasyata_number VARCHAR(50) NOT NULL DEFAULT ''",
        "ALTER TABLE members ADD COLUMN approval_status VARCHAR(20) DEFAULT 'pending'",
        "ALTER TABLE members ADD COLUMN approved_at TIMESTAMP NULL DEFAULT NULL",
        "ALTER TABLE members ADD COLUMN approved_by INT NULL DEFAULT NULL",
        "ALTER TABLE members ADD COLUMN rejection_reason TEXT",
        "ALTER TABLE members ADD COLUMN id_card_generated TINYINT DEFAULT 0",
        "ALTER TABLE members ADD COLUMN id_card_generated_at TIMESTAMP NULL DEFAULT NULL",
        /* Issue #3: card 5-year validity */
        "ALTER TABLE members ADD COLUMN card_expires_at TIMESTAMP NULL DEFAULT NULL",
        "ALTER TABLE members ADD COLUMN kyc_application_id INT NULL DEFAULT NULL",
        "ALTER TABLE members ADD COLUMN twofa_enabled TINYINT DEFAULT 0",
        "ALTER TABLE members ADD COLUMN twofa_secret VARCHAR(64) NULL",
        "ALTER TABLE members ADD COLUMN twofa_backup_codes TEXT NULL",
        "ALTER TABLE members ADD COLUMN twofa_enabled_at DATETIME NULL",
    ];
    foreach ($newCols as $sql) {
        try { $db->exec($sql); } catch (\Throwable $e) { /* column already exists */ }
    }

    /* Legacy data backfills — एक पटक मात्र (हरेक request मा UPDATE दोहोर्याउँदा admin NULL wipe हुन्थ्यो) */
    $legacyBackfillDone = false;
    try {
        $stSet = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1");
        $stSet->execute(['migration_member_schema_backfill_v1']);
        $rw = $stSet->fetch(PDO::FETCH_ASSOC);
        $legacyBackfillDone = ($rw && ($rw['setting_value'] ?? '') === '1');
    } catch (\Throwable $e) { /* site_settings छैन वा पुरानो DB */ }

    if (!$legacyBackfillDone) {
        try {
            $db->exec("UPDATE members SET approval_status='approved'
                       WHERE approval_status IS NULL OR approval_status=''");
            $db->exec("UPDATE members SET approval_status='approved'
                       WHERE (google_id IS NOT NULL AND google_id != '')
                          OR (facebook_id IS NOT NULL AND facebook_id != '')");
            $db->exec("UPDATE members
                          SET card_expires_at = DATE_ADD(COALESCE(approved_at, created_at), INTERVAL 5 YEAR)
                        WHERE card_expires_at IS NULL
                          AND approval_status = 'approved'");
            $db->exec("UPDATE members m
                       INNER JOIN kyc_applications k ON k.id = m.kyc_application_id
                          SET m.avatar_url = ''
                        WHERE COALESCE(m.avatar_url, '') <> ''
                          AND COALESCE(k.photo, '') <> ''
                          AND COALESCE(m.google_id, '') = ''
                          AND COALESCE(m.facebook_id, '') = ''");
            if (function_exists('updateSetting')) {
                updateSetting('migration_member_schema_backfill_v1', '1');
            }
        } catch (\Throwable $e) { /* silent */ }
    }

    /* Notifications table */
    $db->exec("CREATE TABLE IF NOT EXISTS member_notifications (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        member_id   INT NOT NULL,
        title       VARCHAR(255) NOT NULL,
        message     TEXT,
        type        VARCHAR(30) DEFAULT 'info',
        link        VARCHAR(500),
        is_read     TINYINT DEFAULT 0,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* Password reset requests table (admin-approved fallback) */
    $db->exec("CREATE TABLE IF NOT EXISTS member_password_reset_requests (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        member_id       INT NOT NULL,
        status          VARCHAR(20) DEFAULT 'pending',
        requested_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        admin_id        INT NULL DEFAULT NULL,
        resolved_at     TIMESTAMP NULL DEFAULT NULL,
        temp_password   VARCHAR(255) NULL DEFAULT NULL,
        admin_note      TEXT,
        FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* OTP tokens table — self-service password reset */
    $db->exec("CREATE TABLE IF NOT EXISTS member_otp_tokens (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        member_id   INT NOT NULL,
        otp_code    VARCHAR(10) NOT NULL,
        purpose     VARCHAR(50) DEFAULT 'password_reset',
        channel     VARCHAR(10) DEFAULT 'sms',
        sent_to     VARCHAR(200),
        is_used     TINYINT DEFAULT 0,
        attempts    TINYINT DEFAULT 0,
        expires_at  TIMESTAMP NOT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ─── Security Headers ─── */
function memberSecurityHeaders() {
    if (headers_sent()) return;
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    /* member KYC / profile photo — same-origin camera/mic (public config.php सँग मेल) */
    header('Permissions-Policy: geolocation=(), microphone=(self), camera=(self), payment=(), usb=()');
}

/* ─── Safe redirect (open redirect prevention) ─── */
function memberSafeRedirect($url) {
    $parsed = parse_url($url);
    if (!empty($parsed['host'])) {
        $siteHost = parse_url(SITE_URL, PHP_URL_HOST);
        if ($parsed['host'] !== $siteHost) {
            $url = SITE_URL . 'member/';
        }
    }
    if (preg_match('/^\s*(javascript|data|vbscript):/i', $url)) {
        $url = SITE_URL . 'member/';
    }
    header('Location: ' . $url);
    exit;
}

/* ─── Brute Force Rate Limiting ─── */
define('MEM_MAX_ATTEMPTS', 5);
define('MEM_LOCKOUT_SECONDS', 900);

function memberCheckRateLimit($email) {
    $key = 'mem_rl_' . md5(strtolower($email));
    $data = $_SESSION[$key] ?? ['count' => 0, 'first' => time(), 'locked_until' => 0];
    if ($data['locked_until'] > time()) {
        $wait = ceil(($data['locked_until'] - time()) / 60);
        return ['blocked' => true, 'wait' => $wait];
    }
    if ($data['first'] < time() - MEM_LOCKOUT_SECONDS) {
        $data = ['count' => 0, 'first' => time(), 'locked_until' => 0];
        $_SESSION[$key] = $data;
    }
    return ['blocked' => false, 'attempts' => $data['count']];
}

function memberRecordFailedLogin($email) {
    $key  = 'mem_rl_' . md5(strtolower($email));
    $data = $_SESSION[$key] ?? ['count' => 0, 'first' => time(), 'locked_until' => 0];
    $data['count']++;
    if ($data['count'] >= MEM_MAX_ATTEMPTS) {
        $data['locked_until'] = time() + MEM_LOCKOUT_SECONDS;
    }
    $_SESSION[$key] = $data;
}

function memberClearRateLimit($email) {
    unset($_SESSION['mem_rl_' . md5(strtolower($email))]);
}

/* ─── Session helpers ─── */
/* v9.9 FIX:
 *   पुरानो version मा User-Agent वा IP mismatch भए session_destroy() गर्थ्यो।
 *   त्यसले एउटा page मा error आउँदा सबै tabs बाट logout गराउँथ्यो — खासगरी
 *   mobile WebView (Facebook/Instagram in-app browser) र dynamic IP (Mobile Data ↔ WiFi
 *   switch) मा id-card.php click गर्दा "तुरुन्तै login मा फर्किने" bug को मुख्य कारण।
 *
 *   अब कुनै mismatch आए session_destroy() नगरिकन सिर्फ false return गर्छ —
 *   त्यसले अरू tabs को session जोगाउँछ र user लाई फेरि login गर्न दिन्छ।
 *   साथै UA check लाई कमजोर पारिएको छ ताकि browser update / app WebView ले
 *   logout नगराओस्। IP check पनि अहिले advisory मात्र हो।
 */
function memberIsLoggedIn() {
    if (empty($_SESSION['member_id'])) return false;

    // Member session hardening: 2-hour inactivity timeout (mobile-friendly)
    $memberIdleLimit = 7200;
    $last = (int)($_SESSION['member_last_activity'] ?? 0);
    if ($last > 0 && (time() - $last) > $memberIdleLimit) {
        return false;
    }

    /* v10.3 SECURITY (Issue #2): Bind the session to the device that logged in.
     * If UA hash OR IP /24 prefix differ from values stored at login time,
     * silently invalidate THIS request's session (do NOT destroy globally —
     * other tabs may still be valid). This blocks "another computer auto-logged
     * in" caused by a leaked / shared session cookie.
     *
     * Note: Mobile data ↔ WiFi switch usually keeps the same /24 only for the
     * same carrier; if it breaks, user is asked to log in again — acceptable
     * trade-off vs. silent account takeover.
     */
    $expectedUA = $_SESSION['member_agent_hash'] ?? '';
    $expectedIP = $_SESSION['member_ip_partial'] ?? '';

    /* UA fingerprint check disabled — mobile browsers (WebView, Chrome on Android,
     * iOS Safari) frequently vary UA between requests causing false logouts.
     * IP /24 check below is sufficient for session binding security.
     */
    if (false && $expectedUA !== '') {
        $currentUA = substr(md5($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 16);
        if ($currentUA !== $expectedUA) {
            error_log('member-auth: UA fingerprint mismatch for member_id=' . (int)$_SESSION['member_id']);
            return false;
        }
    }
    if ($expectedIP !== '') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $currentIPp = implode('.', array_slice(explode('.', $ip), 0, 3));
        if ($currentIPp !== '' && $currentIPp !== $expectedIP) {
            error_log('member-auth: IP /24 mismatch for member_id=' . (int)$_SESSION['member_id']
                . ' (expected ' . $expectedIP . ', got ' . $currentIPp . ')');
            return false;
        }
    }
    $_SESSION['member_last_activity'] = time();
    return true;
}

function currentMember() {
    if (!memberIsLoggedIn()) return null;
    global $db;
    if (!$db) return null;
    $st = $db->prepare("SELECT * FROM members WHERE id=? AND is_active=1 AND approval_status='approved'");
    $st->execute([$_SESSION['member_id']]);
    return $st->fetch(PDO::FETCH_ASSOC);
}

function requireMemberLogin($redirectBack = true) {
    if (!memberIsLoggedIn()) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
        }
        @session_destroy();
        $back = $redirectBack ? '?next=' . urlencode($_SERVER['REQUEST_URI']) : '';
        header('Location: ' . SITE_URL . 'member/login.php' . $back);
        exit;
    }
}

function memberLogout() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* ─── Validate avatar URL ─── */
function memberSafeAvatarUrl($url) {
    if (!$url) return '';
    $allowed = ['lh3.googleusercontent.com','graph.facebook.com','platform-lookaside.fbsbx.com',
                'scontent.cdninstagram.com','secure.gravatar.com'];
    $parsed = parse_url($url);
    if (!isset($parsed['scheme']) || $parsed['scheme'] !== 'https') return '';
    if (!isset($parsed['host'])) return '';
    $host = strtolower($parsed['host']);
    foreach ($allowed as $a) {
        if ($host === $a || substr($host, -strlen('.'.$a)) === '.'.$a) return $url;
    }
    return '';
}

/* ─── Validate phone against KYC records ─── */
function validatePhoneAgainstKYC($phone) {
    global $db;
    if (!$db || !$phone) return false;
    try {
        $st = $db->prepare("SELECT id FROM kyc_applications WHERE mobile=? LIMIT 1");
        $st->execute([$phone]);
        return (bool)$st->fetch();
    } catch (\Throwable $e) {
        return true; /* KYC table नभए bypass गर्नुस् */
    }
}

/* ─── Register ─── */
function memberRegister($name, $email, $phone, $password, $sadasyataNumber = '',
                        $googleId = null, $facebookId = null, $avatarUrl = '', $kycApplicationId = null) {
    /* v2 Fix: White-screen registration bug को कारण थियो —
     * (1) global $db null भइरहेको थियो जब member-auth.php पहिले load नहुँदा,
     * (2) कुनै unexpected DB exception (column missing, duplicate key race) silently fatal हुन्थ्यो।
     * अब lazy-init + try/catch ले हरेक error लाई user-readable message मा convert गर्छ। */
    global $db;
    if (!$db) {
        try { $db = getDB(); } catch (\Throwable $e) {
            return ['error' => 'Database connection त्रुटि भयो। कृपया केही समयपछि प्रयास गर्नुहोस्।'];
        }
    }
    if (!$db) {
        return ['error' => 'Database उपलब्ध छैन। Admin लाई सम्पर्क गर्नुहोस्।'];
    }

    try {
        ensureMemberTables();
    } catch (\Throwable $e) {
        error_log('memberRegister: ensureMemberTables failed — ' . $e->getMessage());
        /* schema verify fail भए पनि register attempt गरौं */
    }

    $email = strtolower(trim($email));
    $phone = preg_replace('/[^0-9]/', '', $phone);
    $sadasyataNumber = trim($sadasyataNumber);

    /* Sadasyata number required (for manual registration only, not OAuth) */
    if (!$googleId && !$facebookId && empty($sadasyataNumber)) {
        return ['error' => 'सदस्यता नम्बर (Sadasyata Number) अनिवार्य छ।'];
    }

    /* Unique email check */
    if ($email) {
        $chk = $db->prepare("SELECT id FROM members WHERE email=?");
        $chk->execute([$email]);
        if ($chk->fetch()) return ['error' => 'यो इमेल पहिले नै दर्ता छ। लगिन गर्नुहोस्।'];
    }

    /* Unique phone check */
    if ($phone) {
        $chk = $db->prepare("SELECT id FROM members WHERE phone=?");
        $chk->execute([$phone]);
        if ($chk->fetch()) return ['error' => 'यो मोबाइल नम्बर पहिले नै दर्ता छ। लगिन गर्नुहोस् वा सम्पर्क गर्नुहोस्।'];
    }

    /* KYC phone validation — disabled by default (enable in admin if needed) */
    // if ($phone && !$googleId && !$facebookId) {
    //     if (!validatePhoneAgainstKYC($phone)) {
    //         return ['error' => 'यो मोबाइल नम्बर KYC रेकर्डमा फेला परेन।'];
    //     }
    // }

    /* Unique sadasyata number check */
    if ($sadasyataNumber) {
        $chk = $db->prepare("SELECT id FROM members WHERE sadasyata_number=?");
        $chk->execute([$sadasyataNumber]);
        if ($chk->fetch()) return ['error' => 'यो सदस्यता नम्बर पहिले नै दर्ता छ। लगिन गर्नुहोस् वा सम्पर्क गर्नुहोस्।'];
    }

    $name  = strip_tags(trim($name));
    if ($phone && !preg_match('/^(97|98|0|1|2|3|4|5|6|7|8|9)\d+$/', $phone)) $phone = '';

    $hash = $password ? password_hash($password, PASSWORD_DEFAULT) : null;
    $cardNo = 'M-' . date('Y') . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);

    $avatarUrl = memberSafeAvatarUrl($avatarUrl);

    /* OAuth members auto-approved; manual registrations need admin approval */
    $approvalStatus = ($googleId || $facebookId) ? 'approved' : 'pending';

    /* v2 Fix: INSERT लाई try/catch मा wrap — column missing वा duplicate race
     * जस्तो कुनै DB exception हुँदा white-screen नदेखाएर Nepali error return गर्छ। */
    try {
        $st = $db->prepare("INSERT INTO members
            (name, email, phone, sadasyata_number, password_hash, google_id, facebook_id,
             avatar_url, member_card_no, approval_status, kyc_application_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$name, $email ?: null, $phone ?: null, $sadasyataNumber,
                      $hash, $googleId, $facebookId, $avatarUrl, $cardNo, $approvalStatus,
                      $kycApplicationId ? (int)$kycApplicationId : null]);
        $id = $db->lastInsertId();
    } catch (\PDOException $e) {
        error_log('memberRegister INSERT failed: ' . $e->getMessage());
        $msg = $e->getMessage();
        if (stripos($msg, 'Duplicate entry') !== false) {
            if (stripos($msg, 'email') !== false)     return ['error' => 'यो इमेल पहिले नै दर्ता छ।'];
            if (stripos($msg, 'phone') !== false)     return ['error' => 'यो मोबाइल नम्बर पहिले नै दर्ता छ।'];
            if (stripos($msg, 'sadasyata') !== false) return ['error' => 'यो सदस्यता नम्बर पहिले नै दर्ता छ।'];
            return ['error' => 'दर्ता विवरण पहिले नै रेकर्डमा छ।'];
        }
        if (stripos($msg, 'Unknown column') !== false) {
            return ['error' => 'Database structure पुरानो छ। Admin → DB Setup → "Re-verify Schema" चलाएर पुनः प्रयास गर्नुहोस्।'];
        }
        return ['error' => 'दर्ता गर्दा त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस् वा कार्यालयमा सम्पर्क गर्नुहोस्।'];
    } catch (\Throwable $e) {
        error_log('memberRegister fatal: ' . $e->getMessage());
        return ['error' => 'अप्रत्याशित त्रुटि भयो। कार्यालयमा सम्पर्क गर्नुहोस्।'];
    }

    /* Notification create गर्दा fail भए पनि registration sफल मानिन्छ */
    try {
        if ($approvalStatus === 'approved') {
            createMemberNotification($id, '🎉 आकाश सहकारीमा स्वागत छ!',
                'तपाईंको Member Portal account सफलतापूर्वक बनेको छ।',
                'success', SITE_URL . 'member/');
        } else {
            createMemberNotification($id, '⏳ दर्ता सफल — Admin अनुमोदन प्रतीक्षामा',
                'तपाईंको दर्ता सफलतापूर्वक प्राप्त भयो। Admin ले अनुमोदन गरेपछि लगिन गर्न सक्नुहुन्छ।',
                'info', SITE_URL . 'member/login.php');
        }
    } catch (\Throwable $e) {
        error_log('memberRegister notification failed: ' . $e->getMessage());
    }

    return ['id' => $id, 'card_no' => $cardNo, 'approval_status' => $approvalStatus];
}

/* ─── Login ─── */
function memberLogin($email, $password, bool $skipSession = false) {
    /* v2 Fix: Same lazy-init + try/catch pattern */
    global $db;
    if (!$db) {
        try { $db = getDB(); } catch (\Throwable $e) {
            return ['error' => 'Database connection त्रुटि। पछि प्रयास गर्नुहोस्।'];
        }
    }
    if (!$db) return ['error' => 'Database उपलब्ध छैन।'];

    try { ensureMemberTables(); } catch (\Throwable $e) { /* schema verify fail भए पनि login प्रयास */ }

    $email = strtolower(trim($email));
    try {
        $st = $db->prepare("SELECT * FROM members WHERE (email=? OR sadasyata_number=?) AND is_active=1 LIMIT 1");
        $st->execute([$email, $email]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('memberLogin SELECT failed: ' . $e->getMessage());
        return ['error' => 'Login प्रक्रियामा त्रुटि भयो। पछि प्रयास गर्नुहोस्।'];
    }

    if (!$m) return ['error' => 'इमेल / सदस्यता नम्बर फेला परेन वा account निष्क्रिय छ।'];
    if (!$m['password_hash']) return ['error' => 'यो account Google/Facebook बाट बनेको हो — पासवर्ड सेट गरिएको छैन। पहिले Google/Facebook बाट लगिन गर्नुहोस्, त्यसपछि "मेरो प्रोफाइल" मा गएर पासवर्ड सेट गर्नुहोस्।'];
    if (!password_verify($password, $m['password_hash'])) return ['error' => 'पासवर्ड मिलेन। पुनः प्रयास गर्नुहोस्।'];

    if (($m['approval_status'] ?? 'pending') === 'pending') {
        return ['error' => 'pending_approval', 'name' => $m['name']];
    }
    if (($m['approval_status'] ?? 'pending') === 'rejected') {
        $reason = $m['rejection_reason'] ?? '';
        return ['error' => 'rejected', 'reason' => $reason];
    }

    /* ── Issue #3: 5-year card validity check ──
       If member.card_expires_at <= NOW() → auto-flag for renewal & block login. */
    if (!empty($m['card_expires_at'])) {
        $expTs = strtotime($m['card_expires_at']);
        if ($expTs && $expTs < time() && ($m['approval_status'] ?? '') !== 'renewal_pending') {
            try {
                $db->prepare("UPDATE members SET approval_status='renewal_pending' WHERE id=?")
                   ->execute([$m['id']]);
            } catch (\Throwable $e) { error_log('renewal flag: '.$e->getMessage()); }
            return ['error' => 'renewal_required', 'name' => $m['name']];
        }
    }
    if (($m['approval_status'] ?? '') === 'renewal_pending') {
        return ['error' => 'renewal_required', 'name' => $m['name']];
    }

    if ($skipSession) {
        return ['id' => $m['id'], 'member' => $m];
    }

    memberSetSession($m);
    try {
        $db->prepare("UPDATE members SET last_login=NOW() WHERE id=?")->execute([$m['id']]);
    } catch (\Throwable $e) { /* last_login update fail भए पनि login सफल */ }
    return ['id' => $m['id'], 'member' => $m];
}

/* ─── OAuth login/register ─── */
function memberOAuthLogin($provider, $providerId, $name, $email, $avatarUrl = '') {
    global $db;
    ensureMemberTables();

    $col   = $provider === 'google' ? 'google_id' : 'facebook_id';
    $email = strtolower(trim($email));
    $name  = strip_tags(trim($name));
    $avatarUrl = memberSafeAvatarUrl($avatarUrl);

    $st = $db->prepare("SELECT * FROM members WHERE $col=? AND is_active=1");
    $st->execute([$providerId]);
    $m = $st->fetch(PDO::FETCH_ASSOC);

    if (!$m && $email) {
        $st = $db->prepare("SELECT * FROM members WHERE email=? AND is_active=1");
        $st->execute([$email]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if ($m) {
            // Existing account लाई OAuth सँग link गर्दा KYC बाट missing identity sync गर्ने
            $syncSid = trim((string)($m['sadasyata_number'] ?? ''));
            $syncPhone = preg_replace('/[^0-9]/', '', (string)($m['phone'] ?? ''));
            $syncName = trim((string)($m['name'] ?? ''));
            $syncKycId = (int)($m['kyc_application_id'] ?? 0);
            try {
                $k = $db->prepare("SELECT id, full_name, mobile, member_id
                                   FROM kyc_applications
                                   WHERE LOWER(email)=? AND status='approved'
                                   ORDER BY id DESC LIMIT 1");
                $k->execute([strtolower($email)]);
                $kr = $k->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($kr) {
                    if ($syncSid === '') $syncSid = trim((string)($kr['member_id'] ?? ''));
                    if ($syncPhone === '') $syncPhone = preg_replace('/[^0-9]/', '', (string)($kr['mobile'] ?? ''));
                    if ($syncName === '') $syncName = trim((string)($kr['full_name'] ?? ''));
                    if ($syncKycId < 1) $syncKycId = (int)($kr['id'] ?? 0);
                }
            } catch (\Throwable $e) { /* non-fatal sync */ }

            $db->prepare("UPDATE members
                          SET $col=?,
                              avatar_url=COALESCE(NULLIF(avatar_url,''),?),
                              sadasyata_number=COALESCE(NULLIF(sadasyata_number,''),?),
                              phone=COALESCE(NULLIF(phone,''),?),
                              name=COALESCE(NULLIF(name,''),?),
                              kyc_application_id=COALESCE(kyc_application_id,?)
                          WHERE id=?")
               ->execute([$providerId, $avatarUrl, $syncSid, $syncPhone, $syncName, ($syncKycId > 0 ? $syncKycId : null), $m['id']]);
            // refresh row
            $st = $db->prepare("SELECT * FROM members WHERE id=? LIMIT 1");
            $st->execute([$m['id']]);
            $m = $st->fetch(PDO::FETCH_ASSOC) ?: $m;
        }
    }

    if (!$m) {
        return ['error' => 'OAuth बाट नयाँ खाता सीधा खोल्न मिल्दैन। पहिले Member signup (Member ID + Email + Mobile) गरेर KYC match गर्नुहोस्, त्यसपछि Google/Facebook बाट लगिन गर्नुहोस्।'];
    }

    memberSetSession($m);
    $db->prepare("UPDATE members SET last_login=NOW() WHERE id=?")->execute([$m['id']]);
    return ['id' => $m['id']];
}

function memberSetSession($m) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['member_id']         = $m['id'];
    $_SESSION['member_name']       = $m['name'];
    $_SESSION['member_email']      = $m['email'];
    $_SESSION['member_avatar']     = $m['avatar_url'] ?? '';
    $_SESSION['member_card']       = $m['member_card_no'] ?? '';
    $_SESSION['member_agent_hash'] = substr(md5($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 16);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $_SESSION['member_ip_partial'] = implode('.', array_slice(explode('.', $ip), 0, 3));
    $_SESSION['member_last_activity'] = time();
}

/* ─── Admin: Approve Member ─── */
function adminApproveMember($memberId, $adminId = null) {
    global $db;
    if (!$db) return false;
    /* Issue #3: 5-year card validity reset on every approval (covers initial + renewal) */
    $db->prepare("UPDATE members
                     SET approval_status='approved', approved_at=NOW(), approved_by=?,
                         rejection_reason=NULL,
                         card_expires_at = DATE_ADD(NOW(), INTERVAL 5 YEAR)
                   WHERE id=?")
       ->execute([$adminId, $memberId]);

    /* In-app notification */
    createMemberNotification($memberId, '✅ तपाईंको खाता स्वीकृत भयो!',
        'सहकारीको Member Portal मा तपाईंको खाता Admin द्वारा स्वीकृत भएको छ। अब लगिन गर्न सक्नुहुन्छ।',
        'success', SITE_URL . 'member/login.php');

    /* SMS + Email notification */
    try {
        $stmt = $db->prepare("SELECT name, email, phone FROM members WHERE id=?");
        $stmt->execute([$memberId]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($m) {
            $siteName = function_exists('getSetting') ? getSetting('site_name','सहकारी') : 'सहकारी';
            $firstName = trim(explode(' ', trim($m['name']))[0]) ?: 'सदस्य';
            $smsText   = "नमस्ते {$firstName} जी, तपाईंको {$siteName} Member Portal खाता स्वीकृत भयो। अब login गर्न सक्नुहुन्छ: " . SITE_URL . "member/";
            $smsText   = mb_substr($smsText, 0, 160);
            $phone     = preg_replace('/[^0-9]/','', $m['phone'] ?? '');
            $apiToken  = function_exists('getSetting') ? getSetting('notify_sms_token','') : '';
            $smsOn     = function_exists('getSetting') && getSetting('notify_sms_enabled','0')==='1';
            if ($phone && strlen($phone)>=10 && $apiToken && $smsOn) {
                sendDirectSMS($phone, $smsText);
            }
            /* Email */
            if ($m['email'] && function_exists('sendOTPviaEmail')) {
                $subj = "✅ खाता स्वीकृत — {$siteName}";
                $html = "<div style='font-family:sans-serif;max-width:480px;margin:auto'>
                    <div style='background:var(--primary-color);padding:20px;color:#fff;text-align:center;border-radius:8px 8px 0 0'>
                        <h2 style='margin:0'>{$siteName}</h2></div>
                    <div style='padding:28px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px'>
                        <p>नमस्ते <strong>{$firstName}</strong> जी,</p>
                        <p>तपाईंको Member Portal खाता <strong style='color:#16a34a'>स्वीकृत</strong> भएको छ।</p>
                        <div style='text-align:center;margin:20px 0'>
                            <a href='".SITE_URL."member/login.php' style='background:var(--primary-color);color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:bold'>अहिले Login गर्नुहोस्</a>
                        </div>
                        <p style='color:#6b7280;font-size:.85rem'>धन्यवाद,<br>{$siteName}</p>
                    </div></div>";
                @mail($m['email'], '=?UTF-8?B?'.base64_encode($subj).'?=', $html,
                      "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: {$siteName} <".getSetting('notify_email_from','noreply@coop.com').">\r\n");
            }
        }
    } catch (\Throwable $e) { /* notifications are non-fatal */ }
    return true;
}

/* ─── Admin: Reject Member ─── */
function adminRejectMember($memberId, $reason = '', $adminId = null) {
    global $db;
    if (!$db) return false;
    $db->prepare("UPDATE members SET approval_status='rejected', approved_by=?,
                  rejection_reason=? WHERE id=?")
       ->execute([$adminId, $reason, $memberId]);

    createMemberNotification($memberId, '❌ दर्ता अस्वीकृत भयो',
        'माफ गर्नुहोस्, तपाईंको Member Portal दर्ता स्वीकृत हुन सकेन।' . ($reason ? ' कारण: ' . $reason : '') . ' थप जानकारीका लागि कार्यालयमा सम्पर्क गर्नुहोस्।',
        'error', SITE_URL . 'member/login.php');

    /* SMS notification */
    try {
        $stmt = $db->prepare("SELECT name, phone FROM members WHERE id=?");
        $stmt->execute([$memberId]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($m) {
            $siteName  = function_exists('getSetting') ? getSetting('site_name','सहकारी') : 'सहकारी';
            $firstName = trim(explode(' ', trim($m['name']))[0]) ?: 'सदस्य';
            $reasonPart= $reason ? " कारण: " . mb_substr($reason, 0, 50) : '';
            $smsText   = "नमस्ते {$firstName} जी, तपाईंको {$siteName} Member Portal दर्ता अस्वीकृत भयो।{$reasonPart} कार्यालयमा सम्पर्क गर्नुहोस्।";
            $phone     = preg_replace('/[^0-9]/','', $m['phone'] ?? '');
            $smsOn     = function_exists('getSetting') && getSetting('notify_sms_enabled','0')==='1';
            if ($phone && strlen($phone)>=10 && $smsOn) sendDirectSMS($phone, $smsText);
        }
    } catch (\Throwable $e) { /* non-fatal */ }
    return true;
}

/* ─── Admin: Generate ID Card ─── */
function adminGenerateMemberIdCard($memberId, $adminId = null) {
    global $db;
    if (!$db) {
        try { $db = getDB(); } catch (\Throwable $e) { return false; }
    }
    if (!$db || !$memberId) return false;

    try { ensureMemberTables(); } catch (\Throwable $e) { /* schema verify best-effort */ }

    /* Self-heal: पुरानो DB मा हराएका column हरू भए silently थप्ने */
    $healSql = [
        "ALTER TABLE members ADD COLUMN IF NOT EXISTS id_card_generated TINYINT(1) DEFAULT 0",
        "ALTER TABLE members ADD COLUMN IF NOT EXISTS id_card_generated_at TIMESTAMP NULL DEFAULT NULL",
        "ALTER TABLE members ADD COLUMN IF NOT EXISTS member_card_no VARCHAR(50) NULL DEFAULT NULL",
    ];
    foreach ($healSql as $sql) {
        try {
            $db->exec($sql);
        } catch (\Throwable $e) {
            if (stripos($sql, 'id_card_generated_at') !== false) {
                try { $db->exec("ALTER TABLE members ADD COLUMN id_card_generated_at TIMESTAMP NULL DEFAULT NULL"); } catch (\Throwable $e2) {}
            } elseif (stripos($sql, 'id_card_generated') !== false) {
                try { $db->exec("ALTER TABLE members ADD COLUMN id_card_generated TINYINT(1) DEFAULT 0"); } catch (\Throwable $e2) {}
            } elseif (stripos($sql, 'member_card_no') !== false) {
                try { $db->exec("ALTER TABLE members ADD COLUMN member_card_no VARCHAR(50) NULL DEFAULT NULL"); } catch (\Throwable $e2) {}
            }
        }
    }

    try {
        if (file_exists(__DIR__ . '/card-verify-helpers.php')) {
            require_once __DIR__ . '/card-verify-helpers.php';
        }

        $st = $db->prepare("SELECT id, name, sadasyata_number, member_card_no, id_card_generated FROM members WHERE id=? LIMIT 1");
        $st->execute([$memberId]);
        $member = $st->fetch(PDO::FETCH_ASSOC);
        if (!$member) return false;

        $wasGenerated = !empty($member['id_card_generated']);
        $cardNo = '';

        // member_id_cards मा भएको latest card number लाई single source मान्ने
        try {
            $cs = $db->prepare("SELECT id, card_no
                                  FROM member_id_cards
                                 WHERE (member_id = :id OR member_id = :sid)
                                 ORDER BY id DESC LIMIT 1");
            $cs->execute([
                ':id' => (string)$memberId,
                ':sid' => (string)($member['sadasyata_number'] ?? ''),
            ]);
            $cardRow = $cs->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($cardRow && !empty($cardRow['card_no'])) {
                $cardNo = trim((string)$cardRow['card_no']);
            }
        } catch (\Throwable $e) { /* fallback create below */ }

        // नभए नयाँ standardized card create गर्ने
        if ($cardNo === '') {
            $cardNo = function_exists('generateCardNumber')
                ? generateCardNumber((int)$memberId)
                : ('M-' . date('Y') . '-' . str_pad((string)$memberId, 5, '0', STR_PAD_LEFT));

            try {
                $vCode = '';
                $cvv   = '';
                if (function_exists('generateCardVerification')) {
                    [$vCode, $cvv] = generateCardVerification($db);
                }
                $ins = $db->prepare("INSERT INTO member_id_cards
                    (member_id, card_no, verification_code, cvv, issued_date, status)
                    VALUES (?, ?, ?, ?, CURDATE(), 'active')");
                $ins->execute([
                    (string)($member['sadasyata_number'] ?? $memberId),
                    $cardNo,
                    $vCode,
                    $cvv
                ]);
            } catch (\Throwable $e) { /* non-fatal */ }
        }

        $up = $db->prepare("UPDATE members
            SET member_card_no = ?,
                id_card_generated = 1,
                id_card_generated_at = COALESCE(id_card_generated_at, NOW())
            WHERE id = ?");
        $up->execute([$cardNo, $memberId]);

        $verify = $db->prepare("SELECT id_card_generated, id_card_generated_at, member_card_no FROM members WHERE id=? LIMIT 1");
        $verify->execute([$memberId]);
        $updated = $verify->fetch(PDO::FETCH_ASSOC);
        if (!$updated || (int)($updated['id_card_generated'] ?? 0) !== 1) return false;

        if (!$wasGenerated) {
            createMemberNotification($memberId, '🪪 डिजिटल परिचयपत्र तयार भयो!',
                'तपाईंको डिजिटल Member ID Card Admin द्वारा तयार गरिएको छ। Member Portal मा हेर्नुहोस्।',
                'success', SITE_URL . 'member/id-card.php');
            if (function_exists('logActivity')) {
                logActivity((int)($adminId ?? 0), 'member_id_card_generated', "Member ID Card generated for member #{$memberId}");
            }
        }
        return true;
    } catch (\Throwable $e) {
        error_log('adminGenerateMemberIdCard failed: ' . $e->getMessage());
        return false;
    }
}

/* ─── Notify admin (in-app + email) of new password reset request ─── */
function notifyAdminOfPasswordResetRequest($member) {
    global $db;
    if (!$db || !$member) return false;
    try {
        $name  = $member['name']  ?? 'Member';
        $email = $member['email'] ?? '';
        $phone = $member['phone'] ?? '';

        /* In-app activity log (so admin sees red badge) */
        if (function_exists('logActivity')) {
            logActivity(0, 'password_reset_request', "Member '$name' ($email / $phone) ले पासवर्ड Reset अनुरोध पठायो");
        }

        /* Best-effort email to admin */
        $adminEmail = function_exists('getSetting') ? getSetting('admin_notify_email', '') : '';
        if ($adminEmail && function_exists('sendEmail')) {
            $subj = '🔐 Password Reset Request — ' . $name;
            $body = "<p>एक सदस्यले पासवर्ड Reset अनुरोध गर्नुभएको छ।</p>"
                  . "<ul><li><strong>Name:</strong> " . htmlspecialchars($name) . "</li>"
                  . "<li><strong>Email:</strong> " . htmlspecialchars($email) . "</li>"
                  . "<li><strong>Phone:</strong> " . htmlspecialchars($phone) . "</li></ul>"
                  . "<p>Admin Panel → Member Online Portal → Password Reset Requests मा प्रक्रिया गर्नुहोस्।</p>";
            try { @sendEmail($adminEmail, $subj, $body); } catch (\Throwable $e) {}
        }
        return true;
    } catch (\Throwable $e) { return false; }
}

/* ─── Password Reset: Member requests ─── */
function memberRequestPasswordReset($memberId) {
    global $db;
    if (!$db) return false;
    /* Pending request already exists? */
    $chk = $db->prepare("SELECT id FROM member_password_reset_requests WHERE member_id=? AND status='pending' LIMIT 1");
    $chk->execute([$memberId]);
    if ($chk->fetch()) return ['error' => 'तपाईंको पासवर्ड Reset अनुरोध पहिले नै पठाइएको छ। Admin ले प्रक्रिया गर्दैछन्।'];

    $db->prepare("INSERT INTO member_password_reset_requests (member_id) VALUES (?)")
       ->execute([$memberId]);
    return ['success' => true];
}

/* ─── Password Reset: Admin approves ─── */
function adminApprovePasswordReset($requestId, $adminId, $newPassword) {
    global $db;
    if (!$db || !$newPassword || strlen($newPassword) < 6) return false;
    $st = $db->prepare("SELECT * FROM member_password_reset_requests WHERE id=? AND status='pending'");
    $st->execute([$requestId]);
    $req = $st->fetch(PDO::FETCH_ASSOC);
    if (!$req) return false;

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $db->prepare("UPDATE members SET password_hash=? WHERE id=?")->execute([$hash, $req['member_id']]);
    $db->prepare("UPDATE member_password_reset_requests SET status='approved', admin_id=?, resolved_at=NOW(), temp_password=? WHERE id=?")
       ->execute([$adminId, $newPassword, $requestId]);

    createMemberNotification($req['member_id'], '🔑 पासवर्ड Reset स्वीकृत भयो',
        'तपाईंको पासवर्ड Reset अनुरोध Admin ले स्वीकृत गर्नुभयो। कार्यालयबाट नयाँ पासवर्ड प्राप्त गर्नुहोस् र लगिन गर्नुहोस्।',
        'success', SITE_URL . 'member/login.php');
    return true;
}

/* ─── Notifications ─── */
function createMemberNotification($memberId, $title, $message, $type = 'info', $link = '') {
    global $db;
    if (!$db || !$memberId) return;
    try {
        $st = $db->prepare("INSERT INTO member_notifications (member_id, title, message, type, link) VALUES (?,?,?,?,?)");
        $st->execute([$memberId, $title, $message, $type, $link]);
    } catch (Exception $e) { /* silent */ }
}

function getMemberUnreadCount($memberId) {
    global $db;
    if (!$db) return 0;
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM member_notifications WHERE member_id=? AND is_read=0");
        $st->execute([$memberId]);
        return (int)$st->fetchColumn();
    } catch (Exception $e) { return 0; }
}

/* ─── Cross-table application fetch ─── */
function getMemberApplications($email, $phone, $limit = 50) {
    global $db;
    if (!$db) return [];
    $results = [];

    $queries = [
        ['table' => 'appointments',        'service' => 'भेटघाट',         'icon' => 'fa-calendar-check',    'color' => 'var(--primary-color)',
         'fields' => 'id, name as full_name, phone, email, preferred_date as app_date, purpose as detail, status, tracking_id, created_at, branch'],
        ['table' => 'kyc_applications',    'service' => 'KYC दर्ता',      'icon' => 'fa-id-card',            'color' => 'var(--secondary-color,#c0392b)',
         'fields' => 'id, full_name, mobile as phone, email, NULL as app_date, account_type as detail, status, tracking_id, created_at, branch'],
        ['table' => 'loan_applications',   'service' => 'ऋण आवेदन',      'icon' => 'fa-hand-holding-usd',   'color' => '#6a1b9a',
         'fields' => 'id, full_name, mobile as phone, email, NULL as app_date, loan_type as detail, status, tracking_id, created_at, NULL as branch'],
        ['table' => 'account_applications','service' => 'खाता खोल्ने',   'icon' => 'fa-university',         'color' => '#00695c',
         'fields' => 'id, full_name, phone, email, NULL as app_date, account_type as detail, status, tracking_id, created_at, branch'],
        ['table' => 'grievances',          'service' => 'गुनासो',         'icon' => 'fa-comment-exclamation','color' => '#c62828',
         'fields' => 'id, name as full_name, phone, email, NULL as app_date, subject as detail, status, tracking_id, created_at, NULL as branch'],
        /* Welfare claims: support both legacy (welfare_claims) AND new (member_welfare_claims) table names */
        ['table' => 'welfare_claims',          'service' => 'कल्याण दाबी',  'icon' => 'fa-heart', 'color' => '#e65100',
         'fields' => 'id, full_name, phone, email, NULL as app_date, claim_type as detail, status, tracking_id, created_at, NULL as branch'],
        ['table' => 'member_welfare_claims',   'service' => 'कल्याण दाबी',  'icon' => 'fa-heart', 'color' => '#e65100',
         'fields' => 'id, full_name, phone, email, NULL as app_date, claim_type as detail, status, tracking_id, created_at, NULL as branch'],
        ['table' => 'job_applications',    'service' => 'जागिर आवेदन',   'icon' => 'fa-briefcase',          'color' => '#37474f',
         'fields' => 'id, full_name, phone, email, NULL as app_date, position_applied as detail, status, tracking_id, created_at, NULL as branch'],
    ];

    foreach ($queries as $q) {
        try {
            $conds = []; $params = [];
            if ($email) { $conds[] = 'email=?'; $params[] = $email; }
            if ($phone) { $conds[] = 'phone=?'; $params[] = $phone; }
            if (empty($conds)) continue;
            $where = implode(' OR ', $conds);
            $st = $db->prepare("SELECT {$q['fields']}, '{$q['service']}' as service_name,
                                        '{$q['icon']}' as service_icon, '{$q['color']}' as service_color
                                 FROM {$q['table']} WHERE ($where) ORDER BY created_at DESC LIMIT 30");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) { $r['_table'] = $q['table']; $results[] = $r; }
        } catch (Exception $e) { continue; }
    }

    usort($results, fn($a,$b) => strtotime($b['created_at']) - strtotime($a['created_at']));
    return array_slice($results, 0, $limit);
}

/* ─── Status helpers ─── */
function memberStatusBadge($status) {
    $map = [
        'pending'      => ['bg' => '#fff8e1', 'color' => '#f59e0b', 'text' => 'विचाराधीन',   'dot' => '#f59e0b'],
        'under_review' => ['bg' => '#fef2f2', 'color' => 'var(--secondary-color,#c0392b)', 'text' => 'समीक्षामा',    'dot' => 'var(--secondary-color,#c0392b)'],
        'approved'     => ['bg' => '#e8f5e9', 'color' => '#2e7d32', 'text' => 'स्वीकृत',      'dot' => '#2e7d32'],
        'rejected'     => ['bg' => '#ffebee', 'color' => '#c62828', 'text' => 'अस्वीकृत',    'dot' => '#c62828'],
        'completed'    => ['bg' => '#e8f5e9', 'color' => 'var(--primary-color)', 'text' => 'सम्पन्न',      'dot' => 'var(--primary-color)'],
        'resolved'     => ['bg' => '#e8f5e9', 'color' => 'var(--primary-color)', 'text' => 'समाधान भयो',  'dot' => 'var(--primary-color)'],
        'cancelled'    => ['bg' => '#f3f4f6', 'color' => '#6b7280', 'text' => 'रद्द',         'dot' => '#6b7280'],
    ];
    $s = $map[$status] ?? ['bg'=>'#f3f4f6','color'=>'#6b7280','text'=>ucfirst($status),'dot'=>'#9ca3af'];
    return "<span class='mem-badge' style='background:{$s['bg']};color:{$s['color']};'>
        <span style='background:{$s['dot']};width:7px;height:7px;border-radius:50%;display:inline-block;margin-right:4px;'></span>
        {$s['text']}</span>";
}

function memberStatusSteps($status) {
    $steps = ['pending'=>0,'under_review'=>1,'approved'=>2,'completed'=>3];
    return $steps[$status] ?? ($status === 'rejected' ? -1 : 0);
}

/* ─── Find member by contact ─── */
function findMemberByContact($email, $phone) {
    global $db;
    if (!$db) return null;
    if ($email) {
        $st = $db->prepare("SELECT * FROM members WHERE email=? AND is_active=1");
        $st->execute([strtolower(trim($email))]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if ($m) return $m;
    }
    if ($phone) {
        $st = $db->prepare("SELECT * FROM members WHERE phone=? AND is_active=1");
        $st->execute([$phone]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if ($m) return $m;
    }
    return null;
}

/* ─── Google OAuth URL builder ─── */
function googleOAuthUrl() {
    $clientId = getSetting('google_client_id', '');
    if (!$clientId) return null;
    $redirectUri = SITE_URL . 'member/oauth.php?provider=google';
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $params = http_build_query([
        'client_id'     => $clientId,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'access_type'   => 'online',
    ]);
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
}

/* ─── Facebook OAuth URL builder ─── */
function facebookOAuthUrl() {
    $appId = getSetting('facebook_app_id', '');
    if (!$appId) return null;
    $redirectUri = SITE_URL . 'member/oauth.php?provider=facebook';
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $params = http_build_query([
        'client_id'    => $appId,
        'redirect_uri' => $redirectUri,
        'state'        => $state,
        'scope'        => 'email,public_profile',
    ]);
    return 'https://www.facebook.com/v18.0/dialog/oauth?' . $params;
}

/**
 * Member tables auto-create — एकपटक मात्र (lock file आधारित)
 * v2: हरेक request मा runtime DDL hटाइयो।
 * Admin → Migration Runner ले `.schema.lock` हटाएर पुनः run गराउन सक्छ।
 */
$_memberLock = __DIR__ . '/../.member-schema.lock';
if (!file_exists($_memberLock)) {
    try {
        ensureMemberTables();
        @file_put_contents($_memberLock, "Member schema initialized at " . date('Y-m-d H:i:s') . "\n");
    } catch (\Throwable $e) {
        error_log('member-auth: schema bootstrap skipped - ' . $e->getMessage());
    }
}
unset($_memberLock);

/* ─── sendMemberStatusUpdate ─── */
/* v3 Cleanup: यो पुरानो sendMemberStatusUpdate() हटाइयो — notifications.php मा
 * same नाम को अर्को function छ (email/SMS पठाउने)। दुई fileमा same name भएकोले
 * "Cannot redeclare function" fatal आउन सक्थ्यो।
 *
 * अब in-app notification create गर्ने logic लाई छुट्टै नाममा सारियो:
 *     createMemberStatusNotification(...)
 * र notifications.php को sendMemberStatusUpdate() ले अब आफै यो call गर्छ
 * (email/SMS + in-app notification — दुवै एकै ठाउँबाट)। */
function createMemberStatusNotification($type, $email, $phone, $name, $status, $remarks = '', $trackingId = '') {
    $member = findMemberByContact($email, $phone);
    if (!$member) return;

    $memberId = $member['id'];
    $serviceLabels = [
        'appointment' => 'भेटघाट', 'kyc' => 'KYC दर्ता', 'loan' => 'ऋण आवेदन',
        'account' => 'खाता आवेदन', 'grievance' => 'गुनासो', 'welfare' => 'कल्याण दाबी',
        'job' => 'जागिर आवेदन', 'digital_service' => 'डिजिटल सेवा',
    ];
    $statusInfo = [
        'pending'      => ['विचाराधीन',   'info',    '⏳'],
        'under_review' => ['समीक्षामा',   'info',    '🔍'],
        'in_progress'  => ['कार्यान्वयनमा','info',   '⚙️'],
        'processing'   => ['प्रक्रियामा',  'info',    '⚙️'],
        'confirmed'    => ['पुष्टि भयो',  'success', '✅'],
        'approved'     => ['स्वीकृत',     'success', '✅'],
        'completed'    => ['सम्पन्न',     'success', '🎉'],
        'resolved'     => ['समाधान भयो', 'success', '✅'],
        'closed'       => ['बन्द गरियो',  'success', '✅'],
        'disbursed'    => ['वितरण भयो',  'success', '💰'],
        'paid'         => ['भुक्तानी भयो','success', '💰'],
        'shortlisted'  => ['छनोट भयो',   'success', '⭐'],
        'interviewed'  => ['अन्तर्वार्ता','info',    '🗣️'],
        'selected'     => ['चयन भयो',    'success', '🏆'],
        'rejected'     => ['अस्वीकृत',   'error',   '❌'],
        'cancelled'    => ['रद्द',        'warning', '🚫'],
    ];

    $svc   = $serviceLabels[$type]  ?? ucfirst($type);
    $si    = $statusInfo[$status]   ?? [$status, 'info', '📋'];
    $emoji = $si[2]; $sText = $si[0]; $nType = $si[1];

    $firstName = trim(explode(' ', trim($name))[0]) ?: 'सदस्य';
    $title     = "{$emoji} {$svc} — {$sText}";
    $msg       = "{$firstName} जी, तपाईंको {$svc} आवेदनको अवस्था «{$sText}» भएको छ।";
    if ($trackingId) $msg .= "\nTracking ID: {$trackingId}";
    if ($remarks)    $msg .= "\nAdmin टिप्पणी: {$remarks}";

    $link = defined('SITE_URL') ? SITE_URL . 'member/tracker.php' : '';
    try { createMemberNotification($memberId, $title, $msg, $nType, $link); }
    catch (\Throwable $e) { error_log('createMemberStatusNotification: ' . $e->getMessage()); }
}

/* ═══════════════════════════════════════════════════════
   Utility — Direct SMS (non-OTP notifications)
   ════════════════════════════════════════════════════════ */
function sendDirectSMS($phone, $text) {
    $phone    = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) < 10) return false;
    $apiToken = function_exists('getSetting') ? getSetting('notify_sms_token','') : '';
    $senderId = function_exists('getSetting') ? getSetting('notify_sms_sender_id','COOP') : 'COOP';
    $gateway  = function_exists('getSetting') ? getSetting('notify_sms_gateway','sparrow') : 'sparrow';
    if (!$apiToken) return false;
    try {
        if ($gateway === 'sparrow') {
            $ch = curl_init('http://api.sparrowsms.com/v2/sms/');
            curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,
                CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_POSTFIELDS=>http_build_query(['token'=>$apiToken,'from'=>$senderId,'to'=>$phone,'text'=>mb_substr($text,0,160)])]);
            $resp = curl_exec($ch); curl_close($ch);
            $d = json_decode($resp,true);
            return isset($d['response_code']) && $d['response_code']==200;
        } else {
            $apiUrl = function_exists('getSetting') ? getSetting('notify_sms_api_url','') : '';
            if (!$apiUrl) return false;
            $ch = curl_init($apiUrl);
            curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,
                CURLOPT_POSTFIELDS=>json_encode(['phone'=>$phone,'message'=>mb_substr($text,0,160),'token'=>$apiToken]),
                CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
            $resp = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
            return $code >= 200 && $code < 300;
        }
    } catch (\Throwable $e) { return false; }
}

/* ═══════════════════════════════════════════════════════
   OTP — Self-Service Password Reset
   ════════════════════════════════════════════════════════ */

/**
 * Find a member by email OR sadasyata_number (for password reset lookup)
 */
function findMemberForReset($identifier) {
    $db = getDB();
    $identifier = trim($identifier);
    $stmt = $db->prepare(
        "SELECT id, name, email, phone, sadasyata_number, approval_status, is_active
         FROM members WHERE (email=? OR sadasyata_number=?) LIMIT 1"
    );
    $stmt->execute([$identifier, $identifier]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Generate a 6-digit OTP and store in DB (expire 10 mins)
 * Returns ['otp'=>'123456', 'channel'=>'sms|email', 'sent_to'=>'98XX...']
 */
function generateAndStoreOTP($memberId, $channel, $sentTo, $purpose = 'password_reset') {
    $db  = getDB();
    $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    /* Invalidate old OTPs for same member/purpose */
    $db->prepare("UPDATE member_otp_tokens SET is_used=1
                  WHERE member_id=? AND purpose=? AND is_used=0")
       ->execute([$memberId, $purpose]);
    /* Insert new */
    $db->prepare("INSERT INTO member_otp_tokens
                    (member_id, otp_code, purpose, channel, sent_to, expires_at)
                  VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))")
       ->execute([$memberId, $otp, $purpose, $channel, $sentTo]);
    return $otp;
}

/**
 * Verify OTP — returns true if valid, false otherwise
 * Also increments attempt counter; invalidates after 5 failed attempts
 */
function verifyOTP($memberId, $otpCode, $purpose = 'password_reset') {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT * FROM member_otp_tokens
         WHERE member_id=? AND purpose=? AND is_used=0 AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$memberId, $purpose]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;

    /* Too many attempts */
    if ($row['attempts'] >= 5) {
        $db->prepare("UPDATE member_otp_tokens SET is_used=1 WHERE id=?")->execute([$row['id']]);
        return false;
    }
    if ($row['otp_code'] !== trim($otpCode)) {
        $db->prepare("UPDATE member_otp_tokens SET attempts=attempts+1 WHERE id=?")->execute([$row['id']]);
        return false;
    }
    /* Mark used */
    $db->prepare("UPDATE member_otp_tokens SET is_used=1 WHERE id=?")->execute([$row['id']]);
    return true;
}

/**
 * Send OTP via SMS (Sparrow SMS / configured gateway)
 * Returns ['sent'=>true|false, 'error'=>'...']
 */
function sendOTPviaSMS($phone, $otp, $siteName = '') {
    if (!$siteName) $siteName = function_exists('getSetting') ? getSetting('site_name', 'सहकारी') : 'सहकारी';
    $phone   = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) < 10) return ['sent'=>false,'error'=>'Invalid phone number'];

    $message = "तपाईंको {$siteName} पोर्टल OTP: {$otp}. यो 10 मिनेटमा expire हुन्छ। अरूसँग share नगर्नुहोस्।";
    $message = mb_substr($message, 0, 160);

    $apiToken = function_exists('getSetting') ? getSetting('notify_sms_token', '') : '';
    $senderId = function_exists('getSetting') ? getSetting('notify_sms_sender_id', 'COOP') : 'COOP';
    $gateway  = function_exists('getSetting') ? getSetting('notify_sms_gateway', 'sparrow') : 'sparrow';

    if (!$apiToken) return ['sent'=>false,'error'=>'SMS token not configured'];

    try {
        if ($gateway === 'sparrow') {
            $ch = curl_init('http://api.sparrowsms.com/v2/sms/');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query(['token'=>$apiToken,'from'=>$senderId,'to'=>$phone,'text'=>$message]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            $data = json_decode($resp, true);
            $sent = isset($data['response_code']) && $data['response_code'] == 200;
            return ['sent'=>$sent,'error'=>$sent ? '' : ($data['message'] ?? 'SMS failed')];
        } else {
            $apiUrl = function_exists('getSetting') ? getSetting('notify_sms_api_url', '') : '';
            if (!$apiUrl) return ['sent'=>false,'error'=>'API URL not configured'];
            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10,
                CURLOPT_POSTFIELDS=>json_encode(['phone'=>$phone,'message'=>$message,'token'=>$apiToken]),
                CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ['sent'=>($code>=200&&$code<300),'error'=>$code>=200&&$code<300?'':("HTTP {$code}")];
        }
    } catch (\Throwable $e) {
        return ['sent'=>false,'error'=>$e->getMessage()];
    }
}

/**
 * Send OTP via Email
 */
function sendOTPviaEmail($emailAddr, $otp, $memberName, $siteName = '') {
    if (!$siteName) $siteName = function_exists('getSetting') ? getSetting('site_name', 'सहकारी') : 'सहकारी';
    $subject  = "{$siteName} — पासवर्ड रिसेट OTP";
    $htmlBody = "
    <div style='font-family:sans-serif;max-width:480px;margin:auto;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden'>
      <div style='background:var(--primary-color);padding:20px;text-align:center;color:#fff'>
        <h2 style='margin:0'>{$siteName}</h2>
        <p style='margin:4px 0 0;opacity:.85;font-size:.9rem'>Member Portal</p>
      </div>
      <div style='padding:28px'>
        <p>नमस्ते <strong>" . htmlspecialchars($memberName) . "</strong> जी,</p>
        <p>तपाईंको पासवर्ड रिसेट गर्न OTP:</p>
        <div style='text-align:center;margin:24px 0'>
          <span style='font-size:2.4rem;font-weight:700;letter-spacing:10px;color:var(--primary-color);background:#f0fdf4;padding:14px 28px;border-radius:8px;display:inline-block'>{$otp}</span>
        </div>
        <p style='color:#6b7280;font-size:.85rem'>यो OTP <strong>10 मिनेट</strong>मा expire हुन्छ।<br>अरूसँग share नगर्नुहोस्।</p>
      </div>
      <div style='background:#f9fafb;padding:14px;text-align:center;color:#9ca3af;font-size:.78rem'>
        {$siteName} © " . date('Y') . "
      </div>
    </div>";

    $sent = false;
    try {
        if (function_exists('getSetting') && getSetting('notify_smtp_enabled','0')==='1') {
            if (function_exists('sendSmtpEmail')) {
                $sent = sendSmtpEmail($emailAddr, $subject, $htmlBody);
            }
        } else {
            $fromEmail = function_exists('getSetting') ? getSetting('notify_email_from','noreply@cooperative.com') : 'noreply@cooperative.com';
            $fromName  = $siteName;
            $headers   = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
            $headers  .= "From: {$fromName} <{$fromEmail}>\r\n";
            $sent = @mail($emailAddr, '=?UTF-8?B?'.base64_encode($subject).'?=', $htmlBody, $headers);
        }
    } catch (\Throwable $e) { $sent = false; }
    return ['sent'=>$sent,'error'=>$sent?'':'Email delivery failed'];
}

/**
 * High-level: pick best channel (SMS preferred, email fallback) and send OTP
 * Returns ['channel'=>'sms|email','sent_to'=>'...','sent'=>bool,'error'=>'...']
 */
function dispatchOTP($member, $forcedChannel = 'auto') {
    $phone = preg_replace('/[^0-9]/', '', $member['phone'] ?? '');
    $email = trim($member['email'] ?? '');
    $smsOk = strlen($phone) >= 10 && function_exists('getSetting') && getSetting('notify_sms_enabled','0')==='1' && getSetting('notify_sms_token','')!=='';

    if ($forcedChannel === 'email' || (!$smsOk && $email)) {
        /* Email OTP */
        if (!$email) return ['channel'=>'none','sent_to'=>'','sent'=>false,'error'=>'No email or phone found'];
        $otp    = generateAndStoreOTP($member['id'], 'email', $email);
        $result = sendOTPviaEmail($email, $otp, $member['name']);
        return array_merge($result, ['channel'=>'email','sent_to'=>maskEmail($email),'otp'=>$otp]);
    } else {
        /* SMS OTP */
        if (!$smsOk) {
            /* No SMS and no forced channel — fall through to admin-request flow */
            return ['channel'=>'none','sent_to'=>'','sent'=>false,'error'=>'SMS gateway not configured'];
        }
        $otp    = generateAndStoreOTP($member['id'], 'sms', $phone);
        $result = sendOTPviaSMS($phone, $otp);
        return array_merge($result, ['channel'=>'sms','sent_to'=>maskPhone($phone),'otp'=>$otp]);
    }
}

function maskPhone($p) { return strlen($p)>4 ? str_repeat('*',strlen($p)-4).substr($p,-4) : '****'; }
function maskEmail($e) {
    [$u,$d] = explode('@',$e,2) + ['',''];
    return (strlen($u)>2 ? substr($u,0,2).str_repeat('*',max(1,strlen($u)-2)) : '**').'@'.$d;
}
