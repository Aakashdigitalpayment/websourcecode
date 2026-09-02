<?php
require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded

$L = getLangStrings();
$noticeId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$singleNotice = null;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 12;
$offset = ($page - 1) * $perPage;
$notices = [];
$totalNotices = 0;
$totalPages = 1;
$pageJsonLd = [];
$seoBreadcrumbs = [
    ['name' => $L['home'], 'url' => SITE_URL],
    ['name' => isEnglish() ? 'Notices' : 'सूचनाहरू', 'url' => rtrim(SITE_URL, '/') . '/notices.php'],
];

// Load notice BEFORE header so title/description/canonical/OG are unique
try {
    $db = getDB();

    if ($noticeId > 0) {
        $stmt = $db->prepare("SELECT * FROM notices WHERE id = ? AND is_active = 1");
        $stmt->execute([$noticeId]);
        $singleNotice = $stmt->fetch();
        if (!$singleNotice) {
            redirect('notices.php');
        }
    } else {
        $totalNotices = (int)$db->query("SELECT COUNT(*) FROM notices WHERE is_active = 1")->fetchColumn();
        $totalPages = max(1, (int)ceil($totalNotices / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $stmt = $db->prepare("SELECT * FROM notices WHERE is_active = 1 ORDER BY id DESC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $notices = $stmt->fetchAll() ?: [];
    }
} catch (Throwable $e) {
    $notices = [];
    $totalNotices = 0;
    $totalPages = 1;
    /* Keep $singleNotice if already loaded — list query failure must not wipe detail view */
}

if ($singleNotice) {
    $pageTitle = trim((string) ($singleNotice['title'] ?? ''));
    if ($pageTitle === '') {
        $pageTitle = isEnglish() ? 'Notice' : 'सूचना';
    }
    $pageDescription = function_exists('seo_meta_description_from_html')
        ? seo_meta_description_from_html((string) ($singleNotice['content'] ?? ''))
        : '';
    if ($pageDescription === '') {
        $pageDescription = $pageTitle . (isEnglish()
            ? ' — Official notice from our cooperative.'
            : ' — हाम्रो सहकारीको आधिकारिक सूचना।');
    }
    $pageOgType = 'article';
    $pageOgImageAlt = $pageTitle;
    $attach = trim((string) ($singleNotice['attachment'] ?? ''));
    if ($attach !== '' && function_exists('safe_public_upload_path')) {
        $safeAtt = safe_public_upload_path($attach);
        if ($safeAtt !== '' && preg_match('/\.(jpe?g|png|webp|gif)$/i', $safeAtt)) {
            $pageOgImage = $safeAtt;
        }
    }
    $seoBreadcrumbs[] = ['name' => $pageTitle];
    if (function_exists('seo_news_article_json_ld') && function_exists('seo_canonical_url')) {
        $pageJsonLd[] = seo_news_article_json_ld(
            $pageTitle,
            $pageDescription,
            seo_canonical_url(),
            (string) ($singleNotice['notice_date'] ?? $singleNotice['created_at'] ?? ''),
            isset($pageOgImage) ? (string) $pageOgImage : '',
            isEnglish()
        );
    }
} else {
    $pageTitle = isEnglish() ? 'Notices' : 'सूचनाहरू';
    $pageDescription = isEnglish()
        ? 'Official notices and announcements from our cooperative — stay updated with the latest circulars and information.'
        : 'हाम्रो सहकारीका आधिकारिक सूचना तथा घोषणाहरू — नवीनतम परिपत्र र जानकारीसँग अपडेट रहनुहोस्।';
}

require_once 'includes/header.php';
?>
<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <?php if ($singleNotice): ?>
        <p class="mb-1 small opacity-75"><?php echo $L['notices']; ?></p>
        <?php else: ?>
        <h1><?php echo $L['notices']; ?></h1>
        <?php endif; ?>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <?php if ($singleNotice): ?>
                <li class="breadcrumb-item"><a href="notices.php"><?php echo $L['notices']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo e(truncateText((string)$singleNotice['title'], 40)); ?></li>
                <?php else: ?>
                <li class="breadcrumb-item active"><?php echo $L['notices']; ?></li>
                <?php endif; ?>
            </ol>
        </nav>
    </div>
</section>

<!-- Notices Section -->
<section class="notices-section section-padding">
    <div class="container">
        <?php if (!$singleNotice): ?>
        <div class="section-header section-header-unified text-center mb-5" data-aos="fade-up">
            <div class="section-badge-wrap">
                <span class="section-badge"><i class="fas fa-bullhorn"></i> <?php echo $L['notices']; ?></span>
            </div>
            <h2><?php echo isEnglish() ? 'Latest Notices & Announcements' : 'नवीनतम सूचना तथा घोषणाहरू'; ?></h2>
            <div class="section-divider"></div>
            <p><?php echo isEnglish() ? 'Stay updated with our latest news and announcements' : 'हाम्रा नवीनतम समाचार र घोषणाहरूसँग अपडेट रहनुहोस्'; ?></p>
        </div>
        <?php endif; ?>

        <?php if ($singleNotice): ?>
        <!-- Single Notice View -->
        <div class="row">
            <div class="col-lg-8 mx-auto">
                <div class="notice-detail-card">
                    <div class="notice-header">
                        <span class="notice-date">
                            <i class="fas fa-calendar-alt"></i>
                            <?php echo formatDate($singleNotice['notice_date'], 'Y-m-d'); ?>
                        </span>
                        <h1><?php echo e($singleNotice['title']); ?></h1>
                    </div>
                    <div class="notice-content coop-prose">
                        <?php echo function_exists('coop_render_cms_prose')
                            ? coop_render_cms_prose($singleNotice['content'] ?? '')
                            : coop_sanitize_cms_html($singleNotice['content'] ?? ''); ?>
                    </div>
                    <?php if ($singleNotice['attachment']):
                        $attUrl = function_exists('coop_notice_media_src')
                            ? coop_notice_media_src($singleNotice['attachment'])
                            : safe_media_src($singleNotice['attachment']);
                        $attIsPdf = (bool)preg_match('/\.pdf$/i', (string)$singleNotice['attachment']);
                        $attIsImg = (bool)preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', (string)$singleNotice['attachment']);
                    ?>
                    <div class="notice-attachment">
                        <?php if ($attIsImg && $attUrl !== ''): ?>
                        <div class="notice-inline-image mb-3">
                            <img src="<?php echo e($attUrl); ?>" alt="" loading="lazy" decoding="async">
                        </div>
                        <?php endif; ?>
                        <?php if ($attUrl !== ''): ?>
                        <a href="<?php echo e($attUrl); ?>" class="btn nts-btn-primary" target="_blank" rel="noopener noreferrer">
                            <i class="fas <?php echo $attIsPdf ? 'fa-file-pdf' : ($attIsImg ? 'fa-image' : 'fa-paperclip'); ?>"></i>
                            <?php echo isEnglish() ? 'View full notice' : 'पूरा सूचना हेर्नुहोस्'; ?>
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="notice-footer">
                        <a href="notices.php" class="btn btn-outline-primary">
                            <i class="fas fa-arrow-left"></i> <?php echo isEnglish() ? 'View all notices' : 'सबै सूचनाहरू हेर्नुहोस्'; ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- All Notices List -->
        <div class="row">
            <?php if (!empty($notices)): ?>
                <?php foreach ($notices as $notice): ?>
                <div class="col-lg-6 mb-4">
                    <div class="notice-card">
                        <div class="notice-icon">
                            <i class="fas fa-bullhorn"></i>
                        </div>
                        <div class="notice-content">
                            <span class="notice-date">
                                <i class="fas fa-calendar-alt"></i>
                                <?php echo formatDate($notice['notice_date'], 'Y-m-d'); ?>
                            </span>
                            <h5><a href="notices.php?id=<?php echo $notice['id']; ?>"><?php echo e($notice['title']); ?></a></h5>
                            <p><?php echo e(truncateText(strip_tags((string)($notice['content'] ?? '')), 100)); ?></p>
                            <a href="notices.php?id=<?php echo $notice['id']; ?>" class="read-more">
                                <?php echo isEnglish() ? 'Read more' : 'थप पढ्नुहोस्'; ?> <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                        <?php if ($notice['attachment']):
                            $attUrl = function_exists('coop_public_download_url')
                                ? coop_public_download_url($notice['attachment'])
                                : (function_exists('coop_notice_media_src') ? coop_notice_media_src($notice['attachment']) : safe_media_src($notice['attachment']));
                            if ($attUrl !== ''):
                        ?>
                        <div class="notice-attachment-icon">
                            <a href="<?php echo e($attUrl); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo isEnglish() ? 'View attachment' : 'संलग्न फाइल हेर्नुहोस्'; ?>">
                                <i class="fas fa-paperclip"></i>
                            </a>
                        </div>
                        <?php endif; endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="empty-state text-center py-5">
                        <i class="fas fa-clipboard-list fa-4x nts-empty-icon mb-3"></i>
                        <h4><?php echo isEnglish() ? 'No notices yet' : 'कुनै सूचना छैन'; ?></h4>
                        <p class="nts-muted"><?php echo isEnglish() ? 'No notices are available at this time.' : 'हाल कुनै सूचना उपलब्ध छैन।'; ?></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($totalPages > 1 && !$singleNotice):
            $pageWindow = 2;
            $startPage = max(1, $page - $pageWindow);
            $endPage = min($totalPages, $page + $pageWindow);
            if ($page <= 3) {
                $endPage = min($totalPages, 5);
            }
            if ($page >= $totalPages - 2) {
                $startPage = max(1, $totalPages - 4);
            }
        ?>
        <nav class="pagination-nav mt-4" aria-label="<?php echo isEnglish() ? 'Notices pages' : 'सूचना पृष्ठ'; ?>">
            <ul class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $page - 1; ?>" aria-label="<?php echo isEnglish() ? 'Previous page' : 'अघिल्लो पृष्ठ'; ?>"><i class="fas fa-chevron-left"></i></a>
                </li>
                <?php endif; ?>
                <?php if ($startPage > 1): ?>
                <li class="page-item"><a class="page-link" href="?page=1">1</a></li>
                <?php if ($startPage > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                <?php endif; ?>
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                </li>
                <?php endfor; ?>
                <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                <li class="page-item"><a class="page-link" href="?page=<?php echo $totalPages; ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $page + 1; ?>" aria-label="<?php echo isEnglish() ? 'Next page' : 'अर्को पृष्ठ'; ?>"><i class="fas fa-chevron-right"></i></a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>
