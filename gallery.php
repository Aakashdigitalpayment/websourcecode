<?php
require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded
$pageTitle = isEnglish() ? 'Gallery' : 'ग्यालरी';
require_once 'includes/header.php';

// Get filter from URL
$activeTab = $_GET['type'] ?? 'photo';
$activeCategory = $_GET['category'] ?? 'all';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// Get gallery items - check if media_type column exists
$photos = [];
$videos = [];
$categories = [];
$photoTotal = 0;
$videoTotal = 0;
$photoPages = 1;
$videoPages = 1;

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

    $photoLimit = 24;
    $videoLimit = 12;

    /* Categories first so we can validate filter before COUNT/SELECT */
    $categories = $db->query("SELECT DISTINCT category FROM gallery WHERE is_active = 1 AND category IS NOT NULL AND category <> '' LIMIT 50")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (!in_array($activeTab, ['photo', 'video'], true)) {
        $activeTab = 'photo';
    }
    if ($activeCategory !== 'all' && !in_array($activeCategory, $categories, true)) {
        $activeCategory = 'all';
    }

    $catSql = '';
    $catParams = [];
    if ($activeCategory !== 'all') {
        $catSql = ' AND category = ?';
        $catParams[] = $activeCategory;
    }

    if ($hasMediaType) {
        $photoCnt = $db->prepare("SELECT COUNT(*) FROM gallery WHERE is_active = 1 AND (media_type = 'photo' OR media_type IS NULL)" . $catSql);
        $photoCnt->execute($catParams);
        $photoTotal = (int)$photoCnt->fetchColumn();

        $videoCnt = $db->prepare("SELECT COUNT(*) FROM gallery WHERE is_active = 1 AND media_type = 'video'" . $catSql);
        $videoCnt->execute($catParams);
        $videoTotal = (int)$videoCnt->fetchColumn();

        $photoPages = max(1, (int)ceil($photoTotal / $photoLimit));
        $videoPages = max(1, (int)ceil($videoTotal / $videoLimit));

        if ($activeTab === 'video') {
            if ($page > $videoPages) $page = $videoPages;
            $offset = ($page - 1) * $videoLimit;
            $vStmt = $db->prepare("SELECT * FROM gallery WHERE is_active = 1 AND media_type = 'video'" . $catSql . " ORDER BY id DESC LIMIT " . (int)$videoLimit . " OFFSET " . (int)$offset);
            $vStmt->execute($catParams);
            $videos = $vStmt->fetchAll() ?: [];
        } else {
            if ($page > $photoPages) $page = $photoPages;
            $offset = ($page - 1) * $photoLimit;
            $pStmt = $db->prepare("SELECT * FROM gallery WHERE is_active = 1 AND (media_type = 'photo' OR media_type IS NULL)" . $catSql . " ORDER BY id DESC LIMIT " . (int)$photoLimit . " OFFSET " . (int)$offset);
            $pStmt->execute($catParams);
            $photos = $pStmt->fetchAll() ?: [];
        }
    } else {
        $photoCnt = $db->prepare("SELECT COUNT(*) FROM gallery WHERE is_active = 1" . $catSql);
        $photoCnt->execute($catParams);
        $photoTotal = (int)$photoCnt->fetchColumn();
        $videoTotal = 0;
        $photoPages = max(1, (int)ceil($photoTotal / $photoLimit));
        if ($page > $photoPages) $page = $photoPages;
        $offset = ($page - 1) * $photoLimit;
        $pStmt = $db->prepare("SELECT * FROM gallery WHERE is_active = 1" . $catSql . " ORDER BY id DESC LIMIT " . (int)$photoLimit . " OFFSET " . (int)$offset);
        $pStmt->execute($catParams);
        $photos = $pStmt->fetchAll() ?: [];
        $videos = [];
    }
} catch (Throwable $e) {
    $photos = [];
    $videos = [];
    $categories = [];
    $photoTotal = 0;
    $videoTotal = 0;
    $photoPages = 1;
    $videoPages = 1;
}

if (!in_array($activeTab, ['photo', 'video'], true)) {
    $activeTab = 'photo';
}
if ($activeCategory !== 'all' && !in_array($activeCategory, $categories ?? [], true)) {
    $activeCategory = 'all';
}

$L = getLangStrings();
$galleryPageQs = static function (int $p, string $type, string $cat = 'all'): string {
    $q = ['type' => $type, 'page' => $p];
    if ($cat !== 'all') $q['category'] = $cat;
    return '?' . http_build_query($q);
};
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
                <a href="<?php echo htmlspecialchars($galleryPageQs(1, 'photo', $activeCategory)); ?>" class="gallery-tab <?php echo $activeTab === 'photo' ? 'active' : ''; ?>">
                    <i class="lucide-icon" aria-hidden="true" data-lucide="images"></i>
                    <span><?php echo isEnglish() ? 'Photos' : 'फोटोहरू'; ?></span>
                    <span class="tab-count"><?php echo (int)$photoTotal; ?></span>
                </a>
                <a href="<?php echo htmlspecialchars($galleryPageQs(1, 'video', $activeCategory)); ?>" class="gallery-tab <?php echo $activeTab === 'video' ? 'active' : ''; ?>">
                    <i class="fab fa-youtube"></i>
                    <span><?php echo isEnglish() ? 'Videos' : 'भिडियोहरू'; ?></span>
                    <span class="tab-count"><?php echo (int)$videoTotal; ?></span>
                </a>
            </div>

            <?php if (!empty($categories) && count($categories) > 1): ?>
            <!-- Category Dropdown Filter -->
            <div class="gallery-category-filter">
                <select id="categoryFilter" class="form-select" onchange="window.location.href=this.value">
                    <option value="<?php echo htmlspecialchars($galleryPageQs(1, $activeTab, 'all')); ?>" <?php echo $activeCategory === 'all' ? 'selected' : ''; ?>><?php echo isEnglish() ? 'All Categories' : 'सबै वर्ग'; ?></option>
                    <?php foreach ($categories as $cat):
                        $catLabels = [
                            'general' => isEnglish() ? 'General' : 'सामान्य',
                            'events' => isEnglish() ? 'Events' : 'कार्यक्रम',
                            'office' => isEnglish() ? 'Office' : 'कार्यालय',
                            'meetings' => isEnglish() ? 'Meetings' : 'बैठक'
                        ];
                    ?>
                    <option value="<?php echo htmlspecialchars($galleryPageQs(1, $activeTab, (string)$cat)); ?>" <?php echo $activeCategory === $cat ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($catLabels[$cat] ?? $cat); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>

        <!-- Photos Tab Content -->
        <div class="gallery-content" id="photosContent" style="<?php echo $activeTab !== 'photo' ? 'display:none;' : ''; ?>">
            <div class="row gallery-grid">
                <?php if (!empty($photos)): ?>
                    <?php foreach ($photos as $image): ?>
                    <div class="col-lg-3 col-md-4 col-sm-6 mb-4 gallery-item" data-category="<?php echo $image['category']; ?>">
                        <div class="gallery-card">
                            <a href="<?php echo $image['image']; ?>" data-lightbox="photos" data-title="<?php echo htmlspecialchars($image['title'] ?? ''); ?>">
                                <img src="<?php echo $image['image']; ?>" loading="lazy"  alt="<?php echo htmlspecialchars($image['title'] ?? ''); ?>" class="img-fluid">
                                <div class="gallery-overlay">
                                    <i class="fas fa-search-plus"></i>
                                </div>
                            </a>
                            <?php if (!empty($image['title'])): ?>
                            <div class="gallery-caption"><?php echo htmlspecialchars($image['title']); ?></div>
                            <?php endif; ?>
                        </div>
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
            <?php if ($activeTab === 'photo' && $photoPages > 1): ?>
            <nav class="pagination-nav mt-4" aria-label="Gallery photo pages">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                    <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($galleryPageQs($page - 1, 'photo', $activeCategory)); ?>"><i class="fas fa-chevron-left"></i></a></li>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $photoPages; $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="<?php echo htmlspecialchars($galleryPageQs($i, 'photo', $activeCategory)); ?>"><?php echo $i; ?></a>
                    </li>
                    <?php endfor; ?>
                    <?php if ($page < $photoPages): ?>
                    <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($galleryPageQs($page + 1, 'photo', $activeCategory)); ?>"><i class="fas fa-chevron-right"></i></a></li>
                    <?php endif; ?>
                </ul>
            </nav>
            <?php endif; ?>
        </div>

        <!-- Videos Tab Content -->
        <div class="gallery-content" id="videosContent" style="<?php echo $activeTab !== 'video' ? 'display:none;' : ''; ?>">
            <div class="row gallery-grid">
                <?php if (!empty($videos)): ?>
                    <?php foreach ($videos as $video):
                        // Extract YouTube video ID
                        $videoId = '';
                        if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $video['video_url'] ?? '', $matches)) {
                            $videoId = $matches[1];
                        }
                        $thumbnail = $video['thumbnail'] ?? ($videoId ? 'https://img.youtube.com/vi/' . $videoId . '/maxresdefault.jpg' : '');
                    ?>
                    <div class="col-lg-4 col-md-6 mb-4 gallery-item" data-category="<?php echo $video['category']; ?>">
                        <div class="video-card">
                            <a href="<?php echo $video['video_url'] ?? ''; ?>" target="_blank" class="video-link">
                                <div class="video-thumbnail">
                                    <img src="<?php echo $thumbnail; ?>" loading="lazy"  alt="<?php echo htmlspecialchars($video['title'] ?? ''); ?>" class="img-fluid" onerror="this.onerror=null;this.src='https://img.youtube.com/vi/default/hqdefault.jpg';this.style.opacity='0.5'">
                                    <div class="video-play-btn">
                                        <i class="fab fa-youtube"></i>
                                    </div>
                                </div>
                                <?php if (!empty($video['title'])): ?>
                                <div class="video-caption">
                                    <i class="fab fa-youtube"></i>
                                    <?php echo htmlspecialchars($video['title']); ?>
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
                    <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($galleryPageQs($page - 1, 'video', $activeCategory)); ?>"><i class="fas fa-chevron-left"></i></a></li>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $videoPages; $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="<?php echo htmlspecialchars($galleryPageQs($i, 'video', $activeCategory)); ?>"><?php echo $i; ?></a>
                    </li>
                    <?php endfor; ?>
                    <?php if ($page < $videoPages): ?>
                    <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($galleryPageQs($page + 1, 'video', $activeCategory)); ?>"><i class="fas fa-chevron-right"></i></a></li>
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
