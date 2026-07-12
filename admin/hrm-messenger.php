<?php
/**
 * 💬 HRM — Internal Messenger (Inbox + Conversation)
 * v11.1 — admin-side view of all internal messages.
 */
$currentPage = 'hrm-messenger';
$pageTitle   = 'आन्तरिक च्याट';
require_once __DIR__ . '/includes/admin-header.php';
require_once __DIR__ . '/includes/hrm-tables.php';
require_once __DIR__ . '/includes/hrm-messages-table.php';
require_role('admin');

$db = getDB();
ensureHrmTables($db);
ensureHrmMessagesTable($db);

$me = (int)($_SESSION['admin_id'] ?? 0);
$conv = (int)($_GET['emp'] ?? 0);

/* mark conversation read */
if ($conv > 0) {
    $st = $db->prepare("UPDATE hrm_internal_messages SET is_read=1, read_at=NOW()
                        WHERE receiver_employee_id=? AND is_read=0");
    $st->execute([$conv]);
}

/* POST: reply */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reply' && $conv > 0) {
    $body = trim((string)($_POST['body'] ?? ''));
    if ($body !== '') {
        $st = $db->prepare("INSERT INTO hrm_internal_messages
            (sender_admin_id, receiver_employee_id, body, created_at) VALUES (?,?,?,NOW())");
        $st->execute([$me ?: null, $conv, $body]);
        header('Location: hrm-messenger.php?emp=' . $conv);
        exit;
    }
}

/* Sidebar list — last message per employee */
$threads = $db->query("
    SELECT e.id, e.full_name_np, e.photo, e.designation,
           (SELECT body FROM hrm_internal_messages m
             WHERE m.receiver_employee_id=e.id ORDER BY m.created_at DESC LIMIT 1) AS last_body,
           (SELECT created_at FROM hrm_internal_messages m
             WHERE m.receiver_employee_id=e.id ORDER BY m.created_at DESC LIMIT 1) AS last_at,
           (SELECT COUNT(*) FROM hrm_internal_messages m
             WHERE m.receiver_employee_id=e.id AND m.is_read=0) AS unread
    FROM hrm_employees e
    WHERE EXISTS (SELECT 1 FROM hrm_internal_messages m WHERE m.receiver_employee_id=e.id)
    ORDER BY last_at DESC LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

$messages = [];
$activeEmp = null;
if ($conv > 0) {
    $st = $db->prepare("SELECT * FROM hrm_employees WHERE id=?");
    $st->execute([$conv]);
    $activeEmp = $st->fetch(PDO::FETCH_ASSOC);
    $st = $db->prepare("SELECT * FROM hrm_internal_messages
                        WHERE receiver_employee_id=? ORDER BY created_at ASC LIMIT 200");
    $st->execute([$conv]);
    $messages = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>
<style>
.msg-shell{display:grid;grid-template-columns:280px 1fr;gap:12px;height:calc(100vh - 190px);min-height:480px;}
.msg-side{background:#fff;border:1px solid #e6e9ef;border-radius:14px;overflow:hidden;display:flex;flex-direction:column;}
.msg-side-h{padding:12px 14px;border-bottom:1px solid #eef1f6;font-weight:700;color:var(--primary-color,#1a5f2a);}
.msg-list{overflow:auto;flex:1;}
.msg-thread{padding:10px 12px;border-bottom:1px solid #f1f3f7;text-decoration:none;color:#1f2937;display:flex;gap:10px;align-items:center;}
.msg-thread:hover,.msg-thread.active{background:#f6faf7;}
.msg-thread img{width:34px;height:34px;border-radius:50%;object-fit:cover;border:1px solid #e6e9ef;}
.msg-thread .nm{font-weight:600;font-size:13px;}
.msg-thread .pv{font-size:12px;color:#6b7280;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px;}
.msg-thread .badge{background:var(--primary-color,#1a5f2a);}
.msg-pane{background:#fff;border:1px solid #e6e9ef;border-radius:14px;display:flex;flex-direction:column;overflow:hidden;}
.msg-pane-h{padding:12px 14px;border-bottom:1px solid #eef1f6;display:flex;align-items:center;gap:10px;}
.msg-pane-h img{width:36px;height:36px;border-radius:50%;object-fit:cover;}
.msg-body{flex:1;overflow:auto;padding:16px;background:#f7f9fc;}
.msg-bubble{max-width:70%;padding:9px 11px;border-radius:13px;margin-bottom:8px;font-size:13px;line-height:1.4;}
.msg-bubble.in{background:#fff;border:1px solid #e6e9ef;}
.msg-bubble.out{background:var(--primary-color,#1a5f2a);color:#fff;margin-left:auto;}
.msg-bubble .when{font-size:10.5px;opacity:.75;margin-top:4px;display:block;}
.msg-form{padding:10px;border-top:1px solid #eef1f6;display:flex;gap:8px;}
.msg-form textarea{flex:1;border:1px solid #e6e9ef;border-radius:10px;padding:8px 10px;resize:none;}
.msg-form .btn{width:42px;height:42px;display:inline-flex;align-items:center;justify-content:center;padding:0;border-radius:11px;}
.msg-form .btn i{font-size:.92rem;line-height:1;}
@media(max-width:768px){.msg-shell{grid-template-columns:1fr;height:auto;}.msg-side{max-height:240px;}}
</style>
<div class="container-fluid p-3 p-md-4">
  <h2 class="h4 mb-3"><i class="fas fa-comments text-primary me-2"></i>आन्तरिक च्याट</h2>
  <div class="msg-shell">
    <aside class="msg-side">
      <div class="msg-side-h">कुराकानीहरू</div>
      <div class="msg-list">
        <?php if (empty($threads)): ?>
          <div class="p-3 text-muted small">कुनै सन्देश छैन। <a href="hrm-employee-directory.php">डाइरेक्टरीबाट</a> सुरु गर्नुहोस्।</div>
        <?php else: foreach ($threads as $t): ?>
          <a href="?emp=<?= (int)$t['id'] ?>" class="msg-thread <?= $conv===(int)$t['id']?'active':'' ?>">
            <img src="<?= htmlspecialchars(hrmEmployeePhotoUrl($t['photo'])) ?>" alt="">
            <div class="flex-grow-1 min-w-0">
              <div class="d-flex justify-content-between"><span class="nm"><?= htmlspecialchars($t['full_name_np']) ?></span>
                <?php if ((int)$t['unread']>0): ?><span class="badge rounded-pill"><?= (int)$t['unread'] ?></span><?php endif; ?>
              </div>
              <div class="pv"><?= htmlspecialchars(mb_substr((string)$t['last_body'], 0, 40)) ?></div>
            </div>
          </a>
        <?php endforeach; endif; ?>
      </div>
    </aside>
    <section class="msg-pane">
      <?php if (!$activeEmp): ?>
        <div class="d-flex align-items-center justify-content-center text-muted h-100 p-4">कुनै कुराकानी छान्नुहोस्</div>
      <?php else: ?>
        <div class="msg-pane-h">
          <img src="<?= htmlspecialchars(hrmEmployeePhotoUrl($activeEmp['photo'])) ?>" alt="">
          <div>
            <div class="fw-semibold"><?= htmlspecialchars($activeEmp['full_name_np']) ?></div>
            <div class="small text-muted"><?= htmlspecialchars($activeEmp['designation'] ?? '') ?></div>
          </div>
        </div>
        <div class="msg-body" id="msgBody">
          <?php foreach ($messages as $m): $out = !empty($m['sender_admin_id']); ?>
            <div class="msg-bubble <?= $out?'out':'in' ?>">
              <?php if (!empty($m['subject'])): ?><div class="fw-semibold mb-1"><?= htmlspecialchars($m['subject']) ?></div><?php endif; ?>
              <?= nl2br(htmlspecialchars($m['body'])) ?>
              <span class="when"><?= htmlspecialchars(date('M d, H:i', strtotime($m['created_at']))) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <form method="post" class="msg-form">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="reply">
          <textarea name="body" rows="2" placeholder="जवाफ लेख्नुहोस् ..." required></textarea>
          <button class="btn btn-primary" type="submit" aria-label="Send" title="Send"><i class="fas fa-paper-plane"></i></button>
        </form>
      <?php endif; ?>
    </section>
  </div>
</div>
<script>
const mb=document.getElementById('msgBody'); if(mb) mb.scrollTop=mb.scrollHeight;
</script>
<?php require_once __DIR__ . '/includes/admin-footer.php';
