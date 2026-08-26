<?php
/**
 * Information Room — Admin reader browse (preview what members see)
 */
$__t = static function (string $np, string $en): string {
    $lang = (string) ($_SESSION['admin_lang'] ?? $_SESSION['lang'] ?? 'np');
    return strtolower($lang) === 'en' ? $en : $np;
};

$pageTitle = $__t('Information Room — हेर्नुहोस्', 'Information Room — Browse');
$currentPage = 'information-room-browse';

require_once __DIR__ . '/../includes/information-room-tables.php';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$db = getDB();
ensureInformationRoomTables($db);

$catFilter = clean_text($_GET['cat'] ?? '', 50);
if ($catFilter !== '' && !isset(irCategories()[$catFilter])) {
    $catFilter = '';
}

$items = irFetchActiveItems($db, $catFilter);
$adminName = (string) ($_SESSION['admin_username'] ?? $_SESSION['admin_name'] ?? 'Admin');
$siteBase = rtrim((string) (defined('SITE_URL') ? SITE_URL : '../'), '/') . '/';
?>

<link rel="stylesheet" href="<?php echo $siteBase; ?>assets/css/information-room.css?v=<?php echo @filemtime(dirname(__DIR__) . '/assets/css/information-room.css') ?: time(); ?>">

<?php echo function_exists('adminPageHeader') ? adminPageHeader(
    $__t('Information Room — Reader', 'Information Room — Reader'),
    'fa-book-open',
    $__t('Admin ले authorized सदस्यले देख्ने view preview गर्नुहोस्।', 'Preview the view authorized members will see.'),
    '<a href="information-room.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-cog me-1"></i>' . $__t('व्यवस्थापन', 'Manage') . '</a>'
) : ''; ?>

<div class="mb-3 d-flex flex-wrap gap-2">
    <a href="information-room-browse.php" class="btn btn-sm <?php echo $catFilter === '' ? 'btn-primary' : 'btn-outline-primary'; ?>"><?php echo $__t('सबै', 'All'); ?></a>
    <?php foreach (irCategories() as $k => $c): ?>
    <a href="information-room-browse.php?cat=<?php echo urlencode($k); ?>" class="btn btn-sm <?php echo $catFilter === $k ? 'btn-primary' : 'btn-outline-primary'; ?>">
        <?php echo htmlspecialchars(strtolower((string)($_SESSION['admin_lang'] ?? 'np')) === 'en' ? $c['en'] : $c['np'], ENT_QUOTES, 'UTF-8'); ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if (empty($items)): ?>
<div class="alert alert-info"><?php echo $__t('कुनै सक्रिय कागजात छैन।', 'No active documents.'); ?></div>
<?php else: ?>
<div class="ir-room-grid">
    <?php foreach ($items as $row):
        $title = trim((string) ($row['title_np'] ?? '')) !== '' ? (string) $row['title_np'] : (string) $row['title'];
        $catLbl = irCategoryLabel((string) $row['category'], strtolower((string)($_SESSION['admin_lang'] ?? 'np')) === 'en');
    ?>
    <article class="ir-room-card">
        <span class="badge bg-primary-subtle text-primary"><?php echo htmlspecialchars($catLbl, ENT_QUOTES, 'UTF-8'); ?></span>
        <h3><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h3>
        <div class="ir-card-meta">
            <?php if (!empty($row['meeting_date'])): ?>
            <div><?php echo $__t('मिति', 'Date'); ?>: <?php echo htmlspecialchars((string) $row['meeting_date'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if (!empty($row['reference_no'])): ?>
            <div><?php echo $__t('सन्दर्भ', 'Ref'); ?>: <?php echo htmlspecialchars((string) $row['reference_no'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if (empty($row['allow_download'])): ?>
            <div class="text-warning-emphasis"><i class="fas fa-eye me-1"></i><?php echo $__t('हेर्न मात्र', 'View only'); ?></div>
            <?php endif; ?>
        </div>
        <div class="ir-card-actions">
            <a href="information-room-view.php?id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-success w-100">
                <i class="fas fa-book-open me-1"></i><?php echo $__t('पढ्नुहोस्', 'Read'); ?>
            </a>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once 'includes/admin-footer.php'; ?>
