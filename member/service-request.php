<?php
/**
 * Member Portal — सेवा अनुरोध (Pre-filled Service Request)
 * Member profile data auto-fills form — zero re-entry
 */
require_once __DIR__ . '/_bootstrap.php';
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

$memName    = trim((string)($kycRow['full_name']    ?? $mem['name']            ?? ''));
$memSadasyata = trim((string)($kycRow['member_id']  ?? $mem['sadasyata_number']?? ''));
$rPhone     = $memPhone ?: preg_replace('/[^0-9]/', '', (string)($kycRow['mobile'] ?? ''));
$rEmail     = $memEmail ?: strtolower(trim((string)($kycRow['email'] ?? '')));
$rAddress   = trim((string)($kycRow['temporary_address'] ?? $kycRow['permanent_address'] ?? ''));
$rBranch    = trim((string)($kycRow['branch'] ?? ''));

/* Service type options with target table/purpose */
$serviceTypes = [
    'appointment'       => ['label' => $_t('📅 भेटघाट — शाखा भ्रमण / भेट माग्ने','📅 Appointment — Branch visit request'),      'table' => 'appointments', 'purpose' => 'other'],
    'loan_inquiry'      => ['label' => $_t('💰 ऋण जानकारी — कर्जा सम्बन्धी सोधपुछ','💰 Loan Inquiry — Ask about loans'), 'table' => 'appointments', 'purpose' => 'loan_inquiry'],
    'account_info'      => ['label' => $_t('🏦 खाता जानकारी — बचत खाता सम्बन्धी','🏦 Account Info — Savings account related'),   'table' => 'appointments', 'purpose' => 'account_inquiry'],
    'welfare_inquiry'   => ['label' => $_t('❤️ कल्याण सोधपुछ — सुविधा जानकारी','❤️ Welfare Inquiry — Benefit information'),    'table' => 'appointments', 'purpose' => 'other'],
    'document_request'  => ['label' => $_t('📄 कागजात माग — NOC, Statement आदि','📄 Document Request — NOC, Statement etc.'),   'table' => 'appointments', 'purpose' => 'other'],
    'grievance'         => ['label' => $_t('📣 गुनासो — समस्या दर्ता गर्ने','📣 Grievance — Register a problem'),         'table' => 'grievances',   'purpose' => 'other'],
    'general'           => ['label' => $_t('💬 सामान्य सोधपुछ','💬 General Inquiry'),                       'table' => 'appointments', 'purpose' => 'other'],
];

$successMsg = '';
$errorMsg   = '';
$submitted  = [];

/* ── Handle POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    if (!verifyCSRFToken()) {
        $errorMsg = $_t('सुरक्षा जाँच असफल।', 'Security check failed.');
    } elseif (!checkRateLimit('svcreq_' . $memberId, 10, 3600)) {
        $errorMsg = $_t('धेरै अनुरोध भए। १ घण्टापछि पुनः प्रयास गर्नुहोस्।', 'Too many requests. Please try again after 1 hour.');
    } else {
        $svcType    = trim($_POST['service_type'] ?? '');
        $message    = trim(substr($_POST['message'] ?? '', 0, 2000));
        $prefDate   = trim($_POST['preferred_date'] ?? '') ?: null;
        $prefTime   = trim($_POST['preferred_time'] ?? '');
        $branch     = trim(substr($_POST['branch'] ?? '', 0, 80)) ?: $rBranch;

        if (!isset($serviceTypes[$svcType])) {
            $errorMsg = $_t('सेवा प्रकार छान्नुहोस्।', 'Please select service type.');
        } elseif (!$message) {
            $errorMsg = $_t('सन्देश / विवरण अनिवार्य छ।', 'Message/description is required.');
        } else {
            $svc       = $serviceTypes[$svcType];
            $trackingId = 'REQ-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid($memberId,true)),0,6));
            $svcLabel = $serviceTypes[$svcType]['label'] ?? $svcType;
            $corePurpose = $svc['purpose'] ?: 'other';
            $detailPrefix = preg_replace('/^[^\s]+\s*/u', '', $svcLabel);
            $detailText = trim($detailPrefix . ($message ? ("\n\n" . $message) : ''));

            try {
                if ($svc['table'] === 'grievances') {
                    $ins = $db->prepare("INSERT INTO grievances
                        (tracking_id, name, member_id, phone, email, category, subject, description, is_anonymous, status, created_at)
                        VALUES (?,?,?,?,?,'service',?,?,0,'pending',NOW())");
                    $ins->execute([$trackingId, $memName, $memSadasyata, $rPhone, $rEmail, $detailPrefix, $detailText]);
                } else {
                    /* Canonical appointments insert (purpose enum + purpose_detail text). */
                    $effectiveDate = $prefDate ?: date('Y-m-d');
                    $effectiveTime = $prefTime ?: '10:00 AM';
                    $ins = $db->prepare("INSERT INTO appointments
                        (tracking_id, name, phone, email, member_id, preferred_date, preferred_time, purpose, purpose_detail, branch, status, created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,'pending',NOW())");
                    $ins->execute([$trackingId, $memName, $rPhone, $rEmail, $memSadasyata, $effectiveDate, $effectiveTime, $corePurpose, $detailText, $branch]);
                }
                $successMsg = isEnglish()
                    ? "Request submitted! Tracking ID: <strong>$trackingId</strong> — You will be notified after admin confirmation."
                    : "अनुरोध दर्ता भयो! Tracking ID: <strong>$trackingId</strong> — Admin ले confirm गरेपछि सूचित गरिनेछ।";
            } catch (Throwable $e) {
                $errorMsg = $_t('दर्ता गर्न समस्या भयो। पुनः प्रयास गर्नुहोस्।', 'Failed to submit. Please try again.');
                error_log('[service-request] ' . $e->getMessage());
            }
        }
    }
}

/* Recent requests */
$recentReqs = [];
try {
    $rConds = []; $rParams = [];
    if ($rEmail) { $rConds[] = 'LOWER(email)=?'; $rParams[] = strtolower($rEmail); }
    if ($rPhone) { $rConds[] = 'phone=?'; $rParams[] = $rPhone; }
    if (!empty($rConds)) {
        $st = $db->prepare("SELECT tracking_id, name, purpose, status, preferred_date, created_at FROM appointments
                            WHERE " . implode(' OR ', $rConds) . " ORDER BY created_at DESC LIMIT 8");
        $st->execute($rParams);
        $recentReqs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) { $recentReqs = []; }

$siteName  = getSetting('site_name', 'सहकारी');
$pageTitle = $_t('सेवा अनुरोध', 'Service Request') . ' — ' . $siteName;
$csrfField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken()) . '">';

/* Active tab */
$srActiveTab = 'new';
if ($successMsg) $srActiveTab = 'history';
if (isset($_GET['tab']) && in_array($_GET['tab'], ['new','history'], true)) $srActiveTab = $_GET['tab'];

$statusColors = [
    'pending' => 'sr-status--pending',
    'confirmed' => 'sr-status--confirmed',
    'completed' => 'sr-status--completed',
    'cancelled' => 'sr-status--cancelled',
    'processing' => 'sr-status--processing'
];

$extraHead = <<<'HTML'
<style>
/* service-request.php — page-specific only; base styles from member-portal-v2.css */
.svc-card { background:#fff;border:2px solid var(--border-color,#e5e7eb);border-radius:12px;padding:13px 15px;cursor:pointer;transition:all .18s;margin-bottom:10px;display:flex;align-items:center;gap:10px; }
.svc-card:hover,.svc-card.sel { border-color:var(--primary-color);background:color-mix(in srgb,var(--primary-color) 6%,white); }
.svc-card.sel { box-shadow:0 0 0 3px rgba(var(--primary-rgb,26,95,42),.1); }
.svc-card-icon { width:36px;height:36px;border-radius:10px;background:color-mix(in srgb,var(--primary-color) 10%,white);display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0; }
.svc-label { font-size:.88rem;font-weight:600;color:var(--text-primary,#1a2e1f); }
.recent-card { background:color-mix(in srgb,var(--primary-color) 5%,white);border:1px solid var(--border-color,#e5e7eb);border-radius:10px;padding:10px 13px;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px; }
.sr-status--pending,.sr-status--confirmed { color:var(--secondary-color,#c0392b); }
.sr-status--completed { color:var(--primary-color,#1a5f2a); }
.sr-status--cancelled { color:var(--text-muted,#6b7280); }
.sr-track { font-size:.72rem;font-family:monospace;letter-spacing:.5px;background:color-mix(in srgb,var(--primary-color) 8%,white);padding:2px 7px;border-radius:5px;color:var(--primary-color); }
</style>
HTML;
?>
<?php require __DIR__ . '/includes/chrome.php'; ?>

<main class="mp-main">
<div class="mp-container">

  <div class="mp-page-head">
    <h1 class="mem-page-title">
      <i class="fas fa-concierge-bell"></i><?php echo $_t('सेवा अनुरोध', 'Service Request'); ?>
    </h1>
    <a href="tracker.php" class="mp-tracker-link">
      <i class="fas fa-magnifying-glass-chart"></i> <?php echo $_t('Tracker', 'Tracker'); ?>
    </a>
  </div>

  <?php if ($errorMsg): ?>
  <div class="mem-alert mem-alert-error">
    <i class="fas fa-circle-xmark"></i><div><?= htmlspecialchars($errorMsg) ?></div>
  </div>
  <?php endif; ?>

  <!-- ── Tabs ── -->
  <div class="wf-tabs">
    <button class="wf-tab <?= $srActiveTab==='new'?'active':'' ?>" onclick="srShowTab(this,'sr-pane-new')" id="srTabNew">
      <i class="fas fa-plus-circle wf-icon-gap-sm"></i><?php echo $_t('नयाँ अनुरोध', 'New Request'); ?>
    </button>
    <button class="wf-tab <?= $srActiveTab==='history'?'active':'' ?>" onclick="srShowTab(this,'sr-pane-history')" id="srTabHistory">
      <i class="fas fa-clock-rotate-left wf-icon-gap-sm"></i><?php echo $_t('मेरा अनुरोधहरू', 'My Requests'); ?> (<?= count($recentReqs) ?>)
    </button>
  </div>

  <!-- ── Tab: New Request ── -->
  <div class="wf-pane <?= $srActiveTab==='new'?'active':'' ?>" id="sr-pane-new">
    <div class="mem-autofill-banner">
      <i class="fas fa-wand-magic-sparkles"></i>
      <div><?php echo $_t('तपाईंको नाम, फोन, email — <strong>KYC/profile बाट auto-fill</strong> भएको छ। सेवा प्रकार र सन्देश मात्र भर्नुहोस्।', 'Your name, phone and email are <strong>auto-filled from KYC/profile</strong>. Only select service type and message.'); ?></div>
    </div>

    <form method="POST">
      <?= $csrfField ?>
      <input type="hidden" name="action" value="submit">

      <div class="mem-prefill-block">
        <div class="mem-prefill-block-head"><i class="fas fa-user-check"></i><?php echo $_t('तपाईंको जानकारी (KYC बाट)', 'Your Info (from KYC)'); ?></div>
        <div class="mem-prefill-grid">
          <div class="mem-prefill-item"><span class="mem-prefill-label"><?php echo $_t('नाम', 'Name'); ?></span><span class="mem-prefill-value"><?= htmlspecialchars($memName ?: '—') ?></span></div>
          <div class="mem-prefill-item"><span class="mem-prefill-label"><?php echo $_t('सदस्यता नम्बर', 'Member No.'); ?></span><span class="mem-prefill-value mem-tracking-id"><?= htmlspecialchars($memSadasyata ?: '—') ?></span></div>
          <div class="mem-prefill-item"><span class="mem-prefill-label"><?php echo $_t('फोन', 'Phone'); ?></span><span class="mem-prefill-value"><?= htmlspecialchars($rPhone ?: '—') ?></span></div>
          <div class="mem-prefill-item"><span class="mem-prefill-label">Email</span><span class="mem-prefill-value"><?= htmlspecialchars($rEmail ?: '—') ?></span></div>
        </div>
      </div>

      <div class="mem-form-group">
        <label class="mem-form-label"><?php echo $_t('सेवा प्रकार छान्नुहोस्', 'Select Service Type'); ?> <span class="mem-form-required">*</span></label>
        <select name="service_type" class="mem-form-control" required>
          <option value="">— <?php echo $_t('सेवा छान्नुहोस्', 'Select service'); ?> —</option>
          <?php foreach ($serviceTypes as $key => $svc): ?>
          <option value="<?= $key ?>"><?= $svc['label'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mem-form-row mem-form-row-2">
        <div class="mem-form-group">
          <label class="mem-form-label"><i class="fas fa-calendar ico-primary"></i><?php echo $_t('मनपर्ने मिति (Optional)', 'Preferred Date (Optional)'); ?></label>
          <input type="date" name="preferred_date" class="mem-form-control" min="<?= date('Y-m-d') ?>">
        </div>
        <div class="mem-form-group">
          <label class="mem-form-label"><i class="fas fa-clock ico-primary"></i><?php echo $_t('मनपर्ने समय', 'Preferred Time'); ?></label>
          <?php $preferredTimeValue = trim((string)($_POST['preferred_time'] ?? '')); $preferredTimeOptions = function_exists('getOfficeTimeOptions') ? getOfficeTimeOptions(30) : []; ?>
          <select name="preferred_time" class="mem-form-control">
            <option value="">— <?php echo $_t('समय छान्नुहोस्', 'Select time'); ?> —</option>
            <?php foreach ($preferredTimeOptions as $optVal => $optLabel): ?>
            <option value="<?php echo htmlspecialchars($optVal, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $preferredTimeValue === $optVal ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($optLabel, ENT_QUOTES, 'UTF-8'); ?>
            </option>
            <?php endforeach; ?>
            <?php if ($preferredTimeValue !== '' && !isset($preferredTimeOptions[$preferredTimeValue])): ?>
            <option value="<?php echo htmlspecialchars($preferredTimeValue, ENT_QUOTES, 'UTF-8'); ?>" selected>
              <?php echo htmlspecialchars($preferredTimeValue, ENT_QUOTES, 'UTF-8'); ?>
            </option>
            <?php endif; ?>
          </select>
        </div>
      </div>

      <div class="mem-form-group">
        <label class="mem-form-label"><i class="fas fa-building ico-primary"></i><?php echo $_t('शाखा', 'Branch'); ?></label>
        <input type="text" name="branch" class="mem-form-control" value="<?= htmlspecialchars($rBranch) ?>" placeholder="<?php echo $_t('जस्तै: प्रधान कार्यालय', 'e.g., Head Office'); ?>">
      </div>

      <div class="mem-form-group">
        <label class="mem-form-label"><?php echo $_t('विस्तृत सन्देश', 'Detailed Message'); ?> <span class="mem-form-required">*</span></label>
        <textarea name="message" class="mem-form-control" rows="4" required placeholder="<?php echo $_t('तपाईंको अनुरोधको पूरा विवरण लेख्नुहोस्...', 'Write full details of your request...'); ?>"></textarea>
      </div>

      <button type="submit" class="mem-submit-btn">
        <i class="fas fa-paper-plane"></i> <?php echo $_t('अनुरोध पठाउनुहोस्', 'Submit Request'); ?>
      </button>
    </form>
  </div><!-- /sr-pane-new -->

  <!-- ── Tab: History ── -->
  <div class="wf-pane <?= $srActiveTab==='history'?'active':'' ?>" id="sr-pane-history">
    <?php if ($successMsg): ?>
    <div class="mem-alert mem-alert-success">
      <i class="fas fa-circle-check"></i>
      <div><?= $successMsg ?></div>
    </div>
    <?php endif; ?>

    <?php if (empty($recentReqs)): ?>
    <div class="mp-empty">
      <i class="fas fa-inbox mp-empty-icon"></i>
      <div class="mp-empty-title"><?php echo $_t('कुनै अनुरोध छैन', 'No requests found'); ?></div>
      <div class="mp-empty-hint"><?php echo $_t('"नयाँ अनुरोध" tab बाट सेवा लिनुहोस्।', 'Use "New Request" tab to submit.'); ?></div>
    </div>
    <?php else: ?>
    <?php foreach ($recentReqs as $rq):
        $stClass = $statusColors[$rq['status']] ?? '';
        $stLabels = ['pending'=>$_t('पर्खिँदै','Pending'),'confirmed'=>$_t('पुष्टि','Confirmed'),'completed'=>$_t('सम्पन्न','Completed'),'cancelled'=>$_t('रद्द','Cancelled'),'processing'=>$_t('प्रक्रिया','Processing')];
        $stLabel  = $stLabels[$rq['status']] ?? htmlspecialchars($rq['status']);
    ?>
    <div class="recent-card">
      <div>
        <div class="mp-list-title"><?= htmlspecialchars(mb_substr($rq['purpose'] ?? $rq['tracking_id'],0,60)) ?></div>
        <div class="mp-list-meta"><?= date('Y-m-d', strtotime($rq['created_at'])) ?></div>
      </div>
      <div class="mp-status-row">
        <span class="mem-tracking-id"><?= htmlspecialchars($rq['tracking_id']) ?></span>
        <span class="sr-status <?= htmlspecialchars($stClass) ?>"><?= $stLabel ?></span>
      </div>
    </div>
    <?php endforeach; ?>
    <a href="tracker.php?filter=appointment" class="mp-tracker-link-sm">
      <i class="fas fa-magnifying-glass-chart ico-mr"></i><?php echo $_t('सबै Tracker मा हेर्नुहोस्', 'View all in Tracker'); ?> →
    </a>
    <?php endif; ?>
  </div><!-- /sr-pane-history -->

</div>
</main>
<script>
function srShowTab(btn, paneId) {
    document.querySelectorAll('.wf-tab').forEach(function(t){ t.classList.remove('active'); });
    document.querySelectorAll('.wf-pane').forEach(function(p){ p.classList.remove('active'); });
    btn.classList.add('active');
    var pane = document.getElementById(paneId);
    if (pane) pane.classList.add('active');
}
</script>
<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
