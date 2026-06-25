<?php
require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded
$pageTitle = isEnglish() ? 'Awards & Recognition' : 'सम्मान तथा पुरस्कार';
require_once 'includes/header.php';
$L = getLangStrings();

// Get all awards
try {
    $db = getDB();
    $awardsStmt = $db->query("SELECT * FROM awards WHERE is_active = 1 ORDER BY award_date DESC, display_order ASC");
    $awards = $awardsStmt->fetchAll();
} catch (Throwable $e) {
    $awards = [];
}

// Get single award if ID is provided
$singleAward = null;
if (isset($_GET['id']) && isset($db) && $db instanceof PDO) {
    try {
        $stmt = $db->prepare("SELECT * FROM awards WHERE id = ? AND is_active = 1");
        $stmt->execute([(int)$_GET['id']]);
        $singleAward = $stmt->fetch();
    } catch (Throwable $e) {
        $singleAward = null;
    }
}
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo isEnglish() ? 'Awards & Recognition' : 'सम्मान तथा पुरस्कार'; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo isEnglish() ? 'Awards' : 'सम्मान'; ?></li>
            </ol>
        </nav>
    </div>
</section>

<?php if ($singleAward): ?>
<!-- Single Award Detail -->
<section class="section-padding">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="award-detail-card">
                    <?php if (!empty($singleAward['image'])): ?>
                    <div class="award-detail-image">
                        <img src="<?php echo SITE_URL . $singleAward['image']; ?>" loading="lazy"  alt="<?php echo e(isEnglish() ? ($singleAward['title'] ?? $singleAward['title_np']) : ($singleAward['title_np'] ?? $singleAward['title'])); ?>">
                    </div>
                    <?php endif; ?>
                    <div class="award-detail-content">
                        <h2><?php echo e(isEnglish() ? ($singleAward['title'] ?? $singleAward['title_np']) : ($singleAward['title_np'] ?? $singleAward['title'])); ?></h2>
                        <div class="award-meta">
                            <span class="award-by">
                                <i class="fas fa-medal"></i>
                                <?php echo isEnglish() ? ($singleAward['awarded_by'] ?? $singleAward['awarded_by_np']) : ($singleAward['awarded_by_np'] ?? $singleAward['awarded_by']); ?>
                            </span>
                            <?php if ($singleAward['award_date']): ?>
                            <span class="award-date">
                                <i class="fas fa-calendar-alt"></i> <?php echo date('Y-m-d', strtotime($singleAward['award_date'])); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($singleAward['description']) || !empty($singleAward['description_np'])): ?>
                        <div class="award-description">
                            <?php echo isEnglish() ? ($singleAward['description'] ?? $singleAward['description_np']) : ($singleAward['description_np'] ?? $singleAward['description']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="text-center mt-4">
                    <a href="awards.php" class="btn btn-outline-primary">
                        <i class="fas fa-arrow-left"></i> <?php echo isEnglish() ? 'Back to All Awards' : 'सबै सम्मान हेर्नुहोस्'; ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<?php else: ?>
<!-- All Awards Grid -->
<section class="section-padding">
    <div class="container">
        <?php if (!empty($awards)): ?>
        <div class="row">
            <?php foreach ($awards as $index => $award): ?>
            <div class="col-lg-4 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="<?php echo ($index % 3) * 100; ?>">
                <div class="award-card clickable" onclick="window.location.href='awards.php?id=<?php echo $award['id']; ?>'">
                    <?php if (!empty($award['image'])): ?>
                    <div class="award-image">
                        <img src="<?php echo SITE_URL . $award['image']; ?>" loading="lazy"  alt="<?php echo isEnglish() ? ($award['title'] ?? $award['title_np']) : ($award['title_np'] ?? $award['title']); ?>">
                    </div>
                    <?php else: ?>
                    <div class="award-icon">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <?php endif; ?>
                    <div class="award-content">
                        <h4><?php echo isEnglish() ? ($award['title'] ?? $award['title_np']) : ($award['title_np'] ?? $award['title']); ?></h4>
                        <p class="award-by">
                            <i class="fas fa-medal"></i>
                            <?php echo isEnglish() ? ($award['awarded_by'] ?? $award['awarded_by_np']) : ($award['awarded_by_np'] ?? $award['awarded_by']); ?>
                        </p>
                        <?php if ($award['award_date']): ?>
                        <span class="award-date">
                            <i class="fas fa-calendar-alt"></i> <?php echo date('Y', strtotime($award['award_date'])); ?>
                        </span>
                        <?php endif; ?>
                        <a href="awards.php?id=<?php echo $award['id']; ?>" class="award-link">
                            <?php echo isEnglish() ? 'View Details' : 'विवरण हेर्नुहोस्'; ?> <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state text-center py-5">
            <i class="fas fa-trophy fa-4x text-muted mb-3"></i>
            <h4><?php echo isEnglish() ? 'No Awards Available' : 'कुनै सम्मान उपलब्ध छैन'; ?></h4>
            <p class="text-muted"><?php echo isEnglish() ? 'Awards will be added soon.' : 'सम्मान चाँडै थपिनेछ।'; ?></p>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>


<?php require_once 'includes/footer.php'; ?>
