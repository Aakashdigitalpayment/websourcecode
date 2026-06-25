<?php
/**
 * QR-Based Program Attendance Check-in (Public)
 * URL: attend.php?token=XXXX
 * Member opens QR from phone → login check → mark present
 */
require_once __DIR__ . '/_bootstrap.php';
require_once 'includes/ensure-tables.php';




$token = trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['token'] ?? ''));
$db    = null;
$prog  = null;
$err   = '';
$done  = false;
$requestSubmitted = false;
$alreadyDone = false;
$memberId = null;
$memName  = '';
$memCard  = '';

try { $db = getDB(); } catch (Throwable $e) { $err = 'Database unavailable.'; }

if ($db) {
    ensureProgramTables($db);
}

/* ── Load program from token ── */
if ($db && $token) {
    try {
        $st = $db->prepare("SELECT * FROM upcoming_programs WHERE qr_token=? AND is_active=1 LIMIT 1");
        $st->execute([$token]);
        $prog = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$prog) $err = t('यो QR code मान्य छैन वा कार्यक्रम समाप्त भयो।', 'This QR code is invalid or the program has ended.');
        if ($prog && !empty($prog['qr_starts_at']) && strtotime((string)$prog['qr_starts_at']) > time()) {
            $err = t('यो QR scan समय अझै सुरु भएको छैन।', 'This QR scan window has not started yet.');
            $prog = null;
        } elseif ($prog && !empty($prog['qr_expires_at']) && strtotime((string)$prog['qr_expires_at']) < time()) {
            $err = t('यो QR scan समय समाप्त भइसकेको छ।', 'This QR scan window has expired.');
            $prog = null;
        }
    } catch (Throwable $e) { $err = t('कार्यक्रम लोड गर्न सकिएन।', 'Could not load program.'); }
} elseif (!$token) {
    $err = t('QR code token आवश्यक छ।', 'QR code token is required.');
}

/* ── Check if member is already logged in via member portal session ── */
if (!empty($_SESSION['member_id']) && $prog && $db) {
    $memberId = (int)$_SESSION['member_id'];
    try {
        $ms = $db->prepare("SELECT m.name, m.sadasyata_number, k.member_id AS kyc_member_id
                            FROM members m
                            LEFT JOIN kyc_applications k ON k.id = m.kyc_application_id
                            WHERE m.id=? LIMIT 1");
        $ms->execute([$memberId]);
        $mr = $ms->fetch(PDO::FETCH_ASSOC) ?: [];
        $memName = trim((string)($mr['name'] ?? ''));
        $kycMid = trim((string)($mr['kyc_member_id'] ?? ''));
        $memCard = $kycMid !== '' ? $kycMid : trim((string)($mr['sadasyata_number'] ?? ''));
        /* Check if already checked in */
        $dup = $db->prepare("SELECT id FROM member_program_attendance WHERE member_id=? AND program_id=? LIMIT 1");
        $dup->execute([$memberId, (int)$prog['id']]);
        if ($dup->fetchColumn()) $alreadyDone = true;
    } catch (Throwable $e) { $memberId = null; }
}

/* ── Handle check-in form submit ── */
$csrfOk = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $prog && $db && !$err) {
    if (!verifyCSRFToken()) {
        $err = 'Security check failed.';
        $csrfOk = false;
    }
    if ($csrfOk) {
        $action = $_POST['action'] ?? '';
        if ($action === 'checkin_logged') {
            if ($memberId && !$alreadyDone) {
                try {
                    $pend = $db->prepare("SELECT id FROM member_program_attendance_requests WHERE member_id=? AND program_id=? AND status='pending' LIMIT 1");
                    $pend->execute([$memberId, (int)$prog['id']]);
                    if ($pend->fetchColumn()) {
                        $requestSubmitted = true;
                    } else {
                        $ins = $db->prepare("INSERT INTO member_program_attendance_requests
                            (member_id, member_card_no, member_name, program_id, program_title, status, verified_by_ip, user_agent, source)
                            VALUES (?,?,?,?,?,'pending',?,?,?)");
                        $ins->execute([
                            $memberId,
                            $memCard,
                            $memName,
                            (int)$prog['id'],
                            mb_substr((string)$prog['title'], 0, 180),
                            $_SERVER['REMOTE_ADDR'] ?? '',
                            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                            'member_portal_qr_pending'
                        ]);
                        $requestSubmitted = true;
                    }
                } catch (Throwable $e) { $err = 'Attendance अनुरोध पठाउन समस्या भयो।'; }
            }
        } elseif ($action === 'checkin_manual') {
            $inputCard  = trim(preg_replace('/\s+/', '', strtoupper($_POST['member_card'] ?? '')));
            $inputPhone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
            $inputName = mb_substr(trim((string)($_POST['member_name'] ?? '')), 0, 150, 'UTF-8');
            $inputAddress = mb_substr(trim((string)($_POST['member_address'] ?? '')), 0, 255, 'UTF-8');
            if (!$inputCard && !$inputPhone && $inputName === '') {
                $err = 'सदस्यता नम्बर, फोन वा नाम आवश्यक छ।';
            } else {
                try {
                    $kw = []; $kp = [];
                    if ($inputCard)  { $kw[] = 'UPPER(sadasyata_number)=?'; $kp[] = $inputCard; }
                    if ($inputPhone) { $kw[] = 'phone=?'; $kp[] = $inputPhone; }
                    $mRow = null;
                    if (!empty($kw)) {
                        $mst = $db->prepare("SELECT m.id, m.name, m.sadasyata_number, m.phone FROM members m WHERE (" . implode(' OR ', $kw) . ") AND is_active=1 LIMIT 1");
                        $mst->execute($kp);
                        $mRow = $mst->fetch(PDO::FETCH_ASSOC) ?: null;
                    }
                    if (!$mRow && ($inputCard || $inputPhone)) {
                        $kwk = []; $kpk = [];
                        if ($inputCard)  { $kwk[] = 'UPPER(member_id)=?'; $kpk[] = $inputCard; }
                        if ($inputPhone) { $kwk[] = 'mobile=?'; $kpk[] = $inputPhone; }
                        if (!empty($kwk)) {
                            $kst = $db->prepare("SELECT m.id, m.name, m.sadasyata_number, m.phone FROM members m
                                JOIN kyc_applications k ON (LOWER(k.email)=LOWER(m.email) OR k.mobile=m.phone)
                                WHERE (" . implode(' OR ', $kwk) . ") AND m.is_active=1 LIMIT 1");
                            $kst->execute($kpk);
                            $mRow = $kst->fetch(PDO::FETCH_ASSOC) ?: null;
                        }
                    }
                    if ($mRow) {
                        $mId = (int)$mRow['id'];
                        $mCard = trim((string)($mRow['sadasyata_number'] ?? ''));
                        $mNm = trim((string)($mRow['name'] ?? ''));
                        $mPhone = trim((string)($mRow['phone'] ?? $inputPhone));
                        $dup = $db->prepare("SELECT id FROM member_program_attendance WHERE member_id=? AND program_id=? LIMIT 1");
                        $dup->execute([$mId, (int)$prog['id']]);
                        if ($dup->fetchColumn()) {
                    $alreadyDone = true; $memName = $mNm; $memCard = $mCard; $memberId = $mId;
                        } else {
                            $pend = $db->prepare("SELECT id FROM member_program_attendance_requests WHERE member_id=? AND program_id=? AND status='pending' LIMIT 1");
                            $pend->execute([$mId, (int)$prog['id']]);
                            if ($pend->fetchColumn()) {
                                $err = t('तपाईंको उपस्थिति अनुरोध पहिले नै Admin सामु प्रक्रियामा छ। स्वीकृत भएपछि सूचीमा देखिनेछ।', 'Your attendance request is already pending with admin. It will appear in the list after approval.');
                            } else {
                                $ins = $db->prepare("INSERT INTO member_program_attendance_requests
                                    (member_id, member_card_no, member_name, member_phone, member_address, program_id, program_title, status, verified_by_ip, user_agent, source)
                                    VALUES (?,?,?,?,?,?,?,'pending',?,?,?)");
                                $ins->execute([
                                    $mId,
                                    $mCard,
                                    $mNm,
                                    $mPhone,
                                    $inputAddress,
                                    (int)$prog['id'],
                                    mb_substr((string)$prog['title'], 0, 180),
                                    $_SERVER['REMOTE_ADDR'] ?? '',
                                    mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                                    'public_qr_request',
                                ]);
                                $requestSubmitted = true;
                                $memName = $mNm;
                                $memCard = $mCard;
                                $memberId = $mId;
                            }
                        }
                    } else {
                        $pend = $db->prepare("SELECT id FROM member_program_attendance_requests WHERE program_id=? AND status='pending' AND (member_card_no=? OR member_phone=?) LIMIT 1");
                        $pend->execute([(int)$prog['id'], $inputCard, $inputPhone]);
                        if ($pend->fetchColumn()) {
                            $err = t('तपाईंको उपस्थिति अनुरोध पहिले नै Admin सामु प्रक्रियामा छ। स्वीकृत भएपछि सूचीमा देखिनेछ।', 'Your attendance request is already pending with admin. It will appear in the list after approval.');
                        } else {
                            $ins = $db->prepare("INSERT INTO member_program_attendance_requests
                                (member_id, member_card_no, member_name, member_phone, member_address, program_id, program_title, status, verified_by_ip, user_agent, source)
                                VALUES (?,?,?,?,?,?,?,'pending',?,?,?)");
                            $ins->execute([
                                0,
                                $inputCard,
                                $inputName,
                                $inputPhone,
                                $inputAddress,
                                (int)$prog['id'],
                                mb_substr((string)$prog['title'], 0, 180),
                                $_SERVER['REMOTE_ADDR'] ?? '',
                                mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                                'public_qr_unmatched_request',
                            ]);
                            $requestSubmitted = true;
                            $memName = $inputName;
                            $memCard = $inputCard;
                        }
                    }
                } catch (Throwable $e) { $err = t('Check-in गर्न समस्या भयो।', 'There was a problem checking in.'); error_log('[attend] ' . $e->getMessage()); }
            }
        }
    }
}

$siteName     = function_exists('getSetting') ? getSetting('site_name', t('सहकारी', 'Cooperative')) : t('सहकारी', 'Cooperative');
$csrfField    = function_exists('generateCSRFToken') ? '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken()) . '">' : '';
$evDate       = $prog ? ($prog['event_date'] ? date('Y F d', strtotime($prog['event_date'])) : '') : '';
?>
<!DOCTYPE html>
<html lang="<?php echo isEnglish() ? 'en' : 'ne'; ?>" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="QR-based program attendance check-in for cooperative members.">
<title><?php echo htmlspecialchars(t('QR उपस्थिति', 'QR Attendance')); ?> — <?= htmlspecialchars($siteName) ?></title>

<link href="https://fonts.googleapis.com/css2?family=Mukta:wght@400;600;700;800&display=swap" rel="stylesheet">
<?php if (function_exists('coopThemeHeadAssets')) { coopThemeHeadAssets('minimal', ['skip_fonts' => true]); } ?>
</head>
<body>
<div class="card">
  <div class="card-top">
    <div class="prog-icon"><i class="fas fa-calendar-check"></i></div>
    <h1><?= $prog ? htmlspecialchars($prog['title']) : htmlspecialchars($siteName) . ' — QR Attendance' ?></h1>
    <?php if ($prog): ?>
    <div class="meta">
      <?php if ($evDate): ?><i class="fas fa-calendar meta-icon-gap"></i><?= $evDate ?><?php endif; ?>
      <?php if ($prog['event_time']): ?> &nbsp;·&nbsp; <i class="fas fa-clock meta-icon-gap"></i><?= htmlspecialchars($prog['event_time']) ?><?php endif; ?>
      <?php if ($prog['location']): ?><br><i class="fas fa-location-dot meta-icon-gap"></i><?= htmlspecialchars($prog['location']) ?><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="card-body">
    <?php if ($err && !$done && !$requestSubmitted): ?>
    <div class="error-box"><i class="fas fa-circle-xmark icon-shrink"></i><div><?= htmlspecialchars($err) ?></div></div>
    <?php elseif ($requestSubmitted && $prog): ?>
    <div class="msg-center">
      <div class="success-icon success-icon-warn"><i class="fas fa-hourglass-half"></i></div>
      <h2 class="msg-title-warn"><?php echo t('अनुरोध पठाइयो', 'Request Sent'); ?></h2>
      <p class="msg-sub">
        <?= htmlspecialchars($memName ?: 'सदस्य') ?><?php if ($memCard): ?> (<?= htmlspecialchars($memCard) ?>)<?php endif; ?> — <strong><?= htmlspecialchars($prog['title']) ?></strong>
      </p>
      <div class="msg-box-warn">
        <i class="fas fa-user-shield req-icon"></i><strong>Admin</strong> ले कार्यक्रम स्थलमा/panel मा <strong>स्वीकृत</strong> गरेपछि मात्र उपस्थिति सूची र सदस्यको इतिहासमा देखिन्छ। कृपया प्रतिक्षा गर्नुहोस् वा कर्मचारीलाई भन्नुहोस्।
      </div>
    </div>
    <?php elseif ($done): ?>
    <div class="msg-center">
      <div class="success-icon"><i class="fas fa-check"></i></div>
      <h2 class="msg-title-ok"><?php echo t('उपस्थिति दर्ता भयो!', 'Attendance Recorded!'); ?></h2>
      <p class="msg-sub">
        <?= htmlspecialchars($memName ?: 'सदस्य') ?><?php if ($memCard): ?> (<?= htmlspecialchars($memCard) ?>)<?php endif; ?> — <strong><?= htmlspecialchars($prog['title']) ?></strong>
      </p>
      <div class="msg-box-ok">
        <i class="fas fa-circle-check req-icon"></i>Check-in समय: <?= date('H:i A') ?>, <?= date('Y-m-d') ?>
      </div>
    </div>
    <?php elseif ($alreadyDone && $prog): ?>
    <div class="msg-center">
      <div class="success-icon success-icon-info"><i class="fas fa-bookmark"></i></div>
      <h2 class="msg-title-info"><?php echo t('पहिल्यै Check-in भइसक्यो', 'Already Checked In'); ?></h2>
      <p class="msg-sub-sm"><?php echo t('तपाईं यो कार्यक्रममा पहिल्यै उपस्थित दर्ता हुनुभएको छ।', 'You are already marked present for this program.'); ?></p>
    </div>
    <?php elseif ($prog): ?>
      <div class="info-box info-box-ok">
        <i class="fas fa-circle-info icon-shrink"></i>
        <div class="text-left">
          <strong><?php echo t('कार्यक्रम उपस्थिति QR:', 'Program Attendance QR:'); ?></strong>
          <?php echo t('सदस्यले स्थलमा उपस्थित भइसकेपछि scan गर्दा Admin स्वीकृतिको लागि अनुरोध जान्छ — Admin ले approve गरेपछि मात्र attendance सूची र सदस्यको इतिहासमा देखिन्छ।', 'After arriving at the venue, scanning sends a request for admin approval — it appears in attendance list and member history only after admin approval.'); ?>
          <br>
          <span class="text-soft">
            <?php echo t('(Pre-registration भन्दा फरक — त्यो अगाडि नाम दर्ता मात्र हो।)', '(Different from pre-registration — that is only early name registration.)'); ?>
          </span>
          <br>
          <span class="text-soft">
            <strong><?php echo t('मोबाइल छैन?', 'No mobile phone?'); ?></strong>
            <?php echo t('तल सदस्यता/फोन भरेर उपस्थिति अनुरोध पठाउनुहोस् — Admin ले स्वीकृत गरेपछि मात्र सूचीमा आउँछ।', 'Fill member number/phone below and send attendance request — it appears in list only after admin approval.'); ?>
          </span>
          <br>
          <span class="text-soft-2">
            <?php echo t('थप कडा जाँच: Staff कार्ड + CVV verify।', 'Extra strict check: Staff verifies card + CVV.'); ?>
          </span>
        </div>
      </div>
      <?php if ($memberId): ?>
      <!-- Logged-in: one-click check-in -->
      <div class="info-box">
        <i class="fas fa-user-check icon-shrink"></i>
        <div><?php echo t('तपाईं', 'You are logged in as'); ?> <strong><?= htmlspecialchars($memName ?: 'Member') ?></strong><?php echo t(' को रूपमा login हुनुभएको छ।', '.'); ?></div>
      </div>
      <form method="POST">
        <?= $csrfField ?><input type="hidden" name="action" value="checkin_logged">
        <button type="submit" class="btn-primary"><i class="fas fa-user-check"></i> <?php echo t('उपस्थिति दिनुहोस्', 'Mark Attendance'); ?></button>
      </form>
      <div class="divider"><span><?php echo t('वा', 'or'); ?></span></div>
      <?php endif; ?>

      <!-- Manual check-in form -->
      <form method="POST" id="manualForm" class="<?= $memberId ? 'manual-form-hide' : '' ?>">
        <?= $csrfField ?><input type="hidden" name="action" value="checkin_manual">
        <div class="form-group">
          <label><i class="fas fa-user meta-icon-gap"></i><?php echo t('पूरा नाम', 'Full Name'); ?></label>
          <input type="text" name="member_name" class="form-control" placeholder="<?php echo t('आफ्नो नाम लेख्नुहोस्', 'Enter your name'); ?>">
        </div>
        <div class="form-group">
          <label><i class="fas fa-id-card meta-icon-gap"></i><?php echo t('सदस्यता नम्बर', 'Member Number'); ?></label>
          <input type="text" name="member_card" class="form-control" placeholder="<?php echo t('जस्तै: A-001, SA-2025-001', 'e.g. A-001, SA-2025-001'); ?>">
        </div>
        <div class="form-group">
          <label><i class="fas fa-phone meta-icon-gap"></i><?php echo t('फोन नम्बर', 'Phone Number'); ?></label>
          <input type="text" name="phone" class="form-control" placeholder="9XXXXXXXXX">
        </div>
        <div class="form-group">
          <label><i class="fas fa-location-dot meta-icon-gap"></i><?php echo t('ठेगाना', 'Address'); ?></label>
          <input type="text" name="member_address" class="form-control" placeholder="<?php echo t('वडा/टोल/ठेगाना', 'Ward/tole/address'); ?>">
        </div>
        <button type="submit" class="btn-primary"><i class="fas fa-paper-plane"></i> <?php echo t('उपस्थिति अनुरोध पठाउनुहोस्', 'Send Attendance Request'); ?></button>
      </form>

      <?php if ($memberId): ?>
      <button type="button" class="btn-secondary" onclick="document.getElementById('manualForm').style.display=document.getElementById('manualForm').style.display==='none'?'block':'none';">
        <i class="fas fa-keyboard"></i> Manual Check-in
      </button>
      <?php endif; ?>
    <?php endif; ?>

    <div class="footer-link">
      <?php
        $memberAfterLogin = '/member/attend.php?qr_token=' . rawurlencode($token);
        $loginNext = rtrim(SITE_URL, '/') . '/member/login.php?next=' . rawurlencode($memberAfterLogin);
      ?>
      <?php if (!$memberId && $prog && $token): ?>
      <div class="mb-10">
        <a href="<?= htmlspecialchars($loginNext) ?>" class="btn-primary btn-inline-primary">
          <i class="fas fa-right-to-bracket"></i> <?php echo t('Member Portal मा लगिन गरेर Check-in', 'Login to Member Portal and Check-in'); ?>
        </a>
      </div>
      <?php endif; ?>
      <a href="<?= htmlspecialchars(SITE_URL) ?>"><i class="fas fa-home meta-icon-gap"></i><?= htmlspecialchars($siteName) ?></a>
      &nbsp;·&nbsp;
      <a href="<?= htmlspecialchars(SITE_URL) ?>member/"><i class="fas fa-user meta-icon-gap"></i><?php echo t('सदस्य पोर्टल', 'Member Portal'); ?></a>
    </div>
  </div>
</div>
</body>
</html>
