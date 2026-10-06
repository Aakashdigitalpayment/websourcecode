<?php
require_once __DIR__ . '/includes/admin-page-boot.php';
require_once __DIR__ . '/includes/program-reports-common.php';
require_once __DIR__ . '/../includes/program-member-insights.php';
require_once __DIR__ . '/../includes/member-monthly-saving.php';

$db = programReportsInit();
$programId = (int)($_GET['program_id'] ?? 0);
$prog = programReportsSelectedProgram($db, $programId);
$scopeId = $prog ? programResolveScopeId($prog) : 0;
$sections = [
    'gender'   => ['लिङ्गअनुसार', 'users'],
    'staff'    => ['Staff / user अनुसार', 'user-check'],
    'location' => ['स्थानअनुसार', 'map-pin'],
    'desk'     => ['Desk अनुसार', 'monitor'],
    'method'   => ['विधिअनुसार', 'tag'],
    'ms'       => ['मासिक बचतअनुसार', 'piggy-bank'],
];
$f = programBreakdownFilterSpec($_GET);
$active = array_filter($f, static fn($v) => $v !== '');
$export = (string)($_GET['export'] ?? '');

/* URL that keeps program + current filters; $set overrides (''/null = remove) */
$bdUrl = static function (array $set = []) use ($programId, $f): string {
    $q = array_filter(array_merge(['program_id' => $programId], $f, $set), static fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($q);
};

if ($prog && $export === 'csv') {
    $data = programAttendanceBreakdownFiltered($db, $scopeId, $f);
    $rows = [];
    foreach ($sections as $key => [$label]) {
        foreach ($data[$key] ?? [] as $r) {
            $rows[] = [$label, $r['label'], $r['count']];
        }
    }
    programReportsCsvStream('program-breakdown-summary-' . $programId . '.csv', ['Group', 'Value', 'Attendance'], $rows,
        static fn(array $r): array => $r);
}

if ($prog && $export === 'detail') {
    $res = programAttendanceDetailRows($db, $scopeId, $f);
    if (function_exists('writeAuditLog')) {
        writeAuditLog('program_report_export', 'उपस्थिति विस्तृत export (' . $res['total'] . ' row)', 'program', $programId);
    }
    $n = 0;
    programReportsCsvStream('program-attendance-detail-' . $programId . '.csv',
        ['S.N.', 'Member ID', 'नाम', 'बुबाको नाम', 'लिङ्ग', 'फोन', 'ठेगाना', 'मासिक बचत', 'स्थान', 'Desk', 'Staff / user', 'Username', 'विधि', 'उपस्थिति समय'],
        $res['rows'],
        static function (array $r) use (&$n): array {
            return [
                ++$n, $r['member_card_no'], $r['name'], $r['father_name'],
                programGenderLabel(programGenderKey($r['gender_raw'] ?? '')),
                $r['phone'], $r['address'],
                coop_monthly_saving_label(coop_monthly_saving_from_db($r['monthly_saving'] ?? null)),
                $r['loc'], (int)$r['desk_id'] > 0 ? ($r['desk_label'] ?: 'Desk #' . (int)$r['desk_id']) : '',
                (int)$r['staff_admin_id'] > 0 ? ($r['staff_name'] ?: 'Admin #' . (int)$r['staff_admin_id']) : 'Staff बिना',
                $r['staff_user'] ?? '',
                programReportsCsvMethodLabel($r['attendance_method'] ?? ''),
                programReportsFormatAttendedAt($r['attended_at'] ?? null),
            ];
        });
}

$pageTitle = 'उपस्थिति विश्लेषण';
$currentPage = 'program-reports-breakdown';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$programs = programReportsProgramList($db);
$perPage = 100;
$page = max(1, (int)($_GET['page'] ?? 1));
$data = $all = $detail = [];
$total = $grandTotal = 0;
if ($prog) {
    $data = programAttendanceBreakdownFiltered($db, $scopeId, $f);
    /* Dropdown options always come from the unfiltered set so you can switch between values */
    $all = $active ? programAttendanceBreakdownFiltered($db, $scopeId, programBreakdownFilterSpec([])) : $data;
    foreach ($data['method'] ?? [] as $r) $total += $r['count'];
    foreach ($all['method'] ?? [] as $r) $grandTotal += $r['count'];
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $detail = programAttendanceDetailRows($db, $scopeId, $f, $perPage, ($page - 1) * $perPage);
}
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$filterLabels = ['gender' => 'लिङ्ग', 'staff' => 'Staff', 'desk' => 'Desk', 'location' => 'स्थान', 'method' => 'विधि', 'ms' => 'मासिक बचत', 'q' => 'खोज'];
$labelFor = static function (string $k, string $v) use ($all): string {
    foreach ($all[$k] ?? [] as $r) {
        if ((string)$r['key'] === $v) return (string)$r['label'];
    }
    return $v;
};
?>
<div class="container-fluid py-3">
  <?php echo adminPageHeader('उपस्थिति विश्लेषण', 'fa-chart-pie', 'लिङ्ग, staff user, स्थान, desk, विधि र मासिक बचतअनुसार — को को आए भन्ने विस्तृत सूचीसहित।'); ?>
  <?php echo programReportsTabs('program-reports-breakdown', $programId); ?>
  <?php echo programReportsFilterCard($programs, $programId, '— कार्यक्रम छान्नुहोस् —', false); ?>
  <?php if ($prog): ?>

  <div class="card admin-table-card mb-3">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
      <h6 class="mb-0 me-auto"><i class="lucide-icon me-1" data-lucide="filter" aria-hidden="true"></i>Filter</h6>
      <a class="btn btn-success btn-sm" href="<?php echo $h($bdUrl(['export' => 'detail'])); ?>"><i class="lucide-icon me-1" data-lucide="file-spreadsheet" aria-hidden="true"></i>विस्तृत Excel<?php echo $active ? ' (filter अनुसार)' : ''; ?></a>
      <a class="btn btn-outline-success btn-sm" href="<?php echo $h($bdUrl(['export' => 'csv'])); ?>"><i class="lucide-icon me-1" data-lucide="download" aria-hidden="true"></i>सारांश CSV</a>
    </div>
    <div class="card-body">
      <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="program_id" value="<?php echo $programId; ?>">
        <?php foreach (['gender', 'staff', 'location', 'desk', 'method', 'ms'] as $k): ?>
        <div class="col-6 col-md-4 col-xl-2">
          <label class="form-label small mb-1" for="bd_<?php echo $k; ?>"><?php echo $h($filterLabels[$k]); ?></label>
          <select class="form-select form-select-sm" id="bd_<?php echo $k; ?>" name="<?php echo $k; ?>">
            <option value="">सबै</option>
            <?php foreach ($all[$k] ?? [] as $r): if ($k === 'method' && $r['key'] === '') continue; ?>
            <option value="<?php echo $h($r['key']); ?>"<?php echo $f[$k] === (string)$r['key'] ? ' selected' : ''; ?>><?php echo $h($r['label']); ?> (<?php echo (int)$r['count']; ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endforeach; ?>
        <div class="col-12 col-md-6 col-xl-4">
          <label class="form-label small mb-1" for="bd_q">नाम / Member ID / फोन</label>
          <input type="search" class="form-control form-control-sm" id="bd_q" name="q" value="<?php echo $h($f['q']); ?>" placeholder="खोज्नुहोस्…">
        </div>
        <div class="col-auto d-flex gap-2">
          <button type="submit" class="btn btn-primary btn-sm"><i class="lucide-icon me-1" data-lucide="search" aria-hidden="true"></i>लागू गर्नुहोस्</button>
          <?php if ($active): ?><a class="btn btn-outline-secondary btn-sm" href="?program_id=<?php echo $programId; ?>">सबै हटाउनुहोस्</a><?php endif; ?>
        </div>
      </form>
      <?php if ($active): ?>
      <div class="d-flex flex-wrap gap-2 mt-3" aria-label="लागू filter">
        <?php foreach ($active as $k => $v): ?>
        <a class="badge rounded-pill text-bg-light border text-decoration-none py-2 px-3" href="<?php echo $h($bdUrl([$k => null])); ?>" title="यो filter हटाउनुहोस्">
          <?php echo $h($filterLabels[$k] ?? $k); ?>: <strong><?php echo $h($k === 'q' ? $v : $labelFor($k, $v)); ?></strong> <span aria-hidden="true">✕</span>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="mb-3 d-flex flex-wrap gap-2 align-items-center">
    <span class="badge bg-success fs-6"><?php echo $active ? 'Filter मिल्ने' : 'कुल उपस्थिति'; ?>: <?php echo $total; ?></span>
    <?php if ($active): ?><span class="text-muted small">जम्मा <?php echo $grandTotal; ?> मध्ये</span><?php endif; ?>
    <span class="text-muted small ms-md-2">तलका कुनै पनि पङ्क्तिमा click गर्दा त्यसैको सूची खुल्छ।</span>
  </div>
  <div class="row g-3">
    <?php foreach ($sections as $key => [$label, $icon]): $rows = $data[$key] ?? []; if ($key === 'ms' && !isset($data['ms'])) continue; ?>
    <div class="col-md-6 col-xl-4">
      <div class="card admin-table-card h-100">
        <div class="card-header"><h6 class="mb-0"><i class="lucide-icon me-1" data-lucide="<?php echo $icon; ?>" aria-hidden="true"></i><?php echo $h($label); ?></h6></div>
        <div class="card-body">
          <?php if (!$rows): ?><div class="text-muted small">डाटा छैन।</div><?php endif; ?>
          <?php foreach ($rows as $r): $pct = $total > 0 ? round($r['count'] * 100 / $total, 1) : 0; $on = $f[$key] !== '' && $f[$key] === (string)$r['key']; ?>
          <a class="d-block mb-2 text-reset text-decoration-none rounded px-1<?php echo $on ? ' bg-success-subtle' : ''; ?>" href="<?php echo $h($bdUrl([$key => $on ? null : $r['key'], 'page' => null])); ?>" title="<?php echo $on ? 'Filter हटाउनुहोस्' : 'यसको सूची हेर्नुहोस्'; ?>">
            <div class="d-flex justify-content-between small"><span><?php echo $h($r['label']); ?></span><strong><?php echo (int)$r['count']; ?> <span class="text-muted fw-normal">(<?php echo $pct; ?>%)</span></strong></div>
            <div class="progress" style="height:6px" role="progressbar" aria-label="<?php echo $h($r['label']); ?>" aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-success" style="width:<?php echo $pct; ?>%"></div></div>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="card admin-table-card mt-3">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
      <h6 class="mb-0 me-auto"><i class="lucide-icon me-1" data-lucide="list" aria-hidden="true"></i>को को आए — विस्तृत सूची <span class="text-muted fw-normal">(<?php echo $total; ?>)</span></h6>
      <?php if ($total > $perPage): ?><span class="small text-muted">पृष्ठ <?php echo $page; ?> / <?php echo $pages; ?> — पूरा सूची Excel मा</span><?php endif; ?>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>#</th><th>Member ID</th><th>नाम</th><th>बुबाको नाम</th><th>लिङ्ग</th><th>फोन</th><th>मासिक बचत</th><th>स्थान</th><th>Desk</th><th>Staff / user</th><th>विधि</th><th>समय</th></tr></thead>
        <tbody>
        <?php if (empty($detail['rows'])): ?>
          <tr><td colspan="12" class="text-center text-muted py-4">यो filter मा कुनै उपस्थिति छैन।</td></tr>
        <?php endif; ?>
        <?php foreach ($detail['rows'] ?? [] as $i => $r): ?>
          <tr>
            <td class="text-muted"><?php echo ($page - 1) * $perPage + $i + 1; ?></td>
            <td><strong><?php echo $h($r['member_card_no']); ?></strong></td>
            <td><?php echo $h($r['name']); ?></td>
            <td><?php echo $h($r['father_name']); ?></td>
            <td><?php echo $h(programGenderLabel(programGenderKey($r['gender_raw'] ?? ''))); ?></td>
            <td><?php echo $h($r['phone']); ?></td>
            <td><?php echo coop_monthly_saving_badge_html(coop_monthly_saving_from_db($r['monthly_saving'] ?? null)); ?></td>
            <td><?php echo $h($r['loc']); ?></td>
            <td><?php echo (int)$r['desk_id'] > 0 ? $h($r['desk_label'] ?: 'Desk #' . (int)$r['desk_id']) : '<span class="text-muted">—</span>'; ?></td>
            <td><?php echo (int)$r['staff_admin_id'] > 0 ? $h($r['staff_name'] ?: 'Admin #' . (int)$r['staff_admin_id']) . ($r['staff_user'] ? ' <span class="text-muted small">@' . $h($r['staff_user']) . '</span>' : '') : '<span class="text-muted">Staff बिना</span>'; ?></td>
            <td><?php echo $h(programAttendanceMethodLabel($r['attendance_method'] ?? '')); ?></td>
            <td class="text-nowrap small"><?php echo $h(programReportsFormatAttendedAt($r['attended_at'] ?? null)); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pages > 1): ?>
    <div class="card-body py-2">
      <nav aria-label="पृष्ठ"><ul class="pagination pagination-sm mb-0 flex-wrap">
        <?php for ($p = 1; $p <= $pages; $p++): if ($pages > 12 && $p > 2 && $p < $pages - 1 && abs($p - $page) > 2) { if ($p === 3 || $p === $pages - 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; continue; } ?>
        <li class="page-item<?php echo $p === $page ? ' active' : ''; ?>"><a class="page-link" href="<?php echo $h($bdUrl(['page' => $p])); ?>"<?php echo $p === $page ? ' aria-current="page"' : ''; ?>><?php echo $p; ?></a></li>
        <?php endfor; ?>
      </ul></nav>
    </div>
    <?php endif; ?>
  </div>
  <div class="small text-muted mt-3">लिङ्ग: सदस्य रेकर्ड (नभए KYM) बाट। «नखुलेको» = लिङ्ग नराखिएका सदस्य। Staff: दर्ता गर्ने admin; QR / सदस्य आफैंको दर्तामा staff हुँदैन। Filter मिलाएर (जस्तै Staff = कुनै user + लिङ्ग = महिला) त्यही अनुसारको सूची र Excel निकाल्न सकिन्छ।</div>
  <?php endif; ?>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
