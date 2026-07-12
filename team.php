<?php
require_once __DIR__ . '/_bootstrap.php';
$pageTitle = isEnglish() ? 'Contact Officers' : 'मानवीय श्रोत';
require_once 'includes/header.php';

$selectedCommitteeId = isset($_GET['cmt']) ? (int)$_GET['cmt'] : 0;
$selectedTenureId = isset($_GET['tenure']) ? (int)$_GET['tenure'] : 0;

// Get team members
try {
    $db = getDB();
    $boardMembers = $db->query("SELECT * FROM team_members WHERE category = 'board' AND is_active = 1 ORDER BY display_order")->fetchAll();
    $topManagementMembers = $db->query("SELECT * FROM team_members WHERE category = 'top_management' AND is_active = 1 ORDER BY display_order")->fetchAll();
    $managementMembers = $db->query("SELECT * FROM team_members WHERE category = 'management' AND is_active = 1 ORDER BY display_order")->fetchAll();
    $staffMembers = $db->query("SELECT * FROM team_members WHERE category = 'staff' AND is_active = 1 ORDER BY display_order")->fetchAll();
    $adminMembers = $db->query("SELECT * FROM team_members WHERE category = 'admin' AND is_active = 1 ORDER BY display_order")->fetchAll();

    $committeeTypes = [];
    $committeeMembers = [];
    $committeeTenures = [];
    $committeeActiveTenure = [];
    try {
        $committeeTypes = $db->query("SELECT id, name, name_np FROM committee_types WHERE is_active = 1 ORDER BY display_order, id")->fetchAll();
        foreach ($committeeTypes as $_ct) {
            $ctId = (int)$_ct['id'];
            $tenures = [];
            try {
                $tStmt = $db->prepare("SELECT id, tenure_name, tenure_name_np, start_date, end_date, is_current
                    FROM committee_tenures
                    WHERE committee_type_id = ? AND is_active = 1
                    ORDER BY is_current DESC, start_date DESC, id DESC");
                $tStmt->execute([$ctId]);
                $tenures = $tStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $tenures = [];
            }
            $committeeTenures[$ctId] = $tenures;

            $members = [];
            $useTenureId = 0;
            if ($selectedCommitteeId === $ctId && $selectedTenureId > 0) {
                $useTenureId = $selectedTenureId;
            } else {
                foreach ($tenures as $tn) {
                    if (!empty($tn['is_current'])) {
                        $useTenureId = (int)$tn['id'];
                        break;
                    }
                }
            }

            if ($useTenureId > 0) {
                try {
                    $mStmt = $db->prepare("SELECT id, name, name_en, position, position_en, phone, email, photo, display_order
                        FROM committee_members
                        WHERE tenure_id = ? AND is_active = 1
                        ORDER BY display_order, id");
                    $mStmt->execute([$useTenureId]);
                    $members = $mStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    foreach ($members as &$mm) {
                        $mm['position_np'] = $mm['position'] ?? '';
                    }
                    unset($mm);
                } catch (Throwable $e) {
                    $members = [];
                }
            }

            if (empty($members)) {
                $fallback = $db->prepare("SELECT * FROM team_members WHERE category = ? AND is_active = 1 ORDER BY display_order");
                $fallback->execute(['cmt_' . $ctId]);
                $members = $fallback->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            $committeeMembers[$ctId] = $members;
            $committeeActiveTenure[$ctId] = $useTenureId;
        }
    } catch (Throwable $e) {
        $committeeTypes = [];
        $committeeMembers = [];
        $committeeTenures = [];
        $committeeActiveTenure = [];
    }

    // Get Information Officer and Grievance Officer
    $informationOfficer = $db->query("SELECT * FROM team_members WHERE is_information_officer = 1 AND is_active = 1 LIMIT 1")->fetch();
    $grievanceOfficer = $db->query("SELECT * FROM team_members WHERE is_grievance_officer = 1 AND is_active = 1 LIMIT 1")->fetch();
    $chairman = $db->query("SELECT * FROM team_members WHERE is_chairman = 1 AND is_active = 1 LIMIT 1")->fetch();
    $ceo = $db->query("SELECT * FROM team_members WHERE is_ceo = 1 AND is_active = 1 LIMIT 1")->fetch();
} catch (Throwable $e) {
    $boardMembers = $managementMembers = $staffMembers = $adminMembers = [];
    $topManagementMembers = [];
    $informationOfficer = $grievanceOfficer = null;
    $committeeTypes = [];
    $committeeMembers = [];
    $committeeTenures = [];
    $committeeActiveTenure = [];
}
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo isEnglish() ? 'Contact Officers' : 'मानवीय श्रोत'; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo isEnglish() ? 'Contact Officers' : 'मानवीय श्रोत'; ?></li>
            </ol>
        </nav>
    </div>
</section>

<?php
$hasCommitteeFilters = false;
$committeeFilterButtons = [];
foreach ($committeeTypes as $_ct) {
    $ctId = (int)$_ct['id'];
    if (!empty($committeeMembers[$ctId]) || !empty($committeeTenures[$ctId])) {
        $hasCommitteeFilters = true;
        $committeeFilterButtons[] = [
            'id' => $ctId,
            'label' => isEnglish() ? ($_ct['name'] ?: $_ct['name_np']) : ($_ct['name_np'] ?: $_ct['name']),
        ];
    }
}

/* Default committee for the single picker viewer */
$viewCommitteeId = $selectedCommitteeId;
if ($viewCommitteeId <= 0 && !empty($committeeFilterButtons)) {
    $viewCommitteeId = (int)$committeeFilterButtons[0]['id'];
}
$viewCommittee = null;
foreach ($committeeTypes as $_ct) {
    if ((int)$_ct['id'] === $viewCommitteeId) {
        $viewCommittee = $_ct;
        break;
    }
}
$viewTenures = $committeeTenures[$viewCommitteeId] ?? [];
$viewTenureId = (int)($committeeActiveTenure[$viewCommitteeId] ?? 0);
$viewMembers = $committeeMembers[$viewCommitteeId] ?? [];
$viewCommitteeLabel = '';
if ($viewCommittee) {
    $viewCommitteeLabel = isEnglish()
        ? (($viewCommittee['name'] ?: $viewCommittee['name_np']) ?: '')
        : (($viewCommittee['name_np'] ?: $viewCommittee['name']) ?: '');
}
?>

<?php if (!empty($boardMembers) || !empty($topManagementMembers) || !empty($managementMembers) || !empty($staffMembers) || !empty($adminMembers) || $hasCommitteeFilters): ?>
<section class="team-filter-bar section-padding bg-white">
    <div class="container">
        <div class="d-flex flex-wrap gap-2 justify-content-center align-items-center">
            <button type="button" class="filter-btn active" onclick="teamFilter(this, 'all')">
                <i class="fas fa-th-large"></i> <?php echo isEnglish() ? 'All' : 'सबै'; ?>
            </button>
            <button type="button" class="filter-btn" onclick="teamFilter(this, 'contact-officers')">
                <i class="fas fa-id-card-clip"></i> <?php echo isEnglish() ? 'Contact Officers' : 'सम्पर्क अधिकारी'; ?>
            </button>
            <button type="button" class="filter-btn" onclick="teamFilter(this, 'top-management')">
                <i class="fas fa-user-shield"></i> <?php echo isEnglish() ? 'Top Management Team' : 'शीर्ष व्यवस्थापन टोली'; ?>
            </button>
            <button type="button" class="filter-btn" onclick="teamFilter(this, 'board')">
                <i class="lucide-icon" aria-hidden="true" data-lucide="building-columns"></i> <?php echo isEnglish() ? 'Board' : 'सञ्चालक समिति'; ?>
            </button>
            <button type="button" class="filter-btn" onclick="teamFilter(this, 'management')">
                <i class="fas fa-user-tie"></i> <?php echo isEnglish() ? 'Management Team' : 'व्यवस्थापन टोली'; ?>
            </button>
            <button type="button" class="filter-btn" onclick="teamFilter(this, 'staff')">
                <i class="lucide-icon" aria-hidden="true" data-lucide="users"></i> <?php echo isEnglish() ? 'Staff' : 'कर्मचारीहरू'; ?>
            </button>
            <?php if (!empty($adminMembers)): ?>
            <button type="button" class="filter-btn" onclick="teamFilter(this, 'admin')">
                <i class="fas fa-user-shield"></i> <?php echo isEnglish() ? 'Admin' : 'एडमिन'; ?>
            </button>
            <?php endif; ?>
            <?php if ($hasCommitteeFilters): ?>
            <button type="button" class="filter-btn" id="committeeFilterBtn" onclick="teamFilter(this, 'committees')">
                <i class="fas fa-sitemap"></i> <?php echo isEnglish() ? 'Committees' : 'समिति / उपसमिति'; ?>
            </button>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Information & Grievance Officers Section -->
<?php if ($chairman || $ceo || $informationOfficer || $grievanceOfficer): ?>
<section id="contact-officers" class="team-section officers-section section-padding bg-light" data-filter="contact-officers">
    <div class="container">
        <div class="section-header section-header-unified text-center mb-4" data-aos="fade-up">
            <div class="section-badge-wrap">
                <span class="section-badge"><i class="fas fa-user-tie"></i> <?php echo isEnglish() ? 'Contact Officers' : 'सम्पर्क अधिकारी'; ?></span>
            </div>
            <h2><?php echo isEnglish() ? 'Contact Officers' : 'सम्पर्क अधिकारी'; ?></h2>
            <div class="section-divider"></div>
            <p><?php echo isEnglish() ? 'Meet our designated officers, senior leadership and contact points for information or grievances.' : 'सूचना तथा गुनासोका लागि तोकिएका अधिकारीहरू र वरिष्ठ नेतृत्वसँग भेट्नुहोस्'; ?></p>
        </div>

        <div class="row justify-content-center">
            <?php if ($chairman): ?>
            <div class="col-lg-5 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="100">
                <div class="officer-card chairman-officer">
                    <div class="officer-badge">
                        <i class="fas fa-user-tie"></i>
                        <span><?php echo isEnglish() ? 'Chairman' : 'अध्यक्ष'; ?></span>
                    </div>
                    <div class="officer-photo">
                        <?php if ($chairman['photo']): ?>
                            <img src="<?php echo e($chairman['photo']); ?>" loading="lazy" alt="<?php echo e($chairman['name']); ?>">
                        <?php else: ?>
                            <div class="officer-placeholder"><i class="lucide-icon" aria-hidden="true" data-lucide="user"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="officer-info">
                        <h4><?php echo e(isEnglish() && $chairman['name_en'] ? $chairman['name_en'] : $chairman['name']); ?></h4>
                        <span class="position"><?php echo e(isEnglish() && $chairman['position_en'] ? $chairman['position_en'] : ($chairman['position_np'] ?: $chairman['position'])); ?></span>
                        <div class="officer-contact">
                            <?php if ($chairman['phone']): ?>
                            <a href="tel:<?php echo e($chairman['phone']); ?>" class="contact-item">
                                <i class="fas fa-phone"></i>
                                <span><?php echo e($chairman['phone']); ?></span>
                            </a>
                            <?php endif; ?>
                            <?php if ($chairman['email']): ?>
                            <a href="mailto:<?php echo e($chairman['email']); ?>" class="contact-item">
                                <i class="fas fa-envelope"></i>
                                <span><?php echo e($chairman['email']); ?></span>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="officer-description">
                        <p><?php echo isEnglish() ? 'The elected chairman guiding our cooperative governance.' : 'हाम्रा सहकारी शासनलाई मार्गदर्शन गर्ने निर्वाचित अध्यक्ष'; ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($ceo): ?>
            <div class="col-lg-5 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="150">
                <div class="officer-card ceo-officer">
                    <div class="officer-badge">
                        <i class="fas fa-user-check"></i>
                        <span><?php echo isEnglish() ? 'CEO' : 'मुख्य कार्यकारी अधिकारी'; ?></span>
                    </div>
                    <div class="officer-photo">
                        <?php if ($ceo['photo']): ?>
                            <img src="<?php echo e($ceo['photo']); ?>" loading="lazy" alt="<?php echo e($ceo['name']); ?>">
                        <?php else: ?>
                            <div class="officer-placeholder"><i class="lucide-icon" aria-hidden="true" data-lucide="user"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="officer-info">
                        <h4><?php echo e(isEnglish() && $ceo['name_en'] ? $ceo['name_en'] : $ceo['name']); ?></h4>
                        <span class="position"><?php echo e(isEnglish() && $ceo['position_en'] ? $ceo['position_en'] : ($ceo['position_np'] ?: $ceo['position'])); ?></span>
                        <div class="officer-contact">
                            <?php if ($ceo['phone']): ?>
                            <a href="tel:<?php echo e($ceo['phone']); ?>" class="contact-item">
                                <i class="fas fa-phone"></i>
                                <span><?php echo e($ceo['phone']); ?></span>
                            </a>
                            <?php endif; ?>
                            <?php if ($ceo['email']): ?>
                            <a href="mailto:<?php echo e($ceo['email']); ?>" class="contact-item">
                                <i class="fas fa-envelope"></i>
                                <span><?php echo e($ceo['email']); ?></span>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="officer-description">
                        <p><?php echo isEnglish() ? 'The executive leader responsible for daily operations.' : 'दैनिक सञ्चालनको लागि उत्तरदायी कार्यकारी प्रमुख'; ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($informationOfficer): ?>
            <div class="col-lg-5 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="200">
                <div class="officer-card information-officer">
                    <div class="officer-badge">
                        <i class="fas fa-info-circle"></i>
                        <span><?php echo isEnglish() ? 'Information Officer' : 'सूचना अधिकारी'; ?></span>
                    </div>
                    <div class="officer-photo">
                        <?php if ($informationOfficer['photo']): ?>
                            <img src="<?php echo e($informationOfficer['photo']); ?>" loading="lazy" alt="<?php echo e($informationOfficer['name']); ?>">
                        <?php else: ?>
                            <div class="officer-placeholder"><i class="lucide-icon" aria-hidden="true" data-lucide="user"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="officer-info">
                        <h4><?php echo e(isEnglish() && $informationOfficer['name_en'] ? $informationOfficer['name_en'] : $informationOfficer['name']); ?></h4>
                        <span class="position"><?php echo e(isEnglish() && $informationOfficer['position_en'] ? $informationOfficer['position_en'] : ($informationOfficer['position_np'] ?: $informationOfficer['position'])); ?></span>
                        <div class="officer-contact">
                            <?php if ($informationOfficer['phone']): ?>
                            <a href="tel:<?php echo e($informationOfficer['phone']); ?>" class="contact-item">
                                <i class="fas fa-phone"></i>
                                <span><?php echo e($informationOfficer['phone']); ?></span>
                            </a>
                            <?php endif; ?>
                            <?php if ($informationOfficer['email']): ?>
                            <a href="mailto:<?php echo e($informationOfficer['email']); ?>" class="contact-item">
                                <i class="fas fa-envelope"></i>
                                <span><?php echo e($informationOfficer['email']); ?></span>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="officer-description">
                        <p><?php echo isEnglish() ? 'For any information related queries as per Right to Information Act, please contact.' : 'सूचनाको हकसम्बन्धी ऐन अनुसार कुनै पनि सूचना सम्बन्धी जिज्ञासाको लागि सम्पर्क गर्नुहोस्।'; ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($grievanceOfficer): ?>
            <div class="col-lg-5 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="250">
                <div class="officer-card grievance-officer">
                    <div class="officer-badge">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span><?php echo isEnglish() ? 'Grievance Officer' : 'गुनासो अधिकारी'; ?></span>
                    </div>
                    <div class="officer-photo">
                        <?php if ($grievanceOfficer['photo']): ?>
                            <img src="<?php echo e($grievanceOfficer['photo']); ?>" loading="lazy" alt="<?php echo e($grievanceOfficer['name']); ?>">
                        <?php else: ?>
                            <div class="officer-placeholder"><i class="lucide-icon" aria-hidden="true" data-lucide="user"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="officer-info">
                        <h4><?php echo e(isEnglish() && $grievanceOfficer['name_en'] ? $grievanceOfficer['name_en'] : $grievanceOfficer['name']); ?></h4>
                        <span class="position"><?php echo e(isEnglish() && $grievanceOfficer['position_en'] ? $grievanceOfficer['position_en'] : ($grievanceOfficer['position_np'] ?: $grievanceOfficer['position'])); ?></span>
                        <div class="officer-contact">
                            <?php if ($grievanceOfficer['phone']): ?>
                            <a href="tel:<?php echo e($grievanceOfficer['phone']); ?>" class="contact-item">
                                <i class="fas fa-phone"></i>
                                <span><?php echo e($grievanceOfficer['phone']); ?></span>
                            </a>
                            <?php endif; ?>
                            <?php if ($grievanceOfficer['email']): ?>
                            <a href="mailto:<?php echo e($grievanceOfficer['email']); ?>" class="contact-item">
                                <i class="fas fa-envelope"></i>
                                <span><?php echo e($grievanceOfficer['email']); ?></span>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="officer-description">
                        <p><?php echo isEnglish() ? 'For any complaints or grievances related to our services, please contact.' : 'हाम्रो सेवासँग सम्बन्धित कुनै पनि गुनासो वा उजुरीको लागि सम्पर्क गर्नुहोस्।'; ?></p>
                        <a href="grievance.php" class="btn btn-sm btn-outline-danger mt-2">
                            <i class="fas fa-pen"></i> <?php echo isEnglish() ? 'File Grievance Online' : 'अनलाइन गुनासो दर्ता'; ?>
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($boardMembers)): ?>
<section id="board" class="team-section section-padding" data-filter="board">
    <div class="container">
        <div class="section-header section-header-unified text-center">
            <h2>सञ्चालक समिति</h2>
            <p>हाम्रो संस्थाको नेतृत्व गर्ने समिति</p>
        </div>

        <div class="row justify-content-center">
            <?php foreach ($boardMembers as $index => $member): ?>
            <div class="col-lg-3 col-md-4 col-sm-6 mb-4" data-aos="fade-up" data-aos-delay="<?php echo $index * 50; ?>">
                <div class="team-card-circular <?php echo $index === 0 ? 'featured' : ''; ?>">
                    <div class="team-photo-circular">
                        <?php if ($member['photo']): ?>
                            <img src="<?php echo e($member['photo']); ?>" loading="lazy" alt="<?php echo e($member['name']); ?>">
                        <?php else: ?>
                            <div class="team-placeholder-circular"><i class="lucide-icon" aria-hidden="true" data-lucide="user"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="team-info-circular">
                        <h5><?php echo e($member['name']); ?></h5>
                        <?php if ($member['name_en']): ?>
                        <p class="team-name-en"><?php echo e($member['name_en']); ?></p>
                        <?php endif; ?>
                        <span class="team-position-badge"><?php echo e($member['position_np'] ?: $member['position']); ?></span>
                        <?php if ($member['phone'] || $member['email']): ?>
                        <div class="team-contact-circular">
                            <?php if ($member['phone']): ?>
                                <a href="tel:<?php echo e($member['phone']); ?>" title="<?php echo e($member['phone']); ?>"><i class="fas fa-phone"></i></a>
                            <?php endif; ?>
                            <?php if ($member['email']): ?>
                                <a href="mailto:<?php echo e($member['email']); ?>" title="<?php echo e($member['email']); ?>"><i class="fas fa-envelope"></i></a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Management Team -->
<?php if (!empty($topManagementMembers)): ?>
<section id="top-management" class="team-section section-padding bg-light" data-filter="top-management">
    <div class="container">
        <div class="section-header section-header-unified text-center">
            <h2><?php echo isEnglish() ? 'Top Management Team' : 'शीर्ष व्यवस्थापन टोली'; ?></h2>
            <p><?php echo isEnglish() ? 'Our senior managers and executive employees.' : 'हाम्रा वरिष्ठ व्यवस्थापनका कर्मचारीहरू'; ?></p>
        </div>

        <div class="row justify-content-center">
            <?php foreach ($topManagementMembers as $index => $member): ?>
            <div class="col-lg-3 col-md-4 col-sm-6 mb-4" data-aos="fade-up" data-aos-delay="<?php echo $index * 50; ?>">
                <div class="team-card-circular">
                    <div class="team-photo-circular">
                        <?php if ($member['photo']): ?>
                            <img src="<?php echo e($member['photo']); ?>" loading="lazy" alt="<?php echo e($member['name']); ?>">
                        <?php else: ?>
                            <div class="team-placeholder-circular"><i class="lucide-icon" aria-hidden="true" data-lucide="user"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="team-info-circular">
                        <h5><?php echo e($member['name']); ?></h5>
                        <?php if ($member['name_en']): ?>
                        <p class="team-name-en"><?php echo e($member['name_en']); ?></p>
                        <?php endif; ?>
                        <span class="team-position-badge"><?php echo e($member['position_np'] ?: $member['position']); ?></span>
                        <?php if ($member['phone'] || $member['email']): ?>
                        <div class="team-contact-circular">
                            <?php if ($member['phone']): ?>
                                <a href="tel:<?php echo e($member['phone']); ?>" title="<?php echo e($member['phone']); ?>"><i class="fas fa-phone"></i></a>
                            <?php endif; ?>
                            <?php if ($member['email']): ?>
                                <a href="mailto:<?php echo e($member['email']); ?>" title="<?php echo e($member['email']); ?>"><i class="fas fa-envelope"></i></a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($managementMembers)): ?>
<section id="management" class="team-section section-padding bg-light" data-filter="management">
    <div class="container">
        <div class="section-header section-header-unified text-center">
            <h2>व्यवस्थापन टोली</h2>
            <p>संस्थाको दैनिक सञ्चालन गर्ने टोली</p>
        </div>

        <div class="row justify-content-center">
            <?php foreach ($managementMembers as $index => $member): ?>
            <div class="col-lg-3 col-md-4 col-sm-6 mb-4" data-aos="fade-up" data-aos-delay="<?php echo $index * 50; ?>">
                <div class="team-card-circular">
                    <div class="team-photo-circular">
                        <?php if ($member['photo']): ?>
                            <img src="<?php echo e($member['photo']); ?>" loading="lazy" alt="<?php echo e($member['name']); ?>">
                        <?php else: ?>
                            <div class="team-placeholder-circular"><i class="lucide-icon" aria-hidden="true" data-lucide="user"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="team-info-circular">
                        <h5><?php echo e($member['name']); ?></h5>
                        <span class="team-position-badge"><?php echo e($member['position_np'] ?: $member['position']); ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Committees viewer: one section + committee/tenure dropdowns -->
<?php if ($hasCommitteeFilters && $viewCommittee): ?>
<section id="committees" class="team-section section-padding bg-light" data-filter="committees"
         data-active-cmt="<?php echo (int)$viewCommitteeId; ?>">
    <?php foreach ($committeeFilterButtons as $_cf): ?>
    <div id="committee-<?php echo (int)$_cf['id']; ?>" class="visually-hidden" aria-hidden="true"></div>
    <?php endforeach; ?>
    <div class="container">
        <div class="section-header section-header-unified text-center">
            <h2><?php echo isEnglish() ? 'Committees / Subcommittees' : 'समिति / उपसमिति'; ?></h2>
            <p><?php echo isEnglish()
                ? 'Choose a committee and tenure to view members.'
                : 'समिति र कार्यकाल छानेर सदस्यहरू हेर्नुहोस्।'; ?></p>
        </div>

        <div class="team-cmt-picker row g-3 justify-content-center mb-4">
            <div class="col-md-5 col-lg-4">
                <label class="form-label small fw-semibold text-success mb-1" for="teamCmtSelect">
                    <i class="fas fa-sitemap me-1"></i><?php echo isEnglish() ? 'Committee' : 'समिति'; ?>
                </label>
                <div class="coop-select-wrap">
                    <i class="fas fa-sitemap coop-select-icon"></i>
                    <select id="teamCmtSelect" class="form-select coop-select-field"
                            aria-label="<?php echo isEnglish() ? 'Select committee' : 'समिति छान्नुहोस्'; ?>">
                        <?php foreach ($committeeFilterButtons as $_cf): ?>
                        <option value="<?php echo (int)$_cf['id']; ?>" <?php echo (int)$_cf['id'] === $viewCommitteeId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($_cf['label']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="col-md-5 col-lg-4">
                <label class="form-label small fw-semibold text-success mb-1" for="teamTenureSelect">
                    <i class="fas fa-calendar-alt me-1"></i><?php echo isEnglish() ? 'Tenure' : 'कार्यकाल'; ?>
                </label>
                <div class="coop-select-wrap">
                    <i class="fas fa-calendar-alt coop-select-icon"></i>
                    <select id="teamTenureSelect" class="form-select coop-select-field"
                            aria-label="<?php echo isEnglish() ? 'Select tenure' : 'कार्यकाल छान्नुहोस्'; ?>"
                            <?php echo empty($viewTenures) ? 'disabled' : ''; ?>>
                        <?php if (empty($viewTenures)): ?>
                        <option value="0"><?php echo isEnglish() ? 'Current members' : 'हालका सदस्यहरू'; ?></option>
                        <?php else: ?>
                            <?php foreach ($viewTenures as $tn):
                                $tid = (int)$tn['id'];
                                $label = isEnglish()
                                    ? (($tn['tenure_name'] ?: $tn['tenure_name_np']) ?: ('Tenure #' . $tid))
                                    : (($tn['tenure_name_np'] ?: $tn['tenure_name']) ?: ('कार्यकाल #' . $tid));
                                $period = '';
                                if (!empty($tn['start_date']) || !empty($tn['end_date'])) {
                                    $period = trim(($tn['start_date'] ?? '') . ' – ' . ($tn['end_date'] ?? ''));
                                }
                            ?>
                            <option value="<?php echo $tid; ?>" <?php echo $viewTenureId === $tid ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?><?php if ($period): ?> (<?php echo htmlspecialchars($period); ?>)<?php endif; ?>
                                <?php if (!empty($tn['is_current'])): ?> <?php echo isEnglish() ? '(Current)' : '(हालको)'; ?><?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="text-center mb-3">
            <span class="badge rounded-pill px-3 py-2" style="background:rgba(26,95,42,.1);color:var(--primary-color,#1a5f2a);font-weight:600;">
                <?php echo htmlspecialchars($viewCommitteeLabel); ?>
            </span>
        </div>

        <div class="row justify-content-center" id="teamCmtMembers">
            <?php foreach ($viewMembers as $index => $member): ?>
            <div class="col-lg-3 col-md-4 col-sm-6 mb-4">
                <div class="team-card-circular <?php echo $index === 0 ? 'featured' : ''; ?>">
                    <div class="team-photo-circular">
                        <?php if (!empty($member['photo'])): ?>
                            <img src="<?php echo e($member['photo']); ?>" loading="lazy" alt="<?php echo e($member['name']); ?>">
                        <?php else: ?>
                            <div class="team-placeholder-circular"><i class="lucide-icon" aria-hidden="true" data-lucide="user"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="team-info-circular">
                        <h5><?php echo e($member['name']); ?></h5>
                        <?php if (!empty($member['name_en'])): ?>
                        <p class="team-name-en"><?php echo e($member['name_en']); ?></p>
                        <?php endif; ?>
                        <span class="team-position-badge"><?php echo e($member['position_np'] ?: ($member['position'] ?? '')); ?></span>
                        <?php if (!empty($member['phone']) || !empty($member['email'])): ?>
                        <div class="team-contact-circular">
                            <?php if (!empty($member['phone'])): ?>
                                <a href="tel:<?php echo e($member['phone']); ?>" title="<?php echo e($member['phone']); ?>"><i class="fas fa-phone"></i></a>
                            <?php endif; ?>
                            <?php if (!empty($member['email'])): ?>
                                <a href="mailto:<?php echo e($member['email']); ?>" title="<?php echo e($member['email']); ?>"><i class="fas fa-envelope"></i></a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (empty($viewMembers)): ?>
        <p class="text-center text-muted mb-0"><?php echo isEnglish() ? 'No members found for this selection.' : 'यो छनोटका लागि सदस्य भेटिएन।'; ?></p>
        <?php endif; ?>
    </div>
</section>
<style>
.coop-select-wrap{position:relative;display:flex;align-items:center}
.coop-select-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--primary-color,#1a5f2a);font-size:.85rem;pointer-events:none;z-index:2}
.coop-select-field{padding-left:42px;border:1.5px solid color-mix(in srgb,var(--primary-color,#1a5f2a) 30%,#e5e7eb);border-radius:10px;font-size:.93rem;background:#fff;min-height:44px}
.team-cmt-picker .form-label{letter-spacing:.02em}
</style>
<script>
window.__teamCmtTenures = <?php
    $tenureMap = [];
    foreach ($committeeTenures as $cid => $rows) {
        if (!is_numeric($cid)) continue;
        $tenureMap[(int)$cid] = array_map(static function ($tn) {
            return [
                'id' => (int)$tn['id'],
                'label' => (string)(($tn['tenure_name_np'] ?: $tn['tenure_name']) ?: ('#' . $tn['id'])),
                'label_en' => (string)(($tn['tenure_name'] ?: $tn['tenure_name_np']) ?: ('#' . $tn['id'])),
                'period' => trim(($tn['start_date'] ?? '') . ((!empty($tn['start_date']) || !empty($tn['end_date'])) ? ' – ' : '') . ($tn['end_date'] ?? '')),
                'is_current' => !empty($tn['is_current']) ? 1 : 0,
            ];
        }, $rows ?: []);
    }
    echo json_encode($tenureMap, JSON_UNESCAPED_UNICODE);
?>;
window.__teamCmtIsEn = <?php echo isEnglish() ? 'true' : 'false'; ?>;
</script>
<?php endif; ?>

<!-- Staff -->
<?php if (!empty($staffMembers)): ?>
<section id="staff" class="team-section section-padding" data-filter="staff">
    <div class="container">
        <div class="section-header section-header-unified text-center">
            <h2>कर्मचारीहरू</h2>
            <p>हाम्रो समर्पित कर्मचारी टोली</p>
        </div>

        <div class="row justify-content-center">
            <?php foreach ($staffMembers as $index => $member): ?>
            <div class="col-lg-2 col-md-3 col-sm-4 col-6 mb-4" data-aos="fade-up" data-aos-delay="<?php echo ($index % 6) * 50; ?>">
                <div class="team-card-circular small">
                    <div class="team-photo-circular small">
                        <?php if ($member['photo']): ?>
                            <img src="<?php echo e($member['photo']); ?>" loading="lazy" alt="<?php echo e($member['name']); ?>">
                        <?php else: ?>
                            <div class="team-placeholder-circular"><i class="lucide-icon" aria-hidden="true" data-lucide="user"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="team-info-circular">
                        <h6><?php echo e($member['name']); ?></h6>
                        <span class="team-position-badge small"><?php echo e($member['position_np'] ?: $member['position']); ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Admin Team -->
<?php if (!empty($adminMembers)): ?>
<section id="admin" class="team-section section-padding bg-light" data-filter="admin">
    <div class="container">
        <div class="section-header section-header-unified text-center">
            <h2><?php echo isEnglish() ? 'Admin Team' : 'एडमिन टोली'; ?></h2>
            <p><?php echo isEnglish() ? 'System and operations administration' : 'प्रणाली तथा सञ्चालन व्यवस्थापन गर्ने टोली'; ?></p>
        </div>

        <div class="row justify-content-center">
            <?php foreach ($adminMembers as $index => $member): ?>
            <div class="col-lg-2 col-md-3 col-sm-4 col-6 mb-4" data-aos="fade-up" data-aos-delay="<?php echo ($index % 6) * 50; ?>">
                <div class="team-card-circular small">
                    <div class="team-photo-circular small">
                        <?php if ($member['photo']): ?>
                            <img src="<?php echo e($member['photo']); ?>" loading="lazy" alt="<?php echo e($member['name']); ?>">
                        <?php else: ?>
                            <div class="team-placeholder-circular"><i class="lucide-icon" aria-hidden="true" data-lucide="user"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="team-info-circular">
                        <h6><?php echo e($member['name']); ?></h6>
                        <span class="team-position-badge small"><?php echo e($member['position_np'] ?: $member['position']); ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (empty($boardMembers) && empty($managementMembers) && empty($staffMembers) && empty($adminMembers) && empty(array_filter($committeeMembers))): ?>
<section class="section-padding">
    <div class="container">
        <div class="empty-state text-center py-5">
            <i class="lucide-icon fa-4x text-muted mb-3" aria-hidden="true" data-lucide="users"></i>
            <h4>टोली जानकारी उपलब्ध छैन</h4>
            <p class="text-muted">हाल टोली सदस्यहरूको जानकारी उपलब्ध छैन।</p>
        </div>
    </div>
</section>
<?php endif; ?>

<script>
function teamFilter(btn, filter) {
    document.querySelectorAll('.team-filter-bar .filter-btn').forEach(function (el) {
        el.classList.toggle('active', el === btn);
    });
    document.querySelectorAll('.team-section[data-filter]').forEach(function (section) {
        var match = filter === 'all' || section.getAttribute('data-filter') === filter;
        section.style.display = match ? '' : 'none';
        section.classList.toggle('team-section--focused', match && filter !== 'all');
    });
    if (filter !== 'all') {
        var target = document.querySelector('.team-section[data-filter="' + filter + '"]');
        if (target) {
            setTimeout(function () {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 40);
        }
    }
}

function teamGoCommittee(cmtId, tenureId) {
    var url = 'team.php?cmt=' + encodeURIComponent(cmtId || 0);
    if (tenureId) url += '&tenure=' + encodeURIComponent(tenureId);
    url += '#committees';
    location.href = url;
}

(function () {
    var cmtSelect = document.getElementById('teamCmtSelect');
    var tenureSelect = document.getElementById('teamTenureSelect');
    var tenureData = window.__teamCmtTenures || {};
    var isEn = !!window.__teamCmtIsEn;

    function fillTenures(cmtId, preferredTenure) {
        if (!tenureSelect) return;
        var rows = tenureData[String(cmtId)] || tenureData[cmtId] || [];
        tenureSelect.innerHTML = '';
        if (!rows.length) {
            var opt = document.createElement('option');
            opt.value = '0';
            opt.textContent = isEn ? 'Current members' : 'हालका सदस्यहरू';
            tenureSelect.appendChild(opt);
            tenureSelect.disabled = true;
            return;
        }
        tenureSelect.disabled = false;
        var currentId = preferredTenure || 0;
        rows.forEach(function (tn) {
            if (!currentId && tn.is_current) currentId = tn.id;
            var opt = document.createElement('option');
            opt.value = String(tn.id);
            var label = isEn ? (tn.label_en || tn.label) : (tn.label || tn.label_en);
            if (tn.period) label += ' (' + tn.period + ')';
            if (tn.is_current) label += isEn ? ' (Current)' : ' (हालको)';
            opt.textContent = label;
            tenureSelect.appendChild(opt);
        });
        if (!currentId && rows[0]) currentId = rows[0].id;
        tenureSelect.value = String(currentId);
    }

    if (cmtSelect) {
        cmtSelect.addEventListener('change', function () {
            var cmtId = parseInt(cmtSelect.value, 10) || 0;
            fillTenures(cmtId, 0);
            var tid = tenureSelect && !tenureSelect.disabled ? (parseInt(tenureSelect.value, 10) || 0) : 0;
            teamGoCommittee(cmtId, tid);
        });
    }
    if (tenureSelect) {
        tenureSelect.addEventListener('change', function () {
            var cmtId = cmtSelect ? (parseInt(cmtSelect.value, 10) || 0) : 0;
            var tid = parseInt(tenureSelect.value, 10) || 0;
            teamGoCommittee(cmtId, tid);
        });
    }

    function applyHashFocus() {
        var hash = (location.hash || '').replace(/^#/, '');
        var params = new URLSearchParams(location.search);
        var cmtParam = parseInt(params.get('cmt') || '0', 10) || 0;

        if (/^committee-\d+$/.test(hash)) {
            var id = parseInt(hash.replace('committee-', ''), 10) || 0;
            if (id && (!cmtParam || cmtParam !== id)) {
                teamGoCommittee(id, parseInt(params.get('tenure') || '0', 10) || 0);
                return;
            }
            hash = 'committees';
            if (cmtSelect && id) cmtSelect.value = String(id);
        }

        if (hash === 'committees' || cmtParam > 0) {
            var btn = document.getElementById('committeeFilterBtn');
            teamFilter(btn, 'committees');
            return;
        }

        if (!hash) return;
        var section = document.getElementById(hash);
        if (!section || !section.classList.contains('team-section')) return;
        var filter = section.getAttribute('data-filter') || hash;
        var matchBtn = document.querySelector('.team-filter-bar .filter-btn[onclick*="' + filter + '"]');
        teamFilter(matchBtn, filter);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', applyHashFocus);
    else applyHashFocus();
    window.addEventListener('hashchange', applyHashFocus);
})();
</script>
<style>
.team-section--focused {
    outline: 2px solid color-mix(in srgb, var(--primary-color, #1a5f2a) 35%, transparent);
    outline-offset: 6px;
    border-radius: 12px;
}
.visually-hidden {
    position: absolute !important;
    width: 1px; height: 1px; padding: 0; margin: -1px;
    overflow: hidden; clip: rect(0,0,0,0); border: 0;
}
</style>
<?php require_once 'includes/footer.php'; ?>
