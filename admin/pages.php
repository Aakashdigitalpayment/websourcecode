<?php
/**
 * Admin Pages Management
 * - गतिशील पृष्ठहरू (Dynamic pages): table/list + add/edit form (same page)
 * - स्थिर विषयवस्तु (Static content): table/list + add/edit form (same page)
 *
 * Canonical implementation (replaces the old legacy `pages.php` + `pages-v2.php` split).
 */
$pageTitle = 'पृष्ठ व्यवस्थापन';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$db = getDB();
checkCSRF();

$tab = (string) ($_GET['tab'] ?? $_POST['tab'] ?? 'dynamic');
if (!in_array($tab, ['dynamic', 'static'], true)) $tab = 'dynamic';

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'list');
if (!in_array($action, ['list', 'edit', 'edit_static', 'delete', 'add', 'bulk_status'], true)) $action = 'list';
if ($action === 'add') $action = 'edit';

$panel = (string) ($_GET['panel'] ?? '');
if (!in_array($panel, ['list', 'form'], true)) $panel = '';

function pages_admin_tinymce(): void {
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;
    echo '<script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js"></script>';
    echo "<script>
        tinymce.init({
            selector: '.editor',
            height: 420,
            plugins: 'lists link image table code preview fullscreen',
            toolbar: 'undo redo | formatselect | bold italic underline | alignleft aligncenter alignright alignjustify | bullist numlist | link image table | code preview fullscreen',
            menubar: false,
            branding: false,
            promotion: false,
            license_key: 'gpl'
        });
    </script>";
}

// Static pages keys (About Sections)
$staticPages = [
    'vision_content' => ['title' => 'हाम्रो दृष्टिकोण', 'title_en' => 'Our Vision'],
    'mission_content' => ['title' => 'हाम्रो लक्ष्य', 'title_en' => 'Our Mission'],
    'values_content' => ['title' => 'मूल मान्यताहरू', 'title_en' => 'Core Values'],
    'chairman_message' => ['title' => 'अध्यक्षको सन्देश', 'title_en' => "Chairman's Message"],
    'ceo_message' => ['title' => 'प्रमुख कार्यकारी अधिकृतको सन्देश', 'title_en' => "CEO's Message"],
];

// Handle static save (About Sections)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_static_section') {
    $pageKey = clean_text($_POST['page_key'] ?? '');
    if ($pageKey === '' || !array_key_exists($pageKey, $staticPages)) {
        setFlash('error', 'सेक्सन फेला परेन।');
        redirect('pages.php?tab=static');
    }
    $titleNp = clean_text($_POST['title_np'] ?? '');
    if ($titleNp === '') {
        $titleNp = (string)($staticPages[$pageKey]['title'] ?? '');
    }
    $titleEn = clean_text($_POST['title_en'] ?? '');
    if ($titleEn === '') {
        $titleEn = (string)($staticPages[$pageKey]['title_en'] ?? '');
    }
    $contentNp = $_POST['content_np'] ?? '';
    $contentEn = $_POST['content_en'] ?? '';
    try {
        $okNp = updateSetting($pageKey . '_np', $contentNp);
        $okEn = updateSetting($pageKey . '_en', $contentEn);
        $okTitle = updateSetting($pageKey . '_title_np', $titleNp);
        $okTitleEn = updateSetting($pageKey . '_title_en', $titleEn);
        if ($okNp && $okEn && $okTitle && $okTitleEn) {
            setFlash('success', 'सेक्सन सफलतापूर्वक अपडेट भयो।');
        } else {
            setFlash('error', 'सेभ गर्दा समस्या आयो। फेरि प्रयास गर्नुहोस्।');
        }
    } catch (Throwable $e) {
        setFlash('error', 'त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।');
    }
    redirect('pages.php?tab=static&action=edit_static&page=' . urlencode($pageKey) . '&panel=form');
}

// Handle dynamic save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_dynamic_page'])) {
    $pageId = (int) ($_POST['page_id'] ?? 0);
    $slug = clean_text($_POST['slug'] ?? '');
    $titleNp = clean_text($_POST['title_np'] ?? '');
    $titleEn = clean_text($_POST['title_en'] ?? '');
    $contentNp = $_POST['content_np'] ?? '';
    $contentEn = $_POST['content_en'] ?? '';
    $showInMenu = isset($_POST['show_in_menu']) ? 1 : 0;
    $menuPosition = clean_text($_POST['menu_position'] ?? 'about');
    $menuOrder = (int) ($_POST['menu_order'] ?? 0);
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    $allowedMenuPositions = ['about','services','more','footer'];
    if (!in_array($menuPosition, $allowedMenuPositions, true)) $menuPosition = 'about';

    if ($slug === '' && ($titleEn !== '' || $titleNp !== '')) {
        $slugSource = $titleEn !== '' ? $titleEn : $titleNp;
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$slugSource));
        $slug = trim($slug, '-');
    }
    if ($slug === '') $slug = 'page-' . time();

    if ($titleNp === '') {
        setFlash('error', 'शीर्षक (नेपाली) अनिवार्य छ।');
        redirect('pages.php?tab=dynamic&action=edit' . ($pageId > 0 ? '&id=' . $pageId : '') . '&panel=form');
    }

    try {
        if ($pageId > 0) {
            $slugChk = $db->prepare("SELECT id FROM pages WHERE slug = ? AND id <> ? LIMIT 1");
            $slugChk->execute([$slug, $pageId]);
        } else {
            $slugChk = $db->prepare("SELECT id FROM pages WHERE slug = ? LIMIT 1");
            $slugChk->execute([$slug]);
        }
        if ($slugChk->fetch()) {
            setFlash('error', 'यो slug पहिले नै प्रयोग भएको छ। कृपया अर्को slug राख्नुहोस्।');
            redirect('pages.php?tab=dynamic&action=edit' . ($pageId > 0 ? '&id=' . $pageId : '') . '&panel=form');
        }

        if ($pageId > 0) {
            $stmt = $db->prepare("UPDATE pages SET slug = ?, title = ?, title_np = ?, title_en = ?, content = ?, content_np = ?, show_in_menu = ?, menu_position = ?, menu_order = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$slug, $titleNp, $titleNp, $titleEn, $contentEn, $contentNp, $showInMenu, $menuPosition, $menuOrder, $isActive, $pageId]);
            setFlash('success', 'पृष्ठ सफलतापूर्वक अपडेट भयो।');
        } else {
            $stmt = $db->prepare("INSERT INTO pages (slug, title, title_np, title_en, content, content_np, show_in_menu, menu_position, menu_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$slug, $titleNp, $titleNp, $titleEn, $contentEn, $contentNp, $showInMenu, $menuPosition, $menuOrder, $isActive]);
            setFlash('success', 'नयाँ पृष्ठ सफलतापूर्वक थपियो।');
        }
    } catch (Throwable $e) {
        setFlash('error', 'त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।');
    }
    redirect('pages.php?tab=dynamic');
}

// Handle bulk status for dynamic list
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'bulk_status') {
    $bulk = clean_text($_POST['bulk'] ?? '');
    $selected = $_POST['selected_ids'] ?? [];
    $ids = array_values(array_filter(array_map('intval', (array)$selected), fn($v) => $v > 0));
    if (empty($ids) || !in_array($bulk, ['active','inactive'], true)) {
        setFlash('error', 'Bulk update का लागि rows छान्नुहोस्।');
    } else {
        $target = $bulk === 'active' ? 1 : 0;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = $db->prepare("UPDATE pages SET is_active = ? WHERE id IN ($ph)");
            $st->execute(array_merge([$target], $ids));
            setFlash('success', 'Bulk status update सफल भयो।');
        } catch (Throwable $e) {
            setFlash('error', 'Bulk update गर्दा त्रुटि भयो।');
        }
    }
    redirect('pages.php?tab=dynamic');
}

// Handle delete dynamic page
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $deleteId = (int) ($_POST['id'] ?? 0);
    try {
        $protectedSlugs = ['privacy-policy', 'terms-of-service', 'cookie-policy'];
        $chk = $db->prepare("SELECT slug FROM pages WHERE id = ? LIMIT 1");
        $chk->execute([$deleteId]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            setFlash('error', 'पृष्ठ फेला परेन।');
        } elseif (in_array((string)($row['slug'] ?? ''), $protectedSlugs, true)) {
            setFlash('error', 'यो system policy page हो। हटाउन मिल्दैन।');
        } else {
            $db->prepare("DELETE FROM pages WHERE id = ?")->execute([$deleteId]);
            setFlash('success', 'पृष्ठ मेटाइयो।');
        }
    } catch (Throwable $e) {
        setFlash('error', 'मेटाउँदा त्रुटि भयो।');
    }
    redirect('pages.php?tab=dynamic');
}

// Fetch dynamic pages
$dynamicPages = [];
try {
    $sql = "SELECT * FROM pages ORDER BY menu_position, menu_order, id";
    $st = $db->prepare($sql);
    $st->execute();
    $dynamicPages = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $dynamicPages = [];
}
$dpPart = adminPartitionRowsByIsActive($dynamicPages);
$dynamicLive = $dpPart['live'];
$dynamicArch = $dpPart['archived'];

// Dynamic edit context
$editDynId = (int) ($_GET['id'] ?? 0);
$dynEditRow = null;
if ($tab === 'dynamic' && $action === 'edit' && $editDynId > 0) {
    try {
        $st = $db->prepare("SELECT * FROM pages WHERE id = ? LIMIT 1");
        $st->execute([$editDynId]);
        $dynEditRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $dynEditRow = null;
    }
}

// Static edit context
$editStaticKey = (string) ($_GET['page'] ?? '');
if (!array_key_exists($editStaticKey, $staticPages)) $editStaticKey = '';
$staticPagesResolved = [];
foreach ($staticPages as $key => $info) {
    $customTitleNp = trim((string)getSetting($key . '_title_np', ''));
    $customTitleEn = trim((string)getSetting($key . '_title_en', ''));
    $staticPagesResolved[$key] = [
        'title' => $customTitleNp !== '' ? $customTitleNp : (string)$info['title'],
        'title_en' => $customTitleEn !== '' ? $customTitleEn : (string)$info['title_en'],
    ];
}
$staticNp = $editStaticKey !== '' ? getSetting($editStaticKey . '_np', '') : '';
$staticEn = $editStaticKey !== '' ? getSetting($editStaticKey . '_en', '') : '';

$flash = getFlash();
$headerSubtitle = $tab === 'static'
    ? 'स्थिर विषयवस्तु — सूची + फर्म'
    : 'गतिशील पृष्ठहरू — सूची + फर्म';
echo adminPageHeader('पृष्ठ व्यवस्थापन', 'fa-file-alt', $headerSubtitle, '');
if ($flash) echo adminAlert($flash['type'], $flash['message']);
?>

<div class="container-fluid py-3">
    <div class="row justify-content-center">
        <div class="col-12 col-xxl-11">

            <div class="tab-content">
                <!-- ===== Dynamic ===== -->
                <div class="tab-pane fade <?php echo $tab === 'dynamic' ? 'show active' : ''; ?>" id="pgv2-dynamic" role="tabpanel">
                    <ul class="nav nav-tabs admin-nav-tabs mb-0" role="tablist" style="margin-top:10px;">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo ($tab === 'dynamic' && $action !== 'edit' && $panel !== 'form') ? 'active' : ''; ?>"
                                    data-bs-toggle="tab" data-bs-target="#pgv2-dyn-list" type="button" role="tab">
                                <i class="fas fa-list me-2"></i>सूची
                                <span class="badge bg-success ms-1"><?php echo count($dynamicLive); ?> / <?php echo count($dynamicPages); ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo ($tab === 'dynamic' && ($action === 'edit' || $panel === 'form')) ? 'active' : ''; ?>"
                                    data-bs-toggle="tab" data-bs-target="#pgv2-dyn-form" type="button" role="tab">
                                <i class="fas fa-plus-circle me-2"></i><?php echo $dynEditRow ? 'सम्पादन' : 'नयाँ थप्नुहोस्'; ?>
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content">
                        <div class="tab-pane fade <?php echo ($tab === 'dynamic' && $action !== 'edit' && $panel !== 'form') ? 'show active' : ''; ?>" id="pgv2-dyn-list" role="tabpanel">
                            <div class="card admin-table-card svc-flat-top-card">
                                <div class="card-body p-0">
                                    <div class="admin-search-wrap px-3 py-2 border-bottom bg-light d-flex align-items-center gap-3">
                                        <div class="input-group input-group-sm svc-search-group">
                                            <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                                            <input type="text" class="form-control border-start-0 admin-table-search" placeholder="पृष्ठ खोज्नुहोस्..." autocomplete="off">
                                        </div>
                                        <small class="text-muted search-count"></small>
                                    </div>

                                    <form method="POST">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="bulk_status">
                                        <div class="px-3 py-2 border-bottom bg-light d-flex justify-content-end gap-2">
                                            <button type="submit" name="bulk" value="active" class="btn btn-sm btn-outline-success admin-bulk-btn">
                                <i class="fas fa-check-circle" aria-hidden="true"></i> Bulk Active
                            </button>
                            <button type="submit" name="bulk" value="inactive" class="btn btn-sm btn-outline-secondary admin-bulk-btn">
                                <i class="fas fa-ban" aria-hidden="true"></i> Bulk Inactive
                            </button>
                                        </div>
                                        <?php echo adminListSubtabPills('pgv2-sub', count($dynamicLive), count($dynamicArch)); ?>
                                        <div class="tab-content admin-table-subtab-content">
                                            <div class="tab-pane fade show active" id="pgv2-sub-live" role="tabpanel">
                                                <div class="table-responsive">
                                                    <table class="table table-hover align-middle mb-0">
                                                        <thead class="table-light">
                                                            <tr>
                                                                <th width="40" class="text-center"><input type="checkbox" onclick="document.querySelectorAll('#pgv2-sub-live .pgv2-select').forEach(c=>c.checked=this.checked)"></th>
                                                                <th width="60">#</th>
                                                                <th width="160">Slug</th>
                                                                <th>शीर्षक (नेपाली)</th>
                                                                <th width="220">Title (English)</th>
                                                                <th width="120">मेनु</th>
                                                                <th width="90" class="text-center">स्थिति</th>
                                                                <th width="140" class="text-center">कार्य</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php if (empty($dynamicPages)): ?>
                                                            <tr><td colspan="8" class="text-center py-5 text-muted">कुनै पृष्ठ छैन।</td></tr>
                                                            <?php endif; ?>
                                                            <?php foreach ($dynamicLive as $i => $pg): ?>
                                                            <tr>
                                                                <td class="text-center"><input type="checkbox" class="pgv2-select" name="selected_ids[]" value="<?php echo (int)$pg['id']; ?>"></td>
                                                                <td><?php echo $i + 1; ?></td>
                                                                <td>
                                                                    <a href="<?php echo SITE_URL; ?>page.php?slug=<?php echo htmlspecialchars((string)$pg['slug']); ?>" target="_blank" class="text-decoration-none">
                                                                        <code><?php echo htmlspecialchars((string)$pg['slug']); ?></code>
                                                                        <i class="fas fa-external-link-alt fa-xs ms-1"></i>
                                                                    </a>
                                                                </td>
                                                                <td><?php echo htmlspecialchars((string)($pg['title_np'] ?? $pg['title'] ?? '')); ?></td>
                                                                <td><?php echo htmlspecialchars((string)($pg['title_en'] ?? '—')); ?></td>
                                                                <td><?php echo !empty($pg['show_in_menu']) ? '<span class="badge bg-info">' . htmlspecialchars((string)($pg['menu_position'] ?? '')) . '</span>' : '<span class="text-muted">—</span>'; ?></td>
                                                                <td class="text-center"><?php echo !empty($pg['is_active']) ? '<span class="badge bg-success">सक्रिय</span>' : '<span class="badge bg-secondary">निष्क्रिय</span>'; ?></td>
                                                                <td class="text-center">
                                                                    <a class="btn btn-sm btn-primary" href="pages.php?tab=dynamic&action=edit&id=<?php echo (int)$pg['id']; ?>&panel=form" title="सम्पादन"><i class="fas fa-edit"></i></a>
                                                                    <?php $isProtected = in_array((string)($pg['slug'] ?? ''), ['privacy-policy','terms-of-service','cookie-policy'], true); ?>
                                                                    <form method="POST" class="svc-inline-form" onsubmit="return confirm('यो पृष्ठ मेटाउने हो?')" style="display:inline;">
                                                                        <?php echo csrfField(); ?>
                                                                        <input type="hidden" name="action" value="delete">
                                                                        <input type="hidden" name="id" value="<?php echo (int)$pg['id']; ?>">
                                                                        <button class="btn btn-sm btn-outline-danger" <?php echo $isProtected ? 'disabled' : ''; ?> title="<?php echo $isProtected ? 'policy page हटाउन मिल्दैन' : 'मेटाउनुहोस्'; ?>"><i class="fas fa-trash"></i></button>
                                                                    </form>
                                                                </td>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            <div class="tab-pane fade" id="pgv2-sub-arch" role="tabpanel">
                                                <div class="table-responsive">
                                                    <table class="table table-hover align-middle mb-0">
                                                        <thead class="table-light">
                                                            <tr>
                                                                <th width="40" class="text-center"><input type="checkbox" onclick="document.querySelectorAll('#pgv2-sub-arch .pgv2-select').forEach(c=>c.checked=this.checked)"></th>
                                                                <th width="60">#</th>
                                                                <th width="160">Slug</th>
                                                                <th>शीर्षक (नेपाली)</th>
                                                                <th width="220">Title (English)</th>
                                                                <th width="120">मेनु</th>
                                                                <th width="90" class="text-center">स्थिति</th>
                                                                <th width="140" class="text-center">कार्य</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php if (empty($dynamicArch)): ?>
                                                            <tr><td colspan="8" class="text-center py-5 text-muted">अभिलेखमा पृष्ठ छैन।</td></tr>
                                                            <?php endif; ?>
                                                            <?php foreach ($dynamicArch as $i => $pg): ?>
                                                            <tr>
                                                                <td class="text-center"><input type="checkbox" class="pgv2-select" name="selected_ids[]" value="<?php echo (int)$pg['id']; ?>"></td>
                                                                <td><?php echo $i + 1; ?></td>
                                                                <td><code><?php echo htmlspecialchars((string)$pg['slug']); ?></code></td>
                                                                <td><?php echo htmlspecialchars((string)($pg['title_np'] ?? $pg['title'] ?? '')); ?></td>
                                                                <td><?php echo htmlspecialchars((string)($pg['title_en'] ?? '—')); ?></td>
                                                                <td><?php echo !empty($pg['show_in_menu']) ? '<span class="badge bg-info">' . htmlspecialchars((string)($pg['menu_position'] ?? '')) . '</span>' : '<span class="text-muted">—</span>'; ?></td>
                                                                <td class="text-center"><?php echo !empty($pg['is_active']) ? '<span class="badge bg-success">सक्रिय</span>' : '<span class="badge bg-secondary">निष्क्रिय</span>'; ?></td>
                                                                <td class="text-center">
                                                                    <a class="btn btn-sm btn-primary" href="pages.php?tab=dynamic&action=edit&id=<?php echo (int)$pg['id']; ?>&panel=form" title="सम्पादन"><i class="fas fa-edit"></i></a>
                                                                </td>
                                                            </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade <?php echo ($tab === 'dynamic' && ($action === 'edit' || $panel === 'form')) ? 'show active' : ''; ?>" id="pgv2-dyn-form" role="tabpanel">
                            <div class="card svc-flat-top-card">
                                <div class="card-header d-flex justify-content-between align-items-center svc-form-header-grad">
                                    <h5 class="mb-0 fw-bold">
                                        <i class="fas fa-plus-circle me-2"></i><?php echo $dynEditRow ? 'पृष्ठ सम्पादन' : 'नयाँ पृष्ठ थप्नुहोस्'; ?>
                                    </h5>
                                    <a class="btn btn-light btn-sm" href="pages.php?tab=dynamic">
                                        <i class="fas fa-arrow-left me-1"></i>सूचीमा फर्कनुहोस्
                                    </a>
                                </div>
                                <div class="card-body p-4">
                                    <form method="POST" action="">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="save_dynamic_page" value="1">
                                        <input type="hidden" name="page_id" value="<?php echo (int)($dynEditRow['id'] ?? 0); ?>">

                                        <div class="row g-3">
                                            <div class="col-md-8">
                                                <label class="form-label fw-semibold">Slug (URL) <span class="text-danger">*</span></label>
                                                <input type="text" name="slug" class="form-control" required value="<?php echo htmlspecialchars((string)($dynEditRow['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                <div class="form-text">URL: <code><?php echo SITE_URL; ?>page.php?slug=[slug]</code></div>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold">स्थिति</label>
                                                <div class="form-check form-switch mt-2">
                                                    <input class="form-check-input" type="checkbox" name="is_active" id="pgv2_isActive" <?php echo !isset($dynEditRow) || !is_array($dynEditRow) || !array_key_exists('is_active',$dynEditRow) || (int)($dynEditRow['is_active'] ?? 1) === 1 ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="pgv2_isActive">सक्रिय</label>
                                                </div>
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">शीर्षक (नेपाली) <span class="text-danger">*</span></label>
                                                <input type="text" name="title_np" class="form-control" required value="<?php echo htmlspecialchars((string)($dynEditRow['title_np'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">Title (English)</label>
                                                <input type="text" name="title_en" class="form-control" value="<?php echo htmlspecialchars((string)($dynEditRow['title_en'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>

                                            <div class="col-md-4">
                                                <div class="form-check mt-4">
                                                    <input class="form-check-input" type="checkbox" name="show_in_menu" id="pgv2_showInMenu" <?php echo !empty($dynEditRow['show_in_menu']) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="pgv2_showInMenu">मेनुमा देखाउनुहोस्</label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold">मेनु</label>
                                                <select name="menu_position" class="form-select form-select-sm">
                                                    <option value="about" <?php echo (($dynEditRow['menu_position'] ?? '') === 'about') ? 'selected' : ''; ?>>हाम्रो बारेमा</option>
                                                    <option value="services" <?php echo (($dynEditRow['menu_position'] ?? '') === 'services') ? 'selected' : ''; ?>>सेवाहरू</option>
                                                    <option value="more" <?php echo (($dynEditRow['menu_position'] ?? '') === 'more') ? 'selected' : ''; ?>>थप</option>
                                                    <option value="footer" <?php echo (($dynEditRow['menu_position'] ?? '') === 'footer') ? 'selected' : ''; ?>>फुटर</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold">क्रम</label>
                                                <input type="number" name="menu_order" class="form-control form-control-sm" value="<?php echo (int)($dynEditRow['menu_order'] ?? 0); ?>">
                                            </div>

                                            <div class="col-12">
                                                <ul class="nav nav-tabs admin-nav-tabs mb-2" role="tablist">
                                                    <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pgv2_np" type="button" role="tab">नेपाली</button></li>
                                                    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pgv2_en" type="button" role="tab">English</button></li>
                                                </ul>
                                                <div class="tab-content">
                                                    <div class="tab-pane fade show active" id="pgv2_np" role="tabpanel">
                                                        <textarea name="content_np" class="form-control editor" rows="14"><?php echo htmlspecialchars((string)($dynEditRow['content_np'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                                    </div>
                                                    <div class="tab-pane fade" id="pgv2_en" role="tabpanel">
                                                        <textarea name="content_en" class="form-control editor" rows="14"><?php echo htmlspecialchars((string)($dynEditRow['content'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <hr class="my-4">
                                        <button type="submit" class="btn btn-success px-5 fw-semibold"><i class="fas fa-save me-2"></i>सेभ गर्नुहोस्</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ===== Static ===== -->
                <div class="tab-pane fade <?php echo $tab === 'static' ? 'show active' : ''; ?>" id="pgv2-static" role="tabpanel">
                    <ul class="nav nav-tabs admin-nav-tabs mb-0" role="tablist" style="margin-top:10px;">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo ($tab === 'static' && $action !== 'edit_static' && $panel !== 'form') ? 'active' : ''; ?>"
                                    data-bs-toggle="tab" data-bs-target="#pgv2-st-list" type="button" role="tab">
                                <i class="fas fa-list me-2"></i>सूची
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?php echo ($tab === 'static' && ($action === 'edit_static' || $panel === 'form')) ? 'active' : ''; ?>"
                                    data-bs-toggle="tab" data-bs-target="#pgv2-st-form" type="button" role="tab">
                                <i class="fas fa-pen-to-square me-2"></i><?php echo $editStaticKey !== '' ? 'सम्पादन' : 'फर्म'; ?>
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content">
                        <div class="tab-pane fade <?php echo ($tab === 'static' && $action !== 'edit_static' && $panel !== 'form') ? 'show active' : ''; ?>" id="pgv2-st-list" role="tabpanel">
                            <div class="card admin-table-card svc-flat-top-card">
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th width="70">#</th>
                                                    <th>सेक्सन नाम (नेपाली)</th>
                                                    <th width="280">Section Name (English)</th>
                                                    <th width="140" class="text-center">कार्य</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $i = 1; foreach ($staticPagesResolved as $key => $info): ?>
                                                <tr>
                                                    <td><?php echo $i++; ?></td>
                                                    <td><?php echo htmlspecialchars($info['title'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td><?php echo htmlspecialchars($info['title_en'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td class="text-center">
                                                        <a class="btn btn-sm btn-primary" href="pages.php?tab=static&action=edit_static&page=<?php echo urlencode($key); ?>&panel=form" title="सम्पादन"><i class="fas fa-edit"></i></a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="tab-pane fade <?php echo ($tab === 'static' && ($action === 'edit_static' || $panel === 'form')) ? 'show active' : ''; ?>" id="pgv2-st-form" role="tabpanel">
                            <div class="card svc-flat-top-card">
                                <div class="card-header d-flex justify-content-between align-items-center svc-form-header-grad">
                                    <h5 class="mb-0 fw-bold"><i class="fas fa-pen-to-square me-2"></i>स्थिर सेक्सन सम्पादन</h5>
                                    <a class="btn btn-light btn-sm" href="pages.php?tab=static"><i class="fas fa-arrow-left me-1"></i>सूची</a>
                                </div>
                                <div class="card-body p-4">
                                    <?php if ($editStaticKey === ''): ?>
                                        <div class="alert alert-info mb-3">
                                            सूचीबाट Edit थिच्नुहोस्, वा यहाँबाट सेक्सन छानेर सम्पादन खोल्नुहोस्।
                                        </div>
                                        <form method="GET" class="row g-3 align-items-end">
                                            <input type="hidden" name="tab" value="static">
                                            <input type="hidden" name="action" value="edit_static">
                                            <input type="hidden" name="panel" value="form">
                                            <div class="col-md-8">
                                                <label class="form-label fw-semibold">सेक्सन छान्नुहोस्</label>
                                                <select name="page" class="form-select" required>
                                                    <option value="" selected disabled>— सेक्सन छान्नुहोस् —</option>
                                                    <?php foreach ($staticPagesResolved as $key => $info): ?>
                                                        <option value="<?php echo htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8'); ?>">
                                                            <?php echo htmlspecialchars((string)$info['title'], ENT_QUOTES, 'UTF-8'); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <button type="submit" class="btn btn-primary w-100">
                                                    <i class="fas fa-pen-to-square me-2"></i>सम्पादन खोल्नुहोस्
                                                </button>
                                            </div>
                                        </form>
                                    <?php else: ?>
                                    <form method="POST" action="">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="save_static_section">
                                        <input type="hidden" name="page_key" value="<?php echo htmlspecialchars($editStaticKey, ENT_QUOTES, 'UTF-8'); ?>">

                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">सेक्सन नाम (नेपाली)</label>
                                            <input type="text" name="title_np" class="form-control" required value="<?php echo htmlspecialchars($staticPagesResolved[$editStaticKey]['title'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <div class="form-text">यो नाम सूचीमा देखिने शीर्षक हो।</div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">Section Name (English)</label>
                                            <input type="text" name="title_en" class="form-control" value="<?php echo htmlspecialchars($staticPagesResolved[$editStaticKey]['title_en'], ENT_QUOTES, 'UTF-8'); ?>">
                                        </div>

                                        <ul class="nav nav-tabs admin-nav-tabs mb-2" role="tablist">
                                            <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pgv2_st_np" type="button" role="tab">नेपाली</button></li>
                                            <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#pgv2_st_en" type="button" role="tab">English</button></li>
                                        </ul>
                                        <div class="tab-content">
                                            <div class="tab-pane fade show active" id="pgv2_st_np" role="tabpanel">
                                                <textarea name="content_np" class="form-control editor" rows="14"><?php echo htmlspecialchars($staticNp, ENT_QUOTES, 'UTF-8'); ?></textarea>
                                            </div>
                                            <div class="tab-pane fade" id="pgv2_st_en" role="tabpanel">
                                                <textarea name="content_en" class="form-control editor" rows="14"><?php echo htmlspecialchars($staticEn, ENT_QUOTES, 'UTF-8'); ?></textarea>
                                            </div>
                                        </div>

                                        <hr class="my-4">
                                        <button type="submit" class="btn btn-success px-5 fw-semibold"><i class="fas fa-save me-2"></i>सेभ गर्नुहोस्</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </div>
</div>


<?php pages_admin_tinymce(); ?>
<?php require_once 'includes/admin-footer.php'; ?>
