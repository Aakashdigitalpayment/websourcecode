<?php
require_once __DIR__ . '/includes/admin-page-boot.php';
require_once __DIR__ . '/includes/program-reports-common.php';
$pageTitle = 'Duplicate Attempts';
$currentPage = 'program-reports-duplicates';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$db = programReportsInit();
$programId = (int)($_GET['program_id'] ?? 0);
$programs = programReportsProgramList($db);
$resultLabels = [
    'DUPLICATE_BLOCKED' => ['दोहोरो (पहिले नै उपस्थित)', 'danger'],
    'INELIGIBLE'        => ['अयोग्य सदस्य', 'warning'],
    'WINDOW_CLOSED'     => ['समय बाहिर (window बन्द)', 'secondary'],
    'INVALID_MEMBER'    => ['अमान्य सदस्य', 'dark'],
    'INVALID_PROGRAM'   => ['अमान्य कार्यक्रम', 'dark'],
    'OTHER'             => ['अन्य', 'light'],
];
$result = (string)($_GET['result'] ?? '');
if (!isset($resultLabels[$result])) {
    $result = '';
}

$where = '1=1';
$params = [];
if ($programId > 0) {
    $where .= ' AND pa.parent_program_id=?';
    $params[] = $programId;
}
$cst = $db->prepare("SELECT pa.result, COUNT(*) FROM program_attendance_attempts pa WHERE $where GROUP BY pa.result");
$cst->execute($params);
$counts = $cst->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
if ($result !== '') {
    $where .= ' AND pa.result=?';
    $params[] = $result;
}
$total = $result !== '' ? (int)($counts[$result] ?? 0) : array_sum(array_map('intval', $counts));

$st = $db->prepare("SELECT pa.*, m.name AS member_name, m.sadasyata_number, p.title AS program_title,
                           prev.location_label AS prev_location, prev.attended_at AS prev_attended_at,
                           d.desk_label, u.full_name AS staff_name
                    FROM program_attendance_attempts pa
                    LEFT JOIN members m ON m.id=pa.member_id
                    LEFT JOIN upcoming_programs p ON p.id=pa.parent_program_id
                    LEFT JOIN member_program_attendance prev ON prev.id=pa.previous_attendance_id
                    LEFT JOIN program_registration_desks d ON d.id=pa.desk_id
                    LEFT JOIN admin_users u ON u.id=pa.staff_admin_id
                    WHERE $where
                    ORDER BY pa.attempted_at DESC LIMIT " . PROGRAM_REPORT_DISPLAY_LIMIT);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$filterUrl = static fn(string $r): string => '?' . http_build_query(array_filter(['program_id' => $programId ?: null, 'result' => $r ?: null]));
?>
<div class="container-fluid py-3">
  <?php echo adminPageHeader('Duplicate / Blocked Attempts Log', 'fa-shield-alt', 'पहिले नै उपस्थित सदस्यले फेरि प्रयास गर्दा, अयोग्य सदस्य वा समय बाहिरको प्रयास — सबै यहाँ।'); ?>
  <?php echo programReportsTabs('program-reports-duplicates', $programId); ?>
  <?php echo programReportsFilterCard($programs, $programId, '— सबै कार्यक्रम —', false); ?>

  <div class="d-flex flex-wrap gap-2 mb-3">
    <a href="<?php echo htmlspecialchars($filterUrl('')); ?>" class="btn btn-sm <?php echo $result === '' ? 'btn-dark' : 'btn-outline-dark'; ?>">सबै <span class="badge bg-light text-dark ms-1"><?php echo array_sum(array_map('intval', $counts)); ?></span></a>
    <?php foreach ($resultLabels as $code => [$label, $color]): if (empty($counts[$code])) continue; ?>
      <a href="<?php echo htmlspecialchars($filterUrl($code)); ?>" class="btn btn-sm <?php echo $result === $code ? 'btn-' . $color : 'btn-outline-' . $color; ?>"><?php echo htmlspecialchars($label); ?> <span class="badge bg-light text-dark ms-1"><?php echo (int)$counts[$code]; ?></span></a>
    <?php endforeach; ?>
  </div>

  <?php echo programReportsLimitNote(count($rows), $total); ?>
  <div class="card admin-table-card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
    <thead><tr><th>समय</th><th>सदस्य</th><th>कार्यक्रम</th><th>कारण</th><th>पहिलेको उपस्थिति</th><th>Desk / Staff</th><th>विधि</th></tr></thead>
    <tbody><?php foreach ($rows as $r): [$rl, $rc] = $resultLabels[$r['result'] ?? 'OTHER'] ?? $resultLabels['OTHER']; ?><tr>
      <td class="small text-nowrap"><?php echo htmlspecialchars(programReportsFormatAttendedAt($r['attempted_at'] ?? '')); ?></td>
      <td><?php if (!empty($r['sadasyata_number'])): ?><a href="program-member-history.php?member_id=<?php echo rawurlencode((string)$r['sadasyata_number']); ?>" class="text-decoration-none"><?php echo htmlspecialchars((string)($r['member_name'] ?? '')); ?></a> <span class="small text-muted font-monospace"><?php echo htmlspecialchars((string)$r['sadasyata_number']); ?></span><?php else: ?><?php echo htmlspecialchars((string)($r['member_name'] ?? '—')); ?><?php endif; ?></td>
      <td class="small"><?php echo htmlspecialchars((string)($r['program_title'] ?? '')); ?></td>
      <td><span class="badge bg-<?php echo $rc; ?><?php echo in_array($rc, ['warning', 'light'], true) ? ' text-dark' : ''; ?>"><?php echo htmlspecialchars($rl); ?></span>
        <?php if (!empty($r['result_message'])): ?><div class="small text-muted"><?php echo htmlspecialchars((string)$r['result_message']); ?></div><?php endif; ?></td>
      <td class="small"><?php echo htmlspecialchars(trim(($r['prev_location'] ?? '') . ' ' . programReportsFormatAttendedAt($r['prev_attended_at'] ?? ''))) ?: '—'; ?></td>
      <td class="small"><?php echo htmlspecialchars(trim(implode(' · ', array_filter([(string)($r['desk_label'] ?? ''), (string)($r['staff_name'] ?? '')])))) ?: '—'; ?></td>
      <td class="small"><?php echo htmlspecialchars(programAttendanceMethodLabel($r['attempted_method'] ?? '')); ?></td>
    </tr><?php endforeach; if (empty($rows)): ?><tr><td colspan="7" class="text-muted text-center py-4">अहिलेसम्म कुनै रोकिएको प्रयास छैन।<div class="small">डेस्कमा पहिले नै उपस्थित सदस्यको Member ID हाल्दा वा सदस्यले फेरि QR check-in गर्दा यहाँ देखिन्छ।</div></td></tr><?php endif; ?></tbody>
  </table></div></div>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
