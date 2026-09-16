<?php
/**
 * Digital ID Card — v10.6
 * Face shows only: logo/banner, Member ID, name, member mobile, CVV,
 * cooperative address / contact / website. No legacy PREFIX card numbers
 * or verification codes (verify uses name + Member ID + mobile).
 */
require_once __DIR__ . '/_bootstrap.php';
requireMemberLogin();
$_t = static function (string $np, string $en): string {
    return isEnglish() ? $en : $np;
};

$mid = $_SESSION['member_id'] ?? '';
if ($mid === '') {
    header('Location: /member/login.php');
    exit;
}

if (!isset($pdo) && isset($db)) { $pdo = $db; }
if (!isset($pdo) && isset($GLOBALS['pdo'])) { $pdo = $GLOBALS['pdo']; }
if (!isset($pdo) && isset($GLOBALS['db']))  { $pdo = $GLOBALS['db']; }
if (!isset($pdo) && function_exists('getDB')) { $pdo = getDB(); }

require_once __DIR__ . '/../includes/card-verify-helpers.php';
if (function_exists('ensureCardSecurityColumns')) {
    try { ensureCardSecurityColumns($pdo); } catch (Throwable $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_unlock') {
    if (!function_exists('verifyCSRFToken') || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        header('Location: /member/id-card.php');
        exit;
    }
    $cardId = (int)($_POST['card_id'] ?? 0);
    $memberPk = (int)$mid;
    if ($cardId > 0 && $memberPk > 0) {
        try {
            /* Ownership: card must belong to the logged-in member (id / sadasyata / card_no) */
            $rq = $pdo->prepare(
                "UPDATE member_id_cards mic
                    INNER JOIN members m ON (
                        mic.member_id = CAST(m.id AS CHAR)
                        OR mic.member_id = m.sadasyata_number
                        OR mic.member_id = m.member_card_no
                    )
                 SET mic.unlock_requested = 1, mic.unlock_requested_at = NOW()
               WHERE mic.id = ? AND m.id = ?"
            );
            $rq->execute([$cardId, $memberPk]);
            header('Location: /member/id-card.php?unlock_requested=1');
            exit;
        } catch (Throwable $e) {}
    }
}

/* Step 1: load member */
$me = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE id = :mid LIMIT 1");
    $stmt->execute([':mid' => (int) $mid]);
    $me = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) { error_log('[id-card-pk] ' . $e->getMessage()); }
if (!$me) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM members WHERE member_card_no = :mid LIMIT 1");
        $stmt->execute([':mid' => $mid]);
        $me = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { error_log('[id-card-mid] ' . $e->getMessage()); }
}
if (!$me) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM members WHERE sadasyata_number = :mid LIMIT 1");
        $stmt->execute([':mid' => $mid]);
        $me = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}
}
if (is_array($me)) {
    if (function_exists('memberStripAuthSecrets')) {
        $me = memberStripAuthSecrets($me);
    } else {
        unset($me['twofa_secret'], $me['twofa_backup_codes'], $me['password_hash']);
    }
}

/* Step 1.5: KYC-linked details (SSOT: kyc_application_id → sadasyata; no email/mobile soft match) */
$kycRow = null;
try {
    if (function_exists('memberSsotLoadLinkedKyc')) {
        $kycRow = memberSsotLoadLinkedKyc($pdo, $me);
    } else {
        $kycMemberLinkId = (int)($me['kyc_application_id'] ?? 0);
        if ($kycMemberLinkId > 0) {
            $ks = $pdo->prepare("SELECT id, full_name, email, mobile, permanent_address, photo
                                 FROM kyc_applications WHERE id=? LIMIT 1");
            $ks->execute([$kycMemberLinkId]);
            $kycRow = $ks->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
    if ($kycRow && empty($me['kyc_application_id'])) {
        $pdo->prepare("UPDATE members SET kyc_application_id=? WHERE id=?")
            ->execute([(int)$kycRow['id'], (int)$me['id']]);
        $me['kyc_application_id'] = (int)$kycRow['id'];
    }
} catch (Throwable $e) { $kycRow = null; }
if ($kycRow) {
    if (trim((string)($kycRow['full_name'] ?? '')) !== '') $me['full_name'] = trim((string)$kycRow['full_name']);
    if (trim((string)($kycRow['email'] ?? '')) !== '')     $me['email'] = trim((string)$kycRow['email']);
    if (trim((string)($kycRow['mobile'] ?? '')) !== '')    $me['mobile'] = trim((string)$kycRow['mobile']);
    if (trim((string)($kycRow['permanent_address'] ?? '')) !== '') $me['address'] = trim((string)$kycRow['permanent_address']);
    if (!empty($kycRow['photo'])) $me['photo_path'] = trim((string)$kycRow['photo']); // photo source = KYC
}

/* Step 2: load active card row */
if ($me) {
    $card = null;
    try {
        $cs = $pdo->prepare(
            "SELECT id AS card_row_id, card_no, verification_code, cvv, issued_date, status, failed_verify_count, unlock_requested
               FROM member_id_cards
              WHERE (member_id = :id OR member_id = :sid OR member_id = :card)
              ORDER BY id DESC LIMIT 1"
        );
        $cs->execute([
            ':id'  => (string) ($me['id'] ?? ''),
            ':sid' => (string) ($me['sadasyata_number'] ?? ''),
            ':card' => (string) ($me['member_card_no'] ?? ''),
        ]);
        $card = $cs->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { error_log('[id-card-row] ' . $e->getMessage()); }

    /* Member display ID + derived CVV (name first3 + member last4) */
    $rawSid = trim((string)($me['sadasyata_number'] ?? ''));
    $rawMc = trim((string)($me['member_card_no'] ?? ''));
    $legacyPrefixMc = $rawMc !== '' && (bool)preg_match('/^[A-Z]{2,4}-\d{4}-\d+$/i', $rawMc);
    $memberDispId = $rawSid !== ''
        ? $rawSid
        : ($legacyPrefixMc ? '' : $rawMc);
    if ($memberDispId === '') {
        $memberDispId = 'M-' . str_pad((string)($me['id'] ?? 0), 5, '0', STR_PAD_LEFT);
    }
    $memberDispName = function_exists('pickNameForCardCvv')
        ? pickNameForCardCvv(
            (string)(($me['full_name'] ?? '') ?: ($me['name'] ?? '')),
            (string)($me['full_name_np'] ?? '')
          )
        : trim((string)(($me['full_name'] ?? '') ?: ($me['name'] ?? '')));
    $derivedCvv = function_exists('deriveMemberCardCvv')
        ? deriveMemberCardCvv($memberDispName, $memberDispId)
        : '';

    /* Backfill verification_code; sync CVV to derived formula */
    if ($card && (empty($card['verification_code']) || empty($card['cvv']) || ($derivedCvv !== '' && (string)$card['cvv'] !== $derivedCvv))) {
        try {
            [$gCode, $gCvv] = generateCardVerification($pdo, $memberDispName, $memberDispId);
            if ($derivedCvv !== '') $gCvv = $derivedCvv;
            $u = $pdo->prepare(
                "UPDATE member_id_cards
                    SET verification_code = COALESCE(NULLIF(verification_code,''), :code),
                        cvv               = :cvv
                  WHERE id = :rid"
            );
            $u->execute([':code' => $gCode, ':cvv' => $gCvv, ':rid' => $card['card_row_id']]);
            if (empty($card['verification_code'])) $card['verification_code'] = $gCode;
            $card['cvv'] = $gCvv;
        } catch (Throwable $e) { error_log('[id-card-cvv-backfill] ' . $e->getMessage()); }
    }

    /* Auto-create a card on the fly if none exists yet */
    if (!$card) {
        try {
            [$gCode, $gCvv] = generateCardVerification($pdo, $memberDispName, $memberDispId);
            /* Store Member ID as card_no — no PREFIX-YYYY-NNNNN on face/ledger */
            $newCardNo = $memberDispId !== ''
                ? $memberDispId
                : ('M-' . str_pad((string)($me['id'] ?? 0), 5, '0', STR_PAD_LEFT));
            $ins = $pdo->prepare(
                "INSERT INTO member_id_cards
                    (member_id, card_no, verification_code, cvv, issued_date, status)
                 VALUES (:mid, :card, :vcode, :cvv, CURDATE(), 'active')"
            );
            $ins->execute([
                ':mid'   => (string) (($me['sadasyata_number'] ?? '') ?: $me['id']),
                ':card'  => $newCardNo,
                ':vcode' => $gCode,
                ':cvv'   => $gCvv,
            ]);
            $card = [
                'card_row_id'        => (int)$pdo->lastInsertId(),
                'card_no'           => $newCardNo,
                'verification_code' => $gCode,
                'cvv'               => $gCvv,
                'issued_date'       => date('Y-m-d'),
                'status'            => 'active',
                'failed_verify_count' => 0,
                'unlock_requested'  => 0,
            ];
        } catch (Throwable $e) { error_log('[id-card-autocreate] ' . $e->getMessage()); }
    }

    if (!$card) {
        $card = [];
    }

    $me['card_no']           = $card['card_no']           ?? null;
    $me['verification_code'] = $card['verification_code'] ?? null;
    $me['cvv']               = $card['cvv']               ?? null;
    $me['issued_date']       = $card['issued_date']       ?? null;
    $me['card_status']       = $card['status']            ?? 'active';
    $me['failed_verify_count'] = (int)($card['failed_verify_count'] ?? 0);
    $me['unlock_requested']  = (int)($card['unlock_requested'] ?? 0);
    $me['card_row_id']       = (int)($card['card_row_id'] ?? 0);
}

if (!$me) {
    http_response_code(404);
    echo '<div style="font-family:var(--font-primary),Inter,sans-serif;text-align:center;padding:60px 20px;">'
       . '<h2>सदस्य फेला परेन।</h2>'
       . '<p><a href="/member/index.php" style="color:var(--primary-dark);">Dashboard मा फर्किनुहोस्</a></p>'
       . '</div>';
    exit;
}

/* NULL-safe defaults — Member ID = sadasyata only (not legacy PREFIX card_no) */
$me['id']           = $me['id']           ?? 0;
$legacyCardNo = trim((string)($me['member_card_no'] ?? ''));
$isLegacyPrefixNo = $legacyCardNo !== '' && (bool)preg_match('/^[A-Z]{2,4}-\d{4}-\d+$/i', $legacyCardNo);
$me['member_id']    = trim((string)($me['sadasyata_number'] ?? '')) !== ''
    ? (string)$me['sadasyata_number']
    : ($isLegacyPrefixNo ? '' : $legacyCardNo);
if ($me['member_id'] === '') {
    $me['member_id'] = 'M-' . str_pad((string)$me['id'], 5, '0', STR_PAD_LEFT);
}
$me['full_name']    = $me['full_name']    ?? ($me['name'] ?? '');
$me['full_name_np'] = $me['full_name_np'] ?? '';
$me['mobile']       = $me['mobile']       ?? ($me['phone'] ?? '');
$me['email']        = $me['email']        ?? '';
$me['address']      = $me['address']      ?? '';
$me['photo_path']   = $me['photo_path']   ?? '';
$me['created_at']   = $me['created_at']   ?: date('Y-m-d H:i:s');
$me['issued_date']  = $me['issued_date']  ?? null;
$me['card_expires_at'] = $me['card_expires_at'] ?? null;

/* Program participation star rating (1-5) */
$cardProgramAttended = 0;
$cardProgramEligible = 0;
$cardProgramStar = 1;
try {
    $stA = $pdo->prepare("SELECT COUNT(DISTINCT a.program_id)
                          FROM member_program_attendance a
                          INNER JOIN upcoming_programs p ON p.id = a.program_id
                          WHERE a.member_id=? AND p.is_active=1");
    $stA->execute([(int)$me['id']]);
    $cardProgramAttended = (int)$stA->fetchColumn();
} catch (Throwable $e) { $cardProgramAttended = 0; }
try {
    $cardProgramEligible = (int)$pdo->query("SELECT COUNT(*) FROM upcoming_programs WHERE is_active=1")->fetchColumn();
} catch (Throwable $e) { $cardProgramEligible = 0; }
if ($cardProgramEligible > 0) {
    $ratio = $cardProgramAttended / $cardProgramEligible;
    if ($ratio >= 0.90) $cardProgramStar = 5;
    elseif ($ratio >= 0.70) $cardProgramStar = 4;
    elseif ($ratio >= 0.50) $cardProgramStar = 3;
    elseif ($ratio >= 0.30) $cardProgramStar = 2;
    else $cardProgramStar = 1;
}
$cardStarHtml = str_repeat('★', $cardProgramStar) . str_repeat('☆', 5 - $cardProgramStar);

$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
$photo = (!empty($me['photo_path']) && $docRoot && file_exists($docRoot . '/' . ltrim($me['photo_path'], '/')))
       ? '/' . ltrim($me['photo_path'], '/')
       : '/member/assets/photo-placeholder.svg';

$pageTitle = $_t('डिजिटल ID कार्ड', 'Digital ID Card');
$extraHead = (isset($extraHead) ? (string) $extraHead : '')
    . (function_exists('coopThemeLinkHtml')
        ? coopThemeLinkHtml('assets/css/member-id-card-page.css')
        : '');
require __DIR__ . '/includes/chrome.php';

/* ─── Card metadata — Member ID + derived CVV only (no legacy codes) ─── */
$cn       = (string)($me['member_id'] ?? '');
$cnSpaced = $cn;
$memberMobile = trim((string)($me['mobile'] ?? ''));
$displayNameForCvv = function_exists('pickNameForCardCvv')
    ? pickNameForCardCvv(
        (string)(($me['full_name'] ?? '') ?: ($me['name'] ?? '')),
        (string)($me['full_name_np'] ?? '')
      )
    : trim((string)(($me['full_name'] ?? '') ?: ($me['name'] ?? '')));
$cvv      = (function_exists('deriveMemberCardCvv') && $displayNameForCvv !== '')
          ? deriveMemberCardCvv($displayNameForCvv, $cn)
          : (string)($me['cvv'] ?? '');
$orgName  = function_exists('getSetting') ? getSetting('site_name', 'सहकारी') : 'सहकारी';
$orgNameEn = function_exists('getSetting') ? getSetting('site_name_en', '') : '';
$memberDisplayName = trim((string)(($me['full_name_np'] ?? '') ?: ($me['full_name'] ?? '') ?: ($me['name'] ?? '')));
$memberNameEn = trim((string)(($me['full_name'] ?? '') ?: ($me['name'] ?? '')));

/* Issue dates — prefer card.issued_date, then approved_at, then created_at */
$issuedTs = strtotime(!empty($me['issued_date']) ? $me['issued_date']
                    : ($me['approved_at'] ?? $me['created_at']));
$expiryTs = !empty($me['card_expires_at'])
          ? strtotime($me['card_expires_at'])
          : strtotime('+5 years', $issuedTs);
$expYr     = date('y', $expiryTs);
$expMo     = date('m', $expiryTs);
$isExpired = $expiryTs < time();
$daysLeft  = (int) floor(($expiryTs - time()) / 86400);

/* Coop contact — Site Settings */
$cardPhone   = function_exists('getSetting') ? getSetting('phone', getSetting('mobile', '01-XXXXXXX')) : '01-XXXXXXX';
$cardAddress = function_exists('getSetting')
    ? trim((string)getSetting(isEnglish() ? 'address_en' : 'address', getSetting('address', '')))
    : '';
$cardWebsite = function_exists('getSetting') ? trim((string) getSetting('site_url', '')) : '';
$cardLogoRaw = function_exists('getSetting') ? trim((string)getSetting('logo', 'assets/images/logo.png')) : 'assets/images/logo.png';
if ($cardWebsite === '' && defined('SITE_URL')) $cardWebsite = SITE_URL;
$cardWebsite = preg_replace('#^https?://#i', '', rtrim($cardWebsite, '/'));
$cardLogoUrl = '';
if ($cardLogoRaw !== '') {
    if (preg_match('#^https?://#i', $cardLogoRaw)) {
        $cardLogoUrl = $cardLogoRaw;
    } else {
        $base = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') . '/' : '/';
        $cardLogoUrl = $base . ltrim($cardLogoRaw, '/');
    }
}
?>

<div class="idcard-page">
  <div class="idcard-actions">
    <a href="/member/index.php" class="idcard-btn idcard-btn-ghost"><i class="lucide-icon" data-lucide="arrow-left" aria-hidden="true"></i> <?php echo $_t('ड्यासबोर्ड', 'Dashboard'); ?></a>
    <button type="button" id="idcardFlipBtn" class="idcard-btn idcard-btn-ghost"><i class="lucide-icon" data-lucide="refresh-cw" aria-hidden="true"></i> <?php echo $_t('कार्ड उल्ट्याउनुहोस्', 'Flip Card'); ?></button>
    <button type="button" onclick="window.print()" class="idcard-btn idcard-btn-primary"><i class="lucide-icon" data-lucide="printer" aria-hidden="true"></i> <?php echo $_t('प्रिन्ट / डाउनलोड', 'Print / Download'); ?></button>
  </div>
  <div class="idcard-note idcard-note-rating">
    <i class="lucide-icon me-1" data-lucide="star" aria-hidden="true"></i><b><?php echo $_t('कार्यक्रम रेटिङ', 'Program Rating'); ?>:</b> <?php echo $cardStarHtml; ?>
    <span class="idcard-note-muted">(<?php echo (int)$cardProgramAttended; ?>/<?php echo max(1, (int)$cardProgramEligible); ?>)</span>
  </div>

  <?php if ($isExpired): ?>
  <div class="idcard-note idcard-note-expired">
    <i class="lucide-icon" data-lucide="triangle-alert" aria-hidden="true"></i>
    <b><?php echo $_t('तपाईंको ID Card को म्याद सकिएको छ।', 'Your ID card has expired.'); ?></b>
    <?php echo $_t('कृपया कार्यालयमा सम्पर्क गरी कार्ड renew गर्नुहोस् — Admin ले approve गरेपछि feri active हुनेछ।', 'Please contact office to renew it. It will be active again after admin approval.'); ?>
  </div>
  <?php elseif ($daysLeft <= 60): ?>
  <div class="idcard-note idcard-note-soon">
    <i class="lucide-icon" data-lucide="clock" aria-hidden="true"></i>
    <?php echo $_t('कार्ड म्याद', 'Card validity'); ?> <?= $daysLeft ?> <?php echo $_t('दिनमा सकिँदैछ। समयमै renew गर्नुहोस्।', 'days remaining. Please renew on time.'); ?>
  </div>
  <?php endif; ?>
  <?php if (($me['card_status'] ?? 'active') === 'locked'): ?>
  <div class="idcard-note idcard-note-locked">
    <i class="lucide-icon" data-lucide="lock" aria-hidden="true"></i>
    <b><?php echo $_t('यो कार्ड 5+ गलत verify प्रयासका कारण LOCK भएको छ।', 'This card is locked due to 5+ failed verification attempts.'); ?></b>
    <?php echo $_t('कृपया admin/office बाट unlock गराउनुहोस्।', 'Please request unlock from admin/office.'); ?>
    <?php if (!empty($_GET['unlock_requested']) || !empty($me['unlock_requested'])): ?>
      <div class="idcard-note-success"><?php echo $_t('Unlock request पठाइएको छ।', 'Unlock request submitted.'); ?></div>
    <?php endif; ?>
    <div class="idcard-note-actions">
      <form method="POST" class="idcard-form-inline">
        <?php echo function_exists('csrfField') ? csrfField() : ''; ?>
        <input type="hidden" name="action" value="request_unlock">
        <input type="hidden" name="card_id" value="<?php echo (int)($me['card_row_id'] ?? 0); ?>">
        <button type="submit" class="idcard-btn idcard-btn-ghost idcard-btn-danger-outline">
          <i class="lucide-icon" data-lucide="unlock-keyhole" aria-hidden="true"></i> <?php echo $_t('अनलक अनुरोध', 'Unlock Request'); ?>
        </button>
      </form>
      <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', (string)$cardPhone)); ?>" class="idcard-btn idcard-btn-primary idcard-btn-fixed">
        <i class="lucide-icon" data-lucide="phone" aria-hidden="true"></i> <?php echo $_t('कार्यालय कल', 'Office Call'); ?>
      </a>
    </div>
  </div>
  <?php endif; ?>

  <!-- ═══════ MEMBER DIGITAL ID ═══════ -->
  <div class="idcard-flip" id="idcardFlip" role="button" tabindex="0" aria-label="<?php echo $_t('कार्ड उल्ट्याउन क्लिक गर्नुहोस्', 'Click to flip card'); ?>">
    <div class="idcard-flip-inner">

      <!-- ─── FRONT ─── -->
      <div class="idcard idcard-front">
        <div class="idcard-mesh" aria-hidden="true"></div>
        <div class="idcard-orb idcard-orb-a" aria-hidden="true"></div>
        <div class="idcard-orb idcard-orb-b" aria-hidden="true"></div>
        <div class="idcard-shine" aria-hidden="true"></div>
        <div class="idcard-edge" aria-hidden="true"></div>

        <div class="idcard-top">
          <div class="idcard-brand">
            <?php if ($cardLogoUrl !== ''): ?>
            <div class="idcard-logo-wrap">
              <img src="<?= htmlspecialchars($cardLogoUrl) ?>" alt="" class="idcard-logo" onerror="this.parentElement.style.display='none'">
            </div>
            <?php endif; ?>
            <div class="idcard-brand-text">
              <div class="idcard-org"><?= htmlspecialchars($orgName) ?></div>
              <?php if ($orgNameEn !== ''): ?>
              <div class="idcard-org-en"><?= htmlspecialchars($orgNameEn) ?></div>
              <?php endif; ?>
            </div>
          </div>
          <div class="idcard-tag-stack">
            <span class="idcard-tag"><?php echo $_t('सदस्य', 'MEMBER'); ?></span>
            <span class="idcard-tag-sub"><?php echo $_t('परिचयपत्र', 'ID CARD'); ?></span>
          </div>
        </div>

        <div class="idcard-identity">
          <div class="idcard-photo">
            <img src="<?= htmlspecialchars($photo) ?>" alt="Member photo">
            <span class="idcard-photo-ring" aria-hidden="true"></span>
          </div>
          <div class="idcard-identity-text">
            <div class="idcard-label"><?php echo $_t('सदस्यको नाम', 'NAME'); ?></div>
            <div class="idcard-name"><?= htmlspecialchars($memberDisplayName) ?></div>
            <div class="idcard-label idcard-label-gap"><?php echo $_t('सदस्यता नं. (Member ID)', 'MEMBER ID'); ?></div>
            <div class="idcard-cardno" title="<?php echo htmlspecialchars($_t('पुरानो कार्ड नं. / verify code अहिले यही Member ID हो', 'Former card no. / verify code is now this Member ID')); ?>"><?= htmlspecialchars($cnSpaced) ?></div>
            <div class="idcard-label idcard-label-gap"><?php echo $_t('मोबाइल', 'MOBILE'); ?></div>
            <div class="idcard-mobile-val"><?= htmlspecialchars($memberMobile !== '' ? $memberMobile : '—') ?></div>
          </div>
        </div>

        <div class="idcard-id-row">
          <span class="idcard-status"><i class="lucide-icon" data-lucide="circle-check" aria-hidden="true"></i> <?php echo $_t('सक्रिय', 'Active'); ?></span>
          <span class="idcard-mid-no"><i class="lucide-icon" data-lucide="globe" aria-hidden="true"></i> <?= htmlspecialchars($cardWebsite ?: 'website') ?></span>
        </div>
      </div>

      <!-- ─── BACK ─── -->
      <div class="idcard idcard-back">
        <div class="idcard-mesh idcard-mesh-back" aria-hidden="true"></div>
        <div class="idcard-back-banner">
          <?php if ($cardLogoUrl !== ''): ?>
          <img src="<?= htmlspecialchars($cardLogoUrl) ?>" alt="" class="idcard-back-banner-logo" onerror="this.style.display='none'">
          <?php endif; ?>
          <div class="idcard-back-banner-text">
            <div class="idcard-org"><?= htmlspecialchars($orgName) ?></div>
            <?php if ($orgNameEn !== ''): ?>
            <div class="idcard-org-en"><?= htmlspecialchars($orgNameEn) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <div class="idcard-back-body">
          <div class="idcard-sigpanel">
            <div class="idcard-sig-left">
              <span class="idcard-sig-label"><?php echo $_t('सदस्यता नं. / MEMBER ID', 'MEMBER ID'); ?></span>
              <span class="idcard-sigpanel-text idcard-sigpanel-mid"><?= htmlspecialchars($cn) ?></span>
              <span class="idcard-sig-sub"><?= htmlspecialchars($memberNameEn ?: $memberDisplayName) ?></span>
            </div>
            <span class="idcard-cvv-box" title="CVV">
              <span class="cvv-label">CVV</span>
              <span class="cvv-value"><?= $cvv !== '' ? htmlspecialchars($cvv) : '••••' ?></span>
            </span>
          </div>

          <div class="idcard-back-meta">
            <div class="idcard-back-vcode">
              <span class="bv-label"><?php echo $_t('मोबाइल', 'MOBILE'); ?></span>
              <span class="bv-value bv-value-sm"><?= htmlspecialchars($memberMobile !== '' ? $memberMobile : '—') ?></span>
            </div>
            <div class="idcard-back-issued">
              <span class="bv-label"><?php echo $_t('म्याद', 'VALID'); ?></span>
              <span class="bv-value bv-value-sm"><?= $expMo ?>/<?= $expYr ?></span>
            </div>
          </div>

          <div class="idcard-back-note">
            <b><?php echo $_t('प्रमाणीकरण:', 'Verify:'); ?></b>
            <?= htmlspecialchars(($cardWebsite ?: 'website') . '/verify.php') ?>
            — <?php echo $_t('नाम + सदस्यता नं. + मोबाइल।', 'name + member ID + mobile.'); ?>
          </div>
          <div class="idcard-coop-block">
            <?php if ($cardAddress !== ''): ?>
            <div class="idcard-coop-line"><i class="lucide-icon" data-lucide="map-pin" aria-hidden="true"></i> <?= htmlspecialchars($cardAddress) ?></div>
            <?php endif; ?>
            <div class="idcard-coop-line"><i class="lucide-icon" data-lucide="phone" aria-hidden="true"></i> <?= htmlspecialchars($cardPhone) ?></div>
            <div class="idcard-coop-line"><i class="lucide-icon" data-lucide="globe" aria-hidden="true"></i> <?= htmlspecialchars($cardWebsite ?: 'website') ?></div>
          </div>
        </div>
      </div>

    </div>
  </div>
  <p class="idcard-flip-hint"><i class="lucide-icon" data-lucide="hand" aria-hidden="true"></i> <?php echo $_t('कार्डमा टच/क्लिक गरेर उल्ट्याउनुहोस्', 'Tap or click the card to flip'); ?></p>

  <div class="idcard-ssot-note">
    <i class="lucide-icon" data-lucide="fingerprint" aria-hidden="true"></i>
    <?php echo $_t('सदस्यता नं. (Member ID) नै पहिचान हो — पुरानो कार्ड नम्बर / verification code छुट्टै चाहिँदैन।', 'Member ID is the identity — a separate old card number / verification code is not needed.'); ?>
  </div>

  <!-- Details list below card -->
  <div class="idcard-details">
    <div class="idcard-detail"><div class="dl"><?php echo $_t('सदस्यता नं. / Member ID', 'MEMBER ID'); ?></div><div class="dv code"><?= htmlspecialchars($cn) ?></div></div>
    <div class="idcard-detail idcard-detail-cvv">
      <div class="dl idcard-detail-cvv-label"><i class="lucide-icon" data-lucide="shield" aria-hidden="true"></i> CVV</div>
      <div class="dv code idcard-detail-cvv-value"><?= htmlspecialchars($cvv !== '' ? $cvv : '—') ?></div>
    </div>
    <div class="idcard-detail"><div class="dl"><?php echo $_t('मोबाइल', 'Mobile'); ?></div><div class="dv"><?= htmlspecialchars($memberMobile !== '' ? $memberMobile : '-') ?></div></div>
    <div class="idcard-detail"><div class="dl"><?php echo $_t('म्याद सकिने मिति', 'Expiry Date'); ?></div><div class="dv <?= $isExpired ? 'dv-expired' : '' ?>"><?= date('Y-m-d', $expiryTs) ?><?= $isExpired ? ($_t(' (म्याद सकिएको)', ' (Expired)')) : '' ?></div></div>
    <div class="idcard-detail" style="grid-column: 1/-1;"><div class="dl"><?php echo $_t('सहकारी ठेगाना', 'Cooperative address'); ?></div><div class="dv"><?= htmlspecialchars($cardAddress !== '' ? $cardAddress : '-') ?></div></div>
    <div class="idcard-detail"><div class="dl"><?php echo $_t('सहकारी सम्पर्क', 'Cooperative contact'); ?></div><div class="dv"><?= htmlspecialchars($cardPhone) ?></div></div>
    <div class="idcard-detail"><div class="dl"><?php echo $_t('वेबसाइट', 'Website'); ?></div><div class="dv"><?= htmlspecialchars($cardWebsite ?: '-') ?></div></div>

    <div class="idcard-detail idcard-detail-full" style="background:color-mix(in srgb, var(--primary-color) 8%, white); border-color:color-mix(in srgb, var(--primary-color) 28%, #e5e7eb);">
      <div class="dl" style="color:var(--primary-dark);"><i class="lucide-icon" data-lucide="info" aria-hidden="true"></i> <?php echo $_t('CVV कसरी बन्छ?', 'How is CVV built?'); ?></div>
      <div class="idcard-detail-cvv-help" style="margin-top:4px; color:#374151;">
        <?php echo $_t('नामको पहिलो शब्दका पहिलो ३ अक्षर + सदस्यता नं. को पछिल्लो ४ अङ्क। याद राख्नु पर्दैन — कार्डको पछाडि देखिन्छ।', 'First 3 characters of first name + last 4 digits of member ID. No need to memorize — shown on the back of the card.'); ?>
      </div>
    </div>
  </div>

  <!-- Help banner -->
  <div class="idcard-verify-help">
    <div class="vh-icon"><i class="lucide-icon" data-lucide="info" aria-hidden="true"></i></div>
    <div>
      <div class="vh-title"><?php echo $_t('हस्पिटल/पसलमा discount लिँदा सत्यता कसरी देखाउने?', 'How to show verification at hospital/shop discount?'); ?></div>
      <div class="vh-text">
        <?php echo $_t('उनीहरूलाई', 'Ask them to open'); ?> <b><?= htmlspecialchars(($cardWebsite ?: 'website') . '/verify.php') ?></b> <?php echo $_t('मा गएर तपाईंको नाम, सदस्यता नं. र मोबाइल राख्न भन्नुहोस् — CVV ऐच्छिक।', 'and enter your name + member ID + mobile — CVV optional.'); ?>
      </div>
    </div>
  </div>
</div>

<script>
  (function () {
    var btn  = document.getElementById('idcardFlipBtn');
    var flip = document.getElementById('idcardFlip');
    function toggle() { if (flip) flip.classList.toggle('is-flipped'); }
    if (btn && flip) btn.addEventListener('click', function (e) { e.stopPropagation(); toggle(); });
    if (flip) {
      flip.addEventListener('click', function (e) {
        if (e.target.closest('a, button')) return;
        toggle();
      });
      flip.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
      });
    }
  })();
</script>

<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
