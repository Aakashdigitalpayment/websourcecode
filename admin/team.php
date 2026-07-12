<?php
if (!defined('TEAM_ADMIN_SECTION')) {
    define('TEAM_ADMIN_SECTION', 'governance');
}
$teamListSection = TEAM_ADMIN_SECTION;
if (!in_array($teamListSection, ['governance', 'karmachari'], true)) {
    $teamListSection = 'governance';
}
require_once __DIR__ . '/../includes/election-tables.php';
require_once __DIR__ . '/../includes/team-staff-groups.php';
require_once __DIR__ . '/../includes/team-menu-categories.php';
/**
 * टिम सदस्य व्यवस्थापन — Team Members Management
 * Tab UI: सूची + Add/Edit form (modal popup हटाइएको)
 * Special roles: सूचना अधिकारी / गुनासो अधिकारी
 */
/* admin-header भित्र:
   - login/auth check
   - CSRF सुरक्षा
   - ensure-admin-tables
   - global exception handler
   सबै loaded हुन्छ, त्यसैले यो file लाई stable बनाउँछ। */
$__isEn = strtolower((string)($_SESSION['admin_lang'] ?? $_SESSION['lang'] ?? 'np')) === 'en';
$__t = static function (string $np, string $en) use ($__isEn): string {
    return $__isEn ? $en : $np;
};
$pageTitle = $teamListSection === 'karmachari'
    ? $__t('कर्मचारी / व्यवस्थापन', 'Staff / Management')
    : $__t('सञ्चालक / समिति', 'Directors / Committee');
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
if (empty($csrfToken)) $csrfToken = generateCSRFToken();

$success = '';
$error   = '';

/* ── v9.8 Auto-migration: convert team_members.category ENUM→VARCHAR(50) ──
   Without this, saving a "cmt_<id>" committee slug silently fails on strict MySQL
   (ENUM allows only board/management/staff). One-time, idempotent. */
try {
    $_db_chk = getDB();
    $_col = $_db_chk->query("SHOW COLUMNS FROM team_members LIKE 'category'")->fetch(PDO::FETCH_ASSOC);
    if ($_col && stripos($_col['Type'] ?? '', 'enum') !== false) {
        $_db_chk->exec("ALTER TABLE team_members MODIFY COLUMN category VARCHAR(50) NOT NULL DEFAULT 'staff'");
    }
    /* Ensure is_chairman, is_ceo, is_information_officer, is_grievance_officer columns exist */
    $_cols = $_db_chk->query("SHOW COLUMNS FROM team_members")->fetchAll(PDO::FETCH_ASSOC);
    $_existing = array_column($_cols, 'Field');
    if (!in_array('is_chairman', $_existing, true)) {
        $_db_chk->exec("ALTER TABLE team_members ADD COLUMN is_chairman TINYINT(1) DEFAULT 0");
    }
    if (!in_array('is_ceo', $_existing, true)) {
        $_db_chk->exec("ALTER TABLE team_members ADD COLUMN is_ceo TINYINT(1) DEFAULT 0");
    }
    if (!in_array('is_information_officer', $_existing, true)) {
        $_db_chk->exec("ALTER TABLE team_members ADD COLUMN is_information_officer TINYINT(1) DEFAULT 0");
    }
    if (!in_array('is_grievance_officer', $_existing, true)) {
        $_db_chk->exec("ALTER TABLE team_members ADD COLUMN is_grievance_officer TINYINT(1) DEFAULT 0");
    }
    /* Ensure committee_types table exists (silently) */
    $_db_chk->exec("CREATE TABLE IF NOT EXISTS committee_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        name_np VARCHAR(100) DEFAULT NULL,
        slug VARCHAR(80) DEFAULT NULL,
        description TEXT,
        display_order INT DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        show_in_navbar TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    ensureTeamStaffGroupsTable($_db_chk);
    ensureTeamMenuCategoriesTable($_db_chk);
} catch (\Throwable $e) { /* best-effort */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $db = getDB();
    try {
        switch ($_POST['action'] ?? '') {
            case 'add':
            case 'edit':
                $id        = isset($_POST['id']) ? (int)$_POST['id'] : null;
                $name      = clean_text($_POST['name']        ?? '');
                $name_en   = clean_text($_POST['name_en']     ?? '');
                $pos       = clean_text($_POST['position']    ?? '');
                $pos_np    = clean_text($_POST['position_np'] ?? '');
                $pos_en    = clean_text($_POST['position_en'] ?? '');
                $phone     = preg_replace('/[^0-9]/', '', clean_text($_POST['phone']       ?? '', 20));
                $email     = strtolower(clean_text($_POST['email']       ?? '', 254));
                $cat       = clean_text($_POST['category']    ?? 'staff');
                $order     = (int)($_POST['display_order']  ?? 0);
                $isInfo    = isset($_POST['is_information_officer']) ? 1 : 0;
                $isGriev   = isset($_POST['is_grievance_officer'])   ? 1 : 0;
                $isChairman = isset($_POST['is_chairman']) ? 1 : 0;
                $isCeo      = isset($_POST['is_ceo'])      ? 1 : 0;
                $isActive  = isset($_POST['is_active']) ? 1 : 0;

                /* Board is always category=board — never a duplicate cmt_* alias */
                if ($teamListSection === 'governance' && preg_match('/^cmt_(\d+)$/', $cat, $mCat)) {
                    try {
                        $stAlias = $db->prepare('SELECT id, name, name_np FROM committee_types WHERE id=? LIMIT 1');
                        $stAlias->execute([(int)$mCat[1]]);
                        $aliasRow = $stAlias->fetch(PDO::FETCH_ASSOC);
                        if ($aliasRow && function_exists('isBoardCommitteeTypeAlias') && isBoardCommitteeTypeAlias($aliasRow)) {
                            $cat = 'board';
                        }
                    } catch (Throwable $e) { /* ignore */ }
                }

                if ($isInfo) {
                    if ($id) {
                        $db->prepare('UPDATE team_members SET is_information_officer = 0 WHERE id != ?')->execute([$id]);
                    } else {
                        $db->exec('UPDATE team_members SET is_information_officer = 0');
                    }
                }
                if ($isGriev) {
                    if ($id) {
                        $db->prepare('UPDATE team_members SET is_grievance_officer = 0 WHERE id != ?')->execute([$id]);
                    } else {
                        $db->exec('UPDATE team_members SET is_grievance_officer = 0');
                    }
                }
                /* Chairman ra CEO — ek matra person hunu parne (exclusive) —
                   try-catch: column DB मा नभए silently skip, error nadekhaune */
                if ($isChairman) {
                    try {
                        if ($id) { $db->prepare('UPDATE team_members SET is_chairman = 0 WHERE id != ?')->execute([$id]); }
                        else      { $db->exec('UPDATE team_members SET is_chairman = 0'); }
                    } catch (Throwable $e2) {}
                }
                if ($isCeo) {
                    try {
                        if ($id) { $db->prepare('UPDATE team_members SET is_ceo = 0 WHERE id != ?')->execute([$id]); }
                        else      { $db->exec('UPDATE team_members SET is_ceo = 0'); }
                    } catch (Throwable $e2) {}
                }

                $photo = '';
                if (isset($_FILES['photo']) && $_FILES['photo']['error'] === 0) {
                    $pf = uploadImage($_FILES['photo'], UPLOAD_PATH . 'team/', 400, 400, true);
                    if ($pf) $photo = 'assets/uploads/team/' . $pf;
                }

                if ($_POST['action'] === 'add') {
                    $db->prepare("INSERT INTO team_members (name, name_en, position, position_np, position_en, phone, email, photo, category, display_order, is_information_officer, is_grievance_officer, is_chairman, is_ceo, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                       ->execute([$name, $name_en, $pos, $pos_np, $pos_en, $phone, $email, $photo, $cat, $order, $isInfo, $isGriev, $isChairman, $isCeo, $isActive]);
                    $success = $__t('टिम सदस्य सफलतापूर्वक थपियो।', 'Team member added successfully.');
                } else {
                    if ($photo) {
                        $db->prepare("UPDATE team_members SET name=?, name_en=?, position=?, position_np=?, position_en=?, phone=?, email=?, photo=?, category=?, display_order=?, is_information_officer=?, is_grievance_officer=?, is_chairman=?, is_ceo=?, is_active=? WHERE id=?")
                           ->execute([$name, $name_en, $pos, $pos_np, $pos_en, $phone, $email, $photo, $cat, $order, $isInfo, $isGriev, $isChairman, $isCeo, $isActive, $id]);
                    } else {
                        $db->prepare("UPDATE team_members SET name=?, name_en=?, position=?, position_np=?, position_en=?, phone=?, email=?, category=?, display_order=?, is_information_officer=?, is_grievance_officer=?, is_chairman=?, is_ceo=?, is_active=? WHERE id=?")
                           ->execute([$name, $name_en, $pos, $pos_np, $pos_en, $phone, $email, $cat, $order, $isInfo, $isGriev, $isChairman, $isCeo, $isActive, $id]);
                    }
                    $success = $__t('टिम सदस्य सफलतापूर्वक अपडेट भयो।', 'Team member updated successfully.');
                }
                break;

            case 'delete':
                $db->prepare("DELETE FROM team_members WHERE id=?")->execute([(int)$_POST['id']]);
                $success = $__t('टिम सदस्य हटाइयो।', 'Team member deleted.');
                break;

            case 'toggle':
                $db->prepare('UPDATE team_members SET is_active = NOT is_active WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
                $success = $__t('स्थिति परिवर्तन भयो।', 'Status changed.');
                break;

            /* समूह CRUD — governance: committee_types | karmachari: team_staff_groups */
            case 'group_add':
            case 'group_edit':
                $gid = (int)($_POST['group_id'] ?? 0);
                $gNameNp = clean_text($_POST['group_name_np'] ?? '');
                $gNameEn = clean_text($_POST['group_name_en'] ?? '');
                $gName = $gNameEn !== '' ? $gNameEn : $gNameNp;
                $gOrder = (int)($_POST['group_order'] ?? 0);
                $gActive = isset($_POST['group_is_active']) ? 1 : 0;
                $gNav = isset($_POST['group_show_in_navbar']) ? 1 : 0;
                if ($gNameNp === '' && $gNameEn === '') {
                    throw new InvalidArgumentException('Group name required');
                }
                if ($teamListSection === 'governance') {
                    ensureTeamMenuCategoriesTable($db);
                    try {
                        $db->exec("ALTER TABLE committee_types ADD COLUMN icon VARCHAR(80) DEFAULT 'fas fa-users-gear'");
                    } catch (Throwable $e) { /* already exists */ }
                    $gMenuCat = (int)($_POST['group_menu_category_id'] ?? 0) ?: null;
                    $gIcon = clean_text($_POST['group_icon'] ?? 'fas fa-users-gear', 80) ?: 'fas fa-users-gear';
                    if (function_exists('isBoardCommitteeTypeAlias') && isBoardCommitteeTypeAlias([
                        'name' => $gName,
                        'name_np' => $gNameNp,
                    ])) {
                        throw new InvalidArgumentException($__t(
                            'सञ्चालक समिति पहिले नै “board” को रूपमा छ — दोहोरो समूह नबनाउनुहोस्।',
                            'Board Committee already exists as the fixed “board” category — do not create a duplicate group.'
                        ));
                    }
                    if (($_POST['action'] ?? '') === 'group_add') {
                        $db->prepare("INSERT INTO committee_types (name, name_np, display_order, is_active, show_in_navbar, menu_category_id, icon) VALUES (?,?,?,?,?,?,?)")
                           ->execute([$gName, $gNameNp, $gOrder, $gActive, $gNav, $gMenuCat, $gIcon]);
                        $success = $__t('समिति समूह थपियो। अब सदस्य फारमको वर्गमा map गर्न सकिन्छ।', 'Committee group added. You can map members to it in the form.');
                    } else {
                        $db->prepare("UPDATE committee_types SET name=?, name_np=?, display_order=?, is_active=?, show_in_navbar=?, menu_category_id=?, icon=? WHERE id=?")
                           ->execute([$gName, $gNameNp, $gOrder, $gActive, $gNav, $gMenuCat, $gIcon, $gid]);
                        $success = $__t('समिति समूह अपडेट भयो।', 'Committee group updated.');
                    }
                } elseif ($teamListSection === 'karmachari') {
                    ensureTeamStaffGroupsTable($db);
                    ensureTeamMenuCategoriesTable($db);
                    $gMenuCat = (int)($_POST['group_menu_category_id'] ?? 0) ?: null;
                    if (($_POST['action'] ?? '') === 'group_add') {
                        $baseSlug = teamStaffGroupSlugify($gNameEn !== '' ? $gNameEn : $gNameNp, 'group');
                        $slug = $baseSlug;
                        $n = 2;
                        while (true) {
                            $chk = $db->prepare('SELECT id FROM team_staff_groups WHERE slug=? LIMIT 1');
                            $chk->execute([$slug]);
                            if (!$chk->fetch()) break;
                            $slug = substr($baseSlug, 0, 45) . '_' . $n;
                            $n++;
                        }
                        $db->prepare('INSERT INTO team_staff_groups (slug, name_np, name_en, display_order, is_active, show_in_nav, menu_category_id) VALUES (?,?,?,?,?,?,?)')
                           ->execute([$slug, $gNameNp, $gNameEn !== '' ? $gNameEn : $gName, $gOrder, $gActive, $gNav, $gMenuCat]);
                        $success = $__t('वर्ग/समूह थपियो। अब सदस्य फारममा map गर्न सकिन्छ।', 'Category/group added. You can map members in the form.');
                    } else {
                        $db->prepare('UPDATE team_staff_groups SET name_np=?, name_en=?, display_order=?, is_active=?, show_in_nav=?, menu_category_id=? WHERE id=?')
                           ->execute([$gNameNp, $gNameEn !== '' ? $gNameEn : $gName, $gOrder, $gActive, $gNav, $gMenuCat, $gid]);
                        $success = $__t('वर्ग/समूह अपडेट भयो।', 'Category/group updated.');
                    }
                } else {
                    throw new RuntimeException('Invalid section for groups');
                }
                break;

            /* मानवीय श्रोत मेनुका parent श्रेणी (व्यवस्थापन, समिति/उपसमिति, …) */
            case 'menu_cat_add':
            case 'menu_cat_edit':
                ensureTeamMenuCategoriesTable($db);
                $mid = (int)($_POST['menu_cat_id'] ?? 0);
                $mNameNp = clean_text($_POST['menu_name_np'] ?? '');
                $mNameEn = clean_text($_POST['menu_name_en'] ?? '');
                $mIcon = clean_text($_POST['menu_icon'] ?? 'fas fa-folder', 80) ?: 'fas fa-folder';
                $mSource = in_array($_POST['menu_source_type'] ?? '', ['staff', 'committees'], true)
                    ? $_POST['menu_source_type'] : 'staff';
                $mOrder = (int)($_POST['menu_order'] ?? 0);
                $mActive = isset($_POST['menu_is_active']) ? 1 : 0;
                $mNav = isset($_POST['menu_show_in_nav']) ? 1 : 0;
                $mContact = isset($_POST['menu_include_contact']) ? 1 : 0;
                $mBoard = isset($_POST['menu_include_board']) ? 1 : 0;
                if ($mNameNp === '' && $mNameEn === '') {
                    throw new InvalidArgumentException('Menu category name required');
                }
                if (($_POST['action'] ?? '') === 'menu_cat_add') {
                    $baseSlug = teamMenuCategorySlugify($mNameEn !== '' ? $mNameEn : $mNameNp, 'menu');
                    $slug = $baseSlug;
                    $n = 2;
                    while (true) {
                        $chk = $db->prepare('SELECT id FROM team_menu_categories WHERE slug=? LIMIT 1');
                        $chk->execute([$slug]);
                        if (!$chk->fetch()) break;
                        $slug = substr($baseSlug, 0, 45) . '_' . $n;
                        $n++;
                    }
                    $db->prepare('INSERT INTO team_menu_categories (slug, name_np, name_en, icon, source_type, include_contact_officers, include_board, display_order, is_active, show_in_nav) VALUES (?,?,?,?,?,?,?,?,?,?)')
                       ->execute([$slug, $mNameNp, $mNameEn !== '' ? $mNameEn : $mNameNp, $mIcon, $mSource, $mContact, $mBoard, $mOrder, $mActive, $mNav]);
                    $success = $__t('मेनु श्रेणी थपियो। अब सार्वजनिक मानवीय श्रोत मेनुमा देखिन्छ।', 'Menu category added. It will appear under Human Resources.');
                } else {
                    $db->prepare('UPDATE team_menu_categories SET name_np=?, name_en=?, icon=?, source_type=?, include_contact_officers=?, include_board=?, display_order=?, is_active=?, show_in_nav=? WHERE id=?')
                       ->execute([$mNameNp, $mNameEn !== '' ? $mNameEn : $mNameNp, $mIcon, $mSource, $mContact, $mBoard, $mOrder, $mActive, $mNav, $mid]);
                    $success = $__t('मेनु श्रेणी अपडेट भयो।', 'Menu category updated.');
                }
                break;

            case 'menu_cat_delete':
                ensureTeamMenuCategoriesTable($db);
                $mid = (int)($_POST['menu_cat_id'] ?? 0);
                if ($mid > 0) {
                    try {
                        $db->prepare('UPDATE team_staff_groups SET menu_category_id = NULL WHERE menu_category_id=?')->execute([$mid]);
                    } catch (Throwable $e) {}
                    try {
                        $db->prepare('UPDATE committee_types SET menu_category_id = NULL WHERE menu_category_id=?')->execute([$mid]);
                    } catch (Throwable $e) {}
                    $db->prepare('DELETE FROM team_menu_categories WHERE id=?')->execute([$mid]);
                    $success = $__t('मेनु श्रेणी हटाइयो।', 'Menu category deleted.');
                }
                break;

            case 'group_delete':
                $gid = (int)($_POST['group_id'] ?? 0);
                if ($gid <= 0) break;
                if ($teamListSection === 'governance') {
                    $slug = 'cmt_' . $gid;
                    $db->prepare("UPDATE team_members SET category='board' WHERE category=?")->execute([$slug]);
                    $db->prepare("DELETE FROM committee_types WHERE id=?")->execute([$gid]);
                    $success = $__t('समिति समूह हटाइयो। त्यसमा map भएका सदस्य सञ्चालक समितिमा सारियो।', 'Group deleted. Mapped members moved to Board.');
                } elseif ($teamListSection === 'karmachari') {
                    ensureTeamStaffGroupsTable($db);
                    $st = $db->prepare('SELECT slug FROM team_staff_groups WHERE id=? LIMIT 1');
                    $st->execute([$gid]);
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $oldSlug = (string)$row['slug'];
                        $fallback = 'staff';
                        $fb = $db->prepare('SELECT slug FROM team_staff_groups WHERE id != ? AND is_active = 1 ORDER BY display_order, id LIMIT 1');
                        $fb->execute([$gid]);
                        $fbRow = $fb->fetch(PDO::FETCH_ASSOC);
                        if ($fbRow && !empty($fbRow['slug'])) {
                            $fallback = (string)$fbRow['slug'];
                        }
                        $db->prepare('UPDATE team_members SET category=? WHERE category=?')->execute([$fallback, $oldSlug]);
                        $db->prepare('DELETE FROM team_staff_groups WHERE id=?')->execute([$gid]);
                        $success = $__t('वर्ग/समूह हटाइयो। map भएका सदस्य अर्को सक्रिय वर्गमा सारियो।', 'Group deleted. Mapped members moved to another active category.');
                    }
                } else {
                    throw new RuntimeException('Invalid section for groups');
                }
                break;
        }
    } catch (Exception $e) {
        $error = $__t('त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।', 'An error occurred. Please try again later.');
    }
}

$db = getDB();
$teamAdminSelf = ($teamListSection === 'karmachari') ? 'team-karmachari.php' : 'team.php';

$editGroup = null;
$editMenuCat = null;
$allStaffGroups = [];
$allMenuCategories = [];
try {
    ensureTeamStaffGroupsTable($db);
    ensureTeamMenuCategoriesTable($db);
    $allStaffGroups = fetchTeamStaffGroups($db, false);
    $allMenuCategories = fetchAllTeamMenuCategories($db);
} catch (Throwable $e) {
    $allStaffGroups = [];
    $allMenuCategories = [];
}

$staffMenuCategories = array_values(array_filter($allMenuCategories, static function ($c) {
    return ($c['source_type'] ?? '') === 'staff';
}));
$committeeMenuCategories = array_values(array_filter($allMenuCategories, static function ($c) {
    return ($c['source_type'] ?? '') === 'committees';
}));
$menuCatById = [];
foreach ($allMenuCategories as $_mc) {
    $menuCatById[(int)$_mc['id']] = $_mc;
}

if (isset($_GET['edit_group'])) {
    try {
        if ($teamListSection === 'governance') {
            $st = $db->prepare("SELECT * FROM committee_types WHERE id=? LIMIT 1");
            $st->execute([(int)$_GET['edit_group']]);
            $editGroup = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } elseif ($teamListSection === 'karmachari') {
            $st = $db->prepare('SELECT * FROM team_staff_groups WHERE id=? LIMIT 1');
            $st->execute([(int)$_GET['edit_group']]);
            $editGroup = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($editGroup) {
                $editGroup['name'] = $editGroup['name_en'] ?? '';
                $editGroup['show_in_navbar'] = $editGroup['show_in_nav'] ?? 0;
            }
        }
    } catch (Throwable $e) {
        $editGroup = null;
    }
}

if (isset($_GET['edit_menu'])) {
    try {
        $st = $db->prepare('SELECT * FROM team_menu_categories WHERE id=? LIMIT 1');
        $st->execute([(int)$_GET['edit_menu']]);
        $editMenuCat = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $editMenuCat = null;
    }
}

$activeTeamTab = $_GET['tab'] ?? 'list';
if (!in_array($activeTeamTab, ['list', 'form', 'groups', 'menu'], true)) {
    $activeTeamTab = 'list';
}
if ($editGroup) {
    $activeTeamTab = 'groups';
}
if ($editMenuCat) {
    $activeTeamTab = 'menu';
}

$cats = [
    'board' => $__t('सञ्चालक समिति', 'Board Committee'),
    'leadership' => $__t('नेतृत्व', 'Leadership'),
];
$catColors = ['board' => 'var(--primary-color)', 'leadership' => 'var(--warning)'];
$palette = ['var(--warning)', 'var(--secondary-color)', 'var(--text-secondary)', 'var(--info, #0369a1)', 'var(--primary-light)'];
foreach ($allStaffGroups as $i => $sg) {
    $slug = (string)($sg['slug'] ?? '');
    if ($slug === '') continue;
    $cats[$slug] = isEnglish()
        ? (($sg['name_en'] ?: $sg['name_np']) ?: $slug)
        : (($sg['name_np'] ?: $sg['name_en']) ?: $slug);
    $catColors[$slug] = $palette[$i % count($palette)];
}

$extraTypes = [];
$allCommitteeGroups = [];
$boardAliasTypeIds = [];
try {
    $extraTypes = $db->query("SELECT id, name_np, name FROM committee_types WHERE is_active = 1 ORDER BY display_order, id")->fetchAll();
    foreach ($extraTypes as $ct) {
        if (function_exists('isBoardCommitteeTypeAlias') && isBoardCommitteeTypeAlias($ct)) {
            $boardAliasTypeIds[] = (int)$ct['id'];
            continue; /* सञ्चालक समिति = fixed category "board" — do not add cmt_* duplicate */
        }
        $slug = 'cmt_' . (int)$ct['id'];
        if (!isset($cats[$slug])) {
            $cats[$slug] = $ct['name_np'] ?: $ct['name'];
            $catColors[$slug] = 'var(--primary-light)';
        }
    }
    $allCommitteeGroups = $db->query("SELECT * FROM committee_types ORDER BY display_order, id")->fetchAll();
    /* Heal: members wrongly saved under board-alias cmt_* → board */
    foreach ($boardAliasTypeIds as $aliasId) {
        try {
            $db->prepare("UPDATE team_members SET category='board' WHERE category=?")->execute(['cmt_' . $aliasId]);
        } catch (Throwable $e) { /* ignore */ }
    }
} catch (\Throwable $e) { /* committee_types छैन */ }

/* सूची */
if ($teamListSection === 'governance') {
    $govCategoryList = ['board'];
    foreach ($extraTypes as $ct) {
        if (function_exists('isBoardCommitteeTypeAlias') && isBoardCommitteeTypeAlias($ct)) {
            continue;
        }
        $govCategoryList[] = 'cmt_' . (int)$ct['id'];
    }
    /* Keep orphan members previously saved under board-alias cmt_* visible in the list */
    foreach ($boardAliasTypeIds as $aliasId) {
        $govCategoryList[] = 'cmt_' . $aliasId;
    }
    $govCategoryList = array_values(array_unique($govCategoryList));
    $ph = implode(',', array_fill(0, count($govCategoryList), '?'));
    $stTeam = $db->prepare("SELECT * FROM team_members WHERE category IN ($ph) ORDER BY category, display_order, id DESC");
    $stTeam->execute($govCategoryList);
    $team = $stTeam->fetchAll();
} else {
    $staffSlugs = [];
    foreach ($allStaffGroups as $sg) {
        if (!empty($sg['slug'])) $staffSlugs[] = (string)$sg['slug'];
    }
    if (empty($staffSlugs)) {
        $staffSlugs = ['top_management', 'management', 'staff', 'admin'];
    }
    $ph = implode(',', array_fill(0, count($staffSlugs), '?'));
    $stTeam = $db->prepare("SELECT * FROM team_members WHERE category IN ($ph) ORDER BY category, display_order, id DESC");
    $stTeam->execute($staffSlugs);
    $team = $stTeam->fetchAll();
}

$tmPart = adminPartitionRowsByIsActive($team);
$teamLive = $tmPart['live'];
$teamArch = $tmPart['archived'];

/* फारम dropdown */
$catsForm = [];
$catOptgroups = [];
if ($teamListSection === 'governance') {
    $catsForm['board'] = $cats['board'];
    foreach ($extraTypes as $ct) {
        if (function_exists('isBoardCommitteeTypeAlias') && isBoardCommitteeTypeAlias($ct)) {
            continue;
        }
        $slug = 'cmt_' . (int)$ct['id'];
        if (isset($cats[$slug])) $catsForm[$slug] = $cats[$slug];
    }
    $catOptgroups[isEnglish() ? 'Governance' : 'शासन'] = ['board' => $catsForm['board'] ?? $cats['board']];
    $committeeOptions = [];
    foreach ($catsForm as $slug => $label) {
        if (strpos($slug, 'cmt_') === 0) $committeeOptions[$slug] = $label;
    }
    if (!empty($committeeOptions)) {
        $catOptgroups[isEnglish() ? 'Committees / Subcommittees' : 'समिति / उपसमिति'] = $committeeOptions;
    }
} else {
    foreach ($allStaffGroups as $sg) {
        if (empty($sg['is_active'])) continue;
        $slug = (string)($sg['slug'] ?? '');
        if ($slug === '' || !isset($cats[$slug])) continue;
        $catsForm[$slug] = $cats[$slug];
        $grpLabel = $cats[$slug];
        $catOptgroups[$grpLabel] = [$slug => $cats[$slug]];
    }
    if (empty($catsForm)) {
        $catsForm = [
            'top_management' => $__t('शीर्ष व्यवस्थापन टोली', 'Top Management Team'),
            'management' => $__t('व्यवस्थापन', 'Management'),
            'staff' => $__t('कर्मचारी', 'Staff'),
            'admin' => $__t('एडमिन', 'Admin'),
        ];
        foreach ($catsForm as $slug => $label) {
            $catOptgroups[$label] = [$slug => $label];
        }
    }
}

?>

<?php
$teamHeaderTitle = $teamListSection === 'karmachari'
    ? $__t('कर्मचारी / व्यवस्थापन', 'Staff / Management')
    : $__t('सञ्चालक र समिति', 'Directors and Committees');
$teamHeaderIcon = $teamListSection === 'karmachari' ? 'fa-user-tie' : 'fa-building-columns';
$teamHeaderSub = $teamListSection === 'karmachari'
    ? $__t('व्यवस्थापन र कर्मचारी मात्र यहाँ सूचीबद्ध। सञ्चालक समिति वा अन्य समिति: मेनु «सञ्चालक / समिति»। RTI/गुनासो अधिकारी स्विच यहीँ वा «तोकाइ» पृष्ठ।', 'Only management and staff are listed here. For board/other committees use "Directors / Committee". RTI/Grievance officers can be assigned here or from "Assignment" pages.')
    : $__t('सञ्चालक समिति (board) र समिति/उपसमिति (समिति प्रकार) मात्र। कर्मचारी/व्यवस्थापन: मेनु «कर्मचारी / व्यवस्थापन»। RTI/गुनासो अधिकारी यहीँका स्विच वा «तोकाइ» पृष्ठ।', 'Only board committee and committee/subcommittee members are listed here. For staff/management use "Staff / Management". RTI/Grievance officers can be set here or from assignment pages.');
$teamHeaderActions = '<span class="badge admin-stat-badge tm-stat-badge tm-stat-badge--total me-2"><i class="fas fa-layer-group me-1"></i>' . $__t('जम्मा', 'Total') . ': ' . count($team) . '</span>'
    . '<span class="badge admin-stat-badge tm-stat-badge tm-stat-badge--active me-2"><i class="fas fa-check-circle me-1"></i>' . $__t('सक्रिय', 'Active') . ': ' . count($teamLive) . '</span>'
    . '<span class="badge admin-stat-badge tm-stat-badge tm-stat-badge--arch me-2"><i class="fas fa-archive me-1"></i>' . $__t('अभिलेख', 'Archived') . ': ' . count($teamArch) . '</span>';
if ($teamListSection === 'karmachari') {
    $teamHeaderActions .= '<a href="team.php" class="btn btn-sm btn-outline-secondary ms-1 mb-1"><i class="fas fa-building-columns me-1"></i>' . $__t('सञ्चालक / समिति', 'Directors / Committee') . '</a>';
} else {
    $teamHeaderActions .= '<a href="team-karmachari.php" class="btn btn-sm btn-outline-secondary ms-1 mb-1"><i class="fas fa-user-tie me-1"></i>' . $__t('कर्मचारी / व्यवस्थापन', 'Staff / Management') . '</a>';
}
$teamHeaderActions .= '<a href="info-officer.php" class="btn btn-sm btn-outline-primary ms-1 mb-1"><i class="fas fa-user-shield me-1"></i>' . $__t('RTI तोकाइ', 'RTI Assignment') . '</a>'
    . '<a href="grievance-officer.php" class="btn btn-sm btn-outline-secondary ms-1 mb-1"><i class="fas fa-user-tie me-1"></i>' . $__t('गुनासो तोकाइ', 'Grievance Assignment') . '</a>';
echo adminPageHeader($teamHeaderTitle, $teamHeaderIcon, $teamHeaderSub, $teamHeaderActions);
?>

<div class="alert alert-light border small mb-3 py-2">
  <i class="fas fa-sitemap me-1 text-success"></i>
  <?php if ($teamListSection === 'karmachari'): ?>
    <strong><?php echo $__t('कहाँ के?', 'Where is what?'); ?></strong>
    <?php echo $__t('यहाँ = website मा देखिने व्यवस्थापन/कर्मचारी। पद =', 'Here = public staff/management. Posts ='); ?>
    <a href="designations.php"><?php echo $__t('पद मास्टर', 'Post Master'); ?></a> ·
    <?php echo $__t('HR रेकर्ड =', 'HR records ='); ?>
    <a href="hrm-employees.php"><?php echo $__t('HRM कर्मचारी', 'HRM Employees'); ?></a> ·
    <?php echo $__t('शाखा =', 'Branches ='); ?>
    <a href="service-centers.php"><?php echo $__t('शाखाहरू', 'Branches'); ?></a>
  <?php else: ?>
    <strong><?php echo $__t('कहाँ के?', 'Where is what?'); ?></strong>
    <?php echo $__t('यहाँ = सञ्चालक/समिति सदस्य। कार्यकाल इतिहास =', 'Here = board/committee members. Tenure history ='); ?>
    <a href="committees.php"><?php echo $__t('समिति व्यवस्थापन', 'Committee Management'); ?></a> ·
    <?php echo $__t('कर्मचारी/व्यवस्थापन =', 'Staff/Management ='); ?>
    <a href="team-karmachari.php"><?php echo $__t('कर्मचारी पृष्ठ', 'Staff page'); ?></a>
  <?php endif; ?>
</div>

<?php echo adminAlert('success', $success) . adminAlert('danger', $error); ?>

<ul class="nav nav-tabs admin-nav-tabs mb-0" data-team-section="<?php echo htmlspecialchars($teamListSection, ENT_QUOTES, 'UTF-8'); ?>">
    <li class="nav-item">
        <button class="nav-link <?php echo $activeTeamTab === 'list' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#team-list" id="team-list-btn" title="<?php echo $__t('सक्रिय / जम्मा', 'Active / Total'); ?>">
            <i class="fas fa-list me-2"></i><?php echo $__t('सदस्य सूची', 'Member List'); ?>
            <span class="badge tm-tab-count ms-1"><?php echo count($teamLive); ?> / <?php echo count($team); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link <?php echo $activeTeamTab === 'form' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#team-form" id="team-form-btn">
            <i class="fas fa-plus-circle me-2"></i><span id="teamFormTabLabel"><?php echo $__t('नयाँ थप्नुहोस्', 'Add New'); ?></span>
        </button>
    </li>
    <?php if ($teamListSection === 'governance' || $teamListSection === 'karmachari'): ?>
    <li class="nav-item">
        <button class="nav-link <?php echo $activeTeamTab === 'groups' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#team-groups" id="team-groups-btn">
            <i class="fas fa-layer-group me-2"></i><?php echo $teamListSection === 'karmachari'
                ? $__t('वर्ग / समूह', 'Category / Groups')
                : $__t('समिति समूह', 'Committee Groups'); ?>
            <span class="badge tm-tab-count ms-1"><?php echo $teamListSection === 'karmachari' ? count($allStaffGroups) : count($allCommitteeGroups); ?></span>
        </button>
    </li>
    <?php endif; ?>
    <li class="nav-item">
        <button class="nav-link <?php echo $activeTeamTab === 'menu' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#team-menu" id="team-menu-btn">
            <i class="fas fa-sitemap me-2"></i><?php echo $__t('मेनु श्रेणी', 'Menu Categories'); ?>
            <span class="badge tm-tab-count ms-1"><?php echo count($allMenuCategories); ?></span>
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- ══ TAB 1: सूची ══ -->
    <div class="tab-pane fade <?php echo $activeTeamTab === 'list' ? 'show active' : ''; ?>" id="team-list">
        <div class="card admin-table-card svc-flat-top-card">

            <!-- खोज बक्स — client-side filter -->
            <div class="admin-search-wrap tm-search-wrap px-3 py-2 border-bottom d-flex align-items-center gap-3 svc-search-wrap">
                <div class="input-group input-group-sm svc-search-group">
                    <span class="input-group-text tm-input-addon border-end-0"><i class="fas fa-search tm-search-ico"></i></span>
                    <input type="text" class="form-control border-start-0 admin-table-search" placeholder="<?php echo $__t('नाम, विवरण अनुसार खोज्नुहोस्...', 'Search by name or details...'); ?>" autocomplete="off">
                </div>
                <small class="search-count"></small>
            </div>
            <div class="card-body p-0">
                    <?php echo adminListSubtabPills('team-sub', count($teamLive), count($teamArch), $__t('सक्रिय', 'Active'), $__t('अभिलेख', 'Archived')); ?>
                    <div class="tab-content admin-table-subtab-content">
                    <div class="tab-pane fade show active" id="team-sub-live" role="tabpanel">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3" width="70"><?php echo $__t('फोटो', 'Photo'); ?></th>
                                <th><?php echo $__t('नाम', 'Name'); ?></th>
                                <th><?php echo $__t('पद', 'Position'); ?></th>
                                <th width="110"><?php echo $__t('सम्पर्क', 'Contact'); ?></th>
                                <th width="110" class="text-center"><?php echo $__t('वर्ग', 'Category'); ?></th>
                                <th width="120" class="text-center"><?php echo $__t('विशेष भूमिका', 'Special Role'); ?></th>
                                <th width="90" class="text-center"><?php echo $__t('स्थिति', 'Status'); ?></th>
                                <th width="140" class="text-center"><?php echo $__t('कार्य', 'Actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($team)): ?>
                            <tr><td colspan="8" class="text-center py-5 tm-meta-muted">
                                <i class="fas fa-users fa-3x mb-2 d-block opacity-25 tm-empty-state-ico"></i>
                                <?php echo $__t('कुनै सदस्य छैन।', 'No members found.'); ?>
                            </td></tr>
                            <?php elseif (empty($teamLive)): ?>
                            <tr><td colspan="8" class="text-center py-5 tm-meta-muted">
                                <i class="fas fa-check-circle fa-3x mb-2 d-block opacity-25 tm-empty-state-ico"></i>
                                <?php echo $__t('सक्रिय सदस्य छैन। अभिलेख हेर्नुहोस्।', 'No active members. Check archive tab.'); ?>
                            </td></tr>
                            <?php endif; ?>
                            <?php foreach ($teamLive as $m): ?>
                            <tr>
                                <td class="ps-3">
                                    <?php if (!empty($m['photo'])): ?>
                                    <img src="<?php echo SITE_URL . htmlspecialchars($m['photo']); ?>" class="tm-avatar-photo">
                                    <?php else: ?>
                                    <div class="tm-avatar-fallback"><i class="fas fa-user tm-ico-accent"></i></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($m['name']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($m['name_en'] ?? ''); ?></small>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($m['position_np'] ?: $m['position']); ?></div>
                                    <small class="tm-meta-muted"><?php echo htmlspecialchars($m['position_en'] ?? ''); ?></small>
                                </td>
                                <td>
                                    <?php if (!empty($m['phone'])): ?><small><i class="fas fa-phone me-1 tm-ico-accent"></i><?php echo htmlspecialchars($m['phone']); ?></small><br><?php endif; ?>
                                    <?php if (!empty($m['email'])): ?><small><i class="fas fa-envelope me-1 tm-ico-accent"></i><?php echo htmlspecialchars($m['email']); ?></small><?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge tm-cat-badge"
                                          data-badge-color="<?php echo htmlspecialchars($catColors[$m['category']] ?? 'var(--text-secondary)', ENT_QUOTES); ?>">
                                        <?php echo $cats[$m['category']] ?? $m['category']; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($m['is_chairman'])): ?><span class="badge tm-role-badge tm-role-badge--chairman mb-1 d-block"><?php echo $__t('अध्यक्ष', 'Chairman'); ?></span><?php endif; ?>
                                    <?php if (!empty($m['is_ceo'])): ?><span class="badge tm-role-badge tm-role-badge--ceo mb-1 d-block"><?php echo $__t('CEO', 'CEO'); ?></span><?php endif; ?>
                                    <?php if ($m['is_information_officer']): ?><span class="badge tm-role-badge tm-role-badge--info mb-1 d-block"><?php echo $__t('सूचना अधिकारी', 'Info Officer'); ?></span><?php endif; ?>
                                    <?php if ($m['is_grievance_officer']): ?><span class="badge tm-role-badge tm-role-badge--griev mb-1 d-block"><?php echo $__t('गुनासो अधिकारी', 'Grievance'); ?></span><?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <form method="POST" class="svc-inline-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?php echo $m['id']; ?>">
                                        <button class="badge border-0 tm-status-toggle-btn <?php echo $m['is_active'] ? 'tm-status--on' : 'tm-status--off'; ?>">
                                            <?php echo $m['is_active'] ? $__t('सक्रिय', 'Active') : $__t('निष्क्रिय', 'Inactive'); ?>
                                        </button>
                                    </form>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm tm-btn-edit me-1 btn-edit-member"
                                            data-member='<?php echo htmlspecialchars(json_encode($m, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>'
                                            title="<?php echo $__t('सम्पादन', 'Edit'); ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="svc-inline-form" onsubmit="return confirm('<?php echo addslashes($__t('के तपाईं यो सदस्य मेटाउन निश्चित हुनुहुन्छ?', 'Are you sure you want to delete this member?')); ?>')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $m['id']; ?>">
                                        <button class="btn btn-sm tm-btn-del" title="<?php echo $__t('मेटाउनुहोस्', 'Delete'); ?>"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    </div>
                    <div class="tab-pane fade" id="team-sub-arch" role="tabpanel">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3" width="70"><?php echo $__t('फोटो', 'Photo'); ?></th>
                                <th><?php echo $__t('नाम', 'Name'); ?></th>
                                <th><?php echo $__t('पद', 'Position'); ?></th>
                                <th width="110"><?php echo $__t('सम्पर्क', 'Contact'); ?></th>
                                <th width="110" class="text-center"><?php echo $__t('वर्ग', 'Category'); ?></th>
                                <th width="120" class="text-center"><?php echo $__t('विशेष भूमिका', 'Special Role'); ?></th>
                                <th width="90" class="text-center"><?php echo $__t('स्थिति', 'Status'); ?></th>
                                <th width="140" class="text-center"><?php echo $__t('कार्य', 'Actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($teamArch)): ?>
                            <tr><td colspan="8" class="text-center py-5 tm-meta-muted">
                                <i class="fas fa-folder-open fa-3x mb-2 d-block opacity-25 tm-empty-state-ico"></i>
                                <?php echo $__t('अभिलेखमा कुनै सदस्य छैन।', 'No archived members.'); ?>
                            </td></tr>
                            <?php endif; ?>
                            <?php foreach ($teamArch as $m): ?>
                            <tr>
                                <td class="ps-3">
                                    <?php if (!empty($m['photo'])): ?>
                                    <img src="<?php echo SITE_URL . htmlspecialchars($m['photo']); ?>" class="tm-avatar-photo">
                                    <?php else: ?>
                                    <div class="tm-avatar-fallback"><i class="fas fa-user tm-ico-accent"></i></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($m['name']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($m['name_en'] ?? ''); ?></small>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($m['position_np'] ?: $m['position']); ?></div>
                                    <small class="tm-meta-muted"><?php echo htmlspecialchars($m['position_en'] ?? ''); ?></small>
                                </td>
                                <td>
                                    <?php if (!empty($m['phone'])): ?><small><i class="fas fa-phone me-1 tm-ico-accent"></i><?php echo htmlspecialchars($m['phone']); ?></small><br><?php endif; ?>
                                    <?php if (!empty($m['email'])): ?><small><i class="fas fa-envelope me-1 tm-ico-accent"></i><?php echo htmlspecialchars($m['email']); ?></small><?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge tm-cat-badge"
                                          data-badge-color="<?php echo htmlspecialchars($catColors[$m['category']] ?? 'var(--text-secondary)', ENT_QUOTES); ?>">
                                        <?php echo $cats[$m['category']] ?? $m['category']; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($m['is_chairman'])): ?><span class="badge tm-role-badge tm-role-badge--chairman mb-1 d-block"><?php echo $__t('अध्यक्ष', 'Chairman'); ?></span><?php endif; ?>
                                    <?php if (!empty($m['is_ceo'])): ?><span class="badge tm-role-badge tm-role-badge--ceo mb-1 d-block"><?php echo $__t('CEO', 'CEO'); ?></span><?php endif; ?>
                                    <?php if ($m['is_information_officer']): ?><span class="badge tm-role-badge tm-role-badge--info mb-1 d-block"><?php echo $__t('सूचना अधिकारी', 'Info Officer'); ?></span><?php endif; ?>
                                    <?php if ($m['is_grievance_officer']): ?><span class="badge tm-role-badge tm-role-badge--griev mb-1 d-block"><?php echo $__t('गुनासो अधिकारी', 'Grievance'); ?></span><?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <form method="POST" class="svc-inline-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?php echo $m['id']; ?>">
                                        <button class="badge border-0 tm-status-toggle-btn <?php echo $m['is_active'] ? 'tm-status--on' : 'tm-status--off'; ?>">
                                            <?php echo $m['is_active'] ? $__t('सक्रिय', 'Active') : $__t('निष्क्रिय', 'Inactive'); ?>
                                        </button>
                                    </form>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm tm-btn-edit me-1 btn-edit-member"
                                            data-member='<?php echo htmlspecialchars(json_encode($m, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>'
                                            title="<?php echo $__t('सम्पादन', 'Edit'); ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="svc-inline-form" onsubmit="return confirm('<?php echo addslashes($__t('के तपाईं यो सदस्य मेटाउन निश्चित हुनुहुन्छ?', 'Are you sure you want to delete this member?')); ?>')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $m['id']; ?>">
                                        <button class="btn btn-sm tm-btn-del" title="<?php echo $__t('मेटाउनुहोस्', 'Delete'); ?>"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    </div>
                    </div>
            </div>
        </div>
    </div>

    <!-- ══ TAB 2: Add / Edit Form ══ -->
    <div class="tab-pane fade <?php echo $activeTeamTab === 'form' ? 'show active' : ''; ?>" id="team-form">
        <div class="card svc-flat-top-card">
            <div class="card-header d-flex justify-content-between align-items-center svc-form-header-grad">
                <h5 class="mb-0 fw-bold" id="teamFormTitle">
                    <i class="fas fa-plus-circle me-2"></i><?php echo $__t('नयाँ सदस्य थप्नुहोस्', 'Add New Member'); ?>
                </h5>
                <button type="button" class="btn btn-light btn-sm" id="btnCancelTeam">
                    <i class="fas fa-arrow-left me-1"></i><?php echo $__t('सूचीमा फर्कनुहोस्', 'Back to list'); ?>
                </button>
            </div>
            <div class="card-body p-4">
                <form method="POST" enctype="multipart/form-data" id="teamForm" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" id="tmf_action" value="add">
                    <input type="hidden" name="id" id="tmf_id" value="">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold tm-form-label"><?php echo $__t('नाम (नेपाली)', 'Name (Nepali)'); ?> <span class="tm-req-star">*</span></label>
                            <input type="text" name="name" id="tmf_name" class="form-control admin-fancy-input" required placeholder="<?php echo $__t('पूरा नाम नेपालीमा', 'Full name in Nepali'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold tm-form-label"><?php echo $__t('नाम (अंग्रेजी)', 'Name (English)'); ?></label>
                            <input type="text" name="name_en" id="tmf_name_en" class="form-control admin-fancy-input" placeholder="Full name in English">
                        </div>
                        <?php
                        ensureDesignationsTable(getDB());
                        /* पद मास्टर: कर्मचारी पृष्ठ = staff मात्र; सञ्चालक/समिति = committee मात्र */
                        $__desigCats = ($teamListSection === 'karmachari') ? ['staff'] : ['committee'];
                        $__teamDesigs = fetchDesignations(getDB(), $__desigCats);
                        ?>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold tm-form-label"><?php echo $__t('पद (मास्टरबाट)', 'Designation (from master)'); ?></label>
                            <select name="__pos_pick" id="tmf_pos_pick" class="form-select admin-fancy-input" onchange="(function(sel){var o=sel.options[sel.selectedIndex];document.getElementById('tmf_pos_np').value=o.dataset.np||'';document.getElementById('tmf_pos_en').value=o.dataset.en||'';document.getElementById('tmf_pos').value=o.dataset.np||'';})(this)">
                                <option value=""><?php echo $__t('— पद छान्नुहोस् —', '- Select designation -'); ?></option>
                                <?php foreach ($__teamDesigs as $__d): ?>
                                    <option value="<?php echo (int)$__d['id']; ?>" data-np="<?php echo htmlspecialchars($__d['title_np']); ?>" data-en="<?php echo htmlspecialchars($__d['title_en']); ?>">
                                        <?php echo htmlspecialchars($__d['title_np']); ?> <?php if ($__d['title_en']): ?>— <?php echo htmlspecialchars($__d['title_en']); ?><?php endif; ?> <small>[<?php echo htmlspecialchars($__d['category']); ?>]</small>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="small tm-meta-muted mt-1">
                                <?php if ($teamListSection === 'karmachari'): ?>
                                    <?php echo $__t('यहाँ ', 'Here only '); ?><strong><?php echo $__t('कर्मचारी', 'staff'); ?></strong><?php echo $__t(' श्रेणीका पद मात्र देखिन्छन्। नयाँ पद ', ' category designations are shown. Add new designation from '); ?><a href="designations.php" target="_blank"><?php echo $__t('पद मास्टर', 'Designation Master'); ?></a><?php echo $__t(' मा ', ' with '); ?><em><?php echo $__t('श्रेणी: कर्मचारी', 'category: staff'); ?></em><?php echo $__t(' राखेर थप्नुहोस्।', '.'); ?>
                                <?php else: ?>
                                    <?php echo $__t('यहाँ ', 'Here only '); ?><strong><?php echo $__t('समिति', 'committee'); ?></strong><?php echo $__t(' श्रेणीका पद मात्र देखिन्छन्। नयाँ पद ', ' category designations are shown. Add new designation from '); ?><a href="designations.php" target="_blank"><?php echo $__t('पद मास्टर', 'Designation Master'); ?></a><?php echo $__t(' मा ', ' with '); ?><em><?php echo $__t('श्रेणी: समिति', 'category: committee'); ?></em><?php echo $__t(' राखेर थप्नुहोस्।', '.'); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <input type="hidden" name="position_np" id="tmf_pos_np">
                        <input type="hidden" name="position_en" id="tmf_pos_en">
                        <input type="hidden" name="position" id="tmf_pos">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold tm-form-label"><?php echo $__t('फोन', 'Phone'); ?></label>
                            <input type="text" name="phone" id="tmf_phone" class="form-control admin-fancy-input" placeholder="98XXXXXXXX">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold tm-form-label"><?php echo $__t('इमेल', 'Email'); ?></label>
                            <input type="email" name="email" id="tmf_email" class="form-control admin-fancy-input" placeholder="email@example.com">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold tm-form-label"><?php echo $__t('वर्ग / समूह', 'Category / Group'); ?></label>
                            <div class="form-text tm-meta-muted mb-2">
                                <?php if ($teamListSection === 'karmachari'): ?>
                                    <?php echo $__t('वर्गहरू:', 'Groups:'); ?>
                                    <a href="#team-groups" onclick="document.getElementById('team-groups-btn')?.click(); return false;"><?php echo $__t('वर्ग / समूह ट्याब', 'Category / Groups tab'); ?></a>
                                    <?php echo $__t(' बाट थप्न/सम्पादन गर्न सकिन्छ (पद मास्टर जस्तै)।', ' — add/edit like Post Master.'); ?>
                                <?php else: ?>
                                    <?php echo $__t('सञ्चालक समिति (board) वा अन्य समिति समूह। नयाँ समूह:', 'Board committee or other committee groups. New groups:'); ?>
                                    <a href="#team-groups" onclick="document.getElementById('team-groups-btn')?.click(); return false;"><?php echo $__t('समिति समूह ट्याब', 'Committee Groups tab'); ?></a>
                                    <?php if (!empty($boardAliasTypeIds)): ?>
                                    <span class="d-block text-warning mt-1">
                                        <?php echo $__t(
                                            'नोट: “सञ्चालक समिति” नामको समिति प्रकार दोहोरिने भएकाले फारममा board मात्र राखिएको छ। पुराना सदस्य board मा map गर्नुहोस्।',
                                            'Note: A committee type named like Board of Directors was hidden from this list to avoid duplicates — map members to board.'
                                        ); ?>
                                    </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <select name="category" id="tmf_cat" class="form-select admin-fancy-input">
                                <?php
                                $_defCat = $teamListSection === 'karmachari'
                                    ? (array_key_first($catsForm) ?: 'management')
                                    : 'board';
                                foreach ($catOptgroups as $_groupLabel => $_options): ?>
                                    <optgroup label="<?php echo htmlspecialchars($_groupLabel); ?>">
                                        <?php foreach ($_options as $_slug => $_lbl): ?>
                                            <option value="<?php echo htmlspecialchars($_slug); ?>" <?php echo $_slug === $_defCat ? 'selected' : ''; ?>><?php echo htmlspecialchars($_lbl); ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold tm-form-label"><?php echo $__t('क्रम', 'Order'); ?></label>
                            <input type="number" name="display_order" id="tmf_order" class="form-control admin-fancy-input" value="0" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold tm-form-label"><?php echo $__t('फोटो', 'Photo'); ?>
                                <small class="tm-meta-muted fw-normal" id="tmf_photo_note"></small>
                            </label>
                            <input type="file" name="photo" class="form-control admin-fancy-input" accept="image/*">
                            <div id="tmf_photo_prev" class="mt-2"></div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check form-switch fs-5 mb-2">
                                <input class="form-check-input" type="checkbox" name="is_information_officer" id="tmf_is_info" value="1">
                                <label class="form-check-label fw-semibold tm-form-label" for="tmf_is_info"><?php echo $__t('सूचना अधिकारी (RTI)', 'Information Officer (RTI)'); ?></label>
                            </div>
                            <div class="form-check form-switch fs-5 mb-2">
                                <input class="form-check-input" type="checkbox" name="is_grievance_officer" id="tmf_is_griev" value="1">
                                <label class="form-check-label fw-semibold tm-form-label" for="tmf_is_griev"><?php echo $__t('गुनासो अधिकारी', 'Grievance Officer'); ?></label>
                            </div>
                            <div class="form-check form-switch fs-5 mb-2">
                                <input class="form-check-input" type="checkbox" name="is_chairman" id="tmf_is_chairman" value="1">
                                <label class="form-check-label fw-semibold tm-form-label" for="tmf_is_chairman"><?php echo $__t('अध्यक्ष', 'Chairman'); ?></label>
                            </div>
                            <div class="form-check form-switch fs-5">
                                <input class="form-check-input" type="checkbox" name="is_ceo" id="tmf_is_ceo" value="1">
                                <label class="form-check-label fw-semibold tm-form-label" for="tmf_is_ceo"><?php echo $__t('प्रमुख कार्यकारी (CEO)', 'Chief Executive (CEO)'); ?></label>
                            </div>
                            <p class="small tm-meta-muted mb-0 mt-2 tm-note-xs">
                                <i class="fas fa-link me-1 opacity-75"></i><?php echo $__t('छुट्टै पृष्ठबाट पनि तोक्न मिल्छ —', 'Can also be assigned from dedicated pages -'); ?>
                                <a href="info-officer.php" class="tm-inline-link">RTI</a>,
                                <a href="grievance-officer.php" class="tm-inline-link"><?php echo $__t('गुनासो', 'Grievance'); ?></a>.
                            </p>
                            <p class="small tm-meta-muted mt-2">
                                <strong><?php echo $__t('नेतृत्व देखाउन:', 'Leadership visibility:'); ?></strong>
                                <?php echo $__t('यहाँ चयन गरिएका अध्यक्ष, CEO, सूचना अधिकारी वा गुनासो अधिकारी सार्वजनिक नेतृत्‍वमा देखिन्छन्।', 'Selecting Chairman, CEO, Information Officer, or Grievance Officer here makes the member appear in leadership display.'); ?>
                            </p>
                        </div>
                        <div class="col-md-4 d-flex align-items-center">
                            <div class="form-check form-switch fs-5">
                                <input class="form-check-input" type="checkbox" name="is_active" id="tmf_active" value="1" checked>
                                <label class="form-check-label fw-semibold tm-form-label" for="tmf_active"><?php echo $__t('सक्रिय', 'Active'); ?></label>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <div class="d-flex gap-3">
                        <button type="submit" id="tmf_submit" class="btn tm-btn-submit px-5 fw-semibold">
                            <i class="fas fa-plus-circle me-2"></i><?php echo $__t('थप्नुहोस्', 'Add'); ?>
                        </button>
                        <button type="button" id="tmf_cancel2" class="btn tm-btn-cancel px-4">
                            <i class="fas fa-times me-1"></i><?php echo $__t('रद्द', 'Cancel'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($teamListSection === 'governance'): ?>
    <!-- ══ TAB: समिति समूह ══ -->
    <div class="tab-pane fade <?php echo $activeTeamTab === 'groups' ? 'show active' : ''; ?>" id="team-groups">
        <div class="card-body">
            <div class="alert alert-info py-2 mb-3 small">
                <i class="fas fa-info-circle me-1"></i>
                <?php echo $__t(
                    'यहाँ बनेका समूह सदस्य फारमको “वर्ग / समूह” मा देखिन्छन्। सदस्य map गर्नुस् भने सार्वजनिक टोली मेनुमा त्यो समिति अन्तर्गत देखिन्छ। कार्यकाल (वर्ष) अनुसार इतिहास राख्न',
                    'Groups created here appear in the member form Category/Group field. Map a member to show them under that committee on the public team menu. For tenure/year history use'
                ); ?>
                <a href="committees.php?tab=tenures" class="fw-semibold"><?php echo $__t('समिति व्यवस्थापन → कार्यकाल', 'Committee Management → Tenures'); ?></a>।
            </div>

            <div class="card border mb-4">
                <div class="card-header bg-light py-2">
                    <strong><?php echo $editGroup ? $__t('समूह सम्पादन', 'Edit Group') : $__t('नयाँ समिति समूह', 'Add Committee Group'); ?></strong>
                </div>
                <div class="card-body">
                    <form method="POST" action="<?php echo htmlspecialchars($teamAdminSelf); ?>?tab=groups<?php echo $editGroup ? '&edit_group='.(int)$editGroup['id'] : ''; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="<?php echo $editGroup ? 'group_edit' : 'group_add'; ?>">
                        <?php if ($editGroup): ?><input type="hidden" name="group_id" value="<?php echo (int)$editGroup['id']; ?>"><?php endif; ?>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold"><?php echo $__t('नाम (नेपाली) *', 'Name (Nepali) *'); ?></label>
                                <input type="text" name="group_name_np" class="form-control" required
                                       value="<?php echo htmlspecialchars($editGroup['name_np'] ?? ''); ?>"
                                       placeholder="<?php echo $__t('जस्तै: लेखा समिति', 'e.g. Accounts Committee'); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold"><?php echo $__t('नाम (अंग्रेजी)', 'Name (English)'); ?></label>
                                <input type="text" name="group_name_en" class="form-control"
                                       value="<?php echo htmlspecialchars($editGroup['name'] ?? ''); ?>"
                                       placeholder="e.g. Accounts Committee">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold"><?php echo $__t('क्रम', 'Order'); ?></label>
                                <input type="number" name="group_order" class="form-control" min="0"
                                       value="<?php echo (int)($editGroup['display_order'] ?? 0); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold"><?php echo $__t('मेनु श्रेणी', 'Menu category'); ?></label>
                                <select name="group_menu_category_id" class="form-select">
                                    <option value=""><?php echo $__t('— छान्नुहोस् —', '— Choose —'); ?></option>
                                    <?php foreach ($committeeMenuCategories as $_mc): ?>
                                    <option value="<?php echo (int)$_mc['id']; ?>" <?php echo (int)($editGroup['menu_category_id'] ?? 0) === (int)$_mc['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(isEnglish() ? ($_mc['name_en'] ?: $_mc['name_np']) : ($_mc['name_np'] ?: $_mc['name_en'])); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted"><?php echo $__t('मानवीय श्रोत मेनुमा कुन parent श्रेणी अन्तर्गत', 'Which parent category under Human Resources'); ?> —
                                    <a href="#team-menu" onclick="document.getElementById('team-menu-btn')?.click(); return false;"><?php echo $__t('मेनु श्रेणी', 'Menu Categories'); ?></a>
                                    <?php if (empty($committeeMenuCategories)): ?>
                                    <span class="text-warning d-block mt-1"><?php echo $__t('समिति स्रोतको श्रेणी छैन — पहिले मेनु श्रेणीमा स्रोत = समिति थप्नुहोस्।', 'No committee-source categories yet — add one with Source = Committees first.'); ?></span>
                                    <?php endif; ?>
                                </small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold"><?php echo $__t('आइकन', 'Icon'); ?></label>
                                <?php $_gIcon = trim((string)($editGroup['icon'] ?? '')) ?: 'fas fa-users-gear'; ?>
                                <div class="js-fa-icon-picker fa-ip-wrap">
                                    <div class="fa-ip-row input-group">
                                        <span class="fa-ip-preview input-group-text" data-fa-preview>
                                            <i class="<?php echo htmlspecialchars($_gIcon, ENT_QUOTES, 'UTF-8'); ?>"></i>
                                        </span>
                                        <input type="text" name="group_icon" class="form-control" data-fa-input
                                               value="<?php echo htmlspecialchars($_gIcon, ENT_QUOTES, 'UTF-8'); ?>"
                                               placeholder="fas fa-users-gear">
                                        <button type="button" class="btn btn-success fa-ip-open" data-fa-open
                                                title="<?php echo $__t('आइकन छान्नुहोस्', 'Pick icon'); ?>">
                                            <i class="fas fa-th me-1"></i><span><?php echo $__t('छान्नुहोस्', 'Pick'); ?></span>
                                        </button>
                                    </div>
                                    <small class="fa-ip-hint"><?php echo $__t('सार्वजनिक मेनुमा यो समिति item को icon।', 'This icon appears for the committee item in the public menu.'); ?></small>
                                </div>
                            </div>
                            <div class="col-md-2 d-flex flex-column justify-content-end gap-2 pb-1">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="group_is_active" id="grp_active" value="1" <?php echo ($editGroup['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="grp_active"><?php echo $__t('सक्रिय', 'Active'); ?></label>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="group_show_in_navbar" id="grp_nav" value="1" <?php echo ($editGroup['show_in_navbar'] ?? 0) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="grp_nav"><?php echo $__t('मेनुमा', 'In menu'); ?></label>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3 d-flex gap-2">
                            <button type="submit" class="btn btn-success px-4">
                                <i class="fas fa-save me-1"></i><?php echo $editGroup ? $__t('अपडेट', 'Update') : $__t('थप्नुहोस्', 'Add'); ?>
                            </button>
                            <?php if ($editGroup): ?>
                            <a href="<?php echo htmlspecialchars($teamAdminSelf); ?>?tab=groups" class="btn btn-outline-secondary"><?php echo $__t('रद्द', 'Cancel'); ?></a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (empty($allCommitteeGroups)): ?>
            <div class="text-center py-4 text-muted">
                <i class="fas fa-layer-group fa-2x mb-2 d-block opacity-50"></i>
                <?php echo $__t('कुनै समिति समूह छैन। माथि थप्नुहोस्।', 'No committee groups yet. Add one above.'); ?>
            </div>
            <?php else: ?>
            <table class="table table-sm table-hover admin-table-card">
                <thead>
                    <tr>
                        <th><?php echo $__t('समूह', 'Group'); ?></th>
                        <th><?php echo $__t('अंग्रेजी', 'English'); ?></th>
                        <th><?php echo $__t('श्रेणी', 'Category'); ?></th>
                        <th class="text-center"><?php echo $__t('Map key', 'Map key'); ?></th>
                        <th class="text-center"><?php echo $__t('मेनु', 'Menu'); ?></th>
                        <th class="text-center"><?php echo $__t('स्थिति', 'Status'); ?></th>
                        <th class="text-center"><?php echo $__t('कार्य', 'Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($allCommitteeGroups as $g):
                    $gMc = $menuCatById[(int)($g['menu_category_id'] ?? 0)] ?? null;
                ?>
                    <tr>
                        <td>
                            <i class="<?php echo htmlspecialchars(trim((string)($g['icon'] ?? '')) ?: 'fas fa-users-gear'); ?> me-1 text-success"></i>
                            <?php echo htmlspecialchars($g['name_np'] ?: $g['name']); ?>
                        </td>
                        <td><?php echo htmlspecialchars($g['name'] ?? ''); ?></td>
                        <td>
                            <?php if ($gMc): ?>
                                <span class="badge bg-light text-dark border"><?php echo htmlspecialchars(isEnglish() ? ($gMc['name_en'] ?: $gMc['name_np']) : ($gMc['name_np'] ?: $gMc['name_en'])); ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><code class="small">cmt_<?php echo (int)$g['id']; ?></code></td>
                        <td class="text-center"><?php echo !empty($g['show_in_navbar']) ? '✓' : '—'; ?></td>
                        <td class="text-center">
                            <span class="badge <?php echo !empty($g['is_active']) ? 'bg-success' : 'bg-secondary'; ?>">
                                <?php echo !empty($g['is_active']) ? $__t('सक्रिय', 'Active') : $__t('निष्क्रिय', 'Inactive'); ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <a href="<?php echo htmlspecialchars($teamAdminSelf); ?>?tab=groups&edit_group=<?php echo (int)$g['id']; ?>" class="btn btn-sm btn-primary me-1"><i class="fas fa-edit"></i></a>
                            <a href="committees.php?tab=tenures&type=<?php echo (int)$g['id']; ?>" class="btn btn-sm btn-outline-secondary me-1" title="<?php echo $__t('कार्यकाल', 'Tenures'); ?>"><i class="fas fa-calendar-alt"></i></a>
                            <form method="POST" class="d-inline" onsubmit="return confirm('<?php echo addslashes($__t('यो समूह हटाउने?', 'Delete this group?')); ?>')">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="group_delete">
                                <input type="hidden" name="group_id" value="<?php echo (int)$g['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    <?php elseif ($teamListSection === 'karmachari'): ?>
    <!-- ══ TAB: कर्मचारी वर्ग / समूह (पद मास्टर जस्तै) ══ -->
    <div class="tab-pane fade <?php echo $activeTeamTab === 'groups' ? 'show active' : ''; ?>" id="team-groups">
        <div class="card-body">
            <div class="alert alert-info py-2 mb-3 small">
                <i class="fas fa-info-circle me-1"></i>
                <?php echo $__t(
                    'यो वर्ग/समूह सदस्य फारमको “वर्ग / समूह” dropdown मा आउँछ। पद मास्टरबाट पद छुट्टै थपिन्छ; यहाँ चाहिँ कर्मचारीलाई कुन टोलीमा राख्ने भन्ने समूह manage हुन्छ।',
                    'These groups appear in the member form Category/Group dropdown. Designations (posts) stay in Post Master; here you manage which staff team bucket a member belongs to.'
                ); ?>
            </div>

            <div class="card border mb-4">
                <div class="card-header bg-light py-2">
                    <strong><?php echo $editGroup ? $__t('वर्ग सम्पादन', 'Edit Category') : $__t('नयाँ वर्ग / समूह', 'Add Category / Group'); ?></strong>
                </div>
                <div class="card-body">
                    <form method="POST" action="<?php echo htmlspecialchars($teamAdminSelf); ?>?tab=groups<?php echo $editGroup ? '&edit_group='.(int)$editGroup['id'] : ''; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="<?php echo $editGroup ? 'group_edit' : 'group_add'; ?>">
                        <?php if ($editGroup): ?><input type="hidden" name="group_id" value="<?php echo (int)$editGroup['id']; ?>"><?php endif; ?>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold"><?php echo $__t('नाम (नेपाली) *', 'Name (Nepali) *'); ?></label>
                                <input type="text" name="group_name_np" class="form-control" required
                                       value="<?php echo htmlspecialchars($editGroup['name_np'] ?? ''); ?>"
                                       placeholder="<?php echo $__t('जस्तै: व्यवस्थापन', 'e.g. Management'); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold"><?php echo $__t('नाम (अंग्रेजी)', 'Name (English)'); ?></label>
                                <input type="text" name="group_name_en" class="form-control"
                                       value="<?php echo htmlspecialchars($editGroup['name_en'] ?? ($editGroup['name'] ?? '')); ?>"
                                       placeholder="e.g. Management">
                                <small class="text-muted"><?php echo $__t('नयाँ समूहको slug अंग्रेजी नामबाट बन्छ।', 'New group slug is generated from the English name.'); ?></small>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold"><?php echo $__t('क्रम', 'Order'); ?></label>
                                <input type="number" name="group_order" class="form-control" min="0"
                                       value="<?php echo (int)($editGroup['display_order'] ?? 0); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold"><?php echo $__t('मेनु श्रेणी', 'Menu category'); ?></label>
                                <select name="group_menu_category_id" class="form-select">
                                    <option value=""><?php echo $__t('— छान्नुहोस् —', '— Choose —'); ?></option>
                                    <?php foreach ($staffMenuCategories as $_mc): ?>
                                    <option value="<?php echo (int)$_mc['id']; ?>" <?php echo (int)($editGroup['menu_category_id'] ?? 0) === (int)$_mc['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(isEnglish() ? ($_mc['name_en'] ?: $_mc['name_np']) : ($_mc['name_np'] ?: $_mc['name_en'])); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted"><?php echo $__t('मानवीय श्रोत भित्र कुन parent', 'Parent under Human Resources'); ?> —
                                    <a href="#team-menu" onclick="document.getElementById('team-menu-btn')?.click(); return false;"><?php echo $__t('मेनु श्रेणी', 'Menu Categories'); ?></a>
                                </small>
                            </div>
                            <div class="col-md-2 d-flex flex-column justify-content-end gap-2 pb-1">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="group_is_active" id="sgrp_active" value="1" <?php echo ($editGroup['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="sgrp_active"><?php echo $__t('सक्रिय', 'Active'); ?></label>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="group_show_in_navbar" id="sgrp_nav" value="1" <?php echo ($editGroup['show_in_nav'] ?? $editGroup['show_in_navbar'] ?? 1) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="sgrp_nav"><?php echo $__t('सार्वजनिक फिल्टर', 'Public filter'); ?></label>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3 d-flex gap-2">
                            <button type="submit" class="btn btn-success px-4">
                                <i class="fas fa-save me-1"></i><?php echo $editGroup ? $__t('अपडेट', 'Update') : $__t('थप्नुहोस्', 'Add'); ?>
                            </button>
                            <?php if ($editGroup): ?>
                            <a href="<?php echo htmlspecialchars($teamAdminSelf); ?>?tab=groups" class="btn btn-outline-secondary"><?php echo $__t('रद्द', 'Cancel'); ?></a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (empty($allStaffGroups)): ?>
            <div class="text-center py-4 text-muted">
                <i class="fas fa-layer-group fa-2x mb-2 d-block opacity-50"></i>
                <?php echo $__t('कुनै वर्ग छैन। माथि थप्नुहोस्।', 'No categories yet. Add one above.'); ?>
            </div>
            <?php else: ?>
            <table class="table table-sm table-hover admin-table-card">
                <thead>
                    <tr>
                        <th><?php echo $__t('वर्ग', 'Category'); ?></th>
                        <th><?php echo $__t('अंग्रेजी', 'English'); ?></th>
                        <th class="text-center"><?php echo $__t('Slug', 'Slug'); ?></th>
                        <th class="text-center"><?php echo $__t('क्रम', 'Order'); ?></th>
                        <th class="text-center"><?php echo $__t('स्थिति', 'Status'); ?></th>
                        <th class="text-center"><?php echo $__t('कार्य', 'Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($allStaffGroups as $g): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($g['name_np'] ?: $g['name_en']); ?></td>
                        <td><?php echo htmlspecialchars($g['name_en'] ?? ''); ?></td>
                        <td class="text-center"><code class="small"><?php echo htmlspecialchars($g['slug']); ?></code></td>
                        <td class="text-center"><?php echo (int)$g['display_order']; ?></td>
                        <td class="text-center">
                            <span class="badge <?php echo !empty($g['is_active']) ? 'bg-success' : 'bg-secondary'; ?>">
                                <?php echo !empty($g['is_active']) ? $__t('सक्रिय', 'Active') : $__t('निष्क्रिय', 'Inactive'); ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <a href="<?php echo htmlspecialchars($teamAdminSelf); ?>?tab=groups&edit_group=<?php echo (int)$g['id']; ?>" class="btn btn-sm btn-primary me-1"><i class="fas fa-edit"></i></a>
                            <form method="POST" class="d-inline" onsubmit="return confirm('<?php echo addslashes($__t('यो वर्ग हटाउने? सदस्य कर्मचारीमा सर्छन्।', 'Delete this category? Members move to Staff.')); ?>')">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="group_delete">
                                <input type="hidden" name="group_id" value="<?php echo (int)$g['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══ TAB: मानवीय श्रोत मेनु श्रेणी (parent categories) ══ -->
    <div class="tab-pane fade <?php echo $activeTeamTab === 'menu' ? 'show active' : ''; ?>" id="team-menu">
        <div class="card-body">
            <div class="alert alert-info py-2 mb-3 small">
                <i class="fas fa-info-circle me-1"></i>
                <?php echo $__t(
                    'यो सार्वजनिक मेनु «मानवीय श्रोत» भित्रका parent श्रेणी हुन् (जस्तै: व्यवस्थापन, समिति/उपसमिति)। यी item होइनन् — यिनका भित्रका item हरू कर्मचारी वर्ग वा समिति समूहबाट आउँछन्।',
                    'These are parent categories inside the public Human Resources menu (e.g. Management, Committees). They are not leaf items — items under them come from staff groups or committee groups.'
                ); ?>
            </div>

            <div class="card border mb-4">
                <div class="card-header bg-light py-2">
                    <strong><?php echo $editMenuCat ? $__t('मेनु श्रेणी सम्पादन', 'Edit Menu Category') : $__t('नयाँ मेनु श्रेणी', 'Add Menu Category'); ?></strong>
                </div>
                <div class="card-body">
                    <form method="POST" action="<?php echo htmlspecialchars($teamAdminSelf); ?>?tab=menu<?php echo $editMenuCat ? '&edit_menu='.(int)$editMenuCat['id'] : ''; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="<?php echo $editMenuCat ? 'menu_cat_edit' : 'menu_cat_add'; ?>">
                        <?php if ($editMenuCat): ?><input type="hidden" name="menu_cat_id" value="<?php echo (int)$editMenuCat['id']; ?>"><?php endif; ?>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label fw-semibold"><?php echo $__t('नाम (नेपाली) *', 'Name (Nepali) *'); ?></label>
                                <input type="text" name="menu_name_np" class="form-control" required
                                       value="<?php echo htmlspecialchars($editMenuCat['name_np'] ?? ''); ?>"
                                       placeholder="<?php echo $__t('जस्तै: व्यवस्थापन', 'e.g. Management'); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold"><?php echo $__t('नाम (अंग्रेजी)', 'Name (English)'); ?></label>
                                <input type="text" name="menu_name_en" class="form-control"
                                       value="<?php echo htmlspecialchars($editMenuCat['name_en'] ?? ''); ?>"
                                       placeholder="e.g. Management">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold"><?php echo $__t('आइटम स्रोत', 'Item source'); ?></label>
                                <select name="menu_source_type" class="form-select" id="menu_source_type">
                                    <option value="staff" <?php echo ($editMenuCat['source_type'] ?? 'staff') === 'staff' ? 'selected' : ''; ?>><?php echo $__t('कर्मचारी वर्गहरू', 'Staff groups'); ?></option>
                                    <option value="committees" <?php echo ($editMenuCat['source_type'] ?? '') === 'committees' ? 'selected' : ''; ?>><?php echo $__t('समितिहरू', 'Committees'); ?></option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold"><?php echo $__t('आइकन', 'Icon'); ?></label>
                                <div class="js-fa-icon-picker fa-ip-wrap">
                                    <div class="fa-ip-row input-group">
                                        <span class="fa-ip-preview input-group-text" data-fa-preview>
                                            <i class="<?php echo htmlspecialchars($editMenuCat['icon'] ?? 'fas fa-folder', ENT_QUOTES, 'UTF-8'); ?>"></i>
                                        </span>
                                        <input type="text" name="menu_icon" class="form-control" data-fa-input
                                               value="<?php echo htmlspecialchars($editMenuCat['icon'] ?? 'fas fa-folder', ENT_QUOTES, 'UTF-8'); ?>"
                                               placeholder="fas fa-briefcase">
                                        <button type="button" class="btn btn-success fa-ip-open" data-fa-open
                                                title="<?php echo $__t('आइकन छान्नुहोस्', 'Pick icon'); ?>">
                                            <i class="fas fa-th me-1"></i><span><?php echo $__t('छान्नुहोस्', 'Pick'); ?></span>
                                        </button>
                                    </div>
                                    <small class="fa-ip-hint"><?php echo $__t('दायाँ बटनबाट आइकन छान्नुहोस् वा class टाइप गर्नुहोस्। सार्वजनिक मेनुमा यही icon देखिन्छ।', 'Pick an icon with the button, or type a class. This icon appears in the public menu.'); ?></small>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold"><?php echo $__t('क्रम', 'Order'); ?></label>
                                <input type="number" name="menu_order" class="form-control" min="0" value="<?php echo (int)($editMenuCat['display_order'] ?? 0); ?>">
                            </div>
                            <div class="col-md-4 d-flex flex-wrap align-items-end gap-4 pb-1">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="menu_is_active" id="menu_active" value="1" <?php echo ($editMenuCat['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="menu_active"><?php echo $__t('सक्रिय', 'Active'); ?></label>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="menu_show_in_nav" id="menu_nav" value="1" <?php echo ($editMenuCat['show_in_nav'] ?? 1) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="menu_nav"><?php echo $__t('मेनुमा देखाउने', 'Show in menu'); ?></label>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="menu_include_contact" id="menu_contact" value="1" <?php echo ($editMenuCat['include_contact_officers'] ?? 0) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="menu_contact"><?php echo $__t('+ सम्पर्क अधिकारी', '+ Contact officers'); ?></label>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="menu_include_board" id="menu_board" value="1" <?php echo ($editMenuCat['include_board'] ?? 0) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="menu_board"><?php echo $__t('+ सञ्चालक समिति', '+ Board committee'); ?></label>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3 d-flex gap-2">
                            <button type="submit" class="btn btn-success px-4">
                                <i class="fas fa-save me-1"></i><?php echo $editMenuCat ? $__t('अपडेट', 'Update') : $__t('थप्नुहोस्', 'Add'); ?>
                            </button>
                            <?php if ($editMenuCat): ?>
                            <a href="<?php echo htmlspecialchars($teamAdminSelf); ?>?tab=menu" class="btn btn-outline-secondary"><?php echo $__t('रद्द', 'Cancel'); ?></a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (empty($allMenuCategories)): ?>
            <div class="text-center py-4 text-muted">
                <i class="fas fa-sitemap fa-2x mb-2 d-block opacity-50"></i>
                <?php echo $__t('कुनै मेनु श्रेणी छैन।', 'No menu categories yet.'); ?>
            </div>
            <?php else: ?>
            <table class="table table-sm table-hover admin-table-card">
                <thead>
                    <tr>
                        <th><?php echo $__t('श्रेणी', 'Category'); ?></th>
                        <th><?php echo $__t('स्रोत', 'Source'); ?></th>
                        <th class="text-center"><?php echo $__t('क्रम', 'Order'); ?></th>
                        <th class="text-center"><?php echo $__t('मेनु', 'Menu'); ?></th>
                        <th class="text-center"><?php echo $__t('स्थिति', 'Status'); ?></th>
                        <th class="text-center"><?php echo $__t('कार्य', 'Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($allMenuCategories as $mc): ?>
                    <tr>
                        <td>
                            <i class="<?php echo htmlspecialchars($mc['icon'] ?: 'fas fa-folder'); ?> me-1 text-muted"></i>
                            <?php echo htmlspecialchars($mc['name_np'] ?: $mc['name_en']); ?>
                            <?php if (!empty($mc['name_en']) && $mc['name_en'] !== $mc['name_np']): ?>
                            <small class="text-muted d-block"><?php echo htmlspecialchars($mc['name_en']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border">
                                <?php echo ($mc['source_type'] ?? '') === 'committees'
                                    ? $__t('समिति', 'Committees')
                                    : $__t('कर्मचारी वर्ग', 'Staff groups'); ?>
                            </span>
                        </td>
                        <td class="text-center"><?php echo (int)$mc['display_order']; ?></td>
                        <td class="text-center"><?php echo !empty($mc['show_in_nav']) ? '✓' : '—'; ?></td>
                        <td class="text-center">
                            <span class="badge <?php echo !empty($mc['is_active']) ? 'bg-success' : 'bg-secondary'; ?>">
                                <?php echo !empty($mc['is_active']) ? $__t('सक्रिय', 'Active') : $__t('निष्क्रिय', 'Inactive'); ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <a href="<?php echo htmlspecialchars($teamAdminSelf); ?>?tab=menu&edit_menu=<?php echo (int)$mc['id']; ?>" class="btn btn-sm btn-primary me-1"><i class="fas fa-edit"></i></a>
                            <form method="POST" class="d-inline" onsubmit="return confirm('<?php echo addslashes($__t('यो मेनु श्रेणी हटाउने?', 'Delete this menu category?')); ?>')">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="menu_cat_delete">
                                <input type="hidden" name="menu_cat_id" value="<?php echo (int)$mc['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var teamI18n = {
        addBtn: <?php echo json_encode('<i class="fas fa-plus-circle me-2"></i>' . $__t('थप्नुहोस्', 'Add')); ?>,
        editBtn: <?php echo json_encode('<i class="fas fa-save me-2"></i>' . $__t('अपडेट गर्नुहोस्', 'Update')); ?>,
        addTitle: <?php echo json_encode('<i class="fas fa-plus-circle me-2"></i>' . $__t('नयाँ सदस्य थप्नुहोस्', 'Add New Member')); ?>,
        editTitle: <?php echo json_encode('<i class="fas fa-user-edit me-2"></i>' . $__t('सदस्य सम्पादन', 'Edit Member')); ?>,
        addTab: <?php echo json_encode($__t('नयाँ थप्नुहोस्', 'Add New')); ?>,
        editTab: <?php echo json_encode($__t('सम्पादन', 'Edit')); ?>,
        keepPhoto: <?php echo json_encode($__t(' — नयाँ फोटो नचुने भने पुरानै रहन्छ', ' - keep empty to retain current photo')); ?>
    };

    var tabsNav = document.querySelector('.admin-nav-tabs[data-team-section]');
    var teamSection = (tabsNav && tabsNav.getAttribute('data-team-section')) ? tabsNav.getAttribute('data-team-section') : 'governance';
    var defaultCategory = teamSection === 'karmachari' ? 'management' : 'board';
    var boardAliasCats = <?php echo json_encode(array_map(static fn($id) => 'cmt_' . (int)$id, $boardAliasTypeIds), JSON_UNESCAPED_UNICODE); ?>;

    function normalizeMemberCategory(cat) {
        if (!cat) return defaultCategory;
        if (boardAliasCats.indexOf(cat) !== -1) return 'board';
        return cat;
    }

    var listBtn = document.getElementById('team-list-btn');
    var formBtn = document.getElementById('team-form-btn');

    function switchToList() { adminSwitchTab(listBtn, formBtn); }
    function switchToForm() { adminSwitchTab(formBtn, listBtn); }

    function clearForm() {
        document.getElementById('tmf_action').value   = 'add';
        document.getElementById('tmf_id').value       = '';
        document.getElementById('tmf_name').value     = '';
        document.getElementById('tmf_name_en').value  = '';
        document.getElementById('tmf_pos').value      = '';
        document.getElementById('tmf_pos_np').value   = '';
        document.getElementById('tmf_pos_en').value   = '';
        document.getElementById('tmf_phone').value    = '';
        document.getElementById('tmf_email').value    = '';
        document.getElementById('tmf_order').value    = '0';
        document.getElementById('tmf_is_info').checked  = false;
        document.getElementById('tmf_is_griev').checked = false;
        document.getElementById('tmf_active').checked   = true;
        var catSel = document.getElementById('tmf_cat');
        if (catSel) {
            var found = false;
            for (var i=0; i<catSel.options.length; i++) {
                if (catSel.options[i].value === defaultCategory) { catSel.selectedIndex = i; found = true; break; }
            }
            if (!found) catSel.selectedIndex = 0;
        }
        document.getElementById('tmf_photo_prev').innerHTML = '';
        document.getElementById('tmf_photo_note').textContent = '';
        document.getElementById('tmf_submit').innerHTML = teamI18n.addBtn;
        document.getElementById('teamFormTitle').innerHTML = teamI18n.addTitle;
        document.getElementById('teamFormTabLabel').textContent = teamI18n.addTab;
    }

    var addBtn = document.getElementById('btnAddTeam');
    if (addBtn) {
        addBtn.addEventListener('click', function() { clearForm(); switchToForm(); });
    }

    ['btnCancelTeam','tmf_cancel2'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', function() { clearForm(); switchToList(); });
    });

    document.querySelectorAll('.btn-edit-member').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var m;
            try { m = JSON.parse(this.dataset.member); } catch(e) { return; }

            document.getElementById('tmf_action').value   = 'edit';
            document.getElementById('tmf_id').value       = m.id;
            document.getElementById('tmf_name').value     = m.name || '';
            document.getElementById('tmf_name_en').value  = m.name_en || '';
            document.getElementById('tmf_pos').value      = m.position || '';
            (function(){
                var sel=document.getElementById('tmf_pos_pick'); if(!sel) return;
                var want=(m.position_np||m.position||'').trim();
                for(var i=0;i<sel.options.length;i++){ if((sel.options[i].dataset.np||'').trim()===want){ sel.selectedIndex=i; break; } }
            })();
            document.getElementById('tmf_pos_np').value   = m.position_np || '';
            document.getElementById('tmf_pos_en').value   = m.position_en || '';
            document.getElementById('tmf_phone').value    = m.phone || '';
            document.getElementById('tmf_email').value    = m.email || '';
            document.getElementById('tmf_order').value    = m.display_order || 0;
            document.getElementById('tmf_is_info').checked  = m.is_information_officer == 1;
            document.getElementById('tmf_is_griev').checked = m.is_grievance_officer == 1;
            if (document.getElementById('tmf_is_chairman')) document.getElementById('tmf_is_chairman').checked = m.is_chairman == 1;
            if (document.getElementById('tmf_is_ceo'))      document.getElementById('tmf_is_ceo').checked      = m.is_ceo == 1;
            document.getElementById('tmf_active').checked   = m.is_active == 1;
            var sel = document.getElementById('tmf_cat');
            var wantCat = normalizeMemberCategory(m.category || '');
            for (var i=0; i<sel.options.length; i++) {
                if (sel.options[i].value === wantCat) { sel.selectedIndex = i; break; }
            }
            var prev = document.getElementById('tmf_photo_prev');
            prev.innerHTML = m.photo
                ? '<img src="<?php echo SITE_URL; ?>' + m.photo + '" class="tm-photo-preview">'
                : '';
            document.getElementById('tmf_photo_note').textContent = m.photo ? teamI18n.keepPhoto : '';
            document.getElementById('tmf_submit').innerHTML = teamI18n.editBtn;
            document.getElementById('teamFormTitle').innerHTML = teamI18n.editTitle;
            document.getElementById('teamFormTabLabel').textContent = teamI18n.editTab;
            switchToForm();
        });
    });

    document.querySelectorAll('.tm-cat-badge[data-badge-color]').forEach(function (el) {
        var c = (el.getAttribute('data-badge-color') || '').trim();
        if (!c) return;
        el.style.setProperty('--tm-cat', c);
    });
});
</script>

<?php require_once 'includes/admin-footer.php'; ?>
