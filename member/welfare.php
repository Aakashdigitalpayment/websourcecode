<?php
/**
 * Member Portal — कल्याण दाबी (Welfare Claims)
 * Submit new claims + track all existing claims with timeline
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/welfare-claims-tables.php';
require_once __DIR__ . '/../includes/welfare-claims-submit-helper.php';
requireMemberLogin();
memberSecurityHeaders();

$db  = getDB();
$mem = currentMember();
if (!$mem) { header('Location: login.php?msg=session_expired'); exit; }
$_t = static function (string $np, string $en): string {
    return isEnglish() ? $en : $np;
};

$memberId = (int)$mem['id'];
$memEmail = trim((string)($mem['email'] ?? ''));
$memPhone = preg_replace('/[^0-9]/', '', (string)($mem['phone'] ?? ''));

/* KYC-linked profile priority */
$kycRow = null;
try {
    $kycLinkId = (int)($mem['kyc_application_id'] ?? 0);
    if ($kycLinkId > 0) {
        $ks = $db->prepare("SELECT * FROM kyc_applications WHERE id=? LIMIT 1");
        $ks->execute([$kycLinkId]);
        $kycRow = $ks->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$kycRow) {
        $kw = []; $kp = [];
        if ($memEmail) { $kw[] = 'LOWER(email)=?'; $kp[] = strtolower($memEmail); }
        if ($memPhone) { $kw[] = 'mobile=?'; $kp[] = $memPhone; }
        if (!empty($kw)) {
            $ks = $db->prepare("SELECT * FROM kyc_applications WHERE (" . implode(' OR ', $kw) . ") ORDER BY id DESC LIMIT 1");
            $ks->execute($kp);
            $kycRow = $ks->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
} catch (Throwable $e) { $kycRow = null; }

/* Resolved identity */
$memName    = trim((string)($kycRow['full_name'] ?? $mem['name']    ?? ''));
$memSadasyata = trim((string)($kycRow['member_id'] ?? $mem['sadasyata_number'] ?? ''));
$resolvedPhone = $memPhone ?: preg_replace('/[^0-9]/', '', (string)($kycRow['mobile'] ?? ''));
$resolvedEmail = $memEmail ?: strtolower(trim((string)($kycRow['email'] ?? '')));
$resolvedAddress = trim((string)($kycRow['temporary_address'] ?? $kycRow['permanent_address'] ?? ''));

ensureWelfareClaimsTables($db);

/* ── Handle POST: new claim ── */
$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_claim') {
    if (!verifyCSRFToken()) {
        header('Location: welfare.php?err=csrf');
        exit;
    } elseif (!checkRateLimit('welfare_portal_' . $memberId, 5, 3600)) {
        header('Location: welfare.php?err=ratelimit');
        exit;
    } else {
        $claimType  = trim($_POST['claim_type'] ?? '');
        $desc       = trim(substr($_POST['description'] ?? '', 0, 4000));
        $beneName   = trim(substr($_POST['beneficiary_name'] ?? '', 0, 120));
        $beneRel    = trim(substr($_POST['beneficiary_relation'] ?? '', 0, 80));
        $claimAmt   = max(0, (float)($_POST['claim_amount'] ?? 0));
        $dcName     = trim(substr($_POST['deceased_name'] ?? '', 0, 120));
        $dcRel      = trim(substr($_POST['deceased_relation'] ?? '', 0, 80));
        $deathDate  = trim($_POST['death_date'] ?? '') ?: null;
        $delivDate  = trim($_POST['delivery_date'] ?? '') ?: null;
        $hospName   = trim(substr($_POST['hospital_name'] ?? '', 0, 200));
        $disease    = trim(substr($_POST['disease_illness'] ?? '', 0, 500));
        $treatDate  = trim($_POST['treatment_date'] ?? '') ?: null;
        $hospClinic = trim(substr($_POST['hospital_clinic'] ?? '', 0, 200));
        $policyNo   = trim(substr($_POST['policy_number'] ?? '', 0, 80));
        $insurerNm  = trim(substr($_POST['insurer_name'] ?? '', 0, 150));

        $validTypes = ['maternity','death','insurance','medical','accident','other'];
        if (!in_array($claimType, $validTypes, true)) {
            header('Location: welfare.php?err=no_type');
            exit;
        } else {
            try {
                $submit = submitWelfareClaimUnified($db, [
                    'member_name' => $memName,
                    'member_id' => $memSadasyata,
                    'member_portal_id' => $memberId,
                    'phone' => $resolvedPhone,
                    'email' => $resolvedEmail,
                    'address' => $resolvedAddress,
                    'claim_type' => $claimType,
                    'beneficiary_name' => $beneName,
                    'beneficiary_relation' => $beneRel,
                    'claim_amount' => $claimAmt,
                    'description' => $desc,
                    'deceased_name' => $dcName,
                    'deceased_relation' => $dcRel,
                    'death_date' => $deathDate,
                    'delivery_date' => $delivDate,
                    'hospital_name' => $hospName,
                    'disease_illness' => $disease,
                    'treatment_date' => $treatDate,
                    'hospital_clinic' => $hospClinic,
                    'policy_number' => $policyNo ?: null,
                    'insurer_name' => $insurerNm ?: null,
                ], $_FILES);
                $trackingId = $submit['tracking_id'];
                /* PRG: redirect so browser refresh doesn't re-submit */
                header('Location: welfare.php?submitted=1&tid=' . urlencode($trackingId));
                exit;
            } catch (Throwable $e) {
                error_log('[welfare portal] ' . $e->getMessage());
                header('Location: welfare.php?err=submit_failed');
                exit;
            }
        }
    }
    /* Unhandled POST (wrong action etc.) — redirect to prevent re-submit */
    if (empty($successMsg) && empty($errorMsg)) {
        header('Location: welfare.php');
        exit;
    }
}

/* ── Fetch this member's existing claims ── */
$myClaims = [];
try {
    $conds = ['member_portal_id=?'];
    $params = [$memberId];
    if ($resolvedPhone) { $conds[] = 'phone=?'; $params[] = $resolvedPhone; }
    if ($resolvedEmail) { $conds[] = 'LOWER(email)=?'; $params[] = strtolower($resolvedEmail); }
    $st = $db->prepare("SELECT * FROM member_welfare_claims WHERE " . implode(' OR ', $conds) . " ORDER BY created_at DESC LIMIT 50");
    $st->execute($params);
    $myClaims = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $myClaims = []; }

$siteName  = getSetting('site_name', 'सहकारी');
$pageTitle = ($_t('कल्याण दाबी', 'Welfare Claims')) . ' — ' . $siteName;

/* ── Resolve PRG flash messages from GET params ── */
if (!empty($_GET['submitted']) && !empty($_GET['tid'])) {
    $tid = htmlspecialchars($_GET['tid']);
    $successMsg = isEnglish()
        ? "Claim submitted successfully! Tracking ID: <strong>$tid</strong> — You will be notified after admin review."
        : "दाबी सफलतापूर्वक दर्ता भयो! Tracking ID: <strong>$tid</strong> — Admin ले समीक्षा गरेपछि सूचित गरिनेछ।";
}
if (!empty($_GET['err'])) {
    $errKey = $_GET['err'];
    if ($errKey === 'csrf')         $errorMsg = $_t('सुरक्षा जाँच असफल। पुनः प्रयास गर्नुहोस्।', 'Security check failed. Please try again.');
    elseif ($errKey === 'ratelimit') $errorMsg = $_t('धेरै अनुरोधहरू भए। १ घण्टापछि पुनः प्रयास गर्नुहोस्।', 'Too many requests. Please try again after 1 hour.');
    elseif ($errKey === 'no_type')   $errorMsg = $_t('दाबी प्रकार छान्नुहोस्।', 'Please select claim type.');
    else                             $errorMsg = $_t('दाबी दर्ता गर्न समस्या भयो। पुनः प्रयास गर्नुहोस्।', 'Failed to submit claim. Please try again.');
}

$activeTab = empty($myClaims) ? 'new' : ((!empty($_GET['new']) || !empty($successMsg)) ? 'new' : 'history');
$csrfField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken()) . '">';

$statusLabels = [
    'pending'      => ['label' => $_t('पर्खाइमा','Pending'),   'color' => 'var(--secondary-dark,var(--secondary-color))', 'bg' => 'color-mix(in srgb, var(--secondary-color) 14%, white)', 'icon' => 'fa-clock'],
    'under_review' => ['label' => $_t('समीक्षामा','Under Review'),  'color' => 'var(--secondary-color)', 'bg' => 'color-mix(in srgb, var(--secondary-color) 12%, white)', 'icon' => 'fa-magnifying-glass'],
    'approved'     => ['label' => $_t('स्वीकृत','Approved'),    'color' => 'var(--primary-dark,var(--primary-color))', 'bg' => 'color-mix(in srgb, var(--primary-color) 14%, white)', 'icon' => 'fa-circle-check'],
    'rejected'     => ['label' => $_t('अस्वीकृत','Rejected'),   'color' => 'var(--secondary-dark,var(--secondary-color))', 'bg' => 'color-mix(in srgb, var(--secondary-color) 16%, white)', 'icon' => 'fa-circle-xmark'],
    'paid'         => ['label' => $_t('भुक्तानी भयो','Paid'),'color' => 'var(--secondary-dark,var(--secondary-color))','bg' => 'color-mix(in srgb, var(--secondary-color) 14%, white)', 'icon' => 'fa-money-bill'],
    'completed'    => ['label' => $_t('सम्पन्न','Completed'),     'color' => 'var(--primary-color)', 'bg' => 'color-mix(in srgb, var(--primary-light) 14%, white)', 'icon' => 'fa-flag-checkered'],
];
$extraHead = <<<HTML
<style>
.claim-card { background:white; border:1px solid color-mix(in srgb, var(--primary-color) 14%, var(--gray-200)); border-radius:12px; padding:16px; margin-bottom:14px; }
.claim-card:hover { box-shadow:0 4px 12px rgba(var(--primary-rgb),.12); }
.status-pill { display:inline-flex; align-items:center; gap:5px; padding:4px 12px; border-radius:20px; font-size:.78rem; font-weight:700; }
.wf-timeline { display:flex; gap:0; align-items:center; margin:14px 0 4px; flex-wrap:wrap; gap:4px; }
.wf-tstep { display:flex; flex-direction:column; align-items:center; gap:3px; flex:1; min-width:60px; }
.wf-tdot  { width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center;
            font-size:.7rem; border:2px solid color-mix(in srgb, var(--primary-color) 14%, var(--gray-200)); background:var(--gray-50); color:var(--text-muted); }
.wf-tdot.done   { background:var(--primary-color); border-color:var(--primary-color); color:var(--text-on-primary,white); }
.wf-tdot.active { background:var(--secondary-color); border-color:var(--secondary-color); color:var(--text-on-secondary,var(--text-on-primary,white)); }
.wf-tdot.reject { background:var(--secondary-color); border-color:var(--secondary-color); color:var(--text-on-secondary,var(--text-on-primary,white)); }
.wf-tline { flex:1; height:2px; background:color-mix(in srgb, var(--primary-color) 14%, var(--gray-200)); min-width:16px; }
.wf-tline.done { background:var(--primary-color); }
.wf-tlabel { font-size:.65rem; color:var(--text-muted); text-align:center; }
.wf-tlabel.done   { color:var(--primary-color); font-weight:600; }
.wf-tlabel.active { color:var(--secondary-color); font-weight:700; }
.form-group { margin-bottom:14px; }
.form-group label { display:block; font-size:.88rem; font-weight:600; color:var(--text-color); margin-bottom:5px; line-height:1.5; }
.form-control { width:100%; padding:10px 14px; min-height:44px; border:1.5px solid color-mix(in srgb, var(--primary-color) 20%, var(--gray-300)); border-radius:10px;
               font-family:inherit; font-size:.95rem; background:var(--gray-50); transition:border-color .2s; line-height:1.5; }
.form-control:focus { outline:none; border-color:var(--primary-color); background:white; box-shadow:0 0 0 3px rgba(var(--primary-rgb),.12); }
.form-row { display:grid; gap:12px; }
.form-row.cols2 { grid-template-columns:1fr 1fr; }
@media(max-width:540px){ .form-row.cols2 { grid-template-columns:1fr; } }
.type-fields { display:none; }
.type-fields.show { display:block; }
.alert-success { background:color-mix(in srgb, var(--primary-color) 12%, white); border:1px solid color-mix(in srgb, var(--primary-color) 24%, white); border-radius:10px; padding:14px 16px; color:var(--primary-dark,var(--primary-color)); font-size:.9rem; margin-bottom:16px; }
.alert-error   { background:color-mix(in srgb, var(--secondary-color) 12%, white); border:1px solid color-mix(in srgb, var(--secondary-color) 24%, white); border-radius:10px; padding:14px 16px; color:var(--secondary-dark,var(--secondary-color)); font-size:.9rem; margin-bottom:16px; }
.track-id { font-family:monospace; font-weight:700; font-size:.9rem; background:color-mix(in srgb, var(--primary-color) 10%, white); padding:2px 8px; border-radius:6px; }
.empty-state { text-align:center; padding:40px 20px; color:var(--text-muted); }
.empty-state i { font-size:3rem; display:block; margin-bottom:12px; }
.doc-upload { border:2px dashed color-mix(in srgb, var(--primary-color) 18%, var(--gray-200)); border-radius:10px; padding:16px; text-align:center; cursor:pointer; transition:border .2s; }
.doc-upload:hover { border-color:var(--primary-color); }
.wf-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; flex-wrap:wrap; gap:10px; }
.wf-title { font-size:1.45rem; font-weight:700; color:var(--primary-color); margin:0; line-height:1.4; }
.wf-title-icon, .wf-link-icon, .wf-tab-icon { margin-right:8px; }
.wf-link-row { display:flex; gap:8px; }
.wf-link { font-size:.8rem; color:var(--primary-color); text-decoration:none; }
.wf-empty-title { font-size:1rem; font-weight:600; color:var(--text-light); margin-bottom:6px; }
.wf-empty-sub { font-size:.85rem; }
.wf-claim-top { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; flex-wrap:wrap; }
.wf-claim-name { font-size:1rem; font-weight:700; color:var(--text-color); margin-bottom:4px; }
.wf-meta-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:10px; font-size:.82rem; color:var(--text-light); }
.wf-icon-gap { margin-right:4px; }
.wf-remarks { margin-top:10px; padding:9px 12px; background:color-mix(in srgb, var(--primary-color) 8%, white); border-radius:8px; font-size:.82rem; color:var(--text-color); }
.wf-note { margin-top:8px; font-size:.82rem; color:var(--text-light); }
.wf-info-box { background:color-mix(in srgb, var(--secondary-color) 12%, white); border:1px solid color-mix(in srgb, var(--secondary-color) 24%, white); border-radius:10px; padding:12px 14px; font-size:.83rem; color:var(--secondary-dark,var(--secondary-color)); margin-bottom:18px; display:flex; gap:8px; align-items:center; }
.wf-info-box .icon { flex-shrink:0; }
.wf-member-box { background:color-mix(in srgb, var(--primary-color) 8%, white); border:1px solid color-mix(in srgb, var(--primary-color) 18%, var(--gray-200)); border-radius:10px; padding:14px; margin-bottom:18px; }
.wf-member-title { font-size:.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; margin-bottom:10px; }
.wf-readonly { background:color-mix(in srgb, var(--primary-color) 8%, var(--gray-100)); color:var(--text-light); }
.wf-required { color:var(--secondary-color); }
.wf-file-list { margin-top:8px; font-size:.82rem; color:var(--primary-color); }
.wf-submit-btn { width:100%; padding:12px; background:var(--primary-color); color:var(--text-on-primary,white); border:none; border-radius:10px; font-family:inherit; font-size:1rem; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; }
.wf-hidden-file { display:none; }
.wf-ico-warn { color:var(--secondary-dark,var(--secondary-color)); }
.wf-ico-ok { color:var(--primary-color); }
.wf-ico-note { color:var(--text-light); }
.wf-section-box { border-radius:8px; padding:12px; margin-bottom:14px; border:1px solid transparent; }
.wf-section-box.death { background:color-mix(in srgb, var(--secondary-color) 10%, white); border-color:color-mix(in srgb, var(--secondary-color) 22%, white); }
.wf-section-box.maternity { background:color-mix(in srgb, var(--primary-color) 10%, white); border-color:color-mix(in srgb, var(--primary-color) 22%, white); }
.wf-section-box.medical { background:color-mix(in srgb, var(--secondary-color) 10%, white); border-color:color-mix(in srgb, var(--secondary-color) 22%, white); }
.wf-section-box.insurance { background:color-mix(in srgb, var(--secondary-color) 12%, white); border-color:color-mix(in srgb, var(--secondary-color) 24%, white); }
.wf-section-box.other { background:color-mix(in srgb, var(--primary-color) 8%, white); border-color:color-mix(in srgb, var(--primary-color) 18%, var(--gray-200)); }
.wf-section-head { font-size:.8rem; font-weight:700; margin-bottom:10px; }
.wf-section-head.death { color:var(--secondary-dark,var(--secondary-color)); }
.wf-section-head.maternity { color:var(--primary-dark,var(--primary-color)); }
.wf-section-head.medical { color:var(--secondary-color); }
.wf-section-head.insurance { color:var(--secondary-dark,var(--secondary-color)); }
.wf-section-head.other { color:var(--text-color); margin-bottom:6px; }
.wf-hint-text { font-size:.82rem; color:var(--text-light); }
.wf-mb-8 { margin-bottom:8px; }
.wf-mb-0 { margin-bottom:0; }
.wf-icon-gap-sm { margin-right:5px; }
.wf-upload-icon { font-size:1.8rem; color:var(--text-muted); display:block; margin-bottom:6px; }
.wf-upload-title { font-size:.85rem; color:var(--text-light); }
.wf-upload-sub { font-size:.75rem; color:var(--text-muted); margin-top:3px; }
</style>
HTML;
?>
<?php require __DIR__ . '/includes/chrome.php'; ?>

<main class="mp-main">
<div class="mp-container">

  <div class="wf-head">
    <h1 class="wf-title">
      <i class="fas fa-heart-pulse wf-title-icon"></i><?php echo $_t('कल्याण दाबी', 'Welfare Claims'); ?>
    </h1>
    <div class="wf-link-row">
      <a href="tracker.php?filter=welfare" class="wf-link">
        <i class="fas fa-magnifying-glass-chart wf-link-icon"></i> <?php echo $_t('Tracker मा हेर्नुहोस्', 'Open in Tracker'); ?>
      </a>
    </div>
  </div>

  <?php if ($successMsg): ?>
  <div class="alert-success"><i class="fas fa-circle-check wf-title-icon"></i><?= $successMsg ?></div>
  <?php endif; ?>
  <?php if ($errorMsg): ?>
  <div class="alert-error"><i class="fas fa-circle-xmark wf-title-icon"></i><?= htmlspecialchars($errorMsg) ?></div>
  <?php endif; ?>

  <!-- Tabs -->
  <div class="wf-tabs">
    <button class="wf-tab <?= $activeTab==='history'?'active':'' ?>" onclick="showTab(this,'history')">
      <i class="fas fa-list wf-icon-gap-sm"></i><?php echo $_t('मेरा दाबीहरू', 'My Claims'); ?> (<?= count($myClaims) ?>)
    </button>
    <button class="wf-tab <?= $activeTab==='new'?'active':'' ?>" onclick="showTab(this,'new')" id="tabNew">
      <i class="fas fa-plus-circle wf-icon-gap-sm"></i><?php echo $_t('नयाँ दाबी', 'New Claim'); ?>
    </button>
  </div>

  <!-- Tab: History -->
  <div class="wf-pane <?= $activeTab==='history'?'active':'' ?>" id="pane-history">
    <?php if (empty($myClaims)): ?>
    <div class="empty-state">
      <i class="fas fa-heart"></i>
      <div class="wf-empty-title"><?php echo $_t('कुनै दाबी छैन', 'No claims found'); ?></div>
      <div class="wf-empty-sub"><?php echo $_t('नयाँ कल्याण दाबी दर्ता गर्न "नयाँ दाबी" tab खोल्नुहोस्।', 'Open "New Claim" tab to submit a welfare claim.'); ?></div>
    </div>
    <?php else: ?>
    <?php foreach ($myClaims as $cl):
        $st = $cl['status'] ?? 'pending';
        $info = $statusLabels[$st] ?? $statusLabels['pending'];
        $tSteps = [
            ['key'=>'pending',       'label'=>$_t('दर्ता','Submitted')],
            ['key'=>'under_review',  'label'=>$_t('समीक्षा','Review')],
            ['key'=>'approved',      'label'=>$_t('स्वीकृत','Approved')],
            ['key'=>'completed',     'label'=>$_t('सम्पन्न','Completed')],
        ];
        $tOrder = ['pending'=>0,'under_review'=>1,'approved'=>2,'paid'=>3,'completed'=>4,'rejected'=>1];
        $curIdx = $tOrder[$st] ?? 0;
        $isRej  = ($st === 'rejected');
    ?>
    <div class="claim-card">
      <div class="wf-claim-top">
        <div>
          <div class="wf-claim-name">
            <?= htmlspecialchars($cl['claim_type_np'] ?: $cl['claim_type']) ?>
          </div>
          <div class="track-id"><?= htmlspecialchars($cl['tracking_id'] ?? 'N/A') ?></div>
        </div>
        <span class="status-pill" style="background:<?= $info['bg'] ?>;color:<?= $info['color'] ?>;">
          <i class="fas <?= $info['icon'] ?>"></i> <?= $info['label'] ?>
        </span>
      </div>

      <!-- Timeline -->
      <div class="wf-timeline">
        <?php foreach ($tSteps as $ti => $ts):
            $tdone   = !$isRej && $curIdx > $ti;
            $tactive = !$isRej && $curIdx === $ti;
            $treject = $isRej && $ti === 1;
        ?>
        <?php if ($ti > 0): ?>
        <div class="wf-tline <?= $tdone?'done':'' ?>"></div>
        <?php endif; ?>
        <div class="wf-tstep">
          <div class="wf-tdot <?= $treject?'reject':($tdone?'done':($tactive?'active':'')) ?>">
            <?php if ($treject): ?><i class="fas fa-xmark"></i>
            <?php elseif($tdone): ?><i class="fas fa-check"></i>
            <?php elseif($tactive): ?><i class="fas fa-circle-dot"></i>
            <?php else: ?><?= $ti+1 ?><?php endif; ?>
          </div>
          <div class="wf-tlabel <?= $treject?'reject':($tdone?'done':($tactive?'active':'')) ?>"><?= $ts['label'] ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="wf-meta-grid">
        <?php if ($cl['claim_amount'] > 0): ?>
        <div><i class="fas fa-coins wf-icon-gap wf-ico-warn"></i><?php echo $_t('माग रकम', 'Requested Amount'); ?>: रू <?= number_format((float)$cl['claim_amount'],2) ?></div>
        <?php endif; ?>
        <?php if ($cl['approved_amount'] > 0): ?>
        <div><i class="fas fa-check-circle wf-icon-gap wf-ico-ok"></i><?php echo $_t('स्वीकृत रकम', 'Approved Amount'); ?>: रू <?= number_format((float)$cl['approved_amount'],2) ?></div>
        <?php endif; ?>
        <div><i class="fas fa-calendar wf-icon-gap"></i><?= date('Y-m-d', strtotime($cl['created_at'])) ?></div>
        <?php if ($cl['beneficiary_name']): ?>
        <div><i class="fas fa-user wf-icon-gap"></i><?= htmlspecialchars($cl['beneficiary_name']) ?></div>
        <?php endif; ?>
      </div>
      <?php if ($cl['admin_remarks']): ?>
      <div class="wf-remarks">
        <strong><i class="fas fa-comment wf-icon-gap-sm wf-ico-note"></i><?php echo $_t('Admin टिप्पणी', 'Admin Remark'); ?>:</strong>
        <?= htmlspecialchars($cl['admin_remarks']) ?>
      </div>
      <?php endif; ?>
      <?php if ($cl['description']): ?>
      <div class="wf-note"><?= nl2br(htmlspecialchars(mb_substr($cl['description'],0,200))) ?><?= mb_strlen($cl['description'])>200?'…':'' ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Tab: New Claim Form -->
  <div class="wf-pane <?= $activeTab==='new'?'active':'' ?>" id="pane-new">
    <div class="mem-autofill-banner">
      <i class="fas fa-wand-magic-sparkles"></i>
      <div><?php echo $_t('तपाईंको नाम, फोन र ठेगाना <strong>KYC बाट auto-fill</strong> भएको छ — तल देखिन्छ। केवल दाबीको विवरण भर्नुहोस्।', 'Your name, phone and address are <strong>auto-filled from KYC</strong> — shown below. Fill only claim details.'); ?></div>
    </div>

    <form method="POST" enctype="multipart/form-data">
      <?= $csrfField ?>
      <input type="hidden" name="action" value="submit_claim">

      <!-- Pre-filled member info — display only, no input fields -->
      <div class="mem-prefill-block">
        <div class="mem-prefill-block-head"><i class="fas fa-user-check"></i><?php echo $_t('सदस्य जानकारी (KYC बाट)', 'Member Info (from KYC)'); ?></div>
        <div class="mem-prefill-grid">
          <div class="mem-prefill-item">
            <span class="mem-prefill-label"><?php echo $_t('नाम', 'Name'); ?></span>
            <span class="mem-prefill-value"><?= htmlspecialchars($memName ?: '—') ?></span>
          </div>
          <div class="mem-prefill-item">
            <span class="mem-prefill-label"><?php echo $_t('सदस्यता नम्बर', 'Member No.'); ?></span>
            <span class="mem-prefill-value mem-tracking-id"><?= htmlspecialchars($memSadasyata ?: '—') ?></span>
          </div>
          <div class="mem-prefill-item">
            <span class="mem-prefill-label"><?php echo $_t('फोन', 'Phone'); ?></span>
            <span class="mem-prefill-value"><?= htmlspecialchars($resolvedPhone ?: '—') ?></span>
          </div>
          <div class="mem-prefill-item">
            <span class="mem-prefill-label">Email</span>
            <span class="mem-prefill-value"><?= htmlspecialchars($resolvedEmail ?: '—') ?></span>
          </div>
          <?php if ($resolvedAddress): ?>
          <div class="mem-prefill-item">
            <span class="mem-prefill-label"><?php echo $_t('ठेगाना', 'Address'); ?></span>
            <span class="mem-prefill-value"><?= htmlspecialchars($resolvedAddress) ?></span>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Claim Type -->
      <div class="form-group">
        <label><?php echo $_t('दाबी प्रकार', 'Claim Type'); ?> <span class="wf-required">*</span></label>
        <select name="claim_type" class="form-control" required onchange="showTypeFields(this.value)">
          <option value=""><?php echo $_t('— प्रकार छान्नुहोस् —', '— Select claim type —'); ?></option>
          <option value="death"><?php echo $_t('⚫ मृत्यु सुविधा', '⚫ Death benefit'); ?></option>
          <option value="maternity"><?php echo $_t('🟢 सुत्केरी सुविधा', '🟢 Maternity benefit'); ?></option>
          <option value="medical"><?php echo $_t('🔵 उपचार खर्च', '🔵 Medical expense'); ?></option>
          <option value="accident"><?php echo $_t('🟠 दुर्घटना सुविधा', '🟠 Accident benefit'); ?></option>
          <option value="insurance"><?php echo $_t('🟣 बीमा दाबी', '🟣 Insurance claim'); ?></option>
          <option value="other"><?php echo $_t('⚪ अन्य सुविधा', '⚪ Other benefit'); ?></option>
        </select>
      </div>

      <!-- Death-specific fields -->
      <div class="type-fields" id="tf-death">
        <div class="wf-section-box death">
          <div class="wf-section-head death"><i class="fas fa-cross wf-icon-gap-sm"></i>मृत्यु विवरण</div>
          <div class="form-row cols2">
            <div class="form-group wf-mb-0"><label>मृत्यु हुने व्यक्तिको नाम</label><input name="deceased_name" type="text" class="form-control" placeholder="पूरा नाम"></div>
            <div class="form-group wf-mb-0"><label>नाता</label><input name="deceased_relation" type="text" class="form-control" placeholder="जस्तै: आफ्नो, श्रीमती"></div>
            <div class="form-group wf-mb-0"><label><?php echo $_t('मृत्यु मिति', 'Date of Death'); ?></label><input name="death_date" type="date" class="form-control" data-calendar="ad"></div>
          </div>
        </div>
      </div>

      <!-- Maternity-specific fields -->
      <div class="type-fields" id="tf-maternity">
        <div class="wf-section-box maternity">
          <div class="wf-section-head maternity"><i class="fas fa-baby wf-icon-gap-sm"></i>सुत्केरी विवरण</div>
          <div class="form-row cols2">
            <div class="form-group wf-mb-0"><label><?php echo $_t('सुत्केरी मिति', 'Delivery Date'); ?></label><input name="delivery_date" type="date" class="form-control" data-calendar="ad"></div>
            <div class="form-group wf-mb-0"><label>अस्पताल / क्लिनिकको नाम</label><input name="hospital_name" type="text" class="form-control" placeholder="अस्पतालको नाम"></div>
          </div>
        </div>
      </div>

      <!-- Medical/Accident-specific fields -->
      <div class="type-fields" id="tf-medical">
        <div class="wf-section-box medical">
          <div class="wf-section-head medical"><i class="fas fa-stethoscope wf-icon-gap-sm"></i>उपचार विवरण</div>
          <div class="form-row cols2">
            <div class="form-group wf-mb-0"><label>रोग / चोट विवरण</label><input name="disease_illness" type="text" class="form-control" placeholder="संक्षिप्त विवरण"></div>
            <div class="form-group wf-mb-0"><label><?php echo $_t('उपचार मिति', 'Treatment Date'); ?></label><input name="treatment_date" type="date" class="form-control" data-calendar="ad"></div>
            <div class="form-group wf-mb-0"><label>अस्पताल / क्लिनिक</label><input name="hospital_clinic" type="text" class="form-control" placeholder="अस्पतालको नाम"></div>
          </div>
        </div>
      </div>

      <!-- Insurance-specific fields -->
      <div class="type-fields" id="tf-insurance">
        <div class="wf-section-box insurance">
          <div class="wf-section-head insurance"><i class="fas fa-shield-halved wf-icon-gap-sm"></i>बीमा विवरण</div>
          <div class="form-row cols2">
            <div class="form-group wf-mb-0">
              <label>बीमा पोलिसी नम्बर</label>
              <input name="policy_number" type="text" class="form-control" placeholder="जस्तै: NL-2023-XXXXXX">
            </div>
            <div class="form-group wf-mb-0">
              <label>बीमा कम्पनीको नाम</label>
              <input name="insurer_name" type="text" class="form-control" placeholder="बीमा कम्पनी">
            </div>
          </div>
        </div>
      </div>

      <!-- Other-specific fields -->
      <div class="type-fields" id="tf-other">
        <div class="wf-section-box other">
          <div class="wf-section-head other"><i class="fas fa-circle-info wf-icon-gap-sm"></i>अन्य सुविधा दाबी</div>
          <div class="wf-hint-text">तलको <strong>विस्तृत विवरण</strong> section मा आफ्नो दाबीको पूरा जानकारी लेख्नुहोस् — कुन सुविधा माग गरिरहनुभएको छ, किन चाहिएको छ, आदि।</div>
        </div>
      </div>

      <!-- Common fields -->
      <div class="form-row cols2">
        <div class="form-group">
          <label>लाभग्राही नाम (Beneficiary)</label>
          <input name="beneficiary_name" type="text" class="form-control" placeholder="भुक्तानी पाउने व्यक्ति">
        </div>
        <div class="form-group">
          <label>लाभग्राहीसँग नाता</label>
          <input name="beneficiary_relation" type="text" class="form-control" placeholder="जस्तै: आमा, श्रीमान्">
        </div>
      </div>

      <div class="form-group">
        <label>माग रकम (रूपैयाँमा)</label>
        <input name="claim_amount" type="number" class="form-control" min="0" step="0.01" placeholder="0.00">
      </div>

      <div class="form-group">
        <label><?php echo $_t('विस्तृत विवरण', 'Detailed Description'); ?> <span class="wf-required">*</span></label>
        <textarea name="description" class="form-control" rows="4" required placeholder="दाबीको पूरा विवरण लेख्नुहोस्..."></textarea>
      </div>

      <!-- Document upload -->
      <div class="form-group">
        <label><i class="fas fa-paperclip wf-icon-gap-sm"></i>सम्बन्धित कागजपत्र (Optional)</label>
        <label class="doc-upload" for="docUpload">
          <i class="fas fa-cloud-upload-alt wf-upload-icon"></i>
          <div class="wf-upload-title">Click गरी files छान्नुहोस् वा यहाँ drag गर्नुहोस्</div>
          <div class="wf-upload-sub">PDF, JPG, PNG — अधिकतम 10MB प्रति file</div>
          <input type="file" id="docUpload" name="documents[]" multiple accept=".pdf,.jpg,.jpeg,.png" class="wf-hidden-file" onchange="showFiles(this)">
        </label>
        <div id="fileList" class="wf-file-list"></div>
      </div>

      <button type="submit" class="wf-submit-btn">
        <i class="fas fa-paper-plane"></i> <?php echo $_t('दाबी दर्ता गर्नुहोस्', 'Submit Claim'); ?>
      </button>
    </form>
  </div>

</div>
</main>

<script>
function showTab(btn, tab) {
    document.querySelectorAll('.wf-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.wf-tab').forEach(t => t.classList.remove('active'));
    document.getElementById('pane-' + tab).classList.add('active');
    /* btn could be the <i> icon child — walk up to the button */
    var el = btn;
    while (el && el.tagName !== 'BUTTON') el = el.parentElement;
    if (el) el.classList.add('active');
}
function showTypeFields(type) {
    setTimeout(function() {
        document.querySelectorAll('.type-fields').forEach(f => f.classList.remove('show'));
        var map = {
            'death'     : 'tf-death',
            'maternity' : 'tf-maternity',
            'medical'   : 'tf-medical',
            'accident'  : 'tf-medical',
            'insurance' : 'tf-insurance',
            'other'     : 'tf-other'
        };
        if (map[type]) document.getElementById(map[type]).classList.add('show');
    }, 0);
}
function showFiles(input) {
    var list = document.getElementById('fileList');
    list.innerHTML = '';
    Array.from(input.files).forEach(function(f){
        list.innerHTML += '<div><i class="fas fa-file wf-icon-gap-sm"></i>'+f.name+'</div>';
    });
}
</script>
<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
