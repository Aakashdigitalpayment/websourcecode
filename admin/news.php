<?php
/**
 * समाचार व्यवस्थापन — News Management
 * Tab UI: List tab + Add/Edit form tab (modal popup हटाइएको)
 */
$pageTitle = 'समाचार व्यवस्थापन';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$db = getDB();

/* CSRF सुरक्षा: POST अनुरोध प्रमाणित गर्नुहोस् */
checkCSRF();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add' || $action === 'edit') {
            $id         = $_POST['id'] ?? null;
            $title      = clean_text($_POST['title']    ?? '');
            $title_np   = clean_text($_POST['title_np'] ?? '');
            $content    = $_POST['content']    ?? '';
            $content_np = $_POST['content_np'] ?? '';
            $is_active  = isset($_POST['is_active']) ? 1 : 0;
            $image      = $_POST['existing_image'] ?? '';

            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $upload = uploadFile($_FILES['image'], 'news');
                if ($upload['success']) $image = $upload['path'];
            }

            if ($action === 'add') {
                $db->prepare("INSERT INTO news (title, title_np, content, content_np, image, is_active) VALUES (?,?,?,?,?,?)")
                   ->execute([$title, $title_np, $content, $content_np, $image, $is_active]);
                setFlash('success', 'समाचार सफलतापूर्वक थपियो।');
            } else {
                $db->prepare("UPDATE news SET title=?, title_np=?, content=?, content_np=?, image=?, is_active=? WHERE id=?")
                   ->execute([$title, $title_np, $content, $content_np, $image, $is_active, $id]);
                setFlash('success', 'समाचार सफलतापूर्वक अपडेट भयो।');
            }
        } elseif ($action === 'delete') {
            $db->prepare("DELETE FROM news WHERE id=?")->execute([$_POST['id']]);
            setFlash('success', 'समाचार मेटाइयो।');
        } elseif ($action === 'bulk_status') {
            $bulk = clean_text($_POST['bulk'] ?? '');
            $selected = $_POST['selected_ids'] ?? [];
            $ids = array_values(array_filter(array_map('intval', (array)$selected), fn($v) => $v > 0));
            if (empty($ids) || !in_array($bulk, ['active','inactive'], true)) {
                setFlash('error', 'Bulk update का लागि rows छान्नुहोस्।');
            } else {
                $target = $bulk === 'active' ? 1 : 0;
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $st = $db->prepare("UPDATE news SET is_active = ? WHERE id IN ($ph)");
                $st->execute(array_merge([$target], $ids));
                setFlash('success', 'Bulk status update सफल भयो।');
            }
        }
    } catch (Exception $e) {
        setFlash('error', 'त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।');
    }
    redirect('news.php');
}

try { $news = $db->query("SELECT * FROM news ORDER BY created_at DESC")->fetchAll(); }
catch (Exception $e) { $news = []; }
$newsPart = adminPartitionRowsByIsActive($news);
$newsLive = $newsPart['live'];
$newsArch = $newsPart['archived'];
?>

<?php echo adminPageHeader(
    'समाचार व्यवस्थापन',
    'fa-newspaper',
    'संस्थाका समाचार र गतिविधिहरू।',
    '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25 me-2"><i class="fas fa-layer-group me-1"></i>जम्मा: ' . count($news) . '</span>'
    . '<span class="badge admin-stat-badge bg-primary-subtle text-primary border border-primary border-opacity-25 me-2"><i class="fas fa-check-circle me-1"></i>सक्रिय: ' . count($newsLive) . '</span>'
    . '<span class="badge admin-stat-badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25"><i class="fas fa-archive me-1"></i>अभिलेख: ' . count($newsArch) . '</span>'
);
?>
<?php echo adminHelpTip('यो पृष्ठबाट संस्थाका समाचार र गतिविधि थप्न, सम्पादन गर्न सकिन्छ।', ['समाचार थप्न: "+" बटन थिच्नुहोस्।', 'Photo: JPEG/PNG format, 1MB भन्दा कम राख्नुहोस्।', 'Publish गर्न: form भर्दा "Active" छनोट गर्नुहोस्।']); ?>

<?php $flash = getFlash(); if ($flash): ?>
<div class="alert alert-<?php echo $flash['type']==='success'?'success':'danger'; ?> alert-dismissible fade show mb-3">
    <i class="fas fa-<?php echo $flash['type']==='success'?'check-circle':'exclamation-circle'; ?> me-2"></i>
    <?php echo htmlspecialchars($flash['message']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Tab Navigation -->
<ul class="nav nav-tabs admin-nav-tabs mb-0" id="newsTabs">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#news-list" id="tab-list-btn" title="सक्रिय / जम्मा">
            <i class="fas fa-list me-2"></i>समाचार सूची
            <span class="badge bg-success ms-1"><?php echo count($newsLive); ?> / <?php echo count($news); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#news-form" id="tab-form-btn">
            <i class="fas fa-plus-circle me-2"></i><span id="newsFormTabLabel">नयाँ थप्नुहोस्</span>
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- ══ TAB 1: सूची ══ -->
    <div class="tab-pane fade show active" id="news-list">
        <div class="card admin-table-card svc-flat-top-card">

            <!-- खोज बक्स — client-side filter -->
            <div class="admin-search-wrap px-3 py-2 border-bottom bg-light d-flex align-items-center gap-3 svc-search-wrap">
                <div class="input-group input-group-sm svc-search-group">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 admin-table-search" placeholder="नाम, विवरण अनुसार खोज्नुहोस्..." autocomplete="off">
                </div>
                <small class="text-muted search-count"></small>
            </div>
            <div class="card-body p-0">
                    <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="bulk_status">
                    <div class="px-3 py-2 border-bottom bg-light d-flex justify-content-end gap-2">
                        <button type="submit" name="bulk" value="active" class="btn btn-sm btn-outline-success">Bulk Active</button>
                        <button type="submit" name="bulk" value="inactive" class="btn btn-sm btn-outline-secondary">Bulk Inactive</button>
                    </div>
                    <?php echo adminListSubtabPills('news-sub', count($newsLive), count($newsArch)); ?>
                    <div class="tab-content admin-table-subtab-content">
                    <div class="tab-pane fade show active" id="news-sub-live" role="tabpanel">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th width="40" class="text-center"><input type="checkbox" onclick="document.querySelectorAll('#news-sub-live .news-select').forEach(c=>c.checked=this.checked)"></th>
                                <th class="ps-3" width="70">छवि</th>
                                <th>शीर्षक</th>
                                <th width="120">मिति</th>
                                <th width="90" class="text-center">स्थिति</th>
                                <th width="140" class="text-center">कार्य</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($news)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">
                                <i class="fas fa-newspaper fa-3x mb-2 d-block opacity-25"></i>
                                कुनै समाचार छैन। माथिको "नयाँ थप्नुहोस्" बटन थिच्नुहोस्।
                            </td></tr>
                            <?php elseif (empty($newsLive)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">
                                <i class="fas fa-check-circle fa-3x mb-2 d-block opacity-25 text-success"></i>
                                सक्रिय समाचार छैन। अभिलेख हेर्नुहोस्।
                            </td></tr>
                            <?php endif; ?>
                            <?php foreach ($newsLive as $n): ?>
                            <tr>
                                <td class="text-center"><input type="checkbox" class="news-select" name="selected_ids[]" value="<?php echo (int)$n['id']; ?>"></td>
                                <td class="ps-3">
                                    <?php if ($n['image']): ?>
                                    <img src="../<?php echo htmlspecialchars($n['image']); ?>" class="news-thumb-img">
                                    <?php else: ?>
                                    <div class="news-thumb-placeholder"><i class="fas fa-newspaper text-success"></i></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($n['title_np'] ?: $n['title']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars(mb_substr($n['content_np'] ?: $n['content'] ?: '', 0, 60)); ?>…</small>
                                </td>
                                <td><small class="text-muted"><?php echo isset($n['created_at']) ? date('Y-m-d', strtotime($n['created_at'])) : ''; ?></small></td>
                                <td class="text-center">
                                    <span class="badge bg-<?php echo $n['is_active'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $n['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary me-1 btn-edit-news"
                                            data-id="<?php echo $n['id']; ?>"
                                            data-title="<?php echo htmlspecialchars($n['title'], ENT_QUOTES); ?>"
                                            data-title-np="<?php echo htmlspecialchars($n['title_np'] ?? '', ENT_QUOTES); ?>"
                                            data-content="<?php echo htmlspecialchars($n['content'] ?? '', ENT_QUOTES); ?>"
                                            data-content-np="<?php echo htmlspecialchars($n['content_np'] ?? '', ENT_QUOTES); ?>"
                                            data-image="<?php echo htmlspecialchars($n['image'] ?? '', ENT_QUOTES); ?>"
                                            data-active="<?php echo $n['is_active']; ?>"
                                            title="सम्पादन">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="svc-inline-form" onsubmit="return confirm('के तपाईं यो समाचार मेटाउन निश्चित हुनुहुन्छ?')">
    <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $n['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="मेटाउनुहोस्"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    </div>
                    <div class="tab-pane fade" id="news-sub-arch" role="tabpanel">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th width="40" class="text-center"><input type="checkbox" onclick="document.querySelectorAll('#news-sub-arch .news-select').forEach(c=>c.checked=this.checked)"></th>
                                <th class="ps-3" width="70">छवि</th>
                                <th>शीर्षक</th>
                                <th width="120">मिति</th>
                                <th width="90" class="text-center">स्थिति</th>
                                <th width="140" class="text-center">कार्य</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($newsArch)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">
                                <i class="fas fa-folder-open fa-3x mb-2 d-block opacity-25"></i>
                                अभिलेखमा कुनै समाचार छैन।
                            </td></tr>
                            <?php endif; ?>
                            <?php foreach ($newsArch as $n): ?>
                            <tr>
                                <td class="text-center"><input type="checkbox" class="news-select" name="selected_ids[]" value="<?php echo (int)$n['id']; ?>"></td>
                                <td class="ps-3">
                                    <?php if ($n['image']): ?>
                                    <img src="../<?php echo htmlspecialchars($n['image']); ?>" class="news-thumb-img">
                                    <?php else: ?>
                                    <div class="news-thumb-placeholder"><i class="fas fa-newspaper text-success"></i></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($n['title_np'] ?: $n['title']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars(mb_substr($n['content_np'] ?: $n['content'] ?: '', 0, 60)); ?>…</small>
                                </td>
                                <td><small class="text-muted"><?php echo isset($n['created_at']) ? date('Y-m-d', strtotime($n['created_at'])) : ''; ?></small></td>
                                <td class="text-center">
                                    <span class="badge bg-<?php echo $n['is_active'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $n['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary me-1 btn-edit-news"
                                            data-id="<?php echo $n['id']; ?>"
                                            data-title="<?php echo htmlspecialchars($n['title'], ENT_QUOTES); ?>"
                                            data-title-np="<?php echo htmlspecialchars($n['title_np'] ?? '', ENT_QUOTES); ?>"
                                            data-content="<?php echo htmlspecialchars($n['content'] ?? '', ENT_QUOTES); ?>"
                                            data-content-np="<?php echo htmlspecialchars($n['content_np'] ?? '', ENT_QUOTES); ?>"
                                            data-image="<?php echo htmlspecialchars($n['image'] ?? '', ENT_QUOTES); ?>"
                                            data-active="<?php echo $n['is_active']; ?>"
                                            title="सम्पादन">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="svc-inline-form" onsubmit="return confirm('के तपाईं यो समाचार मेटाउन निश्चित हुनुहुन्छ?')">
    <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $n['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="मेटाउनुहोस्"><i class="fas fa-trash"></i></button>
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
    <div class="tab-pane fade" id="news-form">
        <div class="card svc-flat-top-card">
            <div class="card-header d-flex justify-content-between align-items-center svc-form-header-grad">
                <h5 class="mb-0 fw-bold" id="newsFormTitle">
                    <i class="fas fa-plus-circle me-2"></i>नयाँ समाचार थप्नुहोस्
                </h5>
                <button type="button" class="btn btn-light btn-sm" id="btnCancelNews">
                    <i class="fas fa-arrow-left me-1"></i>सूचीमा फर्कनुहोस्
                </button>
            </div>
            <div class="card-body p-4">
                <form method="POST" enctype="multipart/form-data" id="newsForm" class="needs-validation" novalidate>
    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" id="nf_action" value="add">
                    <input type="hidden" name="id" id="nf_id" value="">
                    <input type="hidden" name="existing_image" id="nf_img" value="">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">
                                <i class="fas fa-language me-1"></i>शीर्षक (नेपाली) <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="title_np" id="nf_title_np" class="form-control admin-fancy-input" required placeholder="समाचारको शीर्षक नेपालीमा">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">
                                <i class="fas fa-globe me-1"></i>Title (English)
                            </label>
                            <input type="text" name="title" id="nf_title" class="form-control admin-fancy-input" placeholder="News title in English">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">
                                <i class="fas fa-align-left me-1"></i>विवरण (नेपाली)
                            </label>
                            <textarea name="content_np" id="nf_content_np" class="form-control admin-fancy-input" rows="6" placeholder="समाचारको विवरण नेपालीमा..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">
                                <i class="fas fa-align-left me-1"></i>Content (English)
                            </label>
                            <textarea name="content" id="nf_content" class="form-control admin-fancy-input" rows="6" placeholder="News content in English..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">
                                <i class="fas fa-image me-1"></i>छवि (Image)
                                <small class="text-muted fw-normal" id="nf_img_note"></small>
                            </label>
                            <input type="file" name="image" class="form-control admin-fancy-input" accept="image/*" id="nf_img_file">
                            <div id="nf_img_preview" class="mt-2"></div>
                        </div>
                        <div class="col-md-6 d-flex align-items-center">
                            <div class="form-check form-switch fs-5 mt-3">
                                <input class="form-check-input" type="checkbox" name="is_active" id="nf_active" checked>
                                <label class="form-check-label fw-semibold" for="nf_active">सक्रिय</label>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <div class="d-flex gap-3">
                        <button type="submit" id="nf_submit" class="btn btn-success px-5 fw-semibold">
                            <i class="fas fa-plus-circle me-2"></i>थप्नुहोस्
                        </button>
                        <button type="button" id="nf_cancel2" class="btn btn-outline-secondary px-4">
                            <i class="fas fa-times me-1"></i>रद्द
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div><!-- end tab-content -->

<script>
document.addEventListener('DOMContentLoaded', function() {

    var tabListBtn = document.getElementById('tab-list-btn');
    var tabFormBtn = document.getElementById('tab-form-btn');

    function switchToList() {
        adminSwitchTab(tabListBtn, tabFormBtn);
    }
    function switchToForm() {
        adminSwitchTab(tabFormBtn, tabListBtn);
    }

    function clearForm() {
        document.getElementById('nf_action').value     = 'add';
        document.getElementById('nf_id').value         = '';
        document.getElementById('nf_img').value        = '';
        document.getElementById('nf_title').value      = '';
        document.getElementById('nf_title_np').value   = '';
        document.getElementById('nf_content').value    = '';
        document.getElementById('nf_content_np').value = '';
        document.getElementById('nf_active').checked   = true;
        document.getElementById('nf_img_preview').innerHTML = '';
        document.getElementById('nf_img_note').textContent  = '';
        document.getElementById('nf_submit').innerHTML = '<i class="fas fa-plus-circle me-2"></i>थप्नुहोस्';
        document.getElementById('newsFormTitle').innerHTML  = '<i class="fas fa-plus-circle me-2"></i>नयाँ समाचार थप्नुहोस्';
        document.getElementById('newsFormTabLabel').textContent = 'नयाँ थप्नुहोस्';
        try { document.getElementById('nf_img_file').value = ''; } catch(e) {}
    }

    /* Edit mode flag — edit गर्दा tab switch हुँदा form clear नहोस् */
    var _isEditMode = false;
    if (tabFormBtn) tabFormBtn.addEventListener('show.bs.tab', function() {
        if (!_isEditMode) clearForm();
        _isEditMode = false;
    });

    /* Cancel बटनहरू */
    ['btnCancelNews','nf_cancel2'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', function() { clearForm(); switchToList(); });
    });

    /* Edit बटनहरू — Tab 2 मा switch गरेर form pre-fill गर्नुहोस् */
    document.querySelectorAll('.btn-edit-news').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('nf_action').value     = 'edit';
            document.getElementById('nf_id').value         = d.id;
            document.getElementById('nf_title').value      = d.title || '';
            document.getElementById('nf_title_np').value   = d.titleNp || '';
            document.getElementById('nf_content').value    = d.content || '';
            document.getElementById('nf_content_np').value = d.contentNp || '';
            document.getElementById('nf_img').value        = d.image || '';
            document.getElementById('nf_active').checked   = d.active === '1';

            var prev = document.getElementById('nf_img_preview');
            prev.innerHTML = d.image
                ? '<img src="../' + d.image + '" class="news-preview-img">'
                : '';
            document.getElementById('nf_img_note').textContent = d.image
                ? ' — नयाँ फोटो नचुने भने पुरानै रहन्छ'
                : '';
            document.getElementById('nf_submit').innerHTML = '<i class="fas fa-save me-2"></i>अपडेट गर्नुहोस्';
            document.getElementById('newsFormTitle').innerHTML  = '<i class="fas fa-edit me-2"></i>समाचार सम्पादन';
            document.getElementById('newsFormTabLabel').textContent = 'सम्पादन';
            _isEditMode = true;
            switchToForm();
        });
    });
});
</script>

<?php require_once 'includes/admin-footer.php'; ?>
