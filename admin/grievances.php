<?php
/**
 * Admin: गुनासो व्यवस्थापन — grievances.php
 * ================================================
 * feedbacks.php जस्तै full-page detail/edit view।
 * Modal हटाइयो — ?view=ID बाट detail page खुल्छ।
 */
$__t = static function (string $np, string $en): string {
    $lang = (string)($_SESSION['admin_lang'] ?? $_SESSION['lang'] ?? 'np');
    return strtolower($lang) === 'en' ? $en : $np;
};
$pageTitle   = $__t('गुनासो व्यवस्थापन', 'Grievance Management');
$currentPage = 'grievances';
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/request-status-history.php';

if (!isAdminLoggedIn()) {
    redirect(ADMIN_URL . 'index.php');
}

$db = getDB();
ensureRequestStatusHistoryTable($db);

/* CSRF सुरक्षा: POST अनुरोध प्रमाणित गर्नुहोस् */
checkCSRF();

/* ── auto-ALTER: ensure ALL grievance reply columns exist
   (Issue #4 — Member portal मा admin reply खाली देखिने bug का कारण
    पुरानो install मा admin_response / admin_note / resolved_at column नभएर
    UPDATE silent fail हुन्थ्यो। हरेक page load मा safe-add गरिन्छ — already-exists भए no-op।) */
safeAddColumn($db, 'grievances', 'admin_response',   "TEXT NULL DEFAULT NULL COMMENT 'Admin reply text shown to member'");
safeAddColumn($db, 'grievances', 'admin_note',       "TEXT NULL DEFAULT NULL COMMENT 'Internal admin note (member-invisible)'");
safeAddColumn($db, 'grievances', 'resolved_at',      "TIMESTAMP NULL DEFAULT NULL");
safeAddColumn($db, 'grievances', 'admin_attachment', "VARCHAR(500) DEFAULT '' COMMENT 'Admin reply संलग्न file'");
safeAddColumn($db, 'grievances', 'updated_at',       "TIMESTAMP NULL DEFAULT NULL");

require_once 'includes/admin-header.php';
require_once __DIR__ . '/../includes/auth-roles.php';
/* RBAC: staff hercha matra; mutate admin+ matra */
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_role('admin');
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/includes/admin-request-view.php';

/* ─── POST handlers ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* ── Status + Response Update ── */
    if (isset($_POST['update_grievance'])) {
        $id          = (int)$_POST['id'];
        $status      = clean_text($_POST['status']);
        $adminResp   = clean_text($_POST['admin_response'] ?? '');
        $adminNote   = trim($_POST['admin_note'] ?? '');
        $newFile     = adminUploadFile('admin_attachment');
        $resolvedAt  = ($status === 'resolved' || $status === 'closed') ? date('Y-m-d H:i:s') : null;
        $oldStatus   = '';
        $notifyOptIn = !empty($_POST['notify_member']) && $_POST['notify_member'] === '1';
        $notifyOutcome = [
            'admin_chose' => $notifyOptIn,
            'email' => ['status' => 'not_attempted', 'reason' => '', 'to' => ''],
            'sms'   => ['status' => 'not_attempted', 'reason' => '', 'to' => ''],
        ];
        try {
            $oldStQ = $db->prepare("SELECT status FROM grievances WHERE id=? LIMIT 1");
            $oldStQ->execute([$id]);
            $oldStatus = (string)($oldStQ->fetchColumn() ?: '');
        } catch (Exception $e) {}
        try {
            if ($newFile) {
                $stmt = $db->prepare("UPDATE grievances SET status=?, admin_response=?, admin_note=?, resolved_at=?, admin_attachment=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$status, $adminResp, $adminNote, $resolvedAt, $newFile, $id]);
            } else {
                $stmt = $db->prepare("UPDATE grievances SET status=?, admin_response=?, admin_note=?, resolved_at=?, updated_at=NOW() WHERE id=?");
                $stmt->execute([$status, $adminResp, $adminNote, $resolvedAt, $id]);
            }

            /* Member लाई notification — fail भए पनि main काम रोकिँदैन */
            try {
                $nRow = $db->prepare("SELECT name, email, phone FROM grievances WHERE id=?");
                $nRow->execute([$id]);
                $nData = $nRow->fetch();
                if ($nData) {
                    $r = sendMemberStatusUpdate('grievance',
                        $nData['email'] ?? '', $nData['phone'] ?? '', $nData['name'] ?? '',
                        $status, $adminResp, 'GRV-' . $id, !$notifyOptIn);
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
                    'grievance',
                    $id,
                    $oldStatus !== '' ? $oldStatus : null,
                    $status,
                    (string)($adminResp !== '' ? $adminResp : $adminNote),
                    $notifySent,
                    (int)($_SESSION['admin_id'] ?? 0),
                    (string)($_SESSION['admin_name'] ?? 'Admin'),
                    $notifyOutcome
                );
            } catch (Exception $e) {}

            setFlash('success', $__t('गुनासो अपडेट भयो।', 'Grievance updated.'));
        } catch (Exception $e) {
            setFlash('error', $__t('त्रुटि भयो', 'An error occurred') . ': ' . $e->getMessage());
        }
        redirect('grievances.php' . ($id ? '?view=' . $id : ''));
    }

    /* ── Remove attachment ── */
    if (isset($_POST['remove_attachment'])) {
        $id = (int)$_POST['id'];
        try {
            $row = $db->prepare("SELECT admin_attachment FROM grievances WHERE id=?");
            $row->execute([$id]);
            $r = $row->fetch();
            if ($r && !empty($r['admin_attachment'])) {
                $fp = ROOT_PATH . $r['admin_attachment'];
                if (file_exists($fp)) @unlink($fp);
            }
            $db->prepare("UPDATE grievances SET admin_attachment='' WHERE id=?")->execute([$id]);
            setFlash('success', $__t('फाइल हटाइयो।', 'File removed.'));
        } catch (Exception $e) {}
        redirect('grievances.php?view=' . $id);
    }

    /* ── Delete ── */
    if (isset($_POST['delete'])) {
        $id = (int)$_POST['id'];
        $db->prepare("DELETE FROM grievances WHERE id=?")->execute([$id]);
        setFlash('success', $__t('गुनासो मेटाइयो।', 'Grievance deleted.'));
        redirect('grievances.php');
    }

    /* ── Quick Resolve (list view बाट) ── */
    if (isset($_POST['quick_resolve'])) {
        $qid = (int)($_POST['quick_id'] ?? 0);
        $allowed = ['pending','in_progress','resolved','closed'];
        $qst = in_array($_POST['quick_resolve_status'] ?? '', $allowed) ? $_POST['quick_resolve_status'] : 'resolved';
        $oldStatus = '';
        $notifySent = false;
        try {
            $oldQ = $db->prepare("SELECT status FROM grievances WHERE id=? LIMIT 1");
            $oldQ->execute([$qid]);
            $oldStatus = (string)($oldQ->fetchColumn() ?: '');
        } catch (Exception $e) {}
        try {
            $db->prepare("UPDATE grievances SET status=? WHERE id=?")->execute([$qst, $qid]);
            try {
                $nr = $db->prepare("SELECT name, email, phone, tracking_id FROM grievances WHERE id=?");
                $nr->execute([$qid]);
                $nd = $nr->fetch();
                if ($nd) {
                    sendMemberStatusUpdate(
                        'grievance',
                        (string)($nd['email'] ?? ''),
                        (string)($nd['phone'] ?? ''),
                        (string)($nd['name'] ?? ''),
                        $qst,
                        '',
                        (string)($nd['tracking_id'] ?? ('GRV-' . $qid))
                    );
                    $notifySent = true;
                }
            } catch (Exception $e) {}
            try {
                logRequestStatusHistory(
                    $db,
                    'grievance',
                    $qid,
                    $oldStatus !== '' ? $oldStatus : null,
                    $qst,
                    '',
                    $notifySent,
                    (int)($_SESSION['admin_id'] ?? 0),
                    (string)($_SESSION['admin_name'] ?? 'Admin')
                );
            } catch (Exception $e) {}
            setFlash('success', $__t('गुनासो स्थिति परिवर्तन गरियो।', 'Grievance status changed.'));
        } catch (Exception $e) { setFlash('error', $__t('त्रुटि भयो।', 'An error occurred.')); }
        $gStatuses = ['pending', 'in_progress', 'resolved', 'closed'];
        $redSt = $_GET['status'] ?? '';
        if ($redSt !== '' && !in_array($redSt, $gStatuses, true)) {
            $redSt = '';
        }
        $qs = http_build_query([
            'status' => $redSt,
            'search' => mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200, 'UTF-8'),
        ]);
        redirect('grievances.php?' . $qs);
    }
}

/* ── Status / filter / search query ── */
$grievanceListStatuses = ['pending', 'in_progress', 'resolved', 'closed'];
$statusFilter = $_GET['status'] ?? '';
if ($statusFilter !== '' && !in_array($statusFilter, $grievanceListStatuses, true)) {
    $statusFilter = '';
}
$search       = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200, 'UTF-8');
$where        = '1=1';
$params       = [];
if ($statusFilter) { $where .= ' AND status = ?'; $params[] = $statusFilter; }
if ($search !== '') {
    $where .= ' AND (name LIKE ? OR phone LIKE ? OR email LIKE ? OR tracking_id LIKE ? OR subject LIKE ?)';
    $t = "%$search%";
    $params = array_merge($params, [$t,$t,$t,$t,$t]);
}
try {
    $stmt = $db->prepare("SELECT * FROM grievances WHERE $where ORDER BY created_at DESC");
    $stmt->execute($params);
    $grievances = $stmt->fetchAll();
} catch (Exception $e) { $grievances = []; }

/* ── Counts ── */
$counts = ['pending'=>0,'in_progress'=>0,'resolved'=>0,'closed'=>0];
try {
    $cntStmt = $db->query("SELECT status, COUNT(*) c FROM grievances GROUP BY status");
    while ($r = $cntStmt->fetch()) if (isset($counts[$r['status']])) $counts[$r['status']] = (int)$r['c'];
} catch (Exception $e) {}
$total = array_sum($counts);

/* ── Single view ── */
$viewGrv = null;
if (isset($_GET['view'])) {
    $s = $db->prepare("SELECT * FROM grievances WHERE id=?");
    $s->execute([(int)$_GET['view']]);
    $viewGrv = $s->fetch();
}
$grvHistory = [];
if ($viewGrv && !empty($viewGrv['id'])) {
    try {
        $grvHistory = fetchRequestStatusHistory($db, 'grievance', (int)$viewGrv['id'], 40);
    } catch (Exception $e) {
        $grvHistory = [];
    }
}

/* ── Helpers ── */
$statusLabel = [
    'pending'     => $__t('पेन्डिङ', 'Pending'),
    'in_progress' => $__t('प्रक्रियामा', 'In Progress'),
    'resolved'    => $__t('समाधान', 'Resolved'),
    'closed'      => $__t('बन्द', 'Closed')
];
$statusClass = ['pending' => 'warning', 'in_progress' => 'info', 'resolved' => 'success', 'closed' => 'secondary'];
$catLabels   = ['service' => 'सेवा', 'staff' => 'कर्मचारी', 'loan' => 'ऋण', 'account' => 'खाता', 'branch' => 'शाखा', 'other' => 'अन्य'];

function grvBadge($status, $label, $class) {
    return '<span class="badge bg-' . $class . '">' . htmlspecialchars($label) . '</span>';
}
function grvAttachUrl($path) { return empty($path) ? '' : SITE_URL . ltrim($path, '/'); }
function grvAttachName($path) { return basename($path ?: ''); }
?>

<?php /* ════════════════════════════════════════════
         PAGE HEADER — single view বা list view
         ════════════════════════════════════════════ */
if ($viewGrv) {
    $trackId = $viewGrv['tracking_id'] ?? 'GRV-' . str_pad($viewGrv['id'], 6, '0', STR_PAD_LEFT);
    echo adminPageHeader(
        $__t('गुनासो विवरण', 'Grievance Details'),
        'fa-exclamation-circle',
        'Tracking: ' . $trackId,
        adminBackBtn('grievances.php', $__t('गुनासो सूचीमा फर्किनुहोस्', 'Back to grievance list'))
    );
} else {
    echo adminPageHeader(
        $__t('गुनासो व्यवस्थापन', 'Grievance Management'),
        'fa-exclamation-circle',
        $__t('सदस्यहरूद्वारा पेश गरिएका गुनासो — स्थिति अपडेट, Admin प्रतिक्रिया र Document।', 'Manage member grievances with status updates, admin response, and documents.'),
        adminStatLink('?status=pending',  'danger',  $__t('पेन्डिङ', 'Pending'), $counts['pending'])
        . ' '
        . adminStatLink('grievances.php', 'secondary', $__t('जम्मा', 'Total'), $total)
    );
} ?>

<?php $flash = getFlash(); if ($flash): ?>
<?php echo adminAlert($flash['type'] === 'success' ? 'success' : 'danger', $flash['message']); ?>
<?php endif; ?>

<?php /* ═══════════════════════════════════════════════
         SINGLE DETAIL VIEW
         ═══════════════════════════════════════════════ */
if ($viewGrv):
    $sc      = $statusClass[$viewGrv['status']] ?? 'secondary';
    $sl      = $statusLabel[$viewGrv['status']] ?? $viewGrv['status'];
    $trackId = $viewGrv['tracking_id'] ?? 'GRV-' . str_pad($viewGrv['id'], 6, '0', STR_PAD_LEFT);
    $catTxt  = $catLabels[$viewGrv['category'] ?? ''] ?? ($viewGrv['category'] ?? '—');
?>
<div class="card shadow-sm mb-4 arv-legacy-detail">
    <div class="card-header gradient-card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $__t('गुनासो विवरण', 'Grievance Details'); ?>
            <code class="grv-track-code">
                <?php echo htmlspecialchars($trackId); ?>
            </code>
        </h5>
        <?php echo grvBadge($viewGrv['status'], $sl, $sc); ?>
    </div>

    <div class="card-body">
        <div class="row g-4">

            <!-- ── LEFT: Member Info + Message + Previous Response ── -->
            <div class="col-lg-5">
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="lucide-icon" aria-hidden="true" data-lucide="user"></i><?php echo $__t('गुनासोकर्ताको जानकारी', 'Complainant Information'); ?></div>
                    <table class="table adm-detail-table">
                        <?php if (!$viewGrv['is_anonymous']): ?>
                        <tr><th><?php echo $__t('नाम', 'Name'); ?></th>
                            <td><strong><?php echo htmlspecialchars($viewGrv['name'] ?? '—'); ?></strong></td></tr>
                        <tr><th><?php echo $__t('फोन', 'Phone'); ?></th>
                            <td><a href="tel:<?php echo htmlspecialchars($viewGrv['phone'] ?? ''); ?>" class="text-decoration-none fw-semibold"><?php echo htmlspecialchars($viewGrv['phone'] ?? '—'); ?></a></td></tr>
                        <tr><th><?php echo $__t('इमेल', 'Email'); ?></th>
                            <td><?php echo $viewGrv['email'] ? '<a href="mailto:'.htmlspecialchars($viewGrv['email']).'" class="text-decoration-none">'.htmlspecialchars($viewGrv['email']).'</a>' : '—'; ?></td></tr>
                        <tr><th><?php echo $__t('सदस्य नं.', 'Member ID'); ?></th>
                            <td><?php echo $viewGrv['member_id'] ? '<span class="badge bg-success-subtle text-success-emphasis fw-semibold px-2">'.htmlspecialchars($viewGrv['member_id']).'</span>' : '<span class="text-muted">—</span>'; ?></td></tr>
                        <?php else: ?>
                        <tr><td colspan="2" class="text-center text-muted fst-italic py-2">
                            <i class="fas fa-user-secret me-1"></i><?php echo $__t('गुप्त पहिचान (Anonymous)', 'Anonymous Identity'); ?>
                        </td></tr>
                        <?php endif; ?>
                        <tr><th><?php echo $__t('वर्ग', 'Category'); ?></th>
                            <td><?php echo htmlspecialchars($catTxt); ?></td></tr>
                        <tr><th>Tracking ID</th>
                            <td><code class="text-success fw-bold"><?php echo htmlspecialchars($viewGrv['tracking_id'] ?? '—'); ?></code></td></tr>
                        <tr><th><?php echo $__t('दर्ता मिति', 'Created Date'); ?></th>
                            <td><?php echo isset($viewGrv['created_at']) ? formatNepaliDate($viewGrv['created_at'], true) : '—'; ?></td></tr>
                        <tr><th><?php echo $__t('अवस्था', 'Status'); ?></th>
                            <td><?php echo grvBadge($viewGrv['status'], $sl, $sc); ?></td></tr>
                        <?php if ($viewGrv['resolved_at']): ?>
                        <tr><th><?php echo $__t('समाधान मिति', 'Resolved Date'); ?></th>
                            <td><?php echo formatNepaliDate($viewGrv['resolved_at'], true); ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>

                <?php if (!empty($viewGrv['subject'])): ?>
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-tag"></i><?php echo $__t('विषय', 'Subject'); ?></div>
                    <div class="p-3 fw-semibold grv-subject-box">
                        <?php echo htmlspecialchars($viewGrv['subject']); ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-comment-dots"></i><?php echo $__t('गुनासोको विस्तृत विवरण', 'Detailed Description'); ?></div>
                    <div class="p-3 grv-desc-box">
                        <?php echo nl2br(htmlspecialchars($viewGrv['description'] ?? '—')); ?>
                    </div>
                </div>

                <?php if (!empty($viewGrv['admin_response'])): ?>
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-reply"></i>Admin प्रतिक्रिया <small class="fw-normal text-muted ms-1">(Member ले tracker मा देख्छ)</small></div>
                    <div class="p-3 grv-response-box">
                        <?php echo nl2br(htmlspecialchars($viewGrv['admin_response'])); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($viewGrv['admin_note'])): ?>
                <div class="adm-info-group">
                    <div class="adm-info-group-header grv-note-head"><i class="fas fa-sticky-note grv-note-icon"></i>Admin आन्तरिक टिप्पणी <small class="fw-normal text-muted ms-1">(केवल admin)</small></div>
                    <div class="p-3 grv-note-box">
                        <?php echo nl2br(htmlspecialchars($viewGrv['admin_note'])); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($viewGrv['admin_attachment'])): ?>
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-paperclip"></i>Admin संलग्न Document</div>
                    <div class="p-3 d-flex align-items-center gap-3">
                        <i class="fas fa-file-alt fa-2x text-primary opacity-75"></i>
                        <div class="flex-grow-1 small fw-semibold">
                            <?php echo htmlspecialchars(grvAttachName($viewGrv['admin_attachment'])); ?>
                        </div>
                        <a href="<?php echo htmlspecialchars(grvAttachUrl($viewGrv['admin_attachment'])); ?>"
                           class="btn btn-sm btn-outline-primary" target="_blank" download>
                            <i class="fas fa-download me-1"></i>Download
                        </a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('<?php echo $__t('फाइल हटाउने?', 'Remove this file?'); ?>')">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="remove_attachment" value="1">
                            <input type="hidden" name="id" value="<?php echo $viewGrv['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Delete" title="Delete"><i class="fas fa-trash"></i></button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($grvHistory)): ?>
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-clock-rotate-left"></i><?php echo $__t('Status / Comment History', 'Status / Comment History'); ?></div>
                    <div class="p-3">
                        <?php echo arvLogList($grvHistory); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── RIGHT: Edit Form ── -->
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm">
                    <div class="card-header gradient-card-header py-2">
                        <i class="fas fa-edit me-2"></i><?php echo $__t('स्थिति अपडेट / प्रतिक्रिया / Note / Document', 'Status Update / Response / Note / Document'); ?>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="update_grievance" value="1">
                            <input type="hidden" name="id" value="<?php echo $viewGrv['id']; ?>">

                            <!-- Status -->
                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    <i class="fas fa-circle-dot me-1"></i>अवस्था (Status)
                                </label>
                                <select name="status" class="form-select">
                                    <?php foreach ($statusLabel as $v => $l): ?>
                                    <option value="<?php echo $v; ?>" <?php echo $viewGrv['status']===$v?'selected':''; ?>>
                                        <?php echo $l; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Admin Response — member ले tracker मा देख्छ -->
                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    <i class="fas fa-reply me-1 text-success"></i>Admin प्रतिक्रिया
                                    <span class="text-muted fw-normal small">— Member ले Application Tracker मा देख्छ</span>
                                </label>
                                <textarea name="admin_response" class="form-control" rows="3"
                                    placeholder="सदस्यलाई प्रतिक्रिया लेख्नुहोस्..."
                                ><?php echo htmlspecialchars($viewGrv['admin_response'] ?? ''); ?></textarea>
                            </div>

                            <?php $hasEmail = !empty($viewGrv['email']); $hasPhone = !empty($viewGrv['phone']); ?>
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

                            <!-- Admin Internal Note — member देख्दैन -->
                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    <i class="fas fa-sticky-note me-1 grv-note-icon"></i>
                                    Admin आन्तरिक टिप्पणी (Note)
                                    <span class="text-muted fw-normal small">— Member ले देख्दैन</span>
                                </label>
                                <textarea name="admin_note" class="form-control grv-note-textarea" rows="3"
                                    placeholder="Admin को internal note — member देख्दैन..."
                                ><?php echo htmlspecialchars($viewGrv['admin_note'] ?? ''); ?></textarea>
                            </div>

                            <!-- Document Upload -->
                            <div class="mb-4">
                                <label class="form-label fw-semibold">
                                    <i class="fas fa-paperclip me-1 text-primary"></i>Document संलग्न गर्नुहोस्
                                    <span class="text-muted fw-normal small">— PDF, Word, Image (max 5MB)</span>
                                </label>
                                <input type="file" name="admin_attachment" class="form-control"
                                       accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif">
                                <?php if (!empty($viewGrv['admin_attachment'])): ?>
                                <div class="form-text text-primary">
                                    <i class="fas fa-info-circle me-1"></i>
                                    हाल संलग्न: <strong><?php echo htmlspecialchars(grvAttachName($viewGrv['admin_attachment'])); ?></strong>
                                    — नयाँ file upload गर्नुभयो भने पुरानो replace हुन्छ।
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Submit -->
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary px-4">
                                    <i class="lucide-icon me-1" aria-hidden="true" data-lucide="save"></i><?php echo $__t('अपडेट गर्नुहोस्', 'Update'); ?>
                                </button>
                                <a href="grievances.php" class="btn btn-outline-secondary">
                                    <i class="lucide-icon me-1" aria-hidden="true" data-lucide="arrow-left"></i>सूचीमा फर्किनुहोस्
                                </a>
                            </div>
                        </form>

                        <!-- Delete button — separate form -->
                        <hr class="my-3">
                        <form method="POST" onsubmit="return confirm('<?php echo $__t('के तपाईं यो गुनासो स्थायी रूपले मेटाउन निश्चित हुनुहुन्छ?', 'Are you sure you want to permanently delete this grievance?'); ?>')">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="delete" value="1">
                            <input type="hidden" name="id" value="<?php echo $viewGrv['id']; ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                <i class="fas fa-trash me-1"></i>यो गुनासो मेटाउनुहोस्
                            </button>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php else: /* ═══════════════════════════════ LIST VIEW ═══════════════════════════════ */ ?>

<!-- ── Stat Mini Row ── -->
<div class="stat-mini-row no-print">
    <a href="grievances.php" class="stat-mini <?php echo $statusFilter===''?'active-filter':''; ?>">
        <div class="sm-icon ic-total"><i class="fas fa-list"></i></div>
        <div class="sm-val"><?php echo $total; ?></div>
        <div class="sm-lbl"><?php echo $__t('जम्मा गुनासो', 'Total Grievances'); ?></div>
    </a>
    <a href="?status=pending" class="stat-mini <?php echo $statusFilter==='pending'?'active-filter':''; ?>">
        <div class="sm-icon ic-pending"><i class="lucide-icon" aria-hidden="true" data-lucide="clock"></i></div>
        <div class="sm-val"><?php echo $counts['pending']; ?></div>
        <div class="sm-lbl"><?php echo $__t('पेन्डिङ', 'Pending'); ?></div>
    </a>
    <a href="?status=in_progress" class="stat-mini <?php echo $statusFilter==='in_progress'?'active-filter':''; ?>">
        <div class="sm-icon ic-process"><i class="fas fa-spinner"></i></div>
        <div class="sm-val"><?php echo $counts['in_progress']; ?></div>
        <div class="sm-lbl"><?php echo $__t('प्रक्रियामा', 'In Progress'); ?></div>
    </a>
    <a href="?status=resolved" class="stat-mini <?php echo $statusFilter==='resolved'?'active-filter':''; ?>">
        <div class="sm-icon ic-resolved"><i class="lucide-icon" aria-hidden="true" data-lucide="check-circle"></i></div>
        <div class="sm-val"><?php echo $counts['resolved']; ?></div>
        <div class="sm-lbl"><?php echo $__t('समाधान', 'Resolved'); ?></div>
    </a>
    <a href="?status=closed" class="stat-mini <?php echo $statusFilter==='closed'?'active-filter':''; ?>">
        <div class="sm-icon ic-anon"><i class="lucide-icon" aria-hidden="true" data-lucide="lock"></i></div>
        <div class="sm-val"><?php echo $counts['closed']; ?></div>
        <div class="sm-lbl"><?php echo $__t('बन्द', 'Closed'); ?></div>
    </a>
</div>

<!-- ── Filter Bar ── -->
<div class="adm-filter-bar no-print">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3 col-6">
            <label><?php echo $__t('स्थिति', 'Status'); ?></label>
            <select name="status" id="qf_grv_status" class="form-select form-select-sm">
                <option value=""><?php echo $__t('सबै स्थिति', 'All Status'); ?></option>
                <option value="pending"     <?php echo $statusFilter==='pending'?'selected':''; ?>>⏳ <?php echo $__t('पेन्डिङ', 'Pending'); ?></option>
                <option value="in_progress" <?php echo $statusFilter==='in_progress'?'selected':''; ?>>🔄 <?php echo $__t('प्रक्रियामा', 'In Progress'); ?></option>
                <option value="resolved"    <?php echo $statusFilter==='resolved'?'selected':''; ?>>✅ <?php echo $__t('समाधान', 'Resolved'); ?></option>
                <option value="closed"      <?php echo $statusFilter==='closed'?'selected':''; ?>>🔒 <?php echo $__t('बन्द', 'Closed'); ?></option>
            </select>
        </div>
        <div class="col-md-7 col-12">
            <label><?php echo $__t('खोज्नुहोस्', 'Search'); ?></label>
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white"><i class="lucide-icon text-muted" aria-hidden="true" data-lucide="search"></i></span>
                <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="<?php echo $__t('नाम, फोन, इमेल, Tracking ID, विषय...', 'name, phone, email, Tracking ID, subject...'); ?>">
                <?php if ($search): ?><a href="?status=<?php echo urlencode($statusFilter); ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a><?php endif; ?>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="lucide-icon me-1" aria-hidden="true" data-lucide="search"></i><?php echo $__t('खोज', 'Search'); ?></button>
        </div>
    </form>
    <script>document.getElementById('qf_grv_status').addEventListener('change',function(){this.closest('form').submit();});</script>
</div>

<!-- ── Grievances Table ── -->
<div class="card border-0 shadow-sm grv-table-card">
    <div class="tbl-header-bar no-print">
        <h6><i class="fas fa-exclamation-circle me-2 grv-note-icon"></i><?php echo $__t('गुनासो सूची', 'Grievance List'); ?></h6>
        <span class="result-count-badge"><?php echo count($grievances); ?> <?php echo $__t('गुनासो', 'grievances'); ?></span>
    </div>
    <div class="table-responsive admin-table-card">
        <table class="table-hover table app-table align-middle mb-0 table-responsive-stack">
            <thead>
                <tr>
                    <th class="grv-person-col"><?php echo $__t('व्यक्ति', 'Person'); ?></th>
                    <th><?php echo $__t('विषय', 'Subject'); ?></th>
                    <th><?php echo $__t('वर्ग', 'Category'); ?></th>
                    <th>Tracking ID</th>
                    <th><?php echo $__t('मिति', 'Date'); ?></th>
                    <th><?php echo $__t('स्थिति', 'Status'); ?></th>
                    <th class="no-print"><?php echo $__t('कार्यहरू', 'Actions'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($grievances)): ?>
            <tr class="no-results-row"><td colspan="7"><i class="lucide-icon fa-2x d-block mb-2" aria-hidden="true" data-lucide="inbox"></i><?php echo $__t('कुनै गुनासो फेला परेन।', 'No grievances found.'); ?></td></tr>
            <?php else: foreach ($grievances as $grv):
                $tId = $grv['tracking_id'] ?? 'GRV-' . str_pad($grv['id'], 6, '0', STR_PAD_LEFT);
                $initLetter = $grv['is_anonymous'] ? '?' : mb_strtoupper(mb_substr($grv['name'] ?? 'G', 0, 1));
            ?>
            <tr data-status="<?php echo htmlspecialchars($grv['status']); ?>">
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="av-letter <?php echo $grv['is_anonymous'] ? 'av-anon' : 'av-grv'; ?>"><?php echo $initLetter; ?></div>
                        <div>
                            <?php if (!$grv['is_anonymous']): ?>
                            <div class="cell-main"><?php echo htmlspecialchars($grv['name'] ?? ''); ?></div>
                            <div class="cell-sub"><i class="fas fa-phone fa-xs me-1"></i><?php echo htmlspecialchars($grv['phone'] ?? ''); ?><?php if ($grv['member_id']): ?> · <?php echo htmlspecialchars($grv['member_id']); ?><?php endif; ?></div>
                            <?php else: ?>
                            <div class="cell-main fst-italic text-muted">गुप्त पहिचान</div>
                            <div class="cell-sub"><span class="badge bg-secondary grv-anon-badge">Anonymous</span></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="cell-main" title="<?php echo htmlspecialchars($grv['description'] ?? ''); ?>">
                        <?php echo htmlspecialchars(mb_substr($grv['subject'] ?? '', 0, 50)); ?><?php if (mb_strlen($grv['subject'] ?? '')>50):?>…<?php endif; ?>
                    </div>
                </td>
                <td><span class="badge bg-info-subtle text-info border border-info border-opacity-25 fw-normal"><?php echo $catLabels[$grv['category']] ?? ($grv['category'] ?? '—'); ?></span></td>
                <td><span class="track-badge"><?php echo htmlspecialchars($tId); ?></span></td>
                <td><div class="cell-sub"><?php echo isset($grv['created_at']) ? formatNepaliDate($grv['created_at'], true) : ''; ?></div></td>
                <td><span class="badge-status badge-<?php echo htmlspecialchars($grv['status']); ?>"><?php echo $statusLabel[$grv['status']] ?? $grv['status']; ?></span></td>
                <td class="no-print">
                    <div class="adm-action-icons">
                        <a href="grievances.php?view=<?php echo $grv['id']; ?>" class="adm-icon-btn adm-icon-btn--view" title="<?php echo $__t('विस्तृत / अपडेट', 'Details / Update'); ?>" aria-label="View"><i class="lucide-icon" aria-hidden="true" data-lucide="eye"></i></a>
                        <?php if ($grv['status'] === 'pending' || $grv['status'] === 'in_progress'): ?>
                        <form method="POST" class="qaction-form" onsubmit="return confirm('<?php echo $__t('यो गुनासो समाधान भएको मान्नुहुन्छ?', 'Mark this grievance as resolved?'); ?>')">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="quick_resolve" value="1">
                            <input type="hidden" name="quick_id" value="<?php echo $grv['id']; ?>">
                            <input type="hidden" name="quick_resolve_status" value="resolved">
                            <button type="submit" class="btn-qresolve"><i class="fas fa-check me-1"></i><?php echo $__t('समाधान', 'Resolve'); ?></button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php require_once 'includes/admin-footer.php'; ?>
