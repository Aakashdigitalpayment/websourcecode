<?php
/**
 * Member Portal — डिजिटल सेवा अनुरोध (Native Digital Service Form)
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
$rPhone = $memPhone ?: trim((string)($kycRow['phone'] ?? ''));
$rEmail = $memEmail ?: trim((string)($kycRow['email'] ?? ''));

/* Service types */
$serviceTypes = [
    'missed_call_banking' => ['np' => 'मिस्ड कल बैंकिङ',        'en' => 'Missed Call Banking',     'icon' => 'fa-phone-volume'],
    'statement_request'   => ['np' => 'स्टेटमेन्ट अनुरोध',       'en' => 'Statement Request',        'icon' => 'fa-file-invoice'],
    'bill_payment'        => ['np' => 'बिल भुक्तानी सहयोग',      'en' => 'Bill Payment Support',     'icon' => 'fa-receipt'],
    'mobile_recharge'     => ['np' => 'मोबाइल रिचार्ज अनुरोध',   'en' => 'Mobile Recharge Request',  'icon' => 'fa-mobile-screen'],
    'internet_banking'    => ['np' => 'इन्टरनेट/मोबाइल बैंकिङ', 'en' => 'Internet/Mobile Banking',  'icon' => 'fa-laptop-code'],
    'sms_alert'           => ['np' => 'SMS अलर्ट सेवा',          'en' => 'SMS Alert Service',        'icon' => 'fa-bell'],
    'card_service'        => ['np' => 'कार्ड सेवा',              'en' => 'Card Service',             'icon' => 'fa-credit-card'],
    'qr_payment'          => ['np' => 'QR/डिजिटल भुक्तानी',     'en' => 'QR / Digital Payment',     'icon' => 'fa-qrcode'],
    'share_refund'        => ['np' => 'शेयर फिर्ता (Refund)',    'en' => 'Share Refund',             'icon' => 'fa-money-bill-transfer'],
    'share_increase'      => ['np' => 'शेयर वृद्धि (Increase)',  'en' => 'Share Increase',           'icon' => 'fa-chart-line'],
    'other'               => ['np' => 'अन्य डिजिटल सेवा',        'en' => 'Other Digital Service',    'icon' => 'fa-headset'],
];

/* Recent digital service requests */
$recentRequests = [];
try {
    $rr = $db->prepare("SELECT tracking_id, service_type, service_type_np, status, created_at FROM digital_service_requests WHERE member_id=? ORDER BY created_at DESC LIMIT 10");
    $rr->execute([$memberId]);
    $recentRequests = $rr->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

/* ── Handle POST ── */
$successMsg = '';
$errorMsg   = '';
$trackingId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    if (!verifyCSRFToken()) {
        $errorMsg = $_t('सुरक्षा जाँच असफल।', 'Security check failed.');
    } else {
        $serviceType      = trim((string)($_POST['service_type'] ?? ''));
        $accountNumber    = trim((string)($_POST['account_number'] ?? ''));
        $statementFrom    = trim((string)($_POST['statement_from'] ?? ''));
        $statementTo      = trim((string)($_POST['statement_to'] ?? ''));
        $billerName       = trim((string)($_POST['biller_name'] ?? ''));
        $billReference    = trim((string)($_POST['bill_reference'] ?? ''));
        $rechargeNumber   = preg_replace('/[^0-9]/', '', (string)($_POST['recharge_number'] ?? ''));
        $rechargeAmount   = (float)($_POST['recharge_amount'] ?? 0);
        $serviceAmount    = (float)($_POST['service_amount'] ?? 0);
        $requestDetails   = trim((string)($_POST['request_details'] ?? ''));
        $preferredContact = trim((string)($_POST['preferred_contact'] ?? 'phone'));

        if (!$serviceType || !isset($serviceTypes[$serviceType])) {
            $errorMsg = $_t('सेवा प्रकार छान्नुहोस्।', 'Please select a service type.');
        }

        if (!$errorMsg) {
            try {
                $trackingId = 'DSR-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
                $attachment = '';
                if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                    if (function_exists('uploadFile')) {
                        $up = uploadFile($_FILES['attachment'], 'digital_services');
                        $attachment = $up['path'] ?? '';
                    }
                }
                $stmt = $db->prepare("INSERT INTO digital_service_requests
                    (tracking_id, requester_name, member_id, phone, email,
                     service_type, service_type_np, account_number,
                     statement_from, statement_to, biller_name, bill_reference,
                     recharge_number, recharge_amount, service_amount, request_details, attachment, preferred_contact)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([
                    $trackingId, $memName, $memberId, $rPhone, $rEmail,
                    $serviceType, $serviceTypes[$serviceType]['np'], $accountNumber,
                    $statementFrom ?: null, $statementTo ?: null,
                    $billerName, $billReference,
                    $rechargeNumber ?: null, $rechargeAmount ?: null,
                    $serviceAmount ?: null, $requestDetails, $attachment,
                    in_array($preferredContact, ['phone','email','branch'], true) ? $preferredContact : 'phone',
                ]);
                /* Reload history */
                $rr2 = $db->prepare("SELECT tracking_id, service_type, service_type_np, status, created_at FROM digital_service_requests WHERE member_id=? ORDER BY created_at DESC LIMIT 10");
                $rr2->execute([$memberId]);
                $recentRequests = $rr2->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $successMsg = $_t('डिजिटल सेवा अनुरोध सफलतापूर्वक पेश भयो! Tracking ID: ', 'Digital service request submitted! Tracking ID: ') . $trackingId;
                if (function_exists('sendAdminNotification')) {
                    require_once __DIR__ . '/../includes/notifications.php';
                    sendAdminNotification('digital_service_request', [
                        'नाम' => $memName, 'सेवा' => $serviceTypes[$serviceType]['np'], 'सम्पर्क' => $preferredContact,
                    ], $trackingId);
                }
                logSecurityEvent('digital_service_request', 'Member portal: ' . $memName . ' (' . $trackingId . ')');
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
    'processing' => 'sr-status--processing',
    'completed'  => 'sr-status--confirmed',
    'rejected'   => 'sr-status--cancelled',
];
$statusLabels = [
    'pending'    => $_t('पर्खिँदै', 'Pending'),
    'processing' => $_t('प्रक्रिया', 'Processing'),
    'completed'  => $_t('सम्पन्न', 'Completed'),
    'rejected'   => $_t('अस्वीकृत', 'Rejected'),
];

$siteName  = getSetting('site_name', 'सहकारी');
$pageTitle = $_t('डिजिटल सेवा अनुरोध', 'Digital Service Request') . ' — ' . $siteName;
$csrfField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken()) . '">';
require __DIR__ . '/includes/chrome.php';
?>
<main class="mp-main">
<div class="mp-container">

  <div class="mp-page-head">
    <h1 class="mem-page-title">
      <i class="fas fa-laptop-code"></i><?php echo $_t('डिजिटल सेवा अनुरोध', 'Digital Service Request'); ?>
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
    <button class="wf-tab <?= $activeTab==='new'?'active':'' ?>" onclick="dsShowTab(this,'ds-pane-new')" id="dsTabNew">
      <i class="fas fa-plus-circle"></i><?php echo $_t('नयाँ अनुरोध', 'New Request'); ?>
    </button>
    <button class="wf-tab <?= $activeTab==='history'?'active':'' ?>" onclick="dsShowTab(this,'ds-pane-history')" id="dsTabHistory">
      <i class="fas fa-clock-rotate-left"></i><?php echo $_t('मेरा अनुरोधहरू', 'My Requests'); ?> (<?= count($recentRequests) ?>)
    </button>
  </div>

  <!-- ── New Request ── -->
  <div class="wf-pane <?= $activeTab==='new'?'active':'' ?>" id="ds-pane-new">
    <div class="mem-autofill-banner">
      <i class="fas fa-wand-magic-sparkles"></i>
      <div><?php echo $_t('तपाईंको जानकारी — <strong>KYC/profile बाट auto-fill</strong> भएको छ।', 'Your details are <strong>auto-filled from KYC/profile</strong>.'); ?></div>
    </div>

    <form method="POST" enctype="multipart/form-data">
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

      <!-- Service Type -->
      <div class="mem-form-group">
        <label class="mem-form-label"><?php echo $_t('सेवा प्रकार', 'Service Type'); ?> <span class="mem-form-required">*</span></label>
        <select name="service_type" class="mem-form-control" required id="dsServiceType" onchange="dsToggleFields(this.value)">
          <option value="">— <?php echo $_t('सेवा छान्नुहोस्', 'Select a service'); ?> —</option>
          <?php foreach ($serviceTypes as $val => $st): ?>
          <option value="<?= $val ?>" <?= ($_POST['service_type'] ?? '') === $val ? 'selected' : '' ?>>
            <?= isEnglish() ? $st['en'] : $st['np'] ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Account Number (always useful) -->
      <div class="mem-form-group">
        <label class="mem-form-label"><?php echo $_t('खाता नम्बर', 'Account Number'); ?></label>
        <input type="text" name="account_number" class="mem-form-control" placeholder="<?php echo $_t('तपाईंको खाता नम्बर (यदि लागू छ भने)', 'Your account number (if applicable)'); ?>" value="<?= htmlspecialchars($_POST['account_number'] ?? '') ?>">
      </div>

      <!-- Statement date range — shown for statement_request -->
      <div id="ds-field-statement" style="display:none;">
        <div class="mem-form-row mem-form-row-2">
          <div class="mem-form-group">
            <label class="mem-form-label"><?php echo $_t('स्टेटमेन्ट मिति (देखि)', 'Statement From'); ?></label>
            <input type="date" name="statement_from" class="mem-form-control" value="<?= htmlspecialchars($_POST['statement_from'] ?? '') ?>">
          </div>
          <div class="mem-form-group">
            <label class="mem-form-label"><?php echo $_t('स्टेटमेन्ट मिति (सम्म)', 'Statement To'); ?></label>
            <input type="date" name="statement_to" class="mem-form-control" value="<?= htmlspecialchars($_POST['statement_to'] ?? '') ?>">
          </div>
        </div>
      </div>

      <!-- Bill fields — shown for bill_payment -->
      <div id="ds-field-bill" style="display:none;">
        <div class="mem-form-row mem-form-row-2">
          <div class="mem-form-group">
            <label class="mem-form-label"><?php echo $_t('बिलर नाम', 'Biller Name'); ?></label>
            <input type="text" name="biller_name" class="mem-form-control" placeholder="NEA, Nepal Telecom..." value="<?= htmlspecialchars($_POST['biller_name'] ?? '') ?>">
          </div>
          <div class="mem-form-group">
            <label class="mem-form-label"><?php echo $_t('बिल Reference', 'Bill Reference'); ?></label>
            <input type="text" name="bill_reference" class="mem-form-control" placeholder="<?php echo $_t('ग्राहक नम्बर / SC नम्बर', 'Customer / SC number'); ?>" value="<?= htmlspecialchars($_POST['bill_reference'] ?? '') ?>">
          </div>
        </div>
      </div>

      <!-- Recharge fields — shown for mobile_recharge -->
      <div id="ds-field-recharge" style="display:none;">
        <div class="mem-form-row mem-form-row-2">
          <div class="mem-form-group">
            <label class="mem-form-label"><?php echo $_t('मोबाइल नम्बर', 'Mobile Number'); ?></label>
            <input type="tel" name="recharge_number" class="mem-form-control" maxlength="10" placeholder="98XXXXXXXX" value="<?= htmlspecialchars($_POST['recharge_number'] ?? '') ?>">
          </div>
          <div class="mem-form-group">
            <label class="mem-form-label"><?php echo $_t('रिचार्ज रकम (रु.)', 'Recharge Amount (Rs.)'); ?></label>
            <input type="number" name="recharge_amount" class="mem-form-control" min="10" value="<?= htmlspecialchars($_POST['recharge_amount'] ?? '') ?>">
          </div>
        </div>
      </div>

      <!-- Share amount — shown for share_refund / share_increase -->
      <div id="ds-field-share" style="display:none;">
        <div class="mem-form-group">
          <label class="mem-form-label"><?php echo $_t('रकम (रु.)', 'Amount (Rs.)'); ?></label>
          <input type="number" name="service_amount" class="mem-form-control" min="0" value="<?= htmlspecialchars($_POST['service_amount'] ?? '') ?>">
        </div>
      </div>

      <!-- Details -->
      <div class="mem-form-group">
        <label class="mem-form-label"><?php echo $_t('अतिरिक्त विवरण', 'Additional Details'); ?></label>
        <textarea name="request_details" class="mem-form-control" rows="3" placeholder="<?php echo $_t('थप जानकारी वा निर्देशन...', 'Additional info or instructions...'); ?>"><?= htmlspecialchars($_POST['request_details'] ?? '') ?></textarea>
      </div>

      <!-- Preferred Contact -->
      <div class="mem-form-group">
        <label class="mem-form-label"><?php echo $_t('सम्पर्क माध्यम', 'Preferred Contact'); ?></label>
        <select name="preferred_contact" class="mem-form-control">
          <option value="phone" <?= ($_POST['preferred_contact'] ?? 'phone') === 'phone' ? 'selected' : '' ?>><?php echo $_t('फोन', 'Phone'); ?></option>
          <option value="email" <?= ($_POST['preferred_contact'] ?? '') === 'email' ? 'selected' : '' ?>>Email</option>
          <option value="branch" <?= ($_POST['preferred_contact'] ?? '') === 'branch' ? 'selected' : '' ?>><?php echo $_t('शाखा भ्रमण', 'Branch Visit'); ?></option>
        </select>
      </div>

      <!-- Attachment -->
      <div class="mem-form-group">
        <label class="mem-form-label"><i class="fas fa-paperclip ico-primary"></i><?php echo $_t('संलग्न फाइल (Optional)', 'Attachment (Optional)'); ?></label>
        <input type="file" name="attachment" class="mem-form-control" accept=".jpg,.jpeg,.png,.pdf">
        <div class="mp-file-hint"><?php echo $_t('JPG, PNG, PDF — अधिकतम 5MB', 'JPG, PNG, PDF — max 5MB'); ?></div>
      </div>

      <button type="submit" class="mem-submit-btn">
        <i class="fas fa-paper-plane"></i> <?php echo $_t('अनुरोध पेश गर्नुहोस्', 'Submit Request'); ?>
      </button>
    </form>
  </div>

  <!-- ── History ── -->
  <div class="wf-pane <?= $activeTab==='history'?'active':'' ?>" id="ds-pane-history">
    <?php if ($successMsg): ?>
    <div class="mem-alert mem-alert-success">
      <i class="fas fa-circle-check"></i><div><?= htmlspecialchars($successMsg) ?></div>
    </div>
    <?php endif; ?>

    <?php if (empty($recentRequests)): ?>
    <div class="mp-empty">
      <i class="fas fa-laptop-code mp-empty-icon"></i>
      <div class="mp-empty-title"><?php echo $_t('कुनै अनुरोध छैन', 'No requests yet'); ?></div>
      <div class="mp-empty-hint"><?php echo $_t('"नयाँ अनुरोध" tab बाट सेवा माग्नुहोस्।', 'Use "New Request" tab to submit.'); ?></div>
    </div>
    <?php else: ?>
    <?php foreach ($recentRequests as $req):
        $stCls = $statusColors[$req['status']] ?? 'sr-status--pending';
        $stLbl = $statusLabels[$req['status']] ?? htmlspecialchars($req['status']);
        $svcLabel = isEnglish()
            ? ($serviceTypes[$req['service_type']]['en'] ?? htmlspecialchars($req['service_type']))
            : htmlspecialchars($req['service_type_np'] ?: $req['service_type']);
    ?>
    <div class="recent-card">
      <div>
        <div class="mp-list-title"><?= $svcLabel ?></div>
        <div class="mp-list-meta"><?= date('Y-m-d', strtotime($req['created_at'])) ?></div>
      </div>
      <div class="mp-status-row">
        <span class="mem-tracking-id"><?= htmlspecialchars($req['tracking_id']) ?></span>
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
function dsShowTab(btn, paneId) {
    document.querySelectorAll('.wf-tab').forEach(function(t){ t.classList.remove('active'); });
    document.querySelectorAll('.wf-pane').forEach(function(p){ p.classList.remove('active'); });
    btn.classList.add('active');
    var pane = document.getElementById(paneId);
    if (pane) pane.classList.add('active');
}
function dsToggleFields(val) {
    document.getElementById('ds-field-statement').style.display = val === 'statement_request' ? '' : 'none';
    document.getElementById('ds-field-bill').style.display      = val === 'bill_payment'       ? '' : 'none';
    document.getElementById('ds-field-recharge').style.display  = val === 'mobile_recharge'    ? '' : 'none';
    document.getElementById('ds-field-share').style.display     = (val === 'share_refund' || val === 'share_increase') ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', function(){
    var sel = document.getElementById('dsServiceType');
    if (sel) dsToggleFields(sel.value);
});
</script>
<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
