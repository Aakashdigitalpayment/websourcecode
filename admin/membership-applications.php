<?php
/**
 * Admin: सदस्यता अनुरोध — नयाँ व्यक्ति (Member ID बिना)
 * Approve गर्दा admin ले Member ID हाल्छ → members SSOT stub
 */
$pageTitle   = 'सदस्यता अनुरोध';
$currentPage = 'membership-apps';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/../includes/auth-roles.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role('admin');
    checkCSRF();
}

$db = getDB();
if ($db instanceof PDO && function_exists('membershipEnsureTable')) {
    membershipEnsureTable($db);
}

$adminId = (int)($_SESSION['admin_id'] ?? 0);

/* ── Approve with Member ID ── */
if (isset($_POST['membership_approve'])) {
    $id = (int)($_POST['id'] ?? 0);
    $sadasyata = strtoupper(trim(clean_text($_POST['assigned_sadasyata'] ?? '')));
    $remarks = clean_text($_POST['admin_remarks'] ?? '');
    $r = membershipApproveWithMemberId($db, $id, $sadasyata, $adminId, $remarks);
    if (!empty($r['ok'])) {
        $msg = $r['message'] ?? 'स्वीकृत।';
        setFlash(
            'success',
            $msg . ' Members सूचीमा खोज्नुहोस् / Online KYM भर्न भन्नुहोस्।'
        );
    } else {
        setFlash('error', $r['message'] ?? 'स्वीकृत असफल।');
    }
    redirect('membership-applications.php?view=' . $id);
}

/* ── Reject ── */
if (isset($_POST['membership_reject'])) {
    $id = (int)($_POST['id'] ?? 0);
    $remarks = clean_text($_POST['admin_remarks'] ?? '');
    $r = membershipReject($db, $id, $adminId, $remarks);
    setFlash(!empty($r['ok']) ? 'success' : 'error', $r['message'] ?? '');
    redirect('membership-applications.php?view=' . $id);
}

$statusFilter = trim((string)($_GET['status'] ?? 'pending'));
if (!in_array($statusFilter, ['', 'pending', 'approved', 'rejected', 'all'], true)) {
    $statusFilter = 'pending';
}
if ($statusFilter === 'all') {
    $statusFilter = '';
}
$search = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200, 'UTF-8');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$where = '1=1';
$params = [];
if ($statusFilter !== '') {
    $where .= ' AND status=?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where .= ' AND (full_name LIKE ? OR mobile LIKE ? OR email LIKE ? OR tracking_id LIKE ? OR assigned_sadasyata LIKE ?)';
    $t = '%' . $search . '%';
    $params = array_merge($params, [$t, $t, $t, $t, $t]);
}

$applications = [];
$totalCount = 0;
$pendingCount = 0;
$approvedCount = 0;
$rejectedCount = 0;
try {
    $cnt = $db->prepare("SELECT COUNT(*) FROM membership_applications WHERE $where");
    $cnt->execute($params);
    $totalCount = (int)$cnt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalCount / $limit));
    $st = $db->prepare(
        "SELECT * FROM membership_applications WHERE $where ORDER BY created_at DESC LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset
    );
    $st->execute($params);
    $applications = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $pendingCount = membershipPendingCount($db);
    $approvedCount = (int)$db->query("SELECT COUNT(*) FROM membership_applications WHERE status='approved'")->fetchColumn();
    $rejectedCount = (int)$db->query("SELECT COUNT(*) FROM membership_applications WHERE status='rejected'")->fetchColumn();
} catch (Throwable $e) {
    error_log('[membership-applications] ' . $e->getMessage());
    $totalPages = 1;
}

$viewApp = null;
if (isset($_GET['view'])) {
    $vs = $db->prepare('SELECT * FROM membership_applications WHERE id=? LIMIT 1');
    $vs->execute([(int)$_GET['view']]);
    $viewApp = $vs->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$viewApp) {
        setFlash('error', 'आवेदन फेला परेन।');
        redirect('membership-applications.php');
    }
}

$statusClass = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'];
$statusLabel = ['pending' => 'पेन्डिङ', 'approved' => 'स्वीकृत', 'rejected' => 'अस्वीकृत'];
?>

<div class="container-fluid py-3">

<?php if ($flash = getFlash()): ?>
<div class="alert alert-<?php echo ($flash['type'] ?? '') === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show">
    <?php echo htmlspecialchars((string)($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (function_exists('memberSsotAdminHelpHtml')) {
    echo memberSsotAdminHelpHtml('membership');
} ?>

<?php if ($viewApp): ?>
<?php
    $sc = $statusClass[$viewApp['status'] ?? ''] ?? 'secondary';
    $sl = $statusLabel[$viewApp['status'] ?? ''] ?? ($viewApp['status'] ?? '');
?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <a href="membership-applications.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>सूची</a>
    <h4 class="mb-0 fw-bold text-success">सदस्यता अनुरोध</h4>
    <code><?php echo htmlspecialchars((string)$viewApp['tracking_id']); ?></code>
    <span class="badge bg-<?php echo $sc; ?>"><?php echo htmlspecialchars($sl); ?></span>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><th width="160">नाम</th><td><strong><?php echo htmlspecialchars((string)$viewApp['full_name']); ?></strong></td></tr>
                    <tr><th>मोबाइल</th><td><a href="tel:<?php echo htmlspecialchars((string)$viewApp['mobile']); ?>"><?php echo htmlspecialchars((string)$viewApp['mobile']); ?></a></td></tr>
                    <tr><th>इमेल</th><td><?php echo htmlspecialchars((string)($viewApp['email'] ?: '—')); ?></td></tr>
                    <tr><th>ठेगाना</th><td><?php echo nl2br(htmlspecialchars((string)($viewApp['address'] ?: '—'))); ?></td></tr>
                    <tr><th>नागरिकता नं.</th><td><code><?php echo htmlspecialchars((string)($viewApp['citizenship_no'] ?: '—')); ?></code></td></tr>
                    <tr><th>आवेदक कैफियत</th><td><?php echo nl2br(htmlspecialchars((string)($viewApp['remarks'] ?: '—'))); ?></td></tr>
                    <tr><th>दर्ता</th><td><?php echo htmlspecialchars((string)($viewApp['created_at'] ?? '')); ?></td></tr>
                    <?php if (!empty($viewApp['assigned_sadasyata'])): ?>
                    <tr><th>Assigned Member ID</th><td><code class="text-success fw-bold"><?php echo htmlspecialchars((string)$viewApp['assigned_sadasyata']); ?></code></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($viewApp['member_pk'])): ?>
                    <tr><th>Members PK</th>
                        <td><a href="members.php?view=<?php echo (int)$viewApp['member_pk']; ?>">#<?php echo (int)$viewApp['member_pk']; ?></a></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($viewApp['admin_remarks'])): ?>
                    <tr><th>Admin कैफियत</th><td><?php echo nl2br(htmlspecialchars((string)$viewApp['admin_remarks'])); ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <?php if (($viewApp['status'] ?? '') === 'pending'): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-bold text-success py-2">
                <i class="fas fa-check me-1"></i>स्वीकृत + Member ID दिनुहोस्
            </div>
            <div class="card-body">
                <p class="small text-muted">सहकारीको वास्तविक सदस्यता नं. हाल्नुहोस्। यसले <code>members</code> stub बनाउँछ (पासवर्ड बिना)। त्यसपछि व्यक्तिले Online KYM भर्न सक्छ।</p>
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="membership_approve" value="1">
                    <input type="hidden" name="id" value="<?php echo (int)$viewApp['id']; ?>">
                    <div class="mb-2">
                        <label for="msa_assigned_sadasyata" class="form-label small fw-semibold">Member ID (सदस्यता नं.) <span class="text-danger">*</span></label>
                        <input type="text" name="assigned_sadasyata" id="msa_assigned_sadasyata" class="form-control" required
                               placeholder="उदा. 1234" autocomplete="off">
                    </div>
                    <div class="mb-2">
                        <label for="msa_admin_remarks" class="form-label small fw-semibold">Admin कैफियत</label>
                        <textarea name="admin_remarks" id="msa_admin_remarks" class="form-control" rows="2"></textarea>
                    </div>
                    <button type="submit" class="btn btn-success btn-sm"
                            onclick="return confirm('Member ID दिएर members stub बनाउने?');">
                        <i class="fas fa-user-check me-1"></i>Approve + Create Member
                    </button>
                </form>
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold text-danger py-2">अस्वीकृत</div>
            <div class="card-body">
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="membership_reject" value="1">
                    <input type="hidden" name="id" value="<?php echo (int)$viewApp['id']; ?>">
                    <textarea name="admin_remarks" class="form-control mb-2" rows="2" placeholder="कारण..."></textarea>
                    <button type="submit" class="btn btn-outline-danger btn-sm"
                            onclick="return confirm('अस्वीकृत गर्ने?');">Reject</button>
                </form>
            </div>
        </div>
        <?php elseif (($viewApp['status'] ?? '') === 'approved'): ?>
        <div class="alert alert-success small">
            स्वीकृत। Member ID: <code><?php echo htmlspecialchars((string)($viewApp['assigned_sadasyata'] ?? '')); ?></code><br>
            <a href="members.php?search=<?php echo urlencode((string)($viewApp['assigned_sadasyata'] ?? '')); ?>">Members खोल्नुहोस्</a>
            · व्यक्तिलाई <a href="../online-kyc.php?path=member">Online KYM</a> भर्न भन्नुहोस्।
        </div>
        <?php else: ?>
        <div class="alert alert-secondary small">यो आवेदन अस्वीकृत छ।</div>
        <?php endif; ?>
    </div>
</div>

<?php else: /* list */ ?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <h4 class="mb-0 fw-bold text-success"><i class="fas fa-user-plus me-2"></i>सदस्यता अनुरोध</h4>
    <div class="d-flex gap-2 small">
        <a href="?status=pending" class="badge text-decoration-none <?php echo $statusFilter === 'pending' ? 'bg-warning text-dark' : 'bg-light text-dark border'; ?>">पेन्डिङ <?php echo (int)$pendingCount; ?></a>
        <a href="?status=approved" class="badge text-decoration-none <?php echo $statusFilter === 'approved' ? 'bg-success' : 'bg-light text-dark border'; ?>">स्वीकृत <?php echo (int)$approvedCount; ?></a>
        <a href="?status=rejected" class="badge text-decoration-none <?php echo $statusFilter === 'rejected' ? 'bg-danger' : 'bg-light text-dark border'; ?>">अस्वीकृत <?php echo (int)$rejectedCount; ?></a>
        <a href="?status=all" class="badge text-decoration-none <?php echo $statusFilter === '' ? 'bg-primary' : 'bg-light text-dark border'; ?>">सबै</a>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter === '' ? 'all' : $statusFilter); ?>">
            <div class="col-md-8">
                <input type="search" name="search" class="form-control form-control-sm"
                       value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="नाम, मोबाइल, tracking, Member ID...">
            </div>
            <div class="col-md-4">
                <button class="btn btn-sm btn-success"><i class="fas fa-search me-1"></i>खोज</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>आवेदक</th>
                    <th>सम्पर्क</th>
                    <th>Tracking</th>
                    <th>Member ID</th>
                    <th>स्थिति</th>
                    <th>दर्ता</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$applications): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">कुनै अनुरोध छैन।</td></tr>
            <?php else: foreach ($applications as $row):
                $sc = $statusClass[$row['status'] ?? ''] ?? 'secondary';
                $sl = $statusLabel[$row['status'] ?? ''] ?? ($row['status'] ?? '');
            ?>
                <tr>
                    <td>
                        <div class="fw-semibold small"><?php echo htmlspecialchars((string)$row['full_name']); ?></div>
                        <div class="text-muted small"><?php echo htmlspecialchars(mb_substr((string)($row['address'] ?? ''), 0, 40)); ?></div>
                    </td>
                    <td class="small">
                        <?php echo htmlspecialchars((string)$row['mobile']); ?><br>
                        <span class="text-muted"><?php echo htmlspecialchars((string)($row['email'] ?? '')); ?></span>
                    </td>
                    <td><code class="small"><?php echo htmlspecialchars((string)$row['tracking_id']); ?></code></td>
                    <td><code class="small"><?php echo htmlspecialchars((string)($row['assigned_sadasyata'] ?: '—')); ?></code></td>
                    <td><span class="badge bg-<?php echo $sc; ?>"><?php echo htmlspecialchars($sl); ?></span></td>
                    <td class="small text-muted"><?php echo htmlspecialchars(substr((string)($row['created_at'] ?? ''), 0, 16)); ?></td>
                    <td>
                        <a href="?view=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-outline-success">हेर्नुहोस्</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer bg-white d-flex justify-content-between small">
        <span><?php echo (int)$totalCount; ?> जम्मा</span>
        <nav>
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a class="btn btn-sm <?php echo $p === $page ? 'btn-success' : 'btn-outline-secondary'; ?>"
               href="?<?php echo htmlspecialchars(http_build_query(['status' => $statusFilter === '' ? 'all' : $statusFilter, 'search' => $search, 'page' => $p])); ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>
</div>

<?php require_once 'includes/admin-footer.php'; ?>
