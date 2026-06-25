<?php
/**
 * Member Portal — ऋण आवेदन (Native Loan Application Form)
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

$memberId     = (int)$mem['id'];
$memEmail     = trim((string)($mem['email'] ?? ''));
$memPhone     = preg_replace('/[^0-9]/', '', (string)($mem['phone'] ?? ''));
$memName      = trim((string)($mem['name'] ?? ''));
$memSadasyata = trim((string)($mem['sadasyata_no'] ?? $mem['member_id'] ?? ''));

/* KYC priority */
$kycRow = null;
try {
    $kycLinkId = (int)($mem['kyc_application_id'] ?? 0);
    if ($kycLinkId > 0) {
        $ks = $db->prepare("SELECT * FROM kyc_applications WHERE id=? LIMIT 1");
        $ks->execute([$kycLinkId]);
        $kycRow = $ks->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$kycRow) {
        $ks2 = $db->prepare("SELECT * FROM kyc_applications WHERE member_id=? ORDER BY id DESC LIMIT 1");
        $ks2->execute([$memberId]);
        $kycRow = $ks2->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Throwable $e) {}
if ($kycRow) {
    $fn = trim((string)($kycRow['full_name'] ?? ''));
    if ($fn !== '') $memName = $fn;
}
$rPhone   = $memPhone ?: trim((string)($kycRow['phone'] ?? ''));
$rEmail   = $memEmail ?: trim((string)($kycRow['email'] ?? ''));
$rAddress = trim((string)($kycRow['permanent_address'] ?? $kycRow['address'] ?? ''));
$rCitizen = trim((string)($kycRow['citizenship_no'] ?? ''));

/* Loan rates */
$loanRates = [];
try {
    $loanRates = $db->query("SELECT * FROM interest_rates WHERE category='loan' AND is_active=1 ORDER BY display_order ASC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* Recent loan applications */
$recentLoans = [];
try {
    $rl = $db->prepare("SELECT tracking_id, loan_type, loan_amount, status, created_at FROM loan_applications WHERE member_id=? ORDER BY created_at DESC LIMIT 10");
    $rl->execute([$memberId]);
    $recentLoans = $rl->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

/* ── Handle POST ── */
$successMsg     = '';
$errorMsg       = '';
$loanTrackingId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    if (!verifyCSRFToken()) {
        $errorMsg = $_t('सुरक्षा जाँच असफल।', 'Security check failed.');
    } else {
        $loan_type        = trim((string)($_POST['loan_type'] ?? ''));
        $loan_amount      = (float)($_POST['loan_amount'] ?? 0);
        $loan_tenure      = (int)($_POST['loan_tenure'] ?? 0);
        $repayment_method = trim((string)($_POST['repayment_method'] ?? ''));
        $loan_purpose     = trim((string)($_POST['loan_purpose'] ?? ''));
        $occupation       = trim((string)($_POST['occupation'] ?? ''));
        $monthly_income   = (float)($_POST['monthly_income'] ?? 0);
        $organization     = trim((string)($_POST['organization_name'] ?? ''));
        $other_income     = trim((string)($_POST['other_income'] ?? ''));
        $collateral_type  = trim((string)($_POST['collateral_type'] ?? ''));
        $collateral_desc  = trim((string)($_POST['collateral_desc'] ?? ''));

        if (!$loan_type)       $errorMsg = $_t('ऋणको प्रकार छान्नुहोस्।', 'Please select loan type.');
        elseif ($loan_amount < 1000) $errorMsg = $_t('ऋण रकम कम्तिमा रु. १,००० हुनुपर्छ।', 'Loan amount must be at least Rs. 1,000.');

        if (!$errorMsg) {
            try {
                $loanTrackingId = 'LNP-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
                $stmt = $db->prepare("INSERT INTO loan_applications
                    (tracking_id, full_name, member_id, mobile, email, address, citizenship_no,
                     loan_type, loan_amount, loan_purpose, loan_tenure, repayment_method,
                     occupation, organization_name, monthly_income, other_income,
                     collateral_type, collateral_description)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([
                    $loanTrackingId, $memName, $memberId, $rPhone, $rEmail, $rAddress, $rCitizen,
                    $loan_type, $loan_amount, $loan_purpose, $loan_tenure ?: null, $repayment_method ?: null,
                    $occupation ?: null, $organization ?: null, $monthly_income ?: null, $other_income ?: null,
                    $collateral_type ?: null, $collateral_desc ?: null,
                ]);
                /* Reload */
                $rl2 = $db->prepare("SELECT tracking_id, loan_type, loan_amount, status, created_at FROM loan_applications WHERE member_id=? ORDER BY created_at DESC LIMIT 10");
                $rl2->execute([$memberId]);
                $recentLoans = $rl2->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $successMsg = $_t('ऋण आवेदन सफलतापूर्वक पेश भयो! Tracking ID: ', 'Loan application submitted! Tracking ID: ') . $loanTrackingId;
                if (function_exists('sendAdminNotification')) {
                    require_once __DIR__ . '/../includes/notifications.php';
                    sendAdminNotification('loan_application', ['नाम' => $memName, 'ऋण प्रकार' => $loan_type, 'रकम' => 'रु. ' . number_format($loan_amount)], $loanTrackingId);
                }
                logSecurityEvent('loan_application', 'Member portal: ' . $memName . ' (' . $loanTrackingId . ')');
            } catch (Throwable $e) {
                $errorMsg = $_t('पेश गर्न सकिएन। पुनः प्रयास गर्नुहोस्।', 'Could not submit. Please try again.');
            }
        }
    }
}

/* Active tab */
$activeTab = 'new';
if ($successMsg) $activeTab = 'history';
if (isset($_GET['tab']) && in_array($_GET['tab'], ['new','history'], true)) $activeTab = $_GET['tab'];

$statusColors = [
    'pending'   => 'sr-status--pending',
    'approved'  => 'sr-status--confirmed',
    'rejected'  => 'sr-status--cancelled',
    'processing'=> 'sr-status--processing',
    'completed' => 'sr-status--completed',
];
$statusLabels = [
    'pending'    => $_t('पर्खिँदै', 'Pending'),
    'approved'   => $_t('स्वीकृत', 'Approved'),
    'rejected'   => $_t('अस्वीकृत', 'Rejected'),
    'processing' => $_t('प्रक्रिया', 'Processing'),
    'completed'  => $_t('सम्पन्न', 'Completed'),
];

$siteName  = getSetting('site_name', 'सहकारी');
$pageTitle = $_t('ऋण आवेदन', 'Loan Application') . ' — ' . $siteName;
$csrfField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken()) . '">';
require __DIR__ . '/includes/chrome.php';
?>
<main class="mp-main">
<div class="mp-container">

  <div class="mp-page-head">
    <h1 class="mem-page-title">
      <i class="fas fa-hand-holding-dollar"></i><?php echo $_t('ऋण आवेदन', 'Loan Application'); ?>
    </h1>
    <a href="tracker.php" class="mp-tracker-link">
      <i class="fas fa-magnifying-glass-chart"></i> Tracker
    </a>
  </div>

  <?php if ($errorMsg): ?>
  <div class="mem-alert mem-alert-error">
    <i class="fas fa-circle-xmark"></i><div><?= htmlspecialchars($errorMsg) ?></div>
  </div>
  <?php endif; ?>

  <div class="wf-tabs">
    <button class="wf-tab <?= $activeTab==='new'?'active':'' ?>" onclick="loanShowTab(this,'loan-pane-new')" id="loanTabNew">
      <i class="fas fa-plus-circle wf-icon-gap-sm"></i><?php echo $_t('नयाँ आवेदन', 'New Application'); ?>
    </button>
    <button class="wf-tab <?= $activeTab==='history'?'active':'' ?>" onclick="loanShowTab(this,'loan-pane-history')" id="loanTabHistory">
      <i class="fas fa-clock-rotate-left wf-icon-gap-sm"></i><?php echo $_t('मेरा आवेदनहरू', 'My Applications'); ?> (<?= count($recentLoans) ?>)
    </button>
  </div>

  <!-- ── New Application ── -->
  <div class="wf-pane <?= $activeTab==='new'?'active':'' ?>" id="loan-pane-new">
    <div class="mem-autofill-banner">
      <i class="fas fa-wand-magic-sparkles"></i>
      <div><?php echo $_t('तपाईंको नाम, फोन, email — <strong>KYC/profile बाट auto-fill</strong> भएको छ।', 'Name, phone and email are <strong>auto-filled from KYC/profile</strong>.'); ?></div>
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

      <!-- Loan Details -->
      <div class="mem-form-row mem-form-row-2">
        <div class="mem-form-group">
          <label class="mem-form-label"><?php echo $_t('ऋणको प्रकार', 'Loan Type'); ?> <span class="mem-form-required">*</span></label>
          <select name="loan_type" class="mem-form-control" required>
            <option value="">— <?php echo $_t('ऋण प्रकार छान्नुहोस्', 'Select loan type'); ?> —</option>
            <?php foreach ($loanRates as $lr): ?>
            <option value="<?= htmlspecialchars($lr['name_np'] ?: $lr['name']) ?>"><?= htmlspecialchars($lr['name_np'] ?: $lr['name']) ?> (<?= number_format($lr['rate'],2) ?>%)</option>
            <?php endforeach; ?>
            <option value="व्यापार ऋण"><?php echo $_t('व्यापार ऋण', 'Business Loan'); ?></option>
            <option value="घर ऋण"><?php echo $_t('घर ऋण', 'Home Loan'); ?></option>
            <option value="शिक्षा ऋण"><?php echo $_t('शिक्षा ऋण', 'Education Loan'); ?></option>
            <option value="वाहन ऋण"><?php echo $_t('वाहन ऋण', 'Vehicle Loan'); ?></option>
            <option value="व्यक्तिगत ऋण"><?php echo $_t('व्यक्तिगत ऋण', 'Personal Loan'); ?></option>
            <option value="कृषि ऋण"><?php echo $_t('कृषि ऋण', 'Agriculture Loan'); ?></option>
          </select>
        </div>
        <div class="mem-form-group">
          <label class="mem-form-label"><?php echo $_t('ऋण रकम (रु.)', 'Loan Amount (Rs.)'); ?> <span class="mem-form-required">*</span></label>
          <input type="number" name="loan_amount" class="mem-form-control" required min="1000" step="1000" placeholder="5,00,000" value="<?= htmlspecialchars($_POST['loan_amount'] ?? '') ?>">
        </div>
      </div>

      <div class="mem-form-row mem-form-row-2">
        <div class="mem-form-group">
          <label class="mem-form-label"><?php echo $_t('ऋण अवधि (महिना)', 'Loan Tenure (Months)'); ?></label>
          <select name="loan_tenure" class="mem-form-control">
            <option value="">— <?php echo $_t('छान्नुहोस्', 'Select'); ?> —</option>
            <?php foreach ([12,24,36,48,60,84,120] as $m): ?>
            <option value="<?= $m ?>" <?= ($_POST['loan_tenure'] ?? '') == $m ? 'selected' : '' ?>><?= $m ?> <?php echo $_t('महिना', 'Months'); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mem-form-group">
          <label class="mem-form-label"><?php echo $_t('भुक्तानी विधि', 'Repayment Method'); ?></label>
          <select name="repayment_method" class="mem-form-control">
            <option value="">— <?php echo $_t('छान्नुहोस्', 'Select'); ?> —</option>
            <option value="emi" <?= ($_POST['repayment_method'] ?? '') === 'emi' ? 'selected' : '' ?>><?php echo $_t('ईएमआई (मासिक किस्ता)', 'EMI (Monthly)'); ?></option>
            <option value="quarterly" <?= ($_POST['repayment_method'] ?? '') === 'quarterly' ? 'selected' : '' ?>><?php echo $_t('त्रैमासिक', 'Quarterly'); ?></option>
            <option value="bullet" <?= ($_POST['repayment_method'] ?? '') === 'bullet' ? 'selected' : '' ?>><?php echo $_t('एकमुष्ट', 'Bullet Payment'); ?></option>
          </select>
        </div>
      </div>

      <div class="mem-form-group">
        <label class="mem-form-label"><?php echo $_t('ऋणको उद्देश्य', 'Loan Purpose'); ?></label>
        <textarea name="loan_purpose" class="mem-form-control" rows="2" placeholder="<?php echo $_t('ऋण किन चाहिएको छ...', 'Why do you need this loan...'); ?>"><?= htmlspecialchars($_POST['loan_purpose'] ?? '') ?></textarea>
      </div>

      <!-- Income -->
      <div class="mem-form-row mem-form-row-2">
        <div class="mem-form-group">
          <label class="mem-form-label"><?php echo $_t('पेशा', 'Occupation'); ?></label>
          <select name="occupation" class="mem-form-control">
            <option value="">— <?php echo $_t('छान्नुहोस्', 'Select'); ?> —</option>
            <?php foreach ([
                ['government', $_t('सरकारी नोकरी', 'Government Job')],
                ['private',    $_t('निजी नोकरी', 'Private Job')],
                ['business',   $_t('व्यापार', 'Business')],
                ['agriculture',$_t('कृषि', 'Agriculture')],
                ['foreign',    $_t('वैदेशिक रोजगार', 'Foreign Employment')],
                ['other',      $_t('अन्य', 'Other')],
            ] as [$val, $lbl]): ?>
            <option value="<?= $val ?>" <?= ($_POST['occupation'] ?? '') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mem-form-group">
          <label class="mem-form-label"><?php echo $_t('मासिक आय (रु.)', 'Monthly Income (Rs.)'); ?></label>
          <input type="number" name="monthly_income" class="mem-form-control" min="0" value="<?= htmlspecialchars($_POST['monthly_income'] ?? '') ?>">
        </div>
      </div>

      <!-- Collateral -->
      <div class="mem-form-row mem-form-row-2">
        <div class="mem-form-group">
          <label class="mem-form-label"><?php echo $_t('धितो प्रकार', 'Collateral Type'); ?></label>
          <select name="collateral_type" class="mem-form-control">
            <option value="">— <?php echo $_t('छान्नुहोस्', 'Select'); ?> —</option>
            <option value="land"><?php echo $_t('जग्गा', 'Land'); ?></option>
            <option value="building"><?php echo $_t('घर/भवन', 'Building'); ?></option>
            <option value="vehicle"><?php echo $_t('वाहन', 'Vehicle'); ?></option>
            <option value="deposit"><?php echo $_t('मुद्दती रसिद', 'Fixed Deposit'); ?></option>
            <option value="guarantee"><?php echo $_t('व्यक्तिगत जमानी', 'Personal Guarantee'); ?></option>
            <option value="other"><?php echo $_t('अन्य', 'Other'); ?></option>
          </select>
        </div>
        <div class="mem-form-group">
          <label class="mem-form-label"><?php echo $_t('धितो विवरण', 'Collateral Details'); ?></label>
          <input type="text" name="collateral_desc" class="mem-form-control" value="<?= htmlspecialchars($_POST['collateral_desc'] ?? '') ?>" placeholder="<?php echo $_t('संक्षेपमा...', 'Brief description...'); ?>">
        </div>
      </div>

      <div class="mp-list-meta loan-emi-hint">
        <i class="fas fa-calculator ico-primary"></i>
        <?php echo $_t('मासिक किस्ता अनुमान:', 'Estimate monthly installment:'); ?>
        <a href="<?= SITE_URL ?>member/apply-frame.php?p=emi" class="loan-emi-link">EMI Calculator →</a>
      </div>

      <button type="submit" class="mem-submit-btn">
        <i class="fas fa-paper-plane"></i> <?php echo $_t('आवेदन पेश गर्नुहोस्', 'Submit Application'); ?>
      </button>
    </form>
  </div>

  <!-- ── History ── -->
  <div class="wf-pane <?= $activeTab==='history'?'active':'' ?>" id="loan-pane-history">
    <?php if ($successMsg): ?>
    <div class="mem-alert mem-alert-success">
      <i class="fas fa-circle-check"></i><div><?= htmlspecialchars($successMsg) ?></div>
    </div>
    <?php endif; ?>

    <?php if (empty($recentLoans)): ?>
    <div class="mp-empty">
      <i class="fas fa-file-circle-xmark mp-empty-icon"></i>
      <div class="mp-empty-title"><?php echo $_t('कुनै आवेदन छैन', 'No applications yet'); ?></div>
      <div class="mp-empty-hint"><?php echo $_t('"नयाँ आवेदन" tab बाट ऋणको लागि आवेदन दिनुहोस्।', 'Use "New Application" tab to apply.'); ?></div>
    </div>
    <?php else: ?>
    <?php foreach ($recentLoans as $ln):
        $stCls = $statusColors[$ln['status']] ?? 'sr-status--pending';
        $stLbl = $statusLabels[$ln['status']] ?? htmlspecialchars($ln['status']);
    ?>
    <div class="recent-card">
      <div>
        <div class="mp-list-title"><?= htmlspecialchars($ln['loan_type']) ?> — रु. <?= number_format((float)$ln['loan_amount']) ?></div>
        <div class="mp-list-meta"><?= date('Y-m-d', strtotime($ln['created_at'])) ?></div>
      </div>
      <div class="mp-status-row">
        <span class="mem-tracking-id"><?= htmlspecialchars($ln['tracking_id']) ?></span>
        <span class="sr-status <?= $stCls ?>"><?= $stLbl ?></span>
      </div>
    </div>
    <?php endforeach; ?>
    <a href="tracker.php" class="mp-tracker-link-sm">
      <i class="fas fa-magnifying-glass-chart ico-mr"></i><?php echo $_t('सबै Tracker मा हेर्नुहोस्', 'View all in Tracker'); ?> →
    </a>
    <?php endif; ?>
  </div>

</div>
</main>
<script>
function loanShowTab(btn, paneId) {
    document.querySelectorAll('.wf-tab').forEach(function(t){ t.classList.remove('active'); });
    document.querySelectorAll('.wf-pane').forEach(function(p){ p.classList.remove('active'); });
    btn.classList.add('active');
    var pane = document.getElementById(paneId);
    if (pane) pane.classList.add('active');
}
</script>
<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
