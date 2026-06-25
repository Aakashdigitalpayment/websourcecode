<?php
/**
 * Member Portal — सदस्यता प्रमाणपत्र (Membership Certificate)
 * Printable + browser PDF download via print dialog
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

/* KYC data */
$kycRow = null;
try {
    $kycLinkId = (int)($mem['kyc_application_id'] ?? 0);
    if ($kycLinkId > 0) {
        $ks = $db->prepare("SELECT * FROM kyc_applications WHERE id=? LIMIT 1");
        $ks->execute([$kycLinkId]);
        $kycRow = $ks->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$kycRow) {
        $memEmail = trim((string)($mem['email'] ?? ''));
        $memPhone = preg_replace('/[^0-9]/', '', (string)($mem['phone'] ?? ''));
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

$fullName    = trim((string)($kycRow['full_name']       ?? $mem['name']             ?? ''));
$sadasyata   = trim((string)($kycRow['member_id']       ?? $mem['sadasyata_number'] ?? ''));
$phone       = trim((string)($kycRow['mobile']          ?? $mem['phone']            ?? ''));
$email       = trim((string)($kycRow['email']           ?? $mem['email']            ?? ''));
$address     = trim((string)($kycRow['permanent_address']?? ''));
$fatherName  = trim((string)($kycRow['father_name']     ?? ''));
$dob         = trim((string)($kycRow['date_of_birth_ad'] ?? $kycRow['date_of_birth'] ?? ''));
$citizenship = trim((string)($kycRow['citizenship_no']  ?? ''));
$photoPath   = trim((string)($kycRow['photo']           ?? $mem['avatar_url']       ?? ''));
$approvedDate= trim((string)($kycRow['approved_at']     ?? $mem['created_at']       ?? ''));
$accountType = trim((string)($kycRow['account_type']    ?? ''));

/* Site settings */
$siteName    = getSetting('site_name', 'सहकारी');
$siteNameEn  = getSetting('site_name_en', 'Cooperative');
$sitePhone   = getSetting('phone', '');
$siteEmail   = getSetting('email', '');
$siteAddress = getSetting('address', '');
$siteLogo    = function_exists('getLocalizedLogoPath')
    ? getLocalizedLogoPath('assets/images/logo.png')
    : getSetting('site_logo', getSetting('logo', 'assets/images/logo.png'));

/* Photo URL */
$photoUrl = '';
if ($photoPath) {
    $photoUrl = preg_match('#^https?://#', $photoPath) ? $photoPath : SITE_URL . ltrim($photoPath, '/');
}

/* Verify QR URL */
$verifyUrl = SITE_URL . 'verify.php?id=' . urlencode($sadasyata);
$qrUrl     = 'https://api.qrserver.com/v1/create-qr-code/?data=' . urlencode($verifyUrl) . '&size=120x120&margin=4';

/* Issue / expiry */
$issueDate  = $approvedDate ? date('Y F d', strtotime($approvedDate)) : date('Y F d');
$issueDateNp= $approvedDate ? date('Y-m-d', strtotime($approvedDate)) : date('Y-m-d');
$logoUrl    = SITE_URL . ltrim($siteLogo, '/');

$pageTitle = $_t('सदस्यता प्रमाणपत्र', 'Membership Certificate') . ' — ' . $siteName;
$extraHead = <<<HTML
<style>
@media print {
  .cert-noprint { display:none !important; }
  body { padding-bottom:0 !important; }
  .mp-topbar, .mem-nav, .mp-bottom-nav { display:none !important; }
  .mp-container { padding:0 !important; }
.cert-page { box-shadow:none !important; border:2px solid color-mix(in srgb, var(--primary-color) 22%, var(--gray-300)) !important; }
  @page { size: A4; margin: 10mm; }
}
.cert-page {
  background:white; max-width:680px; margin:0 auto;
  border:3px double var(--primary-color);
  border-radius:8px;
  box-shadow:0 8px 32px rgba(var(--primary-rgb),.12);
  position:relative; overflow:hidden;
}
.cert-top-band { background:var(--primary-color); color:var(--text-on-primary); padding:12px 24px; display:flex; align-items:center; gap:18px; }
.cert-logo { height:auto; width:auto; max-height:52px; max-width:200px; border-radius:10px; background:rgba(255,255,255,0.12); padding:6px 10px; object-fit:contain; }
.cert-logo-placeholder { width:52px;height:52px;border-radius:10px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:1.4rem; }
.cert-site-name { font-size:1.1rem; font-weight:800; line-height:1.2; }
.cert-site-sub  { font-size:.78rem; opacity:.85; margin-top:2px; }
.cert-body { padding:22px 28px; }
.cert-title { text-align:center; margin-bottom:20px; }
.cert-title h2 { font-size:1.25rem; font-weight:800; color:var(--primary-color); letter-spacing:.05em; border-bottom:2px solid var(--primary-color); display:inline-block; padding-bottom:4px; }
.cert-title p { font-size:.78rem; color:var(--text-light); margin-top:4px; }
.cert-member-row { display:flex; gap:20px; margin-bottom:20px; align-items:flex-start; }
.cert-photo { width:90px; height:110px; border:2px solid color-mix(in srgb, var(--primary-color) 14%, var(--gray-200)); border-radius:6px; object-fit:cover; flex-shrink:0; background:color-mix(in srgb, var(--primary-color) 8%, white); display:flex; align-items:center; justify-content:center; }
.cert-photo img { width:86px; height:106px; object-fit:cover; border-radius:4px; }
.cert-details { flex:1; }
.cert-field { margin-bottom:8px; display:flex; gap:4px; font-size:.88rem; }
.cert-field-label { min-width:140px; color:var(--text-light); font-weight:600; flex-shrink:0; }
.cert-field-value { color:var(--text-color); font-weight:700; border-bottom:1px solid color-mix(in srgb, var(--primary-color) 14%, var(--gray-200)); flex:1; padding-bottom:2px; }
.cert-footer { background:color-mix(in srgb, var(--primary-color) 8%, white); border-top:2px solid var(--primary-color); padding:14px 28px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.cert-seal { width:80px; height:80px; border-radius:50%; border:3px solid var(--primary-color); display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; font-size:.55rem; font-weight:700; color:var(--primary-color); line-height:1.2; }
.cert-qr img { display:block; }
.cert-sign-line { border-top:1.5px solid var(--text-color); margin-top:40px; padding-top:5px; font-size:.75rem; color:var(--text-light); text-align:center; min-width:100px; }
.cert-watermark { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%) rotate(-30deg); font-size:5rem; font-weight:900; color:rgba(var(--primary-rgb),.04); pointer-events:none; white-space:nowrap; z-index:0; }
.cert-ribbon { position:absolute; top:16px; right:-20px; background:var(--secondary-color); color:var(--text-on-secondary); font-size:.65rem; font-weight:700; padding:4px 28px; transform:rotate(35deg); letter-spacing:.08em; }
.cert-actions { display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px; }
.cert-page-title { font-size:1.45rem;font-weight:700;color:var(--primary-color);margin:0;line-height:1.4; }
.cert-btn-row { display:flex;gap:10px;flex-wrap:wrap; }
.cert-btn { padding:9px 20px;border-radius:8px;font-family:inherit;font-size:.88rem;font-weight:700;display:flex;align-items:center;gap:6px;text-decoration:none; }
.cert-btn.primary { background:var(--primary-color);color:var(--text-on-primary);border:none;cursor:pointer; }
.cert-btn.outline { background:white;color:var(--primary-color);border:2px solid var(--primary-color); }
.cert-alert { border-radius:10px;padding:16px;font-size:.88rem;margin-bottom:16px;display:flex;gap:8px;align-items:center; }
.cert-alert.warn { background:color-mix(in srgb, var(--secondary-color) 12%, white);border:1px solid color-mix(in srgb, var(--secondary-color) 24%, white);color:var(--secondary-dark); }
.cert-body-inner { position:relative;z-index:1; }
.cert-intro { text-align:center;font-size:.88rem;color:var(--text-color);margin-bottom:18px;line-height:1.6; }
.cert-photo-empty-icon { font-size:2.5rem;color:color-mix(in srgb, var(--primary-color) 20%, var(--gray-300)); }
.cert-plain-muted { font-size:.75rem;color:var(--text-muted);margin-bottom:4px; }
.cert-plain-phone { font-size:.72rem;color:var(--text-muted); }
.cert-qr { display:block;border:1px solid color-mix(in srgb, var(--primary-color) 14%, var(--gray-200));border-radius:4px; }
.cert-qr-note { font-size:.65rem;color:var(--text-muted);margin-top:3px; }
.cert-bottom-help { text-align:center;margin-top:16px;font-size:.8rem;color:var(--text-muted); }
.cert-inline-icon-sm { margin-right:3px; }
.cert-inline-icon-md { margin-right:4px; }
.cert-inline-icon-lg { margin-right:8px; }
.cert-member-code { font-family:monospace;color:var(--primary-color); }
.cert-footer-mid{flex:1;text-align:center;}
.cert-sign-wrap{margin-top:8px;}
.cert-footer-qr{text-align:center;}
</style>
HTML;
?>
<?php require __DIR__ . '/includes/chrome.php'; ?>

<main class="mp-main">
<div class="mp-container">

  <!-- Action buttons (hidden on print) -->
  <div class="cert-noprint cert-actions">
    <h1 class="cert-page-title">
      <i class="fas fa-certificate cert-inline-icon-lg"></i><?php echo $_t('सदस्यता प्रमाणपत्र', 'Membership Certificate'); ?>
    </h1>
    <div class="cert-btn-row">
      <button onclick="window.print()" class="cert-btn primary">
        <i class="fas fa-print"></i> Print / PDF
      </button>
      <a href="id-card.php" class="cert-btn outline">
        <i class="fas fa-id-card"></i> <?php echo $_t('पहिचान कार्ड', 'ID Card'); ?>
      </a>
    </div>
  </div>

  <?php if (!$fullName || !$sadasyata): ?>
  <div class="cert-noprint cert-alert warn">
    <i class="fas fa-triangle-exclamation"></i>
    <div><?php echo $_t('तपाईंको KYC अनुमोदन भएको छैन। KYC approve भएपछि मात्र पूर्ण प्रमाणपत्र उपलब्ध हुनेछ।', 'Your KYC is not approved yet. Full certificate will be available only after KYC approval.'); ?></div>
  </div>
  <?php endif; ?>

  <!-- Certificate -->
  <div class="cert-page" id="certificate">
    <div class="cert-watermark"><?= htmlspecialchars($siteName) ?></div>
    <?php if ($kycRow && ($kycRow['status'] ?? '') === 'approved'): ?>
    <div class="cert-ribbon">VERIFIED</div>
    <?php endif; ?>

    <!-- Top band -->
    <div class="cert-top-band">
      <?php if ($siteLogo && file_exists(__DIR__ . '/../' . ltrim($siteLogo,'/'))): ?>
      <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo" class="cert-logo">
      <?php else: ?>
      <div class="cert-logo-placeholder"><i class="fas fa-seedling"></i></div>
      <?php endif; ?>
      <div>
        <div class="cert-site-name"><?= htmlspecialchars($siteName) ?></div>
        <div class="cert-site-sub"><?= htmlspecialchars($siteNameEn) ?></div>
        <?php if ($siteAddress): ?>
        <div class="cert-site-sub"><i class="fas fa-location-dot cert-inline-icon-sm"></i><?= htmlspecialchars($siteAddress) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Body -->
    <div class="cert-body cert-body-inner">
      <div class="cert-title">
        <h2><?php echo $_t('सदस्यता प्रमाणपत्र', 'Membership Certificate'); ?></h2>
        <p>MEMBERSHIP CERTIFICATE</p>
      </div>

      <div class="cert-intro">
        <?php echo $_t('यसद्वारा प्रमाणित गरिन्छ कि तल उल्लिखित व्यक्ति', 'This is to certify that the person mentioned below is a'); ?>
        <strong><?= htmlspecialchars($siteName) ?></strong>
        <?php echo $_t('को', 'of'); ?>
        <?= $accountType ? htmlspecialchars($accountType) . ' ' : '' ?><?php echo $_t('सदस्य हुनुहुन्छ।', 'member.'); ?>
      </div>

      <div class="cert-member-row">
        <div class="cert-photo">
          <?php if ($photoUrl): ?>
          <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Photo">
          <?php else: ?>
          <i class="fas fa-user cert-photo-empty-icon"></i>
          <?php endif; ?>
        </div>
        <div class="cert-details">
          <div class="cert-field">
            <span class="cert-field-label"><?php echo $_t('पूरा नाम', 'Full Name'); ?></span>
            <span class="cert-field-value"><?= htmlspecialchars($fullName ?: '—') ?></span>
          </div>
          <div class="cert-field">
            <span class="cert-field-label"><?php echo $_t('सदस्यता नम्बर', 'Membership Number'); ?></span>
            <span class="cert-field-value cert-member-code"><?= htmlspecialchars($sadasyata ?: '—') ?></span>
          </div>
          <?php if ($fatherName): ?>
          <div class="cert-field">
            <span class="cert-field-label"><?php echo $_t('बुबाको नाम', "Father's Name"); ?></span>
            <span class="cert-field-value"><?= htmlspecialchars($fatherName) ?></span>
          </div>
          <?php endif; ?>
          <?php if ($dob): ?>
          <div class="cert-field">
            <span class="cert-field-label"><?php echo $_t('जन्म मिति', 'Date of Birth'); ?></span>
            <span class="cert-field-value"><?= htmlspecialchars($dob) ?></span>
          </div>
          <?php endif; ?>
          <?php if ($address): ?>
          <div class="cert-field">
            <span class="cert-field-label"><?php echo $_t('स्थायी ठेगाना', 'Permanent Address'); ?></span>
            <span class="cert-field-value"><?= htmlspecialchars($address) ?></span>
          </div>
          <?php endif; ?>
          <?php if ($citizenship): ?>
          <div class="cert-field">
            <span class="cert-field-label"><?php echo $_t('नागरिकता नम्बर', 'Citizenship Number'); ?></span>
            <span class="cert-field-value"><?= htmlspecialchars($citizenship) ?></span>
          </div>
          <?php endif; ?>
          <div class="cert-field">
            <span class="cert-field-label"><?php echo $_t('सदस्य भएको मिति', 'Member Since'); ?></span>
            <span class="cert-field-value"><?= htmlspecialchars($issueDateNp) ?></span>
          </div>
        </div>
      </div>
    </div>

    <!-- Footer -->
    <div class="cert-footer">
      <div>
        <div class="cert-seal"><?= htmlspecialchars(mb_substr($siteName,0,12)) ?><br><?php echo $_t('सहकारी', 'Co-op'); ?><br><?php echo $_t('छाप', 'Seal'); ?></div>
      </div>
      <div class="cert-footer-mid">
        <div class="cert-plain-muted"><?php echo $_t('जारी मिति', 'Issued Date'); ?>: <?= htmlspecialchars($issueDateNp) ?></div>
        <?php if ($sitePhone): ?>
        <div class="cert-plain-phone"><i class="fas fa-phone cert-inline-icon-sm"></i><?= htmlspecialchars($sitePhone) ?></div>
        <?php endif; ?>
        <div class="cert-sign-wrap">
          <div class="cert-sign-line"><?php echo $_t('अध्यक्ष', 'Chairperson'); ?></div>
        </div>
      </div>
      <div class="cert-footer-qr">
        <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR" width="80" height="80" class="cert-qr">
        <div class="cert-qr-note">Scan to Verify</div>
      </div>
    </div>
  </div>

  <div class="cert-noprint cert-bottom-help">
    <i class="fas fa-info-circle cert-inline-icon-md"></i>
    <?php echo $_t('Print / PDF download को लागि माथिको "Print / PDF" button थिच्नुहोस्। Browser मा "Save as PDF" option छान्नुहोस्।', 'To print or download PDF, click the "Print / PDF" button above and choose "Save as PDF" in browser.'); ?>
  </div>

</div>
</main>
<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
