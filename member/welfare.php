<?php
/**
 * Member Portal — कल्याण दाबी (Welfare Claims)
 * Submit new claims + track all existing claims with timeline
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/welfare-claims-tables.php';
require_once __DIR__ . '/../includes/welfare-claim-types.php';
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
$memName  = trim((string)($mem['name'] ?? ''));
require __DIR__ . '/../includes/member-portal-identity.php';

$rPhone = $memPhone ?: preg_replace('/[^0-9]/', '', (string)($kycRow['mobile'] ?? $kycRow['phone'] ?? ''));
$rEmail = $memEmail ?: strtolower(trim((string)($kycRow['email'] ?? '')));
$resolvedPhone = $rPhone;
$resolvedEmail = $rEmail;
$resolvedAddress = trim((string)($kycRow['temporary_address'] ?? $kycRow['permanent_address'] ?? ''));

ensureWelfareClaimsTables($db);
$claimTypes = welfareClaimTypesMap($db);

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
        $desc       = trim(mb_substr((string)($_POST['description'] ?? ''), 0, 4000, 'UTF-8'));
        $beneName   = trim(mb_substr((string)($_POST['beneficiary_name'] ?? ''), 0, 120, 'UTF-8'));
        $beneRel    = trim(mb_substr((string)($_POST['beneficiary_relation'] ?? ''), 0, 80, 'UTF-8'));
        $claimAmt   = max(0, (float)($_POST['claim_amount'] ?? 0));
        $dcName     = trim(mb_substr((string)($_POST['deceased_name'] ?? ''), 0, 120, 'UTF-8'));
        $dcRel      = trim(mb_substr((string)($_POST['deceased_relation'] ?? ''), 0, 80, 'UTF-8'));
        $deathDate  = trim($_POST['death_date'] ?? '') ?: null;
        $delivDate  = trim($_POST['delivery_date'] ?? '') ?: null;
        $hospName   = trim(mb_substr((string)($_POST['hospital_name'] ?? ''), 0, 200, 'UTF-8'));
        $disease    = trim(mb_substr((string)($_POST['disease_illness'] ?? ''), 0, 500, 'UTF-8'));
        $treatDate  = trim($_POST['treatment_date'] ?? '') ?: null;
        $hospClinic = trim(mb_substr((string)($_POST['hospital_clinic'] ?? ''), 0, 200, 'UTF-8'));
        $policyNo   = trim(mb_substr((string)($_POST['policy_number'] ?? ''), 0, 80, 'UTF-8'));
        $insurerNm  = trim(mb_substr((string)($_POST['insurer_name'] ?? ''), 0, 150, 'UTF-8'));

        if (!array_key_exists($claimType, $claimTypes)) {
            header('Location: welfare.php?err=no_type');
            exit;
        } elseif (!empty($claimTypes[$claimType]['requires_document']) && !welfareHasSupportingDocuments($_FILES)) {
            header('Location: welfare.php?err=doc_required');
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
                if ($e->getMessage() === 'DOC_REQUIRED') {
                    header('Location: welfare.php?err=doc_required');
                } elseif ($e->getMessage() === 'UPLOAD_FAILED') {
                    header('Location: welfare.php?err=upload_failed');
                } else {
                    header('Location: welfare.php?err=submit_failed');
                }
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
    elseif ($errKey === 'doc_required') $errorMsg = $_t('यो दाबी प्रकारको लागि सहयोगी कागजात अनिवार्य छ।', 'Please attach supporting documents for this claim type.');
    elseif ($errKey === 'upload_failed') $errorMsg = $_t('कागजात अपलोड असफल भयो। फाइल प्रकार/साइज जाँचेर पुनः प्रयास गर्नुहोस्।', 'Document upload failed. Check file type/size and try again.');
    else                             $errorMsg = $_t('दाबी दर्ता गर्न समस्या भयो। पुनः प्रयास गर्नुहोस्।', 'Failed to submit claim. Please try again.');
}

$activeTab = empty($myClaims) ? 'new' : ((!empty($_GET['new']) || !empty($successMsg)) ? 'new' : 'history');
$csrfField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken()) . '">';

$statusLabels = [
    'pending'      => ['label' => $_t('पर्खाइमा','Pending'),   'color' => 'var(--secondary-dark,var(--secondary-color))', 'bg' => 'color-mix(in srgb, var(--secondary-color) 14%, white)', 'icon' => 'clock'],
    'under_review' => ['label' => $_t('समीक्षामा','Under Review'),  'color' => 'var(--secondary-color)', 'bg' => 'color-mix(in srgb, var(--secondary-color) 12%, white)', 'icon' => 'search'],
    'approved'     => ['label' => $_t('स्वीकृत','Approved'),    'color' => 'var(--primary-dark,var(--primary-color))', 'bg' => 'color-mix(in srgb, var(--primary-color) 14%, white)', 'icon' => 'circle-check'],
    'rejected'     => ['label' => $_t('अस्वीकृत','Rejected'),   'color' => 'var(--secondary-dark,var(--secondary-color))', 'bg' => 'color-mix(in srgb, var(--secondary-color) 16%, white)', 'icon' => 'circle-x'],
    'paid'         => ['label' => $_t('भुक्तानी भयो','Paid'),'color' => 'var(--secondary-dark,var(--secondary-color))','bg' => 'color-mix(in srgb, var(--secondary-color) 14%, white)', 'icon' => 'banknote'],
    'completed'    => ['label' => $_t('सम्पन्न','Completed'),     'color' => 'var(--primary-color)', 'bg' => 'color-mix(in srgb, var(--primary-light) 14%, white)', 'icon' => 'flag'],
];
$extraHead = (isset($extraHead) ? (string) $extraHead : '')
    . (function_exists('coopThemeLinkHtml')
        ? coopThemeLinkHtml('assets/css/member-welfare-page.css')
        : '');
require __DIR__ . '/includes/chrome.php';
?>

<div class="mp-main">
<div class="mp-container">

  <div class="wf-head">
    <h1 class="wf-title">
      <i class="lucide-icon wf-title-icon" data-lucide="heart-pulse" aria-hidden="true"></i><?php echo $_t('कल्याण दाबी', 'Welfare Claims'); ?>
    </h1>
    <div class="wf-link-row">
      <a href="tracker.php?filter=welfare" class="wf-link">
        <i class="lucide-icon wf-link-icon" data-lucide="chart-no-axes-combined" aria-hidden="true"></i> <?php echo $_t('Tracker मा हेर्नुहोस्', 'Open in Tracker'); ?>
      </a>
    </div>
  </div>

  <?php if ($successMsg): ?>
  <div class="alert-success"><i class="lucide-icon wf-title-icon" data-lucide="circle-check" aria-hidden="true"></i><?= $successMsg ?></div>
  <?php endif; ?>
  <?php if ($errorMsg): ?>
  <div class="alert-error"><i class="lucide-icon wf-title-icon" data-lucide="circle-x" aria-hidden="true"></i><?= htmlspecialchars($errorMsg) ?></div>
  <?php endif; ?>

  <!-- Tabs -->
  <div class="wf-tabs">
    <button type="button" class="wf-tab <?= $activeTab==='history'?'active':'' ?>" onclick="showTab(this,'history')">
      <i class="lucide-icon wf-icon-gap-sm" data-lucide="list" aria-hidden="true"></i><?php echo $_t('मेरा दाबीहरू', 'My Claims'); ?> (<?= count($myClaims) ?>)
    </button>
    <button type="button" class="wf-tab <?= $activeTab==='new'?'active':'' ?>" onclick="showTab(this,'new')" id="tabNew">
      <i class="lucide-icon wf-icon-gap-sm" data-lucide="circle-plus" aria-hidden="true"></i><?php echo $_t('नयाँ दाबी', 'New Claim'); ?>
    </button>
  </div>

  <!-- Tab: History -->
  <div class="wf-pane <?= $activeTab==='history'?'active':'' ?>" id="pane-history">
    <?php if (empty($myClaims)): ?>
    <div class="empty-state">
      <i class="lucide-icon" data-lucide="heart" aria-hidden="true"></i>
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
          <i class="lucide-icon" data-lucide="<?= htmlspecialchars($info['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i> <?= $info['label'] ?>
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
            <?php if ($treject): ?><i class="lucide-icon" data-lucide="x" aria-hidden="true"></i>
            <?php elseif($tdone): ?><i class="lucide-icon" data-lucide="check" aria-hidden="true"></i>
            <?php elseif($tactive): ?><i class="lucide-icon" data-lucide="circle-dot" aria-hidden="true"></i>
            <?php else: ?><?= $ti+1 ?><?php endif; ?>
          </div>
          <div class="wf-tlabel <?= $treject?'reject':($tdone?'done':($tactive?'active':'')) ?>"><?= $ts['label'] ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="wf-meta-grid">
        <?php if ($cl['claim_amount'] > 0): ?>
        <div><i class="lucide-icon wf-icon-gap wf-ico-warn" data-lucide="coins" aria-hidden="true"></i><?php echo $_t('माग रकम', 'Requested Amount'); ?>: रू <?= number_format((float)$cl['claim_amount'],2) ?></div>
        <?php endif; ?>
        <?php if ($cl['approved_amount'] > 0): ?>
        <div><i class="lucide-icon wf-icon-gap wf-ico-ok" data-lucide="circle-check" aria-hidden="true"></i><?php echo $_t('स्वीकृत रकम', 'Approved Amount'); ?>: रू <?= number_format((float)$cl['approved_amount'],2) ?></div>
        <?php endif; ?>
        <div><i class="lucide-icon wf-icon-gap" data-lucide="calendar" aria-hidden="true"></i><?= date('Y-m-d', strtotime($cl['created_at'])) ?></div>
        <?php if ($cl['beneficiary_name']): ?>
        <div><i class="lucide-icon wf-icon-gap" data-lucide="user" aria-hidden="true"></i><?= htmlspecialchars($cl['beneficiary_name']) ?></div>
        <?php endif; ?>
      </div>
      <?php if ($cl['admin_remarks']): ?>
      <div class="wf-remarks">
        <strong><i class="lucide-icon wf-icon-gap-sm wf-ico-note" data-lucide="message-circle" aria-hidden="true"></i><?php echo $_t('Admin टिप्पणी', 'Admin Remark'); ?>:</strong>
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
      <i class="lucide-icon" data-lucide="sparkles" aria-hidden="true"></i>
      <div><?php echo $_t('तपाईंको नाम, फोन र ठेगाना <strong>KYM बाट auto-fill</strong> भएको छ — तल देखिन्छ। केवल दाबीको विवरण भर्नुहोस्।', 'Your name, phone and address are <strong>auto-filled from KYM</strong> — shown below. Fill only claim details.'); ?></div>
    </div>

    <form method="POST" enctype="multipart/form-data" class="coop-form-sticky">
      <?= $csrfField ?>
      <input type="hidden" name="action" value="submit_claim">

      <!-- Pre-filled member info — display only, no input fields -->
      <div class="mem-prefill-block">
        <div class="mem-prefill-block-head"><i class="lucide-icon" data-lucide="user-check" aria-hidden="true"></i><?php echo $_t('सदस्य जानकारी (KYM बाट)', 'Member Info (from KYM)'); ?></div>
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
        <label for="wlfClaimType"><?php echo $_t('दाबी प्रकार', 'Claim Type'); ?> <span class="wf-required">*</span></label>
        <select name="claim_type" id="wlfClaimType" class="form-control" required onchange="showTypeFields(this.value)">
          <option value=""><?php echo $_t('— प्रकार छान्नुहोस् —', '— Select claim type —'); ?></option>
          <?php foreach ($claimTypes as $ck => $ct): ?>
          <option value="<?php echo htmlspecialchars($ck); ?>"
                  data-doc="<?php echo !empty($ct['requires_document']) ? '1' : '0'; ?>">
            <?php echo htmlspecialchars(isEnglish() ? ($ct['en'] ?: $ct['np']) : ($ct['np'] ?: $ct['en'])); ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Death-specific fields -->
      <div class="type-fields" id="tf-death">
        <div class="wf-section-box death">
          <div class="wf-section-head death"><i class="lucide-icon wf-icon-gap-sm" data-lucide="cross" aria-hidden="true"></i>मृत्यु विवरण</div>
          <div class="form-row cols2">
            <div class="form-group wf-mb-0"><label>मृत्यु हुने व्यक्तिको नाम</label><input name="deceased_name" type="text" class="form-control" placeholder="पूरा नाम"></div>
            <div class="form-group wf-mb-0"><label>नाता</label><input name="deceased_relation" type="text" class="form-control" placeholder="जस्तै: आफ्नो, श्रीमती"></div>
            <div class="form-group wf-mb-0"><label><?php echo $_t('मृत्यु मिति', 'Date of Death'); ?><?php echo function_exists('coop_date_label_calendar') ? coop_date_label_calendar() : ''; ?></label><?php echo function_exists('coop_date_input_html') ? coop_date_input_html(['name'=>'death_date','id'=>'mwf_death_date','class'=>'form-control','value'=>(string)($_POST['death_date'] ?? ''),'hint'=>false]) : '<input name="death_date" type="date" class="form-control">'; ?></div>
          </div>
        </div>
      </div>

      <!-- Maternity-specific fields -->
      <div class="type-fields" id="tf-maternity">
        <div class="wf-section-box maternity">
          <div class="wf-section-head maternity"><i class="lucide-icon wf-icon-gap-sm" data-lucide="baby" aria-hidden="true"></i>सुत्केरी विवरण</div>
          <div class="form-row cols2">
            <div class="form-group wf-mb-0"><label><?php echo $_t('सुत्केरी मिति', 'Delivery Date'); ?><?php echo function_exists('coop_date_label_calendar') ? coop_date_label_calendar() : ''; ?></label><?php echo function_exists('coop_date_input_html') ? coop_date_input_html(['name'=>'delivery_date','id'=>'mwf_delivery_date','class'=>'form-control','value'=>(string)($_POST['delivery_date'] ?? ''),'hint'=>false]) : '<input name="delivery_date" type="date" class="form-control">'; ?></div>
            <div class="form-group wf-mb-0"><label>अस्पताल / क्लिनिकको नाम</label><input name="hospital_name" type="text" class="form-control" placeholder="अस्पतालको नाम"></div>
          </div>
        </div>
      </div>

      <!-- Medical/Accident-specific fields -->
      <div class="type-fields" id="tf-medical">
        <div class="wf-section-box medical">
          <div class="wf-section-head medical"><i class="lucide-icon wf-icon-gap-sm" data-lucide="stethoscope" aria-hidden="true"></i>उपचार विवरण</div>
          <div class="form-row cols2">
            <div class="form-group wf-mb-0"><label>रोग / चोट विवरण</label><input name="disease_illness" type="text" class="form-control" placeholder="संक्षिप्त विवरण"></div>
            <div class="form-group wf-mb-0"><label><?php echo $_t('उपचार मिति', 'Treatment Date'); ?><?php echo function_exists('coop_date_label_calendar') ? coop_date_label_calendar() : ''; ?></label><?php echo function_exists('coop_date_input_html') ? coop_date_input_html(['name'=>'treatment_date','id'=>'mwf_treatment_date','class'=>'form-control','value'=>(string)($_POST['treatment_date'] ?? ''),'hint'=>true]) : '<input name="treatment_date" type="date" class="form-control">'; ?></div>
            <div class="form-group wf-mb-0"><label>अस्पताल / क्लिनिक</label><input name="hospital_clinic" type="text" class="form-control" placeholder="अस्पतालको नाम"></div>
          </div>
        </div>
      </div>

      <!-- Insurance-specific fields -->
      <div class="type-fields" id="tf-insurance">
        <div class="wf-section-box insurance">
          <div class="wf-section-head insurance"><i class="lucide-icon wf-icon-gap-sm" data-lucide="shield" aria-hidden="true"></i>बीमा विवरण</div>
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
          <div class="wf-section-head other"><i class="lucide-icon wf-icon-gap-sm" data-lucide="info" aria-hidden="true"></i>अन्य सुविधा दाबी</div>
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
        <label id="wlfDocLabel">
          <i class="lucide-icon wf-icon-gap-sm" data-lucide="paperclip" aria-hidden="true"></i><?php echo $_t('सम्बन्धित कागजपत्र', 'Supporting documents'); ?>
          <small class="text-muted" id="wlfDocHint">(<?php echo $_t('ऐच्छिक', 'Optional'); ?>)</small>
          <span class="wf-required" id="wlfDocReq" style="display:none;">*</span>
        </label>
        <label class="doc-upload" for="docUpload">
          <i class="lucide-icon wf-upload-icon" data-lucide="cloud-upload" aria-hidden="true"></i>
          <div class="wf-upload-title">Click गरी files छान्नुहोस् वा यहाँ drag गर्नुहोस्</div>
          <div class="wf-upload-sub">PDF, JPG, PNG — अधिकतम 10MB प्रति file</div>
          <input type="file" id="docUpload" name="documents[]" multiple accept=".pdf,.jpg,.jpeg,.png" class="wf-hidden-file" onchange="showFiles(this)">
        </label>
        <div id="fileList" class="wf-file-list"></div>
      </div>

      <button type="submit" class="wf-submit-btn">
        <i class="lucide-icon" data-lucide="send" aria-hidden="true"></i> <?php echo $_t('दाबी दर्ता गर्नुहोस्', 'Submit Claim'); ?>
      </button>
    </form>
  </div>

</div>
</div>

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
var claimTypeProfiles = <?php
$__profiles = [];
foreach ($claimTypes as $__k => $__t) {
    $__profiles[$__k] = $__t['profile'] ?? 'other';
}
echo json_encode($__profiles, JSON_UNESCAPED_UNICODE);
?>;
function updateDocRequired(type) {
    var sel = document.getElementById('wlfClaimType');
    var opt = sel && sel.options[sel.selectedIndex];
    var needDoc = opt && opt.getAttribute('data-doc') === '1';
    var hint = document.getElementById('wlfDocHint');
    var req = document.getElementById('wlfDocReq');
    /* Do NOT set HTML required on #docUpload — it is display:none and blocks submit */
    if (hint) hint.style.display = needDoc ? 'none' : '';
    if (req) req.style.display = needDoc ? '' : 'none';
}
function showTypeFields(type) {
    setTimeout(function() {
        document.querySelectorAll('.type-fields').forEach(f => f.classList.remove('show'));
        var profileMap = {
            'death'     : 'tf-death',
            'maternity' : 'tf-maternity',
            'medical'   : 'tf-medical',
            'accident'  : 'tf-medical',
            'insurance' : 'tf-insurance',
            'other'     : 'tf-other'
        };
        var profile = (claimTypeProfiles && claimTypeProfiles[type]) ? claimTypeProfiles[type] : type;
        var id = profileMap[profile] || null;
        if (id) {
            var el = document.getElementById(id);
            if (el) el.classList.add('show');
        }
        updateDocRequired(type);
    }, 0);
}
function showFiles(input) {
    var list = document.getElementById('fileList');
    list.innerHTML = '';
    Array.from(input.files).forEach(function(f){
        list.innerHTML += '<div><i class="lucide-icon wf-icon-gap-sm" data-lucide="file" aria-hidden="true"></i>'+f.name+'</div>';
    });
}
</script>
<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
