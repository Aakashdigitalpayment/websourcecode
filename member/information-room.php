<?php
/**
 * Member Portal — Information Room (authorized members only)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/information-room-tables.php';
requireMemberLogin();
memberSecurityHeaders();

$db = getDB();
ensureInformationRoomTables($db);
ensureInformationRoomMemberColumn($db);

$mem = currentMember();
if (!$mem) {
    header('Location: login.php?msg=session_expired');
    exit;
}

if (!irMemberHasAccess($mem)) {
    http_response_code(403);
    $pageTitle = (function_exists('isEnglish') && isEnglish() ? 'Access denied' : 'पहुँच अस्वीकृत') . ' — ' . SITE_NAME;
    require __DIR__ . '/includes/chrome.php';
    echo '<div class="alert alert-warning mt-3"><i class="fas fa-lock me-2"></i>';
    echo function_exists('isEnglish') && isEnglish()
        ? 'Information Room is not enabled for your account. Contact the cooperative office.'
        : 'तपाईंको खातामा Information Room सक्षम छैन। सहकारी कार्यालयमा सम्पर्क गर्नुहोस्।';
    echo '</div>';
    require __DIR__ . '/includes/chrome-foot.php';
    exit;
}

$_t = static function (string $np, string $en): string {
    return function_exists('isEnglish') && isEnglish() ? $en : $np;
};

$catFilter = clean_text($_GET['cat'] ?? '', 50);
if ($catFilter !== '' && !isset(irCategories()[$catFilter])) {
    $catFilter = '';
}

$items = irFetchActiveItems($db, $catFilter);
$pageTitle = $_t('Information Room', 'Information Room') . ' — ' . SITE_NAME;
$extraHead = '<link rel="stylesheet" href="../assets/css/information-room.css?v=' . (@filemtime(__DIR__ . '/../assets/css/information-room.css') ?: time()) . '">';

require __DIR__ . '/includes/chrome.php';
?>

<div class="mem-page-head mb-3">
    <h1 class="h4 mb-1"><i class="fas fa-vault me-2 text-success"></i><?php echo $_t('Information Room', 'Information Room'); ?></h1>
    <p class="text-muted small mb-0"><?php echo $_t(
        'बोर्ड निर्णय, नीति, कार्यविधि र बिनियम — अनुमति प्राप्त सदस्यका लागि।',
        'Board decisions, policies, procedures and bylaws — for authorized members.'
    ); ?></p>
</div>

<div class="mb-3 d-flex flex-wrap gap-2">
    <a href="information-room.php" class="btn btn-sm <?php echo $catFilter === '' ? 'btn-success' : 'btn-outline-success'; ?>"><?php echo $_t('सबै', 'All'); ?></a>
    <?php foreach (irCategories() as $k => $c): ?>
    <a href="information-room.php?cat=<?php echo urlencode($k); ?>" class="btn btn-sm <?php echo $catFilter === $k ? 'btn-success' : 'btn-outline-success'; ?>">
        <?php echo htmlspecialchars($_t($c['np'], $c['en']), ENT_QUOTES, 'UTF-8'); ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if (empty($items)): ?>
<div class="alert alert-info"><?php echo $_t('अहिले कुनै कागजात उपलब्ध छैन।', 'No documents available right now.'); ?></div>
<?php else: ?>
<div class="ir-room-grid">
    <?php foreach ($items as $row):
        $title = trim((string) ($row['title_np'] ?? '')) !== '' ? (string) $row['title_np'] : (string) $row['title'];
    ?>
    <article class="ir-room-card">
        <span class="badge bg-success-subtle text-success"><?php echo htmlspecialchars(irCategoryLabel((string) $row['category'], function_exists('isEnglish') && isEnglish()), ENT_QUOTES, 'UTF-8'); ?></span>
        <h3><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h3>
        <div class="ir-card-meta">
            <?php if (!empty($row['meeting_date'])): ?>
            <div><?php echo $_t('मिति', 'Date'); ?>: <?php echo htmlspecialchars((string) $row['meeting_date'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if (empty($row['allow_download'])): ?>
            <div class="text-warning-emphasis"><i class="fas fa-eye me-1"></i><?php echo $_t('हेर्न मात्र', 'View only'); ?></div>
            <?php endif; ?>
        </div>
        <div class="ir-card-actions">
            <a href="information-room-view.php?id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-success w-100">
                <i class="fas fa-book-open me-1"></i><?php echo $_t('पढ्नुहोस्', 'Read'); ?>
            </a>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
