<?php
/**
 * ग्यालरी व्यवस्थापन — Gallery Management
 * Tab UI: ग्यालरी सूची | फोटो अपलोड | भिडियो थप्नुहोस्
 * Multiple image upload + YouTube video support
 */
$pageTitle = 'ग्यालरी व्यवस्थापन';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$db = getDB();

/* CSRF सुरक्षा: POST अनुरोध प्रमाणित गर्नुहोस् */
checkCSRF();
$action = $_POST['action'] ?? '';
$id     = intval($_POST['id'] ?? 0) ?: null;

/* ── फोटो / भिडियो अपलोड ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'delete') {
    $title    = clean_text($_POST['title']      ?? '');
    $title_np = clean_text($_POST['title_np']   ?? $title);
    $category = clean_text($_POST['category']   ?? 'general');
    $mediaType = clean_text($_POST['media_type'] ?? 'photo');
    $videoUrl  = clean_text($_POST['video_url'] ?? '');
    $isActive  = isset($_POST['is_active']) ? 1 : 0;

    try {
        /* media_type column check */
        $hasMediaType = false;
        try {
            $chk = $db->query("SHOW COLUMNS FROM gallery LIKE 'media_type'");
            $hasMediaType = $chk && $chk->fetch() !== false;
        } catch (Throwable $e) { error_log("[gallery.php] " . $e->getMessage()); }

        /* YouTube भिडियो */
        if ($mediaType === 'video' && !empty($videoUrl)) {
            $thumb = '';
            if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $videoUrl, $m)) {
                $thumb = 'https://img.youtube.com/vi/' . $m[1] . '/maxresdefault.jpg';
            }
            if ($hasMediaType) {
                $db->prepare("INSERT INTO gallery (title, title_np, image, media_type, video_url, thumbnail, category, is_active) VALUES (?,?,'','video',?,?,?,?)")
                   ->execute([$title, $title_np, $videoUrl, $thumb, $category, $isActive]);
            } else {
                $db->prepare("INSERT INTO gallery (title, image, category, is_active) VALUES (?,?,?,?)")
                   ->execute([$title . ' (Video)', $thumb ?: '', $category, $isActive]);
            }
            setFlash('success', 'भिडियो थपियो।');
            redirect('gallery.php');
        }

        /* Multiple image upload */
        if (isset($_FILES['images']) && $_FILES['images']['error'][0] !== UPLOAD_ERR_NO_FILE) {
            $files = $_FILES['images'];
            $count = 0;
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $file = ['name'=>$files['name'][$i],'type'=>$files['type'][$i],'tmp_name'=>$files['tmp_name'][$i],'error'=>$files['error'][$i],'size'=>$files['size'][$i]];
                    $up = uploadFile($file, 'gallery');
                    if ($up['success']) {
                        $t = $title ?: ('Gallery ' . ($i + 1));
                        if ($hasMediaType) {
                            $db->prepare("INSERT INTO gallery (title, title_np, image, media_type, category, is_active) VALUES (?,?,?,'photo',?,?)")
                               ->execute([$t, $title_np, $up['path'], $category, $isActive]);
                        } else {
                            $db->prepare("INSERT INTO gallery (title, image, category, is_active) VALUES (?,?,?,?)")
                               ->execute([$t, $up['path'], $category, $isActive]);
                        }
                        $count++;
                    }
                }
            }
            if ($count > 0) setFlash('success', $count . ' तस्विर(हरू) अपलोड भयो।');
        }
        redirect('gallery.php');
    } catch (Exception $e) {
        setFlash('error', 'त्रुटि भयो।');
        redirect('gallery.php');
    }
}

/* ── मेटाउने ── */
if ($action === 'delete' && $id) {
    try {
        $stmt = $db->prepare("SELECT image FROM gallery WHERE id=?");
        $stmt->execute([$id]);
        $item = $stmt->fetch();
        if ($item) {
            deleteFile($item['image']);
            $db->prepare("DELETE FROM gallery WHERE id=?")->execute([$id]);
            setFlash('success', 'तस्विर मेटाइयो।');
        }
    } catch (Exception $e) { setFlash('error', 'त्रुटि भयो।'); }
    redirect('gallery.php');
}

/* ── सबै तस्विरहरू लोड गर्ने ── */
try { $images = $db->query("SELECT * FROM gallery ORDER BY id DESC")->fetchAll(); }
catch (Exception $e) { $images = []; }

$flash = getFlash();
?>

<?php echo adminPageHeader(
    'ग्यालरी व्यवस्थापन',
    'fa-images',
    'फोटो अपलोड तथा व्यवस्थापन।',
    '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25 me-2"><i class="fas fa-layer-group me-1"></i>जम्मा: ' . count($images) . ' तस्बिरहरू</span>'
);
?>
<?php echo adminHelpTip('यो पृष्ठबाट Photo Gallery मा तस्बिरहरू थप्न र हटाउन सकिन्छ।', ['Photo थप्न: "Photo Upload" form मा file छान्नुहोस्।', 'Multiple photos: एकैपटक धेरै photos छान्न सकिन्छ।', 'Photo हटाउन: तस्बिरको Delete बटन थिच्नुहोस्।']); ?>

<?php if ($flash && $flash['type'] === 'success'): ?>
<div class="alert alert-success alert-dismissible fade show mb-3"><i class="fas fa-check-circle me-2"></i><?php echo $flash['message']; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php elseif ($flash && $flash['type'] === 'error'): ?>
<div class="alert alert-danger alert-dismissible fade show mb-3"><i class="fas fa-exclamation-circle me-2"></i><?php echo $flash['message']; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<ul class="nav nav-tabs admin-nav-tabs mb-3">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#gal-list"><i class="fas fa-images me-2"></i>ग्यालरी <span class="badge bg-success ms-1"><?php echo count($images); ?></span></button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#gal-photo" id="gal-photo-tab"><i class="fas fa-camera me-2"></i>फोटो अपलोड</button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#gal-video"><i class="fab fa-youtube me-2 gal-yt-icon"></i>भिडियो थप्नुहोस्</button>
    </li>
</ul>

<div class="tab-content">
    <!-- GALLERY GRID TAB -->
    <div class="tab-pane fade show active" id="gal-list">
            <div class="admin-search-wrap px-3 py-2 border-bottom bg-light d-flex align-items-center gap-3">
                <div class="input-group input-group-sm gal-search-group">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 admin-gallery-search" placeholder="शीर्षक वा वर्गले खोज्नुहोस्..." autocomplete="off">
                </div>
            </div>
        <div class="card admin-table-card">
            <div class="card-body">
                <?php if (empty($images)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-images fa-4x mb-3 opacity-25"></i>
                    <p>कुनै तस्विर छैन। "फोटो अपलोड" ट्याब प्रयोग गरी अपलोड गर्नुहोस्।</p>
                </div>
                <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($images as $img): ?>
                    <div class="col-lg-2 col-md-3 col-sm-4 col-6 gallery-card-wrap">
                        <div class="gallery-card position-relative">
                            <?php
                            $isVideo = ($img['media_type'] ?? 'photo') === 'video';
                            $thumbSrc = $isVideo
                                ? ($img['thumbnail'] ?? 'assets/images/video-placeholder.png')
                                : ('../' . $img['image']);
                            ?>
                            <img src="<?php echo htmlspecialchars($thumbSrc); ?>" loading="lazy" alt="<?php echo htmlspecialchars($img['title']); ?>" class="gal-thumb">
                            <?php if ($isVideo): ?>
                            <div class="position-absolute top-50 start-50 translate-middle pe-none">
                                <i class="fab fa-youtube fa-2x text-danger opacity-75"></i>
                            </div>
                            <?php endif; ?>
                            <div class="gallery-hover-overlay">
                                <small class="text-white fw-semibold d-block mb-1"><?php echo htmlspecialchars(mb_substr($img['title'], 0, 20)); ?></small>
                                <div class="d-flex gap-1 justify-content-center">
                                    <a href="<?php echo htmlspecialchars($isVideo ? ($img['video_url'] ?? '#') : ('../' . $img['image'])); ?>"
                                       target="_blank" class="btn btn-sm btn-info" title="हेर्नुहोस्">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <form method="POST" class="gal-inline-form" onsubmit="return confirm('यो फोटो/भिडियो मेटाउने हो?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$img['id']; ?>">
                                        <?php echo csrfField(); ?>
                                        <button type="submit" class="btn btn-sm btn-danger" title="मेटाउनुहोस्">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- PHOTO UPLOAD TAB -->
    <div class="tab-pane fade" id="gal-photo">
        <div class="card admin-table-card">
            <div class="card-header gradient-card-header"><h5><i class="fas fa-camera me-2"></i>फोटो अपलोड गर्नुहोस्</h5></div>
            <div class="card-body p-4">
                <form method="POST" action="gallery.php" enctype="multipart/form-data" class="needs-validation" novalidate>
    <?php echo csrfField(); ?>
                    <input type="hidden" name="media_type" value="photo">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success">शीर्षक (वैकल्पिक)</label>
                            <input type="text" name="title" class="form-control admin-fancy-input" placeholder="फोटोको शीर्षक">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success">वर्ग (Category)</label>
                            <select name="category" class="form-select admin-fancy-input">
                                <option value="general">सामान्य (General)</option>
                                <option value="events">कार्यक्रम (Events)</option>
                                <option value="office">कार्यालय (Office)</option>
                                <option value="meetings">बैठक (Meetings)</option>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end pb-1">
                            <div class="admin-toggle-wrap w-100">
                                <div class="form-check form-switch fs-5">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="galActive" checked>
                                    <label class="form-check-label fw-semibold" for="galActive">सक्रिय</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-success"><i class="fas fa-images me-1"></i>तस्विरहरू छान्नुहोस् <span class="text-danger">*</span></label>
                            <div class="upload-drop-zone p-4 text-center border-2 border-dashed gal-upload-drop"
                                 onclick="document.getElementById('gal_files').click()">
                                <i class="fas fa-cloud-upload-alt fa-3x text-success mb-2"></i>
                                <p class="mb-1 fw-semibold text-success">क्लिक गरी वा drag-drop गरी फोटो छान्नुहोस्</p>
                                <small class="text-muted">PNG, JPG, WebP — एकैपटक धेरै फाइल छान्न सकिन्छ</small>
                            </div>
                            <input type="file" name="images[]" id="gal_files" class="d-none" accept="image/*" multiple required
                                   onchange="showFileNames(this)">
                            <div id="gal_file_names" class="mt-2 text-muted small"></div>
                        </div>
                        <div class="col-12 pt-2">
                            <button type="submit" class="btn btn-success px-5 fw-semibold">
                                <i class="fas fa-upload me-2"></i>अपलोड गर्नुहोस्
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- VIDEO UPLOAD TAB -->
    <div class="tab-pane fade" id="gal-video">
        <div class="card admin-table-card">
            <div class="card-header gal-video-head">
                <h5><i class="fab fa-youtube me-2"></i>YouTube भिडियो थप्नुहोस्</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="gallery.php" class="needs-validation" novalidate>
    <?php echo csrfField(); ?>
                    <input type="hidden" name="media_type" value="video">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">भिडियोको शीर्षक</label>
                            <input type="text" name="title" class="form-control admin-fancy-input" placeholder="भिडियोको नाम">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">वर्ग</label>
                            <select name="category" class="form-select admin-fancy-input">
                                <option value="general">सामान्य</option>
                                <option value="events">कार्यक्रम</option>
                                <option value="office">कार्यालय</option>
                                <option value="meetings">बैठक</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-danger"><i class="fab fa-youtube me-1"></i>YouTube URL <span class="text-danger">*</span></label>
                            <input type="url" name="video_url" class="form-control admin-fancy-input" required
                                   placeholder="https://www.youtube.com/watch?v=XXXXXXXXXXX">
                            <small class="text-muted">Thumbnail स्वचालित रूपमा YouTube बाट लिइनेछ।</small>
                        </div>
                        <div class="col-12 pt-2">
                            <button type="submit" class="btn btn-danger px-5 fw-semibold">
                                <i class="fab fa-youtube me-2"></i>भिडियो थप्नुहोस्
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>


<script>
document.getElementById('btnUploadGallery')?.addEventListener('click', function() {
    adminSwitchTab(document.querySelector('[data-bs-target="#gal-photo"]'));
});
function showFileNames(input) {
    var names = Array.from(input.files).map(f => f.name).join(', ');
    document.getElementById('gal_file_names').textContent = '✓ ' + input.files.length + ' फाइल(हरू): ' + names;
}

    // ── Gallery search filter ──
    (function() {
        var inp = document.querySelector('.admin-gallery-search');
        if (!inp) return;
        inp.addEventListener('input', function() {
            var val = this.value.toLowerCase();
            document.querySelectorAll('.gallery-card-wrap').forEach(function(card) {
                card.style.display = !val || card.textContent.toLowerCase().includes(val) ? '' : 'none';
            });
        });
    })();
</script>

<?php require_once 'includes/admin-footer.php'; ?>
