<?php
/**
 * Admin — सम्मान दरखास्त आवेदन व्यवस्थापन
 */
$pageTitle = 'सम्मान दरखास्त आवेदन';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/../includes/honor-tables.php';
require_once __DIR__ . '/../includes/request-status-history.php';

$db = getDB();
ensureHonorTables($db);
ensureRequestStatusHistoryTable($db);
checkCSRF();

$__t = static function (string $np, string $en): string {
    return (function_exists('isEnglish') && isEnglish()) ? $en : $np;
};

$statusOptions = ['pending', 'under_review', 'shortlisted', 'selected', 'rejected', 'closed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['update_status'])) {
            $appId = (int)($_POST['application_id'] ?? 0);
            $status = clean_text($_POST['status'] ?? '', 40);
            $adminRemarks = clean_text($_POST['admin_remarks'] ?? '', 4000);
            if ($appId < 1 || !in_array($status, $statusOptions, true)) {
                setFlash('error', $__t('अमान्य अनुरोध।', 'Invalid request.'));
                redirect('honor-applications.php');
            }
            $oldStatus = '';
            $os = $db->prepare('SELECT status FROM honor_applications WHERE id=? LIMIT 1');
            $os->execute([$appId]);
            $oldStatus = (string)($os->fetchColumn() ?: '');

            $stmt = $db->prepare('UPDATE honor_applications SET status=?, admin_remarks=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?');
            $stmt->execute([$status, $adminRemarks, $_SESSION['admin_name'] ?? 'Admin', $appId]);

            $notifyOptIn = !empty($_POST['notify_member']) && $_POST['notify_member'] === '1';
            $notifyOutcome = [
                'admin_chose' => $notifyOptIn,
                'email' => ['status' => 'not_attempted', 'reason' => '', 'to' => ''],
                'sms' => ['status' => 'not_attempted', 'reason' => '', 'to' => ''],
            ];
            try {
                $nr = $db->prepare('SELECT applicant_name, email, phone, tracking_id FROM honor_applications WHERE id=?');
                $nr->execute([$appId]);
                $nd = $nr->fetch(PDO::FETCH_ASSOC);
                if ($nd && function_exists('sendMemberStatusUpdate')) {
                    $r = sendMemberStatusUpdate(
                        'honor_application',
                        $nd['email'] ?? '',
                        $nd['phone'] ?? '',
                        $nd['applicant_name'] ?? '',
                        $status,
                        $adminRemarks,
                        (string)($nd['tracking_id'] ?? ''),
                        !$notifyOptIn
                    );
                    if (is_array($r)) {
                        $notifyOutcome['email'] = $r['email'] ?? $notifyOutcome['email'];
                        $notifyOutcome['sms'] = $r['sms'] ?? $notifyOutcome['sms'];
                    }
                }
            } catch (Throwable $ex) {
            }
            $notifySent = (($notifyOutcome['email']['status'] ?? '') === 'sent') || (($notifyOutcome['sms']['status'] ?? '') === 'sent');
            try {
                logRequestStatusHistory(
                    $db,
                    'honor_application',
                    $appId,
                    $oldStatus !== '' ? $oldStatus : null,
                    $status,
                    (string)$adminRemarks,
                    $notifySent,
                    (int)($_SESSION['admin_id'] ?? 0),
                    (string)($_SESSION['admin_name'] ?? 'Admin'),
                    $notifyOutcome
                );
            } catch (Throwable $e) {
            }
            setFlash('success', $__t('स्थिति अद्यावधिक भयो।', 'Status updated.'));
            redirect('honor-applications.php?action=view&id=' . $appId);
        }

        if (isset($_POST['delete_application'])) {
            $appId = (int)($_POST['application_id'] ?? 0);
            if ($appId > 0) {
                $db->prepare('DELETE FROM honor_applications WHERE id = ?')->execute([$appId]);
                setFlash('success', $__t('आवेदन हटाइयो।', 'Application deleted.'));
            }
            redirect('honor-applications.php');
        }
    } catch (Throwable $e) {
        error_log('[honor-applications] ' . $e->getMessage());
        setFlash('error', $__t('त्रुटि भयो।', 'An error occurred.'));
        redirect('honor-applications.php');
    }
}

$action = $_GET['action'] ?? 'list';
if (!in_array($action, ['list', 'view'], true)) {
    $action = 'list';
}
$id = (int)($_GET['id'] ?? 0);

/* CSV export */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $programId = (int)($_GET['program_id'] ?? 0);
    $status = clean_text($_GET['status'] ?? '', 40);
    $where = ['1=1'];
    $params = [];
    if ($programId > 0) {
        $where[] = 'a.program_id = ?';
        $params[] = $programId;
    }
    if ($status !== '' && in_array($status, $statusOptions, true)) {
        $where[] = 'a.status = ?';
        $params[] = $status;
    }
    $sql = 'SELECT a.*, p.title_np AS program_title, c.name_np AS category_name
            FROM honor_applications a
            LEFT JOIN honor_programs p ON p.id = a.program_id
            LEFT JOIN honor_categories c ON c.id = a.category_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY a.created_at DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="honor-applications-' . date('Ymd-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['tracking_id', 'program', 'category', 'applicant_name', 'phone', 'email', 'is_member', 'member_id', 'nominee_name', 'nominee_relation', 'exam_year', 'institution', 'business_note', 'status', 'created_at']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['tracking_id'], $r['program_title'], $r['category_name'], $r['applicant_name'],
            $r['phone'], $r['email'], $r['is_member'] ? 'yes' : 'no', $r['member_id'],
            $r['nominee_name'], $r['nominee_relation'], $r['exam_year'], $r['institution'],
            $r['business_note'], $r['status'], $r['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

$programs = $db->query('SELECT id, title_np, title_en FROM honor_programs ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$categories = $db->query('SELECT id, name_np, name_en FROM honor_categories WHERE is_active=1 ORDER BY display_order')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$filterProgram = (int)($_GET['program_id'] ?? 0);
$filterCategory = (int)($_GET['category_id'] ?? 0);
$filterStatus = clean_text($_GET['status'] ?? '', 40);
$search = clean_text($_GET['search'] ?? '', 120);

?>

<?php if ($flash = getFlash()): ?>
<div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : 'success'; ?> alert-dismissible fade show">
    <?php echo htmlspecialchars((string)$flash['message']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($action === 'view' && $id > 0): ?>
<?php
$stmt = $db->prepare('SELECT a.*, p.title_np AS program_title_np, p.title_en AS program_title_en,
    p.event_label, c.name_np AS category_np, c.name_en AS category_en, c.slug AS category_slug
    FROM honor_applications a
    LEFT JOIN honor_programs p ON p.id = a.program_id
    LEFT JOIN honor_categories c ON c.id = a.category_id
    WHERE a.id = ? LIMIT 1');
$stmt->execute([$id]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$app) {
    setFlash('error', $__t('आवेदन फेला परेन।', 'Application not found.'));
    redirect('honor-applications.php');
}
$history = [];
try {
    $history = fetchRequestStatusHistory($db, 'honor_application', (int)$app['id'], 40);
} catch (Throwable $e) {
    $history = [];
}
?>
<div class="card admin-table-card mb-4">
    <div class="card-header gradient-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0"><i class="fas fa-award me-2"></i><?php echo e($app['tracking_id']); ?></h5>
        <div class="d-flex gap-2">
            <a href="honor-applications.php" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-left me-1"></i><?php echo $__t('फिर्ता', 'Back'); ?></a>
            <a href="print-form.php?type=honor&id=<?php echo (int)$app['id']; ?>" target="_blank" class="btn btn-light btn-sm"><i class="fas fa-print me-1"></i>Print</a>
        </div>
    </div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-lg-8">
                <h6><?php echo $__t('आवेदक', 'Applicant'); ?></h6>
                <div class="row">
                    <div class="col-md-6">
                        <p><strong><?php echo $__t('नाम', 'Name'); ?>:</strong> <?php echo e($app['applicant_name']); ?></p>
                        <p><strong><?php echo $__t('फोन', 'Phone'); ?>:</strong> <a href="tel:<?php echo e($app['phone']); ?>"><?php echo e($app['phone']); ?></a></p>
                        <p><strong><?php echo $__t('इमेल', 'Email'); ?>:</strong> <?php echo e($app['email'] ?: '—'); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong><?php echo $__t('सदस्य', 'Member'); ?>:</strong> <?php echo !empty($app['is_member']) ? $__t('हो', 'Yes') : $__t('होइन', 'No'); ?></p>
                        <p><strong><?php echo $__t('सदस्य नं.', 'Member No.'); ?>:</strong> <?php echo e($app['member_id'] ?: '—'); ?></p>
                        <p><strong><?php echo $__t('ठेगाना', 'Address'); ?>:</strong> <?php echo e($app['address'] ?: '—'); ?></p>
                    </div>
                </div>
                <hr>
                <h6><?php echo $__t('कार्यक्रम / कोटि', 'Program / Category'); ?></h6>
                <p><strong><?php echo $__t('कार्यक्रम', 'Program'); ?>:</strong> <?php echo e($app['program_title_np'] ?: $app['program_title_en']); ?><?php echo $app['event_label'] ? ' (' . e($app['event_label']) . ')' : ''; ?></p>
                <p><strong><?php echo $__t('कोटि', 'Category'); ?>:</strong> <?php echo e($app['category_np'] ?: $app['category_en']); ?></p>
                <p><strong><?php echo $__t('नामांकित', 'Nominee'); ?>:</strong> <?php echo e($app['nominee_name'] ?: '—'); ?> <?php echo $app['nominee_relation'] ? '(' . e($app['nominee_relation']) . ')' : ''; ?></p>
                <p><strong><?php echo $__t('परीक्षा वर्ष', 'Exam year'); ?>:</strong> <?php echo e($app['exam_year'] ?: '—'); ?></p>
                <p><strong><?php echo $__t('संस्था', 'Institution'); ?>:</strong> <?php echo e($app['institution'] ?: '—'); ?></p>
                <p><strong><?php echo $__t('कारोबार नोट', 'Business note'); ?>:</strong> <?php echo e($app['business_note'] ?: '—'); ?></p>
                <p><strong><?php echo $__t('विवरण', 'Description'); ?>:</strong><br><?php echo nl2br(e($app['description'] ?: '—')); ?></p>
                <?php if (!empty($app['attachment'])): ?>
                <p><strong><?php echo $__t('संलग्नक', 'Attachment'); ?>:</strong>
                    <a href="<?php echo SITE_URL . ltrim((string)$app['attachment'], '/'); ?>" target="_blank" rel="noopener"><?php echo $__t('हेर्नुहोस्', 'View'); ?></a>
                </p>
                <?php endif; ?>
                <?php if (!empty($history)): ?>
                <hr>
                <h6><?php echo $__t('स्थिति इतिहास', 'Status history'); ?></h6>
                <ul class="list-unstyled small mb-0">
                    <?php foreach ($history as $h): ?>
                    <li class="mb-1">
                        <span class="badge bg-secondary"><?php echo e($h['old_status'] ?? '—'); ?></span>
                        → <span class="badge bg-primary"><?php echo e($h['new_status'] ?? ''); ?></span>
                        <span class="text-muted"><?php echo e($h['created_at'] ?? ''); ?> · <?php echo e($h['changed_by_name'] ?? ''); ?></span>
                        <?php if (!empty($h['remarks'])): ?><div><?php echo e($h['remarks']); ?></div><?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <div class="col-lg-4">
                <div class="card border">
                    <div class="card-body">
                        <p><strong><?php echo $__t('हालको स्थिति', 'Current status'); ?>:</strong>
                            <span class="badge bg-info"><?php echo e(honorStatusLabel((string)$app['status'], false)); ?></span>
                        </p>
                        <form method="post">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="application_id" value="<?php echo (int)$app['id']; ?>">
                            <div class="mb-3">
                                <label class="form-label"><?php echo $__t('स्थिति', 'Status'); ?></label>
                                <select name="status" class="form-select">
                                    <?php foreach ($statusOptions as $st): ?>
                                    <option value="<?php echo $st; ?>" <?php echo $app['status'] === $st ? 'selected' : ''; ?>><?php echo e(honorStatusLabel($st, false)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label"><?php echo $__t('प्रशासक टिप्पणी / जवाफ', 'Admin remarks / reply'); ?></label>
                                <textarea name="admin_remarks" class="form-control" rows="4" placeholder="<?php echo $__t('छनोट/अस्वीकृतको कारण वा सन्देश…', 'Selection/rejection reason or message…'); ?>"><?php echo e($app['admin_remarks'] ?? ''); ?></textarea>
                                <div class="form-text"><?php echo $__t('यो टिप्पणी आवेदकलाई email/SMS मा जान सक्छ।', 'This remark can be sent to the applicant by email/SMS.'); ?></div>
                            </div>
                            <?php $hasEmail = !empty($app['email']); $hasPhone = !empty($app['phone']); ?>
                            <div class="arv-notify-row mb-3">
                                <label class="arv-notify-toggle">
                                    <input type="checkbox" name="notify_member" value="1" id="notifyMember" <?php echo ($hasEmail || $hasPhone) ? 'checked' : ''; ?>>
                                    <span><i class="fas fa-paper-plane"></i> <?php echo $__t('आवेदकलाई Email/SMS पठाउनुहोस्', 'Send Email/SMS to applicant'); ?></span>
                                </label>
                                <div class="arv-notify-channels small mt-1">
                                    <span class="<?php echo $hasEmail ? 'text-success' : 'text-muted'; ?>"><i class="fas fa-envelope"></i> Email <?php echo $hasEmail ? '✓' : '—'; ?></span>
                                    <span class="ms-2 <?php echo $hasPhone ? 'text-success' : 'text-muted'; ?>"><i class="fas fa-mobile-alt"></i> SMS <?php echo $hasPhone ? '✓' : '—'; ?></span>
                                </div>
                                <?php if (!$hasEmail && !$hasPhone): ?>
                                <div class="text-danger small mt-1"><?php echo $__t('Email/फोन छैन — सूचना जान सक्दैन।', 'No email/phone — cannot notify.'); ?></div>
                                <?php endif; ?>
                            </div>
                            <button type="submit" name="update_status" value="1" class="btn btn-primary w-100"><?php echo $__t('अपडेट गर्नुहोस्', 'Update'); ?></button>
                        </form>
                        <form method="post" class="mt-3" onsubmit="return confirm('<?php echo $__t('मेटाउने निश्चित?', 'Delete?'); ?>');">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="application_id" value="<?php echo (int)$app['id']; ?>">
                            <button type="submit" name="delete_application" value="1" class="btn btn-outline-danger w-100 btn-sm"><?php echo $__t('मेटाउनुहोस्', 'Delete'); ?></button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<?php
$where = ['1=1'];
$params = [];
if ($filterProgram > 0) {
    $where[] = 'a.program_id = ?';
    $params[] = $filterProgram;
}
if ($filterCategory > 0) {
    $where[] = 'a.category_id = ?';
    $params[] = $filterCategory;
}
if ($filterStatus !== '' && in_array($filterStatus, $statusOptions, true)) {
    $where[] = 'a.status = ?';
    $params[] = $filterStatus;
}
if ($search !== '') {
    $where[] = '(a.tracking_id LIKE ? OR a.applicant_name LIKE ? OR a.phone LIKE ? OR a.member_id LIKE ? OR a.nominee_name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$sql = 'SELECT a.*, p.title_np AS program_title, c.name_np AS category_name
        FROM honor_applications a
        LEFT JOIN honor_programs p ON p.id = a.program_id
        LEFT JOIN honor_categories c ON c.id = a.category_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY a.created_at DESC LIMIT 500';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$apps = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$statCounts = ['pending' => 0, 'under_review' => 0, 'shortlisted' => 0, 'selected' => 0, 'rejected' => 0];
try {
    $sc = $db->query("SELECT status, COUNT(*) c FROM honor_applications GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($sc as $row) {
        $statCounts[$row['status']] = (int)$row['c'];
    }
} catch (Throwable $e) {
}
$exportQs = http_build_query(array_filter([
    'export' => 'csv',
    'program_id' => $filterProgram ?: null,
    'status' => $filterStatus !== '' ? $filterStatus : null,
]));
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h4 class="mb-1"><?php echo $__t('सम्मान दरखास्त आवेदन', 'Honor Applications'); ?></h4>
        <p class="text-muted mb-0 small"><?php echo $__t('कार्यक्रम अनुसार आवेदन संकलन र समीक्षा।', 'Collect and review applications by program.'); ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="honor-programs.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-calendar-alt me-1"></i><?php echo $__t('कार्यक्रमहरू', 'Programs'); ?></a>
        <a href="honor-applications.php?<?php echo htmlspecialchars($exportQs); ?>" class="btn btn-outline-success btn-sm"><i class="fas fa-file-csv me-1"></i>CSV</a>
    </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach (['pending', 'under_review', 'shortlisted', 'selected', 'rejected'] as $st): ?>
    <a href="honor-applications.php?status=<?php echo $st; ?><?php echo $filterProgram ? '&program_id=' . $filterProgram : ''; ?>"
       class="btn btn-sm <?php echo $filterStatus === $st ? 'btn-primary' : 'btn-outline-secondary'; ?>">
        <?php echo e(honorStatusLabel($st, false)); ?> (<?php echo (int)($statCounts[$st] ?? 0); ?>)
    </a>
    <?php endforeach; ?>
    <a href="honor-applications.php" class="btn btn-sm btn-outline-dark"><?php echo $__t('सबै', 'All'); ?></a>
</div>

<form method="get" class="card admin-table-card mb-3">
    <div class="card-body row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small"><?php echo $__t('कार्यक्रम', 'Program'); ?></label>
            <select name="program_id" class="form-select form-select-sm">
                <option value="0"><?php echo $__t('सबै', 'All'); ?></option>
                <?php foreach ($programs as $p): ?>
                <option value="<?php echo (int)$p['id']; ?>" <?php echo $filterProgram === (int)$p['id'] ? 'selected' : ''; ?>><?php echo e($p['title_np'] ?: $p['title_en']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small"><?php echo $__t('कोटि', 'Category'); ?></label>
            <select name="category_id" class="form-select form-select-sm">
                <option value="0"><?php echo $__t('सबै', 'All'); ?></option>
                <?php foreach ($categories as $c): ?>
                <option value="<?php echo (int)$c['id']; ?>" <?php echo $filterCategory === (int)$c['id'] ? 'selected' : ''; ?>><?php echo e($c['name_np'] ?: $c['name_en']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small"><?php echo $__t('स्थिति', 'Status'); ?></label>
            <select name="status" class="form-select form-select-sm">
                <option value=""><?php echo $__t('सबै', 'All'); ?></option>
                <?php foreach ($statusOptions as $st): ?>
                <option value="<?php echo $st; ?>" <?php echo $filterStatus === $st ? 'selected' : ''; ?>><?php echo e(honorStatusLabel($st, false)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small"><?php echo $__t('खोज', 'Search'); ?></label>
            <input type="text" name="search" class="form-control form-control-sm" value="<?php echo e($search); ?>" placeholder="HNR- / नाम / फोन">
        </div>
        <div class="col-md-1">
            <button class="btn btn-primary btn-sm w-100" type="submit"><i class="fas fa-search"></i></button>
        </div>
    </div>
</form>

<div class="card admin-table-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th><?php echo $__t('आवेदक', 'Applicant'); ?></th>
                    <th><?php echo $__t('कार्यक्रम / कोटि', 'Program / Category'); ?></th>
                    <th><?php echo $__t('नामांकित', 'Nominee'); ?></th>
                    <th><?php echo $__t('स्थिति', 'Status'); ?></th>
                    <th><?php echo $__t('मिति', 'Date'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($apps)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4"><?php echo $__t('आवेदन छैन।', 'No applications.'); ?></td></tr>
            <?php else: foreach ($apps as $a): ?>
                <tr>
                    <td class="small"><code><?php echo e($a['tracking_id']); ?></code></td>
                    <td>
                        <strong><?php echo e($a['applicant_name']); ?></strong>
                        <div class="small text-muted"><?php echo e($a['phone']); ?><?php echo !empty($a['is_member']) ? ' · ' . e($a['member_id']) : ''; ?></div>
                    </td>
                    <td class="small">
                        <?php echo e($a['program_title'] ?: '—'); ?><br>
                        <span class="text-muted"><?php echo e($a['category_name'] ?: '—'); ?></span>
                    </td>
                    <td class="small"><?php echo e($a['nominee_name'] ?: '—'); ?></td>
                    <td><span class="badge bg-secondary"><?php echo e(honorStatusLabel((string)$a['status'], false)); ?></span></td>
                    <td class="small"><?php echo e($a['created_at']); ?></td>
                    <td><a class="btn btn-sm btn-primary" href="honor-applications.php?action=view&id=<?php echo (int)$a['id']; ?>"><i class="fas fa-eye"></i></a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once 'includes/admin-footer.php'; ?>
