<?php
/**
 * Admin: Member Satisfaction Floating Widget Settings
 * File: admin/satisfaction-settings.php
 *
 * Admin ले यहाँबाट:
 *   - Widget enable/disable गर्न सक्छ
 *   - URLs add, edit, delete गर्न सक्छ
 *   - Icon र title set गर्न सक्छ
 *
 * Database table: satisfaction_links
 */

define('IS_ADMIN_PAGE', true);
require_once '../includes/config.php';
requireAdminLogin();

$db = getDB();

/* site_settings + satisfaction_links — includes/ensure-tables + helpers (admin-header बिना पनि) */
require_once __DIR__ . '/../includes/ensure-tables.php';
require_once __DIR__ . '/../includes/satisfaction-links-tables.php';
ensurePublicTables();
ensureSatisfactionLinksTables($db);

/* -------------------------------------------------------
   POST Handler — form submit भएको process गर्नुहोस्

   NOTE: यो page मा admin-header.php POST पछि include हुन्छ,
   त्यसैले यहाँ manually CSRF verify गरिन्छ।
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* CSRF check — security */
    if (!verifyCSRFToken()) {
        setFlash('error', 'Security check fail भयो। कृपया फेरि try गर्नुहोस्।');
        redirect('satisfaction-settings.php');
    }

    $action = clean_text($_POST['action'] ?? '');

    /* Widget enable/disable toggle */
    if ($action === 'toggle_widget') {
        $enabled = isset($_POST['widget_enabled']) ? '1' : '0';
        /* updateSetting() — site_settings table मा save हुन्छ
           यही table बाट public page मा widget enable/disable हुन्छ */
        updateSetting('satisfaction_widget_enabled', $enabled);
        setFlash('success', $enabled === '1'
            ? 'Widget Enable भयो — public page मा देखिनेछ।'
            : 'Widget Disable भयो — public page मा देखिनेछैन।');
        redirect('satisfaction-settings.php');
    }

    /* नयाँ link थप्नुहोस् */
    if ($action === 'add_link') {
        $title    = clean_text($_POST['title'] ?? '');
        $titleEn  = clean_text($_POST['title_en'] ?? '');
        $url      = clean_text($_POST['url'] ?? '');
        $icon     = clean_text($_POST['icon'] ?? 'fas fa-link');
        $order    = (int)($_POST['display_order'] ?? 0);

        if ($title && $url) {
            $stmt = $db->prepare("INSERT INTO satisfaction_links (title, title_en, url, icon, display_order) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$title, $titleEn, $url, $icon, $order]);
            setFlash('success', 'नयाँ link थपियो।');
        } else {
            setFlash('error', 'Title र URL अनिवार्य छ।');
        }
        redirect('satisfaction-settings.php');
    }

    /* Link update गर्नुहोस् */
    if ($action === 'update_link') {
        $id       = (int)($_POST['id'] ?? 0);
        $title    = clean_text($_POST['title'] ?? '');
        $titleEn  = clean_text($_POST['title_en'] ?? '');
        $url      = clean_text($_POST['url'] ?? '');
        $icon     = clean_text($_POST['icon'] ?? 'fas fa-link');
        $isActive = (int)($_POST['is_active'] ?? 0);
        $order    = (int)($_POST['display_order'] ?? 0);

        if ($id && $title && $url) {
            $stmt = $db->prepare("UPDATE satisfaction_links SET title=?, title_en=?, url=?, icon=?, is_active=?, display_order=? WHERE id=?");
            $stmt->execute([$title, $titleEn, $url, $icon, $isActive, $order, $id]);
            setFlash('success', 'Link अपडेट भयो।');
        }
        redirect('satisfaction-settings.php');
    }

    /* Link delete गर्नुहोस् */
    if ($action === 'delete_link') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare("DELETE FROM satisfaction_links WHERE id = ?")->execute([$id]);
            setFlash('success', 'Link हटाइयो।');
        }
        redirect('satisfaction-settings.php');
    }
}

/* -------------------------------------------------------
   Data load गर्नुहोस्
------------------------------------------------------- */
// Current widget enabled/disabled status
$widgetEnabled = getSetting('satisfaction_widget_enabled', '0') == '1';

// All links
$links = [];
try {
    $linksStmt = $db->query("SELECT * FROM satisfaction_links ORDER BY display_order ASC, id ASC");
    $links = $linksStmt ? $linksStmt->fetchAll() : [];
} catch (Exception $e) {
    $links = [];
}

// Edit mode — URL मा ?edit=ID आएको छ भने
$editLink = null;
if (!empty($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    foreach ($links as $l) {
        if ((int)$l['id'] === $editId) {
            $editLink = $l;
            break;
        }
    }
}
$panel = (string)($_GET['panel'] ?? 'list');
if ($editLink) {
    $panel = 'form';
}
if (!in_array($panel, ['list', 'form'], true)) {
    $panel = 'list';
}

$pageTitle = 'सन्तुष्टि Widget सेटिङ्स';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
?>

<div class="container-fluid py-4">

    <!-- Page Header -->
    <?php
    echo adminPageHeader('सन्तुष्टि Widget','fa-smile','सदस्य सन्तुष्टि survey widget सेटिङ्स।');
    if ($flash = getFlash()):
    ?>
    <div class="alert alert-<?php echo $flash['type']==='success'?'success':'danger'; ?> alert-dismissible fade show mb-3"><i class="fas fa-<?php echo $flash['type']==='success'?'check-circle':'exclamation-circle'; ?> me-2"></i><?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <ul class="nav admin-nav-tabs mb-4" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $panel === 'list' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#satisfaction-list-tab" type="button" role="tab">
                <i class="fas fa-list me-1"></i> सूची
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $panel === 'form' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#satisfaction-form-tab" type="button" role="tab">
                <i class="fas fa-plus me-1"></i> नयाँ थप्नुहोस्
            </button>
        </li>
    </ul>

    <div class="tab-content satisfaction-settings-page">
        <div class="tab-pane fade <?php echo $panel === 'list' ? 'show active' : ''; ?>" id="satisfaction-list-tab" role="tabpanel">
            <!-- ── Widget Enable/Disable Toggle ─────────────────────────────── -->
            <div class="card mb-4 border-<?php echo $widgetEnabled ? 'success' : 'secondary'; ?>">
                <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-status-icon <?php echo $widgetEnabled ? 'is-active' : 'is-inactive'; ?>">
                            <i class="fas fa-smile"></i>
                        </div>
                        <div>
                            <h6 class="mb-0 fw-bold">
                                Widget Status:
                                <?php if ($widgetEnabled): ?>
                                    <span class="badge bg-success ms-1"><i class="fas fa-check-circle me-1"></i>Active — Frontend मा देखिँदैछ</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary ms-1"><i class="fas fa-times-circle me-1"></i>Inactive — Frontend मा देखिँदैन</span>
                                <?php endif; ?>
                            </h6>
                            <small class="text-muted">
                                <?php echo $widgetEnabled
                                    ? 'Widget enable छ — website को दाया side मा floating icon देखिन्छ।'
                                    : 'Widget disable छ — Enable गर्नुहोस् भने floating icon देखिनेछ।'; ?>
                            </small>
                        </div>
                    </div>
                    <form method="POST" action="" class="mb-0">
                        <input type="hidden" name="action" value="toggle_widget">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <?php if ($widgetEnabled): ?>
                            <button type="submit" class="btn btn-outline-danger">
                                <i class="fas fa-toggle-on fa-flip-horizontal me-2"></i>Widget Disable गर्नुहोस्
                            </button>
                        <?php else: ?>
                            <input type="hidden" name="widget_enabled" value="1">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-toggle-on me-2"></i>Widget Enable गर्नुहोस्
                            </button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between">
                    <h5 class="mb-0"><i class="fas fa-list me-2"></i>Links सूची</h5>
                    <span class="badge bg-primary"><?php echo count($links); ?> links</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($links)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-link fa-2x mb-2 d-block"></i>
                        अझै कुनै link थपिएको छैन।
                        <br><small>नयाँ थप्नुहोस् tab बाट थप्नुहोस्।</small>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Icon</th>
                                    <th>शीर्षक</th>
                                    <th>URL</th>
                                    <th>स्थिति</th>
                                    <th>क्रम</th>
                                    <th>कारबाही</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($links as $link): ?>
                                <tr>
                                    <td class="text-center">
                                        <i class="satisfaction-link-icon <?php echo htmlspecialchars($link['icon'] ?? 'fas fa-link'); ?>"></i>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($link['title']); ?></strong>
                                        <?php if ($link['title_en']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($link['title_en']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo htmlspecialchars($link['url']); ?>" target="_blank"
                                           class="text-truncate d-inline-block" style="max-width:150px;"
                                           title="<?php echo htmlspecialchars($link['url']); ?>">
                                            <?php echo htmlspecialchars($link['url']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php if ($link['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo (int)$link['display_order']; ?></td>
                                    <td>
                                        <a href="?edit=<?php echo $link['id']; ?>&panel=form"
                                           class="btn btn-sm btn-primary me-1">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form method="POST" action="" class="d-inline"
                                              onsubmit="return confirm('यो link हटाउनुहोस्?');">
                                            <input type="hidden" name="action" value="delete_link">
                                            <input type="hidden" name="id" value="<?php echo $link['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Delete" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="alert alert-info mt-3">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Preview:</strong> Widget frontend मा page को दाया side मा middle मा floating icon को रूपमा देखिन्छ।
                Hover गर्दा links देखिन्छन्। Widget enable र कम्तिमा एउटा active link भएमा मात्र देखिन्छ।
            </div>
        </div>

        <div class="tab-pane fade <?php echo $panel === 'form' ? 'show active' : ''; ?>" id="satisfaction-form-tab" role="tabpanel">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-<?php echo $editLink ? 'edit' : 'plus-circle'; ?> me-2"></i>
                        <?php echo $editLink ? 'Link सम्पादन गर्नुहोस्' : 'नयाँ Link थप्नुहोस्'; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="<?php echo $editLink ? 'update_link' : 'add_link'; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <?php if ($editLink): ?>
                            <input type="hidden" name="id" value="<?php echo $editLink['id']; ?>">
                        <?php endif; ?>

                        <!-- Nepali Title (अनिवार्य) -->
                        <div class="mb-3">
                            <label class="form-label">नेपाली शीर्षक <span class="text-danger">*</span></label>
                            <!-- जस्तै: "सदस्य सर्वेक्षण" -->
                            <input type="text" name="title" class="form-control"
                                   placeholder="जस्तै: सदस्य सर्वेक्षण"
                                   value="<?php echo htmlspecialchars($editLink['title'] ?? ''); ?>" required>
                        </div>

                        <!-- English Title (optional) -->
                        <div class="mb-3">
                            <label class="form-label">अंग्रेजी शीर्षक <small class="text-muted">(optional)</small></label>
                            <input type="text" name="title_en" class="form-control"
                                   placeholder="e.g. Member Survey"
                                   value="<?php echo htmlspecialchars($editLink['title_en'] ?? ''); ?>">
                        </div>

                        <!-- URL (अनिवार्य) -->
                        <div class="mb-3">
                            <label class="form-label">URL / Link <span class="text-danger">*</span></label>
                            <!-- Full URL वा relative path दुवै हुन्छ -->
                            <input type="url" name="url" class="form-control"
                                   placeholder="https://forms.google.com/..."
                                   value="<?php echo htmlspecialchars($editLink['url'] ?? ''); ?>" required>
                            <small class="text-muted">यो link नयाँ tab मा खुल्छ।</small>
                        </div>

                        <!-- Icon -->
                        <div class="mb-3">
                            <label class="form-label">Icon <small class="text-muted">(FontAwesome class)</small></label>
                            <div class="input-group">
                                <span class="input-group-text" id="iconPreview">
                                    <i class="<?php echo htmlspecialchars($editLink['icon'] ?? 'fas fa-link'); ?>" id="previewIcon"></i>
                                </span>
                                <input type="text" name="icon" class="form-control" id="iconInput"
                                       placeholder="fas fa-smile"
                                       value="<?php echo htmlspecialchars($editLink['icon'] ?? 'fas fa-link'); ?>"
                                       oninput="document.getElementById('previewIcon').className=this.value">
                            </div>
                            <small class="text-muted">
                                सामान्य icons: <code>fas fa-smile</code>, <code>fas fa-star</code>,
                                <code>fas fa-poll</code>, <code>fas fa-heart</code>
                            </small>
                        </div>

                        <!-- Display Order -->
                        <div class="mb-3">
                            <label class="form-label">क्रम (Display Order)</label>
                            <!-- सानो नम्बर पहिले देखिन्छ -->
                            <input type="number" name="display_order" class="form-control"
                                   value="<?php echo (int)($editLink['display_order'] ?? 0); ?>" min="0">
                        </div>

                        <?php if ($editLink): ?>
                        <!-- Active/Inactive toggle for edit mode -->
                        <div class="mb-3 form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="isActive"
                                   <?php echo ($editLink['is_active'] ?? 1) ? 'checked' : ''; ?>
                                   style="width:40px;height:20px;">
                            <label class="form-check-label ms-2" for="isActive">Active (frontend मा देखिन्छ)</label>
                        </div>
                        <?php endif; ?>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i>
                                <?php echo $editLink ? 'Update गर्नुहोस्' : 'थप्नुहोस्'; ?>
                            </button>
                            <?php if ($editLink): ?>
                            <a href="satisfaction-settings.php?panel=list" class="btn btn-outline-secondary">रद्द गर्नुहोस्</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        </div>
    </div>

</div>

<?php require_once 'includes/admin-footer.php'; ?>
