<?php
/**
 * Information Room — Admin document viewer
 */
$__t = static function (string $np, string $en): string {
    $lang = (string) ($_SESSION['admin_lang'] ?? $_SESSION['lang'] ?? 'np');
    return strtolower($lang) === 'en' ? $en : $np;
};

$pageTitle = $__t('Information Room — कागजात', 'Information Room — Document');
$currentPage = 'information-room-browse';

require_once __DIR__ . '/../includes/information-room-tables.php';
require_once 'includes/admin-header.php';

$db = getDB();
ensureInformationRoomTables($db);

$id = (int) ($_GET['id'] ?? 0);
$item = irFetchItem($db, $id, false);
if (!$item) {
    setFlash('error', $__t('कागजात भेटिएन।', 'Document not found.'));
    redirect('information-room-browse.php');
}

$adminName = (string) ($_SESSION['admin_username'] ?? $_SESSION['admin_name'] ?? 'Admin');
$adminId = (int) ($_SESSION['admin_id'] ?? 0);
/* Access logged by information-room-file.php when document bytes are served */

$siteBase = rtrim((string) (defined('SITE_URL') ? SITE_URL : '../'), '/') . '/';
$irItem = $item;
$irPanel = 'admin';
$irBackUrl = 'information-room-browse.php';
$irViewerLabel = $adminName . ' · ' . date('Y-m-d H:i');
$irUseEnglish = strtolower((string) ($_SESSION['admin_lang'] ?? $_SESSION['lang'] ?? 'np')) === 'en';
$_t = $__t;
?>

<link rel="stylesheet" href="<?php echo $siteBase; ?>assets/css/information-room.css?v=<?php echo @filemtime(dirname(__DIR__) . '/assets/css/information-room.css') ?: time(); ?>">

<?php echo function_exists('adminPageHeader') ? adminPageHeader(
    $__t('Information Room', 'Information Room'),
    'fa-book-open',
    $__t('कागजात हेर्नुहोस्', 'View document'),
    '<a href="information-room-browse.php" class="btn btn-sm btn-outline-secondary">' . $__t('सूची', 'List') . '</a>'
) : ''; ?>

<?php require dirname(__DIR__) . '/includes/information-room-viewer.php'; ?>

<script src="<?php echo $siteBase; ?>assets/js/information-room-viewer.js?v=<?php echo @filemtime(dirname(__DIR__) . '/assets/js/information-room-viewer.js') ?: time(); ?>" defer></script>

<?php require_once 'includes/admin-footer.php'; ?>
