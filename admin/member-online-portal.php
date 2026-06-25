<?php
/**
 * Admin: Member Online Portal — दर्ता अनुमोदन, ID Card, पासवर्ड Reset
 */
$__t = static function (string $np, string $en): string {
    $lang = (string)($_SESSION['admin_lang'] ?? $_SESSION['lang'] ?? 'np');
    return strtolower($lang) === 'en' ? $en : $np;
};
$pageTitle   = $__t('सदस्य अनलाइन पोर्टल', 'Member Online Portal');
$currentPage = 'member-online-portal';
require_once 'includes/admin-header.php';
require_once '../includes/member-auth.php';
require_once __DIR__ . '/../includes/card-verify-helpers.php';

if (function_exists('ensureCardSecurityColumns')) {
    try { ensureCardSecurityColumns($db); } catch (Throwable $e) {}
}

ensureMemberTables();
$hasMemberIdCards = false;
try {
    $hasMemberIdCards = (bool)$db->query("SHOW TABLES LIKE 'member_id_cards'")->fetchColumn();
    if (!$hasMemberIdCards) {
        // Lightweight self-heal for production: avoid full ensure-tables overhead.
        $db->exec("CREATE TABLE IF NOT EXISTS member_id_cards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id VARCHAR(50) NOT NULL,
            card_no VARCHAR(40) NOT NULL UNIQUE,
            verification_code VARCHAR(20) NULL UNIQUE,
            cvv CHAR(4) NULL,
            issued_date DATE NOT NULL,
            expiry_date DATE NULL,
            status ENUM('active','expired','revoked') DEFAULT 'active',
            verify_count INT DEFAULT 0,
            last_verified_at DATETIME NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_card_member (member_id),
            INDEX idx_card_status (status),
            INDEX idx_card_verify (verification_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $hasMemberIdCards = true;
    }
} catch (Throwable $e) {
    $hasMemberIdCards = false;
}

/* ── POST Actions ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $action   = $_POST['action']    ?? '';
    $memberId = (int)($_POST['member_id'] ?? 0);
    $adminId  = $_SESSION['admin_id'] ?? null;

    /* Approve member */
    if ($action === 'approve' && $memberId) {
        adminApproveMember($memberId, $adminId);
        setFlash('success', 'सदस्य दर्ता स्वीकृत भयो र notification पठाइयो!');
        redirect('member-online-portal.php?status=pending');
    }

    /* Reject member */
    if ($action === 'reject' && $memberId) {
        $reason = clean_text($_POST['rejection_reason'] ?? '');
        adminRejectMember($memberId, $reason, $adminId);
        setFlash('error', 'सदस्य दर्ता अस्वीकृत भयो।');
        redirect('member-online-portal.php?status=pending');
    }

    /* Toggle active/inactive */
    if ($action === 'toggle_active' && $memberId) {
        $db->prepare("UPDATE members SET is_active = 1 - is_active WHERE id=?")->execute([$memberId]);
        setFlash('success', 'Member अवस्था बदलियो।');
        redirect('member-online-portal.php');
    }

    /* Generate ID card */
    if ($action === 'generate_id_card' && $memberId) {
        $ok = adminGenerateMemberIdCard($memberId, $adminId);
        if ($ok) {
            setFlash('success', '🪪 डिजिटल ID Card Generate भयो र member लाई notification पठाइयो!');
        } else {
            setFlash('error', 'ID Card Generate गर्न सकिएन। Member record वा database schema mismatch हुनसक्छ। यो build मा self-fix logic थपिएको छ—update upload गरेपछि फेरि प्रयास गर्नुहोस्।');
        }
        redirect('member-online-portal.php?view=' . $memberId);
    }

    /* Unlock locked card (admin action) */
    if ($action === 'unlock_card' && $memberId) {
        if (!$hasMemberIdCards) {
            setFlash('error', 'member_id_cards तालिका उपलब्ध छैन। पहिले schema verify/migration चलाउनुहोस्।');
            redirect('member-online-portal.php?view=' . $memberId);
        }
        try {
            $u = $db->prepare("UPDATE member_id_cards
                                  SET status='active',
                                      failed_verify_count=0,
                                      unlock_requested=0,
                                      unlock_requested_at=NULL
                                WHERE (member_id = :id OR member_id = :sid)
                                ORDER BY id DESC LIMIT 1");
            $u->execute([
                ':id' => (string)$memberId,
                ':sid' => (string)($_POST['member_sadasyata'] ?? ''),
            ]);
            setFlash('success', 'कार्ड Unlock गरी active बनाइयो।');
        } catch (Throwable $e) {
            setFlash('error', 'कार्ड Unlock गर्न सकिएन।');
        }
        redirect('member-online-portal.php?view=' . $memberId);
    }

    /* Password reset approve */
    if ($action === 'approve_reset') {
        $requestId   = (int)($_POST['request_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';
        if ($requestId && strlen($newPassword) >= 6) {
            $ok = adminApprovePasswordReset($requestId, $adminId, $newPassword);
            if ($ok) {
                setFlash('success', 'पासवर्ड Reset स्वीकृत भयो र member लाई notification पठाइयो!');
            } else {
                setFlash('error', 'Reset स्वीकृत गर्न सकिएन।');
            }
        } else {
            setFlash('error', 'नयाँ पासवर्ड कम्तीमा ६ अक्षर हुनुपर्छ।');
        }
        redirect('member-online-portal.php?tab=resets');
    }

    /* Password reset reject */
    if ($action === 'reject_reset') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        if ($requestId) {
            $db->prepare("UPDATE member_password_reset_requests SET status='rejected', admin_id=?, resolved_at=NOW() WHERE id=?")
               ->execute([$adminId, $requestId]);
            /* v2: prepared statement (SQL injection safe) */
            $stmt = $db->prepare("SELECT member_id FROM member_password_reset_requests WHERE id = ?");
            $stmt->execute([$requestId]);
            $memberIdForNotice = (int)$stmt->fetchColumn();
            createMemberNotification(
                $memberIdForNotice,
                '❌ पासवर्ड Reset अस्वीकृत भयो',
                'तपाईंको पासवर्ड Reset अनुरोध Admin ले अस्वीकृत गर्नुभयो। थप जानकारीका लागि कार्यालयमा सम्पर्क गर्नुहोस्।',
                'error', SITE_URL . 'member/login.php'
            );
            setFlash('error', 'पासवर्ड Reset अस्वीकृत गरियो।');
        }
        redirect('member-online-portal.php?tab=resets');
    }
}

/* ── Active tab ── */
$activeTab = $_GET['tab'] ?? 'members';
if (!in_array($activeTab, ['members', 'resets'], true)) {
    $activeTab = 'members';
}

/* ── View single member ── */
$viewId     = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$viewMember = null;
$viewCard   = null; /* CVV / Verification code */
$viewPartnerHistory = [];
if ($viewId) {
    try {
        $st = $db->prepare("SELECT m.*,
                               k.full_name AS kyc_full_name,
                               k.email AS kyc_email,
                               k.mobile AS kyc_mobile,
                               k.photo AS kyc_photo
                          FROM members m
                          LEFT JOIN kyc_applications k ON k.id = m.kyc_application_id
                         WHERE m.id=?");
        $st->execute([$viewId]);
        $viewMember = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[member-online-portal view-member] ' . $e->getMessage());
        $viewMember = null;
    }

    if ($viewMember && $hasMemberIdCards) {
        try {
            $cs = $db->prepare(
                "SELECT card_no, verification_code, cvv, issued_date, status, failed_verify_count, unlock_requested, unlock_requested_at
                   FROM member_id_cards
                  WHERE (member_id = :id OR member_id = :sid OR member_id = :mid)
                  ORDER BY id DESC LIMIT 1"
            );
            $cs->execute([
                ':id'  => (string)($viewMember['id'] ?? ''),
                ':sid' => (string)($viewMember['sadasyata_number'] ?? ''),
                ':mid' => (string)($viewMember['member_id'] ?? ''),
            ]);
            $viewCard = $cs->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { /* legacy DB */ }
    }
    if ($viewMember) {
        try {
            $ps = $db->prepare("SELECT partner_name, service_name, service_taken, service_note, created_at
                                FROM member_partner_services
                                WHERE member_id = ?
                                ORDER BY created_at DESC
                                LIMIT 20");
            $ps->execute([(int)$viewMember['id']]);
            $viewPartnerHistory = $ps->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { $viewPartnerHistory = []; }
    }
}

/* ── Stats ── */
$stats = [];
try {
    $stats['total']    = core_safe_count($db, "SELECT COUNT(*) FROM members", '[member-online total]');
    $stats['pending']  = core_safe_count($db, "SELECT COUNT(*) FROM members WHERE approval_status='pending'", '[member-online pending]');
    $stats['approved'] = core_safe_count($db, "SELECT COUNT(*) FROM members WHERE approval_status='approved'", '[member-online approved]');
    $stats['rejected'] = core_safe_count($db, "SELECT COUNT(*) FROM members WHERE approval_status='rejected'", '[member-online rejected]');
    $stats['inactive'] = core_safe_count($db, "SELECT COUNT(*) FROM members WHERE is_active=0", '[member-online inactive]');
} catch (\Throwable $e) {
    $stats = ['total'=>0,'pending'=>0,'approved'=>0,'rejected'=>0,'inactive'=>0];
}

/* ── Pending password resets ── */
$pendingResets = [];
try {
    $pendingResets = $db->query(
        "SELECT r.*, m.name as member_name, m.email, m.phone, m.sadasyata_number
         FROM member_password_reset_requests r
         JOIN members m ON m.id = r.member_id
         WHERE r.status='pending'
         ORDER BY r.requested_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

/* Program attendance rating dataset */
$activeProgramTotal = 0;
$memberProgramCounts = [];
$activeProgramTotal = core_safe_count($db, "SELECT COUNT(*) FROM upcoming_programs WHERE is_active=1", '[member-online active-programs]');
try {
    $ag = $db->query("SELECT a.member_id, COUNT(DISTINCT a.program_id) AS attended
                      FROM member_program_attendance a
                      INNER JOIN upcoming_programs p ON p.id = a.program_id
                      WHERE p.is_active=1
                      GROUP BY a.member_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ag as $r) $memberProgramCounts[(int)$r['member_id']] = (int)$r['attended'];
} catch (\Throwable $e) {}

/* ── Member list filters ── */
$filterStatus = $_GET['status'] ?? '';
$search       = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200, 'UTF-8');
$page         = max(1, (int)($_GET['page'] ?? 1));
$limit        = 20;
$offset       = ($page - 1) * $limit;

$where = '1=1'; $params = [];
if ($filterStatus && in_array($filterStatus, ['pending','approved','rejected'])) {
    $where .= " AND approval_status=?"; $params[] = $filterStatus;
}
if ($search) {
    $where .= " AND (name LIKE ? OR email LIKE ? OR phone LIKE ? OR sadasyata_number LIKE ? OR member_card_no LIKE ?)";
    $t = "%$search%"; $params = array_merge($params, [$t,$t,$t,$t,$t]);
}

$totalCount = 0;
$totalPages = 1;

$cardSelectSql = "NULL AS card_no_db, NULL AS card_cvv";
$cardJoinSql = "";
if ($hasMemberIdCards) {
    $cardSelectSql = "c.card_no AS card_no_db, c.cvv AS card_cvv";
    $memberIdColumnExists = false;
    try {
        $memberIdColumnExists = function_exists('safeColumnExists') ? safeColumnExists('members', 'member_id') : false;
    } catch (Throwable $e) {
        $memberIdColumnExists = false;
    }
    $memberIdCondition = $memberIdColumnExists
        ? " OR member_id COLLATE utf8mb4_unicode_ci = m.member_id COLLATE utf8mb4_unicode_ci"
        : "";
    $cardJoinSql = "LEFT JOIN member_id_cards c
         ON c.id = (
              SELECT id FROM member_id_cards
               WHERE (
                    member_id COLLATE utf8mb4_unicode_ci = CAST(m.id AS CHAR) COLLATE utf8mb4_unicode_ci
                    OR member_id COLLATE utf8mb4_unicode_ci = m.sadasyata_number COLLATE utf8mb4_unicode_ci
                    {$memberIdCondition}
               )
               ORDER BY id DESC LIMIT 1
          )";
}
$members = [];
try {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM members WHERE $where");
    $countStmt->execute($params);
    $totalCount = (int)$countStmt->fetchColumn();
    $totalPages = max(1, ceil($totalCount / $limit));

    $memberStmt = $db->prepare(
        "SELECT m.*,
                COALESCE(NULLIF(k.full_name,''), m.name) AS display_name,
                COALESCE(NULLIF(k.mobile,''), m.phone) AS display_phone,
                COALESCE(NULLIF(k.email,''), m.email) AS display_email,
                COALESCE(NULLIF(k.photo,''), NULLIF(m.avatar_url,'')) AS display_avatar,
                {$cardSelectSql}
           FROM members m
          LEFT JOIN kyc_applications k ON k.id = m.kyc_application_id
          {$cardJoinSql}
          WHERE $where
          ORDER BY m.created_at DESC
          LIMIT $limit OFFSET $offset"
    );
    $memberStmt->execute($params);
    $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[member-online-portal list-query] ' . $e->getMessage());
    $totalCount = 0;
    $totalPages = 1;
    $members = [];
    setFlash('error', 'Member portal data load गर्दा schema mismatch/DB समस्या भेटियो। Database Setup/Migration एकपटक run गर्नुहोस्।');
}

/* Approval status badge helper */
function approvalBadge($status) {
    $map = [
        'pending'  => ['bg-warning text-dark', '⏳ अनुमोदन प्रतीक्षामा'],
        'approved' => ['bg-success',            '✅ स्वीकृत'],
        'rejected' => ['bg-danger',             '❌ अस्वीकृत'],
    ];
    [$cls, $lbl] = $map[$status] ?? ['bg-secondary', ucfirst($status)];
    return "<span class='badge $cls portal-badge-xs'>$lbl</span>";
}
function memberProgramStars(int $attended, int $eligible): string {
    $star = 1;
    if ($eligible > 0) {
        $ratio = $attended / $eligible;
        if ($ratio >= 0.90) $star = 5;
        elseif ($ratio >= 0.70) $star = 4;
        elseif ($ratio >= 0.50) $star = 3;
        elseif ($ratio >= 0.30) $star = 2;
        else $star = 1;
    }
    return str_repeat('★', $star) . str_repeat('☆', 5 - $star);
}
?>

<div class="container-fluid py-3">

<?php if ($flash = getFlash()): ?>
<div class="alert alert-<?php echo $flash['type']==='success'?'success':'danger'; ?> alert-dismissible fade show mb-3"><i class="fas fa-<?php echo $flash['type']==='success'?'check-circle':'exclamation-circle'; ?> me-2"></i><?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php displayFlash(); ?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0 fw-bold text-success">
        <i class="fas fa-globe me-2"></i><?php echo $__t('सदस्य अनलाइन पोर्टल', 'Member Online Portal'); ?>
    </h4>
    <?php if ($stats['pending'] > 0): ?>
    <span class="badge bg-danger portal-pending-pulse-badge">
        <?php echo $stats['pending']; ?> <?php echo $__t('अनुमोदन प्रतीक्षामा', 'awaiting approval'); ?>
    </span>
    <?php endif; ?>
</div>

<!-- Stats Row -->
<?php
    $statCards = [
        ['icon'=>'fa-users',        'label'=>$__t('कुल दर्ता','Total Registrations'), 'value'=>$stats['total'],    'color'=>'primary', 'link'=>'member-online-portal.php'],
        ['icon'=>'fa-clock',        'label'=>$__t('प्रतीक्षामा','Pending'),           'value'=>$stats['pending'],  'color'=>'warning', 'link'=>'?status=pending'],
        ['icon'=>'fa-circle-check', 'label'=>$__t('स्वीकृत','Approved'),              'value'=>$stats['approved'], 'color'=>'success', 'link'=>'?status=approved'],
        ['icon'=>'fa-circle-xmark', 'label'=>$__t('अस्वीकृत','Rejected'),             'value'=>$stats['rejected'], 'color'=>'danger',  'link'=>'?status=rejected'],
        ['icon'=>'fa-ban',          'label'=>$__t('निष्क्रिय','Inactive'),             'value'=>$stats['inactive'], 'color'=>'secondary'],
    ];
    $statColClass = 'col-6 col-sm-4 col-md-2';
    include __DIR__ . '/../includes/components/stat-card.php';
?>

<?php if ($viewMember): /* ── Single Member View ── */ ?>
<?php
$vmName  = trim((string)($viewMember['kyc_full_name'] ?? '')) !== '' ? trim((string)$viewMember['kyc_full_name']) : (string)($viewMember['name'] ?? '');
$vmEmail = trim((string)($viewMember['kyc_email'] ?? '')) !== '' ? trim((string)$viewMember['kyc_email']) : (string)($viewMember['email'] ?? '');
$vmPhone = trim((string)($viewMember['kyc_mobile'] ?? '')) !== '' ? trim((string)$viewMember['kyc_mobile']) : (string)($viewMember['phone'] ?? '');
$vmPhoto = trim((string)($viewMember['kyc_photo'] ?? '')); // photo source = KYC
if ($vmPhoto === '') $vmPhoto = trim((string)($viewMember['avatar_url'] ?? ''));
$vmPhotoSrc = $vmPhoto;
if ($vmPhotoSrc !== '' && strpos($vmPhotoSrc, 'http') !== 0) {
    $vmPhotoSrc = SITE_URL . ltrim($vmPhotoSrc, '/');
}
?>

<div class="d-flex align-items-center gap-2 mb-3">
    <a href="member-online-portal.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i><?php echo $__t('फिर्ता', 'Back'); ?></a>
    <h5 class="mb-0"><?php echo $__t('सदस्य विवरण', 'Member Details'); ?>: <?php echo htmlspecialchars($vmName); ?></h5>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white py-2 border-0">
        <div class="admin-inner-tabstrip-tray">
        <div class="d-flex flex-wrap gap-2 w-100" role="group" aria-label="Member detail tabs">
            <button type="button" class="btn btn-sm btn-success active" id="btnTabMember">
                <i class="fas fa-id-card me-1"></i><?php echo $__t('सदस्य जानकारी', 'Member Information'); ?>
            </button>
            <button type="button" class="btn btn-sm btn-outline-success" id="btnTabNotif">
                <i class="fas fa-bell me-1"></i><?php echo $__t('सूचना', 'Notification'); ?>
            </button>
            <button type="button" class="btn btn-sm btn-outline-success" id="btnTabPartner">
                <i class="fas fa-handshake me-1"></i><?php echo $__t('साझेदार संस्था', 'Partner Services'); ?>
            </button>
        </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-12" id="portalPanelMemberCol">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-4">
                <?php if ($vmPhoto): ?>
                <img src="<?php echo htmlspecialchars($vmPhotoSrc); ?>" class="rounded-circle mb-3 portal-avatar-lg">
                <?php else: ?>
                <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center mx-auto mb-3 portal-avatar-fallback-lg"><?php echo mb_substr($vmName,0,1); ?></div>
                <?php endif; ?>
                <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($vmName); ?></h5>
                <div class="mb-2"><?php echo approvalBadge($viewMember['approval_status'] ?? 'pending'); ?></div>
                <span class="badge <?php echo $viewMember['is_active'] ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $viewMember['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?></span>
            </div>
            <ul class="list-group list-group-flush small portal-member-meta-list">
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted fw-bold">सदस्यता नं</span><span><?php echo htmlspecialchars($viewMember['sadasyata_number'] ?? '—'); ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted fw-bold">Card No</span><code><?php echo htmlspecialchars($viewCard['card_no'] ?? $viewMember['member_card_no'] ?? '—'); ?></code></li>
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted fw-bold">इमेल</span><span><?php echo htmlspecialchars($vmEmail ?: '—'); ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted fw-bold">मोबाइल</span><span><?php echo htmlspecialchars($vmPhone ?: '—'); ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted fw-bold">दर्ता मिति</span><span><?php echo formatNepaliDate($viewMember['created_at']); ?></span></li>
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted fw-bold">Program Rating</span>
                    <span class="portal-rating-strong">
                        <?php $vmAtt=(int)($memberProgramCounts[(int)$viewMember['id']] ?? 0); echo memberProgramStars($vmAtt, (int)$activeProgramTotal); ?>
                        (<?php echo $vmAtt; ?>/<?php echo max(1,(int)$activeProgramTotal); ?>)
                    </span>
                </li>
                <?php if ($viewMember['approved_at']): ?>
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted fw-bold"><?php echo $__t('स्वीकृत मिति','Approved Date'); ?></span><span><?php echo formatNepaliDate($viewMember['approved_at']); ?></span></li>
                <?php endif; ?>
                <?php if (!empty($viewMember['card_expires_at'])): ?>
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted fw-bold">Expired मिति</span><span><?php echo formatNepaliDate($viewMember['card_expires_at']); ?></span></li>
                <?php endif; ?>
                <?php if ($viewMember['rejection_reason']): ?>
                <li class="list-group-item"><span class="text-danger fw-bold small"><?php echo $__t('अस्वीकृतिको कारण','Rejection Reason'); ?>:</span><div class="small"><?php echo htmlspecialchars($viewMember['rejection_reason']); ?></div></li>
                <?php endif; ?>
            </ul>
            <?php if ($viewCard && (!empty($viewCard['cvv']) || !empty($viewCard['verification_code']))): ?>
            <div class="card-body border-top portal-card-security-wrap">
                <div class="fw-bold small text-warning-emphasis mb-2">
                    <i class="fas fa-shield-halved"></i> ID Card विवरण (Admin)
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted fw-bold small">Card No.</span>
                    <code class="small"><?php echo htmlspecialchars($viewCard['card_no'] ?? '—'); ?></code>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted fw-bold small">Verification Code</span>
                    <code class="small text-success fw-bold"><?php echo htmlspecialchars($viewCard['verification_code'] ?? '—'); ?></code>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted fw-bold small">CVV</span>
                    <code class="small text-danger fw-bold portal-cvv-code"><?php echo htmlspecialchars($viewCard['cvv'] ?? '—'); ?></code>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-1">
                    <span class="text-muted fw-bold small">Security Fail Count</span>
                    <code class="small"><?php echo (int)($viewCard['failed_verify_count'] ?? 0); ?></code>
                </div>
                <?php if (($viewCard['status'] ?? '') === 'locked'): ?>
                <div class="alert alert-danger py-2 mt-2 mb-0 small">
                    <i class="fas fa-lock me-1"></i> यो कार्ड LOCK छ।
                    <?php if (!empty($viewCard['unlock_requested'])): ?>
                        <span class="fw-bold ms-1">Member ले unlock request पठाएको छ।</span>
                    <?php endif; ?>
                </div>
                <form method="POST" class="mt-2">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="unlock_card">
                    <input type="hidden" name="member_id" value="<?php echo (int)$viewMember['id']; ?>">
                    <input type="hidden" name="member_sadasyata" value="<?php echo htmlspecialchars($viewMember['sadasyata_number'] ?? ''); ?>">
                    <button type="submit" class="btn btn-sm btn-danger w-100" onclick="return confirm('<?php echo $__t('यो कार्ड unlock गरी active गर्ने?', 'Unlock and activate this card?'); ?>')">
                        <i class="fas fa-unlock me-1"></i><?php echo $__t('कार्ड अनलक / सक्रिय गर्नुहोस्','Card Unlock / Activate'); ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <!-- Actions -->
            <div class="card-body d-grid gap-2">
                <?php $as = $viewMember['approval_status'] ?? 'pending'; ?>
                <?php if ($as === 'pending' || $as === 'rejected'): ?>
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="member_id" value="<?php echo $viewMember['id']; ?>">
                    <button class="btn btn-success btn-sm w-100" onclick="return confirm('<?php echo $__t('स्वीकृत गर्ने?', 'Approve this member?'); ?>')">
                        <i class="fas fa-check me-1"></i><?php echo $__t('स्वीकृत गर्नुहोस्','Approve'); ?>
                    </button>
                </form>
                <?php endif; ?>
                <?php if ($as !== 'rejected'): ?>
                <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal">
                    <i class="fas fa-times me-1"></i><?php echo $__t('अस्वीकृत गर्नुहोस्','Reject'); ?>
                </button>
                <?php endif; ?>
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="member_id" value="<?php echo $viewMember['id']; ?>">
                    <button class="btn btn-outline-<?php echo $viewMember['is_active'] ? 'warning' : 'success'; ?> btn-sm w-100"
                            onclick="return confirm('<?php echo $__t('अवस्था बदल्ने?', 'Change status?'); ?>')">
                        <i class="fas fa-<?php echo $viewMember['is_active'] ? 'ban' : 'check'; ?> me-1"></i>
                        <?php echo $viewMember['is_active'] ? $__t('निष्क्रिय गर्नुहोस्','Deactivate') : $__t('सक्रिय गर्नुहोस्','Activate'); ?>
                    </button>
                </form>
                <?php if (($as === 'approved') && !$viewMember['id_card_generated']): ?>
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="generate_id_card">
                    <input type="hidden" name="member_id" value="<?php echo $viewMember['id']; ?>">
                    <button class="btn btn-outline-primary btn-sm w-100" onclick="return confirm('<?php echo $__t('🪪 ID Card Generate गर्ने?', 'Generate ID card?'); ?>')">
                        <i class="fas fa-id-card me-1"></i><?php echo $__t('ID कार्ड Generate गर्नुहोस्','Generate ID Card'); ?>
                    </button>
                </form>
                <?php elseif ($viewMember['id_card_generated']): ?>
                <div class="alert alert-success py-2 mb-0 small text-center">
                    <i class="fas fa-check-circle me-1"></i>ID Card Generate भइसकेको छ (<?php echo formatNepaliDate($viewMember['id_card_generated_at']); ?>)
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-8 d-none" id="portalPanelRightCol">
        <div class="card border-0 shadow-sm" id="panelNotif">
            <div class="card-header bg-white fw-bold">
                <i class="fas fa-bell me-2 text-success"></i>Notification पठाउनुहोस्
            </div>
            <div class="card-body">
                <form method="POST" action="members.php">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="send_notif" value="1">
                    <input type="hidden" name="member_id" value="<?php echo $viewMember['id']; ?>">
                    <div class="row g-2">
                        <div class="col-md-8"><input type="text" name="notif_title" class="form-control" placeholder="Notification शीर्षक" required maxlength="200"></div>
                        <div class="col-md-4">
                            <select name="notif_type" class="form-select">
                                <option value="info">📘 सूचना</option>
                                <option value="success">✅ सफलता</option>
                                <option value="warning">⚠️ सतर्कता</option>
                                <option value="error">❌ अस्वीकृति</option>
                            </select>
                        </div>
                        <div class="col-12"><textarea name="notif_message" class="form-control" rows="2" placeholder="सन्देश (ऐच्छिक)"></textarea></div>
                        <div class="col-12"><button class="btn btn-success btn-sm"><i class="fas fa-paper-plane me-1"></i>पठाउनुहोस्</button></div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm mt-3 d-none" id="panelPartner">
            <div class="card-header bg-white fw-bold">
                <i class="fas fa-handshake me-2 text-info"></i>साझेदार संस्था सेवा इतिहास
            </div>
            <div class="card-body p-0">
                <?php if (empty($viewPartnerHistory)): ?>
                <div class="text-center py-4 text-muted small">यो सदस्यको service history छैन।</div>
                <?php else: ?>
                <?php
                    $vpPartners = [];
                    foreach ($viewPartnerHistory as $phx) {
                        $pn = trim((string)($phx['partner_name'] ?? ''));
                        if ($pn !== '') $vpPartners[$pn] = true;
                    }
                    $vpPartners = array_keys($vpPartners);
                    sort($vpPartners, SORT_NATURAL | SORT_FLAG_CASE);
                ?>
                <div class="p-3 border-bottom portal-partner-filter-wrap">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <label class="small text-muted fw-bold mb-0">संस्था अनुसार फिल्टर</label>
                        <span class="badge text-bg-light border" id="adminPartnerCountBadge">कुल <?php echo count($viewPartnerHistory); ?> रेकर्ड</span>
                    </div>
                    <select id="adminPartnerFilter" class="form-select form-select-sm portal-partner-filter-select">
                        <option value="">— संस्था छान्नुहोस् (छानेपछि मात्र सूची देखिन्छ) —</option>
                        <?php foreach ($vpPartners as $pn): ?>
                        <option value="<?php echo htmlspecialchars($pn); ?>"><?php echo htmlspecialchars($pn); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="adminPartnerHint" class="small text-muted mt-2">संस्था चयन गरेपछि history table देखिन्छ।</div>
                </div>
                <div class="table-responsive portal-partner-table-wrap">
                    <table class="table table-sm table-hover align-middle mb-0 portal-partner-table d-none" id="adminPartnerHistoryTable">
                        <thead class="table-light">
                            <tr><th>संस्था</th><th>लिएको सेवा</th><th>सेवा अवस्था</th><th>विवरण</th><th>मिति</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($viewPartnerHistory as $ph): ?>
                            <tr class="admin-partner-row" data-partner="<?php echo htmlspecialchars(trim((string)($ph['partner_name'] ?? ''))); ?>">
                                <td class="small fw-bold"><?php echo htmlspecialchars($ph['partner_name'] ?? '—'); ?></td>
                                <td class="small">
                                    <span class="portal-service-chip"><?php echo htmlspecialchars($ph['service_name'] ?? '—'); ?></span>
                                </td>
                                <td class="small">
                                    <span class="badge <?php echo !empty($ph['service_taken']) ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo !empty($ph['service_taken']) ? 'लिएको' : 'नलिएको'; ?>
                                    </span>
                                </td>
                                <td class="small"><?php echo htmlspecialchars($ph['service_note'] ?? '—'); ?></td>
                                <td class="small text-muted"><?php echo formatNepaliDate($ph['created_at'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var btnM = document.getElementById('btnTabMember');
    var btnN = document.getElementById('btnTabNotif');
    var btnP = document.getElementById('btnTabPartner');
    var colM = document.getElementById('portalPanelMemberCol');
    var colR = document.getElementById('portalPanelRightCol');
    var pnlN = document.getElementById('panelNotif');
    var pnlP = document.getElementById('panelPartner');
    if (!btnM || !btnN || !btnP || !colM || !colR || !pnlN || !pnlP) return;
    function setBtn(btn, active){
        btn.classList.toggle('active', active);
        btn.classList.toggle('btn-success', active);
        btn.classList.toggle('btn-outline-success', !active);
    }
    function show(which){
        var member = which === 'member';
        var notif  = which === 'notif';
        var partner = which === 'partner';

        colM.classList.toggle('d-none', !member);
        colR.classList.toggle('d-none', member);
        colM.classList.toggle('col-md-12', member);
        colM.classList.toggle('col-md-4', !member);
        colM.classList.toggle('col-lg-8', member);
        colM.classList.toggle('mx-auto', member);
        colR.classList.toggle('col-md-8', member);
        colR.classList.toggle('col-md-12', !member);

        pnlN.classList.toggle('d-none', !notif);
        pnlP.classList.toggle('d-none', !partner);

        setBtn(btnM, member);
        setBtn(btnN, notif);
        setBtn(btnP, partner);
    }
    btnM.addEventListener('click', function(){ show('member'); });
    btnN.addEventListener('click', function(){ show('notif'); });
    btnP.addEventListener('click', function(){ show('partner'); });
    show('member');
})();

(function(){
    var sel = document.getElementById('adminPartnerFilter');
    var table = document.getElementById('adminPartnerHistoryTable');
    var hint = document.getElementById('adminPartnerHint');
    var countBadge = document.getElementById('adminPartnerCountBadge');
    if (!sel || !table) return;
    function applyAdminPartnerFilter() {
        var v = (sel.value || '').trim();
        var rows = table.querySelectorAll('.admin-partner-row');
        var shown = 0;
        rows.forEach(function(r){
            var ok = v !== '' && r.getAttribute('data-partner') === v;
            r.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        table.classList.toggle('d-none', !(v !== '' && shown > 0));
        if (countBadge) {
            countBadge.textContent = v === ''
                ? ('कुल ' + rows.length + ' रेकर्ड')
                : ('देखिएको ' + shown + ' रेकर्ड');
        }
        if (!hint) return;
        hint.textContent = v === '' ? 'संस्था चयन गरेपछि history table देखिन्छ।'
            : (shown > 0 ? '' : 'छानिएको संस्थामा history छैन।');
        hint.style.display = hint.textContent === '' ? 'none' : '';
    }
    sel.addEventListener('change', applyAdminPartnerFilter);
    applyAdminPartnerFilter();
})();

(function(){
    document.querySelectorAll('.portal-stat-card[data-bg]').forEach(function(card){
        var bg = (card.getAttribute('data-bg') || '').trim();
        if (bg) card.style.background = bg;
    });
    document.querySelectorAll('.portal-stat-color[data-color]').forEach(function(el){
        var c = (el.getAttribute('data-color') || '').trim();
        if (c) el.style.color = c;
    });
})();
</script>


<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="fas fa-times-circle me-2"></i><?php echo $__t('दर्ता अस्वीकृत गर्नुहोस्','Reject Registration'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="member_id" value="<?php echo $viewMember['id']; ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?php echo $__t('अस्वीकृतिको कारण','Rejection Reason'); ?></label>
                        <textarea name="rejection_reason" class="form-control" rows="3" placeholder="<?php echo $__t('उदा: KYC रेकर्ड मेल खाएन, सदस्यता नम्बर गलत छ...', 'e.g., KYC record mismatch, incorrect membership number...'); ?>"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php echo $__t('रद्द','Cancel'); ?></button>
                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('<?php echo $__t('पक्कै अस्वीकृत गर्ने?', 'Are you sure to reject?'); ?>')">
                        <i class="fas fa-times me-1"></i><?php echo $__t('अस्वीकृत गर्नुहोस्','Reject'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php else: /* ── Member List ── */ ?>

<!-- Tab Nav -->
<ul class="nav nav-tabs admin-nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab==='members'?'active':''; ?>" href="?tab=members">
            <i class="fas fa-users me-1"></i>Members
            <?php if ($stats['pending']): ?><span class="badge bg-warning text-dark ms-1"><?php echo $stats['pending']; ?></span><?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab==='resets'?'active':''; ?>" href="?tab=resets">
            <i class="fas fa-key me-1"></i>पासवर्ड Reset अनुरोधहरू
            <?php if (count($pendingResets)): ?><span class="badge bg-danger ms-1"><?php echo count($pendingResets); ?></span><?php endif; ?>
        </a>
    </li>
</ul>

<?php if ($activeTab === 'resets'): ?>

<!-- Password Reset Requests -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-bold"><i class="fas fa-key me-2 text-warning"></i><?php echo $__t('पासवर्ड Reset अनुरोधहरू','Password Reset Requests'); ?></div>
    <div class="card-body p-0">
        <?php if (empty($pendingResets)): ?>
        <div class="text-center py-5 text-muted"><i class="fas fa-check-circle fa-2x mb-2 d-block text-success opacity-50"></i><?php echo $__t('कुनै pending reset अनुरोध छैन।', 'No pending reset requests.'); ?></div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light"><tr><th><?php echo $__t('सदस्य','Member'); ?></th><th><?php echo $__t('सदस्यता नं','Membership No.'); ?></th><th><?php echo $__t('सम्पर्क','Contact'); ?></th><th><?php echo $__t('अनुरोध मिति','Request Date'); ?></th><th><?php echo $__t('कार्य','Action'); ?></th></tr></thead>
                <tbody>
                <?php foreach ($pendingResets as $rr): ?>
                <tr>
                    <td class="fw-bold small"><?php echo htmlspecialchars($rr['member_name']); ?></td>
                    <td class="small"><?php echo htmlspecialchars($rr['sadasyata_number'] ?? '—'); ?></td>
                    <td class="small"><?php echo htmlspecialchars($rr['phone'] ?? $rr['email'] ?? '—'); ?></td>
                    <td class="small text-muted"><?php echo formatNepaliDate($rr['requested_at']); ?></td>
                    <td>
                        <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#resetModal<?php echo $rr['id']; ?>">
                            <i class="fas fa-check me-1"></i>स्वीकृत
                        </button>
                        <form method="POST" class="d-inline">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="reject_reset">
                            <input type="hidden" name="request_id" value="<?php echo $rr['id']; ?>">
                            <button class="btn btn-outline-danger btn-sm" onclick="return confirm('<?php echo $__t('Reset अस्वीकृत गर्ने?', 'Reject reset request?'); ?>')">
                                <i class="fas fa-times"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <!-- Reset Approve Modal -->
                <div class="modal fade" id="resetModal<?php echo $rr['id']; ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header"><h5 class="modal-title"><?php echo $__t('पासवर्ड Reset', 'Password Reset'); ?>: <?php echo htmlspecialchars($rr['member_name']); ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                            <form method="POST">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="approve_reset">
                                <input type="hidden" name="request_id" value="<?php echo $rr['id']; ?>">
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold"><?php echo $__t('नयाँ अस्थायी पासवर्ड', 'New Temporary Password'); ?> <span class="text-danger">*</span></label>
                                        <input type="text" name="new_password" class="form-control" required minlength="6" placeholder="<?php echo $__t('कम्तीमा ६ अक्षर', 'Minimum 6 characters'); ?>">
                                        <div class="form-text text-muted"><?php echo $__t('Member लाई यो पासवर्ड दिनुहोस् र login पछि बदल्न भन्नुहोस्।', 'Share this password with member and ask to change after login.'); ?></div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php echo $__t('रद्द','Cancel'); ?></button>
                                    <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check me-1"></i><?php echo $__t('Reset स्वीकृत गर्नुहोस्','Approve Reset'); ?></button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php else: /* Members tab */ ?>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form class="d-flex gap-2 flex-wrap align-items-center" method="GET">
            <input type="hidden" name="tab" value="members">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="नाम / इमेल / फोन / सदस्यता नं खोज्नुहोस्…"
                   value="<?php echo htmlspecialchars($search); ?>" class="portal-search-input">
            <select name="status" class="form-select form-select-sm portal-status-select">
                <option value="">सबै अवस्था</option>
                <option value="pending"  <?php echo $filterStatus==='pending'  ? 'selected' : ''; ?>>⏳ प्रतीक्षामा</option>
                <option value="approved" <?php echo $filterStatus==='approved' ? 'selected' : ''; ?>>✅ स्वीकृत</option>
                <option value="rejected" <?php echo $filterStatus==='rejected' ? 'selected' : ''; ?>>❌ अस्वीकृत</option>
            </select>
            <button class="btn btn-success btn-sm"><i class="fas fa-search me-1"></i><?php echo $__t('खोज्नुहोस्','Search'); ?></button>
            <?php if ($search || $filterStatus): ?><a href="?tab=members" class="btn btn-outline-secondary btn-sm">Clear</a><?php endif; ?>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($members)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-user-slash fa-2x mb-2 d-block opacity-25"></i>
            <?php echo $search || $filterStatus ? 'कुनै member फेला परेन।' : 'अहिलेसम्म कुनै Member दर्ता भएको छैन।'; ?>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Member</th>
                        <th>सदस्यता नं</th>
                        <th>Contact</th>
                        <th>स्वीकृत मिति</th>
                        <th>Expired मिति</th>
                        <th>दर्ता मिति</th>
                        <th>दर्ता अवस्था</th>
                        <th>Program ⭐</th>
                        <th>ID Card</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($members as $i => $m): ?>
                <?php
                    $mDisplayName  = $m['display_name'] ?? $m['name'];
                    $mDisplayPhone = $m['display_phone'] ?? $m['phone'];
                    $mDisplayEmail = $m['display_email'] ?? $m['email'];
                    $mDisplayAvatar = $m['display_avatar'] ?? $m['avatar_url'];
                ?>
                <tr class="<?php echo ($m['approval_status'] ?? '') === 'pending' ? 'table-warning' : ''; ?>">
                    <td class="text-muted small"><?php echo $offset + $i + 1; ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <?php if ($mDisplayAvatar): ?>
                            <img src="<?php echo htmlspecialchars($mDisplayAvatar); ?>" class="rounded-circle portal-avatar-sm">
                            <?php else: ?>
                            <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center fw-bold portal-avatar-fallback-sm"><?php echo mb_substr((string)$mDisplayName,0,1); ?></div>
                            <?php endif; ?>
                            <div>
                                <div class="fw-bold small"><?php echo htmlspecialchars($mDisplayName); ?></div>
                                <span class="badge <?php echo $m['is_active'] ? 'bg-success' : 'bg-secondary'; ?> portal-badge-xxs"><?php echo $m['is_active'] ? 'Active' : 'Inactive'; ?></span>
                            </div>
                        </div>
                    </td>
                    <td class="small"><code><?php echo htmlspecialchars($m['sadasyata_number'] ?? '—'); ?></code></td>
                    <td class="small">
                        <div><?php echo htmlspecialchars($mDisplayPhone ?? '—'); ?></div>
                        <div class="text-muted portal-email-xs"><?php echo htmlspecialchars($mDisplayEmail ?? ''); ?></div>
                    </td>
                    <td class="small text-muted"><?php echo !empty($m['approved_at']) ? formatNepaliDate($m['approved_at']) : '—'; ?></td>
                    <td class="small text-muted"><?php echo !empty($m['card_expires_at']) ? formatNepaliDate($m['card_expires_at']) : '—'; ?></td>
                    <td class="small text-muted"><?php echo formatNepaliDate($m['created_at']); ?></td>
                    <td><?php echo approvalBadge($m['approval_status'] ?? 'pending'); ?></td>
                    <td class="small portal-rating-strong">
                        <?php $mAtt=(int)($memberProgramCounts[(int)$m['id']] ?? 0); echo memberProgramStars($mAtt, (int)$activeProgramTotal); ?>
                        <div class="text-muted portal-rating-sub"><?php echo $mAtt; ?>/<?php echo max(1,(int)$activeProgramTotal); ?></div>
                    </td>
                    <td class="small">
                        <?php if ($m['id_card_generated']): ?>
                        <span class="badge bg-success portal-badge-mini"><i class="fas fa-check me-1"></i>Generate भयो</span>
                        <?php else: ?>
                        <span class="badge bg-light text-muted border portal-badge-mini">Generate नभएको</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="?view=<?php echo $m['id']; ?>" class="btn btn-sm btn-outline-success" title="विवरण हेर्नुहोस्"><i class="fas fa-eye"></i></a>
                        <?php if (($m['approval_status'] ?? 'pending') === 'pending'): ?>
                        <form method="POST" class="d-inline">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="member_id" value="<?php echo $m['id']; ?>">
                            <button class="btn btn-sm btn-success" title="स्वीकृत गर्नुहोस्" onclick="return confirm('स्वीकृत गर्ने?')"><i class="fas fa-check"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="d-flex justify-content-between align-items-center p-3 border-top">
            <div class="text-muted small">जम्मा <?php echo $totalCount; ?> मध्ये <?php echo min($offset+$limit, $totalCount); ?> देखाइएको</div>
            <nav><ul class="pagination pagination-sm mb-0">
                <?php for ($pg = 1; $pg <= $totalPages; $pg++): ?>
                <li class="page-item <?php echo $pg===$page?'active':''; ?>">
                    <a class="page-link" href="?tab=members&page=<?php echo $pg; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($filterStatus); ?>"><?php echo $pg; ?></a>
                </li>
                <?php endfor; ?>
            </ul></nav>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php endif; /* end !viewMember */ ?>
</div>

<?php require_once 'includes/admin-footer.php'; ?>
