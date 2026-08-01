<?php
require_once 'includes/config.php';
require_once 'includes/program-tables.php';
$pageTitle = isEnglish() ? 'Cooperative Programs' : 'सहकारी कार्यक्रम';
$_t = static function (string $np, string $en): string {
    return isEnglish() ? $en : $np;
};

$programs = [];
$preregSaved = false;
$preregAlready = false;
$preregError = '';
$preregProgramId = 0;
$preregMemberInput = '';
$preregNoteInput = '';
$viewTab = (($_GET['tab'] ?? '') === 'past') ? 'past' : 'upcoming';

try {
    $db = getDB();
    ensureProgramTables($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'program_preregister')) {
        $preregProgramId = (int)($_POST['program_id'] ?? 0);
        $preregMemberInput = trim((string)($_POST['member_id_input'] ?? ''));
        $preregNoteInput = trim((string)($_POST['prereg_note'] ?? ''));

        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $preregError = $_t('सुरक्षा जाँच असफल भयो।', 'Security validation failed.');
        } elseif ($preregProgramId <= 0 || $preregMemberInput === '') {
            $preregError = $_t('कृपया कार्यक्रम र सदस्यता नं. दुवै भर्नुहोस्।', 'Please fill program and member ID.');
        } else {
            $pst = $db->prepare("SELECT id, title, pre_registration_open, is_active FROM upcoming_programs WHERE id=? LIMIT 1");
            $pst->execute([$preregProgramId]);
            $pg = $pst->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$pg || (int)$pg['is_active'] !== 1 || (int)$pg['pre_registration_open'] !== 1) {
                $preregError = $_t('यो कार्यक्रमको pre-registration अहिले खुला छैन।', 'Pre-registration is closed for this program.');
            } else {
                $mst = $db->prepare("SELECT m.id, m.name, m.phone, m.sadasyata_number, m.member_card_no, m.kyc_application_id, m.approval_status, m.is_active
                                      FROM members m
                                      WHERE m.sadasyata_number = ? OR m.member_card_no = ? OR m.id = ?
                                      LIMIT 1");
                $mst->execute([$preregMemberInput, $preregMemberInput, (int)$preregMemberInput]);
                $member = $mst->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$member || (string)($member['approval_status'] ?? '') !== 'approved' || (int)($member['is_active'] ?? 0) !== 1) {
                    $preregError = $_t('सक्रिय सदस्य फेला परेन। सदस्यता नं. जाँच्नुहोस् वा पहिला सदस्य बन्नुहोस्।', 'Active member not found. Check member ID or become a member first.');
                } else {
                    $kycOk = false;
                    if (!empty($member['kyc_application_id'])) {
                        $kst = $db->prepare("SELECT id FROM kyc_applications WHERE id=? AND status='approved' LIMIT 1");
                        $kst->execute([(int)$member['kyc_application_id']]);
                        $kycOk = (bool)$kst->fetchColumn();
                    } else {
                        $phoneDigits = preg_replace('/[^0-9]/', '', (string)($member['phone'] ?? '')) ?? '';
                        $kst = $db->prepare("SELECT id FROM kyc_applications WHERE (member_id=? OR mobile=?) AND status='approved' LIMIT 1");
                        $kst->execute([(string)($member['sadasyata_number'] ?? ''), $phoneDigits]);
                        $kycOk = (bool)$kst->fetchColumn();
                    }
                    if (!$kycOk) {
                        $preregError = $_t('स्वीकृत KYC छैन। कृपया KYM approve भएपछि pre-register गर्नुहोस्।', 'Approved KYC required. Please pre-register after KYC is approved.');
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
                                mb_substr((string)($member['phone'] ?? ''), 0, 30),
                                $preregProgramId,
                                mb_substr((string)$pg['title'], 0, 180),
                                mb_substr($preregNoteInput, 0, 500),
                                'public_program_page'
                            ]);
                            $preregSaved = true;
                        }
                    }
                }
            }
        }

        // PRG: avoid double-submit; keep flash via query
        if ($preregSaved || $preregAlready || $preregError !== '') {
            $q = ['tab' => $viewTab, 'pid' => $preregProgramId];
            if ($preregSaved) $q['ok'] = '1';
            elseif ($preregAlready) $q['dup'] = '1';
            else {
                $q['err'] = '1';
                $_SESSION['cp_prereg_error'] = $preregError;
                $_SESSION['cp_prereg_member'] = $preregMemberInput;
                $_SESSION['cp_prereg_note'] = $preregNoteInput;
            }
            header('Location: cooperative-programs.php?' . http_build_query($q) . '#prog-' . $preregProgramId);
            exit;
        }
    }

    $programs = $db->query("SELECT id, title, description, event_date, event_time, location, pre_registration_open
                            FROM upcoming_programs
                            WHERE is_active=1
                            ORDER BY COALESCE(event_date, '9999-12-31') ASC, id DESC
                            LIMIT 120")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'program_preregister') && $preregError === '') {
        $preregError = $_t('Pre-registration पूरा गर्न सकिएन। कृपया फेरि प्रयास गर्नुहोस्।', 'Pre-registration could not be completed. Please try again.');
    }
    try {
        $db = getDB();
        $programs = $db->query("SELECT id, title, description, event_date, event_time, location, pre_registration_open
                                FROM upcoming_programs
                                WHERE is_active=1
                                ORDER BY COALESCE(event_date, '9999-12-31') ASC, id DESC
                                LIMIT 120")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e2) {
        $programs = [];
    }
}

/* Flash from PRG redirect */
if (!empty($_GET['ok'])) {
    $preregSaved = true;
    $preregProgramId = (int)($_GET['pid'] ?? 0);
}
if (!empty($_GET['dup'])) {
    $preregAlready = true;
    $preregProgramId = (int)($_GET['pid'] ?? 0);
}
if (!empty($_GET['err'])) {
    $preregProgramId = (int)($_GET['pid'] ?? 0);
    $preregError = (string)($_SESSION['cp_prereg_error'] ?? $_t('Pre-registration असफल।', 'Pre-registration failed.'));
    $preregMemberInput = (string)($_SESSION['cp_prereg_member'] ?? '');
    $preregNoteInput = (string)($_SESSION['cp_prereg_note'] ?? '');
    unset($_SESSION['cp_prereg_error'], $_SESSION['cp_prereg_member'], $_SESSION['cp_prereg_note']);
}

$todayTs = strtotime('today');
$upcomingPrograms = [];
$pastPrograms = [];
foreach ($programs as $p) {
    $adEv = function_exists('programEventDateToAd') ? programEventDateToAd((string)($p['event_date'] ?? '')) : (string)($p['event_date'] ?? '');
    if ($adEv !== '' && strtotime($adEv) < $todayTs) {
        $pastPrograms[] = $p;
    } else {
        $upcomingPrograms[] = $p;
    }
}
$listPrograms = ($viewTab === 'past') ? $pastPrograms : $upcomingPrograms;
$openPreregCount = count(array_filter($upcomingPrograms, static fn($p) => !empty($p['pre_registration_open'])));

require_once 'includes/header.php';
$memberPortalAttend = rtrim(SITE_URL, '/') . '/member/attend.php';
$memberPortalScan = rtrim(SITE_URL, '/') . '/member/scan.php';
?>

<!-- Page Banner -->
<section class="page-banner page-banner-modern">
    <div class="container">
        <div class="banner-content-modern">
            <h1 class="page-title-modern"><?php echo $_t('सहकारी कार्यक्रम', 'Cooperative Programs'); ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb breadcrumb-modern">
                    <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>" class="breadcrumb-link-modern"><?php echo $_t('गृहपृष्ठ', 'Home'); ?></a></li>
                    <li class="breadcrumb-item active"><?php echo $_t('सहकारी कार्यक्रम', 'Cooperative Programs'); ?></li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<div class="cp-hero">
    <div class="container">
        <div class="cp-hero-inner">
            <div class="cp-hero-icon">
                <i class="fas fa-calendar-check"></i>
            </div>
            <div class="cp-hero-text">
                <h2><?php echo $_t('सहकारी कार्यक्रमहरू', 'Cooperative Programs'); ?></h2>
                <p><?php echo $_t(
                    'Pre-register गरेर अगाडि नाम दर्ता गर्नुहोस्। स्थलमा उपस्थिति Member Portal बाट QR scan गर्नुहोस्।',
                    'Pre-register ahead of time. For venue attendance, scan the program QR from the Member Portal.'
                ); ?></p>
            </div>
            <div class="cp-hero-stats">
                <div class="cp-stat-box">
                    <div class="cp-stat-num"><?php echo count($upcomingPrograms); ?></div>
                    <div class="cp-stat-lbl"><?php echo $_t('आगामी', 'Upcoming'); ?></div>
                </div>
                <?php if ($openPreregCount > 0): ?>
                <div class="cp-stat-box">
                    <div class="cp-stat-num accent"><?php echo $openPreregCount; ?></div>
                    <div class="cp-stat-lbl"><?php echo $_t('Pre-reg खुला', 'Pre-reg Open'); ?></div>
                </div>
                <?php endif; ?>
                <?php if (count($pastPrograms) > 0): ?>
                <div class="cp-stat-box">
                    <div class="cp-stat-num"><?php echo count($pastPrograms); ?></div>
                    <div class="cp-stat-lbl"><?php echo $_t('भइसकेका', 'Past'); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<section class="cp-shell">
    <div class="container">

        <div class="cp-howto mb-4" data-aos="fade-up">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="cp-howto-card">
                        <span class="cp-howto-num">1</span>
                        <h3><?php echo $_t('Pre-register', 'Pre-register'); ?></h3>
                        <p><?php echo $_t('तलबाट सदस्यता नं. राखेर अगाडि नाम दर्ता (उपस्थिति गणना होइन)।', 'Reserve your name with member ID below (does not count as attendance).'); ?></p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="cp-howto-card">
                        <span class="cp-howto-num">2</span>
                        <h3><?php echo $_t('स्थलमा QR', 'Scan QR at venue'); ?></h3>
                        <p><?php echo $_t('Member Portal → Scan / Attend बाट कार्यक्रम QR स्क्यान गर्नुहोस्।', 'Use Member Portal → Scan / Attend with the program QR.'); ?></p>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <a class="btn btn-sm btn-success" href="<?php echo htmlspecialchars($memberPortalScan); ?>"><i class="fas fa-camera me-1"></i><?php echo $_t('स्क्यान', 'Scan'); ?></a>
                            <a class="btn btn-sm btn-outline-success" href="<?php echo htmlspecialchars($memberPortalAttend); ?>"><i class="fas fa-clipboard-check me-1"></i><?php echo $_t('Attend', 'Attend'); ?></a>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="cp-howto-card">
                        <span class="cp-howto-num">3</span>
                        <h3><?php echo $_t('Admin approve', 'Admin approve'); ?></h3>
                        <p><?php echo $_t('QR अनुरोध Admin ले approve गरेपछि उपस्थिति इतिहासमा देखिन्छ।', 'After admin approves the QR request, it appears in your attendance history.'); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="cp-tabs mb-3">
            <a class="cp-tab <?php echo $viewTab === 'upcoming' ? 'active' : ''; ?>" href="cooperative-programs.php?tab=upcoming">
                <?php echo $_t('आगामी', 'Upcoming'); ?> <span class="badge"><?php echo count($upcomingPrograms); ?></span>
            </a>
            <a class="cp-tab <?php echo $viewTab === 'past' ? 'active' : ''; ?>" href="cooperative-programs.php?tab=past">
                <?php echo $_t('भइसकेका', 'Past'); ?> <span class="badge"><?php echo count($pastPrograms); ?></span>
            </a>
        </div>

        <?php if (empty($listPrograms)): ?>
        <div class="cp-empty" data-aos="fade-up">
            <div class="cp-empty-icon"><i class="fas fa-calendar-times"></i></div>
            <h5 class="mb-2"><?php echo $viewTab === 'past'
                ? $_t('भइसकेका कार्यक्रम छैनन्', 'No past programs')
                : $_t('हाल सक्रिय कार्यक्रम उपलब्ध छैन', 'No Active Programs Available'); ?></h5>
            <p class="text-muted small"><?php echo $_t('कृपया पछि फेरि जाँच गर्नुहोस्।', 'Please check back later.'); ?></p>
        </div>
        <?php else: ?>

        <div class="cp-section-sub">
            <h3>
                <i class="fas fa-list-ul"></i>
                <?php echo $viewTab === 'past' ? $_t('भइसकेका कार्यक्रम', 'Past Programs') : $_t('आगामी कार्यक्रमहरू', 'Upcoming Programs'); ?>
                <span class="badge bg-success ms-1" style="font-size:.72rem;"><?php echo count($listPrograms); ?></span>
            </h3>
        </div>

        <div class="row g-4">
        <?php foreach ($listPrograms as $pg):
            $evDate   = $pg['event_date'] ?? '';
            $adEvDate = function_exists('programEventDateToAd') ? programEventDateToAd((string)$evDate) : (string)$evDate;
            $dayNum   = $adEvDate ? date('d', strtotime($adEvDate)) : '';
            $monStr   = $adEvDate ? date('M', strtotime($adEvDate)) : '';
            $isPassed = $adEvDate && strtotime($adEvDate) < $todayTs;
            $showForm = ($preregProgramId === (int)$pg['id'] && ($preregSaved || $preregAlready || $preregError !== ''));
        ?>
            <div class="col-lg-6" data-aos="fade-up" id="prog-<?php echo (int)$pg['id']; ?>">
                <div class="cp-card <?php echo $isPassed ? 'cp-card-past' : ''; ?>">

                    <div class="cp-card-head">
                        <?php if ($dayNum): ?>
                        <div class="cp-date-box">
                            <div class="dd"><?php echo $dayNum; ?></div>
                            <div class="mm"><?php echo $monStr; ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="cp-head-right">
                            <h5><?php echo htmlspecialchars($pg['title']); ?></h5>
                            <?php if (!empty($pg['pre_registration_open']) && !$isPassed): ?>
                                <span class="cp-open-badge"><i class="fas fa-user-plus"></i><?php echo $_t('Pre-reg खुला', 'Pre-reg Open'); ?></span>
                            <?php elseif ($isPassed): ?>
                                <span class="cp-closed-badge"><i class="fas fa-check"></i><?php echo $_t('भइसक्यो', 'Past'); ?></span>
                            <?php else: ?>
                                <span class="cp-closed-badge"><i class="fas fa-lock"></i><?php echo $_t('Pre-reg बन्द', 'Pre-reg Closed'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="cp-card-body">
                        <div class="cp-meta-row">
                            <?php if ($evDate): ?>
                            <span class="cp-pill cp-pill-date">
                                <i class="fas fa-calendar-alt"></i>
                                <?php echo htmlspecialchars($evDate); ?>
                            </span>
                            <?php else: ?>
                            <span class="cp-pill cp-pill-tba"><i class="fas fa-clock"></i><?php echo $_t('मिति घोषणा हुनेछ', 'Date TBA'); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($pg['event_time'])): ?>
                            <span class="cp-pill cp-pill-time"><i class="fas fa-clock"></i><?php echo htmlspecialchars($pg['event_time']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($pg['location'])): ?>
                            <span class="cp-pill cp-pill-loc"><i class="fas fa-map-marker-alt"></i><?php echo htmlspecialchars($pg['location']); ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="cp-desc-text">
                            <?php echo nl2br(htmlspecialchars($pg['description'] ?: $_t('कार्यक्रमको थप विवरण चाँडै अपडेट हुनेछ।', 'Program details will be updated soon.'))); ?>
                        </div>

                        <?php if (!empty($pg['pre_registration_open']) && !$isPassed): ?>
                        <div class="cp-prereg-wrap">
                            <button type="button" class="cp-btn-prereg"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#preRegForm<?php echo (int)$pg['id']; ?>"
                                    aria-expanded="<?php echo $showForm ? 'true' : 'false'; ?>">
                                <i class="fas fa-user-check"></i>
                                <?php echo $_t('Pre-register गर्नुहोस्', 'Pre-register Now'); ?>
                            </button>

                            <div class="collapse cp-prereg-collapse <?php echo $showForm ? 'show' : ''; ?>"
                                 id="preRegForm<?php echo (int)$pg['id']; ?>">
                                <div class="cp-prereg-inner">
                                    <div class="cp-prereg-title">
                                        <i class="fas fa-clipboard-list"></i>
                                        <?php echo $_t('छिटो Pre-Registration', 'Quick Pre-Registration'); ?>
                                    </div>
                                    <p class="small text-muted mb-2"><?php echo $_t('सदस्य पोर्टलबाट पनि pre-register गर्न सकिन्छ।', 'You can also pre-register from the Member Portal.'); ?>
                                        <a href="<?php echo htmlspecialchars($memberPortalAttend); ?>"><?php echo $_t('Portal खोल्नुहोस्', 'Open portal'); ?></a>
                                    </p>
                                    <?php if ($showForm): ?>
                                    <div class="alert py-2 px-3 mb-2 <?php echo $preregSaved ? 'alert-success' : ($preregAlready ? 'alert-warning' : 'alert-danger'); ?>" style="font-size:.84rem;">
                                        <?php if ($preregSaved): ?>
                                            <i class="fas fa-check-circle me-1"></i><?php echo $_t('Registration सफल भयो!', 'Registration successful!'); ?>
                                        <?php elseif ($preregAlready): ?>
                                            <i class="fas fa-info-circle me-1"></i><?php echo $_t('पहिल्यै registration भइसक्यो।', 'Already registered.'); ?>
                                        <?php else: ?>
                                            <i class="fas fa-exclamation-circle me-1"></i><?php echo htmlspecialchars($preregError); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!$preregSaved && !$preregAlready): ?>
                                    <form method="POST" action="cooperative-programs.php?tab=<?php echo urlencode($viewTab); ?>" class="needs-validation row g-2" novalidate>
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="program_preregister">
                                        <input type="hidden" name="program_id" value="<?php echo (int)$pg['id']; ?>">
                                        <div class="col-sm-6">
                                            <input type="text" name="member_id_input" class="form-control form-control-sm"
                                                   placeholder="<?php echo $_t('सदस्यता नं. / कार्ड नं.', 'Member ID / Card No.'); ?>"
                                                   value="<?php echo htmlspecialchars($preregProgramId === (int)$pg['id'] ? $preregMemberInput : ''); ?>"
                                                   required autocomplete="off">
                                        </div>
                                        <div class="col-sm-6">
                                            <input type="text" name="prereg_note" class="form-control form-control-sm"
                                                   placeholder="<?php echo $_t('टिप्पणी (वैकल्पिक)', 'Note (optional)'); ?>"
                                                   value="<?php echo htmlspecialchars($preregProgramId === (int)$pg['id'] ? $preregNoteInput : ''); ?>">
                                        </div>
                                        <div class="col-12">
                                            <button class="btn btn-sm btn-primary">
                                                <i class="fas fa-check-circle me-1"></i><?php echo $_t('Registration Confirm', 'Confirm Registration'); ?>
                                            </button>
                                        </div>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="cp-card-footer">
                        <a href="<?php echo htmlspecialchars($memberPortalAttend); ?>" class="cp-att-btn">
                            <i class="fas fa-id-card"></i>
                            <?php echo $_t('सदस्य पोर्टल — उपस्थिति', 'Member Portal — Attendance'); ?>
                        </a>
                        <a href="<?php echo htmlspecialchars($memberPortalScan); ?>" class="cp-att-btn cp-att-btn-alt">
                            <i class="fas fa-qrcode"></i>
                            <?php echo $_t('QR स्क्यान', 'QR Scan'); ?>
                        </a>
                    </div>

                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</section>

<style>
.cp-howto-card {
  background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 16px 16px 14px;
  height: 100%; box-shadow: 0 4px 14px rgba(0,0,0,.04); position: relative;
}
.cp-howto-num {
  display: inline-flex; width: 28px; height: 28px; border-radius: 999px; align-items: center; justify-content: center;
  background: color-mix(in srgb, var(--primary-color, #1a8754) 14%, white); color: var(--primary-dark, #0a4a25);
  font-weight: 800; font-size: .85rem; margin-bottom: 8px;
}
.cp-howto-card h3 { font-size: .95rem; font-weight: 800; margin: 0 0 6px; color: #111827; }
.cp-howto-card p { font-size: .8rem; color: #6b7280; margin: 0; line-height: 1.5; }
.cp-tabs { display: flex; gap: 8px; flex-wrap: wrap; }
.cp-tab {
  display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 999px;
  background: #fff; border: 1px solid #e5e7eb; color: #374151; text-decoration: none; font-weight: 700; font-size: .85rem;
}
.cp-tab.active { background: var(--primary-color, #1a8754); border-color: transparent; color: #fff; }
.cp-tab .badge { background: rgba(0,0,0,.08); color: inherit; }
.cp-tab.active .badge { background: rgba(255,255,255,.25); }
.cp-card-past { opacity: .92; }
.cp-card-footer { display: flex; flex-wrap: wrap; gap: 8px; }
.cp-att-btn-alt { background: #fff !important; color: var(--primary-dark, #0a4a25) !important; border: 1px solid color-mix(in srgb, var(--primary-color, #1a8754) 35%, #ddd); }
</style>

<?php require_once 'includes/footer.php'; ?>
