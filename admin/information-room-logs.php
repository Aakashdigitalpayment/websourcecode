<?php
/**
 * Information Room — Access log (who viewed / downloaded)
 */
$__t = static function (string $np, string $en): string {
    $lang = (string) ($_SESSION['admin_lang'] ?? $_SESSION['lang'] ?? 'np');
    return strtolower($lang) === 'en' ? $en : $np;
};

$pageTitle = $__t('Information Room — पहुँच लग', 'Information Room — Access log');
$currentPage = 'information-room-logs';

require_once __DIR__ . '/../includes/information-room-tables.php';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$db = getDB();
ensureInformationRoomTables($db);

$itemFilter = (int) ($_GET['item'] ?? 0);
$logs = irFetchAccessLogs($db, 250, $itemFilter);
$flash = getFlash();

echo adminPageHeader(
    $__t('Information Room — पहुँच लग', 'Information Room — Access log'),
    'fa-clipboard-list',
    $__t('कसले कहिले कागजात हेर्यो / डाउनलोड गर्‍यो — audit trail।', 'Who viewed or downloaded documents — audit trail.'),
    '<a href="information-room.php" class="btn btn-sm btn-outline-secondary me-2"><i class="fas fa-cog me-1"></i>'
    . $__t('व्यवस्थापन', 'Manage') . '</a>'
    . '<a href="information-room-browse.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye me-1"></i>'
    . $__t('Reader', 'Reader') . '</a>'
);

if (!empty($flash)) {
    echo adminAlert($flash['type'] === 'success' ? 'success' : 'danger', $flash['message']);
}
?>

<div class="card admin-table-card mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small mb-0" for="irLogItem"><?php echo $__t('कागजात ID (ऐच्छिक)', 'Document ID (optional)'); ?></label>
                <input type="number" min="1" class="form-control form-control-sm" id="irLogItem" name="item" value="<?php echo $itemFilter > 0 ? (int) $itemFilter : ''; ?>" placeholder="ID">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><?php echo $__t('फिल्टर', 'Filter'); ?></button>
                <?php if ($itemFilter > 0): ?>
                <a href="information-room-logs.php" class="btn btn-sm btn-outline-secondary"><?php echo $__t('सबै', 'All'); ?></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card admin-table-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 admin-data-table">
                <thead>
                    <tr>
                        <th><?php echo $__t('समय', 'Time'); ?></th>
                        <th><?php echo $__t('कागजात', 'Document'); ?></th>
                        <th><?php echo $__t('दर्शक', 'Viewer'); ?></th>
                        <th><?php echo $__t('क्रिया', 'Action'); ?></th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($logs === []): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4"><?php echo $__t('लग खाली छ।', 'No access logs yet.'); ?></td></tr>
                <?php else: foreach ($logs as $row):
                    $docTitle = trim((string) ($row['title_np'] ?? '')) !== ''
                        ? (string) $row['title_np']
                        : (string) ($row['title'] ?? ('#' . (int) $row['item_id']));
                    $when = (string) ($row['created_at'] ?? '');
                    if ($when !== '' && function_exists('formatNepaliDate')) {
                        $whenDisp = formatNepaliDate($when, true);
                    } else {
                        $whenDisp = $when !== '' ? $when : '—';
                    }
                    $vType = (string) ($row['viewer_type'] ?? '');
                    $action = (string) ($row['action'] ?? 'view');
                ?>
                    <tr>
                        <td class="small text-nowrap"><?php echo htmlspecialchars($whenDisp, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <a href="information-room-view.php?id=<?php echo (int) $row['item_id']; ?>">
                                <?php echo htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                            <div class="small text-muted">#<?php echo (int) $row['item_id']; ?></div>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $vType === 'admin' ? 'primary' : 'success'; ?>-subtle text-<?php echo $vType === 'admin' ? 'primary' : 'success'; ?>">
                                <?php echo $vType === 'admin' ? 'Admin' : 'Member'; ?>
                            </span>
                            <?php echo htmlspecialchars((string) ($row['viewer_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            <div class="small text-muted">ID <?php echo (int) ($row['viewer_id'] ?? 0); ?></div>
                        </td>
                        <td>
                            <?php if ($action === 'download'): ?>
                            <span class="badge bg-warning-subtle text-warning-emphasis"><?php echo $__t('डाउनलोड', 'Download'); ?></span>
                            <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary"><?php echo $__t('हेर्नुहोस्', 'View'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="small font-monospace"><?php echo htmlspecialchars((string) ($row['ip_address'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'includes/admin-footer.php'; ?>
