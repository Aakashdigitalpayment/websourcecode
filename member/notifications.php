<?php
/* v2: bootstrap ले config + member-auth + global error guard load गर्छ */
require_once __DIR__ . '/_bootstrap.php';
requireMemberLogin();
memberSecurityHeaders();

$db       = getDB();
$mem      = currentMember();
$memberId = $mem['id'];
$_t = static function (string $np, string $en): string {
    return isEnglish() ? $en : $np;
};

/* Mark all as read — POST + CSRF only (GET ले CSRF जोखिम) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        header('Location: ' . SITE_URL . 'member/notifications.php');
        exit;
    }
    $db->prepare("UPDATE member_notifications SET is_read=1 WHERE member_id=?")->execute([$memberId]);
    header('Location: ' . SITE_URL . 'member/notifications.php');
    exit;
}

$unread = getMemberUnreadCount($memberId);
$notifSt = $db->prepare("SELECT * FROM member_notifications WHERE member_id=? ORDER BY created_at DESC LIMIT 100");
$notifSt->execute([$memberId]);
$notifs = $notifSt->fetchAll(PDO::FETCH_ASSOC);

$siteName  = getSetting('site_name', 'आकाश सहकारी');
$siteUrl   = SITE_URL;
$pageTitle = $_t('सूचनाहरू', 'Notifications') . ' — ' . $siteName;

$iconMap = [
    'success' => ['fas fa-circle-check',         '#16a34a', '#f0fdf4'],
    'error'   => ['fas fa-circle-exclamation',   '#dc2626', '#fef2f2'],
    'warning' => ['fas fa-triangle-exclamation', '#d97706', '#fffbeb'],
    'info'    => ['fas fa-circle-info',           'var(--secondary-color,#c0392b)', '#fef2f2'],
];
require __DIR__ . '/includes/chrome.php';
?>

    <div class="mem-card">
        <div class="mem-card-header">
            <div class="mem-card-title">
                <i class="fas fa-bell"></i><?php echo $_t('सबै सूचनाहरू', 'All Notifications'); ?>
                <?php if ($unread > 0): ?><span class="mem-notif-dot" style="position:static;"><?php echo $unread; ?></span><?php endif; ?>
            </div>
            <?php if ($unread > 0): ?>
            <form method="POST" action="" class="d-inline">
                <?php echo csrfField(); ?>
                <input type="hidden" name="mark_all" value="1">
                <button type="submit" class="btn btn-link p-0 border-0" style="font-size:0.78rem;color:var(--mem-primary);font-weight:700;text-decoration:none;">
                    <i class="fas fa-check-double me-1"></i><?php echo $_t('सबै पढिएको', 'Mark all as read'); ?>
                </button>
            </form>
            <?php endif; ?>
        </div>
        <div class="mem-card-body">
            <?php if (empty($notifs)): ?>
            <div class="mem-empty">
                <span class="mem-empty-icon">🔔</span>
                <div><?php echo $_t('कुनै सूचना छैन।', 'No notifications yet.'); ?></div>
                <div style="font-size:0.78rem;margin-top:6px;"><?php echo $_t('आवेदनको अवस्था बदलिएपछि यहाँ सूचना आउँछ।', 'Updates will appear here when application status changes.'); ?></div>
            </div>
            <?php else: ?>
            <?php foreach ($notifs as $n):
                $ic = $iconMap[$n['type']] ?? $iconMap['info'];
                $link = $n['link'] ?? '';
            ?>
            <div class="mem-notif-item <?php echo !$n['is_read'] ? 'unread' : ''; ?>"
                 id="notif-<?php echo $n['id']; ?>"
                 onclick="readAndGo(<?php echo $n['id']; ?>, '<?php echo addslashes($link); ?>')"
                 style="border-radius:10px;cursor:pointer;">
                <div class="mem-notif-dot-icon" style="background:<?php echo $ic[2]; ?>;color:<?php echo $ic[1]; ?>;width:42px;height:42px;border-radius:50%;flex-shrink:0;">
                    <i class="<?php echo $ic[0]; ?>"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div class="mem-notif-title"><?php echo htmlspecialchars($n['title']); ?></div>
                    <div class="mem-notif-msg" style="white-space:pre-line;"><?php echo htmlspecialchars($n['message'] ?? ''); ?></div>
                    <div class="mem-notif-time"><i class="fas fa-clock me-1"></i><?php echo formatNepaliDate($n['created_at'], true); ?></div>
                </div>
                <?php if (!$n['is_read']): ?>
                <span style="width:9px;height:9px;border-radius:50%;background:var(--mem-accent);flex-shrink:0;margin-top:8px;"></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

<script>
function readAndGo(id, link) {
    var el = document.getElementById('notif-' + id);
    if (el && el.classList.contains('unread')) {
        el.classList.remove('unread');
        fetch('<?php echo $siteUrl; ?>member/ajax.php?action=mark_read&id=' + id);
        var dot = el.querySelector('span[style*="background:var"]');
        if (dot) dot.remove();
    }
    if (link && link !== 'undefined' && link !== '') {
        setTimeout(function(){ window.location.href = link; }, 150);
    }
}
</script>
<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
