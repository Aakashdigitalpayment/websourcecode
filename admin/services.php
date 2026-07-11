<?php
/**
 * सेवा व्यवस्थापन — Services Management
 * Tab UI: सूची + Add/Edit form (modal popup हटाइएको)
 */
$__t = static function (string $np, string $en): string {
    $lang = (string)($_SESSION['admin_lang'] ?? $_SESSION['lang'] ?? 'np');
    return strtolower($lang) === 'en' ? $en : $np;
};
$pageTitle = $__t('सेवा व्यवस्थापन', 'Services Management');
require_once '../includes/config.php';
require_once '../includes/service-products-tables.php';
if (!isAdminLoggedIn()) redirect(ADMIN_URL . 'index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'सुरक्षा जाँच असफल।'];
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? ADMIN_URL . 'dashboard.php')); exit;
    }
}
if (empty($csrfToken)) $csrfToken = generateCSRFToken();

$success = '';
$error   = '';
$db      = getDB();
ensureServiceProductsTables($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $act = $_POST['action'];

        /* ── Service Category CRUD ────────────────────────────────────────── */
        if ($act === 'cat_add' || $act === 'cat_edit') {
            $catName   = clean_text($_POST['cat_name']    ?? '');
            $catNameEn = clean_text($_POST['cat_name_en'] ?? '');
            $catNameNp = clean_text($_POST['cat_name_np'] ?? $catName);
            $catIcon   = clean_text($_POST['cat_icon']    ?? 'fas fa-th-large');
            $catOrder  = (int)($_POST['cat_order'] ?? 0);
            $catActive = isset($_POST['cat_is_active']) ? 1 : 0;
            if ($catName === '') throw new Exception('Category name required.');
            if ($act === 'cat_add') {
                $db->prepare("INSERT INTO service_categories (name, name_en, name_np, icon, display_order, is_active) VALUES (?,?,?,?,?,?)")
                   ->execute([$catName, $catNameEn, $catNameNp, $catIcon, $catOrder, $catActive]);
                $success = $__t('श्रेणी थपियो।', 'Category added.');
            } else {
                $db->prepare("UPDATE service_categories SET name=?, name_en=?, name_np=?, icon=?, display_order=?, is_active=? WHERE id=?")
                   ->execute([$catName, $catNameEn, $catNameNp, $catIcon, $catOrder, $catActive, (int)$_POST['cat_id']]);
                $success = $__t('श्रेणी अपडेट भयो।', 'Category updated.');
            }
            header('Location: services.php?tab=cats&msg=' . urlencode($success)); exit;
        }
        if ($act === 'cat_delete') {
            $catDel = (int)($_POST['cat_id'] ?? 0);
            if ($catDel) {
                $db->prepare("DELETE FROM service_categories WHERE id=?")->execute([$catDel]);
                // Unlink services from this category
                $db->prepare("UPDATE services SET service_category_id = NULL WHERE service_category_id=?")->execute([$catDel]);
            }
            header('Location: services.php?tab=cats'); exit;
        }

        if ($act === 'add' || $act === 'edit') {
            $title        = clean_text($_POST['title']        ?? '');
            $title_en     = clean_text($_POST['title_en']     ?? '');
            $title_np     = clean_text($_POST['title_np']     ?? $title);
            $description  = clean_text($_POST['description']  ?? '');
            $description_np = clean_text($_POST['description_np'] ?? $description);
            $icon         = clean_text($_POST['icon']         ?? 'fas fa-star');
            $order        = (int)($_POST['display_order']   ?? 0);
            $is_active    = isset($_POST['is_active']) ? 1 : 0;
            $nav_group          = in_array($_POST['nav_group'] ?? '', ['general','social','student','financial','other']) ? ($_POST['nav_group']) : 'general';
            $service_category_id = (int)($_POST['service_category_id'] ?? 0) ?: null;

            if ($act === 'add') {
                try {
                    $db->prepare("INSERT INTO services (title, title_en, title_np, description, description_np, icon, display_order, is_active, nav_group, service_category_id) VALUES (?,?,?,?,?,?,?,?,?,?)")
                       ->execute([$title, $title_en, $title_np, $description, $description_np, $icon, $order, $is_active, $nav_group, $service_category_id]);
                } catch (Throwable $e2) {
                    $db->prepare("INSERT INTO services (title, title_en, title_np, description, description_np, icon, display_order, is_active) VALUES (?,?,?,?,?,?,?,?)")
                       ->execute([$title, $title_en, $title_np, $description, $description_np, $icon, $order, $is_active]);
                }
                $success = 'सेवा सफलतापूर्वक थपियो।';
            } else {
                try {
                    $db->prepare("UPDATE services SET title=?, title_en=?, title_np=?, description=?, description_np=?, icon=?, display_order=?, is_active=?, nav_group=?, service_category_id=? WHERE id=?")
                       ->execute([$title, $title_en, $title_np, $description, $description_np, $icon, $order, $is_active, $nav_group, $service_category_id, (int)$_POST['id']]);
                } catch (Throwable $e2) {
                    $db->prepare("UPDATE services SET title=?, title_en=?, title_np=?, description=?, description_np=?, icon=?, display_order=?, is_active=? WHERE id=?")
                       ->execute([$title, $title_en, $title_np, $description, $description_np, $icon, $order, $is_active, (int)$_POST['id']]);
                }
                $success = 'सेवा सफलतापूर्वक अपडेट भयो।';
            }
        } elseif ($act === 'delete') {
            $db->prepare("DELETE FROM services WHERE id=?")->execute([(int)$_POST['id']]);
            $success = 'सेवा मेटाइयो।';
        } elseif ($act === 'bulk_status') {
            $bulk = clean_text($_POST['bulk'] ?? '');
            $selected = $_POST['selected_ids'] ?? [];
            $ids = array_values(array_filter(array_map('intval', (array)$selected), fn($v) => $v > 0));
            if (empty($ids) || !in_array($bulk, ['active','inactive'], true)) {
                $error = 'Bulk update का लागि rows छान्नुहोस्।';
            } else {
                $target = $bulk === 'active' ? 1 : 0;
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $st = $db->prepare("UPDATE services SET is_active = ? WHERE id IN ($ph)");
                $st->execute(array_merge([$target], $ids));
                $success = 'Bulk status update सफल भयो।';
            }
        } elseif ($act === 'product_add' || $act === 'product_edit') {
            $pid = (int)($_POST['product_id'] ?? 0);
            $serviceId = (int)($_POST['product_service_id'] ?? 0);
            $titleNp = clean_text($_POST['product_title_np'] ?? '');
            $titleEn = clean_text($_POST['product_title_en'] ?? '');
            $descNp = trim((string)($_POST['product_description_np'] ?? ''));
            $descEn = trim((string)($_POST['product_description_en'] ?? ''));
            $order = (int)($_POST['product_display_order'] ?? 0);
            $active = isset($_POST['product_is_active']) ? 1 : 0;
            if ($serviceId <= 0 || $titleNp === '') {
                $error = 'Product थप्न सेवा र नेपाली शीर्षक अनिवार्य छ।';
            } elseif ($act === 'product_add') {
                $db->prepare("INSERT INTO service_products (service_id, title_np, title_en, description_np, description_en, display_order, is_active)
                              VALUES (?,?,?,?,?,?,?)")
                    ->execute([$serviceId, $titleNp, $titleEn, $descNp, $descEn, $order, $active]);
                $success = 'Service product सफलतापूर्वक थपियो।';
            } else {
                $db->prepare("UPDATE service_products
                              SET service_id=?, title_np=?, title_en=?, description_np=?, description_en=?, display_order=?, is_active=?
                              WHERE id=?")
                    ->execute([$serviceId, $titleNp, $titleEn, $descNp, $descEn, $order, $active, $pid]);
                $success = 'Service product सफलतापूर्वक अपडेट भयो।';
            }
        } elseif ($act === 'product_delete') {
            $pid = (int)($_POST['product_id'] ?? 0);
            if ($pid > 0) {
                $db->prepare("DELETE FROM service_products WHERE id=?")->execute([$pid]);
                $success = 'Service product हटाइयो।';
            }
        }
    } catch (Exception $e) {
        $error = 'त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।';
    }
}

try {
    $services = $db->query("SELECT * FROM services ORDER BY display_order, id DESC")->fetchAll();
} catch (Exception $e) { $services = []; }
try {
    $serviceProducts = $db->query("SELECT sp.*, s.title_np AS service_title_np, s.title_en AS service_title_en, s.title AS service_title
                                   FROM service_products sp
                                   LEFT JOIN services s ON s.id = sp.service_id
                                   ORDER BY sp.service_id, sp.display_order, sp.id DESC")->fetchAll();
} catch (Exception $e) { $serviceProducts = []; }

// Service categories (dynamic mega-menu groups)
try {
    $serviceCategories = $db->query("SELECT * FROM service_categories ORDER BY display_order, id")->fetchAll();
} catch (Exception $e) { $serviceCategories = []; }

$activeTab = $_GET['tab'] ?? 'services';
$editCatId = (int)($_GET['edit_cat'] ?? 0);
$editCat   = null;
foreach ($serviceCategories as $sc) {
    if ((int)$sc['id'] === $editCatId) { $editCat = $sc; break; }
}

$editServiceId = (int)($_GET['edit'] ?? 0);
$editService = null;
if ($editServiceId > 0) {
    foreach ($services as $svcRow) {
        if ((int)($svcRow['id'] ?? 0) === $editServiceId) {
            $editService = $svcRow;
            break;
        }
    }
}
$openServiceForm = is_array($editService);

require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$svcPart = adminPartitionRowsByIsActive($services);
$servicesLive = $svcPart['live'];
$servicesArch = $svcPart['archived'];
?>

<?php echo adminPageHeader(
    $__t('सेवा व्यवस्थापन', 'Services Management'),
    'fa-concierge-bell',
    $__t('संस्थाले प्रदान गर्ने सेवाहरू व्यवस्थापन।', 'Manage services provided by the organization.'),
    '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25 me-2"><i class="fas fa-layer-group me-1"></i>' . $__t('जम्मा', 'Total') . ': ' . count($services) . '</span>'
    . '<span class="badge admin-stat-badge bg-primary-subtle text-primary border border-primary border-opacity-25 me-2"><i class="fas fa-check-circle me-1"></i>' . $__t('सक्रिय', 'Active') . ': ' . count($servicesLive) . '</span>'
    . '<span class="badge admin-stat-badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25"><i class="fas fa-archive me-1"></i>' . $__t('अभिलेख', 'Archived') . ': ' . count($servicesArch) . '</span>'
); ?>

<?php echo adminAlert('success', $success) . adminAlert('danger', $error); ?>

<ul class="nav nav-tabs admin-nav-tabs mb-0">
    <li class="nav-item">
        <button class="nav-link <?php echo $openServiceForm ? '' : ($activeTab === 'cats' ? '' : 'active'); ?>" data-bs-toggle="tab" data-bs-target="#svc-list" id="svc-list-btn" title="सक्रिय / जम्मा">
            <i class="fas fa-list me-2"></i><?php echo $__t('सेवा सूची', 'Service List'); ?>
            <span class="badge bg-success ms-1"><?php echo count($servicesLive); ?> / <?php echo count($services); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link <?php echo $openServiceForm ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#svc-form" id="svc-form-btn">
            <i class="fas fa-plus-circle me-2"></i><span id="svcFormTabLabel"><?php echo $openServiceForm ? $__t('सम्पादन', 'Edit') : $__t('नयाँ थप्नुहोस्', 'Add New'); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#svc-products" id="svc-products-btn">
            <i class="fas fa-list-check me-2"></i><span id="svcProductsTabLabel">Service Products</span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link <?php echo $activeTab === 'cats' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#svc-cats" id="svc-cats-btn">
            <i class="fas fa-layer-group me-2"></i><?php echo $__t('मेनु श्रेणी', 'Menu Categories'); ?>
            <span class="badge bg-secondary ms-1"><?php echo count($serviceCategories); ?></span>
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- ══ TAB 1: सूची ══ -->
    <div class="tab-pane fade <?php echo $openServiceForm ? '' : 'show active'; ?>" id="svc-list">
        <div class="card admin-table-card svc-flat-top-card">

            <!-- खोज बक्स — client-side filter -->
            <div class="admin-search-wrap px-3 py-2 border-bottom bg-light d-flex align-items-center gap-3 svc-search-wrap">
                <div class="input-group input-group-sm svc-search-group">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 admin-table-search" placeholder="<?php echo $__t('नाम, विवरण अनुसार खोज्नुहोस्...', 'Search by name or description...'); ?>" autocomplete="off">
                </div>
                <small class="text-muted search-count"></small>
            </div>
            <div class="card-body p-0">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="bulk_status">
                        <div class="px-3 py-2 border-bottom bg-light d-flex justify-content-end gap-2">
                            <button type="submit" name="bulk" value="active" class="btn btn-sm btn-outline-success">Bulk Active</button>
                            <button type="submit" name="bulk" value="inactive" class="btn btn-sm btn-outline-secondary">Bulk Inactive</button>
                        </div>
                    <?php echo adminListSubtabPills('svc-sub', count($servicesLive), count($servicesArch)); ?>
                    <div class="tab-content admin-table-subtab-content">
                    <div class="tab-pane fade show active" id="svc-sub-live" role="tabpanel">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th width="40" class="text-center"><input type="checkbox" onclick="document.querySelectorAll('#svc-sub-live .svc-select').forEach(c=>c.checked=this.checked)"></th>
                                <th class="ps-3" width="60"><?php echo $__t('आइकन', 'Icon'); ?></th>
                                <th><?php echo $__t('शीर्षक', 'Title'); ?></th>
                                <th><?php echo $__t('विवरण', 'Description'); ?></th>
                                <th width="70" class="text-center"><?php echo $__t('क्रम', 'Order'); ?></th>
                                <th width="90" class="text-center"><?php echo $__t('स्थिति', 'Status'); ?></th>
                                <th width="140" class="text-center"><?php echo $__t('कार्य', 'Actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($services)): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted">
                                <i class="fas fa-concierge-bell fa-3x mb-2 d-block opacity-25"></i>
                                <?php echo $__t('कुनै सेवा छैन।', 'No services found.'); ?>
                            </td></tr>
                            <?php elseif (empty($servicesLive)): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted">
                                <i class="fas fa-check-circle fa-3x mb-2 d-block opacity-25 text-success"></i>
                                <?php echo $__t('सक्रिय सेवा छैन। अभिलेख हेर्नुहोस्।', 'No active services. Check archived tab.'); ?>
                            </td></tr>
                            <?php endif; ?>
                            <?php foreach ($servicesLive as $s): ?>
                            <tr>
                                <td class="text-center"><input type="checkbox" class="svc-select" name="selected_ids[]" value="<?php echo (int)$s['id']; ?>"></td>
                                <td class="ps-3">
                                    <div class="admin-icon-cell">
                                        <i class="<?php echo htmlspecialchars($s['icon']); ?> svc-icon-mark"></i>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($s['title_np'] ?: $s['title']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($s['title_en'] ?? $s['title']); ?></small>
                                </td>
                                <td><span class="text-muted"><?php echo htmlspecialchars(mb_substr($s['description_np'] ?: ($s['description'] ?? ''), 0, 60)); ?>…</span></td>
                                <td class="text-center"><span class="badge bg-light text-dark border"><?php echo $s['display_order']; ?></span></td>
                                <td class="text-center"><span class="badge bg-<?php echo $s['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $s['is_active'] ? $__t('सक्रिय', 'Active') : $__t('निष्क्रिय', 'Inactive'); ?></span></td>
                                <td class="text-center">
                                    <a href="services.php?edit=<?php echo (int)$s['id']; ?>" class="btn btn-sm btn-primary me-1" title="<?php echo $__t('सम्पादन', 'Edit'); ?>">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="POST" class="svc-inline-form" onsubmit="return confirm('<?php echo $__t('के तपाईं यो सेवा मेटाउन निश्चित हुनुहुन्छ?', 'Are you sure you want to delete this service?'); ?>')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="<?php echo $__t('मेटाउनुहोस्', 'Delete'); ?>"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    </div>
                    <div class="tab-pane fade" id="svc-sub-arch" role="tabpanel">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th width="40" class="text-center"><input type="checkbox" onclick="document.querySelectorAll('#svc-sub-arch .svc-select').forEach(c=>c.checked=this.checked)"></th>
                                <th class="ps-3" width="60"><?php echo $__t('आइकन', 'Icon'); ?></th>
                                <th><?php echo $__t('शीर्षक', 'Title'); ?></th>
                                <th><?php echo $__t('विवरण', 'Description'); ?></th>
                                <th width="70" class="text-center"><?php echo $__t('क्रम', 'Order'); ?></th>
                                <th width="90" class="text-center"><?php echo $__t('स्थिति', 'Status'); ?></th>
                                <th width="140" class="text-center"><?php echo $__t('कार्य', 'Actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($servicesArch)): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted">
                                <i class="fas fa-folder-open fa-3x mb-2 d-block opacity-25"></i>
                                <?php echo $__t('अभिलेखमा कुनै सेवा छैन।', 'No archived services.'); ?>
                            </td></tr>
                            <?php endif; ?>
                            <?php foreach ($servicesArch as $s): ?>
                            <tr>
                                <td class="text-center"><input type="checkbox" class="svc-select" name="selected_ids[]" value="<?php echo (int)$s['id']; ?>"></td>
                                <td class="ps-3">
                                    <div class="admin-icon-cell">
                                        <i class="<?php echo htmlspecialchars($s['icon']); ?> svc-icon-mark"></i>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($s['title_np'] ?: $s['title']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($s['title_en'] ?? $s['title']); ?></small>
                                </td>
                                <td><span class="text-muted"><?php echo htmlspecialchars(mb_substr($s['description_np'] ?: ($s['description'] ?? ''), 0, 60)); ?>…</span></td>
                                <td class="text-center"><span class="badge bg-light text-dark border"><?php echo $s['display_order']; ?></span></td>
                                <td class="text-center"><span class="badge bg-<?php echo $s['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $s['is_active'] ? $__t('सक्रिय', 'Active') : $__t('निष्क्रिय', 'Inactive'); ?></span></td>
                                <td class="text-center">
                                    <a href="services.php?edit=<?php echo (int)$s['id']; ?>" class="btn btn-sm btn-primary me-1" title="सम्पादन">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="POST" class="svc-inline-form" onsubmit="return confirm('के तपाईं यो सेवा मेटाउन निश्चित हुनुहुन्छ?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="मेटाउनुहोस्"><i class="fas fa-trash"></i></button>
                                    </form>
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

    <!-- ══ TAB 2: Add / Edit Form ══ -->
    <div class="tab-pane fade <?php echo $openServiceForm ? 'show active' : ''; ?>" id="svc-form">
        <div class="card svc-flat-top-card">
            <div class="card-header d-flex justify-content-between align-items-center svc-form-header-grad">
                <h5 class="mb-0 fw-bold" id="svcFormTitle">
                    <i class="fas fa-plus-circle me-2"></i><?php echo $openServiceForm ? $__t('सेवा सम्पादन', 'Edit Service') : $__t('नयाँ सेवा थप्नुहोस्', 'Add New Service'); ?>
                </h5>
                <a href="services.php" class="btn btn-light btn-sm" id="btnCancelSvc">
                    <i class="fas fa-arrow-left me-1"></i><?php echo $__t('सूचीमा फर्कनुहोस्', 'Back to List'); ?>
                </a>
            </div>
            <div class="card-body p-4">
                <form method="POST" id="svcForm" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" id="svcf_action" value="<?php echo $openServiceForm ? 'edit' : 'add'; ?>">
                    <input type="hidden" name="id" id="svcf_id" value="<?php echo (int)($editService['id'] ?? 0); ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success"><?php echo $__t('शीर्षक (नेपाली)', 'Title (Nepali)'); ?> <span class="text-danger">*</span></label>
                            <input type="text" name="title" id="svcf_title" class="form-control admin-fancy-input" required placeholder="<?php echo $__t('सेवाको शीर्षक नेपालीमा', 'Service title in Nepali'); ?>" value="<?php echo htmlspecialchars($editService['title_np'] ?? ($editService['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">Title (English)</label>
                            <input type="text" name="title_en" id="svcf_title_en" class="form-control admin-fancy-input" placeholder="Service title in English" value="<?php echo htmlspecialchars($editService['title_en'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-success"><?php echo $__t('विवरण', 'Description'); ?></label>
                            <textarea name="description" id="svcf_desc" class="form-control admin-fancy-input" rows="3" placeholder="<?php echo $__t('सेवाको संक्षिप्त विवरण...', 'Short service description...'); ?>"><?php echo htmlspecialchars($editService['description_np'] ?? ($editService['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold text-success">आइकन क्लास (Font Awesome)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-success text-white border-success" id="svcIconPreview"><i class="<?php echo htmlspecialchars($editService['icon'] ?? 'fas fa-star', ENT_QUOTES, 'UTF-8'); ?>"></i></span>
                                <input type="text" name="icon" id="svcf_icon" class="form-control admin-fancy-input"
                                       value="<?php echo htmlspecialchars($editService['icon'] ?? 'fas fa-star', ENT_QUOTES, 'UTF-8'); ?>" placeholder="fas fa-star"
                                       oninput="document.getElementById('svcIconPreview').innerHTML='<i class=\''+this.value+'\'></i>'">
                            </div>
                            <small class="text-muted"><?php echo $__t('FontAwesome class — जस्तै', 'FontAwesome class — e.g.'); ?>: fas fa-piggy-bank, fas fa-hand-holding-usd</small>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold text-success"><?php echo $__t('मेनु श्रेणी', 'Menu Category'); ?></label>
                            <?php $curCatId = (int)($editService['service_category_id'] ?? 0); ?>
                            <select name="service_category_id" id="svcf_cat" class="form-select admin-fancy-input">
                                <option value=""><?php echo $__t('— श्रेणी छैन —', '— No Category —'); ?></option>
                                <?php foreach ($serviceCategories as $sc): ?>
                                <option value="<?php echo $sc['id']; ?>" <?php echo $curCatId == $sc['id'] ? 'selected' : ''; ?>>
                                    <?php $scLabel = (!empty($sc['name_np']) ? $sc['name_np'] : (!empty($sc['name_en']) ? $sc['name_en'] : $sc['name'])); echo htmlspecialchars($scLabel); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted"><?php echo $__t('मेगामेनुको column', 'Mega-menu column'); ?> — <a href="?tab=cats" class="text-success"><?php echo $__t('श्रेणी थप्ने', 'Add categories'); ?></a></small>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold text-success"><?php echo $__t('क्रम', 'Order'); ?></label>
                            <input type="number" name="display_order" id="svcf_order" class="form-control admin-fancy-input" value="<?php echo (int)($editService['display_order'] ?? 0); ?>" min="0">
                        </div>
                        <div class="col-md-2 d-flex align-items-end pb-1">
                            <div class="form-check form-switch fs-5">
                                <input class="form-check-input" type="checkbox" name="is_active" id="svcf_active" <?php echo !isset($editService['is_active']) || (int)$editService['is_active'] === 1 ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold" for="svcf_active"><?php echo $__t('सक्रिय', 'Active'); ?></label>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <div class="d-flex gap-3">
                        <button type="submit" id="svcf_submit" class="btn btn-success px-5 fw-semibold">
                            <i class="fas fa-plus-circle me-2"></i><?php echo $openServiceForm ? $__t('अपडेट गर्नुहोस्', 'Update') : $__t('थप्नुहोस्', 'Add'); ?>
                        </button>
                        <button type="button" id="svcf_cancel2" class="btn btn-outline-secondary px-4">
                            <i class="fas fa-times me-1"></i><?php echo $__t('रद्द', 'Cancel'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ══ TAB 3: Service Products ══ -->
    <div class="tab-pane fade" id="svc-products">
        <div class="card svc-flat-top-card">
            <div class="card-header d-flex justify-content-between align-items-center svc-form-header-grad">
                <h5 class="mb-0 fw-bold" id="svcProductFormTitle">
                    <i class="fas fa-list-check me-2"></i>Service Product थप्नुहोस्
                </h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" id="svcProductForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" id="sp_action" value="product_add">
                    <input type="hidden" name="product_id" id="sp_id" value="">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success">Service <span class="text-danger">*</span></label>
                            <select name="product_service_id" id="sp_service_id" class="form-select admin-fancy-input" required>
                                <option value="">सेवा छान्नुहोस्...</option>
                                <?php foreach ($services as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['title_np'] ?: $s['title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success">Product (नेपाली) <span class="text-danger">*</span></label>
                            <input type="text" name="product_title_np" id="sp_title_np" class="form-control admin-fancy-input" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success">Product (English)</label>
                            <input type="text" name="product_title_en" id="sp_title_en" class="form-control admin-fancy-input">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">विवरण (नेपाली)</label>
                            <textarea name="product_description_np" id="sp_desc_np" class="form-control admin-fancy-input" rows="2"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success">Description (English)</label>
                            <textarea name="product_description_en" id="sp_desc_en" class="form-control admin-fancy-input" rows="2"></textarea>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label fw-semibold text-success">क्रम</label>
                            <input type="number" name="product_display_order" id="sp_order" class="form-control admin-fancy-input" value="0" min="0">
                        </div>
                        <div class="col-md-1 d-flex align-items-end pb-1">
                            <div class="form-check form-switch fs-5">
                                <input class="form-check-input" type="checkbox" name="product_is_active" id="sp_active" checked>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-3 mt-3">
                        <button type="submit" id="sp_submit" class="btn btn-success px-4 fw-semibold">
                            <i class="fas fa-plus-circle me-2"></i>Product थप्नुहोस्
                        </button>
                        <button type="button" id="sp_cancel" class="btn btn-outline-secondary px-4">रद्द</button>
                    </div>
                </form>

                <hr class="my-4">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Service</th>
                                <th>Product</th>
                                <th>Description</th>
                                <th class="text-center">क्रम</th>
                                <th class="text-center">स्थिति</th>
                                <th class="text-center">कार्य</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($serviceProducts)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">Service products छैनन्।</td></tr>
                            <?php endif; ?>
                            <?php foreach ($serviceProducts as $sp): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(($sp['service_title_np'] ?: $sp['service_title']) ?: '—'); ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($sp['title_np']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($sp['title_en'] ?: '—'); ?></small>
                                </td>
                                <td><small class="text-muted"><?php echo htmlspecialchars(mb_substr((string)($sp['description_np'] ?: $sp['description_en'] ?: ''), 0, 80)); ?></small></td>
                                <td class="text-center"><?php echo (int)$sp['display_order']; ?></td>
                                <td class="text-center"><span class="badge bg-<?php echo (int)$sp['is_active'] ? 'success' : 'secondary'; ?>"><?php echo (int)$sp['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?></span></td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-primary me-1 btn-edit-sp"
                                            data-id="<?php echo (int)$sp['id']; ?>"
                                            data-service-id="<?php echo (int)$sp['service_id']; ?>"
                                            data-title-np="<?php echo htmlspecialchars($sp['title_np'], ENT_QUOTES); ?>"
                                            data-title-en="<?php echo htmlspecialchars((string)$sp['title_en'], ENT_QUOTES); ?>"
                                            data-desc-np="<?php echo htmlspecialchars((string)$sp['description_np'], ENT_QUOTES); ?>"
                                            data-desc-en="<?php echo htmlspecialchars((string)$sp['description_en'], ENT_QUOTES); ?>"
                                            data-order="<?php echo (int)$sp['display_order']; ?>"
                                            data-active="<?php echo (int)$sp['is_active']; ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="svc-inline-form" onsubmit="return confirm('यो product हटाउने हो?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="product_delete">
                                        <input type="hidden" name="product_id" value="<?php echo (int)$sp['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Delete" title="Delete"><i class="fas fa-trash"></i></button>
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

    <!-- ── मेनु श्रेणी (Service Categories) Tab Panel ──────────────────── -->
    <div class="tab-pane fade <?php echo $activeTab === 'cats' ? 'show active' : ''; ?>" id="svc-cats">
        <div class="card-body">
            <div class="alert alert-info py-2 mb-3 small">
                <i class="fas fa-info-circle me-1"></i>
                <?php echo $__t('यहाँ बनाएका श्रेणी Services को form मा "मेनु श्रेणी" dropdown मा देखिन्छन्। Service लाई श्रेणी assign गर्नुस् भने website को mega-menu मा त्यो श्रेणी अन्तर्गत column बन्छ।','Categories you create here appear in the service form\'s "Menu Category" dropdown. Assign a category to a service, and it shows as a column in the website mega-menu.'); ?>
            </div>

            <!-- Category Add/Edit Form -->
            <div class="card border mb-4">
                <div class="card-header bg-light py-2"><strong><?php echo $editCat ? $__t('श्रेणी सम्पादन', 'Edit Category') : $__t('नयाँ श्रेणी थप्नुहोस्', 'Add New Category'); ?></strong></div>
                <div class="card-body">
                    <form method="POST" action="services.php?tab=cats<?php echo $editCat ? '&edit_cat='.$editCat['id'] : ''; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="<?php echo $editCat ? 'cat_edit' : 'cat_add'; ?>">
                        <?php if ($editCat): ?><input type="hidden" name="cat_id" value="<?php echo $editCat['id']; ?>"><?php endif; ?>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label fw-semibold"><?php echo $__t('नाम (नेपाली) *', 'Name (Nepali) *'); ?></label>
                                <input type="text" name="cat_name_np" class="form-control" required value="<?php echo htmlspecialchars($editCat['name_np'] ?? $editCat['name'] ?? ''); ?>" placeholder="<?php echo $__t('जस्तै: सामाजिक कार्यक्रम', 'e.g. Social Programs'); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold"><?php echo $__t('नाम (अंग्रेजी)', 'Name (English)'); ?></label>
                                <input type="text" name="cat_name_en" class="form-control" value="<?php echo htmlspecialchars($editCat['name_en'] ?? ''); ?>" placeholder="e.g. Social Programs">
                                <input type="hidden" name="cat_name" value=""><!-- filled by JS below -->
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold"><?php echo $__t('आइकन (Font Awesome)', 'Icon (Font Awesome)'); ?></label>
                                <input type="text" name="cat_icon" class="form-control" value="<?php echo htmlspecialchars($editCat['icon'] ?? 'fas fa-th-large'); ?>" placeholder="fas fa-hands-helping">
                                <small class="text-muted"><a href="https://fontawesome.com/icons" target="_blank">FA Icons</a></small>
                            </div>
                            <div class="col-md-1">
                                <label class="form-label fw-semibold"><?php echo $__t('क्रम', 'Order'); ?></label>
                                <input type="number" name="cat_order" class="form-control" value="<?php echo (int)($editCat['display_order'] ?? 0); ?>" min="0">
                            </div>
                            <div class="col-md-2 d-flex align-items-end gap-2 pb-1">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="cat_is_active" id="cat_active" value="1" <?php echo ($editCat['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="cat_active"><?php echo $__t('सक्रिय', 'Active'); ?></label>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3 d-flex gap-2">
                            <button type="submit" class="btn btn-success px-4" onclick="this.form.elements['cat_name'].value = this.form.elements['cat_name_np'].value || this.form.elements['cat_name_en'].value;">
                                <i class="fas fa-save me-1"></i><?php echo $editCat ? $__t('अपडेट गर्नुहोस्', 'Update') : $__t('थप्नुहोस्', 'Add Category'); ?>
                            </button>
                            <?php if ($editCat): ?><a href="services.php?tab=cats" class="btn btn-outline-secondary"><?php echo $__t('रद्द गर्नुहोस्', 'Cancel'); ?></a><?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Category List -->
            <?php if (empty($serviceCategories)): ?>
            <div class="text-center py-4 text-muted"><i class="fas fa-layer-group fa-2x mb-2 d-block opacity-50"></i><?php echo $__t('कुनै श्रेणी छैन। माथि थप्नुहोस्।', 'No categories yet. Add one above.'); ?></div>
            <?php else: ?>
            <table class="table table-sm table-hover admin-table-card">
                <thead><tr>
                    <th><?php echo $__t('श्रेणी नाम', 'Category Name'); ?></th>
                    <th><?php echo $__t('अंग्रेजी', 'English'); ?></th>
                    <th class="text-center"><?php echo $__t('आइकन', 'Icon'); ?></th>
                    <th class="text-center"><?php echo $__t('क्रम', 'Order'); ?></th>
                    <th class="text-center"><?php echo $__t('स्थिति', 'Status'); ?></th>
                    <th class="text-center"><?php echo $__t('कार्य', 'Action'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($serviceCategories as $sc): ?>
                <tr>
                    <td><i class="<?php echo htmlspecialchars($sc['icon']); ?> me-2 text-success"></i><?php echo htmlspecialchars($sc['name_np'] ?: $sc['name']); ?></td>
                    <td><?php echo htmlspecialchars($sc['name_en'] ?? ''); ?></td>
                    <td class="text-center"><code class="small"><?php echo htmlspecialchars($sc['icon']); ?></code></td>
                    <td class="text-center"><?php echo (int)$sc['display_order']; ?></td>
                    <td class="text-center"><span class="badge <?php echo $sc['is_active'] ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $sc['is_active'] ? $__t('सक्रिय','Active') : $__t('निष्क्रिय','Inactive'); ?></span></td>
                    <td class="text-center">
                        <a href="?tab=cats&edit_cat=<?php echo $sc['id']; ?>" class="btn btn-sm btn-primary me-1"><i class="fas fa-edit"></i></a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('यो श्रेणी हटाउने? यसमा assign भएका services unlink हुन्छन्।')">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="cat_delete">
                            <input type="hidden" name="cat_id" value="<?php echo $sc['id']; ?>">
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

    var listBtn = document.getElementById('svc-list-btn');
    var formBtn = document.getElementById('svc-form-btn');

    function switchToList() { adminSwitchTab(listBtn, formBtn); }
    function switchToForm() { adminSwitchTab(formBtn, listBtn); }

    function clearForm() {
        document.getElementById('svcf_action').value  = 'add';
        document.getElementById('svcf_id').value      = '';
        document.getElementById('svcf_title').value   = '';
        document.getElementById('svcf_title_en').value= '';
        document.getElementById('svcf_desc').value    = '';
        document.getElementById('svcf_icon').value    = 'fas fa-star';
        document.getElementById('svcf_order').value   = '0';
        document.getElementById('svcf_active').checked= true;
        document.getElementById('svcIconPreview').innerHTML = '<i class="fas fa-star"></i>';
        document.getElementById('svcf_submit').innerHTML = '<i class="fas fa-plus-circle me-2"></i>थप्नुहोस्';
        document.getElementById('svcFormTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>नयाँ सेवा थप्नुहोस्';
        document.getElementById('svcFormTabLabel').textContent = 'नयाँ थप्नुहोस्';
    }

    /* v10.3 FIX: btnAddService element exist गर्दैन — null-check le JS crash रोक्छ
       (पहिले crash le .btn-edit-svc click handler attach नभएर edit काम गर्दैनथ्यो)। */
    var _btnAddSvc = document.getElementById('btnAddService');
    if (_btnAddSvc) _btnAddSvc.addEventListener('click', function() { clearForm(); switchToForm(); });

    ['btnCancelSvc','svcf_cancel2'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', function() { clearForm(); switchToList(); });
    });

    document.querySelectorAll('.btn-edit-svc').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var d = this.dataset;
            document.getElementById('svcf_action').value  = 'edit';
            document.getElementById('svcf_id').value      = d.id;
            document.getElementById('svcf_title').value   = d.title;
            document.getElementById('svcf_title_en').value= d.titleEn || '';
            document.getElementById('svcf_desc').value    = d.description || '';
            document.getElementById('svcf_icon').value    = d.icon;
            document.getElementById('svcf_order').value   = d.order;
            if (document.getElementById('svcf_cat')) document.getElementById('svcf_cat').value = d.cat_id || '';
            document.getElementById('svcf_active').checked= d.active === '1';
            document.getElementById('svcIconPreview').innerHTML = '<i class="' + d.icon + '"></i>';
            document.getElementById('svcf_submit').innerHTML = '<i class="fas fa-save me-2"></i>अपडेट गर्नुहोस्';
            document.getElementById('svcFormTitle').innerHTML = '<i class="fas fa-edit me-2"></i>सेवा सम्पादन';
            document.getElementById('svcFormTabLabel').textContent = 'सम्पादन';
            switchToForm();
        });
    });

    function clearProductForm() {
        document.getElementById('sp_action').value = 'product_add';
        document.getElementById('sp_id').value = '';
        document.getElementById('sp_service_id').value = '';
        document.getElementById('sp_title_np').value = '';
        document.getElementById('sp_title_en').value = '';
        document.getElementById('sp_desc_np').value = '';
        document.getElementById('sp_desc_en').value = '';
        document.getElementById('sp_order').value = '0';
        document.getElementById('sp_active').checked = true;
        document.getElementById('sp_submit').innerHTML = '<i class="fas fa-plus-circle me-2"></i>Product थप्नुहोस्';
        document.getElementById('svcProductFormTitle').innerHTML = '<i class="fas fa-list-check me-2"></i>Service Product थप्नुहोस्';
    }
    var _spCancel = document.getElementById('sp_cancel');
    if (_spCancel) _spCancel.addEventListener('click', clearProductForm);
    document.querySelectorAll('.btn-edit-sp').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('sp_action').value = 'product_edit';
            document.getElementById('sp_id').value = d.id;
            document.getElementById('sp_service_id').value = d.serviceId;
            document.getElementById('sp_title_np').value = d.titleNp || '';
            document.getElementById('sp_title_en').value = d.titleEn || '';
            document.getElementById('sp_desc_np').value = d.descNp || '';
            document.getElementById('sp_desc_en').value = d.descEn || '';
            document.getElementById('sp_order').value = d.order || '0';
            document.getElementById('sp_active').checked = d.active === '1';
            document.getElementById('sp_submit').innerHTML = '<i class="fas fa-save me-2"></i>Product अपडेट';
            document.getElementById('svcProductFormTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Service Product सम्पादन';
            var productsBtn = document.getElementById('svc-products-btn');
            if (productsBtn) {
                adminSwitchTab(productsBtn, document.getElementById('svc-list-btn'));
            }
        });
    });
});
</script>

<?php require_once 'includes/admin-footer.php'; ?>
