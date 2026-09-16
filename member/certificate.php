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

/* KYC data (SSOT: kyc_application_id → sadasyata; no email/mobile soft match) */
$kycRow = null;
try {
    if (function_exists('memberSsotLoadLinkedKyc')) {
        $kycRow = memberSsotLoadLinkedKyc($db, $mem);
    } else {
        $kycLinkId = (int)($mem['kyc_application_id'] ?? 0);
        if ($kycLinkId > 0) {
            $ks = $db->prepare("SELECT * FROM kyc_applications WHERE id=? LIMIT 1");
            $ks->execute([$kycLinkId]);
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
$extraHead = (isset($extraHead) ? (string) $extraHead : '')
    . (function_exists('coopThemeLinkHtml')
        ? coopThemeLinkHtml('assets/css/member-certificate-page.css')
        : '');
?>
<?php require __DIR__ . '/includes/chrome.php'; ?>

<div class="mp-main">
<div class="mp-container">

  <!-- Action buttons (hidden on print) -->
  <div class="cert-noprint cert-actions">
    <h1 class="cert-page-title">
      <i class="lucide-icon cert-inline-icon-lg" data-lucide="badge" aria-hidden="true"></i><?php echo $_t('सदस्यता प्रमाणपत्र', 'Membership Certificate'); ?>
    </h1>
    <div class="cert-btn-row">
      <button type="button" onclick="window.print()" class="cert-btn primary">
        <i class="lucide-icon" data-lucide="printer" aria-hidden="true"></i> Print / PDF
      </button>
      <a href="id-card.php" class="cert-btn outline">
        <i class="lucide-icon" data-lucide="id-card" aria-hidden="true"></i> <?php echo $_t('पहिचान कार्ड', 'ID Card'); ?>
      </a>
    </div>
  </div>

  <?php if (!$fullName || !$sadasyata): ?>
  <div class="cert-noprint cert-alert warn">
    <i class="lucide-icon" data-lucide="triangle-alert" aria-hidden="true"></i>
    <div><?php echo $_t('तपाईंको केवाइएम अनुमोदन भएको छैन। KYM approve भएपछि मात्र पूर्ण प्रमाणपत्र उपलब्ध हुनेछ।', 'Your KYC is not approved yet. Full certificate will be available only after KYC approval.'); ?></div>
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
      <div class="cert-logo-placeholder"><i class="lucide-icon" data-lucide="sprout" aria-hidden="true"></i></div>
      <?php endif; ?>
      <div>
        <div class="cert-site-name"><?= htmlspecialchars($siteName) ?></div>
        <div class="cert-site-sub"><?= htmlspecialchars($siteNameEn) ?></div>
        <?php if ($siteAddress): ?>
        <div class="cert-site-sub"><i class="lucide-icon cert-inline-icon-sm" data-lucide="map-pin" aria-hidden="true"></i><?= htmlspecialchars($siteAddress) ?></div>
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
          <i class="lucide-icon cert-photo-empty-icon" data-lucide="user" aria-hidden="true"></i>
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
        <div class="cert-plain-phone"><i class="lucide-icon cert-inline-icon-sm" data-lucide="phone" aria-hidden="true"></i><?= htmlspecialchars($sitePhone) ?></div>
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
    <i class="lucide-icon cert-inline-icon-md" data-lucide="info" aria-hidden="true"></i>
    <?php echo $_t('Print / PDF download को लागि माथिको "Print / PDF" button थिच्नुहोस्। Browser मा "Save as PDF" option छान्नुहोस्।', 'To print or download PDF, click the "Print / PDF" button above and choose "Save as PDF" in browser.'); ?>
  </div>

</div>
</div>
<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
