<?php
/**
 * Admin: Members list (Member ID SSOT ledger)
 * - Member list, details, direct notification send
 *
 * IMPORTANT: load deps + data BEFORE admin-header output.
 * Heavy schema backfill after header was blanking the main pane on large imports.
 */
if (!defined('IS_ADMIN_PAGE')) {
    define('IS_ADMIN_PAGE', true);
}
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/member-auth.php';
require_once __DIR__ . '/../includes/auth-roles.php';
require_once __DIR__ . '/includes/admin-ui.php';

/* List data loads BEFORE admin-header, so $db is not set yet (header normally assigns it).
   Same pattern as kyc-applications.php — without this, queries see null → "no db". */
if (!isset($db) || !($db instanceof PDO)) {
    try {
        $db = function_exists('getDB') ? getDB() : null;
    } catch (Throwable $e) {
        error_log('[admin/members getDB] ' . $e->getMessage());
        $db = null;
    }
}

/* RBAC: staff hercha matra; mutate admin+ matra */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role('admin');
}

/* Do NOT call ensureMemberTables() on list GET.
   Root cause of blank Active Members page after ~2k Excel import (live main):
   header flushed → ensureMemberTables() ran multi-row UPDATE+KYC JOIN → timeout
   → white main pane while KYC list still worked. Schema CREATE stays in member-auth
   for register/login only; list page only needs SELECT. */

$pageTitle   = 'सबै सदस्य (Member ID)';
$currentPage = 'members';

/* Unstick DBs where backfill flag never got set (timeout before updateSetting). */
try {
    if ($db instanceof PDO) {
        $stFlag = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1");
        $stFlag->execute(['migration_member_schema_backfill_v1']);
        if ((string)$stFlag->fetchColumn() !== '1') {
            if (function_exists('updateSetting')) {
                updateSetting('migration_member_schema_backfill_v1', '1');
            } else {
                $db->exec(
                    "INSERT INTO site_settings (setting_key, setting_value)
                     VALUES ('migration_member_schema_backfill_v1', '1')
                     ON DUPLICATE KEY UPDATE setting_value = '1'"
                );
            }
        }
    }
} catch (Throwable $e) { /* never block list */ }

/* ── Send Notification (single + bulk) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_notif'])) {
    checkCSRF();
    $target   = (string)($_POST['notif_target'] ?? 'single');
    $title    = clean_text($_POST['notif_title']   ?? '');
    $message  = clean_text($_POST['notif_message'] ?? '');
    $type     = in_array($_POST['notif_type'] ?? '', ['info','success','warning','error'], true) ? $_POST['notif_type'] : 'info';
    $url      = SITE_URL . 'member/notifications.php';

    if (trim($title) === '') {
        setFlash('error', 'Title राख्नुहोस्।');
        redirect('members.php');
    }

    if ($target === 'all') {
        $audience = (string)($_POST['notif_audience'] ?? 'active');
        $where = "is_active = 1 AND COALESCE(approval_status, 'approved') IN ('approved','active')";
        if ($audience === 'pending') {
            $where = "approval_status = 'pending'";
        } elseif ($audience === 'kyc_linked') {
            $where = "is_active = 1 AND kyc_application_id IS NOT NULL";
        } elseif ($audience === 'all_active') {
            $where = "is_active = 1";
        }
        $sent = 0;
        try {
            $q = $db->query("SELECT id FROM members WHERE {$where}");
            foreach ($q->fetchAll(PDO::FETCH_COLUMN, 0) ?: [] as $mid) {
                $mid = (int)$mid;
                if ($mid <= 0) continue;
                createMemberNotification($mid, $title, $message, $type, $url);
                $sent++;
            }
            setFlash('success', "Bulk Notification {$sent} जना सदस्यलाई सफलतापूर्वक पठाइयो।");
        } catch (Throwable $e) {
            setFlash('error', 'Bulk send गर्दा त्रुटि भयो: ' . $e->getMessage());
        }
        redirect('members.php');
    }

    $memberId = (int)($_POST['member_id'] ?? 0);
    if ($memberId) {
        createMemberNotification($memberId, $title, $message, $type, $url);
        setFlash('success', 'Notification सफलतापूर्वक पठाइयो!');
    } else {
        setFlash('error', 'Member id गलत।');
    }
    redirect('members.php' . ($memberId ? '?view=' . $memberId : ''));
}

/* ── Toggle active/inactive ── */
if (isset($_POST['toggle_active'])) {
    checkCSRF();
    $mid = (int)$_POST['member_id'];
    try {
        $db->prepare("UPDATE members SET is_active = 1 - COALESCE(is_active, 0) WHERE id=?")->execute([$mid]);
        setFlash('success', 'Member status बदलियो।');
        if (function_exists('writeAuditLog')) {
            writeAuditLog('member_status_toggle', "Toggled active status for member ID: {$mid}", 'member', $mid);
        }
    } catch (Throwable $e) {
        setFlash('error', 'Status बदल्न सकिएन।');
    }
    redirect('members.php');
}

/* ── View single member ── */
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$viewMember = null;
$viewApps   = [];
$viewNotifs = [];
$viewCard   = null;
if ($viewId > 0) {
    try {
        $st = $db->prepare("SELECT * FROM members WHERE id=?");
        $st->execute([$viewId]);
        $viewMember = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($viewMember) {
            if (function_exists('getMemberApplications')) {
                $viewApps = getMemberApplications($viewMember['email'] ?? '', $viewMember['phone'] ?? '', 30, $viewMember['id'] ?? null);
            }
            try {
                $nst = $db->prepare("SELECT * FROM member_notifications WHERE member_id=? ORDER BY created_at DESC LIMIT 20");
                $nst->execute([$viewId]);
                $viewNotifs = $nst->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $viewNotifs = [];
            }
            try {
                $cs = $db->prepare(
                    "SELECT card_no, verification_code, cvv, issued_date, status
                       FROM member_id_cards
                      WHERE (member_id = :id OR member_id = :sid)
                      ORDER BY id DESC LIMIT 1"
                );
                $cs->execute([
                    ':id'  => (string)$viewMember['id'],
                    ':sid' => (string)($viewMember['sadasyata_number'] ?? ''),
                ]);
                $viewCard = $cs->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {
                $viewCard = null;
            }
        }
    } catch (Throwable $e) {
        error_log('[admin/members view] ' . $e->getMessage());
        $viewMember = null;
    }
}

/* ── Member list (before header so failures never blank the chrome mid-page) ── */
$search = function_exists('mb_substr')
    ? mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200, 'UTF-8')
    : substr(trim((string)($_GET['search'] ?? '')), 0, 200);
$kycFilter = trim((string)($_GET['kyc'] ?? 'all'));
if (!in_array($kycFilter, ['all', 'linked', 'unlinked', 'no_password'], true)) {
    $kycFilter = 'all';
}
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$memSub = isset($_GET['mem_sub']) ? (string) $_GET['mem_sub'] : 'live';
if (!in_array($memSub, ['live', 'arch'], true)) {
    $memSub = 'live';
}

$listError = '';
$listErrorDetail = '';
$countLiveMembers = 0;
$countArchMembers = 0;
$totalCount = 0;
$members = [];
$showAllActiveFallback = false;
$whereList = '1=1';
$paramsBase = [];

/* Cheap one-shot column heal — DDL only, never multi-row UPDATE.
   Live import DBs often lack columns that CREATE TABLE IF NOT EXISTS never adds. */
$colHeal = [
    "ALTER TABLE members ADD COLUMN is_active TINYINT NOT NULL DEFAULT 1",
    "ALTER TABLE members ADD COLUMN approval_status VARCHAR(20) DEFAULT 'pending'",
    "ALTER TABLE members ADD COLUMN google_id VARCHAR(255) NULL",
    "ALTER TABLE members ADD COLUMN facebook_id VARCHAR(255) NULL",
    "ALTER TABLE members ADD COLUMN avatar_url VARCHAR(500) NULL",
    "ALTER TABLE members ADD COLUMN password_hash VARCHAR(255) NULL",
    "ALTER TABLE members ADD COLUMN kyc_application_id INT NULL DEFAULT NULL",
    "ALTER TABLE members ADD COLUMN sadasyata_number VARCHAR(50) NOT NULL DEFAULT ''",
    "ALTER TABLE members ADD COLUMN phone VARCHAR(20) NULL",
    "ALTER TABLE members ADD COLUMN member_card_no VARCHAR(50) NULL",
    "ALTER TABLE members ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP",
];
if ($db instanceof PDO) {
    foreach ($colHeal as $ddl) {
        try { $db->exec($ddl); } catch (Throwable $e) { /* already exists */ }
    }
}

$hasCol = static function (string $col): bool {
    return function_exists('safeColumnExists') ? safeColumnExists('members', $col) : true;
};
$hasIsActive = $hasCol('is_active');
$hasApproval = $hasCol('approval_status');
$hasGoogle = $hasCol('google_id');
$hasFacebook = $hasCol('facebook_id');
$hasAvatar = $hasCol('avatar_url');
$hasPassword = $hasCol('password_hash');
$hasKycId = $hasCol('kyc_application_id');
$hasSadasyata = $hasCol('sadasyata_number');
$hasPhone = $hasCol('phone');
$hasEmail = $hasCol('email');
$hasCardNo = $hasCol('member_card_no');
$hasCreated = $hasCol('created_at');

$where = '1=1';
$params = [];
if ($search !== '') {
    $searchParts = [];
    $t = '%' . $search . '%';
    if ($hasCol('name')) { $searchParts[] = 'name LIKE ?'; $params[] = $t; }
    if ($hasEmail) { $searchParts[] = 'email LIKE ?'; $params[] = $t; }
    if ($hasPhone) { $searchParts[] = 'phone LIKE ?'; $params[] = $t; }
    if ($hasSadasyata) { $searchParts[] = 'sadasyata_number LIKE ?'; $params[] = $t; }
    if ($hasCardNo) { $searchParts[] = 'member_card_no LIKE ?'; $params[] = $t; }
    if ($searchParts) {
        $where .= ' AND (' . implode(' OR ', $searchParts) . ')';
    }
}
if ($kycFilter === 'linked' && $hasKycId && $hasPassword) {
    $where .= " AND kyc_application_id IS NOT NULL AND kyc_application_id <> 0 AND password_hash IS NOT NULL AND password_hash <> ''";
} elseif ($kycFilter === 'unlinked' && $hasKycId) {
    $where .= " AND (kyc_application_id IS NULL OR kyc_application_id = 0)";
} elseif ($kycFilter === 'no_password' && $hasPassword) {
    $where .= " AND (password_hash IS NULL OR password_hash = '')";
}

$whereBase = $where;
$paramsBase = $params;

$liveClause = $hasIsActive ? ' AND COALESCE(is_active, 1) = 1' : '';
$archClause = $hasIsActive ? ' AND COALESCE(is_active, 1) = 0' : ' AND 0=1';

try {
    if (!($db instanceof PDO)) {
        throw new RuntimeException('Database connection unavailable');
    }

    $cntLiveSt = $db->prepare("SELECT COUNT(*) FROM members WHERE $whereBase" . ($hasIsActive ? $liveClause : ''));
    $cntLiveSt->execute($paramsBase);
    $countLiveMembers = (int) $cntLiveSt->fetchColumn();

    if ($hasIsActive) {
        $cntArchSt = $db->prepare("SELECT COUNT(*) FROM members WHERE $whereBase" . $archClause);
        $cntArchSt->execute($paramsBase);
        $countArchMembers = (int) $cntArchSt->fetchColumn();
    } else {
        $countArchMembers = 0;
        $countLiveMembers = (int) $countLiveMembers;
    }

    /* No is_active column → treat entire ledger as live */
    if (!$hasIsActive) {
        $memSub = 'live';
    }

    $activeClause = $memSub === 'live' ? $liveClause : $archClause;
    $whereList = $whereBase . $activeClause;

    $total = $db->prepare("SELECT COUNT(*) FROM members WHERE $whereList");
    $total->execute($paramsBase);
    $totalCount = (int)$total->fetchColumn();

    if ($totalCount === 0 && $countLiveMembers === 0 && $countArchMembers === 0) {
        $raw = $db->prepare("SELECT COUNT(*) FROM members WHERE $whereBase");
        $raw->execute($paramsBase);
        $rawTotal = (int)$raw->fetchColumn();
        if ($rawTotal > 0) {
            $whereList = $whereBase;
            $totalCount = $rawTotal;
            $showAllActiveFallback = true;
            $countLiveMembers = $rawTotal;
        }
    }

    $limit = max(1, min(100, (int)$limit));
    $offset = max(0, (int)$offset);
    if ($totalCount > 0 && $offset >= $totalCount) {
        $page = max(1, (int)ceil($totalCount / $limit));
        $offset = ($page - 1) * $limit;
    }

    $selectCols = ['id', 'name'];
    if ($hasEmail) $selectCols[] = 'email';
    if ($hasPhone) $selectCols[] = 'phone';
    if ($hasSadasyata) $selectCols[] = 'sadasyata_number';
    if ($hasAvatar) $selectCols[] = 'avatar_url';
    if ($hasGoogle) $selectCols[] = 'google_id';
    if ($hasFacebook) $selectCols[] = 'facebook_id';
    if ($hasPassword) $selectCols[] = 'password_hash';
    if ($hasKycId) $selectCols[] = 'kyc_application_id';
    if ($hasIsActive) $selectCols[] = 'is_active';
    if ($hasApproval) $selectCols[] = 'approval_status';
    if ($hasCreated) $selectCols[] = 'created_at';
    if ($hasCardNo) $selectCols[] = 'member_card_no';

    $sql = 'SELECT ' . implode(', ', $selectCols) . "
            FROM members
            WHERE $whereList
            ORDER BY id DESC
            LIMIT {$limit} OFFSET {$offset}";
    $st = $db->prepare($sql);
    $st->execute($paramsBase);
    $members = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('[admin/members list] ' . $e->getMessage());
    $listErrorDetail = $e->getMessage();
    /* Absolute fallback — ignore filters/optional columns; still show imported rows */
    try {
        if (!($db instanceof PDO) && function_exists('getDB')) {
            $db = getDB();
        }
        if (!($db instanceof PDO)) {
            throw new RuntimeException('Database connection unavailable');
        }
        $totalCount = (int) $db->query('SELECT COUNT(*) FROM members')->fetchColumn();
        $countLiveMembers = $totalCount;
        $countArchMembers = 0;
        $showAllActiveFallback = true;
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);
        if ($totalCount > 0 && $offset >= $totalCount) {
            $page = max(1, (int)ceil($totalCount / $limit));
            $offset = ($page - 1) * $limit;
        }
        $members = $db->query(
            "SELECT * FROM members ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $listError = '';
        $listErrorDetail = '';
    } catch (Throwable $e2) {
        error_log('[admin/members list fallback] ' . $e2->getMessage());
        $listError = 'सदस्य सूची लोड गर्न समस्या भयो। कृपया पुनः प्रयास गर्नुहोस्।';
        $listErrorDetail = $e2->getMessage();
        $members = [];
        $totalCount = 0;
    }
}

$memPreserveQ = array_filter([
    'search' => $search !== '' ? $search : null,
    'kyc' => $kycFilter !== 'all' ? $kycFilter : null,
], static function ($v) {
    return $v !== null && $v !== '';
});

$totalPages = max(1, (int)ceil(max(1, $totalCount) / max(1, $limit)));
if ($totalCount === 0) {
    $totalPages = 1;
}

$stats = ['total'=>0,'active'=>0,'pending'=>0,'renewal'=>0,'kyc_linked'=>0,'google'=>0,'facebook'=>0];
try {
    if ($db instanceof PDO) {
        $stats['total'] = (int) $db->query('SELECT COUNT(*) FROM members')->fetchColumn();
        if ($hasIsActive) {
            $stats['active'] = (int) $db->query('SELECT COUNT(*) FROM members WHERE COALESCE(is_active, 1) = 1')->fetchColumn();
        } else {
            $stats['active'] = $stats['total'];
        }
        if ($hasApproval) {
            $stats['pending'] = (int) $db->query("SELECT COUNT(*) FROM members WHERE approval_status = 'pending'")->fetchColumn();
            $stats['renewal'] = (int) $db->query("SELECT COUNT(*) FROM members WHERE approval_status = 'renewal_pending'")->fetchColumn();
        }
        if ($hasKycId) {
            $stats['kyc_linked'] = (int) $db->query('SELECT COUNT(*) FROM members WHERE kyc_application_id IS NOT NULL')->fetchColumn();
        }
        if ($hasGoogle) {
            $stats['google'] = (int) $db->query("SELECT COUNT(*) FROM members WHERE google_id IS NOT NULL AND google_id != ''")->fetchColumn();
        }
        if ($hasFacebook) {
            $stats['facebook'] = (int) $db->query("SELECT COUNT(*) FROM members WHERE facebook_id IS NOT NULL AND facebook_id != ''")->fetchColumn();
        }
    }
} catch (Throwable $e) {
    error_log('[admin/members stats] ' . $e->getMessage());
}

require_once __DIR__ . '/includes/admin-header.php';
?>

<div class="container-fluid py-3">
<?php
try {
    if (function_exists('adminHelpTip')) {
        echo adminHelpTip(
            'यो पृष्ठबाट संस्थाका सदस्यहरूको सूची र स्थिति देख्न सकिन्छ।',
            ['Pending सदस्य approve गर्न: "Approve" बटन थिच्नुहोस्।', 'सदस्य खोज्न: माथिको Search box प्रयोग गर्नुहोस्।', 'KYC status हेर्न: सदस्यको नाममा क्लिक गर्नुहोस्।']
        );
    }
} catch (Throwable $e) { /* tip must never blank the page */ }
?>

<?php if (!empty($showAllActiveFallback)): ?>
<div class="alert alert-warning mb-3">
    <i class="fas fa-info-circle me-2"></i>
    सक्रिय/निष्क्रिय फिल्टर मिलेन — सबै सदस्य देखाइँदैछ। Import पछिको डेटा ठिकै छ कि जाँच्नुहोस्।
</div>
<?php endif; ?>

<?php if ($flash = getFlash()): ?>
<div class="alert alert-<?php echo $flash['type']==='success'?'success':'danger'; ?> alert-dismissible fade show mb-3"><i class="fas fa-<?php echo $flash['type']==='success'?'check-circle':'exclamation-circle'; ?> me-2"></i><?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if ($listError !== ''): ?>
<div class="alert alert-danger mb-3"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($listError, ENT_QUOTES, 'UTF-8'); ?>
<?php if ($listErrorDetail !== ''): ?>
<div class="small mt-1 text-muted"><code><?php echo htmlspecialchars(function_exists('mb_substr') ? mb_substr($listErrorDetail, 0, 240, 'UTF-8') : substr($listErrorDetail, 0, 240), ENT_QUOTES, 'UTF-8'); ?></code></div>
<?php endif; ?>
</div>
<?php endif; ?>


<?php if (function_exists('displayFlash')) { displayFlash(); } ?>

<?php if ($viewMember): /* ── Single Member View ── */ ?>

<div class="d-flex align-items-center gap-2 mb-3">
    <a href="members.php" class="btn btn-outline-secondary btn-sm"><i class="lucide-icon me-1" aria-hidden="true" data-lucide="arrow-left"></i>फिर्ता</a>
    <h4 class="mb-0">Member विवरण</h4>
</div>

<div class="row g-3">
    <!-- Member Info Card -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-4">
                <?php if ($viewMember['avatar_url']): ?>
                <img src="<?php echo htmlspecialchars($viewMember['avatar_url']); ?>" class="rounded-circle mb-3 mem-avatar-lg">
                <?php else: ?>
                <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center mx-auto mb-3 mem-avatar-fallback-lg">
                    <?php echo function_exists('mb_substr') ? mb_substr((string)($viewMember['name'] ?? ''), 0, 1) : substr((string)($viewMember['name'] ?? ''), 0, 1); ?>
                </div>
                <?php endif; ?>
                <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($viewMember['name']); ?></h5>
                <div class="text-muted small"><code><?php echo htmlspecialchars($viewMember['sadasyata_number'] ?? '—'); ?></code></div>
                <div class="mt-2">
                    <?php if ($viewMember['google_id']): ?><span class="badge mem-badge-google"><i class="fa-brands fa-google me-1"></i>Google</span><?php endif; ?>
                    <?php if ($viewMember['facebook_id']): ?><span class="badge mem-badge-facebook"><i class="fa-brands fa-facebook-f me-1"></i>Facebook</span><?php endif; ?>
                    <?php if ($viewMember['password_hash']): ?><span class="badge bg-success">Email</span><?php endif; ?>
                </div>
            </div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span class="text-muted small fw-bold">सदस्यता नं. / Member ID</span>
                    <code class="small"><?php echo htmlspecialchars($viewMember['sadasyata_number'] ?? '—'); ?></code>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span class="text-muted small fw-bold">लिंक स्थिति</span>
                    <span class="small"><?php
                        $vs = function_exists('memberSsotStatusForMemberRow') ? memberSsotStatusForMemberRow($viewMember) : 'unknown';
                        echo function_exists('memberSsotStatusBadgeHtml') ? memberSsotStatusBadgeHtml($vs) : '—';
                    ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span class="text-muted small fw-bold">इमेल</span>
                    <span class="small"><?php echo htmlspecialchars($viewMember['email'] ?? '—'); ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span class="text-muted small fw-bold">मोबाइल</span>
                    <span class="small"><?php echo htmlspecialchars($viewMember['phone'] ?? '—'); ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span class="text-muted small fw-bold">ठेगाना</span>
                    <span class="small"><?php echo htmlspecialchars($viewMember['address'] ?? '—'); ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span class="text-muted small fw-bold">दर्ता</span>
                    <span class="small"><?php echo formatNepaliDate($viewMember['created_at']); ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span class="text-muted small fw-bold">अवस्था</span>
                    <span class="badge <?php echo $viewMember['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                        <?php echo $viewMember['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?>
                    </span>
                </li>
                <?php
                /* Issue #3: Card validity / expiry display */
                $cExp = $viewMember['card_expires_at'] ?? '';
                if ($cExp):
                    $cExpTs = strtotime($cExp);
                    $isExp  = $cExpTs && $cExpTs < time();
                ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span class="text-muted small fw-bold">Card म्याद</span>
                    <span class="badge <?php echo $isExp ? 'bg-danger' : 'bg-info'; ?>">
                        <?php echo date('Y-m-d', $cExpTs); ?>
                        <?php echo $isExp ? ' (Expired)' : ''; ?>
                    </span>
                </li>
                <?php endif; ?>
            </ul>

            <?php /* Member ID + CVV — पुरानो Card No / Verification Code = Member ID नै */ ?>
            <?php
            $adminFaceId = trim((string)($viewMember['sadasyata_number'] ?? ''));
            if ($adminFaceId === '') {
                $rawMc = trim((string)($viewCard['card_no'] ?? $viewMember['member_card_no'] ?? ''));
                if ($rawMc !== '' && !preg_match('/^[A-Z]{2,4}-\d{4}-\d+$/i', $rawMc)) {
                    $adminFaceId = $rawMc;
                }
            }
            if ($adminFaceId === '') {
                $adminFaceId = 'M-' . str_pad((string)($viewMember['id'] ?? 0), 5, '0', STR_PAD_LEFT);
            }
            ?>
            <?php if ($viewCard && (!empty($viewCard['cvv']) || $adminFaceId !== '')): ?>
            <div class="card-body border-top mem-card-secret">
                <div class="fw-bold small text-warning-emphasis mb-2">
                    <i class="lucide-icon" aria-hidden="true" data-lucide="shield-halved"></i> ID Card विवरण
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted small">सदस्यता नं. / Member ID</span>
                    <code class="small text-success fw-bold"><?php echo htmlspecialchars($adminFaceId); ?></code>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted small">CVV</span>
                    <code class="small text-danger fw-bold mem-cvv-code"><?php echo htmlspecialchars($viewCard['cvv'] ?? '—'); ?></code>
                </div>
                <div class="small text-muted mt-2 mem-help-xs">
                    Member ID नै पहिचान हो (पुरानो card/verify code छुट्टै होइन)। Verify: नाम + Member ID + मोबाइल।
                </div>
            </div>
            <?php endif; ?>
            <div class="card-body border-top">
                <div class="fw-bold small mb-2 text-success"><i class="fas fa-link me-1"></i>SSOT shortcuts</div>
                <div class="d-grid gap-2">
                    <?php if (!empty($viewMember['kyc_application_id'])): ?>
                    <a href="kyc-applications.php?view=<?php echo (int)$viewMember['kyc_application_id']; ?>" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-id-card me-1"></i>केवाइएम खोल्नुहोस्
                    </a>
                    <?php elseif (!empty($viewMember['sadasyata_number'])): ?>
                    <a href="kyc-applications.php?search=<?php echo urlencode((string)$viewMember['sadasyata_number']); ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-search me-1"></i>केवाइएम खोज (Member ID)
                    </a>
                    <?php endif; ?>
                    <a href="member-online-portal.php?view=<?php echo (int)$viewMember['id']; ?>" class="btn btn-sm btn-outline-success">
                        <i class="fas fa-globe me-1"></i>Portal unlock / approve
                    </a>
                    <?php if (empty($viewMember['password_hash'])): ?>
                    <div class="small text-muted">पासवर्ड छैन — सदस्यले register गरेर सेट गर्नुपर्छ, वा import temp password प्रयोग।</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="toggle_active" value="1">
                    <input type="hidden" name="member_id" value="<?php echo $viewMember['id']; ?>">
                    <button type="submit" class="btn btn-sm w-100 <?php echo $viewMember['is_active'] ? 'btn-outline-danger' : 'btn-outline-success'; ?>"
                            onclick="return confirm('Member status बदल्ने?')">
                        <i class="fas fa-<?php echo $viewMember['is_active'] ? 'ban' : 'check'; ?> me-1"></i>
                        <?php echo $viewMember['is_active'] ? 'निष्क्रिय गर्नुहोस्' : 'सक्रिय गर्नुहोस्'; ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <!-- Send Notification -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-bold text-success">
                <i class="lucide-icon me-2" aria-hidden="true" data-lucide="bell"></i>Member लाई Notification पठाउनुहोस्
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="send_notif" value="1">
                    <input type="hidden" name="member_id" value="<?php echo $viewMember['id']; ?>">
                    <div class="row g-2">
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold" for="mem_notif_title">शीर्षक <span class="text-danger">*</span></label>
                            <input type="text" name="notif_title" id="mem_notif_title" class="form-control" required
                                   placeholder="Notification शीर्षक" maxlength="200">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold" for="mem_notif_type">प्रकार</label>
                            <select name="notif_type" id="mem_notif_type" class="form-select">
                                <option value="info">📘 सूचना</option>
                                <option value="success">✅ सफलता</option>
                                <option value="warning">⚠️ सतर्कता</option>
                                <option value="error">❌ अस्वीकृति</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold" for="mem_notif_msg">सन्देश (ऐच्छिक)</label>
                            <textarea name="notif_message" id="mem_notif_msg" class="form-control" rows="2"
                                      placeholder="विस्तृत सन्देश"></textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-success btn-sm">
                                <i class="fas fa-paper-plane me-1"></i>Notification पठाउनुहोस्
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tabs: Applications | Notifications -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white p-0">
                <ul class="nav nav-tabs admin-nav-tabs px-3 pt-2" id="memTabs">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabApps">
                        <i class="fas fa-file-alt me-1"></i>आवेदनहरू (<?php echo count($viewApps); ?>)
                    </a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabNotifs">
                        <i class="lucide-icon me-1" aria-hidden="true" data-lucide="bell"></i>Notifications (<?php echo count($viewNotifs); ?>)
                    </a></li>
                </ul>
            </div>
            <div class="card-body tab-content p-0">
                <!-- Applications tab -->
                <div class="tab-pane fade show active p-3" id="tabApps">
                    <?php if (empty($viewApps)): ?>
                    <div class="text-center text-muted py-4"><i class="lucide-icon fa-2x mb-2 d-block opacity-25" aria-hidden="true" data-lucide="inbox"></i>कुनै आवेदन छैन</div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead><tr><th>सेवा</th><th>विवरण</th><th>अवस्था</th><th>मिति</th><th>Tracking</th></tr></thead>
                            <tbody>
                            <?php foreach ($viewApps as $app): ?>
                            <tr>
                                <td><span class="badge mem-service-badge" data-service-color="<?php echo htmlspecialchars($app['service_color'] ?? '#16a34a', ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="fas <?php echo $app['service_icon']; ?> me-1"></i><?php echo $app['service_name']; ?>
                                </span></td>
                                <td class="small"><?php echo htmlspecialchars(mb_strimwidth($app['detail']??'', 0, 35, '…')); ?></td>
                                <td><?php echo memberStatusBadge($app['status']); ?></td>
                                <td class="small text-muted"><?php echo formatNepaliDate($app['created_at']); ?></td>
                                <td><code class="small"><?php echo htmlspecialchars($app['tracking_id'] ?? '—'); ?></code></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <!-- Notifications tab -->
                <div class="tab-pane fade p-3" id="tabNotifs">
                    <?php if (empty($viewNotifs)): ?>
                    <div class="text-center text-muted py-4"><i class="fas fa-bell-slash fa-2x mb-2 d-block opacity-25"></i>कुनै notification छैन</div>
                    <?php else: ?>
                    <?php
                    $icMap = ['success'=>'bg-success','error'=>'bg-danger','warning'=>'bg-warning','info'=>'bg-primary'];
                    foreach ($viewNotifs as $n): ?>
                    <div class="d-flex align-items-start gap-2 mb-3 pb-3 border-bottom">
                        <span class="badge rounded-pill <?php echo $icMap[$n['type']] ?? 'bg-secondary'; ?> mem-notif-pill">
                            <i class="lucide-icon" aria-hidden="true" data-lucide="bell"></i>
                        </span>
                        <div class="flex-grow-1">
                            <div class="fw-bold small"><?php echo htmlspecialchars($n['title']); ?>
                                <?php if (!$n['is_read']): ?><span class="badge bg-warning text-dark ms-1 mem-unread-pill">Unread</span><?php endif; ?>
                            </div>
                            <div class="text-muted mem-notif-message"><?php echo nl2br(htmlspecialchars($n['message'] ?? '')); ?></div>
                            <div class="text-muted mem-notif-time"><?php echo formatNepaliDate($n['created_at'], true); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php else: /* ── Member List ── */ ?>

<?php if (function_exists('memberSsotAdminHelpHtml')) { echo memberSsotAdminHelpHtml('members'); } ?>

<!-- Pending approval banner -->
<?php if (!empty($stats['pending']) && $stats['pending'] > 0): ?>
<div class="alert alert-warning border-start border-warning border-4 d-flex align-items-center justify-content-between mb-3" role="alert">
    <div>
        <i class="lucide-icon me-2" aria-hidden="true" data-lucide="clock"></i>
        <strong><?php echo $stats['pending']; ?> Member</strong> दर्ता अनुमोदन प्रतीक्षामा छ।
    </div>
    <a href="member-online-portal.php?status=pending" class="btn btn-warning btn-sm fw-bold">
        <i class="lucide-icon me-1" aria-hidden="true" data-lucide="check-circle"></i>अनुमोदन गर्नुहोस् →
    </a>
</div>
<?php endif; ?>

<!-- Issue #3: Renewal-pending banner -->
<?php if (!empty($stats['renewal']) && $stats['renewal'] > 0): ?>
<div class="alert alert-info border-start border-info border-4 d-flex align-items-center justify-content-between mb-3" role="alert">
    <div>
        <i class="fas fa-rotate me-2"></i>
        <strong><?php echo $stats['renewal']; ?> Member</strong> को card म्याद सकिएको छ — renewal प्रतीक्षामा।
    </div>
    <a href="?search=" class="btn btn-info btn-sm fw-bold text-white">
        <i class="fas fa-list me-1"></i>हेर्नुहोस् →
    </a>
</div>
<?php endif; ?>

<!-- Stats -->
<?php
    $statCards = [
        ['icon'=>'fa-users',              'label'=>'कुल Members',      'value'=>$stats['total'],             'color'=>'primary', 'link'=>'members.php'],
        ['icon'=>'fa-user-check',         'label'=>'सक्रिय',           'value'=>$stats['active'],            'color'=>'success', 'link'=>'members.php?status=active'],
        ['icon'=>'fa-clock',              'label'=>'प्रतीक्षामा',      'value'=>$stats['pending'] ?? 0,      'color'=>'warning', 'link'=>'members.php?status=pending'],
        ['icon'=>'fa-rotate',             'label'=>'Renewal Pending',   'value'=>$stats['renewal'] ?? 0,      'color'=>'info',    'link'=>'members.php?renewal=1'],
        ['icon'=>'fa-link',               'label'=>'KYC Linked',        'value'=>$stats['kyc_linked'] ?? 0,   'color'=>'secondary'],
        ['icon'=>'fa-g',                  'label'=>'Google Login',       'value'=>$stats['google'],            'color'=>'danger'],
        ['icon'=>'fa-f',                  'label'=>'Facebook Login',     'value'=>$stats['facebook'],          'color'=>'primary'],
    ];
    $statColClass = 'col-6 col-sm-4 col-md-3 col-lg-2';
    include __DIR__ . '/../includes/components/stat-card.php';
?>

<!-- Search + Table — अरू admin सूची जस्तै -->
<div class="card admin-table-card svc-flat-top-card border-0 shadow-sm">
    <div class="admin-search-wrap px-3 py-2 border-bottom bg-light d-flex align-items-center gap-3 flex-wrap">
        <form class="d-flex flex-wrap align-items-center gap-2 flex-grow-1" method="get" action="members.php">
            <input type="hidden" name="mem_sub" value="<?php echo htmlspecialchars($memSub, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="input-group input-group-sm mem-search-group" style="max-width:min(100%, 320px)">
                <span class="input-group-text bg-white border-end-0"><i class="lucide-icon text-muted" aria-hidden="true" data-lucide="search"></i></span>
                <input type="text" name="search" class="form-control border-start-0 mem-filter-search" placeholder="नाम / सदस्यता नं / इमेल / फोन…"
                       value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
            </div>
            <select name="kyc" class="form-select form-select-sm mem-filter-kyc" title="लिंक फिल्टर">
                <option value="all" <?php echo $kycFilter==='all' ? 'selected' : ''; ?>>लिंक: सबै</option>
                <option value="linked" <?php echo $kycFilter==='linked' ? 'selected' : ''; ?>>Linked (KYM+पासवर्ड)</option>
                <option value="unlinked" <?php echo $kycFilter==='unlinked' ? 'selected' : ''; ?>>Member only (KYM छैन)</option>
                <option value="no_password" <?php echo $kycFilter==='no_password' ? 'selected' : ''; ?>>Stub (पासवर्ड छैन)</option>
            </select>
            <button type="submit" class="btn btn-sm btn-success"><i class="lucide-icon me-1" aria-hidden="true" data-lucide="search"></i>खोज</button>
            <?php if ($search !== '' || $kycFilter !== 'all'): ?>
                <a href="members.php<?php echo $memSub === 'arch' ? '?mem_sub=arch' : ''; ?>" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#bulkNotifModal" title="सबै सदस्यलाई एकैचोटि सूचना पठाउनुहोस्">
                <i class="fas fa-bullhorn me-1"></i>Bulk Notification
            </button>
            <a href="member-import.php" class="btn btn-sm btn-success" title="पुराना सदस्य CSV बाट import">
                <i class="fas fa-file-csv me-1"></i>Bulk Import
            </a>
            <a href="member-ssot-duplicates.php" class="btn btn-sm btn-outline-warning" title="दोहोरो Member ID जाँच">
                <i class="fas fa-clone me-1"></i>Duplicate IDs
            </a>
        </form>
        <small class="text-muted">
            <?php echo $memSub === 'live' ? 'सक्रिय सदस्य' : 'अभिलेख (निष्क्रिय)'; ?>
            <?php if ($totalCount > 0): ?>
                · <?php echo $offset + 1; ?>–<?php echo $offset + count($members); ?> / <?php echo $totalCount; ?>
            <?php else: ?> · 0 / 0<?php endif; ?>
        </small>
    </div>
    <div class="card-body p-0">
        <?php echo adminListSubtabQueryLinks('mem-sub', $countLiveMembers, $countArchMembers, 'mem_sub', $memSub, 'members.php', $memPreserveQ); ?>
        <?php if (empty($members)): ?>
        <div class="text-center text-muted py-5 px-3">
            <i class="fas fa-user-slash fa-3x mb-3 opacity-25"></i>
            <div><?php
                if ($listError !== '') {
                    echo 'सूची अहिले देखाइएन।';
                } elseif ($search !== '') {
                    echo "'" . htmlspecialchars($search, ENT_QUOTES, 'UTF-8') . "' फेला परेन।";
                } elseif ($memSub === 'arch') {
                    echo 'अभिलेखमा कुनै सदस्य छैन।';
                } elseif ($countArchMembers > 0) {
                    echo 'सक्रिय सूची खाली छ — ' . (int)$countArchMembers . ' जना अभिलेख (निष्क्रिय) ट्याबमा छन्।';
                } else {
                    echo 'अहिलेसम्म कुनै सक्रिय Member छैन।';
                }
            ?></div>
            <?php if ($memSub === 'live' && $countArchMembers > 0 && $listError === ''): ?>
            <div class="mt-2">
                <a class="btn btn-sm btn-outline-secondary" href="members.php?mem_sub=arch<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>">
                    अभिलेख हेर्नुहोस् (<?php echo (int)$countArchMembers; ?>)
                </a>
            </div>
            <?php endif; ?>
            <small class="text-muted mt-2 d-block">
                थप्न: <a href="member-import.php">Bulk Import</a>
                · नयाँ व्यक्ति: <a href="membership-applications.php">सदस्यता अनुरोध</a>
                · कागजात: <a href="kyc-applications.php">KYM</a>
                · लगइन: <a href="member-online-portal.php">Portal unlock</a>
            </small>
        </div>
        <?php else: ?>
        <div class="table-responsive admin-table-card">
            <table class="table table-hover align-middle mb-0 table-responsive-stack">
                <thead class="table-light"><tr>
                    <th>#</th><th>Member</th><th>सदस्यता नं. / Member ID</th><th>लिंक स्थिति</th><th>मोबाइल</th>
                    <th>Login विधि</th><th>दर्ता</th><th>अवस्था</th><th>Action</th>
                </tr></thead>
                <tbody>
                <?php foreach ($members as $i => $m):
                    try {
                    $ssotCode = function_exists('memberSsotStatusForMemberRow') ? memberSsotStatusForMemberRow($m) : 'unknown';
                    $mName = trim((string)($m['name'] ?? ''));
                    $mInitial = $mName !== ''
                        ? (function_exists('mb_substr') ? mb_substr($mName, 0, 1, 'UTF-8') : substr($mName, 0, 1))
                        : '?';
                ?>
                <tr>
                    <td class="text-muted small"><?php echo $offset + $i + 1; ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?php if (!empty($m['avatar_url'])): ?>
                            <img src="<?php echo htmlspecialchars((string)$m['avatar_url'], ENT_QUOTES, 'UTF-8'); ?>" class="rounded-circle mem-avatar-sm" alt="">
                            <?php else: ?>
                            <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center fw-bold mem-avatar-fallback-sm">
                                <?php echo htmlspecialchars($mInitial, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <?php endif; ?>
                            <div>
                                <div class="fw-bold small"><?php echo htmlspecialchars($mName !== '' ? $mName : '—', ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="text-muted mem-email-xs"><?php echo htmlspecialchars((string)($m['email'] ?? '') !== '' ? (string)$m['email'] : '—', ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="small"><code><?php echo htmlspecialchars($m['sadasyata_number'] ?? '—'); ?></code></td>
                    <td class="small"><?php echo function_exists('memberSsotStatusBadgeHtml') ? memberSsotStatusBadgeHtml($ssotCode) : '—'; ?></td>
                    <td class="small"><?php echo htmlspecialchars($m['phone'] ?? '—'); ?></td>
                    <td>
                        <?php if ($m['google_id']): ?><span class="badge mem-badge-google mem-login-pill"><i class="fa-brands fa-google me-1"></i>G</span><?php endif; ?>
                        <?php if ($m['facebook_id']): ?><span class="badge mem-badge-facebook mem-login-pill"><i class="fa-brands fa-facebook-f me-1"></i>FB</span><?php endif; ?>
                        <?php if ($m['password_hash']): ?><span class="badge bg-success mem-login-pill">Email</span><?php endif; ?>
                    </td>
                    <td class="small text-muted"><?php echo formatNepaliDate($m['created_at']); ?></td>
                    <td>
                        <span class="badge <?php echo !empty($m['is_active']) || !array_key_exists('is_active', $m) ? 'bg-success' : 'bg-secondary'; ?>">
                            <?php echo !empty($m['is_active']) || !array_key_exists('is_active', $m) ? 'Active' : 'Inactive'; ?>
                        </span>
                        <?php
                        $as = $m['approval_status'] ?? 'pending';
                        $asBadge = ['pending'=>'bg-warning text-dark','approved'=>'bg-success','rejected'=>'bg-danger','renewal_pending'=>'bg-info text-dark'];
                        $asLabel = ['pending'=>'⏳ Pending','approved'=>'✅ Approved','rejected'=>'❌ Rejected','renewal_pending'=>'🔄 Renewal'];
                        $bClass  = $asBadge[$as] ?? 'bg-secondary';
                        $bLabel  = $asLabel[$as] ?? $as;
                        echo "<br><span class='badge $bClass mem-status-pill'>$bLabel</span>";
                        ?>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm" role="group">
                            <a href="members.php?view=<?php echo (int)$m['id']; ?>" class="btn btn-outline-secondary" title="Member विवरण">
                                <i class="fas fa-user"></i>
                            </a>
                            <a href="member-online-portal.php?view=<?php echo (int)$m['id']; ?>" class="btn btn-outline-success" title="Portal">
                                <i class="fas fa-globe"></i>
                            </a>
                            <?php if (!empty($m['kyc_application_id'])): ?>
                            <a href="kyc-applications.php?view=<?php echo (int)$m['kyc_application_id']; ?>" class="btn btn-outline-primary" title="केवाइएम">
                                <i class="fas fa-id-card"></i>
                            </a>
                            <?php elseif (!empty($m['sadasyata_number'])): ?>
                            <a href="kyc-applications.php?search=<?php echo urlencode((string)$m['sadasyata_number']); ?>" class="btn btn-outline-secondary" title="केवाइएम खोज">
                                <i class="fas fa-search"></i>
                            </a>
                            <?php endif; ?>
                            <?php if ($as === 'pending' || $as === 'renewal_pending'): ?>
                            <a href="member-online-portal.php?status=<?php echo $as === 'renewal_pending' ? 'renewal_pending' : 'pending'; ?>" class="btn btn-warning" title="अनुमोदन">
                                <i class="fas fa-check"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php
                    } catch (Throwable $rowEx) {
                        error_log('[admin/members row] ' . $rowEx->getMessage());
                        echo '<tr><td colspan="9" class="text-danger small">यो पङ्क्ति देखाउन सकिएन।</td></tr>';
                    }
                endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination — windowed so 2000+ members never paint 100+ page buttons -->
        <?php if ($totalPages > 1): ?>
        <div class="d-flex justify-content-between align-items-center p-3 border-top">
            <div class="text-muted small">जम्मा <?php echo $totalCount; ?> मध्ये <?php echo min($offset+$limit, $totalCount); ?> देखाइएको</div>
            <nav><ul class="pagination pagination-sm mb-0">
                <?php
                $pgWindow = 7;
                $pgStart = max(1, $page - (int)floor($pgWindow / 2));
                $pgEnd = min($totalPages, $pgStart + $pgWindow - 1);
                $pgStart = max(1, $pgEnd - $pgWindow + 1);
                $memPageQ = static function (int $pg) use ($search, $kycFilter, $memSub): string {
                    return htmlspecialchars('members.php?' . http_build_query(array_filter([
                        'page' => $pg > 1 ? $pg : null,
                        'search' => $search !== '' ? $search : null,
                        'kyc' => $kycFilter !== 'all' ? $kycFilter : null,
                        'mem_sub' => $memSub !== 'live' ? $memSub : null,
                    ], static fn ($v) => $v !== null && $v !== '')), ENT_QUOTES, 'UTF-8');
                };
                if ($page > 1): ?>
                <li class="page-item"><a class="page-link" href="<?php echo $memPageQ($page - 1); ?>">‹</a></li>
                <?php endif;
                if ($pgStart > 1): ?>
                <li class="page-item"><a class="page-link" href="<?php echo $memPageQ(1); ?>">1</a></li>
                <?php if ($pgStart > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif;
                endif;
                for ($pg = $pgStart; $pg <= $pgEnd; $pg++): ?>
                <li class="page-item <?php echo $pg === $page ? 'active' : ''; ?>">
                    <a class="page-link" href="<?php echo $memPageQ($pg); ?>"><?php echo $pg; ?></a>
                </li>
                <?php endfor;
                if ($pgEnd < $totalPages):
                    if ($pgEnd < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                <li class="page-item"><a class="page-link" href="<?php echo $memPageQ($totalPages); ?>"><?php echo $totalPages; ?></a></li>
                <?php endif;
                if ($page < $totalPages): ?>
                <li class="page-item"><a class="page-link" href="<?php echo $memPageQ($page + 1); ?>">›</a></li>
                <?php endif; ?>
            </ul></nav>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<!-- ── Bulk Notification Modal ── -->
<div class="modal fade" id="bulkNotifModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" class="modal-content border-0 shadow-lg">
            <?php echo csrfField(); ?>
            <input type="hidden" name="send_notif" value="1">
            <input type="hidden" name="notif_target" value="all">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-bullhorn me-2"></i>सबै सदस्यलाई Notification पठाउनुहोस्</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning small">
                    <i class="fas fa-triangle-exclamation me-1"></i>
                    यो सूचना तपाईंले छनोट गर्नुभएको audience का सबै सदस्यको Member Portal नोटिफिकेसन panel मा देखिनेछ। पठाइसकेपछि फिर्ता गर्न मिल्दैन।
                </div>

                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label fw-semibold"><i class="fas fa-heading me-1 text-success"></i>शीर्षक <span class="text-danger">*</span></label>
                        <input type="text" name="notif_title" class="form-control" required maxlength="200" placeholder="Notification शीर्षक (e.g. आजको कार्यक्रमको सूचना)">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold"><i class="fas fa-tag me-1 text-success"></i>प्रकार</label>
                        <select name="notif_type" class="form-select">
                            <option value="info">📘 सूचना (Info)</option>
                            <option value="success">✅ सफलता (Success)</option>
                            <option value="warning">⚠️ सतर्कता (Warning)</option>
                            <option value="error">❌ अस्वीकृति (Error)</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold"><i class="fas fa-comment me-1 text-success"></i>विस्तृत सन्देश</label>
                        <textarea name="notif_message" class="form-control" rows="4" maxlength="2000" placeholder="विस्तृत सन्देश यहाँ लेख्नुहोस्…"></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold"><i class="lucide-icon me-1 text-success" aria-hidden="true" data-lucide="users"></i>कसलाई पठाउने (Audience)</label>
                        <select name="notif_audience" class="form-select">
                            <option value="active" selected>✅ सक्रिय + अनुमोदित सदस्य मात्र (recommended)</option>
                            <option value="all_active">🌐 सबै सक्रिय (अनुमोदन-स्थिति नहेरी)</option>
                            <option value="kyc_linked">🔗 KYC-Linked सक्रिय सदस्य मात्र</option>
                            <option value="pending">⏳ Pending Approval मात्र</option>
                        </select>
                        <div class="form-text">"सक्रिय + अनुमोदित" चयन गरेको खण्डमा रद्द गरिएका वा निष्क्रिय सदस्यलाई पठाइने छैन।</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">रद्द</button>
                <button type="submit" class="btn btn-success" onclick="return confirm('के तपाईं पक्का सबै चयनित सदस्यलाई यो notification पठाउन चाहनुहुन्छ?')">
                    <i class="fas fa-paper-plane me-1"></i>सबैलाई पठाउनुहोस्
                </button>
            </div>
        </form>
    </div>
</div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.mem-service-badge[data-service-color]').forEach(function (el) {
        var c = (el.getAttribute('data-service-color') || '').trim();
        if (c) el.style.backgroundColor = c;
    });
});
</script>


<?php require_once 'includes/admin-footer.php'; ?>
