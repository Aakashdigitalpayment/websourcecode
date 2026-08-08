<?php
/**
 * ग्यालरी व्यवस्थापन — Album-first gallery management
 * Tabs: एल्बमहरू | ग्यालरी | फोटो अपलोड | भिडियो
 */
$pageTitle = 'ग्यालरी व्यवस्थापन';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once dirname(__DIR__) . '/includes/gallery-albums.php';

$db = getDB();
ensureGalleryAlbumsSchema($db);

checkCSRF();
$action = $_POST['action'] ?? '';
$id     = intval($_POST['id'] ?? 0) ?: null;

$catOptions = galleryAlbumCategoryLabels(false);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'create_album') {
            $nameNp = clean_text($_POST['name_np'] ?? '');
            $nameEn = clean_text($_POST['name_en'] ?? '');
            $category = clean_text($_POST['category'] ?? 'general');
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if ($nameNp === '') {
                setFlash('error', 'एल्बमको नाम (नेपाली) आवश्यक छ।');
            } else {
                $maxOrder = (int)$db->query('SELECT COALESCE(MAX(display_order), 0) + 1 FROM gallery_albums')->fetchColumn();
                $db->prepare(
                    'INSERT INTO gallery_albums (name_np, name_en, category, is_active, display_order)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([$nameNp, $nameEn, $category, $isActive, $maxOrder]);
                setFlash('success', 'एल्बम सिर्जना भयो।');
            }
            redirect('gallery.php?tab=albums');
        }

        if ($action === 'update_album' && $id) {
            $nameNp = clean_text($_POST['name_np'] ?? '');
            $nameEn = clean_text($_POST['name_en'] ?? '');
            $category = clean_text($_POST['category'] ?? 'general');
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            if ($nameNp === '') {
                setFlash('error', 'एल्बमको नाम (नेपाली) आवश्यक छ।');
            } else {
                $db->prepare(
                    'UPDATE gallery_albums SET name_np = ?, name_en = ?, category = ?, is_active = ? WHERE id = ?'
                )->execute([$nameNp, $nameEn, $category, $isActive, $id]);
                setFlash('success', 'एल्बम अद्यावधिक भयो।');
            }
            redirect('gallery.php?tab=albums');
        }

        if ($action === 'delete_album' && $id) {
            $mediaCount = galleryAlbumMediaCount($db, (int)$id);
            if ($mediaCount > 0) {
                setFlash('error', 'यो एल्बममा ' . $mediaCount . ' फोटो/भिडियो छन्। पहिले media हटाउनुहोस् वा अर्को एल्बममा सार्नुहोस्।');
            } else {
                $db->prepare('DELETE FROM gallery_albums WHERE id = ?')->execute([$id]);
                setFlash('success', 'एल्बम मेटाइयो।');
            }
            redirect('gallery.php?tab=albums');
        }

        if ($action === 'delete' && $id) {
            $stmt = $db->prepare('SELECT image FROM gallery WHERE id = ?');
            $stmt->execute([$id]);
            $item = $stmt->fetch();
            if ($item) {
                deleteFile($item['image']);
                $db->prepare('DELETE FROM gallery WHERE id = ?')->execute([$id]);
                setFlash('success', 'तस्विर मेटाइयो।');
            }
            redirect('gallery.php?tab=list');
        }

        /* ── Edit existing photo/video (album / title / status) ── */
        if ($action === 'update_media' && $id) {
            $title = clean_text($_POST['title'] ?? '');
            $title_np = clean_text($_POST['title_np'] ?? $title);
            $albumId = (int)($_POST['album_id'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $videoUrl = clean_text($_POST['video_url'] ?? '');

            $stmt = $db->prepare('SELECT * FROM gallery WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                setFlash('error', 'मिडिया भेटिएन।');
                redirect('gallery.php?tab=list');
            }

            if ($albumId <= 0) {
                setFlash('error', 'एल्बम छान्नुहोस्।');
                redirect('gallery.php?tab=list');
            }
            $albumRow = galleryFetchAlbumById($db, $albumId);
            if (!$albumRow) {
                setFlash('error', 'चयन गरिएको एल्बम भेटिएन।');
                redirect('gallery.php?tab=list');
            }

            $albumCategory = (string)($albumRow['category'] ?? 'general');
            $albumName = (string)($albumRow['name_np'] ?? '');
            $isVideo = (($item['media_type'] ?? 'photo') === 'video');
            $t = $title !== '' ? $title : (string)($item['title'] ?? '');
            $tnp = $title_np !== '' ? $title_np : ($t !== '' ? $t : (string)($item['title_np'] ?? ''));

            if ($isVideo) {
                $thumb = (string)($item['thumbnail'] ?? '');
                $finalUrl = $videoUrl !== '' ? $videoUrl : (string)($item['video_url'] ?? '');
                if ($videoUrl !== '' && preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $videoUrl, $m)) {
                    $thumb = 'https://img.youtube.com/vi/' . $m[1] . '/maxresdefault.jpg';
                }
                $db->prepare(
                    'UPDATE gallery
                     SET title = ?, title_np = ?, video_url = ?, thumbnail = ?,
                         category = ?, album = ?, album_id = ?, is_active = ?
                     WHERE id = ? AND media_type = \'video\''
                )->execute([$t, $tnp, $finalUrl, $thumb, $albumCategory, $albumName, $albumId, $isActive, $id]);
            } else {
                $db->prepare(
                    'UPDATE gallery
                     SET title = ?, title_np = ?, category = ?, album = ?, album_id = ?, is_active = ?
                     WHERE id = ? AND (media_type = \'photo\' OR media_type IS NULL OR media_type = \'\')'
                )->execute([$t, $tnp, $albumCategory, $albumName, $albumId, $isActive, $id]);
            }

            setFlash('success', 'मिडिया अद्यावधिक भयो।');
            redirect('gallery.php?tab=list');
        }

        if ($action !== 'delete' && $action !== 'update_media') {
            $title    = clean_text($_POST['title'] ?? '');
            $title_np = clean_text($_POST['title_np'] ?? $title);
            $category = clean_text($_POST['category'] ?? 'general');
            $albumId  = (int)($_POST['album_id'] ?? 0);
            $mediaType = clean_text($_POST['media_type'] ?? 'photo');
            $videoUrl  = clean_text($_POST['video_url'] ?? '');
            $isActive  = isset($_POST['is_active']) ? 1 : 0;

            $hasMediaType = galleryHasMediaTypeColumn($db);
            $hasAlbumId = function_exists('dbColumnExists') ? dbColumnExists('gallery', 'album_id') : true;

            if ($mediaType === 'video' && !empty($videoUrl)) {
                if ($albumId <= 0) {
                    setFlash('error', 'भिडियो थप्न पहिले एल्बम छान्नुहोस्।');
                    redirect('gallery.php?tab=video');
                }
                $albumRow = galleryFetchAlbumById($db, $albumId);
                if (!$albumRow) {
                    setFlash('error', 'चयन गरिएको एल्बम भेटिएन।');
                    redirect('gallery.php?tab=video');
                }
                $albumCategory = (string)($albumRow['category'] ?? 'general');
                $albumName = (string)($albumRow['name_np'] ?? '');
                $thumb = '';
                if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $videoUrl, $m)) {
                    $thumb = 'https://img.youtube.com/vi/' . $m[1] . '/maxresdefault.jpg';
                }
                if ($hasMediaType) {
                    $db->prepare(
                        "INSERT INTO gallery
                            (title, title_np, image, media_type, video_url, thumbnail, category, album, album_id, is_active)
                         VALUES (?,?,'','video',?,?,?,?,?,?)"
                    )->execute([
                        $title,
                        $title_np,
                        $videoUrl,
                        $thumb,
                        $albumCategory,
                        $albumName,
                        $albumId,
                        $isActive,
                    ]);
                } else {
                    $db->prepare('INSERT INTO gallery (title, image, category, is_active) VALUES (?,?,?,?)')
                       ->execute([$title . ' (Video)', $thumb ?: '', $category, $isActive]);
                }
                setFlash('success', 'भिडियो "' . $albumName . '" एल्बममा थपियो।');
                redirect('gallery.php?tab=video');
            }

            if (isset($_FILES['images']) && $_FILES['images']['error'][0] !== UPLOAD_ERR_NO_FILE) {
                if ($albumId <= 0) {
                    setFlash('error', 'पहिले एल्बम छान्नुहोस् वा नयाँ एल्बम बनाउनुहोस्।');
                    redirect('gallery.php?tab=upload');
                }

                $albumRow = galleryFetchAlbumById($db, $albumId);
                if (!$albumRow) {
                    setFlash('error', 'चयन गरिएको एल्बम भेटिएन।');
                    redirect('gallery.php?tab=upload');
                }

                $albumCategory = (string)($albumRow['category'] ?? 'general');
                $albumName = (string)($albumRow['name_np'] ?? '');
                $files = $_FILES['images'];
                $count = 0;

                for ($i = 0; $i < count($files['name']); $i++) {
                    if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                        continue;
                    }
                    $file = [
                        'name' => $files['name'][$i],
                        'type' => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'error' => $files['error'][$i],
                        'size' => $files['size'][$i],
                    ];
                    $up = uploadFile($file, 'gallery');
                    if (!$up['success']) {
                        continue;
                    }
                    $t = $title !== '' ? $title : ('Gallery ' . ($i + 1));
                    $tnp = $title_np !== '' ? $title_np : $t;

                    if ($hasMediaType && $hasAlbumId) {
                        $db->prepare(
                            "INSERT INTO gallery (title, title_np, image, media_type, category, album, album_id, is_active)
                             VALUES (?,?,?,'photo',?,?,?,?)"
                        )->execute([$t, $tnp, $up['path'], $albumCategory, $albumName, $albumId, $isActive]);
                    } elseif ($hasMediaType) {
                        $db->prepare(
                            "INSERT INTO gallery (title, title_np, image, media_type, category, is_active)
                             VALUES (?,?,?,'photo',?,?)"
                        )->execute([$t, $tnp, $up['path'], $albumCategory, $isActive]);
                    } else {
                        $db->prepare('INSERT INTO gallery (title, image, category, is_active) VALUES (?,?,?,?)')
                           ->execute([$t, $up['path'], $albumCategory, $isActive]);
                    }
                    $count++;
                }

                if ($count > 0) {
                    setFlash('success', $count . ' तस्विर(हरू) "' . $albumName . '" एल्बममा अपलोड भयो।');
                } else {
                    setFlash('error', 'कुनै तस्विर अपलोड भएन।');
                }
                redirect('gallery.php?tab=list');
            }
        }
    } catch (Exception $e) {
        setFlash('error', 'त्रुटि भयो।');
        redirect('gallery.php');
    }
}

$albums = galleryFetchAdminAlbums($db);
$editAlbumId = isset($_GET['edit_album']) ? (int)$_GET['edit_album'] : 0;
$editAlbum = $editAlbumId > 0 ? galleryFetchAlbumById($db, $editAlbumId) : null;

try {
    $images = $db->query(
        'SELECT g.*, a.name_np AS album_name_np, a.name_en AS album_name_en
         FROM gallery g
         LEFT JOIN gallery_albums a ON a.id = g.album_id
         ORDER BY g.id DESC LIMIT 500'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    try {
        $images = $db->query('SELECT * FROM gallery ORDER BY id DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {
        $images = [];
    }
}

$activeTab = preg_replace('/[^a-z]/', '', (string)($_GET['tab'] ?? 'albums'));
if (!in_array($activeTab, ['albums', 'list', 'upload', 'video'], true)) {
    $activeTab = 'albums';
}

$flash = getFlash();
$photoCount = 0;
foreach ($images as $img) {
    if (($img['media_type'] ?? 'photo') !== 'video') {
        $photoCount++;
    }
}
?>

<?php echo adminPageHeader(
    'ग्यालरी व्यवस्थापन',
    'fa-images',
    'पहिले एल्बम बनाउनुहोस्, त्यसपछि फोटो अपलोड गर्नुहोस्।',
    '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25 me-2"><i class="fas fa-folder me-1"></i>एल्बम: ' . count($albums) . '</span>'
    . '<span class="badge admin-stat-badge bg-primary-subtle text-primary border border-primary border-opacity-25"><i class="fas fa-images me-1"></i>फोटो: ' . $photoCount . '</span>'
);
?>
<?php echo adminHelpTip('सही क्रम:', [
    '१. "एल्बमहरू" ट्याबमा कार्यक्रम/सभाको नामले एल्बम बनाउनुहोस्।',
    '२. "फोटो अपलोड" / "भिडियो" मा एल्बम छानेर media थप्नुहोस्।',
    '३. ग्यालरी सूचीबाट Edit (✏️) थिचेर album change / title / status अपडेट गर्न सकिन्छ।',
    '४. Public मा Photos/Videos tab → album cover → click गरेर popup मा media हेर्नुहोस्।',
]); ?>

<?php if ($flash && $flash['type'] === 'success'): ?>
<div class="alert alert-success alert-dismissible fade show mb-3"><i class="fas fa-check-circle me-2"></i><?php echo $flash['message']; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php elseif ($flash && $flash['type'] === 'error'): ?>
<div class="alert alert-danger alert-dismissible fade show mb-3"><i class="fas fa-exclamation-circle me-2"></i><?php echo $flash['message']; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<ul class="nav nav-tabs admin-nav-tabs mb-3">
    <li class="nav-item">
        <button class="nav-link <?php echo $activeTab === 'albums' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#gal-albums">
            <i class="fas fa-folder me-2"></i>एल्बमहरू <span class="badge bg-success ms-1"><?php echo count($albums); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link <?php echo $activeTab === 'list' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#gal-list">
            <i class="fas fa-images me-2"></i>ग्यालरी <span class="badge bg-primary ms-1"><?php echo count($images); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link <?php echo $activeTab === 'upload' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#gal-photo" id="gal-photo-tab">
            <i class="fas fa-camera me-2"></i>फोटो अपलोड
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link <?php echo $activeTab === 'video' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#gal-video">
            <i class="fab fa-youtube me-2 gal-yt-icon"></i>भिडियो थप्नुहोस्
        </button>
    </li>
</ul>

<div class="tab-content">
    <!-- ALBUMS TAB -->
    <div class="tab-pane fade <?php echo $activeTab === 'albums' ? 'show active' : ''; ?>" id="gal-albums">
        <div class="row g-3">
            <div class="col-lg-5">
                <div class="card admin-table-card">
                    <div class="card-header gradient-card-header">
                        <h5 class="mb-0"><i class="fas fa-folder-plus me-2"></i><?php echo $editAlbum ? 'एल्बम सम्पादन' : 'नयाँ एल्बम'; ?></h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="gallery.php?tab=albums">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="<?php echo $editAlbum ? 'update_album' : 'create_album'; ?>">
                            <?php if ($editAlbum): ?>
                            <input type="hidden" name="id" value="<?php echo (int)$editAlbum['id']; ?>">
                            <?php endif; ?>
                            <div class="mb-3">
                                <label for="gal_album_name_np" class="form-label fw-semibold text-success">नाम (नेपाली) <span class="text-danger">*</span></label>
                                <input type="text" name="name_np" id="gal_album_name_np" class="form-control admin-fancy-input" required
                                       value="<?php echo htmlspecialchars((string)($editAlbum['name_np'] ?? '')); ?>"
                                       placeholder="जस्तै: २५ औं साधारण सभा">
                            </div>
                            <div class="mb-3">
                                <label for="gal_album_name_en" class="form-label fw-semibold">नाम (English)</label>
                                <input type="text" name="name_en" id="gal_album_name_en" class="form-control admin-fancy-input"
                                       value="<?php echo htmlspecialchars((string)($editAlbum['name_en'] ?? '')); ?>"
                                       placeholder="Optional English name">
                            </div>
                            <div class="mb-3">
                                <label for="gal_album_category" class="form-label fw-semibold text-success">वर्ग</label>
                                <select name="category" id="gal_album_category" class="form-select admin-fancy-input">
                                    <?php foreach ($catOptions as $val => $lbl): ?>
                                    <option value="<?php echo htmlspecialchars($val); ?>" <?php echo (($editAlbum['category'] ?? 'general') === $val) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($lbl); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="galAlbumActive"
                                           <?php echo !isset($editAlbum['is_active']) || (int)($editAlbum['is_active'] ?? 1) === 1 ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-semibold" for="galAlbumActive">सक्रिय (public मा देखिने)</label>
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-save me-1"></i><?php echo $editAlbum ? 'अद्यावधिक' : 'एल्बम बनाउनुहोस्'; ?>
                                </button>
                                <?php if ($editAlbum): ?>
                                <a href="gallery.php?tab=albums" class="btn btn-outline-secondary">रद्द</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="card admin-table-card">
                    <div class="card-header"><h5 class="mb-0"><i class="fas fa-list me-2"></i>एल्बम सूची</h5></div>
                    <div class="card-body p-0">
                        <?php if (empty($albums)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-folder-open fa-3x mb-3 opacity-25"></i>
                            <p class="mb-0">कुनै एल्बम छैन। बायाँबाट पहिलो एल्बम बनाउनुहोस्।</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>एल्बम</th>
                                        <th class="text-center">फोटो</th>
                                        <th class="text-center">भिडियो</th>
                                        <th>वर्ग</th>
                                        <th class="text-center">स्थिति</th>
                                        <th class="text-end">कार्य</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($albums as $alb): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars((string)$alb['name_np']); ?></strong>
                                            <?php if (!empty($alb['name_en'])): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars((string)$alb['name_en']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><span class="badge bg-primary"><?php echo (int)($alb['photo_count'] ?? 0); ?></span></td>
                                        <td class="text-center"><span class="badge bg-danger"><?php echo (int)($alb['video_count'] ?? 0); ?></span></td>
                                        <td><?php echo htmlspecialchars($catOptions[$alb['category'] ?? 'general'] ?? $alb['category']); ?></td>
                                        <td class="text-center">
                                            <?php if ((int)($alb['is_active'] ?? 0) === 1): ?>
                                            <span class="badge bg-success">सक्रिय</span>
                                            <?php else: ?>
                                            <span class="badge bg-secondary">निष्क्रिय</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <a href="gallery.php?tab=albums&amp;edit_album=<?php echo (int)$alb['id']; ?>" class="btn btn-sm btn-outline-primary" title="सम्पादन"><i class="fas fa-edit"></i></a>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('यो खाली एल्बम मेटाउने हो?')">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="delete_album">
                                                <input type="hidden" name="id" value="<?php echo (int)$alb['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="मेटाउनुहोस्" <?php echo (int)($alb['media_count'] ?? 0) > 0 ? 'disabled' : ''; ?>><i class="fas fa-trash"></i></button>
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
            </div>
        </div>
    </div>

    <!-- GALLERY LIST TAB -->
    <div class="tab-pane fade <?php echo $activeTab === 'list' ? 'show active' : ''; ?>" id="gal-list">
        <div class="admin-search-wrap px-3 py-2 border-bottom bg-light d-flex align-items-center gap-3 flex-wrap">
            <div class="input-group input-group-sm gal-search-group">
                <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                <input type="text" class="form-control border-start-0 admin-gallery-search" placeholder="शीर्षक, एल्बम वा वर्गले खोज्नुहोस्..." autocomplete="off">
            </div>
            <select id="galAlbumFilter" class="form-select form-select-sm gal-album-filter-select">
                <option value="">सबै एल्बम</option>
                <?php foreach ($albums as $alb): ?>
                <option value="<?php echo (int)$alb['id']; ?>"><?php echo htmlspecialchars((string)$alb['name_np']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="card admin-table-card">
            <div class="card-body">
                <?php if (empty($images)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-images fa-4x mb-3 opacity-25"></i>
                    <p>कुनै तस्विर छैन। पहिले एल्बम बनाएर "फोटो अपलोड" प्रयोग गर्नुहोस्।</p>
                </div>
                <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($images as $img):
                        $isVideo = ($img['media_type'] ?? 'photo') === 'video';
                        $thumbSrc = $isVideo
                            ? ($img['thumbnail'] ?? 'assets/images/video-placeholder.png')
                            : ('../' . $img['image']);
                        $albumLbl = trim((string)($img['album_name_np'] ?? ''));
                        if ($albumLbl === '') {
                            $albumLbl = trim((string)($img['album'] ?? ''));
                        }
                    ?>
                    <div class="col-lg-2 col-md-3 col-sm-4 col-6 gallery-card-wrap" data-album-id="<?php echo (int)($img['album_id'] ?? 0); ?>">
                        <div class="gallery-card position-relative">
                            <img src="<?php echo htmlspecialchars($thumbSrc); ?>" loading="lazy" alt="<?php echo htmlspecialchars((string)$img['title']); ?>" class="gal-thumb">
                            <?php if ($isVideo): ?>
                            <div class="position-absolute top-50 start-50 translate-middle pe-none">
                                <i class="fab fa-youtube fa-2x text-danger opacity-75"></i>
                            </div>
                            <?php endif; ?>
                            <div class="gallery-hover-overlay">
                                <small class="text-white fw-semibold d-block mb-1"><?php echo htmlspecialchars(mb_substr((string)$img['title'], 0, 20)); ?></small>
                                <?php if ($albumLbl !== ''): ?>
                                <small class="text-white-50 d-block mb-1 gal-album-lbl"><i class="fas fa-folder me-1"></i><?php echo htmlspecialchars(mb_substr($albumLbl, 0, 24)); ?></small>
                                <?php endif; ?>
                                <span class="visually-hidden"><?php echo htmlspecialchars($albumLbl . ' ' . ($img['category'] ?? '')); ?></span>
                                <div class="d-flex gap-1 justify-content-center flex-wrap">
                                    <a href="<?php echo htmlspecialchars($isVideo ? ($img['video_url'] ?? '#') : ('../' . $img['image'])); ?>"
                                       target="_blank" class="btn btn-sm btn-info" title="हेर्नुहोस्">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <button type="button"
                                            class="btn btn-sm btn-warning gal-edit-media-btn"
                                            title="सम्पादन / एल्बम"
                                            data-bs-toggle="modal"
                                            data-bs-target="#galEditMediaModal"
                                            data-media-id="<?php echo (int)$img['id']; ?>"
                                            data-media-type="<?php echo $isVideo ? 'video' : 'photo'; ?>"
                                            data-media-title="<?php echo htmlspecialchars((string)($img['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-media-url="<?php echo htmlspecialchars((string)($img['video_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-album-id="<?php echo (int)($img['album_id'] ?? 0); ?>"
                                            data-is-active="<?php echo (int)($img['is_active'] ?? 1); ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
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

    <!-- One reusable modal keeps the DOM light even with hundreds of media rows. -->
    <div class="modal fade" id="galEditMediaModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="gallery.php?tab=list" id="galEditMediaForm">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="update_media">
                    <input type="hidden" name="id" id="galEditMediaId" value="">
                    <div class="modal-header">
                        <h5 class="modal-title" id="galEditMediaTitle">
                            <i class="fas fa-edit me-2 text-success"></i>मिडिया सम्पादन
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="galEditAlbumId" class="form-label fw-semibold text-success">एल्बम <span class="text-danger">*</span></label>
                            <select name="album_id" id="galEditAlbumId" class="form-select" required>
                                <option value="">— एल्बम छान्नुहोस् —</option>
                                <?php foreach ($albums as $alb): ?>
                                <option value="<?php echo (int)$alb['id']; ?>"><?php echo htmlspecialchars((string)$alb['name_np']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($albums)): ?>
                            <small class="text-danger">पहिले एल्बम बनाउनुहोस्।</small>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label for="galEditTitleInput" class="form-label fw-semibold">शीर्षक</label>
                            <input type="text" name="title" id="galEditTitleInput" class="form-control" placeholder="शीर्षक">
                        </div>
                        <div class="mb-3" id="galEditVideoUrlWrap">
                            <label for="galEditVideoUrl" class="form-label fw-semibold">YouTube URL</label>
                            <input type="url" name="video_url" id="galEditVideoUrl" class="form-control" placeholder="https://www.youtube.com/watch?v=...">
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" id="galEditActive">
                            <label class="form-check-label fw-semibold" for="galEditActive">सक्रिय</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">रद्द</button>
                        <button type="submit" class="btn btn-success" <?php echo empty($albums) ? 'disabled' : ''; ?>>
                            <i class="fas fa-save me-1"></i>सेभ गर्नुहोस्
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- PHOTO UPLOAD TAB -->
    <div class="tab-pane fade <?php echo $activeTab === 'upload' ? 'show active' : ''; ?>" id="gal-photo">
        <div class="card admin-table-card">
            <div class="card-header gradient-card-header"><h5><i class="fas fa-camera me-2"></i>फोटो अपलोड गर्नुहोस्</h5></div>
            <div class="card-body p-4">
                <?php if (empty($albums)): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    पहिले <strong>एल्बमहरू</strong> ट्याबमा एउटा एल्बम बनाउनुहोस्, त्यसपछि फोटो अपलोड गर्न सकिन्छ।
                    <a href="gallery.php?tab=albums" class="alert-link ms-1">एल्बम बनाउनुहोस् →</a>
                </div>
                <?php else: ?>
                <form method="POST" action="gallery.php?tab=upload" enctype="multipart/form-data" class="needs-validation" novalidate>
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="media_type" value="photo">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="gal_photo_album" class="form-label fw-semibold text-success">एल्बम छान्नुहोस् <span class="text-danger">*</span></label>
                            <select name="album_id" id="gal_photo_album" class="form-select admin-fancy-input" required>
                                <option value="">— एल्बम छान्नुहोस् —</option>
                                <?php foreach ($albums as $alb): ?>
                                <option value="<?php echo (int)$alb['id']; ?>">
                                    <?php echo htmlspecialchars((string)$alb['name_np']); ?>
                                    (<?php echo (int)($alb['photo_count'] ?? 0); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="gal_photo_title" class="form-label fw-semibold">शीर्षक (वैकल्पिक — caption)</label>
                            <input type="text" name="title" id="gal_photo_title" class="form-control admin-fancy-input" placeholder="फोटोको शीर्षक">
                        </div>
                        <div class="col-md-6 d-flex align-items-end pb-1">
                            <div class="admin-toggle-wrap w-100">
                                <div class="form-check form-switch fs-5">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="galActive" checked>
                                    <label class="form-check-label fw-semibold" for="galActive">सक्रिय</label>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <label for="gal_files" class="form-label fw-semibold text-success"><i class="fas fa-images me-1"></i>तस्विरहरू छान्नुहोस् <span class="text-danger">*</span></label>
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
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- VIDEO UPLOAD TAB -->
    <div class="tab-pane fade <?php echo $activeTab === 'video' ? 'show active' : ''; ?>" id="gal-video">
        <div class="card admin-table-card">
            <div class="card-header gal-video-head">
                <h5><i class="fab fa-youtube me-2"></i>YouTube भिडियो थप्नुहोस्</h5>
            </div>
            <div class="card-body p-4">
                <?php if (empty($albums)): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    भिडियो थप्न पहिले <strong>एल्बमहरू</strong> ट्याबमा एल्बम बनाउनुहोस्।
                    <a href="gallery.php?tab=albums" class="alert-link ms-1">एल्बम बनाउनुहोस् →</a>
                </div>
                <?php else: ?>
                <form method="POST" action="gallery.php?tab=video" class="needs-validation" novalidate>
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="media_type" value="video">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="gal_video_album" class="form-label fw-semibold text-success">एल्बम छान्नुहोस् <span class="text-danger">*</span></label>
                            <select name="album_id" id="gal_video_album" class="form-select admin-fancy-input" required>
                                <option value="">— एल्बम छान्नुहोस् —</option>
                                <?php foreach ($albums as $alb): ?>
                                <option value="<?php echo (int)$alb['id']; ?>">
                                    <?php echo htmlspecialchars((string)$alb['name_np']); ?>
                                    (<?php echo (int)($alb['video_count'] ?? 0); ?> भिडियो)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="gal_video_title" class="form-label fw-semibold text-success">भिडियोको शीर्षक</label>
                            <input type="text" name="title" id="gal_video_title" class="form-control admin-fancy-input" placeholder="भिडियोको नाम">
                        </div>
                        <div class="col-12">
                            <label for="gal_video_url" class="form-label fw-semibold text-danger"><i class="fab fa-youtube me-1"></i>YouTube URL <span class="text-danger">*</span></label>
                            <input type="url" name="video_url" id="gal_video_url" class="form-control admin-fancy-input" required
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
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function showFileNames(input) {
    var names = Array.from(input.files).map(function (f) { return f.name; }).join(', ');
    document.getElementById('gal_file_names').textContent = '✓ ' + input.files.length + ' फाइल(हरू): ' + names;
}

(function () {
    var inp = document.querySelector('.admin-gallery-search');
    var albumFilter = document.getElementById('galAlbumFilter');
    function filterCards() {
        var val = inp ? inp.value.toLowerCase() : '';
        var albumId = albumFilter ? albumFilter.value : '';
        document.querySelectorAll('.gallery-card-wrap').forEach(function (card) {
            var textOk = !val || card.textContent.toLowerCase().includes(val);
            var albumOk = !albumId || card.getAttribute('data-album-id') === albumId;
            card.style.display = textOk && albumOk ? '' : 'none';
        });
    }
    if (inp) inp.addEventListener('input', filterCards);
    if (albumFilter) albumFilter.addEventListener('change', filterCards);
})();

(function () {
    var modal = document.getElementById('galEditMediaModal');
    if (!modal) return;

    /* Bootstrap modals must be body children; tab/card stacking contexts can
       otherwise place the dialog behind its own backdrop. */
    document.body.appendChild(modal);

    modal.addEventListener('show.bs.modal', function (event) {
        var trigger = event.relatedTarget;
        if (!trigger) return;

        var type = trigger.getAttribute('data-media-type') || 'photo';
        document.getElementById('galEditMediaId').value = trigger.getAttribute('data-media-id') || '';
        document.getElementById('galEditTitleInput').value = trigger.getAttribute('data-media-title') || '';
        document.getElementById('galEditVideoUrl').value = trigger.getAttribute('data-media-url') || '';
        document.getElementById('galEditAlbumId').value = trigger.getAttribute('data-album-id') || '';
        document.getElementById('galEditActive').checked = trigger.getAttribute('data-is-active') === '1';
        document.getElementById('galEditVideoUrlWrap').classList.toggle('d-none', type !== 'video');
        document.getElementById('galEditMediaTitle').innerHTML =
            '<i class="fas fa-edit me-2 text-success"></i>'
            + (type === 'video' ? 'भिडियो सम्पादन' : 'फोटो सम्पादन');
    });
})();
</script>

<?php require_once 'includes/admin-footer.php'; ?>
