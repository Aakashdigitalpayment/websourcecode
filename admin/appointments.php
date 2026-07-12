<?php
/**
 * Admin: भेटघाट व्यवस्थापन — appointments.php
 * =============================================
 * feedbacks.php pattern: ?view=ID → full-page detail + edit form।
 * Modal पूर्ण रूपले हटाइयो।
 */
$pageTitle   = 'भेटघाट व्यवस्थापन';
$currentPage = 'appointments';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/includes/admin-request-view.php';
require_once __DIR__ . '/../includes/request-status-history.php';

/* ── Auto-ALTER: admin_attachment column थप्ने — MySQL 5.7+ compatible ── */
safeAddColumn($db, 'appointments', 'admin_attachment', "VARCHAR(500) DEFAULT '' COMMENT 'Admin reply मा संलग्न file'");
safeAddColumn($db, 'appointments', 'visit_kind', "VARCHAR(20) NOT NULL DEFAULT 'member'");
safeAddColumn($db, 'appointments', 'organization_address', 'VARCHAR(500) NULL DEFAULT NULL');
safeAddColumn($db, 'appointments', 'organization_website', 'VARCHAR(255) NULL DEFAULT NULL');
safeAddColumn($db, 'appointments', 'contact_person', 'VARCHAR(120) NULL DEFAULT NULL');

$appointmentListStatuses = ['pending', 'confirmed', 'completed', 'cancelled'];
ensureRequestStatusHistoryTable($db);

/* ─── Status Update ─── */
if (isset($_POST['update_status'])) {
    checkCSRF();
    $id      = intval($_POST['id']);
    $status  = clean_text($_POST['status'] ?? '');
    if (!in_array($status, $appointmentListStatuses, true)) {
        setFlash('error', 'अमान्य स्थिति।');
        redirect('appointments.php' . ($id ? '?view=' . $id : ''));
    }
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
        $os = $db->prepare("SELECT status FROM appointments WHERE id=? LIMIT 1");
        $os->execute([$id]);
        $oldStatus = (string)($os->fetchColumn() ?: '');
    } catch (Exception $e) {}

    if ($newFile) {
        $stmt = $db->prepare("UPDATE appointments SET status=?, remarks=?, admin_attachment=? WHERE id=?");
        $stmt->execute([$status, $remarks, $newFile, $id]);
    } else {
        $stmt = $db->prepare("UPDATE appointments SET status=?, remarks=? WHERE id=?");
        $stmt->execute([$status, $remarks, $id]);
    }
    /* Member लाई status notification — email/SMS */
    try {
        /* appointments table मा 'name' column छ, 'full_name' होइन */
        $nRow = $db->prepare("SELECT name, email, phone FROM appointments WHERE id=?");
        $nRow->execute([$id]);
        $nData = $nRow->fetch();
        if ($nData) {
            $r = sendMemberStatusUpdate('appointment',
                $nData['email'] ?? '', $nData['phone'] ?? '', $nData['name'] ?? '',
                $status, $remarks, 'APT-' . $id, !$notifyOptIn);
            if (is_array($r)) {
                $notifyOutcome['email'] = $r['email'] ?? $notifyOutcome['email'];
                $notifyOutcome['sms']   = $r['sms']   ?? $notifyOutcome['sms'];
            }
        }
    } catch (Exception $e) { /* notification fail भए पनि main काम रोकिँदैन */ }
    $notifySent = ($notifyOutcome['email']['status'] === 'sent') || ($notifyOutcome['sms']['status'] === 'sent');
    try {
        logRequestStatusHistory(
            $db,
            'appointment',
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
    setFlash('success', 'स्थिति अपडेट भयो।');
    redirect('appointments.php' . ($id ? '?view=' . $id : ''));
}

/* ─── Delete ─── */
if (isset($_POST['delete'])) {
    checkCSRF();
    $id = intval($_POST['delete_id'] ?? 0);
    if ($id > 0) {
        $db->prepare("DELETE FROM appointments WHERE id=?")->execute([$id]);
        setFlash('success', 'भेटघाट मेटाइयो।');
    }
    redirect('appointments.php');
}

/* ─── Quick Status (list view बाट सिधै) ─── */
if (isset($_POST['quick_status'])) {
    checkCSRF();
    $qid = (int)($_POST['quick_id'] ?? 0);
    $allowed = $appointmentListStatuses;
    $qst = in_array($_POST['quick_status_val'] ?? '', $allowed, true) ? $_POST['quick_status_val'] : 'pending';
    $oldStatus = '';
    $notifySent = false;
    try {
        $os = $db->prepare("SELECT status FROM appointments WHERE id=? LIMIT 1");
        $os->execute([$qid]);
        $oldStatus = (string)($os->fetchColumn() ?: '');
    } catch (Exception $e) {}
    try {
        $db->prepare("UPDATE appointments SET status=? WHERE id=?")->execute([$qst, $qid]);
        try {
            $nr = $db->prepare("SELECT name, email, phone, tracking_id FROM appointments WHERE id=?");
            $nr->execute([$qid]); $nd = $nr->fetch();
            if ($nd) { sendMemberStatusUpdate('appointment', $nd['email']??'', $nd['phone']??'', $nd['name']??'', $qst, '', $nd['tracking_id']??''); $notifySent = true; }
        } catch (Throwable $e) { error_log("[appointments.php] " . $e->getMessage()); }
        try {
            logRequestStatusHistory(
                $db,
                'appointment',
                $qid,
                $oldStatus !== '' ? $oldStatus : null,
                $qst,
                '',
                $notifySent,
                (int)($_SESSION['admin_id'] ?? 0),
                (string)($_SESSION['admin_name'] ?? 'Admin')
            );
        } catch (Exception $e) {}
        setFlash('success', 'स्थिति परिवर्तन गरियो।');
    } catch (Exception $e) { setFlash('error', 'त्रुटि भयो।'); }
    $redAptSt = $_GET['status'] ?? '';
    if ($redAptSt !== '' && !in_array($redAptSt, $appointmentListStatuses, true)) {
        $redAptSt = '';
    }
    $qs = http_build_query([
        'status' => $redAptSt,
        'search' => mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200, 'UTF-8'),
        'page'   => max(1, (int)($_GET['page'] ?? 1)),
    ]);
    redirect('appointments.php?' . $qs);
}

/* ─── Filter + Pagination ─── */
$status_filter = $_GET['status'] ?? '';
if ($status_filter !== '' && !in_array($status_filter, $appointmentListStatuses, true)) {
    $status_filter = '';
}
$kind_filter = $_GET['kind'] ?? '';
if ($kind_filter !== '' && !in_array($kind_filter, ['member', 'cooperative'], true)) {
    $kind_filter = '';
}
$search  = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200, 'UTF-8');
$where   = "1=1"; $aptParams = [];
if ($status_filter) { $where .= " AND status = ?"; $aptParams[] = $status_filter; }
if ($kind_filter !== '') {
    $where .= " AND IFNULL(visit_kind,'member') = ?";
    $aptParams[] = $kind_filter;
}
if ($search !== '') {
    $where .= " AND (name LIKE ? OR phone LIKE ? OR email LIKE ? OR tracking_id LIKE ? OR branch LIKE ? OR purpose LIKE ? OR IFNULL(contact_person,'') LIKE ? OR IFNULL(organization_address,'') LIKE ?)";
    $t = "%$search%"; $aptParams = array_merge($aptParams, [$t,$t,$t,$t,$t,$t,$t,$t]);
}
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 15;
$offset = ($page - 1) * $limit;
try {
    $cnt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE $where"); $cnt->execute($aptParams); $total = $cnt->fetchColumn();
    $totalPages = ceil($total / $limit);
    $stmt = $db->prepare("SELECT * FROM appointments WHERE $where ORDER BY preferred_date DESC, created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute(array_merge($aptParams, [$limit, $offset])); $appointments = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('[admin/appointments list] ' . $e->getMessage());
    /* Fallback without coop columns if older DB somehow skipped safeAddColumn */
    try {
        $whereFb = '1=1'; $fbParams = [];
        if ($status_filter) { $whereFb .= ' AND status = ?'; $fbParams[] = $status_filter; }
        if ($search !== '') {
            $whereFb .= ' AND (name LIKE ? OR phone LIKE ? OR email LIKE ? OR tracking_id LIKE ? OR branch LIKE ? OR purpose LIKE ?)';
            $t = "%$search%"; $fbParams = array_merge($fbParams, [$t,$t,$t,$t,$t,$t]);
        }
        $cnt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE $whereFb"); $cnt->execute($fbParams); $total = $cnt->fetchColumn();
        $totalPages = ceil($total / $limit);
        $stmt = $db->prepare("SELECT * FROM appointments WHERE $whereFb ORDER BY preferred_date DESC, created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute(array_merge($fbParams, [$limit, $offset])); $appointments = $stmt->fetchAll();
    } catch (Exception $e2) {
        error_log('[admin/appointments list fallback] ' . $e2->getMessage());
        $appointments = []; $total = 0; $totalPages = 0;
    }
}

/* ─── Counts ─── */
try {
    $counts = [
        'pending'   => $db->query("SELECT COUNT(*) FROM appointments WHERE status='pending'")->fetchColumn(),
        'confirmed' => $db->query("SELECT COUNT(*) FROM appointments WHERE status='confirmed'")->fetchColumn(),
        'completed' => $db->query("SELECT COUNT(*) FROM appointments WHERE status='completed'")->fetchColumn(),
        'cancelled' => $db->query("SELECT COUNT(*) FROM appointments WHERE status='cancelled'")->fetchColumn(),
        'member'    => $db->query("SELECT COUNT(*) FROM appointments WHERE IFNULL(visit_kind,'member')='member'")->fetchColumn(),
        'cooperative'=> $db->query("SELECT COUNT(*) FROM appointments WHERE IFNULL(visit_kind,'member')='cooperative'")->fetchColumn(),
    ];
} catch (Exception $e) {
    $counts = ['pending'=>0,'confirmed'=>0,'completed'=>0,'cancelled'=>0,'member'=>0,'cooperative'=>0];
}

/* ─── Single view ─── */
$viewApt = null;
if (isset($_GET['view'])) {
    $s = $db->prepare("SELECT * FROM appointments WHERE id=?");
    $s->execute([(int)$_GET['view']]);
    $viewApt = $s->fetch();
    if (!$viewApt) { setFlash('error', 'भेटघाट फेला परेन।'); redirect('appointments.php'); }
}
$appointmentHistory = [];
if ($viewApt && !empty($viewApt['id'])) {
    try { $appointmentHistory = fetchRequestStatusHistory($db, 'appointment', (int)$viewApt['id'], 40); } catch (Exception $e) { $appointmentHistory = []; }
}

/* ─── Helpers ─── */
$statusClass = ['pending'=>'warning','confirmed'=>'info','completed'=>'success','cancelled'=>'danger'];
$statusLabel = ['pending'=>'पेन्डिङ','confirmed'=>'पुष्टि भएको','completed'=>'सम्पन्न','cancelled'=>'रद्द'];
$purposes = [
    'account_inquiry' => 'खाता जानकारी',
    'loan_inquiry'    => 'ऋण जानकारी',
    'kyc_update'      => 'केवाइसी अपडेट',
    'loan_repayment'  => 'ऋण भुक्तानी',
    'account_opening' => 'खाता खोल्ने',
    'other'           => 'अन्य'
];

/* ─── Page Header ─── */
if ($viewApt) {
    echo adminPageHeader('भेटघाट विवरण', 'fa-calendar-check',
        'APT-' . str_pad($viewApt['id'], 6, '0', STR_PAD_LEFT),
        adminBackBtn('appointments.php', 'भेटघाट सूचीमा फर्किनुहोस्'));
} else {
    echo adminPageHeader('भेटघाट / सहकारी भ्रमण', 'fa-calendar-check',
        'सदस्य भेटघाट र सहकारी भ्रमण दुवै यहाँ आउँछन् — confirm/complete/cancel गर्न सकिन्छ',
        adminStatLink('?status=pending', 'danger', 'पेन्डिङ', $counts['pending'])
        . ' ' . adminStatLink('?kind=cooperative', 'primary', 'सहकारी भ्रमण', $counts['cooperative'] ?? 0));
}

$flash = getFlash(); if ($flash) echo adminAlert($flash['type'] === 'success' ? 'success' : 'danger', $flash['message']);

/* ═══════════════════════════════════
   SINGLE DETAIL VIEW
   ═══════════════════════════════════ */
if ($viewApt):
    $sc = $statusClass[$viewApt['status']] ?? 'secondary';
    $sl = $statusLabel[$viewApt['status']] ?? $viewApt['status'];
    $purposeTxt = $purposes[$viewApt['purpose'] ?? ''] ?? ($viewApt['purpose'] ?? '—');
    $isCoopVisit = (($viewApt['visit_kind'] ?? 'member') === 'cooperative');
?>
<div class="card shadow-sm mb-4 arv-legacy-detail">
    <div class="card-header gradient-card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="lucide-icon me-2" aria-hidden="true" data-lucide="calendar-check"></i>भेटघाट विवरण
            <code class="apt-track-chip">
                APT-<?php echo str_pad($viewApt['id'], 6, '0', STR_PAD_LEFT); ?>
            </code>
            <?php if ($isCoopVisit): ?>
            <span class="badge bg-primary ms-1">सहकारी भ्रमण</span>
            <?php else: ?>
            <span class="badge bg-secondary ms-1">सदस्य भेटघाट</span>
            <?php endif; ?>
            <span class="badge bg-<?php echo $sc; ?> ms-1"><?php echo $sl; ?></span>
        </h5>
        <a href="appointments.php" class="btn btn-outline-light btn-sm">
            <i class="lucide-icon me-1" aria-hidden="true" data-lucide="arrow-left"></i>फिर्ता
        </a>
    </div>

    <div class="card-body">
        <div class="row g-4">

            <!-- ── LEFT: Appointment Details ── -->
            <div class="col-lg-7">

                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="lucide-icon" aria-hidden="true" data-lucide="user"></i><?php echo $isCoopVisit ? 'सहकारी जानकारी' : 'आवेदकको जानकारी'; ?></div>
                    <table class="table adm-detail-table">
                        <tr><th><?php echo $isCoopVisit ? 'सहकारी नाम' : 'नाम'; ?></th>
                            <td><strong><?php echo htmlspecialchars($viewApt['name'] ?? '—'); ?></strong></td></tr>
                        <?php if ($isCoopVisit): ?>
                        <tr><th>सम्पर्क व्यक्ति</th>
                            <td><?php echo htmlspecialchars($viewApt['contact_person'] ?? '—'); ?></td></tr>
                        <tr><th>ठेगाना</th>
                            <td><?php echo htmlspecialchars($viewApt['organization_address'] ?? '—'); ?></td></tr>
                        <tr><th>वेबसाइट</th>
                            <td><?php
                                $w = trim((string)($viewApt['organization_website'] ?? ''));
                                echo $w !== '' ? '<a href="'.htmlspecialchars($w).'" target="_blank" rel="noopener" class="text-decoration-none">'.htmlspecialchars($w).'</a>' : '<span class="text-muted">—</span>';
                            ?></td></tr>
                        <?php else: ?>
                        <tr><th>सदस्य ID</th>
                            <td><?php echo $viewApt['member_id'] ? '<span class="badge bg-success-subtle text-success-emphasis fw-semibold px-2">'.htmlspecialchars($viewApt['member_id']).'</span>' : '<span class="text-muted">—</span>'; ?></td></tr>
                        <?php endif; ?>
                        <tr><th>फोन</th>
                            <td><a href="tel:<?php echo htmlspecialchars($viewApt['phone'] ?? ''); ?>" class="text-decoration-none fw-semibold"><?php echo htmlspecialchars($viewApt['phone'] ?? '—'); ?></a></td></tr>
                        <tr><th>इमेल</th>
                            <td><?php echo $viewApt['email'] ? '<a href="mailto:'.htmlspecialchars($viewApt['email']).'" class="text-decoration-none">'.htmlspecialchars($viewApt['email']).'</a>' : '<span class="text-muted">—</span>'; ?></td></tr>
                    </table>
                </div>

                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-calendar-alt"></i>भेटघाट विवरण</div>
                    <table class="table adm-detail-table">
                        <tr><th>मिति</th>
                            <td><strong><?php echo !empty($viewApt['preferred_date']) ? formatNepaliDate($viewApt['preferred_date']) : '—'; ?></strong></td></tr>
                        <tr><th>समय</th>
                            <td><?php echo htmlspecialchars($viewApt['preferred_time'] ?? '—'); ?></td></tr>
                        <tr><th>उद्देश्य</th>
                            <td><?php echo htmlspecialchars($purposeTxt); ?></td></tr>
                        <tr><th>सेवा कार्यालय</th>
                            <td><?php echo htmlspecialchars(str_replace(['_'],[' '], ucwords($viewApt['branch'] ?? '—'))); ?></td></tr>
                        <tr><th>Tracking ID</th>
                            <td><code class="text-success fw-bold"><?php echo htmlspecialchars($viewApt['tracking_id'] ?? ('APT-'.str_pad($viewApt['id'],6,'0',STR_PAD_LEFT))); ?></code></td></tr>
                        <tr><th>दर्ता मिति</th>
                            <td><?php echo !empty($viewApt['created_at']) ? formatNepaliDate($viewApt['created_at'], true) : '—'; ?></td></tr>
                    </table>
                </div>

                <?php $memberMessage = trim((string)($viewApt['purpose_detail'] ?? $viewApt['message'] ?? '')); ?>
                <?php if ($memberMessage !== ''): ?>
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-comment-dots"></i><?php echo $isCoopVisit ? 'भ्रमण विवरण' : 'सदस्यको सन्देश'; ?></div>
                    <div class="p-3 apt-text-block">
                        <?php echo nl2br(htmlspecialchars($memberMessage)); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($viewApt['remarks'])): ?>
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-reply"></i>Admin टिप्पणी (Member ले Tracker मा देख्छ)</div>
                    <div class="p-3 apt-text-block apt-text-block-success">
                        <?php echo nl2br(htmlspecialchars($viewApt['remarks'])); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($viewApt['admin_attachment'])): ?>
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-paperclip"></i>Admin संलग्न Document</div>
                    <div class="p-3 d-flex align-items-center gap-3">
                        <i class="fas fa-file-alt fa-2x text-primary opacity-75"></i>
                        <div class="flex-grow-1">
                            <div class="fw-semibold small"><?php echo htmlspecialchars(basename($viewApt['admin_attachment'])); ?></div>
                        </div>
                        <a href="<?php echo htmlspecialchars(SITE_URL . ltrim($viewApt['admin_attachment'], '/')); ?>"
                           class="btn btn-sm btn-outline-primary" target="_blank" download>
                            <i class="fas fa-download me-1"></i>Download
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($appointmentHistory)): ?>
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-clock-rotate-left"></i>Status / Comment History</div>
                    <div class="p-3">
                        <?php echo arvLogList($appointmentHistory); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── RIGHT: Status Update Form ── -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-header gradient-card-header py-2">
                        <i class="fas fa-edit me-2"></i>स्थिति अपडेट / कैफियत / Document
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <?php echo csrfField(); /* CSRF protection — admin POST मा अनिवार्य */ ?>
                            <input type="hidden" name="update_status" value="1">
                            <input type="hidden" name="id" value="<?php echo $viewApt['id']; ?>">

                            <div class="mb-3">
                                <label class="form-label fw-semibold"><i class="fas fa-circle-dot me-1"></i>अवस्था</label>
                                <select name="status" class="form-select">
                                    <option value="pending"   <?php echo $viewApt['status']==='pending'  ?'selected':''; ?>>पेन्डिङ</option>
                                    <option value="confirmed" <?php echo $viewApt['status']==='confirmed'?'selected':''; ?>>पुष्टि भएको</option>
                                    <option value="completed" <?php echo $viewApt['status']==='completed'?'selected':''; ?>>सम्पन्न</option>
                                    <option value="cancelled" <?php echo $viewApt['status']==='cancelled'?'selected':''; ?>>रद्द</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    <i class="fas fa-reply me-1 text-success"></i>Admin कैफियत
                                    <span class="text-muted fw-normal small">— Member ले Tracker मा देख्छ</span>
                                </label>
                                <textarea name="remarks" class="form-control" rows="4"
                                    placeholder="Confirmation को विवरण, cancelled भएको कारण..."
                                ><?php echo htmlspecialchars($viewApt['remarks'] ?? ''); ?></textarea>
                            </div>

                            <?php $hasEmail = !empty($viewApt['email']); $hasPhone = !empty($viewApt['phone']); ?>
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

                            <!-- Admin ले appointment confirmation letter attach गर्न सक्छ -->
                            <div class="mb-4">
                                <label class="form-label fw-semibold">
                                    <i class="fas fa-paperclip me-1 text-primary"></i>Confirmation Letter/Document
                                    <span class="text-muted fw-normal small">— PDF, Image (max 5MB)</span>
                                </label>
                                <input type="file" name="admin_attachment" class="form-control"
                                       accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                                <?php if (!empty($viewApt['admin_attachment'])): ?>
                                <div class="form-text text-primary mt-1">
                                    <i class="fas fa-info-circle me-1"></i>हाल: <strong><?php echo htmlspecialchars(basename($viewApt['admin_attachment'])); ?></strong>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary px-4">
                                    <i class="lucide-icon me-1" aria-hidden="true" data-lucide="save"></i>अपडेट गर्नुहोस्
                                </button>
                                <a href="appointments.php" class="btn btn-outline-secondary">
                                    <i class="lucide-icon me-1" aria-hidden="true" data-lucide="arrow-left"></i>सूचीमा
                                </a>
                            </div>
                        </form>

                        <hr class="my-3">
                        <form method="POST"
                              onsubmit="return confirm('के तपाईं यो भेटघाट स्थायी रूपले मेटाउन निश्चित हुनुहुन्छ?')">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="delete" value="1">
                            <input type="hidden" name="delete_id" value="<?php echo $viewApt['id']; ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                <i class="fas fa-trash me-1"></i>यो भेटघाट मेटाउनुहोस्
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Appointment Summary Card -->
                <div class="card mt-3 bg-light border-0">
                    <div class="card-body py-3 text-center">
                        <?php $aptDate = $viewApt['preferred_date'] ?? ''; ?>
                        <div class="fs-6 fw-bold"><?php echo $aptDate ? formatNepaliDate($aptDate) : '—'; ?></div>
                        <div class="small text-muted mb-1">भेटघाट मिति</div>
                        <div class="small">
                            <i class="lucide-icon me-1 text-muted" aria-hidden="true" data-lucide="clock"></i><?php echo htmlspecialchars($viewApt['preferred_time'] ?? '—'); ?>
                            <?php if ($viewApt['branch']): ?>
                            &nbsp;|&nbsp;<i class="fas fa-building me-1 text-muted"></i><?php echo htmlspecialchars($viewApt['branch']); ?>
                            <?php endif; ?>
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
    <a href="appointments.php" class="stat-mini <?php echo ($status_filter==='' && $kind_filter==='')?'active-filter':''; ?>">
        <div class="sm-icon ic-total"><i class="lucide-icon" aria-hidden="true" data-lucide="calendar-check"></i></div>
        <div class="sm-val"><?php echo (int)(($counts['pending']??0)+($counts['confirmed']??0)+($counts['completed']??0)+($counts['cancelled']??0)); ?></div>
        <div class="sm-lbl">जम्मा</div>
    </a>
    <a href="?kind=cooperative" class="stat-mini <?php echo $kind_filter==='cooperative'?'active-filter':''; ?>">
        <div class="sm-icon ic-process"><i class="fas fa-handshake"></i></div>
        <div class="sm-val"><?php echo (int)($counts['cooperative'] ?? 0); ?></div>
        <div class="sm-lbl">सहकारी भ्रमण</div>
    </a>
    <a href="?kind=member" class="stat-mini <?php echo $kind_filter==='member'?'active-filter':''; ?>">
        <div class="sm-icon ic-total"><i class="fas fa-user"></i></div>
        <div class="sm-val"><?php echo (int)($counts['member'] ?? 0); ?></div>
        <div class="sm-lbl">सदस्य भेटघाट</div>
    </a>
    <a href="?status=pending" class="stat-mini <?php echo $status_filter==='pending'?'active-filter':''; ?>">
        <div class="sm-icon ic-pending"><i class="lucide-icon" aria-hidden="true" data-lucide="clock"></i></div>
        <div class="sm-val"><?php echo $counts['pending']; ?></div>
        <div class="sm-lbl">पेन्डिङ</div>
    </a>
    <a href="?status=confirmed" class="stat-mini <?php echo $status_filter==='confirmed'?'active-filter':''; ?>">
        <div class="sm-icon ic-process"><i class="fas fa-check"></i></div>
        <div class="sm-val"><?php echo $counts['confirmed']; ?></div>
        <div class="sm-lbl">पुष्टि भएको</div>
    </a>
    <a href="?status=completed" class="stat-mini <?php echo $status_filter==='completed'?'active-filter':''; ?>">
        <div class="sm-icon ic-approved"><i class="fas fa-check-double"></i></div>
        <div class="sm-val"><?php echo $counts['completed']; ?></div>
        <div class="sm-lbl">सम्पन्न</div>
    </a>
    <a href="?status=cancelled" class="stat-mini <?php echo $status_filter==='cancelled'?'active-filter':''; ?>">
        <div class="sm-icon ic-rejected"><i class="fas fa-times-circle"></i></div>
        <div class="sm-val"><?php echo $counts['cancelled']; ?></div>
        <div class="sm-lbl">रद्द</div>
    </a>
</div>

<!-- ── Filter Bar ── -->
<div class="adm-filter-bar no-print">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-2 col-6">
            <label>प्रकार</label>
            <select name="kind" id="apt_qf_kind" class="form-select form-select-sm">
                <option value="">सबै प्रकार</option>
                <option value="cooperative" <?php echo $kind_filter==='cooperative'?'selected':''; ?>>सहकारी भ्रमण</option>
                <option value="member" <?php echo $kind_filter==='member'?'selected':''; ?>>सदस्य भेटघाट</option>
            </select>
        </div>
        <div class="col-md-2 col-6">
            <label>स्थिति</label>
            <select name="status" id="apt_qf_status" class="form-select form-select-sm">
                <option value="">सबै स्थिति</option>
                <option value="pending"   <?php echo $status_filter==='pending'?'selected':''; ?>>⏳ पेन्डिङ</option>
                <option value="confirmed" <?php echo $status_filter==='confirmed'?'selected':''; ?>>✅ पुष्टि भएको</option>
                <option value="completed" <?php echo $status_filter==='completed'?'selected':''; ?>>☑️ सम्पन्न</option>
                <option value="cancelled" <?php echo $status_filter==='cancelled'?'selected':''; ?>>❌ रद्द</option>
            </select>
        </div>
        <div class="col-md-6 col-12">
            <label>खोज्नुहोस्</label>
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white"><i class="lucide-icon text-muted" aria-hidden="true" data-lucide="search"></i></span>
                <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="नाम, फोन, Tracking ID, सहकारी, सम्पर्क व्यक्ति...">
                <?php if ($search): ?><a href="?status=<?php echo urlencode($status_filter); ?>&amp;kind=<?php echo urlencode($kind_filter); ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a><?php endif; ?>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="lucide-icon me-1" aria-hidden="true" data-lucide="search"></i>खोज</button>
        </div>
    </form>
    <script>
    document.getElementById('apt_qf_status').addEventListener('change',function(){this.closest('form').submit();});
    document.getElementById('apt_qf_kind').addEventListener('change',function(){this.closest('form').submit();});
    </script>
</div>

<!-- ── Table ── -->
<div class="card border-0 shadow-sm app-rounded-card">
    <div class="tbl-header-bar no-print">
        <h6><i class="lucide-icon me-2 text-primary" aria-hidden="true" data-lucide="calendar-check"></i>भेटघाट सूची</h6>
        <span class="result-count-badge"><?php echo $total; ?> भेटघाट</span>
    </div>
    <div class="table-responsive admin-table-card">
        <table class="table-hover table app-table align-middle mb-0 table-responsive-stack">
            <thead>
                <tr>
                    <th>Tracking ID</th>
                    <th>नाम / ID</th>
                    <th>मिति / समय</th>
                    <th>उद्देश्य</th>
                    <th>सेवा कार्यालय</th>
                    <th>स्थिति</th>
                    <th class="no-print">कार्यहरू</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($appointments)): ?>
            <tr class="no-results-row"><td colspan="7"><i class="lucide-icon fa-2x d-block mb-2" aria-hidden="true" data-lucide="inbox"></i>कुनै भेटघाट फेला परेन।</td></tr>
            <?php else: foreach ($appointments as $apt):
                $sc = $statusClass[$apt['status']] ?? 'secondary';
                $sl = $statusLabel[$apt['status']] ?? $apt['status'];
                $aptTrackId = $apt['tracking_id'] ?: 'APT-' . str_pad($apt['id'], 6, '0', STR_PAD_LEFT);
            ?>
            <tr>
                <td><code class="text-primary small apt-track-code"><?php echo htmlspecialchars($aptTrackId); ?></code></td>
                <td>
                    <div class="cell-main"><?php echo htmlspecialchars($apt['name'] ?? '—'); ?></div>
                    <?php if (($apt['visit_kind'] ?? 'member') === 'cooperative'): ?>
                        <div class="cell-sub"><span class="badge bg-primary-subtle text-primary border border-primary-subtle">सहकारी भ्रमण</span></div>
                        <?php if (!empty($apt['contact_person'])): ?><div class="cell-sub"><?php echo htmlspecialchars($apt['contact_person']); ?></div><?php endif; ?>
                    <?php elseif (!empty($apt['member_id'])): ?>
                        <div class="cell-sub">ID: <?php echo htmlspecialchars($apt['member_id']); ?></div>
                    <?php endif; ?>
                    <div class="cell-sub"><i class="fas fa-phone fa-xs text-muted me-1"></i><?php echo htmlspecialchars($apt['phone'] ?? ''); ?></div>
                </td>
                <td>
                    <div class="cell-main"><?php echo !empty($apt['preferred_date']) ? formatNepaliDate($apt['preferred_date']) : '—'; ?></div>
                    <div class="cell-sub"><?php echo htmlspecialchars($apt['preferred_time'] ?? ''); ?></div>
                </td>
                <td><span class="small"><?php echo htmlspecialchars($purposes[$apt['purpose'] ?? ''] ?? ($apt['purpose'] ?? '—')); ?></span></td>
                <td><span class="small"><?php echo htmlspecialchars($apt['branch'] ?: '—'); ?></span></td>
                <td>
                    <span class="badge-status badge-<?php echo $apt['status']==='pending'?'pending':($apt['status']==='confirmed'?'approved':($apt['status']==='completed'?'approved':'rejected')); ?>">
                        <?php echo $sl; ?>
                    </span>
                </td>
                <td class="no-print">
                    <div class="adm-action-icons">
                        <a href="appointments.php?view=<?php echo $apt['id']; ?>" class="adm-icon-btn adm-icon-btn--view" title="विवरण" aria-label="View"><i class="lucide-icon" aria-hidden="true" data-lucide="eye"></i></a>
                        <?php if ($apt['status'] === 'pending'): ?>
                        <form method="POST" class="d-inline">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="quick_status" value="1">
                            <input type="hidden" name="quick_id" value="<?php echo $apt['id']; ?>">
                            <input type="hidden" name="quick_status_val" value="confirmed">
                            <button type="submit" class="btn-qapprove" title="पुष्टि गर्नुहोस्"><i class="fas fa-check me-1"></i>पुष्टि</button>
                        </form>
                        <form method="POST" class="d-inline" onsubmit="return confirm('रद्द गर्नुहुन्छ?')">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="quick_status" value="1">
                            <input type="hidden" name="quick_id" value="<?php echo $apt['id']; ?>">
                            <input type="hidden" name="quick_status_val" value="cancelled">
                            <button type="submit" class="btn-qreject" title="रद्द"><i class="fas fa-times me-1"></i>रद्द</button>
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

<!-- ── Pagination ── -->
<?php if ($totalPages > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <li class="page-item <?php echo $p===$page?'active':''; ?>">
            <a class="page-link" href="?<?php echo http_build_query(['status'=>$status_filter,'search'=>$search,'page'=>$p]); ?>"><?php echo $p; ?></a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<?php endif; ?>

<?php require_once 'includes/admin-footer.php'; ?>
