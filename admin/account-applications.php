<?php
/**
 * Admin: खाता आवेदन व्यवस्थापन — account-applications.php
 * ============================================================
 * feedbacks.php pattern: ?view=ID → full-page detail + edit form।
 * Modal पूर्ण रूपले हटाइयो।
 */
$__t = static function (string $np, string $en): string {
    $lang = (string)($_SESSION['admin_lang'] ?? $_SESSION['lang'] ?? 'np');
    return strtolower($lang) === 'en' ? $en : $np;
};
$pageTitle   = $__t('खाता आवेदन व्यवस्थापन', 'Account Applications');
$currentPage = 'account-apps';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/includes/admin-request-view.php';
require_once __DIR__ . '/../includes/request-status-history.php';
require_once __DIR__ . '/../includes/auth-roles.php';
/* RBAC: staff hercha matra; mutate (approve/reject/delete) admin+ matra */
if ($_SERVER['REQUEST_METHOD'] === 'POST') { require_role('admin'); checkCSRF(); }

/* ── Auto-ALTER — MySQL 5.7+ compatible ── */
safeAddColumn($db, 'account_applications', 'admin_attachment', "VARCHAR(500) DEFAULT '' COMMENT 'Admin reply मा संलग्न file'");
safeAddColumn($db, 'account_applications', 'updated_at', "TIMESTAMP NULL DEFAULT NULL");

$accountListStatuses = ['pending', 'approved', 'rejected'];
ensureRequestStatusHistoryTable($db);

/* ─── Status Update ─── */
if (isset($_POST['update_status'])) {
    $id      = intval($_POST['id']);
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
        $os = $db->prepare("SELECT status FROM account_applications WHERE id=? LIMIT 1");
        $os->execute([$id]);
        $oldStatus = (string)($os->fetchColumn() ?: '');
    } catch (Exception $e) {}

    try {
        if ($newFile) {
            $stmt = $db->prepare("UPDATE account_applications SET status=?, remarks=?, admin_attachment=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$status, $remarks, $newFile, $id]);
        } else {
            $stmt = $db->prepare("UPDATE account_applications SET status=?, remarks=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$status, $remarks, $id]);
        }
        /* Member लाई status notification — email/SMS, channel-wise audit */
        try {
            $nRow = $db->prepare("SELECT full_name, email, mobile, tracking_id FROM account_applications WHERE id=?");
            $nRow->execute([$id]);
            $nData = $nRow->fetch();
            if ($nData) {
                $r = sendMemberStatusUpdate('account',
                    $nData['email'] ?? '', $nData['mobile'] ?? '', $nData['full_name'] ?? '',
                    $status, $remarks, $nData['tracking_id'] ?? '', !$notifyOptIn);
                if (is_array($r)) {
                    $notifyOutcome['email'] = $r['email'] ?? $notifyOutcome['email'];
                    $notifyOutcome['sms']   = $r['sms']   ?? $notifyOutcome['sms'];
                }
            }
        } catch (Throwable $e) { error_log("[account-applications.php] " . $e->getMessage()); }
        $notifySent = ($notifyOutcome['email']['status'] === 'sent') || ($notifyOutcome['sms']['status'] === 'sent');
        try {
            logRequestStatusHistory(
                $db,
                'account',
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
        setFlash('success', $__t('स्थिति अपडेट भयो।', 'Status updated.'));
    } catch (Exception $e) {
        setFlash('error', $__t('त्रुटि भयो।', 'An error occurred.'));
    }
    redirect('account-applications.php' . ($id ? '?view=' . $id : ''));
}

/* ─── Delete ─── */
if (isset($_POST['delete'])) {
    $id = (int)($_POST['delete_id'] ?? 0);
    try { $db->prepare("DELETE FROM account_applications WHERE id=?")->execute([$id]); setFlash('success', $__t('आवेदन मेटाइयो।', 'Application deleted.')); } catch (Throwable $e) { error_log("[account-applications.php] " . $e->getMessage()); }
    redirect('account-applications.php');
}

/* ─── Quick Status ─── */
if (isset($_POST['quick_status'])) {
    $qid = (int)($_POST['quick_id'] ?? 0);
    $allowed = ['pending','approved','rejected'];
    $qst = in_array($_POST['quick_status_val'] ?? '', $allowed) ? $_POST['quick_status_val'] : 'pending';
    $oldStatus = '';
    $notifySent = false;
    try {
        $os = $db->prepare("SELECT status FROM account_applications WHERE id=? LIMIT 1");
        $os->execute([$qid]);
        $oldStatus = (string)($os->fetchColumn() ?: '');
    } catch (Exception $e) {}
    try {
        $db->prepare("UPDATE account_applications SET status=?, updated_at=NOW() WHERE id=?")->execute([$qst, $qid]);
        try {
            $nr = $db->prepare("SELECT full_name, email, mobile, tracking_id FROM account_applications WHERE id=?");
            $nr->execute([$qid]); $nd = $nr->fetch();
            if ($nd) { sendMemberStatusUpdate('account', $nd['email']??'', $nd['mobile']??'', $nd['full_name']??'', $qst, '', $nd['tracking_id']??''); $notifySent = true; }
        } catch (Throwable $e) { error_log("[account-applications.php] " . $e->getMessage()); }
        try {
            logRequestStatusHistory(
                $db,
                'account',
                $qid,
                $oldStatus !== '' ? $oldStatus : null,
                $qst,
                '',
                $notifySent,
                (int)($_SESSION['admin_id'] ?? 0),
                (string)($_SESSION['admin_name'] ?? 'Admin')
            );
        } catch (Exception $e) {}
        setFlash('success', $__t('खाता आवेदन स्थिति परिवर्तन गरियो।', 'Account application status changed.'));
    } catch (Exception $e) { setFlash('error', $__t('त्रुटि भयो।', 'An error occurred.')); }
    $redAccSt = $_GET['status'] ?? '';
    if ($redAccSt !== '' && !in_array($redAccSt, $accountListStatuses, true)) {
        $redAccSt = '';
    }
    $qs = http_build_query([
        'status' => $redAccSt,
        'search' => mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200, 'UTF-8'),
        'page'   => max(1, (int)($_GET['page'] ?? 1)),
    ]);
    redirect('account-applications.php?' . $qs);
}

/* ─── Filter / Search / Pagination ─── */
$status_filter = $_GET['status'] ?? '';
if ($status_filter !== '' && !in_array($status_filter, $accountListStatuses, true)) {
    $status_filter = '';
}
$search        = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200, 'UTF-8');
$page          = max(1, (int)($_GET['page'] ?? 1));
$limit         = 15; $offset = ($page-1)*$limit;
$where = '1=1'; $params = [];
if ($status_filter) { $where .= ' AND status = ?'; $params[] = $status_filter; }
if ($search !== '') {
    $where .= ' AND (full_name LIKE ? OR full_name_en LIKE ? OR mobile LIKE ? OR email LIKE ? OR tracking_id LIKE ? OR citizenship_no LIKE ?)';
    $t = "%$search%"; $params = array_merge($params, [$t,$t,$t,$t,$t,$t]);
}
try {
    $cntS = $db->prepare("SELECT COUNT(*) FROM account_applications WHERE $where"); $cntS->execute($params); $totalCount = (int)$cntS->fetchColumn();
    $totalPages = max(1, ceil($totalCount / $limit));
    $stmt = $db->prepare("SELECT * FROM account_applications WHERE $where ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute(array_merge($params, [$limit, $offset])); $applications = $stmt->fetchAll();
} catch (Exception $e) { $applications = []; $totalCount = 0; $totalPages = 1; }

/* ─── Single view ─── */
$viewApp = null;
if (isset($_GET['view'])) {
    $s = $db->prepare("SELECT * FROM account_applications WHERE id=?");
    $s->execute([(int)$_GET['view']]);
    $viewApp = $s->fetch();
    if (!$viewApp) { setFlash('error', $__t('आवेदन फेला परेन।', 'Application not found.')); redirect('account-applications.php'); }
}
$accountHistory = [];
if ($viewApp && !empty($viewApp['id'])) {
    try { $accountHistory = fetchRequestStatusHistory($db, 'account', (int)$viewApp['id'], 40); } catch (Exception $e) { $accountHistory = []; }
}

$statusClass = ['pending'=>'warning','approved'=>'success','rejected'=>'danger'];
$statusLabel = [
    'pending'  => $__t('पेन्डिङ', 'Pending'),
    'approved' => $__t('स्वीकृत', 'Approved'),
    'rejected' => $__t('अस्वीकृत', 'Rejected')
];
$accTypes    = [
    'saving'    => $__t('बचत', 'Saving'),
    'current'   => $__t('चल्ती', 'Current'),
    'fixed'     => $__t('मुद्दती', 'Fixed'),
    'recurring' => $__t('आवधिक', 'Recurring'),
    'child'     => $__t('बाल बचत', 'Child Savings')
];

/* ─── Counts ─── */
try {
    $pendingCount  = $db->query("SELECT COUNT(*) FROM account_applications WHERE status='pending'")->fetchColumn();
    $approvedCount = $db->query("SELECT COUNT(*) FROM account_applications WHERE status='approved'")->fetchColumn();
    $rejectedCount = $db->query("SELECT COUNT(*) FROM account_applications WHERE status='rejected'")->fetchColumn();
} catch (Exception $e) { $pendingCount=$approvedCount=$rejectedCount=0; }

/* ─── Page Header ─── */
if ($viewApp) {
    $trackId = $viewApp['tracking_id'] ?? 'ACC-' . str_pad($viewApp['id'], 6, '0', STR_PAD_LEFT);
    echo adminPageHeader($__t('खाता आवेदन विवरण', 'Account Application Details'), 'fa-user-plus',
        'Tracking: ' . $trackId,
        adminBackBtn('account-applications.php', $__t('खाता आवेदन सूचीमा', 'Back to account application list')));
} else {
    echo adminPageHeader($__t('खाता आवेदन व्यवस्थापन', 'Account Applications'), 'fa-user-plus',
        $__t('सदस्यहरूको नयाँ खाता आवेदनहरू — समीक्षा र स्थिति अपडेट', 'Review and update status of new account applications'),
        adminStatLink('?status=pending', 'danger', $__t('पेन्डिङ', 'Pending'), $pendingCount));
}

$flash = getFlash(); if ($flash) echo adminAlert($flash['type'] === 'success' ? 'success' : 'danger', $flash['message']);

/* ═══════════════════════════════════
   SINGLE DETAIL VIEW
   ═══════════════════════════════════ */
if ($viewApp):
    $sc = $statusClass[$viewApp['status']] ?? 'secondary';
    $sl = $statusLabel[$viewApp['status']] ?? $viewApp['status'];
    $trackId = $viewApp['tracking_id'] ?? 'ACC-' . str_pad($viewApp['id'], 6, '0', STR_PAD_LEFT);
    $accType = $accTypes[$viewApp['account_type']] ?? $viewApp['account_type'];
?>
<div class="card shadow-sm mb-4 arv-legacy-detail">
    <div class="card-header gradient-card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="fas fa-user-plus me-2"></i><?php echo $__t('खाता आवेदन विवरण', 'Account Application Details'); ?>
            <code class="apt-track-chip">
                <?php echo htmlspecialchars($trackId); ?>
            </code>
        </h5>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-<?php echo $sc; ?> fs-6"><?php echo $sl; ?></span>
            <a href="print-form.php?type=account&id=<?php echo (int)$viewApp['id']; ?>" target="_blank"
               class="btn btn-light btn-sm"><i class="fas fa-print me-1"></i>Print Form</a>
        </div>
    </div>

    <div class="card-body">
        <div class="row g-4">

            <!-- ── LEFT: आवेदकको विवरण ── -->
            <div class="col-lg-7">

                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-user"></i><?php echo $__t('व्यक्तिगत जानकारी', 'Personal Information'); ?></div>
                    <table class="table adm-detail-table">
                        <tr><th><?php echo $__t('पूरा नाम (नेपाली)', 'Full Name (Nepali)'); ?></th>
                            <td><strong><?php echo htmlspecialchars($viewApp['full_name'] ?? '—'); ?></strong></td></tr>
                        <tr><th>Full Name (EN)</th>
                            <td><?php echo htmlspecialchars($viewApp['full_name_en'] ?: '—'); ?></td></tr>
                        <tr><th><?php echo $__t('जन्म मिति', 'Date of Birth'); ?></th>
                            <td><?php echo htmlspecialchars($viewApp['dob_bs'] ?: '—'); ?></td></tr>
                        <tr><th><?php echo $__t('लिङ्ग', 'Gender'); ?></th>
                            <td><?php echo htmlspecialchars($viewApp['gender'] ?? '—'); ?></td></tr>
                        <tr><th><?php echo $__t('वैवाहिक अवस्था', 'Marital Status'); ?></th>
                            <td><?php echo htmlspecialchars($viewApp['marital_status'] ?: '—'); ?></td></tr>
                        <tr><th><?php echo $__t('पेशा', 'Occupation'); ?></th>
                            <td><?php echo htmlspecialchars($viewApp['occupation'] ?: '—'); ?></td></tr>
                        <tr><th>Tracking ID</th>
                            <td><code class="text-success fw-bold"><?php echo htmlspecialchars($viewApp['tracking_id'] ?? '—'); ?></code></td></tr>
                    </table>
                </div>

                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-phone"></i><?php echo $__t('सम्पर्क जानकारी', 'Contact Information'); ?></div>
                    <table class="table adm-detail-table">
                        <tr><th><?php echo $__t('मोबाइल', 'Mobile'); ?></th>
                            <td><a href="tel:<?php echo htmlspecialchars($viewApp['mobile'] ?? ''); ?>" class="text-decoration-none fw-semibold"><?php echo htmlspecialchars($viewApp['mobile'] ?? '—'); ?></a></td></tr>
                        <tr><th><?php echo $__t('इमेल', 'Email'); ?></th>
                            <td><?php echo $viewApp['email'] ? '<a href="mailto:'.htmlspecialchars($viewApp['email']).'" class="text-decoration-none">'.htmlspecialchars($viewApp['email']).'</a>' : '—'; ?></td></tr>
                        <tr><th><?php echo $__t('स्थायी ठेगाना', 'Permanent Address'); ?></th>
                            <td><?php echo htmlspecialchars($viewApp['permanent_address'] ?: '—'); ?></td></tr>
                        <tr><th><?php echo $__t('अस्थायी ठेगाना', 'Temporary Address'); ?></th>
                            <td><?php echo htmlspecialchars($viewApp['temporary_address'] ?: '—'); ?></td></tr>
                        <tr><th><?php echo $__t('शाखा', 'Branch'); ?></th>
                            <td><?php echo htmlspecialchars(str_replace('_',' ',ucwords($viewApp['branch'] ?? '—'))); ?></td></tr>
                        <tr><th><?php echo $__t('खाता प्रकार', 'Account Type'); ?></th>
                            <td><strong><?php echo htmlspecialchars($accType); ?></strong></td></tr>
                        <tr><th><?php echo $__t('दर्ता मिति', 'Created Date'); ?></th>
                            <td><?php echo formatNepaliDate($viewApp['created_at'], true); ?></td></tr>
                    </table>
                </div>

                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-id-card"></i><?php echo $__t('नागरिकता विवरण', 'Citizenship Details'); ?></div>
                    <table class="table adm-detail-table">
                        <tr><th><?php echo $__t('नागरिकता नं.', 'Citizenship No.'); ?></th>
                            <td><code class="text-dark"><?php echo htmlspecialchars($viewApp['citizenship_no'] ?? '—'); ?></code></td></tr>
                        <tr><th><?php echo $__t('जारी मिति', 'Issued Date'); ?></th>
                            <td><?php echo htmlspecialchars($viewApp['citizenship_issued_date'] ?: '—'); ?></td></tr>
                        <tr><th><?php echo $__t('जारी स्थान', 'Issued Place'); ?></th>
                            <td><?php echo htmlspecialchars($viewApp['citizenship_issued_place'] ?: '—'); ?></td></tr>
                        <tr><th><?php echo $__t('बुबाको नाम', "Father's Name"); ?></th>
                            <td><?php echo htmlspecialchars($viewApp['father_name'] ?: '—'); ?></td></tr>
                        <tr><th><?php echo $__t('आमाको नाम', "Mother's Name"); ?></th>
                            <td><?php echo htmlspecialchars($viewApp['mother_name'] ?: '—'); ?></td></tr>
                    </table>
                </div>

                <?php if (!empty($viewApp['nominee_name'])): ?>
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-user-friends"></i><?php echo $__t('नामिनी विवरण', 'Nominee Details'); ?></div>
                    <table class="table adm-detail-table">
                        <tr><th><?php echo $__t('नामिनीको नाम', 'Nominee Name'); ?></th>
                            <td><strong><?php echo htmlspecialchars($viewApp['nominee_name']); ?></strong></td></tr>
                        <tr><th><?php echo $__t('सम्बन्ध', 'Relation'); ?></th>
                            <td><?php echo htmlspecialchars($viewApp['nominee_relation'] ?: '—'); ?></td></tr>
                        <tr><th><?php echo $__t('फोन', 'Phone'); ?></th>
                            <td><a href="tel:<?php echo htmlspecialchars($viewApp['nominee_phone'] ?? ''); ?>" class="text-decoration-none"><?php echo htmlspecialchars($viewApp['nominee_phone'] ?: '—'); ?></a></td></tr>
                    </table>
                </div>
                <?php endif; ?>

                <!-- कागजातहरू — photos & documents -->
                <?php
                $docs = [
                    'photo'             => 'फोटो',
                    'citizenship_front' => 'नागरिकता अगाडि',
                    'citizenship_back'  => 'नागरिकता पछाडि',
                    'signature'         => 'हस्ताक्षर',
                ];
                $hasDocs = false;
                foreach ($docs as $col => $_) { if (!empty($viewApp[$col])) { $hasDocs = true; break; } }
                if ($hasDocs):
                ?>
                <div class="adm-info-group">
                <div class="adm-info-group-header"><i class="fas fa-images"></i>पेश गरिएका कागजातहरू</div>
                <div class="p-3"><div class="row g-3">
                    <?php foreach ($docs as $col => $label): ?>
                    <?php if (!empty($viewApp[$col])): ?>
                    <div class="col-6 col-md-3 text-center">
                        <a href="<?php echo htmlspecialchars(SITE_URL . $viewApp[$col]); ?>" target="_blank">
                            <img src="<?php echo htmlspecialchars(SITE_URL . $viewApp[$col]); ?>"
                                 class="img-thumbnail mb-1 acc-doc-thumb" alt="<?php echo $label; ?>">
                            <div class="small text-muted"><?php echo $label; ?></div>
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div></div></div>
                <?php endif; ?>

                <?php if (!empty($viewApp['admin_attachment'])): ?>
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-paperclip"></i><?php echo $__t('Admin संलग्न Document', 'Admin Attached Document'); ?></div>
                    <div class="p-3 d-flex align-items-center gap-3">
                        <i class="fas fa-file-alt fa-2x text-primary opacity-75"></i>
                        <div class="flex-grow-1 fw-semibold small"><?php echo htmlspecialchars(basename($viewApp['admin_attachment'])); ?></div>
                        <a href="<?php echo htmlspecialchars(SITE_URL . ltrim($viewApp['admin_attachment'], '/')); ?>"
                           class="btn btn-sm btn-outline-primary" target="_blank" download>
                            <i class="fas fa-download me-1"></i><?php echo $__t('डाउनलोड', 'Download'); ?>
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($viewApp['remarks'])): ?>
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-sticky-note"></i><?php echo $__t('Admin टिप्पणी (Member ले Tracker मा देख्छ)', 'Admin Remarks (Visible in member tracker)'); ?></div>
                    <div class="p-3 apt-text-block apt-text-block-success">
                        <?php echo nl2br(htmlspecialchars($viewApp['remarks'])); ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($accountHistory)): ?>
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-clock-rotate-left"></i>Status / Comment History</div>
                    <div class="p-3">
                        <?php echo arvLogList($accountHistory); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── RIGHT: Status Update Form ── -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-header gradient-card-header py-2">
                        <i class="fas fa-edit me-2"></i><?php echo $__t('स्थिति अपडेट / कैफियत / Document', 'Status Update / Remarks / Document'); ?>
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
                                    <i class="fas fa-reply me-1 text-success"></i><?php echo $__t('Admin कैफियत', 'Admin Remarks'); ?>
                                    <span class="text-muted fw-normal small">— <?php echo $__t('Member ले Tracker मा देख्छ', 'Visible in member tracker'); ?></span>
                                </label>
                                <textarea name="remarks" class="form-control" rows="4"
                                    placeholder="<?php echo $__t('स्वीकृति वा अस्वीकृतिको कारण, आवश्यक कागजात...', 'Reason for approval/rejection, required documents...'); ?>"
                                ><?php echo htmlspecialchars($viewApp['remarks'] ?? ''); ?></textarea>
                            </div>

                            <?php $hasEmail = !empty($viewApp['email']); $hasPhone = !empty($viewApp['mobile']); ?>
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

                            <!-- Admin ले खाता खोलने पत्र वा rejection notice attach गर्न सक्छ -->
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
                                <a href="account-applications.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-1"></i><?php echo $__t('सूचीमा', 'Back to list'); ?>
                                </a>
                            </div>
                        </form>

                        <hr class="my-3">
                        <form method="POST"
                              onsubmit="return confirm('<?php echo $__t('के तपाईं यो खाता आवेदन स्थायी रूपले मेटाउन निश्चित हुनुहुन्छ?', 'Are you sure you want to permanently delete this account application?'); ?>')">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="delete" value="1">
                            <input type="hidden" name="delete_id" value="<?php echo $viewApp['id']; ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                <i class="fas fa-trash me-1"></i><?php echo $__t('यो आवेदन मेटाउनुहोस्', 'Delete this application'); ?>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Summary -->
                <div class="card mt-3 bg-light border-0">
                    <div class="card-body py-3 text-center">
                        <div class="fs-6 fw-bold"><?php echo htmlspecialchars($accType); ?></div>
                        <div class="small text-muted mb-2"><?php echo $__t('खाता प्रकार', 'Account Type'); ?></div>
                        <?php if ($viewApp['branch']): ?>
                        <div class="small"><i class="fas fa-building me-1 text-muted"></i><?php echo htmlspecialchars($viewApp['branch']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php else: /* ═══════════ LIST VIEW ═══════════ */ ?>

<!-- ── Stat Mini Row ── -->
<div class="stat-mini-row no-print">
    <a href="account-applications.php" class="stat-mini <?php echo $status_filter===''?'active-filter':''; ?>">
        <div class="sm-icon ic-total"><i class="fas fa-file-alt"></i></div>
        <div class="sm-val"><?php echo $totalCount; ?></div>
        <div class="sm-lbl"><?php echo $__t('जम्मा आवेदन', 'Total Applications'); ?></div>
    </a>
    <a href="?status=pending" class="stat-mini <?php echo $status_filter==='pending'?'active-filter':''; ?>">
        <div class="sm-icon ic-pending"><i class="fas fa-clock"></i></div>
        <div class="sm-val"><?php echo $pendingCount; ?></div>
        <div class="sm-lbl"><?php echo $__t('पेन्डिङ', 'Pending'); ?></div>
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
</div>

<!-- ── Filter Bar ── -->
<div class="adm-filter-bar no-print">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3 col-6">
            <label><?php echo $__t('स्थिति', 'Status'); ?></label>
            <select name="status" id="qf_acc_status" class="form-select form-select-sm">
                <option value=""><?php echo $__t('सबै स्थिति', 'All Status'); ?></option>
                <option value="pending"  <?php echo $status_filter==='pending'?'selected':''; ?>>⏳ <?php echo $__t('पेन्डिङ', 'Pending'); ?></option>
                <option value="approved" <?php echo $status_filter==='approved'?'selected':''; ?>>✅ <?php echo $__t('स्वीकृत', 'Approved'); ?></option>
                <option value="rejected" <?php echo $status_filter==='rejected'?'selected':''; ?>>❌ <?php echo $__t('अस्वीकृत', 'Rejected'); ?></option>
            </select>
        </div>
        <div class="col-md-7 col-12">
            <label><?php echo $__t('खोज्नुहोस्', 'Search'); ?></label>
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="<?php echo $__t('नाम, मोबाइल, इमेल, नागरिकता नं., Tracking ID...', 'name, mobile, email, citizenship no., Tracking ID...'); ?>">
                <?php if ($search): ?><a href="?status=<?php echo urlencode($status_filter); ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a><?php endif; ?>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-search me-1"></i><?php echo $__t('खोज', 'Search'); ?></button>
        </div>
    </form>
    <script>document.getElementById('qf_acc_status').addEventListener('change',function(){this.closest('form').submit();});</script>
</div>

<!-- ── Account Table ── -->
<div class="card border-0 shadow-sm app-rounded-card">
    <div class="tbl-header-bar no-print">
        <h6><i class="fas fa-user-plus me-2 text-purple acc-title-icon"></i><?php echo $__t('खाता आवेदन सूची', 'Account Application List'); ?></h6>
        <span class="result-count-badge"><?php echo $totalCount; ?> <?php echo $__t('आवेदन', 'applications'); ?></span>
    </div>
    <div class="table-responsive admin-table-card">
        <table class="table-hover table app-table align-middle mb-0 table-responsive-stack">
            <thead>
                <tr>
                    <th class="acc-col-applicant"><?php echo $__t('आवेदक', 'Applicant'); ?></th>
                    <th><?php echo $__t('खाता प्रकार', 'Account Type'); ?></th>
                    <th><?php echo $__t('सम्पर्क', 'Contact'); ?></th>
                    <th><?php echo $__t('नागरिकता', 'Citizenship'); ?></th>
                    <th>Tracking ID</th>
                    <th><?php echo $__t('दर्ता मिति', 'Created Date'); ?></th>
                    <th><?php echo $__t('स्थिति', 'Status'); ?></th>
                    <th class="no-print"><?php echo $__t('कार्यहरू', 'Actions'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($applications)): ?>
            <tr class="no-results-row"><td colspan="8"><i class="fas fa-inbox fa-2x d-block mb-2"></i><?php echo $__t('कुनै खाता आवेदन फेला परेन।', 'No account applications found.'); ?></td></tr>
            <?php else: foreach ($applications as $app):
                $trackId = $app['tracking_id'] ?: 'ACC-' . str_pad($app['id'], 6, '0', STR_PAD_LEFT);
                $initLetter = mb_strtoupper(mb_substr($app['full_name'] ?? 'A', 0, 1));
                $accType = $accTypes[$app['account_type']] ?? $app['account_type'];
            ?>
            <tr data-status="<?php echo htmlspecialchars($app['status']); ?>">
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="av-letter av-acc"><?php echo $initLetter; ?></div>
                        <div>
                            <div class="cell-main"><?php echo htmlspecialchars($app['full_name']); ?></div>
                            <?php if ($app['full_name_en']): ?><div class="cell-sub"><?php echo htmlspecialchars($app['full_name_en']); ?></div><?php endif; ?>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="cell-main"><?php echo htmlspecialchars($accType); ?></div>
                    <?php if ($app['branch']): ?><div class="cell-sub"><i class="fas fa-building fa-xs me-1"></i><?php echo htmlspecialchars($app['branch']); ?></div><?php endif; ?>
                </td>
                <td>
                    <div class="cell-main"><i class="fas fa-phone fa-xs text-muted me-1"></i><?php echo htmlspecialchars($app['mobile']); ?></div>
                    <?php if ($app['email']): ?><div class="cell-sub"><?php echo htmlspecialchars($app['email']); ?></div><?php endif; ?>
                </td>
                <td><div class="cell-sub"><?php echo htmlspecialchars($app['citizenship_no'] ?: '—'); ?></div></td>
                <td><span class="track-badge"><?php echo htmlspecialchars($trackId); ?></span></td>
                <td><div class="cell-sub"><?php echo formatNepaliDate($app['created_at']); ?></div></td>
                <td><span class="badge-status badge-<?php echo htmlspecialchars($app['status']); ?>"><?php echo $statusLabel[$app['status']] ?? $app['status']; ?></span></td>
                <td class="no-print">
                    <div class="adm-action-icons">
                        <a href="account-applications.php?view=<?php echo $app['id']; ?>" class="adm-icon-btn adm-icon-btn--view" title="<?php echo $__t('विवरण', 'Details'); ?>" aria-label="View"><i class="fas fa-eye"></i></a>
                        <?php if ($app['status'] === 'pending'): ?>
                        <form method="POST" class="qaction-form" onsubmit="return confirm('<?php echo $__t('खाता आवेदन स्वीकृत गर्नुहुन्छ?', 'Approve this account application?'); ?>')">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="quick_status" value="1">
                            <input type="hidden" name="quick_id" value="<?php echo $app['id']; ?>">
                            <input type="hidden" name="quick_status_val" value="approved">
                            <button type="submit" class="btn-qapprove"><i class="fas fa-check me-1"></i><?php echo $__t('स्वीकृत', 'Approve'); ?></button>
                        </form>
                        <form method="POST" class="qaction-form" onsubmit="return confirm('<?php echo $__t('खाता आवेदन अस्वीकृत गर्नुहुन्छ?', 'Reject this account application?'); ?>')">
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
            <?php $qs2 = ['status'=>$status_filter,'search'=>$search]; ?>
            <a href="?<?php echo http_build_query(array_merge($qs2,['page'=>1])); ?>" class="<?php echo $page==1?'disabled':''; ?>"><i class="fas fa-angle-double-left"></i></a>
            <a href="?<?php echo http_build_query(array_merge($qs2,['page'=>max(1,$page-1)])); ?>" class="<?php echo $page==1?'disabled':''; ?>"><i class="fas fa-angle-left"></i></a>
            <?php $s2=max(1,$page-2);$e2=min($totalPages,$page+2); for($i=$s2;$i<=$e2;$i++): ?>
            <?php echo $i==$page ? "<span class='active'>$i</span>" : "<a href='?".http_build_query(array_merge($qs2,['page'=>$i]))."'>$i</a>"; ?>
            <?php endfor; ?>
            <a href="?<?php echo http_build_query(array_merge($qs2,['page'=>min($totalPages,$page+1)])); ?>" class="<?php echo $page>=$totalPages?'disabled':''; ?>"><i class="fas fa-angle-right"></i></a>
            <a href="?<?php echo http_build_query(array_merge($qs2,['page'=>$totalPages])); ?>" class="<?php echo $page==$totalPages?'disabled':''; ?>"><i class="fas fa-angle-double-right"></i></a>
            <span class="acc-page-meta"><?php echo $page; ?>/<?php echo $totalPages; ?> · <?php echo $totalCount; ?> <?php echo $__t('रेकर्ड', 'records'); ?></span>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php require_once 'includes/admin-footer.php'; ?>
