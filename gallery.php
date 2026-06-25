<?php
require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded
$pageTitle = isEnglish() ? 'Gallery' : 'ग्यालरी';
require_once 'includes/header.php';

// Get filter from URL
$activeTab = $_GET['type'] ?? 'photo';
$activeCategory = $_GET['category'] ?? 'all';

// Get gallery items - check if media_type column exists
$photos = [];
$videos = [];
$categories = [];

try {
    $db = getDB();

    // Check if media_type column exists
    $hasMediaType = false;
    try {
        $checkCol = $db->query("SHOW COLUMNS FROM gallery LIKE 'media_type'");
        $hasMediaType = $checkCol && $checkCol->fetch() !== false;
    } catch (Throwable $e) {
        $hasMediaType = false;
    }

    if ($hasMediaType) {
        // Separate photos and videos
        $photos = $db->query("SELECT * FROM gallery WHERE is_active = 1 AND (media_type = 'photo' OR media_type IS NULL) ORDER BY id DESC")->fetchAll();
        $videos = $db->query("SELECT * FROM gallery WHERE is_active = 1 AND media_type = 'video' ORDER BY id DESC")->fetchAll();
    } else {
        // All items are photos
        $photos = $db->query("SELECT * FROM gallery WHERE is_active = 1 ORDER BY id DESC")->fetchAll();
        $videos = [];
    }

    // Get unique categories
    $categories = $db->query("SELECT DISTINCT category FROM gallery WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $photos = [];
    $videos = [];
    $categories = [];
}

if (!in_array($activeTab, ['photo', 'video'], true)) {
    $activeTab = 'photo';
}
if ($activeCategory !== 'all' && !in_array($activeCategory, $categories, true)) {
    $activeCategory = 'all';
}

$L = getLangStrings();
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
                <a href="?type=photo" class="gallery-tab <?php echo $activeTab === 'photo' ? 'active' : ''; ?>">
                    <i class="lucide-icon" aria-hidden="true" data-lucide="images"></i>
                    <span><?php echo isEnglish() ? 'Photos' : 'फोटोहरू'; ?></span>
                    <span class="tab-count"><?php echo count($photos); ?></span>
                </a>
                <a href="?type=video" class="gallery-tab <?php echo $activeTab === 'video' ? 'active' : ''; ?>">
                    <i class="fab fa-youtube"></i>
                    <span><?php echo isEnglish() ? 'Videos' : 'भिडियोहरू'; ?></span>
                    <span class="tab-count"><?php echo count($videos); ?></span>
                </a>
            </div>

            <?php if (!empty($categories) && count($categories) > 1): ?>
            <!-- Category Dropdown Filter -->
            <div class="gallery-category-filter">
                <select id="categoryFilter" class="form-select" onchange="filterByCategory(this.value)">
                    <option value="all"><?php echo isEnglish() ? 'All Categories' : 'सबै वर्ग'; ?></option>
                    <?php foreach ($categories as $cat):
                        $catLabels = [
                            'general' => isEnglish() ? 'General' : 'सामान्य',
                            'events' => isEnglish() ? 'Events' : 'कार्यक्रम',
                            'office' => isEnglish() ? 'Office' : 'कार्यालय',
                            'meetings' => isEnglish() ? 'Meetings' : 'बैठक'
                        ];
                    ?>
                    <option value="<?php echo $cat; ?>" <?php echo $activeCategory === $cat ? 'selected' : ''; ?>>
                        <?php echo $catLabels[$cat] ?? $cat; ?>
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
                                    <img src="<?php echo $thumbnail; ?>" loading="lazy"  alt="<?php echo htmlspecialchars($video['title'] ?? ''); ?>" class="img-fluid" onerror="this.src='assets/images/video-placeholder.png'">
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
        </div>
    </div>
</section>

<!-- Lightbox CSS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.3/css/lightbox.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.3/js/lightbox.min.js"></script>


<script>
// Category Filter
function filterByCategory(category) {
    var activeContent = document.querySelector('.gallery-content:not([style*="display: none"])');
    if (!activeContent) activeContent = document.getElementById('photosContent');

    var items = activeContent.querySelectorAll('.gallery-item');
    items.forEach(function(item) {
        if (category === 'all' || item.dataset.category === category) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
}

// Initialize filter from URL if category is set
document.addEventListener('DOMContentLoaded', function() {
    var urlParams = new URLSearchParams(window.location.search);
    var category = urlParams.get('category');
    if (category) {
        filterByCategory(category);
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
