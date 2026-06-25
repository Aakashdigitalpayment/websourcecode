<?php
/**
 * Digital ID Card — v10.4
 * Issues fixed:
 *   #1 — card_no on visual card always matches admin display (single
 *        source of truth = member_id_cards.card_no; fallback uses
 *        generateCardNumber() so prefix matches everywhere).
 *   #2 — Footer phone & website pulled from site_settings (phone, site_url).
 *   #3 — Card prefix derived from site domain.
 *   #4 — Validity = 5 years; uses members.card_expires_at when present.
 *   #5 — Cleaner header (handled in _partials/header.php).
 *   #8 — Issued + expiry dates always shown.
 *  #10 — verification_code printed exactly as stored in DB so verify.php
 *        always matches digit-for-digit.
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
    $cardId = (int)($_POST['card_id'] ?? 0);
    if ($cardId > 0) {
        try {
            $rq = $pdo->prepare("UPDATE member_id_cards
                                    SET unlock_requested = 1, unlock_requested_at = NOW()
                                  WHERE id = ?");
            $rq->execute([$cardId]);
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

/* Step 1.5: KYC-linked details override (name/mobile/email/address/photo consistency) */
$kycRow = null;
try {
    $kycMemberLinkId = (int)($me['kyc_application_id'] ?? 0);
    if ($kycMemberLinkId > 0) {
        $ks = $pdo->prepare("SELECT id, full_name, email, mobile, permanent_address, photo
                             FROM kyc_applications WHERE id=? LIMIT 1");
        $ks->execute([$kycMemberLinkId]);
        $kycRow = $ks->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$kycRow) {
        $kw = [];
        $kp = [];
        if (!empty($me['email'])) { $kw[] = 'LOWER(email)=?'; $kp[] = strtolower(trim((string)$me['email'])); }
        if (!empty($me['phone'])) { $kw[] = 'mobile=?'; $kp[] = preg_replace('/[^0-9]/', '', (string)$me['phone']); }
        if (!empty($kw)) {
            $ks = $pdo->prepare("SELECT id, full_name, email, mobile, permanent_address, photo
                                 FROM kyc_applications
                                 WHERE (" . implode(' OR ', $kw) . ")
                                 ORDER BY id DESC LIMIT 1");
            $ks->execute($kp);
            $kycRow = $ks->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($kycRow && empty($me['kyc_application_id'])) {
                $pdo->prepare("UPDATE members SET kyc_application_id=? WHERE id=?")
                    ->execute([(int)$kycRow['id'], (int)$me['id']]);
                $me['kyc_application_id'] = (int)$kycRow['id'];
            }
        }
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

    /* Backfill verification_code / cvv if missing */
    if ($card && (empty($card['verification_code']) || empty($card['cvv']))) {
        try {
            [$gCode, $gCvv] = generateCardVerification($pdo);
            $u = $pdo->prepare(
                "UPDATE member_id_cards
                    SET verification_code = COALESCE(NULLIF(verification_code,''), :code),
                        cvv               = COALESCE(NULLIF(cvv,''),               :cvv)
                  WHERE id = :rid"
            );
            $u->execute([':code' => $gCode, ':cvv' => $gCvv, ':rid' => $card['card_row_id']]);
            if (empty($card['verification_code'])) $card['verification_code'] = $gCode;
            if (empty($card['cvv']))               $card['cvv']               = $gCvv;
        } catch (Throwable $e) { error_log('[id-card-cvv-backfill] ' . $e->getMessage()); }
    }

    /* Auto-create a card on the fly if none exists yet */
    if (!$card) {
        try {
            [$gCode, $gCvv] = generateCardVerification($pdo);
            $newCardNo      = generateCardNumber((int) $me['id']);   // ← v10.4 helper
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
            /* Mirror to members.member_card_no so admin list matches */
            try {
                $pdo->prepare("UPDATE members SET member_card_no = :c WHERE id = :id")
                    ->execute([':c' => $newCardNo, ':id' => (int) $me['id']]);
                $me['member_card_no'] = $newCardNo;
            } catch (Throwable $e) {}
        } catch (Throwable $e) { error_log('[id-card-autocreate] ' . $e->getMessage()); }
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
    echo '<div style="font-family:Mukta,sans-serif;text-align:center;padding:60px 20px;">'
       . '<h2>सदस्य फेला परेन।</h2>'
       . '<p><a href="/member/index.php" style="color:var(--primary-dark);">Dashboard मा फर्किनुहोस्</a></p>'
       . '</div>';
    exit;
}

/* NULL-safe defaults */
$me['id']           = $me['id']           ?? 0;
$me['member_id']    = $me['sadasyata_number'] ?? ($me['member_card_no'] ?? '');
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
require __DIR__ . '/includes/chrome.php';

/* ─── Card metadata ─── */
$cn       = $me['card_no'] ?? generateCardNumber((int) $me['id']);
$cnSpaced = wordwrap(preg_replace('/[^A-Z0-9]/', '', strtoupper($cn)), 4, ' ', true);
$vCode    = $me['verification_code'] ?? '';
$cvv      = $me['cvv'] ?? '';

/* Issue dates — prefer card.issued_date, then approved_at, then created_at */
$issuedTs = strtotime(!empty($me['issued_date']) ? $me['issued_date']
                    : ($me['approved_at'] ?? $me['created_at']));
/* v10.4: prefer DB-stored expiry; fallback = issued + 5 years */
$expiryTs = !empty($me['card_expires_at'])
          ? strtotime($me['card_expires_at'])
          : strtotime('+5 years', $issuedTs);
$issuedYr  = date('y', $issuedTs);
$expYr     = date('y', $expiryTs);
$issuedMo  = date('m', $issuedTs);
$expMo     = date('m', $expiryTs);
$isExpired = $expiryTs < time();
$daysLeft  = (int) floor(($expiryTs - time()) / 86400);

/* Footer info — Site Settings बाट dynamic (Issue #2) */
$cardPhone   = function_exists('getSetting') ? getSetting('phone', getSetting('mobile', '01-XXXXXXX')) : '01-XXXXXXX';
$cardWebsite = function_exists('getSetting') ? trim((string) getSetting('site_url', '')) : '';
$cardLogoRaw = function_exists('getSetting') ? trim((string)getSetting('logo', 'assets/images/logo.png')) : 'assets/images/logo.png';
if ($cardWebsite === '' && defined('SITE_URL')) $cardWebsite = SITE_URL;
$cardWebsite = preg_replace('#^https?://#i', '', rtrim($cardWebsite, '/'));
$cardLogoUrl = '';
if ($cardLogoRaw !== '') {
    $cardLogoUrl = preg_match('#^https?://#i', $cardLogoRaw) ? $cardLogoRaw : (SITE_URL . ltrim($cardLogoRaw, '/'));
}
?>

<div class="idcard-page">
  <div class="idcard-actions">
    <a href="/member/index.php" class="idcard-btn idcard-btn-ghost"><i class="fas fa-arrow-left"></i> <?php echo $_t('ड्यासबोर्ड', 'Dashboard'); ?></a>
    <button type="button" id="idcardFlipBtn" class="idcard-btn idcard-btn-ghost"><i class="fas fa-arrows-rotate"></i> <?php echo $_t('कार्ड उल्ट्याउनुहोस्', 'Flip Card'); ?></button>
    <button type="button" onclick="window.print()" class="idcard-btn idcard-btn-primary"><i class="fas fa-print"></i> <?php echo $_t('प्रिन्ट / डाउनलोड', 'Print / Download'); ?></button>
  </div>
  <div class="idcard-note idcard-note-rating">
    <i class="fas fa-star me-1"></i><b><?php echo $_t('कार्यक्रम रेटिङ', 'Program Rating'); ?>:</b> <?php echo $cardStarHtml; ?>
    <span class="idcard-note-muted">(<?php echo (int)$cardProgramAttended; ?>/<?php echo max(1, (int)$cardProgramEligible); ?>)</span>
  </div>

  <?php if ($isExpired): ?>
  <div class="idcard-note idcard-note-expired">
    <i class="fas fa-triangle-exclamation"></i>
    <b><?php echo $_t('तपाईंको ID Card को म्याद सकिएको छ।', 'Your ID card has expired.'); ?></b>
    <?php echo $_t('कृपया कार्यालयमा सम्पर्क गरी कार्ड renew गर्नुहोस् — Admin ले approve गरेपछि feri active हुनेछ।', 'Please contact office to renew it. It will be active again after admin approval.'); ?>
  </div>
  <?php elseif ($daysLeft <= 60): ?>
  <div class="idcard-note idcard-note-soon">
    <i class="fas fa-clock"></i>
    <?php echo $_t('कार्ड म्याद', 'Card validity'); ?> <?= $daysLeft ?> <?php echo $_t('दिनमा सकिँदैछ। समयमै renew गर्नुहोस्।', 'days remaining. Please renew on time.'); ?>
  </div>
  <?php endif; ?>
  <?php if (($me['card_status'] ?? 'active') === 'locked'): ?>
  <div class="idcard-note idcard-note-locked">
    <i class="fas fa-lock"></i>
    <b><?php echo $_t('यो कार्ड 5+ गलत verify प्रयासका कारण LOCK भएको छ।', 'This card is locked due to 5+ failed verification attempts.'); ?></b>
    <?php echo $_t('कृपया admin/office बाट unlock गराउनुहोस्।', 'Please request unlock from admin/office.'); ?>
    <?php if (!empty($_GET['unlock_requested']) || !empty($me['unlock_requested'])): ?>
      <div class="idcard-note-success">✅ <?php echo $_t('Unlock request पठाइएको छ।', 'Unlock request submitted.'); ?></div>
    <?php endif; ?>
    <div class="idcard-note-actions">
      <form method="POST" class="idcard-form-inline">
        <input type="hidden" name="action" value="request_unlock">
        <input type="hidden" name="card_id" value="<?php echo (int)($me['card_row_id'] ?? 0); ?>">
        <button type="submit" class="idcard-btn idcard-btn-ghost idcard-btn-danger-outline">
          <i class="fas fa-unlock-keyhole"></i> <?php echo $_t('अनलक अनुरोध', 'Unlock Request'); ?>
        </button>
      </form>
      <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', (string)$cardPhone)); ?>" class="idcard-btn idcard-btn-primary idcard-btn-fixed">
        <i class="fas fa-phone"></i> <?php echo $_t('कार्यालय कल', 'Office Call'); ?>
      </a>
    </div>
  </div>
  <?php endif; ?>

  <!-- ═══════ ATM-STYLE FLIP CARD ═══════ -->
  <div class="idcard-flip" id="idcardFlip">
    <div class="idcard-flip-inner">

      <!-- ─── FRONT ─── -->
      <div class="idcard idcard-front">
        <div class="idcard-shine"></div>

        <div class="idcard-top">
          <div class="idcard-brand">
            <?php if ($cardLogoUrl !== ''): ?>
            <img src="<?= htmlspecialchars($cardLogoUrl) ?>" alt="" class="idcard-logo" onerror="this.style.display='none'">
            <?php endif; ?>
            <div>
              <div class="idcard-org"><?= htmlspecialchars(function_exists('getSetting') ? getSetting('site_name', 'सहकारी') : 'सहकारी') ?></div>
              <div class="idcard-org-en"><?= htmlspecialchars(function_exists('getSetting') ? getSetting('site_name_en', '') : '') ?></div>
            </div>
          </div>
          <span class="idcard-tag">MEMBER&nbsp;CARD</span>
        </div>

        <div class="idcard-mid">
          <div class="idcard-chip" aria-hidden="true">
            <span class="chip-l1"></span><span class="chip-l2"></span>
            <span class="chip-l3"></span><span class="chip-l4"></span>
          </div>
          <span class="idcard-wifi" aria-hidden="true"><i class="fas fa-wifi"></i></span>
          <div class="idcard-photo">
            <img src="<?= htmlspecialchars($photo) ?>" alt="Member photo">
          </div>
        </div>

        <div class="idcard-cardno"><?= htmlspecialchars($cnSpaced) ?></div>

        <div class="idcard-bottom">
          <div class="idcard-name-block">
            <div class="idcard-label">सदस्यको नाम / MEMBER NAME</div>
            <div class="idcard-name"><?= htmlspecialchars($me['full_name_np'] ?: $me['full_name']) ?></div>
          </div>
          <div class="idcard-valid">
            <div class="idcard-label">VALID&nbsp;THRU</div>
            <div class="idcard-valid-val"><?= $expMo ?>/<?= $expYr ?></div>
          </div>
        </div>

        <div class="idcard-id-row">
          <span class="idcard-status"><i class="fas fa-circle-check"></i> <?php echo $_t('सक्रिय', 'Active'); ?></span>
          <span class="idcard-mid-no"><?php echo $_t('सदस्यता नं', 'Member No'); ?>: <b><?= htmlspecialchars($me['member_id'] ?: ('M-' . str_pad((string) $me['id'], 5, '0', STR_PAD_LEFT))) ?></b></span>
        </div>
      </div>

      <!-- ─── BACK ─── -->
      <div class="idcard idcard-back">
        <div class="idcard-magstripe"></div>
        <div class="idcard-back-body">
          <div class="idcard-sigpanel">
            <span class="idcard-sigpanel-text"><?= htmlspecialchars($me['full_name'] ?: '') ?></span>
            <span class="idcard-cvv-box">
              <span class="cvv-label">CVV</span>
              <span class="cvv-value"><?= $cvv ? htmlspecialchars($cvv) : '••••' ?></span>
            </span>
          </div>

          <?php if ($vCode): ?>
          <div class="idcard-back-vcode">
            <span class="bv-label">VERIFICATION CODE</span>
            <span class="bv-value"><?= htmlspecialchars($vCode) ?></span>
          </div>
          <?php endif; ?>

          <div class="idcard-back-note">
            <?php echo $_t('यो कार्ड सहकारीको सम्पत्ति हो। हराएमा वा चोरी भएमा तुरुन्तै कार्यालयलाई सूचित गर्नुहोस्।', 'This card is property of the cooperative. Report immediately if lost or stolen.'); ?><br>
            <b><?php echo $_t('CVV कसैलाई नदेखाउनुहोस् —', 'Do not share CVV —'); ?></b> <?php echo $_t('verify पोर्टलमा मात्र प्रयोग गर्नुहोस्।', 'use it only in verification portal.'); ?>
          </div>
          <div class="idcard-back-foot">
            <span><i class="fas fa-phone"></i> <?= htmlspecialchars($cardPhone) ?></span>
            <span><i class="fas fa-globe"></i> <?= htmlspecialchars($cardWebsite ?: 'website') ?></span>
          </div>
        </div>
      </div>

    </div>
  </div>

  <!-- Details list below card -->
  <div class="idcard-details">
    <div class="idcard-detail"><div class="dl">CARD NUMBER</div><div class="dv code"><?= htmlspecialchars($cn) ?></div></div>
    <div class="idcard-detail"><div class="dl"><?php echo $_t('जारी मिति', 'Issued Date'); ?></div><div class="dv"><?= date('Y-m-d', $issuedTs) ?></div></div>
    <div class="idcard-detail"><div class="dl"><?php echo $_t('म्याद सकिने मिति', 'Expiry Date'); ?></div><div class="dv <?= $isExpired ? 'dv-expired' : '' ?>"><?= date('Y-m-d', $expiryTs) ?><?= $isExpired ? ($_t(' (म्याद सकिएको)', ' (Expired)')) : '' ?></div></div>
    <div class="idcard-detail"><div class="dl"><?php echo $_t('मोबाइल', 'Mobile'); ?></div><div class="dv"><?= htmlspecialchars($me['mobile'] ?: '-') ?></div></div>
    <div class="idcard-detail"><div class="dl"><?php echo $_t('इमेल', 'Email'); ?></div><div class="dv"><?= htmlspecialchars($me['email'] ?: '-') ?></div></div>
    <div class="idcard-detail" style="grid-column: 1/-1;"><div class="dl"><?php echo $_t('ठेगाना', 'Address'); ?></div><div class="dv"><?= htmlspecialchars($me['address'] ?: '-') ?></div></div>

    <?php if ($vCode || $cvv): ?>
    <div class="idcard-detail idcard-detail-full idcard-detail-vcode">
      <div class="dl idcard-detail-vcode-label"><i class="fas fa-shield-halved"></i> Verification Code</div>
      <div class="dv code idcard-detail-vcode-value"><?= htmlspecialchars($vCode) ?></div>
    </div>
    <div class="idcard-detail idcard-detail-full idcard-detail-cvv">
      <div class="dl idcard-detail-cvv-label"><i class="fas fa-eye-slash"></i> CVV <?php echo $_t('(गोप्य 4 अङ्क)', '(secret 4 digits)'); ?></div>
      <div class="dv code idcard-detail-cvv-value"><?= htmlspecialchars($cvv) ?></div>
      <div class="idcard-detail-cvv-help">
        ⚠ <?php echo $_t('यो CVV कसैलाई share नगर्नुहोस्। यो र Verification Code राखेर मात्र अरूले तपाईंको सदस्यता verify गर्न सक्छन्।', 'Do not share this CVV. Only CVV + verification code can verify your membership.'); ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Help banner -->
  <div class="idcard-verify-help">
    <div class="vh-icon"><i class="fas fa-circle-info"></i></div>
    <div>
      <div class="vh-title"><?php echo $_t('हस्पिटल/पसलमा discount लिँदा सत्यता कसरी देखाउने?', 'How to show verification at hospital/shop discount?'); ?></div>
      <div class="vh-text">
        <?php echo $_t('उनीहरूलाई', 'Ask them to open'); ?> <b><?= htmlspecialchars(($cardWebsite ?: 'website') . '/verify.php') ?></b> <?php echo $_t('मा गएर Verification Code र CVV राख्न भन्नुहोस् — तुरुन्तै तपाईंको name, photo र active status देखिन्छ।', 'and enter verification code + CVV. Your name, photo and active status appear instantly.'); ?>
      </div>
    </div>
  </div>
</div>

<style>
.idcard-page { max-width: 760px; margin: 24px auto; padding: 0 14px; }
.idcard-actions { display: flex; flex-wrap:wrap; gap: 10px; margin-bottom: 20px; }
.idcard-btn {
  flex: 1 1 140px; padding: 11px 14px; border-radius: 10px; border: none; cursor: pointer;
  font-weight: 600; font-size: 13.5px; display: inline-flex; align-items: center; justify-content: center;
  gap: 8px; text-decoration: none; transition: all .15s; font-family: inherit;
}
.idcard-btn-ghost { background: white; color: var(--primary-dark); border: 1.5px solid color-mix(in srgb, var(--primary-color) 24%, #cbd5d0); }
.idcard-btn-primary { background: linear-gradient(135deg, var(--primary-dark), var(--primary-color)); color: var(--text-on-primary,white); }
.idcard-btn:hover { filter: brightness(1.06); transform: translateY(-1px); }
.idcard-btn-fixed { flex:0 0 auto; }
.idcard-btn-danger-outline { flex:0 0 auto; border-color:var(--secondary-color); color:var(--secondary-dark,var(--secondary-color)); }

.idcard-note { max-width:440px; margin:0 auto 14px; border-radius:10px; font-size:13px; }
.idcard-note-rating { margin-bottom:10px; padding:8px 12px; background:color-mix(in srgb, var(--accent-color) 10%, white); border:1px solid color-mix(in srgb, var(--accent-color) 22%, #ddd6fe); color:var(--accent-color); }
.idcard-note-muted { color:var(--text-light,#6b7280); }
.idcard-note-expired { padding:12px 14px; background:color-mix(in srgb, var(--secondary-color) 16%, white); border:2px solid var(--secondary-color); color:var(--secondary-dark,var(--secondary-color)); line-height:1.5; }
.idcard-note-soon { padding:10px 14px; background:color-mix(in srgb, var(--secondary-color) 10%, white); border:1px solid color-mix(in srgb, var(--secondary-color) 30%, #fbbf24); color:var(--secondary-dark,var(--secondary-color)); font-size:12.5px; }
.idcard-note-locked { padding:12px 14px; background:color-mix(in srgb, var(--secondary-color) 14%, white); border:2px solid var(--secondary-color); color:var(--secondary-dark,var(--secondary-color)); line-height:1.6; }
.idcard-note-success { margin-top:8px; font-weight:600; }
.idcard-note-actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
.idcard-form-inline { margin:0; }

.idcard-flip { perspective: 1400px; max-width: 440px; margin: 0 auto 22px; }
.idcard-flip-inner {
  position: relative; width: 100%; aspect-ratio: 1.586 / 1; min-height: 240px;
  transition: transform .7s cubic-bezier(.4,.2,.2,1); transform-style: preserve-3d;
}
.idcard-flip.is-flipped .idcard-flip-inner { transform: rotateY(180deg); }

.idcard {
  position: absolute; inset: 0; backface-visibility: hidden;
  border-radius: 18px; padding: 20px 22px; color: #fff;
  box-shadow: 0 22px 48px rgba(8,55,28,.38), inset 0 1px 0 rgba(255,255,255,.18), inset 0 -1px 0 rgba(0,0,0,.2);
  overflow: hidden; display: flex; flex-direction: column;
  font-family: 'Mukta', 'Noto Sans Devanagari', system-ui, sans-serif;
}
.idcard-front {
  background:
    radial-gradient(120% 160% at 0% 0%, rgba(255,255,255,.18), transparent 55%),
    radial-gradient(120% 160% at 100% 100%, rgba(0,0,0,.25), transparent 55%),
    linear-gradient(135deg, #053a1a 0%, #0a4a25 35%, var(--primary-dark) 65%, #1a8754 100%);
}
.idcard-shine {
  position: absolute; top: -50%; left: -50%; width: 200%; height: 200%;
  background: linear-gradient(120deg, transparent 30%, rgba(255,255,255,.08) 50%, transparent 70%);
  pointer-events: none;
}
.idcard-top { display:flex; justify-content:space-between; align-items:flex-start; }
.idcard-brand { display:flex; align-items:center; gap:9px; }
.idcard-logo { width:34px; height:34px; border-radius:7px; background:white; padding:3px; object-fit:contain; }
.idcard-org { font-weight:800; font-size:.95rem; line-height:1.1; letter-spacing:.01em; }
.idcard-org-en { font-size:.62rem; opacity:.85; letter-spacing:.04em; margin-top:1px; }
.idcard-tag {
  font-size:.6rem; background:rgba(255,255,255,.18); padding:4px 9px;
  border-radius:6px; letter-spacing:.18em; font-weight:700;
  border:1px solid rgba(255,255,255,.18);
}
.idcard-mid { display:flex; align-items:center; gap:14px; margin-top:14px; position:relative; }
.idcard-chip {
  position: relative; width:46px; height:36px; border-radius:6px;
  background: linear-gradient(135deg,color-mix(in srgb, var(--secondary-color) 26%, white) 0%, var(--secondary-color) 50%, var(--secondary-dark,var(--secondary-color)) 100%);
  box-shadow: inset 0 0 0 1px rgba(120,53,15,.4), 0 1px 2px rgba(0,0,0,.25);
}
.idcard-chip span { position:absolute; background:rgba(120,53,15,.4); border-radius:1px; }
.chip-l1 { top:6px;  left:6px;  width:14px; height:2px; }
.chip-l2 { top:13px; left:6px;  width:14px; height:2px; }
.chip-l3 { top:6px;  right:6px; width:14px; height:2px; }
.chip-l4 { top:13px; right:6px; width:14px; height:2px; }
.idcard-wifi { font-size:18px; opacity:.65; }
.idcard-photo {
  margin-left:auto; width:64px; height:80px; border-radius:6px; overflow:hidden;
  background:white; padding:2px; box-shadow:0 2px 6px rgba(0,0,0,.3);
}
.idcard-photo img { width:100%; height:100%; object-fit:cover; border-radius:4px; }
.idcard-cardno {
  margin-top:14px; font-family:'Courier New',monospace; font-size:1.05rem;
  letter-spacing:.18em; font-weight:700; text-shadow:0 1px 2px rgba(0,0,0,.4);
}
.idcard-bottom { display:flex; justify-content:space-between; align-items:flex-end; margin-top:10px; gap:10px; }
.idcard-label { font-size:.5rem; opacity:.7; letter-spacing:.1em; font-weight:600; }
.idcard-name { font-size:.85rem; font-weight:700; line-height:1.2; }
.idcard-valid { text-align:right; }
.idcard-valid-val { font-family:'Courier New',monospace; font-weight:700; font-size:.85rem; letter-spacing:.05em; }
.idcard-id-row { display:flex; justify-content:space-between; align-items:center; margin-top:6px; font-size:.62rem; opacity:.92; }
.idcard-status { display:inline-flex; align-items:center; gap:4px; background:rgba(255,255,255,.18); padding:2px 8px; border-radius:999px; }
.idcard-mid-no b { letter-spacing:.05em; }

/* ─── BACK ─── */
.idcard-back {
  background:
    radial-gradient(120% 160% at 100% 0%, rgba(255,255,255,.12), transparent 50%),
    linear-gradient(135deg, #1f2937 0%, #111827 50%, #0a4a25 100%);
  transform: rotateY(180deg);
}
.idcard-magstripe { height:36px; background:#000; margin:14px -22px 0; }
.idcard-back-body { display:flex; flex-direction:column; flex:1; gap:10px; padding-top:16px; }
.idcard-sigpanel {
  background:repeating-linear-gradient(45deg,#fff,#fff 4px,#f3f4f6 4px,#f3f4f6 8px);
  height:34px; border-radius:4px; display:flex; align-items:center; justify-content:space-between;
  padding:0 8px; color:#111827;
}
.idcard-sigpanel-text { font-style:italic; font-weight:600; font-size:.85rem; }
.idcard-cvv-box { display:flex; flex-direction:column; align-items:flex-end; }
.cvv-label { font-size:.5rem; font-weight:700; letter-spacing:.1em; opacity:.7; }
.cvv-value { font-family:'Courier New',monospace; font-weight:700; font-size:1rem; letter-spacing:.2em; }
.idcard-back-vcode {
  background:rgba(255,255,255,.06); border-radius:6px; padding:6px 10px;
  display:flex; justify-content:space-between; align-items:center;
}
.bv-label { font-size:.55rem; opacity:.75; letter-spacing:.1em; font-weight:700; }
.bv-value { font-family:'Courier New',monospace; font-weight:700; letter-spacing:.16em; font-size:.85rem; }
.idcard-back-note { font-size:.52rem; opacity:.78; line-height:1.5; }
.idcard-back-foot { display:flex; justify-content:space-between; font-size:.55rem; opacity:.85; padding-top:4px; border-top:1px solid rgba(255,255,255,.12); margin-top:auto; }
.idcard-back-foot i { margin-right:3px; }

/* ─── Details list ─── */
.idcard-details {
  display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px;
  background:white; border-radius:14px; padding:14px; box-shadow:0 4px 14px rgba(0,0,0,.06);
}
.idcard-detail .dv-expired { color:var(--secondary-dark,var(--secondary-color)); }
.idcard-detail-full { grid-column:1/-1; }
.idcard-detail-vcode { background:linear-gradient(135deg, color-mix(in srgb, var(--primary-color) 12%, white), color-mix(in srgb, var(--primary-light) 18%, white)); border-color:var(--primary-color); }
.idcard-detail-vcode-label { color:var(--primary-dark,var(--primary-color)); }
.idcard-detail-vcode-value { color:var(--primary-dark,var(--primary-color)); font-size:16px; }
.idcard-detail-cvv { background:color-mix(in srgb, var(--secondary-color) 16%, white); border-color:var(--secondary-color); }
.idcard-detail-cvv-label { color:var(--secondary-dark,var(--secondary-color)); }
.idcard-detail-cvv-value { color:var(--secondary-dark,var(--secondary-color)); font-size:18px; letter-spacing:.3em; }
.idcard-detail-cvv-help { font-size:11px; color:var(--secondary-dark,var(--secondary-color)); margin-top:6px; line-height:1.5; }
.idcard-detail { background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:10px 12px; }
.idcard-detail .dl { font-size:.68rem; font-weight:700; color:#6b7280; letter-spacing:.04em; margin-bottom:4px; }
.idcard-detail .dv { font-size:.85rem; color:#111827; word-break:break-word; }
.idcard-detail .dv.code { font-family:'Courier New',monospace; letter-spacing:.05em; font-weight:700; }

.idcard-verify-help {
  margin-top:18px; background:#fef2f2; border:1px solid #fecaca;
  border-radius:10px; padding:14px; display:flex; gap:10px; align-items:flex-start;
}
.vh-icon { color:var(--secondary-color,#c0392b); font-size:1.4rem; flex-shrink:0; }
.vh-title { font-weight:700; font-size:.88rem; color:var(--secondary-dark,#922b21); margin-bottom:4px; }
.vh-text { font-size:.78rem; color:var(--secondary-dark,#922b21); line-height:1.55; }
.vh-text b { font-family:'Courier New',monospace; background:white; padding:1px 6px; border-radius:4px; }

@media (max-width:480px) {
  .idcard-flip { max-width:100%; }
  .idcard { padding:16px 18px; }
  .idcard-org { font-size:.85rem; }
  .idcard-cardno { font-size:.95rem; letter-spacing:.13em; }
  .idcard-details { grid-template-columns:1fr; }
}
@media print {
  .idcard-actions, .idcard-verify-help, .idcard-details { display:none !important; }
  body { background:#fff !important; }
}
</style>

<script>
  (function () {
    var btn  = document.getElementById('idcardFlipBtn');
    var flip = document.getElementById('idcardFlip');
    if (btn && flip) btn.addEventListener('click', function () { flip.classList.toggle('is-flipped'); });
    if (flip) flip.addEventListener('click', function (e) {
      if (e.target.closest('a, button')) return;
      flip.classList.toggle('is-flipped');
    });
  })();
</script>

<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
