<?php
/**
 * Member ID SSOT — duplicate inventory + optional UNIQUE index
 * Live-safe: report first; add UNIQUE only when clean.
 */
$pageTitle   = 'Member ID Duplicate Inventory';
$currentPage = 'members';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/../includes/auth-roles.php';

$db = getDB();
$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    require_role('admin');
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'add_unique' && function_exists('memberSsotTryAddUniqueIndex')) {
        $r = memberSsotTryAddUniqueIndex($db);
        $message = $r['message'] ?? '';
        $messageType = !empty($r['ok']) ? 'success' : 'danger';
    }
}

$dups = function_exists('memberSsotDuplicateSadasyataReport')
    ? memberSsotDuplicateSadasyataReport($db)
    : [];
$emptyCount = function_exists('memberSsotEmptySadasyataCount')
    ? memberSsotEmptySadasyataCount($db)
    : 0;

$hasUnique = false;
try {
    $idx = $db->query("SHOW INDEX FROM members WHERE Column_name='sadasyata_number' AND Non_unique=0");
    $hasUnique = (bool)($idx && $idx->fetch());
} catch (Throwable $e) {
    $hasUnique = false;
}
?>

<div class="container-fluid py-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <h4 class="mb-0 fw-bold text-success">
            <i class="fas fa-clone me-2"></i>Member ID — दोहोरो सूची (SSOT)
        </h4>
        <div class="d-flex gap-2">
            <a href="members.php" class="btn btn-sm btn-outline-secondary">Members</a>
            <a href="member-import.php" class="btn btn-sm btn-outline-success">Bulk Import</a>
        </div>
    </div>

    <?php if (function_exists('memberSsotAdminHelpHtml')) { echo memberSsotAdminHelpHtml('members'); } ?>

    <?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">दोहोरो सदस्यता नं. समूह</div>
                    <div class="fs-3 fw-bold <?php echo $dups ? 'text-danger' : 'text-success'; ?>"><?php echo count($dups); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">खाली सदस्यता नं.</div>
                    <div class="fs-3 fw-bold <?php echo $emptyCount > 0 ? 'text-warning' : 'text-success'; ?>"><?php echo (int)$emptyCount; ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">UNIQUE INDEX</div>
                    <div class="fs-5 fw-bold <?php echo $hasUnique ? 'text-success' : 'text-secondary'; ?>">
                        <?php echo $hasUnique ? 'छ (uq_members_sadasyata)' : 'अहिले छैन'; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$hasUnique): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <p class="small mb-2">
                दोहोरो र खाली सदस्यता नम्बर सफा भएपछि मात्र DB मा
                <code>UNIQUE (sadasyata_number)</code> थप्न सकिन्छ।
            </p>
            <form method="post" onsubmit="return confirm('UNIQUE index थप्ने? दोहोरो भएमा असफल हुन्छ।');">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_unique">
                <button type="submit" class="btn btn-warning btn-sm"
                    <?php echo ($dups || $emptyCount > 0) ? 'disabled title="पहिले duplicates/empty मिलाउनुहोस्"' : ''; ?>>
                    <i class="fas fa-database me-1"></i>UNIQUE INDEX थप्नुहोस्
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <strong>दोहोरो सदस्यता नम्बर</strong>
            <span class="text-muted small ms-2">Admin ले म्यानुअल मिलाउनुहोस् — स्वतः merge हुँदैन।</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>सदस्यता नं.</th>
                        <th>Count</th>
                        <th>Member PK ids</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$dups): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">दोहोरो छैन — राम्रो।</td></tr>
                <?php else: foreach ($dups as $d): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars((string)$d['sadasyata_number']); ?></code></td>
                        <td><span class="badge bg-danger"><?php echo (int)$d['cnt']; ?></span></td>
                        <td class="small"><?php echo htmlspecialchars((string)$d['ids']); ?></td>
                        <td>
                            <a class="btn btn-sm btn-outline-success"
                               href="members.php?search=<?php echo urlencode((string)$d['sadasyata_number']); ?>">
                                Members मा खोज
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'includes/admin-footer.php'; ?>
