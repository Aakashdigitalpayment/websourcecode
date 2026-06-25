<?php
require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded
$pageTitle = isEnglish() ? 'News & Activities' : 'समाचार तथा क्रियाकलापहरू';
require_once 'includes/header.php';
$L = getLangStrings();

// Get page number
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 9;
$offset = ($page - 1) * $perPage;

// Get news from database
try {
    $db = getDB();

    // Get total count
    $countStmt = $db->query("SELECT COUNT(*) FROM news WHERE is_active = 1");
    $totalNews = $countStmt->fetchColumn();
    $totalPages = ceil($totalNews / $perPage);

    // Get news with pagination
    $stmt = $db->prepare("SELECT * FROM news WHERE is_active = 1 ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $news = $stmt->fetchAll();
} catch (Throwable $e) {
    $news = [];
    $totalPages = 1;
}
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo isEnglish() ? 'News & Activities' : 'समाचार तथा क्रियाकलापहरू'; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo isEnglish() ? 'News' : 'समाचार'; ?></li>
            </ol>
        </nav>
    </div>
</section>

<!-- News Content -->
<section class="section-padding">
    <div class="container">
        <?php if (!empty($news)): ?>
        <div class="row">
            <?php foreach ($news as $item): ?>
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="news-card">
                    <div class="news-image">
                        <?php if (!empty($item['image'])): ?>
                        <img src="<?php echo $item['image']; ?>" loading="lazy"  alt="<?php echo e(getLangField($item, 'title')); ?>">
                        <?php else: ?>
                        <div class="news-placeholder">
                            <i class="lucide-icon" aria-hidden="true" data-lucide="newspaper"></i>
                        </div>
                        <?php endif; ?>
                        <div class="news-date">
                            <span class="day"><?php echo date('d', strtotime($item['created_at'])); ?></span>
                            <span class="month"><?php echo date('M', strtotime($item['created_at'])); ?></span>
                        </div>
                    </div>
                    <div class="news-content">
                        <h4><?php echo getLangField($item, 'title'); ?></h4>
                        <p><?php echo truncateText(getLangField($item, 'content'), 120); ?></p>
                        <a href="news-detail.php?id=<?php echo $item['id']; ?>" class="read-more">
                            <?php echo isEnglish() ? 'Read More' : 'थप पढ्नुहोस्'; ?> <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav class="pagination-nav mt-5">
            <ul class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $page - 1; ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                </li>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                </li>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $page + 1; ?>">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="chevron-right"></i>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
        <?php endif; ?>

        <?php else: ?>
        <!-- Empty State -->
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="news-card text-center">
                    <div class="news-image">
                        <div class="news-placeholder">
                            <i class="lucide-icon" aria-hidden="true" data-lucide="newspaper"></i>
                        </div>
                    </div>
                    <div class="news-content py-4">
                        <h4><?php echo isEnglish() ? 'No news published yet' : 'अहिलेसम्म समाचार प्रकाशित छैन'; ?></h4>
                        <p><?php echo isEnglish() ? 'Please check back later for latest updates and activities.' : 'नयाँ अपडेट तथा गतिविधिका लागि केही समयपछि पुनः हेर्नुहोस्।'; ?></p>
                        <a href="<?php echo SITE_URL; ?>notices.php" class="read-more">
                            <?php echo isEnglish() ? 'View Notices' : 'सूचनाहरू हेर्नुहोस्'; ?> <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>
