<?php
$__t = static function (string $np, string $en): string {
    $lang = (string)($_SESSION['admin_lang'] ?? $_SESSION['lang'] ?? 'np');
    return strtolower($lang) === 'en' ? $en : $np;
};
$pageTitle = $__t('डिजिटल सेवा अनुरोधहरू', 'Digital Service Requests');
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/includes/admin-request-view.php';
require_once __DIR__ . '/../includes/auth-roles.php';
require_once __DIR__ . '/../includes/digital-service-requests-tables.php';
require_once __DIR__ . '/../includes/request-status-history.php';
/* RBAC: staff hercha matra; mutate admin+ matra */
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_role('admin');

$db = getDB();
ensureDigitalServiceRequestsTables($db);
ensureRequestStatusHistoryTable($db);

/* CSRF सुरक्षा: POST अनुरोध प्रमाणित गर्नुहोस् */
checkCSRF();

$statusLabels = [
    'pending' => ['np' => 'पेन्डिङ', 'en' => 'Pending', 'class' => 'warning'],
    'processing' => ['np' => 'प्रक्रियामा', 'en' => 'Processing', 'class' => 'info'],
    'approved' => ['np' => 'स्वीकृत', 'en' => 'Approved', 'class' => 'success'],
    'rejected' => ['np' => 'अस्वीकृत', 'en' => 'Rejected', 'class' => 'danger'],
    'completed' => ['np' => 'सम्पन्न', 'en' => 'Completed', 'class' => 'primary']
];

$serviceLabels = [
    'missed_call_banking' => ['np' => 'मिस्ड कल बैंकिङ', 'en' => 'Missed Call Banking'],
    'statement_request' => ['np' => 'स्टेटमेन्ट अनुरोध', 'en' => 'Statement Request'],
    'bill_payment' => ['np' => 'बिल भुक्तानी सहयोग', 'en' => 'Bill Payment Support'],
    'mobile_recharge' => ['np' => 'मोबाइल रिचार्ज', 'en' => 'Mobile Recharge'],
    'internet_banking' => ['np' => 'इन्टरनेट/मोबाइल बैंकिङ', 'en' => 'Internet/Mobile Banking'],
    'sms_alert' => ['np' => 'SMS अलर्ट', 'en' => 'SMS Alert'],
    'card_service' => ['np' => 'कार्ड सेवा', 'en' => 'Card Service'],
    'qr_payment' => ['np' => 'QR/डिजिटल भुक्तानी', 'en' => 'QR/Digital Payment'],
    'share_refund' => ['np' => 'शेयर फिर्ता', 'en' => 'Share Refund'],
    'share_increase' => ['np' => 'शेयर वृद्धि', 'en' => 'Share Increase'],
    'other' => ['np' => 'अन्य', 'en' => 'Other']
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['update_status'])) {
            $requestId = (int)$_POST['request_id'];
            $status    = clean_text($_POST['status'] ?? 'pending');
            $remarks   = clean_text($_POST['admin_remarks'] ?? '');
            if (!isset($statusLabels[$status])) { $status = 'pending'; }
            $oldStatus = '';
            $notifyOptIn = !empty($_POST['notify_member']) && $_POST['notify_member'] === '1';
            $notifyOutcome = [
                'admin_chose' => $notifyOptIn,
                'email' => ['status' => 'not_attempted', 'reason' => '', 'to' => ''],
                'sms'   => ['status' => 'not_attempted', 'reason' => '', 'to' => ''],
            ];
            try {
                $oldSt = $db->prepare("SELECT status FROM digital_service_requests WHERE id=? LIMIT 1");
                $oldSt->execute([$requestId]);
                $oldStatus = (string)($oldSt->fetchColumn() ?: '');
            } catch (Exception $e) {}
            /* File upload — admin ले letter/document attach गर्न सक्छ */
            $newFile = adminUploadFile('admin_attachment');
            if ($newFile) {
                $stmt = $db->prepare("UPDATE digital_service_requests SET status=?, admin_remarks=?, admin_attachment=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?");
                $stmt->execute([$status, $remarks, $newFile, $_SESSION['admin_name'] ?? 'Admin', $requestId]);
            } else {
                $stmt = $db->prepare("UPDATE digital_service_requests SET status=?, admin_remarks=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?");
                $stmt->execute([$status, $remarks, $_SESSION['admin_name'] ?? 'Admin', $requestId]);
            }
            /* Member लाई status notification — email/SMS */
            try {
                $nRow = $db->prepare("SELECT requester_name, email, phone, tracking_id FROM digital_service_requests WHERE id=?");
                $nRow->execute([$requestId]);
                $nData = $nRow->fetch();
                if ($nData) {
                    $r = sendMemberStatusUpdate('digital_service',
                        $nData['email'] ?? '', $nData['phone'] ?? '', $nData['requester_name'] ?? '',
                        $status, $remarks, $nData['tracking_id'] ?? '', !$notifyOptIn);
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
                    'digital_service',
                    $requestId,
                    $oldStatus !== '' ? $oldStatus : null,
                    $status,
                    (string)$remarks,
                    $notifySent,
                    (int)($_SESSION['admin_id'] ?? 0),
                    (string)($_SESSION['admin_name'] ?? 'Admin'),
                    $notifyOutcome
                );
            } catch (Exception $e) {}
            setFlash('success', $__t('डिजिटल सेवा अनुरोध अपडेट भयो।', 'Digital service request updated.'));
            redirect('digital-service-requests.php');
        }
        if (isset($_POST['delete_request'])) {
            $requestId = (int)$_POST['request_id'];
            $stmt = $db->prepare("DELETE FROM digital_service_requests WHERE id = ?");
            $stmt->execute([$requestId]);
            setFlash('success', $__t('अनुरोध हटाइयो।', 'Request deleted.'));
            redirect('digital-service-requests.php');
        }
    } catch (Exception $e) {
        setFlash('error', $__t('त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।', 'An error occurred. Please try again later.'));
    }
}

$action = $_GET['action'] ?? 'list';
if (!in_array($action, ['list', 'view'], true)) {
    $action = 'list';
}
$id = (int)($_GET['id'] ?? 0);
?>

<?php if ($action === 'view' && $id > 0): ?>
<?php
$stmt = $db->prepare("SELECT * FROM digital_service_requests WHERE id = ?");
$stmt->execute([$id]);
$request = $stmt->fetch();
if (!$request) {
    setFlash('error', $__t('अनुरोध फेला परेन।', 'Request not found.'));
    redirect('digital-service-requests.php');
}
$dsrHistory = [];
try {
    $dsrHistory = fetchRequestStatusHistory($db, 'digital_service', (int)$request['id'], 40);
} catch (Exception $e) {
    $dsrHistory = [];
}
?>
<div class="card admin-table-card mb-4 arv-legacy-detail">
    <div class="card-header gradient-card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-mobile-alt"></i> <?php echo $__t('अनुरोध विवरण', 'Request Details'); ?> - <?php echo e($request['tracking_id']); ?></h5>
        <div class="d-flex align-items-center gap-2">
            <a href="digital-service-requests.php" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-left me-1"></i><?php echo $__t('फिर्ता', 'Back'); ?></a>
            <a href="print-form.php?type=digital&id=<?php echo (int)$request['id']; ?>" target="_blank"
               class="btn btn-light btn-sm"><i class="fas fa-print me-1"></i>Print Form</a>
        </div>
    </div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="info-section">
                    <h6><i class="fas fa-user"></i> <?php echo $__t('सदस्य / अनुरोधकर्ता', 'Member / Requester'); ?></h6>
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong><?php echo $__t('नाम', 'Name'); ?>:</strong> <?php echo e($request['requester_name']); ?></p>
                            <p><strong><?php echo $__t('सदस्य नं.', 'Member ID'); ?>:</strong> <?php echo e($request['member_id']) ?: 'N/A'; ?></p>
                            <p><strong><?php echo $__t('फोन', 'Phone'); ?>:</strong> <a href="tel:<?php echo e($request['phone']); ?>"><?php echo e($request['phone']); ?></a></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong><?php echo $__t('इमेल', 'Email'); ?>:</strong> <?php echo e($request['email']) ?: 'N/A'; ?></p>
                            <p><strong><?php echo $__t('सेवा', 'Service'); ?>:</strong> <?php echo e($request['service_type_np'] ?: (isset($serviceLabels[$request['service_type']]) ? $__t($serviceLabels[$request['service_type']]['np'], $serviceLabels[$request['service_type']]['en']) : $request['service_type'])); ?></p>
                            <p><strong><?php echo $__t('मिति', 'Date'); ?>:</strong> <?php echo formatNepaliDate($request['created_at']); ?></p>
                        </div>
                    </div>
                </div>
                <div class="info-section">
                    <h6><i class="fas fa-list-check"></i> <?php echo $__t('सेवा विवरण', 'Service Details'); ?></h6>
                    <div class="row">
                        <div class="col-md-6"><p><strong><?php echo $__t('खाता नं.', 'Account No.'); ?>:</strong> <?php echo e($request['account_number']) ?: 'N/A'; ?></p></div>
                        <div class="col-md-6"><p><strong><?php echo $__t('सम्पर्क माध्यम', 'Preferred Contact'); ?>:</strong> <?php echo e($request['preferred_contact']); ?></p></div>
                        <?php if ($request['statement_from'] || $request['statement_to']): ?>
                        <div class="col-md-6"><p><strong><?php echo $__t('स्टेटमेन्ट', 'Statement'); ?>:</strong> <?php echo e($request['statement_from']); ?> - <?php echo e($request['statement_to']); ?></p></div>
                        <?php endif; ?>
                        <?php if ($request['biller_name'] || $request['bill_reference']): ?>
                        <div class="col-md-6"><p><strong><?php echo $__t('बिल', 'Bill'); ?>:</strong> <?php echo e($request['biller_name']); ?> / <?php echo e($request['bill_reference']); ?></p></div>
                        <?php endif; ?>
                        <?php if ($request['recharge_number'] || $request['recharge_amount']): ?>
                        <div class="col-md-6"><p><strong><?php echo $__t('रिचार्ज', 'Recharge'); ?>:</strong> <?php echo e($request['recharge_number']); ?> / रू. <?php echo number_format((float)$request['recharge_amount'], 2); ?></p></div>
                        <?php endif; ?>
                        <?php if (!empty($request['service_amount'])): ?>
                        <div class="col-md-6"><p><strong><?php echo $__t('सेवा रकम', 'Service Amount'); ?>:</strong> रू. <?php echo number_format((float)$request['service_amount'], 2); ?></p></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($request['request_details']): ?>
                    <p><strong><?php echo $__t('थप विवरण', 'Additional Details'); ?>:</strong></p>
                    <div class="dsr-soft-bg p-3 rounded"><?php echo nl2br(e($request['request_details'])); ?></div>
                    <?php endif; ?>
                    <?php if ($request['attachment']): ?>
                    <p class="mt-3"><strong class="dsr-label-strong"><?php echo $__t('कागजात', 'Attachment'); ?>:</strong> <a href="<?php echo SITE_URL . e($request['attachment']); ?>" target="_blank" class="btn btn-sm dsr-attachment-btn"><i class="fas fa-file"></i> <?php echo $__t('हेर्नुहोस्', 'View'); ?></a></p>
                    <?php endif; ?>
                </div>
                <?php if ($request['admin_remarks']): ?>
                <div class="info-section">
                    <h6><i class="fas fa-note-sticky"></i> <?php echo $__t('टिप्पणी', 'Remarks'); ?></h6>
                    <div class="dsr-remark-box"><?php echo nl2br(e($request['admin_remarks'])); ?></div>
                </div>
                <?php endif; ?>
            </div>
            <div class="col-lg-4">
                <div class="card dsr-soft-bg">
                    <div class="card-header"><h6 class="mb-0"><i class="fas fa-edit"></i> <?php echo $__t('स्थिति अपडेट', 'Update Status'); ?></h6></div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="update_status" value="1">
                            <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                            <div class="mb-3">
                                <label class="form-label"><?php echo $__t('स्थिति', 'Status'); ?></label>
                                <select name="status" class="form-select">
                                    <?php foreach ($statusLabels as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $request['status'] === $key ? 'selected' : ''; ?>><?php echo $__t($label['np'], $label['en']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label"><?php echo $__t('Admin टिप्पणी', 'Admin Remarks'); ?></label>
                                <textarea name="admin_remarks" class="form-control" rows="4"><?php echo e($request['admin_remarks']); ?></textarea>
                            </div>
                            <?php $hasEmail = !empty($request['email']); $hasPhone = !empty($request['phone']); ?>
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
                            <!-- Admin ले सेवा सम्बन्धी document वा instruction attach गर्न सक्छ -->
                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="fas fa-paperclip me-1"></i><?php echo $__t('संलग्न फाइल (Optional)', 'Attachment (Optional)'); ?>
                                </label>
                                <?php if (!empty($request['admin_attachment'])): ?>
                                <div class="mb-1"><?php echo adminAttachmentHtml($request['admin_attachment']); ?></div>
                                <?php endif; ?>
                                <input type="file" name="admin_attachment" class="form-control"
                                       accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                                <small class="dsr-muted"><?php echo $__t('PDF, JPG, PNG, DOC — अधिकतम 5MB', 'PDF, JPG, PNG, DOC — max 5MB'); ?></small>
                            </div>
                            <button type="submit" class="btn dsr-update-btn w-100"><i class="fas fa-save"></i> <?php echo $__t('अपडेट गर्नुहोस्', 'Update'); ?></button>
                        </form>
                        <hr>
                        <form method="POST" onsubmit="return confirm('<?php echo $__t('के तपाईं निश्चित हुनुहुन्छ?', 'Are you sure?'); ?>');">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="delete_request" value="1">
                            <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm w-100"><i class="fas fa-trash"></i> <?php echo $__t('हटाउनुहोस्', 'Delete'); ?></button>
                        </form>
                    </div>
                </div>
                <div class="card mt-3">
                    <div class="card-body text-center">
                        <h6><?php echo $__t('Tracking ID', 'Tracking ID'); ?></h6>
                        <p class="fs-5 font-monospace dsr-track-text"><?php echo e($request['tracking_id']); ?></p>
                    </div>
                </div>
                <?php if (!empty($dsrHistory)): ?>
                <div class="card mt-3">
                    <div class="card-header"><h6 class="mb-0"><i class="fas fa-clock-rotate-left me-1"></i><?php echo $__t('Status / Comment History', 'Status / Comment History'); ?></h6></div>
                    <div class="card-body">
                        <?php echo arvLogList($dsrHistory); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>


<?php else: ?>
<?php
$filterStatus = $_GET['status'] ?? '';
if ($filterStatus !== '' && !isset($statusLabels[$filterStatus])) {
    $filterStatus = '';
}
$filterType = $_GET['type'] ?? '';
if ($filterType !== '' && !isset($serviceLabels[$filterType])) {
    $filterType = '';
}
$search = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200, 'UTF-8');
$whereClause = '1=1';
$params = [];
if ($filterStatus && isset($statusLabels[$filterStatus])) {
    $whereClause .= ' AND status = ?';
    $params[] = $filterStatus;
}
if ($filterType && isset($serviceLabels[$filterType])) {
    $whereClause .= ' AND service_type = ?';
    $params[] = $filterType;
}
if ($search) {
    $whereClause .= ' AND (tracking_id LIKE ? OR requester_name LIKE ? OR phone LIKE ? OR member_id LIKE ? OR email LIKE ?)';
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term, $term, $term]);
}
$requests = [];
$statusCounts = [];
$totalRequests = 0;
try {
    $stmt = $db->prepare("SELECT * FROM digital_service_requests WHERE $whereClause ORDER BY created_at DESC");
    $stmt->execute($params);
    $requests = $stmt->fetchAll();
    $countStmt = $db->query("SELECT status, COUNT(*) as count FROM digital_service_requests GROUP BY status");
    if ($countStmt) {
        while ($row = $countStmt->fetch()) {
            $statusCounts[$row['status']] = $row['count'];
        }
    }
    $totalRequests = array_sum($statusCounts);
} catch (Exception $e) {
    $requests = [];
    $statusCounts = [];
    $totalRequests = 0;
}
echo adminPageHeader(
    $__t('डिजिटल सेवा अनुरोधहरू', 'Digital Service Requests'), 'fa-mobile-alt',
    $__t('अनलाइन डिजिटल सेवा अनुरोधहरूको स्थिति र व्यवस्थापन', 'Manage status of online digital service requests'),
    adminStatLink('?status=pending', 'danger', $__t('पेन्डिङ', 'Pending'), $statusCounts['pending'] ?? 0)
    . ' ' . adminStatLink('?status=completed', 'success', $__t('सम्पन्न', 'Completed'), $statusCounts['completed'] ?? 0)
    . ' ' . adminStatLink('digital-service-requests.php', 'secondary', $__t('जम्मा', 'Total'), $totalRequests)
);
$_flash = getFlash(); if ($_flash) echo adminAlert($_flash['type'], $_flash['message']);
?>
<!-- ── Stat Mini Row ── -->
<div class="stat-mini-row no-print">
    <a href="digital-service-requests.php" class="stat-mini <?php echo !$filterStatus&&!$filterType&&!$search?'active-filter':''; ?>">
        <div class="sm-icon ic-total"><i class="fas fa-mobile-alt"></i></div>
        <div class="sm-val"><?php echo $totalRequests; ?></div>
        <div class="sm-lbl"><?php echo $__t('जम्मा', 'Total'); ?></div>
    </a>
    <a href="?status=pending" class="stat-mini <?php echo $filterStatus==='pending'?'active-filter':''; ?>">
        <div class="sm-icon ic-pending"><i class="fas fa-clock"></i></div>
        <div class="sm-val"><?php echo $statusCounts['pending'] ?? 0; ?></div>
        <div class="sm-lbl"><?php echo $__t('पेन्डिङ', 'Pending'); ?></div>
    </a>
    <a href="?status=processing" class="stat-mini <?php echo $filterStatus==='processing'?'active-filter':''; ?>">
        <div class="sm-icon ic-process"><i class="fas fa-spinner"></i></div>
        <div class="sm-val"><?php echo $statusCounts['processing'] ?? 0; ?></div>
        <div class="sm-lbl"><?php echo $__t('प्रक्रियाधीन', 'Processing'); ?></div>
    </a>
    <a href="?status=completed" class="stat-mini <?php echo $filterStatus==='completed'?'active-filter':''; ?>">
        <div class="sm-icon ic-approved"><i class="fas fa-check-circle"></i></div>
        <div class="sm-val"><?php echo $statusCounts['completed'] ?? 0; ?></div>
        <div class="sm-lbl"><?php echo $__t('सम्पन्न', 'Completed'); ?></div>
    </a>
    <a href="?status=rejected" class="stat-mini <?php echo $filterStatus==='rejected'?'active-filter':''; ?>">
        <div class="sm-icon ic-rejected"><i class="fas fa-times-circle"></i></div>
        <div class="sm-val"><?php echo $statusCounts['rejected'] ?? 0; ?></div>
        <div class="sm-lbl"><?php echo $__t('अस्वीकृत', 'Rejected'); ?></div>
    </a>
</div>

<!-- ── Filter Bar ── -->
<div class="adm-filter-bar no-print">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-2 col-6">
            <label><?php echo $__t('स्थिति', 'Status'); ?></label>
            <select name="status" class="form-select form-select-sm" onchange="this.closest('form').submit()">
                <option value=""><?php echo $__t('सबै स्थिति', 'All Status'); ?></option>
                <?php foreach ($statusLabels as $key => $label): ?>
                <option value="<?php echo $key; ?>" <?php echo $filterStatus===$key?'selected':''; ?>><?php echo $__t($label['np'], $label['en']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 col-6">
            <label><?php echo $__t('सेवा', 'Service'); ?></label>
            <select name="type" class="form-select form-select-sm" onchange="this.closest('form').submit()">
                <option value=""><?php echo $__t('सबै', 'All'); ?></option>
                <?php foreach ($serviceLabels as $key => $label): ?>
                <option value="<?php echo $key; ?>" <?php echo $filterType===$key?'selected':''; ?>><?php echo $__t($label['np'], $label['en']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6 col-12">
            <label><?php echo $__t('खोज्नुहोस्', 'Search'); ?></label>
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white"><i class="fas fa-search dsr-search-icon"></i></span>
                <input type="text" name="search" class="form-control" value="<?php echo e($search); ?>" placeholder="<?php echo $__t('Tracking ID, नाम, फोन, सदस्य ID...', 'Tracking ID, name, phone, member ID...'); ?>">
            </div>
        </div>
        <div class="col-md-2 col-6">
            <button type="submit" class="btn dsr-search-btn btn-sm w-100"><i class="fas fa-search me-1"></i><?php echo $__t('खोज', 'Search'); ?></button>
            <?php if ($filterStatus||$filterType||$search): ?><a href="digital-service-requests.php" class="btn btn-outline-secondary btn-sm w-100 mt-1"><i class="fas fa-times me-1"></i><?php echo $__t('रिसेट', 'Reset'); ?></a><?php endif; ?>
        </div>
    </form>
</div>

<div class="card border-0 shadow-sm app-rounded-card">
    <div class="tbl-header-bar no-print">
        <h6><i class="fas fa-mobile-alt me-2 dsr-head-icon"></i><?php echo $__t('डिजिटल सेवा अनुरोध सूची', 'Digital Service Requests List'); ?></h6>
        <span class="result-count-badge"><?php echo count($requests); ?> <?php echo $__t('रेकर्ड', 'records'); ?></span>
    </div>
    <div class="table-responsive admin-table-card">
        <table class="table-hover table app-table align-middle mb-0 table-responsive-stack">
                <thead>
                    <tr>
                        <th>Tracking</th>
                        <th><?php echo $__t('नाम/सम्पर्क', 'Name/Contact'); ?></th>
                        <th><?php echo $__t('सेवा', 'Service'); ?></th>
                        <th><?php echo $__t('मिति', 'Date'); ?></th>
                        <th><?php echo $__t('स्थिति', 'Status'); ?></th>
                        <th><?php echo $__t('कार्य', 'Action'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $request): ?>
                    <tr>
                        <td><span class="font-monospace dsr-track-inline"><?php echo e($request['tracking_id']); ?></span></td>
                        <td>
                            <strong><?php echo e($request['requester_name']); ?></strong>
                            <br><small><i class="fas fa-phone"></i> <?php echo e($request['phone']); ?><?php if ($request['email']): ?> | <i class="fas fa-envelope"></i> <?php echo e($request['email']); ?><?php endif; ?></small>
                        </td>
                        <td><?php echo e($request['service_type_np'] ?: (isset($serviceLabels[$request['service_type']]) ? $__t($serviceLabels[$request['service_type']]['np'], $serviceLabels[$request['service_type']]['en']) : $request['service_type'])); ?></td>
                        <td><?php echo formatNepaliDate($request['created_at']); ?></td>
                        <td><span class="badge dsr-st-badge dsr-st-<?php echo $statusLabels[$request['status']]['class'] ?? 'secondary'; ?>"><?php echo isset($statusLabels[$request['status']]) ? $__t($statusLabels[$request['status']]['np'], $statusLabels[$request['status']]['en']) : e($request['status']); ?></span></td>
                        <td>
                            <div class="adm-action-icons">
                                <a href="?action=view&id=<?php echo (int)$request['id']; ?>" class="adm-icon-btn adm-icon-btn--view" title="<?php echo $__t('विवरण', 'Details'); ?>" aria-label="View"><i class="fas fa-eye"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($requests)): ?>
                    <tr><td colspan="6" class="text-center py-4"><?php echo $__t('कुनै अनुरोध छैन', 'No requests found'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once 'includes/admin-footer.php'; ?>
