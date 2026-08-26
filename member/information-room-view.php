<?php
/**
 * Member Portal — Information Room document viewer
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/information-room-tables.php';
requireMemberLogin();
memberSecurityHeaders();

$db = getDB();
ensureInformationRoomTables($db);
ensureInformationRoomMemberColumn($db);

$mem = currentMember();
if (!$mem || !irMemberHasAccess($mem)) {
    header('Location: information-room.php');
    exit;
}

$_t = static function (string $np, string $en): string {
    return function_exists('isEnglish') && isEnglish() ? $en : $np;
};

$id = (int) ($_GET['id'] ?? 0);
$item = irFetchItem($db, $id, true);
if (!$item || !irCanAccessItem($item, false, $mem)) {
    header('Location: information-room.php');
    exit;
}

$memName = (string) ($mem['name'] ?? 'Member');
$memId = (int) ($mem['id'] ?? 0);
/* Access logged by information-room-file.php when document bytes are served */

$pageTitle = $_t('Information Room', 'Information Room') . ' — ' . SITE_NAME;
$extraHead = '<link rel="stylesheet" href="../assets/css/information-room.css?v=' . (@filemtime(__DIR__ . '/../assets/css/information-room.css') ?: time()) . '">';

$irItem = $item;
$irPanel = 'member';
$irBackUrl = 'information-room.php';
$irViewerLabel = $memName . ' · ' . date('Y-m-d H:i');

require __DIR__ . '/includes/chrome.php';
require dirname(__DIR__) . '/includes/information-room-viewer.php';
?>

<script src="../assets/js/information-room-viewer.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/information-room-viewer.js') ?: time(); ?>" defer></script>

<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
