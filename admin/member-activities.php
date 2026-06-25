<?php
require_once __DIR__ . '/../includes/config.php';
if (!isAdminLoggedIn()) {
    redirect(ADMIN_URL . 'index.php');
}
$db = getDB();

function memberActivityRows(PDO $db, string $table, string $sql, array $params = []): array {
    if (function_exists('safeTableExists') && !safeTableExists($table)) return [];
    try {
        $st = $db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function addDateFilters(string $column, array &$params, ?string $dateFrom, ?string $dateTo): string {
    $sql = '';
    if ($dateFrom) {
        $sql .= " AND DATE($column) >= ?";
        $params[] = $dateFrom;
    }
    if ($dateTo) {
        $sql .= " AND DATE($column) <= ?";
        $params[] = $dateTo;
    }
    return $sql;
}

$search = trim((string)($_GET['q'] ?? ''));
$selectedMemberId = (int)($_GET['member'] ?? 0);
$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo = trim((string)($_GET['to'] ?? ''));
$viewMode = ($_GET['view'] ?? 'tables') === 'timeline' ? 'timeline' : 'tables';
$exportCsv = ($_GET['export'] ?? '') === 'csv';

if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = '';

$members = [];
$member = null;
$activities = [];
$summary = [];
$timeline = [];

if ($search !== '') {
    $searchLike = '%' . $search . '%';
    $searchDigits = preg_replace('/\D+/', '', $search) ?? '';
    $searchLower = strtolower($search);

    $ms = $db->prepare(
        "SELECT id, name, email, phone, sadasyata_number, member_card_no, approval_status, is_active, created_at
         FROM members
         WHERE id = :idExact
            OR LOWER(COALESCE(email, '')) = :emailExact
            OR REPLACE(REPLACE(REPLACE(COALESCE(phone, ''), '-', ''), ' ', ''), '+', '') = :phoneExact
            OR sadasyata_number = :sadaExact
            OR member_card_no = :cardExact
            OR name LIKE :likeName
            OR email LIKE :likeEmail
            OR phone LIKE :likePhone
            OR sadasyata_number LIKE :likeSada
            OR member_card_no LIKE :likeCard
         ORDER BY
            CASE
                WHEN id = :idOrder THEN 0
                WHEN LOWER(COALESCE(email, '')) = :emailOrder THEN 1
                WHEN sadasyata_number = :sadaOrder THEN 2
                WHEN member_card_no = :cardOrder THEN 3
                ELSE 4
            END, id DESC
         LIMIT 30"
    );
    $ms->execute([
        ':idExact' => (int)$search, ':emailExact' => $searchLower, ':phoneExact' => $searchDigits, ':sadaExact' => $search, ':cardExact' => $search,
        ':likeName' => $searchLike, ':likeEmail' => $searchLike, ':likePhone' => $searchLike, ':likeSada' => $searchLike, ':likeCard' => $searchLike,
        ':idOrder' => (int)$search, ':emailOrder' => $searchLower, ':sadaOrder' => $search, ':cardOrder' => $search,
    ]);
    $members = $ms->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($selectedMemberId > 0) {
        foreach ($members as $m) if ((int)$m['id'] === $selectedMemberId) { $member = $m; break; }
    }
    if (!$member && !empty($members)) {
        $member = $members[0];
        $selectedMemberId = (int)$member['id'];
    }

    if ($member) {
        $mid = (int)$member['id'];
        $sada = (string)($member['sadasyata_number'] ?? '');
        $cardNo = (string)($member['member_card_no'] ?? '');
        $phoneDigits = preg_replace('/\D+/', '', (string)($member['phone'] ?? '')) ?? '';
        $email = strtolower((string)($member['email'] ?? ''));

        $p = [$mid, $sada, $cardNo, $email, $phoneDigits];
        $activities['digital_services'] = memberActivityRows(
            $db, 'digital_service_requests',
            "SELECT id, tracking_id, service_type, status, created_at
             FROM digital_service_requests
             WHERE (member_id IN (?, ?, ?) OR LOWER(COALESCE(email,'')) = ? OR REPLACE(REPLACE(REPLACE(COALESCE(phone,''), '-', ''), ' ', ''), '+', '') = ?)"
             . addDateFilters('created_at', $p, $dateFrom, $dateTo) . " ORDER BY id DESC LIMIT 100",
            $p
        );

        $p = [$mid];
        $activities['partner_services'] = memberActivityRows(
            $db, 'member_partner_services',
            "SELECT id, partner_name, service_name, service_taken, service_note, created_at
             FROM member_partner_services
             WHERE member_id = ?" . addDateFilters('created_at', $p, $dateFrom, $dateTo) . " ORDER BY id DESC LIMIT 100",
            $p
        );

        $p = [$mid];
        $activities['program_attendance'] = memberActivityRows(
            $db, 'member_program_attendance',
            "SELECT id, program_title, is_priority, attendance_note, attended_at
             FROM member_program_attendance
             WHERE member_id = ?" . addDateFilters('attended_at', $p, $dateFrom, $dateTo) . " ORDER BY id DESC LIMIT 100",
            $p
        );

        $p = [$mid, $email, $phoneDigits];
        $activities['program_prereg'] = memberActivityRows(
            $db, 'member_program_preregistrations',
            "SELECT id, program_title, event_date, source, created_at
             FROM member_program_preregistrations
             WHERE (member_id = ? OR LOWER(COALESCE(email,'')) = ? OR REPLACE(REPLACE(REPLACE(COALESCE(phone,''), '-', ''), ' ', ''), '+', '') = ?)"
             . addDateFilters('created_at', $p, $dateFrom, $dateTo) . " ORDER BY id DESC LIMIT 100",
            $p
        );

        $p = [$mid, $sada, $cardNo, $email, $phoneDigits];
        $activities['grievances'] = memberActivityRows(
            $db, 'grievances',
            "SELECT id, tracking_id, category, subject, status, created_at
             FROM grievances
             WHERE (member_id IN (?, ?, ?) OR LOWER(COALESCE(email,'')) = ? OR REPLACE(REPLACE(REPLACE(COALESCE(phone,''), '-', ''), ' ', ''), '+', '') = ?)"
             . addDateFilters('created_at', $p, $dateFrom, $dateTo) . " ORDER BY id DESC LIMIT 100",
            $p
        );

        $p = [$mid, $sada, $cardNo, $email, $phoneDigits];
        $activities['welfare_claims'] = memberActivityRows(
            $db, 'member_welfare_claims',
            "SELECT id, tracking_id, claim_type, claim_amount, status, created_at
             FROM member_welfare_claims
             WHERE (member_id IN (?, ?, ?) OR LOWER(COALESCE(email,'')) = ? OR REPLACE(REPLACE(REPLACE(COALESCE(phone,''), '-', ''), ' ', ''), '+', '') = ?)"
             . addDateFilters('created_at', $p, $dateFrom, $dateTo) . " ORDER BY id DESC LIMIT 100",
            $p
        );

        $p = [$mid, $sada, $cardNo, $email, $phoneDigits];
        $activities['appointments'] = memberActivityRows(
            $db, 'appointments',
            "SELECT id, tracking_id, purpose, preferred_date, preferred_time, status, created_at
             FROM appointments
             WHERE (member_id IN (?, ?, ?) OR LOWER(COALESCE(email,'')) = ? OR REPLACE(REPLACE(REPLACE(COALESCE(phone,''), '-', ''), ' ', ''), '+', '') = ?)"
             . addDateFilters('created_at', $p, $dateFrom, $dateTo) . " ORDER BY id DESC LIMIT 100",
            $p
        );

        $p = [$mid, $sada, $cardNo, $email, $phoneDigits];
        $activities['loan_applications'] = memberActivityRows(
            $db, 'loan_applications',
            "SELECT id, loan_type, loan_amount, status, created_at
             FROM loan_applications
             WHERE (member_id IN (?, ?, ?) OR LOWER(COALESCE(email,'')) = ? OR REPLACE(REPLACE(REPLACE(COALESCE(mobile,''), '-', ''), ' ', ''), '+', '') = ?)"
             . addDateFilters('created_at', $p, $dateFrom, $dateTo) . " ORDER BY id DESC LIMIT 100",
            $p
        );

        $p = [$email, $phoneDigits];
        $activities['account_applications'] = memberActivityRows(
            $db, 'account_applications',
            "SELECT id, account_type, status, created_at
             FROM account_applications
             WHERE (LOWER(COALESCE(email,'')) = ? OR REPLACE(REPLACE(REPLACE(COALESCE(mobile,''), '-', ''), ' ', ''), '+', '') = ?)"
             . addDateFilters('created_at', $p, $dateFrom, $dateTo) . " ORDER BY id DESC LIMIT 100",
            $p
        );

        $p = [$mid, $sada, $cardNo, $email, $phoneDigits];
        $activities['member_surveys'] = memberActivityRows(
            $db, 'member_survey',
            "SELECT id, tracking_id, satisfaction_level, suggestions, created_at
             FROM member_survey
             WHERE (member_id IN (?, ?, ?) OR LOWER(COALESCE(email,'')) = ? OR REPLACE(REPLACE(REPLACE(COALESCE(phone,''), '-', ''), ' ', ''), '+', '') = ?)"
             . addDateFilters('created_at', $p, $dateFrom, $dateTo) . " ORDER BY id DESC LIMIT 100",
            $p
        );

        $p = [$mid];
        $activities['password_resets'] = memberActivityRows(
            $db, 'member_password_reset_requests',
            "SELECT id, status, requested_at, resolved_at
             FROM member_password_reset_requests
             WHERE member_id = ?" . addDateFilters('requested_at', $p, $dateFrom, $dateTo) . " ORDER BY id DESC LIMIT 100",
            $p
        );

        foreach ($activities as $k => $rows) $summary[$k] = count($rows);

        foreach (($activities['digital_services'] ?? []) as $r) $timeline[] = ['date' => $r['created_at'] ?? '', 'type' => 'Digital Service', 'status' => $r['status'] ?? '', 'detail' => (string)($r['service_type'] ?? '') . ' ' . (string)($r['tracking_id'] ?? '')];
        foreach (($activities['partner_services'] ?? []) as $r) $timeline[] = ['date' => $r['created_at'] ?? '', 'type' => 'Partner Service', 'status' => !empty($r['service_taken']) ? 'taken' : 'not_taken', 'detail' => (string)($r['partner_name'] ?? '') . ' - ' . (string)($r['service_name'] ?? '')];
        foreach (($activities['program_attendance'] ?? []) as $r) $timeline[] = ['date' => $r['attended_at'] ?? '', 'type' => 'Program Attendance', 'status' => !empty($r['is_priority']) ? 'priority' : 'normal', 'detail' => (string)($r['program_title'] ?? '')];
        foreach (($activities['program_prereg'] ?? []) as $r) $timeline[] = ['date' => $r['created_at'] ?? '', 'type' => 'Program Pre-Reg', 'status' => (string)($r['source'] ?? ''), 'detail' => (string)($r['program_title'] ?? '')];
        foreach (($activities['grievances'] ?? []) as $r) $timeline[] = ['date' => $r['created_at'] ?? '', 'type' => 'Grievance', 'status' => (string)($r['status'] ?? ''), 'detail' => (string)($r['subject'] ?? '')];
        foreach (($activities['welfare_claims'] ?? []) as $r) $timeline[] = ['date' => $r['created_at'] ?? '', 'type' => 'Welfare Claim', 'status' => (string)($r['status'] ?? ''), 'detail' => (string)($r['claim_type'] ?? '')];
        foreach (($activities['appointments'] ?? []) as $r) $timeline[] = ['date' => $r['created_at'] ?? '', 'type' => 'Appointment', 'status' => (string)($r['status'] ?? ''), 'detail' => (string)($r['purpose'] ?? '')];
        foreach (($activities['loan_applications'] ?? []) as $r) $timeline[] = ['date' => $r['created_at'] ?? '', 'type' => 'Loan Application', 'status' => (string)($r['status'] ?? ''), 'detail' => (string)($r['loan_type'] ?? '')];
        foreach (($activities['account_applications'] ?? []) as $r) $timeline[] = ['date' => $r['created_at'] ?? '', 'type' => 'Account Application', 'status' => (string)($r['status'] ?? ''), 'detail' => (string)($r['account_type'] ?? '')];
        foreach (($activities['member_surveys'] ?? []) as $r) $timeline[] = ['date' => $r['created_at'] ?? '', 'type' => 'Survey', 'status' => 'level ' . (string)($r['satisfaction_level'] ?? ''), 'detail' => mb_substr((string)($r['suggestions'] ?? ''), 0, 80)];
        foreach (($activities['password_resets'] ?? []) as $r) $timeline[] = ['date' => $r['requested_at'] ?? '', 'type' => 'Password Reset', 'status' => (string)($r['status'] ?? ''), 'detail' => 'Request #' . (string)($r['id'] ?? '')];

        usort($timeline, function ($a, $b) { return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')); });
    }
}

if ($exportCsv && $member) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="member-activities-' . (int)$member['id'] . '-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Member', $member['name'] ?? '']);
    fputcsv($out, ['Member ID', $member['id'] ?? '']);
    fputcsv($out, ['Membership No', $member['sadasyata_number'] ?? '']);
    fputcsv($out, ['Date From', $dateFrom ?: '']);
    fputcsv($out, ['Date To', $dateTo ?: '']);
    fputcsv($out, []);
    fputcsv($out, ['Timeline Date', 'Activity Type', 'Status', 'Detail']);
    foreach ($timeline as $t) fputcsv($out, [$t['date'] ?? '', $t['type'] ?? '', $t['status'] ?? '', $t['detail'] ?? '']);
    fclose($out);
    exit;
}

$pageTitle = 'Member Activities Search';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
echo adminPageHeader('Member Activities Search', 'fa-magnifying-glass-chart', 'एक सदस्यका सबै गतिविधि एउटै ठाउँमा हेर्नुहोस्', '');
?>

<div class="container-fluid py-3">
    <div class="row justify-content-center">
        <div class="col-12 col-xxl-11">
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-body">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-lg-4">
                            <label class="form-label fw-semibold">Member Search</label>
                            <input type="text" name="q" class="form-control" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Member ID / फोन / इमेल / सदस्यता नं / Card नं">
                        </div>
                        <div class="col-lg-2">
                            <label class="form-label fw-semibold">From</label>
                            <input type="date" name="from" class="form-control" value="<?php echo htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-lg-2">
                            <label class="form-label fw-semibold">To</label>
                            <input type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-lg-2">
                            <label class="form-label fw-semibold">View</label>
                            <select name="view" class="form-select">
                                <option value="tables" <?php echo $viewMode === 'tables' ? 'selected' : ''; ?>>Tables</option>
                                <option value="timeline" <?php echo $viewMode === 'timeline' ? 'selected' : ''; ?>>Timeline</option>
                            </select>
                        </div>
                        <div class="col-lg-2">
                            <button class="btn btn-success w-100"><i class="fas fa-search me-1"></i> खोज्नुहोस्</button>
                        </div>
                        <div class="col-lg-12 text-end">
                            <a href="member-activities.php" class="btn btn-outline-secondary btn-sm">Clear</a>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($search !== '' && empty($members)): ?>
                <div class="alert alert-warning">कुनै member फेला परेन।</div>
            <?php endif; ?>

            <?php if (!empty($members) && !$member): ?>
                <div class="alert alert-info">Member छान्नुस्।</div>
            <?php endif; ?>

            <?php if (!empty($members)): ?>
                <div class="card shadow-sm border-0 mb-3">
                    <div class="card-header bg-white fw-bold">Search Results (<?php echo count($members); ?>)</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th><th>Name</th><th>सदस्यता नं</th><th>Phone</th><th>Email</th><th>Status</th><th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($members as $i => $m): ?>
                                <tr class="<?php echo ((int)$m['id'] === (int)$selectedMemberId) ? 'table-success' : ''; ?>">
                                    <td><?php echo $i + 1; ?></td>
                                    <td><?php echo htmlspecialchars((string)$m['name']); ?></td>
                                    <td><code><?php echo htmlspecialchars((string)($m['sadasyata_number'] ?? '—')); ?></code></td>
                                    <td><?php echo htmlspecialchars((string)($m['phone'] ?? '—')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($m['email'] ?? '—')); ?></td>
                                    <td>
                                        <span class="badge <?php echo (($m['approval_status'] ?? '') === 'approved') ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                            <?php echo htmlspecialchars((string)($m['approval_status'] ?? 'pending')); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="?q=<?php echo urlencode($search); ?>&member=<?php echo (int)$m['id']; ?>&from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>&view=<?php echo urlencode($viewMode); ?>" class="btn btn-sm btn-outline-primary">
                                            View Activities
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($member): ?>
                <div class="card shadow-sm border-0 mb-3">
                    <div class="card-body">
                        <h5 class="mb-2 fw-bold"><?php echo htmlspecialchars((string)$member['name']); ?></h5>
                        <div class="small text-muted">
                            ID: <?php echo (int)$member['id']; ?> |
                            सदस्यता नं: <?php echo htmlspecialchars((string)($member['sadasyata_number'] ?? '—')); ?> |
                            Phone: <?php echo htmlspecialchars((string)($member['phone'] ?? '—')); ?> |
                            Email: <?php echo htmlspecialchars((string)($member['email'] ?? '—')); ?>
                        </div>
                        <div class="mt-2">
                            <a href="?q=<?php echo urlencode($search); ?>&member=<?php echo (int)$selectedMemberId; ?>&from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>&view=<?php echo urlencode($viewMode); ?>&export=csv" class="btn btn-sm btn-outline-success">
                                <i class="fas fa-file-csv me-1"></i>Export CSV
                            </a>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <?php
                    $boxes = [
                        'digital_services' => 'Digital Service',
                        'partner_services' => 'Partner Services',
                        'program_attendance' => 'Program Attendance',
                        'program_prereg' => 'Program Pre-Reg',
                        'grievances' => 'Grievances',
                        'welfare_claims' => 'Welfare Claims',
                        'appointments' => 'Appointments',
                        'loan_applications' => 'Loan Apps',
                        'account_applications' => 'Account Apps',
                        'member_surveys' => 'Surveys',
                        'password_resets' => 'Password Resets',
                    ];
                    foreach ($boxes as $k => $label):
                    ?>
                    <div class="col-6 col-md-4 col-xl-2">
                        <div class="card border-0 shadow-sm text-center py-3">
                            <div class="h4 mb-1"><?php echo (int)($summary[$k] ?? 0); ?></div>
                            <div class="small text-muted"><?php echo $label; ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php
                $sections = [
                    'digital_services' => ['title' => 'Digital Service Requests', 'columns' => ['tracking_id','service_type','status','created_at']],
                    'partner_services' => ['title' => 'Partner Services', 'columns' => ['partner_name','service_name','service_taken','service_note','created_at']],
                    'program_attendance' => ['title' => 'Program Attendance', 'columns' => ['program_title','is_priority','attendance_note','attended_at']],
                    'program_prereg' => ['title' => 'Program Pre-Registrations', 'columns' => ['program_title','event_date','source','created_at']],
                    'grievances' => ['title' => 'Grievances', 'columns' => ['tracking_id','category','subject','status','created_at']],
                    'welfare_claims' => ['title' => 'Welfare Claims', 'columns' => ['tracking_id','claim_type','claim_amount','status','created_at']],
                    'appointments' => ['title' => 'Appointments', 'columns' => ['tracking_id','purpose','preferred_date','preferred_time','status','created_at']],
                    'loan_applications' => ['title' => 'Loan Applications', 'columns' => ['loan_type','loan_amount','status','created_at']],
                    'account_applications' => ['title' => 'Account Applications', 'columns' => ['account_type','status','created_at']],
                    'member_surveys' => ['title' => 'Member Surveys', 'columns' => ['tracking_id','satisfaction_level','suggestions','created_at']],
                    'password_resets' => ['title' => 'Password Reset Requests', 'columns' => ['status','requested_at','resolved_at']],
                ];
                ?>

                <?php if ($viewMode === 'timeline'): ?>
                    <div class="card shadow-sm border-0 mb-3">
                        <div class="card-header bg-white fw-bold">Activity Timeline (<?php echo count($timeline); ?>)</div>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0">
                                <thead class="table-light"><tr><th>Date</th><th>Type</th><th>Status</th><th>Detail</th></tr></thead>
                                <tbody>
                                <?php if (empty($timeline)): ?>
                                    <tr><td colspan="4" class="text-muted">रेकर्ड छैन।</td></tr>
                                <?php else: ?>
                                    <?php foreach ($timeline as $t): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)($t['date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string)($t['type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string)($t['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string)($t['detail'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                <?php foreach ($sections as $key => $meta): $rows = $activities[$key] ?? []; ?>
                    <div class="card shadow-sm border-0 mb-3">
                        <div class="card-header bg-white fw-bold"><?php echo htmlspecialchars($meta['title']); ?> (<?php echo count($rows); ?>)</div>
                        <?php if (empty($rows)): ?>
                            <div class="card-body text-muted small">रेकर्ड छैन।</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <?php foreach ($meta['columns'] as $col): ?>
                                                <th><?php echo htmlspecialchars(str_replace('_', ' ', $col)); ?></th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($rows as $r): ?>
                                        <tr>
                                            <?php foreach ($meta['columns'] as $col): ?>
                                                <?php $val = $r[$col] ?? '—'; ?>
                                                <td><?php echo htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8'); ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once 'includes/admin-footer.php'; ?>
