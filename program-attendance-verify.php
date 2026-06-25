<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/card-verify-helpers.php';
require_once __DIR__ . '/includes/program-tables.php';

$pageTitle = isEnglish() ? 'Program Attendance Verify' : 'कार्यक्रम उपस्थिति प्रमाणीकरण';
$attendanceStaffMode = isAdminLoggedIn();
if (!$attendanceStaffMode) {
    redirect(ADMIN_URL . 'index.php');
}
require_once __DIR__ . '/includes/header.php';

$pdo = getDB();
ensureProgramTables($pdo);
$saved = false;
$already = false;
$error = '';
$memberInfo = null;
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$programId = (int)($_POST['program_id'] ?? ($_GET['program_id'] ?? 0));
$code = mb_substr(trim((string)($_POST['code'] ?? '')), 0, 80, 'UTF-8');
$cvv  = mb_substr(trim((string)($_POST['cvv'] ?? '')), 0, 32, 'UTF-8');

$programs = [];
try {
    $programs = $pdo->query("SELECT id, title, event_date, event_time, location, qr_token
                             FROM upcoming_programs
                             WHERE is_active=1
                             ORDER BY COALESCE(event_date, '9999-12-31') ASC, id DESC")->fetchAll() ?: [];
} catch (Throwable $e) { $programs = []; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = isEnglish() ? 'Security validation failed.' : 'सुरक्षा जाँच असफल भयो।';
    } elseif ($programId <= 0 || $code === '' || $cvv === '') {
        $error = isEnglish() ? 'Please select program and enter code/CVV.' : 'कृपया कार्यक्रम छान्नुहोस् र code/CVV राख्नुहोस्।';
    } else {
        $result = verifyCardCredentials($pdo, $code, $cvv, $ip);
        if (empty($result['ok'])) {
            $error = (string)($result['error'] ?? (isEnglish() ? 'Verification failed.' : 'Verification असफल भयो।'));
        } else {
            $memberInfo = $result['member'] ?? null;
            try {
                $pst = $pdo->prepare("SELECT id, title FROM upcoming_programs WHERE id=? AND is_active=1 LIMIT 1");
                $pst->execute([$programId]);
                $pg = $pst->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$pg || !$memberInfo) {
                    $error = isEnglish() ? 'Program not found.' : 'कार्यक्रम फेला परेन।';
                } else {
                    $mid = (int)($memberInfo['id'] ?? 0);
                    $cardNo = trim((string)($memberInfo['member_id'] ?? ''));
                    $chk = $pdo->prepare("SELECT id FROM member_program_attendance WHERE member_id=? AND program_id=? LIMIT 1");
                    $chk->execute([$mid, $programId]);
                    if ($chk->fetchColumn()) {
                        $already = true;
                    } else {
                        $ins = $pdo->prepare("INSERT INTO member_program_attendance
                            (member_id, member_card_no, program_id, program_title, is_priority, attendance_note, verified_by_ip, source)
                            VALUES (?,?,?,?,?,?,?,?)");
                        $ins->execute([$mid, $cardNo, $programId, mb_substr((string)$pg['title'], 0, 180), 0, 'Program attendance verify page', $ip, 'program_verify_page']);
                        $saved = true;
                    }
                }
            } catch (Throwable $e) {
                $error = isEnglish() ? 'Could not save attendance.' : 'Attendance सुरक्षित गर्न सकिएन।';
            }
        }
    }
}
?>
<section class="section-padding pav-shell">
    <div class="container">

        <!-- Navigation pills -->
        <div class="pav-nav">
            <a href="<?php echo SITE_URL; ?>verify.php" class="pav-nav-pill member">
                <i class="fas fa-id-card"></i> Member Verify
            </a>
            <a href="<?php echo SITE_URL; ?>cooperative-programs.php" class="pav-nav-pill program">
                <i class="fas fa-user-plus"></i> Program Registration
            </a>
            <span class="pav-nav-pill att">
                <i class="fas fa-user-check"></i> Attendance Verify
            </span>
        </div>

        <div class="pav-card">
            <!-- Header -->
            <div class="pav-card-header">
                <div class="pav-card-icon"><i class="fas fa-user-check"></i></div>
                <h1 class="pav-card-title"><?php echo isEnglish() ? 'Program Attendance' : 'कार्यक्रम उपस्थिति प्रमाणीकरण'; ?></h1>
                <p class="pav-card-sub"><?php echo isEnglish() ? 'Select program and verify with card number + CVV to record attendance.' : 'कार्यक्रम छानी Card Number र CVV राखेर उपस्थिति प्रमाणित गर्नुहोस्।'; ?></p>
            </div>

            <div class="pav-card-body">

                <?php if ($error !== ''): ?>
                    <div class="pav-alert error"><i class="fas fa-circle-exclamation"></i><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <!-- Form -->
                <form method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                    <div class="pav-field">
                        <label><i class="fas fa-calendar-check me-1"></i><?php echo isEnglish() ? 'Select Program' : 'कार्यक्रम छान्नुहोस्'; ?></label>
                        <select name="program_id" class="pav-field-input" id="pavProgramSelect" required>
                            <option value=""><?php echo isEnglish() ? '— Choose a program —' : '— कार्यक्रम छान्नुहोस् —'; ?></option>
                            <?php foreach ($programs as $pg): ?>
                                <?php
                                    $qrToken = trim((string)($pg['qr_token'] ?? ''));
                                    $memberAttendUrl = $qrToken !== '' ? (rtrim(SITE_URL, '/') . '/member/attend.php?qr_token=' . rawurlencode($qrToken)) : '';
                                ?>
                                <option value="<?php echo (int)$pg['id']; ?>" <?php echo $programId === (int)$pg['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pg['title']); ?>
                                    <?php if (!empty($pg['event_date'])): ?> · <?php echo htmlspecialchars($pg['event_date']); ?><?php endif; ?>
                                    <?php if (!empty($pg['location'])): ?> · <?php echo htmlspecialchars($pg['location']); ?><?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="pavProgramQr" class="pav-program-qr" aria-live="polite"></div>
                        <div id="pavProgramQrEmpty" class="pav-program-qr-empty">
                            <?php echo isEnglish() ? 'Selected program QR दिखाउन, त्यो कार्यक्रममा QR token generate भएको हुनुपर्छ।' : 'कार्यक्रम select गरेपछि QR देखाउन, त्यो कार्यक्रममा QR token generate भएको हुनुपर्छ।'; ?>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-sm-7">
                            <div class="pav-field" style="margin-bottom:0;">
                                <label><i class="fas fa-id-card me-1"></i>Verification Code / Card No.</label>
                                <input type="text" name="code" class="pav-field-input"
                                       value="<?php echo htmlspecialchars($code); ?>"
                                       placeholder="AKS-XXXX-XXXX"
                                       autocomplete="off" required>
                            </div>
                        </div>
                        <div class="col-sm-5">
                            <div class="pav-field" style="margin-bottom:0;">
                                <label><i class="fas fa-lock me-1"></i>CVV (4 digit)</label>
                                <input type="text" name="cvv" class="pav-field-input"
                                       maxlength="4" inputmode="numeric" pattern="[0-9]{4}"
                                       value="<?php echo htmlspecialchars($cvv); ?>"
                                       placeholder="••••" required>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="pav-btn">
                            <i class="fas fa-circle-check"></i>
                            <?php echo isEnglish() ? 'Verify & Record Attendance' : 'Verify गरेर Attendance सुरक्षित गर्नुहोस्'; ?>
                        </button>
                    </div>
                </form>

                <!-- Result card -->
                <?php if ($memberInfo && ($saved || $already)): ?>
                <div class="pav-result <?php echo $saved ? 'pav-result-ok' : 'pav-result-warn'; ?>">
                    <div class="pav-result-head <?php echo $saved ? 'ok' : 'warn'; ?>">
                        <i class="fas <?php echo $saved ? 'fa-circle-check' : 'fa-circle-info'; ?>"></i>
                        <?php if ($saved): ?>
                            <?php echo isEnglish() ? 'Attendance recorded successfully!' : 'उपस्थिति सफलतापूर्वक दर्ता भयो!'; ?>
                        <?php else: ?>
                            <?php echo isEnglish() ? 'Already marked for this program.' : 'यो कार्यक्रममा attendance पहिल्यै दर्ता भइसकेको छ।'; ?>
                        <?php endif; ?>
                    </div>
                    <div class="pav-member-card">
                        <?php
                            $photoPath = (string)($memberInfo['photo_path'] ?? '');
                            $photoSrc = '';
                            if ($photoPath) {
                                $photoSrc = (strpos($photoPath, 'http') === 0)
                                    ? $photoPath
                                    : (SITE_URL . ltrim($photoPath, '/'));
                            }
                        ?>
                        <?php if ($photoSrc): ?>
                            <img src="<?php echo htmlspecialchars($photoSrc); ?>" alt="" class="pav-member-photo"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='grid'">
                            <div class="pav-member-photo-fallback" style="display:none;"><i class="fas fa-user"></i></div>
                        <?php else: ?>
                            <div class="pav-member-photo-fallback"><i class="fas fa-user"></i></div>
                        <?php endif; ?>
                        <div class="pav-member-info">
                            <div class="pav-member-name"><?php echo htmlspecialchars((string)($memberInfo['full_name'] ?? '—')); ?></div>
                            <div class="pav-member-id"><?php echo htmlspecialchars((string)($memberInfo['member_id'] ?? '')); ?></div>
                            <span class="pav-result-badge <?php echo $saved ? 'saved' : 'already'; ?>">
                                <i class="fas <?php echo $saved ? 'fa-check' : 'fa-rotate-left'; ?>"></i>
                                <?php echo $saved ? (isEnglish() ? 'Attendance Saved' : 'उपस्थिति दर्ता') : (isEnglish() ? 'Already Recorded' : 'पहिल्यै दर्ता'); ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</section>
<script>
(function(){
    var programQrMap = <?php
        $qrMap = [];
        foreach ($programs as $pg) {
            $id = (int)($pg['id'] ?? 0);
            if ($id <= 0) continue;
            $token = trim((string)($pg['qr_token'] ?? ''));
            if ($token === '') continue;
            $qrMap[$id] = rtrim(SITE_URL, '/') . '/member/attend.php?qr_token=' . rawurlencode($token);
        }
        echo json_encode($qrMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ?> || {};
    var programSel = document.getElementById('pavProgramSelect');
    var programQrBox = document.getElementById('pavProgramQr');
    var programQrEmpty = document.getElementById('pavProgramQrEmpty');
    function updateProgramQr() {
        if (!programSel || !programQrBox) return;
        var pid = (programSel.value || '').trim();
        var url = (pid && programQrMap[pid]) ? programQrMap[pid] : '';
        if (!url) {
            programQrBox.classList.remove('is-visible');
            programQrBox.innerHTML = '';
            if (programQrEmpty) programQrEmpty.style.display = '';
            return;
        }
        var qrSrc = 'https://api.qrserver.com/v1/create-qr-code/?size=144x144&margin=4&data=' + encodeURIComponent(url);
        programQrBox.innerHTML =
            '<img class="pav-program-qr-img" src="' + qrSrc + '" alt="Program QR">' +
            '<div class="pav-program-qr-meta">' +
            '<div class="pav-program-qr-title"><?php echo isEnglish() ? 'Selected program QR' : 'छानिएको कार्यक्रमको QR'; ?></div>' +
            '<a class="pav-program-qr-link" target="_blank" rel="noopener" href="' + url + '"><i class="fas fa-up-right-from-square me-1"></i><?php echo isEnglish() ? 'Open attendance link' : 'Attendance link खोल्नुहोस्'; ?></a>' +
            '</div>';
        programQrBox.classList.add('is-visible');
        if (programQrEmpty) programQrEmpty.style.display = 'none';
    }
    if (programSel) {
        programSel.addEventListener('change', updateProgramQr);
        updateProgramQr();
    }

    var code = document.querySelector('.pav-card-body input[name="code"]');
    var cvv  = document.querySelector('.pav-card-body input[name="cvv"]');
    if (code) code.addEventListener('input', function(e){
        e.target.value = e.target.value.toUpperCase().replace(/[^A-Z0-9-]/g, '');
    });
    if (cvv) cvv.addEventListener('input', function(e){
        e.target.value = e.target.value.replace(/\D/g,'').slice(0,4);
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
