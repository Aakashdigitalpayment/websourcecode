<?php
/**
 * Admin Analytics Dashboard
 * Charts: member growth, applications, KYC funnel, attendance, welfare
 */
$pageTitle   = 'Analytics Dashboard';
$currentPage = 'analytics';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$db = getDB();

/* ── Data collection — all try/catch so missing tables don't break page ── */

/* 1. Member growth — last 12 months */
$memberGrowth = [];
try {
    $st = $db->query("
        SELECT DATE_FORMAT(created_at,'%Y-%m') AS mo, COUNT(*) AS cnt
        FROM members
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY mo ORDER BY mo ASC LIMIT 12");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $memberGrowth[$r['mo']] = (int)$r['cnt'];
    }
} catch (Throwable $e) {}

/* Fill all 12 months */
$growthLabels = []; $growthData = [];
for ($i = 11; $i >= 0; $i--) {
    $mo = date('Y-m', strtotime("-$i months"));
    $growthLabels[] = date('M Y', strtotime("-$i months"));
    $growthData[]   = $memberGrowth[$mo] ?? 0;
}

/* Cumulative for area chart */
$cumData = []; $cum = 0;
try {
  $total0 = core_safe_count($db, "SELECT COUNT(*) FROM members WHERE created_at < DATE_SUB(NOW(), INTERVAL 12 MONTH)", '[analytics base-members]');
    $cum = $total0;
} catch (Throwable $e) {}
foreach ($growthData as $v) { $cum += $v; $cumData[] = $cum; }

/* 2. Total summary stats */
$stats = [];
$statDefs = [
    'members'   => "SELECT COUNT(*) FROM members WHERE approval_status='approved'",
    'kyc_total' => "SELECT COUNT(*) FROM kyc_applications",
    'kyc_done'  => "SELECT COUNT(*) FROM kyc_applications WHERE status='approved'",
    'loans'     => "SELECT COUNT(*) FROM loan_applications",
    'accounts'  => "SELECT COUNT(*) FROM account_applications",
    'appoints'  => "SELECT COUNT(*) FROM appointments",
    'welfare'   => "SELECT COUNT(*) FROM member_welfare_claims",
    'attend'    => "SELECT COUNT(DISTINCT member_id) FROM member_program_attendance",
];
foreach ($statDefs as $k => $q) {
  $stats[$k] = core_safe_count($db, $q, '[analytics stat ' . $k . ']');
}

/* 3. Applications by type (pie) */
$appTypes = [
    'KYC'          => $stats['kyc_total'],
    'ऋण'           => $stats['loans'],
    'खाता'         => $stats['accounts'],
    'भेटघाट'       => $stats['appoints'],
    'कल्याण'       => $stats['welfare'],
];
$appTypes['गुनासो'] = core_safe_count($db, "SELECT COUNT(*) FROM grievances", '[analytics app grievance]');
$appTypes['जागिर']  = core_safe_count($db, "SELECT COUNT(*) FROM job_applications", '[analytics app jobs]');

/* 4. KYC funnel */
$kycStatus = [];
try {
    $st = $db->query("SELECT status, COUNT(*) as cnt FROM kyc_applications GROUP BY status");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $kycStatus[$r['status']] = (int)$r['cnt'];
} catch (Throwable $e) {}

/* 5. Applications last 6 months */
$appMonths = []; $appMonthLabels = [];
for ($i = 5; $i >= 0; $i--) {
    $mo = date('Y-m', strtotime("-$i months"));
    $appMonthLabels[] = date('M', strtotime("-$i months"));
    $appMonths[$mo] = 0;
}
$appTables = ['kyc_applications','loan_applications','account_applications','appointments'];
foreach ($appTables as $tbl) {
    try {
        $st = $db->query("SELECT DATE_FORMAT(created_at,'%Y-%m') AS mo, COUNT(*) AS cnt FROM $tbl WHERE created_at>=DATE_SUB(NOW(),INTERVAL 6 MONTH) GROUP BY mo");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($appMonths[$r['mo']])) $appMonths[$r['mo']] += (int)$r['cnt'];
        }
    } catch (Throwable $e) {}
}

/* 6. Welfare by type */
$welfarTypes = [];
try {
    $st = $db->query("SELECT claim_type, COUNT(*) as cnt FROM member_welfare_claims GROUP BY claim_type");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $welfarTypes[$r['claim_type']] = (int)$r['cnt'];
} catch (Throwable $e) {}

/* 7. Program attendance per program (top 8) */
$progAttend = [];
try {
    $st = $db->query("SELECT program_title, COUNT(*) as cnt FROM member_program_attendance GROUP BY program_title ORDER BY cnt DESC LIMIT 8");
    $progAttend = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

/* 8. Member approval status breakdown */
$memStatus = [];
try {
    $st = $db->query("SELECT approval_status, COUNT(*) as cnt FROM members GROUP BY approval_status");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $memStatus[$r['approval_status']] = (int)$r['cnt'];
} catch (Throwable $e) {}

/* ── JSON encode all datasets ── */
$j = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE);
?>
<div class="container-fluid py-3">
  <?php echo adminPageHeader('Analytics Dashboard', 'fa-chart-line', 'Member growth, applications, attendance र welfare को visual overview।'); ?>
  <?php if ($f = getFlash()): ?><div class="mb-3"><?php echo adminAlert($f['type'], $f['message']); ?></div><?php endif; ?>

  <!-- Summary Stat Cards -->
  <div class="row g-3 mb-4">
    <?php
    $cards = [
        ['label'=>'अनुमोदित सदस्य',    'val'=>$stats['members'],   'icon'=>'fa-users',          'color'=>'success'],
        ['label'=>'KYC अनुमोदित',       'val'=>$stats['kyc_done'],  'icon'=>'fa-id-card',        'color'=>'primary'],
        ['label'=>'उपस्थित (Programs)',  'val'=>$stats['attend'],    'icon'=>'fa-calendar-check', 'color'=>'info'],
        ['label'=>'कल्याण दाबी',         'val'=>$stats['welfare'],   'icon'=>'fa-heart-pulse',    'color'=>'danger'],
        ['label'=>'ऋण आवेदन',           'val'=>$stats['loans'],     'icon'=>'fa-hand-holding-dollar','color'=>'warning'],
        ['label'=>'खाता खोल्ने',         'val'=>$stats['accounts'],  'icon'=>'fa-building-columns','color'=>'secondary'],
    ];
    foreach ($cards as $c):
    ?>
    <div class="col-6 col-md-4 col-xl-2">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body text-center py-3">
          <div class="mb-2 text-<?= $c['color'] ?>"><i class="fas <?= $c['icon'] ?> fa-lg"></i></div>
          <div class="fw-bold fs-4 text-<?= $c['color'] ?>"><?= number_format($c['val']) ?></div>
          <div class="small text-muted"><?= $c['label'] ?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Row 1: Member growth + Application types -->
  <div class="row g-3 mb-3">
    <div class="col-lg-8">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
          <h6 class="mb-0 fw-bold"><i class="lucide-icon text-success me-2" aria-hidden="true" data-lucide="chart-line"></i>सदस्य वृद्धि (पछिल्ला १२ महिना)</h6>
          <span class="badge bg-success-subtle text-success">Cumulative + Monthly</span>
        </div>
        <div class="card-body">
          <canvas id="chartGrowth" height="90"></canvas>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-white py-3">
          <h6 class="mb-0 fw-bold"><i class="fas fa-chart-pie text-primary me-2"></i>आवेदन प्रकार</h6>
        </div>
        <div class="card-body d-flex align-items-center justify-content-center">
          <canvas id="chartAppTypes" height="180"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Row 2: Monthly applications + KYC funnel -->
  <div class="row g-3 mb-3">
    <div class="col-md-7">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
          <h6 class="mb-0 fw-bold"><i class="lucide-icon text-info me-2" aria-hidden="true" data-lucide="chart-bar"></i>मासिक आवेदनहरू (पछिल्ला ६ महिना)</h6>
        </div>
        <div class="card-body">
          <canvas id="chartMonthly" height="120"></canvas>
        </div>
      </div>
    </div>
    <div class="col-md-5">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
          <h6 class="mb-0 fw-bold"><i class="fas fa-filter text-warning me-2"></i>KYC अवस्था</h6>
        </div>
        <div class="card-body">
          <canvas id="chartKyc" height="160"></canvas>
          <!-- KYC rate -->
          <?php
          $kycTotal = array_sum($kycStatus);
          $kycApprv = $kycStatus['approved'] ?? 0;
          $kycRate  = $kycTotal > 0 ? round($kycApprv / $kycTotal * 100, 1) : 0;
          ?>
          <div class="mt-3">
            <div class="d-flex justify-content-between small text-muted mb-1">
              <span>KYC Approval Rate</span><span><?= $kycRate ?>%</span>
            </div>
            <div class="progress anl-progress-md">
              <div class="progress-bar bg-success anl-progress-bar" data-width="<?= (float)$kycRate ?>"></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Row 3: Attendance per program + Welfare by type -->
  <div class="row g-3 mb-3">
    <div class="col-md-7">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
          <h6 class="mb-0 fw-bold"><i class="lucide-icon text-success me-2" aria-hidden="true" data-lucide="calendar-check"></i>कार्यक्रम उपस्थिति (Top 8)</h6>
        </div>
        <div class="card-body">
          <?php if (empty($progAttend)): ?>
          <div class="text-center text-muted py-4"><i class="fas fa-calendar-xmark fa-2x mb-2 d-block"></i>कुनै attendance data छैन</div>
          <?php else: ?>
          <canvas id="chartAttend" height="130"></canvas>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-md-5">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
          <h6 class="mb-0 fw-bold"><i class="fas fa-heart text-danger me-2"></i>कल्याण दाबी प्रकार</h6>
        </div>
        <div class="card-body">
          <?php if (empty($welfarTypes)): ?>
          <div class="text-center text-muted py-4"><i class="fas fa-heart-crack fa-2x mb-2 d-block"></i>कुनै welfare data छैन</div>
          <?php else: ?>
          <canvas id="chartWelfare" height="200"></canvas>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Row 4: Member approval status table -->
  <div class="row g-3">
    <div class="col-md-4">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
          <h6 class="mb-0 fw-bold"><i class="lucide-icon text-primary me-2" aria-hidden="true" data-lucide="users"></i>सदस्य अनुमोदन अवस्था</h6>
        </div>
        <div class="card-body">
          <canvas id="chartMemStatus" height="200"></canvas>
        </div>
      </div>
    </div>
    <div class="col-md-8">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
          <h6 class="mb-0 fw-bold"><i class="fas fa-table text-secondary me-2"></i>Quick Summary</h6>
        </div>
        <div class="card-body p-0">
          <table class="table table-sm table-hover mb-0 anl-table-compact">
            <thead class="table-light"><tr><th>मेट्रिक</th><th class="text-end">संख्या</th><th class="text-end">%</th></tr></thead>
            <tbody>
              <?php
              $total = max(1, $stats['members']);
              $rows = [
                  ['KYC अनुमोदित',        $stats['kyc_done'],  $stats['kyc_total']],
                  ['Program उपस्थित',      $stats['attend'],    $stats['members']],
                  ['कल्याण दाबी',          $stats['welfare'],   $stats['members']],
                  ['ऋण आवेदन',             $stats['loans'],     $stats['members']],
                  ['खाता खोल्ने',           $stats['accounts'],  $stats['members']],
                  ['भेटघाट / Appointment', $stats['appoints'],  $stats['members']],
              ];
              foreach ($rows as [$label, $num, $denom]):
                  $pct = $denom > 0 ? round($num/$denom*100,1) : 0;
              ?>
              <tr>
                <td><?= $label ?></td>
                <td class="text-end fw-bold"><?= number_format($num) ?></td>
                <td class="text-end">
                  <div class="d-flex align-items-center justify-content-end gap-2">
                    <div class="progress flex-grow-1 anl-progress-sm">
                      <div class="progress-bar bg-success anl-progress-bar" data-width="<?= (float)min(100,$pct) ?>"></div>
                    </div>
                    <span class="anl-pct-label"><?= $pct ?>%</span>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Mukta','Noto Sans Devanagari',sans-serif";
Chart.defaults.color = '#6b7280';
var primaryColor = getComputedStyle(document.documentElement).getPropertyValue('--bs-primary') || '#1a5f2a';

/* 1. Member Growth */
new Chart('chartGrowth', {type:'bar', data:{
    labels: <?= $j($growthLabels) ?>,
    datasets:[
        {type:'line', label:'कुल सदस्य', data:<?= $j($cumData) ?>, borderColor:'#1a5f2a', backgroundColor:'rgba(26,95,42,.08)', fill:true, tension:.4, pointRadius:4, yAxisID:'y1'},
        {type:'bar', label:'नयाँ सदस्य', data:<?= $j($growthData) ?>, backgroundColor:'rgba(26,95,42,.65)', borderRadius:4, yAxisID:'y'}
    ]
}, options:{responsive:true, interaction:{mode:'index'}, scales:{
    y:{beginAtZero:true, grid:{color:'#f3f4f6'}, title:{display:true,text:'नयाँ'}},
    y1:{position:'right', beginAtZero:true, grid:{display:false}, title:{display:true,text:'कुल'}}
}, plugins:{legend:{position:'bottom'}}}});

/* 2. Application types doughnut */
var appData = <?= $j(array_values($appTypes)) ?>;
var appSum  = appData.reduce((a,b)=>a+b,0);
if(appSum > 0) new Chart('chartAppTypes', {type:'doughnut', data:{
    labels: <?= $j(array_keys($appTypes)) ?>,
    datasets:[{data: appData,
        backgroundColor:['#1a5f2a','var(--secondary-color,#c0392b)','var(--secondary-dark,#922b21)','#d97706','#dc2626','#0f766e','#374151'],
        borderWidth:2, borderColor:'#fff'}]
}, options:{plugins:{legend:{position:'bottom',labels:{font:{size:11},padding:8}}}, cutout:'65%'}});

/* 3. Monthly applications */
new Chart('chartMonthly', {type:'bar', data:{
    labels: <?= $j($appMonthLabels) ?>,
    datasets:[{label:'आवेदनहरू', data:<?= $j(array_values($appMonths)) ?>,
        backgroundColor:'rgba(21,101,192,.7)', borderRadius:5}]
}, options:{responsive:true, scales:{y:{beginAtZero:true, grid:{color:'#f3f4f6'}}}, plugins:{legend:{display:false}}}});

/* 4. KYC status */
var kycLabels = <?= $j(array_keys($kycStatus)) ?>;
var kycData   = <?= $j(array_values($kycStatus)) ?>;
if(kycLabels.length > 0) new Chart('chartKyc', {type:'doughnut', data:{
    labels: kycLabels,
    datasets:[{data: kycData,
        backgroundColor:['#d97706','var(--secondary-color,#c0392b)','#16a34a','#dc2626','#9ca3af'],
        borderWidth:2, borderColor:'#fff'}]
}, options:{plugins:{legend:{position:'bottom',labels:{font:{size:10},padding:6}}}, cutout:'60%'}});

/* 5. Attendance per program */
<?php if (!empty($progAttend)): ?>
new Chart('chartAttend', {type:'bar', data:{
    labels: <?= $j(array_column($progAttend,'program_title')) ?>,
    datasets:[{label:'उपस्थित', data:<?= $j(array_map(fn($r)=>(int)$r['cnt'], $progAttend)) ?>,
        backgroundColor:'rgba(26,95,42,.7)', borderRadius:4}]
}, options:{indexAxis:'y', responsive:true, scales:{x:{beginAtZero:true, grid:{color:'#f3f4f6'}}}, plugins:{legend:{display:false}}}});
<?php endif; ?>

/* 6. Welfare types */
<?php if (!empty($welfarTypes)): ?>
var wlLabels = <?= $j(array_keys($welfarTypes)) ?>;
var wlData   = <?= $j(array_values($welfarTypes)) ?>;
new Chart('chartWelfare', {type:'pie', data:{
    labels: wlLabels,
    datasets:[{data: wlData,
        backgroundColor:['#dc2626','#d97706','var(--secondary-color,#c0392b)','var(--secondary-dark,#922b21)','#16a34a'],
        borderWidth:2, borderColor:'#fff'}]
}, options:{plugins:{legend:{position:'bottom',labels:{font:{size:10},padding:6}}}}});
<?php endif; ?>

/* 7. Member approval status */
var msKeys = <?= $j(array_keys($memStatus)) ?>;
var msVals = <?= $j(array_values($memStatus)) ?>;
if(msKeys.length > 0) new Chart('chartMemStatus', {type:'doughnut', data:{
    labels: msKeys,
    datasets:[{data: msVals,
        backgroundColor:['#16a34a','#d97706','#dc2626','#9ca3af'],
        borderWidth:2, borderColor:'#fff'}]
}, options:{plugins:{legend:{position:'bottom',labels:{font:{size:10},padding:6}}}, cutout:'55%'}});

document.querySelectorAll('.anl-progress-bar[data-width]').forEach(function (el) {
    var w = parseFloat(el.getAttribute('data-width') || '0');
    if (!isFinite(w)) w = 0;
    if (w < 0) w = 0;
    if (w > 100) w = 100;
    el.style.width = w + '%';
});
</script>
<?php require_once 'includes/admin-footer.php'; ?>
