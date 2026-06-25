<?php
/**
 * Admin: KYC आवेदन व्यवस्थापन — kyc-applications.php
 * =====================================================
 * feedbacks.php pattern: ?view=ID → full-page detail + edit form।
 * Modal पूर्ण रूपले हटाइयो।
 */
$pageTitle   = 'केवाइसी आवेदन व्यवस्थापन';
$currentPage = 'kyc';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/includes/admin-request-view.php';
require_once '../includes/member-auth.php'; /* adminGenerateMemberIdCard() को लागि */
require_once __DIR__ . '/../includes/request-status-history.php';
require_once __DIR__ . '/../includes/auth-roles.php';
/* RBAC: staff hercha matra; mutate admin+ matra */
if ($_SERVER['REQUEST_METHOD'] === 'POST') { require_role('admin'); checkCSRF(); }

/* admin-header मा contact_messages आदि fail भए $db null हुन सक्छ — यहाँ PDO पक्का गर्नुहोस् */
if (!($db instanceof PDO) && function_exists('getDB')) {
    try {
        $db = getDB();
    } catch (Throwable $e) {
        $db = null;
    }
}

/* ── Auto-ALTER: admin_attachment column थप्ने — MySQL 5.7+ compatible ── */
if ($db instanceof PDO) {
    safeAddColumn($db, 'kyc_applications', 'admin_attachment', "VARCHAR(500) DEFAULT '' COMMENT 'Admin reply मा संलग्न file'");
    safeAddColumn($db, 'kyc_applications', 'updated_at', "TIMESTAMP NULL DEFAULT NULL");
    safeAddColumn($db, 'kyc_applications', 'want_id_card', "TINYINT DEFAULT 0 COMMENT 'Member le ID card request gareko cha?'");
    safeAddColumn($db, 'kyc_applications', 'member_id', "VARCHAR(50) NULL COMMENT 'KYC सदस्यता नम्बर'");
    safeAddColumn($db, 'kyc_applications', 'national_id_number', "VARCHAR(50) NULL");
    safeAddColumn($db, 'kyc_applications', 'national_id_card', "VARCHAR(255) NULL");
    safeAddColumn($db, 'kyc_applications', 'photo_quality_score', "TINYINT UNSIGNED NULL");
    safeAddColumn($db, 'kyc_applications', 'risk_category', "ENUM('low','medium','high') DEFAULT 'medium'");
    safeAddColumn($db, 'kyc_applications', 'kyc_verified_at', "DATETIME NULL");
    safeAddColumn($db, 'kyc_applications', 'risk_review_due_at', "DATE NULL");
    safeAddColumn($db, 'kyc_applications', 'risk_review_status', "ENUM('normal','due_review') DEFAULT 'normal'");
    safeAddColumn($db, 'kyc_applications', 'family_details_json', "TEXT NULL");
    safeAddColumn($db, 'kyc_applications', 'aml_details_json', "LONGTEXT NULL");
    /* Existing DB हरुमा enum मा incomplete थप्ने */
    try {
        $db->exec("ALTER TABLE kyc_applications MODIFY COLUMN status ENUM('pending','approved','rejected','incomplete','partial') DEFAULT 'pending'");
    } catch (Throwable $e) { /* ignore */ }
}

$kycListStatuses = ['pending', 'approved', 'rejected', 'incomplete', 'partial'];
if ($db instanceof PDO) {
    ensureRequestStatusHistoryTable($db);
}

/* ── Bulk Import (Excel CSV) ── */
if (isset($_POST['import_kyc_csv'])) {
    $file = $_FILES['kyc_csv_file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        setFlash('error', 'CSV file छान्नुहोस्।');
        redirect('kyc-applications.php');
    }
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv'], true)) {
        setFlash('error', 'Excel file लाई CSV (UTF-8) मा Save गरेर मात्र upload गर्नुहोस्।');
        redirect('kyc-applications.php');
    }

    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        setFlash('error', 'CSV file पढ्न सकिएन।');
        redirect('kyc-applications.php');
    }

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        setFlash('error', 'CSV खाली छ।');
        redirect('kyc-applications.php');
    }
    $header = array_map(fn($h) => strtolower(trim((string)$h)), $header);
    $required = ['full_name','mobile'];
    foreach ($required as $rk) {
        if (!in_array($rk, $header, true)) {
            fclose($handle);
            setFlash('error', "CSV header मा '{$rk}' अनिवार्य छ। Sample file प्रयोग गर्नुहोस्।");
            redirect('kyc-applications.php');
        }
    }
    $idx = array_flip($header);

    $hasTrackingId = false;
    try {
        $colChk = $db->query("SHOW COLUMNS FROM kyc_applications LIKE 'tracking_id'");
        $hasTrackingId = $colChk && $colChk->fetch() !== false;
    } catch (Throwable $e) {}

    $insertCols = [
        'member_id','full_name','mobile','email','citizenship_no','national_id_number','dob_bs','gender',
        'permanent_address','occupation','account_type','branch','status','remarks','want_id_card'
    ];
    if ($hasTrackingId) array_unshift($insertCols, 'tracking_id');
    $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
    $sql = "INSERT INTO kyc_applications (" . implode(',', $insertCols) . ") VALUES ($placeholders)";
    $ins = $db->prepare($sql);
    $dupByMemberId = $db->prepare("SELECT id FROM kyc_applications
                                   WHERE member_id=? AND status IN ('pending','approved','incomplete','partial')
                                   LIMIT 1");
    $dupByNameMobile = $db->prepare("SELECT id FROM kyc_applications WHERE full_name=? AND mobile=? LIMIT 1");

    $ok = 0; $skip = 0; $rowNo = 1;
    $allowedStatuses = ['pending','approved','rejected','incomplete','partial'];
    while (($row = fgetcsv($handle)) !== false) {
        $rowNo++;
        if (!is_array($row) || count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) continue;
        $val = fn($key) => isset($idx[$key], $row[$idx[$key]]) ? trim((string)$row[$idx[$key]]) : '';

        $memberId = strtoupper(trim(clean_text($val('member_id'))));
        $fullName = clean_text($val('full_name'));
        $mobile   = preg_replace('/[^0-9]/', '', $val('mobile'));
        if ($fullName === '' || $mobile === '' || strlen($mobile) < 7) { $skip++; continue; }

        if ($memberId !== '') {
            $dupByMemberId->execute([$memberId]);
            if ($dupByMemberId->fetch()) { $skip++; continue; }
        } else {
            $dupByNameMobile->execute([$fullName, $mobile]);
            if ($dupByNameMobile->fetch()) { $skip++; continue; }
        }

        $status = strtolower($val('status'));
        if (!in_array($status, $allowedStatuses, true)) $status = 'pending';
        $wantId = in_array(strtolower($val('want_id_card')), ['1','yes','y','true'], true) ? 1 : 0;

        $values = [
            $memberId,
            $fullName,
            $mobile,
            clean_text($val('email')),
            clean_text($val('citizenship_no')),
            clean_text($val('national_id_number')),
            clean_text($val('dob_bs')),
            clean_text($val('gender')),
            clean_text($val('permanent_address')),
            clean_text($val('occupation')),
            clean_text($val('account_type')),
            clean_text($val('branch')),
            $status,
            clean_text($val('remarks')),
            $wantId
        ];
        if ($hasTrackingId) {
            $trackingId = 'KYC-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid((string)$rowNo, true)), 0, 6));
            array_unshift($values, $trackingId);
        }
        try {
            $ins->execute($values);
            $ok++;
        } catch (Throwable $e) {
            $skip++;
        }
    }
    fclose($handle);
    setFlash('success', "KYC bulk import सम्पन्न भयो। सफल: {$ok}, Skip: {$skip}");
    redirect('kyc-applications.php');
}

/* ── Helper: KYC approve भएमा ID card auto-generate गर्ने ── */
function kycAutoGenerateIdCard(PDO $db, int $kycId, string $kycEmail, string $kycMobile): void {
    try {
        /* member_auth.php include भएको अपेक्षा */
        if (!function_exists('adminGenerateMemberIdCard')) return;
        /* want_id_card = 1 छ कि छैन */
        $row = $db->prepare("SELECT want_id_card FROM kyc_applications WHERE id=?");
        $row->execute([$kycId]);
        $kyc = $row->fetch(PDO::FETCH_ASSOC);
        if (!$kyc || empty($kyc['want_id_card'])) return;
        /* Email वा Mobile बाट member खोज्ने */
        $mem = null;
        if ($kycEmail) {
            $ms = $db->prepare("SELECT id FROM members WHERE email=? AND is_active=1 LIMIT 1");
            $ms->execute([strtolower(trim($kycEmail))]);
            $mem = $ms->fetch(PDO::FETCH_ASSOC);
        }
        if (!$mem && $kycMobile) {
            $ms = $db->prepare("SELECT id FROM members WHERE phone=? AND is_active=1 LIMIT 1");
            $ms->execute([preg_replace('/[^0-9]/', '', $kycMobile)]);
            $mem = $ms->fetch(PDO::FETCH_ASSOC);
        }
        if ($mem) {
            adminGenerateMemberIdCard((int)$mem['id']);
        }
    } catch (\Throwable $e) { /* Silent — ID card नबने पनि approval block नहोस् */ }
}

/* ─── Status Update ─── */
if (isset($_POST['update_status'])) {
    $id      = (int)$_POST['id'];
    $allowedStatuses = ['pending','approved','rejected','incomplete','partial'];
    $status  = clean_text($_POST['status']);
    if (!in_array($status, $allowedStatuses, true)) $status = 'pending';
    $remarks = clean_text($_POST['remarks'] ?? '');
    $riskCategory = strtolower(trim((string)($_POST['risk_category'] ?? 'medium')));
    if (!in_array($riskCategory, ['low','medium','high'], true)) $riskCategory = 'medium';
    $editMemberId = strtoupper(trim(clean_text($_POST['member_id'] ?? '')));
    $editMobile = preg_replace('/[^0-9]/', '', (string)($_POST['mobile'] ?? ''));
    $editEmail  = strtolower(trim((string)($_POST['email'] ?? '')));
    $newFile = adminUploadFile('admin_attachment');
    $oldStatus = '';
    $notifyOptIn = !empty($_POST['notify_member']) && $_POST['notify_member'] === '1';
    $notifyOutcome = [
        'admin_chose' => $notifyOptIn,
        'email' => ['status' => 'not_attempted', 'reason' => '', 'to' => ''],
        'sms'   => ['status' => 'not_attempted', 'reason' => '', 'to' => ''],
    ];
    try {
        $os = $db->prepare("SELECT status FROM kyc_applications WHERE id=? LIMIT 1");
        $os->execute([$id]);
        $oldStatus = (string)($os->fetchColumn() ?: '');
    } catch (Exception $e) {}

    if ($editMemberId === '') {
        setFlash('error', 'Member ID खाली राख्न मिल्दैन।');
        redirect('kyc-applications.php?view=' . $id);
    }
    if ($editMobile !== '' && (strlen($editMobile) < 7 || strlen($editMobile) > 15)) {
        setFlash('error', 'मोबाइल नम्बर मान्य छैन।');
        redirect('kyc-applications.php?view=' . $id);
    }
    if ($editEmail !== '' && !filter_var($editEmail, FILTER_VALIDATE_EMAIL)) {
        setFlash('error', 'इमेल ठेगाना मान्य छैन।');
        redirect('kyc-applications.php?view=' . $id);
    }

    // एउटै member_id को active KYC duplicate रोक्ने
    try {
        $dup = $db->prepare("SELECT id FROM kyc_applications
                             WHERE member_id=? AND id<>?
                               AND status IN ('pending','approved','incomplete','partial')
                             LIMIT 1");
        $dup->execute([$editMemberId, $id]);
        if ($dup->fetch(PDO::FETCH_ASSOC)) {
            setFlash('error', 'यो Member ID अर्को active KYC मा प्रयोग भएको छ।');
            redirect('kyc-applications.php?view=' . $id);
        }
    } catch (Throwable $e) {}

    try {
        if ($newFile) {
            $stmt = $db->prepare("UPDATE kyc_applications
                                  SET status=?, remarks=?, member_id=?, mobile=?, email=?, risk_category=?, admin_attachment=?, updated_at=NOW(),
                                      kyc_verified_at = CASE WHEN ?='approved' AND kyc_verified_at IS NULL THEN NOW() WHEN ?<>'approved' THEN NULL ELSE kyc_verified_at END,
                                      risk_review_due_at = CASE
                                          WHEN ?='approved' THEN DATE_ADD(DATE(COALESCE(kyc_verified_at, NOW())), INTERVAL (CASE WHEN ?='high' THEN 1 WHEN ?='low' THEN 3 ELSE 2 END) YEAR)
                                          ELSE NULL END,
                                      risk_review_status = CASE
                                          WHEN ?='approved' THEN (CASE
                                              WHEN DATE_ADD(DATE(COALESCE(kyc_verified_at, NOW())), INTERVAL (CASE WHEN ?='high' THEN 1 WHEN ?='low' THEN 3 ELSE 2 END) YEAR) <= CURDATE()
                                              THEN 'due_review' ELSE 'normal' END)
                                          ELSE 'normal' END
                                  WHERE id=?");
            $stmt->execute([$status, $remarks, $editMemberId, $editMobile ?: null, $editEmail ?: null, $riskCategory, $newFile, $status, $status, $status, $riskCategory, $riskCategory, $status, $riskCategory, $riskCategory, $id]);
        } else {
            $stmt = $db->prepare("UPDATE kyc_applications
                                  SET status=?, remarks=?, member_id=?, mobile=?, email=?, risk_category=?, updated_at=NOW(),
                                      kyc_verified_at = CASE WHEN ?='approved' AND kyc_verified_at IS NULL THEN NOW() WHEN ?<>'approved' THEN NULL ELSE kyc_verified_at END,
                                      risk_review_due_at = CASE
                                          WHEN ?='approved' THEN DATE_ADD(DATE(COALESCE(kyc_verified_at, NOW())), INTERVAL (CASE WHEN ?='high' THEN 1 WHEN ?='low' THEN 3 ELSE 2 END) YEAR)
                                          ELSE NULL END,
                                      risk_review_status = CASE
                                          WHEN ?='approved' THEN (CASE
                                              WHEN DATE_ADD(DATE(COALESCE(kyc_verified_at, NOW())), INTERVAL (CASE WHEN ?='high' THEN 1 WHEN ?='low' THEN 3 ELSE 2 END) YEAR) <= CURDATE()
                                              THEN 'due_review' ELSE 'normal' END)
                                          ELSE 'normal' END
                                  WHERE id=?");
            $stmt->execute([$status, $remarks, $editMemberId, $editMobile ?: null, $editEmail ?: null, $riskCategory, $status, $status, $status, $riskCategory, $riskCategory, $status, $riskCategory, $riskCategory, $id]);
        }
        /* Member लाई status notification — email/SMS */
        try {
            $nRow = $db->prepare("SELECT full_name, email, phone, mobile, tracking_id FROM kyc_applications WHERE id=?");
            $nRow->execute([$id]);
            $nData = $nRow->fetch();
            if ($nData) {
                $r = sendMemberStatusUpdate('kyc',
                    $nData['email'] ?? '', $nData['phone'] ?? $nData['mobile'] ?? '', $nData['full_name'] ?? '',
                    $status, $remarks, $nData['tracking_id'] ?? '', !$notifyOptIn);
                if (is_array($r)) {
                    $notifyOutcome['email'] = $r['email'] ?? $notifyOutcome['email'];
                    $notifyOutcome['sms']   = $r['sms']   ?? $notifyOutcome['sms'];
                }
                /* KYC approved + want_id_card = 1 → ID card auto-generate */
                if ($status === 'approved') {
                    kycAutoGenerateIdCard($db, $id,
                        $nData['email'] ?? '',
                        $nData['mobile'] ?? $nData['phone'] ?? '');
                }
            }
        } catch (Exception $e) {}
        $notifySent = ($notifyOutcome['email']['status'] === 'sent') || ($notifyOutcome['sms']['status'] === 'sent');
        try {
            logRequestStatusHistory(
                $db,
                'kyc',
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
        setFlash('success', 'स्थिति अपडेट गरियो।');
    } catch (Exception $e) {
        setFlash('error', 'त्रुटि भयो।');
    }
    redirect('kyc-applications.php' . ($id ? '?view=' . $id : ''));
}

/* ─── Delete ─── */
if (isset($_POST['delete'])) {
    $id = (int)($_POST['delete_id'] ?? 0);
    try { $db->prepare("DELETE FROM kyc_applications WHERE id=?")->execute([$id]); setFlash('success', 'आवेदन मेटाइयो।'); } catch (Exception $e) {}
    redirect('kyc-applications.php');
}

/* ─── Quick Status ─── */
if (isset($_POST['quick_status'])) {
    $qid = (int)($_POST['quick_id'] ?? 0);
    $allowed = ['pending','approved','rejected','incomplete','partial'];
    $qst = in_array($_POST['quick_status_val'] ?? '', $allowed) ? $_POST['quick_status_val'] : 'pending';
    $oldStatus = '';
    $notifySent = false;
    try {
        $os = $db->prepare("SELECT status FROM kyc_applications WHERE id=? LIMIT 1");
        $os->execute([$qid]);
        $oldStatus = (string)($os->fetchColumn() ?: '');
    } catch (Exception $e) {}
    try {
        $db->prepare("UPDATE kyc_applications
                      SET status=?, updated_at=NOW(),
                          kyc_verified_at = CASE WHEN ?='approved' AND kyc_verified_at IS NULL THEN NOW() WHEN ?<>'approved' THEN NULL ELSE kyc_verified_at END,
                          risk_review_due_at = CASE
                              WHEN ?='approved' THEN DATE_ADD(DATE(COALESCE(kyc_verified_at, NOW())), INTERVAL (CASE WHEN risk_category='high' THEN 1 WHEN risk_category='low' THEN 3 ELSE 2 END) YEAR)
                              ELSE NULL END,
                          risk_review_status = CASE
                              WHEN ?='approved' THEN (CASE
                                  WHEN DATE_ADD(DATE(COALESCE(kyc_verified_at, NOW())), INTERVAL (CASE WHEN risk_category='high' THEN 1 WHEN risk_category='low' THEN 3 ELSE 2 END) YEAR) <= CURDATE()
                                  THEN 'due_review' ELSE 'normal' END)
                              ELSE 'normal' END
                      WHERE id=?")->execute([$qst, $qst, $qst, $qst, $qst, $qid]);
        try {
            $nr = $db->prepare("SELECT full_name, email, mobile, tracking_id FROM kyc_applications WHERE id=?");
            $nr->execute([$qid]); $nd = $nr->fetch();
            if ($nd) {
                sendMemberStatusUpdate('kyc', $nd['email']??'', $nd['mobile']??'', $nd['full_name']??'', $qst, '', $nd['tracking_id']??'');
                $notifySent = true;
                /* KYC approved + want_id_card = 1 → ID card auto-generate */
                if ($qst === 'approved') {
                    kycAutoGenerateIdCard($db, $qid, $nd['email'] ?? '', $nd['mobile'] ?? '');
                }
            }
        } catch (Exception $e) {}
        try {
            logRequestStatusHistory(
                $db,
                'kyc',
                $qid,
                $oldStatus !== '' ? $oldStatus : null,
                $qst,
                '',
                $notifySent,
                (int)($_SESSION['admin_id'] ?? 0),
                (string)($_SESSION['admin_name'] ?? 'Admin')
            );
        } catch (Exception $e) {}
        setFlash('success', 'KYC स्थिति परिवर्तन गरियो।');
    } catch (Exception $e) { setFlash('error', 'त्रुटि भयो।'); }
    $redKycSt = $_GET['status'] ?? '';
    if ($redKycSt !== '' && !in_array($redKycSt, $kycListStatuses, true)) {
        $redKycSt = '';
    }
    $qs = http_build_query([
        'status' => $redKycSt,
        'search' => mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200, 'UTF-8'),
        'page'   => max(1, (int)($_GET['page'] ?? 1)),
    ]);
    redirect('kyc-applications.php?' . $qs);
}

/* ─── Filter + Pagination ─── */
$status_filter = $_GET['status'] ?? '';
if ($status_filter !== '' && !in_array($status_filter, $kycListStatuses, true)) {
    $status_filter = '';
}
$search  = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200, 'UTF-8');
$where   = "1=1"; $params2 = [];
if ($status_filter) { $where .= " AND status = ?"; $params2[] = $status_filter; }
if ($search !== '') {
    $where .= " AND (member_id LIKE ? OR full_name LIKE ? OR full_name_en LIKE ? OR mobile LIKE ? OR citizenship_no LIKE ? OR national_id_number LIKE ? OR tracking_id LIKE ?)";
    $t = "%$search%"; $params2 = array_merge($params2, [$t,$t,$t,$t,$t,$t,$t]);
}
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 15;
$offset = ($page - 1) * $limit;

try {
    $cntStmt2 = $db->prepare("SELECT COUNT(*) FROM kyc_applications WHERE $where"); $cntStmt2->execute($params2); $total = $cntStmt2->fetchColumn();
    $totalPages    = ceil($total / $limit);
    $stmt2 = $db->prepare("SELECT * FROM kyc_applications WHERE $where ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt2->execute(array_merge($params2, [$limit, $offset])); $applications = $stmt2->fetchAll();
} catch (Exception $e) { $applications = []; $total = 0; $totalPages = 0; }

$pendingCount    = core_safe_count($db, "SELECT COUNT(*) FROM kyc_applications WHERE status='pending'", '[kyc-applications pending]');
$approvedCount   = core_safe_count($db, "SELECT COUNT(*) FROM kyc_applications WHERE status='approved'", '[kyc-applications approved]');
$rejectedCount   = core_safe_count($db, "SELECT COUNT(*) FROM kyc_applications WHERE status='rejected'", '[kyc-applications rejected]');
$incompleteCount = core_safe_count($db, "SELECT COUNT(*) FROM kyc_applications WHERE status='incomplete'", '[kyc-applications incomplete]');
$partialCount    = core_safe_count($db, "SELECT COUNT(*) FROM kyc_applications WHERE status='partial'", '[kyc-applications partial]');

/* ─── Single view ─── */
$viewApp = null;
if (isset($_GET['view'])) {
    $s = $db->prepare("SELECT * FROM kyc_applications WHERE id=?");
    $s->execute([(int)$_GET['view']]);
    $viewApp = $s->fetch();
    if (!$viewApp) { setFlash('error', 'आवेदन फेला परेन।'); redirect('kyc-applications.php'); }
}
$kycHistory = [];
if ($viewApp && !empty($viewApp['id'])) {
    try { $kycHistory = fetchRequestStatusHistory($db, 'kyc', (int)$viewApp['id'], 40); } catch (Exception $e) { $kycHistory = []; }
}

$statusClass = ['pending'=>'warning','approved'=>'success','rejected'=>'danger','incomplete'=>'secondary','partial'=>'info'];
$statusLabel = ['pending'=>'पेन्डिङ','approved'=>'स्वीकृत','rejected'=>'अस्वीकृत','incomplete'=>'अपूर्ण','partial'=>'आंशिक'];

/* ─── Page Header ─── */
if ($viewApp) {
    $trackId = $viewApp['tracking_id'] ?? 'KYC-' . str_pad($viewApp['id'], 6, '0', STR_PAD_LEFT);
    echo adminPageHeader('KYC आवेदन विवरण', 'fa-user-check',
        'Tracking: ' . $trackId,
        adminBackBtn('kyc-applications.php', 'KYC सूचीमा फर्किनुहोस्'));
} else {
    echo adminPageHeader('केवाइसी आवेदन व्यवस्थापन', 'fa-user-check',
        'सदस्यहरूको KYC अद्यावधिक आवेदनहरू — समीक्षा र स्थिति अपडेट',
        adminStatLink('?status=pending', 'danger', 'पेन्डिङ', $pendingCount)
        . ' ' . adminStatLink('kyc-applications.php', 'secondary', 'जम्मा', $total));
}

$flash = getFlash(); if ($flash) echo adminAlert($flash['type'] === 'success' ? 'success' : 'danger', $flash['message']);

/* ═══════════════════════════════════
   SINGLE DETAIL VIEW
   ═══════════════════════════════════ */
if ($viewApp):
    $sc = $statusClass[$viewApp['status']] ?? 'secondary';
    $sl = $statusLabel[$viewApp['status']] ?? $viewApp['status'];
    $trackId = $viewApp['tracking_id'] ?? 'KYC-' . str_pad($viewApp['id'], 6, '0', STR_PAD_LEFT);
?>
<div class="card shadow-sm mb-4 arv-legacy-detail">
    <div class="card-header gradient-card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="fas fa-user-check me-2"></i>KYC आवेदन विवरण
            <code class="apt-track-chip">
                <?php echo htmlspecialchars($trackId); ?>
            </code>
        </h5>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-<?php echo $sc; ?> fs-6"><?php echo $sl; ?></span>
            <a href="print-form.php?type=kyc&id=<?php echo (int)$viewApp['id']; ?>" target="_blank"
               class="btn btn-light btn-sm"><i class="fas fa-print me-1"></i>Print Form</a>
        </div>
    </div>

    <div class="card-body">
        <div class="row g-4">

            <!-- ── LEFT: KYC Details ── -->
            <div class="col-lg-7" id="kycLeftDetailsCol">
                <!-- Photo -->
                <?php if (!empty($viewApp['photo'])): ?>
                <div class="text-center mb-3">
                    <img src="../<?php echo htmlspecialchars($viewApp['photo']); ?>"
                         alt="फोटो" class="img-thumbnail kyc-photo-main">
                </div>
                <?php endif; ?>

                <div id="kycInfoTabs" class="mb-2"></div>

                <!-- व्यक्तिगत जानकारी -->
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-user"></i>व्यक्तिगत जानकारी</div>
                    <table class="table adm-detail-table">
                        <tr><th>पूरा नाम</th>
                            <td><strong><?php echo htmlspecialchars($viewApp['full_name'] ?? '—'); ?></strong></td></tr>
                        <tr><th>सदस्यता नं. (Member ID)</th>
                            <td><code class="text-primary fw-bold"><?php echo htmlspecialchars($viewApp['member_id'] ?? '—'); ?></code></td></tr>
                        <tr><th>Full Name (EN)</th>
                            <td><?php echo htmlspecialchars($viewApp['full_name_en'] ?: '—'); ?></td></tr>
                        <tr><th>जन्म मिति</th>
                            <td><?php echo htmlspecialchars($viewApp['dob_bs'] ?: '—'); ?></td></tr>
                        <tr><th>लिङ्ग</th>
                            <td><?php echo htmlspecialchars($viewApp['gender'] ?: '—'); ?></td></tr>
                        <tr><th>मोबाइल</th>
                            <td><a href="tel:<?php echo htmlspecialchars($viewApp['mobile'] ?? ''); ?>" class="text-decoration-none fw-semibold"><?php echo htmlspecialchars($viewApp['mobile'] ?? '—'); ?></a></td></tr>
                        <tr><th>इमेल</th>
                            <td><?php echo $viewApp['email'] ? '<a href="mailto:'.htmlspecialchars($viewApp['email']).'" class="text-decoration-none">'.htmlspecialchars($viewApp['email']).'</a>' : '—'; ?></td></tr>
                        <tr><th>नागरिकता नं.</th>
                            <td><code class="text-dark"><?php echo htmlspecialchars($viewApp['citizenship_no'] ?? '—'); ?></code></td></tr>
                        <tr><th>नागरिकता जारी जिल्ला</th>
                            <td><?php echo htmlspecialchars($viewApp['citizenship_issued_place'] ?? '—'); ?></td></tr>
                        <tr><th>नागरिकता जारी मिति</th>
                            <td><?php echo htmlspecialchars($viewApp['citizenship_issued_date'] ?? '—'); ?></td></tr>
                        <tr><th>National ID नं.</th>
                            <td><code class="text-dark"><?php echo htmlspecialchars($viewApp['national_id_number'] ?? '—'); ?></code></td></tr>
                        <tr><th>Risk Category</th>
                            <td>
                                <?php
                                $rk = strtolower(trim((string)($viewApp['risk_category'] ?? 'medium')));
                                $rkLabel = ['low'=>'Low','medium'=>'Medium','high'=>'High'][$rk] ?? 'Medium';
                                $rkClass = $rk === 'high' ? 'bg-danger' : ($rk === 'low' ? 'bg-success' : 'bg-warning text-dark');
                                ?>
                                <span class="badge <?php echo $rkClass; ?>"><?php echo $rkLabel; ?></span>
                            </td></tr>
                        <tr><th>KYC Verified Date</th>
                            <td><?php echo htmlspecialchars($viewApp['kyc_verified_at'] ?? '—'); ?></td></tr>
                        <tr><th>Next Review Due</th>
                            <td><?php echo htmlspecialchars($viewApp['risk_review_due_at'] ?? '—'); ?></td></tr>
                        <tr><th>Review Status</th>
                            <td>
                                <?php $rr = (string)($viewApp['risk_review_status'] ?? 'normal'); ?>
                                <span class="badge <?php echo $rr === 'due_review' ? 'bg-danger' : 'bg-success'; ?>">
                                    <?php echo $rr === 'due_review' ? 'Due Review' : 'Normal'; ?>
                                </span>
                            </td></tr>
                        <tr><th>फोटो गुणस्तर स्कोर</th>
                            <td>
                                <?php
                                $pqs = (int)($viewApp['photo_quality_score'] ?? 0);
                                if ($pqs > 0):
                                    $pClass = $pqs >= 70 ? 'bg-success' : ($pqs >= 50 ? 'bg-warning text-dark' : 'bg-danger');
                                ?>
                                    <span class="badge <?php echo $pClass; ?>"><?php echo $pqs; ?>/100</span>
                                    <?php if ($pqs < 70): ?><span class="small text-muted ms-1">(review)</span><?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr><th>स्थायी ठेगाना</th>
                            <td><?php echo htmlspecialchars($viewApp['permanent_address'] ?: '—'); ?></td></tr>
                        <tr><th>अस्थायी ठेगाना</th>
                            <td><?php echo htmlspecialchars($viewApp['temporary_address'] ?: '—'); ?></td></tr>
                        <tr><th>बुबाको नाम</th>
                            <td><?php echo htmlspecialchars($viewApp['father_name'] ?: '—'); ?></td></tr>
                        <tr><th>आमाको नाम</th>
                            <td><?php echo htmlspecialchars($viewApp['mother_name'] ?: '—'); ?></td></tr>
                        <tr><th>पेशा</th>
                            <td><?php echo htmlspecialchars($viewApp['occupation'] ?: '—'); ?></td></tr>
                        <tr><th>खाता प्रकार</th>
                            <td><?php echo htmlspecialchars($viewApp['account_type'] ?: '—'); ?></td></tr>
                        <tr><th>शाखा</th>
                            <td><?php echo htmlspecialchars(str_replace('_',' ',ucwords($viewApp['branch'] ?? '—'))); ?></td></tr>
                        <tr><th>Tracking ID</th>
                            <td><code class="text-success fw-bold"><?php echo htmlspecialchars($viewApp['tracking_id'] ?? '—'); ?></code></td></tr>
                        <tr><th>डिजिटल ID कार्ड</th>
                            <td>
                                <?php if (!empty($viewApp['want_id_card'])): ?>
                                    <span class="badge kyc-id-requested"><i class="fas fa-id-card me-1"></i>अनुरोध गरिएको — स्वीकृतिमा स्वतः तयार हुनेछ</span>
                                <?php else: ?>
                                    <span class="text-muted kyc-id-not-requested">अनुरोध गरिएको छैन</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr><th>दर्ता मिति</th>
                            <td><?php echo formatNepaliDate($viewApp['created_at'], true); ?></td></tr>
                    </table>
                </div>

                <?php if (!empty($viewApp['citizenship_front']) || !empty($viewApp['citizenship_back']) || !empty($viewApp['national_id_card'])): ?>
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-id-card"></i>नागरिकता / National ID प्रतिलिपिहरू</div>
                    <div class="p-3">
                    <div class="row g-3">
                        <?php if (!empty($viewApp['citizenship_front'])): ?>
                        <div class="col-6 text-center">
                            <a href="../<?php echo htmlspecialchars($viewApp['citizenship_front']); ?>" target="_blank">
                                <img src="../<?php echo htmlspecialchars($viewApp['citizenship_front']); ?>"
                                     class="img-thumbnail mb-1 kyc-doc-thumb" alt="नागरिकता अगाडि">
                                <div class="small text-muted fw-semibold">अगाडि</div>
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($viewApp['citizenship_back'])): ?>
                        <div class="col-6 text-center">
                            <a href="../<?php echo htmlspecialchars($viewApp['citizenship_back']); ?>" target="_blank">
                                <img src="../<?php echo htmlspecialchars($viewApp['citizenship_back']); ?>"
                                     class="img-thumbnail mb-1 kyc-doc-thumb" alt="नागरिकता पछाडि">
                                <div class="small text-muted fw-semibold">पछाडि</div>
                            </a>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($viewApp['national_id_card'])): ?>
                        <div class="col-6 text-center">
                            <a href="../<?php echo htmlspecialchars($viewApp['national_id_card']); ?>" target="_blank">
                                <img src="../<?php echo htmlspecialchars($viewApp['national_id_card']); ?>"
                                     class="img-thumbnail mb-1 kyc-doc-thumb" alt="National ID Card">
                                <div class="small text-muted fw-semibold">National ID</div>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php
                $familyRows = [];
                $familyRaw = trim((string)($viewApp['family_details_json'] ?? ''));
                if ($familyRaw !== '') {
                    $decodedFamily = json_decode($familyRaw, true);
                    if (is_array($decodedFamily)) {
                        foreach ($decodedFamily as $fr) {
                            if (!is_array($fr)) continue;
                            $familyRows[] = [
                                'relation' => trim((string)($fr['relation'] ?? '')),
                                'name' => trim((string)($fr['name'] ?? '')),
                                'phone' => trim((string)($fr['phone'] ?? '')),
                            ];
                        }
                    }
                }
                ?>
                <?php if (!empty($familyRows)): ?>
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-users"></i>पारिवारिक विवरण (Relation-wise)</div>
                    <div class="p-3">
                        <div class="table-responsive">
                            <table class="table table-sm adm-detail-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th class="kyc-col-relation">सम्बन्ध</th>
                                        <th>नाम</th>
                                        <th class="kyc-col-phone">फोन</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($familyRows as $fr): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($fr['relation'] !== '' ? $fr['relation'] : '—'); ?></td>
                                        <td><?php echo htmlspecialchars($fr['name'] !== '' ? $fr['name'] : '—'); ?></td>
                                        <td><?php echo htmlspecialchars($fr['phone'] !== '' ? $fr['phone'] : '—'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php
                $amlRows = [];
                $amlRaw = trim((string)($viewApp['aml_details_json'] ?? ''));
                if ($amlRaw !== '') {
                    $amlDecoded = json_decode($amlRaw, true);
                    if (is_array($amlDecoded)) {
                        $amlLabelMap = [
                            'passport_no' => 'Passport नं.',
                            'pan_no' => 'PAN नं.',
                            'driving_license_no' => 'Driving License नं.',
                            'education_qualification' => 'शैक्षिक योग्यता',
                            'religion' => 'धर्म',
                            'caste' => 'जात',
                            'occupation_location' => 'पेशा/व्यवसाय स्थान',
                            'occupation_business_name' => 'पेशा/व्यवसाय नाम',
                            'business_pan_no' => 'Business PAN नं.',
                            'business_registration_type' => 'Business दर्ता प्रकार',
                            'business_registration_no' => 'Business दर्ता नं.',
                            'business_registration_office' => 'दर्ता निकाय',
                            'business_registration_date_bs' => 'Business दर्ता मिति (BS)',
                            'business_nature' => 'व्यवसाय प्रकृति',
                            'estimated_annual_income' => 'अनुमानित वार्षिक आय',
                            'politically_exposed' => 'PEP स्थिति',
                            'past_crime_declared' => 'अपराध घोषणा',
                            'landlord_name' => 'घरधनीको नाम',
                            'landlord_contact' => 'घरधनी सम्पर्क',
                            'voter_id_card_no' => 'मतदाता परिचयपत्र नं.',
                            'polling_station' => 'मतदान स्थल',
                            'member_purpose' => 'सदस्यता उद्देश्य',
                            'self_other_coop_member' => 'आफू अन्य सहकारी सदस्य',
                            'self_other_coop_details' => 'अन्य सहकारी विवरण',
                            'family_same_coop_member' => 'परिवार यसै सहकारीमा',
                            'family_same_coop_details' => 'परिवार सदस्य विवरण',
                            'annual_family_income' => 'वार्षिक पारिवारिक आम्दानी',
                            'net_worth_details' => 'सम्पत्ति/Net Worth',
                            'annual_debit_credit_estimate' => 'वार्षिक डेबिट/क्रेडिट',
                            'annual_turnover_numbers' => 'वार्षिक कारोबार संख्या',
                            'annual_deposit_estimate' => 'वार्षिक जम्मा अनुमान',
                            'institution_debt_estimate' => 'संस्थासँग ऋणधन अनुमान',
                            'nearest_person_name' => 'नजिकको व्यक्ति नाम',
                            'nearest_person_relation' => 'नजिकको व्यक्ति नाता',
                            'nominee_name' => 'हकवाला नाम',
                            'nominee_dob' => 'हकवाला जन्म मिति',
                            'nominee_citizenship_no' => 'हकवाला नागरिकता नं.',
                            'nominee_relation' => 'हकवालासँग नाता',
                            'nominee_issue_district' => 'हकवाला जारी जिल्ला',
                            'nominee_issue_date' => 'हकवाला जारी मिति',
                            'nominee_permanent_address' => 'हकवाला स्थायी ठेगाना',
                            'nominee_temporary_address' => 'हकवाला अस्थायी ठेगाना',
                            'longitude_latitude' => 'देशान्तर/अक्षांश',
                            'map_resolved_address' => 'Map बाट प्राप्त ठेगाना',
                            'other_attached_docs' => 'अन्य संलग्न कागजात',
                        ];
                        foreach ($amlLabelMap as $k => $lbl) {
                            $v = trim((string)($amlDecoded[$k] ?? ''));
                            if ($v !== '') $amlRows[] = ['label' => $lbl, 'value' => $v];
                        }
                        if (!empty($amlDecoded['income_items']) && is_array($amlDecoded['income_items'])) {
                            $items = [];
                            foreach ($amlDecoded['income_items'] as $it) {
                                $n = trim((string)($it['name'] ?? ''));
                                $a = (float)($it['amount'] ?? 0);
                                if ($n !== '' && $a > 0) $items[] = $n . ' (Rs. ' . number_format($a, 2) . ')';
                            }
                            if (!empty($items)) $amlRows[] = ['label' => 'आय स्रोतहरू', 'value' => implode(', ', $items)];
                        }
                        if (!empty($amlDecoded['expense_items']) && is_array($amlDecoded['expense_items'])) {
                            $items = [];
                            foreach ($amlDecoded['expense_items'] as $it) {
                                $n = trim((string)($it['name'] ?? ''));
                                $a = (float)($it['amount'] ?? 0);
                                if ($n !== '' && $a > 0) $items[] = $n . ' (Rs. ' . number_format($a, 2) . ')';
                            }
                            if (!empty($items)) $amlRows[] = ['label' => 'खर्च स्रोतहरू', 'value' => implode(', ', $items)];
                        }
                        if (isset($amlDecoded['income_total']) || isset($amlDecoded['expense_total'])) {
                            $amlRows[] = [
                                'label' => 'आय/खर्च/अन्तर',
                                'value' => 'Rs. ' . number_format((float)($amlDecoded['income_total'] ?? 0), 2)
                                    . ' / Rs. ' . number_format((float)($amlDecoded['expense_total'] ?? 0), 2)
                                    . ' / Rs. ' . number_format((float)($amlDecoded['net_saving_estimate'] ?? ((float)($amlDecoded['income_total'] ?? 0) - (float)($amlDecoded['expense_total'] ?? 0))), 2),
                            ];
                        }
                    }
                }
                ?>
                <?php if (!empty($amlRows)): ?>
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-shield-halved"></i>AML/KYM थप विवरण</div>
                    <table class="table adm-detail-table">
                        <?php foreach ($amlRows as $row): ?>
                        <tr>
                            <th><?php echo htmlspecialchars($row['label']); ?></th>
                            <td><?php echo htmlspecialchars($row['value']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
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
                <?php if (!empty($kycHistory)): ?>
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-clock-rotate-left"></i>Status / Comment History</div>
                    <div class="p-3">
                        <?php echo arvLogList($kycHistory); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <script>
            (function () {
                var left = document.getElementById('kycLeftDetailsCol');
                var tabs = document.getElementById('kycInfoTabs');
                if (!left || !tabs) return;
                var groups = Array.prototype.slice.call(left.querySelectorAll('.adm-info-group'));
                if (groups.length < 2) return;

                function shortTitle(raw) {
                    var t = String(raw || '').trim();
                    if (!t) return 'Section';
                    return t.length > 20 ? (t.slice(0, 20) + '…') : t;
                }

                var items = groups.map(function (g, idx) {
                    var h = g.querySelector('.adm-info-group-header');
                    var title = h ? h.textContent : ('Section ' + (idx + 1));
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn btn-sm btn-outline-success kyc-mini-tab-btn';
                    btn.textContent = shortTitle(title);
                    btn.title = String(title || '').trim();
                    tabs.appendChild(btn);
                    return { group: g, btn: btn };
                });

                function show(idx) {
                    items.forEach(function (it, i) {
                        var active = i === idx;
                        it.group.style.display = active ? '' : 'none';
                        it.btn.classList.toggle('btn-success', active);
                        it.btn.classList.toggle('btn-outline-success', !active);
                        it.btn.classList.toggle('active', active);
                    });
                }

                items.forEach(function (it, i) {
                    it.btn.addEventListener('click', function () { show(i); });
                });
                show(0);
            })();
            </script>


            <!-- ── RIGHT: Status Update Form ── -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-header gradient-card-header py-2">
                        <i class="fas fa-edit me-2"></i>स्थिति अपडेट / KYC Document
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="update_status" value="1">
                            <input type="hidden" name="id" value="<?php echo $viewApp['id']; ?>">

                            <div class="mb-3">
                                <label class="form-label fw-semibold"><i class="fas fa-circle-dot me-1"></i>KYC अवस्था</label>
                                <select name="status" class="form-select">
                                    <?php foreach ($statusLabel as $v => $l): ?>
                                    <option value="<?php echo $v; ?>" <?php echo $viewApp['status']===$v?'selected':''; ?>><?php echo $l; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-md-12">
                                    <label class="form-label fw-semibold"><i class="fas fa-id-badge me-1 text-primary"></i>Member ID (Edit)</label>
                                    <input type="text" name="member_id" class="form-control"
                                           value="<?php echo htmlspecialchars($viewApp['member_id'] ?? ''); ?>"
                                           placeholder="जस्तै: 1234" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold"><i class="fas fa-phone me-1 text-primary"></i>मोबाइल (Edit)</label>
                                    <input type="text" name="mobile" class="form-control"
                                           value="<?php echo htmlspecialchars($viewApp['mobile'] ?? ''); ?>"
                                           placeholder="98XXXXXXXX">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold"><i class="fas fa-envelope me-1 text-primary"></i>इमेल (Edit)</label>
                                    <input type="email" name="email" class="form-control"
                                           value="<?php echo htmlspecialchars($viewApp['email'] ?? ''); ?>"
                                           placeholder="name@example.com">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label fw-semibold"><i class="fas fa-layer-group me-1 text-primary"></i>Risk Category</label>
                                    <?php $curRisk = strtolower(trim((string)($viewApp['risk_category'] ?? 'medium'))); ?>
                                    <select name="risk_category" class="form-select">
                                        <option value="low" <?php echo $curRisk==='low'?'selected':''; ?>>Low Risk (3 years)</option>
                                        <option value="medium" <?php echo $curRisk==='medium'?'selected':''; ?>>Medium Risk (2 years)</option>
                                        <option value="high" <?php echo $curRisk==='high'?'selected':''; ?>>High Risk (1 year)</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">
                                    <i class="fas fa-reply me-1 text-success"></i>Admin टिप्पणी
                                    <span class="text-muted fw-normal small">— Member ले Tracker मा देख्छ</span>
                                </label>
                                <textarea name="remarks" class="form-control" rows="4"
                                    placeholder="KYC approval/rejection को कारण, थप आवश्यक कागजात..."
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

                            <!-- Admin ले KYC approval letter attach गर्न सक्छ -->
                            <div class="mb-4">
                                <label class="form-label fw-semibold">
                                    <i class="fas fa-paperclip me-1 text-primary"></i>KYC Document/Letter संलग्न
                                    <span class="text-muted fw-normal small">— PDF, Word, Image (max 5MB)</span>
                                </label>
                                <input type="file" name="admin_attachment" class="form-control"
                                       accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                <?php if (!empty($viewApp['admin_attachment'])): ?>
                                <div class="form-text text-primary mt-1">
                                    <i class="fas fa-info-circle me-1"></i>हाल: <strong><?php echo htmlspecialchars(basename($viewApp['admin_attachment'])); ?></strong>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary px-4">
                                    <i class="fas fa-save me-1"></i>अपडेट गर्नुहोस्
                                </button>
                                <a href="kyc-applications.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-1"></i>सूचीमा
                                </a>
                            </div>
                        </form>

                        <hr class="my-3">
                        <form method="POST"
                              onsubmit="return confirm('के तपाईं यो KYC आवेदन स्थायी रूपले मेटाउन निश्चित हुनुहुन्छ?')">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="delete" value="1">
                            <input type="hidden" name="delete_id" value="<?php echo $viewApp['id']; ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                <i class="fas fa-trash me-1"></i>यो KYC आवेदन मेटाउनुहोस्
                            </button>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php else: /* ═══════════ LIST VIEW ═══════════ */ ?>

<!-- ── Stat Mini Row ── -->
<div class="stat-mini-row no-print">
    <a href="kyc-applications.php" class="stat-mini <?php echo $status_filter===''?'active-filter':''; ?>">
        <div class="sm-icon ic-total"><i class="fas fa-id-card"></i></div>
        <div class="sm-val"><?php echo $total; ?></div>
        <div class="sm-lbl">जम्मा KYC</div>
    </a>
    <a href="?status=pending" class="stat-mini <?php echo $status_filter==='pending'?'active-filter':''; ?>">
        <div class="sm-icon ic-pending"><i class="fas fa-clock"></i></div>
        <div class="sm-val"><?php echo $pendingCount; ?></div>
        <div class="sm-lbl">पेन्डिङ</div>
    </a>
    <a href="?status=approved" class="stat-mini <?php echo $status_filter==='approved'?'active-filter':''; ?>">
        <div class="sm-icon ic-approved"><i class="fas fa-check-circle"></i></div>
        <div class="sm-val"><?php echo $approvedCount; ?></div>
        <div class="sm-lbl">स्वीकृत</div>
    </a>
    <a href="?status=rejected" class="stat-mini <?php echo $status_filter==='rejected'?'active-filter':''; ?>">
        <div class="sm-icon ic-rejected"><i class="fas fa-times-circle"></i></div>
        <div class="sm-val"><?php echo $rejectedCount; ?></div>
        <div class="sm-lbl">अस्वीकृत</div>
    </a>
    <a href="?status=incomplete" class="stat-mini <?php echo $status_filter==='incomplete'?'active-filter':''; ?>">
        <div class="sm-icon kyc-sm-icon-muted"><i class="fas fa-file-circle-exclamation"></i></div>
        <div class="sm-val"><?php echo $incompleteCount; ?></div>
        <div class="sm-lbl">अपूर्ण</div>
    </a>
</div>

<!-- ── Bulk Import (Excel CSV) ── -->
<div class="card border-0 shadow-sm mb-3 no-print kyc-rounded-card">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <h6 class="mb-0"><i class="fas fa-file-import me-2 text-primary"></i>KYC Bulk Import (Excel/CSV)</h6>
            <a href="kyc-import-sample.php" class="btn btn-outline-success btn-sm">
                <i class="fas fa-download me-1"></i>Sample Format डाउनलोड
            </a>
        </div>
        <form method="POST" enctype="multipart/form-data" class="row g-2 align-items-end">
            <?php echo csrfField(); ?>
            <input type="hidden" name="import_kyc_csv" value="1">
            <div class="col-md-9">
                <label class="small text-muted mb-1">Excel मा sample भरेर <strong>CSV (UTF-8)</strong> मा Save गरी upload गर्नुहोस्।</label>
                <input type="file" name="kyc_csv_file" class="form-control form-control-sm" accept=".csv" required>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="fas fa-upload me-1"></i>Bulk Import
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── Filter Bar ── -->
<div class="adm-filter-bar no-print">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3 col-6">
            <label>स्थिति</label>
            <select name="status" id="qf_kyc_status" class="form-select form-select-sm">
                <option value="">सबै स्थिति</option>
                <option value="pending"  <?php echo $status_filter==='pending'?'selected':''; ?>>⏳ पेन्डिङ</option>
                <option value="approved" <?php echo $status_filter==='approved'?'selected':''; ?>>✅ स्वीकृत</option>
                <option value="rejected" <?php echo $status_filter==='rejected'?'selected':''; ?>>❌ अस्वीकृत</option>
                <option value="incomplete" <?php echo $status_filter==='incomplete'?'selected':''; ?>>📝 अपूर्ण</option>
                <option value="partial" <?php echo $status_filter==='partial'?'selected':''; ?>>🧩 आंशिक</option>
            </select>
        </div>
        <div class="col-md-7 col-12">
            <label>खोज्नुहोस्</label>
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="Member ID, नाम, मोबाइल, नागरिकता नं., Tracking ID...">
                <?php if ($search): ?><a href="?status=<?php echo urlencode($status_filter); ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a><?php endif; ?>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-search me-1"></i>खोज</button>
        </div>
    </form>
    <script>document.getElementById('qf_kyc_status').addEventListener('change',function(){this.closest('form').submit();});</script>
</div>

<!-- ── KYC Table ── -->
<div class="card border-0 shadow-sm app-rounded-card">
    <div class="tbl-header-bar no-print">
        <h6><i class="fas fa-user-check me-2 text-primary"></i>KYC आवेदन सूची</h6>
        <span class="result-count-badge"><?php echo $total; ?> आवेदन</span>
    </div>
    <div class="table-responsive admin-table-card">
        <table class="table-hover table app-table align-middle mb-0 table-responsive-stack">
            <thead>
                <tr>
                    <th class="acc-col-applicant">आवेदक</th>
                    <th>Member ID</th>
                    <th>सम्पर्क</th>
                    <th>नागरिकता</th>
                    <th>खाता / शाखा</th>
                    <th>Tracking ID</th>
                    <th>दर्ता मिति</th>
                    <th>स्थिति</th>
                    <th class="no-print">कार्यहरू</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($applications)): ?>
            <tr class="no-results-row"><td colspan="9"><i class="fas fa-inbox fa-2x d-block mb-2"></i>कुनै KYC आवेदन फेला परेन।</td></tr>
            <?php else: foreach ($applications as $app):
                $trackId = $app['tracking_id'] ?: 'KYC-' . str_pad($app['id'], 6, '0', STR_PAD_LEFT);
                $initLetter = mb_strtoupper(mb_substr($app['full_name'] ?? 'K', 0, 1));
            ?>
            <tr data-status="<?php echo htmlspecialchars($app['status']); ?>">
                <td data-label="आवेदक">
                    <div class="d-flex align-items-center gap-2">
                        <?php if (!empty($app['photo'])): ?>
                        <img src="../<?php echo htmlspecialchars($app['photo']); ?>" class="kyc-list-avatar" alt="">
                        <?php else: ?>
                        <div class="av-letter av-kyc"><?php echo $initLetter; ?></div>
                        <?php endif; ?>
                        <div>
                            <div class="cell-main"><?php echo htmlspecialchars($app['full_name']); ?></div>
                            <?php if ($app['full_name_en']): ?><div class="cell-sub"><?php echo htmlspecialchars($app['full_name_en']); ?></div><?php endif; ?>
                        </div>
                    </div>
                </td>
                <td data-label="Member ID"><code class="cell-sub"><?php echo htmlspecialchars($app['member_id'] ?? '—'); ?></code></td>
                <td data-label="सम्पर्क">
                    <div class="cell-main"><i class="fas fa-phone fa-xs text-muted me-1"></i><?php echo htmlspecialchars($app['mobile']); ?></div>
                    <?php if ($app['email']): ?><div class="cell-sub"><?php echo htmlspecialchars($app['email']); ?></div><?php endif; ?>
                </td>
                <td data-label="नागरिकता"><div class="cell-sub"><?php echo htmlspecialchars($app['citizenship_no'] ?: '—'); ?></div></td>
                <td data-label="खाता / शाखा">
                    <div class="cell-main"><?php echo htmlspecialchars($app['account_type'] ?: '—'); ?></div>
                    <?php if ($app['branch']): ?><div class="cell-sub"><?php echo htmlspecialchars($app['branch']); ?></div><?php endif; ?>
                </td>
                <td data-label="Tracking ID"><span class="track-badge"><?php echo htmlspecialchars($trackId); ?></span></td>
                <td data-label="दर्ता मिति"><div class="cell-sub"><?php echo formatNepaliDate($app['created_at']); ?></div></td>
                <td data-label="स्थिति"><span class="badge-status badge-<?php echo htmlspecialchars($app['status']); ?>"><?php echo $statusLabel[$app['status']] ?? $app['status']; ?></span></td>
                <td class="no-print" data-label="कार्यहरू">
                    <div class="adm-action-icons">
                        <a href="kyc-applications.php?view=<?php echo $app['id']; ?>" class="adm-icon-btn adm-icon-btn--view" title="विवरण" aria-label="View"><i class="fas fa-eye"></i></a>
                        <?php if ($app['status'] === 'pending'): ?>
                        <form method="POST" class="qaction-form" onsubmit="return confirm('KYC स्वीकृत गर्नुहुन्छ?')">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="quick_status" value="1">
                            <input type="hidden" name="quick_id" value="<?php echo $app['id']; ?>">
                            <input type="hidden" name="quick_status_val" value="approved">
                            <button type="submit" class="btn-qapprove"><i class="fas fa-check me-1"></i>स्वीकृत</button>
                        </form>
                        <form method="POST" class="qaction-form" onsubmit="return confirm('KYC अस्वीकृत गर्नुहुन्छ?')">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="quick_status" value="1">
                            <input type="hidden" name="quick_id" value="<?php echo $app['id']; ?>">
                            <input type="hidden" name="quick_status_val" value="rejected">
                            <button type="submit" class="btn-qreject"><i class="fas fa-times me-1"></i>अस्वीकृत</button>
                        </form>
                        <form method="POST" class="qaction-form" onsubmit="return confirm('KYC अपूर्ण (document थप चाहियो) राख्नुहुन्छ?')">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="quick_status" value="1">
                            <input type="hidden" name="quick_id" value="<?php echo $app['id']; ?>">
                            <input type="hidden" name="quick_status_val" value="incomplete">
                            <button type="submit" class="btn btn-sm btn-outline-secondary py-1 px-2"><i class="fas fa-file-circle-exclamation me-1"></i>अपूर्ण</button>
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
            <?php $qs = ['status'=>$status_filter,'search'=>$search]; ?>
            <a href="?<?php echo http_build_query(array_merge($qs,['page'=>1])); ?>" class="<?php echo $page==1?'disabled':''; ?>"><i class="fas fa-angle-double-left"></i></a>
            <a href="?<?php echo http_build_query(array_merge($qs,['page'=>max(1,$page-1)])); ?>" class="<?php echo $page==1?'disabled':''; ?>"><i class="fas fa-angle-left"></i></a>
            <?php $start=max(1,$page-2);$end=min($totalPages,$page+2); for($i=$start;$i<=$end;$i++): ?>
            <?php echo $i==$page ? "<span class='active'>$i</span>" : "<a href='?".http_build_query(array_merge($qs,['page'=>$i]))."'>$i</a>"; ?>
            <?php endfor; ?>
            <a href="?<?php echo http_build_query(array_merge($qs,['page'=>min($totalPages,$page+1)])); ?>" class="<?php echo $page>=$totalPages?'disabled':''; ?>"><i class="fas fa-angle-right"></i></a>
            <a href="?<?php echo http_build_query(array_merge($qs,['page'=>$totalPages])); ?>" class="<?php echo $page==$totalPages?'disabled':''; ?>"><i class="fas fa-angle-double-right"></i></a>
            <span class="acc-page-meta"><?php echo $page; ?>/<?php echo $totalPages; ?> · <?php echo $total; ?> रेकर्ड</span>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php require_once 'includes/admin-footer.php'; ?>
