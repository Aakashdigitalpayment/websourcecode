<?php
/**
 * Admin — Push Notifications
 * Send real-time push alerts to all members or a specific member.
 */
$pageTitle   = 'Push Notifications — सदस्यहरूलाई सूचना';
$currentPage = 'push-notifications';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once '_bootstrap.php';
require_once '../includes/push-helper.php';

requireAdminLogin();

$db = getDB();
ensurePushTables($db);

/* ── Stats ──────────────────────────────────────────────────── */
$subCount = (int)$db->query(
    'SELECT COUNT(*) FROM member_push_subscriptions'
)->fetchColumn();

$memberCount = (int)$db->query(
    'SELECT COUNT(DISTINCT member_id) FROM member_push_subscriptions'
)->fetchColumn();

/* ── Recent send log ─────────────────────────────────────────── */
$recentLogs = [];
try {
    $recentLogs = $db->query("
        SELECT l.id, l.notif_id, l.member_id, l.http_code, l.sent_at,
               n.title AS notif_title,
               m.name  AS member_name
        FROM   member_push_log    l
        LEFT JOIN member_notifications n ON n.id = l.notif_id
        LEFT JOIN members              m ON m.id = l.member_id
        ORDER  BY l.sent_at DESC
        LIMIT  50
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { $recentLogs = []; }

/* ── Handle send ─────────────────────────────────────────────── */
$result   = null;
$formErr  = '';
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['_action'])) {

    if ($_POST['_action'] === 'send_push') {
        $title    = trim($_POST['title']    ?? '');
        $body     = trim($_POST['body']     ?? '');
        $type     = in_array($_POST['type'] ?? '', ['success','info','warning','error'])
                    ? $_POST['type'] : 'info';
        $targetId = !empty($_POST['target_member_id'])
                    ? (int)$_POST['target_member_id'] : null;

        if ($title === '') {
            $formErr = 'शीर्षक (title) आवश्यक छ।';
        } elseif ($subCount === 0) {
            $formErr = 'कुनै पनि सदस्यले Push Notification enable गरेका छैनन्।';
        } else {
            /* Save to member_notifications */
            if ($targetId !== null) {
                $db->prepare("
                    INSERT INTO member_notifications
                        (member_id, title, message, type, is_read, created_at)
                    VALUES (?, ?, ?, ?, 0, NOW())
                ")->execute([$targetId, $title, $body, $type]);
                $notifId = (int)$db->lastInsertId();
            } else {
                /* All active members */
                $members = $db->query(
                    "SELECT id FROM members WHERE status = 'active' OR status IS NULL"
                )->fetchAll(PDO::FETCH_COLUMN);
                $notifId = null;
                $ins = $db->prepare("
                    INSERT INTO member_notifications
                        (member_id, title, message, type, is_read, created_at)
                    VALUES (?, ?, ?, ?, 0, NOW())
                ");
                foreach ($members as $mid) {
                    $ins->execute([(int)$mid, $title, $body, $type]);
                    if ($notifId === null) $notifId = (int)$db->lastInsertId();
                }
            }

            /* Broadcast push */
            $pushResult = broadcastWebPush($db, $targetId, $notifId);
            $result = array_merge($pushResult, [
                'title'   => $title,
                'body'    => $body,
                'target'  => $targetId ? "Member #{$targetId}" : 'सबै सदस्यहरू',
            ]);

            /* Refresh log */
            try {
                $recentLogs = $db->query("
                    SELECT l.id, l.notif_id, l.member_id, l.http_code, l.sent_at,
                           n.title AS notif_title, m.name AS member_name
                    FROM   member_push_log l
                    LEFT JOIN member_notifications n ON n.id = l.notif_id
                    LEFT JOIN members              m ON m.id = l.member_id
                    ORDER  BY l.sent_at DESC LIMIT 50
                ")->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {}
        }
    }

    /* Delete expired subscriptions manually */
    if ($_POST['_action'] === 'clear_expired') {
        $deleted = (int)$db->exec(
            "DELETE FROM member_push_subscriptions
             WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
        $result = ['cleared' => $deleted];
    }
}

/* ── Member search for targeted send ─────────────────────────── */
$members = [];
try {
    $members = $db->query(
        "SELECT id, name, phone, email FROM members
         WHERE status='active' OR status IS NULL
         ORDER BY name LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { $members = []; }

/* ── Type colours ─────────────────────────────────────────────── */
$typeColors = [
    'info'    => '#0ea5e9',
    'success' => '#16a34a',
    'warning' => '#f59e0b',
    'error'   => '#ef4444',
];
?>

<div class="content-header">
    <h1 class="page-title">
        <i class="fas fa-bell-ring" style="color:var(--primary-color);margin-right:8px;"></i>
        Push Notifications
    </h1>
    <div style="font-size:.82rem;color:#6b7280;">
        सदस्यहरूको फोनमा Browser Closed भएपनि real-time alert पठाउनुहोस्।
    </div>
</div>

<?php if ($result && !isset($result['cleared'])): ?>
<div class="alert alert-<?php echo $result['sent'] > 0 ? 'success' : 'warning'; ?>"
     style="border-radius:12px;padding:14px 18px;margin-bottom:20px;display:flex;gap:12px;align-items:flex-start;">
    <i class="fas fa-<?php echo $result['sent'] > 0 ? 'circle-check' : 'triangle-exclamation'; ?>"
       style="font-size:1.2rem;margin-top:2px;flex-shrink:0;"></i>
    <div>
        <div style="font-weight:700;margin-bottom:4px;">
            Push पठाइयो: "<em><?php echo htmlspecialchars($result['title']); ?></em>"
            → <?php echo htmlspecialchars($result['target']); ?>
        </div>
        <div style="font-size:.85rem;">
            ✓ सफल: <strong><?php echo $result['sent']; ?></strong> &nbsp;
            ✗ असफल: <strong><?php echo $result['failed']; ?></strong> &nbsp;
            🗑 हटाइएको (expired): <strong><?php echo $result['removed']; ?></strong>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (isset($result['cleared'])): ?>
<div class="alert alert-info" style="border-radius:12px;padding:14px 18px;margin-bottom:20px;">
    <i class="fas fa-trash"></i>
    <?php echo (int)$result['cleared']; ?> पुराना (90 दिन भन्दा बढी) subscriptions हटाइए।
</div>
<?php endif; ?>

<?php if ($formErr): ?>
<div class="alert alert-danger" style="border-radius:12px;padding:14px 18px;margin-bottom:20px;">
    <i class="fas fa-circle-xmark"></i> <?php echo htmlspecialchars($formErr); ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-bottom:24px;">
    <div class="card" style="border-radius:16px;">
        <div class="card-body" style="display:flex;gap:14px;align-items:center;padding:18px 20px;">
            <div style="width:48px;height:48px;border-radius:12px;background:#e8f5e9;display:flex;align-items:center;justify-content:center;">
                <i class="fas fa-mobile-screen-button" style="color:var(--primary-color);font-size:1.3rem;"></i>
            </div>
            <div>
                <div style="font-size:1.6rem;font-weight:800;color:var(--primary-color);"><?php echo $subCount; ?></div>
                <div style="font-size:.8rem;color:#6b7280;font-weight:600;">कुल Subscriptions</div>
            </div>
        </div>
    </div>
    <div class="card" style="border-radius:16px;">
        <div class="card-body" style="display:flex;gap:14px;align-items:center;padding:18px 20px;">
            <div style="width:48px;height:48px;border-radius:12px;background:#ede9fe;display:flex;align-items:center;justify-content:center;">
                <i class="fas fa-users" style="color:#7c3aed;font-size:1.3rem;"></i>
            </div>
            <div>
                <div style="font-size:1.6rem;font-weight:800;color:#7c3aed;"><?php echo $memberCount; ?></div>
                <div style="font-size:.8rem;color:#6b7280;font-weight:600;">Push-enabled सदस्यहरू</div>
            </div>
        </div>
    </div>
    <div class="card" style="border-radius:16px;">
        <div class="card-body" style="display:flex;gap:14px;align-items:center;padding:18px 20px;">
            <div style="width:48px;height:48px;border-radius:12px;background:#fef3c7;display:flex;align-items:center;justify-content:center;">
                <i class="fas fa-paper-plane" style="color:#d97706;font-size:1.3rem;"></i>
            </div>
            <div>
                <div style="font-size:1.6rem;font-weight:800;color:#d97706;"><?php echo count($recentLogs); ?></div>
                <div style="font-size:.8rem;color:#6b7280;font-weight:600;">हालैका Sends (50)</div>
            </div>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">

<!-- ── Send Form ─────────────────────────────────────── -->
<div class="card" style="border-radius:16px;">
    <div class="card-header" style="background:linear-gradient(135deg,var(--primary-color),#2e7d3a);color:#fff;border-radius:16px 16px 0 0;padding:14px 20px;">
        <i class="fas fa-paper-plane"></i>
        <strong style="margin-left:8px;">नयाँ Push Notification पठाउनुहोस्</strong>
    </div>
    <div class="card-body" style="padding:20px;">
        <form method="POST" id="pushForm">
            <input type="hidden" name="_action" value="send_push">

            <div class="mb-3">
                <label class="form-label fw-600">शीर्षक (Title) <span style="color:#ef4444;">*</span></label>
                <input type="text" name="title" class="form-control" maxlength="100"
                       placeholder="जस्तै: ऋण स्वीकृत भयो" required
                       value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>">
            </div>

            <div class="mb-3">
                <label class="form-label fw-600">सन्देश (Body)</label>
                <textarea name="body" class="form-control" rows="3" maxlength="250"
                          placeholder="जस्तै: तपाईंको ऋण आवेदन स्वीकृत गरिएको छ। विवरणका लागि Member Portal खोल्नुहोस्।"><?php
                    echo htmlspecialchars($_POST['body'] ?? '');
                ?></textarea>
                <div style="font-size:.74rem;color:#9ca3af;margin-top:3px;">अधिकतम 250 अक्षर</div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-600">प्रकार (Type)</label>
                <select name="type" class="form-select">
                    <option value="info"    <?php echo ($_POST['type'] ?? '') === 'info'    ? 'selected' : ''; ?>>ℹ️ जानकारी (Info)</option>
                    <option value="success" <?php echo ($_POST['type'] ?? '') === 'success' ? 'selected' : ''; ?>>✅ सफल (Success)</option>
                    <option value="warning" <?php echo ($_POST['type'] ?? '') === 'warning' ? 'selected' : ''; ?>>⚠️ सचेतना (Warning)</option>
                    <option value="error"   <?php echo ($_POST['type'] ?? '') === 'error'   ? 'selected' : ''; ?>>🔴 गल्ती (Error)</option>
                </select>
            </div>

            <div class="mb-4">
                <label class="form-label fw-600">पठाउने लक्ष्य</label>
                <select name="target_member_id" class="form-select" id="targetSelect">
                    <option value="">सबै Push-enabled सदस्यहरू (<?php echo $memberCount; ?> जना)</option>
                    <?php foreach ($members as $m): ?>
                    <option value="<?php echo $m['id']; ?>">
                        <?php echo htmlspecialchars($m['name'] . ' — ' . ($m['phone'] ?: $m['email'] ?: '#'.$m['id'])); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($subCount === 0): ?>
            <div style="background:#fef3c7;border-radius:10px;padding:12px 14px;font-size:.83rem;color:#92400e;margin-bottom:16px;">
                <i class="fas fa-triangle-exclamation"></i>
                अहिले कुनै Push Subscription छैन। सदस्यहरूले Member Portal मा bell icon थिचेर notification enable गर्नुपर्छ।
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-success w-100"
                    style="border-radius:10px;padding:11px;font-weight:700;font-size:.95rem;"
                    <?php echo $subCount === 0 ? 'disabled' : ''; ?>>
                <i class="fas fa-paper-plane me-1"></i>
                Push Notification पठाउनुहोस्
                <?php if ($memberCount > 0): ?>
                <span style="font-size:.78rem;opacity:.8;">(<?php echo $memberCount; ?> devices)</span>
                <?php endif; ?>
            </button>
        </form>

        <hr style="margin:20px 0;">
        <form method="POST">
            <input type="hidden" name="_action" value="clear_expired">
            <button type="submit" class="btn btn-outline-secondary w-100"
                    style="border-radius:10px;font-size:.82rem;"
                    onclick="return confirm('90 दिन पुराना subscriptions हटाउने?')">
                <i class="fas fa-trash me-1"></i> Expired Subscriptions सफा गर्नुहोस्
            </button>
        </form>
    </div>
</div>

<!-- ── Send Log ───────────────────────────────────────── -->
<div class="card" style="border-radius:16px;">
    <div class="card-header" style="padding:14px 20px;border-bottom:1px solid #f0f0f0;">
        <strong><i class="fas fa-list-check me-2"></i>हालैका Sends</strong>
    </div>
    <div style="max-height:520px;overflow-y:auto;">
        <?php if (empty($recentLogs)): ?>
        <div style="text-align:center;padding:40px;color:#9ca3af;font-size:.85rem;">
            <i class="fas fa-inbox" style="font-size:2rem;display:block;margin-bottom:10px;opacity:.4;"></i>
            अझै कुनै push पठाइएको छैन।
        </div>
        <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
            <thead>
                <tr style="background:#f9fafb;border-bottom:1px solid #e5e7eb;">
                    <th style="padding:8px 12px;text-align:left;color:#6b7280;font-weight:600;">सदस्य</th>
                    <th style="padding:8px 12px;text-align:left;color:#6b7280;font-weight:600;">Notification</th>
                    <th style="padding:8px 6px;text-align:center;color:#6b7280;font-weight:600;">Status</th>
                    <th style="padding:8px 12px;text-align:right;color:#6b7280;font-weight:600;">समय</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recentLogs as $log): ?>
            <?php $ok = in_array((int)$log['http_code'], [200,201,202]); ?>
            <tr style="border-bottom:1px solid #f3f4f6;">
                <td style="padding:8px 12px;">
                    <?php echo $log['member_name'] ? htmlspecialchars($log['member_name']) : '#'.$log['member_id']; ?>
                </td>
                <td style="padding:8px 12px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?php echo $log['notif_title'] ? htmlspecialchars($log['notif_title']) : '—'; ?>
                </td>
                <td style="padding:8px 6px;text-align:center;">
                    <?php if ($ok): ?>
                    <span style="background:#d1fae5;color:#065f46;border-radius:6px;padding:2px 8px;font-weight:700;font-size:.72rem;">
                        <?php echo $log['http_code']; ?> OK
                    </span>
                    <?php else: ?>
                    <span style="background:#fee2e2;color:#b91c1c;border-radius:6px;padding:2px 8px;font-weight:700;font-size:.72rem;">
                        <?php echo $log['http_code'] ?: '?'; ?>
                    </span>
                    <?php endif; ?>
                </td>
                <td style="padding:8px 12px;text-align:right;color:#9ca3af;white-space:nowrap;">
                    <?php echo date('M d, H:i', strtotime($log['sent_at'])); ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

</div>

<?php require_once 'includes/admin-footer.php'; ?>
