<?php
require_once 'includes/config.php';
require_once 'includes/program-tables.php';
$pageTitle = isEnglish() ? 'Cooperative Programs' : 'सहकारी कार्यक्रम';
require_once 'includes/header.php';

$programs = [];
$preregSaved = false;
$preregAlready = false;
$preregError = '';
$preregProgramId = (int)($_POST['program_id'] ?? 0);
$preregMemberInput = trim((string)($_POST['member_id_input'] ?? ''));
$preregNoteInput = trim((string)($_POST['prereg_note'] ?? ''));
try {
    $db = getDB();
    ensureProgramTables($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'program_preregister')) {
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $preregError = isEnglish() ? 'Security validation failed.' : 'सुरक्षा जाँच असफल भयो।';
        } else {
            $memberIdInput = trim((string)($_POST['member_id_input'] ?? ''));
            $note = trim((string)($_POST['prereg_note'] ?? ''));
            if ($preregProgramId <= 0 || $memberIdInput === '') {
                $preregError = isEnglish() ? 'Please fill program and member ID.' : 'कृपया कार्यक्रम र सदस्यता नं. दुवै भर्नुहोस्।';
            } else {
                $pst = $db->prepare("SELECT id, title, pre_registration_open, is_active FROM upcoming_programs WHERE id=? LIMIT 1");
                $pst->execute([$preregProgramId]);
                $pg = $pst->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$pg || (int)$pg['is_active'] !== 1 || (int)$pg['pre_registration_open'] !== 1) {
                    $preregError = isEnglish() ? 'Pre-registration is closed for this program.' : 'यो कार्यक्रमको pre-registration अहिले खुला छैन।';
                } else {
                    $mst = $db->prepare("SELECT m.id, m.name, m.phone, m.sadasyata_number, m.member_card_no, m.kyc_application_id, m.approval_status, m.is_active
                                          FROM members m
                                          WHERE m.sadasyata_number = ? OR m.member_card_no = ? OR m.id = ?
                                          LIMIT 1");
                    $mst->execute([$memberIdInput, $memberIdInput, (int)$memberIdInput]);
                    $member = $mst->fetch(PDO::FETCH_ASSOC) ?: null;
                    if (!$member || (string)($member['approval_status'] ?? '') !== 'approved' || (int)($member['is_active'] ?? 0) !== 1) {
                        $preregError = isEnglish() ? 'Not member. Please become a member first.' : 'Not member. कृपया पहिला सदस्य बन्नुहोस्।';
                    } else {
                        $kycOk = false;
                        if (!empty($member['kyc_application_id'])) {
                            $kst = $db->prepare("SELECT id FROM kyc_applications WHERE id=? LIMIT 1");
                            $kst->execute([(int)$member['kyc_application_id']]);
                            $kycOk = (bool)$kst->fetchColumn();
                        } else {
                            $kst = $db->prepare("SELECT id FROM kyc_applications WHERE member_id=? OR mobile=? LIMIT 1");
                            $kst->execute([(string)($member['sadasyata_number'] ?? ''), preg_replace('/[^0-9]/', '', (string)($member['phone'] ?? ($member['phone'] ?? '') ?? ''))]);
                            $kycOk = (bool)$kst->fetchColumn();
                        }
                        if (!$kycOk) {
                            $preregError = isEnglish() ? 'Not member. Please become a member first.' : 'Not member. कृपया पहिला सदस्य बन्नुहोस्।';
                        } else {
                            $chk = $db->prepare("SELECT id FROM member_program_preregistrations WHERE member_id=? AND program_id=? LIMIT 1");
                            $chk->execute([(int)$member['id'], $preregProgramId]);
                            if ($chk->fetchColumn()) {
                                $preregAlready = true;
                            } else {
                                $ins = $db->prepare("INSERT INTO member_program_preregistrations
                                    (member_id, member_card_no, member_name, phone, program_id, program_title, note, source)
                                    VALUES (?,?,?,?,?,?,?,?)");
                                $ins->execute([
                                    (int)$member['id'],
                                    (string)($member['sadasyata_number'] ?: ($member['member_card_no'] ?? '')),
                                    mb_substr((string)($member['name'] ?? ''), 0, 150),
                                    mb_substr((string)($member['phone'] ?: (($member['phone'] ?? '') ?? '')), 0, 30),
                                    $preregProgramId,
                                    mb_substr((string)$pg['title'], 0, 180),
                                    mb_substr($note, 0, 500),
                                    'public_program_page'
                                ]);
                                $preregSaved = true;
                            }
                        }
                    }
                }
            }
        }
    }

    $programs = $db->query("SELECT id, title, description, event_date, event_time, location, pre_registration_open
                            FROM upcoming_programs
                            WHERE is_active=1
                            ORDER BY COALESCE(event_date, '9999-12-31') ASC, id DESC
                            LIMIT 120")->fetchAll();
} catch (Throwable $e) {
    // POST exception आए पनि page खाली नदेखियोस्; user लाई स्पष्ट error देखाऔं।
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'program_preregister') && $preregError === '' && !$preregSaved && !$preregAlready) {
        $preregError = isEnglish() ? 'Pre-registration could not be completed. Please try again.' : 'Pre-registration पूरा गर्न सकिएन। कृपया फेरि प्रयास गर्नुहोस्।';
    }
    try {
        $db = getDB();
        $programs = $db->query("SELECT id, title, description, event_date, event_time, location, pre_registration_open
                                FROM upcoming_programs
                                WHERE is_active=1
                                ORDER BY COALESCE(event_date, '9999-12-31') ASC, id DESC
                                LIMIT 120")->fetchAll();
    } catch (Throwable $e2) {
        $programs = [];
    }
}
?>

<!-- Page Banner -->
<section class="page-banner page-banner-modern">
    <div class="container">
        <div class="banner-content-modern">
            <h1 class="page-title-modern"><?php echo isEnglish() ? 'Cooperative Programs' : 'सहकारी कार्यक्रम'; ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb breadcrumb-modern">
                    <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>" class="breadcrumb-link-modern"><?php echo isEnglish() ? 'Home' : 'गृहपृष्ठ'; ?></a></li>
                    <li class="breadcrumb-item active"><?php echo isEnglish() ? 'Cooperative Programs' : 'सहकारी कार्यक्रम'; ?></li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<!-- Hero Stats Strip -->
<?php
$openPreregCount = count(array_filter($programs, fn($p) => !empty($p['pre_registration_open'])));
$upcomingCount   = count(array_filter($programs, fn($p) => !empty($p['event_date']) && strtotime($p['event_date']) >= strtotime('today')));
?>
<div class="cp-hero">
    <div class="container">
        <div class="cp-hero-inner">
            <div class="cp-hero-icon">
                <i class="fas fa-calendar-check"></i>
            </div>
            <div class="cp-hero-text">
                <h2><?php echo isEnglish() ? 'Upcoming Cooperative Programs' : 'आगामी सहकारी कार्यक्रमहरू'; ?></h2>
                <p><?php echo isEnglish()
                    ? 'Stay informed about cooperative events and register for programs you want to attend.'
                    : 'सहकारी कार्यक्रमहरूको जानकारी लिनुहोस् र आफू सहभागी हुन चाहेको कार्यक्रममा pre-registration गर्नुहोस्।'; ?>
                </p>
            </div>
            <?php if (!empty($programs)): ?>
            <div class="cp-hero-stats">
                <div class="cp-stat-box">
                    <div class="cp-stat-num"><?php echo count($programs); ?></div>
                    <div class="cp-stat-lbl"><?php echo isEnglish() ? 'Programs' : 'कार्यक्रम'; ?></div>
                </div>
                <?php if ($openPreregCount > 0): ?>
                <div class="cp-stat-box">
                    <div class="cp-stat-num accent"><?php echo $openPreregCount; ?></div>
                    <div class="cp-stat-lbl"><?php echo isEnglish() ? 'Pre-reg Open' : 'Pre-reg खुला'; ?></div>
                </div>
                <?php endif; ?>
                <?php if ($upcomingCount > 0): ?>
                <div class="cp-stat-box">
                    <div class="cp-stat-num"><?php echo $upcomingCount; ?></div>
                    <div class="cp-stat-lbl"><?php echo isEnglish() ? 'Upcoming' : 'आगामी'; ?></div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Main Section -->
<section class="cp-shell">
    <div class="container">

        <?php if ($preregSaved || $preregAlready || $preregError !== ''): ?>
        <div class="mb-4">
            <div class="alert alert-dismissible fade show <?php echo $preregSaved ? 'alert-success' : ($preregAlready ? 'alert-warning' : 'alert-danger'); ?>">
                <i class="fas <?php echo $preregSaved ? 'fa-check-circle' : ($preregAlready ? 'fa-info-circle' : 'fa-exclamation-circle'); ?> me-2"></i>
                <?php if ($preregSaved): ?>
                    <?php echo isEnglish() ? 'Program pre-registration successful!' : 'कार्यक्रम pre-registration सफल भयो!'; ?>
                <?php elseif ($preregAlready): ?>
                    <?php echo isEnglish() ? 'This member is already pre-registered for this program.' : 'यो सदस्यको यो कार्यक्रममा pre-registration पहिल्यै भइसकेको छ।'; ?>
                <?php else: ?>
                    <?php echo htmlspecialchars($preregError); ?>
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($programs)): ?>
        <div class="cp-empty" data-aos="fade-up">
            <div class="cp-empty-icon"><i class="fas fa-calendar-times"></i></div>
            <h5 class="mb-2"><?php echo isEnglish() ? 'No Active Programs Available' : 'हाल सक्रिय कार्यक्रम उपलब्ध छैन'; ?></h5>
            <p class="text-muted small"><?php echo isEnglish() ? 'Please check back later for upcoming cooperative programs.' : 'कृपया आगामी सहकारी कार्यक्रमहरूको लागि पछि फेरि जाँच गर्नुहोस्।'; ?></p>
        </div>
        <?php else: ?>

        <div class="cp-section-sub">
            <h3><i class="fas fa-list-ul"></i> <?php echo isEnglish() ? 'All Programs' : 'सबै कार्यक्रमहरू'; ?> <span class="badge bg-success ms-1" style="font-size:.72rem;"><?php echo count($programs); ?></span></h3>
        </div>

        <div class="row g-4">
        <?php foreach ($programs as $pg):
            /* Date display */
            $evDate   = $pg['event_date'] ?? '';
            $dayNum   = $evDate ? date('d', strtotime($evDate)) : '';
            $monStr   = $evDate ? date('M', strtotime($evDate)) : '';
            $isPassed = $evDate && strtotime($evDate) < strtotime('today');
        ?>
            <div class="col-lg-6" data-aos="fade-up">
                <div class="cp-card">

                    <!-- Colored header -->
                    <div class="cp-card-head">
                        <?php if ($dayNum): ?>
                        <div class="cp-date-box">
                            <div class="dd"><?php echo $dayNum; ?></div>
                            <div class="mm"><?php echo $monStr; ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="cp-head-right">
                            <h5><?php echo htmlspecialchars($pg['title']); ?></h5>
                            <?php if (!empty($pg['pre_registration_open'])): ?>
                                <span class="cp-open-badge"><i class="fas fa-user-plus"></i><?php echo isEnglish() ? 'Pre-reg Open' : 'Pre-reg खुला'; ?></span>
                            <?php else: ?>
                                <span class="cp-closed-badge"><i class="fas fa-lock"></i><?php echo isEnglish() ? 'Pre-reg Closed' : 'Pre-reg बन्द'; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Body -->
                    <div class="cp-card-body">
                        <div class="cp-meta-row">
                            <?php if ($evDate): ?>
                            <span class="cp-pill cp-pill-date <?php echo $isPassed ? 'opacity-60' : ''; ?>">
                                <i class="fas fa-calendar-alt"></i>
                                <?php echo date('Y-m-d', strtotime($evDate)); ?>
                                <?php if ($isPassed): ?><em style="font-size:.7rem;">(<?php echo isEnglish()?'Past':'भइसक्यो'; ?>)</em><?php endif; ?>
                            </span>
                            <?php else: ?>
                            <span class="cp-pill cp-pill-tba"><i class="fas fa-clock"></i><?php echo isEnglish() ? 'Date TBA' : 'मिति घोषणा हुनेछ'; ?></span>
                            <?php endif; ?>
                            <?php if (!empty($pg['event_time'])): ?>
                            <span class="cp-pill cp-pill-time"><i class="fas fa-clock"></i><?php echo htmlspecialchars($pg['event_time']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($pg['location'])): ?>
                            <span class="cp-pill cp-pill-loc"><i class="fas fa-map-marker-alt"></i><?php echo htmlspecialchars($pg['location']); ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="cp-desc-text">
                            <?php echo htmlspecialchars($pg['description'] ?: (isEnglish() ? 'Program details will be updated soon.' : 'कार्यक्रमको थप विवरण चाँडै अपडेट हुनेछ।')); ?>
                        </div>

                        <?php if (!empty($pg['pre_registration_open'])): ?>
                        <div class="cp-prereg-wrap">
                            <button type="button" class="cp-btn-prereg"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#preRegForm<?php echo (int)$pg['id']; ?>">
                                <i class="fas fa-user-check"></i>
                                <?php echo isEnglish() ? 'Pre-register Now' : 'Pre-register गर्नुहोस्'; ?>
                            </button>

                            <div class="collapse cp-prereg-collapse <?php echo ($preregProgramId === (int)$pg['id'] && ($preregSaved || $preregAlready || $preregError !== '')) ? 'show' : ''; ?>"
                                 id="preRegForm<?php echo (int)$pg['id']; ?>">
                                <div class="cp-prereg-inner">
                                    <div class="cp-prereg-title">
                                        <i class="fas fa-clipboard-list"></i>
                                        <?php echo isEnglish() ? 'Quick Pre-Registration' : 'छिटो Pre-Registration'; ?>
                                    </div>
                                    <?php if ($preregProgramId === (int)$pg['id'] && ($preregSaved || $preregAlready || $preregError !== '')): ?>
                                    <div class="alert py-2 px-3 mb-2 <?php echo $preregSaved ? 'alert-success' : ($preregAlready ? 'alert-warning' : 'alert-danger'); ?>" style="font-size:.84rem;">
                                        <?php if ($preregSaved): ?>
                                            <i class="fas fa-check-circle me-1"></i><?php echo isEnglish() ? 'Registration successful!' : 'Registration सफल भयो!'; ?>
                                        <?php elseif ($preregAlready): ?>
                                            <i class="fas fa-info-circle me-1"></i><?php echo isEnglish() ? 'Already registered.' : 'पहिल्यै registration भइसक्यो।'; ?>
                                        <?php else: ?>
                                            <i class="fas fa-exclamation-circle me-1"></i><?php echo htmlspecialchars($preregError); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    <form method="POST" class="needs-validation row g-2" novalidate>
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="program_preregister">
                                        <input type="hidden" name="program_id" value="<?php echo (int)$pg['id']; ?>">
                                        <div class="col-sm-6">
                                            <input type="text" name="member_id_input" class="form-control form-control-sm"
                                                   placeholder="<?php echo isEnglish() ? 'Member ID / Card No.' : 'सदस्यता नं. / कार्ड नं.'; ?>"
                                                   value="<?php echo htmlspecialchars($preregProgramId === (int)$pg['id'] ? $preregMemberInput : ''); ?>"
                                                   required>
                                        </div>
                                        <div class="col-sm-6">
                                            <input type="text" name="prereg_note" class="form-control form-control-sm"
                                                   placeholder="<?php echo isEnglish() ? 'Note (optional)' : 'टिप्पणी (वैकल्पिक)'; ?>"
                                                   value="<?php echo htmlspecialchars($preregProgramId === (int)$pg['id'] ? $preregNoteInput : ''); ?>">
                                        </div>
                                        <div class="col-12">
                                            <button class="btn btn-sm btn-primary">
                                                <i class="fas fa-check-circle me-1"></i><?php echo isEnglish() ? 'Confirm Registration' : 'Registration Confirm'; ?>
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div><!-- /.cp-card-body -->

                    <!-- Footer -->
                    <div class="cp-card-footer">
                        <a href="program-attendance-verify.php?program_id=<?php echo (int)$pg['id']; ?>" class="cp-att-btn">
                            <i class="fas fa-qrcode"></i>
                            <?php echo isEnglish() ? 'Verify Attendance' : 'उपस्थिति जाँच'; ?>
                        </a>
                    </div>

                </div><!-- /.cp-card -->
            </div>
        <?php endforeach; ?>
        </div><!-- /.row -->
        <?php endif; ?>

    </div>
</section>

<?php require_once 'includes/footer.php'; ?>
