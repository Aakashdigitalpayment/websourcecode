<?php
require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded
$pageTitle = isEnglish() ? 'Committees' : 'समिति/उपसमिति';
require_once 'includes/header.php';
$L = getLangStrings();

// Get selected committee type
/* ?type=N (पुरानो) र ?id=N (नयाँ navbar बाट) — दुवै support */
$selectedType  = isset($_GET['type']) ? (int)$_GET['type'] : (isset($_GET['id']) ? (int)$_GET['id'] : null);
$selectedName  = isset($_GET['name']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', trim($_GET['name'])) : null;
$showPast      = isset($_GET['past']) && $_GET['past'] == '1';
$selectedTenure = isset($_GET['tenure']) ? (int)$_GET['tenure'] : null; // past tenure year filter

// Get data from database
try {
    $db = getDB();

    // Get committee types
    $committeeTypes = $db->query("SELECT * FROM committee_types WHERE is_active = 1 ORDER BY display_order, id")->fetchAll();

    // Resolve name → id (nav links use ?name=sanchalak etc.)
    if (!$selectedType && $selectedName) {
        $nameMap = [
            'sanchalak'   => ['सञ्चालक','sanchalak','board'],
            'byabasthapan'=> ['व्यवस्थापन','byabasthapan','management'],
            'lekha'       => ['लेखा','lekha','audit'],
            'sallahakar'  => ['सल्लाह','sallahakar','advisory'],
            'anya'        => ['अन्य','anya','sub','other'],
        ];
        $keywords = $nameMap[$selectedName] ?? [$selectedName];
        foreach ($committeeTypes as $t) {
            $haystack = strtolower($t['name'] . ' ' . $t['name_np']);
            foreach ($keywords as $kw) {
                if (mb_strpos($haystack, mb_strtolower($kw)) !== false) {
                    $selectedType = $t['id'];
                    break 2;
                }
            }
        }
    }

    // Also get board members from team_members table (for showing in filters)
    $boardMembers = $db->query("SELECT * FROM team_members WHERE category = 'board' AND is_active = 1 ORDER BY display_order")->fetchAll();
    
    // Get committee members from team_members table by category (cmt_X format)
    $allTeamMembers = $db->query("SELECT * FROM team_members WHERE category LIKE 'cmt_%' AND is_active = 1 ORDER BY category, display_order")->fetchAll();
    $committeeTeamMembers = [];
    foreach ($allTeamMembers as $tm) {
        $cmtId = (int)str_replace('cmt_', '', $tm['category']);
        if ($cmtId > 0) {
            if (!isset($committeeTeamMembers[$cmtId])) $committeeTeamMembers[$cmtId] = [];
            $committeeTeamMembers[$cmtId][] = $tm;
        }
    }

    // Get current tenures with members
    $currentCommittees = [];
    $pastCommittees = [];

    foreach ($committeeTypes as $type) {
        // Skip if specific type is selected and this isn't it
        if ($selectedType && $selectedType != $type['id']) continue;

        // Get current tenure
        $stmt = $db->prepare("SELECT * FROM committee_tenures WHERE committee_type_id = ? AND is_current = 1 AND is_active = 1");
        $stmt->execute([$type['id']]);
        $currentTenure = $stmt->fetch();

        if ($currentTenure) {
            // Get members for current tenure
            $memberStmt = $db->prepare("SELECT * FROM committee_members WHERE tenure_id = ? AND is_active = 1 ORDER BY display_order, id");
            $memberStmt->execute([$currentTenure['id']]);
            $members = $memberStmt->fetchAll();

            // If no members in committee_members, try to get from team_members (for this committee type)
            if (empty($members)) {
                // First check if this is the board committee
                if (stripos($type['name'], 'संचालक') !== false || stripos($type['name'], 'board') !== false) {
                    $members = $boardMembers;
                } else {
                    // Check team_members table for members with matching cmt_X category
                    $members = $committeeTeamMembers[$type['id']] ?? [];
                }
            }

            $currentCommittees[] = [
                'type' => $type,
                'tenure' => $currentTenure,
                'members' => $members
            ];
        } else {
            // Even if no tenure, still show the type if we have team members for it
            $isBoardType = (stripos($type['name'], 'संचालक') !== false || stripos($type['name'], 'board') !== false);
            $teamMembers = $isBoardType ? $boardMembers : ($committeeTeamMembers[$type['id']] ?? []);
            
            if (!empty($teamMembers)) {
                $currentCommittees[] = [
                    'type' => $type,
                    'tenure' => ['tenure_name' => isEnglish() ? 'Current' : 'हालको'],
                    'members' => $teamMembers
                ];
            }
        }

        // Get past tenures
        if ($showPast || $selectedType) {
            $pastStmt = $db->prepare("SELECT * FROM committee_tenures WHERE committee_type_id = ? AND is_current = 0 AND is_active = 1 ORDER BY start_date DESC");
            $pastStmt->execute([$type['id']]);
            $pastTenures = $pastStmt->fetchAll();

            foreach ($pastTenures as $tenure) {
                // If a specific tenure year is selected, only show that one
                if ($selectedTenure && $tenure['id'] != $selectedTenure) continue;

                $memberStmt = $db->prepare("SELECT * FROM committee_members WHERE tenure_id = ? AND is_active = 1 ORDER BY display_order, id");
                $memberStmt->execute([$tenure['id']]);
                $members = $memberStmt->fetchAll();

                $pastCommittees[] = [
                    'type'    => $type,
                    'tenure'  => $tenure,
                    'members' => $members
                ];
            }

            // Collect all available past tenures for the dropdown (for selected type or all)
            if (!isset($allPastTenures)) $allPastTenures = [];
            foreach ($pastTenures as $t) {
                $allPastTenures[$t['id']] = [
                    'id'         => $t['id'],
                    'label'      => ($t['tenure_name_np'] ?? $t['tenure_name'] ?? ''),
                    'type_id'    => $type['id'],
                    'type_label' => isEnglish() ? $type['name'] : $type['name_np'],
                ];
            }
        }
    }

    // If no committees found at all but we have board members, show them as default
    if (empty($currentCommittees) && !empty($boardMembers) && !$showPast) {
        $currentCommittees[] = [
            'type' => ['id' => 0, 'name' => 'Board of Directors', 'name_np' => 'सञ्चालक समिति'],
            'tenure' => ['tenure_name' => isEnglish() ? 'Current' : 'हालको'],
            'members' => $boardMembers
        ];
    }
} catch (Throwable $e) {
    $committeeTypes = [];
    $currentCommittees = [];
    $pastCommittees = [];
    $boardMembers = [];
    $committeeTeamMembers = [];
}

/* Section heading context: "Management Team" छानिएको बेला generic "समिति सदस्य" नदेखियोस् */
$isManagementView = false;
if (!$showPast && !empty($currentCommittees) && count($currentCommittees) === 1) {
    $onlyType = $currentCommittees[0]['type'] ?? [];
    $typeText = mb_strtolower(trim((string)(($onlyType['name_np'] ?? '') . ' ' . ($onlyType['name'] ?? ''))));
    $isManagementView = (mb_strpos($typeText, 'व्यवस्थापन') !== false || mb_strpos($typeText, 'management') !== false);
}

$currentBadgeTitle = $isManagementView
    ? (isEnglish() ? 'Current Management Team' : 'हालका व्यवस्थापन समूह')
    : (isEnglish() ? 'Current Committees' : 'हालका समितिहरू');
$currentHeroTitle = $isManagementView
    ? (isEnglish() ? 'Current Management Team Members' : 'हालका व्यवस्थापन समूह सदस्यहरू')
    : (isEnglish() ? 'Current Committee Members' : 'हालका समिति सदस्यहरू');
$currentHeroDesc = $isManagementView
    ? (isEnglish() ? 'Our current management team serving the cooperative' : 'हाम्रो सहकारीलाई सेवा गरिरहेका हालका व्यवस्थापन समूह सदस्यहरू')
    : (isEnglish() ? 'Our current committee members serving the cooperative' : 'हाम्रो सहकारीलाई सेवा गरिरहेका हालका समिति सदस्यहरू');
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo isEnglish() ? 'Committees & Sub-committees' : 'समिति/उपसमिति'; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo isEnglish() ? 'Committees' : 'समिति'; ?></li>
            </ol>
        </nav>
    </div>
</section>

<!-- Committee Filter — dropdown (replaces tab cloud that wraps badly with many types) -->
<section class="committee-filter py-4">
    <div class="container">
        <div class="filter-wrapper">
            <div class="row align-items-center g-3">

                <!-- Committee type dropdown -->
                <div class="col-sm-6 col-md-5">
                    <div class="coop-select-wrap">
                        <i class="fas fa-users coop-select-icon"></i>
                        <select id="committeeTypeSelect" class="form-select coop-select-field"
                                onchange="location.href=this.value"
                                aria-label="<?php echo isEnglish() ? 'Select committee type' : 'समिति प्रकार छान्नुहोस्'; ?>">
                            <option value="committees.php<?php echo $showPast ? '?past=1' : ''; ?>"
                                <?php echo !$selectedType ? 'selected' : ''; ?>>
                                <?php echo isEnglish() ? '— All Committees —' : '— सबै समितिहरू —'; ?>
                            </option>
                            <?php foreach ($committeeTypes as $type): ?>
                            <option value="?<?php echo $showPast ? 'past=1&' : ''; ?>type=<?php echo $type['id']; ?>"
                                <?php echo $selectedType == $type['id'] ? 'selected' : ''; ?>>
                                <?php echo isEnglish() ? htmlspecialchars($type['name']) : htmlspecialchars($type['name_np']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Tenure/year dropdown — only in past mode -->
                <?php if ($showPast && !empty($allPastTenures)): ?>
                <div class="col-sm-6 col-md-4">
                    <div class="coop-select-wrap">
                        <i class="fas fa-calendar-alt coop-select-icon"></i>
                        <select class="form-select coop-select-field"
                                onchange="location.href=this.value"
                                aria-label="<?php echo isEnglish() ? 'Select tenure year' : 'कार्यकाल छान्नुहोस्'; ?>">
                            <option value="?past=1<?php echo $selectedType ? '&type='.$selectedType : ''; ?>">
                                <?php echo isEnglish() ? '— All Years —' : '— सबै कार्यकाल —'; ?>
                            </option>
                            <?php foreach ($allPastTenures as $pt): ?>
                            <option value="?past=1<?php echo $selectedType ? '&type='.$selectedType : ''; ?>&tenure=<?php echo $pt['id']; ?>"
                                <?php echo $selectedTenure == $pt['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($pt['label'] ?: $pt['type_label']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Current / Past toggle -->
                <div class="col-sm-12 col-md-<?php echo ($showPast && !empty($allPastTenures)) ? '3' : '7'; ?> text-md-end">
                    <?php if (!$showPast): ?>
                    <a href="?past=1<?php echo $selectedType ? '&type='.$selectedType : ''; ?>"
                       class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-history me-1"></i>
                        <?php echo isEnglish() ? 'Past Committees' : 'विगतका समितिहरू'; ?>
                    </a>
                    <?php else: ?>
                    <a href="committees.php<?php echo $selectedType ? '?type='.$selectedType : ''; ?>"
                       class="btn btn-primary btn-sm">
                        <i class="fas fa-users me-1"></i>
                        <?php echo isEnglish() ? 'Current Committees' : 'हालका समितिहरू'; ?>
                    </a>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</section>
<style>
.coop-select-wrap {
    position: relative !important;
    display: flex !important;
    align-items: center !important;
}
.coop-select-icon {
    position: absolute !important;
    left: 14px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    color: var(--primary-color, #1a5f2a) !important;
    font-size: .85rem !important;
    pointer-events: none !important;
    z-index: 2 !important;
}
.coop-select-field {
    padding-left: 42px !important;
    border: 1.5px solid color-mix(in srgb, var(--primary-color, #1a5f2a) 30%, #e5e7eb) !important;
    border-radius: 10px !important;
    font-size: .93rem !important;
    color: var(--text-primary, #1a2e1f) !important;
    background: #fff !important;
    cursor: pointer !important;
    box-shadow: 0 1px 4px rgba(0,0,0,.05) !important;
    transition: border-color .18s, box-shadow .18s;
}
.coop-select-field:focus {
    border-color: var(--primary-color, #1a5f2a) !important;
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary-color, #1a5f2a) 15%, transparent) !important;
    outline: none !important;
}
</style>

<!-- Current Committees -->
<?php if (!$showPast && !empty($currentCommittees)): ?>
<section class="committees-section section-padding">
    <div class="container">
<div class="section-header section-header-unified text-center mb-4" data-aos="fade-up">
<div class="section-badge-wrap">
<span class="section-badge"><i class="fas fa-users"></i> <?php echo $currentBadgeTitle; ?></span>
</div>
<h2><?php echo $currentHeroTitle; ?></h2>
<div class="section-divider"></div>
<p><?php echo $currentHeroDesc; ?></p>
</div>

        <?php foreach ($currentCommittees as $committee): ?>
        <div class="committee-block mb-5" data-aos="fade-up">
            <div class="committee-header">
                <h3>
                    <i class="fas fa-users-cog"></i>
                    <?php echo isEnglish() ? $committee['type']['name'] : $committee['type']['name_np']; ?>
                </h3>
                <span class="tenure-badge">
                    <i class="fas fa-calendar-alt"></i>
                    <?php echo isEnglish() ? 'Tenure: ' : 'कार्यकाल: '; ?>
                    <?php echo $committee['tenure']['tenure_name']; ?>
                </span>
            </div>

            <?php if (!empty($committee['members'])): ?>
            <div class="row justify-content-center">
                <?php foreach ($committee['members'] as $index => $member): ?>
                <div class="col-lg-3 col-md-4 col-sm-6 mb-4" data-aos="fade-up" data-aos-delay="<?php echo ($index % 4) * 50; ?>">
                    <div class="team-card-circular <?php echo $index === 0 ? 'featured' : ''; ?>">
                        <div class="team-photo-circular">
                            <?php if ($member['photo']): ?>
                                <img src="<?php echo $member['photo']; ?>" loading="lazy"  alt="<?php echo $member['name']; ?>">
                            <?php else: ?>
                                <div class="team-placeholder-circular">
                                    <i class="fas fa-user"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="team-info-circular">
                            <h5><?php echo $member['name']; ?></h5>
                            <?php if ($member['name_en']): ?>
                            <p class="team-name-en"><?php echo $member['name_en']; ?></p>
                            <?php endif; ?>
                            <span class="team-position-badge">
                                <?php echo isEnglish() ? ($member['position_en'] ?: $member['position']) : $member['position']; ?>
                            </span>
                            <?php if ($member['phone'] || $member['email']): ?>
                            <div class="team-contact-circular">
                                <?php if ($member['phone']): ?>
                                <a href="tel:<?php echo $member['phone']; ?>" title="<?php echo $member['phone']; ?>">
                                    <i class="fas fa-phone"></i>
                                </a>
                                <?php endif; ?>
                                <?php if ($member['email']): ?>
                                <a href="mailto:<?php echo $member['email']; ?>" title="<?php echo $member['email']; ?>">
                                    <i class="fas fa-envelope"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-committee text-center py-4">
                <i class="fas fa-user-friends fa-3x text-muted mb-3"></i>
                <p class="text-muted"><?php echo isEnglish() ? 'No members available' : 'सदस्यहरू उपलब्ध छैनन्'; ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- Past Committees -->
<?php if ($showPast && !empty($pastCommittees)): ?>
<section class="committees-section past-committees section-padding">
    <div class="container">
        <div class="section-header section-header-unified text-center" data-aos="fade-up">
            <h2><?php echo isEnglish() ? 'Past Committees' : 'विगतका समितिहरू'; ?></h2>
            <p><?php echo isEnglish() ? 'Our former committee members who served the cooperative' : 'हाम्रो सहकारीलाई सेवा गरेका पूर्व समिति सदस्यहरू'; ?></p>
        </div>

        <?php foreach ($pastCommittees as $committee): ?>
        <div class="committee-block past mb-5" data-aos="fade-up">
            <div class="committee-header">
                <h3>
                    <i class="fas fa-history"></i>
                    <?php echo isEnglish() ? $committee['type']['name'] : $committee['type']['name_np']; ?>
                </h3>
                <span class="tenure-badge past">
                    <i class="fas fa-calendar-alt"></i>
                    <?php echo isEnglish() ? 'Tenure: ' : 'कार्यकाल: '; ?>
                    <?php echo $committee['tenure']['tenure_name']; ?>
                    (<?php echo date('Y', strtotime($committee['tenure']['start_date'])); ?> - <?php echo date('Y', strtotime($committee['tenure']['end_date'])); ?>)
                </span>
            </div>

            <?php if (!empty($committee['members'])): ?>
            <div class="row justify-content-center">
                <?php foreach ($committee['members'] as $index => $member): ?>
                <div class="col-lg-2 col-md-3 col-sm-4 col-6 mb-3" data-aos="fade-up" data-aos-delay="<?php echo ($index % 6) * 50; ?>">
                    <div class="team-card-circular small">
                        <div class="team-photo-circular small">
                            <?php if ($member['photo']): ?>
                                <img src="<?php echo $member['photo']; ?>" loading="lazy"  alt="<?php echo $member['name']; ?>">
                            <?php else: ?>
                                <div class="team-placeholder-circular">
                                    <i class="fas fa-user"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="team-info-circular">
                            <h6><?php echo $member['name']; ?></h6>
                            <span class="team-position-badge small">
                                <?php echo isEnglish() ? ($member['position_en'] ?: $member['position']) : $member['position']; ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- Empty State -->
<?php if (empty($currentCommittees) && empty($pastCommittees)): ?>
<section class="section-padding">
    <div class="container">
        <div class="empty-state text-center py-5">
            <i class="fas fa-users-cog fa-4x text-muted mb-3"></i>
            <h4><?php echo isEnglish() ? 'No Committee Information Available' : 'समिति जानकारी उपलब्ध छैन'; ?></h4>
            <p class="text-muted"><?php echo isEnglish() ? 'Committee information will be available soon.' : 'समिति जानकारी चाँडै उपलब्ध हुनेछ।'; ?></p>
        </div>
    </div>
</section>
<?php endif; ?>


<?php require_once 'includes/footer.php'; ?>
