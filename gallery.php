<?php
require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded
$pageTitle = isEnglish() ? 'Gallery' : 'ग्यालरी';
$pageDescription = isEnglish()
    ? 'Photo and video gallery of cooperative events, meetings and activities.'
    : 'सहकारीका कार्यक्रम, बैठक र क्रियाकलापका फोटो तथा भिडियो ग्यालरी।';
require_once 'includes/header.php';

$activeTab = $_GET['type'] ?? 'photo';
$activeCategory = $_GET['category'] ?? 'all';
$albumParamRaw = isset($_GET['album']) ? trim((string)$_GET['album']) : null;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$photos = [];
$videos = [];
$categories = [];
$albums = []; // [ ['key'=>..., 'count'=>..., 'cover'=>..., 'max_id'=>...], ... ]
$photoTotal = 0;   // all photos (tab badge)
$videoTotal = 0;
$albumPhotoTotal = 0; // photos in current album (pagination)
$photoPages = 1;
$videoPages = 1;
$activeAlbum = 'all'; // 'all' = cover grid; else album key
$showAlbumCovers = false;
$latestAlbumKey = '';
$hasAlbumCol = false;
$hasMediaType = false;

$galleryAlbumKeyExpr = static function (bool $hasAlbum): string {
    if ($hasAlbum) {
        return "COALESCE(NULLIF(TRIM(album), ''), NULLIF(TRIM(title_np), ''), NULLIF(TRIM(title), ''), 'General')";
    }
    return "COALESCE(NULLIF(TRIM(title_np), ''), NULLIF(TRIM(title), ''), 'General')";
};

try {
    $db = getDB();

    $hasMediaType = function_exists('dbColumnExists')
        ? dbColumnExists('gallery', 'media_type')
        : false;
    if (!$hasMediaType && !function_exists('dbColumnExists')) {
        try {
            $checkCol = $db->query("SHOW COLUMNS FROM gallery LIKE 'media_type'");
            $hasMediaType = $checkCol && $checkCol->fetch() !== false;
        } catch (Throwable $e) {
            $hasMediaType = false;
        }
    }

    $hasAlbumCol = function_exists('dbColumnExists')
        ? dbColumnExists('gallery', 'album')
        : false;
    if (!$hasAlbumCol && !function_exists('dbColumnExists')) {
        try {
            $checkAlbum = $db->query("SHOW COLUMNS FROM gallery LIKE 'album'");
            $hasAlbumCol = $checkAlbum && $checkAlbum->fetch() !== false;
        } catch (Throwable $e) {
            $hasAlbumCol = false;
        }
    }

    $photoLimit = 24;
    $videoLimit = 12;
    $albumExpr = $galleryAlbumKeyExpr($hasAlbumCol);
    $photoWhere = $hasMediaType
        ? "is_active = 1 AND (media_type = 'photo' OR media_type IS NULL)"
        : 'is_active = 1';
    $videoWhere = $hasMediaType
        ? "is_active = 1 AND media_type = 'video'"
        : 'is_active = 0'; // no videos without media_type

    if (!in_array($activeTab, ['photo', 'video'], true)) {
        $activeTab = 'photo';
    }

    /* Categories — used for video filter only */
    $categories = $db->query(
        "SELECT DISTINCT category FROM gallery WHERE is_active = 1 AND category IS NOT NULL AND category <> '' LIMIT 50"
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if ($activeCategory !== 'all' && !in_array($activeCategory, $categories, true)) {
        $activeCategory = 'all';
    }

    $catSql = '';
    $catParams = [];
    if ($activeCategory !== 'all') {
        $catSql = ' AND category = ?';
        $catParams[] = $activeCategory;
    }

    /* Album list (photos only) */
    try {
        $albumSql = "SELECT {$albumExpr} AS album_key,
                            COUNT(*) AS photo_count,
                            MAX(id) AS max_id,
                            SUBSTRING_INDEX(GROUP_CONCAT(image ORDER BY id DESC SEPARATOR '||'), '||', 1) AS cover_image
                     FROM gallery
                     WHERE {$photoWhere}
                     GROUP BY {$albumExpr}
                     ORDER BY max_id DESC
                     LIMIT 100";
        $albums = $db->query($albumSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $albums = [];
    }

    $albumKeys = [];
    foreach ($albums as &$aRow) {
        $aRow['key'] = (string)($aRow['album_key'] ?? '');
        $aRow['count'] = (int)($aRow['photo_count'] ?? 0);
        $aRow['cover'] = (string)($aRow['cover_image'] ?? '');
        $aRow['max_id'] = (int)($aRow['max_id'] ?? 0);
        if ($aRow['key'] !== '') {
            $albumKeys[] = $aRow['key'];
        }
    }
    unset($aRow);

    $latestAlbumKey = $albumKeys[0] ?? '';

    /* Resolve active album for photo tab */
    if ($albumParamRaw === null || $albumParamRaw === '') {
        $activeAlbum = $latestAlbumKey !== '' ? $latestAlbumKey : 'all';
    } elseif ($albumParamRaw === 'all') {
        $activeAlbum = 'all';
    } elseif (in_array($albumParamRaw, $albumKeys, true)) {
        $activeAlbum = $albumParamRaw;
    } else {
        /* Unknown album → latest (safe) */
        $activeAlbum = $latestAlbumKey !== '' ? $latestAlbumKey : 'all';
    }
    $showAlbumCovers = ($activeTab === 'photo' && $activeAlbum === 'all');

    /* Tab totals */
    if ($hasMediaType) {
        $photoTotal = (int)$db->query("SELECT COUNT(*) FROM gallery WHERE {$photoWhere}")->fetchColumn();
        $videoCnt = $db->prepare("SELECT COUNT(*) FROM gallery WHERE {$videoWhere}" . $catSql);
        $videoCnt->execute($catParams);
        $videoTotal = (int)$videoCnt->fetchColumn();
    } else {
        $photoTotal = (int)$db->query("SELECT COUNT(*) FROM gallery WHERE is_active = 1")->fetchColumn();
        $videoTotal = 0;
    }
    $videoPages = max(1, (int)ceil($videoTotal / $videoLimit));

    if ($activeTab === 'video') {
        if ($page > $videoPages) {
            $page = $videoPages;
        }
        $offset = ($page - 1) * $videoLimit;
        if ($hasMediaType) {
            $vStmt = $db->prepare(
                "SELECT * FROM gallery WHERE {$videoWhere}" . $catSql
                . ' ORDER BY id DESC LIMIT ' . (int)$videoLimit . ' OFFSET ' . (int)$offset
            );
            $vStmt->execute($catParams);
            $videos = $vStmt->fetchAll() ?: [];
        }
    } else {
        /* Photo tab */
        if ($showAlbumCovers) {
            $photos = [];
            $albumPhotoTotal = 0;
            $photoPages = 1;
        } else {
            $albumSqlFilter = " AND ({$albumExpr}) = ?";
            $cntStmt = $db->prepare("SELECT COUNT(*) FROM gallery WHERE {$photoWhere}" . $albumSqlFilter);
            $cntStmt->execute([$activeAlbum]);
            $albumPhotoTotal = (int)$cntStmt->fetchColumn();
            $photoPages = max(1, (int)ceil($albumPhotoTotal / $photoLimit));
            if ($page > $photoPages) {
                $page = $photoPages;
            }
            $offset = ($page - 1) * $photoLimit;
            $pStmt = $db->prepare(
                "SELECT * FROM gallery WHERE {$photoWhere}" . $albumSqlFilter
                . ' ORDER BY id DESC LIMIT ' . (int)$photoLimit . ' OFFSET ' . (int)$offset
            );
            $pStmt->execute([$activeAlbum]);
            $photos = $pStmt->fetchAll() ?: [];
        }
    }
} catch (Throwable $e) {
    $photos = [];
    $videos = [];
    $categories = [];
    $albums = [];
    $photoTotal = 0;
    $videoTotal = 0;
    $albumPhotoTotal = 0;
    $photoPages = 1;
    $videoPages = 1;
    $activeAlbum = 'all';
    $showAlbumCovers = true;
}

if (!in_array($activeTab, ['photo', 'video'], true)) {
    $activeTab = 'photo';
}

$L = getLangStrings();
$galleryPageQs = static function (int $p, string $type, string $albumOrCat = 'all', string $mode = 'album'): string {
    $q = ['type' => $type];
    if ($p > 1) {
        $q['page'] = $p;
    }
    if ($mode === 'category') {
        if ($albumOrCat !== 'all') {
            $q['category'] = $albumOrCat;
        }
    } else {
        /* Always include album for photo deep-links (incl. all covers) */
        if ($type === 'photo') {
            $q['album'] = $albumOrCat;
        } elseif ($albumOrCat !== 'all') {
            $q['category'] = $albumOrCat;
        }
    }
    return '?' . http_build_query($q);
};

$albumLabelFallback = isEnglish() ? 'General' : 'सामान्य';
?>
<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo isEnglish() ? 'Gallery' : 'फोटो/भिडियो ग्यालरी'; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo isEnglish() ? 'Gallery' : 'ग्यालरी'; ?></li>
            </ol>
        </nav>
    </div>
</section>

<!-- Gallery Section -->
<section class="gallery-section section-padding">
    <div class="container">
        <!-- Photo/Video Tabs -->
        <div class="gallery-tabs-wrapper">
            <div class="gallery-tabs">
                <a href="<?php echo htmlspecialchars($galleryPageQs(1, 'photo', $latestAlbumKey !== '' ? $latestAlbumKey : 'all')); ?>" class="gallery-tab <?php echo $activeTab === 'photo' ? 'active' : ''; ?>">
                    <i class="lucide-icon" aria-hidden="true" data-lucide="images"></i>
                    <span><?php echo isEnglish() ? 'Photos' : 'फोटोहरू'; ?></span>
                    <span class="tab-count"><?php echo (int)$photoTotal; ?></span>
                </a>
                <a href="<?php echo htmlspecialchars($galleryPageQs(1, 'video', 'all', 'category')); ?>" class="gallery-tab <?php echo $activeTab === 'video' ? 'active' : ''; ?>">
                    <i class="fab fa-youtube"></i>
                    <span><?php echo isEnglish() ? 'Videos' : 'भिडियोहरू'; ?></span>
                    <span class="tab-count"><?php echo (int)$videoTotal; ?></span>
                </a>
            </div>

            <?php if ($activeTab === 'photo' && !empty($albums)): ?>
            <!-- Album Dropdown Filter -->
            <div class="gallery-category-filter gallery-album-filter">
                <label class="visually-hidden" for="albumFilter"><?php echo isEnglish() ? 'Album' : 'एल्बम'; ?></label>
                <select id="albumFilter" class="form-select" onchange="window.location.href=this.value">
                    <option value="<?php echo htmlspecialchars($galleryPageQs(1, 'photo', 'all')); ?>" <?php echo $activeAlbum === 'all' ? 'selected' : ''; ?>>
                        <?php echo isEnglish() ? 'All albums' : 'सबै एल्बम'; ?>
                    </option>
                    <?php foreach ($albums as $alb): ?>
                    <option value="<?php echo htmlspecialchars($galleryPageQs(1, 'photo', $alb['key'])); ?>" <?php echo $activeAlbum === $alb['key'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($alb['key'] !== '' ? $alb['key'] : $albumLabelFallback); ?>
                        (<?php echo (int)$alb['count']; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php elseif ($activeTab === 'video' && !empty($categories) && count($categories) > 1): ?>
            <!-- Category filter (videos only) -->
            <div class="gallery-category-filter">
                <select id="categoryFilter" class="form-select" onchange="window.location.href=this.value">
                    <option value="<?php echo htmlspecialchars($galleryPageQs(1, 'video', 'all', 'category')); ?>" <?php echo $activeCategory === 'all' ? 'selected' : ''; ?>><?php echo isEnglish() ? 'All Categories' : 'सबै वर्ग'; ?></option>
                    <?php foreach ($categories as $cat):
                        $catLabels = [
                            'general' => isEnglish() ? 'General' : 'सामान्य',
                            'events' => isEnglish() ? 'Events' : 'कार्यक्रम',
                            'office' => isEnglish() ? 'Office' : 'कार्यालय',
                            'meetings' => isEnglish() ? 'Meetings' : 'बैठक'
                        ];
                    ?>
                    <option value="<?php echo htmlspecialchars($galleryPageQs(1, 'video', (string)$cat, 'category')); ?>" <?php echo $activeCategory === $cat ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($catLabels[$cat] ?? $cat); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($activeTab === 'photo' && !$showAlbumCovers && $activeAlbum !== 'all'): ?>
        <div class="gallery-album-heading">
            <h2 class="h5 mb-0">
                <i class="fas fa-folder-open me-2 text-success" aria-hidden="true"></i>
                <?php echo htmlspecialchars($activeAlbum !== '' ? $activeAlbum : $albumLabelFallback); ?>
            </h2>
            <a class="gallery-album-all-link" href="<?php echo htmlspecialchars($galleryPageQs(1, 'photo', 'all')); ?>">
                <?php echo isEnglish() ? 'All albums' : 'सबै एल्बम'; ?>
            </a>
        </div>
        <?php endif; ?>

        <!-- Photos Tab Content -->
        <div class="gallery-content" id="photosContent" style="<?php echo $activeTab !== 'photo' ? 'display:none;' : ''; ?>">

            <?php if ($showAlbumCovers): ?>
            <div class="row gallery-grid gallery-album-grid">
                <?php if (!empty($albums)): ?>
                    <?php foreach ($albums as $alb):
                        $cover = $alb['cover'] !== '' ? $alb['cover'] : '';
                        $href = $galleryPageQs(1, 'photo', $alb['key']);
                    ?>
                    <div class="col-lg-3 col-md-4 col-sm-6 mb-4 gallery-item">
                        <a href="<?php echo htmlspecialchars($href); ?>" class="gallery-album-card">
                            <div class="gallery-album-cover">
                                <?php if ($cover !== ''): ?>
                                <img src="<?php echo htmlspecialchars($cover); ?>" loading="lazy" alt="" class="img-fluid">
                                <?php else: ?>
                                <div class="gallery-album-cover-empty"><i class="fas fa-images" aria-hidden="true"></i></div>
                                <?php endif; ?>
                                <span class="gallery-album-count"><?php echo (int)$alb['count']; ?></span>
                            </div>
                            <div class="gallery-album-title"><?php echo htmlspecialchars($alb['key'] !== '' ? $alb['key'] : $albumLabelFallback); ?></div>
                        </a>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-12">
                        <div class="empty-state text-center py-5">
                            <i class="lucide-icon fa-4x text-muted mb-3" aria-hidden="true" data-lucide="images"></i>
                            <h4><?php echo isEnglish() ? 'No photos available' : 'कुनै तस्विर छैन'; ?></h4>
                            <p class="text-muted"><?php echo isEnglish() ? 'No photos available at the moment.' : 'हाल कुनै तस्विर उपलब्ध छैन।'; ?></p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="row gallery-grid">
                <?php if (!empty($photos)): ?>
                    <?php foreach ($photos as $image):
                        $caption = trim((string)($image['title_np'] ?? '')) !== '' && !isEnglish()
                            ? (string)$image['title_np']
                            : (string)($image['title'] ?? '');
                    ?>
                    <div class="col-lg-3 col-md-4 col-sm-6 mb-4 gallery-item" data-category="<?php echo htmlspecialchars((string)($image['category'] ?? '')); ?>">
                        <div class="gallery-card">
                            <a href="<?php echo htmlspecialchars((string)$image['image']); ?>" data-lightbox="photos" data-title="<?php echo htmlspecialchars($caption); ?>">
                                <img src="<?php echo htmlspecialchars((string)$image['image']); ?>" loading="lazy" alt="<?php echo htmlspecialchars($caption); ?>" class="img-fluid">
                                <div class="gallery-overlay">
                                    <i class="fas fa-search-plus"></i>
                                </div>
                            </a>
                            <?php if ($caption !== ''): ?>
                            <div class="gallery-caption"><?php echo htmlspecialchars($caption); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-12">
                        <div class="empty-state text-center py-5">
                            <i class="lucide-icon fa-4x text-muted mb-3" aria-hidden="true" data-lucide="images"></i>
                            <h4><?php echo isEnglish() ? 'No photos in this album' : 'यो एल्बममा तस्विर छैन'; ?></h4>
                            <p class="text-muted">
                                <a href="<?php echo htmlspecialchars($galleryPageQs(1, 'photo', 'all')); ?>">
                                    <?php echo isEnglish() ? 'Browse all albums' : 'सबै एल्बम हेर्नुहोस्'; ?>
                                </a>
                            </p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($activeTab === 'photo' && $photoPages > 1): ?>
            <nav class="pagination-nav mt-4" aria-label="Gallery photo pages">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                    <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($galleryPageQs($page - 1, 'photo', $activeAlbum)); ?>"><i class="fas fa-chevron-left"></i></a></li>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $photoPages; $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="<?php echo htmlspecialchars($galleryPageQs($i, 'photo', $activeAlbum)); ?>"><?php echo $i; ?></a>
                    </li>
                    <?php endfor; ?>
                    <?php if ($page < $photoPages): ?>
                    <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($galleryPageQs($page + 1, 'photo', $activeAlbum)); ?>"><i class="fas fa-chevron-right"></i></a></li>
                    <?php endif; ?>
                </ul>
            </nav>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Videos Tab Content -->
        <div class="gallery-content" id="videosContent" style="<?php echo $activeTab !== 'video' ? 'display:none;' : ''; ?>">
            <div class="row gallery-grid">
                <?php if (!empty($videos)): ?>
                    <?php foreach ($videos as $video):
                        $videoId = '';
                        if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $video['video_url'] ?? '', $matches)) {
                            $videoId = $matches[1];
                        }
                        $thumbnail = $video['thumbnail'] ?? ($videoId ? 'https://img.youtube.com/vi/' . $videoId . '/maxresdefault.jpg' : '');
                    ?>
                    <div class="col-lg-4 col-md-6 mb-4 gallery-item" data-category="<?php echo htmlspecialchars((string)($video['category'] ?? '')); ?>">
                        <div class="video-card">
                            <a href="<?php echo htmlspecialchars((string)($video['video_url'] ?? '')); ?>" target="_blank" rel="noopener" class="video-link">
                                <div class="video-thumbnail">
                                    <img src="<?php echo htmlspecialchars((string)$thumbnail); ?>" loading="lazy" alt="<?php echo htmlspecialchars((string)($video['title'] ?? '')); ?>" class="img-fluid" onerror="this.onerror=null;this.src='https://img.youtube.com/vi/default/hqdefault.jpg';this.style.opacity='0.5'">
                                    <div class="video-play-btn">
                                        <i class="fab fa-youtube"></i>
                                    </div>
                                </div>
                                <?php if (!empty($video['title'])): ?>
                                <div class="video-caption">
                                    <i class="fab fa-youtube"></i>
                                    <?php echo htmlspecialchars((string)$video['title']); ?>
                                </div>
                                <?php endif; ?>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-12">
                        <div class="empty-state text-center py-5">
                            <i class="fab fa-youtube fa-4x text-muted mb-3"></i>
                            <h4><?php echo isEnglish() ? 'No videos available' : 'कुनै भिडियो छैन'; ?></h4>
                            <p class="text-muted"><?php echo isEnglish() ? 'No videos available at the moment.' : 'हाल कुनै भिडियो उपलब्ध छैन।'; ?></p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($activeTab === 'video' && $videoPages > 1): ?>
            <nav class="pagination-nav mt-4" aria-label="Gallery video pages">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                    <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($galleryPageQs($page - 1, 'video', $activeCategory, 'category')); ?>"><i class="fas fa-chevron-left"></i></a></li>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $videoPages; $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="<?php echo htmlspecialchars($galleryPageQs($i, 'video', $activeCategory, 'category')); ?>"><?php echo $i; ?></a>
                    </li>
                    <?php endfor; ?>
                    <?php if ($page < $videoPages): ?>
                    <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($galleryPageQs($page + 1, 'video', $activeCategory, 'category')); ?>"><i class="fas fa-chevron-right"></i></a></li>
                    <?php endif; ?>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Lightbox CSS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.3/css/lightbox.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.3/js/lightbox.min.js"></script>

<?php require_once 'includes/footer.php'; ?>
