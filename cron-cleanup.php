<?php
/**
 * =====================================================
 * CRON CLEANUP — Daily Maintenance
 * =====================================================
 * cPanel Cron Jobs मा यो script daily run गर्नुहोस्:
 *
 *   php /home/USERNAME/public_html/cron-cleanup.php
 *
 * वा URL बाट (token अनिवार्य — कमजोर फलब्याक हटाइएको):
 *   export CRON_TOKEN='...२०+ random...'
 *   वा includes/.cron-token फाइलमा एउटा लाइन मात्र राख्नुहोस्
 *   curl "https://yoursite.com/cron-cleanup.php?token=$CRON_TOKEN"
 *
 * के गर्छ?
 *   - 90 दिन भन्दा पुरानो activity_log delete
 *   - Expired login_attempts (1 दिन भन्दा पुरानो) delete
 *   - Expired/used member OTP tokens delete
 * =====================================================
 */

/* SECURITY: CLI बाट मात्र, वा CRON_TOKEN env वा includes/.cron-token (२०+ char) */
if (php_sapi_name() !== 'cli') {
    $secret = trim((string)(getenv('CRON_TOKEN') ?: ''));
    if ($secret === '') {
        $tf = __DIR__ . '/includes/.cron-token';
        if (is_readable($tf)) {
            $secret = trim((string) file_get_contents($tf));
        }
    }
    if ($secret === '' || strlen($secret) < 20) {
        http_response_code(404);
        exit('Not found');
    }
    $token = mb_substr((string)($_GET['token'] ?? ''), 0, 256, 'UTF-8');
    if (!hash_equals($secret, $token)) {
        http_response_code(404);
        exit('Not found');
    }
}

require_once __DIR__ . '/includes/config.php';

if (!function_exists('purgeOldRecords')) {
    exit("purgeOldRecords() not available — config.php update गर्नुहोस्\n");
}

$result = purgeOldRecords(90);
$logLine = '[' . date('Y-m-d H:i:s') . '] Cleanup: ' . json_encode($result) . "\n";
@file_put_contents(__DIR__ . '/logs/cron-cleanup.log', $logLine, FILE_APPEND);
echo $logLine;
