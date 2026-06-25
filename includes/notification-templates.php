<?php
/**
 * ════════════════════════════════════════════════════════════════════
 * NOTIFICATION TEMPLATES — v4
 * ════════════════════════════════════════════════════════════════════
 * Admin बाट edit गरिएका subject/body templates load + render गर्ने helper।
 * notifications.php ले यो file लाई use गर्छ। Backward-compatible:
 *   - notification_templates table छैन भने हार्डकोडेड default fallback
 *   - कुनै specific template row छैन भने हार्डकोडेड default fallback
 *
 * Placeholder syntax: {variable_name}
 * Common: {name} {tracking_id} {status} {remarks} {amount} {date}
 *         {details} {site_name} {site_url}
 * ════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/notification-templates-tables.php';

if (!function_exists('ensureNotificationTemplatesTable')) {
function ensureNotificationTemplatesTable(): bool {
    try {
        global $db;
        if (!$db) {
            $db = getDB();
        }
        if (!$db) {
            return false;
        }
        ensureNotificationTemplatesSchema($db);
        return true;
    } catch (\Throwable $e) {
        error_log('[notif-tpl] ensure: ' . $e->getMessage());
        return false;
    }
}}

/* In-memory cache (per-request) — एकै event multiple times call हुँदा DB hit नगरोस् */
if (!isset($GLOBALS['__notif_tpl_cache'])) $GLOBALS['__notif_tpl_cache'] = [];

if (!function_exists('getNotificationTemplate')) {
function getNotificationTemplate(string $event, string $audience, string $channel): ?array {
    $key = "$event|$audience|$channel";
    if (isset($GLOBALS['__notif_tpl_cache'][$key])) return $GLOBALS['__notif_tpl_cache'][$key];
    try {
        global $db; if (!$db) $db = getDB(); if (!$db) return null;
        $st = $db->prepare("SELECT enabled, subject, body, variables FROM notification_templates
                            WHERE event_type=? AND audience=? AND channel=? LIMIT 1");
        $st->execute([$event, $audience, $channel]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        $GLOBALS['__notif_tpl_cache'][$key] = $row;
        return $row;
    } catch (\Throwable $e) {
        /* Table missing or DB error → return null, caller fallback गर्छ */
        return null;
    }
}}

if (!function_exists('renderNotificationTemplate')) {
function renderNotificationTemplate(string $tpl, array $vars): string {
    if (!$tpl) return '';
    /* {site_name}/{site_url} auto-inject if not given */
    if (!isset($vars['site_name'])) $vars['site_name'] = function_exists('getSetting') ? getSetting('site_name','आकाश सहकारी') : 'आकाश सहकारी';
    if (!isset($vars['site_url']))  $vars['site_url']  = defined('SITE_URL') ? SITE_URL : '';
    $out = $tpl;
    foreach ($vars as $k => $v) {
        if (is_array($v)) {
            /* Auto-format details array as "key: value\n" */
            $lines = [];
            foreach ($v as $kk => $vv) $lines[] = "{$kk}: " . (is_scalar($vv) ? $vv : json_encode($vv, JSON_UNESCAPED_UNICODE));
            $v = implode("\n", $lines);
        }
        $out = str_replace('{'.$k.'}', (string)$v, $out);
    }
    /* Escape any unfilled placeholders → blank */
    $out = preg_replace('/\{[a-z_][a-z0-9_]*\}/i', '', $out);
    /* \n literal → newline */
    $out = str_replace('\\n', "\n", $out);
    return $out;
}}

/* List all events available for template editing (UI helper) */
if (!function_exists('getNotificationEventsList')) {
function getNotificationEventsList(): array {
    return [
        'admin' => [
            'loan_application'    => 'नयाँ ऋण आवेदन',
            'grievance'           => 'नयाँ गुनासो',
            'digital_service'     => 'नयाँ डिजिटल सेवा अनुरोध',
            'kyc_application'     => 'नयाँ KYC आवेदन',
            'appointment'         => 'नयाँ भेटघाट',
            'account_application' => 'नयाँ खाता आवेदन',
            'job_application'     => 'नयाँ जागिर आवेदन',
            'contact_message'     => 'नयाँ सम्पर्क सन्देश',
        ],
        'member' => [
            'loan'        => 'ऋण आवेदन — Status update',
            'kyc'         => 'KYC — Status update',
            'account'     => 'खाता आवेदन — Status update',
            'appointment' => 'भेटघाट — Status update',
            'grievance'   => 'गुनासो — Status update',
        ],
    ];
}}
