<?php
/**
 * Admin: Notification Settings (Email & SMS)
 * File: admin/notification-settings.php
 *
 * Admin ले यहाँबाट configure गर्न सक्छ:
 *   - Email notifications on/off + recipient emails
 *   - SMS notifications on/off + Sparrow SMS settings
 *   - Per-event toggles (कुन event मा notification जाने)
 *   - Test notification पठाउन सकिन्छ
 *   - Notification log हेर्न सकिन्छ
 */

define('IS_ADMIN_PAGE', true);
require_once '../includes/config.php';
requireAdminLogin();

  /* ── Early CSRF Protection ── */
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCSRFToken()) {
      setFlash('error', 'सुरक्षा जाँच असफल। कृपया पुनः प्रयास गर्नुहोस्।');
      redirect(function_exists('adminSelfUrl') ? adminSelfUrl() : (ADMIN_URL . 'dashboard.php'));
  }
  if (empty($csrfToken)) $csrfToken = generateCSRFToken();
  require_once '../includes/notifications.php'; // notification functions

$db = getDB();
ensureNotificationTable(); // log table ensure

/* -------------------------------------------------------
   POST handler
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = clean_text($_POST['action'] ?? '');

    /* Save all settings */
    if ($action === 'save_settings') {

        /* Email settings — admin लाई notification */
        updateSetting('notify_email_enabled',    isset($_POST['notify_email_enabled'])    ? '1' : '0');
        updateSetting('notify_email_recipients', clean_text($_POST['notify_email_recipients'] ?? ''));
        updateSetting('notify_email_from',       clean_text($_POST['notify_email_from']       ?? ''));

        /* SMS settings — Sparrow/Aakash API */
        updateSetting('notify_sms_enabled',      isset($_POST['notify_sms_enabled'])      ? '1' : '0');
        updateSetting('notify_sms_recipients',   clean_text($_POST['notify_sms_recipients']   ?? ''));
        updateSetting('notify_sms_gateway',      clean_text($_POST['notify_sms_gateway']      ?? 'sparrow'));
        updateSetting('notify_sms_token',        clean_text($_POST['notify_sms_token']        ?? ''));
        updateSetting('notify_sms_sender_id',    clean_text($_POST['notify_sms_sender_id']    ?? 'COOP'));
        updateSetting('notify_sms_api_url',      clean_text($_POST['notify_sms_api_url']      ?? ''));

        /* SMTP settings — Gmail/Outlook/cPanel SMTP बाट email पठाउन */
        updateSetting('smtp_enabled',     isset($_POST['smtp_enabled']) ? '1' : '0');
        updateSetting('smtp_host',        clean_text($_POST['smtp_host']       ?? ''));
        updateSetting('smtp_port',        clean_text($_POST['smtp_port']       ?? '587'));
        updateSetting('smtp_user',        clean_text($_POST['smtp_user']       ?? ''));
        /* Password: blank आएमा पुरानो नै राख्छ */
        if (!empty(trim($_POST['smtp_password'] ?? ''))) {
            updateSetting('smtp_password', clean_text($_POST['smtp_password']));
        }
        updateSetting('smtp_encryption',  clean_text($_POST['smtp_encryption'] ?? 'tls'));
        updateSetting('smtp_from_email',  clean_text($_POST['smtp_from_email'] ?? ''));
        updateSetting('smtp_from_name',   clean_text($_POST['smtp_from_name']  ?? ''));

        /* Member notification — admin ले status update गर्दा member लाई notification */
        updateSetting('notify_member_enabled', isset($_POST['notify_member_enabled']) ? '1' : '0');
        updateSetting('notify_member_email',   isset($_POST['notify_member_email'])   ? '1' : '0');
        updateSetting('notify_member_sms',     isset($_POST['notify_member_sms'])     ? '1' : '0');

        /* Per-event toggles — email */
        $events = ['loan_application','grievance','digital_service','kyc_application',
                   'appointment','account_application','job_application','contact_message'];
        foreach ($events as $ev) {
            updateSetting('notify_email_' . $ev, isset($_POST['notify_email_' . $ev]) ? '1' : '0');
            updateSetting('notify_sms_'   . $ev, isset($_POST['notify_sms_'   . $ev]) ? '1' : '0');
        }

        setFlash('success', 'Notification settings सुरक्षित भयो।');
        redirect('notification-settings.php');
    }

    /* Test notification */
    if ($action === 'test_notification') {
        $channel = clean_text($_POST['test_channel'] ?? 'email');

        /* Temporarily enable for test */
        $testDetails = [
            'नाम'    => 'Test User',
            'फोन'    => '9800000000',
            'इमेल'   => getSetting('email', 'test@example.com'),
            'विषय'   => 'Test Notification',
            'समय'    => date('Y-m-d H:i:s'),
        ];

        if ($channel === 'email') {
            /* Force enable for test */
            $orig = getSetting('notify_email_enabled', '0');
            updateSetting('notify_email_enabled', '1');
            updateSetting('notify_email_loan_application', '1');
            sendEmailNotification('loan_application', 'Test Notification — ' . getSetting('site_name', 'Cooperative'), $testDetails, 'TEST-001');
            updateSetting('notify_email_enabled', $orig);
            setFlash('success', 'Test email पठाइयो। Inbox जाँच गर्नुहोस्। (Spam folder पनि हेर्नुहोस्)');
        } elseif ($channel === 'sms') {
            $orig = getSetting('notify_sms_enabled', '0');
            updateSetting('notify_sms_enabled', '1');
            updateSetting('notify_sms_loan_application', '1');
            sendSMSNotification('loan_application', 'Test SMS from ' . getSetting('site_name', 'Cooperative') . '. Notification system working!');
            updateSetting('notify_sms_enabled', $orig);
            setFlash('success', 'Test SMS पठाइयो। Phone जाँच गर्नुहोस्।');
        }

        redirect('notification-settings.php');
    }

    /* Clear notification log */
    if ($action === 'clear_log') {
        $db->exec("DELETE FROM notification_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        setFlash('success', '30 दिनभन्दा पुराना logs हटाइयो।');
        redirect('notification-settings.php');
    }
}

/* -------------------------------------------------------
   Load current settings
------------------------------------------------------- */
$events = [
    'loan_application'   => ['label' => 'ऋण आवेदन',         'icon' => 'fa-hand-holding-dollar'],
    'grievance'          => ['label' => 'गुनासो',             'icon' => 'fa-triangle-exclamation'],
    'digital_service'    => ['label' => 'डिजिटल सेवा',       'icon' => 'fa-laptop-code'],
    'kyc_application'    => ['label' => 'KYC आवेदन',         'icon' => 'fa-id-card-clip'],
    'appointment'        => ['label' => 'भेटघाट',             'icon' => 'fa-calendar-check'],
    'account_application'=> ['label' => 'खाता आवेदन',         'icon' => 'fa-user-plus'],
    'job_application'    => ['label' => 'जागिर आवेदन',        'icon' => 'fa-briefcase'],
    'contact_message'    => ['label' => 'सम्पर्क सन्देश',     'icon' => 'fa-envelope'],
];

function gs($key, $default = '') {
    return htmlspecialchars(getSetting($key, $default));
}

/* Recent notification log */
$logs = [];
try {
    $logStmt = $db->query("SELECT * FROM notification_log ORDER BY created_at DESC LIMIT 50");
    $logs = $logStmt ? $logStmt->fetchAll() : [];
} catch (Exception $e) { $logs = []; }

$pageTitle   = 'Notification Settings';
$currentPage = 'notification-settings'; /* admin sidebar मा active highlight को लागि */
$panel = (string)($_GET['panel'] ?? 'list');
if (!in_array($panel, ['list', 'form'], true)) {
    $panel = 'list';
}
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
?>

<div class="container-fluid py-4">

    <!-- Page Header -->
    <?php
    echo adminPageHeader('Notification Settings','fa-bell','Email / SMS notification configuration।');
    if ($flash = getFlash()):
    ?>
    <div class="alert alert-<?php echo $flash['type']==='success'?'success':'danger'; ?> alert-dismissible fade show mb-3"><i class="fas fa-<?php echo $flash['type']==='success'?'check-circle':'exclamation-circle'; ?> me-2"></i><?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <ul class="nav admin-nav-tabs mb-4" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $panel === 'list' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#notif-list-tab" type="button" role="tab" aria-selected="<?php echo $panel === 'list' ? 'true' : 'false'; ?>">
                <i class="fas fa-list me-1"></i> सूची
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $panel === 'form' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#notif-form-tab" type="button" role="tab" aria-selected="<?php echo $panel === 'form' ? 'true' : 'false'; ?>">
                <i class="fas fa-plus me-1"></i> नयाँ थप्नुहोस्
            </button>
        </li>
    </ul>

    <div class="tab-content">
    <div class="tab-pane fade <?php echo $panel === 'list' ? 'show active' : ''; ?>" id="notif-list-tab" role="tabpanel">
    <!-- Notification Log -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list-check me-2"></i>Notification Log
                <small class="text-muted ms-2">(Last 50)</small>
            </h5>
            <form method="POST" action="" class="d-inline"
                  onsubmit="return confirm('30 दिनभन्दा पुराना logs हटाउनुहोस्?');">
                <input type="hidden" name="action" value="clear_log">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">
                    <i class="fas fa-trash me-1"></i>Old Logs हटाउनुहोस्
                </button>
            </form>
        </div>
        <div class="card-body p-0">
            <?php if (empty($logs)): ?>
            <div class="text-center py-4 text-muted">
                <i class="fas fa-inbox fa-2x mb-2 d-block opacity-25"></i>
                अझै कुनै notification log छैन।
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Time</th>
                            <th>Event</th>
                            <th>Channel</th>
                            <th>Recipient</th>
                            <th>स्थिति</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="text-muted small"><?php echo date('M d H:i', strtotime($log['created_at'])); ?></td>
                            <td><small><?php echo htmlspecialchars($log['event_type']); ?></small></td>
                            <td>
                                <?php if ($log['channel'] === 'email'): ?>
                                <span class="badge bg-primary"><i class="fas fa-envelope me-1"></i>Email</span>
                                <?php else: ?>
                                <span class="badge bg-success"><i class="fas fa-mobile-alt me-1"></i>SMS</span>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?php echo htmlspecialchars($log['recipient']); ?></td>
                            <td>
                                <?php if ($log['status'] === 'sent'): ?>
                                <span class="badge bg-success"><i class="fas fa-check me-1"></i>Sent</span>
                                <?php else: ?>
                                <span class="badge bg-danger"><i class="fas fa-times me-1"></i>Failed</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?php echo htmlspecialchars(substr($log['error_msg'] ?? '', 0, 60)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    </div>

    <div class="tab-pane fade <?php echo $panel === 'form' ? 'show active' : ''; ?>" id="notif-form-tab" role="tabpanel">
    <!-- v10.3 (Issue #5): Template editor shortcut — admin लाई "के पठाइन्छ" तुरुन्तै edit गर्न सजिलो बनाउँदै -->
    <div class="card border-0 shadow-sm mb-4" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-left:5px solid #4f46e5 !important;">
      <div class="card-body d-flex flex-wrap align-items-center gap-3 py-3">
        <div style="width:48px;height:48px;background:#4f46e5;color:#fff;border-radius:12px;display:grid;place-items:center;font-size:1.4rem;flex-shrink:0;">
          <i class="fas fa-envelope-open-text"></i>
        </div>
        <div style="flex:1 1 260px;">
          <div class="fw-bold" style="color:#3730a3;font-size:1rem;">SMS / Email मा के content पठाउने?</div>
          <div class="small text-muted" style="line-height:1.5;">
            हरेक event (KYC, Loan, Grievance, OTP, Welcome आदि) को subject + body अनि SMS template
            यहाँबाट dynamic edit गर्न सक्नुहुन्छ। Placeholders: <code>{name}</code> <code>{tracking_id}</code> <code>{status}</code> <code>{amount}</code> ...
          </div>
        </div>
        <a href="notification-templates.php" class="btn btn-primary px-4" style="background:#4f46e5;border-color:#4f46e5;">
          <i class="fas fa-pen me-1"></i> Templates Edit गर्नुहोस्
        </a>
      </div>
    </div>

    <form method="POST" action="">
        <input type="hidden" name="action" value="save_settings">
        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

        <div class="row g-4">
            <!-- EMAIL SETTINGS -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-envelope me-2 text-primary"></i>Email Notifications</h5>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch"
                                   name="notify_email_enabled" id="emailEnabled"
                                   style="width:44px;height:22px;"
                                   <?php echo getSetting('notify_email_enabled','0')==='1' ? 'checked' : ''; ?>>
                            <label class="form-check-label ms-2 fw-semibold" for="emailEnabled">
                                <?php echo getSetting('notify_email_enabled','0')==='1' ? 'Enabled' : 'Disabled'; ?>
                            </label>
                        </div>
                    </div>
                    <div class="card-body">

                        <!-- Recipient emails -->
                        <div class="mb-3">
                            <label class="form-label">
                                Admin Email(s) <span class="text-danger">*</span>
                            </label>
                            <!-- Multiple emails: comma separated -->
                            <input type="text" name="notify_email_recipients" class="form-control"
                                   placeholder="akashpame@gmail.com, akashpame@gmail.com"
                                   value="<?php echo gs('notify_email_recipients'); ?>">
                            <small class="text-muted">Multiple emails: comma (,) ले छुट्याउनुहोस्</small>
                        </div>

                        <!-- From email -->
                        <div class="mb-4">
                            <label class="form-label">From Email</label>
                            <input type="email" name="notify_email_from" class="form-control"
                                   placeholder="noreply@cooperative.com"
                                   value="<?php echo gs('notify_email_from'); ?>">
                            <small class="text-muted">
                                Sender email — hosting को email सँग match गर्नुहोस्।<br>
                                <span class="text-warning"><i class="fas fa-info-circle"></i>
                                PHP mail() काम नगरेमा hosting provider बाट SMTP setup गर्नुहोस्।</span>
                            </small>
                        </div>

                        <!-- Per-event email toggles -->
                        <label class="form-label fw-semibold">Email पठाउने events:</label>
                        <div class="row g-2">
                            <?php foreach ($events as $ev => $info): ?>
                            <div class="col-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox"
                                           name="notify_email_<?php echo $ev; ?>"
                                           id="email_<?php echo $ev; ?>"
                                           <?php echo getSetting('notify_email_'.$ev,'0')==='1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="email_<?php echo $ev; ?>">
                                        <i class="fas <?php echo $info['icon']; ?> me-1 text-muted small"></i>
                                        <?php echo $info['label']; ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SMS SETTINGS -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-mobile-alt me-2 text-success"></i>SMS Notifications</h5>
                        <!-- Enable toggle -->
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch"
                                   name="notify_sms_enabled" id="smsEnabled"
                                   style="width:44px;height:22px;"
                                   <?php echo gs('notify_sms_enabled') === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label ms-2 fw-semibold" for="smsEnabled">
                                <?php echo getSetting('notify_sms_enabled','0')==='1' ? 'Enabled' : 'Disabled'; ?>
                            </label>
                        </div>
                    </div>
                    <div class="card-body">

                        <!-- Admin phone numbers -->
                        <div class="mb-3">
                            <label class="form-label">Admin Mobile Number(s) <span class="text-danger">*</span></label>
                            <input type="text" name="notify_sms_recipients" class="form-control"
                                   placeholder="9827157000"
                                   value="<?php echo gs('notify_sms_recipients'); ?>">
                            <small class="text-muted">Multiple numbers: comma (,) ले छुट्याउनुहोस्</small>
                        </div>

                        <!-- SMS Gateway -->
                        <div class="mb-3">
                            <label class="form-label">SMS Gateway</label>
                            <select name="notify_sms_gateway" class="form-select" id="smsGateway"
                                    onchange="toggleGatewayFields()">
                                <option value="sparrow" <?php echo gs('notify_sms_gateway')==='sparrow' ? 'selected':''; ?>>
                                    Sparrow SMS (Nepal — most popular)
                                </option>
                                <option value="aakash" <?php echo gs('notify_sms_gateway')==='aakash' ? 'selected':''; ?>>
                                    Aakash SMS (Nepal)
                                </option>
                                <option value="webhook" <?php echo gs('notify_sms_gateway')==='webhook' ? 'selected':''; ?>>
                                    Custom Webhook / Other API
                                </option>
                            </select>
                        </div>

                        <!-- Sparrow/Aakash: API Token -->
                        <div class="mb-3">
                            <label class="form-label">API Token / Key <span class="text-danger">*</span></label>
                            <input type="password" name="notify_sms_token" class="form-control"
                                   placeholder="Sparrow SMS API token यहाँ राख्नुहोस्"
                                   value="<?php echo gs('notify_sms_token'); ?>">
                            <small class="text-muted">
                                Sparrow SMS: <a href="https://sparrowsms.com" target="_blank">sparrowsms.com</a> मा account खोलेर token लिनुहोस्।
                            </small>
                        </div>

                        <!-- Sender ID -->
                        <div class="mb-3">
                            <label class="form-label">Sender ID / Name</label>
                            <input type="text" name="notify_sms_sender_id" class="form-control"
                                   maxlength="11"
                                   placeholder="COOP"
                                   value="<?php echo gs('notify_sms_sender_id', 'COOP'); ?>">
                            <small class="text-muted">Max 11 characters — SMS मा देखिने name</small>
                        </div>

                        <!-- Custom API URL (for webhook/aakash) -->
                        <div class="mb-4" id="customApiUrlField" style="<?php echo in_array(gs('notify_sms_gateway'), ['aakash','webhook']) ? '' : 'display:none;'; ?>">
                            <label class="form-label">Custom API URL</label>
                            <input type="url" name="notify_sms_api_url" class="form-control"
                                   placeholder="https://your-sms-api.com/send"
                                   value="<?php echo gs('notify_sms_api_url'); ?>">
                        </div>

                        <!-- Per-event SMS toggles -->
                        <label class="form-label fw-semibold">SMS पठाउने events:</label>
                        <div class="row g-2">
                            <?php foreach ($events as $ev => $info): ?>
                            <div class="col-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox"
                                           name="notify_sms_<?php echo $ev; ?>"
                                           id="sms_<?php echo $ev; ?>"
                                           <?php echo getSetting('notify_sms_'.$ev,'0')==='1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="sms_<?php echo $ev; ?>">
                                        <i class="fas <?php echo $info['icon']; ?> me-1 text-muted small"></i>
                                        <?php echo $info['label']; ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div><!-- /row -->

        <!-- ============================================================ -->
        <!-- SMTP SETTINGS CARD — Gmail/Outlook/cPanel SMTP बाट email    -->
        <!-- ============================================================ -->
        <div class="card mb-4 border-primary">
            <div class="card-header bg-primary bg-opacity-10 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 text-primary">
                    <i class="fas fa-server me-2"></i>SMTP Email Setup
                    <small class="text-muted ms-2 fw-normal">— Gmail / Outlook / cPanel SMTP बाट email पठाउन</small>
                </h5>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch"
                           name="smtp_enabled" id="smtpEnabled"
                           style="width:44px;height:22px;"
                           <?php echo gs('smtp_enabled') === '1' ? 'checked' : ''; ?>>
                    <label class="form-check-label ms-2 fw-semibold" for="smtpEnabled">
                        <?php echo getSetting('smtp_enabled','0')==='1' ? 'SMTP On' : 'PHP mail() On'; ?>
                    </label>
                </div>
            </div>
            <div class="card-body">
                <div class="alert alert-info small mb-3">
                    <i class="fas fa-lightbulb me-1"></i>
                    <strong>SMTP Off भए:</strong> Hosting को default PHP mail() use हुन्छ — सजिलो तर spam मा जान सक्छ।<br>
                    <strong>SMTP On भए:</strong> Gmail/Outlook/आफ्नो domain SMTP use हुन्छ — reliable र spam हुँदैन।
                </div>
                <div class="row g-3">
                    <!-- SMTP Host -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">SMTP Host <span class="text-danger">*</span></label>
                        <input type="text" name="smtp_host" class="form-control"
                               placeholder="smtp.gmail.com  वा  mail.yourdomain.com"
                               value="<?php echo gs('smtp_host'); ?>">
                        <small class="text-muted">
                            Gmail: <code>smtp.gmail.com</code> | Outlook: <code>smtp.office365.com</code> |
                            cPanel: <code>mail.yourdomain.com</code>
                        </small>
                    </div>
                    <!-- SMTP Port + Encryption -->
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Port</label>
                        <input type="number" name="smtp_port" class="form-control"
                               placeholder="587"
                               value="<?php echo gs('smtp_port', '587'); ?>">
                        <small class="text-muted">TLS=587 | SSL=465 | Plain=25</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Encryption</label>
                        <select name="smtp_encryption" class="form-select">
                            <option value="tls"  <?php echo gs('smtp_encryption','tls')==='tls'  ? 'selected':''; ?>>TLS (port 587) — Recommended</option>
                            <option value="ssl"  <?php echo gs('smtp_encryption')    ==='ssl'  ? 'selected':''; ?>>SSL (port 465)</option>
                            <option value="none" <?php echo gs('smtp_encryption')    ==='none' ? 'selected':''; ?>>None (port 25)</option>
                        </select>
                    </div>
                    <!-- SMTP Username -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">SMTP Username (Email) <span class="text-danger">*</span></label>
                        <input type="email" name="smtp_user" class="form-control"
                               placeholder="akashpame@gmail.com"
                               value="<?php echo gs('smtp_user'); ?>">
                        <small class="text-muted">Gmail/Outlook को email address नै username हो।</small>
                    </div>
                    <!-- SMTP Password -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">SMTP Password / App Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="smtp_password" class="form-control"
                                   id="smtpPass"
                                   placeholder="<?php echo getSetting('smtp_password','') ? '(सुरक्षित छ — बदल्न नयाँ password लेख्नुहोस्)' : 'Password यहाँ राख्नुहोस्'; ?>">
                            <button class="btn btn-outline-secondary" type="button"
                                    onclick="let f=document.getElementById('smtpPass');f.type=f.type=='password'?'text':'password';" aria-label="View" title="View">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small class="text-muted text-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            Gmail: Normal password काम गर्दैन — <strong>App Password</strong> बनाउनुहोस्।
                            <a href="https://myaccount.google.com/apppasswords" target="_blank" class="text-primary">यहाँ बनाउनुहोस् →</a>
                        </small>
                    </div>
                    <!-- From Email / Name -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">From Email</label>
                        <input type="email" name="smtp_from_email" class="form-control"
                               placeholder="noreply@cooperative.com"
                               value="<?php echo gs('smtp_from_email'); ?>">
                        <small class="text-muted">Email मा देखिने sender address</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">From Name</label>
                        <input type="text" name="smtp_from_name" class="form-control"
                               placeholder="Aakash Cooperative"
                               value="<?php echo gs('smtp_from_name', getSetting('site_name','')); ?>">
                        <small class="text-muted">Email मा देखिने sender नाम</small>
                    </div>
                </div>

                <!-- SMTP Quick Guide -->
                <div class="mt-4">
                    <button class="btn btn-sm btn-outline-info" type="button"
                            data-bs-toggle="collapse" data-bs-target="#smtpGuide">
                        <i class="fas fa-question-circle me-1"></i>Gmail SMTP setup कसरी गर्ने? (Click गर्नुहोस्)
                    </button>
                    <div class="collapse mt-3" id="smtpGuide">
                        <div class="card border-info">
                            <div class="card-body bg-info bg-opacity-5 small">
                                <ol class="mb-0 ps-3">
                                    <li class="mb-2">Google account मा login गर्नुहोस् → <strong>myaccount.google.com</strong></li>
                                    <li class="mb-2"><strong>Security</strong> tab → <strong>2-Step Verification</strong> ON गर्नुहोस्</li>
                                    <li class="mb-2"><strong>App passwords</strong> → Select app: <em>Mail</em> → Select device: <em>Other</em> → Generate</li>
                                    <li class="mb-2">16-digit password copy गर्नुहोस् र माथिको <strong>App Password</strong> field मा राख्नुहोस्</li>
                                    <li class="mb-2">
                                        Settings:<br>
                                        • Host: <code>smtp.gmail.com</code><br>
                                        • Port: <code>587</code><br>
                                        • Encryption: <code>TLS</code><br>
                                        • Username: तपाईंको Gmail address<br>
                                        • Password: 16-digit App Password
                                    </li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- MEMBER NOTIFICATION CARD — Member लाई status update जानकारी -->
        <!-- ============================================================ -->
        <div class="card mb-4 border-success">
            <div class="card-header bg-success bg-opacity-10 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 text-success">
                    <i class="fas fa-user-check me-2"></i>Member Status Notification
                    <small class="text-muted ms-2 fw-normal">— Admin ले status update गर्दा member लाई जानकारी</small>
                </h5>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch"
                           name="notify_member_enabled" id="memberEnabled"
                           style="width:44px;height:22px;"
                           <?php echo gs('notify_member_enabled') === '1' ? 'checked' : ''; ?>>
                    <label class="form-check-label ms-2 fw-semibold" for="memberEnabled">
                        <?php echo getSetting('notify_member_enabled','0')==='1' ? 'Enabled' : 'Disabled'; ?>
                    </label>
                </div>
            </div>
            <div class="card-body">
                <div class="alert alert-success small mb-3">
                    <i class="fas fa-info-circle me-1"></i>
                    यो feature ON गरेमा — Admin ले ऋण आवेदन, गुनासो, KYC, खाता आवेदन आदिको status update गर्दा
                    <strong>member को email र/वा phone</strong> मा automatically notification जान्छ।
                    Member ले application-tracker.php मा गएर पनि status हेर्न सक्छ।
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="card border-0 bg-light p-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox"
                                       name="notify_member_email" id="memberEmailCheck"
                                       style="width:40px;height:20px;"
                                       <?php echo getSetting('notify_member_email','1')==='1' ? 'checked' : ''; ?>>
                                <label class="form-check-label ms-2 fw-semibold" for="memberEmailCheck">
                                    <i class="fas fa-envelope text-primary me-1"></i>Email notification
                                </label>
                            </div>
                            <small class="text-muted mt-1 d-block ps-4">
                                Member को registered email मा status update email पठाइन्छ।<br>
                                SMTP configure गर्नुस् — reliable delivery को लागि।
                            </small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card border-0 bg-light p-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox"
                                       name="notify_member_sms" id="memberSmsCheck"
                                       style="width:40px;height:20px;"
                                       <?php echo getSetting('notify_member_sms','1')==='1' ? 'checked' : ''; ?>>
                                <label class="form-check-label ms-2 fw-semibold" for="memberSmsCheck">
                                    <i class="fas fa-mobile-alt text-success me-1"></i>SMS notification
                                </label>
                            </div>
                            <small class="text-muted mt-1 d-block ps-4">
                                Member को phone मा SMS पठाइन्छ।<br>
                                SMS Gateway (Sparrow SMS) configure गर्नुस् माथि।
                            </small>
                        </div>
                    </div>
                </div>
                <div class="mt-3 p-3 bg-warning bg-opacity-10 rounded small">
                    <i class="fas fa-lightbulb text-warning me-1"></i>
                    <strong>कसरी काम गर्छ:</strong>
                    Admin ले कुनै पनि आवेदनको status बदल्छ (pending → approved आदि) →
                    Member को email/SMS मा "<em>तपाईंको ऋण आवेदन स्वीकृत भयो</em>" भनेर notification आउँछ →
                    Member ले Application Tracker मा जाँदा details हेर्न सक्छ।
                </div>
            </div>
        </div>

        <!-- Save Button -->
        <div class="d-flex gap-3 mb-4">
            <button type="submit" class="btn btn-primary btn-lg px-5">
                <i class="fas fa-save me-2"></i>Settings सुरक्षित गर्नुहोस्
            </button>
        </div>
    </form>

    <!-- Test Notifications -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header"><h5 class="mb-0"><i class="fas fa-flask me-2"></i>Test Notification</h5></div>
                <div class="card-body">
                    <p class="text-muted small">Settings save गरेपछि test गर्नुहोस्।</p>
                    <div class="d-flex gap-3 flex-wrap">
                        <!-- Test Email -->
                        <form method="POST" action="" class="d-inline">
                            <input type="hidden" name="action" value="test_notification">
                            <input type="hidden" name="test_channel" value="email">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <button type="submit" class="btn btn-outline-primary">
                                <i class="fas fa-envelope me-2"></i>Test Email पठाउनुहोस्
                            </button>
                        </form>
                        <!-- Test SMS -->
                        <form method="POST" action="" class="d-inline">
                            <input type="hidden" name="action" value="test_notification">
                            <input type="hidden" name="test_channel" value="sms">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <button type="submit" class="btn btn-outline-success">
                                <i class="fas fa-mobile-alt me-2"></i>Test SMS पठाउनुहोस्
                            </button>
                        </form>
                    </div>
                    <div class="alert alert-light mt-3 mb-0 small">
                        <i class="fas fa-info-circle me-1"></i>
                        Test notification configured recipient(s) लाई पठाइन्छ।
                        Spam/Junk folder पनि जाँच गर्नुहोस्।
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Guide -->
        <div class="col-md-6">
            <div class="card border-info">
                <div class="card-header bg-info bg-opacity-10">
                    <h5 class="mb-0 text-info"><i class="fas fa-circle-info me-2"></i>Setup Guide</h5>
                </div>
                <div class="card-body small">
                    <ol class="mb-0 ps-3">
                        <li class="mb-2">
                            <strong>Email Setup:</strong>
                            <ul class="ps-2 mt-1">
                                <li>Hosting को domain email use गर्नुहोस् (जस्तै: admin@yourdomain.com)</li>
                                <li>cPanel → Email Accounts मा नयाँ email बनाउनुहोस्</li>
                                <li>काम नगरेमा hosting provider सँग SMTP settings माग्नुहोस्</li>
                            </ul>
                        </li>
                        <li>
                            <strong>SMS Setup (Sparrow SMS):</strong>
                            <ul class="ps-2 mt-1">
                                <li><a href="https://sparrowsms.com" target="_blank">sparrowsms.com</a> मा account खोल्नुहोस्</li>
                                <li>Dashboard बाट API Token copy गर्नुहोस्</li>
                                <li>Token यहाँ paste गर्नुहोस् र Save गर्नुहोस्</li>
                            </ul>
                        </li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
    </div>
    </div>

</div>

<script>
/* SMS gateway field toggle — custom URL field show/hide */
function toggleGatewayFields() {
    var gateway = document.getElementById('smsGateway').value;
    var urlField = document.getElementById('customApiUrlField');
    if (urlField) {
        urlField.style.display = (gateway === 'aakash' || gateway === 'webhook') ? '' : 'none';
    }
}
</script>

<?php require_once 'includes/admin-footer.php'; ?>
