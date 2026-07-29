<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/includes/gallery-albums.php';

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
$albums = [];
$photoTotal = 0;
$videoTotal = 0;
$albumPhotoTotal = 0;
$photoPages = 1;
$videoPages = 1;
$activeAlbumId = 0;
$activeAlbumRow = null;
$showAlbumCovers = true;
$hasMediaType = false;

try {
    $db = getDB();
    ensureGalleryAlbumsSchema($db);
    $hasMediaType = galleryHasMediaTypeColumn($db);

    $photoLimit = 24;
    $videoLimit = 12;
    $photoWhere = $hasMediaType
        ? "is_active = 1 AND (media_type = 'photo' OR media_type IS NULL OR media_type = '')"
        : 'is_active = 1';
    $videoWhere = $hasMediaType
        ? "is_active = 1 AND media_type = 'video'"
        : 'is_active = 0';

    if (!in_array($activeTab, ['photo', 'video'], true)) {
        $activeTab = 'photo';
    }

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

    $albums = galleryFetchPublicAlbums($db, $hasMediaType);

    if ($activeTab === 'photo') {
        if ($albumParamRaw === null || $albumParamRaw === '' || $albumParamRaw === 'all') {
            $showAlbumCovers = true;
            $activeAlbumId = 0;
            $activeAlbumRow = null;
        } else {
            $activeAlbumRow = galleryResolveAlbumParam($db, $albumParamRaw);
            if ($activeAlbumRow) {
                $activeAlbumId = (int)$activeAlbumRow['id'];
                $showAlbumCovers = false;
            } else {
                $showAlbumCovers = true;
                $activeAlbumId = 0;
            }
        }
    }

    if ($hasMediaType) {
        $photoTotal = (int)$db->query("SELECT COUNT(*) FROM gallery WHERE {$photoWhere}")->fetchColumn();
        $videoCnt = $db->prepare("SELECT COUNT(*) FROM gallery WHERE {$videoWhere}" . $catSql);
        $videoCnt->execute($catParams);
        $videoTotal = (int)$videoCnt->fetchColumn();
    } else {
        $photoTotal = (int)$db->query('SELECT COUNT(*) FROM gallery WHERE is_active = 1')->fetchColumn();
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
            $videos = $vStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } elseif (!$showAlbumCovers && $activeAlbumId > 0) {
        $result = galleryFetchAlbumPhotos($db, $activeAlbumId, $page, $photoLimit, $hasMediaType);
        $photos = $result['photos'];
        $albumPhotoTotal = $result['total'];
        $photoPages = $result['pages'];
        $page = $result['page'];
    }
} catch (Throwable $e) {
    $photos = [];
    $videos = [];
    $categories = [];
    $albums = [];
    $photoTotal = 0;
    $videoTotal = 0;
    $showAlbumCovers = true;
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
    } elseif ($type === 'photo') {
        $q['album'] = $albumOrCat;
    } elseif ($albumOrCat !== 'all') {
        $q['category'] = $albumOrCat;
    }
    return '?' . http_build_query($q);
};

$activeAlbumLabel = $activeAlbumRow ? galleryAlbumLabel($activeAlbumRow, isEnglish()) : '';
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

<section class="gallery-section section-padding">
    <div class="container">
        <div class="gallery-tabs-wrapper">
            <div class="gallery-tabs">
                <a href="<?php echo htmlspecialchars($galleryPageQs(1, 'photo', 'all')); ?>" class="gallery-tab <?php echo $activeTab === 'photo' ? 'active' : ''; ?>">
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
            <div class="gallery-category-filter gallery-album-filter">
                <label class="visually-hidden" for="albumFilter"><?php echo isEnglish() ? 'Album' : 'एल्बम'; ?></label>
                <select id="albumFilter" class="form-select" onchange="window.location.href=this.value">
                    <option value="<?php echo htmlspecialchars($galleryPageQs(1, 'photo', 'all')); ?>" <?php echo $showAlbumCovers ? 'selected' : ''; ?>>
                        <?php echo isEnglish() ? 'All albums' : 'सबै एल्बम'; ?>
                    </option>
                    <?php foreach ($albums as $alb): ?>
                    <option value="<?php echo htmlspecialchars($galleryPageQs(1, 'photo', (string)(int)$alb['id'])); ?>"
                        <?php echo !$showAlbumCovers && $activeAlbumId === (int)$alb['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(galleryAlbumLabel($alb, isEnglish())); ?>
                        (<?php echo (int)$alb['count']; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php elseif ($activeTab === 'video' && !empty($categories) && count($categories) > 1): ?>
            <div class="gallery-category-filter">
                <select id="categoryFilter" class="form-select" onchange="window.location.href=this.value">
                    <option value="<?php echo htmlspecialchars($galleryPageQs(1, 'video', 'all', 'category')); ?>" <?php echo $activeCategory === 'all' ? 'selected' : ''; ?>><?php echo isEnglish() ? 'All Categories' : 'सबै वर्ग'; ?></option>
                    <?php
                    $catLabels = galleryAlbumCategoryLabels(isEnglish());
                    foreach ($categories as $cat):
                    ?>
                    <option value="<?php echo htmlspecialchars($galleryPageQs(1, 'video', (string)$cat, 'category')); ?>" <?php echo $activeCategory === $cat ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($catLabels[$cat] ?? $cat); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($activeTab === 'photo' && !$showAlbumCovers && $activeAlbumRow): ?>
        <div class="gallery-album-heading">
            <h2 class="h5 mb-0">
                <i class="fas fa-folder-open me-2 text-success" aria-hidden="true"></i>
                <?php echo htmlspecialchars($activeAlbumLabel); ?>
                <small class="text-muted fw-normal ms-1">(<?php echo (int)$albumPhotoTotal; ?>)</small>
            </h2>
            <a class="gallery-album-all-link" href="<?php echo htmlspecialchars($galleryPageQs(1, 'photo', 'all')); ?>">
                <?php echo isEnglish() ? 'All albums' : 'सबै एल्बम'; ?>
            </a>
        </div>
        <?php endif; ?>

        <div class="gallery-content" id="photosContent" style="<?php echo $activeTab !== 'photo' ? 'display:none;' : ''; ?>">
            <?php if ($showAlbumCovers): ?>
            <div class="row gallery-grid gallery-album-grid">
                <?php if (!empty($albums)): ?>
                    <?php foreach ($albums as $alb):
                        $cover = (string)($alb['cover'] ?? '');
                        $href = $galleryPageQs(1, 'photo', (string)(int)$alb['id']);
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
                            <div class="gallery-album-title"><?php echo htmlspecialchars(galleryAlbumLabel($alb, isEnglish())); ?></div>
                        </a>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-12">
                        <div class="empty-state text-center py-5">
                            <i class="lucide-icon fa-4x text-muted mb-3" aria-hidden="true" data-lucide="images"></i>
                            <h4><?php echo isEnglish() ? 'No albums yet' : 'अहिले कुनै एल्बम छैन'; ?></h4>
                            <p class="text-muted"><?php echo isEnglish() ? 'Photos will appear here once albums are added.' : 'एल्बम थपिएपछि यहाँ देखिनेछ।'; ?></p>
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
                    <div class="col-lg-3 col-md-4 col-sm-6 mb-4 gallery-item">
                        <div class="gallery-card">
                            <a href="<?php echo htmlspecialchars((string)$image['image']); ?>" data-lightbox="photos" data-title="<?php echo htmlspecialchars($caption); ?>">
                                <img src="<?php echo htmlspecialchars((string)$image['image']); ?>" loading="lazy" alt="<?php echo htmlspecialchars($caption); ?>" class="img-fluid">
                                <div class="gallery-overlay"><i class="fas fa-search-plus"></i></div>
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
            <?php if ($photoPages > 1): ?>
            <nav class="pagination-nav mt-4" aria-label="Gallery photo pages">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                    <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($galleryPageQs($page - 1, 'photo', (string)$activeAlbumId)); ?>"><i class="fas fa-chevron-left"></i></a></li>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $photoPages; $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="<?php echo htmlspecialchars($galleryPageQs($i, 'photo', (string)$activeAlbumId)); ?>"><?php echo $i; ?></a>
                    </li>
                    <?php endfor; ?>
                    <?php if ($page < $photoPages): ?>
                    <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($galleryPageQs($page + 1, 'photo', (string)$activeAlbumId)); ?>"><i class="fas fa-chevron-right"></i></a></li>
                    <?php endif; ?>
                </ul>
            </nav>
            <?php endif; ?>
            <?php endif; ?>
        </div>

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
                    <div class="col-lg-4 col-md-6 mb-4 gallery-item">
                        <div class="video-card">
                            <a href="<?php echo htmlspecialchars((string)($video['video_url'] ?? '')); ?>" target="_blank" rel="noopener" class="video-link">
                                <div class="video-thumbnail">
                                    <img src="<?php echo htmlspecialchars((string)$thumbnail); ?>" loading="lazy" alt="<?php echo htmlspecialchars((string)($video['title'] ?? '')); ?>" class="img-fluid" onerror="this.onerror=null;this.src='https://img.youtube.com/vi/default/hqdefault.jpg';this.style.opacity='0.5'">
                                    <div class="video-play-btn"><i class="fab fa-youtube"></i></div>
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

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.3/css/lightbox.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.3/js/lightbox.min.js"></script>

<?php require_once 'includes/footer.php'; ?>
