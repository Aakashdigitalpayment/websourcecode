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
 *   #5 — Cleaner header (handled in member chrome).
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

    /* Member display ID + derived CVV (name first3 + member last4) */
    $memberDispId = (string)(($me['sadasyata_number'] ?? '') ?: ($me['member_card_no'] ?? '') ?: ('M-' . str_pad((string)($me['id'] ?? 0), 5, '0', STR_PAD_LEFT)));
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
            $newCardNo      = generateCardNumber((int) $me['id']);   // ← v10.4 helper (legacy internal)
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

/* ─── Card metadata — visible number = सदस्यता नं; CVV = derived formula ─── */
$cn       = (string)(($me['member_id'] ?? '') ?: ($me['sadasyata_number'] ?? '') ?: ($me['member_card_no'] ?? '') ?: ('M-' . str_pad((string)$me['id'], 5, '0', STR_PAD_LEFT)));
$cnSpaced = $cn;
$vCode    = $me['verification_code'] ?? '';
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
        <?php echo function_exists('csrfField') ? csrfField() : ''; ?>
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

  <!-- ═══════ PREMIUM FLIP MEMBER CARD ═══════ -->
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
            <span class="idcard-tag">MEMBER</span>
            <span class="idcard-tag-sub">DIGITAL ID</span>
          </div>
        </div>

        <div class="idcard-mid">
          <div class="idcard-chip-stack">
            <div class="idcard-chip" aria-hidden="true">
              <span class="chip-l1"></span><span class="chip-l2"></span>
              <span class="chip-l3"></span><span class="chip-l4"></span>
              <span class="chip-center"></span>
            </div>
            <span class="idcard-contactless" aria-hidden="true">
              <i class="fas fa-wifi"></i>
            </span>
          </div>
          <div class="idcard-photo">
            <img src="<?= htmlspecialchars($photo) ?>" alt="Member photo">
            <span class="idcard-photo-ring" aria-hidden="true"></span>
          </div>
        </div>

        <div class="idcard-number-block">
          <div class="idcard-label"><?php echo $_t('सदस्यता नं. / MEMBER ID', 'MEMBER ID'); ?></div>
          <div class="idcard-cardno"><?= htmlspecialchars($cnSpaced) ?></div>
        </div>

        <div class="idcard-bottom">
          <div class="idcard-name-block">
            <div class="idcard-label"><?php echo $_t('सदस्यको नाम', 'CARD HOLDER'); ?></div>
            <div class="idcard-name"><?= htmlspecialchars($memberDisplayName) ?></div>
          </div>
          <div class="idcard-valid">
            <div class="idcard-label">VALID THRU</div>
            <div class="idcard-valid-val"><?= $expMo ?>/<?= $expYr ?></div>
          </div>
        </div>

        <div class="idcard-id-row">
          <span class="idcard-status"><i class="fas fa-circle-check"></i> <?php echo $_t('सक्रिय', 'Active'); ?></span>
          <span class="idcard-mid-no"><i class="fas fa-globe"></i> <?= htmlspecialchars($cardWebsite ?: 'website') ?></span>
        </div>
      </div>

      <!-- ─── BACK ─── -->
      <div class="idcard idcard-back">
        <div class="idcard-mesh idcard-mesh-back" aria-hidden="true"></div>
        <div class="idcard-magstripe" aria-hidden="true">
          <span class="idcard-magstripe-gloss"></span>
        </div>
        <div class="idcard-back-body">
          <div class="idcard-sigpanel">
            <div class="idcard-sig-left">
              <span class="idcard-sig-label">AUTHORIZED SIGNATURE</span>
              <span class="idcard-sigpanel-text"><?= htmlspecialchars($memberNameEn ?: $memberDisplayName) ?></span>
            </div>
            <span class="idcard-cvv-box" title="CVV">
              <span class="cvv-label">CVV</span>
              <span class="cvv-value"><?= $cvv !== '' ? htmlspecialchars($cvv) : '••••' ?></span>
            </span>
          </div>

          <div class="idcard-back-meta">
            <div class="idcard-back-vcode">
              <span class="bv-label"><?php echo $_t('सदस्यता नं.', 'MEMBER ID'); ?></span>
              <span class="bv-value"><?= htmlspecialchars($cn) ?></span>
            </div>
            <div class="idcard-back-issued">
              <span class="bv-label"><?php echo $_t('जारी', 'ISSUED'); ?></span>
              <span class="bv-value bv-value-sm"><?= date('m/y', $issuedTs) ?></span>
            </div>
          </div>

          <div class="idcard-holo" aria-hidden="true">
            <span></span><span></span><span></span>
          </div>

          <div class="idcard-back-note">
            <?php echo $_t('यो कार्ड सहकारीको सम्पत्ति हो। हराएमा तुरुन्तै कार्यालयलाई सूचित गर्नुहोस्।', 'This card is property of the cooperative. Report loss immediately.'); ?>
            <br>
            <b><?php echo $_t('प्रमाणीकरण:', 'Verify:'); ?></b>
            <?= htmlspecialchars(($cardWebsite ?: 'website') . '/verify.php') ?>
            — <?php echo $_t('नाम + सदस्यता नं. (CVV ऐच्छिक)।', 'name + member ID (CVV optional).'); ?>
          </div>
          <div class="idcard-back-foot">
            <span><i class="fas fa-phone"></i> <?= htmlspecialchars($cardPhone) ?></span>
            <?php if ($cardLogoUrl !== ''): ?>
            <img src="<?= htmlspecialchars($cardLogoUrl) ?>" alt="" class="idcard-back-logo" onerror="this.style.display='none'">
            <?php endif; ?>
            <span><i class="fas fa-globe"></i> <?= htmlspecialchars($cardWebsite ?: 'website') ?></span>
          </div>
        </div>
      </div>

    </div>
  </div>
  <p class="idcard-flip-hint"><i class="fas fa-hand-pointer"></i> <?php echo $_t('कार्डमा टच/क्लिक गरेर उल्ट्याउनुहोस्', 'Tap or click the card to flip'); ?></p>

  <!-- Details list below card -->
  <div class="idcard-details">
    <div class="idcard-detail"><div class="dl"><?php echo $_t('सदस्यता नं.', 'MEMBER ID'); ?></div><div class="dv code"><?= htmlspecialchars($cn) ?></div></div>
    <div class="idcard-detail idcard-detail-cvv">
      <div class="dl idcard-detail-cvv-label"><i class="fas fa-shield-halved"></i> CVV</div>
      <div class="dv code idcard-detail-cvv-value"><?= htmlspecialchars($cvv !== '' ? $cvv : '—') ?></div>
    </div>
    <div class="idcard-detail"><div class="dl"><?php echo $_t('जारी मिति', 'Issued Date'); ?></div><div class="dv"><?= date('Y-m-d', $issuedTs) ?></div></div>
    <div class="idcard-detail"><div class="dl"><?php echo $_t('म्याद सकिने मिति', 'Expiry Date'); ?></div><div class="dv <?= $isExpired ? 'dv-expired' : '' ?>"><?= date('Y-m-d', $expiryTs) ?><?= $isExpired ? ($_t(' (म्याद सकिएको)', ' (Expired)')) : '' ?></div></div>
    <div class="idcard-detail"><div class="dl"><?php echo $_t('मोबाइल', 'Mobile'); ?></div><div class="dv"><?= htmlspecialchars($me['mobile'] ?: '-') ?></div></div>
    <div class="idcard-detail"><div class="dl"><?php echo $_t('इमेल', 'Email'); ?></div><div class="dv"><?= htmlspecialchars($me['email'] ?: '-') ?></div></div>
    <div class="idcard-detail" style="grid-column: 1/-1;"><div class="dl"><?php echo $_t('ठेगाना', 'Address'); ?></div><div class="dv"><?= htmlspecialchars($me['address'] ?: '-') ?></div></div>
    <div class="idcard-detail" style="grid-column: 1/-1;"><div class="dl"><?php echo $_t('वेबसाइट', 'Website'); ?></div><div class="dv"><?= htmlspecialchars($cardWebsite ?: '-') ?></div></div>

    <div class="idcard-detail idcard-detail-full" style="background:color-mix(in srgb, var(--primary-color) 8%, white); border-color:color-mix(in srgb, var(--primary-color) 28%, #e5e7eb);">
      <div class="dl" style="color:var(--primary-dark);"><i class="fas fa-info-circle"></i> <?php echo $_t('CVV कसरी बन्छ?', 'How is CVV built?'); ?></div>
      <div class="idcard-detail-cvv-help" style="margin-top:4px; color:#374151;">
        <?php echo $_t('नामको पहिलो शब्दका पहिलो ३ अक्षर + सदस्यता नं. को पछिल्लो ४ अङ्क। याद राख्नु पर्दैन — कार्डको पछाडि देखिन्छ।', 'First 3 characters of first name + last 4 digits of member ID. No need to memorize — shown on the back of the card.'); ?>
      </div>
    </div>
  </div>

  <!-- Help banner -->
  <div class="idcard-verify-help">
    <div class="vh-icon"><i class="fas fa-circle-info"></i></div>
    <div>
      <div class="vh-title"><?php echo $_t('हस्पिटल/पसलमा discount लिँदा सत्यता कसरी देखाउने?', 'How to show verification at hospital/shop discount?'); ?></div>
      <div class="vh-text">
        <?php echo $_t('उनीहरूलाई', 'Ask them to open'); ?> <b><?= htmlspecialchars(($cardWebsite ?: 'website') . '/verify.php') ?></b> <?php echo $_t('मा गएर तपाईंको नाम र सदस्यता नं. राख्न भन्नुहोस् — CVV कार्डमा पनि छ, ऐच्छिक रूपमा राख्न सकिन्छ।', 'and enter your name + member ID. CVV is also printed on the card and can be entered optionally.'); ?>
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

.idcard-flip {
  perspective: 1600px; max-width: 460px; margin: 0 auto 8px;
  cursor: pointer; outline: none;
}
.idcard-flip:focus-visible { box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary-color) 45%, transparent); border-radius: 22px; }
.idcard-flip-inner {
  position: relative; width: 100%; aspect-ratio: 1.586 / 1; min-height: 250px;
  transition: transform .75s cubic-bezier(.2,.8,.2,1); transform-style: preserve-3d;
  filter: drop-shadow(0 24px 40px rgba(5, 40, 22, .42));
}
.idcard-flip.is-flipped .idcard-flip-inner { transform: rotateY(180deg); }
.idcard-flip-hint {
  text-align: center; font-size: .75rem; color: #6b7280; margin: 0 auto 20px; max-width: 460px;
}
.idcard-flip-hint i { margin-right: 4px; opacity: .7; }

.idcard {
  position: absolute; inset: 0; backface-visibility: hidden; -webkit-backface-visibility: hidden;
  border-radius: 20px; padding: 18px 20px; color: #fff;
  overflow: hidden; display: flex; flex-direction: column;
  font-family: 'Mukta', 'Noto Sans Devanagari', system-ui, sans-serif;
  border: 1px solid rgba(255,255,255,.14);
  box-shadow:
    inset 0 1px 0 rgba(255,255,255,.22),
    inset 0 -1px 0 rgba(0,0,0,.22);
}
.idcard-front {
  background:
    linear-gradient(145deg, rgba(255,255,255,.12) 0%, transparent 38%),
    linear-gradient(160deg, #03401f 0%, #0a5c32 38%, #117a48 72%, #0d6b3c 100%);
}
.idcard-mesh {
  position: absolute; inset: 0; pointer-events: none; opacity: .22;
  background-image:
    radial-gradient(circle at 20% 20%, rgba(255,255,255,.35) 0 1px, transparent 1.5px),
    radial-gradient(circle at 80% 60%, rgba(255,255,255,.2) 0 1px, transparent 1.5px);
  background-size: 18px 18px, 22px 22px;
  mix-blend-mode: soft-light;
}
.idcard-mesh-back { opacity: .12; }
.idcard-orb {
  position: absolute; border-radius: 50%; pointer-events: none; filter: blur(2px);
}
.idcard-orb-a {
  width: 180px; height: 180px; right: -50px; top: -60px;
  background: radial-gradient(circle, rgba(255,215,128,.28), transparent 68%);
  animation: idcardOrbFloat 8s ease-in-out infinite;
}
.idcard-orb-b {
  width: 140px; height: 140px; left: -40px; bottom: -50px;
  background: radial-gradient(circle, rgba(120,255,200,.18), transparent 70%);
  animation: idcardOrbFloat 10s ease-in-out infinite reverse;
}
@keyframes idcardOrbFloat {
  0%, 100% { transform: translate(0,0) scale(1); }
  50% { transform: translate(-8px, 10px) scale(1.06); }
}
.idcard-shine {
  position: absolute; top: -60%; left: -40%; width: 70%; height: 220%;
  background: linear-gradient(105deg, transparent 40%, rgba(255,255,255,.16) 50%, transparent 60%);
  pointer-events: none;
  animation: idcardSheen 5.5s ease-in-out infinite;
}
@keyframes idcardSheen {
  0%, 100% { transform: translateX(-20%) rotate(18deg); opacity: .35; }
  50% { transform: translateX(160%) rotate(18deg); opacity: .7; }
}
.idcard-edge {
  position: absolute; inset: 8px; border-radius: 14px; pointer-events: none;
  border: 1px solid rgba(255,255,255,.1);
}

.idcard-top { display:flex; justify-content:space-between; align-items:flex-start; position: relative; z-index: 1; }
.idcard-brand { display:flex; align-items:center; gap:10px; min-width: 0; }
.idcard-logo-wrap {
  width: 40px; height: 40px; border-radius: 10px; flex-shrink: 0;
  background: linear-gradient(145deg, #fff, #f3f4f6);
  padding: 4px; box-shadow: 0 4px 12px rgba(0,0,0,.25), inset 0 1px 0 #fff;
}
.idcard-logo { width:100%; height:100%; object-fit:contain; display:block; }
.idcard-brand-text { min-width: 0; }
.idcard-org {
  font-weight:800; font-size: .98rem; line-height:1.15; letter-spacing:.01em;
  text-shadow: 0 1px 8px rgba(0,0,0,.25);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px;
}
.idcard-org-en { font-size:.62rem; opacity:.88; letter-spacing:.05em; margin-top:2px; font-weight:500; }
.idcard-tag-stack { text-align: right; flex-shrink: 0; }
.idcard-tag {
  display: block; font-size:.58rem; background:rgba(255,255,255,.16); padding:4px 9px;
  border-radius:999px; letter-spacing:.2em; font-weight:800;
  border:1px solid rgba(255,255,255,.22); backdrop-filter: blur(6px);
}
.idcard-tag-sub {
  display:block; font-size:.48rem; letter-spacing:.16em; opacity:.75; margin-top:4px; font-weight:600;
}

.idcard-mid { display:flex; align-items:center; gap:14px; margin-top:12px; position:relative; z-index:1; }
.idcard-chip-stack { display:flex; align-items:center; gap:10px; }
.idcard-chip {
  position: relative; width:48px; height:36px; border-radius:7px;
  background:
    linear-gradient(145deg, #f6e27a 0%, #d4a017 45%, #b8860b 100%);
  box-shadow:
    inset 0 0 0 1px rgba(120,53,15,.35),
    0 3px 8px rgba(0,0,0,.28);
}
.idcard-chip span { position:absolute; background:rgba(90,40,10,.35); border-radius:1px; }
.chip-l1 { top:7px;  left:6px;  width:14px; height:2px; }
.chip-l2 { top:14px; left:6px;  width:14px; height:2px; }
.chip-l3 { top:7px;  right:6px; width:14px; height:2px; }
.chip-l4 { top:14px; right:6px; width:14px; height:2px; }
.chip-center {
  top: 50%; left: 50%; width: 10px; height: 14px; margin: -7px 0 0 -5px;
  border: 1px solid rgba(90,40,10,.4); border-radius: 2px; background: transparent !important;
}
.idcard-contactless {
  font-size: 17px; opacity: .7; transform: rotate(90deg);
  filter: drop-shadow(0 1px 2px rgba(0,0,0,.3));
}
.idcard-photo {
  margin-left:auto; width:68px; height:84px; border-radius:10px; overflow:hidden;
  background: linear-gradient(145deg, #fff, #e8e8e8); padding:3px;
  box-shadow: 0 6px 16px rgba(0,0,0,.35), inset 0 0 0 1px rgba(255,255,255,.6);
  position: relative;
}
.idcard-photo img { width:100%; height:100%; object-fit:cover; border-radius:7px; display:block; }
.idcard-photo-ring {
  position: absolute; inset: 0; border-radius: 10px; pointer-events: none;
  box-shadow: inset 0 0 0 1px rgba(255,215,128,.35);
}

.idcard-number-block { margin-top: 12px; position: relative; z-index: 1; }
.idcard-cardno {
  margin-top: 3px;
  font-family: 'Courier New', ui-monospace, monospace;
  font-size: 1.18rem; letter-spacing: .14em; font-weight: 700;
  color: #fff;
  text-shadow: 0 2px 6px rgba(0,0,0,.35);
}
.idcard-bottom { display:flex; justify-content:space-between; align-items:flex-end; margin-top:10px; gap:10px; position: relative; z-index: 1; }
.idcard-label { font-size:.5rem; opacity:.72; letter-spacing:.12em; font-weight:700; text-transform: uppercase; }
.idcard-name { font-size:.9rem; font-weight:700; line-height:1.2; margin-top:2px; text-shadow: 0 1px 4px rgba(0,0,0,.25); }
.idcard-valid { text-align:right; flex-shrink: 0; }
.idcard-valid-val { font-family:'Courier New',monospace; font-weight:700; font-size:.9rem; letter-spacing:.08em; margin-top:2px; }
.idcard-id-row {
  display:flex; justify-content:space-between; align-items:center; margin-top:auto; padding-top:8px;
  font-size:.62rem; opacity:.95; position: relative; z-index: 1;
  border-top: 1px solid rgba(255,255,255,.12);
}
.idcard-status {
  display:inline-flex; align-items:center; gap:5px;
  background:rgba(34,197,94,.22); padding:3px 9px; border-radius:999px;
  border: 1px solid rgba(134,239,172,.35); font-weight: 700;
}
.idcard-mid-no { opacity: .9; letter-spacing: .02em; }
.idcard-mid-no i { margin-right: 4px; opacity: .8; }

/* ─── BACK ─── */
.idcard-back {
  background:
    linear-gradient(160deg, #0b1f14 0%, #123526 42%, #0f2f22 100%);
  transform: rotateY(180deg);
}
.idcard-magstripe {
  height: 42px; background: linear-gradient(180deg, #111 0%, #000 40%, #1a1a1a 100%);
  margin: 10px -20px 0; position: relative; overflow: hidden;
}
.idcard-magstripe-gloss {
  position: absolute; inset: 0;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,.08), transparent);
}
.idcard-back-body { display:flex; flex-direction:column; flex:1; gap:9px; padding-top:14px; position: relative; z-index: 1; }
.idcard-sigpanel {
  background:
    linear-gradient(180deg, #fafafa, #ececec),
    repeating-linear-gradient(90deg, transparent, transparent 7px, rgba(0,0,0,.04) 7px, rgba(0,0,0,.04) 8px);
  min-height: 40px; border-radius: 8px; display:flex; align-items:stretch; justify-content:space-between;
  padding: 6px 8px 6px 10px; color:#111827;
  box-shadow: inset 0 0 0 1px rgba(0,0,0,.08);
}
.idcard-sig-left { display:flex; flex-direction:column; justify-content:center; min-width:0; flex:1; }
.idcard-sig-label { font-size: .42rem; letter-spacing: .12em; color: #6b7280; font-weight: 700; }
.idcard-sigpanel-text {
  font-family: 'Segoe Script', 'Brush Script MT', cursive, Mukta, sans-serif;
  font-weight:600; font-size:.92rem; line-height:1.1;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.idcard-cvv-box {
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  background: #111827; color: #fff; border-radius: 7px; padding: 4px 10px; min-width: 72px;
  box-shadow: 0 2px 8px rgba(0,0,0,.25);
}
.cvv-label { font-size:.48rem; font-weight:800; letter-spacing:.14em; opacity:.75; }
.cvv-value { font-family:'Courier New',monospace; font-weight:800; font-size:.95rem; letter-spacing:.1em; color: #fde68a; }

.idcard-back-meta { display:flex; gap:8px; }
.idcard-back-vcode, .idcard-back-issued {
  background:rgba(255,255,255,.07); border-radius:8px; padding:7px 10px;
  display:flex; flex-direction:column; gap:2px;
  border: 1px solid rgba(255,255,255,.08);
}
.idcard-back-vcode { flex: 1; }
.idcard-back-issued { flex: 0 0 auto; text-align: center; min-width: 64px; }
.bv-label { font-size:.5rem; opacity:.72; letter-spacing:.1em; font-weight:700; }
.bv-value { font-family:'Courier New',monospace; font-weight:700; letter-spacing:.1em; font-size:.88rem; }
.bv-value-sm { font-size: .82rem; }

.idcard-holo {
  height: 10px; border-radius: 999px; overflow: hidden; display: flex;
  box-shadow: inset 0 0 0 1px rgba(255,255,255,.15);
}
.idcard-holo span { flex: 1; }
.idcard-holo span:nth-child(1) { background: linear-gradient(90deg, #34d399, #60a5fa); }
.idcard-holo span:nth-child(2) { background: linear-gradient(90deg, #60a5fa, #c084fc, #f472b6); }
.idcard-holo span:nth-child(3) { background: linear-gradient(90deg, #f472b6, #fbbf24, #34d399); }

.idcard-back-note { font-size:.55rem; opacity:.82; line-height:1.55; }
.idcard-back-foot {
  display:flex; justify-content:space-between; align-items:center; gap:8px;
  font-size:.55rem; opacity:.9; padding-top:6px;
  border-top:1px solid rgba(255,255,255,.12); margin-top:auto;
}
.idcard-back-foot i { margin-right:3px; }
.idcard-back-logo {
  width: 22px; height: 22px; border-radius: 5px; object-fit: contain;
  background: #fff; padding: 2px; flex-shrink: 0;
}

/* ─── Details list ─── */
.idcard-details {
  display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:10px;
  background:white; border-radius:14px; padding:14px; box-shadow:0 4px 14px rgba(0,0,0,.06);
}
.idcard-detail .dv-expired { color:var(--secondary-dark,var(--secondary-color)); }
.idcard-detail-full { grid-column:1/-1; }
.idcard-detail-cvv { background:color-mix(in srgb, var(--secondary-color) 12%, white); border-color:color-mix(in srgb, var(--secondary-color) 35%, #e5e7eb); }
.idcard-detail-cvv-label { color:var(--secondary-dark,var(--secondary-color)); }
.idcard-detail-cvv-value { color:var(--secondary-dark,var(--secondary-color)); font-size:1.05rem; letter-spacing:.12em; }
.idcard-detail-cvv-help { font-size:11px; color:var(--secondary-dark,var(--secondary-color)); margin-top:6px; line-height:1.5; }
.idcard-detail { background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:10px 12px; }
.idcard-detail .dl { font-size:.68rem; font-weight:700; color:#6b7280; letter-spacing:.04em; margin-bottom:4px; }
.idcard-detail .dv { font-size:.85rem; color:#111827; word-break:break-word; }
.idcard-detail .dv.code { font-family:'Courier New',monospace; letter-spacing:.05em; font-weight:700; }

.idcard-verify-help {
  margin-top:18px; background:color-mix(in srgb, var(--primary-color) 8%, white);
  border:1px solid color-mix(in srgb, var(--primary-color) 25%, #e5e7eb);
  border-radius:10px; padding:14px; display:flex; gap:10px; align-items:flex-start;
}
.vh-icon { color:var(--primary-dark, #0a4a25); font-size:1.4rem; flex-shrink:0; }
.vh-title { font-weight:700; font-size:.88rem; color:var(--primary-dark,#0a4a25); margin-bottom:4px; }
.vh-text { font-size:.78rem; color:#374151; line-height:1.55; }
.vh-text b { font-family:'Courier New',monospace; background:white; padding:1px 6px; border-radius:4px; }

@media (max-width:480px) {
  .idcard-flip { max-width:100%; }
  .idcard { padding:14px 16px; }
  .idcard-org { font-size:.82rem; max-width: 160px; }
  .idcard-cardno { font-size:1rem; letter-spacing:.1em; }
  .idcard-photo { width:58px; height:72px; }
  .idcard-details { grid-template-columns:1fr; }
  .cvv-value { font-size: .85rem; }
}
@media (prefers-reduced-motion: reduce) {
  .idcard-shine, .idcard-orb-a, .idcard-orb-b { animation: none; }
  .idcard-flip-inner { transition: none; }
}
@media print {
  .idcard-actions, .idcard-verify-help, .idcard-details, .idcard-flip-hint, .idcard-note { display:none !important; }
  .idcard-flip-inner { filter: none; transform: none !important; }
  .idcard-front { position: relative; }
  .idcard-back { display: none; }
  body { background:#fff !important; }
}
</style>

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
