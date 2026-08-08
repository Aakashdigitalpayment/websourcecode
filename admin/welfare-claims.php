<?php
/**
 * Admin Panel - Member Welfare Claims Management
 * सदस्य कल्याण दाबी व्यवस्थापन
 */
if (!ob_get_level()) {
    ob_start();
}
$pageTitle = 'सदस्य कल्याण दाबीहरू';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/includes/admin-request-view.php';
require_once __DIR__ . '/includes/admin-excel-export.php';
require_once __DIR__ . '/../includes/welfare-claims-tables.php';
require_once __DIR__ . '/../includes/welfare-claim-types.php';
require_once __DIR__ . '/../includes/request-status-history.php';

$db = getDB();
ensureWelfareClaimsTables($db);
ensureRequestStatusHistoryTable($db);

/* CSRF सुरक्षा: POST अनुरोध प्रमाणित गर्नुहोस् */
checkCSRF();
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
if (!in_array($action, ['list', 'view'], true)) {
    $action = 'list';
}
$id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['update_status'])) {
            $claim_id = (int)$_POST['claim_id'];
            $status = clean_text($_POST['status']);
            $approved_amount = floatval($_POST['approved_amount'] ?? 0);
            $admin_remarks = clean_text($_POST['admin_remarks'] ?? '');
            $oldStatus = '';
            $notifyOptIn = !empty($_POST['notify_member']) && $_POST['notify_member'] === '1';
            $notifyOutcome = [
                'admin_chose' => $notifyOptIn,
                'email' => ['status' => 'not_attempted', 'reason' => '', 'to' => ''],
                'sms'   => ['status' => 'not_attempted', 'reason' => '', 'to' => ''],
            ];
            try {
                $os = $db->prepare("SELECT status FROM member_welfare_claims WHERE id=? LIMIT 1");
                $os->execute([$claim_id]);
                $oldStatus = (string)($os->fetchColumn() ?: '');
            } catch (Exception $e) {}

            $stmt = $db->prepare("UPDATE member_welfare_claims SET
                status = ?,
                approved_amount = ?,
                admin_remarks = ?,
                reviewed_by = ?,
                reviewed_at = NOW(),
                paid_at = CASE WHEN ? = 'paid' THEN NOW() ELSE paid_at END
                WHERE id = ?");
            $stmt->execute([$status, $approved_amount, $admin_remarks, $_SESSION['admin_name'] ?? 'Admin', $status, $claim_id]);

            /* Member portal notification */
            try {
                $nr = $db->prepare("SELECT member_name, full_name, email, phone FROM member_welfare_claims WHERE id=?");
                $nr->execute([$claim_id]); $nd = $nr->fetch();
                if ($nd && function_exists('sendMemberStatusUpdate')) {
                    $r = sendMemberStatusUpdate('welfare', $nd['email']??'', $nd['phone']??'', $nd['full_name'] ?? $nd['member_name'] ?? '', $status, $admin_remarks, '', !$notifyOptIn);
                    if (is_array($r)) {
                        $notifyOutcome['email'] = $r['email'] ?? $notifyOutcome['email'];
                        $notifyOutcome['sms']   = $r['sms']   ?? $notifyOutcome['sms'];
                    }
                }
            } catch (Exception $ex) {}
            $notifySent = ($notifyOutcome['email']['status'] === 'sent') || ($notifyOutcome['sms']['status'] === 'sent');
            try {
                logRequestStatusHistory(
                    $db,
                    'welfare',
                    $claim_id,
                    $oldStatus !== '' ? $oldStatus : null,
                    $status,
                    (string)$admin_remarks,
                    $notifySent,
                    (int)($_SESSION['admin_id'] ?? 0),
                    (string)($_SESSION['admin_name'] ?? 'Admin'),
                    $notifyOutcome
                );
            } catch (Exception $e) {}

            setFlash('success', 'दाबी स्थिति सफलतापूर्वक अपडेट भयो।');
            /* Update पछि detail page मा नै redirect — list होइन */
            redirect('welfare-claims.php?action=view&id=' . $claim_id);
        }

        if (isset($_POST['delete_claim'])) {
            $claim_id = (int)$_POST['claim_id'];
            $stmt = $db->prepare("DELETE FROM member_welfare_claims WHERE id = ?");
            $stmt->execute([$claim_id]);
            setFlash('success', 'दाबी सफलतापूर्वक हटाइयो।');
            redirect('welfare-claims.php');
        }
    } catch (Exception $e) {
        setFlash('error', 'त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।');
    }
}

// Claim type labels — DB catalog + fallback (include inactive for old rows)
$claimTypes = welfareClaimTypesMap($db, false);


$statusLabels = [
    'pending' => ['np' => 'पेन्डिङ', 'class' => 'warning'],
    'under_review' => ['np' => 'समीक्षाधीन', 'class' => 'info'],
    'approved' => ['np' => 'स्वीकृत', 'class' => 'success'],
    'rejected' => ['np' => 'अस्वीकृत', 'class' => 'danger'],
    'paid' => ['np' => 'भुक्तान भयो', 'class' => 'primary'],
    'completed' => ['np' => 'सम्पन्न', 'class' => 'success']
];

// Get filter parameters
$filterStatus = $_GET['status'] ?? '';
if ($filterStatus !== '' && !isset($statusLabels[$filterStatus])) {
    $filterStatus = '';
}
$filterType = $_GET['type'] ?? '';
if ($filterType !== '' && !isset($claimTypes[$filterType])) {
    $filterType = '';
}
$search = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200, 'UTF-8');
[$dateFrom, $dateTo] = adminExcelDateRangeFromGet();

$wlfWhere = '1=1';
$wlfParams = [];
if ($filterStatus) {
    $wlfWhere .= ' AND status = ?';
    $wlfParams[] = $filterStatus;
}
if ($filterType) {
    $wlfWhere .= ' AND claim_type = ?';
    $wlfParams[] = $filterType;
}
if ($search) {
    $wlfWhere .= ' AND (tracking_id LIKE ? OR member_name LIKE ? OR phone LIKE ? OR member_id LIKE ? OR email LIKE ?)';
    $searchTerm = "%$search%";
    $wlfParams = array_merge($wlfParams, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}
adminExcelAppendDateWhere($wlfWhere, $wlfParams, $dateFrom, $dateTo);

if (adminExcelIsExportRequest() && $db instanceof PDO) {
    $exportId = (int)($_GET['id'] ?? 0);
    try {
        if ($exportId > 0) {
            $st = $db->prepare('SELECT * FROM member_welfare_claims WHERE id=? LIMIT 1');
            $st->execute([$exportId]);
            $exportRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $fname = adminExcelFilename('welfare-claim-' . $exportId);
        } else {
            $st = $db->prepare("SELECT * FROM member_welfare_claims WHERE $wlfWhere ORDER BY created_at DESC LIMIT 10000");
            $st->execute($wlfParams);
            $exportRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $fname = adminExcelFilename('welfare-claims', $dateFrom, $dateTo);
        }
    } catch (Throwable $e) {
        error_log('[welfare-export] ' . $e->getMessage());
        $exportRows = [];
        $fname = adminExcelFilename('welfare-claims');
    }
    $cols = [
        'ID' => 'id', 'Tracking ID' => 'tracking_id', 'Member Name' => 'member_name',
        'Member ID' => 'member_id', 'Phone' => 'phone', 'Email' => 'email',
        'Claim Type' => 'claim_type', 'Claim Amount' => 'claim_amount',
        'Approved Amount' => 'approved_amount', 'Status' => 'status',
        'Description' => 'description',
        'Deceased Name' => 'deceased_name', 'Deceased Relation' => 'deceased_relation',
        'Death Date' => 'death_date', 'Beneficiary' => 'beneficiary_name',
        'Delivery Date' => 'delivery_date', 'Hospital' => 'hospital_name',
        'Disease/Injury' => 'disease_illness', 'Treatment Date' => 'treatment_date',
        'Hospital/Clinic' => 'hospital_clinic', 'Policy No' => 'policy_number',
        'Insurer' => 'insurer_name', 'Supporting Docs' => 'supporting_documents',
        'Created At' => 'created_at', 'Admin Remarks' => 'admin_remarks',
    ];
    adminExcelStreamCsv($fname, array_keys($cols), adminExcelMapRows($exportRows, $cols));
}
?>

<?php if ($action === 'view' && $id > 0): ?>
<?php
// View single claim
$stmt = $db->prepare("SELECT * FROM member_welfare_claims WHERE id = ?");
$stmt->execute([$id]);
$claim = $stmt->fetch();
$claimHistory = [];
if ($claim) {
    try { $claimHistory = fetchRequestStatusHistory($db, 'welfare', (int)$id, 40); } catch (Exception $e) { $claimHistory = []; }
}

if (!$claim) {
    setFlash('error', 'दाबी फेला परेन।');
    redirect('welfare-claims.php');
}
?>

<div class="card admin-table-card mb-4 arv-legacy-detail">
    <div class="card-header gradient-card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">
            <i class="fas fa-eye"></i> दाबी विवरण — <?php echo $claim['tracking_id']; ?>
        </h5>
        <div class="d-flex align-items-center gap-2">
            <a href="welfare-claims.php" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left"></i> फिर्ता
            </a>
            <?php echo adminExcelSingleLink('welfare-claims.php', (int)$claim['id']); ?>
            <?php echo adminPrintFormLink('welfare', (int)$claim['id']); ?>
        </div>
    </div>
    <div class="card-body">
        <div class="row">
            <!-- Left Column - Claim Details -->
            <div class="col-lg-8">
                <!-- Status Badge -->
                <div class="mb-4">
                    <span class="badge bg-<?php echo $statusLabels[$claim['status']]['class'] ?? 'secondary'; ?> fs-6">
                        <?php echo $statusLabels[$claim['status']]['np'] ?? $claim['status']; ?>
                    </span>
                    <span class="badge ms-2" style="background-color: <?php echo $claimTypes[$claim['claim_type']]['color'] ?? '#888'; ?>">
                        <i class="fas <?php echo $claimTypes[$claim['claim_type']]['icon'] ?? 'fa-file'; ?>"></i>
                        <?php echo $claim['claim_type_np'] ?? $claimTypes[$claim['claim_type']]['np'] ?? $claim['claim_type']; ?>
                    </span>
                </div>

                <!-- Member Information -->
                <div class="wlf-info-section">
                    <h6><i class="fas fa-user"></i> सदस्य जानकारी</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>नाम:</strong> <?php echo e($claim['member_name']); ?></p>
                            <p><strong>सदस्य नं.:</strong> <?php echo e($claim['member_id']) ?: 'N/A'; ?></p>
                            <p><strong>फोन:</strong> <a href="tel:<?php echo $claim['phone']; ?>"><?php echo e($claim['phone']); ?></a></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>इमेल:</strong> <?php echo e($claim['email']) ?: 'N/A'; ?></p>
                            <p><strong>ठेगाना:</strong> <?php echo e($claim['address']) ?: 'N/A'; ?></p>
                            <p><strong>दर्ता मिति:</strong> <?php echo formatNepaliDate($claim['created_at']); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Claim-specific Details (use catalog form_profile, not raw slug) -->
                <?php
                $claimProfile = (string)($claimTypes[$claim['claim_type']]['profile'] ?? $claim['claim_type']);
                if ($claimProfile === 'accident') {
                    $claimProfile = 'medical';
                }
                ?>
                <?php if ($claimProfile === 'death' && ($claim['deceased_name'] || $claim['death_date'])): ?>
                <div class="wlf-info-section">
                    <h6><i class="fas fa-heart-broken"></i> मृत्यु दाबी विवरण</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>मृतकको नाम:</strong> <?php echo e($claim['deceased_name']); ?></p>
                            <p><strong>नाता:</strong> <?php echo e($claim['deceased_relation']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>मृत्यु मिति:</strong> <?php echo $claim['death_date'] ? formatDate($claim['death_date']) : 'N/A'; ?></p>
                            <?php if ($claim['death_certificate']): ?>
                            <p><strong>प्रमाणपत्र:</strong> <a href="<?php echo SITE_URL . $claim['death_certificate']; ?>" target="_blank" class="btn btn-sm btn-outline-primary" rel="noopener noreferrer"><i class="fas fa-file"></i> हेर्नुहोस्</a></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($claim['beneficiary_name']): ?>
                    <div class="row mt-2">
                        <div class="col-md-6">
                            <p><strong>लाभग्राही:</strong> <?php echo e($claim['beneficiary_name']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>सदस्यसँगको नाता:</strong> <?php echo e($claim['beneficiary_relation']); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($claimProfile === 'maternity' && ($claim['delivery_date'] || $claim['hospital_name'])): ?>
                <div class="wlf-info-section">
                    <h6><i class="fas fa-baby"></i> सुत्केरी विवरण</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>प्रसूति मिति:</strong> <?php echo $claim['delivery_date'] ? formatDate($claim['delivery_date']) : 'N/A'; ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>अस्पताल:</strong> <?php echo e($claim['hospital_name']); ?></p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (in_array($claimProfile, ['medical','accident'], true) && ($claim['disease_illness'] || $claim['treatment_date'] || $claim['hospital_clinic'])): ?>
                <div class="wlf-info-section">
                    <h6><i class="fas fa-<?php echo ($claim['claim_type'] === 'accident' || ($claimTypes[$claim['claim_type']]['profile'] ?? '') === 'accident') ? 'triangle-exclamation' : 'hospital'; ?>"></i>
                        <?php echo ($claim['claim_type'] === 'accident' || ($claimTypes[$claim['claim_type']]['profile'] ?? '') === 'accident') ? 'दुर्घटना / उपचार विवरण' : 'उपचार विवरण'; ?>
                    </h6>
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>रोग/चोट विवरण:</strong> <?php echo e($claim['disease_illness']) ?: 'N/A'; ?></p>
                            <p><strong>उपचार मिति:</strong> <?php echo $claim['treatment_date'] ? formatDate($claim['treatment_date']) : 'N/A'; ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>अस्पताल/क्लिनिक:</strong> <?php echo e($claim['hospital_clinic']) ?: 'N/A'; ?></p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($claimProfile === 'insurance' && ($claim['policy_number'] || $claim['insurer_name'])): ?>
                <div class="wlf-info-section">
                    <h6><i class="fas fa-shield-halved"></i> बीमा विवरण</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>पोलिसी नम्बर:</strong> <?php echo e($claim['policy_number']) ?: 'N/A'; ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>बीमा कम्पनी:</strong> <?php echo e($claim['insurer_name']) ?: 'N/A'; ?></p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Amount & Description -->
                <div class="wlf-info-section">
                    <h6><i class="fas fa-rupee-sign"></i> रकम विवरण</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>दाबी रकम:</strong> <span class="fs-5">रू. <?php echo number_format($claim['claim_amount'], 2); ?></span></p>
                        </div>
                        <?php if ($claim['approved_amount']): ?>
                        <div class="col-md-6">
                            <p><strong>स्वीकृत रकम:</strong> <span class="fs-5 text-success">रू. <?php echo number_format($claim['approved_amount'], 2); ?></span></p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($claim['description']): ?>
                    <p><strong>विवरण:</strong></p>
                    <p class="bg-light p-3 rounded"><?php echo nl2br(e($claim['description'])); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Supporting Documents -->
                <?php if ($claim['supporting_documents']): ?>
                <div class="wlf-info-section">
                    <h6><i class="fas fa-paperclip"></i> संलग्न कागजातहरू</h6>
                    <div class="documents-list">
                        <?php foreach (explode(',', $claim['supporting_documents']) as $doc): ?>
                        <?php if (trim($doc)): ?>
                        <a href="<?php echo SITE_URL . trim($doc); ?>" target="_blank" class="btn btn-sm btn-outline-secondary me-2 mb-2" rel="noopener noreferrer">
                            <i class="fas fa-file"></i> <?php echo basename(trim($doc)); ?>
                        </a>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Admin Notes -->
                <?php if ($claim['admin_remarks']): ?>
                <div class="wlf-info-section">
                    <h6><i class="fas fa-sticky-note"></i> Admin टिप्पणी</h6>
                    <p class="bg-warning-subtle p-3 rounded"><?php echo nl2br(e($claim['admin_remarks'])); ?></p>
                    <?php if ($claim['reviewed_by']): ?>
                    <small class="text-muted">समीक्षा गर्ने: <?php echo e($claim['reviewed_by']); ?> | <?php echo $claim['reviewed_at'] ? formatDate($claim['reviewed_at'], 'Y-m-d H:i') : ''; ?></small>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($claimHistory)): ?>
                <div class="wlf-info-section">
                    <h6><i class="fas fa-clock-rotate-left"></i> Status / Comment History</h6>
                    <?php echo arvLogList($claimHistory); ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right Column - Status Update -->
            <div class="col-lg-4">
                <div class="card bg-light">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-edit"></i> स्थिति अपडेट गर्नुहोस्</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <?php echo csrfField(); /* CSRF protection — admin POST मा अनिवार्य */ ?>
                            <input type="hidden" name="update_status" value="1">
                            <input type="hidden" name="claim_id" value="<?php echo $claim['id']; ?>">

                            <div class="mb-3">
                                <label for="wc_status" class="form-label">स्थिति</label>
                                <select name="status" id="wc_status" class="form-select" required>
                                    <?php foreach ($statusLabels as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $claim['status'] === $key ? 'selected' : ''; ?>>
                                        <?php echo $label['np']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="wc_approved_amount" class="form-label">स्वीकृत रकम (रू.)</label>
                                <input type="number" name="approved_amount" id="wc_approved_amount" class="form-control" step="0.01" value="<?php echo $claim['approved_amount'] ?: $claim['claim_amount']; ?>">
                            </div>

                            <div class="mb-3">
                                <label for="wc_admin_remarks" class="form-label">टिप्पणी/कारण</label>
                                <textarea name="admin_remarks" id="wc_admin_remarks" class="form-control" rows="3"><?php echo e($claim['admin_remarks']); ?></textarea>
                            </div>

                            <?php $hasEmail = !empty($claim['email']); $hasPhone = !empty($claim['phone']); ?>
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

                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-save"></i> अपडेट गर्नुहोस्
                            </button>
                        </form>

                        <hr>

                        <!-- Delete Option -->
                        <form method="POST" onsubmit="return confirm('के तपाईं निश्चित हुनुहुन्छ?');">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="delete_claim" value="1">
                            <input type="hidden" name="claim_id" value="<?php echo $claim['id']; ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                                <i class="fas fa-trash"></i> दाबी हटाउनुहोस्
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="card mt-3">
                    <div class="card-body text-center">
                        <h6>ट्र्याकिङ ID</h6>
                        <p class="fs-5 font-monospace text-primary"><?php echo $claim['tracking_id']; ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<?php else: ?>
<?php
$wlfFilteredTotal = 0;
try {
    $cntSt = $db->prepare("SELECT COUNT(*) FROM member_welfare_claims WHERE $wlfWhere");
    $cntSt->execute($wlfParams);
    $wlfFilteredTotal = (int)$cntSt->fetchColumn();
} catch (Exception $e) {
    $wlfFilteredTotal = 0;
}

$stmt = $db->prepare("SELECT * FROM member_welfare_claims WHERE $wlfWhere ORDER BY created_at DESC LIMIT 500");
$stmt->execute($wlfParams);
$claims = $stmt->fetchAll();

// Get counts by status
$countStmt = $db->query("SELECT status, COUNT(*) as count FROM member_welfare_claims GROUP BY status");
$statusCounts = [];
while ($row = $countStmt->fetch()) {
    $statusCounts[$row['status']] = $row['count'];
}
$totalClaims = array_sum($statusCounts);
$wlfFilterQs = array_filter([
    'status' => $filterStatus, 'type' => $filterType, 'search' => $search,
    'date_from' => $dateFrom, 'date_to' => $dateTo,
], static fn($v) => $v !== null && $v !== '');
?>

<?php
echo adminPageHeader('कल्याण दाबी व्यवस्थापन', 'fa-hand-holding-heart',
    'सदस्य कल्याण दाबीहरू हेर्नुहोस् र व्यवस्थापन गर्नुहोस्',
    '<a href="welfare-claim-types.php" class="btn btn-outline-success btn-sm"><i class="fas fa-tags me-1"></i>दाबी प्रकार</a> ' .
    adminStatLink('?status=pending',   'warning', 'पेन्डिङ',     $statusCounts['pending']      ?? 0) . ' ' .
    adminStatLink('?status=approved',  'success', 'स्वीकृत',     $statusCounts['approved']     ?? 0) . ' ' .
    adminStatLink('?status=rejected',  'danger',  'अस्वीकृत',    $statusCounts['rejected']     ?? 0)
);
if ($flash = getFlash()):
?>
<div class="alert alert-<?php echo $flash['type']==='success'?'success':'danger'; ?> alert-dismissible fade show mb-3"><i class="fas fa-<?php echo $flash['type']==='success'?'check-circle':'exclamation-circle'; ?> me-2"></i><?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<!-- ── Stat Mini Row ── -->
<div class="stat-mini-row no-print">
    <a href="welfare-claims.php" class="stat-mini <?php echo !$filterStatus&&!$filterType&&!$search&&!$dateFrom&&!$dateTo?'active-filter':''; ?>">
        <div class="sm-icon ic-total"><i class="fas fa-hand-holding-heart"></i></div>
        <div class="sm-val"><?php echo $totalClaims; ?></div>
        <div class="sm-lbl">जम्मा</div>
    </a>
    <a href="?status=pending" class="stat-mini <?php echo $filterStatus==='pending'?'active-filter':''; ?>">
        <div class="sm-icon ic-pending"><i class="fas fa-clock"></i></div>
        <div class="sm-val"><?php echo $statusCounts['pending'] ?? 0; ?></div>
        <div class="sm-lbl">पेन्डिङ</div>
    </a>
    <a href="?status=under_review" class="stat-mini <?php echo $filterStatus==='under_review'?'active-filter':''; ?>">
        <div class="sm-icon ic-process"><i class="fas fa-search"></i></div>
        <div class="sm-val"><?php echo $statusCounts['under_review'] ?? 0; ?></div>
        <div class="sm-lbl">समीक्षाधीन</div>
    </a>
    <a href="?status=approved" class="stat-mini <?php echo $filterStatus==='approved'?'active-filter':''; ?>">
        <div class="sm-icon ic-approved"><i class="fas fa-check-circle"></i></div>
        <div class="sm-val"><?php echo $statusCounts['approved'] ?? 0; ?></div>
        <div class="sm-lbl">स्वीकृत</div>
    </a>
    <a href="?status=paid" class="stat-mini <?php echo $filterStatus==='paid'?'active-filter':''; ?>">
        <div class="sm-icon" style="background:#fef2f2;"><i class="fas fa-rupee-sign" style="color:var(--secondary-color,#c0392b);"></i></div>
        <div class="sm-val"><?php echo $statusCounts['paid'] ?? 0; ?></div>
        <div class="sm-lbl">भुक्तान</div>
    </a>
    <a href="?status=rejected" class="stat-mini <?php echo $filterStatus==='rejected'?'active-filter':''; ?>">
        <div class="sm-icon ic-rejected"><i class="fas fa-times-circle"></i></div>
        <div class="sm-val"><?php echo $statusCounts['rejected'] ?? 0; ?></div>
        <div class="sm-lbl">अस्वीकृत</div>
    </a>
</div>

<!-- ── Filter Bar ── -->
<div class="adm-filter-bar no-print">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-2 col-6">
            <label>स्थिति</label>
            <select name="status" class="form-select form-select-sm" onchange="this.closest('form').submit()">
                <option value="">सबै स्थिति</option>
                <?php foreach ($statusLabels as $key => $label): ?>
                <option value="<?php echo $key; ?>" <?php echo $filterStatus===$key?'selected':''; ?>><?php echo $label['np']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 col-6">
            <label>दाबी प्रकार</label>
            <select name="type" class="form-select form-select-sm" onchange="this.closest('form').submit()">
                <option value="">सबै</option>
                <?php foreach ($claimTypes as $key => $type): ?>
                <option value="<?php echo $key; ?>" <?php echo $filterType===$key?'selected':''; ?>><?php echo $type['np']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php echo adminExcelDateInputsHtml($dateFrom, $dateTo, 'col-md-2 col-6'); ?>
        <div class="col-md-4 col-12">
            <label>खोज्नुहोस्</label>
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                <input type="text" name="search" class="form-control" value="<?php echo e($search); ?>" placeholder="नाम, फोन, Tracking ID, सदस्य ID...">
            </div>
        </div>
        <div class="col-md-2 col-6">
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-search me-1"></i>खोज</button>
            <?php if ($filterStatus||$filterType||$search||$dateFrom||$dateTo): ?><a href="welfare-claims.php" class="btn btn-outline-secondary btn-sm w-100 mt-1"><i class="fas fa-times me-1"></i>हटाउनुहोस्</a><?php endif; ?>
        </div>
    </form>
    <?php echo adminExcelExportButtonHtml($wlfFilterQs, $wlfFilteredTotal); ?>
</div>

<!-- ── Claims Table ── -->
<div class="card border-0 shadow-sm" style="border-radius:10px;overflow:hidden;">
    <div class="tbl-header-bar no-print">
        <h6><i class="fas fa-hand-holding-heart me-2 text-primary"></i>सदस्य कल्याण दाबी सूची</h6>
        <span class="result-count-badge"><?php echo count($claims); ?> दाबी</span>
    </div>
    <div class="table-responsive admin-table-card">
        <table class="table-hover table app-table align-middle mb-0 table-responsive-stack">
                <thead>
                    <tr>
                        <th>ट्र्याकिङ ID</th>
                        <th>सदस्य</th>
                        <th>दाबी प्रकार</th>
                        <th>रकम</th>
                        <th>स्थिति</th>
                        <th>मिति</th>
                        <th>कार्य</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($claims)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <p class="text-muted">कुनै दाबी छैन।</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($claims as $claim): ?>
                    <tr>
                        <td>
                            <code><?php echo $claim['tracking_id']; ?></code>
                        </td>
                        <td>
                            <strong><?php echo e($claim['member_name']); ?></strong><br>
                            <small class="text-muted">
                                <i class="fas fa-phone"></i> <?php echo e($claim['phone']); ?>
                                <?php if ($claim['member_id']): ?>
                                | <i class="fas fa-id-card"></i> <?php echo e($claim['member_id']); ?>
                                <?php endif; ?>
                            </small>
                        </td>
                        <td>
                            <span class="badge" style="background-color: <?php echo $claimTypes[$claim['claim_type']]['color'] ?? '#888'; ?>">
                                <i class="fas <?php echo $claimTypes[$claim['claim_type']]['icon'] ?? 'fa-file'; ?>"></i>
                                <?php echo $claim['claim_type_np'] ?? $claimTypes[$claim['claim_type']]['np'] ?? $claim['claim_type']; ?>
                            </span>
                        </td>
                        <td>
                            रू. <?php echo number_format($claim['claim_amount'], 2); ?>
                            <?php if ($claim['approved_amount']): ?>
                            <br><small class="text-success">स्वीकृत: रू. <?php echo number_format($claim['approved_amount'], 2); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $statusLabels[$claim['status']]['class'] ?? 'secondary'; ?>">
                                <?php echo $statusLabels[$claim['status']]['np'] ?? $claim['status']; ?>
                            </span>
                        </td>
                        <td>
                            <?php echo formatNepaliDate($claim['created_at']); ?>
                        </td>
                        <td>
                            <div class="adm-action-icons">
                            <a href="welfare-claims.php?action=view&id=<?php echo $claim['id']; ?>" class="adm-icon-btn adm-icon-btn--view" title="हेर्नुहोस्" aria-label="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="?export=csv&amp;id=<?php echo (int)$claim['id']; ?>" class="adm-icon-btn" title="Excel" aria-label="Excel"><i class="fas fa-file-excel text-success"></i></a>
                            <?php echo adminPrintFormIcon('welfare', (int)$claim['id']); ?>
                            <form method="POST" class="adm-icon-form" onsubmit="return confirm('हटाउने?');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="delete_claim" value="1">
                                <input type="hidden" name="claim_id" value="<?php echo $claim['id']; ?>">
                                <button type="submit" class="adm-icon-btn adm-icon-btn--delete" title="हटाउनुहोस्" aria-label="Delete">
                                    <i class="fas fa-trash-can"></i>
                                </button>
                            </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php require_once 'includes/admin-footer.php'; ?>
