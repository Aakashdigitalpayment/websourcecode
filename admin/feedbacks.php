<?php
/**
 * Admin: Feedback/Survey Management — feedbacks.php
 * ==================================================
 * यहाँबाट admin ले सदस्यहरूको feedback/गुनासो हेर्न र manage गर्न सक्छ।
 * Features:
 *   — Tracking ID देखिन्छ
 *   — Status: pending → reviewed → resolved
 *   — Admin reply (member ले application-tracker मा देख्छ)
 *   — Admin internal note (केवल admin ले देख्छ, member देख्दैन)
 *   — Document upload (admin ले attachment जोड्न सक्छ)
 */
$pageTitle = 'Feedback / सुझाव व्यवस्थापन';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/includes/admin-request-view.php';
require_once __DIR__ . '/../includes/request-status-history.php';

$db = getDB();
ensureRequestStatusHistoryTable($db);

/* CSRF सुरक्षा: POST अनुरोध प्रमाणित गर्नुहोस् */
checkCSRF();

/* ── नयाँ columns auto-add — पुरानो DB मा नभएमा automatically थपिन्छ ──
   admin_note  = admin को आन्तरिक टिप्पणी (member देख्दैन)
   admin_attachment = admin ले upload गरेको document को path */
/* ── Auto-ALTER: MySQL 5.7+ compatible ── */
safeAddColumn($db, 'member_feedback', 'admin_note',       "TEXT DEFAULT NULL COMMENT 'Admin internal note — member le dekhdaina'");
safeAddColumn($db, 'member_feedback', 'admin_attachment', "VARCHAR(500) DEFAULT NULL COMMENT 'Admin uploaded document path'");
safeAddColumn($db, 'member_feedback', 'updated_at',       "TIMESTAMP NULL DEFAULT NULL COMMENT 'Last update timestamp'");
safeAddColumn($db, 'member_feedback', 'admin_reply',      "TEXT DEFAULT NULL COMMENT 'Admin reply visible to member via tracker'");

  /* ─── POST handler — status update, reply, note, file upload, delete ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'update_status') {
            $id         = (int)($_POST['id'] ?? 0);
            $status     = $_POST['status']      ?? 'pending';
            if (!in_array($status, ['pending', 'reviewed', 'resolved'], true)) {
                $status = 'pending';
            }
            $adminReply = trim($_POST['admin_reply']  ?? '');
            $adminNote  = trim($_POST['admin_note']   ?? '');
            $oldStatus = '';
            $notifyOptIn = !empty($_POST['notify_member']) && $_POST['notify_member'] === '1';
            $notifyOutcome = [
                'admin_chose' => $notifyOptIn,
                'email' => ['status' => 'not_attempted', 'reason' => '', 'to' => ''],
                'sms'   => ['status' => 'not_attempted', 'reason' => '', 'to' => ''],
            ];
            try {
                $os = $db->prepare("SELECT status FROM member_feedback WHERE id=? LIMIT 1");
                $os->execute([$id]);
                $oldStatus = (string)($os->fetchColumn() ?: '');
            } catch (Exception $e) {}

            /* File upload — adminUploadFile() function config.php मा छ
               नयाँ file आएमा save गर्छ, नआएमा पुरानो attachment राख्छ */
            $newFile = adminUploadFile('admin_attachment');

            if ($newFile) {
                /* नयाँ document upload भयो */
                $stmt = $db->prepare("UPDATE member_feedback
                    SET status = ?, admin_reply = ?, admin_note = ?,
                        admin_attachment = ?, updated_at = NOW()
                    WHERE id = ?");
                $stmt->execute([$status, $adminReply, $adminNote, $newFile, $id]);
            } else {
                /* File upload भएन — पुरानो attachment राख्छ */
                $stmt = $db->prepare("UPDATE member_feedback
                    SET status = ?, admin_reply = ?, admin_note = ?,
                        updated_at = NOW()
                    WHERE id = ?");
                $stmt->execute([$status, $adminReply, $adminNote, $id]);
            }
            try {
                $nr = $db->prepare("SELECT name, email, phone, tracking_id FROM member_feedback WHERE id=? LIMIT 1");
                $nr->execute([$id]);
                $nd = $nr->fetch();
                if ($nd && function_exists('sendMemberStatusUpdate')) {
                    $r = sendMemberStatusUpdate(
                        'feedback',
                        (string)($nd['email'] ?? ''),
                        (string)($nd['phone'] ?? ''),
                        (string)($nd['name'] ?? ''),
                        (string)$status,
                        (string)$adminReply,
                        (string)($nd['tracking_id'] ?? ''),
                        !$notifyOptIn
                    );
                    if (is_array($r)) {
                        $notifyOutcome['email'] = $r['email'] ?? $notifyOutcome['email'];
                        $notifyOutcome['sms']   = $r['sms']   ?? $notifyOutcome['sms'];
                    }
                }
            } catch (Throwable $e) {}
            $notifySent = ($notifyOutcome['email']['status'] === 'sent') || ($notifyOutcome['sms']['status'] === 'sent');
            try {
                logRequestStatusHistory(
                    $db,
                    'feedback',
                    $id,
                    $oldStatus !== '' ? $oldStatus : null,
                    $status,
                    (string)$adminReply,
                    $notifySent,
                    (int)($_SESSION['admin_id'] ?? 0),
                    (string)($_SESSION['admin_name'] ?? 'Admin'),
                    $notifyOutcome
                );
            } catch (Exception $e) {}
            setFlash('success', 'Feedback सफलतापूर्वक अपडेट भयो।');

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            /* Attachment file पनि delete गर्छ */
            try {
                $row = $db->prepare("SELECT admin_attachment FROM member_feedback WHERE id = ?");
                $row->execute([$id]);
                $r = $row->fetch();
                if ($r && !empty($r['admin_attachment'])) {
                    $filePath = ROOT_PATH . $r['admin_attachment'];
                    if (file_exists($filePath)) @unlink($filePath);
                }
            } catch (Throwable $e) { error_log("[feedbacks.php] " . $e->getMessage()); }
            $db->prepare("DELETE FROM member_feedback WHERE id = ?")
               ->execute([$id]);
            setFlash('success', 'Feedback सफलतापूर्वक मेटाइयो।');

        } elseif ($action === 'remove_attachment') {
            /* Document मात्र हटाउने (feedback नमेटाई) */
            $id = (int)($_POST['id'] ?? 0);
            try {
                $row = $db->prepare("SELECT admin_attachment FROM member_feedback WHERE id = ?");
                $row->execute([$id]);
                $r = $row->fetch();
                if ($r && !empty($r['admin_attachment'])) {
                    $filePath = ROOT_PATH . $r['admin_attachment'];
                    if (file_exists($filePath)) @unlink($filePath);
                }
            } catch (Throwable $e) { error_log("[feedbacks.php] " . $e->getMessage()); }
            $db->prepare("UPDATE member_feedback SET admin_attachment = NULL WHERE id = ?")
               ->execute([$id]);
            setFlash('success', 'Document हटाइयो।');
        }
    } catch (Exception $e) {
        setFlash('error', 'त्रुटि भयो: ' . $e->getMessage());
    }
    redirect('feedbacks.php' . (isset($_GET['view']) ? '?view=' . (int)$_GET['view'] : ''));
}

/* ─── Filter ─── */
$fbStatusAllowed = ['pending', 'reviewed', 'resolved'];
$fbTypeAllowed   = ['feedback', 'suggestion', 'complaint', 'inquiry'];
$filterStatus = $_GET['status'] ?? '';
if ($filterStatus !== '' && !in_array($filterStatus, $fbStatusAllowed, true)) {
    $filterStatus = '';
}
$filterType = $_GET['type'] ?? '';
if ($filterType !== '' && !in_array($filterType, $fbTypeAllowed, true)) {
    $filterType = '';
}
$filterSearch = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200, 'UTF-8');

$sql    = "SELECT * FROM member_feedback WHERE 1=1";
$params = [];
if ($filterStatus) { $sql .= " AND status = ?"; $params[] = $filterStatus; }
if ($filterType)   { $sql .= " AND type = ?";   $params[] = $filterType;   }
if ($filterSearch !== '') {
    $sql .= " AND (name LIKE ? OR phone LIKE ? OR tracking_id LIKE ? OR subject LIKE ? OR member_id LIKE ?)";
    $ft = "%$filterSearch%"; $params = array_merge($params, [$ft,$ft,$ft,$ft,$ft]);
}
$sql .= " ORDER BY created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$feedbacks = $stmt->fetchAll();

/* ─── Single view ─── */
$viewFeedback = null;
if (isset($_GET['view'])) {
    $s = $db->prepare("SELECT * FROM member_feedback WHERE id = ?");
    $s->execute([(int)$_GET['view']]);
    $viewFeedback = $s->fetch();
}
$feedbackHistory = [];
if ($viewFeedback && !empty($viewFeedback['id'])) {
    try { $feedbackHistory = fetchRequestStatusHistory($db, 'feedback', (int)$viewFeedback['id'], 40); } catch (Exception $e) { $feedbackHistory = []; }
}

/* ─── Counts for badges ─── */
$counts = [];
foreach (['pending','reviewed','resolved'] as $s) {
    $c = $db->prepare("SELECT COUNT(*) FROM member_feedback WHERE status = ?");
    $c->execute([$s]);
    $counts[$s] = $c->fetchColumn();
}

/* ─── Helpers ─── */
function fbBadge($status) {
    switch ($status) {
        case 'pending':  return '<span class="badge bg-warning text-dark">विचाराधीन</span>';
        case 'reviewed': return '<span class="badge bg-info text-white">समीक्षित</span>';
        case 'resolved': return '<span class="badge bg-success text-white">समाधान भयो</span>';
        default:         return '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>';
    }
}
function fbTypeBadge($type) {
    switch ($type) {
        case 'complaint':  return '<span class="badge bg-danger">गुनासो</span>';
        case 'suggestion': return '<span class="badge bg-info text-white">सुझाव</span>';
        case 'inquiry':    return '<span class="badge bg-primary">जिज्ञासा</span>';
        default:           return '<span class="badge bg-secondary">फिडब्याक</span>';
    }
}

/* attachment URL बनाउने helper — SITE_URL + relative path */
function attachmentUrl($path) {
    if (empty($path)) return '';
    return SITE_URL . ltrim($path, '/');
}
function attachmentName($path) {
    return basename($path ?: '');
}
?>

<div class="container-fluid py-4">

    <!-- ── Page Header ── -->
    <?php
    echo adminPageHeader('Feedback / सुझाव व्यवस्थापन', 'fa-comments',
        'सदस्यहरूको प्रतिक्रिया, गुनासो र सुझावहरू — Status अपडेट, Admin Note र Document Upload गर्नुहोस्।',
        adminStatLink('?status=pending', 'danger', 'पेन्डिङ', $counts['pending'])
        . ' ' . adminStatLink('?status=reviewed', 'info', 'हेरिएको', $counts['reviewed'])
        . ' ' . adminStatLink('feedbacks.php', 'secondary', 'जम्मा', array_sum($counts))
    );
    if ($flash = getFlash()):
    ?>
    <div class="alert alert-<?php echo $flash['type']==='success'?'success':'danger'; ?> alert-dismissible fade show mb-3"><i class="fas fa-<?php echo $flash['type']==='success'?'check-circle':'exclamation-circle'; ?> me-2"></i><?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <!-- ── Stat Mini Row ── -->
    <div class="stat-mini-row no-print">
        <a href="feedbacks.php" class="stat-mini <?php echo !$filterStatus&&!$filterType?'active-filter':''; ?>">
            <div class="sm-icon ic-total"><i class="fas fa-comments"></i></div>
            <div class="sm-val"><?php echo array_sum($counts); ?></div>
            <div class="sm-lbl">जम्मा</div>
        </a>
        <a href="?status=pending" class="stat-mini <?php echo $filterStatus==='pending'?'active-filter':''; ?>">
            <div class="sm-icon ic-pending"><i class="fas fa-clock"></i></div>
            <div class="sm-val"><?php echo $counts['pending']; ?></div>
            <div class="sm-lbl">पेन्डिङ</div>
        </a>
        <a href="?status=reviewed" class="stat-mini <?php echo $filterStatus==='reviewed'?'active-filter':''; ?>">
            <div class="sm-icon ic-process"><i class="fas fa-eye"></i></div>
            <div class="sm-val"><?php echo $counts['reviewed']; ?></div>
            <div class="sm-lbl">हेरिएको</div>
        </a>
        <a href="?status=resolved" class="stat-mini <?php echo $filterStatus==='resolved'?'active-filter':''; ?>">
            <div class="sm-icon ic-approved"><i class="fas fa-check-circle"></i></div>
            <div class="sm-val"><?php echo $counts['resolved']; ?></div>
            <div class="sm-lbl">समाधान</div>
        </a>
    </div>

    <?php if ($viewFeedback): ?>
    <!-- ════════════════════════════════════════════
         Single Feedback Detail View + Edit
    ════════════════════════════════════════════ -->
    <div class="card shadow-sm mb-4 arv-legacy-detail">
        <div class="card-header gradient-card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-comment-dots me-2"></i>Feedback विवरण
                <?php
                    $vfTrack = !empty($viewFeedback['tracking_id'])
                        ? $viewFeedback['tracking_id']
                        : 'FBK-' . str_pad($viewFeedback['id'], 6, '0', STR_PAD_LEFT);
                ?>
                <code style="font-size:0.85rem;background:rgba(255,255,255,0.15);padding:2px 10px;border-radius:6px;margin-left:8px;letter-spacing:1px;">
                    <?php echo htmlspecialchars($vfTrack); ?>
                </code>
            </h5>
            <a href="feedbacks.php" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i>सूचीमा फर्किनुहोस्
            </a>
        </div>

        <div class="card-body">
            <div class="row g-4">

                <!-- LEFT: Member Information -->
                <div class="col-lg-5">
                    <h6 class="fw-bold mb-2 text-muted"><i class="fas fa-user me-1"></i>सदस्य जानकारी</h6>
                    <table class="table adm-detail-table">
                        <tr>
                            <th>नाम</th>
                            <td><strong><?php echo htmlspecialchars($viewFeedback['name']); ?></strong></td>
                        </tr>
                        <tr>
                            <th>सदस्य नं.</th>
                            <td><?php echo htmlspecialchars($viewFeedback['member_id'] ?: '—'); ?></td>
                        </tr>
                        <tr>
                            <th>फोन</th>
                            <td><?php echo htmlspecialchars($viewFeedback['phone']); ?></td>
                        </tr>
                        <tr>
                            <th>इमेल</th>
                            <td><?php echo htmlspecialchars($viewFeedback['email'] ?: '—'); ?></td>
                        </tr>
                        <tr>
                            <th>प्रकार</th>
                            <td><?php echo fbTypeBadge($viewFeedback['type']); ?></td>
                        </tr>
                        <tr>
                            <th>विषय</th>
                            <td><?php echo htmlspecialchars($viewFeedback['subject'] ?: '—'); ?></td>
                        </tr>
                        <tr>
                            <th>दर्ता मिति</th>
                            <td><?php echo formatNepaliDate($viewFeedback['created_at'], true); ?></td>
                        </tr>
                        <tr>
                            <th>अवस्था</th>
                            <td><?php echo fbBadge($viewFeedback['status']); ?></td>
                        </tr>
                    </table>

                    <!-- Member को सन्देश -->
                    <h6 class="fw-bold mb-2 text-muted"><i class="fas fa-envelope me-1"></i>सदस्यको सन्देश</h6>
                    <div class="bg-light border rounded p-3 mb-3"
                         style="min-height:90px;white-space:pre-wrap;font-size:0.95rem;">
                        <?php echo nl2br(htmlspecialchars($viewFeedback['message'])); ?>
                    </div>

                    <!-- Admin Reply — member ले application-tracker मा देख्छ -->
                    <?php if (!empty($viewFeedback['admin_reply'])): ?>
                    <h6 class="fw-bold mb-2" style="color:var(--primary-color);">
                        <i class="fas fa-reply me-1"></i>Admin जवाफ
                        <small class="text-muted fw-normal">(Member ले tracker मा देख्छ)</small>
                    </h6>
                    <div class="bg-success bg-opacity-10 border border-success rounded p-3 mb-3"
                         style="white-space:pre-wrap;font-size:0.9rem;">
                        <?php echo nl2br(htmlspecialchars($viewFeedback['admin_reply'])); ?>
                    </div>
                    <?php endif; ?>

                    <!-- Admin Internal Note — member देख्दैन -->
                    <?php if (!empty($viewFeedback['admin_note'])): ?>
                    <h6 class="fw-bold mb-2" style="color:#6f4e00;">
                        <i class="fas fa-sticky-note me-1"></i>Admin आन्तरिक टिप्पणी
                        <small class="text-muted fw-normal">(केवल admin देख्छ)</small>
                    </h6>
                    <div class="rounded p-3 mb-3"
                         style="background:#fff9e6;border:1.5px solid #ffc107;white-space:pre-wrap;font-size:0.9rem;color:#5c4400;">
                        <?php echo nl2br(htmlspecialchars($viewFeedback['admin_note'])); ?>
                    </div>
                    <?php endif; ?>

                    <!-- Admin Attachment — upload गरिएको document -->
                    <?php if (!empty($viewFeedback['admin_attachment'])): ?>
                    <h6 class="fw-bold mb-2 text-primary">
                        <i class="fas fa-paperclip me-1"></i>संलग्न Document
                    </h6>
                    <div class="d-flex align-items-center gap-2 p-2 border rounded bg-light mb-3">
                        <i class="fas fa-file-alt fa-lg text-primary"></i>
                        <div class="flex-grow-1">
                            <div class="small fw-semibold">
                                <?php echo htmlspecialchars(attachmentName($viewFeedback['admin_attachment'])); ?>
                            </div>
                        </div>
                        <a href="<?php echo htmlspecialchars(attachmentUrl($viewFeedback['admin_attachment'])); ?>"
                           class="btn btn-sm btn-outline-primary" target="_blank" download>
                            <i class="fas fa-download me-1"></i>Download
                        </a>
                        <!-- Document हटाउने -->
                        <form method="POST" style="display:inline;"
                              onsubmit="return confirm('यो document हटाउने?');">
                            <input type="hidden" name="action" value="remove_attachment">
                            <input type="hidden" name="id"     value="<?php echo $viewFeedback['id']; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Document हटाउनुहोस्">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($feedbackHistory)): ?>
                    <h6 class="fw-bold mb-2 text-primary"><i class="fas fa-clock-rotate-left me-1"></i>Status / Comment History</h6>
                    <div class="mb-3">
                        <?php echo arvLogList($feedbackHistory); ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- RIGHT: Status Update Form -->
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header gradient-card-header py-2">
                            <i class="fas fa-edit me-2"></i>Status अपडेट / जवाफ / Note / Document
                        </div>
                        <div class="card-body">

                            <!-- enctype="multipart/form-data" — file upload को लागि अनिवार्य -->
                            <form method="POST" action="" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="id"     value="<?php echo $viewFeedback['id']; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                                <!-- Status dropdown -->
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">
                                        <i class="fas fa-circle-dot me-1"></i>अवस्था (Status)
                                    </label>
                                    <select name="status" class="form-select">
                                        <option value="pending"
                                            <?php echo $viewFeedback['status'] === 'pending'  ? 'selected' : ''; ?>>
                                            ⏳ Pending — समीक्षाधीन
                                        </option>
                                        <option value="reviewed"
                                            <?php echo $viewFeedback['status'] === 'reviewed' ? 'selected' : ''; ?>>
                                            👁 Reviewed — हेरिएको
                                        </option>
                                        <option value="resolved"
                                            <?php echo $viewFeedback['status'] === 'resolved' ? 'selected' : ''; ?>>
                                            ✅ Resolved — समाधान भयो
                                        </option>
                                    </select>
                                </div>

                                <!-- Admin Reply — member ले application-tracker मा देख्छ -->
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">
                                        <i class="fas fa-reply me-1 text-success"></i>
                                        Admin जवाफ
                                        <span class="text-muted fw-normal small">
                                            — Member ले Application Tracker मा देख्छ
                                        </span>
                                    </label>
                                    <textarea name="admin_reply" class="form-control" rows="3"
                                        placeholder="सदस्यलाई जवाफ लेख्नुहोस् (optional)..."
                                    ><?php echo htmlspecialchars($viewFeedback['admin_reply'] ?? ''); ?></textarea>
                                </div>

                                <?php $hasEmail = !empty($viewFeedback['email']); $hasPhone = !empty($viewFeedback['phone']); ?>
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

                                <!-- Admin Internal Note — केवल admin को लागि -->
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">
                                        <i class="fas fa-sticky-note me-1" style="color:#d4900a;"></i>
                                        Admin आन्तरिक टिप्पणी (Note)
                                        <span class="text-muted fw-normal small">
                                            — Member ले देख्दैन, admin को internal memo मात्र
                                        </span>
                                    </label>
                                    <textarea name="admin_note" class="form-control" rows="3"
                                        placeholder="Admin को आन्तरिक टिप्पणी — member देख्दैन..."
                                        style="border-color:#ffc107;background:#fffdf0;"
                                    ><?php echo htmlspecialchars($viewFeedback['admin_note'] ?? ''); ?></textarea>
                                </div>

                                <!-- Document Upload — admin ले attach गर्छ -->
                                <div class="mb-4">
                                    <label class="form-label fw-semibold">
                                        <i class="fas fa-paperclip me-1 text-primary"></i>
                                        Document संलग्न गर्नुहोस्
                                        <span class="text-muted fw-normal small">
                                            — PDF, Word, Image (max 5MB)
                                        </span>
                                    </label>
                                    <input type="file" name="admin_attachment"
                                           class="form-control"
                                           accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif">
                                    <?php if (!empty($viewFeedback['admin_attachment'])): ?>
                                    <div class="form-text text-primary">
                                        <i class="fas fa-info-circle me-1"></i>
                                        हाल संलग्न: <strong><?php echo htmlspecialchars(attachmentName($viewFeedback['admin_attachment'])); ?></strong>
                                        — नयाँ file upload गर्नुभयो भने पुरानो replace हुन्छ।
                                    </div>
                                    <?php else: ?>
                                    <div class="form-text text-muted">
                                        अहिले कुनै document संलग्न छैन। upload गर्न file छान्नुहोस्।
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary px-4">
                                        <i class="fas fa-save me-1"></i>अपडेट गर्नुहोस्
                                    </button>
                                    <a href="feedbacks.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left me-1"></i>फिर्ता
                                    </a>
                                </div>
                            </form>

                        </div>
                    </div>

                    <!-- Delete section -->
                    <div class="mt-3 text-end">
                        <form method="POST" style="display:inline;"
                              onsubmit="return confirm('यो feedback पूरै मेटाउने? (यो कार्य फिर्ता हुँदैन।)');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id"     value="<?php echo $viewFeedback['id']; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                <i class="fas fa-trash me-1"></i>यो Feedback मेटाउनुहोस्
                            </button>
                        </form>
                    </div>
                </div><!-- /col right -->

            </div><!-- /row -->
        </div><!-- /card-body -->
    </div><!-- /card -->

    <?php else: ?>
    <!-- ════════════════════════════════════════════
         Feedback List Table
    ════════════════════════════════════════════ -->

    <!-- ── Filter Bar ── -->
    <div class="adm-filter-bar no-print">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3 col-6">
                <label>स्थिति</label>
                <select name="status" class="form-select form-select-sm" onchange="this.closest('form').submit()">
                    <option value="">सबै स्थिति</option>
                    <option value="pending"  <?php echo $filterStatus==='pending'?'selected':''; ?>>⏳ पेन्डिङ</option>
                    <option value="reviewed" <?php echo $filterStatus==='reviewed'?'selected':''; ?>>👁 हेरिएको</option>
                    <option value="resolved" <?php echo $filterStatus==='resolved'?'selected':''; ?>>✅ समाधान</option>
                </select>
            </div>
            <div class="col-md-3 col-6">
                <label>प्रकार</label>
                <select name="type" class="form-select form-select-sm" onchange="this.closest('form').submit()">
                    <option value="">सबै प्रकार</option>
                    <option value="feedback"   <?php echo $filterType==='feedback'?'selected':''; ?>>Feedback</option>
                    <option value="suggestion" <?php echo $filterType==='suggestion'?'selected':''; ?>>सुझाव</option>
                    <option value="complaint"  <?php echo $filterType==='complaint'?'selected':''; ?>>गुनासो</option>
                    <option value="inquiry"    <?php echo $filterType==='inquiry'?'selected':''; ?>>जिज्ञासा</option>
                </select>
            </div>
            <div class="col-md-4 col-12">
                <label>खोज्नुहोस्</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($filterSearch); ?>" placeholder="नाम, ट्र्याकिङ ID, फोन...">
                </div>
            </div>
            <div class="col-md-2 col-6">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-search me-1"></i>खोज</button>
                <?php if ($filterStatus||$filterType||$filterSearch !== ''): ?><a href="feedbacks.php" class="btn btn-outline-secondary btn-sm w-100 mt-1"><i class="fas fa-times me-1"></i>हटाउनुहोस्</a><?php endif; ?>
            </div>
        </form>
    </div>

    <!-- ── Table ── -->
    <div class="card border-0 shadow-sm" style="border-radius:10px;overflow:hidden;">
        <div class="tbl-header-bar no-print">
            <h6><i class="fas fa-comments me-2 text-primary"></i>Feedback सूची</h6>
            <span class="result-count-badge"><?php echo count($feedbacks); ?> रेकर्ड</span>
        </div>
        <div class="table-responsive admin-table-card">
            <table class="table-hover table app-table align-middle mb-0 table-responsive-stack">
                <thead>
                        <tr>
                            <th style="min-width:140px;">ट्र्याकिङ नम्बर</th>
                            <th>नाम</th>
                            <th>प्रकार</th>
                            <th style="max-width:200px;">विषय / सन्देश</th>
                            <th>फोन</th>
                            <th>मिति</th>
                            <th>अवस्था</th>
                            <th style="width:120px;">कार्य</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($feedbacks as $fb): ?>
                        <tr class="<?php echo $fb['status'] === 'pending' ? 'table-warning-subtle' : ''; ?>">
                            <!-- Tracking ID -->
                            <td>
                                <?php
                                    $fbTrack = !empty($fb['tracking_id'])
                                        ? $fb['tracking_id']
                                        : 'FBK-' . str_pad($fb['id'], 6, '0', STR_PAD_LEFT);
                                ?>
                                <code class="text-success small" style="letter-spacing:0.5px;">
                                    <?php echo htmlspecialchars($fbTrack); ?>
                                </code>
                            </td>

                            <!-- Name -->
                            <td>
                                <span class="fw-semibold"><?php echo htmlspecialchars($fb['name']); ?></span>
                                <?php if ($fb['member_id']): ?>
                                <br><small class="text-muted">ID: <?php echo htmlspecialchars($fb['member_id']); ?></small>
                                <?php endif; ?>
                            </td>

                            <!-- Type -->
                            <td><?php echo fbTypeBadge($fb['type']); ?></td>

                            <!-- Subject / message preview -->
                            <td class="small text-muted"
                                style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                title="<?php echo htmlspecialchars($fb['message']); ?>">
                                <?php echo htmlspecialchars(truncateText($fb['subject'] ?: $fb['message'], 50)); ?>
                            </td>

                            <!-- Phone -->
                            <td class="small"><?php echo htmlspecialchars($fb['phone']); ?></td>

                            <!-- Date -->
                            <td class="small text-muted nowrap">
                                <?php echo formatNepaliDate($fb['created_at']); ?>
                            </td>

                            <!-- Status + icons -->
                            <td>
                                <?php echo fbBadge($fb['status']); ?>
                                <div class="mt-1 d-flex flex-wrap gap-1">
                                    <?php if (!empty($fb['admin_reply'])): ?>
                                    <span class="badge bg-success bg-opacity-75" style="font-size:0.65rem;" title="Admin जवाफ छ">
                                        <i class="fas fa-reply me-1"></i>जवाफ
                                    </span>
                                    <?php endif; ?>
                                    <?php if (!empty($fb['admin_note'])): ?>
                                    <span class="badge" style="background:#d4900a;font-size:0.65rem;" title="Admin Note छ">
                                        <i class="fas fa-sticky-note me-1"></i>Note
                                    </span>
                                    <?php endif; ?>
                                    <?php if (!empty($fb['admin_attachment'])): ?>
                                    <span class="badge bg-primary bg-opacity-75" style="font-size:0.65rem;" title="Document संलग्न छ">
                                        <i class="fas fa-paperclip me-1"></i>Doc
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- Actions -->
                            <td>
                                <div class="adm-action-icons">
                                    <a href="?view=<?php echo $fb['id']; ?>"
                                       class="adm-icon-btn adm-icon-btn--view" title="विवरण हेर्नुहोस् / अपडेट गर्नुहोस्" aria-label="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <form method="POST" class="adm-icon-form"
                                          onsubmit="return confirm('यो feedback मेटाउने?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id"     value="<?php echo $fb['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <button type="submit" class="adm-icon-btn adm-icon-btn--delete" title="मेटाउनुहोस्" aria-label="Delete">
                                            <i class="fas fa-trash-can"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <?php if (empty($feedbacks)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-5">
                                <i class="fas fa-inbox fa-3x mb-3 d-block opacity-25"></i>
                                कुनै feedback उपलब्ध छैन।
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>

</div><!-- /container-fluid -->

<?php require_once 'includes/admin-footer.php'; ?>
