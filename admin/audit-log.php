<?php
$__t = static function (string $np, string $en): string {
    $lang = (string)($_SESSION['admin_lang'] ?? $_SESSION['lang'] ?? 'np');
    return strtolower($lang) === 'en' ? $en : $np;
};
$pageTitle = $__t('अडिट लग', 'Audit Log');
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once 'includes/audit-log.php';

// ── Ensure table exists (safe for upgrades) ──────────────────────────────
try {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS admin_activity_log (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        admin_id    INT NOT NULL DEFAULT 0,
        action      VARCHAR(64) NOT NULL,
        target_type VARCHAR(32) NULL,
        target_id   INT NULL,
        details     TEXT NULL,
        ip          VARCHAR(45) NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_activity_admin  (admin_id, created_at),
        INDEX idx_activity_action (action),
        INDEX idx_activity_target (target_type, target_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (\Throwable $e) { /* table likely already exists */ }

// ── Filters ───────────────────────────────────────────────────────────────
$perPage   = 30;
$page      = max(1, (int)($_GET['p'] ?? 1));
$offset    = ($page - 1) * $perPage;

$filterAdmin  = (int)($_GET['admin_id'] ?? 0);
$filterAction = trim((string)($_GET['action_type'] ?? ''));
$filterFrom   = trim((string)($_GET['from_date'] ?? ''));
$filterTo     = trim((string)($_GET['to_date'] ?? ''));

$where  = [];
$params = [];

if ($filterAdmin > 0) {
    $where[]  = 'l.admin_id = ?';
    $params[] = $filterAdmin;
}
if ($filterAction !== '') {
    $where[]  = 'l.action = ?';
    $params[] = $filterAction;
}
if ($filterFrom !== '') {
    $where[]  = 'DATE(l.created_at) >= ?';
    $params[] = $filterFrom;
}
if ($filterTo !== '') {
    $where[]  = 'DATE(l.created_at) <= ?';
    $params[] = $filterTo;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Total count ───────────────────────────────────────────────────────────
$totalRows = 0;
try {
    $cStmt = $db->prepare("SELECT COUNT(*) FROM admin_activity_log l $whereSql");
    $cStmt->execute($params);
    $totalRows = (int)$cStmt->fetchColumn();
} catch (\Throwable $e) {}

$totalPages = max(1, (int)ceil($totalRows / $perPage));

// ── Rows ──────────────────────────────────────────────────────────────────
$rows = [];
try {
    $rStmt = $db->prepare(
        "SELECT l.*, COALESCE(a.full_name, a.username, CONCAT('Admin #', l.admin_id)) AS admin_name
         FROM admin_activity_log l
         LEFT JOIN admin_users a ON a.id = l.admin_id
         $whereSql
         ORDER BY l.created_at DESC
         LIMIT $perPage OFFSET $offset"
    );
    $rStmt->execute($params);
    $rows = $rStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// ── All distinct admins for filter dropdown ────────────────────────────────
$admins = [];
try {
    $admins = $db->query(
        "SELECT DISTINCT l.admin_id,
                COALESCE(a.full_name, a.username, CONCAT('Admin #', l.admin_id)) AS admin_name
         FROM admin_activity_log l
         LEFT JOIN admin_users a ON a.id = l.admin_id
         ORDER BY admin_name"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// ── All distinct action types for filter dropdown ─────────────────────────
$actionTypes = [];
try {
    $actionTypes = $db->query(
        "SELECT DISTINCT action FROM admin_activity_log ORDER BY action"
    )->fetchColumn();
    // Re-fetch properly
    $atStmt = $db->query("SELECT DISTINCT action FROM admin_activity_log ORDER BY action");
    $actionTypes = $atStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {}

// ── Action badge map ──────────────────────────────────────────────────────
$badgeClass = function(string $action): string {
    $map = [
        'notice_create'            => 'bg-success',
        'notice_update'            => 'bg-primary',
        'notice_delete'            => 'bg-danger',
        'notice_bulk_status'       => 'bg-warning text-dark',
        'settings_update'          => 'bg-info text-dark',
        'member_status_toggle'     => 'bg-warning text-dark',
        'member_notification_sent' => 'bg-secondary',
    ];
    return $map[$action] ?? 'bg-dark';
};

$actionLabel = function(string $action): string {
    $map = [
        'notice_create'            => 'Notice Create',
        'notice_update'            => 'Notice Update',
        'notice_delete'            => 'Notice Delete',
        'notice_bulk_status'       => 'Notice Bulk',
        'settings_update'          => 'Settings Update',
        'member_status_toggle'     => 'Member Toggle',
        'member_notification_sent' => 'Notif Sent',
    ];
    return $map[$action] ?? $action;
};

$flash = getFlash();
?>

<div class="admin-page-content">
    <div class="d-flex align-items-center justify-content-between mb-3 gap-2 flex-wrap">
        <div>
            <h4 class="mb-0 fw-bold"><i class="fas fa-shield-halved me-2 text-primary"></i><?php echo $__t('अडिट लग', 'Audit Log'); ?></h4>
            <small class="text-muted"><?php echo $__t('प्रशासकले गरेका सबै परिवर्तनको अभिलेख', 'Complete record of all admin actions'); ?></small>
        </div>
        <div class="text-muted small">
            <?php echo $__t('जम्मा', 'Total'); ?>: <strong><?php echo number_format($totalRows); ?></strong> <?php echo $__t('प्रविष्टि', 'entries'); ?>
        </div>
    </div>

    <?php if (!empty($flash)) {
        echo adminAlert($flash['type'] === 'success' ? 'success' : 'danger', $flash['message']);
    } ?>

    <!-- Filter Form -->
    <div class="card mb-3 border-0 shadow-sm">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label form-label-sm mb-1"><?php echo $__t('प्रशासक', 'Admin'); ?></label>
                    <select name="admin_id" class="form-select form-select-sm">
                        <option value=""><?php echo $__t('सबै', 'All Admins'); ?></option>
                        <?php foreach ($admins as $a): ?>
                            <option value="<?php echo (int)$a['admin_id']; ?>"
                                <?php echo $filterAdmin === (int)$a['admin_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($a['admin_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-md-3">
                    <label class="form-label form-label-sm mb-1"><?php echo $__t('कार्य', 'Action'); ?></label>
                    <select name="action_type" class="form-select form-select-sm">
                        <option value=""><?php echo $__t('सबै कार्य', 'All Actions'); ?></option>
                        <?php foreach ($actionTypes as $at): ?>
                            <option value="<?php echo htmlspecialchars($at, ENT_QUOTES); ?>"
                                <?php echo $filterAction === $at ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($actionLabel($at), ENT_QUOTES); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label form-label-sm mb-1"><?php echo $__t('देखि', 'From'); ?></label>
                    <input type="date" name="from_date" class="form-control form-control-sm"
                           value="<?php echo htmlspecialchars($filterFrom, ENT_QUOTES); ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label form-label-sm mb-1"><?php echo $__t('सम्म', 'To'); ?></label>
                    <input type="date" name="to_date" class="form-control form-control-sm"
                           value="<?php echo htmlspecialchars($filterTo, ENT_QUOTES); ?>">
                </div>
                <div class="col-12 col-md-2 d-flex gap-1">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                        <i class="fas fa-filter me-1"></i><?php echo $__t('फिल्टर', 'Filter'); ?>
                    </button>
                    <?php if ($filterAdmin || $filterAction || $filterFrom || $filterTo): ?>
                        <a href="audit-log.php" class="btn btn-outline-secondary btn-sm" aria-label="Close" title="Close">
                            <i class="fas fa-times"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Log Table -->
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="min-width:140px"><?php echo $__t('मिति/समय', 'Date / Time'); ?></th>
                        <th style="min-width:120px"><?php echo $__t('प्रशासक', 'Admin'); ?></th>
                        <th style="min-width:140px"><?php echo $__t('कार्य', 'Action'); ?></th>
                        <th style="min-width:100px"><?php echo $__t('लक्ष्य', 'Target'); ?></th>
                        <th style="min-width:200px"><?php echo $__t('विवरण', 'Details'); ?></th>
                        <th style="min-width:110px"><?php echo $__t('IP ठेगाना', 'IP Address'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                <i class="fas fa-inbox fa-2x mb-2 d-block opacity-25"></i>
                                <?php echo $__t('कुनै अभिलेख भेटिएन।', 'No log entries found.'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td class="ps-3 text-nowrap small">
                                    <span class="d-block fw-semibold"><?php echo date('Y-m-d', strtotime($row['created_at'])); ?></span>
                                    <span class="text-muted"><?php echo date('H:i:s', strtotime($row['created_at'])); ?></span>
                                </td>
                                <td class="small">
                                    <i class="fas fa-user-tie text-muted me-1"></i>
                                    <?php echo htmlspecialchars($row['admin_name'], ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td>
                                    <span class="badge <?php echo $badgeClass($row['action']); ?> text-wrap">
                                        <?php echo htmlspecialchars($actionLabel($row['action']), ENT_QUOTES); ?>
                                    </span>
                                </td>
                                <td class="small text-muted">
                                    <?php if ($row['target_type']): ?>
                                        <span class="badge bg-light text-secondary border">
                                            <?php echo htmlspecialchars($row['target_type'], ENT_QUOTES); ?>
                                            <?php if ($row['target_id']): ?>#<?php echo (int)$row['target_id']; ?><?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted" style="max-width:250px">
                                    <?php
                                    $det = $row['details'] ?? '';
                                    // If stored as JSON object, extract "msg" key; otherwise show raw
                                    $decoded = json_decode((string)$det, true);
                                    $display = is_array($decoded) ? ($decoded['msg'] ?? $det) : $det;
                                    echo htmlspecialchars((string)$display, ENT_QUOTES, 'UTF-8');
                                    ?>
                                </td>
                                <td class="small font-monospace text-muted">
                                    <?php echo htmlspecialchars($row['ip'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-white py-2">
                <nav>
                    <ul class="pagination pagination-sm mb-0 justify-content-center flex-wrap gap-1">
                        <?php
                        $qBase = array_filter(['admin_id'=>$filterAdmin,'action_type'=>$filterAction,'from_date'=>$filterFrom,'to_date'=>$filterTo]);
                        $qs    = $qBase ? '&' . http_build_query($qBase) : '';
                        ?>
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?p=<?php echo $page-1; ?><?php echo $qs; ?>">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
                            <li class="page-item <?php echo $i===$page ? 'active' : ''; ?>">
                                <a class="page-link" href="?p=<?php echo $i; ?><?php echo $qs; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?p=<?php echo $page+1; ?><?php echo $qs; ?>">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/admin-footer.php'; ?>
