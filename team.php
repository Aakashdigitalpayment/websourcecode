<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/includes/team-staff-groups.php';
require_once __DIR__ . '/includes/team-menu-categories.php';
$pageTitle = isEnglish() ? 'Contact Officers' : 'मानवीय श्रोत';
require_once 'includes/header.php';

$selectedCommitteeId = isset($_GET['cmt']) ? (int)$_GET['cmt'] : 0;
$selectedTenureId = isset($_GET['tenure']) ? (int)$_GET['tenure'] : 0;
$selectedMenuSlug = preg_replace('/[^a-z0-9\-_]/', '', strtolower((string)($_GET['menu'] ?? '')));
$selectedItemKey = preg_replace('/[^a-z0-9\-_]/', '', strtolower((string)($_GET['item'] ?? $_GET['cat'] ?? '')));
if ($selectedCommitteeId > 0 && ($selectedItemKey === '' || $selectedItemKey === 'committees')) {
    $selectedItemKey = 'cmt-' . $selectedCommitteeId;
}

$staffGroups = [];
$staffMembersBySlug = [];
$boardMembers = [];
$committeeTypes = [];
$committeeMembers = [];
$committeeTenures = [];
$committeeActiveTenure = [];
$informationOfficer = $grievanceOfficer = $chairman = $ceo = null;

// Get team members
try {
    $db = getDB();
    try {
        ensureTeamMenuCategoriesTable($db);
        ensureTeamStaffGroupsTable($db);
    } catch (Throwable $e) { /* best-effort */ }
    $boardMembers = $db->query("SELECT * FROM team_members WHERE category = 'board' AND is_active = 1 ORDER BY display_order")->fetchAll();

    try {
        $staffGroups = fetchTeamStaffGroups($db, true);
        $memberStmt = $db->prepare("SELECT * FROM team_members WHERE category = ? AND is_active = 1 ORDER BY display_order");
        foreach ($staffGroups as $sg) {
            $slug = (string)($sg['slug'] ?? '');
            if ($slug === '') continue;
            $memberStmt->execute([$slug]);
            $staffMembersBySlug[$slug] = $memberStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        $staffGroups = [];
        $staffMembersBySlug = [];
    }

    try {
        $committeeTypes = $db->query("SELECT id, name, name_np, menu_category_id FROM committee_types WHERE is_active = 1 ORDER BY display_order, id")->fetchAll();
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
    $boardMembers = [];
    $staffGroups = [];
    $staffMembersBySlug = [];
    $informationOfficer = $grievanceOfficer = $chairman = $ceo = null;
    $committeeTypes = [];
    $committeeMembers = [];
    $committeeTenures = [];
    $committeeActiveTenure = [];
}

$hasAnyStaffMembers = false;
foreach ($staffMembersBySlug as $_sm) {
    if (!empty($_sm)) { $hasAnyStaffMembers = true; break; }
}

/* ── Menu श्रेणी tree (same parents as nav) → items inside each ── */
$menuCategoriesPublic = [];
try {
    $menuCategoriesPublic = array_values(array_filter(
        fetchTeamMenuCategories(getDB(), false),
        static fn($c) => !empty($c['is_active'])
    ));
} catch (Throwable $e) {
    $menuCategoriesPublic = [];
}

$fallbackCommitteesMenuId = 0;
foreach ($menuCategoriesPublic as $_mc) {
    if (($_mc['source_type'] ?? '') === 'committees') {
        $fallbackCommitteesMenuId = (int)$_mc['id'];
        break;
    }
}

$menuTree = [];
foreach ($menuCategoriesPublic as $_mc) {
    $mid = (int)$_mc['id'];
    $mslug = (string)($_mc['slug'] ?? '');
    if ($mslug === '') $mslug = 'menu-' . $mid;
    $mlabel = isEnglish()
        ? (($_mc['name_en'] ?: $_mc['name_np']) ?: $mslug)
        : (($_mc['name_np'] ?: $_mc['name_en']) ?: $mslug);
    $source = (string)($_mc['source_type'] ?? 'staff');
    $items = [];

    if ($source === 'staff') {
        if (!empty($_mc['include_contact_officers']) && ($chairman || $ceo || $informationOfficer || $grievanceOfficer)) {
            $items[] = [
                'key' => 'contact-officers',
                'label' => isEnglish() ? 'Contact Officers' : 'सम्पर्क अधिकारी',
                'type' => 'contact',
                'filter' => 'contact-officers',
            ];
        }
        foreach ($staffGroups as $_sg) {
            if ((int)($_sg['menu_category_id'] ?? 0) !== $mid) continue;
            $slug = (string)($_sg['slug'] ?? '');
            if ($slug === '' || empty($staffMembersBySlug[$slug])) continue;
            $anchor = teamStaffGroupAnchor($slug);
            $items[] = [
                'key' => $anchor,
                'label' => isEnglish()
                    ? (($_sg['name_en'] ?: $_sg['name_np']) ?: $slug)
                    : (($_sg['name_np'] ?: $_sg['name_en']) ?: $slug),
                'type' => 'staff',
                'filter' => $anchor,
                'slug' => $slug,
            ];
        }
    } else {
        if (!empty($_mc['include_board']) && !empty($boardMembers)) {
            $items[] = [
                'key' => 'board',
                'label' => isEnglish() ? 'Board Committee' : 'सञ्चालक समिति',
                'type' => 'board',
                'filter' => 'board',
            ];
        }
        foreach ($committeeTypes as $_ct) {
            $ctId = (int)$_ct['id'];
            $ctMenu = (int)($_ct['menu_category_id'] ?? 0);
            $belongs = ($ctMenu === $mid) || ($ctMenu === 0 && $mid === $fallbackCommitteesMenuId);
            if (!$belongs) continue;
            if (function_exists('isBoardCommitteeTypeAlias') && isBoardCommitteeTypeAlias($_ct)) {
                continue;
            }
            if (empty($committeeMembers[$ctId]) && empty($committeeTenures[$ctId])) continue;
            $items[] = [
                'key' => 'cmt-' . $ctId,
                'label' => isEnglish() ? ($_ct['name'] ?: $_ct['name_np']) : ($_ct['name_np'] ?: $_ct['name']),
                'type' => 'committee',
                'filter' => 'committees',
                'cmt' => $ctId,
            ];
        }
    }

    if (empty($items)) continue;
    $menuTree[$mslug] = [
        'id' => $mid,
        'slug' => $mslug,
        'label' => $mlabel,
        'source' => $source,
        'items' => $items,
    ];
}

/* Resolve selection: menu → item */
$viewMenuSlug = $selectedMenuSlug;
if ($viewMenuSlug === '' || !isset($menuTree[$viewMenuSlug])) {
    if ($selectedItemKey !== '') {
        foreach ($menuTree as $_slug => $_node) {
            foreach ($_node['items'] as $_it) {
                if ($_it['key'] === $selectedItemKey
                    || ($selectedCommitteeId > 0 && ($_it['cmt'] ?? 0) === $selectedCommitteeId)
                    || ($selectedItemKey === 'committees' && ($_it['type'] ?? '') === 'committee')
                ) {
                    $viewMenuSlug = $_slug;
                    if ($selectedItemKey === 'committees' && $selectedCommitteeId > 0) {
                        $selectedItemKey = 'cmt-' . $selectedCommitteeId;
                    } elseif ($selectedItemKey === 'committees') {
                        $selectedItemKey = (string)$_it['key'];
                    }
                    break 2;
                }
            }
        }
    }
}
if (($viewMenuSlug === '' || !isset($menuTree[$viewMenuSlug])) && $selectedItemKey === '' && $selectedCommitteeId <= 0) {
    $viewMenuSlug = 'all';
} elseif ($viewMenuSlug === '' || !isset($menuTree[$viewMenuSlug])) {
    $viewMenuSlug = array_key_first($menuTree) ?: 'all';
}

$viewItemKey = $selectedItemKey;
$viewItem = null;
if ($viewMenuSlug !== 'all' && isset($menuTree[$viewMenuSlug])) {
    foreach ($menuTree[$viewMenuSlug]['items'] as $_it) {
        if ($_it['key'] === $viewItemKey) { $viewItem = $_it; break; }
    }
    if (!$viewItem && $selectedCommitteeId > 0) {
        foreach ($menuTree[$viewMenuSlug]['items'] as $_it) {
            if ((int)($_it['cmt'] ?? 0) === $selectedCommitteeId) {
                $viewItem = $_it;
                $viewItemKey = $_it['key'];
                break;
            }
        }
    }
    if (!$viewItem && !empty($menuTree[$viewMenuSlug]['items'])) {
        $viewItem = $menuTree[$viewMenuSlug]['items'][0];
        $viewItemKey = $viewItem['key'];
    }
}

$viewCat = 'all';
$viewCommitteeId = 0;
if ($viewItem) {
    $viewCat = (string)($viewItem['filter'] ?? 'all');
    if (($viewItem['type'] ?? '') === 'committee') {
        $viewCommitteeId = (int)($viewItem['cmt'] ?? 0);
        $viewCat = 'committees';
    }
} elseif ($viewMenuSlug === 'all') {
    $viewCat = 'all';
}

$allowedFiltersForMenu = [];
if ($viewMenuSlug !== 'all' && isset($menuTree[$viewMenuSlug])) {
    foreach ($menuTree[$viewMenuSlug]['items'] as $_it) {
        $allowedFiltersForMenu[] = (string)$_it['filter'];
        if (($_it['type'] ?? '') === 'committee') {
            $allowedFiltersForMenu[] = 'committees';
        }
    }
    $allowedFiltersForMenu = array_values(array_unique($allowedFiltersForMenu));
}

$viewCommittee = null;
foreach ($committeeTypes as $_ct) {
    if ((int)$_ct['id'] === $viewCommitteeId) {
        $viewCommittee = $_ct;
        break;
    }
}
$viewTenures = ($viewCat === 'committees') ? ($committeeTenures[$viewCommitteeId] ?? []) : [];
$viewTenureId = ($viewCat === 'committees') ? (int)($committeeActiveTenure[$viewCommitteeId] ?? 0) : 0;
if ($viewCat === 'committees' && $selectedTenureId > 0) {
    $viewTenureId = $selectedTenureId;
}
if ($viewCat === 'committees' && $viewTenureId <= 0 && !empty($viewTenures)) {
    foreach ($viewTenures as $tn) {
        if (!empty($tn['is_current'])) { $viewTenureId = (int)$tn['id']; break; }
    }
    if ($viewTenureId <= 0) $viewTenureId = (int)($viewTenures[0]['id'] ?? 0);
}
/* Reload members if tenure overridden via GET */
if ($viewCat === 'committees' && $viewCommitteeId > 0 && $viewTenureId > 0
    && $viewTenureId !== (int)($committeeActiveTenure[$viewCommitteeId] ?? 0)) {
    try {
        $mStmt = getDB()->prepare("SELECT id, name, name_en, position, position_en, phone, email, photo, display_order
            FROM committee_members WHERE tenure_id = ? AND is_active = 1 ORDER BY display_order, id");
        $mStmt->execute([$viewTenureId]);
        $viewMembers = $mStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($viewMembers as &$mm) { $mm['position_np'] = $mm['position'] ?? ''; }
        unset($mm);
    } catch (Throwable $e) {
        $viewMembers = $committeeMembers[$viewCommitteeId] ?? [];
    }
} else {
    $viewMembers = ($viewCat === 'committees') ? ($committeeMembers[$viewCommitteeId] ?? []) : [];
}
$viewCommitteeLabel = '';
if ($viewCommittee) {
    $viewCommitteeLabel = isEnglish()
        ? (($viewCommittee['name'] ?: $viewCommittee['name_np']) ?: '')
        : (($viewCommittee['name_np'] ?: $viewCommittee['name']) ?: '');
}

$hasCommitteeFilters = $viewCat === 'committees' && $viewCommitteeId > 0;
$focusFilter = ($viewCat === 'all') ? 'all' : $viewCat;
$menuItemsForSelect = ($viewMenuSlug !== 'all' && isset($menuTree[$viewMenuSlug]))
    ? $menuTree[$viewMenuSlug]['items']
    : [];
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo isEnglish() ? 'Human Resources' : 'मानवीय श्रोत'; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo isEnglish() ? 'Human Resources' : 'मानवीय श्रोत'; ?></li>
            </ol>
        </nav>
    </div>
</section>

<?php if (!empty($menuTree)): ?>
<section class="team-filter-bar section-padding bg-white border-bottom">
    <div class="container">
        <div class="team-triple-filter">
            <div class="team-triple-title">
                <i class="fas fa-filter"></i>
                <span><?php echo isEnglish() ? 'Filter people' : 'मानवीय स्रोत फिल्टर'; ?></span>
            </div>
            <div class="row g-3 align-items-end justify-content-center">
                <div class="col-md-4 col-lg-3">
                    <label class="form-label small fw-semibold text-success mb-1" for="teamMenuSelect">
                        <i class="fas fa-layer-group me-1" aria-hidden="true"></i>
                        1. <?php echo isEnglish() ? 'Category' : 'श्रेणी'; ?>
                    </label>
                    <select id="teamMenuSelect" class="form-select team-filter-select">
                        <option value="all" <?php echo $viewMenuSlug === 'all' ? 'selected' : ''; ?>><?php echo isEnglish() ? 'All categories' : 'सबै श्रेणी'; ?></option>
                        <?php foreach ($menuTree as $_slug => $_node): ?>
                        <option value="<?php echo htmlspecialchars($_slug); ?>" <?php echo $viewMenuSlug === $_slug ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($_node['label']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 col-lg-3" id="teamItemWrap" style="<?php echo ($viewMenuSlug !== 'all' && !empty($menuItemsForSelect)) ? '' : 'display:none'; ?>">
                    <label class="form-label small fw-semibold text-success mb-1" for="teamItemSelect">
                        <i class="fas fa-sitemap me-1" aria-hidden="true"></i>
                        2. <?php echo isEnglish() ? 'Item' : 'विवरण'; ?>
                    </label>
                    <select id="teamItemSelect" class="form-select team-filter-select">
                        <?php foreach ($menuItemsForSelect as $_it): ?>
                        <option value="<?php echo htmlspecialchars($_it['key']); ?>"
                                data-type="<?php echo htmlspecialchars($_it['type']); ?>"
                                data-filter="<?php echo htmlspecialchars($_it['filter']); ?>"
                                data-cmt="<?php echo (int)($_it['cmt'] ?? 0); ?>"
                                <?php echo $viewItemKey === $_it['key'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($_it['label']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 col-lg-3" id="teamTenureWrap" style="<?php echo ($viewCat === 'committees' && !empty($viewTenures)) ? '' : 'display:none'; ?>">
                    <label class="form-label small fw-semibold text-success mb-1" for="teamTenureSelect">
                        <i class="fas fa-calendar-alt me-1" aria-hidden="true"></i>
                        3. <?php echo isEnglish() ? 'Tenure' : 'कार्यकाल'; ?>
                    </label>
                    <select id="teamTenureSelect" class="form-select team-filter-select" <?php echo empty($viewTenures) ? 'disabled' : ''; ?>>
                        <?php if (empty($viewTenures)): ?>
                        <option value="0"><?php echo isEnglish() ? 'Latest / current' : 'हालको / पछिल्लो'; ?></option>
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
                                <?php if (!empty($tn['is_current'])): ?> <?php echo isEnglish() ? '(Latest)' : '(हालको)'; ?><?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            <p class="text-center text-muted small mt-3 mb-0">
                <?php echo isEnglish()
                    ? 'Same categories as the Human Resources menu: pick category → item (→ tenure for committees).'
                    : 'मानवीय श्रोत मेनुकै श्रेणी: श्रेणी छान्नुहोस् → त्यसभित्रको विवरण मात्र (समितिमा कार्यकाल पनि)।'; ?>
            </p>
        </div>
    </div>
</section>
<style>
.team-triple-filter{max-width:980px;margin:0 auto;padding:8px 4px 4px}
.team-triple-title{display:flex;align-items:center;justify-content:center;gap:8px;font-weight:700;color:var(--primary-color,#1a5f2a);margin-bottom:14px}
.team-triple-title i{opacity:.85}
.team-filter-select{
  border:1.5px solid color-mix(in srgb,var(--primary-color,#1a5f2a) 30%,#e5e7eb);
  border-radius:10px;
  font-size:.93rem;
  background-color:#fff;
  min-height:44px;
  padding:.5rem 2.25rem .5rem .85rem;
}
.team-filter-select:focus{
  border-color:var(--primary-color,#1a5f2a);
  box-shadow:0 0 0 .2rem color-mix(in srgb,var(--primary-color,#1a5f2a) 18%,transparent);
}
</style>
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

<!-- Management / Staff groups (dynamic from team_staff_groups) -->
<?php
$_staffSectionIdx = 0;
foreach ($staffGroups as $_sg):
    $slug = (string)($_sg['slug'] ?? '');
    if ($slug === '') continue;
    $members = $staffMembersBySlug[$slug] ?? [];
    if (empty($members)) continue;
    $anchor = teamStaffGroupAnchor($slug);
    $title = isEnglish()
        ? (($_sg['name_en'] ?: $_sg['name_np']) ?: $slug)
        : (($_sg['name_np'] ?: $_sg['name_en']) ?: $slug);
    $useSmall = in_array($slug, ['staff', 'admin'], true) || count($members) > 8;
    $bgClass = ($_staffSectionIdx % 2 === 0) ? 'bg-light' : '';
    $_staffSectionIdx++;
?>
<section id="<?php echo htmlspecialchars($anchor); ?>" class="team-section section-padding <?php echo $bgClass; ?>" data-filter="<?php echo htmlspecialchars($anchor); ?>">
    <div class="container">
        <div class="section-header section-header-unified text-center">
            <h2><?php echo htmlspecialchars($title); ?></h2>
        </div>

        <div class="row justify-content-center">
            <?php foreach ($members as $index => $member): ?>
            <div class="<?php echo $useSmall ? 'col-lg-2 col-md-3 col-sm-4 col-6' : 'col-lg-3 col-md-4 col-sm-6'; ?> mb-4" data-aos="fade-up" data-aos-delay="<?php echo ($index % 6) * 50; ?>">
                <div class="team-card-circular <?php echo $useSmall ? 'small' : ''; ?>">
                    <div class="team-photo-circular <?php echo $useSmall ? 'small' : ''; ?>">
                        <?php if (!empty($member['photo'])): ?>
                            <img src="<?php echo e($member['photo']); ?>" loading="lazy" alt="<?php echo e($member['name']); ?>">
                        <?php else: ?>
                            <div class="team-placeholder-circular"><i class="lucide-icon" aria-hidden="true" data-lucide="user"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="team-info-circular">
                        <?php if ($useSmall): ?>
                        <h6><?php echo e($member['name']); ?></h6>
                        <?php else: ?>
                        <h5><?php echo e($member['name']); ?></h5>
                        <?php if (!empty($member['name_en'])): ?>
                        <p class="team-name-en"><?php echo e($member['name_en']); ?></p>
                        <?php endif; ?>
                        <?php endif; ?>
                        <span class="team-position-badge <?php echo $useSmall ? 'small' : ''; ?>"><?php echo e($member['position_np'] ?: $member['position']); ?></span>
                        <?php if (!$useSmall && (!empty($member['phone']) || !empty($member['email']))): ?>
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
    </div>
</section>
<?php endforeach; ?>

<!-- Committees viewer: one section + committee/tenure dropdowns -->
<?php if ($viewCat === 'committees' && $viewCommittee): ?>
<section id="committees" class="team-section section-padding bg-light" data-filter="committees"
         data-active-cmt="<?php echo (int)$viewCommitteeId; ?>">
    <?php if ($viewCommitteeId > 0): ?>
    <div id="committee-<?php echo (int)$viewCommitteeId; ?>" class="visually-hidden" aria-hidden="true"></div>
    <?php endif; ?>
    <div class="container">
        <div class="section-header section-header-unified text-center">
            <h2><?php echo htmlspecialchars($viewCommitteeLabel ?: (isEnglish() ? 'Committees' : 'समिति')); ?></h2>
            <p><?php echo isEnglish()
                ? 'Members for the selected committee and tenure.'
                : 'चयनित समिति र कार्यकालका सदस्यहरू।'; ?></p>
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
<?php endif; ?>

<?php if (empty($boardMembers) && !$hasAnyStaffMembers && empty(array_filter($committeeMembers))): ?>
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
window.__teamCmtTenures = window.__teamCmtTenures || <?php
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
window.__teamFocusFilter = <?php echo json_encode($focusFilter); ?>;
window.__teamMenuSlug = <?php echo json_encode($viewMenuSlug); ?>;
window.__teamMenuTree = <?php echo json_encode($menuTree, JSON_UNESCAPED_UNICODE); ?>;
window.__teamAllowedFilters = <?php echo json_encode($allowedFiltersForMenu, JSON_UNESCAPED_UNICODE); ?>;

function teamFilter(btn, filter, allowed) {
    var allow = Array.isArray(allowed) ? allowed : null;
    document.querySelectorAll('.team-section[data-filter]').forEach(function (section) {
        var key = section.getAttribute('data-filter');
        var match;
        if (filter === 'all' && (!allow || !allow.length)) {
            match = true;
        } else if (filter === 'all' && allow && allow.length) {
            match = allow.indexOf(key) !== -1;
        } else {
            match = key === filter;
            if (match && allow && allow.length && allow.indexOf(key) === -1) match = false;
        }
        section.style.display = match ? '' : 'none';
        section.classList.toggle('team-section--focused', match && filter !== 'all');
    });
    if (filter !== 'all') {
        var target = document.querySelector('.team-section[data-filter="' + filter + '"]');
        if (target) {
            setTimeout(function () {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 60);
        }
    }
}

function teamBuildUrl(menuSlug, itemKey, tenureId) {
    if (!menuSlug || menuSlug === 'all') return 'team.php';
    var tree = window.__teamMenuTree || {};
    var node = tree[menuSlug];
    var item = null;
    if (node && node.items) {
        for (var i = 0; i < node.items.length; i++) {
            if (node.items[i].key === itemKey) { item = node.items[i]; break; }
        }
        if (!item && node.items.length) item = node.items[0];
    }
    if (!item) return 'team.php?menu=' + encodeURIComponent(menuSlug);

    var url = 'team.php?menu=' + encodeURIComponent(menuSlug) + '&item=' + encodeURIComponent(item.key);
    var hash = item.filter || item.key;
    if (item.type === 'committee') {
        url += '&cat=committees&cmt=' + encodeURIComponent(item.cmt || 0);
        if (tenureId) url += '&tenure=' + encodeURIComponent(tenureId);
        hash = 'committees';
    } else {
        url += '&cat=' + encodeURIComponent(item.filter || item.key);
    }
    return url + '#' + encodeURIComponent(hash);
}

(function () {
    var menuSelect = document.getElementById('teamMenuSelect');
    var itemSelect = document.getElementById('teamItemSelect');
    var tenureSelect = document.getElementById('teamTenureSelect');
    var itemWrap = document.getElementById('teamItemWrap');
    var tenureWrap = document.getElementById('teamTenureWrap');
    var tenureData = window.__teamCmtTenures || {};
    var menuTree = window.__teamMenuTree || {};
    var isEn = !!window.__teamCmtIsEn;

    function latestTenureId(rows) {
        if (!rows || !rows.length) return 0;
        for (var i = 0; i < rows.length; i++) {
            if (rows[i].is_current) return rows[i].id;
        }
        return rows[0].id;
    }

    function fillItems(menuSlug, preferredKey) {
        if (!itemSelect) return null;
        itemSelect.innerHTML = '';
        var node = menuTree[menuSlug];
        if (!node || !node.items || !node.items.length) {
            if (itemWrap) itemWrap.style.display = 'none';
            return null;
        }
        if (itemWrap) itemWrap.style.display = '';
        var selected = null;
        node.items.forEach(function (it) {
            var opt = document.createElement('option');
            opt.value = it.key;
            opt.textContent = it.label;
            opt.setAttribute('data-type', it.type || '');
            opt.setAttribute('data-filter', it.filter || '');
            opt.setAttribute('data-cmt', String(it.cmt || 0));
            itemSelect.appendChild(opt);
            if (preferredKey && it.key === preferredKey) selected = it;
        });
        if (!selected) selected = node.items[0];
        itemSelect.value = selected.key;
        return selected;
    }

    function fillTenures(cmtId, preferredTenure) {
        if (!tenureSelect) return;
        var rows = tenureData[String(cmtId)] || tenureData[cmtId] || [];
        tenureSelect.innerHTML = '';
        if (!rows.length) {
            if (tenureWrap) tenureWrap.style.display = 'none';
            tenureSelect.disabled = true;
            return;
        }
        if (tenureWrap) tenureWrap.style.display = '';
        tenureSelect.disabled = false;
        var currentId = preferredTenure || latestTenureId(rows);
        rows.forEach(function (tn) {
            var opt = document.createElement('option');
            opt.value = String(tn.id);
            var label = isEn ? (tn.label_en || tn.label) : (tn.label || tn.label_en);
            if (tn.period) label += ' (' + tn.period + ')';
            if (tn.is_current) label += isEn ? ' (Latest)' : ' (हालको)';
            opt.textContent = label;
            tenureSelect.appendChild(opt);
        });
        tenureSelect.value = String(currentId);
    }

    function selectedItemMeta() {
        if (!itemSelect || !itemSelect.options.length) return null;
        var opt = itemSelect.options[itemSelect.selectedIndex];
        if (!opt) return null;
        return {
            key: opt.value,
            type: opt.getAttribute('data-type') || '',
            filter: opt.getAttribute('data-filter') || opt.value,
            cmt: parseInt(opt.getAttribute('data-cmt') || '0', 10) || 0
        };
    }

    function go() {
        var menuSlug = menuSelect ? menuSelect.value : 'all';
        if (menuSlug === 'all') {
            location.href = 'team.php';
            return;
        }
        var item = selectedItemMeta();
        var tid = 0;
        if (item && item.type === 'committee') {
            tid = tenureSelect && !tenureSelect.disabled ? (parseInt(tenureSelect.value, 10) || 0) : 0;
            if (!tid) tid = latestTenureId(tenureData[String(item.cmt)] || []);
        }
        location.href = teamBuildUrl(menuSlug, item ? item.key : '', tid);
    }

    if (menuSelect) {
        menuSelect.addEventListener('change', function () {
            var menuSlug = menuSelect.value;
            if (menuSlug === 'all') {
                if (itemWrap) itemWrap.style.display = 'none';
                if (tenureWrap) tenureWrap.style.display = 'none';
                go();
                return;
            }
            var item = fillItems(menuSlug, '');
            if (item && item.type === 'committee') fillTenures(item.cmt || 0, 0);
            else if (tenureWrap) tenureWrap.style.display = 'none';
            go();
        });
    }
    if (itemSelect) {
        itemSelect.addEventListener('change', function () {
            var item = selectedItemMeta();
            if (item && item.type === 'committee') fillTenures(item.cmt || 0, 0);
            else if (tenureWrap) tenureWrap.style.display = 'none';
            go();
        });
    }
    if (tenureSelect) {
        tenureSelect.addEventListener('change', go);
    }

    function boot() {
        var params = new URLSearchParams(location.search);
        var hash = (location.hash || '').replace(/^#/, '');
        if (/^committee-\d+$/.test(hash)) {
            var id = parseInt(hash.replace('committee-', ''), 10) || 0;
            if (id) {
                var foundMenu = '';
                Object.keys(menuTree).forEach(function (ms) {
                    (menuTree[ms].items || []).forEach(function (it) {
                        if (it.cmt === id) foundMenu = ms;
                    });
                });
                location.replace(teamBuildUrl(foundMenu || (window.__teamMenuSlug || ''), 'cmt-' + id, parseInt(params.get('tenure') || '0', 10) || 0));
                return;
            }
        }
        var focus = window.__teamFocusFilter || params.get('cat') || hash || 'all';
        if (params.get('cmt') && !params.get('cat') && !params.get('item')) focus = 'committees';
        if (hash === 'committees' || focus === 'committees') focus = 'committees';
        if (focus === 'all' && hash && hash !== 'all') focus = hash;
        var allowed = window.__teamAllowedFilters || [];
        if (window.__teamMenuSlug && window.__teamMenuSlug !== 'all' && (!allowed || !allowed.length)) {
            allowed = [];
        }
        teamFilter(null, focus === 'all' && !(allowed && allowed.length) ? 'all' : focus, allowed);
        if (focus === 'all' && allowed && allowed.length) {
            teamFilter(null, 'all', allowed);
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
    window.addEventListener('hashchange', boot);
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
