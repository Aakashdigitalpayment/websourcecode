<?php
require_once 'includes/config.php';

// Get news ID
$newsId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$pageOgImage = '';

// Get news item
try {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM news WHERE id = ? AND is_active = 1");
    $stmt->execute([$newsId]);
    $news = $stmt->fetch();

    if (!$news) {
        redirect('news.php');
    }

    $pageTitle = getLangField($news, 'title');
    $bodyForDesc = getLangField($news, 'content');
    $pageDescription = function_exists('seo_meta_description_from_html')
        ? seo_meta_description_from_html($bodyForDesc)
        : '';
    if ($pageDescription === '') {
        $pageDescription = $pageTitle;
    }
    $imgRaw = trim((string) ($news['image'] ?? ''));
    if ($imgRaw !== '' && ($safe = safe_public_upload_path($imgRaw)) !== '') {
        $pageOgImage = $safe;
    }

    // Get related news (other news)
    $relatedStmt = $db->prepare("SELECT * FROM news WHERE id != ? AND is_active = 1 ORDER BY created_at DESC LIMIT 3");
    $relatedStmt->execute([$newsId]);
    $relatedNews = $relatedStmt->fetchAll();

} catch (Exception $e) {
    redirect('news.php');
}

require_once 'includes/header.php';
$L = getLangStrings();
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo isEnglish() ? 'News Detail' : 'समाचार विवरण'; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item"><a href="news.php"><?php echo isEnglish() ? 'News' : 'समाचार'; ?></a></li>
                <li class="breadcrumb-item active"><?php echo truncateText(getLangField($news, 'title'), 30); ?></li>
            </ol>
        </nav>
    </div>
</section>

<!-- News Detail Content -->
<section class="section-padding">
    <div class="container">
        <div class="row">
            <!-- Main Content -->
            <div class="col-lg-8">
                <article class="news-detail-article">
                    <div class="news-detail-header">
                        <h1><?php echo getLangField($news, 'title'); ?></h1>
                        <div class="news-meta">
                            <span class="news-date">
                                <i class="fas fa-calendar-alt"></i>
                                <?php echo date('Y-m-d', strtotime($news['created_at'])); ?>
                            </span>
                        </div>
                    </div>

                    <?php if (!empty($news['image'])): ?>
                    <div class="news-detail-image">
                        <img src="<?php echo $news['image']; ?>" loading="lazy"  alt="<?php echo getLangField($news, 'title'); ?>">
                    </div>
                    <?php endif; ?>

                    <div class="news-detail-content coop-prose">
                        <?php echo getLangField($news, 'content'); ?>
                    </div>

                    <div class="news-share">
                        <span><?php echo isEnglish() ? 'Share:' : 'सेयर गर्नुहोस्:'; ?></span>
                        <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode(SITE_URL . 'news-detail.php?id=' . $news['id']); ?>" target="_blank" class="share-btn facebook">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode(SITE_URL . 'news-detail.php?id=' . $news['id']); ?>&text=<?php echo urlencode(getLangField($news, 'title')); ?>" target="_blank" class="share-btn twitter">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="https://wa.me/?text=<?php echo urlencode(getLangField($news, 'title') . ' ' . SITE_URL . 'news-detail.php?id=' . $news['id']); ?>" target="_blank" class="share-btn whatsapp">
                            <i class="fab fa-whatsapp"></i>
                        </a>
                    </div>

                    <div class="news-navigation">
                        <a href="news.php" class="btn btn-outline-primary">
                            <i class="fas fa-arrow-left"></i> <?php echo isEnglish() ? 'Back to News' : 'समाचारमा फर्कनुहोस्'; ?>
                        </a>
                    </div>
                </article>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                <?php if (!empty($relatedNews)): ?>
                <div class="sidebar-widget">
                    <h4 class="widget-title"><?php echo isEnglish() ? 'Related News' : 'सम्बन्धित समाचार'; ?></h4>
                    <div class="related-news-list">
                        <?php foreach ($relatedNews as $related): ?>
                        <div class="related-news-item">
                            <div class="related-news-image">
                                <?php if (!empty($related['image'])): ?>
                                <img src="<?php echo $related['image']; ?>" loading="lazy"  alt="<?php echo getLangField($related, 'title'); ?>">
                                <?php else: ?>
                                <div class="related-placeholder">
                                    <i class="fas fa-newspaper"></i>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="related-news-info">
                                <h5><a href="news-detail.php?id=<?php echo $related['id']; ?>"><?php echo truncateText(getLangField($related, 'title'), 50); ?></a></h5>
                                <span class="date"><?php echo date('Y-m-d', strtotime($related['created_at'])); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="sidebar-widget">
                    <h4 class="widget-title"><?php echo isEnglish() ? 'Quick Links' : 'द्रुत लिंकहरू'; ?></h4>
                    <ul class="quick-links">
                        <li><a href="notices.php"><i class="fas fa-bullhorn"></i> <?php echo isEnglish() ? 'Notices' : 'सूचनाहरू'; ?></a></li>
                        <li><a href="downloads.php"><i class="fas fa-download"></i> <?php echo isEnglish() ? 'Downloads' : 'डाउनलोडहरू'; ?></a></li>
                        <li><a href="gallery.php"><i class="fas fa-images"></i> <?php echo isEnglish() ? 'Gallery' : 'ग्यालरी'; ?></a></li>
                        <li><a href="contact.php"><i class="fas fa-envelope"></i> <?php echo isEnglish() ? 'Contact Us' : 'सम्पर्क'; ?></a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>


<?php require_once 'includes/footer.php'; ?>
