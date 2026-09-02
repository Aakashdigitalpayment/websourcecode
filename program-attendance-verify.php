<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/card-verify-helpers.php';
require_once __DIR__ . '/includes/program-tables.php';
require_once __DIR__ . '/includes/program-attendance-helpers.php';

$pageTitle = isEnglish() ? 'Program Attendance Verify' : 'कार्यक्रम उपस्थिति प्रमाणीकरण';
$currentPage = 'program-attendance-verify';
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
$ip = function_exists('coop_client_ip') ? coop_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$programId = (int)($_POST['program_id'] ?? ($_GET['program_id'] ?? 0));
$occurrenceId = (int)($_POST['occurrence_id'] ?? 0);
$existingAttendance = null;
$code = mb_substr(trim((string)($_POST['code'] ?? '')), 0, 80, 'UTF-8');
$cvv  = mb_substr(trim((string)($_POST['cvv'] ?? '')), 0, 32, 'UTF-8');

$programs = [];
try {
    $programs = $pdo->query("SELECT id, title, event_date, event_time, location, qr_token, is_multi_location
                             FROM upcoming_programs
                             WHERE is_active=1
                             ORDER BY COALESCE(event_date, '9999-12-31') ASC, id DESC LIMIT 200")->fetchAll() ?: [];
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
                $pg = programFetchById($pdo, $programId);
                if (!$pg || !$memberInfo) {
                    $error = isEnglish() ? 'Program not found.' : 'कार्यक्रम फेला परेन।';
                } elseif ((int)($pg['is_multi_location'] ?? 0) === 1 && $occurrenceId <= 0) {
                    $error = isEnglish() ? 'Select occurrence/location for multi-location program.' : 'Multi-location कार्यक्रममा स्थान/occurrence छान्नुहोस्।';
                } else {
                    $mid = (int)($memberInfo['id'] ?? 0);
                    $cardNo = trim((string)($memberInfo['member_id'] ?? ''));
                    $rec = recordProgramAttendance($pdo, [
                        'member_id' => $mid,
                        'member_card_no' => $cardNo,
                        'program_id' => $programId,
                        'occurrence_id' => $occurrenceId > 0 ? $occurrenceId : null,
                        'attendance_method' => 'STAFF_VERIFY',
                        'source' => 'program_verify_page',
                        'attendance_note' => 'Program attendance verify page',
                        'ip' => $ip,
                        'staff_admin_id' => (int)($_SESSION['admin_id'] ?? 0),
                    ]);
                    if (!empty($rec['ok'])) {
                        $saved = true;
                    } elseif (!empty($rec['duplicate'])) {
                        $already = true;
                        $existingAttendance = $rec['existing'] ?? null;
                    } else {
                        $error = (string)($rec['error_en'] ?? $rec['error_np'] ?? (isEnglish() ? 'Could not save attendance.' : 'Attendance सुरक्षित गर्न सकिएन।'));
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
                        <label for="pavProgramSelect"><i class="fas fa-calendar-check me-1"></i><?php echo isEnglish() ? 'Select Program' : 'कार्यक्रम छान्नुहोस्'; ?></label>
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
                        <div id="pavOccurrenceWrap" class="mt-2" style="display:none">
                            <label for="pavOccurrenceSelect" class="small"><?php echo isEnglish() ? 'Location / Occurrence' : 'स्थान / Occurrence'; ?></label>
                            <select name="occurrence_id" id="pavOccurrenceSelect" class="pav-field-input">
                                <option value=""><?php echo isEnglish() ? '— Select location —' : '— स्थान छान्नुहोस् —'; ?></option>
                            </select>
                        </div>
                        <div id="pavProgramQr" class="pav-program-qr" aria-live="polite"></div>
                        <div id="pavProgramQrEmpty" class="pav-program-qr-empty">
                            <?php echo isEnglish() ? 'Selected program QR दिखाउन, त्यो कार्यक्रममा QR token generate भएको हुनुपर्छ।' : 'कार्यक्रम select गरेपछि QR देखाउन, त्यो कार्यक्रममा QR token generate भएको हुनुपर्छ।'; ?>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-sm-7">
                            <div class="pav-field" style="margin-bottom:0;">
                                <label for="pav_code"><i class="fas fa-id-card me-1"></i>Verification Code / Card No.</label>
                                <input type="text" name="code" id="pav_code" class="pav-field-input"
                                       value="<?php echo htmlspecialchars($code); ?>"
                                       placeholder="AKS-XXXX-XXXX"
                                       autocomplete="off" required>
                            </div>
                        </div>
                        <div class="col-sm-5">
                            <div class="pav-field" style="margin-bottom:0;">
                                <label for="pav_cvv"><i class="fas fa-lock me-1"></i>CVV (4 digit)</label>
                                <input type="text" name="cvv" id="pav_cvv" class="pav-field-input"
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
                            <?php if ($existingAttendance): ?>
                                <div class="small mt-1"><?php echo htmlspecialchars(programFormatExistingAttendanceMessage($existingAttendance, isEnglish())); ?></div>
                            <?php endif; ?>
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
    var programMultiMap = <?php
        $multiMap = [];
        foreach ($programs as $pg) {
            if ((int)($pg['is_multi_location'] ?? 0) !== 1) continue;
            $st = $pdo->prepare('SELECT id, location_name, event_date FROM program_occurrences WHERE parent_program_id=? AND is_active=1 ORDER BY sort_order ASC, id ASC');
            $st->execute([(int)$pg['id']]);
            $multiMap[(int)$pg['id']] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        echo json_encode($multiMap, JSON_UNESCAPED_UNICODE);
    ?> || {};
    var programSel = document.getElementById('pavProgramSelect');
    var occurrenceWrap = document.getElementById('pavOccurrenceWrap');
    var occurrenceSel = document.getElementById('pavOccurrenceSelect');
    function updateOccurrences() {
        if (!programSel || !occurrenceWrap || !occurrenceSel) return;
        var pid = programSel.value || '';
        var list = (pid && programMultiMap[pid]) ? programMultiMap[pid] : [];
        occurrenceSel.innerHTML = '<option value=""><?php echo isEnglish() ? "— Select location —" : "— स्थान छान्नुहोस् —"; ?></option>';
        if (list.length) {
            occurrenceWrap.style.display = '';
            occurrenceSel.required = true;
            list.forEach(function(o){
                var opt = document.createElement('option');
                opt.value = o.id;
                opt.textContent = o.location_name + (o.event_date ? (' · ' + o.event_date) : '');
                occurrenceSel.appendChild(opt);
            });
        } else {
            occurrenceWrap.style.display = 'none';
            occurrenceSel.required = false;
        }
    }
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
            '<a class="pav-program-qr-link" target="_blank" rel="noopener noreferrer" href="' + url + '"><i class="fas fa-up-right-from-square me-1"></i><?php echo isEnglish() ? 'Open attendance link' : 'Attendance link खोल्नुहोस्'; ?></a>' +
            '</div>';
        programQrBox.classList.add('is-visible');
        if (programQrEmpty) programQrEmpty.style.display = 'none';
    }
    if (programSel) {
        programSel.addEventListener('change', function(){ updateProgramQr(); updateOccurrences(); });
        updateProgramQr();
        updateOccurrences();
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
