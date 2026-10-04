<?php
require_once __DIR__ . '/includes/admin-page-boot.php';
require_once __DIR__ . '/includes/program-reports-common.php';
require_once __DIR__ . '/../includes/program-member-insights.php';

$db = programReportsInit();
$q = mb_substr(trim((string)($_GET['member_id'] ?? '')), 0, 60);
$type = (string)($_GET['type'] ?? 'AGM');
$typeOptions = programTypeOptions();
if ($type !== '' && !isset($typeOptions[$type])) {
    $type = 'AGM';
}

$member = $q !== '' ? programResolveMemberBySadasyata($db, $q) : null;
$identity = $member ? programMemberIdentity($db, $member) : null;
$register = $member ? programMemberTypeRegister($db, (int)$member['id'], $type, 10) : null;
$log = $member ? programMemberAttendanceLog($db, (int)$member['id']) : [];

if ($member && ($_GET['export'] ?? '') === 'csv') {
    $sid = programMemberSadasyataNo($member);
    programReportsCsvStream('member-attendance-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $sid) . '.csv',
        ['Member ID', 'Name', 'Father', 'Program', 'Type', 'Program Date', 'Location', 'Method', 'Attended At'], $log,
        static fn(array $r): array => [
            $sid, $member['name'] ?? '', $identity['father_name'], $r['parent_title'] ?: $r['program_title'],
            $r['program_type'] ?? '', $r['event_date'] ?? '', $r['location_label'] ?? '',
            programReportsCsvMethodLabel($r['attendance_method'] ?? ''), $r['attended_at'] ?? '',
        ]);
}

$pageTitle = 'सदस्य उपस्थिति इतिहास';
$currentPage = 'program-reports-history';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$byType = [];
foreach ($log as $r) {
    $t = programTypeLabel($r['program_type'] ?? 'General');
    $byType[$t] = ($byType[$t] ?? 0) + 1;
}
arsort($byType);
$typeLabel = $type !== '' ? programTypeLabel($type) : 'सबै कार्यक्रम';
?>
<div class="container-fluid py-3">
  <?php echo adminPageHeader('सदस्य उपस्थिति इतिहास', 'fa-history', 'कुन सदस्य कुन कुन AGM / कार्यक्रममा आए — लगातार उपस्थिति पनि।'); ?>
  <?php echo programReportsTabs('program-reports-history', 0); ?>

  <div class="card admin-table-card mb-3"><div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-5"><label class="form-label small mb-0" for="mh_q">Member ID (सदस्यता नं.)</label>
        <input name="member_id" id="mh_q" class="form-control" value="<?php echo htmlspecialchars($q); ?>" placeholder="उदा. AKS-2080-0001" autocapitalize="characters" autofocus required></div>
      <div class="col-md-4"><label class="form-label small mb-0" for="mh_type">लगातार उपस्थिति जाँच</label>
        <select name="type" id="mh_type" class="form-select">
          <?php foreach ($typeOptions as $k => $o): ?><option value="<?php echo htmlspecialchars($k); ?>" <?php echo $type === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($o['np']); ?></option><?php endforeach; ?>
          <option value="" <?php echo $type === '' ? 'selected' : ''; ?>>सबै कार्यक्रम</option>
        </select></div>
      <div class="col-md-3 d-flex gap-2"><button type="submit" class="btn btn-primary flex-fill"><i class="lucide-icon me-1" data-lucide="search" aria-hidden="true"></i>हेर्नुहोस्</button>
        <?php if ($member): ?><a class="btn btn-success" href="?member_id=<?php echo rawurlencode($q); ?>&amp;type=<?php echo rawurlencode($type); ?>&amp;export=csv" title="CSV">CSV</a><?php endif; ?></div>
    </form>
  </div></div>

  <?php if ($q !== '' && !$member): ?>
    <div class="alert alert-warning">Member ID «<?php echo htmlspecialchars($q); ?>» फेला परेन।</div>
  <?php elseif ($member): ?>
  <div class="row g-3 mb-3">
    <div class="col-lg-4">
      <div class="card h-100"><div class="card-body">
        <div class="fs-5 fw-bold"><?php echo htmlspecialchars((string)($member['name'] ?? '')); ?></div>
        <div class="font-monospace text-muted mb-2"><?php echo htmlspecialchars(programMemberSadasyataNo($member)); ?></div>
        <div class="small"><span class="text-muted">बुबाको नाम:</span> <strong><?php echo htmlspecialchars($identity['father_name'] !== '' ? $identity['father_name'] : '—'); ?></strong></div>
        <?php if ($identity['dob_label'] !== ''): ?><div class="small"><span class="text-muted">जन्म मिति:</span> <?php echo htmlspecialchars($identity['dob_label'] . ($identity['age'] !== null ? ' (उमेर ' . $identity['age'] . ' वर्ष)' : '')); ?></div><?php endif; ?>
        <div class="small"><span class="text-muted">लिङ्ग:</span> <?php echo htmlspecialchars($identity['gender_label']); ?></div>
        <?php if (!empty($member['phone'])): ?><div class="small"><span class="text-muted">फोन:</span> <?php echo htmlspecialchars((string)$member['phone']); ?></div><?php endif; ?>
        <?php if ((int)($member['is_active'] ?? 1) !== 1): ?><span class="badge bg-secondary mt-2">निष्क्रिय सदस्य</span><?php endif; ?>
      </div></div>
    </div>
    <div class="col-lg-8">
      <div class="row g-2 h-100">
        <div class="col-6 col-md-3"><div class="card p-2 text-center h-100"><div class="small text-muted">कुल उपस्थिति</div><strong class="fs-3 text-success"><?php echo count($log); ?></strong></div></div>
        <div class="col-6 col-md-3"><div class="card p-2 text-center h-100"><div class="small text-muted"><?php echo htmlspecialchars($typeLabel); ?> (पछिल्ला <?php echo (int)$register['total']; ?>)</div><strong class="fs-3"><?php echo (int)$register['attended']; ?>/<?php echo (int)$register['total']; ?></strong></div></div>
        <div class="col-6 col-md-3"><div class="card p-2 text-center h-100"><div class="small text-muted">लगातार (भर्खरदेखि)</div><strong class="fs-3 <?php echo $register['streak'] >= 3 ? 'text-success' : ''; ?>"><?php echo (int)$register['streak']; ?></strong></div></div>
        <div class="col-6 col-md-3"><div class="card p-2 text-center h-100"><div class="small text-muted">पछिल्ला ३ मा</div>
          <?php $last3 = array_slice($register['rows'], 0, 3); $last3Att = count(array_filter($last3, static fn($r) => $r['attended'])); ?>
          <?php if (count($last3) < 3): ?><strong class="fs-6 text-muted mt-2">३ वटा <?php echo htmlspecialchars($typeLabel); ?> भएका छैनन्</strong>
          <?php elseif ($last3Att === 3): ?><strong class="fs-5 text-success mt-1">✓ लगातार ३ मा उपस्थित</strong>
          <?php else: ?><strong class="fs-5 text-warning mt-1"><?php echo $last3Att; ?>/३ मा मात्र</strong><?php endif; ?>
        </div></div>
        <?php if ($byType): ?>
        <div class="col-12"><div class="small text-muted">
          <?php foreach ($byType as $t => $c): ?><span class="badge bg-light text-dark border me-1"><?php echo htmlspecialchars($t); ?>: <?php echo (int)$c; ?></span><?php endforeach; ?>
        </div></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card admin-table-card">
        <div class="card-header"><h6 class="mb-0"><?php echo htmlspecialchars($typeLabel); ?> — उपस्थिति register</h6><div class="small text-muted">सम्पन्न भएका (कम्तीमा एक उपस्थिति भएका) पछिल्ला १० वटा</div></div>
        <div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr><th>कार्यक्रम</th><th>मिति</th><th class="text-center">स्थिति</th></tr></thead>
          <tbody>
          <?php foreach ($register['rows'] as $r): ?>
            <tr>
              <td><a href="program-detail.php?id=<?php echo (int)$r['id']; ?>" class="text-decoration-none"><?php echo htmlspecialchars((string)$r['title']); ?></a></td>
              <td class="small"><?php echo htmlspecialchars((string)($r['event_date'] ?: '—')); ?></td>
              <td class="text-center"><?php echo $r['attended'] ? '<span class="badge bg-success">उपस्थित</span>' : '<span class="badge bg-danger-subtle text-danger border border-danger-subtle">अनुपस्थित</span>'; ?></td>
            </tr>
          <?php endforeach; if (empty($register['rows'])): ?>
            <tr><td colspan="3" class="text-muted text-center py-3">यो प्रकारको सम्पन्न कार्यक्रम छैन।</td></tr>
          <?php endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card admin-table-card">
        <div class="card-header"><h6 class="mb-0">सबै उपस्थिति log</h6></div>
        <div class="table-responsive"><table class="table table-sm table-hover mb-0">
          <thead><tr><th>कार्यक्रम</th><th>प्रकार</th><th>स्थान</th><th>विधि</th><th>समय</th></tr></thead>
          <tbody>
          <?php foreach ($log as $r): ?>
            <tr>
              <td><?php echo htmlspecialchars((string)($r['parent_title'] ?: $r['program_title'])); ?><?php if (!empty($r['event_date'])): ?><div class="small text-muted"><?php echo htmlspecialchars((string)$r['event_date']); ?></div><?php endif; ?></td>
              <td class="small"><?php echo htmlspecialchars(programTypeLabel($r['program_type'] ?? 'General')); ?></td>
              <td class="small"><?php echo htmlspecialchars(($r['location_label'] ?? '') !== '' ? (string)$r['location_label'] : '—'); ?></td>
              <td class="small"><?php echo htmlspecialchars(programAttendanceMethodLabel($r['attendance_method'] ?? '')); ?></td>
              <td class="small"><?php echo htmlspecialchars(programReportsFormatAttendedAt($r['attended_at'] ?? '')); ?></td>
            </tr>
          <?php endforeach; if (empty($log)): ?>
            <tr><td colspan="5" class="text-muted text-center py-3">यो सदस्यको कुनै उपस्थिति छैन।</td></tr>
          <?php endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
