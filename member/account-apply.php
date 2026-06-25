<?php
/**
 * Member Portal — खाता खोल्ने आवेदन (Native Account Opening Form)
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
$rPhone    = $memPhone ?: trim((string)($kycRow['phone'] ?? ''));
$rEmail    = $memEmail ?: trim((string)($kycRow['email'] ?? ''));
$rAddress  = trim((string)($kycRow['permanent_address'] ?? $kycRow['address'] ?? ''));
$rCitizen  = trim((string)($kycRow['citizenship_no'] ?? ''));
$rDobBS    = trim((string)($kycRow['dob_bs'] ?? ''));
$rGender   = trim((string)($kycRow['gender'] ?? ''));

/* Recent account applications */
$recentAccounts = [];
try {
    $ra = $db->prepare("SELECT tracking_id, account_type, initial_deposit, status, created_at FROM account_applications WHERE member_id=? ORDER BY created_at DESC LIMIT 10");
    $ra->execute([$memberId]);
    $recentAccounts = $ra->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

/* ── Handle POST ── */
$successMsg    = '';
$errorMsg      = '';
$accTrackingId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    if (!verifyCSRFToken()) {
        $errorMsg = $_t('सुरक्षा जाँच असफल।', 'Security check failed.');
    } else {
        $account_type    = trim((string)($_POST['account_type'] ?? ''));
        $initial_deposit = (float)($_POST['initial_deposit'] ?? 1000);
        $nominee_name    = trim((string)($_POST['nominee_name'] ?? ''));
        $nominee_relation= trim((string)($_POST['nominee_relation'] ?? ''));
        $nominee_phone   = trim((string)($_POST['nominee_phone'] ?? ''));
        $branch          = trim((string)($_POST['branch'] ?? ''));
        $notes           = trim((string)($_POST['notes'] ?? ''));

        if (!$account_type) $errorMsg = $_t('खाता प्रकार छान्नुहोस्।', 'Please select account type.');

        if (!$errorMsg) {
            try {
                $accTrackingId = 'ACC-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
                $stmt = $db->prepare("INSERT INTO account_applications
                    (tracking_id, account_type, full_name, full_name_en, dob_bs, gender,
                     mobile, email, permanent_address, citizenship_no,
                     initial_deposit, nominee_name, nominee_relation, nominee_phone,
                     member_id, branch)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([
                    $accTrackingId, $account_type,
                    $memName, '', $rDobBS, $rGender,
                    $rPhone, $rEmail, $rAddress, $rCitizen,
                    $initial_deposit, $nominee_name ?: null, $nominee_relation ?: null, $nominee_phone ?: null,
                    $memberId, $branch ?: null,
                ]);
                /* Reload */
                $ra2 = $db->prepare("SELECT tracking_id, account_type, initial_deposit, status, created_at FROM account_applications WHERE member_id=? ORDER BY created_at DESC LIMIT 10");
                $ra2->execute([$memberId]);
                $recentAccounts = $ra2->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $successMsg = $_t('खाता खोल्ने आवेदन सफलतापूर्वक पेश भयो! Tracking ID: ', 'Account application submitted! Tracking ID: ') . $accTrackingId;
                if (function_exists('sendAdminNotification')) {
                    require_once __DIR__ . '/../includes/notifications.php';
                    sendAdminNotification('account_opening', ['नाम' => $memName, 'खाता प्रकार' => $account_type, 'जम्मा' => 'रु. ' . number_format($initial_deposit)], $accTrackingId);
                }
                logSecurityEvent('account_application', 'Member portal: ' . $memName . ' (' . $accTrackingId . ')');
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
    'pending'    => 'sr-status--pending',
    'approved'   => 'sr-status--confirmed',
    'rejected'   => 'sr-status--cancelled',
    'processing' => 'sr-status--processing',
    'completed'  => 'sr-status--completed',
];
$statusLabels = [
    'pending'    => $_t('पर्खिँदै', 'Pending'),
    'approved'   => $_t('स्वीकृत', 'Approved'),
    'rejected'   => $_t('अस्वीकृत', 'Rejected'),
    'processing' => $_t('प्रक्रिया', 'Processing'),
    'completed'  => $_t('सम्पन्न', 'Completed'),
];
$accountTypeLabels = [
    'saving'    => $_t('बचत खाता', 'Saving Account'),
    'current'   => $_t('चल्ती खाता', 'Current Account'),
    'fixed'     => $_t('मुद्दती निक्षेप', 'Fixed Deposit'),
    'recurring' => $_t('आवधिक बचत', 'Recurring Deposit'),
    'child'     => $_t('बाल बचत', 'Child Saving'),
];

/* Branches */
$branches = [];
try {
    $branches = $db->query("SELECT * FROM service_centers WHERE is_active=1 ORDER BY is_main_branch DESC, display_order ASC, name ASC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$siteName  = getSetting('site_name', 'सहकारी');
$pageTitle = $_t('खाता खोल्ने आवेदन', 'Account Opening') . ' — ' . $siteName;
$csrfField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken()) . '">';
require __DIR__ . '/includes/chrome.php';
?>
<main class="mp-main">
<div class="mp-container">

  <div class="mp-page-head">
    <h1 class="mem-page-title">
      <i class="fas fa-landmark"></i><?php echo $_t('खाता खोल्ने आवेदन', 'Account Opening'); ?>
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
    <button class="wf-tab <?= $activeTab==='new'?'active':'' ?>" onclick="accShowTab(this,'acc-pane-new')" id="accTabNew">
      <i class="fas fa-plus-circle wf-icon-gap-sm"></i><?php echo $_t('नयाँ आवेदन', 'New Application'); ?>
    </button>
    <button class="wf-tab <?= $activeTab==='history'?'active':'' ?>" onclick="accShowTab(this,'acc-pane-history')" id="accTabHistory">
      <i class="fas fa-clock-rotate-left wf-icon-gap-sm"></i><?php echo $_t('मेरा आवेदनहरू', 'My Applications'); ?> (<?= count($recentAccounts) ?>)
    </button>
  </div>

  <!-- ── New Application ── -->
  <div class="wf-pane <?= $activeTab==='new'?'active':'' ?>" id="acc-pane-new">
    <div class="mem-autofill-banner">
      <i class="fas fa-wand-magic-sparkles"></i>
      <div><?php echo $_t('तपाईंको जानकारी — <strong>KYC/profile बाट auto-fill</strong> भएको छ।', 'Your details are <strong>auto-filled from KYC/profile</strong>.'); ?></div>
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

      <!-- Account Type -->
      <div class="mem-form-row mem-form-row-2">
        <div class="mem-form-group">
          <label class="mem-form-label"><?php echo $_t('खाता प्रकार', 'Account Type'); ?> <span class="mem-form-required">*</span></label>
          <select name="account_type" class="mem-form-control" required>
            <option value="">— <?php echo $_t('खाता छान्नुहोस्', 'Select account type'); ?> —</option>
            <?php foreach ($accountTypeLabels as $val => $lbl): ?>
            <option value="<?= $val ?>" <?= ($_POST['account_type'] ?? '') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mem-form-group">
          <label class="mem-form-label"><?php echo $_t('प्रारम्भिक जम्मा', 'Initial Deposit'); ?></label>
          <select name="initial_deposit" class="mem-form-control">
            <option value="1000">रु. १,०००</option>
            <option value="5000">रु. ५,०००</option>
            <option value="10000">रु. १०,०००</option>
            <option value="25000">रु. २५,०००</option>
            <option value="50000">रु. ५०,०००</option>
            <option value="100000">रु. १,००,०००+</option>
          </select>
        </div>
      </div>

      <div class="mem-form-group">
        <label class="mem-form-label"><i class="fas fa-building ico-primary"></i><?php echo $_t('शाखा', 'Branch'); ?></label>
        <select name="branch" class="mem-form-control">
          <option value=""><?php echo $_t('शाखा छान्नुहोस्', 'Select branch'); ?></option>
          <?php foreach ($branches as $br): ?>
          <option value="<?= htmlspecialchars($br['name']) ?>"><?= htmlspecialchars($br['name']) ?></option>
          <?php endforeach; ?>
          <option value="प्रधान कार्यालय"><?php echo $_t('प्रधान कार्यालय', 'Head Office'); ?></option>
        </select>
      </div>

      <!-- Nominee -->
      <div class="mp-section-divider">
        <i class="fas fa-user-friends ico-primary"></i><?php echo $_t('नमिनी विवरण (Optional)', 'Nominee Details (Optional)'); ?>
      </div>
      <div class="mem-form-row mem-form-row-2">
        <div class="mem-form-group">
          <label class="mem-form-label"><?php echo $_t('नमिनीको नाम', 'Nominee Name'); ?></label>
          <input type="text" name="nominee_name" class="mem-form-control" value="<?= htmlspecialchars($_POST['nominee_name'] ?? '') ?>" placeholder="<?php echo $_t('पूरा नाम', 'Full name'); ?>">
        </div>
        <div class="mem-form-group">
          <label class="mem-form-label"><?php echo $_t('सम्बन्ध', 'Relation'); ?></label>
          <select name="nominee_relation" class="mem-form-control">
            <option value="">—</option>
            <option value="spouse"><?php echo $_t('पति/पत्नी', 'Spouse'); ?></option>
            <option value="son"><?php echo $_t('छोरा', 'Son'); ?></option>
            <option value="daughter"><?php echo $_t('छोरी', 'Daughter'); ?></option>
            <option value="father"><?php echo $_t('बुबा', 'Father'); ?></option>
            <option value="mother"><?php echo $_t('आमा', 'Mother'); ?></option>
            <option value="sibling"><?php echo $_t('दाजुभाइ/दिदीबहिनी', 'Sibling'); ?></option>
            <option value="other"><?php echo $_t('अन्य', 'Other'); ?></option>
          </select>
        </div>
        <div class="mem-form-group">
          <label class="mem-form-label"><?php echo $_t('नमिनीको फोन', 'Nominee Phone'); ?></label>
          <input type="tel" name="nominee_phone" class="mem-form-control" maxlength="10" placeholder="98XXXXXXXX" value="<?= htmlspecialchars($_POST['nominee_phone'] ?? '') ?>">
        </div>
      </div>

      <button type="submit" class="mem-submit-btn">
        <i class="fas fa-paper-plane"></i> <?php echo $_t('आवेदन पेश गर्नुहोस्', 'Submit Application'); ?>
      </button>
    </form>
  </div>

  <!-- ── History ── -->
  <div class="wf-pane <?= $activeTab==='history'?'active':'' ?>" id="acc-pane-history">
    <?php if ($successMsg): ?>
    <div class="mem-alert mem-alert-success">
      <i class="fas fa-circle-check"></i><div><?= htmlspecialchars($successMsg) ?></div>
    </div>
    <?php endif; ?>

    <?php if (empty($recentAccounts)): ?>
    <div class="mp-empty">
      <i class="fas fa-piggy-bank mp-empty-icon"></i>
      <div class="mp-empty-title"><?php echo $_t('कुनै आवेदन छैन', 'No applications yet'); ?></div>
      <div class="mp-empty-hint"><?php echo $_t('"नयाँ आवेदन" tab बाट खाता खोल्नुहोस्।', 'Use "New Application" tab to apply.'); ?></div>
    </div>
    <?php else: ?>
    <?php foreach ($recentAccounts as $ac):
        $stCls = $statusColors[$ac['status']] ?? 'sr-status--pending';
        $stLbl = $statusLabels[$ac['status']] ?? htmlspecialchars($ac['status']);
        $acLabel = $accountTypeLabels[$ac['account_type']] ?? htmlspecialchars($ac['account_type']);
    ?>
    <div class="recent-card">
      <div>
        <div class="mp-list-title"><?= $acLabel ?> — रु. <?= number_format((float)$ac['initial_deposit']) ?></div>
        <div class="mp-list-meta"><?= date('Y-m-d', strtotime($ac['created_at'])) ?></div>
      </div>
      <div class="mp-status-row">
        <span class="mem-tracking-id"><?= htmlspecialchars($ac['tracking_id']) ?></span>
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
function accShowTab(btn, paneId) {
    document.querySelectorAll('.wf-tab').forEach(function(t){ t.classList.remove('active'); });
    document.querySelectorAll('.wf-pane').forEach(function(p){ p.classList.remove('active'); });
    btn.classList.add('active');
    var pane = document.getElementById(paneId);
    if (pane) pane.classList.add('active');
}
</script>
<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
