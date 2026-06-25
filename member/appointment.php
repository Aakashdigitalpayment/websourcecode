<?php
/**
 * Member Portal — भेटघाट बुक (Native Appointment Form)
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

$memberId   = (int)$mem['id'];
$memEmail   = trim((string)($mem['email'] ?? ''));
$memPhone   = preg_replace('/[^0-9]/', '', (string)($mem['phone'] ?? ''));
$memName    = trim((string)($mem['name'] ?? ''));
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
$rPhone = $memPhone ?: trim((string)($kycRow['phone'] ?? ''));
$rEmail = $memEmail ?: trim((string)($kycRow['email'] ?? ''));

/* Branches */
$branches = [];
try {
    $branches = $db->query("SELECT * FROM service_centers WHERE is_active=1 ORDER BY is_main_branch DESC, display_order ASC, name ASC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* Time options */
$timeOptions = function_exists('getOfficeTimeOptions') ? getOfficeTimeOptions(30) : [];

/* Recent appointments */
$recentAppts = [];
try {
    $ra = $db->prepare("SELECT * FROM appointments WHERE member_id=? ORDER BY created_at DESC LIMIT 10");
    $ra->execute([$memberId]);
    $recentAppts = $ra->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

/* ── Handle POST ── */
$successMsg = '';
$errorMsg   = '';
$apptTrackingId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    if (!verifyCSRFToken()) {
        $errorMsg = $_t('सुरक्षा जाँच असफल। पुनः प्रयास गर्नुहोस्।', 'Security check failed. Please try again.');
    } else {
        $purpose        = trim((string)($_POST['purpose'] ?? ''));
        $purpose_detail = trim((string)($_POST['purpose_detail'] ?? ''));
        $preferred_date = trim((string)($_POST['preferred_date'] ?? ''));
        $preferred_time = trim((string)($_POST['preferred_time'] ?? ''));
        $branch         = trim((string)($_POST['branch'] ?? ''));

        if (!$purpose)        $errorMsg = $_t('उद्देश्य छान्नुहोस्।', 'Please select a purpose.');
        elseif (!$preferred_date) $errorMsg = $_t('मिति अनिवार्य छ।', 'Preferred date is required.');
        elseif (!$preferred_time) $errorMsg = $_t('समय छान्नुहोस्।', 'Please select preferred time.');

        if (!$errorMsg) {
            try {
                $apptTrackingId = 'APT-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
                $stmt = $db->prepare("INSERT INTO appointments (tracking_id, name, phone, email, member_id, purpose, purpose_detail, preferred_date, preferred_time, branch) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$apptTrackingId, $memName, $rPhone, $rEmail, $memberId, $purpose, $purpose_detail, $preferred_date, $preferred_time, $branch]);
                /* Reload history */
                $ra2 = $db->prepare("SELECT * FROM appointments WHERE member_id=? ORDER BY created_at DESC LIMIT 10");
                $ra2->execute([$memberId]);
                $recentAppts = $ra2->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $successMsg = $_t('भेटघाट अनुरोध सफलतापूर्वक पेश भयो! Tracking ID: ', 'Appointment submitted! Tracking ID: ') . $apptTrackingId;
                if (function_exists('sendAdminNotification')) {
                    require_once __DIR__ . '/../includes/notifications.php';
                    sendAdminNotification('appointment', ['नाम' => $memName, 'फोन' => $rPhone, 'उद्देश्य' => $purpose, 'मिति' => $preferred_date, 'समय' => $preferred_time], $apptTrackingId);
                }
                logSecurityEvent('appointment_booking', 'Member portal: ' . $memName . ' (' . $apptTrackingId . ')');
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
    'confirmed' => 'sr-status--confirmed',
    'completed' => 'sr-status--completed',
    'cancelled' => 'sr-status--cancelled',
];
$statusLabels = [
    'pending'   => $_t('पर्खिँदै', 'Pending'),
    'confirmed' => $_t('पुष्टि', 'Confirmed'),
    'completed' => $_t('सम्पन्न', 'Completed'),
    'cancelled' => $_t('रद्द', 'Cancelled'),
];
$purposes = [
    'account_inquiry' => $_t('खाता जानकारी', 'Account Inquiry'),
    'loan_inquiry'    => $_t('ऋण जानकारी', 'Loan Inquiry'),
    'kyc_update'      => $_t('केवाइसी अपडेट', 'KYC Update'),
    'loan_repayment'  => $_t('ऋण भुक्तानी', 'Loan Repayment'),
    'account_opening' => $_t('खाता खोल्ने', 'Account Opening'),
    'other'           => $_t('अन्य', 'Other'),
];

$siteName  = getSetting('site_name', 'सहकारी');
$pageTitle = $_t('भेटघाट बुक', 'Book Appointment') . ' — ' . $siteName;
$csrfField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken()) . '">';
$extraHead = '<style>
.appt-status{display:inline-block;font-size:.72rem;font-weight:700;padding:2px 9px;border-radius:20px;}
.appt-status--pending{background:color-mix(in srgb,var(--secondary-color) 14%,white);color:var(--secondary-dark);}
.appt-status--confirmed{background:color-mix(in srgb,var(--primary-color) 14%,white);color:var(--primary-dark);}
.appt-status--completed{background:color-mix(in srgb,var(--primary-color) 10%,white);color:var(--primary-dark);}
.appt-status--cancelled{background:#f3f4f6;color:#6b7280;}
</style>';
require __DIR__ . '/includes/chrome.php';
?>
<main class="mp-main">
<div class="mp-container">

  <div class="mp-page-head">
    <h1 class="mem-page-title">
      <i class="fas fa-calendar-check"></i><?php echo $_t('भेटघाट बुक', 'Book Appointment'); ?>
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
    <button class="wf-tab <?= $activeTab==='new'?'active':'' ?>" onclick="apptShowTab(this,'appt-pane-new')" id="apptTabNew">
      <i class="fas fa-plus-circle wf-icon-gap-sm"></i><?php echo $_t('नयाँ बुकिङ', 'New Booking'); ?>
    </button>
    <button class="wf-tab <?= $activeTab==='history'?'active':'' ?>" onclick="apptShowTab(this,'appt-pane-history')" id="apptTabHistory">
      <i class="fas fa-clock-rotate-left wf-icon-gap-sm"></i><?php echo $_t('मेरा भेटघाटहरू', 'My Appointments'); ?> (<?= count($recentAppts) ?>)
    </button>
  </div>

  <!-- ── New Booking ── -->
  <div class="wf-pane <?= $activeTab==='new'?'active':'' ?>" id="appt-pane-new">
    <div class="mem-autofill-banner">
      <i class="fas fa-wand-magic-sparkles"></i>
      <div><?php echo $_t('तपाईंको नाम, फोन, email — <strong>KYC/profile बाट auto-fill</strong> भएको छ।', 'Your name, phone and email are <strong>auto-filled from KYC/profile</strong>.'); ?></div>
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
        <label class="mem-form-label"><?php echo $_t('उद्देश्य', 'Purpose'); ?> <span class="mem-form-required">*</span></label>
        <select name="purpose" class="mem-form-control" required>
          <option value="">— <?php echo $_t('उद्देश्य छान्नुहोस्', 'Select purpose'); ?> —</option>
          <?php foreach ($purposes as $val => $label): ?>
          <option value="<?= $val ?>" <?= ($_POST['purpose'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="mem-form-group">
        <label class="mem-form-label"><?php echo $_t('उद्देश्य विवरण', 'Purpose Details'); ?></label>
        <textarea name="purpose_detail" class="mem-form-control" rows="2" placeholder="<?php echo $_t('संक्षेपमा बताउनुहोस्...', 'Briefly describe...'); ?>"><?= htmlspecialchars($_POST['purpose_detail'] ?? '') ?></textarea>
      </div>

      <div class="mem-form-row mem-form-row-2">
        <div class="mem-form-group">
          <label class="mem-form-label"><i class="fas fa-calendar ico-primary"></i><?php echo $_t('मनपर्ने मिति', 'Preferred Date'); ?> <span class="mem-form-required">*</span></label>
          <input type="date" name="preferred_date" class="mem-form-control" required min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($_POST['preferred_date'] ?? '') ?>">
        </div>
        <div class="mem-form-group">
          <label class="mem-form-label"><i class="fas fa-clock ico-primary"></i><?php echo $_t('मनपर्ने समय', 'Preferred Time'); ?> <span class="mem-form-required">*</span></label>
          <?php $selTime = trim((string)($_POST['preferred_time'] ?? '')); ?>
          <select name="preferred_time" class="mem-form-control" required>
            <option value="">— <?php echo $_t('समय छान्नुहोस्', 'Select time'); ?> —</option>
            <?php foreach ($timeOptions as $tv => $tl): ?>
            <option value="<?= htmlspecialchars($tv) ?>" <?= $selTime === $tv ? 'selected' : '' ?>><?= htmlspecialchars($tl) ?></option>
            <?php endforeach; ?>
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

      <button type="submit" class="mem-submit-btn">
        <i class="fas fa-calendar-check"></i> <?php echo $_t('भेटघाट बुक गर्नुहोस्', 'Book Appointment'); ?>
      </button>
    </form>
  </div>

  <!-- ── History ── -->
  <div class="wf-pane <?= $activeTab==='history'?'active':'' ?>" id="appt-pane-history">
    <?php if ($successMsg): ?>
    <div class="mem-alert mem-alert-success">
      <i class="fas fa-circle-check"></i><div><?= htmlspecialchars($successMsg) ?></div>
    </div>
    <?php endif; ?>

    <?php if (empty($recentAppts)): ?>
    <div class="mp-empty">
      <i class="fas fa-calendar-xmark mp-empty-icon"></i>
      <div class="mp-empty-title"><?php echo $_t('कुनै भेटघाट छैन', 'No appointments yet'); ?></div>
      <div class="mp-empty-hint"><?php echo $_t('"नयाँ बुकिङ" बाट भेटघाट बुक गर्नुहोस्।', 'Use "New Booking" tab to book.'); ?></div>
    </div>
    <?php else: ?>
    <?php foreach ($recentAppts as $ap):
        $stCls = $statusColors[$ap['status']] ?? 'appt-status--pending';
        $stLbl = $statusLabels[$ap['status']] ?? htmlspecialchars($ap['status']);
        $purposeLabel = $purposes[$ap['purpose']] ?? htmlspecialchars($ap['purpose'] ?? '');
    ?>
    <div class="recent-card">
      <div>
        <div class="mp-list-title"><?= $purposeLabel ?></div>
        <div class="mp-list-meta">
          <i class="fas fa-calendar fa-xs ico-mr"></i><?= htmlspecialchars($ap['preferred_date'] ?? '') ?>
          <?php if (!empty($ap['preferred_time'])): ?> &nbsp;·&nbsp; <i class="fas fa-clock fa-xs ico-mr"></i><?= htmlspecialchars($ap['preferred_time']) ?><?php endif; ?>
        </div>
      </div>
      <div class="mp-status-row">
        <span class="mem-tracking-id"><?= htmlspecialchars($ap['tracking_id']) ?></span>
        <span class="appt-status <?= $stCls ?>"><?= $stLbl ?></span>
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
function apptShowTab(btn, paneId) {
    document.querySelectorAll('.wf-tab').forEach(function(t){ t.classList.remove('active'); });
    document.querySelectorAll('.wf-pane').forEach(function(p){ p.classList.remove('active'); });
    btn.classList.add('active');
    var pane = document.getElementById(paneId);
    if (pane) pane.classList.add('active');
}
</script>
<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
