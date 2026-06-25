<?php
/**
 * Admin: ऋण आवेदन व्यवस्थापन — loan-applications.php
 * =====================================================
 * feedbacks.php pattern: ?view=ID → full-page detail + edit form।
 * Modal पूर्ण रूपले हटाइयो।
 */
$__t = static function (string $np, string $en): string {
    $lang = (string)($_SESSION['admin_lang'] ?? $_SESSION['lang'] ?? 'np');
    return strtolower($lang) === 'en' ? $en : $np;
};
$pageTitle   = $__t('ऋण आवेदन व्यवस्थापन', 'Loan Applications');
$currentPage = 'loans';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/includes/admin-request-view.php';
require_once __DIR__ . '/../includes/request-status-history.php';
require_once __DIR__ . '/../includes/auth-roles.php';
/* RBAC: staff hercha matra; mutate (approve/reject/delete) admin+ matra */
if ($_SERVER['REQUEST_METHOD'] === 'POST') { require_role('admin'); checkCSRF(); }

/* ── Auto-ALTER: admin_attachment column थप्ने — MySQL 5.7+ compatible ── */
safeAddColumn($db, 'loan_applications', 'admin_attachment', "VARCHAR(500) DEFAULT '' COMMENT 'Admin reply मा संलग्न file'");
safeAddColumn($db, 'loan_applications', 'updated_at', "TIMESTAMP NULL DEFAULT NULL");

$loanListStatuses = ['pending', 'processing', 'approved', 'rejected', 'disbursed'];
ensureRequestStatusHistoryTable($db);

/* ─── Status Update ─── */
if (isset($_POST['update_status'])) {
    $id      = (int)$_POST['id'];
    $status  = clean_text($_POST['status']);
    $remarks = clean_text($_POST['remarks'] ?? '');
    $newFile = adminUploadFile('admin_attachment');
    $oldStatus = '';
    $notifyOptIn = !empty($_POST['notify_member']) && $_POST['notify_member'] === '1';
    $notifyOutcome = [
        'admin_chose' => $notifyOptIn,
        'email' => ['status' => 'not_attempted', 'reason' => '', 'to' => ''],
        'sms'   => ['status' => 'not_attempted', 'reason' => '', 'to' => ''],
    ];
    try {
        $os = $db->prepare("SELECT status FROM loan_applications WHERE id=? LIMIT 1");
        $os->execute([$id]);
        $oldStatus = (string)($os->fetchColumn() ?: '');
    } catch (Exception $e) {}

    try {
        if ($newFile) {
            $stmt = $db->prepare("UPDATE loan_applications SET status=?, remarks=?, admin_attachment=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$status, $remarks, $newFile, $id]);
        } else {
            $stmt = $db->prepare("UPDATE loan_applications SET status=?, remarks=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$status, $remarks, $id]);
        }
        /* Member लाई status notification — email/SMS, channel-wise audit */
        try {
            $nRow = $db->prepare("SELECT full_name, email, phone, tracking_id FROM loan_applications WHERE id=?");
            $nRow->execute([$id]);
            $nData = $nRow->fetch();
            if ($nData) {
                $r = sendMemberStatusUpdate('loan',
                    $nData['email'] ?? '', $nData['phone'] ?? '', $nData['full_name'] ?? '',
                    $status, $remarks, $nData['tracking_id'] ?? '', !$notifyOptIn);
                if (is_array($r)) {
                    $notifyOutcome['email'] = $r['email'] ?? $notifyOutcome['email'];
                    $notifyOutcome['sms']   = $r['sms']   ?? $notifyOutcome['sms'];
                }
            }
        } catch (Exception $e) {}
        $notifySent = ($notifyOutcome['email']['status'] === 'sent') || ($notifyOutcome['sms']['status'] === 'sent');
        try {
            logRequestStatusHistory(
                $db,
                'loan',
                $id,
                $oldStatus !== '' ? $oldStatus : null,
                $status,
                (string)$remarks,
                $notifySent,
                (int)($_SESSION['admin_id'] ?? 0),
                (string)($_SESSION['admin_name'] ?? 'Admin'),
                $notifyOutcome
            );
        } catch (Exception $e) {}
        setFlash('success', $__t('स्थिति अपडेट गरियो।', 'Status updated.'));
    } catch (Exception $e) {
        setFlash('error', $__t('त्रुटि भयो।', 'An error occurred.'));
    }
    redirect('loan-applications.php' . ($id ? '?view=' . $id : ''));
}

/* ─── Delete ─── */
if (isset($_POST['delete'])) {
    $id = (int)($_POST['delete_id'] ?? 0);
    try { $db->prepare("DELETE FROM loan_applications WHERE id=?")->execute([$id]); setFlash('success', $__t('आवेदन मेटाइयो।', 'Application deleted.')); } catch (Exception $e) {}
    redirect('loan-applications.php');
}

/* ─── Quick Status (list view बाट सिधै approve/reject) ─── */
if (isset($_POST['quick_status'])) {
    $qid = (int)($_POST['quick_id'] ?? 0);
    $allowed = ['pending','processing','approved','rejected','disbursed'];
    $qst = in_array($_POST['quick_status_val'] ?? '', $allowed) ? $_POST['quick_status_val'] : 'pending';
    $oldStatus = '';
    $notifySent = false;
    try {
        $os = $db->prepare("SELECT status FROM loan_applications WHERE id=? LIMIT 1");
        $os->execute([$qid]);
        $oldStatus = (string)($os->fetchColumn() ?: '');
    } catch (Exception $e) {}
    try {
        $db->prepare("UPDATE loan_applications SET status=?, updated_at=NOW() WHERE id=?")->execute([$qst, $qid]);
        try {
            $nr = $db->prepare("SELECT full_name, email, mobile, tracking_id FROM loan_applications WHERE id=?");
            $nr->execute([$qid]); $nd = $nr->fetch();
            if ($nd) { sendMemberStatusUpdate('loan', $nd['email']??'', $nd['mobile']??'', $nd['full_name']??'', $qst, '', $nd['tracking_id']??''); $notifySent = true; }
        } catch (Exception $e) {}
        try {
            logRequestStatusHistory(
                $db,
                'loan',
                $qid,
                $oldStatus !== '' ? $oldStatus : null,
                $qst,
                '',
                $notifySent,
                (int)($_SESSION['admin_id'] ?? 0),
                (string)($_SESSION['admin_name'] ?? 'Admin')
            );
        } catch (Exception $e) {}
        setFlash('success', $__t('स्थिति परिवर्तन गरियो।', 'Status changed.'));
    } catch (Exception $e) { setFlash('error', $__t('त्रुटि भयो।', 'An error occurred.')); }
    $redLoanSt = $_GET['status'] ?? '';
    if ($redLoanSt !== '' && !in_array($redLoanSt, $loanListStatuses, true)) {
        $redLoanSt = '';
    }
    $qs = http_build_query([
        'status' => $redLoanSt,
        'search' => mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200, 'UTF-8'),
        'page'   => max(1, (int)($_GET['page'] ?? 1)),
    ]);
    redirect('loan-applications.php?' . $qs);
}

/* ─── Filters & Counts ─── */
$status_filter = $_GET['status'] ?? '';
if ($status_filter !== '' && !in_array($status_filter, $loanListStatuses, true)) {
    $status_filter = '';
}
$search  = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200, 'UTF-8');
$where   = "1=1"; $lparams = [];
if ($status_filter) { $where .= " AND status = ?"; $lparams[] = $status_filter; }
if ($search !== '') {
    $where .= " AND (full_name LIKE ? OR mobile LIKE ? OR email LIKE ? OR citizenship_no LIKE ? OR tracking_id LIKE ? OR loan_type LIKE ?)";
    $t = "%$search%"; $lparams = array_merge($lparams, [$t,$t,$t,$t,$t,$t]);
}

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 15;
$offset = ($page - 1) * $limit;

try {
    $lcnt = $db->prepare("SELECT COUNT(*) FROM loan_applications WHERE $where"); $lcnt->execute($lparams); $total = $lcnt->fetchColumn();
    $totalPages = ceil($total / $limit);
    $lstmt = $db->prepare("SELECT * FROM loan_applications WHERE $where ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $lstmt->execute(array_merge($lparams, [$limit, $offset])); $applications = $lstmt->fetchAll();
    $totalAmount = $db->query("SELECT SUM(loan_amount) FROM loan_applications WHERE status='pending'")->fetchColumn();
} catch (Exception $e) { $applications = []; $total = 0; $totalPages = 0; $totalAmount = 0; }

$pendingCount    = core_safe_count($db, "SELECT COUNT(*) FROM loan_applications WHERE status='pending'", '[loan-applications pending]');
$approvedCount   = core_safe_count($db, "SELECT COUNT(*) FROM loan_applications WHERE status='approved'", '[loan-applications approved]');
$rejectedCount   = core_safe_count($db, "SELECT COUNT(*) FROM loan_applications WHERE status='rejected'", '[loan-applications rejected]');
$processingCount = core_safe_count($db, "SELECT COUNT(*) FROM loan_applications WHERE status='processing'", '[loan-applications processing]');

/* ─── Single view ─── */
$viewApp = null;
if (isset($_GET['view'])) {
    $s = $db->prepare("SELECT * FROM loan_applications WHERE id=?");
    $s->execute([(int)$_GET['view']]);
    $viewApp = $s->fetch();
    if (!$viewApp) { setFlash('error', $__t('आवेदन फेला परेन।', 'Application not found.')); redirect('loan-applications.php'); }
}
$loanHistory = [];
if ($viewApp && !empty($viewApp['id'])) {
    try { $loanHistory = fetchRequestStatusHistory($db, 'loan', (int)$viewApp['id'], 40); } catch (Exception $e) { $loanHistory = []; }
}

$statusLabel = [
    'pending'    => $__t('पेन्डिङ', 'Pending'),
    'processing' => $__t('प्रक्रियामा', 'Processing'),
    'approved'   => $__t('स्वीकृत', 'Approved'),
    'rejected'   => $__t('अस्वीकृत', 'Rejected'),
    'disbursed'  => $__t('वितरित', 'Disbursed')
];
$statusClass = ['pending'=>'warning','processing'=>'info','approved'=>'success','rejected'=>'danger','disbursed'=>'primary'];

/* ─── Page Header ─── */
if ($viewApp) {
    $trackId = $viewApp['tracking_id'] ?? 'LOAN-' . str_pad($viewApp['id'], 6, '0', STR_PAD_LEFT);
    echo adminPageHeader($__t('ऋण आवेदन विवरण', 'Loan Application Details'), 'fa-hand-holding-usd',
        'Tracking: ' . $trackId,
        adminBackBtn('loan-applications.php', $__t('ऋण सूचीमा फर्किनुहोस्', 'Back to loan list')));
} else {
    echo adminPageHeader($__t('ऋण आवेदन व्यवस्थापन', 'Loan Applications'), 'fa-hand-holding-usd',
        $__t('सदस्यहरूको ऋण आवेदनहरू — समीक्षा, स्थिति अपडेट र Approval', 'Review, approve, and update status of member loan applications'),
        adminStatLink('?status=pending', 'danger', $__t('पेन्डिङ', 'Pending'), $pendingCount));
}

$flash = getFlash(); if ($flash) echo adminAlert($flash['type'] === 'success' ? 'success' : 'danger', $flash['message']);

/* ═══════════════════════════════════════════
   SINGLE DETAIL VIEW
   ═══════════════════════════════════════════ */
if ($viewApp):
    $sc = $statusClass[$viewApp['status']] ?? 'secondary';
    $sl = $statusLabel[$viewApp['status']] ?? $viewApp['status'];
    $trackId = $viewApp['tracking_id'] ?? 'LOAN-' . str_pad($viewApp['id'], 6, '0', STR_PAD_LEFT);
?>
<div class="card shadow-sm mb-4 arv-legacy-detail">
    <div class="card-header gradient-card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="fas fa-hand-holding-usd me-2"></i><?php echo $__t('ऋण आवेदन विवरण', 'Loan Application Details'); ?>
            <code class="apt-track-chip">
                <?php echo htmlspecialchars($trackId); ?>
            </code>
        </h5>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-<?php echo $sc; ?> fs-6"><?php echo $sl; ?></span>
            <a href="print-form.php?type=loan&id=<?php echo (int)$viewApp['id']; ?>" target="_blank"
               class="btn btn-light btn-sm"><i class="fas fa-print me-1"></i>Print Form</a>
        </div>
    </div>
    <div class="card-body">
        <div class="row g-4">

            <!-- ── LEFT: Application Details ── -->
            <div class="col-lg-7">

                <!-- आवेदकको जानकारी -->
                <div class="adm-info-group">
                <div class="adm-info-group-header"><i class="fas fa-user"></i><?php echo $__t('आवेदकको जानकारी', 'Applicant Information'); ?></div>
                <table class="table adm-detail-table">
                    <tr><th><?php echo $__t('पूरा नाम', 'Full Name'); ?></th><td><strong><?php echo htmlspecialchars($viewApp['full_name'] ?? '—'); ?></strong></td></tr>
                    <tr><th><?php echo $__t('सदस्य नं.', 'Member ID'); ?></th><td><?php echo htmlspecialchars($viewApp['member_id'] ?: '—'); ?></td></tr>
                    <tr><th><?php echo $__t('मोबाइल', 'Mobile'); ?></th><td><?php echo htmlspecialchars($viewApp['mobile'] ?? '—'); ?></td></tr>
                    <tr><th><?php echo $__t('इमेल', 'Email'); ?></th><td><?php echo htmlspecialchars($viewApp['email'] ?: '—'); ?></td></tr>
                    <tr><th><?php echo $__t('नागरिकता नं.', 'Citizenship No.'); ?></th><td><?php echo htmlspecialchars($viewApp['citizenship_no'] ?: '—'); ?></td></tr>
                    <tr><th><?php echo $__t('ठेगाना', 'Address'); ?></th><td><?php echo htmlspecialchars($viewApp['address'] ?: '—'); ?></td></tr>
                    <tr><th><?php echo $__t('दर्ता मिति', 'Created Date'); ?></th><td><?php echo formatNepaliDate($viewApp['created_at'], true); ?></td></tr>
                </table>

                </div>

                <!-- ऋण जानकारी -->
                <div class="adm-info-group">
                <div class="adm-info-group-header"><i class="fas fa-money-bill-wave"></i>ऋण जानकारी</div>
                <table class="table adm-detail-table">
                    <tr><th>ऋणको प्रकार</th><td><?php echo htmlspecialchars($viewApp['loan_type'] ?? '—'); ?></td></tr>
                    <tr><th>रकम</th>
                        <td><strong class="text-primary fs-5">रू. <?php echo number_format((float)($viewApp['loan_amount'] ?? 0)); ?></strong></td></tr>
                    <tr><th>अवधि</th><td><?php echo $viewApp['loan_tenure'] ? htmlspecialchars($viewApp['loan_tenure']) . ' महिना' : '—'; ?></td></tr>
                    <tr><th>भुक्तानी विधि</th><td><?php echo htmlspecialchars($viewApp['repayment_method'] ?: '—'); ?></td></tr>
                    <tr><th>उद्देश्य</th><td><?php echo htmlspecialchars($viewApp['loan_purpose'] ?: '—'); ?></td></tr>
                </table>

                </div>

                <!-- आय जानकारी -->
                <div class="adm-info-group">
                <div class="adm-info-group-header"><i class="fas fa-briefcase"></i>आय / पेशा जानकारी</div>
                <table class="table adm-detail-table">
                    <tr><th>पेशा</th><td><?php echo htmlspecialchars($viewApp['occupation'] ?: '—'); ?></td></tr>
                    <tr><th>संस्था/व्यवसाय</th><td><?php echo htmlspecialchars($viewApp['organization_name'] ?: '—'); ?></td></tr>
                    <tr><th>मासिक आय</th>
                        <td><?php echo !empty($viewApp['monthly_income']) ? 'रू. ' . number_format((float)$viewApp['monthly_income']) : '—'; ?></td></tr>
                </table>

                </div>

                <!-- धितो जानकारी -->
                <div class="adm-info-group">
                <div class="adm-info-group-header"><i class="fas fa-home"></i>धितो (Collateral) जानकारी</div>
                <table class="table adm-detail-table">
                    <tr><th>धितो प्रकार</th><td><?php echo htmlspecialchars($viewApp['collateral_type'] ?: '—'); ?></td></tr>
                    <tr><th>धितो मूल्य</th>
                        <td><?php echo !empty($viewApp['collateral_value']) ? 'रू. ' . number_format((float)$viewApp['collateral_value']) : '—'; ?></td></tr>
                    <tr><th>विवरण</th><td><?php echo htmlspecialchars($viewApp['collateral_description'] ?: '—'); ?></td></tr>
                </table>

                </div>

                <?php if (!empty($viewApp['guarantor_name'])): ?>
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-user-shield"></i>जमानी (Guarantor)</div>
                    <table class="table adm-detail-table">
                        <tr><th>नाम</th><td><strong><?php echo htmlspecialchars($viewApp['guarantor_name']); ?></strong></td></tr>
                        <tr><th>सम्बन्ध</th><td><?php echo htmlspecialchars($viewApp['guarantor_relation'] ?: '—'); ?></td></tr>
                        <tr><th>फोन</th><td><a href="tel:<?php echo htmlspecialchars($viewApp['guarantor_phone'] ?? ''); ?>" class="text-decoration-none"><?php echo htmlspecialchars($viewApp['guarantor_phone'] ?: '—'); ?></a></td></tr>
                        <tr><th>ठेगाना</th><td><?php echo htmlspecialchars($viewApp['guarantor_address'] ?: '—'); ?></td></tr>
                    </table>
                </div>
                <?php endif; ?>

                <?php if (!empty($viewApp['documents'])): ?>
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-file-alt"></i>पेश गरिएका कागजातहरू</div>
                    <div class="p-3 d-flex flex-wrap gap-2">
                        <?php foreach (explode(',', $viewApp['documents']) as $doc): $doc = trim($doc); if (!$doc) continue; ?>
                        <a href="../<?php echo htmlspecialchars($doc); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-file-pdf me-1"></i><?php echo htmlspecialchars(basename($doc)); ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($viewApp['admin_attachment'])): ?>
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-paperclip"></i>Admin संलग्न Document</div>
                    <div class="p-3 d-flex align-items-center gap-3">
                        <i class="fas fa-file-alt fa-2x text-primary opacity-75"></i>
                        <div class="flex-grow-1 fw-semibold small"><?php echo htmlspecialchars(basename($viewApp['admin_attachment'])); ?></div>
                        <a href="<?php echo htmlspecialchars(SITE_URL . ltrim($viewApp['admin_attachment'], '/')); ?>"
                           class="btn btn-sm btn-outline-primary" target="_blank" download>
                            <i class="fas fa-download me-1"></i>Download
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($viewApp['remarks'])): ?>
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-sticky-note"></i>Admin टिप्पणी (Member ले Tracker मा देख्छ)</div>
                    <div class="p-3 apt-text-block apt-text-block-success">
                        <?php echo nl2br(htmlspecialchars($viewApp['remarks'])); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($loanHistory)): ?>
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-clock-rotate-left"></i>Status / Comment History</div>
                    <div class="p-3">
                        <?php echo arvLogList($loanHistory); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── RIGHT: Status Update Form ── -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-header gradient-card-header py-2">
                        <i class="fas fa-edit me-2"></i><?php echo $__t('स्थिति अपडेट / Admin टिप्पणी / Document', 'Status Update / Admin Remarks / Document'); ?>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="update_status" value="1">
                            <input type="hidden" name="id" value="<?php echo $viewApp['id']; ?>">

                            <div class="mb-3">
                                <label class="form-label fw-semibold"><i class="fas fa-circle-dot me-1"></i><?php echo $__t('अवस्था', 'Status'); ?></label>
                                <select name="status" class="form-select">
                                    <?php foreach ($statusLabel as $v => $l): ?>
                                    <option value="<?php echo $v; ?>" <?php echo $viewApp['status']===$v?'selected':''; ?>><?php echo $l; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    <i class="fas fa-reply me-1 text-success"></i><?php echo $__t('Admin टिप्पणी/कैफियत', 'Admin Remarks'); ?>
                                </label>
                                <textarea name="remarks" class="form-control" rows="4"
                                    placeholder="<?php echo $__t('स्वीकृति/अस्वीकृतिको कारण, सर्तहरू...', 'Reason for approval/rejection, conditions...'); ?>"
                                ><?php echo htmlspecialchars($viewApp['remarks'] ?? ''); ?></textarea>
                            </div>

                            <?php $hasEmail = !empty($viewApp['email']); $hasPhone = !empty($viewApp['phone'] ?? $viewApp['mobile'] ?? ''); ?>
                            <div class="arv-notify-row mb-3">
                                <label class="arv-notify-toggle">
                                    <input type="checkbox" name="notify_member" value="1" <?php echo ($hasEmail || $hasPhone) ? 'checked' : ''; ?>>
                                    <span><i class="fas fa-paper-plane"></i> Member लाई SMS/Email पठाउनुहोस्</span>
                                </label>
                                <div class="arv-notify-channels">
                                    <span class="<?php echo $hasEmail ? 'is-on' : 'is-off'; ?>"><i class="fas fa-envelope"></i> Email <?php echo $hasEmail ? '✓' : '—'; ?></span>
                                    <span class="<?php echo $hasPhone ? 'is-on' : 'is-off'; ?>"><i class="fas fa-mobile-screen"></i> SMS <?php echo $hasPhone ? '✓' : '—'; ?></span>
                                </div>
                            </div>

                            <!-- Admin ले approval letter वा rejection notice attach गर्न सक्छ -->
                            <div class="mb-4">
                                <label class="form-label fw-semibold">
                                    <i class="fas fa-paperclip me-1 text-primary"></i><?php echo $__t('Document संलग्न गर्नुहोस्', 'Attach Document'); ?>
                                    <span class="text-muted fw-normal small">— PDF, Word, Image (max 5MB)</span>
                                </label>
                                <input type="file" name="admin_attachment" class="form-control"
                                       accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                <?php if (!empty($viewApp['admin_attachment'])): ?>
                                <div class="form-text text-primary mt-1">
                                    <i class="fas fa-info-circle me-1"></i><?php echo $__t('हाल', 'Current'); ?>: <strong><?php echo htmlspecialchars(basename($viewApp['admin_attachment'])); ?></strong>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary px-4">
                                    <i class="fas fa-save me-1"></i><?php echo $__t('अपडेट गर्नुहोस्', 'Update'); ?>
                                </button>
                                <a href="loan-applications.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-1"></i><?php echo $__t('सूचीमा', 'Back to list'); ?>
                                </a>
                            </div>
                        </form>

                        <hr class="my-3">
                        <form method="POST"
                              onsubmit="return confirm('<?php echo $__t('के तपाईं यो ऋण आवेदन स्थायी रूपले मेटाउन निश्चित हुनुहुन्छ?', 'Are you sure you want to permanently delete this loan application?'); ?>')">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="delete" value="1">
                            <input type="hidden" name="delete_id" value="<?php echo $viewApp['id']; ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                <i class="fas fa-trash me-1"></i><?php echo $__t('यो आवेदन मेटाउनुहोस्', 'Delete this application'); ?>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Summary box -->
                <div class="card mt-3 bg-light border-0">
                    <div class="card-body py-3 text-center">
                        <div class="fs-5 fw-bold text-primary">रू. <?php echo number_format((float)($viewApp['loan_amount'] ?? 0)); ?></div>
                        <div class="small text-muted"><?php echo $__t('माग गरिएको ऋण रकम', 'Requested Loan Amount'); ?></div>
                        <hr class="my-2">
                        <div class="small text-muted">
                            <i class="fas fa-calendar me-1"></i>
                            <?php echo $viewApp['loan_tenure'] ? htmlspecialchars($viewApp['loan_tenure']) . ' महिना' : '—'; ?>
                            &nbsp;|&nbsp;
                            <?php echo htmlspecialchars($viewApp['loan_type'] ?? ''); ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php else: /* ═══════════ LIST VIEW ═══════════ */ ?>

<!-- ── Stat Mini Row ── -->
<div class="stat-mini-row no-print">
    <a href="loan-applications.php" class="stat-mini <?php echo $status_filter===''?'active-filter':''; ?>">
        <div class="sm-icon ic-total"><i class="fas fa-file-alt"></i></div>
        <div class="sm-val"><?php echo $total; ?></div>
        <div class="sm-lbl"><?php echo $__t('जम्मा आवेदन', 'Total Applications'); ?></div>
    </a>
    <a href="?status=pending" class="stat-mini <?php echo $status_filter==='pending'?'active-filter':''; ?>">
        <div class="sm-icon ic-pending"><i class="fas fa-clock"></i></div>
        <div class="sm-val"><?php echo $pendingCount; ?></div>
        <div class="sm-lbl"><?php echo $__t('पेन्डिङ', 'Pending'); ?></div>
    </a>
    <a href="?status=processing" class="stat-mini <?php echo $status_filter==='processing'?'active-filter':''; ?>">
        <div class="sm-icon ic-process"><i class="fas fa-spinner"></i></div>
        <div class="sm-val"><?php echo $processingCount; ?></div>
        <div class="sm-lbl"><?php echo $__t('प्रक्रियामा', 'Processing'); ?></div>
    </a>
    <a href="?status=approved" class="stat-mini <?php echo $status_filter==='approved'?'active-filter':''; ?>">
        <div class="sm-icon ic-approved"><i class="fas fa-check-circle"></i></div>
        <div class="sm-val"><?php echo $approvedCount; ?></div>
        <div class="sm-lbl"><?php echo $__t('स्वीकृत', 'Approved'); ?></div>
    </a>
    <a href="?status=rejected" class="stat-mini <?php echo $status_filter==='rejected'?'active-filter':''; ?>">
        <div class="sm-icon ic-rejected"><i class="fas fa-times-circle"></i></div>
        <div class="sm-val"><?php echo $rejectedCount; ?></div>
        <div class="sm-lbl"><?php echo $__t('अस्वीकृत', 'Rejected'); ?></div>
    </a>
    <div class="stat-mini loan-stat-static">
        <div class="sm-icon ic-amount"><i class="fas fa-rupee-sign"></i></div>
        <div class="sm-val loan-stat-amount"><?php echo number_format((float)$totalAmount/100000,2); ?>L</div>
        <div class="sm-lbl"><?php echo $__t('पेन्डिङ रकम', 'Pending Amount'); ?></div>
    </div>
</div>

<!-- ── Filter Bar ── -->
<div class="adm-filter-bar no-print">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3 col-6">
            <label><?php echo $__t('स्थिति', 'Status'); ?></label>
            <select name="status" id="qf_status" class="form-select form-select-sm">
                <option value=""><?php echo $__t('सबै स्थिति', 'All Status'); ?></option>
                <option value="pending"    <?php echo $status_filter==='pending'?'selected':''; ?>>⏳ <?php echo $__t('पेन्डिङ', 'Pending'); ?></option>
                <option value="processing" <?php echo $status_filter==='processing'?'selected':''; ?>>🔄 <?php echo $__t('प्रक्रियामा', 'Processing'); ?></option>
                <option value="approved"   <?php echo $status_filter==='approved'?'selected':''; ?>>✅ <?php echo $__t('स्वीकृत', 'Approved'); ?></option>
                <option value="rejected"   <?php echo $status_filter==='rejected'?'selected':''; ?>>❌ <?php echo $__t('अस्वीकृत', 'Rejected'); ?></option>
                <option value="disbursed"  <?php echo $status_filter==='disbursed'?'selected':''; ?>>💰 <?php echo $__t('वितरित', 'Disbursed'); ?></option>
            </select>
        </div>
        <div class="col-md-6 col-12">
            <label><?php echo $__t('खोज्नुहोस्', 'Search'); ?></label>
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="<?php echo $__t('नाम, मोबाइल, Tracking ID, नागरिकता नं., ऋण प्रकार...', 'name, mobile, Tracking ID, citizenship no., loan type...'); ?>">
                <?php if ($search): ?>
                <a href="?status=<?php echo urlencode($status_filter); ?>" class="btn btn-outline-secondary btn-sm" title="<?php echo $__t('खोज हटाउनुहोस्', 'Clear search'); ?>"><i class="fas fa-times"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-search me-1"></i><?php echo $__t('खोज', 'Search'); ?></button>
        </div>
        <div class="col-md-1 col-6 text-end">
            <a href="?<?php echo http_build_query(['status'=>$status_filter,'search'=>$search,'export'=>'csv']); ?>" class="btn-csv no-print" title="CSV Export"><i class="fas fa-file-csv"></i> CSV</a>
        </div>
    </form>
    <script>document.getElementById('qf_status').addEventListener('change',function(){this.closest('form').submit();});</script>
</div>

<!-- ── Application Table ── -->
<div class="card border-0 shadow-sm app-rounded-card">
    <div class="tbl-header-bar no-print">
        <h6><i class="fas fa-hand-holding-usd me-2 text-success"></i><?php echo $__t('ऋण आवेदन सूची', 'Loan Application List'); ?></h6>
        <span class="result-count-badge"><?php echo $total; ?> <?php echo $__t('आवेदन', 'applications'); ?><?php echo $search ? ' — "'.htmlspecialchars($search).'" '.($__t('खोज', 'search')) : ''; ?></span>
    </div>
    <div class="table-responsive admin-table-card">
        <table class="table-hover table app-table align-middle mb-0 table-responsive-stack">
            <thead>
                <tr>
                    <th class="acc-col-applicant"><?php echo $__t('आवेदक', 'Applicant'); ?></th>
                    <th><?php echo $__t('सम्पर्क', 'Contact'); ?></th>
                    <th><?php echo $__t('ऋण विवरण', 'Loan Details'); ?></th>
                    <th><?php echo $__t('रकम', 'Amount'); ?></th>
                    <th>Tracking ID</th>
                    <th><?php echo $__t('दर्ता मिति', 'Created Date'); ?></th>
                    <th><?php echo $__t('स्थिति', 'Status'); ?></th>
                    <th class="no-print"><?php echo $__t('कार्यहरू', 'Actions'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($applications)): ?>
            <tr class="no-results-row"><td colspan="8"><i class="fas fa-inbox fa-2x d-block mb-2"></i><?php echo $__t('कुनै आवेदन फेला परेन।', 'No applications found.'); ?></td></tr>
            <?php else: foreach ($applications as $app):
                $trackId = $app['tracking_id'] ?: 'LNP-' . str_pad($app['id'], 6, '0', STR_PAD_LEFT);
                $initLetter = mb_strtoupper(mb_substr($app['full_name'] ?? 'A', 0, 1));
            ?>
            <tr data-status="<?php echo htmlspecialchars($app['status']); ?>">
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="av-letter"><?php echo $initLetter; ?></div>
                        <div>
                            <div class="cell-main"><?php echo htmlspecialchars($app['full_name']); ?></div>
                            <?php if ($app['member_id']): ?>
                            <div class="cell-sub"><?php echo $__t('सदस्य', 'Member'); ?>: <?php echo htmlspecialchars($app['member_id']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="cell-main"><i class="fas fa-phone fa-xs text-muted me-1"></i><?php echo htmlspecialchars($app['mobile']); ?></div>
                    <?php if ($app['email']): ?><div class="cell-sub"><?php echo htmlspecialchars($app['email']); ?></div><?php endif; ?>
                </td>
                <td>
                    <div class="cell-main"><?php echo htmlspecialchars($app['loan_type']); ?></div>
                    <div class="cell-sub"><?php echo $app['loan_tenure'] ? htmlspecialchars($app['loan_tenure']).' '.$__t('महिना', 'months') : ''; ?><?php echo $app['collateral_type'] ? ' · '.htmlspecialchars($app['collateral_type']) : ''; ?></div>
                </td>
                <td class="amount-cell">रू.<?php echo number_format((float)$app['loan_amount']); ?></td>
                <td><span class="track-badge"><?php echo htmlspecialchars($trackId); ?></span></td>
                <td><div class="cell-sub"><?php echo formatNepaliDate($app['created_at']); ?></div></td>
                <td>
                    <span class="badge-status badge-<?php echo htmlspecialchars($app['status']); ?>">
                        <?php echo $statusLabel[$app['status']] ?? $app['status']; ?>
                    </span>
                </td>
                <td class="no-print">
                    <div class="adm-action-icons">
                        <a href="loan-applications.php?view=<?php echo $app['id']; ?>" class="adm-icon-btn adm-icon-btn--view" title="<?php echo $__t('विस्तृत हेर्नुहोस्', 'View details'); ?>" aria-label="View">
                            <i class="fas fa-eye"></i>
                        </a>
                        <?php if ($app['status'] === 'pending' || $app['status'] === 'processing'): ?>
                        <form method="POST" class="qaction-form" onsubmit="return confirm('<?php echo $__t('यो आवेदन स्वीकृत गर्नुहुन्छ?', 'Approve this application?'); ?>')">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="quick_status" value="1">
                            <input type="hidden" name="quick_id" value="<?php echo $app['id']; ?>">
                            <input type="hidden" name="quick_status_val" value="approved">
                            <button type="submit" class="btn-qapprove"><i class="fas fa-check me-1"></i><?php echo $__t('स्वीकृत', 'Approve'); ?></button>
                        </form>
                        <form method="POST" class="qaction-form" onsubmit="return confirm('<?php echo $__t('यो आवेदन अस्वीकृत गर्नुहुन्छ?', 'Reject this application?'); ?>')">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="quick_status" value="1">
                            <input type="hidden" name="quick_id" value="<?php echo $app['id']; ?>">
                            <input type="hidden" name="quick_status_val" value="rejected">
                            <button type="submit" class="btn-qreject"><i class="fas fa-times me-1"></i><?php echo $__t('अस्वीकृत', 'Reject'); ?></button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="p-3 border-top no-print">
        <div class="adm-pagination">
            <?php
            $qs = ['status'=>$status_filter,'search'=>$search];
            $prevPage = $page > 1 ? $page - 1 : null;
            $nextPage = $page < $totalPages ? $page + 1 : null;
            ?>
            <a href="?<?php echo http_build_query(array_merge($qs,['page'=>1])); ?>" class="<?php echo $page==1?'disabled':''; ?>" title="<?php echo $__t('पहिलो', 'First'); ?>"><i class="fas fa-angle-double-left"></i></a>
            <a href="<?php echo $prevPage ? '?'.http_build_query(array_merge($qs,['page'=>$prevPage])) : '#'; ?>" class="<?php echo !$prevPage?'disabled':''; ?>"><i class="fas fa-angle-left"></i></a>
            <?php
            $start = max(1, $page - 2); $end = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
            <?php if ($i == $page): ?>
            <span class="active"><?php echo $i; ?></span>
            <?php else: ?>
            <a href="?<?php echo http_build_query(array_merge($qs,['page'=>$i])); ?>"><?php echo $i; ?></a>
            <?php endif; endfor; ?>
            <a href="<?php echo $nextPage ? '?'.http_build_query(array_merge($qs,['page'=>$nextPage])) : '#'; ?>" class="<?php echo !$nextPage?'disabled':''; ?>"><i class="fas fa-angle-right"></i></a>
            <a href="?<?php echo http_build_query(array_merge($qs,['page'=>$totalPages])); ?>" class="<?php echo $page==$totalPages?'disabled':''; ?>" title="<?php echo $__t('अन्तिम', 'Last'); ?>"><i class="fas fa-angle-double-right"></i></a>
            <span class="acc-page-meta"><?php echo $page; ?>/<?php echo $totalPages; ?> <?php echo $__t('पेज', 'pages'); ?> · <?php echo $total; ?> <?php echo $__t('रेकर्ड', 'records'); ?></span>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php require_once 'includes/admin-footer.php'; ?>
