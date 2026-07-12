<?php
require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded
$pageTitle = isEnglish() ? 'Notices' : 'सूचनाहरू';
require_once 'includes/header.php';

$L = getLangStrings();
$noticeId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$singleNotice = null;

// Get single notice or all notices
try {
    $db = getDB();

    if ($noticeId > 0) {
        $stmt = $db->prepare("SELECT * FROM notices WHERE id = ? AND is_active = 1");
        $stmt->execute([$noticeId]);
        $singleNotice = $stmt->fetch();
    }

    $notices = $db->query("SELECT * FROM notices WHERE is_active = 1 ORDER BY id DESC")->fetchAll();
} catch (Throwable $e) {
    $notices = [];
    $singleNotice = null;
}
?>
<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo $L['notices']; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo $L['notices']; ?></li>
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
                        <h2><?php echo e($singleNotice['title']); ?></h2>
                    </div>
                    <div class="notice-content coop-prose">
                        <?php echo $singleNotice['content']; ?>
                    </div>
                    <?php if ($singleNotice['attachment']): ?>
                    <div class="notice-attachment">
                        <a href="<?php echo $singleNotice['attachment']; ?>" class="btn nts-btn-primary" target="_blank">
                            <i class="fas fa-download"></i> फाइल डाउनलोड गर्नुहोस्
                        </a>
                    </div>
                    <?php endif; ?>
                    <div class="notice-footer">
                        <a href="notices.php" class="btn btn-outline-primary">
                            <i class="fas fa-arrow-left"></i> सबै सूचनाहरू हेर्नुहोस्
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
                            <h5><a href="notices.php?id=<?php echo $notice['id']; ?>"><?php echo $notice['title']; ?></a></h5>
                            <p><?php echo e(truncateText(strip_tags((string)($notice['content'] ?? '')), 100)); ?></p>
                            <a href="notices.php?id=<?php echo $notice['id']; ?>" class="read-more">
                                थप पढ्नुहोस् <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                        <?php if ($notice['attachment']): ?>
                        <div class="notice-attachment-icon">
                            <a href="<?php echo $notice['attachment']; ?>" target="_blank" title="फाइल डाउनलोड">
                                <i class="fas fa-paperclip"></i>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="empty-state text-center py-5">
                        <i class="fas fa-clipboard-list fa-4x nts-empty-icon mb-3"></i>
                        <h4>कुनै सूचना छैन</h4>
                        <p class="nts-muted">हाल कुनै सूचना उपलब्ध छैन।</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>
