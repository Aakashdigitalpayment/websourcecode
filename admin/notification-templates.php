<?php
/**
 * Admin: Notification Templates Manager — v4
 * हरेक event को email/SMS subject + body live edit गर्न मिल्ने
 */
define('IS_ADMIN_PAGE', true);
require_once '../includes/config.php';
requireAdminLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCSRFToken()) {
    setFlash('error','सुरक्षा जाँच असफल');
    redirect('notification-templates.php');
}
if (empty($csrfToken)) $csrfToken = generateCSRFToken();

$db = getDB();
ensureNotificationTemplatesTable();

/* Save */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $event    = clean_text($_POST['event_type'] ?? '');
    $audience = in_array($_POST['audience'] ?? '', ['admin','member']) ? $_POST['audience'] : 'admin';
    $channel  = in_array($_POST['channel']  ?? '', ['email','sms'])    ? $_POST['channel']  : 'email';
    $enabled  = isset($_POST['enabled']) ? 1 : 0;
    $subject  = clean_text($_POST['subject'] ?? '');
    $body     = trim($_POST['body'] ?? '');

    if ($event && $body) {
        $st = $db->prepare("INSERT INTO notification_templates (event_type,audience,channel,enabled,subject,body)
                            VALUES (?,?,?,?,?,?)
                            ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), subject=VALUES(subject), body=VALUES(body)");
        $st->execute([$event,$audience,$channel,$enabled,$subject,$body]);
        if (function_exists('auditLog')) auditLog('template_edit','notification_templates',null,null,
            ['event'=>$event,'audience'=>$audience,'channel'=>$channel]);
        setFlash('success','Template सुरक्षित भयो: '.$event.' / '.$audience.' / '.$channel);
    } else setFlash('error','Event र Body आवश्यक छ।');
    redirect('notification-templates.php?event='.urlencode($event).'&audience='.$audience.'&channel='.$channel);
}

/* Test send */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_send') {
    $event = clean_text($_POST['event_type'] ?? '');
    $to    = strtolower(clean_text($_POST['test_to'] ?? '', 254));
    require_once '../includes/notifications.php';
    if ($to && $event) {
        try {
            sendAdminNotification($event, [
                'नाम'=>'Test User','फोन'=>'9800000000','इमेल'=>$to,
                'ऋण रकम'=>'Rs. 50,000','मिति'=>date('Y-m-d'),
            ], 'TEST-'.date('His'));
            setFlash('success','Test notification trigger भयो (Email + SMS gateway settings अनुसार)। Inbox/log check गर्नुहोस्।');
        } catch (Exception $e) { setFlash('error','Test failed: '.$e->getMessage()); }
    }
    redirect('notification-templates.php');
}

$events    = getNotificationEventsList();
$selEvent  = $_GET['event']    ?? 'loan_application';
$selAud    = $_GET['audience'] ?? 'admin';
$selChan   = $_GET['channel']  ?? 'email';
if (!in_array($selAud, ['admin', 'member'], true)) {
    $selAud = 'admin';
}
if (!in_array($selChan, ['email', 'sms'], true)) {
    $selChan = 'email';
}

if (!isset($events[$selAud])) $selAud = 'admin';
if (!isset($events[$selAud][$selEvent])) $selEvent = array_key_first($events[$selAud]);

$tpl = getNotificationTemplate($selEvent, $selAud, $selChan) ?: ['enabled'=>1,'subject'=>'','body'=>'','variables'=>''];

require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
$flash = getFlash();
echo adminPageHeader('Notification Templates','fa-envelope-open-text',
    'हरेक event को email/SMS को subject र body यहाँबाट edit गर्नुहोस्। Placeholders: {name} {tracking_id} {status} {remarks} {amount} {date} {details} {site_name}');
if ($flash) echo adminAlert($flash['type'],$flash['message']);
?>
<div class="container-fluid py-4">
<div class="notification-template-page admin-form-page">
<div class="row g-4">
  <!-- LEFT: Sidebar -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm notif-events-card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="fas fa-list me-2"></i>Events</span>
        <span class="badge bg-light text-dark"><?php echo count($events['admin'] ?? []) + count($events['member'] ?? []); ?> items</span>
      </div>
      <div class="list-group list-group-flush" style="max-height:600px;overflow-y:auto;">
        <?php foreach ($events as $aud => $list): ?>
          <div class="list-group-item bg-light fw-bold text-uppercase text-muted small"><?php echo $aud === 'admin' ? 'Admin Notifications' : 'Member Notifications'; ?></div>
          <?php foreach ($list as $ek => $elabel):
              foreach (['email','sms'] as $ch):
                  $active = ($selEvent===$ek && $selAud===$aud && $selChan===$ch);
                  $row = getNotificationTemplate($ek,$aud,$ch);
                  $on  = $row && (int)($row['enabled']??1)===1;
          ?>
          <a href="?event=<?php echo urlencode($ek); ?>&audience=<?php echo $aud; ?>&channel=<?php echo $ch; ?>"
             class="list-group-item list-group-item-action d-flex justify-content-between align-items-center<?php echo $active?' active':''; ?>"
             style="<?php echo $active?'':'border-left:3px solid '.($on?'#16a34a':'#d1d5db').';'; ?>">
            <span><i class="fas fa-<?php echo $ch==='email'?'envelope':'comment-sms'; ?> me-2"></i><?php echo htmlspecialchars($elabel); ?> <small class="text-muted">(<?php echo strtoupper($ch); ?>)</small></span>
            <span class="badge bg-<?php echo $on?'success':'secondary'; ?>"><?php echo $on?'ON':'OFF'; ?></span>
          </a>
          <?php endforeach; endforeach; ?>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- RIGHT: Editor -->
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm notif-editor-card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-pen me-2"></i>Edit Template</h5>
        <span class="badge bg-light text-dark text-lowercase"><?php echo $selAud; ?> / <?php echo $selChan; ?> / <?php echo htmlspecialchars($selEvent); ?></span>
      </div>
      <div class="card-body">
        <form method="POST">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="event_type" value="<?php echo htmlspecialchars($selEvent); ?>">
          <input type="hidden" name="audience" value="<?php echo $selAud; ?>">
          <input type="hidden" name="channel" value="<?php echo $selChan; ?>">

          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="enabled" id="enChk" <?php echo (int)($tpl['enabled']??1)===1?'checked':''; ?>>
            <label class="form-check-label fw-semibold" for="enChk">यो template Enable छ</label>
          </div>

          <?php if ($selChan === 'email'): ?>
          <div class="mb-3">
            <label class="form-label fw-semibold">Subject Line</label>
            <input type="text" name="subject" class="form-control" value="<?php echo htmlspecialchars($tpl['subject']??''); ?>" placeholder="उदाहरण: नयाँ ऋण आवेदन ({tracking_id})">
          </div>
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label fw-semibold">Body / Message <?php if ($selChan==='sms') echo '<small class="text-danger">(Max 160 char)</small>'; ?></label>
            <textarea name="body" class="form-control font-monospace" rows="<?php echo $selChan==='email'?12:5; ?>" required><?php echo htmlspecialchars($tpl['body']??''); ?></textarea>
            <small class="text-muted">Available placeholders: <code>{name}</code> <code>{tracking_id}</code> <code>{status}</code> <code>{remarks}</code> <code>{amount}</code> <code>{date}</code> <code>{details}</code> <code>{site_name}</code></small>
          </div>

          <div class="d-flex gap-2 flex-wrap">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Template</button>
            <a href="notification-settings.php?panel=form" class="btn btn-outline-secondary"><i class="fas fa-gear me-1"></i>Settings</a>
          </div>
        </form>

        <hr class="my-4">

        <!-- Test send -->
        <h6 class="fw-bold mb-2"><i class="fas fa-paper-plane me-1"></i>Test Send</h6>
        <form method="POST" class="row g-2 align-items-center">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="test_send">
          <input type="hidden" name="event_type" value="<?php echo htmlspecialchars($selEvent); ?>">
          <div class="col-md-8">
            <input type="email" name="test_to" class="form-control" placeholder="test@example.com" required>
          </div>
          <div class="col-md-4">
            <button type="submit" class="btn btn-outline-primary w-100">Send Test</button>
          </div>
        </form>
        <small class="text-muted d-block mt-2">यो current event को template ले admin notification trigger गर्छ। Email + SMS दुवै gateway settings अनुसार जान्छ।</small>
      </div>
    </div>
  </div>
</div>
</div>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
