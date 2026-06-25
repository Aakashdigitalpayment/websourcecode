<?php
/**
 * Member Portal — गुनासो दर्ता (Native Grievance Form)
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

/* Recent grievances */
$recentGrievances = [];
try {
    $rg = $db->prepare("SELECT tracking_id, category, subject, status, is_anonymous, created_at FROM grievances WHERE member_id=? ORDER BY created_at DESC LIMIT 10");
    $rg->execute([$memberId]);
    $recentGrievances = $rg->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

/* ── Handle POST ── */
$successMsg = '';
$errorMsg   = '';
$trackingId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    if (!verifyCSRFToken()) {
        $errorMsg = $_t('सुरक्षा जाँच असफल।', 'Security check failed.');
    } else {
        $category     = trim((string)($_POST['category'] ?? 'other'));
        $subject      = trim((string)($_POST['subject'] ?? ''));
        $description  = trim((string)($_POST['description'] ?? ''));
        $is_anonymous = isset($_POST['is_anonymous']) ? 1 : 0;

        if (!$subject)      $errorMsg = $_t('विषय अनिवार्य छ।', 'Subject is required.');
        elseif (!$description) $errorMsg = $_t('विवरण अनिवार्य छ।', 'Description is required.');

        if (!$errorMsg) {
            try {
                $trackingId = 'GRV-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
                $attachment = '';
                if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                    if (function_exists('uploadFile')) {
                        $up = uploadFile($_FILES['attachment'], 'grievances');
                        $attachment = $up['path'] ?? '';
                    }
                }
                $saveName  = $is_anonymous ? '' : $memName;
                $saveMid   = $is_anonymous ? '' : (string)$memberId;
                $savePhone = $is_anonymous ? '' : $rPhone;
                $saveEmail = $is_anonymous ? '' : $rEmail;

                $stmt = $db->prepare("INSERT INTO grievances (tracking_id, name, member_id, phone, email, category, subject, description, attachment, is_anonymous, status) VALUES (?,?,?,?,?,?,?,?,?,?,'pending')");
                $stmt->execute([$trackingId, $saveName, $saveMid, $savePhone, $saveEmail, $category, $subject, $description, $attachment, $is_anonymous]);

                /* Reload history */
                $rg2 = $db->prepare("SELECT tracking_id, category, subject, status, is_anonymous, created_at FROM grievances WHERE member_id=? ORDER BY created_at DESC LIMIT 10");
                $rg2->execute([$memberId]);
                $recentGrievances = $rg2->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $successMsg = $_t('गुनासो सफलतापूर्वक दर्ता भयो! Tracking ID: ', 'Grievance submitted! Tracking ID: ') . $trackingId;
                if (function_exists('sendAdminNotification')) {
                    require_once __DIR__ . '/../includes/notifications.php';
                    sendAdminNotification('grievance', ['विषय' => $subject, 'वर्ग' => $category, 'नाम' => $saveName ?: 'गुमनाम'], $trackingId);
                }
                logSecurityEvent('grievance_filed', 'Member portal: ' . ($is_anonymous ? 'Anonymous' : $memName) . ' (' . $trackingId . ')');
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

$categories = [
    'service_quality' => $_t('सेवा गुणस्तर', 'Service Quality'),
    'staff_behavior'  => $_t('कर्मचारी व्यवहार', 'Staff Behavior'),
    'account_issue'   => $_t('खाता समस्या', 'Account Issue'),
    'loan_issue'      => $_t('ऋण समस्या', 'Loan Issue'),
    'delay'           => $_t('ढिलाइ', 'Delay/Wait Time'),
    'digital_service' => $_t('डिजिटल सेवा', 'Digital Service'),
    'other'           => $_t('अन्य', 'Other'),
];
$statusColors = [
    'pending'      => 'sr-status--pending',
    'under_review' => 'sr-status--processing',
    'resolved'     => 'sr-status--confirmed',
    'rejected'     => 'sr-status--cancelled',
    'closed'       => 'sr-status--completed',
];
$statusLabels = [
    'pending'      => $_t('पर्खिँदै', 'Pending'),
    'under_review' => $_t('समीक्षामा', 'Under Review'),
    'resolved'     => $_t('समाधान', 'Resolved'),
    'rejected'     => $_t('अस्वीकृत', 'Rejected'),
    'closed'       => $_t('बन्द', 'Closed'),
];

$siteName  = getSetting('site_name', 'सहकारी');
$pageTitle = $_t('गुनासो दर्ता', 'File Grievance') . ' — ' . $siteName;
$csrfField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken()) . '">';
require __DIR__ . '/includes/chrome.php';
?>
<main class="mp-main">
<div class="mp-container">

  <div class="mp-page-head">
    <h1 class="mem-page-title">
      <i class="fas fa-comment-exclamation"></i><?php echo $_t('गुनासो दर्ता', 'File Grievance'); ?>
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
    <button class="wf-tab <?= $activeTab==='new'?'active':'' ?>" onclick="grvShowTab(this,'grv-pane-new')" id="grvTabNew">
      <i class="fas fa-plus-circle"></i><?php echo $_t('नयाँ गुनासो', 'New Grievance'); ?>
    </button>
    <button class="wf-tab <?= $activeTab==='history'?'active':'' ?>" onclick="grvShowTab(this,'grv-pane-history')" id="grvTabHistory">
      <i class="fas fa-clock-rotate-left"></i><?php echo $_t('मेरा गुनासोहरू', 'My Grievances'); ?> (<?= count($recentGrievances) ?>)
    </button>
  </div>

  <!-- ── New Grievance ── -->
  <div class="wf-pane <?= $activeTab==='new'?'active':'' ?>" id="grv-pane-new">
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

      <div class="mem-form-row mem-form-row-2">
        <div class="mem-form-group">
          <label class="mem-form-label"><?php echo $_t('गुनासोको वर्ग', 'Category'); ?> <span class="mem-form-required">*</span></label>
          <select name="category" class="mem-form-control" required>
            <?php foreach ($categories as $val => $lbl): ?>
            <option value="<?= $val ?>" <?= ($_POST['category'] ?? 'other') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mem-form-group grv-anon-wrap">
          <label class="grv-anon-label">
            <input type="checkbox" name="is_anonymous" value="1" <?= isset($_POST['is_anonymous']) ? 'checked' : '' ?> class="grv-anon-check">
            <?php echo $_t('गुमनाम रूपमा पेश गर्नुहोस्', 'Submit anonymously'); ?>
          </label>
        </div>
      </div>

      <div class="mem-form-group">
        <label class="mem-form-label"><?php echo $_t('विषय', 'Subject'); ?> <span class="mem-form-required">*</span></label>
        <input type="text" name="subject" class="mem-form-control" required maxlength="300"
               placeholder="<?php echo $_t('गुनासोको विषय संक्षेपमा लेख्नुहोस्', 'Brief subject of your grievance'); ?>"
               value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>">
      </div>

      <div class="mem-form-group">
        <label class="mem-form-label"><?php echo $_t('विस्तृत विवरण', 'Detailed Description'); ?> <span class="mem-form-required">*</span></label>
        <textarea name="description" class="mem-form-control" rows="5" required maxlength="8000"
                  placeholder="<?php echo $_t('तपाईंको गुनासोको विस्तृत विवरण लेख्नुहोस्...', 'Describe your grievance in detail...'); ?>"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
      </div>

      <div class="mem-form-group">
        <label class="mem-form-label"><i class="fas fa-paperclip ico-primary"></i><?php echo $_t('संलग्न फाइल (Optional)', 'Attachment (Optional)'); ?></label>
        <input type="file" name="attachment" class="mem-form-control" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
        <div class="mp-file-hint"><?php echo $_t('JPG, PNG, PDF, DOC — अधिकतम 5MB', 'JPG, PNG, PDF, DOC — max 5MB'); ?></div>
      </div>

      <button type="submit" class="mem-submit-btn">
        <i class="fas fa-paper-plane"></i> <?php echo $_t('गुनासो पेश गर्नुहोस्', 'Submit Grievance'); ?>
      </button>
    </form>
  </div>

  <!-- ── History ── -->
  <div class="wf-pane <?= $activeTab==='history'?'active':'' ?>" id="grv-pane-history">
    <?php if ($successMsg): ?>
    <div class="mem-alert mem-alert-success">
      <i class="fas fa-circle-check"></i><div><?= htmlspecialchars($successMsg) ?></div>
    </div>
    <?php endif; ?>

    <?php if (empty($recentGrievances)): ?>
    <div class="mp-empty">
      <i class="fas fa-comment-slash mp-empty-icon"></i>
      <div class="mp-empty-title"><?php echo $_t('कुनै गुनासो छैन', 'No grievances yet'); ?></div>
      <div class="mp-empty-hint"><?php echo $_t('"नयाँ गुनासो" tab बाट गुनासो दर्ता गर्नुहोस्।', 'Use "New Grievance" tab to file.'); ?></div>
    </div>
    <?php else: ?>
    <?php foreach ($recentGrievances as $gr):
        $stCls = $statusColors[$gr['status']] ?? 'sr-status--pending';
        $stLbl = $statusLabels[$gr['status']] ?? htmlspecialchars($gr['status']);
        $catLabel = $categories[$gr['category']] ?? htmlspecialchars($gr['category'] ?? '');
    ?>
    <div class="recent-card">
      <div class="mp-flex-fill">
        <div class="mp-list-title-ellipsis"><?= htmlspecialchars($gr['subject']) ?></div>
        <div class="mp-list-meta">
          <?= $catLabel ?>
          <?php if ($gr['is_anonymous']): ?> &nbsp;·&nbsp; <i class="fas fa-user-secret fa-xs"></i> <?php echo $_t('गुमनाम', 'Anonymous'); ?><?php endif; ?>
          &nbsp;·&nbsp; <?= date('Y-m-d', strtotime($gr['created_at'])) ?>
        </div>
      </div>
      <div class="mp-status-row-end">
        <span class="mem-tracking-id"><?= htmlspecialchars($gr['tracking_id']) ?></span>
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
function grvShowTab(btn, paneId) {
    document.querySelectorAll('.wf-tab').forEach(function(t){ t.classList.remove('active'); });
    document.querySelectorAll('.wf-pane').forEach(function(p){ p.classList.remove('active'); });
    btn.classList.add('active');
    var pane = document.getElementById(paneId);
    if (pane) pane.classList.add('active');
}
</script>
<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
