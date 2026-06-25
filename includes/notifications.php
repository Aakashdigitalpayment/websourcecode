<?php
/* Guard: agar notification-log-tables.php missing cha bhane skip */
$__nltf = __DIR__ . '/notification-log-tables.php';
if (is_file($__nltf)) { require_once $__nltf; }
unset($__nltf);

/**
 * Notification System — Email & SMS
 * File: includes/notifications.php
 *
 * यो file सबै form submissions पछि include हुन्छ।
 * Admin ले admin/notification-settings.php बाट configure गर्न सक्छ।
 *
 * Features:
 *   - Email notification (PHP mail() — सबै hosting मा काम गर्छ)
 *   - SMS notification (Sparrow SMS API — Nepal को सबैभन्दा popular)
 *   - Per-event on/off toggle
 *   - Notification log table मा save हुन्छ
 *   - Failed notification ले form submission break गर्दैन
 *
 * Supported events:
 *   loan_application, grievance, digital_service, kyc_application,
 *   appointment, account_application, job_application, contact_message
 */

/* -------------------------------------------------------
   Auto-create notification_log table
------------------------------------------------------- */
function ensureNotificationTable() {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    try {
        ensureNotificationLogTable(getDB());
    } catch (Exception $e) { /* silent */ }
}

/* -------------------------------------------------------
   Log a notification to DB
------------------------------------------------------- */
function logNotification($eventType, $channel, $recipient, $subject, $message, $status, $errorMsg = '') {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO notification_log
                (event_type, channel, recipient, subject, message, status, error_msg)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$eventType, $channel, $recipient, $subject, $message, $status, $errorMsg]);
    } catch (Exception $e) { /* log failure should never crash app */ }
}

/* -------------------------------------------------------
   Check if a notification event is enabled
   Returns true if admin has enabled this event type
------------------------------------------------------- */
function isNotificationEnabled($channel, $event) {
    // channel: 'email' वा 'sms'
    // event: 'loan_application', 'grievance', etc.
    $key = 'notify_' . $channel . '_' . $event;
    return getSetting($key, '0') === '1';
}

/* -------------------------------------------------------
   Build a clean HTML email template
------------------------------------------------------- */
function buildEmailHtml($subject, $eventLabel, $details, $trackingId = '') {
    $siteName  = getSetting('site_name', 'आकाश सहकारी');
    $siteUrl   = defined('SITE_URL') ? SITE_URL : '';
    $year      = date('Y');
    $primaryColor   = getSetting('primary_color', '#1a5f2a');
    $secondaryColor = getSetting('secondary_color', '#c0392b');
    $primaryLight   = getSetting('primary_light', $primaryColor);
    $emailBg        = 'color-mix(in srgb, ' . $primaryColor . ' 7%, white)';
    $emailCardBg    = 'white';
    $emailCardBorder= 'color-mix(in srgb, ' . $primaryColor . ' 14%, #e5e7eb)';
    $emailMuted     = 'var(--text-light,#555)';

    /* Details table rows */
    $rows = '';
    foreach ($details as $label => $value) {
        if ($value === '' || $value === null) continue;
        $rows .= "<tr>
            <td style='padding:8px 12px;font-weight:600;color:{$emailMuted};width:35%;border-bottom:1px solid #f0f0f0;'>{$label}</td>
            <td style='padding:8px 12px;color:var(--text-color,#222);border-bottom:1px solid #f0f0f0;'>" . htmlspecialchars((string)$value) . "</td>
        </tr>";
    }

    $trackingRow = $trackingId
        ? "<tr>
            <td colspan='2' style='padding:12px;background:#e8f5e9;text-align:center;border-radius:6px;'>
                <strong style='color:{$primaryColor};'>Tracking ID: {$trackingId}</strong>
            </td>
        </tr>"
        : '';

    return "<!DOCTYPE html>
<html lang='ne'>
<head><meta charset='UTF-8'><meta name='viewport' content='width=device-width'></head>
<body style='margin:0;padding:0;background:{$emailBg};font-family:Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:{$emailBg};padding:30px 0;'>
<tr><td align='center'>
<table width='600' cellpadding='0' cellspacing='0' style='background:{$emailCardBg};border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(var(--primary-rgb,26,95,42),0.14);max-width:600px;border:1px solid {$emailCardBorder};'>

  <!-- Header -->
  <tr>
    <td style='background:linear-gradient(135deg,{$primaryColor},{$primaryLight});padding:24px 32px;'>
      <h1 style='color:var(--text-on-primary,white);margin:0;font-size:20px;'>{$siteName} — Admin Notification</h1>
      <p style='color:color-mix(in srgb, var(--text-on-primary,white) 82%, transparent);margin:6px 0 0;font-size:14px;'>{$eventLabel}</p>
    </td>
  </tr>

  <!-- Body -->
  <tr>
    <td style='padding:28px 32px;'>
      <h2 style='color:{$primaryColor};font-size:18px;margin:0 0 16px;'>{$subject}</h2>
      <p style='color:{$emailMuted};font-size:14px;margin:0 0 20px;'>
        नयाँ आवेदन प्राप्त भयो। Admin Panel मा जानुहोस् र प्रक्रिया गर्नुहोस्।
      </p>

      <table width='100%' cellpadding='0' cellspacing='0'
             style='border:1px solid {$emailCardBorder};border-radius:8px;overflow:hidden;'>
        {$rows}
        {$trackingRow}
      </table>

      <div style='text-align:center;margin-top:24px;'>
        <a href='{$siteUrl}admin/dashboard.php'
           style='background:linear-gradient(135deg,{$primaryColor},{$primaryLight});color:var(--text-on-primary,white);padding:12px 28px;
                  border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;'>
          Admin Panel खोल्नुहोस्
        </a>
      </div>
    </td>
  </tr>

  <!-- Footer -->
  <tr>
    <td style='background:color-mix(in srgb, {$primaryColor} 6%, #f8f9fa);padding:16px 32px;text-align:center;border-top:1px solid {$emailCardBorder};'>
      <p style='color:var(--text-muted,#999);font-size:12px;margin:0;'>
        यो automatic notification हो — {$siteName} © {$year}
      </p>
    </td>
  </tr>

</table>
</td></tr>
</table>
</body>
</html>";
}

/* -------------------------------------------------------
   Send Email Notification
   PHP mail() function — सबै hosting मा available हुन्छ
------------------------------------------------------- */
function sendEmailNotification($eventType, $subject, $details, $trackingId = '') {
    ensureNotificationTable();

    /* Email notifications enabled? */
    if (getSetting('notify_email_enabled', '0') !== '1') return;
    if (!isNotificationEnabled('email', $eventType)) return;

    /* Admin email(s) — comma separated */
    $toList = getSetting('notify_email_recipients', '');
    if (!$toList) return;

    $recipients = array_filter(array_map('trim', explode(',', $toList)));
    if (empty($recipients)) return;

    /* Email from settings */
    $fromName  = getSetting('site_name', 'आकाश सहकारी') . ' Website';
    $fromEmail = getSetting('notify_email_from', getSetting('site_email', 'noreply@localhost'));

    /* Build event label for email */
    $eventLabels = [
        'loan_application'   => 'नयाँ ऋण आवेदन / New Loan Application',
        'grievance'          => 'नयाँ गुनासो / New Grievance',
        'digital_service'    => 'नयाँ डिजिटल सेवा / New Digital Service Request',
        'kyc_application'    => 'नयाँ KYC आवेदन / New KYC Application',
        'appointment'        => 'नयाँ भेटघाट / New Appointment',
        'account_application'=> 'नयाँ खाता आवेदन / New Account Application',
        'job_application'    => 'नयाँ जागिर आवेदन / New Job Application',
        'contact_message'    => 'नयाँ सम्पर्क सन्देश / New Contact Message',
    ];
    $eventLabel = $eventLabels[$eventType] ?? $eventType;

    /* Build HTML */
    $htmlBody = buildEmailHtml($subject, $eventLabel, $details, $trackingId);

    /* Email headers */
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "Reply-To: {$fromEmail}\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    foreach ($recipients as $to) {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) continue;
        try {
            /* PHP mail() — server configuration मा depend गर्छ */
            $sent = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, $headers);
            logNotification($eventType, 'email', $to, $subject, 'HTML email', $sent ? 'sent' : 'failed',
                $sent ? '' : 'mail() returned false — check server mail config');
        } catch (Exception $e) {
            logNotification($eventType, 'email', $to, $subject, 'HTML email', 'failed', $e->getMessage());
        }
    }
}

/* -------------------------------------------------------
   Send SMS Notification via Sparrow SMS
   Sparrow SMS: Nepal को popular SMS gateway
   API docs: https://sparrowsms.com/api
------------------------------------------------------- */
function sendSMSNotification($eventType, $message) {
    ensureNotificationTable();

    /* SMS notifications enabled? */
    if (getSetting('notify_sms_enabled', '0') !== '1') return;
    if (!isNotificationEnabled('sms', $eventType)) return;

    /* SMS recipient(s) — comma separated phone numbers */
    $toList = getSetting('notify_sms_recipients', '');
    if (!$toList) return;

    $recipients = array_filter(array_map('trim', explode(',', $toList)));
    if (empty($recipients)) return;

    /* SMS gateway settings */
    $gateway = getSetting('notify_sms_gateway', 'sparrow');
    $apiToken = getSetting('notify_sms_token', '');
    $senderId = getSetting('notify_sms_sender_id', 'COOP');

    if (!$apiToken) {
        foreach ($recipients as $r) {
            logNotification($eventType, 'sms', $r, '', $message, 'failed', 'SMS API token not configured');
        }
        return;
    }

    /* Truncate message — SMS max 160 chars */
    $smsText = mb_substr($message, 0, 160);

    foreach ($recipients as $to) {
        /* Clean phone number — Nepal: 98XXXXXXXX */
        $phone = preg_replace('/[^0-9]/', '', $to);
        if (strlen($phone) < 10) continue;

        $sent = false;
        $error = '';

        try {
            if ($gateway === 'sparrow') {
                /* Sparrow SMS API */
                $apiUrl = 'http://api.sparrowsms.com/v2/sms/';
                $postData = http_build_query([
                    'token'  => $apiToken,
                    'from'   => $senderId,
                    'to'     => $phone,
                    'text'   => $smsText,
                ]);

                /* cURL request */
                $ch = curl_init($apiUrl);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $postData,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 10, /* 10 second timeout */
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
                    $responseData = json_decode($response, true);
                    /* Sparrow SMS returns response_code 200 for success */
                    $sent  = isset($responseData['response_code']) && $responseData['response_code'] == 200;
                    $error = $sent ? '' : ($responseData['message'] ?? 'Unknown error');
                } else {
                    $error = "HTTP {$httpCode} — cURL error: " . curl_error($ch);
                }

            } elseif ($gateway === 'aakash') {
                /* Aakash SMS — another Nepal provider */
                $apiUrl = getSetting('notify_sms_api_url', '');
                if ($apiUrl) {
                    $ch = curl_init($apiUrl . '?' . http_build_query([
                        'auth'    => $apiToken,
                        'msisdn'  => $phone,
                        'message' => $smsText,
                        'senderid'=> $senderId,
                    ]));
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10]);
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    $sent = ($httpCode >= 200 && $httpCode < 300);
                    $error = $sent ? '' : "HTTP {$httpCode}";
                }

            } elseif ($gateway === 'webhook') {
                /* Custom webhook — admin ले आफ्नै API URL configure गर्न सक्छ */
                $apiUrl = getSetting('notify_sms_api_url', '');
                if ($apiUrl) {
                    $ch = curl_init($apiUrl);
                    curl_setopt_array($ch, [
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => json_encode(['phone'=>$phone, 'message'=>$smsText, 'token'=>$apiToken]),
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                        CURLOPT_TIMEOUT        => 10,
                    ]);
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    $sent  = ($httpCode >= 200 && $httpCode < 300);
                    $error = $sent ? '' : "HTTP {$httpCode}";
                }
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
            $sent  = false;
        }

        logNotification($eventType, 'sms', $phone, '', $smsText, $sent ? 'sent' : 'failed', $error);
    }
}

/* -------------------------------------------------------
   SMTP Email Sender — native PHP, कुनै library चाहिँदैन
   Admin ले notification-settings.php बाट SMTP configure गर्छ
   Supports: plain / STARTTLS (587) / SSL (465)
------------------------------------------------------- */
function sendSmtpEmail($to, $subject, $htmlBody) {
    /* SMTP settings — admin ले settings मा configure गर्छ */
    $host       = getSetting('smtp_host', '');
    $port       = (int) getSetting('smtp_port', 587);
    $user       = getSetting('smtp_user', '');
    $pass       = getSetting('smtp_password', '');
    $enc        = getSetting('smtp_encryption', 'tls'); /* tls / ssl / none */
    $fromEmail  = getSetting('smtp_from_email', getSetting('notify_email_from', ''));
    $fromName   = getSetting('smtp_from_name',  getSetting('site_name', 'आकाश सहकारी'));

    /* SMTP configure नगरेको छ — PHP mail() मा fallback */
    if (!$host || !$user) {
        $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
        return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, $headers);
    }

    try {
        /* SSL मा direct SSL socket खोल्छ, अरूमा plain */
        $socketAddr = ($enc === 'ssl') ? "ssl://{$host}" : $host;
        $sock = @fsockopen($socketAddr, $port, $errNo, $errStr, 10);
        if (!$sock) return false;

        /* Helper: एक line read गर्ने */
        $read = function() use ($sock) {
            $line = '';
            while (!feof($sock)) {
                $ch = fgets($sock, 515);
                $line .= $ch;
                if (substr($ch, 3, 1) === ' ') break; /* last line */
            }
            return $line;
        };

        /* Helper: command पठाउने र response code check गर्ने */
        $cmd = function($command, $expected = 0) use ($sock, $read) {
            fwrite($sock, $command . "\r\n");
            $resp = $read();
            if ($expected && (int)substr($resp, 0, 3) !== $expected) return false;
            return $resp;
        };

        $read(); /* Server greeting */

        /* EHLO */
        $ehloResp = $cmd("EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

        /* STARTTLS — port 587 */
        if ($enc === 'tls') {
            $cmd("STARTTLS", 220);
            stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $cmd("EHLO " . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        }

        /* AUTH LOGIN */
        $cmd("AUTH LOGIN", 334);
        $cmd(base64_encode($user), 334);
        if (!$cmd(base64_encode($pass), 235)) { fclose($sock); return false; }

        /* Mail FROM / RCPT TO */
        $cmd("MAIL FROM:<{$fromEmail}>", 250);
        $cmd("RCPT TO:<{$to}>", 250);

        /* DATA */
        $cmd("DATA", 354);

        /* Build raw email */
        $boundary = md5(microtime());
        $rawHeaders  = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>\r\n";
        $rawHeaders .= "To: {$to}\r\n";
        $rawHeaders .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $rawHeaders .= "MIME-Version: 1.0\r\n";
        $rawHeaders .= "Content-Type: text/html; charset=UTF-8\r\n";
        $rawHeaders .= "Date: " . date('r') . "\r\n";

        fwrite($sock, $rawHeaders . "\r\n" . $htmlBody . "\r\n.\r\n");
        $dataResp = $read();
        $ok = ((int)substr($dataResp, 0, 3) === 250);

        $cmd("QUIT");
        fclose($sock);
        return $ok;

    } catch (Exception $e) {
        return false;
    }
}

/* -------------------------------------------------------
   Member लाई status update notification पठाउने
   — Admin ले form update गर्दा call हुन्छ
   — Member को email र/वा phone मा notification जान्छ
------------------------------------------------------- */
function sendMemberStatusUpdate(
    string $eventType,   /* 'grievance', 'loan', 'kyc', 'account', 'appointment', 'digital_service' */
    string $memberEmail, /* member को email */
    string $memberPhone, /* member को phone/mobile */
    string $memberName,  /* member को नाम */
    string $newStatus,   /* नयाँ status */
    string $adminComment,/* Admin को टिप्पणी */
    string $trackingId = '',
    bool $forceSkip = false  /* admin ले "नपठाउने" चुने भए true */
): array {
    /* Structured outcome — caller ले audit log मा राख्न सक्छ.
       Status values: 'sent' | 'failed' | 'skipped' | 'not_attempted' */
    $out = [
        'master_enabled' => false,
        'email' => ['status' => 'not_attempted', 'reason' => '', 'to' => $memberEmail],
        'sms'   => ['status' => 'not_attempted', 'reason' => '', 'to' => $memberPhone],
    ];

    ensureNotificationTable();

    if ($forceSkip) {
        $out['email']['status'] = 'skipped';
        $out['email']['reason'] = 'admin opted not to notify';
        $out['sms']['status']   = 'skipped';
        $out['sms']['reason']   = 'admin opted not to notify';
        return $out;
    }

    /* Member notification feature enabled छ? — admin ले toggle गर्न सक्छ */
    if (getSetting('notify_member_enabled', '0') !== '1') {
        $out['email']['status'] = 'skipped';
        $out['email']['reason'] = 'master notification disabled in settings';
        $out['sms']['status']   = 'skipped';
        $out['sms']['reason']   = 'master notification disabled in settings';
        return $out;
    }
    $out['master_enabled'] = true;

    $siteName = getSetting('site_name', 'आकाश सहकारी');
    $siteUrl  = defined('SITE_URL') ? SITE_URL : '';
    $primaryColor = getSetting('primary_color', '#1a5f2a');
    $primaryLight = getSetting('primary_light', $primaryColor);
    $emailBg      = 'color-mix(in srgb, ' . $primaryColor . ' 7%, white)';
    $emailCardBg  = 'white';
    $emailCardBorder = 'color-mix(in srgb, ' . $primaryColor . ' 14%, #e5e7eb)';

    /* Status को Nepali/English label */
    $statusLabels = [
        'pending'     => 'समीक्षाधीन / Pending',
        'in_progress' => 'कार्यान्वयनमा / In Progress',
        'processing'  => 'प्रक्रियामा / Processing',
        'approved'    => 'स्वीकृत / Approved',
        'rejected'    => 'अस्वीकृत / Rejected',
        'resolved'    => 'समाधान भयो / Resolved',
        'closed'      => 'बन्द गरियो / Closed',
        'completed'   => 'सम्पन्न / Completed',
        'disbursed'   => 'वितरण भयो / Disbursed',
        'confirmed'   => 'पुष्टि भयो / Confirmed',
        'cancelled'   => 'रद्द / Cancelled',
    ];
    $statusLabel = $statusLabels[$newStatus] ?? $newStatus;

    /* Event को Nepali label */
    $eventLabels = [
        'grievance'       => 'गुनासो / Grievance',
        'loan'            => 'ऋण आवेदन / Loan Application',
        'kyc'             => 'KYC आवेदन',
        'account'         => 'खाता आवेदन / Account Application',
        'appointment'     => 'भेटघाट / Appointment',
        'digital_service' => 'डिजिटल सेवा अनुरोध / Digital Service Request',
    ];
    $eventLabel = $eventLabels[$eventType] ?? $eventType;

    /* -------- EMAIL to member -------- */
    if (!$memberEmail) {
        $out['email']['status'] = 'skipped';
        $out['email']['reason'] = 'member email empty';
    } elseif (!filter_var($memberEmail, FILTER_VALIDATE_EMAIL)) {
        $out['email']['status'] = 'skipped';
        $out['email']['reason'] = 'member email invalid format';
    } elseif (getSetting('notify_member_email', '1') !== '1') {
        $out['email']['status'] = 'skipped';
        $out['email']['reason'] = 'email channel disabled';
    }

    if ($memberEmail && filter_var($memberEmail, FILTER_VALIDATE_EMAIL)
        && getSetting('notify_member_email', '1') === '1') {

        $subject = "{$siteName} — तपाईंको {$eventLabel} को स्थिति अपडेट भयो";

        /* Tracking link */
        $trackingLink = $trackingId
            ? "<a href='{$siteUrl}application-tracker.php' style='color:{$primaryColor};font-weight:600;'>
                Application Tracker ({$trackingId})
               </a>"
            : "<a href='{$siteUrl}application-tracker.php' style='color:{$primaryColor};'>Application Tracker</a>";

        $commentBlock = $adminComment
            ? "<div style='background:#f0faf2;border-left:4px solid {$primaryLight};padding:12px 16px;margin-top:16px;border-radius:0 8px 8px 0;'>
                <strong style='color:{$primaryColor};'>Admin को जवाफ:</strong><br>
                <p style='margin:6px 0 0;color:#333;'>" . nl2br(htmlspecialchars($adminComment)) . "</p>
               </div>"
            : '';

        $htmlBody = "<!DOCTYPE html>
<html lang='ne'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width'></head>
<body style='margin:0;padding:0;background:{$emailBg};font-family:Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:{$emailBg};padding:30px 0;'>
<tr><td align='center'>
<table width='600' cellpadding='0' cellspacing='0'
       style='background:{$emailCardBg};border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(var(--primary-rgb,26,95,42),0.14);max-width:600px;border:1px solid {$emailCardBorder};'>

  <tr>
    <td style='background:linear-gradient(135deg,{$primaryColor},{$primaryLight});padding:24px 32px;'>
      <h1 style='color:var(--text-on-primary,white);margin:0;font-size:20px;'>{$siteName}</h1>
      <p style='color:color-mix(in srgb, var(--text-on-primary,white) 82%, transparent);margin:6px 0 0;font-size:14px;'>{$eventLabel} — Status Update</p>
    </td>
  </tr>

  <tr>
    <td style='padding:28px 32px;'>
      <p style='color:var(--text-light,#555);font-size:16px;'>नमस्ते <strong>" . htmlspecialchars($memberName) . "</strong>,</p>
      <p style='color:var(--text-light,#555);font-size:15px;'>तपाईंको <strong>{$eventLabel}</strong> को स्थिति अपडेट भएको जानकारी गराउँदछौं।</p>

      <table width='100%' cellpadding='0' cellspacing='0'
             style='border:1px solid {$emailCardBorder};border-radius:8px;overflow:hidden;margin-top:16px;'>
        <tr>
          <td style='padding:12px 16px;background:color-mix(in srgb, {$primaryColor} 6%, #f8f9fa);font-weight:600;color:var(--text-light,#555);width:40%;'>आवेदन प्रकार</td>
          <td style='padding:12px 16px;color:var(--text-color,#222);'>{$eventLabel}</td>
        </tr>
        <tr style='background:color-mix(in srgb, {$primaryColor} 10%, white);'>
          <td style='padding:12px 16px;font-weight:600;color:var(--text-light,#555);'>नयाँ स्थिति</td>
          <td style='padding:12px 16px;color:{$primaryColor};font-weight:700;font-size:16px;'>{$statusLabel}</td>
        </tr>
        " . ($trackingId ? "<tr>
          <td style='padding:12px 16px;font-weight:600;color:var(--text-light,#555);'>Tracking ID</td>
          <td style='padding:12px 16px;color:{$primaryColor};font-weight:600;'>{$trackingId}</td>
        </tr>" : '') . "
      </table>

      {$commentBlock}

      <div style='text-align:center;margin-top:24px;'>
        <a href='{$siteUrl}application-tracker.php'
           style='background:linear-gradient(135deg,{$primaryColor},{$primaryLight});color:var(--text-on-primary,white);padding:12px 28px;
                  border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;'>
          आफ्नो स्थिति हेर्नुहोस्
        </a>
      </div>

      <p style='color:var(--text-muted,#999);font-size:12px;margin-top:24px;border-top:1px solid {$emailCardBorder};padding-top:16px;'>
        यो automatic notification हो — {$siteName} © " . date('Y') . "<br>
        कुनै प्रश्न भएमा हाम्रो कार्यालयमा सम्पर्क गर्नुहोस्।
      </p>
    </td>
  </tr>

</table>
</td></tr>
</table>
</body></html>";

        /* SMTP enabled भए SMTP बाट, नभए PHP mail() बाट */
        $useSMTP = getSetting('smtp_enabled', '0') === '1';
        if ($useSMTP) {
            $sent = sendSmtpEmail($memberEmail, $subject, $htmlBody);
        } else {
            $fromEmail = getSetting('notify_email_from', 'noreply@cooperative.com');
            $fromName  = getSetting('site_name', 'आकाश सहकारी');
            $headers   = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
            $headers  .= "From: {$fromName} <{$fromEmail}>\r\n";
            $sent = @mail($memberEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, $headers);
        }
        logNotification(
            'member_status_' . $eventType, 'email', $memberEmail,
            $subject, "Status: {$newStatus}", $sent ? 'sent' : 'failed',
            $sent ? '' : 'mail/smtp failed — check email config'
        );
        $out['email']['status'] = $sent ? 'sent' : 'failed';
        if (!$sent) $out['email']['reason'] = 'mail/smtp failed — check email config';
    }

    /* -------- SMS to member -------- */
    if (!$memberPhone) {
        $out['sms']['status'] = 'skipped';
        $out['sms']['reason'] = 'member phone empty';
    } elseif (getSetting('notify_member_sms', '1') !== '1') {
        $out['sms']['status'] = 'skipped';
        $out['sms']['reason'] = 'sms channel disabled';
    } elseif (getSetting('notify_sms_enabled', '0') !== '1') {
        $out['sms']['status'] = 'skipped';
        $out['sms']['reason'] = 'sms gateway not enabled';
    }

    if ($memberPhone && getSetting('notify_member_sms', '1') === '1'
        && getSetting('notify_sms_enabled', '0') === '1') {

        $phone   = preg_replace('/[^0-9]/', '', $memberPhone);
        $out['sms']['to'] = $phone;
        if (strlen($phone) < 10) {
            $out['sms']['status'] = 'skipped';
            $out['sms']['reason'] = 'phone too short (<10 digits)';
        }
        if (strlen($phone) >= 10) {

            /* v4: Member SMS template (admin-edited) — fallback to legacy if missing */
            $tplRow = function_exists('getNotificationTemplate') ? getNotificationTemplate($eventType, 'member', 'sms') : null;
            if ($tplRow && (int)($tplRow['enabled'] ?? 1) === 1) {
                $smsText = renderNotificationTemplate($tplRow['body'] ?? '', [
                    'name'        => $memberName,
                    'status'      => $statusLabel,
                    'tracking_id' => $trackingId ?: '',
                    'remarks'     => $adminComment ?: '',
                ]);
            } else {
                $commentShort = $adminComment ? ' Admin: ' . mb_substr($adminComment, 0, 50) . (mb_strlen($adminComment) > 50 ? '...' : '') : '';
                $trackPart    = $trackingId ? " ID:{$trackingId}." : '';
                $smsText = "नमस्ते {$memberName}, तपाईंको {$eventLabel} स्थिति: {$statusLabel}.{$trackPart}{$commentShort} -{$siteName}";
            }
            $smsText = mb_substr($smsText, 0, 160); /* SMS 160 char limit */

            $apiToken = getSetting('notify_sms_token', '');
            $senderId = getSetting('notify_sms_sender_id', 'COOP');
            $gateway  = getSetting('notify_sms_gateway', 'sparrow');
            $sent     = false; $error = '';

            if ($apiToken) {
                try {
                    if ($gateway === 'sparrow') {
                        $ch = curl_init('http://api.sparrowsms.com/v2/sms/');
                        curl_setopt_array($ch, [
                            CURLOPT_POST           => true,
                            CURLOPT_POSTFIELDS     => http_build_query(['token'=>$apiToken,'from'=>$senderId,'to'=>$phone,'text'=>$smsText]),
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT        => 10,
                            CURLOPT_SSL_VERIFYPEER => true,
                        ]);
                        $resp = curl_exec($ch);
                        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);
                        $data = json_decode($resp, true);
                        $sent = isset($data['response_code']) && $data['response_code'] == 200;
                        $error = $sent ? '' : ($data['message'] ?? 'Unknown');
                    } elseif (in_array($gateway, ['aakash','webhook'])) {
                        $apiUrl = getSetting('notify_sms_api_url', '');
                        if ($apiUrl) {
                            $ch = curl_init($apiUrl);
                            curl_setopt_array($ch, [
                                CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10,
                                CURLOPT_POSTFIELDS=>json_encode(['phone'=>$phone,'message'=>$smsText,'token'=>$apiToken]),
                                CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
                            ]);
                            $resp = curl_exec($ch);
                            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);
                            $sent  = ($code >= 200 && $code < 300);
                            $error = $sent ? '' : "HTTP {$code}";
                        }
                    }
                } catch (Exception $e) { $error = $e->getMessage(); }
            } else {
                $error = 'SMS token not configured';
            }

            logNotification(
                'member_status_' . $eventType, 'sms', $phone,
                '', $smsText, $sent ? 'sent' : 'failed', $error
            );
            $out['sms']['status'] = $sent ? 'sent' : 'failed';
            if (!$sent) $out['sms']['reason'] = $error !== '' ? $error : 'sms gateway error';
        }
    }

    /* v3 Cleanup: Email/SMS पठाएसँगै Member Portal भित्रको bell-notification पनि
     * auto create गर्छ। पहिले admin code ले सबै ठाउँमा 2 अलग functions call गर्नु
     * पर्थ्यो — अब एउटै call ले 3 channels (email + SMS + in-app) सबै handle गर्छ। */
    if (function_exists('createMemberStatusNotification')) {
        try {
            createMemberStatusNotification(
                $eventType, $memberEmail, $memberPhone, $memberName,
                $newStatus, $adminComment, $trackingId
            );
        } catch (\Throwable $e) {
            error_log('[notifications] In-app notification error: ' . $e->getMessage());
        }
    }
    return $out;
}

/* -------------------------------------------------------
   Main dispatcher — one function to call from any form
   Usage:
       sendAdminNotification('loan_application', [
           'नाम'           => $fullName,
           'ऋण रकम'       => 'Rs. ' . $loanAmount,
           'फोन'           => $phone,
       ], $trackingId);
------------------------------------------------------- */
function sendAdminNotification($eventType, $details = [], $trackingId = '') {

    /* v4: Admin-edited templates from DB take precedence (if available).
     * If notification_templates table missing or specific row missing,
     * we fall back to the hardcoded defaults below. */
    $emailTpl = function_exists('getNotificationTemplate') ? getNotificationTemplate($eventType, 'admin', 'email') : null;
    $smsTpl   = function_exists('getNotificationTemplate') ? getNotificationTemplate($eventType, 'admin', 'sms')   : null;

    $name = $details['नाम'] ?? $details['Name'] ?? $details['Full Name'] ?? $details['आवेदकको नाम'] ?? 'N/A';
    $amount = $details['ऋण रकम'] ?? $details['Amount'] ?? 'N/A';
    $date = $details['मिति'] ?? $details['Date'] ?? 'N/A';

    $renderVars = [
        'name'        => $name,
        'amount'      => $amount,
        'date'        => $date,
        'tracking_id' => $trackingId ?: 'N/A',
        'tracking'    => $trackingId ?: 'N/A',
        'details'     => $details,
    ];

    /* Email — DB template OR hardcoded fallback */
    if ($emailTpl && (int)($emailTpl['enabled'] ?? 1) === 1) {
        $subject = renderNotificationTemplate($emailTpl['subject'] ?? '', $renderVars) ?: 'New Notification';
        $body    = renderNotificationTemplate($emailTpl['body'] ?? '', $renderVars);
        try { sendEmailNotificationRaw($eventType, $subject, $body, $details, $trackingId); }
        catch (Exception $e) { error_log('[notifications] Email error: ' . $e->getMessage()); }
    } else {
        /* Hardcoded subject/sms fallback (backward compat) */
        $config = _legacyNotificationConfig();
        $cfg     = $config[$eventType] ?? ['subject' => 'New Notification', 'sms_tpl' => 'New submission received.'];
        $subject = $cfg['subject'] . ($trackingId ? " ({$trackingId})" : '');
        try { sendEmailNotification($eventType, $subject, $details, $trackingId); }
        catch (Exception $e) { error_log('[notifications] Email error: ' . $e->getMessage()); }
    }

    /* SMS — DB template OR hardcoded fallback */
    if ($smsTpl && (int)($smsTpl['enabled'] ?? 1) === 1) {
        $smsMsg = renderNotificationTemplate($smsTpl['body'] ?? '', $renderVars);
    } else {
        $config = _legacyNotificationConfig();
        $cfg = $config[$eventType] ?? ['sms_tpl' => 'New submission received.'];
        $smsMsg = strtr($cfg['sms_tpl'], [
            '{tracking}' => $trackingId ?: 'N/A',
            '{name}'     => $name,
            '{amount}'   => $amount,
            '{date}'     => $date,
        ]);
        $siteName = getSetting('site_name', 'आकाश सहकारी');
        $smsMsg .= ' — ' . $siteName;
    }
    try { sendSMSNotification($eventType, $smsMsg); }
    catch (Exception $e) { error_log('[notifications] SMS error: ' . $e->getMessage()); }
}

/* Legacy hardcoded fallback */
function _legacyNotificationConfig(): array {
    return [
        'loan_application'    => ['subject'=>'नयाँ ऋण आवेदन प्राप्त भयो',  'sms_tpl'=>'New Loan Application by {name}. Amount: {amount}. ID: {tracking}'],
        'grievance'           => ['subject'=>'नयाँ गुनासो दर्ता भयो',       'sms_tpl'=>'New Grievance. ID: {tracking}'],
        'digital_service'     => ['subject'=>'नयाँ डिजिटल सेवा अनुरोध',     'sms_tpl'=>'New Digital Service Request. ID: {tracking}'],
        'kyc_application'     => ['subject'=>'नयाँ KYC आवेदन',              'sms_tpl'=>'New KYC by {name}. ID: {tracking}'],
        'appointment'         => ['subject'=>'नयाँ भेटघाट बुकिङ',           'sms_tpl'=>'New Appointment by {name} on {date}. ID: {tracking}'],
        'account_application' => ['subject'=>'नयाँ खाता खोल्ने आवेदन',      'sms_tpl'=>'New Account Application by {name}. ID: {tracking}'],
        'job_application'     => ['subject'=>'नयाँ जागिर आवेदन',            'sms_tpl'=>'New Job Application by {name}. ID: {tracking}'],
        'contact_message'     => ['subject'=>'नयाँ सम्पर्क सन्देश',          'sms_tpl'=>'New Contact Message from {name}'],
    ];
}

/* v4: Send email with pre-rendered subject + body (template manager use गर्दा) */
function sendEmailNotificationRaw($eventType, $subject, $bodyText, $details, $trackingId = '') {
    ensureNotificationTable();
    if (getSetting('notify_email_enabled', '0') !== '1') return;
    if (!isNotificationEnabled('email', $eventType)) return;

    $toList = getSetting('notify_email_recipients', '');
    $recipients = array_filter(array_map('trim', explode(',', $toList)));
    if (empty($recipients)) return;

    $fromName  = getSetting('site_name', 'आकाश सहकारी') . ' Website';
    $fromEmail = getSetting('notify_email_from', getSetting('site_email', 'noreply@localhost'));

    /* Wrap plain-text body into HTML (preserve newlines) */
    $primaryColor = getSetting('primary_color', '#1a5f2a');
    $emailBg      = 'color-mix(in srgb, ' . $primaryColor . ' 7%, #f9f9f9)';
    $emailCardBorder = 'color-mix(in srgb, ' . $primaryColor . ' 14%, #e5e7eb)';
    $htmlBody = '<div style="font-family:Arial,sans-serif;color:var(--text-color,#333);line-height:1.6;padding:20px;background:' . $emailBg . ';">'
              . '<div style="max-width:600px;margin:0 auto;background:white;padding:30px;border-radius:8px;border:1px solid ' . $emailCardBorder . ';">'
              . nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'))
              . '</div></div>';

    $headers  = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$fromName} <{$fromEmail}>\r\nReply-To: {$fromEmail}\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    foreach ($recipients as $to) {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) continue;
        try {
            $sent = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, $headers);
            logNotification($eventType, 'email', $to, $subject, mb_substr($bodyText,0,500), $sent ? 'sent' : 'failed',
                $sent ? '' : 'mail() returned false');
        } catch (Exception $e) {
            logNotification($eventType, 'email', $to, $subject, mb_substr($bodyText,0,500), 'failed', $e->getMessage());
        }
    }
}
/* End of notifications.php — v4 (template-aware) */
