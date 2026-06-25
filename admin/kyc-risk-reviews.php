<?php
$pageTitle = 'KYC Risk Review';
$currentPage = 'kyc-risk-reviews';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_reviewed'])) {
    require_role('admin');
    checkCSRF();
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $s = $db->prepare("SELECT risk_category FROM kyc_applications WHERE id=? LIMIT 1");
            $s->execute([$id]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $rk = strtolower(trim((string)($row['risk_category'] ?? 'medium')));
                if (!in_array($rk, ['low','medium','high'], true)) $rk = 'medium';
                $years = $rk === 'high' ? 1 : ($rk === 'low' ? 3 : 2);
                $due = date('Y-m-d', strtotime('+' . $years . ' years'));
                $u = $db->prepare("UPDATE kyc_applications
                                   SET kyc_verified_at=NOW(),
                                       risk_review_due_at=?,
                                       risk_review_status='normal',
                                       updated_at=NOW()
                                   WHERE id=?");
                $u->execute([$due, $id]);
                setFlash('success', 'KYC review cycle reset गरियो।');
            }
        } catch (Throwable $e) {
            setFlash('error', 'Update गर्दा त्रुटि भयो।');
        }
    }
    redirect('kyc-risk-reviews.php');
}

// ensure auto flags are in sync
try {
    $db->exec("UPDATE kyc_applications
               SET risk_review_due_at = CASE
                    WHEN kyc_verified_at IS NULL THEN NULL
                    WHEN risk_category='high' THEN DATE_ADD(DATE(kyc_verified_at), INTERVAL 1 YEAR)
                    WHEN risk_category='low' THEN DATE_ADD(DATE(kyc_verified_at), INTERVAL 3 YEAR)
                    ELSE DATE_ADD(DATE(kyc_verified_at), INTERVAL 2 YEAR)
               END
               WHERE status='approved'");
    $db->exec("UPDATE kyc_applications
               SET risk_review_status = CASE
                    WHEN status<>'approved' OR risk_review_due_at IS NULL THEN 'normal'
                    WHEN risk_review_due_at <= CURDATE() THEN 'due_review'
                    ELSE 'normal'
               END");
} catch (Throwable $e) {}

$filter = strtolower(trim((string)($_GET['filter'] ?? 'due')));
if (!in_array($filter, ['due','all','high'], true)) $filter = 'due';
$where = "status='approved'";
if ($filter === 'due') $where .= " AND risk_review_status='due_review'";
if ($filter === 'high') $where .= " AND risk_category='high'";

$rows = [];
try {
    $q = $db->query("SELECT id, member_id, full_name, mobile, email, risk_category, kyc_verified_at, risk_review_due_at, risk_review_status
                     FROM kyc_applications
                     WHERE {$where}
                     ORDER BY (risk_review_status='due_review') DESC, risk_review_due_at ASC, id DESC
                     LIMIT 500");
    $rows = $q ? ($q->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
} catch (Throwable $e) {
    $rows = [];
}

$flash = getFlash();
echo adminPageHeader('KYC Risk Review', 'fa-shield-halved', 'Risk-based review cycle (Low=3Y, Medium=2Y, High=1Y)');
if ($flash) echo adminAlert($flash['type'] === 'success' ? 'success' : 'danger', $flash['message']);
?>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end mb-3">
            <div class="col-md-3">
                <label class="form-label">Filter</label>
                <select name="filter" class="form-select">
                    <option value="due" <?php echo $filter==='due'?'selected':''; ?>>Due Review only</option>
                    <option value="high" <?php echo $filter==='high'?'selected':''; ?>>High Risk only</option>
                    <option value="all" <?php echo $filter==='all'?'selected':''; ?>>All approved</option>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100" type="submit"><i class="fas fa-filter me-1"></i>Apply</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table-hover table table-sm align-middle">
                <thead>
                    <tr>
                        <th>Member</th>
                        <th>Contact</th>
                        <th>Risk</th>
                        <th>Verified At</th>
                        <th>Next Review Due</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No records.</td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <?php
                    $rk = strtolower(trim((string)($r['risk_category'] ?? 'medium')));
                    $rkClass = $rk === 'high' ? 'bg-danger' : ($rk === 'low' ? 'bg-success' : 'bg-warning text-dark');
                    $isDue = (($r['risk_review_status'] ?? '') === 'due_review');
                    ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?php echo htmlspecialchars($r['full_name'] ?? '—'); ?></div>
                            <div class="small text-muted"><code><?php echo htmlspecialchars($r['member_id'] ?? '—'); ?></code></div>
                        </td>
                        <td>
                            <div><?php echo htmlspecialchars($r['mobile'] ?? '—'); ?></div>
                            <div class="small text-muted"><?php echo htmlspecialchars($r['email'] ?? '—'); ?></div>
                        </td>
                        <td><span class="badge <?php echo $rkClass; ?>"><?php echo strtoupper($rk); ?></span></td>
                        <td><?php echo htmlspecialchars($r['kyc_verified_at'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($r['risk_review_due_at'] ?? '—'); ?></td>
                        <td><span class="badge <?php echo $isDue ? 'bg-danger' : 'bg-success'; ?>"><?php echo $isDue ? 'Due Review' : 'Normal'; ?></span></td>
                        <td>
                            <form method="POST" class="d-inline">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="mark_reviewed" value="1">
                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                <button class="btn btn-sm btn-outline-primary" type="submit">Mark Reviewed</button>
                            </form>
                            <a class="btn btn-sm btn-outline-secondary" href="kyc-applications.php?view=<?php echo (int)$r['id']; ?>">Open</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'includes/admin-footer.php'; ?>
