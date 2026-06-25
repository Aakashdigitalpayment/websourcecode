<?php
/**
 * Admin Vendor Enlistment Management
 * भेन्डर सूचीकरण आवेदन व्यवस्थापन
 */
$pageTitle = 'भेन्डर सूचीकरण';
$currentPage = 'vendor-enlistment';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

try {
    $db = getDB();
} catch (Exception $e) {
    $db = null;
}

/* CSRF सुरक्षा: POST अनुरोध प्रमाणित गर्नुहोस् */
checkCSRF();
$csrfToken = generateCSRFToken();

require_once __DIR__ . '/../includes/vendors-tables.php';
if ($db instanceof PDO) {
    ensureVendorsTables($db);
}

$success = '';
$error   = '';

/* ─────────────────────────────────────────────
   POST: स्थिति परिवर्तन / मेटाउने
───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'सुरक्षा जाँच असफल भयो।';
    } else {
        $action = clean_text($_POST['action'] ?? '');
        $id = intval($_POST['id'] ?? 0);

        if ($action === 'status' && $id) {
            $status = clean_text($_POST['status'] ?? '');
            if (in_array($status, ['pending', 'approved', 'rejected'])) {
                $db->prepare("UPDATE vendors SET status = ? WHERE id = ?")->execute([$status, $id]);
                setFlash('success', 'भेन्डर स्थिति अपडेट भयो।');
            }
            redirect('vendor-enlistment.php');
        }

        if ($action === 'delete' && $id) {
            $db->prepare("DELETE FROM vendors WHERE id = ?")->execute([$id]);
            setFlash('success', 'भेन्डर रेकर्ड मेटाइयो।');
            redirect('vendor-enlistment.php');
        }
    }
}

/* ─────────────────────────────────────────────
   GET: Filter by status tab
───────────────────────────────────────────── */
$tab = $_GET['tab'] ?? 'all';
$validTabs = ['all', 'pending', 'approved', 'rejected'];
if (!in_array($tab, $validTabs, true)) {
    $tab = 'all';
}

try {
    $counts = [
        'all'      => core_safe_count($db, "SELECT COUNT(*) FROM vendors", '[vendor-enlistment all]'),
        'pending'  => core_safe_count($db, "SELECT COUNT(*) FROM vendors WHERE status='pending'", '[vendor-enlistment pending]'),
        'approved' => core_safe_count($db, "SELECT COUNT(*) FROM vendors WHERE status='approved'", '[vendor-enlistment approved]'),
        'rejected' => core_safe_count($db, "SELECT COUNT(*) FROM vendors WHERE status='rejected'", '[vendor-enlistment rejected]'),
    ];
    if ($tab === 'all') {
        $vendors = $db->query("SELECT * FROM vendors ORDER BY status='pending' DESC, created_at DESC")->fetchAll();
    } else {
        $vendors = $db->prepare("SELECT * FROM vendors WHERE status = ? ORDER BY created_at DESC");
        $vendors->execute([$tab]);
        $vendors = $vendors->fetchAll();
    }
} catch (Exception $e) {
    $vendors = [];
    $counts = ['all'=>0,'pending'=>0,'approved'=>0,'rejected'=>0];
}

/* Detail view */
$detail = null;
if (isset($_GET['view'])) {
    $vid = intval($_GET['view']);
    try {
        $stmt = $db->prepare("SELECT * FROM vendors WHERE id = ?");
        $stmt->execute([$vid]);
        $detail = $stmt->fetch();
    } catch (Exception $e) {}
}

$statusLabels = [
    'pending'  => ['np' => 'विचाराधीन', 'class' => 'warning', 'text' => 'text-dark'],
    'approved' => ['np' => 'स्वीकृत',   'class' => 'success', 'text' => 'text-white'],
    'rejected' => ['np' => 'अस्वीकृत',  'class' => 'danger',  'text' => 'text-white'],
];
$businessLabels = [
    'supplier'         => 'आपूर्तिकर्ता',
    'contractor'       => 'ठेकेदार',
    'service_provider' => 'सेवा प्रदायक',
    'trader'           => 'व्यापारी',
    'other'            => 'अन्य',
];
?>

<?php echo adminPageHeader(
    'भेन्डर सूचीकरण',
    'fa-store',
    'वेबसाइटबाट आएका भेन्डर दर्ता आवेदनहरूको व्यवस्थापन।',
    '<span class="badge admin-stat-badge bg-warning-subtle text-warning border border-warning border-opacity-25 me-2"><i class="fas fa-clock me-1"></i>पेन्डिङ: ' . $counts['pending'] . '</span>'
    . '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25"><i class="fas fa-layer-group me-1"></i>जम्मा: ' . $counts['all'] . '</span>'
); ?>
<?php $_flash = getFlash(); if ($_flash) echo adminAlert($_flash['type'], $_flash['message']); ?>
<?php if ($error) echo adminAlert('danger', $error); ?>

<?php if ($detail): ?>
<!-- ══ Detail View ══ -->
<div class="card admin-table-card mb-3 arv-legacy-detail">
    <div class="card-header d-flex align-items-center justify-content-between py-2">
        <span class="fw-semibold"><i class="fas fa-store me-2"></i><?php echo htmlspecialchars($detail['company_name']); ?></span>
        <a href="vendor-enlistment.php?tab=<?php echo $tab; ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i>सूचीमा फर्कनुहोस्
        </a>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="adm-info-group">
                    <div class="adm-info-group-header"><i class="fas fa-store"></i>भेन्डर जानकारी</div>
                    <table class="table table-sm adm-detail-table mb-0">
                        <tr><th>कम्पनी/फर्म</th><td><?php echo htmlspecialchars($detail['company_name']); ?></td></tr>
                        <tr><th>मालिक/प्रोप्राइटर</th><td><?php echo htmlspecialchars($detail['owner_name'] ?? '—'); ?></td></tr>
                        <tr><th>ठेगाना</th><td><?php echo htmlspecialchars($detail['address'] ?? '—'); ?></td></tr>
                        <tr><th>फोन</th><td><?php echo htmlspecialchars($detail['phone'] ?? '—'); ?></td></tr>
                        <tr><th>इमेल</th><td><?php echo htmlspecialchars($detail['email'] ?? '—'); ?></td></tr>
                        <tr><th>प्यान/भ्याट</th><td><?php echo htmlspecialchars($detail['pan_no'] ?? '—'); ?></td></tr>
                        <tr><th>व्यवसाय प्रकार</th><td><?php echo $businessLabels[$detail['business_type']] ?? htmlspecialchars($detail['business_type'] ?? '—'); ?></td></tr>
                        <tr><th>आवेदन मिति</th><td><?php echo formatNepaliDate($detail['created_at']); ?></td></tr>
                        <tr>
                            <th>हालको स्थिति</th>
                            <td>
                                <span class="badge bg-<?php echo $statusLabels[$detail['status']]['class']; ?> <?php echo $statusLabels[$detail['status']]['text']; ?>">
                                    <?php echo $statusLabels[$detail['status']]['np']; ?>
                                </span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label fw-semibold text-muted small">विवरण</label>
                    <div class="p-3 bg-light rounded ven-desc-box">
                        <?php echo nl2br(htmlspecialchars($detail['description'] ?? '—')); ?>
                    </div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <?php if ($detail['status'] !== 'approved'): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="status">
                        <input type="hidden" name="id" value="<?php echo $detail['id']; ?>">
                        <input type="hidden" name="status" value="approved">
                        <button type="submit" class="btn btn-success btn-sm">
                            <i class="fas fa-check me-1"></i>स्वीकृत गर्नुहोस्
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php if ($detail['status'] !== 'rejected'): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="status">
                        <input type="hidden" name="id" value="<?php echo $detail['id']; ?>">
                        <input type="hidden" name="status" value="rejected">
                        <button type="submit" class="btn btn-danger btn-sm">
                            <i class="fas fa-times me-1"></i>अस्वीकृत गर्नुहोस्
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php if ($detail['status'] !== 'pending'): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="status">
                        <input type="hidden" name="id" value="<?php echo $detail['id']; ?>">
                        <input type="hidden" name="status" value="pending">
                        <button type="submit" class="btn btn-warning btn-sm text-dark">
                            <i class="fas fa-undo me-1"></i>विचाराधीनमा राख्नुहोस्
                        </button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" onsubmit="return confirm('के तपाईं पक्का हुनुहुन्छ?')">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo $detail['id']; ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm">
                            <i class="fas fa-trash me-1"></i>मेटाउनुहोस्
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ══ List View ══ -->

<!-- ── Stat Mini Row ── -->
<div class="stat-mini-row no-print">
    <a href="?tab=all" class="stat-mini <?php echo $tab==='all'?'active-filter':''; ?>">
        <div class="sm-icon ic-total"><i class="fas fa-store"></i></div>
        <div class="sm-val"><?php echo $counts['all']; ?></div>
        <div class="sm-lbl">जम्मा भेन्डर</div>
    </a>
    <a href="?tab=pending" class="stat-mini <?php echo $tab==='pending'?'active-filter':''; ?>">
        <div class="sm-icon ic-pending"><i class="fas fa-clock"></i></div>
        <div class="sm-val"><?php echo $counts['pending']; ?></div>
        <div class="sm-lbl">विचाराधीन</div>
    </a>
    <a href="?tab=approved" class="stat-mini <?php echo $tab==='approved'?'active-filter':''; ?>">
        <div class="sm-icon ic-approved"><i class="fas fa-check-circle"></i></div>
        <div class="sm-val"><?php echo $counts['approved']; ?></div>
        <div class="sm-lbl">स्वीकृत</div>
    </a>
    <a href="?tab=rejected" class="stat-mini <?php echo $tab==='rejected'?'active-filter':''; ?>">
        <div class="sm-icon ic-rejected"><i class="fas fa-times-circle"></i></div>
        <div class="sm-val"><?php echo $counts['rejected']; ?></div>
        <div class="sm-lbl">अस्वीकृत</div>
    </a>
</div>

<!-- ── Vendor Table ── -->
<div class="card border-0 shadow-sm app-rounded-card">
    <div class="tbl-header-bar no-print">
        <h6><i class="fas fa-store me-2 ven-title-icon"></i>भेन्डर सूची — <?php echo $tab==='all'?'सबै':(ucfirst($tab)); ?></h6>
        <div class="d-flex gap-2 align-items-center">
            <div class="admin-search-wrap d-flex align-items-center gap-2">
                <div class="input-group input-group-sm ven-search-group">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 admin-table-search" placeholder="खोज्नुहोस्..." autocomplete="off">
                </div>
                <small class="text-muted search-count"></small>
            </div>
            <span class="result-count-badge"><?php echo count($vendors); ?> भेन्डर</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table-hover table app-table align-middle mb-0">
            <thead>
                <tr>
                    <th class="ven-col-company">कम्पनी / फर्म</th>
                    <th>मालिक</th>
                    <th>सम्पर्क</th>
                    <th>व्यवसाय प्रकार</th>
                    <th>मिति</th>
                    <th>स्थिति</th>
                    <th class="no-print">कार्यहरू</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($vendors)): ?>
            <tr class="no-results-row"><td colspan="7"><i class="fas fa-store fa-2x d-block mb-2"></i>कुनै भेन्डर आवेदन छैन।</td></tr>
            <?php endif; foreach ($vendors as $v):
                $vStatus = $v['status'] ?? 'pending';
                $initLetter = mb_strtoupper(mb_substr($v['company_name'] ?? 'V', 0, 1));
            ?>
            <tr data-status="<?php echo htmlspecialchars($vStatus); ?>">
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="av-letter av-vnd"><?php echo $initLetter; ?></div>
                        <div>
                            <div class="cell-main"><?php echo htmlspecialchars($v['company_name']); ?></div>
                            <?php if ($v['pan_no']): ?><div class="cell-sub">PAN: <?php echo htmlspecialchars($v['pan_no']); ?></div><?php endif; ?>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="cell-main"><?php echo htmlspecialchars($v['owner_name'] ?? '—'); ?></div>
                    <?php if ($v['district'] ?? ''): ?><div class="cell-sub"><?php echo htmlspecialchars($v['district']); ?></div><?php endif; ?>
                </td>
                <td>
                    <?php if ($v['phone']): ?><div class="cell-main"><i class="fas fa-phone fa-xs text-muted me-1"></i><?php echo htmlspecialchars($v['phone']); ?></div><?php endif; ?>
                    <?php if ($v['email']): ?><div class="cell-sub"><?php echo htmlspecialchars($v['email']); ?></div><?php endif; ?>
                </td>
                <td><span class="badge bg-info-subtle text-info border border-info border-opacity-25 fw-normal"><?php echo $businessLabels[$v['business_type']] ?? htmlspecialchars($v['business_type'] ?? '—'); ?></span></td>
                <td><div class="cell-sub"><?php echo formatNepaliDate($v['created_at']); ?></div></td>
                <td>
                    <span class="badge-status badge-<?php echo $vStatus==='pending'?'pending':($vStatus==='approved'?'approved':'rejected'); ?>">
                        <?php echo $statusLabels[$vStatus]['np'] ?? $vStatus; ?>
                    </span>
                </td>
                <td class="no-print">
                    <div class="adm-action-icons">
                        <a href="?view=<?php echo $v['id']; ?>&tab=<?php echo $tab; ?>" class="adm-icon-btn adm-icon-btn--view" title="विवरण" aria-label="View"><i class="fas fa-eye"></i></a>
                        <?php if ($v['status'] === 'pending'): ?>
                        <form method="POST" class="qaction-form" onsubmit="return confirm('भेन्डर स्वीकृत गर्नुहुन्छ?')">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="status">
                            <input type="hidden" name="id" value="<?php echo $v['id']; ?>">
                            <input type="hidden" name="status" value="approved">
                            <button type="submit" class="btn-qapprove"><i class="fas fa-check me-1"></i>स्वीकृत</button>
                        </form>
                        <form method="POST" class="qaction-form" onsubmit="return confirm('भेन्डर अस्वीकृत गर्नुहुन्छ?')">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="status">
                            <input type="hidden" name="id" value="<?php echo $v['id']; ?>">
                            <input type="hidden" name="status" value="rejected">
                            <button type="submit" class="btn-qreject"><i class="fas fa-times me-1"></i>अस्वीकृत</button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" class="adm-icon-form" onsubmit="return confirm('के तपाईं पक्का हुनुहुन्छ?')">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $v['id']; ?>">
                            <button type="submit" class="adm-icon-btn adm-icon-btn--delete" title="मेटाउनुहोस्" aria-label="Delete"><i class="fas fa-trash-can"></i></button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php require_once 'includes/admin-footer.php'; ?>
